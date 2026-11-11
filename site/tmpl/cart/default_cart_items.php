<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

/**
 * Cart items sub-template: the heading row (the Items label, Clear cart at its end), the
 * line-item table (name, quantity, price, tax, discount, total; the money columns are
 * .cart-money), the money summary (layout cart.summary) read as the table's continuation,
 * then the held coupon codes, the coupon form and the rule nudges.
 */

use Alfa\Component\Alfa\Administrator\Event\ItemFields\CartSurface;
use Alfa\Component\Alfa\Site\Helper\Layout;
use Alfa\Component\Alfa\Site\Helper\PriceUnits;
use Alfa\Component\Alfa\Site\Service\Cart\CartSummary;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

// A LOOK, NOT THE CART. One observation, assembled by the caller: this file
// cannot add a line, take a coupon or empty anything, and every number below
// belongs to the same moment.
$snapshot = !empty($displayData) ? $displayData : $this->snapshot;

// One translated name per column, printed by the header and carried by every
// cell for the stacked phone layout, so the two cannot drift apart.
$columnLabels = array_map(
    static fn (string $key): string => htmlspecialchars(Text::_($key), ENT_QUOTES, 'UTF-8'),
    [
        'name'     => 'COM_ALFA_CART_TABLE_NAME',
        'quantity' => 'COM_ALFA_CART_TABLE_QUANTITY',
        'price'    => 'COM_ALFA_CART_TABLE_PRICE',
        'tax'      => 'COM_ALFA_CART_TABLE_TAX',
        'discount' => 'COM_ALFA_CART_TABLE_DISCOUNT',
        'total'    => 'COM_ALFA_CART_TABLE_TOTAL',
    ],
);

?>

