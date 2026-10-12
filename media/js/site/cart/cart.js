document.addEventListener("DOMContentLoaded", () => {
    // restoreInputData();

    // Attach event listeners to the cart container
    const cartContainer = document.querySelector('[data-cart-outer]');

    // NO CART MARKUP ON THIS PAGE, SO NOTHING TO WIRE.
    //
    // This file is not only the cart page's: a carrier's locker picker declares it
    // as a dependency because it listens for the alfaCartShipmentChanged /
    // alfaCartPaymentChanged / alfaCartMethodsRefreshed events dispatched below.
    // So it legitimately loads wherever that picker renders: an item page, the
    // shop listing, and the administrator's order builder, none of which carry a
    // cart container. Both listeners in this handler hang off that one element,
    // and reaching for it threw a TypeError on every one of those pages.
    if (!cartContainer) {
        return;
    }

    let quantityUpdateController = new AbortController();
    let shipmentUpdateController = new AbortController();
    let paymentUpdateController = new AbortController();
    let methodsRefreshController = new AbortController();

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

        // Decrement to the multiple of the step just below the quantity, never
        // below the smallest sale: at the minimum nothing happens and nothing
        // is posted, and the remove button deletes the line.
        if (event.target.matches('[data-action="cart-item-decrement"], [data-action="cart-item-decrement"] *')) {
            let cartItem = event.target.closest('[data-item-id]');
            let inputField = cartItem.querySelector('[data-action="cart-item-quantity"]');
            const { step, min } = stepperFigures(inputField);
            const quantity = parseInt(inputField.value, 10);
            const onStep = floorToStep(quantity, step);
            const lower = onStep < quantity ? onStep : onStep - step;

            if (!isNaN(quantity) && lower >= min) {
                inputField.value = saleQuantity(inputField, lower);

                changeQuantity();
            }
            // Create and dispatch a change event
            // let changeEvent = new Event('change', { bubbles: true });
            // inputField.dispatchEvent(changeEvent); // Dispatch the change event

        }

        // Increment to the multiple of the step just above the quantity, at
        // least the smallest sale, never past the line's maximum: at the
        // maximum nothing happens and nothing is posted.
        if (event.target.matches('[data-action="cart-item-increment"], [data-action="cart-item-increment"] *')) {
            let cartItem = event.target.closest('[data-item-id]');
            let inputField = cartItem.querySelector('[data-action="cart-item-quantity"]');
            const { step, min, max } = stepperFigures(inputField);
            const quantity = parseInt(inputField.value, 10);
            const onStep = floorToStep(quantity, step);
            const higher = Math.max(onStep + step, min);

            if (!isNaN(quantity) && (max === null || higher <= max)) {
                inputField.value = saleQuantity(inputField, higher);

                changeQuantity();
            }
            // await changeQuantity(itemId, 0);

            // Create and dispatch a change event
            // let changeEvent = new Event('change', {bubbles: true});
            // inputField.dispatchEvent(changeEvent); // Dispatch the change event
        }

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
     * The quantity a line is asked to hold for a figure, typed into its box or
     * reached by a press: the smallest sale for an empty, invalid or
     * non-positive figure, so a cleared box never deletes the line; otherwise
     * the figure in whole units, up to a whole multiple of the step, at least
     * the smallest sale and, above the maximum, the largest multiple of the
     * step within it, never below the smallest sale. The server decides in
     * the end.
     */
    function saleQuantity(input, figure) {
        const { step, min, max } = stepperFigures(input);
        let quantity = parseInt(figure, 10);

        if (isNaN(quantity) || quantity <= 0) {
            quantity = min;
        } else {
            const rest = quantity % step;

            if (rest !== 0) {
                quantity += step - rest;
            }

            quantity = Math.max(quantity, min);

            if (max !== null && quantity > max) {
                quantity = Math.max(max - (max % step), min);
            }
        }

        return quantity;
    }


    // Clear cart button listener
    // For input changes or div change
    cartContainer.addEventListener('change',  (event) => {

        // Quantity update listener
        if (event.target.matches('[data-action="cart-item-quantity"]')) {
            let cartItem = event.target.closest('[data-item-id]');
            let inputField = cartItem.querySelector('[data-action="cart-item-quantity"]');

            // The typed figure becomes the quantity the line is asked to hold.
            inputField.value = saleQuantity(inputField, inputField.value);

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

        // DELIVERY PLACE CHANGED: the offered methods and shipping costs depend on it
        if (event.target.matches('[data-alfa-delivery-place]')) {
            deliveryPlaceChanged(event.target);
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

        const basePath = Joomla.getOptions('system.paths').base || '';
        let url = basePath + '/index.php?option=com_alfa&task=cart.clearCart&format=json';

        const options = {
            method: 'POST',
            headers: {
                'X-CSRF-Token': Joomla.getOptions('csrf.token', ''),
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

        let lineId = cartItem.getAttribute('data-line-id');

        await changeQuantity(lineId, 0);

    }

    async function changeQuantity(lineId=-1 , quantity=-1) {
        // Abort the previous request
        if (quantityUpdateController) {
            quantityUpdateController.abort();
        }

        // Create a new AbortController instance for the new request
        quantityUpdateController = new AbortController();


        let clickedElement = event.target;

        lineId = parseInt(lineId);

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

        if(lineId <= 0){
            lineId = cartItem.getAttribute('data-line-id');
            if(quantity == -1){
                quantity = cartItem.querySelector('[name="quantity"]').value;
            }

        }

        if(quantity<0){ quantity = 0;}
        quantity = parseFloat(quantity);


        if(lineId <= 0){
            console.error('Line id is invalid');
            return;
        }

        const params = new URLSearchParams();
        params.append("id_line", lineId);
        params.append('quantity', quantity);

        const basePath = Joomla.getOptions('system.paths').base || '';
        let url = basePath + '/index.php?option=com_alfa&task=cart.updateQuantity&format=json';

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

                // A refused change is answered with the cart as it stands and
                // with what the cart said about it: the line shows the quantity
                // the cart kept, and the words go in the cart's message slot.
                if (!responseData.success) {
                    if (responseData.data && responseData.data.items) {
                        applyMethodsResponse(cartOuter, responseData);
                    }

                    showMessages(cartOuter, responseData);

                    return;
                }

                // The reading came back settled, so the method panels are
                // redrawn with the items: a carrier's parcel limits or a
                // payment method's ceiling may no longer be met at the new
                // quantity, and a panel left standing would still offer them.
                applyMethodsResponse(cartOuter, responseData);
                showMessages(cartOuter, responseData);

                document.dispatchEvent(new CustomEvent('alfaCartMethodsRefreshed', {
                    detail: { data: responseData }
                }));
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
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

        const basePath = Joomla.getOptions('system.paths').base || '';
        let url = basePath + '/index.php?option=com_alfa&task=cart.updateShipment&format=json';

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

                applyMethodsResponse(cartOuter, responseData);


                const event = new CustomEvent('alfaCartShipmentChanged', {
                    detail: { data: responseData }
                });
                document.dispatchEvent(event);

            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('Error:', error);
            }
        }

    }

    // Swap the server-rendered sections of a method change. A section the page
    // does not show (the store offers no methods of that kind) has no container
    // and is skipped.
    function applyMethodsResponse(cartOuter, responseData) {
        if (responseData.data.items.isEmpty) {
            cartOuter.innerHTML = responseData.data.items.tmpl;
            return;
        }

        const cartItems = cartOuter.querySelector('[data-cart-items]');
        const cartShipment = cartOuter.querySelector('[data-cart-shipments]');
        const cartPayment = cartOuter.querySelector('[data-cart-payments]');

        if (cartItems) {
            cartItems.outerHTML = responseData.data.items.tmpl;
        }
        if (cartShipment && responseData.data.shipments) {
            cartShipment.outerHTML = responseData.data.shipments.tmpl;
        }
        if (cartPayment && responseData.data.payments) {
            cartPayment.outerHTML = responseData.data.payments.tmpl;
        }
    }

    /**
     * Put what the server rendered for the cart's message slot in its place:
     * the fragment replaces the slot, so a change that said nothing clears what
     * the one before it said. A layout without the slot shows nothing, and the
     * cart still reads as the server answered.
     */
    function showMessages(cartOuter, responseData) {
        const slot = cartOuter.querySelector('[data-cart-messages]');

        if (slot && responseData.data && typeof responseData.data.messages === 'string') {
            slot.outerHTML = responseData.data.messages;
        }
    }

    // The delivery place changed: the whole form goes to the server (it is
    // kept as the form state the page reload reads) and the method lists and
    // shipping cost come back re-rendered for the new place.
    async function deliveryPlaceChanged(field) {
        const form = field.closest('form');
        const cartOuter = document.querySelector('[data-cart-outer]');

        if (!form || !cartOuter) {
            return;
        }

        if (methodsRefreshController) {
            methodsRefreshController.abort();
        }

        methodsRefreshController = new AbortController();

        const basePath = Joomla.getOptions('system.paths').base || '';
        const url = basePath + '/index.php?option=com_alfa&task=cart.refreshMethods&format=json';

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': Joomla.getOptions('csrf.token', ''),
                },
                body: new FormData(form),
                signal: methodsRefreshController.signal
            });

            if (!response.ok) {
                throw new Error(response.statusText);
            }

            const responseData = await response.json();

            if (!responseData.success) {
                throw new Error('Operation was not successful : ' + responseData.message);
            }

            applyMethodsResponse(cartOuter, responseData);

            document.dispatchEvent(new CustomEvent('alfaCartMethodsRefreshed', {
                detail: { data: responseData }
            }));
        } catch (error) {
            if (error.name !== 'AbortError') {
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

        const basePath = Joomla.getOptions('system.paths').base || '';
        let url = basePath + '/index.php?option=com_alfa&task=cart.updatePayment&format=json';

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

                applyMethodsResponse(cartOuter, responseData);

                const event = new CustomEvent('alfaCartPaymentChanged', {
                    detail: { data: responseData }
                });
                document.dispatchEvent(event);

            }
        } catch (error) {
            if (error.name !== 'AbortError') {
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













