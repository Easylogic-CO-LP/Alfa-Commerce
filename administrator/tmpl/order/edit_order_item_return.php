<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * Order Item Return Page
 * Shows after Save & Close or Delete
 * Sends postMessage to parent with:
 * - action: 'saved' or 'deleted'
 * - shouldClose: true (parent should close modal)
 * - shouldReload: true (parent should reload page)
 *
 * Mirrors edit_payment_return.php pattern exactly
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

$app   = Factory::getApplication();
$input = $app->input;

$shouldClose  = $input->getInt('close', 1) == 1;
$shouldReload = $input->getInt('reload', 1) == 1;

$returnData = [
	'messageType'  => 'alfa:order-item-action',
	'action'       => '',
	'orderId'      => isset($this->order) ? $this->order->id : 0,
	'itemId'       => isset($this->orderItem) ? $this->orderItem->id : 0,
	'shouldClose'  => $shouldClose,
	'shouldReload' => $shouldReload,
	'messages'     => '',
	'timestamp'    => date('c')
];

$this->document->addScriptOptions('alfa.orderitem.modal.return', $returnData);

$message = "SUCCESS";
?>

<div class="container-fluid">
    <div class="text-center p-5">

        <!-- Success Message -->
        <h3 class="mb-3"><?php echo $message; ?></h3>

        <!-- Loading Spinner -->
        <div class="spinner-border text-primary mt-3" role="status">
            <span class="visually-hidden"><?php echo Text::_('COM_ALFA_LOADING'); ?></span>
        </div>

        <!-- Status Text -->
        <p class="text-muted mt-3">
			<?php echo Text::_('COM_ALFA_CLOSING_MODAL'); ?>
        </p>

    </div>
</div>

<script>

    (function () {
        'use strict';

        const dataToReturn = Joomla.getOptions('alfa.orderitem.modal.return', {});

        console.log('[Order Item Return] Page loaded');
        console.log('[Order Item Return] Will send to parent:', dataToReturn);

        function notifyParent() {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage(dataToReturn, '*');
                console.log('[Order Item Return] Message sent to parent');
            } else {
                console.warn('[Order Item Return] No parent window found');
            }
        }

        notifyParent();

    })();
</script>