<div class="cart-lines" data-cart-items>

    <?php // What the cart said about a change made on the page; empty when the page loads. ?>
    <?php echo Layout::render('cart.messages', ['messages' => []]); ?>

    <?php // The heading row: the Items label at the start edge, Clear cart at the end edge. ?>
    <div class="cart-lines-heading">
        <h4 class="cart-lines-title"><?php echo Text::_('COM_ALFA_CART_ITEMS_HEADING'); ?></h4>
        <button type="button" class="action secondary small" data-action="cart-clear"><?php echo Text::_('COM_ALFA_CART_CLEAR'); ?></button>
    </div>

    <table>
        <thead>
        <tr>
            <th><?php echo $columnLabels['name']; ?></th>
            <th><?php echo $columnLabels['quantity']; ?></th>
            <th class="cart-money"><?php echo $columnLabels['price']; ?></th>
            <th class="cart-money"><?php echo $columnLabels['tax']; ?></th>
            <th class="cart-money"><?php echo $columnLabels['discount']; ?></th>
            <th class="cart-money"><?php echo $columnLabels['total']; ?></th>
        </tr>
        </thead>
        <tbody>
		<?php foreach ($snapshot->lines as $cartItem):

            // An unavailable line is shown, not dropped: it still holds a
            // quantity, still draws the shopper's stock ceiling, and placement
            // refuses the order until they remove it. Its money is not in the
            // totals below, so it prints no line figure: a withdrawn line has no
            // price, and a line out of stock keeps its price and shows the unit.
            $unavailable = !empty($cartItem->unavailable);
            // A line refused only for its quantity names the smallest one that is
            // charged, and keeps its controls so the shopper can set it.
            $requantify = $unavailable && isset($cartItem->chargeable_from);
            $price = \is_object($cartItem->data->price ?? null) ? $cartItem->data->price : null;
            $payable = $price !== null && !$unavailable;
            $outOfStock = $price !== null && $unavailable;

			// The unit before discounts, by the one unit rule from the line's own
			// whole amount and quantity; on a negotiated line, the agreed rate.
			$unitPrice = $price !== null ? PriceUnits::line(price: $price, withTax: true) : null;
			$baseTotalWithTax = $price?->getBaseTotalWithTax();

            // '-' means priced with no discount; the em dash means the column
            // does not apply: the line is unpriced or its money is not counted.
            // The saving is tax included, as every figure of the cart: the struck
            // total less this saving is the line's total.
            $discountTotal = $payable
                ? ($price->hasDiscount() ? $price->getSavingsTotalWithTax()->format() : '-')
                : '-';

            $total = $price?->getTotal();

            ?>
            <tr class="cart-item-outer<?php echo $unavailable ? ' cart-item-unavailable' : ''; ?>" data-item-id="<?php echo $cartItem->id_item; ?>" data-line-id="<?php echo $cartItem->id; ?>">

                <!-- Name + chosen configuration -->
                <td data-label="<?php echo $columnLabels['name']; ?>" class="cart-item-col-name cart-item-col">
					<?php echo htmlspecialchars($cartItem->data->name); ?>
					<?php $cartFieldSurfaces = $cartItem->surfaces[CartSurface::Main->value] ?? []; ?>
					<?php if ($cartFieldSurfaces): ?>
                        <ul class="cart-item-fields">
							<?php foreach ($cartFieldSurfaces as $surface): ?>
                                <li><?php echo Layout::render($surface['layout'], $surface['layoutData'], $surface['layoutPluginType'], $surface['layoutPluginName']); ?></li>
							<?php endforeach; ?>
                        </ul>
					<?php endif; ?>

					<?php if ($unavailable): ?>
                        <p class="cart-item-unavailable-reason"><?php echo htmlspecialchars((string) ($cartItem->unavailable_reason ?? '')); ?></p>
					<?php endif; ?>
					<?php if (!$unavailable || $requantify): ?>
                        <?php // Belongs with the name + chosen configuration it re-opens, not with
                              // the quantity spinner; that column has no room for a labelled control.
                              // An unavailable line is remove-only, so it offers no edit link. ?>
                        <a class="action secondary small cart-item-edit"
                           href="<?php echo Route::_('index.php?option=com_alfa&view=item&id=' . (int) $cartItem->id_item . '&edit_line=' . (int) $cartItem->id); ?>"
                           data-action="cart-item-edit">
                            <?php echo Text::_('COM_ALFA_CART_EDIT'); ?>
                        </a>
					<?php endif; ?>
                </td>

                <!-- Quantity Controls -->
                <td data-label="<?php echo $columnLabels['quantity']; ?>" class="cart-item-col-quantity cart-item-col">
                    <?php // The stepper and the remove button sit in one row of their own, so the
                          // cell stays a table cell on wide screens and stacks like the others on phones. ?>
                    <div class="quantity-control">
					<?php if ($unavailable && !$requantify): ?>
                        <?php // No spinner on an unavailable line: the only action left on
                              // it is taking it out of the cart. ?>
                        <span class="cart-item-quantity-static"><?php echo (int) $cartItem->quantity; ?></span>
					<?php else: ?>
                    <div class="cart-item-quantity-wrapper alfa-stepper">
                        <button class="cart-item-quantity-controls alfa-stepper-btn decrement"
                                data-action="cart-item-decrement">-</button>

                        <input class="cart-item-quantity alfa-stepper-input"
                               type="number"
                               name="quantity"
                               data-action="cart-item-quantity"
                               <?php // The line's smallest sale and step in whole units, as the
                                     // hydrator states them from the quantity rules. ?>
                               min="<?php echo $cartItem->sale_minimum ?? 1; ?>"
                               <?php // The canonical per-line maximum, as the mini-cart renders it;
                                     // empty means nothing limits the line. A client hint the server
                                     // clamp enforces. At the maximum '+' is disabled, so it never
                                     // posts a lower figure; at 0 the box is read-only, since the
                                     // stepper clamps to the maximum and posting 0 removes the line. ?>
                               max="<?php echo $cartItem->quantity_limit ?? ''; ?>"
                               data-step="<?php echo $cartItem->sale_increment ?? 1; ?>"
                               value="<?php echo $cartItem->quantity; ?>"<?php echo ($cartItem->quantity_limit ?? null) === 0 ? ' readonly' : ''; ?>>

                        <button class="cart-item-quantity-controls alfa-stepper-btn increment"
                                data-action="cart-item-increment"<?php echo isset($cartItem->quantity_limit) && (int) $cartItem->quantity >= $cartItem->quantity_limit ? ' disabled' : ''; ?>>+</button>
                    </div>
					<?php endif; ?>

                    <button class="cart-item-remove" data-action="cart-item-remove"
                            title="<?php echo Text::_('COM_ALFA_CART_REMOVE'); ?>"
                            aria-label="<?php echo Text::_('COM_ALFA_CART_REMOVE'); ?>">
                        <svg height="18" viewBox="0 0 32 32" width="18" fill="currentColor" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                            <path d="m3 7h2v20.48a3.53 3.53 0 0 0 3.52 3.52h15a3.53 3.53 0 0 0 3.48-3.52v-20.48h2a1 1 0 0 0 0-2h-26a1 1 0 0 0 0 2zm22 0v20.48a1.52 1.52 0 0 1 -1.52 1.52h-15a1.52 1.52 0 0 1 -1.48-1.52v-20.48z"/>
                            <path d="m12 3h8a1 1 0 0 0 0-2h-8a1 1 0 0 0 0 2z"/>
                            <path d="m12.68 25a1 1 0 0 0 1-1v-12a1 1 0 0 0 -2 0v12a1 1 0 0 0 1 1z"/>
                            <path d="m19.32 25a1 1 0 0 0 1-1v-12a1 1 0 0 0 -2 0v12a1 1 0 0 0 1 1z"/>
                        </svg>
                    </button>
                    </div>
                </td>

                <td data-label="<?php echo $columnLabels['price']; ?>" class="cart-item-col-price cart-item-col cart-money">
		            <?= $price ? $unitPrice : '-'; ?>
                </td>

                <td data-label="<?php echo $columnLabels['tax']; ?>" class="cart-item-col-tax cart-item-col cart-money">
	                <?= $payable ? $price->getTaxTotal()->format() : '-'; ?>
                </td>

                <td data-label="<?php echo $columnLabels['discount']; ?>" class="cart-item-col-discount cart-item-col cart-money">
					<?= $discountTotal; ?>
                </td>

                <!-- Line Total: the line's own money -->
                <td data-label="<?php echo $columnLabels['total']; ?>" class="cart-item-col-total cart-money">
					<?php if ($payable): ?>
                    <?php // A struck "was" total claims a saving, so it is printed only when the line
                          // has one: the same gate the admin statement and the cart summary use. ?>
                    <?php if ($price->hasDiscount()): ?>
                    <del><?= $baseTotalWithTax->format(); ?></del><br/>
                    <?php endif; ?>
					<span><?= $total->format(); ?></span>
					<?php else: ?>
					<span>-</span>
					<?php // A line out of stock says why its total is not counted. ?>
					<?php if ($outOfStock): ?>
					<span class="cart-item-out-of-stock"><?php echo Text::_(string: 'COM_ALFA_ITEM_FIELD_OUT_OF_STOCK'); ?></span>
					<?php endif; ?>
					<?php endif; ?>
                </td>
            </tr>
		<?php endforeach; ?>
        </tbody>
    </table>

    <?php // THE MONEY, OUT OF THE LINES TABLE: the summary rows, prepared by the
          // builder (which rows exist, every figure), right under the table, which it
          // continues. Inside [data-cart-items], so every answer that swaps it brings it. ?>
    <?php echo Layout::render(layout: 'cart.summary', data: [
        'rows' => CartSummary::rows(
            totals: $snapshot->totals,
            rules: $snapshot->rules,
            currency: $snapshot->currency,
            shippingAwaitsChoice: $snapshot->shippingAwaitsChoice(),
        ),
        'label' => Text::_('COM_ALFA_CART_SUMMARY_LABEL'),
    ]); ?>

	<?php
	// EVERY HELD CODE THE ROWS ABOVE DID NOT ALREADY GIVE A REMOVE CONTROL.
	//
	// The cart holds codes now, so every one of them needs a way out or it is
	// stuck for the life of the cart. Two kinds have no control above:
	//
	//   * a code stored as CONDITIONS_NOT_MET: real code, conditions not met
	//     yet, so it never enters the applied rules at all;
	//   * a SHIPPING-class code: it IS applying, but the summary gives it no rule
	//     row (the discount is shown on the shipping row instead), and the remove
	//     form lives inside the row it skips.
	//
	// So the exclusion is not "applied"; it is "already removable above".
	$codesWithARemoveControl = [];

	foreach ($snapshot->rules->applied as $appliedRule) {
		if ($appliedRule->code !== null && $appliedRule->discountClass !== 'shipping') {
			$codesWithARemoveControl[] = strtoupper((string) $appliedRule->code);
		}
	}

	// Uppercased on both sides: the shopper's entry is uppercased on the way in,
	// while the merchant's rule code is whatever they typed.
	$heldCouponCodes = array_diff(
		array_map('strtoupper', $snapshot->rules->codes),
		$codesWithARemoveControl,
	);
	?>
	<?php if ($heldCouponCodes !== []): ?>
    <ul class="cart-coupon-held">
		<?php foreach ($heldCouponCodes as $heldCouponCode): ?>
        <li class="cart-coupon-held-row">
            <span class="cart-coupon-held-code"><?php echo htmlspecialchars($heldCouponCode, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="cart-coupon-held-note"><?php echo Text::_('COM_ALFA_CART_COUPON_HELD'); ?></span>
            <form action="<?php echo Route::_('index.php?option=com_alfa&task=cart.removeCoupon'); ?>"
                  method="post" class="cart-rule-remove-form">
                <input type="hidden" name="coupon_code" value="<?php echo htmlspecialchars($heldCouponCode, ENT_QUOTES, 'UTF-8'); ?>"/>
                <?php $heldRemoveLabel = htmlspecialchars(Text::sprintf('COM_ALFA_CART_COUPON_REMOVE', $heldCouponCode), ENT_QUOTES, 'UTF-8'); ?>
                <button type="submit" class="cart-rule-remove"
                        title="<?php echo $heldRemoveLabel; ?>"
                        aria-label="<?php echo $heldRemoveLabel; ?>">&times;</button>
				<?php echo HTMLHelper::_('form.token'); ?>
            </form>
        </li>
		<?php endforeach; ?>
    </ul>
	<?php endif; ?>

    <!-- Coupon code: its own small form (activation only; automatic rules
         need nothing) -->
    <form action="<?php echo Route::_('index.php?option=com_alfa&task=cart.applyCoupon'); ?>" method="post" class="cart-coupon-form">
        <label for="cart-coupon-code"><?php echo Text::_('COM_ALFA_CART_COUPON_LABEL'); ?></label>
        <input type="text" class="form-control" id="cart-coupon-code" name="coupon_code" value="" autocomplete="off"
               placeholder="<?php echo Text::_('COM_ALFA_CART_COUPON_PLACEHOLDER'); ?>"/>
        <button type="submit" class="action secondary small"><?php echo Text::_('COM_ALFA_CART_COUPON_APPLY'); ?></button>
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>

    <!-- Near-miss rule nudges: milestone bars + checklists from the engine's
         distance-to-eligibility structure -->
    <?php echo Layout::render('cart.rules_progress', [
        'progress' => $snapshot->rules->progress,
        'currency' => $snapshot->currency,
    ]); ?>

</div>