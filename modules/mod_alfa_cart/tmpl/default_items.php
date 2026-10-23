<?php

/**
 * @package        Alfa Commerce
 * @author         Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license        GNU General Public License version 3 or later; see LICENSE
 */

use Alfa\Component\Alfa\Administrator\Event\ItemFields\CartSurface;
use Alfa\Component\Alfa\Site\Helper\Layout;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

if (isset($displayData)) {
    extract($displayData);
}

// Nothing to draw without a reading of the cart, and the reading is what this
// layout renders from, so it is the reading that decides.
$snapshot = ($snapshot ?? null);

if ($snapshot === null) {
    return;
}

$priceSettings = ($priceSettings ?? null);

$items = $snapshot->lines;
$itemsCount = count($items);

?>

<div class="mod-alfa-cart-data-inner" data-cart-items="<?php echo $itemsCount; ?>">
    <div class="cart-header">
        <div class="cart-quantity">
			<?php if ($itemsCount > 0): ?>
                <span class="cart-quantity-number"><?php echo $itemsCount; ?></span>
			<?php endif; ?>
            <span class="cart-quantity-label">
                <?php echo Text::plural('MOD_ALFA_CART_NUMBER_OF_ITEMS', $itemsCount); ?>
            </span>
        </div>
        <button class="close-btn" aria-label="<?php echo Text::_('MOD_ALFA_CART_CLOSE'); ?>">
            <svg
                    fill="currentColor"
                    height="24"
                    width="24"
                    viewBox="0 0 320.591 320.591"
                    xmlns="http://www.w3.org/2000/svg">
                <path d="m30.391 318.583c-7.86.457-15.59-2.156-21.56-7.288-11.774-11.844-11.774-30.973 0-42.817l257.812-257.813c12.246-11.459 31.462-10.822 42.921 1.424 10.362 11.074 10.966 28.095 1.414 39.875l-259.331 259.331c-5.893 5.058-13.499 7.666-21.256 7.288z"/>
                <path d="m287.9 318.583c-7.966-.034-15.601-3.196-21.257-8.806l-257.813-257.814c-10.908-12.738-9.425-31.908 3.313-42.817 11.369-9.736 28.136-9.736 39.504 0l259.331 257.813c12.243 11.462 12.876 30.679 1.414 42.922-.456.487-.927.958-1.414 1.414-6.35 5.522-14.707 8.161-23.078 7.288z"/>
            </svg>
        </button>
    </div>
    <div class="cart-body">
		<?php // What the cart said about the change just made: the drawer answers a change with it inside.?>
		<?php echo Layout::render('cart.messages', ['messages' => $messages ?? []]); ?>

		<?php if (!empty($items)): ?>
            <ul class="cart-list">
				<?php foreach ($items as $cartItem): ?>
					<?php
                    // AN UNAVAILABLE LINE IS REMOVE-ONLY HERE TOO.
                    // Its product left the catalogue or its unit is out of stock. The
                    // drawer reads the same lines the cart page does, so the line is
                    // shown, named and explained, its quantity is a number rather than
                    // a control, and the only thing still offered on it is taking it
                    // out. Its money is not in the cart total, so its total is a dash:
                    // a withdrawn line has no price, and a line out of stock keeps its
                    // price, shows the unit and is labelled out of stock, as on the
                    // cart page.
                    $unavailable = !empty($cartItem->unavailable);
				    // A line refused only for its quantity names the smallest one that
				    // is charged, and keeps its stepper so the shopper can set it.
				    $requantify = $unavailable && isset($cartItem->chargeable_from);
				    $outOfStock = $unavailable && \is_object($cartItem->data->price ?? null);
				    ?>
                    <li class="cart-item-outer<?php echo $unavailable ? ' cart-item-unavailable' : ''; ?>" data-item-id="<?= (int) $cartItem->id_item; ?>" data-line-id="<?= (int) $cartItem->id; ?>">
                        <div class="cart-item-details">
                            <div class="cart-item-name">
								<?php echo htmlspecialchars($cartItem->data->name); ?>
                            </div>
							<?php if ($unavailable): ?>
                                <p class="cart-item-unavailable-reason"><?php echo htmlspecialchars((string) ($cartItem->unavailable_reason ?? '')); ?></p>
							<?php endif; ?>
							<?php $cartFieldSurfaces = $cartItem->surfaces[CartSurface::Main->value] ?? []; ?>
							<?php if ($cartFieldSurfaces): ?>
                                <ul class="cart-item-fields">
									<?php foreach ($cartFieldSurfaces as $surface): ?>
                                        <li><?php echo Layout::render($surface['layout'], $surface['layoutData'], $surface['layoutPluginType'], $surface['layoutPluginName']); ?></li>
									<?php endforeach; ?>
                                </ul>
							<?php endif; ?>
                            <div class="cart-item-price">
								<?php if ($unavailable): ?>
									<?php if ($outOfStock): ?>
										<?php
				                        // One unit figure, the one the cart page prints for the line:
				                        // the unit price with tax, before discounts. The module's price
				                        // settings are not applied, since a total or a breakdown they
				                        // switch on would print line money the cart does not count.
				                        echo LayoutHelper::render(
				                            layoutFile: 'item.price',
				                            displayData: [
				                                'item' => $cartItem->data,
				                                'settings' => ['base_price_with_tax_show' => 1],
				                                'options' => ['show_totals' => false],
				                            ],
				                            basePath: JPATH_ROOT . '/components/com_alfa/layouts',
				                        );
									    ?>
									<?php endif; ?>
									<?php echo '&mdash;'; ?>
									<?php if ($outOfStock): ?>
										<span class="cart-item-out-of-stock"><?php echo Text::_(string: 'MOD_ALFA_CART_OUT_OF_STOCK'); ?></span>
									<?php endif; ?>
								<?php else: ?>
									<?php
                                    echo LayoutHelper::render('item.price', [
									    'item' => $cartItem->data,  // Pass item data
									    'settings' => $priceSettings,
									    'options' => ['show_totals' => true],
                                    ], JPATH_ROOT . '/components/com_alfa/layouts');
								    ?>
								<?php endif; ?>
                            </div>

                        </div>

                        <div class="item-quantity-controls-wrapper">
							<?php if ($unavailable && !$requantify): ?>
                                <span class="cart-item-quantity-static"><?php echo (int) $cartItem->quantity; ?></span>
							<?php else: ?>
                            <div class="alfa-stepper">
                                <button class="alfa-stepper-btn decrement" data-action="cart-item-decrement">-
                                </button>

                                <input class="alfa-stepper-input"
                                       type="number"
                                       name="quantity"
                                       data-action="cart-item-quantity"
                                       <?php // The line's smallest sale in whole units, as the cart
								             // page renders it from the hydrator's figures.?>
                                       min="<?php echo $cartItem->sale_minimum ?? 1; ?>"
                                       <?php // The line's canonical maximum, rendered as on the cart page:
								             // empty means no limit, '+' is disabled at the maximum and the
								             // box is read-only at 0. The server clamp enforces it.?>
                                       max="<?php echo $cartItem->quantity_limit ?? ''; ?>"
                                       <?php // The line's step in whole units, as the cart page renders it.?>
                                       data-step="<?php echo $cartItem->sale_increment ?? 1; ?>"
                                       value="<?php echo $cartItem->quantity; ?>"<?php echo ($cartItem->quantity_limit ?? null) === 0 ? ' readonly' : ''; ?>>

                                <button class="alfa-stepper-btn increment" data-action="cart-item-increment"<?php echo isset($cartItem->quantity_limit) && (int) $cartItem->quantity >= $cartItem->quantity_limit ? ' disabled' : ''; ?>>+
                                </button>
                            </div>
							<?php endif; ?>

                            <button class="cart-item-remove" data-action="cart-item-remove">
                                <svg fill="currentColor"
                                     height="20"
                                     width="20"
                                     viewBox="0 0 32 32"
                                     xmlns="http://www.w3.org/2000/svg">
                                    <path d="m3 7h2v20.48a3.53 3.53 0 0 0 3.52 3.52h15a3.53 3.53 0 0 0 3.48-3.52v-20.48h2a1 1 0 0 0 0-2h-26a1 1 0 0 0 0 2zm22 0v20.48a1.52 1.52 0 0 1 -1.52 1.52h-15a1.52 1.52 0 0 1 -1.48-1.52v-20.48z"/>
                                    <path d="m12 3h8a1 1 0 0 0 0-2h-8a1 1 0 0 0 0 2z"/>
                                    <path d="m12.68 25a1 1 0 0 0 1-1v-12a1 1 0 0 0 -2 0v12a1 1 0 0 0 1 1z"/>
                                    <path d="m19.32 25a1 1 0 0 0 1-1v-12a1 1 0 0 0 -2 0v12a1 1 0 0 0 1 1z"/>
                                </svg>
                            </button>
                        </div>
                    </li>
				<?php endforeach; ?>
            </ul>

		<?php else: ?>
            <span class="cart-empty"><?php echo Text::_('MOD_ALFA_CART_EMPTY_MSG'); ?></span>
		<?php endif; ?>
    </div>

    <!-- Footer -->
	<?php if (!empty($items)): ?>
        <div class="cart-footer">

            <!-- Totals Summary -->
            <div class="cart-totals">

                <!-- Shipping Row -->
				<?php //if (!$snapshot->totals->shipment->isZero()):?>
                <div class="totals-row shipping-row">
                    <div class="totals-label">
                        <strong><?php echo Text::_('MOD_ALFA_CART_SHIPPING'); ?>:</strong>
                    </div>
                    <div class="totals-value">
						<?php echo $snapshot->totals->shipment->format(); ?>
                    </div>
                </div>
				<?php //endif;?>

                <!-- Grand Total Row -->
                <div class="totals-row grand-total-row">
                    <div class="totals-label">
                        <strong><?php echo Text::_('MOD_ALFA_CART_TOTAL_PRICE_TEXT'); ?>:</strong>
                    </div>
                    <div class="totals-value">
                        <strong><?php echo $snapshot->totals->grand->format(); ?></strong>
                    </div>
                </div>

            </div>

            <!-- Checkout Button -->
            <a href="<?php echo Route::_('index.php?option=com_alfa&view=cart'); ?>"
               class="cart-checkout">
				<?php echo Text::_('MOD_ALFA_CART_COMPLETE_ORDER_BUTTON'); ?>
            </a>

        </div>
	<?php endif; ?>
</div>