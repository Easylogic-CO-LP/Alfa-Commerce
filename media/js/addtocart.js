async function addToCart() {

    let product_id = document.querySelector('input[name="product_id"]').value;
    let quantity = document.querySelector('input[name="quantity"]').value;
    

    
    const params = new URLSearchParams();
    params.append("product_id", product_id);
    params.append("quantity", quantity);

    let url = '/index.php?option=com_alfa&task=item.addToCart&format=json';
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
        console.error('Error fetching data:', response.statusText);
        throw new Error('error status' + response.status);
        // alert('Failed to recalculate price. Please try again.');
    } else {
        // let priceResponse = await response.json();

        // console.log(priceResponse);

        // let data = await response.json();
        
        // Extract prices from the response
        // const { base_price, discounted_price, price_with_tax } = priceResponse.data;

        // Format prices (assuming you want to use currency formatting)
        // const formatCurrency = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });
        // const formatCurrency = new Intl.NumberFormat('el-GR', { style: 'currency', currency: 'EUR' });

        // Update the UI
        // const productPrice = document.querySelector('.product-price');
        // if (productPrice) {
        //     productPrice.innerHTML = priceResponse.data;
        // }
        
    }
}


document.addEventListener("DOMContentLoaded", () => {
    const addToCartBtn = document.querySelector('[data-action="add-to-cart"]');
    
    // Event listener for decrement button
    addToCartBtn.addEventListener('click', () => {
        addToCart();  // Trigger recalculation when the value changes
    });

});