<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\Layout;
use Alfa\Component\Alfa\Site\Helper\PriceDisplay;
use Alfa\Component\Alfa\Site\Service\Order\OrderConfirmation;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * ONE ORDER, as its customer reads it: the facts, Pay now while it may be paid again, the gateway's block, the parcels'
 * tracking numbers, the sales documents to download, the lines with the money summary and the addresses. The order completed page and the order page of
 * the customer's account both print it, from the same read-only value (Site\Service\Order\OrderConfirmation); it
 * decides and queries nothing. Override html/layouts/com_alfa/order/detail.php in a template to restructure both pages.
 *
 * Classes the stylesheet reads (media/com_alfa/css/site/order-completed.css):
 *   .order-facts  .order-payment-state  .order-pay-again  .order-confirmation-notice  .order-downloads-notice
 *   .order-provider-unavailable  .order-gateway  .order-parcels  .order-documents  .order-document-date
 *   .order-document-voided  .order-items  .order-addresses  .order-address
 * The lines and their totals are .cart-lines, so they look as the cart's do (cart.css).
 *
 * Display data:
 *   order                OrderConfirmation  the order
 *   block                array|null         what the gateway drew through orderComplete(): one surface descriptor
 *                                           (layout, layoutData, layoutPluginType, layoutPluginName); null for none
 *   providerUnavailable  bool               the gateway of record could not be asked: the neutral line, no block
 *   payAgainUrl          string             the address of Pay now; '' while the order may not be paid again
 *   email                string             the address the confirmation went to, to name it; '' to leave it out
 *
 * @since  2.3.0
 */

/** @var OrderConfirmation $order */
$order = $displayData['order'];
$block = $displayData['block'] ?? null;
$providerUnavailable = (bool) ($displayData['providerUnavailable'] ?? false);
$payAgainUrl = (string) ($displayData['payAgainUrl'] ?? '');
$email = (string) ($displayData['email'] ?? '');

$paymentStates = [
    OrderConfirmation::PAID => 'COM_ALFA_ORDER_COMPLETED_PAID',
    OrderConfirmation::PARTLY_PAID => 'COM_ALFA_ORDER_COMPLETED_PARTLY_PAID',
    OrderConfirmation::AWAITING_PAYMENT => 'COM_ALFA_ORDER_COMPLETED_AWAITING_PAYMENT',
    OrderConfirmation::REFUNDED => 'COM_ALFA_ORDER_COMPLETED_REFUNDED',
];

$columns = [
    'name' => $this->escape(Text::_('COM_ALFA_CART_TABLE_NAME')),
    'quantity' => $this->escape(Text::_('COM_ALFA_CART_TABLE_QUANTITY')),
    'total' => $this->escape(Text::_('COM_ALFA_CART_TABLE_TOTAL')),
];

