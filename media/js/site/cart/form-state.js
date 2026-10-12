/**
 * Alfa Commerce: checkout form state keeper
 *
 * Persists the checkout form's field values to server-side user state on
 * every committed change (the browser fires `change` on blur), so a
 * refresh, a navigation or a closed tab never loses what the customer
 * typed. The server task is the same state the reload pattern reads
 * (CartModel::loadFormData).
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 400;
    var timer = null;

    function save(form) {
        var base = (Joomla.getOptions('system.paths') || {}).base || '';
        var url = base + '/index.php?option=com_alfa&task=cart.saveFormState&format=json';
        var data = new FormData(form);

        // Fire-and-forget; keepalive lets the final save survive tab close.
        fetch(url, { method: 'POST', body: data, keepalive: true, headers: { 'X-CSRF-Token': (window.Joomla && Joomla.getOptions('csrf.token', '')) || '' } }).catch(function () {
            // State persistence is a convenience; never interrupt checkout.
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('cart-form');

        if (!form) {
            return;
        }

        // Delegated on the form root: native `change` bubbles from every
        // control, `alfa:field-change` is the contract for custom widgets
        // that write values programmatically (same pair the showon engine
        // listens to).
        var onFieldChange = function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                save(form);
            }, DEBOUNCE_MS);
        };

        form.addEventListener('change', onFieldChange);
        form.addEventListener('alfa:field-change', onFieldChange);

        // Leaving through another form (coupon, picker) or the page itself
        // must not lose a just-typed field: flush immediately.
        document.addEventListener('submit', function (event) {
            if (event.target !== form) {
                clearTimeout(timer);
                save(form);
            }
        }, true);

        window.addEventListener('pagehide', function () {
            clearTimeout(timer);
            save(form);
        });

        // The picker redirect lands on a fragment, but late layout (cart
        // images/totals above) shifts the target after the browser's native
        // anchor scroll; re-align once everything has settled.
        window.addEventListener('load', function () {
            var target = location.hash && document.getElementById(location.hash.slice(1));

            if (target && form.contains(target)) {
                var top = target.getBoundingClientRect().top
                    + (window.pageYOffset || document.scrollingElement.scrollTop || 0)
                    - Math.round(window.innerHeight / 3);
                window.scrollTo(0, Math.max(0, top));
            }
        });
    });
})();
