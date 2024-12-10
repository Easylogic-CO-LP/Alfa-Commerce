<?php

// use Joomla\CMS\Factory;
// use Joomla\CMS\HTML\HTMLHelper;

// $price = $displayData;

?>

<div class="add-to-cart-wrapper">
    <div class="item-quantity-controls-wrapper">
        <button class="item-quantity-controls decrement" data-action="decrement">-</button>
        <input class="item-quantity" type="number" name="quantity" min="1" value="1">
        <button class="item-quantity-controls increment" data-action="increment">+</button>
    </div>

    <button class="add-to-cart-btn" data-action="add-to-cart">
        Add to Cart
    </button>

</div>