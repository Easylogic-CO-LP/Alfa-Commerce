<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Language\Text;

extract($displayData);

if (empty($item)) return;

if(empty($quantity)){ $quantity = $item->quantity_min; }

// Condition to show a "zero stock" message if the quantity exceeds stock
$zeroStockShow = !empty($item->stock) && $quantity > $item->stock;
// Condition to show a "low stock" message if the stock left is less than or equal to the low stock threshold
$lowStockShow = !empty($item->stock_low) && $item->stock - $quantity <= $item->stock_low;

// Determine the class to add and message to show based on stock conditions
$stockClass   = '';
$stockMessage = '';
if ($zeroStockShow)
{
	$stockClass   = 'zero-stock';
	$stockMessage = Text::_($item->stock_zero_message);
}
elseif ($lowStockShow)
{
	$stockClass   = 'low-stock';
	$stockMessage = Text::_($item->stock_low_message);
}

// HTML structure
// Ensure that the data-item-stock-info attribute is always included on the outer div
?>

<div class="<?php echo $stockClass; ?>" data-item-stock-info>
	<?php echo $stockMessage; ?>
</div>