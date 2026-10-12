<?php

/**
 * Document header: frozen seller identity (left) + the numbered document
 * box (right). Every value comes from the document row, never config,
 * which may have changed since issue.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  1.3.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var array $displayData */
$document = $displayData['document'];
$series = $displayData['series'] ?? null;

// Registry-driven title, resolved in the DOCUMENT's frozen language.
$title = DocumentHelper::typeLabel((string) $document->doc_type, (string) $document->language);

if ((int) $document->is_combined === 1) {
    $title .= ' - ' . DocumentHelper::typeLabel(DocumentHelper::TYPE_DELIVERY_NOTE, (string) $document->language);
}

$sellerAddress = json_decode((string) ($document->seller_address ?? ''), true);
$sellerExtra = json_decode((string) ($document->seller_extra ?? ''), true);
?>

<?php if ((int) $document->status === 2) : ?>
    <div class="voided-banner">
        <?php echo Text::_('COM_ALFA_DOC_PRINT_VOIDED_BANNER'); ?>
        <?php if (!empty($document->void_reason)) : ?>
            - <?php echo htmlspecialchars((string) $document->void_reason, ENT_COMPAT, 'UTF-8'); ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<table class="doc-header">
    <tr>
        <td>
            <div class="seller-name"><?php echo htmlspecialchars((string) $document->seller_name, ENT_COMPAT, 'UTF-8'); ?></div>
            <div class="seller-details">
                <?php if (!empty($document->seller_activity)) : ?>
                    <?php echo htmlspecialchars((string) $document->seller_activity, ENT_COMPAT, 'UTF-8'); ?><br>
                <?php endif; ?>
                <?php if (!empty($sellerAddress['text'])) : ?>
                    <?php echo nl2br(htmlspecialchars((string) $sellerAddress['text'], ENT_COMPAT, 'UTF-8')); ?><br>
                <?php endif; ?>
                <?php if (!empty($document->seller_vat_number)) : ?>
                    <?php echo Text::_('COM_ALFA_DOC_PRINT_VAT'); ?>: <?php echo htmlspecialchars((string) $document->seller_vat_number, ENT_COMPAT, 'UTF-8'); ?>
                <?php endif; ?>
                <?php if (!empty($document->seller_tax_office)) : ?>
                    - <?php echo Text::_('COM_ALFA_DOC_PRINT_TAX_OFFICE'); ?>: <?php echo htmlspecialchars((string) $document->seller_tax_office, ENT_COMPAT, 'UTF-8'); ?>
                <?php endif; ?>
                <?php if (!empty($sellerExtra['text'])) : ?>
                    <br><?php echo nl2br(htmlspecialchars((string) $sellerExtra['text'], ENT_COMPAT, 'UTF-8')); ?>
                <?php endif; ?>
            </div>
        </td>
        <td style="width: 66mm;">
            <div class="doc-box">
                <div class="doc-title"><?php echo htmlspecialchars($title, ENT_COMPAT, 'UTF-8'); ?></div>
                <table>
                    <tr>
                        <td class="label"><?php echo Text::_('COM_ALFA_DOC_PRINT_SERIES'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($series->code ?? '-'), ENT_COMPAT, 'UTF-8'); ?></td>
                    </tr>
                    <tr>
                        <td class="label"><?php echo Text::_('COM_ALFA_DOC_PRINT_NUMBER'); ?></td>
                        <td><strong><?php echo htmlspecialchars((string) $document->number_formatted, ENT_COMPAT, 'UTF-8'); ?></strong></td>
                    </tr>
                    <tr>
                        <td class="label"><?php echo Text::_('COM_ALFA_DOC_PRINT_DATE'); ?></td>
                        <td><?php echo HTMLHelper::_('date', $document->issue_date_local, Text::_('DATE_FORMAT_LC4')); ?></td>
                    </tr>
                    <tr>
                        <td class="label"><?php echo Text::_('COM_ALFA_DOC_PRINT_ORDER'); ?></td>
                        <td>#<?php echo (int) $document->id_order; ?></td>
                    </tr>
                    <?php if (!empty($displayData['corrects'])) : ?>
                        <tr>
                            <td class="label"><?php echo Text::_('COM_ALFA_DOC_PRINT_CORRECTS'); ?></td>
                            <td><?php echo htmlspecialchars((string) $displayData['corrects']->number_formatted, ENT_COMPAT, 'UTF-8'); ?></td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>
        </td>
    </tr>
</table>
