<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartQuantityPolicy;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Administrator\Service\ItemFields\ItemFieldDisplayResolver;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * Frontend item-fields data loader: the ONE place the site resolves what a
 * product carries (the catalog list, the item page and the cart all call it).
 *
 * It mirrors MediaHelper::getMediaData(): pass item ids, get a per-item map
 * back. Everything is batched (no N+1) and memoised in request-level static
 * caches at three levels (item, field definition and value), so asking for the
 * same item (or a value/field shared by several items) never hits the database
 * twice in one request.
 *
 * Each item's data object carries:
 *   ->fields        Fields the item actually uses, DERIVED from its values
 *                   (every option / combination value / informational value
 *                   knows its item_field_id). Per-item clones of the shared
 *                   definition, each carrying only that item's offered values.
 *   ->options       Option rows: value + price delta (operation/amount).
 *   ->combinations  Published combinations: value set, their ledger stock
 *                   figures and the in_stock verdict of the shared stock rule
 *                   (variant gray-out, no server round-trip).
 *   ->informational Informational (spec) values.
 *
 * Roles live in three independent tables (item_info_values / item_option_values
 * / item_combinations); a value's field role is wherever it is used, never a
 * re-checked flag; see ItemFieldsAdminHelper.
 *
 * @since  1.3.0
 */
class ItemFieldsHelper
{
    /**
     * Field roles in storefront render order: a field's place in the
     * variation -> option -> informational flow (ROLE_NONE = not used here). The
     * field sort and the ShowOn forward-only guard read these; mirrors the admin
     * ItemFieldsAdminHelper so backend and storefront agree on "earlier than".
     *
     * @since  1.3.0
     */
    private const ROLE_VARIATION = 0;
    private const ROLE_OPTION = 1;
    private const ROLE_INFORMATIONAL = 2;
    private const ROLE_NONE = 3;

    /**
     * Per-item data, keyed by item id. A cached null-equivalent (empty object)
     * is stored even for items that carry nothing, so they are never re-queried.
     *
     * @var array<int, object>
     *
     * @since  1.3.0
     */
    private static array $itemCache = [];

    /**
     * Field definitions keyed by id (type, decoded params, active-language
     * label). Misses are cached as null so a missing field is never re-queried.
     *
     * @var array<int, object|null>
     *
     * @since  1.3.0
     */
    private static array $fieldCache = [];

    /**
     * Pool values keyed by id (item_field_id, decoded params, active-language
     * name). Misses are cached as null so a missing value is never re-queried.
     *
     * @var array<int, object|null>
     *
     * @since  1.3.0
     */
    private static array $valueCache = [];

    /**
     * Stock facts of every inventory unit loaded with the items, keyed
     * "itemId|combinationId": the effective tracking flag, the ledger figures
     * (row count, physical, salable at enabled locations), whether the unit's
     * projection is non-zero (input to the missing-ledger test only), whether an
     * integrity hold keeps it off sale until its figures are repaired, the raw
     * backorder mode, the safety floor, the smallest quantity the merchant's
     * quantity rules sell and the low-stock threshold. A plain item
     * is keyed with combination 0; an item bearing any current (non-archived)
     * combination row has no entry of its own, because only its combinations are
     * units. Kept apart from the data objects so none of it reaches a rendered or
     * encoded item.
     *
     * @var array<string, object>
     *
     * @since  1.14.0
     */
    private static array $unitStock = [];

