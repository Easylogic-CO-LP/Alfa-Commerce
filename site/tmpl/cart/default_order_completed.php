<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

/**
 * The order-completed page (2.2.0, design 2.17).
 *
 * It prints one read-only value, $this->confirmation (Site\Service\Order\OrderConfirmation),
 * which the site read service prepared through CartModel::getCompletedOrder(): this file
 * reads nothing else, queries nothing and decides nothing. Null means the order is not
 * available to this visitor, and the page then says only that (the view answers 404).
 *
 * The money is the cart.summary layout's (C15), fed rows the order froze; the lines and their
 * totals are .cart-lines, so they look as the cart's do (cart.css). The gateway's
 * block is its own layout, left out with the neutral line when its plugin cannot be asked.
 * Print is bound by the page's script (asset com_alfa.order-completed): it opens this same keyed
 * address with tmpl=component and print=1 (Joomla's own print pattern), whose root the view
 * marks data-order-print, and prints that view; the key is never written into the page. The
 * stylesheet prints the lines, the totals and the addresses in black on white and hides the
 * page's own actions. The page's one h1 is the completed message (or the not-available
 * heading), its sections h2; the Items heading is the cart's heading row and label
 * (.cart-lines-heading, .cart-lines-title), so it reads at the cart's Items size.
 *
 * Classes the stylesheet reads (media/com_alfa/css/site/order-completed.css):
 *   .order-completed  .order-facts  .order-items  .order-addresses  .order-address
 *   .order-completed-actions
 */

use Alfa\Component\Alfa\Site\Helper\Layout;
use Alfa\Component\Alfa\Site\Service\Order\OrderConfirmation;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

/** @var OrderConfirmation|null $order */
$order = $this->confirmation;

$this->getDocument()->getWebAssetManager()
    ->useStyle('com_alfa.order-completed')
    ->useScript('com_alfa.order-completed');

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
<div class="com-alfa cart-wrapper order-completed" data-order-completed<?php echo $this->print ? ' data-order-print' : ''; ?>>
<?php if ($order === null) : ?>

    <h1 class="order-not-available-heading"><?php echo Text::_('COM_ALFA_ORDER_NOT_AVAILABLE_HEADING'); ?></h1>

    <p class="order-not-available"><?php echo Text::_('COM_ALFA_ORDER_NOT_AVAILABLE'); ?></p>

    <?php // The page's own action first and primary, Continue shopping after it, as the process page orders them (NEW-8);
          // alone, Continue shopping is the page's one way on. ?>
    <div class="order-completed-actions">
        <?php if ($this->loginUrl !== '') : ?>
        <a class="action primary" href="<?php echo $this->loginUrl; ?>"><?php echo Text::_('JLOGIN'); ?></a>
        <?php endif; ?>
        <a class="action <?php echo $this->loginUrl !== '' ? 'secondary' : 'primary'; ?>" href="<?php echo Uri::root(); ?>"><?php echo Text::_('COM_ALFA_CART_EMPTY_CONTINUE'); ?></a>
    </div>

<?php else : ?>

    <h1 class="order-completed-message"><?php echo Text::_('COM_ALFA_ORDER_COMPLETED_MESSAGE'); ?></h1>

    <p class="order-number">
        <?php echo Text::sprintf('COM_ALFA_ORDER_COMPLETED_NUMBER', $this->escape($order->number)); ?>
        <?php // For the logged-in owner, the link to this order in the account goes here once the account's order view exists (design 8.4 item 13). ?>
    </p>

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

    <?php // The address the confirmation went to; a guest sees a typo at once. ?>
    <?php if ($order->email !== '') : ?>
    <p class="order-confirmation-notice"><?php echo Text::sprintf('COM_ALFA_ORDER_COMPLETED_CONFIRMATION_SENT', $this->escape($order->email)); ?></p>
    <?php endif; ?>

    <?php if ($order->downloads) : ?>
    <p class="order-downloads-notice"><?php echo Text::_('COM_ALFA_ORDER_COMPLETED_DOWNLOADS_EMAILED'); ?></p>
    <?php endif; ?>

    <?php if ($this->providerUnavailable) : ?>
    <p class="order-provider-unavailable"><?php echo Text::_('COM_ALFA_ORDER_PROVIDER_UNAVAILABLE'); ?></p>
    <?php elseif (!empty($this->event->onOrderCompleteView)) : ?>
    <div class="order-gateway">
        <?php
        // An order with no payment method has an empty layout, which renders nothing.
        echo Layout::render(
            $this->event->onOrderCompleteView->getLayout(),
            $this->event->onOrderCompleteView->getLayoutData(),
            $this->event->onOrderCompleteView->getLayoutPluginType(),
            $this->event->onOrderCompleteView->getLayoutPluginName(),
        );
        ?>
    </div>
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
                <td data-label="<?php echo $columns['total']; ?>" class="order-item-total cart-money"><?php echo $line->total->format(); ?></td>
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

    <div class="order-completed-actions">
        <button type="button" class="action secondary" data-action="order-print"><?php echo Text::_('COM_ALFA_ORDER_COMPLETED_PRINT'); ?></button>
        <a class="action primary" href="<?php echo Uri::root(); ?>"><?php echo Text::_('COM_ALFA_CART_EMPTY_CONTINUE'); ?></a>
    </div>

<?php endif; ?>
</div>
