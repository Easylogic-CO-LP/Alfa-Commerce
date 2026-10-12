<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\Actions;
use Alfa\Component\Alfa\Administrator\Helper\Columns;
use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
    ->useScript('com_alfa.admin')
    ->useScript('table.columns');

// Joomla.Text._() needs explicit registration; .ini alone is not enough.
Text::script('COM_ALFA_DOCUMENTS_VOID_PROMPT');
Text::script('COM_ALFA_DOCUMENTS_VOID_NEEDS_REASON');

$user = $this->getCurrentUser();
$canVoid = AlfaHelper::can(section: 'documents', action: 'edit.state', user: $user);
$canOpenOrder = AlfaHelper::can(section: 'orders', action: 'edit', user: $user);
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn = $this->escape($this->state->get('list.direction'));
// Modals are collected while the rows render and printed after the list's
// </form>: a modal nested in a form would submit its fields with the list.
$voidModals = [];

$columnsByAnchor = Columns::prepare($this->pluginColumns ?? [], []);   // END-only folding

$typeBadge = [
    DocumentHelper::TYPE_INVOICE => 'bg-primary',
    DocumentHelper::TYPE_RETAIL_RECEIPT => 'bg-info',
    DocumentHelper::TYPE_CREDIT_INVOICE => 'bg-warning text-dark',
    DocumentHelper::TYPE_CREDIT_RECEIPT => 'bg-warning text-dark',
    DocumentHelper::TYPE_DELIVERY_NOTE => 'bg-secondary',
];
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=documents'); ?>" method="post"
      name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

                <div class="clearfix"></div><?php echo $this->renderSlot('before-grid'); ?><?php echo Actions::bulkBar($this->pluginBulkActions ?? []); ?>

                <?php if (empty($this->items)) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span>
                        <?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
                    </div>
                <?php else : ?>
                <table class="table table-striped" id="documentList">
                    <thead>
                    <tr>
                        <th scope="col" class="w-10">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_DOCUMENTS_HEADING_NUMBER', 'a.number', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="w-10">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_SERIES_FIELD_DOC_TYPE', 'a.doc_type', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_DOCUMENTS_HEADING_BUYER'); ?></th>
                        <th scope="col" class="w-8 text-center"><?php echo Text::_('COM_ALFA_TITLE_ORDER'); ?></th>
                        <th scope="col" class="w-10 text-end">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_DOCUMENTS_HEADING_TOTAL', 'a.total_tax_incl', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="w-10">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_DOCUMENTS_HEADING_DATE', 'a.issue_date_local', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="w-8 text-center"><?php echo Text::_('JSTATUS'); ?></th>
                        <th scope="col" class="w-8 text-center"><?php echo Text::_('COM_ALFA_DOCUMENTS_HEADING_ACTIONS'); ?></th><?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>

                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->items as $i => $item) : ?>
                        <?php $isVoided = (int) $item->status === DocumentHelper::STATUS_VOIDED; ?>
                        <tr class="<?php echo $isVoided ? 'text-muted' : ''; ?>">
                            <td>
                                <strong <?php echo $isVoided ? 'style="text-decoration: line-through;"' : ''; ?>>
                                    <?php echo $this->escape((string) $item->number_formatted); ?>
                                </strong>
                                <?php if (!empty($item->series_code)) : ?>
                                    <div class="small text-muted"><?php echo Text::_('COM_ALFA_SERIES_FIELD_CODE'); ?>: <?php echo $this->escape($item->series_code); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $typeBadge[$item->doc_type] ?? 'bg-secondary'; ?>">
                                    <?php echo DocumentHelper::typeLabel((string) $item->doc_type); ?>
                                </span>
                                <?php if ((int) $item->is_combined === 1) : ?>
                                    <span class="badge bg-dark"><?php echo Text::_('COM_ALFA_DOCUMENTS_BADGE_COMBINED'); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($item->id_corrects)) : ?>
                                    <div class="small text-muted"><?php echo Text::sprintf('COM_ALFA_DOCUMENTS_CORRECTS', (int) $item->id_corrects); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $this->escape((string) $item->buyer_name); ?>
                                <?php if (!empty($item->buyer_vat_number)) : ?>
                                    <div class="small text-muted"><?php echo Text::_('COM_ALFA_DOCUMENTS_VAT_ABBR'); ?> <?php echo $this->escape($item->buyer_vat_number); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($canOpenOrder) : ?>
                                <a href="<?php echo Route::_('index.php?option=com_alfa&task=order.edit&id=' . (int) $item->id_order); ?>">
                                    #<?php echo (int) $item->id_order; ?>
                                </a>
                                <?php else : ?>
                                    #<?php echo (int) $item->id_order; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if (DocumentHelper::isFiscal((string) $item->doc_type)) : ?>
                                    <strong><?php echo number_format((float) $item->total_tax_incl, 2, ',', '.'); ?> <?php echo $this->escape((string) $item->currency_code); ?></strong>
                                    <div class="small text-muted"><?php echo Text::_('COM_ALFA_EXCL_VAT'); ?>: <?php echo number_format((float) $item->total_tax_excl, 2, ',', '.'); ?></div>
                                <?php else : ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo HTMLHelper::_('date', $item->issue_date_local, Text::_('DATE_FORMAT_LC4')); ?></td>
                            <td class="text-center">
                                <?php if ($isVoided) : ?>
                                    <span class="badge bg-danger"><?php echo Text::_('COM_ALFA_DOCUMENT_STATUS_VOIDED'); ?></span>
                                <?php else : ?>
                                    <span class="badge bg-success"><?php echo Text::_('COM_ALFA_DOCUMENT_STATUS_ISSUED'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                                   href="<?php echo Route::_('index.php?option=com_alfa&task=documents.printDocument&id=' . (int) $item->id); ?>">
                                    <span class="icon-print" aria-hidden="true"></span> <?php echo Text::_('COM_ALFA_DOCUMENTS_ACTION_PRINT'); ?>
                                </a>
                    <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                       href="<?php echo Route::_('index.php?option=com_alfa&task=documents.downloadPdf&id=' . (int) $item->id); ?>">
                        <span class="icon-file-2" aria-hidden="true"></span> PDF
                    </a>
                                <?php if ($canVoid && !$isVoided) : ?>
                                    <?php // The same iframe modal the order screen opens,
                                          // over the void layout of this very view. ?>
                                    <?php $voidModalId = 'voidDocumentModal-' . (int) $item->id; ?>
                                    <a href="#<?php echo $voidModalId; ?>" data-bs-toggle="modal"
                                       class="btn btn-sm btn-outline-danger">
                                        <?php echo Text::_('COM_ALFA_DOCUMENTS_ACTION_VOID'); ?>
                                    </a>
                                    <?php $voidModals[] = (int) $item->id; ?>
                                <?php endif; ?>
                            </td><?php echo Columns::cells($columnsByAnchor, Columns::END, $item); ?><?php echo Actions::cell($this->pluginRowActions ?? [], $i, $item); ?>

                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php echo $this->pagination->getListFooter(); ?>
                <?php endif; ?>

                <input type="hidden" name="task" value=""/>
                <input type="hidden" name="boxchecked" value="0"/>
                <?php echo HTMLHelper::_('form.token'); ?>
            </div>
        </div>
    </div>
</form>

<?php
// Void modals: one per voidable row, each an ordinary Joomla form in an
//    iframe over this view's own void layout. This page used to carry a
//    single hand-written dialog whose two values a script copied into a form
//    it created at submit time: the same throwaway POST the order screen
//    carried, written out a second time. Both are gone.
foreach ($voidModals as $voidId) {
    echo HTMLHelper::_(
        'bootstrap.renderModal', 'voidDocumentModal-' . $voidId,
        [
            'title'      => Text::_('COM_ALFA_DOCUMENTS_ACTION_VOID'),
            'url'        => Route::_(sprintf(
                'index.php?option=com_alfa&view=documents&layout=void&tmpl=component&id=%d',
                $voidId
            )),
            'width'      => '60%',
            'bodyHeight' => '50vh',
        ]
    );
}
?>

<?php if ($voidModals !== []) : ?>
<!--  MODAL COMMUNICATION - postMessage between iframe and parent   -->
<!--                                                                 -->
<!--  The void layout's return page sends:                           -->
<!--    { messageType: 'alfa:document-action',                       -->
<!--      documentId: 42, shouldClose: true, shouldReload: true }    -->
<script>
    (function() {
        'use strict';

        window.addEventListener('message', function(event) {
            // Only accept messages from our own origin (the modals are same-origin).
            if (event.origin !== window.location.origin) {
                return;
            }

            if (event.data.messageType !== 'alfa:document-action') {
                return;
            }

            if (event.data.shouldClose) {
                var modalElement = document.getElementById('voidDocumentModal-' + event.data.documentId);

                if (modalElement) {
                    var bsModal = bootstrap.Modal.getInstance(modalElement);
                    if (bsModal) {
                        bsModal.hide();
                    }
                }
            }

            // A voided document changes its own row's number, status and
            // actions, so the list behind the modal is re-read.
            if (event.data.shouldReload) {
                window.location.reload();
            }
        });
    })();
</script>
<?php endif; ?>
