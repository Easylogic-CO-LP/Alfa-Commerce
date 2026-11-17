<?php

/**
 * Document lines table. FROZEN amounts straight from the rows; the
 * template never computes. `showPrices` false renders the delivery-note
 * variant (quantities only, no money columns).
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
$lines = $displayData['lines'] ?? [];
$showPrices = $displayData['showPrices'] ?? true;

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
// Every number on the document (its amounts, its quantities, its tax rates) prints with the separators of the
// document's own language, whoever prints it: 1,234.56 on an English document, 1.234,56 on a Greek one. Only how a
// number is written follows the language; the stored figures and the totals do not change.
$separators = DocumentRenderer::separators(document: $displayData['document'] ?? null);
$fmt = DocumentMoney::formatter($displayData['document'] ?? null, decimalSeparator: $separators['decimal'], thousandsSeparator: $separators['thousands']);
// The net unit by the one unit rule, at the same frozen scale, from the
// line's own net total and quantity: a unit is presentation, the line total
// is the money. Totals keep $fmt.
$unitFmt = DocumentMoney::unitFormatter(document: $displayData['document'] ?? null, decimalSeparator: $separators['decimal'], thousandsSeparator: $separators['thousands']);
// A quantity to three places, its padding zeros dropped (2.5, 1,200).
$quantity = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 3, $separators['decimal'], $separators['thousands']), '0'), $separators['decimal']);
// A tax rate prints as stored (2.2.2): every digit its column holds, its padding zeros dropped (15.5%, 8.875%).
?>
<table class="lines-table">
    <thead>
    <tr>
        <th style="width: 6mm;">#</th>
        <th><?php echo Text::_('COM_ALFA_DOCUMENTS_MODAL_PRODUCT'); ?></th>
        <th class="ctr" style="width: 14mm;"><?php echo Text::_('COM_ALFA_DOCUMENTS_MODAL_QTY'); ?></th>
        <?php if ($showPrices) : ?>
            <th class="num" style="width: 22mm;"><?php echo Text::_('COM_ALFA_DOC_PRINT_UNIT_PRICE'); ?></th>
            <th class="num" style="width: 18mm;"><?php echo Text::_('COM_ALFA_DOC_PRINT_DISCOUNT'); ?></th>
            <th class="num" style="width: 14mm;"><?php echo Text::_('COM_ALFA_DOC_PRINT_VAT_RATE'); ?></th>
            <th class="num" style="width: 22mm;"><?php echo Text::_('COM_ALFA_DOC_PRINT_NET'); ?></th>
            <th class="num" style="width: 24mm;"><?php echo Text::_('COM_ALFA_DOC_PRINT_TOTAL'); ?></th>
        <?php endif; ?>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($lines as $i => $line) : ?>
        <tr>
            <td class="ctr"><?php echo $i + 1; ?></td>
            <td>
                <?php echo htmlspecialchars((string) $line->name, ENT_COMPAT, 'UTF-8'); ?>
                <?php if (!empty($line->sku)) : ?>
                    <div class="line-desc"><?php echo htmlspecialchars((string) $line->sku, ENT_COMPAT, 'UTF-8'); ?></div>
                <?php endif; ?>
                <?php if (!empty($line->description)) : ?>
                    <div class="line-desc"><?php echo htmlspecialchars((string) $line->description, ENT_COMPAT, 'UTF-8'); ?></div>
                <?php endif; ?>
            </td>
            <td class="ctr">
                <?php echo $quantity($line->quantity); ?>
                <?php if (!empty($line->unit)) : ?>
                    <?php echo htmlspecialchars((string) $line->unit, ENT_COMPAT, 'UTF-8'); ?>
                <?php endif; ?>
            </td>
            <?php if ($showPrices) : ?>
                <td class="num"><?php echo $unitFmt($line->unit_price_tax_excl, $line->total_tax_excl, $line->quantity); ?></td>
                <td class="num"><?php echo (float) $line->discount_tax_excl > 0 ? $fmt((float) $line->discount_tax_excl) : '-'; ?></td>
                <td class="num"><?php echo htmlspecialchars(AlfaHelper::percentDisplay(value: $line->tax_rate, decimalSeparator: $separators['decimal']), ENT_COMPAT, 'UTF-8'); ?></td>
                <td class="num"><?php echo $fmt((float) $line->total_tax_excl); ?></td>
                <td class="num"><strong><?php echo $fmt((float) $line->total_tax_incl); ?></strong></td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
