<?php

use \Joomla\CMS\Language\Text;

$item = $displayData['item'];
$quantity = $displayData['quantity'];

if(empty($item)) return;

// Condition to show a "zero stock" message if the quantity exceeds stock
$zeroStockShow = !empty($item->stock) && $quantity > $item->stock;
// Condition to show a "low stock" message if the stock left is less than or equal to the low stock threshold
$lowStockShow = !empty($item->stock_low) && $item->stock - $quantity <= $item->stock_low;

// Determine the class to add and message to show based on stock conditions
$stockClass = '';
$stockMessage = '';
if ($zeroStockShow) {
    $stockClass = 'zero-stock';
    $stockMessage = Text::_($item->stock_zero_message);
} elseif ($lowStockShow) {
    $stockClass = 'low-stock';
    $stockMessage = Text::_($item->stock_low_message);
}

// HTML structure
// Ensure that the data-item-stock-info attribute is always included on the outer div
?>

<div class="<?php echo $stockClass; ?>" data-item-stock-info>
    <?php echo $stockMessage; ?>
</div>