<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/**
 * ORDER AGAIN: the form that puts an order's lines in the cart at today's prices and availability (task order.reorder,
 * a POST with the form's token, for the order's customer logged in). The order page of the customer's account prints
 * it, and so does the order completed page for its customer logged in. Override html/layouts/com_alfa/order/reorder.php
 * in a template to change it on both.
 *
 * Classes the stylesheet reads (media/com_alfa/css/site/order-completed.css): .order-reorder
 *
 * Display data:
 *   orderId  int  the order
 *
 * @since  2.3.0
 */

$orderId = (int) ($displayData['orderId'] ?? 0);

?>
<form class="order-reorder" method="post" action="<?php echo Route::_('index.php?option=com_alfa&task=order.reorder'); ?>">
    <input type="hidden" name="id" value="<?php echo $orderId; ?>">
    <?php echo HTMLHelper::_('form.token'); ?>
    <button type="submit" class="action secondary"><?php echo Text::_('COM_ALFA_REORDER_BUTTON'); ?></button>
</form>
