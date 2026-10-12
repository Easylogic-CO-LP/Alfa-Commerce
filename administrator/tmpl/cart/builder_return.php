<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The cart-line popup's return page: the twin of
 * tmpl/order/edit_order_item_return.php.
 *
 * The controller has already done the work and redirected here. This page
 * reports the outcome to the window that opened the popup: close, and re-render
 * the builder so it shows real, server-rendered state. It decides nothing and
 * carries no cart data; the parent asks the server for that.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Cart\HtmlView $this
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

$input = Factory::getApplication()->getInput();

$this->document->addScriptOptions('alfa.cartline.modal.return', [
    'messageType'  => 'alfa:cart-line-action',
    'cartId'       => (int) $input->getInt('cart_id', 0),
    'lineId'       => (int) $input->getInt('line_id', 0),
    'shouldClose'  => $input->getInt('close', 1) === 1,
    'shouldReload' => $input->getInt('reload', 1) === 1,
]);
?>

<div class="container-fluid">
    <div class="text-center p-5">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden"><?php echo Text::_('COM_ALFA_LOADING'); ?></span>
        </div>

        <p class="text-muted mt-3">
            <?php echo Text::_('COM_ALFA_CLOSING_MODAL'); ?>
        </p>
    </div>
</div>

<script>
    (function () {
        'use strict';

        var dataToReturn = Joomla.getOptions('alfa.cartline.modal.return', {});

        if (window.parent && window.parent !== window) {
            window.parent.postMessage(dataToReturn, window.location.origin);
        }
    }());
</script>
