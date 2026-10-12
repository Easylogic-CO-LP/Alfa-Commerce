<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentMoney;
use Alfa\Component\Alfa\Administrator\Service\Document\OrderChargeLines;
use Alfa\Component\Alfa\Administrator\Service\Document\OrderItemLines;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;

/**
 * DocumentLinesField: which lines go on the document, and how many.
 *
 * Not a list field. Each row is a line with three independent things beside it:
 * a tick, a quantity, and the quantity still left for the document.
 *
 * One group is rendered per source, and the modal's script shows the one the
 * draft claims and disables the inputs of every other (a form posts every
 * enabled control, hidden or not):
 *
 *  - the SALES group lists the order's own lines. What is left of each is a
 *    per-type pool, so the group is re-capped whenever the type changes, from
 *    the map published here as `data-remaining`, one entry per sales type;
 *  - one CREDIT group per document a credit may correct lists that
 *    document's own lines, with its frozen names, SKUs and prices and what
 *    credits have not yet given back of each: a credit reads the document it
 *    corrects, never the order as it stands now.
 *
 * Posts as lines[<id>][selected] and lines[<id>][quantity], the shape
 * OrderItemLines::buildSalesLines() and buildCreditLines() read.
 *
 * @since  1.3.0
 */
class DocumentLinesField extends FormField
{
    /**
     * The field type.
     *
     * @var string
     * @since  1.3.0
     */
    protected $type = 'DocumentLines';

    /**
     * Render the sales group and one credit group per correctable document.
     *
     * @return string The field HTML.
     *
     * @since   1.3.0
     */
    protected function getInput(): string
    {
        $orderId = (int) $this->form->getValue('id_order');

        if ($orderId <= 0) {
            return '';
        }

        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        // A kept draft (a refused issue redisplayed) draws each row it holds
        // as the operator left it, in the ONE group it claimed: the order's for
        // any other type, the corrected document's for a credit. data-kept
        // tells the modal's script to keep that group's rows on its first sync.
        $kept = \is_array($this->value) ? $this->value : null;
        $isCredit = \in_array((string) $this->form->getValue('doc_type'), DocumentHelper::creditTypes(), true);
        $keptCorrects = $kept !== null && $isCredit ? (int) $this->form->getValue('id_corrects') : 0;
        $keptSales = $kept !== null && !$isCredit ? $kept : null;

        $html = ['<div id="issue_lines_block">'];
        $lines = $this->orderLines($orderId);

        if ($lines === []) {
            // A credit reads the document it corrects, so its groups are
            // still offered below.
            $html[] = '<div class="alert alert-info">' . Text::_('COM_ALFA_DOCUMENTS_MODAL_NO_LINES') . '</div>';
        } else {
            // The undocumented pool per sales type, so switching the type
            // re-caps every row without a round trip. One read a type, once.
            $remaining = [];

            foreach (DocumentHelper::types() as $type) {
                if (!\in_array($type, DocumentHelper::creditTypes(), true)) {
                    $remaining[$type] = DocumentHelper::remainingQuantities(orderId: $orderId, docType: $type);
                }
            }

            $language = DocumentHelper::defaultLanguageTag();
            // Each unit by the one unit rule, at the order's frozen scale.
            $lineCurrency = OrderItemLines::orderCurrency(orderId: $orderId);

            $html[] = '<table class="table table-sm align-middle issue-lines-group" id="' . $this->id . '" data-corrects="0"'
                . ' data-remaining="' . $escape(json_encode($remaining, JSON_THROW_ON_ERROR)) . '"'
                . ($keptSales !== null ? ' data-kept="1"' : '')
                . ($isCredit ? ' hidden' : '') . '>';
            $html[] = $this->tableHead();

            foreach ($lines as $line) {
                $lineId = (int) $line->id;
                $name = $escape(MultilingualHelper::resolveLangMap((string) $line->name, $language));

                $html[] = '<tr data-line-id="' . $lineId . '">';
                $html[] = '<td><input class="form-check-input issue-line-check" type="checkbox"'
                    . ' name="' . $this->name . '[' . $lineId . '][selected]" value="1"'
                    . ($keptSales === null || !isset($keptSales[$lineId]) || !empty($keptSales[$lineId]['selected']) ? ' checked' : '') . '></td>';
                $html[] = '<td>' . $name
                    . ($line->sku !== null && $line->sku !== ''
                        ? '<div class="small text-muted">' . $escape((string) $line->sku) . '</div>'
                        : '')
                    . '</td>';
                $html[] = '<td class="text-end">'
                    . $escape($lineCurrency->formatUnit(
                        unit: $line->unit_price_tax_incl,
                        lineTotal: $line->total_price_tax_incl,
                        quantity: $line->quantity,
                        rate: $line->custom_unit_price_tax_incl,
                        includeSymbol: false,
                    )) . '</td>';
                $html[] = '<td class="text-center"><input class="form-control form-control-sm issue-line-qty"'
                    . ' type="number" min="0" step="1"'
                    . ' name="' . $this->name . '[' . $lineId . '][quantity]" value="'
                    . ($keptSales === null ? '0' : $escape((string) ($keptSales[$lineId]['quantity'] ?? '0'))) . '"></td>';
                $html[] = '<td class="text-center issue-line-remaining" data-line-id="' . $lineId . '">-</td>';
                $html[] = '</tr>';
            }

            $html[] = '</tbody></table>';
        }

        // An order on which a credit note carries a charge line nothing active
        // accounts for can take no credit at all, so it offers no credit group.
        if (OrderChargeLines::unrecordedCreditNotes(orderId: $orderId) === []) {
            foreach (DocumentHelper::correctableDocuments(DocumentHelper::getForOrder($orderId)) as $doc) {
                $html[] = $this->creditGroup(doc: $doc, kept: $keptCorrects === (int) $doc->id ? $kept : null);
            }
        }

        $html[] = '</div>';

        return implode('', $html);
    }

