<?php

/**
 * Per-rate tax summary from the frozen #__alfa_document_taxes rows +
 * the totals box. Reports and print read the SAME frozen summary, never
 * re-derived from lines (rounding truth).
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  2.0.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Document\DocumentMoney;
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
$fmt = DocumentMoney::formatter($displayData['document'] ?? null);
$currency = htmlspecialchars((string) $document->currency_code, ENT_COMPAT, 'UTF-8');
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
                                <?php echo rtrim(rtrim(number_format((float) $tax->tax_rate, 2, ',', ''), '0'), ','); ?>%
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
