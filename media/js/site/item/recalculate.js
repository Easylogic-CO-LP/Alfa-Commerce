async function recalculate() {

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
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: params
    };

    //makes request to My Component to retrieve the item record.
    let response = await fetch(url, options);

    if (!response.ok) {
        throw new Error('error status' + response.status);
        console.error('Error fetching data:', response.statusText);
        // alert('Failed to recalculate price. Please try again.');
    } else {
        let priceResponse = await response.json();

        // Update the UI
        const productPrice = item.querySelector('[data-item-prices]');
        if (productPrice) {
            productPrice.outerHTML = priceResponse.data;
        }

    }
}


document.addEventListener("DOMContentLoaded", () => {
    const decrementButtons = document.querySelectorAll('[data-action="decrement"]');
    const incrementButtons = document.querySelectorAll('[data-action="increment"]');
    
    const quantityInputs = document.querySelectorAll('input[name="quantity"]');

    // recalculate();

    // Event listener for decrement button
    decrementButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            quantityInput = event.target.closest('[data-item-id]').querySelector('input[name="quantity"]');
            let currentValue = parseInt(quantityInput.value);
            if (currentValue > 1) {  // Prevent going below 1
                quantityInput.value = currentValue - 1;
                recalculate();  // Trigger recalculation when the value changes
            }
        });
    });

    // Event listener for increment button
    incrementButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            quantityInput = event.target.closest('[data-item-id]').querySelector('input[name="quantity"]');
            let currentValue = parseInt(quantityInput.value);
            quantityInput.value = currentValue + 1;
            recalculate();  // Trigger recalculation when the value changes
        });
    });

    // Event listener for quantity input field change
    quantityInputs.forEach((button) => {
        button.addEventListener('change', (event) => {
            quantityInput = event.target.closest('[data-item-id]').querySelector('input[name="quantity"]');
            let currentValue = parseInt(quantityInput.value);
            if (currentValue < 1) {
                quantityInput.value = 1;  // Ensure minimum value is 1
            }
            recalculate();  // Trigger recalculation when the value changes
        });
    });
});