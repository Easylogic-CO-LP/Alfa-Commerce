<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\KeptPost;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;

/**
 * The product stock field: one storage, two renderings (progressive
 * disclosure), over the locations stock can be corrected at: every published
 * location, plus an unpublished one while it still holds a quantity
 * ({@see InventoryAvailabilityService::locationBreakdown()}).
 *
 * ONE such location (the default) -> a plain quantity input proxying its row.
 * TWO OR MORE -> the per-location breakdown is MANDATORY (every deduction needs
 * an explicit source), with the total computed, never entered.
 *
 * Every figure comes from the stock records, never from the product row's
 * stock columns, which are derived figures. A tracked figure carries the
 * baseline it was rendered with, so the model can tell a changed figure from
 * an untouched one. A post kept after a failed save or a reload is rendered
 * back as posted, with the baseline of its first rendering. An untracked
 * product renders empty opening inputs: switching tracking on requires the
 * operator to state the quantity on hand.
 *
 * Either rendering is an ADAPTER: the model turns changed figures into
 * inventory commands; no rendering writes a column.
 *
 * @since  1.4.0
 */
class InventoryStockField extends FormField
{
    /**
     * The user-state key the item edit form keeps its post under.
     *
     * @since  1.10.0
     */
    private const KEPT_POST_KEY = 'com_alfa.edit.item.data';

    /**
     * @var string Field type.
     */
    protected $type = 'InventoryStock';

