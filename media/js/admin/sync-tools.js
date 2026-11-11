/**
 * Alfa Commerce - Tools view: AJAX maintenance actions with per-card progress.
 *
 * Every card is registry-contributed (core and plugins alike) and every button
 * (.alfa-tool-btn) drives the progress bar inside its own card:
 *   - data-action="single"   -> one request (task=toolaction.run).
 *   - data-action="backfill" -> chunked plan -> chunk loop (task=toolaction.plan/.chunk).
 *
 * Optional per-button behaviour, all declared as data attributes by the layout:
 *   - data-confirm-message -> native confirm() before anything runs.
 *   - a form with data-alfa-confirm (Tools > Tasks, a new web-cron address) asks it first; declining sends nothing.
 *   - data-mutating -> every request is a POST carrying a fresh one-time key
 *     (`once`), which the endpoint demands of a state-changing action; a
 *     read-only action is fetched. The session token rides in the URL either way.
 *   - a `refresh` flag in a single-action reply reloads the page after the result
 *     is shown, for actions whose outcome changes what the page displays.
 *   - a `location` in a chunked action's plan opens that page instead (a queued job's progress page), as a fresh
 *     request even when it is the page already open (only its fragment would differ, and the browser would merely
 *     scroll); beside the button only "Opening its progress page...", no bar: what happened (a request that joined a
 *     job already waiting or running) is in the message area of the page that opens.
 *
 * On success the bar turns green and the button stays disabled (reload to re-run);
 * on error the bar turns red, the error is said beside the button in plain words, and the button is re-enabled for a
 * retry. A button never just goes grey (2.2.0, entry 20).
 *
 * Server replies use Joomla's JsonResponse envelope: { success, message, data }.
 */
