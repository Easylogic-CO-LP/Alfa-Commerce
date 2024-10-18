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
                console.log(responseData.message);
            }else{
                alert(responseData.message);
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