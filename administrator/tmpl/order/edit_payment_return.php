<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * Payment Success Page
 * Shows after Save & Close or Delete
 * Sends postMessage to parent with:
 * - action: 'saved' or 'deleted'
 * - shouldClose: true (parent should close modal)
 * - shouldReload: true (parent should reload page)
 *
 * Path: administrator/components/com_alfa/tmpl/order/edit_payment_return.php
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

$app   = Factory::getApplication();
$input = $app->input;

$shouldClose  = $input->getInt('close', 1) == 1;
$shouldReload = $input->getInt('reload', 1) == 1;

//
//// Get parameters
//$orderId = $input->getInt('id_order', 0);
//$paymentId = $input->getInt('id_payment', 0);
//$action = $input->getString('action', 'saved'); // 'saved' or 'deleted'
//
//// Get any messages from controller
//$messages = $app->getMessageQueue();
//$messagesArray = [];
//foreach ($messages as $msg) {
//	$messagesArray[] = [
//		'type' => $msg['type'],
//		'message' => $msg['message']
//	];
//}

// Prepare data to send to parent
// Parent will use this to:
// 1. Close the modal (shouldClose: true)
// 2. Reload the order page (shouldReload: true)
// 3. Show any success messages
$returnData = [
	'messageType'  => 'alfa:payment-action',
	'action'       => '',             // What happened: 'saved' or 'deleted'
	'orderId'      => isset($this->order) ? $this->order->id : 0,
	'paymentId'    => isset($this->payment) ? $this->payment->id : 0,
	'shouldClose'  => $shouldClose,            // Parent should close modal
	'shouldReload' => $shouldReload,           // Parent should reload page
	'messages'     => '',
	'timestamp'    => date('c')
];

$this->document->addScriptOptions('alfa.payment.modal.return', $returnData);

// Success message
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

        // Get success data from Joomla options
        const dataToReturn = Joomla.getOptions('alfa.payment.modal.return', {});

        console.log('[Payment Return Layout Post Message] Page loaded');
        console.log('[Payment Return Layout Post Message] Will send to parent:', dataToReturn);

        /**
         * Send success message to parent
         * Parent will:
         * 1. Show any messages (from successData.messages)
         * 2. Close the modal (because shouldClose: true)
         * 3. Reload the page (because shouldReload: true)
         */
        function notifyParent() {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage(dataToReturn, '*');
                console.log('[Payment Return Layout Post Message] Message sent to parent');
            } else {
                console.warn('[Payment Return Layout Post Message] No parent window found');
            }
        }

        // Send message immediately when page loads
        notifyParent();

    })();
</script>