    /**
     * One credit group: the corrected document's own lines and what credits
     * have not yet given back of each.
     *
     * Every row names the document's frozen line, never the order's line as it
     * reads now: a credit gives back what that document charged. Server
     * defaults mirror the sales group's: each open row ticked at quantity 0,
     * for the modal's script to fill from its data-remaining. A row with
     * nothing left is rendered disabled and stays on screen as history.
     *
     * @param object $doc The document a credit may correct.
     * @param array|null $kept The kept draft's lines when this group is the one it claimed.
     *
     * @return string The group's table, hidden until the script shows it.
     *
     * @since   1.14.0
     */
    private function creditGroup(object $doc, ?array $kept): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $unitOf = DocumentMoney::unitFormatter(document: $doc);

        $html = ['<table class="table table-sm align-middle issue-lines-group" data-corrects="' . (int) $doc->id . '"'
            . ($kept !== null ? ' data-kept="1"' : '') . ' hidden>'];
        $html[] = $this->tableHead();

        foreach (OrderItemLines::remainingForCredit(correctsId: (int) $doc->id) as $lineId => $entry) {
            $lineId = (int) $lineId;
            $left = self::quantityText(quantity: max(0.0, (float) $entry->remaining_qty));
            $open = (float) $entry->remaining_qty > 0.000001;
            $sku = (string) ($entry->row->sku ?? '');

            $html[] = '<tr data-line-id="' . $lineId . '" data-remaining="' . $left . '">';
            $html[] = '<td><input class="form-check-input issue-line-check" type="checkbox"'
                . ' name="' . $this->name . '[' . $lineId . '][selected]" value="1"'
                . ($kept === null || !isset($kept[$lineId]) || !empty($kept[$lineId]['selected']) ? ' checked' : '')
                . ($open ? '' : ' disabled') . '></td>';
            $html[] = '<td>' . $escape((string) $entry->row->name)
                . ($sku !== '' ? '<div class="small text-muted">' . $escape($sku) . '</div>' : '')
                . '</td>';
            $html[] = '<td class="text-end">' . $escape($unitOf(
                $entry->row->unit_price_tax_incl,
                $entry->row->total_tax_incl,
                $entry->row->quantity,
                $entry->row->custom_unit_price_tax_incl,
            )) . '</td>';
            $html[] = '<td class="text-center"><input class="form-control form-control-sm issue-line-qty"'
                . ' type="number" min="0" step="any" max="' . $left . '"'
                . ' name="' . $this->name . '[' . $lineId . '][quantity]" value="'
                . ($kept === null ? '0' : $escape((string) ($kept[$lineId]['quantity'] ?? '0'))) . '"'
                . ($open ? '' : ' disabled') . '></td>';
            $html[] = '<td class="text-center issue-line-remaining" data-line-id="' . $lineId . '">' . $left . '</td>';
            $html[] = '</tr>';
        }

        $html[] = '</tbody></table>';

        return implode('', $html);
    }

    /**
     * The column heads every group shares.
     *
     * @return string The <thead> and the opening <tbody>.
     *
     * @since   1.14.0
     */
    private function tableHead(): string
    {
        return '<thead><tr>'
            . '<th class="w-1"></th>'
            . '<th>' . Text::_('COM_ALFA_DOCUMENTS_MODAL_PRODUCT') . '</th>'
            . '<th class="text-end">' . Text::_('COM_ALFA_DOCUMENTS_MODAL_UNIT_PRICE') . '</th>'
            . '<th class="text-center" style="width: 130px;">' . Text::_('COM_ALFA_DOCUMENTS_MODAL_QTY') . '</th>'
            . '<th class="text-center">' . Text::_('COM_ALFA_DOCUMENTS_MODAL_REMAINING') . '</th>'
            . '</tr></thead><tbody>';
    }

    /**
     * A quantity as text, at the issuer's quantity precision, trailing zeros dropped.
     *
     * The row's data-remaining, its input's max and its remaining cell carry
     * this same text, never a raw float cast.
     *
     * @param float $quantity The quantity.
     *
     * @return string For example "2", "0.5" or "0.125".
     *
     * @since   1.14.0
     */
    private static function quantityText(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 6, '.', ''), '0'), '.');
    }

    /**
     * The order's lines, in their stored order.
     *
     * Read here rather than taken from the enriched order the screen already
     * holds: a field is rendered from its form, not from a view, and the
     * columns a pick needs are plain scalars.
     *
     * @param int $orderId The order.
     *
     * @return array<int, object> The line rows.
     *
     * @since   1.3.0
     */
    private function orderLines(int $orderId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'name', 'sku', 'quantity', 'unit_price_tax_incl', 'total_price_tax_incl', 'custom_unit_price_tax_incl']))
            ->from($db->quoteName('#__alfa_order_items'))
            ->where($db->quoteName('id_order') . ' = :order')
            ->order($db->quoteName('id') . ' ASC')
            ->bind(':order', $orderId, ParameterType::INTEGER);

        return $db->setQuery($query)->loadObjectList() ?: [];
    }

    /**
     * No label row: the table carries its own heading.
     *
     * @return string Empty.
     *
     * @since   1.3.0
     */
    protected function getLabel(): string
    {
        return '';
    }
}
