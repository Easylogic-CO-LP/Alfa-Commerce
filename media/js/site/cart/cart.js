document.addEventListener("DOMContentLoaded", () => {
    // restoreInputData();

    // Attach event listeners to the cart container
    const cartContainer = document.querySelector('[data-cart-outer]');

    let quantityUpdateController = new AbortController();
    let shipmentUpdateController = new AbortController();
    let paymentUpdateController = new AbortController();

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
    // For input changes or div change
    cartContainer.addEventListener('change',  (event) => {

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

            // event.preventDefault();
            changeQuantity();

        }
        
        // SHIPMENT CHANGED
        if (event.target.matches('[data-cart-shipments],[data-cart-shipments] *') &&
            event.target.type === "radio"
            ) {
                shipmentChanged();
            
        }

        // PAYMENT CHANGED
        if (event.target.matches('[data-cart-payments],[data-cart-payments] *') &&
            event.target.type === "radio"
            ) {
                paymentChanged();
            
        }
        // alert("Hey");
        // Order form input change listener.
        // if(event.target.matches('[data-cart-fields] *') &&
        //     event.target.tagName === "INPUT"
        //     ){
        // if(
        //     event.target.tagName === "INPUT" &&
        //     event.target.name?.startsWith('cartform')
        // ){
        //     // Change user info on DB.
        //     updateInputData(event.target);
        //
        //     // saveInputData(event.target, event.target.name);
        // }

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
                    const cartOuter = document.querySelector('[data-cart-outer]');
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
            if(quantity == -1){
                quantity = cartItem.querySelector('[name="quantity"]').value;
            }
            
        }

        if(quantity<0){ quantity = 0;}
        quantity = parseFloat(quantity);


        if(itemId <= 0){
            console.error('Item id is invalid');
            return;
        }

        // Adding currently selected shipment id.
        // let shipment_id = document.querySelector('input[name="shipment_method"]:checked')
        //     ? document.querySelector('input[name="shipment_method"]:checked').value
        //     : 1;

        // console.log(itemId);
        const params = new URLSearchParams();
        params.append("id_item", itemId);
        params.append('quantity', quantity);
        // params.append("shipment_id", shipment_id);

        // console.log(params);

        const url = '/index.php?option=com_alfa&task=cart.updateQuantity&format=json';

        const options = {
            method: 'POST',
            headers: {
                'X-CSRF-Token': Joomla.getOptions('csrf.token', ''),
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

    async function shipmentChanged(eventTarget){
        
        // Abort the previous request
        if (shipmentUpdateController) {
            shipmentUpdateController.abort();
        }

        // Create a new AbortController instance for the new request
        shipmentUpdateController = new AbortController();

        let clickedElement = event.target;

        let cartOuter = document.querySelector('[data-cart-outer]');
        let cartItems = cartOuter.querySelector('[data-cart-items]');//cart items list
        let cartShipment = cartOuter.querySelector("[data-cart-shipments]");
        let cartPayment = cartOuter.querySelector("[data-cart-payments]");

        if (!cartOuter) {
            console.error('Div with [data-cart-outer] not found');
            return;
        }

        if (!cartItems) {
            console.error('Div with [data-cart-items] not found');
            return;
        }

        let shipmentId = clickedElement.value;

        // console.log(itemId);
        const params = new URLSearchParams();
        params.append("id_shipment", shipmentId);
        // params.append('quantity', quantity);
        // params.append("shipment_id", shipment_id);

        // console.log(params);

        const url = '/index.php?option=com_alfa&task=cart.updateShipment&format=json';

        const options = {
            method: 'POST',
            headers: {
                'X-CSRF-Token': Joomla.getOptions('csrf.token', ''),
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: params,
            signal: shipmentUpdateController.signal // Attach the abort signal to this request
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

                if(responseData.data.items.isEmpty){
                    cartOuter.innerHTML = responseData.data.items.tmpl;
                }else{
                    cartItems.outerHTML = responseData.data.items.tmpl;
                    cartPayment.outerHTML = responseData.data.payments.tmpl;
                    cartShipment.outerHTML = responseData.data.shipments.tmpl;
                }


                const event = new CustomEvent('alfaCartShipmentChanged', {
                    detail: { data: responseData }
                });
                document.dispatchEvent(event);

            }
        } catch (error) {
            if (error.name === 'AbortError') {
                console.log('Previous change shipment request aborted');
            } else {
                console.error('Error:', error);
            }
        }

    }

     async function paymentChanged(eventTarget){
        
        // Abort the previous request
        if (paymentUpdateController) {
            paymentUpdateController.abort();
        }

        // Create a new AbortController instance for the new request
        paymentUpdateController = new AbortController();

        let clickedElement = event.target;

        let cartOuter = document.querySelector('[data-cart-outer]');
        let cartItems = cartOuter.querySelector('[data-cart-items]');//cart items list
        let cartShipment = cartOuter.querySelector("[data-cart-shipments]");
        let cartPayment = cartOuter.querySelector("[data-cart-payments]");

        if (!cartOuter) {
            console.error('Div with [data-cart-outer] not found');
            return;
        }

        if (!cartItems) {
            console.error('Div with [data-cart-items] not found');
            return;
        }

        let paymentId = clickedElement.value;

        // console.log(itemId);
        const params = new URLSearchParams();
        params.append("id_payment", paymentId);
        // params.append('quantity', quantity);
        // params.append("shipment_id", shipment_id);

        // console.log(params);

        const url = '/index.php?option=com_alfa&task=cart.updatePayment&format=json';

        const options = {
            method: 'POST',
            headers: {
                'X-CSRF-Token': Joomla.getOptions('csrf.token', ''),
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: params,
            signal: paymentUpdateController.signal // Attach the abort signal to this request
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
                    cartOuter.innerHTML = responseData.data.items.tmpl;
                }else{
                    cartItems.outerHTML = responseData.data.items.tmpl;
                    cartPayment.outerHTML = responseData.data.payments.tmpl;
                    cartShipment.outerHTML = responseData.data.shipments.tmpl;
                }

                const event = new CustomEvent('alfaCartPaymentChanged', {
                    detail: { data: responseData }
                });
                document.dispatchEvent(event);

            }
        } catch (error) {
            if (error.name === 'AbortError') {
                console.log('Previous change payment request aborted');
            } else {
                console.error('Error:', error);
            }
        }

    }


    // async function updateInputData(target){
    //
    //     // alert('hey');
    //
    //     let url = '/index.php?option=com_alfa&task=cart.updateUserInfo&format=json';
    //
    //     let passedData = {
    //         "fieldName"     : target.name,
    //         "fieldValue"    : target.value
    //     };
    //
    //     fetch(url, {
    //         method: "POST",
    //         headers: {
    //             'X-CSRF-Token': Joomla.getOptions('csrf.token', ''),
    //             "Content-Type": "application/json",
    //         },
    //         body: JSON.stringify(passedData)
    //     })
    //     .then(response => response.json())
    //     .then(response => {
    //
    //         if(response.success === true){
    //             // location.reload();
    //         }
    //         else{
    //             console.log("FAILED");
    //             console.log(response.message);
    //             console.log(response.messages);
    //         }
    //
    //     });
    //
    //
    // }

















    // Saves input field data to history state.
    // function saveInputData(eventTarget, key){
    //     let currentState = history.state ? history.state : {};
    //     currentState[key] = eventTarget.value;
    //     history.replaceState(currentState, "");
    // }
    //
    // function restoreInputData(){
    //     // Adds saved data before page reload to the orderform.
    //     // window.addEventListener("pageshow", () =>{
    //     // Get the input names of all input fields of orderform. (main alfa-commerce checkout form)
    //     let allCartInputs = document.querySelectorAll("[data-cart-outer] form input");
    //
    //     const inputNames = Array.from(allCartInputs)
    //         .filter(element => element.name)
    //         .map(element => element.name);
    //
    //     let prevValue = "";
    //     inputNames.forEach((element, index) => {    // Set the input field's value based on previous history state.
    //         prevValue = history.state?.[element];
    //         if(prevValue)
    //             if(document.querySelector('form input[name="' + element + '"]').type === "radio")
    //                 document.querySelector('form input[name="' + element + '"][value="' + prevValue + '"]').checked = true;
    //             else
    //                 document.querySelector('form input[name="' + element + '"]').value = prevValue;
    //
    //     });
    //     // })
    // }
    

});













