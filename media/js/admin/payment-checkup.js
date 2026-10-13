/**
 * Payment method checkup: the "Check connection" button.
 *
 * Posts the method id, the variant and the form token to the checkup endpoint
 * and swaps in the card the server draws back. No markup is built here: the
 * server renders the same layout it used on page load.
 *
 * @package     Alfa.Component
 * @subpackage  Administrator
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * @since  2.0.0
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-alfa-checkup-run]');

        if (!button || button.disabled) {
            return;
        }

        var card = button.closest('[data-alfa-checkup]');
        var state = card ? card.querySelector('[data-alfa-checkup-state]') : null;
        var body = new FormData();

        body.append('method', button.dataset.method || '0');
        body.append('variant', button.dataset.variant || 'sandbox');
        body.append('card', card ? card.id : '');
        body.append(button.dataset.token || '', '1');

        button.disabled = true;
        button.setAttribute('aria-busy', 'true');

        fetch(button.dataset.url, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.text();
            })
            .then(function (html) {
                var holder = document.createElement('template');

                holder.innerHTML = html.trim();

                var fresh = holder.content.querySelector('[data-alfa-checkup]');

                if (!card || !fresh) {
                    throw new Error('No checkup card in the answer');
                }

                card.replaceWith(fresh);
            })
            .catch(function () {
                button.disabled = false;
                button.removeAttribute('aria-busy');

                if (state) {
                    state.textContent = button.dataset.error || '';
                }
            });
    });
})();