    /**
     * Resolve the field data for a set of items, keyed by item id. Only the
     * items not already cached hit the database; the rest are served from the
     * request cache.
     *
     * @param int[] $itemIds The item ids.
     *
     * @return array<int, object> Item id -> data object (fields/options/combinations/informational).
     *
     * @since  1.3.0
     */
    public static function getDataForItems(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_map('intval', array_filter($itemIds))));

        if (!$itemIds) {
            return [];
        }

        $missing = array_values(array_diff($itemIds, array_keys(self::$itemCache)));

        if ($missing) {
            self::loadItems(itemIds: $missing);
        }

        $out = [];

        foreach ($itemIds as $id) {
            $out[$id] = self::$itemCache[$id] ?? self::emptyData();
        }

        return $out;
    }

    /**
     * Resolve the field data for a single item (cache-served on repeat calls).
     *
     * @param int $itemId The item id.
     *
     * @return object The item's data object.
     *
     * @since  1.3.0
     */
    public static function getDataForItem(int $itemId): object
    {
        return self::getDataForItems(itemIds: [$itemId])[$itemId] ?? self::emptyData();
    }

    /**
     * Field definitions by id (type, decoded params, active-language label),
     * memoised. Values are NOT attached; an item gets only its offered values.
     *
     * @param int[] $fieldIds Field-definition ids.
     *
     * @return array<int, object> Field id -> definition (only ids that exist).
     *
     * @since  1.3.0
     */
    public static function getFields(array $fieldIds): array
    {
        $fieldIds = array_values(array_unique(array_map('intval', array_filter($fieldIds))));
        $missing = array_values(array_diff($fieldIds, array_keys(self::$fieldCache)));

        if ($missing) {
            $db = Factory::getContainer()->get('DatabaseDriver');

            // NO state filter, deliberately. Every id reaching here was read off a
            // row the item already sells on (a combination dimension, an option, a
            // spec), so filtering by state cannot narrow an offer; it can only
            // delete the definition of something already on sale, and this list is
            // what dispatches the field plugins, i.e. what draws the selector. A
            // product would keep its combinations and silently lose the control to
            // choose them. What may or may not be sold is decided by the
            // combination's own state (see loadItems).
            $db->setQuery(
                $db->getQuery(true)
                    ->select('f.*')
                    ->from($db->qn('#__alfa_item_fields', 'f'))
                    ->whereIn($db->qn('f.id'), $missing)
                    ->order('f.ordering ASC'),
            );
            $rows = $db->loadObjectList('id') ?: [];

            foreach ($rows as $field) {
                $field->params_decoded = is_string($field->params) ? (json_decode($field->params, true) ?: []) : [];
                $field->display_name = $field->field_name;
                $field->retired = (int) $field->state !== 1;
            }

            self::applyLanguageNames(
                rows:        $rows,
                table:       '#__alfa_item_fields_',
                foreignKey:  'id_item_field',
                targetField: 'display_name',
            );

            // Cache hits and misses alike (null = known-absent), never re-query.
            foreach ($missing as $id) {
                self::$fieldCache[$id] = $rows[$id] ?? null;
            }
        }

        $out = [];

        foreach ($fieldIds as $id) {
            if (self::$fieldCache[$id] !== null) {
                $out[$id] = self::$fieldCache[$id];
            }
        }

        return $out;
    }

    /**
     * Pool values by id (item_field_id, decoded params, active-language name),
     * memoised.
     *
     * @param int[] $valueIds Value ids.
     *
     * @return array<int, object> Value id -> value (only ids that exist).
     *
     * @since  1.3.0
     */
    public static function getValues(array $valueIds): array
    {
        $valueIds = array_values(array_unique(array_map('intval', array_filter($valueIds))));
        $missing = array_values(array_diff($valueIds, array_keys(self::$valueCache)));

        if ($missing) {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $db->setQuery(
                $db->getQuery(true)
                    ->select([$db->qn('id'), $db->qn('item_field_id'), $db->qn('params'), $db->qn('state')])
                    ->from($db->qn('#__alfa_item_field_values'))
                    ->whereIn($db->qn('id'), $missing),
            );
            $rows = $db->loadObjectList('id') ?: [];

            // Same contract as the fields above: these ids are the ones the item's
            // saved rows point at, so an unpublished value still has to resolve its
            // label; otherwise a live combination renders nameless.
            foreach ($rows as $value) {
                $value->params_decoded = is_string($value->params) ? (json_decode($value->params, true) ?: []) : [];
                $value->display_name = '#' . $value->id;
                $value->retired = (int) $value->state !== 1;
            }

            self::applyLanguageNames(
                rows:        $rows,
                table:       '#__alfa_item_field_values_',
                foreignKey:  'id_item_field_value',
                targetField: 'display_name',
            );

            foreach ($missing as $id) {
                self::$valueCache[$id] = $rows[$id] ?? null;
            }
        }

        $out = [];

        foreach ($valueIds as $id) {
            if (self::$valueCache[$id] !== null) {
                $out[$id] = self::$valueCache[$id];
            }
        }

        return $out;
    }

    /**
     * One of the item's fields by id (int) or machine name (string), for a template
     * override that needs a field's label or values without walking $item->fields.
     *
     *   $colour = ItemFieldsHelper::find($item, 'colour');
     *
     * @param object $item The item (carries ->fields).
     * @param int|string $key Field id, or field machine name (field_name).
     *
     * @return object|null The item's field entry, or null when it carries none.
     *
     * @since  1.10.0
     */
    public static function find(object $item, int|string $key): ?object
    {
        foreach ((array) ($item->fields ?? []) as $field) {
            if (\is_int($key) ? (int) $field->id === $key : (string) ($field->field_name ?? '') === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The fields a listing card draws: those switched on with Show on listings,
     * less any whose effective ShowOn rule reads a field the item carries but the
     * card does not draw, repeated until nothing changes, since dropping one can
     * orphan another's rule. A field the item lacks is no operand here: its rule
     * was already dropped when the rule was resolved.
     *
     * A field the card draws without an input (a plugin's read-only mode) stays
     * drawn, but answers no rule: ShowOn reads the card's inputs, and it has none.
     *
     * @param object $item The item (carries ->fields with the effective showon).
     * @param int[] $readOnlyIds Drawn fields that render no input.
     *
     * @return int[] Field ids, in field order.
     *
     * @since  1.10.0
     */
    public static function listingFieldIds(object $item, array $readOnlyIds = []): array
    {
        $present = [];
        $drawn = [];
        $readOnly = array_flip($readOnlyIds);

        foreach ((array) ($item->fields ?? []) as $field) {
            $present[(string) ($field->field_name ?? '')] = true;

            if (!empty($field->show_on_listing)) {
                $drawn[(int) $field->id] = $field;
            }
        }

        do {
            $drawnNames = [];

            foreach ($drawn as $fieldId => $field) {
                if (!isset($readOnly[$fieldId])) {
                    $drawnNames[(string) ($field->field_name ?? '')] = true;
                }
            }

            $changed = false;

            foreach ($drawn as $fieldId => $field) {
                $rule = $field->params_decoded['showon'] ?? null;

                if (empty($rule)) {
                    continue;
                }

                foreach (self::ruleOperandFields($rule) as $operand) {
                    if (isset($present[$operand]) && !isset($drawnNames[$operand])) {
                        unset($drawn[$fieldId]);
                        $changed = true;

                        break;
                    }
                }
            }
        } while ($changed);

        return array_keys($drawn);
    }

    /**
     * The stock facts of one inventory unit, as loaded with its item.
     *
     * A plain item is asked with combination 0; a combination with its own id.
     * Null when the pair is not a sellable unit of a loaded item: the item level
     * of an item that bears combinations, an unpublished, archived, deleted or
     * foreign combination, or an item that does not exist. The item is loaded (one
     * batch, then cache-served) when it is not in the request cache yet.
     *
     * The object carries managed, ledgerRows, ledgerPhysical, ledgerSalable,
     * projectionNonZero, held (an open integrity hold), backorder (the raw stored
     * mode, resolved by the stock rule), minQty, saleMinimum (the smallest
     * quantity the quantity rules accept, null when they accept none) and
     * stockLowAt. It is a copy: changing it changes nothing.
     *
     * @param int $itemId The item.
     * @param int $combinationId The combination, or 0 for the item itself.
     *
     * @return object|null The unit's facts, or null when the pair is not a unit.
     *
     * @since  1.14.0
     */
    public static function unitStockFor(int $itemId, int $combinationId): ?object
    {
        if ($itemId <= 0) {
            return null;
        }

        if (!isset(self::$itemCache[$itemId])) {
            self::getDataForItems(itemIds: [$itemId]);
        }

        $facts = self::$unitStock[$itemId . '|' . $combinationId] ?? null;

        return $facts !== null ? clone $facts : null;
    }

    /**
     * Clear the request caches (test/CLI re-runs, and after any stock command
     * whose effect the same request must read).
     *
     *
     * @since  1.3.0
     */
    public static function clearCache(): void
    {
        self::$itemCache = [];
        self::$fieldCache = [];
        self::$valueCache = [];
        self::$unitStock = [];
    }

    //  Internal

    /**
     * Batch-load the data for the given (uncached) items and populate the item
     * cache: combinations, options and informational by item id, then the
     * values they reference (via getValues/getFields, themselves cached), then
     * the derived fields. Items that carry nothing are cached empty.
     *
     * @param int[] $itemIds Item ids known to be absent from the cache.
     *
     *
     * @since  1.3.0
     */
    private static function loadItems(array $itemIds): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        // Seed empty so even item-with-nothing is cached (never re-queried).
        $data = [];

        foreach ($itemIds as $id) {
            $data[$id] = self::emptyData();
        }

        // Item stock settings. A combination inherits the tracking flag, backorder
        // mode, safety floor and low-stock threshold from its item when its own is
        // NULL, and each quantity rule (minimum, step, maximum) when its own is
        // not set. The item's projections are read only for the missing-ledger test.
        // bears_combinations counts every current combination row, published or
        // not: an item that has one is never itself a unit. Archived rows are not
        // counted: they remain only as the stock identity of order history, so an
        // item whose combinations are all archived is a plain item again.
        $bears = $db->getQuery(true)
            ->select('1')
            ->from($db->qn('#__alfa_item_combinations', 'bc'))
            ->where($db->qn('bc.item_id') . ' = ' . $db->qn('i.id'))
            ->where(CombinationState::currentSql(column: $db->qn('bc.state')));

        $db->setQuery(
            $db->getQuery(true)
                ->select([
                    $db->qn('i.id'), $db->qn('i.stock'), $db->qn('i.available_quantity'), $db->qn('i.manage_stock'),
                    $db->qn('i.backorder'), $db->qn('i.min_qty'), $db->qn('i.stock_low'),
                    $db->qn('i.quantity_min'), $db->qn('i.quantity_max'), $db->qn('i.quantity_step'),
                    'EXISTS (' . $bears . ') AS ' . $db->qn('bears_combinations'),
                ])
                ->from($db->qn('#__alfa_items', 'i'))
                ->whereIn($db->qn('i.id'), $itemIds),
        );
        $itemStock = $db->loadObjectList('id') ?: [];

        // The ledger figures of every unit in the batch, in one query, with its
        // open integrity hold: item id => combination id => {rows, physical,
        // reserved, salable, held}.
        $ledger = InventoryAvailabilityService::unitFactsForItems(itemIds: $itemIds);

        // Combinations (+ value sets)
        $db->setQuery(
            $db->getQuery(true)
                ->select([
                    $db->qn('id'), $db->qn('item_id'), $db->qn('default_on'),
                    // The canonical variant order, which the resolver sorts by.
                    $db->qn('ordering'),
                    // The projections are read only for the missing-ledger test and
                    // replaced below by the unit's ledger figures; plus the
                    // commercial identity fields.
                    $db->qn('sku'), $db->qn('gtin'), $db->qn('mpn'),
                    $db->qn('stock'), $db->qn('available_quantity'), $db->qn('manage_stock'), $db->qn('stock_action'), $db->qn('stock_low'),
                    // Per-variant stock policy; NULL = inherit the item.
                    $db->qn('backorder'), $db->qn('min_qty'),
                    $db->qn('weight'), $db->qn('width'), $db->qn('height'), $db->qn('depth'),
                    $db->qn('quantity_min'), $db->qn('quantity_max'), $db->qn('quantity_step'),
                ])
                ->from($db->qn('#__alfa_item_combinations'))
                ->whereIn($db->qn('item_id'), $itemIds)
                ->where($db->qn('state') . ' = 1')
                // Per-item drag order (mirrors the backend grid), id as tiebreak.
                ->order($db->qn('ordering') . ' ASC, ' . $db->qn('id') . ' ASC'),
        );
        $combos = $db->loadObjectList('id') ?: [];

        foreach ($combos as $combo) {
            $combo->value_ids = [];
            $combo->values = [];
        }

        if ($combos) {
            $db->setQuery(
                $db->getQuery(true)
                    ->select([$db->qn('combination_id'), $db->qn('value_id')])
                    ->from($db->qn('#__alfa_item_combination_values'))
                    ->whereIn($db->qn('combination_id'), array_map('intval', array_keys($combos))),
            );

            foreach ($db->loadObjectList() ?: [] as $row) {
                if (isset($combos[(int) $row->combination_id])) {
                    $combos[(int) $row->combination_id]->value_ids[] = (int) $row->value_id;
                }
            }
        }

        // Options
        $db->setQuery(
            $db->getQuery(true)
                ->select([
                    $db->qn('id'), $db->qn('item_id'), $db->qn('value_id'),
                    $db->qn('operation'), $db->qn('amount'), $db->qn('apply_on'), $db->qn('ordering'),
                ])
                ->from($db->qn('#__alfa_item_option_values'))
                ->whereIn($db->qn('item_id'), $itemIds)
                ->order($db->qn('ordering') . ' ASC'),
        );
        $options = $db->loadObjectList('id') ?: [];

        // Informational (item id -> value ids)
        $db->setQuery(
            $db->getQuery(true)
                ->select([$db->qn('item_id'), $db->qn('value_id')])
                ->from($db->qn('#__alfa_item_info_values'))
                ->whereIn($db->qn('item_id'), $itemIds)
                // Per-item drag order (mirrors the backend Informational list).
                ->order($db->qn('ordering') . ' ASC'),
        );
        $infoByItem = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $infoByItem[(int) $row->item_id][] = (int) $row->value_id;
        }

        // Every referenced value, then the fields they belong to
        $valueIds = [];

        foreach ($options as $option) {
            $valueIds[(int) $option->value_id] = true;
        }
        foreach ($combos as $combo) {
            foreach ($combo->value_ids as $vid) {
                $valueIds[(int) $vid] = true;
            }
        }
        foreach ($infoByItem as $vids) {
            foreach ($vids as $vid) {
                $valueIds[(int) $vid] = true;
            }
        }

        $values = self::getValues(valueIds: array_keys($valueIds));
        $fieldDefs = self::getFields(fieldIds: array_map(
            static fn ($value) => (int) $value->item_field_id,
            $values,
        ));

        // Wire value objects onto the rows.
        foreach ($options as $option) {
            $option->value = $values[(int) $option->value_id] ?? null;
        }
        foreach ($combos as $combo) {
            $combo->values = array_values(array_filter(array_map(
                static fn ($vid) => $values[(int) $vid] ?? null,
                $combo->value_ids,
            )));
        }

        // Group rows by item.
        $optionsByItem = [];
        $combosByItem = [];

        foreach ($options as $option) {
            $optionsByItem[(int) $option->item_id][] = $option;
        }
        foreach ($combos as $combo) {
            $combosByItem[(int) $combo->item_id][] = $combo;
        }

        // Assemble + cache each item
        // Shop-wide safety-stock floor: the last fallback when neither the
        // combination nor its item sets one. Read once for the whole batch.
        $globalMinQty = (float) ComponentHelper::getParams('com_alfa')->get('min_qty', 0);

        foreach ($itemIds as $id) {
            $entry = $data[$id];
            $entry->options = $optionsByItem[$id] ?? [];
            $entry->combinations = $combosByItem[$id] ?? [];

            $stockRow = $itemStock[$id] ?? null;
            $itemLedger = (array) ($ledger[$id] ?? []);
            $itemLowStock = self::nullableFloat(value: $stockRow->stock_low ?? null);

            // A plain item is its own unit. An item bearing any current combination
            // row has no unit of its own: its item-level ledger is never sellable.
            if ($stockRow !== null && !(int) $stockRow->bears_combinations) {
                self::$unitStock[$id . '|0'] = self::unitFacts(
                    managed:             (int) ($stockRow->manage_stock ?? 0) === 1,
                    ledger:              $itemLedger[0] ?? null,
                    projectionStock:     self::nullableFloat(value: $stockRow->stock),
                    projectionAvailable: self::nullableFloat(value: $stockRow->available_quantity),
                    backorder:           $stockRow->backorder,
                    minQty:              (float) ($stockRow->min_qty ?? $globalMinQty),
                    saleMinimum:         self::saleMinimumOf(combination: null, item: $stockRow),
                    stockLowAt:          InventoryAvailabilityService::lowStockThreshold(combination: null, item: $itemLowStock),
                );
            }

            foreach ($entry->combinations as $combo) {
                // Every published combination is its own inventory unit, judged by
                // the shared stock rule over its ledger, so the variant gray-out
                // states what the cart will enforce. Settings inherit combination ->
                // item -> global (`??` keeps an explicit 0); an explicit tracking flag
                // on the combination wins in either direction, NULL at both levels is
                // untracked. Each quantity rule is the combination's where it sets
                // one, else the item's, exactly as the cart resolves it. Still one
                // query for the batch: the ledger was read above.
                $facts = self::unitFacts(
                    managed:             (int) ($combo->manage_stock ?? $stockRow->manage_stock ?? 0) === 1,
                    ledger:              $itemLedger[(int) $combo->id] ?? null,
                    projectionStock:     self::nullableFloat(value: $combo->stock),
                    projectionAvailable: self::nullableFloat(value: $combo->available_quantity),
                    backorder:           $combo->backorder ?? $stockRow->backorder ?? null,
                    minQty:              (float) ($combo->min_qty ?? $stockRow->min_qty ?? $globalMinQty),
                    saleMinimum:         self::saleMinimumOf(combination: $combo, item: $stockRow),
                    stockLowAt:          InventoryAvailabilityService::lowStockThreshold(
                        combination: self::nullableFloat(value: $combo->stock_low),
                        item:        $itemLowStock,
                    ),
                );

                self::$unitStock[$id . '|' . (int) $combo->id] = $facts;

                // The public figures of a combination are its ledger figures.
                $combo->stock = $facts->ledgerPhysical;
                $combo->available_quantity = $facts->ledgerSalable;
                $combo->in_stock = InventoryAvailabilityService::sellableStatus(status: self::unitStatus(facts: $facts));
            }

            $entry->informational = array_values(array_filter(array_map(
                static fn ($vid) => $values[(int) $vid] ?? null,
                $infoByItem[$id] ?? [],
            )));

            $entry->fields = self::deriveFields(
                entry:        $entry,
                infoValueIds: $infoByItem[$id] ?? [],
                values:       $values,
                fieldDefs:    $fieldDefs,
            );

            self::$itemCache[$id] = $entry;
        }

        // Per-item field config (e.g. a configurator's "areas"): ONE batched read
        // for the whole page, attached as $field->config, so plugins read it via
        // $event->getConfig() with no per-item query (avoids N+1).
        self::attachFieldConfig(entries: $data);
    }

    /**
     * Assemble the stock facts of one unit from its settings and its ledger.
     *
     * @param bool $managed Whether the unit's stock is tracked (effective flag).
     * @param mixed $ledger The unit's ledger figures {rows, physical, reserved,
     *                      salable, held}, or null when the batch read has no
     *                      entry for it.
     * @param float|null $projectionStock The unit's physical projection column.
     * @param float|null $projectionAvailable The unit's salable projection column.
     * @param string|null $backorder The raw stored backorder mode (combination, else item).
     * @param float $minQty The safety floor (combination -> item -> global).
     * @param int|null $saleMinimum The smallest quantity the unit's quantity rules accept,
     *                              or null when they accept none.
     * @param float $stockLowAt The low-stock threshold (0 when none applies).
     *
     * @return object The facts, shaped as unitStockFor() documents.
     *
     * @since  1.14.0
     */
    private static function unitFacts(
        bool $managed,
        mixed $ledger,
        ?float $projectionStock,
        ?float $projectionAvailable,
        ?string $backorder,
        float $minQty,
        ?int $saleMinimum,
        float $stockLowAt,
    ): object {
        $ledger = $ledger !== null ? (object) $ledger : null;

        return (object) [
            'managed' => $managed,
            'ledgerRows' => (int) ($ledger->rows ?? 0),
            'ledgerPhysical' => (float) ($ledger->physical ?? 0.0),
            'ledgerSalable' => (float) ($ledger->salable ?? 0.0),
            'projectionNonZero' => InventoryAvailabilityService::projectionNonZero(
                stock:     $projectionStock,
                available: $projectionAvailable,
            ),
            'held' => !empty($ledger->held),
            'backorder' => $backorder,
            'minQty' => $minQty,
            'saleMinimum' => $saleMinimum,
            'stockLowAt' => $stockLowAt,
        ];
    }

    /**
     * The smallest quantity a unit's quantity rules accept, resolved rule by rule
     * as the cart resolves them: the combination's minimum, step and maximum
     * where it sets one, else the item's.
     *
     * @param object|null $combination The combination row, or null for the item unit.
     * @param object|null $item The item's stock settings row (null when the item is missing).
     *
     * @return int|null The smallest acceptable quantity, or null when no quantity is acceptable.
     *
     * @since  1.14.0
     */
    private static function saleMinimumOf(?object $combination, ?object $item): ?int
    {
        return CartQuantityPolicy::saleMinimum(
            min:  CartQuantityPolicy::ruleValue(combination: $combination->quantity_min ?? null, item: $item->quantity_min ?? null),
            step: CartQuantityPolicy::ruleValue(combination: $combination->quantity_step ?? null, item: $item->quantity_step ?? null),
            max:  CartQuantityPolicy::ruleValue(combination: $combination->quantity_max ?? null, item: $item->quantity_max ?? null),
        );
    }

    /**
     * The shared stock rule's status for a unit's facts.
     *
     * @param object $facts The unit's facts (see unitFacts()).
     *
     * @return string One of the InventoryAvailabilityService STATUS_* constants.
     *
     * @since  1.14.0
     */
    private static function unitStatus(object $facts): string
    {
        return InventoryAvailabilityService::statusFrom(
            salable:           $facts->ledgerSalable,
            managed:           $facts->managed,
            ledgerPresent:     $facts->ledgerRows > 0,
            projectionNonZero: $facts->projectionNonZero,
            integrityHold:     $facts->held,
            backorder:         $facts->backorder,
            minQty:            $facts->minQty,
            saleMinimum:       $facts->saleMinimum,
        );
    }

    /**
     * A nullable numeric column as a float, keeping NULL.
     *
     * @param mixed $value The column value.
     *
     *
     * @since  1.14.0
     */
    private static function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    /**
     * Batch-attach each field's EFFECTIVE per-item config onto $field->config, in
     * one pair of queries for the whole page. Effective = the field's OPTION-row
     * config merged over its item-level config (item-level alone when the field is
     * not placed as an option). Plugins then read it via $event->getConfig() with
     * zero per-item DB hits (the N+1 the loader exists to avoid).
     *
     * @param object[] $entries Item data objects keyed by item id (each with
     *                          ->fields + ->options); $field->config is set in place.
     *
     *
     * @since  1.3.0
     */
    private static function attachFieldConfig(array $entries): void
    {
        $fieldIds = [];
        $optionIds = [];

        foreach ($entries as $entry) {
            foreach ($entry->fields as $field) {
                $fieldIds[(int) $field->id] = true;
            }
            foreach ($entry->options as $option) {
                $optionIds[(int) $option->id] = true;
            }
        }

        if (!$fieldIds) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // Item-level config: [itemId][fieldId] => array, plus the per-item GROUP
        // order off the SAME row (ordering, NULL = inherit global), read here so
        // the fields sort below costs no extra query.
        $db->setQuery(
            $db->getQuery(true)
                ->select([$db->qn('item_id'), $db->qn('field_id'), $db->qn('params'), $db->qn('ordering')])
                ->from($db->qn('#__alfa_item_field_overrides'))
                ->whereIn($db->qn('item_id'), array_map('intval', array_keys($entries)))
                ->whereIn($db->qn('field_id'), array_map('intval', array_keys($fieldIds))),
        );
        $itemCfg = [];
        $itemFieldOrder = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $itemCfg[(int) $row->item_id][(int) $row->field_id] = (new Registry((string) $row->params))->toArray();

            if ($row->ordering !== null) {
                $itemFieldOrder[(int) $row->item_id][(int) $row->field_id] = (int) $row->ordering;
            }
        }

        // Option-row config: [optionId] => array (scope_id = the option-value id).
        $optionCfg = [];

        if ($optionIds) {
            $db->setQuery(
                $db->getQuery(true)
                    ->select([$db->qn('scope_id'), $db->qn('params')])
                    ->from($db->qn('#__alfa_item_combination_field_params'))
                    ->where($db->qn('scope') . ' = ' . $db->q('option'))
                    ->whereIn($db->qn('scope_id'), array_map('intval', array_keys($optionIds))),
            );

            foreach ($db->loadObjectList() ?: [] as $row) {
                $optionCfg[(int) $row->scope_id] = (new Registry((string) $row->params))->toArray();
            }
        }

        // Attach the effective config to each field.
        foreach ($entries as $itemId => $entry) {
            // First option of each field on this item (its option-scope row).
            $optionOfField = [];

            foreach ($entry->options as $option) {
                $fieldId = (int) ($option->value->item_field_id ?? 0);

                if ($fieldId > 0 && !isset($optionOfField[$fieldId])) {
                    $optionOfField[$fieldId] = (int) $option->id;
                }
            }

            // Value ids that participate in this item's combinations: a field is a
            // VARIANT axis (and so can never be a ShowOn target) when any of its
            // values appears in a combination.
            $comboValueIds = [];

            foreach ($entry->combinations ?? [] as $combo) {
                foreach ($combo->value_ids ?? [] as $vid) {
                    $comboValueIds[(int) $vid] = true;
                }
            }

            // Value ids used as OPTIONS / INFORMATIONAL, so each field's ROLE
            // (variation / option / informational) can be derived for the render
            // order below, mirroring the backend section order.
            $optionValueIds = [];

            foreach ($entry->options as $option) {
                $optionValueIds[(int) $option->value_id] = true;
            }

            $infoValueIds = [];

            foreach ($entry->informational ?? [] as $infoValue) {
                $infoValueIds[(int) $infoValue->id] = true;
            }

            // Render order = the backend SECTION order first (variations -> options
            // -> informational), then the per-item group order WITHIN each section
            // (the dragged option/info group headers; empty -> global field order).
            // usort is stable (PHP 8.0+), so fields without an explicit order keep
            // their global relative position. This is exactly what the surface
            // dispatch (foreach $item->fields) renders, so the storefront mirrors
            // the backend.
            $rolePriority = static function ($field) use ($comboValueIds, $optionValueIds, $infoValueIds): int {
                foreach ($field->values ?? [] as $value) {
                    $vid = (int) $value->id;

                    if (isset($comboValueIds[$vid])) {
                        return self::ROLE_VARIATION;
                    }

                    if (isset($optionValueIds[$vid])) {
                        return self::ROLE_OPTION;
                    }

                    if (isset($infoValueIds[$vid])) {
                        return self::ROLE_INFORMATIONAL;
                    }
                }

                return self::ROLE_NONE;
            };

            $fieldOrder = $itemFieldOrder[(int) $itemId] ?? [];

            // Fully deterministic: section role, then per-item group order, then the
            // global field ordering, then id, so the order NEVER depends on the
            // (unordered) field-definition load and is stable across every render.
            $fieldComparator = static function ($a, $b) use ($rolePriority, $fieldOrder) {
                $roleA = $rolePriority($a);
                $roleB = $rolePriority($b);

                if ($roleA !== $roleB) {
                    return $roleA <=> $roleB;
                }

                $orderA = $fieldOrder[(int) $a->id] ?? PHP_INT_MAX;
                $orderB = $fieldOrder[(int) $b->id] ?? PHP_INT_MAX;

                if ($orderA !== $orderB) {
                    return $orderA <=> $orderB;
                }

                $globalA = (int) ($a->ordering ?? 0);
                $globalB = (int) ($b->ordering ?? 0);

                if ($globalA !== $globalB) {
                    return $globalA <=> $globalB;
                }

                return (int) $a->id <=> (int) $b->id;
            };

            // We need a SORTED view here ONLY to compute each field's rank for the
            // ShowOn forward-only guard (a rule may gate only on an EARLIER,
            // interactive field). The REAL ordering of $entry->fields happens at the
            // end (the final usort below). $ordered is a copy-on-write copy, so
            // sorting it now leaves $entry->fields untouched until then.
            $ordered = $entry->fields;
            usort($ordered, $fieldComparator);
            $fieldMeta = [];

            foreach ($ordered as $rank => $orderedField) {
                $fieldMeta[(string) ($orderedField->field_name ?? '')] = [
                    'role' => $rolePriority($orderedField),
                    'rank' => $rank,
                ];
            }

            foreach ($entry->fields as $field) {
                $fieldId = (int) $field->id;
                $base = $itemCfg[(int) $itemId][$fieldId] ?? [];
                $optionId = $optionOfField[$fieldId] ?? 0;

                $field->config = new Registry(
                    $optionId > 0 ? array_merge($base, $optionCfg[$optionId] ?? []) : $base,
                );

                self::resolveEffectiveShowon($field, $comboValueIds, $fieldMeta);
            }

            usort($entry->fields, $fieldComparator);
        }
    }

    /**
     * Resolve a field's EFFECTIVE ShowOn rule onto $field->params_decoded['showon'],
     * which the surface emits as data-showon-rule. Two scopes, merged here so the
     * runtime engine stays agnostic:
     *
     *  - the per-item rule ($field->config['showon']) OVERRIDES the global
     *    field-definition rule when present (item wins);
     *  - a field that is a VARIANT AXIS in this item is never a ShowOn target; its
     *    rule is dropped, so no data-showon-rule is emitted and the engine never
     *    touches it (visibility of a variant value is combinations, not ShowOn).
     *
     * The "variant can't be a target" boundary lives HERE (the layer that already
     * knows the combinations), never in the engine.
     *
     * @param object $field The per-item field object (carries ->values, ->config).
     * @param array $comboValueIds Value ids appearing in this item's combinations (as a set).
     * @param array $fieldMeta Field name => [role, rank] in render order (present fields only).
     *
     *
     * @since  1.3.0
     */
    private static function resolveEffectiveShowon(object $field, array $comboValueIds, array $fieldMeta): void
    {
        if (!isset($field->params_decoded) || !is_array($field->params_decoded)) {
            $field->params_decoded = [];
        }

        foreach ($field->values ?? [] as $value) {
            if (isset($comboValueIds[(int) $value->id])) {
                unset($field->params_decoded['showon']);

                return;
            }
        }

        $itemRule = $field->config->get('showon');

        if (!empty($itemRule)) {
            $field->params_decoded['showon'] = $itemRule;
        }

        // Operand guard: drop the rule (field stays shown) when it gates on a field
        // it cannot meaningfully read, so the storefront matches the backend badge:
        //   - absent from this item (dangling: chiefly a global rule reaching for a
        //     field this item lacks), OR
        //   - informational (a static value, never a real switch), OR
        //   - shown AFTER this field (forward reference: not chosen yet when this
        //     field renders, so the condition can never hold in the sequential flow).
        $effective = $field->params_decoded['showon'] ?? null;

        if (!empty($effective)) {
            $selfRank = $fieldMeta[(string) ($field->field_name ?? '')]['rank'] ?? PHP_INT_MAX;

            foreach (self::ruleOperandFields($effective) as $operandField) {
                $meta = $fieldMeta[$operandField] ?? null;

                if ($meta === null || $meta['role'] > self::ROLE_OPTION || $meta['rank'] >= $selfRank) {
                    unset($field->params_decoded['showon']);

                    break;
                }
            }
        }
    }

    /**
     * The distinct field machine names a ShowOn rule references as operands (the
     * part before the dot in each `name.facet`), walking nested groups.
     *
     * @param string|array $rule The stored rule (JSON string or decoded array).
     *
     * @return string[]
     *
     * @since  1.3.0
     */
    private static function ruleOperandFields($rule): array
    {
        $rule = \is_array($rule) ? $rule : (json_decode((string) $rule, true) ?: []);
        $names = [];

        self::collectOperands($rule, $names);

        return array_keys($names);
    }

    /**
     * Collect operand field names from a rule node into $names (keys).
     *
     * @param array $node A rule group/leaf.
     * @param array $names Accumulator (field name => true), by reference.
     *
     *
     * @since  1.3.0
     */
    private static function collectOperands(array $node, array &$names): void
    {
        foreach ($node['rules'] ?? [] as $child) {
            if (!\is_array($child)) {
                continue;
            }

            if (isset($child['rules'])) {
                self::collectOperands($child, $names);

                continue;
            }

            $operand = (string) ($child['operand'] ?? '');
            $name = explode('.', $operand, 2)[0];

            if ($name !== '') {
                $names[$name] = true;
            }
        }
    }

    /**
     * Derive an item's fields from the values it uses (options + combination
     * values + informational), grouped by field in definition order. Each
     * returned field is a per-item clone of the shared definition carrying only
     * that item's offered values (no cross-item bleed).
     *
     * @param object $entry The item's data object (options + combinations).
     * @param int[] $infoValueIds The item's informational value ids.
     * @param object[] $values Value pool keyed by id.
     * @param object[] $fieldDefs Field definitions keyed by id (display order).
     *
     * @return object[] Per-item field objects in definition order.
     *
     * @since  1.3.0
     */
    private static function deriveFields(object $entry, array $infoValueIds, array $values, array $fieldDefs): array
    {
        $usedValueIds = [];

        foreach ($entry->options as $option) {
            $usedValueIds[(int) $option->value_id] = true;
        }
        foreach ($entry->combinations as $combo) {
            foreach ($combo->value_ids as $vid) {
                $usedValueIds[(int) $vid] = true;
            }
        }
        foreach ($infoValueIds as $vid) {
            $usedValueIds[(int) $vid] = true;
        }

        $valuesByField = [];

        foreach (array_keys($usedValueIds) as $vid) {
            $value = $values[$vid] ?? null;

            if ($value !== null) {
                $valuesByField[(int) $value->item_field_id][] = $value;
            }
        }

        $fields = [];

        foreach ($fieldDefs as $fid => $definition) {
            if (!isset($valuesByField[(int) $fid])) {
                continue;
            }

            $field = clone $definition;
            $field->values = $valuesByField[(int) $fid];
            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Overlay active-language names from a per-language aux table onto loaded
     * rows (keyed by their own id). Silently leaves machine names when the aux
     * table is pending resync.
     *
     * @param object[] $rows Rows keyed by id (mutated in place).
     * @param string $table Aux table base (e.g. '#__alfa_item_fields_').
     * @param string $foreignKey Aux table column pointing back at the row id.
     * @param string $targetField The row property to overwrite with the name.
     *
     *
     * @since  1.3.0
     */
    private static function applyLanguageNames(array $rows, string $table, string $foreignKey, string $targetField): void
    {
        if (!$rows) {
            return;
        }

        // The one resolver the order snapshot also reads, so a live label and a
        // frozen label are the same string. Field or value domain is the id
        // column the caller keys on.
        $ids = array_map('intval', array_keys($rows));
        $maps = $foreignKey === 'id_item_field'
            ? ItemFieldDisplayResolver::fieldLabelMaps($ids)
            : ItemFieldDisplayResolver::valueTextMaps($ids);

        foreach ($rows as $id => $row) {
            $resolved = MultilingualHelper::resolveLangMap($maps[(int) $id] ?? []);

            if ($resolved !== '') {
                $row->{$targetField} = $resolved;
            }
        }
    }

    /**
     * A fresh, empty per-item data object.
     *
     *
     * @since  1.3.0
     */
    private static function emptyData(): object
    {
        return (object) [
            'fields' => [],
            'options' => [],
            'combinations' => [],
            'informational' => [],
        ];
    }
}
