async function addToCart() {

    let item = event.target.closest('[data-item-id]');


    if(!item){
        console.error('div with attribute [data-item-id] not found to add to cart');
        return;
    }

    let quantity = item.querySelector('input[name="quantity"]');
    if(!quantity){
        console.error('input[name="quantity"] not found to add to cart');
        return;
    }

    let item_id = item.getAttribute('data-item-id');
    if(!item_id){
        console.error('data-item-id value is invalid or not found to add to cart');
        return;
    }
    
    const params = new URLSearchParams();
    params.append("item_id", item_id);
    params.append("quantity", quantity.value);

    let url = '/index.php?option=com_alfa&task=cart.addToCart&format=json';
    const options = {
        method: 'POST',
        headers: {
            'X-CSRF-Token': Joomla.getOptions('csrf.token', ''),
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: params
    };

    try {
        const response = await fetch(url, options);
        
        if (!response.ok) {
            console.error('Error fetching data', response.statusText);
            throw new Error(response.statusText);
        } else {
            const responseData = await response.json();
            
            if(responseData.success){
                
                // execute custom event alfaProductAdded
                const event = new CustomEvent('alfaProductAdded', {
                    detail: { data: responseData }
                });
                document.dispatchEvent(event);

            }else{

                let warnings = '';
                if (responseData.messages) {
                    warnings = responseData.messages.warning.join('\n');
                }

                // Combine main message and warnings
                const fullMessage = responseData.message + (warnings ? '\n\n' + warnings : '');

                alert(fullMessage); // Display everything
            }
            
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

document.addEventListener("DOMContentLoaded", () => {
    const addToCartBtns = document.querySelectorAll('[data-action="add-to-cart"]');

    // Event listener for decrement button
    addToCartBtns.forEach((button) => {
        button.addEventListener('click', (event) => {
            addToCart();  // Trigger recalculation when the value changes
        });
    });

});