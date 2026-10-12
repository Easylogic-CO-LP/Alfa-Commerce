<?php

/**
 * Order Edit: Sales Documents panel.
 *
 * Lists the order's legal documents and opens the Issue popup. Same shape the
 * Items, Payments and Shipments panels have: the table's columns are
 * auto-generated from order_documents.xml, a $mergeMap says which fields share
 * a cell and a $renderMap says how each one is drawn, so adding a column is
 * an XML edit, not a template edit.
 *
 * The Issue popup is an ordinary iframe modal over edit_document.php, again
 * like the other three. It used to be hand-written markup inside this page,
 * which sits inside the order's own <form>: it could not be a form itself, so
 * it was a <div> whose fields a script copied into a throwaway form at submit
 * time. That is gone.
 *
 * Hidden entirely while documents_issuer = external (ERP shops).
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  1.3.0
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger;
use Alfa\Component\Alfa\Administrator\Service\Document\CorrectionStrategies;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$orderId = (int) ($this->order->id ?? 0);

if ($orderId <= 0 || !DocumentHelper::isIssuerEnabled()) {
    return;
}

$user = $this->getCurrentUser();
// Issuing by hand is a fiscal act, not merely an order edit: it needs Orders edit AND
// Sales Documents create, the pair OrderController::assertCanIssueDocument() enforces.
// Voiding and reading are the documents' own permissions.
$canIssue = AlfaHelper::can(section: 'orders', action: 'edit', user: $user)
    && AlfaHelper::can(section: 'documents', action: 'create', user: $user);
$canVoid = AlfaHelper::can(section: 'documents', action: 'edit.state', user: $user);
$canRead = AlfaHelper::can(section: 'documents', action: 'view', user: $user);
// Its own capability: the service asks it alone. The
// modal is an order screen, so opening it also takes Orders: Edit, which the
// viewer of this panel holds. A replacer who may neither issue nor void still
// opens that modal, so the listener below is guarded on it too.
$canReplace = AlfaHelper::can(section: 'documents', action: CorrectionStrategies::CAPABILITY_REPLACE, user: $user);

$documents = DocumentHelper::getForOrder($orderId);

// Column definitions, from the view's order_documents.xml form.
$documentFields = $this->documentListForm->getGroup('');

//  MERGE MAP - Which fields share a table cell
//
//  Format: 'primary_field' => ['child1', 'child2', ...]
//
//  - The primary field name becomes the column header
//  - Children render below/beside the primary in the same <td>
//  - Fields NOT mentioned anywhere get their own column
$mergeMap = [
    // Type badge, then "also a delivery note" and "corrects #N" beside it
    'doc_type'       => ['is_combined', 'id_corrects'],
    // Amount and the currency it was frozen in
    'total_tax_incl' => ['currency_code'],
    // Issued or voided, then what a credit note still waits for
    'status'         => ['credit_destination'],
];

// Flatten to child => parent for the header and cell loops
$childFields = [];

foreach ($mergeMap as $parent => $children) {
    foreach ($children as $child) {
        $childFields[$child] = $parent;
    }
}

// Badge class per document type. A type the map does not name still gets a
// badge; the registry is open, and an unknown alias is not an error here.
$typeBadge = [
    DocumentHelper::TYPE_INVOICE        => 'bg-primary',
    DocumentHelper::TYPE_RETAIL_RECEIPT => 'bg-info',
    DocumentHelper::TYPE_CREDIT_INVOICE => 'bg-warning text-dark',
    DocumentHelper::TYPE_CREDIT_RECEIPT => 'bg-warning text-dark',
    DocumentHelper::TYPE_DELIVERY_NOTE  => 'bg-secondary',
];

//  RENDER MAP - Custom HTML rendering per field name
//
//  Format: 'field_name' => function($value, $doc, $escape) -> string
//
//  Fields NOT listed here fall through to a plain escaped value.
$renderMap = [
    // Number: struck through once the document is voided
    'number_formatted' => static function ($value, $doc, $escape): string {
        $voided = (int) $doc->status === DocumentHelper::STATUS_VOIDED;

        return '<strong' . ($voided ? ' style="text-decoration: line-through;"' : '') . '>'
            . $escape((string) $value) . '</strong>';
    },

    // Type: the registry's own label, never the raw alias
    'doc_type' => static function ($value, $doc, $escape) use ($typeBadge): string {
        return '<span class="badge ' . ($typeBadge[$value] ?? 'bg-secondary') . '">'
            . DocumentHelper::typeLabel((string) $value) . '</span>';
    },

    // Combined: a badge when set, nothing when not
    'is_combined' => static function ($value, $doc, $escape): string {
        return (int) $value === 1
            ? '<span class="badge bg-dark">' . Text::_('COM_ALFA_DOCUMENTS_BADGE_COMBINED') . '</span>'
            : '';
    },

    // Corrects: which document this credit is against
    'id_corrects' => static function ($value, $doc, $escape): string {
        return !empty($value)
            ? '<span class="small text-muted">' . Text::sprintf('COM_ALFA_DOCUMENTS_CORRECTS', (int) $value) . '</span>'
            : '';
    },

    // Issue date, in the shop's own timezone
    'issue_date_local' => static function ($value, $doc, $escape): string {
        return HTMLHelper::_('date', $value, Text::_('DATE_FORMAT_LC4'));
    },

    // Total: only a fiscal document carries money
    'total_tax_incl' => static function ($value, $doc, $escape): string {
        return DocumentHelper::isFiscal((string) $doc->doc_type)
            ? number_format((float) $value, 2, ',', '.')
            : '-';
    },

    // Currency: suppressed on the types that show no total
    'currency_code' => static function ($value, $doc, $escape): string {
        return DocumentHelper::isFiscal((string) $doc->doc_type) ? $escape((string) $value) : '';
    },

    // Status: issued or voided. A document is never deleted
    'status' => static function ($value, $doc, $escape): string {
        $voided = (int) $value === DocumentHelper::STATUS_VOIDED;

        return '<span class="badge ' . ($voided ? 'bg-danger' : 'bg-success') . '">'
            . Text::_($voided ? 'COM_ALFA_DOCUMENT_STATUS_VOIDED' : 'COM_ALFA_DOCUMENT_STATUS_ISSUED')
            . '</span>';
    },

    // Customer credit not paid in: the note waits
    // A credit note numbered for the customer's credit account is paid in
    // once it is committed. One whose credit was not paid in keeps waiting,
    // so the row says so and names what the note still waits for: what it is
    // still owed. That is the note's figure, not the order's: a refund
    // recorded on the order's payments can leave the order less to give back.
    // Nothing is shown for a voided note, for one that was paid in or settled
    // by a refund, or for a note sent anywhere else.
    //
    // Beside the sentence the row offers that same pay-in again, to whoever
    // may issue the credit and while the note can still take it: the account
    // takes a note whole or not at all, so one a refund has paid back in part
    // is offered no credit it would be refused. The panel sits inside the
    // order's own form, so the action is a button of the page's credit form;
    // the button carries a one-time key of its own, which the server spends.
    // That form's amount field is the Payments panel's and carries browser
    // limits; this button posts none of it, so it does not ask the browser to
    // validate the form.
    'credit_destination' => static function ($value, $doc, $escape) use ($canIssue, $orderId): string {
        if ($value !== CustomerCreditLedger::CREDIT_NOTE_DESTINATION || (int) $doc->status === DocumentHelper::STATUS_VOIDED) {
            return '';
        }

        $owed = CustomerCreditLedger::creditNoteOwed(documentId: (int) $doc->id);

        if (!$owed->isPositive()) {
            return '';
        }

        $waiting = '<span class="d-block text-warning-emphasis">' . Text::sprintf('COM_ALFA_DOCUMENTS_CREDIT_WAITING', $escape($owed->format())) . '</span>';

        if (!$canIssue || !$owed->equals(Money::of((float) $doc->total_tax_incl, $owed->getCurrency()))) {
            return $waiting;
        }

        return $waiting . '<button type="submit" class="btn btn-sm btn-warning mt-1" form="alfa-order-credit-form" formnovalidate'
            . ' name="once" value="' . bin2hex(random_bytes(16)) . '" formaction="'
            . $escape(Route::_('index.php?option=com_alfa', false) . '&task=order.payInCreditNote&id_order=' . $orderId . '&id_document=' . (int) $doc->id)
            . '">' . Text::_('COM_ALFA_DOCUMENTS_ACTION_CREDIT_PAY_IN') . '</button>';
    },
];

$escape = fn ($value): string => $this->escape((string) $value);

?>

<!--  SECTION HEADER: Title + Issue button                          -->
<?php // Heading and its action on one flex row - a float-end button drops out of
      // the heading's line box and lands on top of the block below it. Same shape
      // the Items section uses. ?>
<div class="d-flex justify-content-between align-items-center mt-4 mb-3">
    <h3>
        <span class="icon-file-2" aria-hidden="true"></span>
        <?php echo Text::_('COM_ALFA_DOCUMENTS_TITLE'); ?>
        <?php if (!empty($documents)) : ?>
            <span class="badge bg-secondary"><?php echo count($documents); ?></span>
        <?php endif; ?>
    </h3>
    <?php if ($canIssue) : ?>
        <a href="#issueDocumentModal" data-bs-toggle="modal" class="btn btn-success">
            <span class="icon-plus" aria-hidden="true"></span> <?php echo Text::_('COM_ALFA_DOCUMENTS_ACTION_ISSUE'); ?>
        </a>
    <?php endif; ?>
</div>

<!--  DOCUMENTS TABLE                                               -->
<?php if (empty($documents)) : ?>
    <div class="alert alert-info"><?php echo Text::_('COM_ALFA_DOCUMENTS_NONE_YET'); ?></div>
<?php else : ?>
    <table class="table table-striped table-bordered">

        <!-- Table header -->
        <!-- Auto-generated from order_documents.xml.               -->
        <!-- Skips: hidden fields and child fields.                 -->
        <thead>
        <tr>
            <?php foreach ($documentFields as $field) : ?>
                <?php
                $name = $field->getAttribute('name') ?? $field->fieldname ?? '';

                if ($field->getAttribute('type') === 'hidden') {
                    continue;
                }

                if (isset($childFields[$name])) {
                    continue;
                }
                ?>
                <th><?php echo Text::_($field->getAttribute('label')); ?></th>
            <?php endforeach; ?>

            <th class="text-center"><?php echo Text::_('COM_ALFA_DOCUMENTS_HEADING_ACTIONS'); ?></th>
        </tr>
        </thead>

        <!-- Table body -->
        <tbody id="document-rows">
        <?php foreach ($documents as $doc) : ?>
            <?php $isVoided = (int) $doc->status === DocumentHelper::STATUS_VOIDED; ?>
            <tr class="<?php echo $isVoided ? 'text-muted' : ''; ?>">

                <?php foreach ($documentFields as $field) : ?>
                    <?php
                    $fieldName = $field->getAttribute('name') ?? $field->fieldname ?? '';

                    if ($field->getAttribute('type') === 'hidden') {
                        continue;
                    }

                    if (isset($childFields[$fieldName])) {
                        continue;
                    }

                    $render = static function (string $name) use ($doc, $renderMap, $escape): string {
                        $value = $doc->$name ?? null;

                        return isset($renderMap[$name])
                            ? $renderMap[$name]($value, $doc, $escape)
                            : $escape($value);
                    };
                    ?>
                    <td>
                        <?php echo $render($fieldName); ?>
                        <?php // Merged children render inside the parent's cell ?>
                        <?php foreach ($mergeMap[$fieldName] ?? [] as $child) : ?>
                            <?php $childHtml = $render($child); ?>
                            <?php if ($childHtml !== '') : ?>
                                <span class="merge-child"><?php echo $childHtml; ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </td>
                <?php endforeach; ?>

                <!-- Actions cell -->
                <td class="text-center">
                    <?php if ($canRead) : ?>
                        <div class="btn-group me-1" role="group">
                            <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                               href="<?php echo Route::_('index.php?option=com_alfa&task=documents.printDocument&id=' . (int) $doc->id); ?>">
                                <span class="icon-print" aria-hidden="true"></span> <?php echo Text::_('COM_ALFA_DOCUMENTS_ACTION_PRINT'); ?>
                            </a>
                            <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                               href="<?php echo Route::_('index.php?option=com_alfa&task=documents.downloadPdf&id=' . (int) $doc->id); ?>">
                                <span class="icon-file-2" aria-hidden="true"></span> PDF
                            </a>
                        </div>
                    <?php endif; ?>
                    <?php if ($canIssue && !$isVoided && DocumentHelper::isFiscal((string) $doc->doc_type)) : ?>
                        <?php // Correcting THIS document: the source is named by
                              // the row, which is how a credit gets its target
                              // without the screen choosing one. ?>
                        <?php $correctModalId = 'correctionModal-' . (int) $doc->id; ?>
                        <div class="btn-group me-1" role="group">
                            <a href="#<?php echo $correctModalId; ?>" data-bs-toggle="modal"
                               class="btn btn-sm btn-outline-primary"
                               title="<?php echo Text::_('COM_ALFA_CORRECTION_ACTION_REVIEW'); ?>">
                                <span class="icon-wrench" aria-hidden="true"></span>
                            </a>
                        </div>
                        <?php
                        echo HTMLHelper::_(
                            'bootstrap.renderModal', $correctModalId,
                            [
                                'title'      => Text::_('COM_ALFA_CORRECTION_MODAL_TITLE'),
                                'url'        => Route::_(sprintf(
                                    'index.php?option=com_alfa&view=order&layout=edit_correction&tmpl=component&id_order=%d&id_source=%d',
                                    $orderId,
                                    (int) $doc->id
                                )),
                                'width'      => '80%',
                                'bodyHeight' => '70vh',
                            ]
                        );
                        ?>
                    <?php endif; ?>
                    <?php
                    // Offered only when the strategy engine says it is available,
                    // and that answer already folds in the Void & Replace
                    // capability, the document-level stock bar and any live
                    // corrective. A button the service would certainly refuse
                    // is not drawn.
                    $replaceable = !$isVoided
                        && CorrectionStrategies::forDocument((int) $doc->id, $user)[CorrectionStrategies::VOID_AND_REPLACE]->available;
                    ?>
                    <?php if ($replaceable) : ?>
                        <?php $replaceModalId = 'replacementModal-' . (int) $doc->id; ?>
                        <div class="btn-group me-1" role="group">
                            <a href="#<?php echo $replaceModalId; ?>" data-bs-toggle="modal"
                               class="btn btn-sm btn-outline-warning"
                               data-alfa-replace="<?php echo (int) $doc->id; ?>"
                               title="<?php echo Text::_('COM_ALFA_REPLACEMENT_ACTION'); ?>">
                                <span class="icon-refresh" aria-hidden="true"></span>
                            </a>
                        </div>
                        <?php
                        echo HTMLHelper::_(
                            'bootstrap.renderModal', $replaceModalId,
                            [
                                'title'      => Text::_('COM_ALFA_REPLACEMENT_MODAL_TITLE'),
                                'url'        => Route::_(sprintf(
                                    'index.php?option=com_alfa&view=order&layout=edit_replacement&tmpl=component&id_order=%d&id_source=%d',
                                    $orderId,
                                    (int) $doc->id
                                )),
                                'width'      => '80%',
                                'bodyHeight' => '70vh',
                            ]
                        );
                        ?>
                    <?php endif; ?>
                    <?php if ($canVoid && !$isVoided) : ?>
                        <?php // Its own iframe modal over the documents view's void
                              // layout, the same shell every other order popup uses.
                              // It used to be a shared dialog whose two values a
                              // script copied into a form it built at submit time. ?>
                        <?php $voidModalId = 'voidDocumentModal-' . (int) $doc->id; ?>
                        <div class="btn-group" role="group">
                            <a href="#<?php echo $voidModalId; ?>" data-bs-toggle="modal"
                               class="btn btn-sm btn-outline-danger">
                                <?php echo Text::_('COM_ALFA_DOCUMENTS_ACTION_VOID'); ?>
                            </a>
                        </div>
                        <?php
                        echo HTMLHelper::_(
                            'bootstrap.renderModal', $voidModalId,
                            [
                                'title'      => Text::_('COM_ALFA_DOCUMENTS_ACTION_VOID'),
                                'url'        => Route::_(sprintf(
                                    'index.php?option=com_alfa&view=documents&layout=void&tmpl=component&id=%d',
                                    (int) $doc->id
                                )),
                                'width'      => '60%',
                                'bodyHeight' => '50vh',
                            ]
                        );
                        ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<!--  ISSUE DOCUMENT MODAL                                          -->
<!--  Opens edit_document.php - an ordinary Joomla form in an iframe -->
<!--  with its own toolbar, exactly like Add Shipment and Add Payment -->
<?php if ($canIssue) : ?>
<?php
$issueUrl = Route::_(sprintf(
    'index.php?option=com_alfa&view=order&layout=edit_document&tmpl=component&id_order=%d',
    $orderId
));

echo HTMLHelper::_(
    'bootstrap.renderModal', 'issueDocumentModal',
    [
        'title'      => Text::_('COM_ALFA_DOCUMENTS_MODAL_TITLE'),
        'url'        => $issueUrl,
        'width'      => '80%',
        'bodyHeight' => '70vh',
    ]
);
?>
<?php endif; ?>

<?php // A voider who may not issue still opens a modal, so the listener is
      // guarded on EITHER right, not on issuing alone. A user with neither
      // carries no modal and needs no listener. ?>
<?php if ($canIssue || $canVoid || $canReplace) : ?>
<!--  MODAL COMMUNICATION - postMessage between iframe and parent   -->
<!--                                                                 -->
<!--  When a document is issued or voided inside a modal iframe,     -->
<!--  the iframe sends a postMessage to the parent:                  -->
<!--    { messageType: 'alfa:document-action',                       -->
<!--      shouldClose: true, shouldReload: true }                    -->
<!--                                                                 -->
<!--  This script listens for that message and:                      -->
<!--    1. Closes the modal (if shouldClose)                         -->
<!--    2. Reloads the order page (if shouldReload)                  -->
<script>
    (function() {
        'use strict';

        /**
         * Listen for messages from the issue modal iframe.
         *
         * The iframe (edit_document.php) sends a postMessage when:
         *   - A document is issued (shouldReload = true)
         *   - The operator cancels  (shouldReload = false)
         */
        window.addEventListener('message', function(event) {
            // Only accept messages from our own origin (the issue modal is same-origin).
            if (event.origin !== window.location.origin) {
                return;
            }

            // Only handle messages from our document modal
            if (event.data.messageType !== 'alfa:document-action') {
                return;
            }

            // Close the modal if requested
            if (event.data.shouldClose) {
                closeModal();
            }

            // Reload the order page: the new document has to appear in the
            // table above, and it moves every remaining pool with it.
            if (event.data.shouldReload) {
                reloadOrderPage();
            }
        });

        /**
         * Reload the order edit page by submitting the form with a
         * special "reload" task. This preserves the current tab state
         * and shows a Joomla loading spinner during the reload.
         * @since  1.3.0
         */
        function reloadOrderPage() {
            var orderForm = document.querySelector('#order-form');

            if (!orderForm) {
                console.error('[Documents] Order form not found, cannot reload');
                return;
            }

            var taskInput = orderForm.querySelector('input[name=task]');
            var url = new URL(window.location.href);
            var view = url.searchParams.get('view') || '';

            if (taskInput && view) {
                taskInput.value = view + '.reload';
            }

            // Painted first, then submitted; see Alfa.loader.showThen().
            Alfa.loader.showThen(() => orderForm.submit());
        }

        /**
         * Close whichever modal sent the message: the single Issue modal, or
         * the per-document Void modal that names its document.
         *
         * @param {number|undefined} documentId  Set by a void, absent on an issue.
         * @since  1.3.0
         */
        function closeModal() {
            // Several modals share this message (Issue, Void, Correct and
            // Void & Replace, per row or per page) and only one is ever open.
            // Closing the one that is shown is exact; guessing its id from the
            // message was not, once a fourth sender arrived.
            document.querySelectorAll('.modal.show').forEach(function (modalElement) {
                var bsModal = bootstrap.Modal.getInstance(modalElement);
                if (bsModal) {
                    bsModal.hide();
                }
            });
        }
    })();
</script>
<?php endif; ?>
