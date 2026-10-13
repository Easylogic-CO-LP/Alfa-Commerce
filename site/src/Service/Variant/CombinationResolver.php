<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Variant;

use stdClass;

defined('_JEXEC') or die;

/**
 * Resolves a buyer's variant selection against an item's combinations, entirely
 * server-side.
 *
 * Given the item's variant fields (e.g. Size, Colour), its published
 * combinations (each a set of value ids + an in_stock flag) and the partial
 * selection made so far (one value id per field), it computes:
 *
 *   - which value of each field is still SELECTABLE: selectable means the value
 *     appears in at least one combination consistent with the values already
 *     chosen for the OTHER fields; the rest are grayed out;
 *   - which of those selectable values also lead to an IN-STOCK combination;
 *   - the ACTIVE combination once every variant field is chosen;
 *   - whether add-to-cart may be enabled (complete selection + in stock).
 *
 * Wherever it has to pick ONE combination on the shopper's behalf (the initial
 * selection, the combination a card displays), it prefers one that can be
 * bought: the configured default when it can, else the first buyable one in the
 * canonical variant order, else the configured default, else the first. The
 * canonical order is the combinations grid's own: ordering ascending, then id.
 * The resolver sorts its input by it, so neither a caller's array order nor the
 * database's row order decides.
 *
 * It is pure: no database and no request access. The view feeds it the data
 * already loaded by ItemFieldsHelper plus the request's sel[fieldId]=valueId,
 * and re-renders the item component on each change.
 *
 * @since  2.0.0
 */
