<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Cart\CartQuantityPolicy;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Joomla\CMS\Language\Text;

extract($displayData);

if (empty($item)) return;

// Without a quantity, the band judges the smallest sale of the displayed unit: its
// quantity rules in whole units, as the cart applies them, never a raw rule value.
if (empty($quantity))
{
	$activeId = $item->variant->activeCombination->id ?? null;
	$quantity = CartQuantityPolicy::bounds(
		item: $item,
		combinationId: $activeId !== null ? (int) $activeId : null,
	)['minimum'] ?? 1;
}

// The availability status is the model's answer ($item->stockStatus, set by
// VariantHelper::resolve from the displayed unit's ledger through the shared stock
// rule); the layout only maps it to a class and message. No status (an incomplete
// variant selection, or an item no model resolved) says nothing: the container is
// still rendered so a reload can fill it. The low-stock band applies to a tracked
// unit that is in stock, from its ledger salable quantity and resolved threshold.
$status = $item->stockStatus ?? null;

$stockClass   = '';
$stockMessage = '';

if ($status === InventoryAvailabilityService::STATUS_OUT_OF_STOCK)
{
	$stockClass   = 'zero-stock';
	// Per-language editor HTML (resolved via the lang-table COALESCE alias); output raw.
	$stockMessage = $item->stock_zero_message;
}
elseif ($status === InventoryAvailabilityService::STATUS_BACKORDER)
{
	// Sellable, but nothing on hand: a backorder, never the "out of stock" text.
	$stockClass   = 'backorder';
	$stockMessage = Text::_('COM_ALFA_STOCK_INFO_BACKORDER');
}
elseif ($status === InventoryAvailabilityService::STATUS_IN_STOCK
	&& isset($item->stockSalable, $item->stockLowAt)
	&& (float) $item->stockLowAt > 0
	&& (float) $item->stockSalable - (float) $quantity <= (float) $item->stockLowAt)
{
	$stockClass   = 'low-stock';
	$stockMessage = $item->stock_low_message;
}

// HTML structure
// Ensure that the data-item-stock-info attribute is always included on the outer div
?>

<div class="<?php echo $stockClass; ?>" data-item-stock-info>
	<?php echo $stockMessage; ?>
</div>