(function () {
    'use strict';

    function init() {
        document.querySelectorAll('.alfa-tool-btn').forEach(function (btn) {
            btn.addEventListener('click', function () { run(btn); });
        });

        // A form whose submission cannot be taken back asks first (the web-cron address that stops the old one, UX-11).
        document.querySelectorAll('form[data-alfa-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.getAttribute('data-alfa-confirm'))) {
                    event.preventDefault();
                }
            });
        });
    }

    // Bind now if the DOM is already parsed (deferred/late load), else on ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function run(btn) {
        const confirmMessage = btn.getAttribute('data-confirm-message');

        if (confirmMessage && !window.confirm(confirmMessage)) {
            return;
        }

        const wrap = btn.closest('.card-body').querySelector('.alfa-progress');
        const ctx = {
            btn: btn,
            track: wrap.querySelector('.progress'),
            bar: wrap.querySelector('.progress-bar'),
            status: wrap.querySelector('[data-status]'),
            mutating: btn.hasAttribute('data-mutating'),
        };

        btn.disabled = true;
        wrap.style.display = 'block';
        // A chunked action's bar shows once its plan has work to walk: a plan that opens a job's page draws none.
        ctx.track.hidden = btn.getAttribute('data-action') === 'backfill';
        resetBar(ctx.bar);
        ctx.status.classList.remove('text-danger');
        ctx.status.textContent = '...';

        if (btn.getAttribute('data-action') === 'backfill') {
            runBackfill(btn, ctx);
        } else {
            runSingle(btn, ctx);
        }
    }

    // One-shot action: animate the bar while the request is in flight, green on done.
    function runSingle(btn, ctx) {
        busy(ctx.bar);

        request(btn.getAttribute('data-url'), ctx.mutating)
            .then(function (data) {
                done(ctx, data.message || doneText());

                // The server says its outcome changed what this page displays.
                // Show the result first, then reload so it is actually visible.
                if (data.refresh) {
                    setTimeout(function () { window.location.reload(); }, 1200);
                }
            })
            .catch(function (err) { fail(ctx, err); });
    }

    // Chunked backfill: fetch the plan, then walk table-by-table, chunk-by-chunk.
    function runBackfill(btn, ctx) {
        ctx.chunkUrl = btn.getAttribute('data-chunk-url');
        // Optional per-button completion label (falls back to the backfill default).
        ctx.doneText = btn.getAttribute('data-done-text') || '';

        request(btn.getAttribute('data-plan-url'), ctx.mutating)
            .then(function (data) {
                // The action queued a job: its progress page follows it, and says there what happened (a request that
                // joined a job already waiting or running). Beside the button, only that the page opens; no bar.
                if (data && data.location) {
                    ctx.status.textContent = openingText();
                    openPage(data.location);
                    return;
                }

                ctx.track.hidden = false;
                const plan = (data && data.plan) || [];
                ctx.grandTotal = plan.reduce(function (sum, t) { return sum + (t.total || 0); }, 0) || 1;
                processPlan(plan, 0, 0, 0, ctx);
            })
            .catch(function (err) { fail(ctx, err); });
    }

    function processPlan(plan, tableIndex, offset, doneSoFar, ctx) {
        if (tableIndex >= plan.length) {
            // Prefer what the server actually reported. A chunked tool only knows what
            // it did once it has done it ("11 products indexed, covering manufacturers"
            // cannot be written on the button in advance), so a message carried back by
            // the final chunk outranks the static label and the generic fallback.
            done(ctx, ctx.lastMessage || ctx.doneText || doneText());

            if (ctx.refresh) {
                setTimeout(function () { window.location.reload(); }, 1200);
            }
            return;
        }

        const entry = plan[tableIndex];

        if (!entry.total) {
            processPlan(plan, tableIndex + 1, 0, doneSoFar, ctx);
            return;
        }

        const url = ctx.chunkUrl
            + '&table=' + encodeURIComponent(entry.table)
            + '&offset=' + offset;

        request(url, ctx.mutating)
            .then(function (data) {
                const processed = doneSoFar + (data.processed || 0);

                if (data.message) {
                    ctx.lastMessage = data.message;
                }

                if (data.refresh) {
                    ctx.refresh = true;
                }

                setBar(ctx.bar, Math.min(100, Math.round((processed / ctx.grandTotal) * 100)));
                // A single-entry plan is the registry's flattened shape; its entry name
                // is the action id, which is an internal identifier, not a label.
                ctx.status.textContent = (plan.length > 1 ? entry.table + ' - ' : '')
                    + Math.min(data.next || 0, data.total || 0) + ' / ' + (data.total || 0);

                if (data.done) {
                    processPlan(plan, tableIndex + 1, 0, processed, ctx);
                } else {
                    processPlan(plan, tableIndex, data.next, processed, ctx);
                }
            })
            .catch(function (err) { fail(ctx, err); });
    }

    // Open a page as a fresh request. When it is the page already open but for its fragment, assigning it would only
    // scroll (no request, the button left grey): the address takes the fragment and the page is loaded again.
    function openPage(location) {
        const target = new URL(location, window.location.href);

        if (target.origin === window.location.origin && target.pathname === window.location.pathname
            && target.search === window.location.search) {
            window.history.replaceState(null, '', target.href);
            window.location.reload();
            return;
        }

        window.location.assign(target.href);
    }

    // A key no request has presented before. The server records each one it
    // consumes, so a repeat of the same submission is refused there; the key
    // itself carries nothing and is never reused here.
    function onceKey() {
        return 'once-' + Date.now() + '-' + Math.random().toString(36).slice(2);
    }

    // Resolve to the JsonResponse `data` payload, or reject with its message.
    // A state-changing action goes as a POST with a fresh one-time key, which
    // the endpoint demands of it; anything else is a plain fetch.
    function request(url, mutating) {
        const options = { headers: { 'X-Requested-With': 'XMLHttpRequest' } };

        if (mutating) {
            options.method = 'POST';
            options.body = new URLSearchParams({ once: onceKey() });
        }

        return fetch(url, options)
            .then(function (r) {
                if (!r.ok) {
                    // The answer's own message when it carries one, else a plain sentence with the status.
                    return r.json().catch(function () { return null; }).then(function (json) {
                        throw new Error((json && json.message) || failedText(r.status));
                    });
                }
                // An answer that is not JSON (a login page after the session ended, a PHP notice before the JSON) is said
                // in the same plain sentence, never as the parser's own message.
                return r.json().catch(function () { throw new Error(failedText(r.status)); });
            })
            .then(function (json) {
                if (!json || json.success === false) {
                    throw new Error((json && json.message) || 'Request failed');
                }
                return json.data || {};
            });
    }

    function resetBar(bar) {
        bar.classList.remove('bg-success', 'bg-danger');
        bar.classList.add('progress-bar-striped', 'progress-bar-animated');
        setBar(bar, 0);
    }

    // Indeterminate "working" state: fill the track with animated stripes, no number.
    function busy(bar) {
        bar.style.width = '100%';
        bar.textContent = '';
        bar.setAttribute('aria-valuenow', 100);
    }

    function setBar(bar, pct) {
        bar.style.width = pct + '%';
        bar.textContent = pct + '%';
        bar.setAttribute('aria-valuenow', pct);
    }

    function done(ctx, message) {
        ctx.bar.classList.remove('progress-bar-striped', 'progress-bar-animated');
        ctx.bar.classList.add('bg-success');
        setBar(ctx.bar, 100);
        ctx.status.textContent = message;
        ctx.btn.disabled = true; // stays disabled; reload the page to run again
    }

    function fail(ctx, err) {
        ctx.bar.classList.remove('progress-bar-striped', 'progress-bar-animated');
        ctx.bar.classList.add('bg-danger');
        ctx.status.classList.add('text-danger');
        const errPrefix = (window.Joomla && Joomla.Text)
            ? Joomla.Text._('COM_ALFA_SYNC_ERROR_PREFIX', 'Error: %s').replace('%s', err.message)
            : 'Error: ' + err.message;
        ctx.status.textContent = errPrefix;
        ctx.btn.disabled = false; // allow a retry
    }

    function openingText() {
        return (window.Joomla && Joomla.Text)
            ? Joomla.Text._('COM_ALFA_TOOLS_JOB_OPENING', 'Opening its progress page...')
            : 'Opening its progress page...';
    }

    function failedText(status) {
        return (window.Joomla && Joomla.Text)
            ? Joomla.Text._('COM_ALFA_TOOLS_REQUEST_FAILED', 'The server\'s answer could not be read (HTTP %s).').replace('%s', String(status))
            : 'The server\'s answer could not be read (HTTP ' + status + ').';
    }

    function doneText() {
        return (window.Joomla && Joomla.Text)
            ? Joomla.Text._('COM_ALFA_TOOLS_BACKFILL_DONE')
            : 'Done.';
    }
})();
