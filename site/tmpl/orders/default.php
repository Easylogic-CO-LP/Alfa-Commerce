<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

/**
 * My orders: the customer's orders, newest first, each row's number opening the order's own page.
 *
 * It prints what the view prepared ($this->items, OrdersModel::getItems(): number, created, status as a customer reads
 * it, total) and the list's pages; it queries and decides nothing. On a narrow screen each order is a block of
 * labelled facts (the cells' data-label).
 *
 * Classes the stylesheet reads (media/com_alfa/css/site/orders.css):
 *   .orders  .orders-heading  .orders-empty  .orders-list  .orders-number  .orders-date  .orders-status  .orders-total
 *   .orders-pages
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$this->getDocument()->getWebAssetManager()->useStyle('com_alfa.orders');

$columns = [
    'number' => $this->escape(Text::_('COM_ALFA_ORDERS_COL_ORDER')),
    'date' => $this->escape(Text::_('COM_ALFA_ORDERS_COL_DATE')),
    'status' => $this->escape(Text::_('COM_ALFA_ORDERS_COL_STATUS')),
    'total' => $this->escape(Text::_('COM_ALFA_ORDERS_COL_TOTAL')),
];

?>
<div class="com-alfa orders">
    <h1 class="orders-heading"><?php echo $this->escape($this->params->get('page_heading')); ?></h1>

    <?php if ($this->items === []) : ?>
    <p class="orders-empty"><?php echo Text::_('COM_ALFA_ORDERS_EMPTY'); ?></p>
    <?php else : ?>
    <table class="orders-list">
        <thead>
        <tr>
            <th scope="col"><?php echo $columns['number']; ?></th>
            <th scope="col"><?php echo $columns['date']; ?></th>
            <th scope="col"><?php echo $columns['status']; ?></th>
            <th scope="col" class="orders-total"><?php echo $columns['total']; ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($this->items as $order) : ?>
        <tr>
            <td class="orders-number" data-label="<?php echo $columns['number']; ?>">
                <a href="<?php echo Route::_('index.php?option=com_alfa&view=order&id=' . (int) $order->id); ?>"><?php echo $this->escape(Text::sprintf('COM_ALFA_ORDERS_NUMBER', $order->number)); ?></a>
            </td>
            <td class="orders-date" data-label="<?php echo $columns['date']; ?>"><?php echo HTMLHelper::_('date', $order->created, Text::_('DATE_FORMAT_LC4')); ?></td>
            <td class="orders-status" data-label="<?php echo $columns['status']; ?>"><?php echo $this->escape($order->status); ?></td>
            <td class="orders-total" data-label="<?php echo $columns['total']; ?>"><?php echo $order->total->format(); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($this->pagination !== null && $this->pagination->pagesTotal > 1) : ?>
    <nav class="orders-pages" aria-label="<?php echo $this->escape(Text::_('JLIB_HTML_PAGINATION')); ?>">
        <?php echo $this->pagination->getPagesLinks(); ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
</div>