    /**
     * Render the input(s).
     *
     * @return string
     *
     * @since  1.4.0
     */
    protected function getInput()
    {
        $itemId = (int) $this->form->getValue('id');

        // A product with combinations owns no item-level unit: its figure is
        // read-only and summed over its combination units.
        if ($itemId > 0 && self::countCombinations(itemId: $itemId) > 0) {
            return self::combinationAggregateInput(itemId: $itemId);
        }

        // THE SIBLING INLINE EDITOR'S LOCATION SET. Counting published locations
        // alone hid a retired shelf's stock from the form that edits it, while that
        // stock kept counting towards the product's total. A brand-new item has no
        // ledger rows, so it lists the published locations at zero and keeps its
        // shape across the first save; the model adjusts each location up from there.
        $locations = (new InventoryAvailabilityService())->locationBreakdown(itemId: $itemId);

        // A kept post belongs to the record it was posted for, and only to it.
        $kept = KeptPost::forRecord(stateKey: self::KEPT_POST_KEY, recordId: $itemId);

        // The STORED flag decides, not the form value: a post kept with tracking
        // switched on still describes an untracked product until a save commits
        // the switch. A new product has nothing stored and renders tracked.
        $tracked = $itemId === 0 || self::storedManageStock(itemId: $itemId) === 1;

        // One row can only be the default location (always published), which then
        // holds every unit, so the plain figure proxies it exactly.
        if (\count($locations) <= 1) {
            $ledger = InventoryAvailabilityService::formatQuantity(
                value: (float) ($locations[0]['physical'] ?? 0.0),
            );
            $keptValue = self::keptFigure(values: $kept, key: 'stock');

            if (!$tracked) {
                return '<input type="number" name="' . $this->name . '" id="' . $this->id . '"'
                    . ' value="' . self::escape(value: $keptValue ?? '') . '"'
                    . ' placeholder="' . self::escape(value: $ledger) . '"'
                    . ' class="form-control" step="any">'
                    . '<div class="form-text">'
                    . self::escape(value: Text::sprintf('COM_ALFA_INVENTORY_TRACKING_OPENING_HINT', $ledger))
                    . '</div>';
            }

            $keptBaselines = \is_array($kept['inventory_stock_baseline'] ?? null) ? $kept['inventory_stock_baseline'] : [];

            return '<input type="number" name="' . $this->name . '" id="' . $this->id . '"'
                . ' value="' . self::escape(value: $keptValue ?? $ledger) . '"'
                . ' class="form-control" step="any">'
                . self::baselineInput(key: 0, value: self::keptFigure(values: $keptBaselines, key: 0) ?? $ledger);
        }

        $keptFigures = \is_array($kept['inventory_stock'] ?? null) ? $kept['inventory_stock'] : [];
        $keptBaselines = \is_array($kept['inventory_stock_baseline'] ?? null) ? $kept['inventory_stock_baseline'] : [];

        // The computed total is maintained by a delegated listener in admin.js,
        // addressed through the data attributes below. The component's asset file
        // is registered here because only a routed component request registers it;
        // a render with no document has no page to attach the script to.
        $document = Factory::getApplication()->getDocument();

        if ($document !== null) {
            $assets = $document->getWebAssetManager();
            $assets->getRegistry()->addExtensionRegistryFile(name: 'com_alfa');
            // useScript() is resolved by the asset manager's __call, so its
            // argument is positional.
            $assets->useScript('com_alfa.admin');
        }

        $total = 0.0;
        $recorded = 0.0;
        $html = '<table class="table table-sm mb-1" id="' . $this->id . '_locations" data-alfa-location-total>';
        $html .= '<thead><tr>'
            . '<th>' . Text::_('COM_ALFA_INVENTORY_HEADING_LOCATION') . '</th>'
            . '<th class="w-25 text-end">' . Text::_('COM_ALFA_FORM_LBL_ITEM_STOCK') . '</th>'
            . '<th class="w-25 text-end">' . Text::_('COM_ALFA_LOCATION_HEADING_RESERVED') . '</th>'
            . '<th class="w-25 text-end">' . Text::_('COM_ALFA_INVENTORY_HEADING_AVAILABLE') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($locations as $location) {
            $locationId = (int) $location['location_id'];
            $physical = (float) $location['physical'];
            $reserved = (float) $location['reserved'];
            $ledger = InventoryAvailabilityService::formatQuantity(value: $physical);
            $keptValue = self::keptFigure(values: $keptFigures, key: $locationId);
            $recorded += $physical;

            // Tracked: the kept figure or the ledger, with its baseline. Untracked:
            // an empty opening input, the recorded figure only as a hint.
            $value = $tracked ? ($keptValue ?? $ledger) : ($keptValue ?? '');
            $total += (float) $value;

            $html .= '<tr><td>' . self::escape(value: (string) $location['title'])
                . ($location['is_default']
                    ? ' <span class="badge bg-primary">' . Text::_('COM_ALFA_LOCATION_BADGE_DEFAULT') . '</span>'
                    : '')
                // Listed only because it still holds stock: it can be drained from
                // here, and the inventory kernel refuses to put new stock into it.
                . (!$location['published']
                    ? ' <span class="badge bg-secondary">' . Text::_('JDISABLED') . '</span>'
                    : '')
                . '</td>'
                . '<td class="text-end"><input type="number" step="any" class="form-control form-control-sm text-end"'
                . ' data-alfa-location-stock'
                . ' name="jform[inventory_stock][' . $locationId . ']"'
                . ' value="' . self::escape(value: $value) . '"'
                . ($tracked ? '' : ' placeholder="' . self::escape(value: $ledger) . '"')
                . '>'
                . ($tracked
                    ? self::baselineInput(key: $locationId, value: self::keptFigure(values: $keptBaselines, key: $locationId) ?? $ledger)
                    : '')
                . '</td>'
                . '<td class="text-end text-muted">' . InventoryAvailabilityService::formatQuantity(value: $reserved) . '</td>'
                . '<td class="text-end text-muted">' . InventoryAvailabilityService::formatQuantity(value: $physical - $reserved) . '</td></tr>';
        }

        $html .= '</tbody><tfoot><tr>'
            . '<td>' . Text::_('COM_ALFA_INVENTORY_STOCK_TOTAL') . '</td>'
            . '<td class="text-end"><strong data-alfa-location-total-value id="' . $this->id . '_total">'
            . InventoryAvailabilityService::formatQuantity(value: $total) . '</strong></td>'
            . '<td></td><td></td></tr></tfoot></table>';
        $html .= '<div class="form-text">' . Text::_('COM_ALFA_INVENTORY_STOCK_BREAKDOWN_HINT') . '</div>';

        if (!$tracked) {
            $html .= '<div class="form-text">'
                . self::escape(value: Text::sprintf(
                    'COM_ALFA_INVENTORY_TRACKING_OPENING_HINT',
                    InventoryAvailabilityService::formatQuantity(value: $recorded),
                ))
                . '</div>';
        }

        return $html;
    }