class CombinationResolver
{
    /**
     * Resolve a selection against the item's combinations.
     *
     * The returned object has:
     *   ->selection         array  fieldId => valueId (sanitised to valid pairs)
     *   ->variantFieldIds   int[]  fields that participate in combinations
     *   ->availableValueIds array  fieldId => [valueId => true]  (selectable)
     *   ->inStockValueIds   array  fieldId => [valueId => true]  (selectable + in stock)
     *   ->matchingCount     int    combinations consistent with the full selection
     *   ->activeCombination object|null the resolved combination (complete selection)
     *   ->complete          bool   every variant field has a chosen value
     *   ->canAddToCart      bool   complete and the active combination is in stock
     *
     * HIDDEN DIMENSIONS (a listing card that draws only some of them): the variant
     * fields missing from $shownFieldIds constrain nothing (the shown values'
     * availability ignores them), and the active combination is the one the card
     * displays among those agreeing with the shown picks: the configured default
     * when it agrees and can be bought, else the first buyable match in canonical
     * order, else the configured default when it agrees, else the first match. The
     * hidden fields then carry that combination's values in ->selection. Null
     * (every dimension shown) is the product page and the cart, resolved exactly
     * as before.
     *
     * @param object[] $fields The item's fields (each ->id and ->values[] with ->id, ->item_field_id).
     * @param object[] $combinations The item's combinations (each ->id, ->ordering, ->value_ids int[],
     *                               ->in_stock bool, ->default_on), in any order.
     * @param array $selection Raw fieldId => valueId chosen so far (from sel[]).
     * @param int[]|null $shownFieldIds The fields the surface draws (null = all of them).
     *
     * @return stdClass The resolution (shape above).
     *
     * @since  2.0.0
     */
    public static function resolve(array $fields, array $combinations, array $selection, ?array $shownFieldIds = null): stdClass
    {
        // value id -> owning field id, across every field's value pool.
        $valueField = [];

        foreach ($fields as $field) {
            foreach ($field->values ?? [] as $value) {
                $valueField[(int) $value->id] = (int) $field->id;
            }
        }

        // Decompose each combination, in canonical order, into a fieldId =>
        // valueId map; collect the fields that participate in combinations (the
        // variant fields) as a map for O(1) lookup. The in_stock flag is cached
        // here so the inner loops never re-read the property.
        $comboMaps = [];
        $variantFields = [];

        foreach (self::canonicalOrder(combinations: $combinations) as $combo) {
            $map = [];

            foreach ($combo->value_ids ?? [] as $vid) {
                $fid = $valueField[(int) $vid] ?? null;

                if ($fid !== null) {
                    $map[$fid] = (int) $vid;
                    $variantFields[$fid] = true;
                }
            }

            $comboMaps[] = [
                'combo' => $combo,
                'map' => $map,
                'inStock' => !empty($combo->in_stock),
            ];
        }

        $variantFieldIds = array_keys($variantFields);

        // Variant fields the surface does not draw (none unless a card hides some).
        $hidden = $shownFieldIds === null
            ? []
            : array_diff_key($variantFields, array_flip(array_map('intval', $shownFieldIds)));

        // Keep only selection pairs whose field is a variant field and whose
        // value genuinely belongs to that field.
        $sel = [];

        foreach ($selection as $fid => $vid) {
            $fid = (int) $fid;
            $vid = (int) $vid;

            if (isset($variantFields[$fid]) && ($valueField[$vid] ?? null) === $fid) {
                $sel[$fid] = $vid;
            }
        }

        // What availability and matching are judged against: the selection, less
        // any hidden dimension (identical to $sel when nothing is hidden).
        $constraint = array_diff_key($sel, $hidden);

        // A combo matches a selection subset when it agrees on every chosen field.
        $matchesSubset = self::matchesSubset(...);

        // Per field: selectable values are those appearing in a combo that
        // matches the selection on all OTHER fields (so any field can always be
        // re-picked to a value compatible with the rest).
        $available = [];
        $inStock = [];

        foreach ($variantFieldIds as $fid) {
            $available[$fid] = [];
            $inStock[$fid] = [];

            $other = $constraint;
            unset($other[$fid]);

            foreach ($comboMaps as $cm) {
                if (!$matchesSubset($cm['map'], $other)) {
                    continue;
                }

                $vid = $cm['map'][$fid] ?? null;

                if ($vid === null) {
                    continue;
                }

                $available[$fid][$vid] = true;

                if ($cm['inStock']) {
                    $inStock[$fid][$vid] = true;
                }
            }
        }

        // Combinations consistent with the FULL current selection. We carry only
        // a count plus the first match (callers never need the whole list); the
        // DB's UNIQUE (item_id, signature) guarantees a single match once the
        // selection is complete, so the first match IS the active combination.
        $matchingCount = 0;
        $firstMatch = null;

        foreach ($comboMaps as $cm) {
            if (!$matchesSubset($cm['map'], $constraint)) {
                continue;
            }

            $matchingCount++;
            $firstMatch ??= $cm['combo'];
        }

        if ($hidden === []) {
            $complete = $variantFieldIds !== [] && count($sel) === count($variantFieldIds);
            $active = $complete ? $firstMatch : null;
        } else {
            // The card still displays ONE combination (price, image, sku, stock):
            // the preferred one among those agreeing with the shown picks.
            $display = self::preferred(comboMaps: $comboMaps, constraint: $constraint);

            // Hidden dimensions carry the displayed combination's values, so the
            // selection describes what the card shows.
            foreach (array_keys($hidden) as $fid) {
                if (isset($display['map'][$fid])) {
                    $sel[$fid] = $display['map'][$fid];
                } else {
                    unset($sel[$fid]);
                }
            }

            $complete = count($sel) === count($variantFieldIds);
            $active = $display['combo'] ?? null;
        }

        $result = new stdClass();
        $result->selection = $sel;
        $result->variantFieldIds = $variantFieldIds;
        $result->availableValueIds = $available;
        $result->inStockValueIds = $inStock;
        $result->matchingCount = $matchingCount;
        $result->activeCombination = $active;
        $result->complete = $complete;
        $result->canAddToCart = $complete && $active !== null && !empty($active->in_stock);

        return $result;
    }

    /**
     * The initial selection to preselect when the request carries none, so a
     * variant product opens on a valid, priced combination the shopper can buy
     * whenever one exists: the configured default (default_on) when it can be
     * bought, else the first buyable combination in canonical order, else the
     * configured default, else the first combination.
     *
     * @param object[] $fields The item's fields (for value -> field mapping).
     * @param object[] $combinations The item's combinations (each ->id, ->ordering, ->value_ids,
     *                               ->in_stock, ->default_on), in any order.
     *
     * @return array fieldId => valueId (empty when there are no combinations).
     *
     * @since  2.0.0
     */
    public static function defaultSelection(array $fields, array $combinations): array
    {
        if (!$combinations) {
            return [];
        }

        $valueField = [];

        foreach ($fields as $field) {
            foreach ($field->values ?? [] as $value) {
                $valueField[(int) $value->id] = (int) $field->id;
            }
        }

        $comboMaps = [];

        foreach (self::canonicalOrder(combinations: $combinations) as $combo) {
            $map = [];

            foreach ($combo->value_ids ?? [] as $vid) {
                $fid = $valueField[(int) $vid] ?? null;

                if ($fid !== null) {
                    $map[$fid] = (int) $vid;
                }
            }

            $comboMaps[] = [
                'combo' => $combo,
                'map' => $map,
                'inStock' => !empty($combo->in_stock),
            ];
        }

        return self::preferred(comboMaps: $comboMaps, constraint: [])['map'] ?? [];
    }

