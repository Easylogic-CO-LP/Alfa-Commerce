/**
 * Alfa Admin: Setup checklist card (dashboard)
 * LAYOUT-DRIVEN component. The whole card is server-rendered as one
 * `.setup-card` node by the `setup.card` layout; this script never builds
 * content; refresh re-fetches that same layout and swaps the node in place.
 * Presentation state is declarative (`data-state="open|collapsed"`): the CSS shows
 * and hides regions from it, this script only flips the attribute and persists the
 * choice per user (server-side, never localStorage).
 *
 * Tour buttons on the rows are NOT handled here: `.button-start-guidedtour` is the
 * core Guided Tours click contract (plg_system_guidedtours owns it).
 *
 * @package  Alfa Commerce
 * @since  2.0.0
 */
(function () {
    'use strict';

    /**
     * Joomla.getOptions key the dashboard template publishes endpoints/token under.
     */
    var OPTIONS_KEY = 'com_alfa.setup';

    /**
     * The contract with the `setup.card` layout. Every selector and state token the
     * script touches lives here: change the markup, change one line.
     */
    var SEL = {
        root:            '.setup-card',        // component wrapper (the swapped node)
        refresh:         '[data-action="refresh"]', // re-evaluate now
        collapse:        '[data-action="collapse"]',
        expand:          '[data-action="expand"]',
        review:          '[data-action="setup-review"]', // the title tick, shown once setup is done
        cta:             'a.setup-cta',        // row deep link -> opens in the iframe modal
        item:            'li.setup-item',      // one checklist row, the scope of its own answers
        provision: '[data-action="provision"]', // row one-click auto-fix
        stateAttr: 'data-state'                 // 'open' | 'collapsed'
    };

    /**
     * The shared iframe modal actions.js builds (Alfa.openActionFrame); closing it
     * is our cue that a setup task may have been completed.
     */
    var FRAME_MODAL_ID = 'alfa-action-frame-modal';

    var opts; // resolved script options (cardUrl, stateUrl, token)

    /**
     * The current card node (reassigned on every refresh swap).
     *
     * @return {Element|null}
     */
    function root() {
        return document.querySelector(SEL.root);
    }

    /**
     * Persist the user's presentation state; fire-and-forget.
     *
     * @param {string} state 'open' | 'collapsed'
     */
    function saveState(state) {
        var body = new URLSearchParams();

        body.set('state', state);
        body.set(opts.token, '1');

        fetch(opts.stateUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).catch(function () { /* a lost preference is not an error worth surfacing */ });
    }

    /**
     * Flip the declarative state on the node and persist it.
     *
     * @param {string} state 'open' | 'collapsed'
     */
    function setState(state) {
        var node = root();

        if (!node) {
            return;
        }

        node.setAttribute(SEL.stateAttr, state);
        saveState(state);
    }

    /**
     * Replace the card node with a freshly rendered one (the single render path).
     *
     * @param {string} html The setup.card layout output.
     */
    function swapCard(html) {
        var holder = document.createElement('div');

        holder.innerHTML = html;

        var fresh = holder.querySelector(SEL.root);
        var stale = root();

        if (fresh && stale) {
            stale.replaceWith(fresh);
        }
    }

    /**
     * Ask the server for a fresh card: GET re-evaluates, POST performs the given
     * body's action first (a provision); then swap it in.
     *
     * @param {string}                 url     Endpoint returning the card layout.
     * @param {URLSearchParams|null}   body    POST body; null for a plain GET.
     * @param {Element}                [trigger] The clicked control, marked busy for
     *                                           the request's lifetime so the merchant
     *                                           can see it is actually working.
     */
    function requestCard(url, body, trigger) {
        var node = root();

        if (!node) {
            return;
        }

        node.classList.add('is-busy');

        if (trigger && window.Alfa && Alfa.loader) {
            Alfa.loader.showIn(trigger);
        }

        var init = { headers: { 'X-Requested-With': 'fetch' } };

        if (body) {
            body.set(opts.token, '1');
            init.method = 'POST';
            init.headers['Content-Type'] = 'application/x-www-form-urlencoded';
            init.body = body.toString();
        }

        fetch(body ? url : url + '&' + opts.token + '=1', init)
            .then(function (response) {
                // A refusal's body is server text (a raw exception, or a whole error
                // page with its markup), so it is never read: the operator gets the
                // translated notice below instead.
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.text();
            })
            .then(swapCard)
            .catch(function () {
                var stale = root();

                if (stale) {
                    stale.classList.remove('is-busy');
                }

                if (window.Alfa && typeof Alfa.notify === 'function') {
                    Alfa.notify(Joomla.Text._('COM_ALFA_SETUP_REQUEST_FAILED'), 'error');
                }
            })
            .finally(function () {
                // On success the card node (and this button with it) has already
                // been replaced, so this only matters on the failure path. Restoring
                // a detached node is harmless.
                if (trigger && window.Alfa && Alfa.loader) {
                    Alfa.loader.hideIn(trigger);
                }
            });
    }

    /**
     * Re-fetch the card (fresh evaluation server-side) and swap the node in place.
     */
    function refresh(trigger) {
        requestCard(opts.cardUrl, null, trigger);
    }


    /**
     * One delegated click handler on the document: survives every node swap.
     *
     * @param {Event} event
     */
    function onClick(event) {
        // The title tick lives outside the card (it is the only control that does),
        // so it is answered before the "inside the card" guard below.
        if (event.target.closest(SEL.review)) {
            setState('open');

            return;
        }

        if (!event.target.closest(SEL.root)) {
            return;
        }

        // Row deep links open in the shared iframe modal (the payment/shipment popup
        // pattern) so the merchant never leaves the setup; plain navigation stays
        // the fallback when actions.js isn't around.
        var cta = event.target.closest(SEL.cta);

        if (cta) {
            if (window.Alfa && typeof Alfa.openActionFrame === 'function') {
                event.preventDefault();

                // Opened WHOLE, not with `tmpl=component`: that template carries no
                // toolbar module position, so the screen used to arrive with no Save
                // and no New. The frame hides the navigation chrome with frame.css
                // instead and keeps the toolbar (Alfa.dressActionFrame).
                Alfa.openActionFrame(cta.getAttribute('href'), cta.getAttribute('data-frame-title') || '');
            }

            return;
        }

        var provision = event.target.closest(SEL.provision);

        if (provision) {
            var provisionBody = new URLSearchParams();

            provisionBody.set('check', provision.getAttribute('data-check') || '');

            // A row may ask something before its fix runs (which menu to build in).
            // Matched on the ROW's own class, not on `[data-check]`: the button
            // carries that attribute too, so `closest` answered with the button
            // itself, the `<select>` beside it was never found, and every fix ran
            // with no answers: the merchant picked a menu and the storefront pages
            // went into a freshly created "Alfa Commerce" one regardless.
            var row = provision.closest(SEL.item);

            if (row) {
                row.querySelectorAll('[data-provision-answer]').forEach(function (field) {
                    provisionBody.set('answer[' + field.getAttribute('data-provision-answer') + ']', field.value || '');
                });
            }
            requestCard(opts.provisionUrl, provisionBody, provision);

            return;
        }

        if (event.target.closest(SEL.refresh)) {
            refresh(event.target.closest(SEL.refresh));
        } else if (event.target.closest(SEL.collapse)) {
            setState('collapsed');
        } else if (event.target.closest(SEL.expand)) {
            setState('open');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        opts = window.Joomla && Joomla.getOptions ? Joomla.getOptions(OPTIONS_KEY) : null;

        if (!opts || !root()) {
            return;
        }

        document.addEventListener('click', onClick);

        // Whatever happened inside the iframe modal, the truth may have changed:
        // re-evaluate when it closes so the row ticks itself off immediately.
        document.addEventListener('hidden.bs.modal', function (event) {
            if (event.target && event.target.id === FRAME_MODAL_ID && root()) {
                refresh();
            }
        });
    });
}());
