<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Order screen: an order that names no currency.
 *
 * The order is shown so an administrator can see which one it is: its number,
 * its status, who it is for and when it was placed, under the reason nothing
 * can be done with it. No amount is printed and no action is offered: an
 * order with no currency has no money that can be shown, taken or invoiced.
 *
 * Receives:
 *   $this->order            the order without money (OrderModel::getItemWithoutMoney())
 *   $this->currencyRefusal  the sentence to state
 *   $this->statusName       the name of the order's status
 *
 * @since  1.14.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$order = $this->order;
$number = '#' . (int) $order->id . (trim((string) ($order->reference ?? '')) !== '' ? ' - ' . trim((string) $order->reference) : '');
?>

<?php // The form carries the toolbar's Close only. It posts to the order, not to its edit layout: nothing is edited here. ?>
<form action="<?php echo Route::_('index.php?option=com_alfa&view=order&id=' . (int) $order->id); ?>"
      method="post" name="adminForm" id="adminForm">

    <div class="alert alert-danger" role="alert" data-alfa-order-no-currency>
        <?php echo $this->escape($this->currencyRefusal); ?>
    </div>

    <div class="card">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3"><?php echo Text::_('COM_ALFA_TITLE_ORDER'); ?></dt>
                <dd class="col-sm-9"><?php echo $this->escape($number); ?></dd>

                <dt class="col-sm-3"><?php echo Text::_('COM_ALFA_STATUS'); ?></dt>
                <dd class="col-sm-9"><?php echo $this->escape($this->statusName); ?></dd>

                <dt class="col-sm-3"><?php echo Text::_('COM_ALFA_CUSTOMER'); ?></dt>
                <dd class="col-sm-9">
                    <?php echo $this->escape((string) ($order->user_name ?? '')); ?>
                    <?php if ((string) ($order->user_email ?? '') !== '') : ?>
                        <span class="text-muted">- <?php echo $this->escape((string) $order->user_email); ?></span>
                    <?php endif; ?>
                </dd>

                <dt class="col-sm-3"><?php echo Text::_('COM_ALFA_CREATED'); ?></dt>
                <dd class="col-sm-9">
                    <?php echo !empty($order->created) ? HTMLHelper::_('date', $order->created, Text::_('DATE_FORMAT_LC2')) : ''; ?>
                </dd>
            </dl>
        </div>
    </div>

    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
