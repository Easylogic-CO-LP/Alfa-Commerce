<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * Layout: Price Display with Breakdown Support
 *
 * Displays formatted price breakdown with configurable visibility settings.
 * Uses PriceResult object for accurate Money calculations.
 *
 * REQUIRED PARAMETERS:
 * @var object $item      Item with price data (must have ->price property)
 * @var array  $settings  Price visibility settings (from PriceSettings helper)
 *
 * OPTIONAL PARAMETERS:
 * @var array  $options   Additional display options:
 *                        - 'show_totals' (bool): Show total amounts instead of per-unit prices
 *                        - 'css_class' (string): Custom CSS class (default: 'item-prices')
 *
 * USAGE:
 * ```php
 * // Product page (per-unit prices)
 * echo LayoutHelper::render('item.price', [
 *     'item' => $product,
 *     'settings' => $priceSettings,
 * ]);
 *
 * // Cart item (total prices)
 * echo LayoutHelper::render('item.price', [
 *     'item' => $cartItem->data,
 *     'settings' => $priceSettings,
 *     'options' => ['show_totals' => true],
 * ]);
 * ```
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Alfa\Component\Alfa\Site\Helper\PriceSettings;
use Alfa\Component\Alfa\Site\Helper\PriceUnits;

// Extract display data
extract($displayData);

// Get price object
$prices = $item->price ?? null;
if (!$prices) {
	return;
}

// Get settings (with automatic fallback)
if (!isset($settings) || empty($settings)) {
	$settings = PriceSettings::get();
}

// Determine display mode (default to per-unit prices)
$showTotals = !empty($options['show_totals']);

// Get CSS class option
$cssClass = 'item-prices' . (!empty($options['css_class']) ? ' ' . trim($options['css_class']) : '');

// Prepare display values
// Construct all price values based on display mode (totals vs per-unit).
// Totals are money. A unit is presentation: each is printed by the one unit
// rule from the facts the result states, for the quantity it was priced at.

$figures = $showTotals
	? [
		'base'          => $prices->getBaseTotal()->format(),
		'discount'      => $prices->getSavingsTotal()->format(),
		'subtotal'      => $prices->getSubtotal()->format(),
		'tax'           => $prices->getTaxTotal()->format(),
		'base_with_tax' => $prices->getBaseTotalWithTax()->format(),
		'final'         => $prices->getTotal()->format(),
	]
	// Only the figures this block shows are printed.
	: PriceUnits::page(price: $prices, figures: array_keys(array_filter([
		'base'          => !empty($settings['base_price_show']),
		'discount'      => !empty($settings['discount_amount_show']) && $prices->hasDiscount(),
		'subtotal'      => !empty($settings['base_price_with_discounts_show']),
		'tax'           => !empty($settings['tax_amount_show']),
		'base_with_tax' => !empty($settings['base_price_with_tax_show']),
		'final'         => !empty($settings['final_price_show']),
	])));

// Common values (same for both modes)
$discountPercent = $prices->getSavingsPercent();
$hasDiscount = $prices->hasDiscount();

?>

<?php
/**
 * Container with data-item-prices attribute
 *
 * CRITICAL: This data attribute is used by JavaScript for:
 * - Dynamic price recalculation when quantity changes
 * - Price updates when product options are selected
 * - Cart interactions and AJAX updates
 *
 * Removing or renaming this attribute will break price functionality!
 */
