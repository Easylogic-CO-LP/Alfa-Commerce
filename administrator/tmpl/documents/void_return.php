<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * Void Success Page
 * Shows after Void or Cancel
 * Sends postMessage to parent with:
 * - action: 'voided'
 * - shouldClose: true (parent should close modal)
 * - shouldReload: true (parent should reload page)
 *
 * The messageType is the one the order screen already listens for, because a
 * void IS a document action and the order behind the modal has to re-render:
 * the panel's table, and every remaining pool the voided document was holding.
 *
 * Path: administrator/components/com_alfa/tmpl/documents/void_return.php
 *
 * @since  1.3.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

$app   = Factory::getApplication();
$input = $app->input;

$shouldClose  = $input->getInt('close', 1) == 1;
$shouldReload = $input->getInt('reload', 1) == 1;

// The outcome belongs to the page the opener reloads: this one closes as it
// loads, so a message shown only here is never read (C-6). The queue is read
// and put back in the session, where that next page takes it from; this page
// still renders its own copy before it closes. A close that reloads nothing
// keeps nothing, so no stale message turns up on some later page.
if ($shouldReload) {
	$app->getSession()->set('application.queue', $app->getMessageQueue());
}

$returnData = [
	'messageType'  => 'alfa:document-action',
	'action'       => 'voided',
	'documentId'   => isset($this->voidDocument) ? (int) $this->voidDocument->id : 0,
	'orderId'      => isset($this->voidDocument) ? (int) $this->voidDocument->id_order : 0,
	'shouldClose'  => $shouldClose,            // Parent should close modal
	'shouldReload' => $shouldReload,           // Parent should reload page
	'messages'     => '',
	'timestamp'    => date('c')
];

$this->document->addScriptOptions('alfa.document.void.return', $returnData);

// Success message
$message = Text::_('COM_ALFA_MODAL_RETURN_DONE');
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

        const dataToReturn = Joomla.getOptions('alfa.document.void.return', {});

        /**
         * Send the outcome to the parent, which closes the modal and reloads
         * the page behind it.
         * @since  1.3.0
         */
        function notifyParent() {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage(dataToReturn, window.location.origin);
            } else {
                console.warn('[Document Void Return] No parent window found');
            }
        }

        notifyParent();

    })();
</script>
