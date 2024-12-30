<?php

$item = $displayData;

// HTML structure of Quantity Controls
// Ensure that the data-action,data-step,min,max attributes is always included
?>

<div class="item-quantity-controls-wrapper">

    <button 
        class="item-quantity-controls decrement"
        data-action="decrement"
        aria-label="Decrement quantity"
    >
        -
    </button>

    <input 
        class="item-quantity"
        type="number"
        name="quantity"
        value="<?php echo $item->quantity_min?>"
        min="<?php echo $item->quantity_min?>"
        max="<?php echo $item->quantity_max;?>"
        data-step="<?php echo $item->quantity_step?>"
    />
    
    <button 
        class="item-quantity-controls increment"
        data-action="increment"
        aria-label="Increment quantity"
    >
        +
    </button>

</div>