?>
<div class="<?php echo $cssClass; ?>" data-item-prices data-display-mode="<?php echo $showTotals ? 'totals' : 'unit'; ?>">

	<?php // BASE PRICE (per unit or total, before discounts) ?>
	<?php if (!empty($settings['base_price_show'])): ?>
        <div class="price-base">
			<?php if (!empty($settings['base_price_show_label'])): ?>
                <?php // Totals show the line's base amount; a unit is the base price. ?>
                <span class="price-label"><?php echo Text::_($showTotals ? 'COM_ALFA_PRICE_BREAKDOWN_BASE' : 'COM_ALFA_PRICE_BASE'); ?>:</span>
			<?php endif; ?>
            <span class="price-value"><?php echo $figures['base']; ?></span>
        </div>
	<?php endif; ?>

	<?php // DISCOUNT AMOUNT ?>
	<?php if (!empty($settings['discount_amount_show']) && $hasDiscount): ?>
        <div class="price-discount">
			<?php if (!empty($settings['discount_amount_show_label'])): ?>
                <span class="price-label"><?php echo Text::_('COM_ALFA_PRICE_DISCOUNT'); ?>:</span>
			<?php endif; ?>
            <span class="price-value discount-value">
				-<?php echo $figures['discount']; ?>
				<?php if ($discountPercent > 0): ?>
                    <span class="discount-percent">(-<?php echo $discountPercent; ?>%)</span>
				<?php endif; ?>
			</span>
        </div>
	<?php endif; ?>

	<?php // SUBTOTAL (After discounts, before tax) ?>
	<?php if (!empty($settings['base_price_with_discounts_show'])): ?>
        <div class="price-subtotal">
			<?php if (!empty($settings['base_price_with_discounts_show_label'])): ?>
                <span class="price-label"><?php echo Text::_('COM_ALFA_PRICE_SUBTOTAL'); ?>:</span>
			<?php endif; ?>
            <span class="price-value"><?php echo $figures['subtotal']; ?></span>
        </div>
	<?php endif; ?>

	<?php // TAX AMOUNT ?>
	<?php if (!empty($settings['tax_amount_show'])): ?>
        <div class="price-tax">
			<?php if (!empty($settings['tax_amount_show_label'])): ?>
                <span class="price-label"><?php echo Text::_('COM_ALFA_PRICE_TAX'); ?>:</span>
			<?php endif; ?>
            <span class="price-value"><?php echo $figures['tax']; ?></span>
        </div>
	<?php endif; ?>

	<?php // BASE PRICE WITH TAX (Informational: base + tax, before discounts) ?>
	<?php if (!empty($settings['base_price_with_tax_show'])): ?>
        <div class="price-with-tax">
			<?php if (!empty($settings['base_price_with_tax_show_label'])): ?>
                <span class="price-label"><?php echo Text::_('COM_ALFA_PRICE_WITH_TAX'); ?>:</span>
			<?php endif; ?>
            <span class="price-value"><?php echo $figures['base_with_tax']; ?></span>
        </div>
	<?php endif; ?>

	<?php // FINAL PRICE ?>
	<?php if (!empty($settings['final_price_show'])): ?>
        <div class="price-final">
			<?php if (!empty($settings['final_price_show_label'])): ?>
                <span class="price-label"><?php echo Text::_('COM_ALFA_PRICE_FINAL'); ?>:</span>
			<?php endif; ?>
            <span class="price-value price-final-value">
				<?php echo $figures['final']; ?>
			</span>
        </div>
	<?php endif; ?>

	<?php // PRICE BREAKDOWN ?>
	<?php if (!empty($settings['price_breakdown_show'])): ?>
        <details class="price-breakdown">
            <summary class="price-breakdown-toggle">
				<?php echo Text::_('COM_ALFA_PRICE_BREAKDOWN'); ?>
            </summary>
            <div class="price-breakdown-content">
                <ol class="price-breakdown-steps">
					<?php
					$breakdown = $prices->getBreakdown();
					$steps = $breakdown->toArray()['steps'] ?? [];

					foreach ($steps as $step):
						$operation = $step['operation'] ?? 'set';
						$amount = $step['amount']['formatted'] ?? '';
						$sign = '';

						// Determine operation sign
						if ($operation === 'subtract' && $amount) {
							$sign = '−';
						} elseif ($operation === 'add' && $amount) {
							$sign = '+';
						}
						?>
                        <li class="breakdown-step" data-operation="<?php echo $operation; ?>">
                            <span class="breakdown-description"><?php echo htmlspecialchars($step['description'] ?? ''); ?></span>
                            <span class="breakdown-amount">
                                <?php if ($sign): ?>
                                    <span class="breakdown-sign"><?php echo $sign; ?></span>
                                <?php endif; ?>
                                <span class="breakdown-value"><?php echo $amount; ?></span>
                            </span>
                        </li>
					<?php endforeach; ?>

                    <li class="breakdown-step breakdown-total">
                        <span class="breakdown-description"><?php echo Text::_('COM_ALFA_PRICE_FINAL'); ?></span>
                        <span class="breakdown-amount">
                            <span class="breakdown-value"><?php echo $prices->getTotal()->format(); ?></span>
                        </span>
                    </li>
                </ol>
            </div>
        </details>
	<?php endif; ?>

</div>