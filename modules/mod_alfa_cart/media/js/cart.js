document.addEventListener("DOMContentLoaded", () => {
    const cartOuter = document.querySelector('[data-mod-cart-outer]');
    const cartToggler = cartOuter.querySelector('.cart-toggler');

    const cartItemsWrapper = cartOuter.querySelector('[data-cart-items]').parentElement;

    cartToggler.addEventListener("click", toggleCart, false);
    cartItemsWrapper.addEventListener("transitionend", onTransitionCartEnd, false);
    cartItemsWrapper.addEventListener("click", toggleCart, false);

    if (cartOuter) {
        cartOuter.addEventListener("click", async (event) => {
            let clickedElement = event.target;

            // Remove item from cart
            if (clickedElement && clickedElement.matches('[data-action="cart-item-remove"], [data-action="cart-item-remove"] *')) {
                event.preventDefault();
                const cartItemElement = clickedElement.closest('[data-item-id]');
                await removeItem(cartItemElement);
            }

            if (clickedElement && clickedElement.matches('.close-btn, .close-btn *')) {
                toggleCart();
            }

            if (event.target.matches('[data-action="cart-item-decrement"], [data-action="cart-item-decrement"] *')) {
                let cartItem = event.target.closest('[data-item-id]');
                let inputField = cartItem.querySelector('[data-action="cart-item-quantity"]');
                let quantity = parseInt(inputField.value);

                if (quantity > 1) {
                    inputField.value = quantity - 1;
                    let changeEvent = new Event('change', { bubbles: true });
                    inputField.dispatchEvent(changeEvent);
                }
            }

            if (event.target.matches('[data-action="cart-item-increment"], [data-action="cart-item-increment"] *')) {
                let cartItem = event.target.closest('[data-item-id]');
                let inputField = cartItem.querySelector('[data-action="cart-item-quantity"]');
                let quantity = parseInt(inputField.value);

                inputField.value = quantity + 1;

                // Create and dispatch a change event
                let changeEvent = new Event('change', { bubbles: true });
                inputField.dispatchEvent(changeEvent);
            }
        });

        cartItemsWrapper.addEventListener("change", async (event) => {
            const target = event.target;

            //Handles quantity change
            if (target.matches('[data-action="cart-item-quantity"]')) {
                const cartItemElement = target.closest('[data-item-id]');
                const itemId = cartItemElement.getAttribute('data-item-id');
                const newQuantity = parseInt(target.value, 10);

                //Ensure valid quantity
                if (newQuantity <= 0) {
                    target.value = 1;
                    return;
                }
                const updatedCartData = await changeQuantity(itemId, newQuantity);
            }
        });
    }

    document.addEventListener("alfaProductAdded", async (event) => {
        const url = "/index.php?option=com_ajax&module=alfa_cart&method=reload&format=json";
        
        fetch(url)
            .then((response) => {
                if (!response.ok) {
                    throw new Error("Failed to fetch cart data");
                }
                return response.json();
            })
            .then((data) => {
                if (data.success) {
                    cartOuter.querySelector("[data-cart-items]").outerHTML = data.data.tmpl;

                    const itemCount = data.data.total_items;
                    const totalQuantity = data.data.total_quantity;

                    let counterValue = parseInt(modAlfaCartTogglerCounterValue) === 1 ? itemCount : totalQuantity;
                    cartToggler.setAttribute("data-counter", counterValue);
                } else {
                    console.error("Error updating cart:", data.message);
                }
            })
            .catch((error) => {
                console.error("Error fetching cart data:", error);
            });
    });

    async function removeItem(cartItemElement) {
        if (!cartItemElement) {
            console.error("Item row with [data-item-id] not found");
            return;
        }

        let itemId = cartItemElement.getAttribute("data-item-id");

        // Call changeQuantity with 0 to remove the item
        await changeQuantity(itemId, 0);

        // Fetch updated cart data after removing an item
        const url = "/index.php?option=com_ajax&module=alfa_cart&method=reload&format=json";

        try {
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error("Failed to fetch cart data");
            }
            const data = await response.json();

            if (data.success) {
                cartOuter.querySelector("[data-cart-items]").outerHTML = data.data.tmpl;

                const itemCount = data.data.total_items;
                const totalQuantity = data.data.total_quantity;

                let counterValue = parseInt(modAlfaCartTogglerCounterValue) === 1 ? itemCount : totalQuantity;
                cartToggler.setAttribute("data-counter", counterValue);
            } else {
                console.error("Error updating cart:", data.message);
            }
        } catch (error) {
            console.error("Error fetching cart data:", error);
        }
    }


    async function changeQuantity(itemId = -1, quantity = -1) {
        if (itemId <= 0 || quantity < 0) {
            console.error('Invalid item ID or quantity');
            return;
        }

        const params = new URLSearchParams();
        params.append("id_item", itemId);
        params.append('quantity', quantity);

        const url = '/index.php?option=com_ajax&module=alfa_cart&method=get&format=json';

        const options = {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: params
        };

        try {
            const response = await fetch(url, options);

            if (!response || !response.ok) {
                console.error('Error fetching data', response ? response.statusText : 'No response');
            } else {
                const responseData = await response.json();

                if (!responseData.success) {
                    console.error('Operation was not successful');
                }

                const itemCount = responseData.data.total_items;
                const totalQuantity = responseData.data.total_quantity;

                let counterValue = parseInt(modAlfaCartTogglerCounterValue) === 1 ? itemCount : totalQuantity;
                cartToggler.setAttribute("data-counter", counterValue);
                // Update the cart UI with the response data
                cartOuter.querySelector('[data-cart-items]').outerHTML = responseData.data.tmpl;
            }
        } catch (error) {
            console.error('Error:', error);
        }
    }

    function toggleCart(event = null) {
        if (event && event.target.closest("[data-cart-items]")) return;

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