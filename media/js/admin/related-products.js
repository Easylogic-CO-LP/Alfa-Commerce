/**
 * Alfa Commerce - a product's Related tab.
 *
 * A row's variant cell lists what its product offers (its combinations, dimensions and options), so when the row's
 * product changes in its picker, the cell is asked for again: the server draws it with the field's own layout
 * (task=item.relatedVariant, the address on the tab's wrapper) and the script swaps it in. The script builds no
 * markup and keeps nothing: a row whose answer fails keeps the cell it had, and the save judges what is posted.
 */
(function () {
    'use strict';

    document.addEventListener('change', function (event) {
        const input = event.target;

        if (!(input instanceof HTMLInputElement) || !input.matches('.related-products .js-input-value')) {
            return;
        }

        const tab = input.closest('.related-products');
        const row = input.closest('.subform-repeatable-group');
        const cell = row ? row.querySelector('[data-related-variant]') : null;
        const control = input.name.replace(/\[item_id\]$/, '');

        if (!tab || !cell || control === input.name) {
            return;
        }

        const address = new URL(tab.dataset.variantUrl, window.location.href);
        address.searchParams.set('item_id', input.value || '0');
        address.searchParams.set('control', control);

        fetch(address.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (answer) {
                if (!answer || !answer.success || !answer.data || typeof answer.data.html !== 'string') {
                    return;
                }

                const drawn = document.createElement('template');
                drawn.innerHTML = answer.data.html;
                const fresh = drawn.content.querySelector('[data-related-variant]');

                if (fresh) {
                    cell.replaceWith(fresh);
                }
            })
            .catch(function () {
                // The cell keeps what it showed; the save judges what is posted.
            });
    });
})();
