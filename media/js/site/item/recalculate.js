document.addEventListener("DOMContentLoaded", () => {
    const decrementButtons = document.querySelectorAll('[data-action="decrement"]');
    const incrementButtons = document.querySelectorAll('[data-action="increment"]');
    const quantityInputs = document.querySelectorAll('input[name="quantity"]');
    
    let recalculateAbortController = new AbortController();
    
    // Event listener for decrement button
    decrementButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            let quantityInput = event.target.closest('[data-item-id]').querySelector('input[name="quantity"]');
            let currentValue = parseFloat(quantityInput.value);
            let minValue = parseFloat(quantityInput.min);
            let step = parseFloat(quantityInput.dataset.step);

            currentValue -= step;

            quantityInput.value = (currentValue <= minValue ) ? minValue : currentValue;

            recalculate();  // Trigger recalculation when the value changes

        });
    });

    // Event listener for increment button
    incrementButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            let quantityInput = event.target.closest('[data-item-id]').querySelector('input[name="quantity"]');
            let currentValue = parseFloat(quantityInput.value);
            let maxValue = quantityInput.max && !isNaN(quantityInput.max) ? parseFloat(quantityInput.max) : null;
            let step = parseFloat(quantityInput.dataset.step);

            currentValue += step;

            quantityInput.value = (currentValue >= maxValue && maxValue != null) ? maxValue : currentValue;

            recalculate();  // Trigger recalculation when the value changes

        });
    });

    // Event listener for quantity input field change
    quantityInputs.forEach((button) => {
        button.addEventListener('change', (event) => {
            let quantityInput = event.target.closest('[data-item-id]').querySelector('input[name="quantity"]');
            let currentValue = parseFloat(quantityInput.value);
            let minValue = parseFloat(quantityInput.min);
            let maxValue = quantityInput.max && !isNaN(quantityInput.max) ? parseFloat(quantityInput.max) : null;
            let step = parseFloat(quantityInput.dataset.step);

            // If input number is not divisible by step, adjust it
            if (currentValue % step !== 0) {
                currentValue = Math.ceil(currentValue / step) * step;
            }

            // Ensure value doesn't go below the minimum
            if (currentValue < minValue) {
                currentValue = minValue;
            }
            // Ensure value doesn't go over the maximum
            else if (maxValue !== null && currentValue > maxValue) {
                currentValue = maxValue;
            }

            quantityInput.value = currentValue; // Update the input with the adjusted value

            recalculate(); // Trigger recalculation when the value changes
        });
    });


    async function recalculate() {

        //Aborting previous request
        if(recalculateAbortController) {
            recalculateAbortController.abort();
        }

        recalculateAbortController = new AbortController();

        let item = event.target.closest('[data-item-id]');

        if(!item){
            console.error('div with attribute [data-item-id] not found to update the price');
            return;
        }

        let quantity = item.querySelector('input[name="quantity"]');
        if(!quantity){
            console.error('input[name="quantity"] not found to update the price');
            return;
        }

        let item_id = item.getAttribute('data-item-id');
        if(!item_id){
            console.error('data-item-id value is invalid or not found to update the price');
            return;
        }

        const params = new URLSearchParams();
        params.append("quantity", quantity.value);
        params.append("item_id", item_id);

        let url = '/index.php?option=com_alfa&task=cart.recalculate&format=json';
        const options = {
            method: 'POST',
            headers: {
                'X-CSRF-Token': Joomla.getOptions('csrf.token', ''),
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: params,
            signal: recalculateAbortController.signal
        };

        //makes request to My Component to retrieve the item record.
        let response = await fetch(url, options);

        if (!response.ok) {
            throw new Error('error status' + response.status);
            console.error('Error fetching data:', response.statusText);
            // alert('Failed to recalculate price. Please try again.');
        } else {
            let controllerResponse = await response.json();

            if(!controllerResponse.success){
                console.error(controllerResponse.message);
                return;
            }

            let responseData = controllerResponse.data;

            let priceLayout = responseData.price_layout;
            let stockInfoLayout = responseData.stock_info_layout;

            // Update the UI of the prices
            const productPrice = item.querySelector('[data-item-prices]');
            if (productPrice) {
                productPrice.outerHTML = priceLayout;
            }

            // Update the UI of the stock info
            const productStockInfo = item.querySelector('[data-item-stock-info]');
            if (productStockInfo) {
                productStockInfo.outerHTML = stockInfoLayout;
            }

        }
    }

});