?>
    <dl class="order-facts">
        <div>
            <dt><?php echo Text::_('COM_ALFA_ORDER_COMPLETED_DATE'); ?></dt>
            <dd><?php echo HTMLHelper::_('date', $order->created, Text::_('DATE_FORMAT_LC2')); ?></dd>
        </div>
        <?php if ($order->status !== '') : ?>
        <div>
            <dt><?php echo Text::_('COM_ALFA_ORDER_COMPLETED_STATUS'); ?></dt>
            <dd><?php echo $this->escape($order->status); ?></dd>
        </div>
        <?php endif; ?>
        <?php if ($order->shipment !== '') : ?>
        <div>
            <dt><?php echo Text::_('COM_ALFA_ORDER_COMPLETED_SHIPMENT'); ?></dt>
            <dd><?php echo $this->escape($order->shipment); ?></dd>
        </div>
        <?php endif; ?>
        <?php if ($order->payment !== '') : ?>
        <div>
            <dt><?php echo Text::_('COM_ALFA_ORDER_COMPLETED_PAYMENT'); ?></dt>
            <dd><?php echo $this->escape($order->payment); ?></dd>
        </div>
        <?php endif; ?>
        <?php if (isset($paymentStates[$order->paymentState])) : ?>
        <div class="order-payment-state">
            <dt><?php echo Text::_('COM_ALFA_ORDER_COMPLETED_PAYMENT_STATE'); ?></dt>
            <dd><?php echo Text::_($paymentStates[$order->paymentState]); ?></dd>
        </div>
        <?php endif; ?>
    </dl>

    <?php // The order still owes money and may be paid again: its payment step, never a new order. ?>
    <?php if ($payAgainUrl !== '') : ?>
    <div class="order-pay-again">
        <p><?php echo Text::_('COM_ALFA_PAY_AGAIN_INTRO'); ?></p>
        <a class="action primary" href="<?php echo $this->escape($payAgainUrl); ?>" rel="nofollow"><?php echo Text::_('COM_ALFA_PAY_AGAIN_BUTTON'); ?></a>
    </div>
    <?php endif; ?>

    <?php // The address the confirmation went to; a guest sees a typo at once. ?>
    <?php if ($email !== '') : ?>
    <p class="order-confirmation-notice"><?php echo Text::sprintf('COM_ALFA_ORDER_COMPLETED_CONFIRMATION_SENT', $this->escape($email)); ?></p>
    <?php endif; ?>

    <?php if ($order->downloads) : ?>
    <p class="order-downloads-notice"><?php echo Text::_('COM_ALFA_ORDER_COMPLETED_DOWNLOADS_EMAILED'); ?></p>
    <?php endif; ?>

    <?php if ($providerUnavailable) : ?>
    <p class="order-provider-unavailable"><?php echo Text::_('COM_ALFA_ORDER_PROVIDER_UNAVAILABLE'); ?></p>
    <?php elseif ($block !== null) : ?>
    <div class="order-gateway">
        <?php
        echo Layout::render(
            $block['layout'],
            $block['layoutData'],
            $block['layoutPluginType'],
            $block['layoutPluginName'],
        );
        ?>
    </div>
    <?php endif; ?>

    <?php if ($order->parcels !== []) : ?>
    <section class="order-parcels">
        <h2><?php echo Text::_('COM_ALFA_ORDER_PARCELS'); ?></h2>
        <dl>
            <?php foreach ($order->parcels as $parcel) : ?>
            <div>
                <dt><?php echo $this->escape($parcel->carrier !== '' ? $parcel->carrier : Text::_('COM_ALFA_ORDER_PARCEL_TRACKING')); ?></dt>
                <dd><?php echo $this->escape($parcel->tracking); ?></dd>
            </div>
            <?php endforeach; ?>
        </dl>
    </section>
    <?php endif; ?>

    <?php if ($order->documents !== []) : ?>
    <section class="order-documents">
        <h2><?php echo Text::_('COM_ALFA_ORDER_DOCUMENTS'); ?></h2>
        <ul>
            <?php foreach ($order->documents as $document) : ?>
            <li>
                <a href="<?php echo $this->escape($document->url); ?>" rel="nofollow"><?php echo $this->escape(trim($document->type . ' ' . $document->number)); ?></a>
                <?php if ($document->date !== '') : ?>
                <span class="order-document-date"><?php echo HTMLHelper::_('date', $document->date, Text::_('DATE_FORMAT_LC4'), null); ?></span>
                <?php endif; ?>
                <?php if ($document->voided) : ?>
                <span class="order-document-voided"><?php echo Text::_('COM_ALFA_ORDER_DOCUMENT_VOIDED'); ?></span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <section class="order-items cart-lines">
        <div class="cart-lines-heading">
            <h2 class="cart-lines-title"><?php echo Text::_('COM_ALFA_ORDER_COMPLETED_ITEMS'); ?></h2>
        </div>
        <table>
            <thead>
            <tr>
                <th><?php echo $columns['name']; ?></th>
                <th><?php echo $columns['quantity']; ?></th>
                <th class="cart-money"><?php echo $columns['total']; ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($order->lines as $line) : ?>
            <tr>
                <td data-label="<?php echo $columns['name']; ?>" class="order-item-name">
                    <?php echo $this->escape($line->name); ?>
                    <?php if ($line->options !== []) : ?>
                    <ul class="order-item-options">
                        <?php foreach ($line->options as $option) : ?>
                        <li><?php echo $this->escape($option->label); ?>: <?php echo $this->escape($option->value); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </td>
                <td data-label="<?php echo $columns['quantity']; ?>" class="order-item-quantity"><?php echo (int) $line->quantity; ?></td>
                <td data-label="<?php echo $columns['total']; ?>" class="order-item-total cart-money"><?php echo PriceDisplay::price(price: $line->total); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php echo Layout::render(layout: 'cart.summary', data: ['rows' => $order->summary, 'label' => Text::_('COM_ALFA_ORDER_COMPLETED_TOTALS')]); ?>
    </section>

    <?php if ($order->delivery !== [] || $order->invoice !== []) : ?>
    <section class="order-addresses">
        <?php foreach (['COM_ALFA_ORDER_COMPLETED_DELIVERY_ADDRESS' => $order->delivery, 'COM_ALFA_ORDER_COMPLETED_INVOICE_ADDRESS' => $order->invoice] as $heading => $fields) : ?>
        <?php if ($fields !== []) : ?>
        <div class="order-address">
            <h2><?php echo Text::_($heading); ?></h2>
            <dl>
                <?php foreach ($fields as $field) : ?>
                <div>
                    <dt><?php echo $this->escape($field->label); ?></dt>
                    <dd><?php echo $this->escape($field->value); ?></dd>
                </div>
                <?php endforeach; ?>
            </dl>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
