<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * Cart View - Full Table Layout
 *
 * Modern architecture using:
 * - CartItem wrapper structure
 * - PriceResult getter methods
 * - Money objects (no deprecated PriceFormat)
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

$cart = !empty($displayData) ? $displayData : $this->cart;

?>

<div data-cart-items>

    <!-- Items Section -->
    <h4><?php echo Text::_('COM_ALFA_CART_ITEMS_HEADING'); ?></h4>

    <table>
        <thead>
        <tr>
            <th><?php echo Text::_('COM_ALFA_CART_TABLE_NAME'); ?></th>
            <th><?php echo Text::_('COM_ALFA_CART_TABLE_QUANTITY'); ?></th>
            <th><?php echo Text::_('COM_ALFA_CART_TABLE_PRICE'); ?></th>
            <th><?php echo Text::_('COM_ALFA_CART_TABLE_TAX'); ?></th>
            <th><?php echo Text::_('COM_ALFA_CART_TABLE_DISCOUNT'); ?></th>
            <th><?php echo Text::_('COM_ALFA_CART_TABLE_TOTAL'); ?></th>
        </tr>
        </thead>
        <tbody>
		<?php foreach ($cart->getData()->items as $cartItem):

			$tax  = $cartItem->data->price->getTaxPrice();
            $basePrice = $cartItem->data->price->getBasePrice();
			$basePriceWithTax = $basePrice->add($tax);

			$taxTotal  = $cartItem->data->price->getTaxTotal();
			$baseTotal = $cartItem->data->price->getBaseTotal();
			$baseTotalWithTax = $baseTotal->add($taxTotal);

            $discountTotal = $cartItem->data->price->hasDiscount()? $cartItem->data->price->getSavingsTotal()->format():'-';

            $total = $cartItem->data->price->getTotal();

            ?>
            <!--
            CartItem structure:
            - cartItem->id_item (for data-item-id)
            - cartItem->quantity (cart quantity)
            - cartItem->data (complete item with price)
            -->
            <tr class="cart-item-outer" data-item-id="<?php echo $cartItem->id_item; ?>">

                <!-- Name -->
                <td data-label="Name" class="cart-item-col-name cart-item-col">
					<?php echo htmlspecialchars($cartItem->data->name); ?>
                </td>

                <!-- Quantity Controls -->
                <td data-label="Quantity" class="cart-item-col-quantity cart-item-col">
                    <div class="cart-item-quantity-wrapper">
                        <button class="cart-item-quantity-controls decrement"
                                data-action="cart-item-decrement">-</button>

                        <input class="cart-item-quantity"
                               type="number"
                               name="quantity"
                               data-action="cart-item-quantity"
                               min="<?php echo $cartItem->data->quantity_min ?? 1; ?>"
                               max="<?php echo $cartItem->data->quantity_max ?? $cartItem->data->stock; ?>"
                               data-step="<?php echo $cartItem->data->quantity_step ?? 1; ?>"
                               value="<?php echo $cartItem->quantity; ?>">

                        <button class="cart-item-quantity-controls increment"
                                data-action="cart-item-increment">+</button>
                    </div>

                    <button class="cart-item-remove" data-action="cart-item-remove">
                        <svg height="32" viewBox="0 0 32 32" width="32" xmlns="http://www.w3.org/2000/svg">
                            <path d="m3 7h2v20.48a3.53 3.53 0 0 0 3.52 3.52h15a3.53 3.53 0 0 0 3.48-3.52v-20.48h2a1 1 0 0 0 0-2h-26a1 1 0 0 0 0 2zm22 0v20.48a1.52 1.52 0 0 1 -1.52 1.52h-15a1.52 1.52 0 0 1 -1.48-1.52v-20.48z"/>
                            <path d="m12 3h8a1 1 0 0 0 0-2h-8a1 1 0 0 0 0 2z"/>
                            <path d="m12.68 25a1 1 0 0 0 1-1v-12a1 1 0 0 0 -2 0v12a1 1 0 0 0 1 1z"/>
                            <path d="m19.32 25a1 1 0 0 0 1-1v-12a1 1 0 0 0 -2 0v12a1 1 0 0 0 1 1z"/>
                        </svg>
                    </button>
                </td>

                <td data-label="Price" class="cart-item-col-price cart-item-col">
		            <?= $basePriceWithTax->format(false) . ' x ' .$cartItem->quantity.' = '.$baseTotalWithTax->format(); ?>
                </td>

                <td data-label="Tax" class="cart-item-col-tax cart-item-col">
	                <?= $taxTotal->format(); ?>
                </td>

                <td data-label="Discount" class="cart-item-col-discount cart-item-col">
					<?= $discountTotal; ?>
                </td>

                <!-- Line Total (unit price × quantity) -->
                <td data-label="Total" class="cart-item-col-total">
                    <del><?= $baseTotalWithTax->format(); ?></del><br/>
					<span><?= $total->format(); ?></span>
                </td>
            </tr>
		<?php endforeach; ?>
        </tbody>

        <tfoot>
        <!-- Subtotal with discount (if any) -->
        <tr>
            <td colspan="3"></td>
            <td>
			    <?php if (!$cart->getTaxTotal()->isZero()): ?>
				    <?php echo $cart->getTaxTotal()->format(); ?>
			    <?php endif; ?>
            </td>
            <td>
			    <?php if (!$cart->getDiscountTotal()->isZero()): ?>
                    -<?php echo $cart->getDiscountTotal()->format(); ?>
			    <?php endif; ?>
            </td>
            <td>
			    <?php if (!$cart->getDiscountTotal()->isZero()): ?>
                    <del><?php echo $cart->getTotal()->add($cart->getDiscountTotal())->format(); ?></del><br/>
			    <?php endif; ?>
                <span><?php echo $cart->getTotal()->format(); ?></span>
            </td>
        </tr>

        <!-- Shipping -->
        <tr>
            <td colspan="4"></td>
            <td><strong><?php echo Text::_('COM_ALFA_CART_SHIPPING'); ?>:</strong></td>
            <td><?php echo $cart->getShipmentTotal()->format(); ?></td>
        </tr>

        <!-- Grand Total -->
        <tr class="grand-total-row">
            <td colspan="1">
                <button data-action="cart-clear"><?php echo Text::_('COM_ALFA_CART_CLEAR'); ?></button>
            </td>
            <td colspan="3"></td>
            <td><strong><?php echo Text::_('COM_ALFA_CART_TOTAL'); ?>:</strong></td>
            <td><strong><?php echo $cart->getGrandTotal()->format(); ?></strong></td>
        </tr>
        </tfoot>

    </table>

</div>