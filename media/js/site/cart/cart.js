document.addEventListener("DOMContentLoaded", () => {
    // Attach event listeners to the cart container
    const cartContainer = document.querySelector('[data-cart-outer]');

    let quantityUpdateController = new AbortController();

    // Clear cart button listener
    cartContainer.addEventListener('click', (event) => {
        if (event.target.matches('[data-action="cart-clear"], [data-action="cart-clear"] *')) {
            event.preventDefault();
            clearCart();
        }

        // Remove item button listener
        if (event.target.matches('[data-action="cart-item-remove"], [data-action="cart-item-remove"] *')) {
            event.preventDefault();
            removeItem();
        }

        // Decrement item quantity
        if (event.target.matches('[data-action="cart-item-decrement"], [data-action="cart-item-decrement"] *')) {
            let cartItem = event.target.closest('[data-item-id]');
            let inputField = cartItem.querySelector('[data-action="cart-item-quantity"]');
            let quantity = parseFloat(inputField.value);
            let quantityMin = parseFloat(inputField.min);
            let quantityStep = parseFloat(inputField.dataset.step);

            quantity -= quantityStep;

            inputField.value = (quantity <= quantityMin ) ? quantityMin : quantity;

            changeQuantity();
            // Create and dispatch a change event
            // let changeEvent = new Event('change', { bubbles: true });
            // inputField.dispatchEvent(changeEvent); // Dispatch the change event

        }

        // Increment item quantity
        if (event.target.matches('[data-action="cart-item-increment"], [data-action="cart-item-increment"] *')) {
            let cartItem = event.target.closest('[data-item-id]');
            let inputField = cartItem.querySelector('[data-action="cart-item-quantity"]');
            let quantity = parseFloat(inputField.value);
            let quantityMax = inputField.max && !isNaN(inputField.max) ? parseFloat(inputField.max) : null;
            let quantityStep = parseFloat(inputField.dataset.step);

            quantity += quantityStep;

            inputField.value = (quantity >= quantityMax && quantityMax != null) ? quantityMax : quantity;

            changeQuantity();
            // await changeQuantity(itemId, 0);

            // Create and dispatch a change event
            // let changeEvent = new Event('change', {bubbles: true});
            // inputField.dispatchEvent(changeEvent); // Dispatch the change event
        }

    });


    // Clear cart button listener
    cartContainer.addEventListener('change', (event) => {
        
        // Quantity update listener
        if (event.target.matches('[data-action="cart-item-quantity"]')) {
            let cartItem = event.target.closest('[data-item-id]');
            let inputField = cartItem.querySelector('[data-action="cart-item-quantity"]');
            let quantity = parseFloat(inputField.value);
            let quantityMin = parseFloat(inputField.min);
            let quantityMax = inputField.max && !isNaN(inputField.max) ? parseFloat(inputField.max) : null;
            let quantityStep = parseFloat(inputField.dataset.step);

            // If input number is not divisible by step, adjust it
            if (quantity % quantityStep !== 0) {
                quantity = Math.ceil(quantity / quantityStep) * quantityStep;
            }

            // Ensure value doesn't go below the minimum
            if (quantity < quantityMin) {
                quantity = quantityMin;
            }
            // Ensure value doesn't go over the maximum
            else if (quantityMax !== null && quantity > quantityMax) {
                quantity = quantityMax;
            }

            inputField.value = quantity; // Update the input with the adjusted value

            event.preventDefault();
            changeQuantity();
        }

    });


    async function clearCart() {

        let url = '/index.php?option=com_alfa&task=cart.clearCart&format=json';

        const options = {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            // body: params
        };

        try {
            const response = await fetch(url, options);

            if (!response.ok) {
                console.error('Error fetching data', response.statusText);
                throw new Error(response.statusText);
            } else {

                const responseData = await response.json();

                if(responseData.success){
                    const cartOuter = document.querySelector('#cart-outer');
                    cartOuter.innerHTML = responseData.data;
                }else{
                    console.error(responseData.message);
                }

            }
        } catch (error) {
            console.error('Error:', error);
        }
    }

    async function removeItem() {
        let clickedElement = event.target;

        let cartOuter = document.querySelector('[data-cart-outer]');
        let cartItems = clickedElement.closest('[data-cart-items]');//cart items list
        let cartItem = clickedElement.closest('[data-item-id]');//cart item clicked

        if (!cartOuter) {
            console.error('Div with [data-cart-outer] not found');
            return;
        }

        if (!cartItems) {
            console.error('Div with [data-cart-items] not found');
            return;
        }

        if (!cartItem) {
            console.error('Item row with [data-item-id] not found');
            return;
        }

        let itemId = cartItem.getAttribute('data-item-id');
        
        await changeQuantity(itemId, 0);

    }

    async function changeQuantity(itemId=-1 , quantity=-1) {
        // Abort the previous request
        if (quantityUpdateController) {
            quantityUpdateController.abort();
        }

        // Create a new AbortController instance for the new request
        quantityUpdateController = new AbortController();


        let clickedElement = event.target;

        itemId = parseInt(itemId);

        let cartOuter = document.querySelector('[data-cart-outer]');
        let cartItems = clickedElement.closest('[data-cart-items]');//cart items list
        let cartItem = clickedElement.closest('[data-item-id]');//cart item clicked

        if (!cartOuter) {
            console.error('Div with [data-cart-outer] not found');
            return;
        }

        if (!cartItems) {
            console.error('Div with [data-cart-items] not found');
            return;
        }

        if (!cartItem) {
            console.error('Item row with [data-item-id] not found');
            return;
        }

        if(itemId <= 0){
            itemId = cartItem.getAttribute('data-item-id');
            if(quantity==-1){
                quantity = cartItem.querySelector('[name="quantity"]').value;
            }
            
        }

        if(quantity<0){ quantity = 0;}
        quantity = parseFloat(quantity);


        if(itemId <= 0){
            console.error('Item id is invalid');
            return;
        }

        // console.log(itemId);
        const params = new URLSearchParams();
        params.append("id_item", itemId);
        params.append('quantity', quantity);

        const url = '/index.php?option=com_alfa&task=cart.updateQuantity&format=json';

        const options = {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: params,
            signal: quantityUpdateController.signal // Attach the abort signal to this request
        };

        try {
            const response = await fetch(url, options);

            if (!response.ok) {
                console.error('Error fetching data', response.statusText);
                throw new Error(response.statusText);
            } else {

                const responseData = await response.json();

                // let total = parseFloat(responseData.data.total);
                if(!responseData.success) {
                    throw new Error('Operation was not successful : '+responseData.message); // Custom error message
                }

                if(responseData.data.isEmpty){
                    cartOuter.innerHTML = responseData.data.tmpl;
                }else{
                    cartItems.outerHTML = responseData.data.tmpl;
                }

            }
        } catch (error) {
            if (error.name === 'AbortError') {
                console.log('Previous update quantity request aborted');
            } else {
                console.error('Error:', error);
            }
        }

    }

});