    /**
     * The combination to show on the shopper's behalf among those agreeing with
     * a constraint: the configured default (default_on) when it agrees and can be
     * bought; else the first buyable one that agrees, in canonical order; else
     * the configured default when it agrees; else the first that agrees.
     *
     * Buyable means loaded (the caller passes the published, current
     * combinations only) and flagged in stock by the stock verdict the item-fields
     * seed computed for it.
     *
     * @param array $comboMaps Decomposed combinations in canonical order, each
     *                         ['combo' => object, 'map' => fieldId => valueId, 'inStock' => bool].
     * @param array $constraint fieldId => valueId every candidate must agree with (empty = none).
     *
     * @return array|null The chosen entry of $comboMaps, or null when none agrees.
     *
     * @since  2.0.0
     */
    private static function preferred(array $comboMaps, array $constraint): ?array
    {
        $default = null;

        foreach ($comboMaps as $cm) {
            if (!empty($cm['combo']->default_on)) {
                $default = $cm;
                break;
            }
        }

        $defaultAgrees = $default !== null && self::matchesSubset(map: $default['map'], subset: $constraint);

        if ($defaultAgrees && $default['inStock']) {
            return $default;
        }

        $firstMatch = null;

        foreach ($comboMaps as $cm) {
            if (!self::matchesSubset(map: $cm['map'], subset: $constraint)) {
                continue;
            }

            if ($cm['inStock']) {
                return $cm;
            }

            $firstMatch ??= $cm;
        }

        return $defaultAgrees ? $default : $firstMatch;
    }

    /**
     * The combinations in canonical variant order: ordering ascending, then id,
     * as the combinations grid and the storefront loader list them. The sort is
     * stable and works on a copy.
     *
     * @param object[] $combinations The item's combinations, in any order.
     *
     * @return object[] The same combinations, reindexed in canonical order.
     *
     * @since  2.0.0
     */
    private static function canonicalOrder(array $combinations): array
    {
        $ordered = array_values($combinations);

        usort(
            $ordered,
            static fn (object $a, object $b): int => [(int) ($a->ordering ?? 0), (int) ($a->id ?? 0)]
                <=> [(int) ($b->ordering ?? 0), (int) ($b->id ?? 0)],
        );

        return $ordered;
    }

    /**
     * Whether a combination agrees with a selection subset on every chosen field.
     *
     * @param array $map The combination's fieldId => valueId map.
     * @param array $subset fieldId => valueId to agree with.
     *
     *
     * @since  2.0.0
     */
    private static function matchesSubset(array $map, array $subset): bool
    {
        foreach ($subset as $fid => $vid) {
            if (($map[$fid] ?? null) !== $vid) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every value each variant dimension takes across the item's combinations. A
     * dimension with exactly ONE value is FIXED: there is nothing to choose, so it
     * never counts as missing ({@see self::withFixedDimensions()}).
     *
     * @param object[] $fields The item's fields (for value -> field mapping).
     * @param object[] $combinations The item's combinations.
     *
     * @return array<int, array<int, true>> fieldId => [valueId => true].
     *
     * @since  2.0.0
     */
    public static function dimensionValues(array $fields, array $combinations): array
    {
        $valueField = [];

        foreach ($fields as $field) {
            foreach ($field->values ?? [] as $value) {
                $valueField[(int) $value->id] = (int) $field->id;
            }
        }

        $dimensions = [];

        foreach ($combinations as $combo) {
            foreach ($combo->value_ids ?? [] as $vid) {
                $fid = $valueField[(int) $vid] ?? null;

                if ($fid !== null) {
                    $dimensions[$fid][(int) $vid] = true;
                }
            }
        }

        return $dimensions;
    }

    /**
     * The selection with every FIXED dimension it leaves out (absent or 0) filled
     * with that dimension's only value.
     *
     * @param object[] $fields The item's fields (for value -> field mapping).
     * @param object[] $combinations The item's combinations.
     * @param array $selection fieldId => valueId.
     *
     * @return array fieldId => valueId.
     *
     * @since  2.0.0
     */
    public static function withFixedDimensions(array $fields, array $combinations, array $selection): array
    {
        foreach (self::dimensionValues($fields, $combinations) as $fid => $values) {
            if (count($values) === 1 && empty($selection[$fid])) {
                $selection[$fid] = (int) array_key_first($values);
            }
        }

        return $selection;
    }
}
