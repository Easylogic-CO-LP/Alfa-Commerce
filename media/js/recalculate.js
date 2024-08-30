async function recalculate() {

    let quantity = document.querySelector('input[name="quantity"]').value;
    let product_id = document.querySelector('input[name="product_id"]').value;

    
    const params = new URLSearchParams();
    params.append("quantity", quantity);
    params.append("product_id", product_id);

    let url = '/index.php?option=com_alfa&task=item.recalculate&format=json';
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

        // console.log(priceResponse);

        // let data = await response.json();
        
        // Extract prices from the response
        // const { base_price, discounted_price, price_with_tax } = priceResponse.data;

        // Format prices (assuming you want to use currency formatting)
        // const formatCurrency = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });
        // const formatCurrency = new Intl.NumberFormat('el-GR', { style: 'currency', currency: 'EUR' });

        // Update the UI
        const productPrice = document.querySelector('.product-price');
        if (productPrice) {
            productPrice.innerHTML = priceResponse.data;
        }
        // let items = data.data;

        // const productPrice = document.querySelector('.product-price');

        // productPrice.innerHTML = items.price;
    
        // let itemsTable = Array.from(document.getElementsByClassName('item'));

        // items.forEach((item, index) => {
            // let itemTable = itemsTable[index];
            // let currItem = document.querySelector('#item-' + item.id);
            // if (itemTable) {
            //     console.log('Yes I am' + item.name);
            //     let itemTitle =  currItem.querySelector('.title');
            //     if (itemTitle) {
            //         itemTitle.innerHTML = item.name;
            //     }
            //     let itemDescription =  currItem.querySelector('.description');
            //     if (itemDescription) {
            //         itemDescription.innerHTML = item.description;
            //     }
            //     let itemCategories =  currItem.querySelector('.categories');
            //     if (itemCategories) {
            //         itemCategories.innerHTML = item.category_names;
            //     }
            //     let itemManufacturer =  currItem.querySelector('.manufacturers');
            //     if (itemManufacturer) {
            //         itemManufacturer.innerHTML = item.manufacturer_names;
            //     }
            // }
        // });
        // return items;
    }
}


document.addEventListener("DOMContentLoaded", () => {
    const decrementButton = document.querySelector('[data-action="decrement"]');
    const incrementButton = document.querySelector('[data-action="increment"]');
    
    const quantityInput = document.querySelector('input[name="quantity"]');

    // recalculate();

    // Event listener for decrement button
    decrementButton.addEventListener('click', () => {
        let currentValue = parseInt(quantityInput.value);
        if (currentValue > 1) {  // Prevent going below 1
            quantityInput.value = currentValue - 1;
            recalculate();  // Trigger recalculation when the value changes
        }
    });

    // Event listener for increment button
    incrementButton.addEventListener('click', () => {
        let currentValue = parseInt(quantityInput.value);
        quantityInput.value = currentValue + 1;
        recalculate();  // Trigger recalculation when the value changes
    });

    // Event listener for quantity input field change
    quantityInput.addEventListener('change', () => {
        let currentValue = parseInt(quantityInput.value);
        if (currentValue < 1) {
            quantityInput.value = 1;  // Ensure minimum value is 1
        }
        recalculate();  // Trigger recalculation when the value changes
    });
});