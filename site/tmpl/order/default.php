<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

/**
 * One order of the customer's account.
 *
 * The order itself is the order.detail layout's, the block the order completed page prints too (the facts, Pay now,
 * the gateway's block, the parcels' tracking numbers, the lines with the totals, the addresses), from the one
 * read-only value $this->confirmation (Site\Service\Order\OrderConfirmation). Then what the customer may do with it:
 * cancel it where the shop allows ($this->cancel, CustomerCancel::verdict()), confirmed in place with no script (a
 * details element holding the POST form), order it again while the shop sells online (the order.reorder layout),
 * print it, go back to their orders. Null means the order is not theirs, and
 * the page says only that (the view answers 404).
 *
 * The page shares the order completed page's root classes and stylesheet, and its Print (the same address with
 * tmpl=component and print=1, the root marked data-order-print).
 *
 * Classes the stylesheet reads (media/com_alfa/css/site/order-completed.css):
 *   .order-completed  .order-account  .order-number  .order-cancel  .order-cancel-open  .order-cancel-held
 *   .order-completed-actions,
 *   and those of the order.detail layout
 */

use Alfa\Component\Alfa\Site\Helper\Layout;
use Alfa\Component\Alfa\Site\Service\Order\CustomerCancel;
use Alfa\Component\Alfa\Site\Service\Order\OrderConfirmation;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var OrderConfirmation|null $order */
$order = $this->confirmation;
$orders = Route::_('index.php?option=com_alfa&view=orders');

$this->getDocument()->getWebAssetManager()
    ->useStyle('com_alfa.order-completed')
    ->useScript('com_alfa.order-completed');

?>
<div class="com-alfa cart-wrapper order-completed order-account" data-order-completed<?php echo $this->print ? ' data-order-print' : ''; ?>>
<?php if ($order === null) : ?>

    <h1 class="order-not-available-heading"><?php echo Text::_('COM_ALFA_ORDER_NOT_AVAILABLE_HEADING'); ?></h1>

    <p class="order-not-available"><?php echo Text::_('COM_ALFA_ORDER_NOT_AVAILABLE'); ?></p>

    <div class="order-completed-actions">
        <a class="action primary" href="<?php echo $orders; ?>"><?php echo Text::_('COM_ALFA_ORDER_BACK_TO_ORDERS'); ?></a>
    </div>

<?php else : ?>

    <h1 class="order-number"><?php echo $this->escape(Text::sprintf('COM_ALFA_ORDER_HEADING', $order->number)); ?></h1>

    <?php
    echo Layout::render(layout: 'order.detail', data: [
        'order' => $order,
        'block' => $this->gatewayBlock,
        'providerUnavailable' => $this->providerUnavailable,
        'payAgainUrl' => $this->payAgainUrl,
    ]);
    ?>

    <?php // Cancel: only where the shop allows it; confirmed in place, a POST with the form's token. ?>
    <?php if ($this->cancel === CustomerCancel::OPEN) : ?>
    <details class="order-cancel">
        <summary class="action secondary order-cancel-open"><?php echo Text::_('COM_ALFA_ORDER_CANCEL_BUTTON'); ?></summary>
        <form method="post" action="<?php echo Route::_('index.php?option=com_alfa&task=order.cancel'); ?>">
            <p><?php echo Text::_('COM_ALFA_ORDER_CANCEL_QUESTION'); ?></p>
            <input type="hidden" name="id" value="<?php echo (int) $order->id; ?>">
            <input type="hidden" name="status" value="<?php echo (int) $order->statusId; ?>">
            <?php echo HTMLHelper::_('form.token'); ?>
            <button type="submit" class="action danger"><?php echo Text::_('COM_ALFA_ORDER_CANCEL_CONFIRM'); ?></button>
        </form>
    </details>
    <?php elseif ($this->cancel === CustomerCancel::HELD) : ?>
    <p class="order-cancel-held"><?php echo CustomerCancel::sentence(CustomerCancel::HELD); ?></p>
    <?php endif; ?>

    <div class="order-completed-actions">
        <?php if ($this->reorder) : ?>
        <?php echo Layout::render(layout: 'order.reorder', data: ['orderId' => $order->id]); ?>
        <?php endif; ?>
        <button type="button" class="action secondary" data-action="order-print"><?php echo Text::_('COM_ALFA_ORDER_COMPLETED_PRINT'); ?></button>
        <a class="action primary" href="<?php echo $orders; ?>"><?php echo Text::_('COM_ALFA_ORDER_BACK_TO_ORDERS'); ?></a>
    </div>

<?php endif; ?>
</div>
