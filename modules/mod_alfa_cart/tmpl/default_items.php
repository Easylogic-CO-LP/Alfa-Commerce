<?php

use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

if (isset($displayData)) {
    extract($displayData);
}

$cart = ($cart ?? null);
if (!isset($cart)) {
    return;
}

$priceSettings = ($priceSettings ?? null);

$priceSettings = ($priceSettings ?? null);

$data  = $cart->getData();
$items = $data->items ?? [];
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
        <button class="close-btn" aria-label="Close">
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

		<?php if (!empty($items)): ?>
            <ul class="cart-list">
				<?php foreach ($items as $cartItem): ?>
                    <li class="cart-item-outer" data-item-id="<?= $cartItem->id_item; ?>">
                        <div class="cart-item-details">
                            <div class="cart-item-name">
								<?php echo htmlspecialchars($cartItem->data->name); ?>
                            </div>
                            <div class="cart-item-price">
								<?php
                                echo LayoutHelper::render('price', [
                                    'item' => $cartItem->data,  // Pass item data
                                    'settings' => $priceSettings,
                                    'options' => ['show_totals' => true],
                                ], JPATH_ROOT . '/components/com_alfa/layouts');
				    ?>
                            </div>

                        </div>

                        <div class="item-quantity-controls-wrapper">
                            <button class="item-quantity-controls decrement" data-action="cart-item-decrement">-</button>

                            <input class="item-quantity"
                                   type="number"
                                   name="quantity"
                                   data-action="cart-item-quantity"
                                   min="<?php echo $cartItem->data->quantity_min ?? 1; ?>"
                                   max="<?php echo $cartItem->data->stock ?? 999; ?>"
                                   value="<?php echo $cartItem->quantity; ?>">

                            <button class="item-quantity-controls increment" data-action="cart-item-increment">+</button>

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
            <span class="alfa-cart-empty"><?php echo Text::_('MOD_ALFA_CART_EMPTY_MSG'); ?></span>
		<?php endif; ?>
    </div>

    <!-- Footer -->
	<?php if (!empty($items)): ?>
        <div class="cart-footer">

            <!-- Totals Summary -->
            <div class="cart-totals">

                <!-- Shipping Row -->
				<?php //if (!$cart->getShipmentTotal()->isZero()):?>
                    <div class="totals-row shipping-row">
                        <div class="totals-label">
                            <strong><?php echo Text::_('MOD_ALFA_CART_SHIPPING'); ?>:</strong>
                        </div>
                        <div class="totals-value">
							<?php echo $cart->getShipmentTotal()->format(); ?>
                        </div>
                    </div>
				<?php //endif;?>

                <!-- Grand Total Row -->
                <div class="totals-row grand-total-row">
                    <div class="totals-label">
                        <strong><?php echo Text::_('MOD_ALFA_CART_TOTAL_PRICE_TEXT'); ?>:</strong>
                    </div>
                    <div class="totals-value">
                        <strong><?php echo $cart->getGrandTotal()->format(); ?></strong>
                    </div>
                </div>

            </div>

            <!-- Checkout Button -->
            <a href="<?php echo Route::_('index.php?option=com_alfa&view=cart'); ?>"
               class="btn btn-primary btn-checkout">
				<?php echo Text::_('MOD_ALFA_CART_COMPLETE_ORDER_BUTTON'); ?>
            </a>

        </div>
	<?php endif; ?>
</div>