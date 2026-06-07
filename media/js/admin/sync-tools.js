/**
 * Alfa Commerce — Tools view: AJAX maintenance actions with per-card progress.
 *
 * Every tool button (.alfa-tool-btn) drives the progress bar inside its own card:
 *   - data-action="single"   → one request (language-table resync, user/group resync).
 *   - data-action="backfill" → chunked translation backfill (plan → table → chunk).
 *
 * On success the bar turns green and the button stays disabled (reload to re-run);
 * on error the bar turns red and the button is re-enabled for a retry.
 *
 * Server replies use Joomla's JsonResponse envelope: { success, message, data }.
 */
(function () {
    'use strict';

    function init() {
        document.querySelectorAll('.alfa-tool-btn').forEach(function (btn) {
            btn.addEventListener('click', function () { run(btn); });
        });
    }

    // Bind now if the DOM is already parsed (deferred/late load), else on ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function run(btn) {
        const wrap = btn.closest('.card-body').querySelector('.alfa-progress');
        const ctx = {
            btn: btn,
            bar: wrap.querySelector('.progress-bar'),
            status: wrap.querySelector('[data-status]'),
        };

        btn.disabled = true;
        wrap.style.display = 'block';
        resetBar(ctx.bar);
        ctx.status.classList.remove('text-danger');
        ctx.status.textContent = '…';

        if (btn.getAttribute('data-action') === 'backfill') {
            runBackfill(btn, ctx);
        } else {
            runSingle(btn, ctx);
        }
    }

    // One-shot action: animate the bar while the request is in flight, green on done.
    function runSingle(btn, ctx) {
        busy(ctx.bar);

        getJson(btn.getAttribute('data-url'))
            .then(function (data) { done(ctx, data.message || doneText()); })
            .catch(function (err) { fail(ctx, err); });
    }

    // Chunked backfill: fetch the plan, then walk table-by-table, chunk-by-chunk.
    function runBackfill(btn, ctx) {
        ctx.chunkUrl = btn.getAttribute('data-chunk-url');

        getJson(btn.getAttribute('data-plan-url'))
            .then(function (data) {
                const plan = (data && data.plan) || [];
                ctx.grandTotal = plan.reduce(function (sum, t) { return sum + (t.total || 0); }, 0) || 1;
                processPlan(plan, 0, 0, 0, ctx);
            })
            .catch(function (err) { fail(ctx, err); });
    }

    function processPlan(plan, tableIndex, offset, doneSoFar, ctx) {
        if (tableIndex >= plan.length) {
            done(ctx, doneText());
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

        getJson(url)
            .then(function (data) {
                const processed = doneSoFar + (data.processed || 0);

                setBar(ctx.bar, Math.min(100, Math.round((processed / ctx.grandTotal) * 100)));
                ctx.status.textContent = entry.table + ' — '
                    + Math.min(data.next || 0, data.total || 0) + ' / ' + (data.total || 0);

                if (data.done) {
                    processPlan(plan, tableIndex + 1, 0, processed, ctx);
                } else {
                    processPlan(plan, tableIndex, data.next, processed, ctx);
                }
            })
            .catch(function (err) { fail(ctx, err); });
    }

    // Resolve to the JsonResponse `data` payload, or reject with its message.
    function getJson(url) {
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.json();
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
        ctx.btn.disabled = true; // stays disabled — reload the page to run again
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

    function doneText() {
        return (window.Joomla && Joomla.Text)
            ? Joomla.Text._('COM_ALFA_TOOLS_BACKFILL_DONE')
            : 'Done.';
    }
})();
