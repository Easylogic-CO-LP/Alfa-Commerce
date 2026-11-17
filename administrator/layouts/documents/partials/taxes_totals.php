<?php

/**
 * Per-rate tax summary from the frozen #__alfa_document_taxes rows +
 * the totals box. Reports and print read the SAME frozen summary, never
 * re-derived from lines (rounding truth).
 *
 * Each row prints the tax names its lines froze before its rate ("VAT 24%"),
 * the names a row carries in `tax_names` (DocumentRenderer); a row with none,
 * as on every document issued before 2.2.0, prints its rate alone, exactly as
 * it always has.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  2.0.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentMoney;
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentRenderer;
use Joomla\CMS\Language\Text;

/** @var array $displayData */
$document = $displayData['document'];
$taxes = $displayData['taxes'] ?? [];

// ONE AUTHORITY, AND IT IS THE DOCUMENT'S OWN FROZEN SCALE.
//
// This was `number_format($v, 2, ',', '.')`: a hardcoded two decimals,
// written twice across the partials. It made an issued document immune to
// a currency edit BY ACCIDENT (nothing here consulted the currency at
// all), and it printed wrong for every shop whose currency is not
// two-decimal. The document now carries the scale it was issued under and
// rendering asks for it, so the accident becomes a rule and the rule is
// right at every scale.
//
// Every number on the document prints with the separators of the document's own language, whoever prints it:
// 1,234.56 and 15.5% on an English document, 1.234,56 and 15,5% on a Greek one. Only how a number is written follows
// the language; the stored figures and the totals do not change.
$separators = DocumentRenderer::separators(document: $document);
$fmt = DocumentMoney::formatter($displayData['document'] ?? null, decimalSeparator: $separators['decimal'], thousandsSeparator: $separators['thousands']);
$currency = htmlspecialchars((string) $document->currency_code, ENT_COMPAT, 'UTF-8');
// A tax rate prints as stored (2.2.1): every digit its column holds, its padding zeros dropped. The blank line after
// the row's rate keeps the line break the rate printed before, so a row whose figure is unchanged prints as it did.
?>
<table class="bottom-table">
    <tr>
        <td>
            <?php if ($taxes !== []) : ?>
                <table class="taxes-table">
                    <thead>
                    <tr>
                        <th style="text-align: left;"><?php echo Text::_('COM_ALFA_DOC_PRINT_VAT_ANALYSIS'); ?></th>
                        <th><?php echo Text::_('COM_ALFA_DOC_PRINT_NET'); ?></th>
                        <th><?php echo Text::_('COM_ALFA_DOC_PRINT_VAT_AMOUNT'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($taxes as $tax) : ?>
                        <tr>
                            <td style="text-align: left;">
                                <?php echo (($tax->tax_names ?? []) === [] ? '' : htmlspecialchars(implode(', ', $tax->tax_names), ENT_COMPAT, 'UTF-8') . ' ')
                                    . htmlspecialchars(AlfaHelper::percentDisplay(value: $tax->tax_rate, decimalSeparator: $separators['decimal']), ENT_COMPAT, 'UTF-8'); ?>

                                <?php if (!empty($tax->exemption_code)) : ?>
                                    (<?php echo htmlspecialchars((string) $tax->exemption_code, ENT_COMPAT, 'UTF-8'); ?>)
                                <?php endif; ?>
                            </td>
                            <td><?php echo $fmt((float) $tax->base_tax_excl); ?></td>
                            <td><?php echo $fmt((float) $tax->tax_amount); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </td>
        <td>
            <table class="totals-table">
                <tr>
                    <td class="label"><?php echo Text::_('COM_ALFA_DOC_PRINT_NET_TOTAL'); ?></td>
                    <td class="num"><?php echo $fmt((float) $document->total_tax_excl); ?> <?php echo $currency; ?></td>
                </tr>
                <tr>
                    <td class="label"><?php echo Text::_('COM_ALFA_DOC_PRINT_VAT_TOTAL'); ?></td>
                    <td class="num"><?php echo $fmt((float) $document->total_tax); ?> <?php echo $currency; ?></td>
                </tr>
                <tr class="grand">
                    <td class="label"><?php echo Text::_('COM_ALFA_DOC_PRINT_GRAND_TOTAL'); ?></td>
                    <td class="num"><?php echo $fmt((float) $document->total_tax_incl); ?> <?php echo $currency; ?></td>
                </tr>
            </table>
        </td>
    </tr>
</table>
