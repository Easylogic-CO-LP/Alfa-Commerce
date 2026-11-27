<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

/**
 * The order-completed page.
 *
 * It prints one read-only value, $this->confirmation (Site\Service\Order\OrderConfirmation),
 * which the site read service prepared through CartModel::getCompletedOrder(): this file
 * reads nothing else, queries nothing and decides nothing. Null means the order is not
 * available to this visitor, and the page then says only that (the view answers 404).
 *
 * The order itself is the order.detail layout's (layouts/order/detail.php), the block the
 * order page of the customer's account prints too: the facts, Pay now, the gateway's
 * block (left out with the neutral line when its plugin cannot be asked), the parcels'
 * tracking numbers, the lines with the cart.summary layout fed rows the order froze, and
 * the addresses.
 * Print is bound by the page's script (asset com_alfa.order-completed): it opens this same keyed
 * address with tmpl=component and print=1 (Joomla's own print pattern), whose root the view
 * marks data-order-print, and prints that view; the key is never written into the page. The
 * stylesheet prints the lines, the totals and the addresses in black on white and hides the
 * page's own actions. The page's one h1 is the completed message (or the not-available
 * heading), its sections h2; the Items heading is the cart's heading row and label
 * (.cart-lines-heading, .cart-lines-title), so it reads at the cart's Items size.
 *
 * A guest reaches the order on this keyed page alone, from the checkout or from the order's
 * e-mails; a customer logged in is shown the order's page in their account as well
 * ($this->accountOrderUrl) and Order again ($this->reorder, the order.reorder layout).
 * Cancelling is never offered here: it is the account's.
 *
 * Classes the stylesheet reads (media/com_alfa/css/site/order-completed.css):
 *   .order-completed  .order-account-link  .order-completed-actions, and those of the
 *   order.detail and order.reorder layouts
 */

use Alfa\Component\Alfa\Site\Helper\Layout;
use Alfa\Component\Alfa\Site\Service\Order\OrderConfirmation;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

/** @var OrderConfirmation|null $order */
$order = $this->confirmation;

$this->getDocument()->getWebAssetManager()
    ->useStyle('com_alfa.order-completed')
    ->useScript('com_alfa.order-completed');

?>
<div class="cart-wrapper order-completed" data-order-completed<?php echo $this->print ? ' data-order-print' : ''; ?>>
<?php if ($order === null) : ?>

    <h1 class="order-not-available-heading"><?php echo Text::_('COM_ALFA_ORDER_NOT_AVAILABLE_HEADING'); ?></h1>

    <p class="order-not-available"><?php echo Text::_('COM_ALFA_ORDER_NOT_AVAILABLE'); ?></p>

    <?php // The page's own action first and primary, Continue shopping after it, as the process page orders them;
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
        <?php // The order's customer, logged in: the order's page in their account. ?>
        <?php if ($this->accountOrderUrl !== '') : ?>
        <a class="order-account-link" href="<?php echo $this->accountOrderUrl; ?>"><?php echo Text::_('COM_ALFA_ORDER_COMPLETED_IN_ACCOUNT'); ?></a>
        <?php endif; ?>
    </p>

    <?php
    // The order as its customer reads it, the same block the order page of the customer's account prints.
    echo Layout::render(layout: 'order.detail', data: [
        'order' => $order,
        'block' => $this->gatewayBlock,
        'providerUnavailable' => $this->providerUnavailable,
        'payAgainUrl' => $this->payAgainUrl,
        'email' => $order->email,
    ]);
    ?>

    <div class="order-completed-actions">
        <?php // Order again: for the order's customer logged in, as on the order's page in their account. ?>
        <?php if ($this->reorder) : ?>
        <?php echo Layout::render(layout: 'order.reorder', data: ['orderId' => $order->id]); ?>
        <?php endif; ?>
        <button type="button" class="action secondary" data-action="order-print"><?php echo Text::_('COM_ALFA_ORDER_COMPLETED_PRINT'); ?></button>
        <a class="action primary" href="<?php echo Uri::root(); ?>"><?php echo Text::_('COM_ALFA_CART_EMPTY_CONTINUE'); ?></a>
    </div>

<?php endif; ?>
</div>
