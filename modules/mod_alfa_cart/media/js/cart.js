document.addEventListener("DOMContentLoaded", () => {
    const cartOuter = document.querySelector('[data-mod-cart-outer]');

    if (!cartOuter) {
        return;
    }

    const cartToggler = cartOuter.querySelector('.cart-toggler');
    const cartItemsWrapper = cartOuter.querySelector('[data-cart-items]').parentElement;

    // Supersede an in-flight update when a newer one starts, so rapid +/- clicks
    // (which each send the absolute new quantity) can't render out of order.
    let quantityUpdateController = new AbortController();

    cartToggler.addEventListener("click", toggleCart, false);
    cartItemsWrapper.addEventListener("transitionend", onTransitionCartEnd, false);
    cartItemsWrapper.addEventListener("click", toggleCart, false);

    cartOuter.addEventListener("click", async (event) => {
        const clickedElement = event.target;

        // Remove line = set its quantity to 0.
        if (clickedElement.matches('[data-action="cart-item-remove"], [data-action="cart-item-remove"] *')) {
            event.preventDefault();
            const row = clickedElement.closest('[data-line-id]');

            if (row) {
                await changeQuantity(parseInt(row.getAttribute('data-line-id'), 10), 0);
            }

            return;
        }

        if (clickedElement.matches('.close-btn, .close-btn *')) {
            toggleCart();
            return;
        }

        // Decrement to the multiple of the step just below the quantity, never
        // below the smallest sale: at the minimum nothing happens, and the
        // remove button deletes the line.
        if (clickedElement.matches('[data-action="cart-item-decrement"], [data-action="cart-item-decrement"] *')) {
            const input = clickedElement.closest('[data-line-id]').querySelector('[data-action="cart-item-quantity"]');
            const { step, min } = stepperFigures(input);
            const quantity = parseInt(input.value, 10);
            const onStep = floorToStep(quantity, step);
            const lower = onStep < quantity ? onStep : onStep - step;

            if (!isNaN(quantity) && lower >= min) {
                input.value = lower;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }

            return;
        }

        // Increment to the multiple of the step just above the quantity, at
        // least the smallest sale, never past the line's maximum.
        if (clickedElement.matches('[data-action="cart-item-increment"], [data-action="cart-item-increment"] *')) {
            const input = clickedElement.closest('[data-line-id]').querySelector('[data-action="cart-item-quantity"]');
            const { step, min, max } = stepperFigures(input);
            const quantity = parseInt(input.value, 10);
            const onStep = floorToStep(quantity, step);
            const higher = Math.max(onStep + step, min);

            if (!isNaN(quantity) && (max === null || higher <= max)) {
                input.value = higher;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    });

    cartItemsWrapper.addEventListener("change", async (event) => {
        if (!event.target.matches('[data-action="cart-item-quantity"]')) {
            return;
        }

        const row = event.target.closest('[data-line-id]');
        const lineId = parseInt(row.getAttribute('data-line-id'), 10);
        const { step, min, max } = stepperFigures(event.target);
        let quantity = parseInt(event.target.value, 10);

        if (isNaN(quantity) || quantity <= 0) {
            // A cleared or invalid box becomes the smallest sale rather than
            // deleting the line.
            quantity = min;
        } else {
            // Up to a whole multiple of the step, then at least the smallest
            // sale; above the maximum, the largest multiple of the step within
            // it, never below the smallest sale. The server decides in the end.
            const rest = quantity % step;

            if (rest !== 0) {
                quantity += step - rest;
            }

            quantity = Math.max(quantity, min);

            if (max !== null && quantity > max) {
                quantity = Math.max(max - (max % step), min);
            }
        }

        event.target.value = quantity;

        await changeQuantity(lineId, quantity);
    });

    // Re-render the drawer when a product is added elsewhere on the page. With
    // "Open the cart" the drawer is the confirmation: claim the add so the
    // component shows no card, then open once the fresh markup is in.
    document.addEventListener("alfaProductAdded", (event) => {
        const openOnAdd = cartOuter.getAttribute("data-on-add") === "open";

        if (openOnAdd) {
            event.preventDefault();
        }

        reloadCart().then(() => {
            if (openOnAdd) {
                openCart();
            }
        });
    });

    /**
     * The figures a line's stepper moves by, read from the stepper's own
     * attributes: its whole-unit step (data-step) and smallest sale (min), one
     * unit when absent or invalid, and its maximum (max), none when empty.
     */
    function stepperFigures(input) {
        const step = parseInt(input.dataset.step, 10);
        const min = parseInt(input.min, 10);
        const max = input.max === '' ? NaN : parseInt(input.max, 10);

        return {
            step: step > 0 ? step : 1,
            min: min > 0 ? min : 1,
            max: max >= 0 ? max : null,
        };
    }

    /**
     * A line's quantity less its remainder by the step: the quantity itself
     * when it is a multiple of the step, otherwise the multiple just under it.
     * A line keeps a quantity off the step when the merchant changes the rules
     * after it was stored, so the moves start from here and reach the multiple
     * next to it in either direction, never skipping one.
     */
    function floorToStep(quantity, step) {
        return quantity - (quantity % step);
    }

    /**
     * Set a cart LINE's quantity (0 removes it) and re-render the drawer. The cart
     * is line-based: distinct configurations of the same product are distinct
     * lines, so every operation keys on the line id, never the item id.
     */
    async function changeQuantity(lineId, quantity) {
        if (!lineId || lineId <= 0 || quantity < 0) {
            console.error('mod_alfa_cart: invalid line id or quantity');
            return;
        }

        quantityUpdateController.abort();
        quantityUpdateController = new AbortController();

        const params = new URLSearchParams();
        params.append('id_line', lineId);
        params.append('quantity', quantity);

        const basePath = Joomla.getOptions('system.paths').base || '';
        const url = basePath + '/index.php?option=com_ajax&module=alfa_cart&method=setQuantity&format=json';

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': Joomla.getOptions('csrf.token', ''),
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params,
                signal: quantityUpdateController.signal,
            });

            if (!response.ok) {
                console.error('mod_alfa_cart: update request failed', response.statusText);
                return;
            }

            const data = await response.json();

            // A refused change is answered with the drawer as it stands: the
            // line at the quantity the cart kept, and the cart's words inside it.
            if (!data.success) {
                if (data.data && data.data.tmpl) {
                    applyCartResponse(data.data);
                }

                return;
            }

            applyCartResponse(data.data);
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('mod_alfa_cart: update error', error);
            }
        }
    }

    /** Re-fetch and re-render the drawer without mutating the cart. */
    async function reloadCart() {
        const basePath = Joomla.getOptions('system.paths').base || '';
        const url = basePath + '/index.php?option=com_ajax&module=alfa_cart&method=reload&format=json';

        try {
            const response = await fetch(url);

            if (!response.ok) {
                throw new Error(response.statusText);
            }

            const data = await response.json();

            if (data.success) {
                applyCartResponse(data.data);
            }
        } catch (error) {
            console.error('mod_alfa_cart: reload error', error);
        }
    }

    /** Swap in the re-rendered drawer markup and refresh the toggler counter. */
    function applyCartResponse(payload) {
        cartOuter.querySelector('[data-cart-items]').outerHTML = payload.tmpl;

        const useItems = parseInt(modAlfaCartTogglerCounterValue, 10) === 1;
        cartToggler.setAttribute('data-counter', useItems ? payload.total_items : payload.total_quantity);
    }

    /** Open the drawer if it is not already visible. */
    function openCart() {
        if (!cartItemsWrapper.classList.contains("cart--visible")) {
            toggleCart();
        }
    }

    function toggleCart(event = null) {
        if (event && event.target.closest("[data-cart-items]")) {
            return;
        }

        cartItemsWrapper.classList.add("cart--animatable");

        if (!cartItemsWrapper.classList.contains("cart--visible")) {
            document.querySelector("html").classList.add("mod-alfa-cart-no-scroll");
            cartItemsWrapper.classList.add("cart--visible");
        } else {
            document.querySelector("html").classList.remove("mod-alfa-cart-no-scroll");
            cartItemsWrapper.classList.remove("cart--visible");
        }
    }

    function onTransitionCartEnd() {
        cartItemsWrapper.classList.remove("cart--animatable");
    }
});