    /**
     * The read-only figure of a product with combinations, from the stock records
     * of its combination units.
     *
     * Physical and reserved sum every combination still in the catalogue (an
     * archived one is no part of the product's figures); available sums only the
     * published combinations that track stock, which is what the product can
     * sell. Stock still recorded on the product itself is not part of either: it
     * cannot be sold while combinations exist, so it is named in a notice.
     *
     * @param int $itemId The product.
     *
     *
     * @since  1.10.0
     */
    private static function combinationAggregateInput(int $itemId): string
    {
        $aggregate = InventoryAvailabilityService::combinationAggregate(itemId: $itemId);

        $display = InventoryAvailabilityService::formatQuantity(value: $aggregate['physical'])
            . ', ' . Text::_('COM_ALFA_INVENTORY_ABBR_AVAILABLE') . ' '
            . InventoryAvailabilityService::formatQuantity(value: $aggregate['salable'])
            . ' - ' . Text::_('COM_ALFA_INVENTORY_ABBR_RESERVED') . ' '
            . InventoryAvailabilityService::formatQuantity(value: $aggregate['reserved']);

        $html = '<input type="text" class="form-control" readonly value="' . self::escape(value: $display) . '">'
            . '<div class="form-text">' . Text::_('COM_ALFA_INVENTORY_STOCK_COMBINATIONS_AGGREGATE') . '</div>';

        if ($aggregate['item_level_rows_not_zero'] > 0) {
            $html .= '<div class="alert alert-warning mt-2 mb-0">'
                . self::escape(value: Text::sprintf(
                    'COM_ALFA_INVENTORY_STOCK_STRANDED',
                    InventoryAvailabilityService::formatQuantity(value: $aggregate['item_level_physical']),
                ))
                . '</div>';
        }

        return $html;
    }

    /**
     * Combinations of a product that are still in the catalogue. An archived
     * combination is kept only as the stock identity of order history and owns
     * none of the product's stock: a product whose combinations are all archived
     * renders as a product without combinations.
     *
     * @param int $itemId The product.
     *
     *
     * @since  1.10.0
     */
    private static function countCombinations(int $itemId): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_item_combinations'))
                ->where($db->quoteName('item_id') . ' = :itemId')
                ->where(CombinationState::currentSql(column: $db->quoteName('state')))
                ->bind(':itemId', $itemId, ParameterType::INTEGER),
        )->loadResult();
    }

    /**
     * The product's stored stock-tracking flag.
     *
     * @param int $itemId The product.
     *
     * @return int|null The stored value; null when unset or the row is gone.
     *
     * @since  1.10.0
     */
    private static function storedManageStock(int $itemId): ?int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $stored = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('manage_stock'))
                ->from($db->quoteName('#__alfa_items'))
                ->where($db->quoteName('id') . ' = :itemId')
                ->bind(':itemId', $itemId, ParameterType::INTEGER),
        )->loadResult();

        return $stored === null ? null : (int) $stored;
    }

    /**
     * One figure out of a kept post, exactly as it was posted.
     *
     * @param array $values The kept values.
     * @param int|string $key The figure's key.
     *
     * @return string|null The posted figure; null when none was posted.
     *
     * @since  1.10.0
     */
    private static function keptFigure(array $values, int|string $key): ?string
    {
        return \array_key_exists($key, $values) && \is_scalar($values[$key]) ? (string) $values[$key] : null;
    }

    /**
     * Escape a value for an HTML attribute or text node.
     *
     * @param string $value The value.
     *
     *
     * @since  1.10.0
     */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * THE FIGURE THE OPERATOR ACTUALLY SAW, carried back with the post.
     *
     * This rendering is an adapter: the model turns a changed figure into an
     * inventory command that converges the stock record to it. A figure equal to
     * its baseline is untouched and issues no command, so a form left open while
     * three units sell does not bring them back when it is saved unchanged.
     *
     * A post kept after a failed save or a reload is rendered with the baseline
     * of its FIRST rendering, never with the kept figure itself: otherwise a
     * typed change would read as untouched on the second save and be dropped.
     *
     * @param int $key Location id, or 0 for the single-figure rendering.
     * @param string $value The baseline figure.
     *
     *
     * @since  1.10.0
     */
    private static function baselineInput(int $key, string $value): string
    {
        return '<input type="hidden" name="jform[inventory_stock_baseline][' . $key . ']"'
            . ' value="' . self::escape(value: $value) . '">';
    }
}
