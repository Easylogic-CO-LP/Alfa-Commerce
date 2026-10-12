/**
 * Alfa Commerce: quantity recalculation.
 *
 * Quantity +/-/typed changes re-price one item without a reload: POST to
 * cart.recalculate and swap that item's [data-item-prices] + [data-item-stock-info].
 *
 * Handlers are DELEGATED on document (not bound on DOMContentLoaded) so they keep
 * working after a catalog card is swapped in place by variant.js.
 */
(function () {
    'use strict';

    // One in-flight controller per item id; aborting one card's
    // re-price must never cancel another card's (catalog listing).
    var controllers = {};

    /**
     * Clamp a quantity input to its step / min / max.
     */
    function clamp(input) {
        var step = parseFloat(input.dataset.step) || 1;
        var min = parseFloat(input.min);
        var max = (input.max !== '' && !isNaN(input.max)) ? parseFloat(input.max) : null;
        var value = parseFloat(input.value);

        if (isNaN(value)) {
            value = !isNaN(min) ? min : step;
        }

        if (step && value % step !== 0) {
            value = Math.ceil(value / step) * step;
        }

        if (!isNaN(min) && value < min) {
            value = min;
        } else if (max !== null && value > max) {
            value = max;
        }

        input.value = value;

        return value;
    }

    /**
     * Re-price the given [data-item-id] container for its current quantity.
     */
    async function recalculate(item) {
        var quantity = item.querySelector('input[name="quantity"]');
        var itemId = item.getAttribute('data-item-id');

        if (!quantity || !itemId) {
            return;
        }

        if (controllers[itemId]) {
            controllers[itemId].abort();
        }

        var controller = new AbortController();
        controllers[itemId] = controller;

        var params = new URLSearchParams();
        params.append('quantity', quantity.value);
        params.append('item_id', itemId);

        var basePath = (Joomla.getOptions('system.paths') || {}).base || '';
        var url = basePath + '/index.php?option=com_alfa&task=cart.recalculate&format=json';

        try {
            var response = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': Joomla.getOptions('csrf.token', ''),
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: params,
                signal: controller.signal
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            var result = await response.json();

            if (!result.success) {
                console.error(result.message);

                return;
            }

            var data = result.data || {};
            var price = item.querySelector('[data-item-prices]');

            if (price && data.price_layout) {
                price.outerHTML = data.price_layout;
            }

            var stock = item.querySelector('[data-item-stock-info]');

            if (stock && data.stock_info_layout) {
                stock.outerHTML = data.stock_info_layout;
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('[AlfaRecalculate] failed:', error);
            }
        }
    }

    // +/- buttons (delegated).
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-action="cart-increment"], [data-action="cart-decrement"]');

        if (!button) {
            return;
        }

        var item = button.closest('[data-item-id]');

        if (!item) {
            return;
        }

        var input = item.querySelector('input[name="quantity"]');

        if (!input) {
            return;
        }

        var step = parseFloat(input.dataset.step) || 1;
        var current = parseFloat(input.value);
        current = isNaN(current) ? (parseFloat(input.min) || step) : current;
        input.value = current + (button.dataset.action === 'cart-increment' ? step : -step);

        clamp(input);
        recalculate(item);
    });

    // Typed quantity (delegated).
    document.addEventListener('change', function (event) {
        var input = event.target.closest('input[name="quantity"]');

        if (!input) {
            return;
        }

        var item = input.closest('[data-item-id]');

        if (!item) {
            return;
        }

        clamp(input);
        recalculate(item);
    });
})();
