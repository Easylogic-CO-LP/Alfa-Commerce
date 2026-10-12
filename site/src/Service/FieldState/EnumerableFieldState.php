<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\FieldState;

defined('_JEXEC') or die;

/**
 * The default state producer for an ENUMERABLE field (choice/color/list: anything
 * whose value is a value-pool id). Resolving "what did the buyer pick" is the same
 * regardless of widget, so it lives here once, not per plugin: a variant field
 * reads the resolved variant's selection, an option field reads the chosen value
 * among its own option values. Complex fields (e.g. a configurator) bypass this and
 * return their own facets via {@see ProvidesState}.
 *
 * The slice is `['value' => <id|ids|null>]`: a stable id, an array of ids for a
 * multi-select, or null when nothing is selected (the bag treats null as "not set").
 *
 * @since  1.3.0
 */
final class EnumerableFieldState
{
    /**
     * The input-state slice for an enumerable field against the current item.
     *
     * @param object $field A per-item field object (carries ->id, ->values).
     * @param object $item The item being rendered (carries ->variant, ->selectedOptionValueIds).
     *
     * @return array{value: int|int[]|null}
     *
     * @since  1.3.0
     */
    public static function of(object $field, object $item): array
    {
        $fieldId = (int) ($field->id ?? 0);
        $variant = $item->variant ?? null;

        // Variant field: the selection comes from the resolved combination.
        if ($variant !== null && \in_array($fieldId, $variant->variantFieldIds ?? [], true)) {
            $selected = (int) ($variant->selection[$fieldId] ?? 0);

            return ['value' => $selected !== 0 ? $selected : null];
        }

        // Option field: the chosen value(s) among this field's own option values.
        $chosen = array_flip(array_map('intval', $item->selectedOptionValueIds ?? []));
        $selected = [];

        foreach ($field->values ?? [] as $value) {
            if (isset($chosen[(int) $value->id])) {
                $selected[] = (int) $value->id;
            }
        }

        if ($selected === []) {
            return ['value' => null];
        }

        // A single pick is a scalar id; multiple picks stay an array (multi-select).
        return ['value' => \count($selected) === 1 ? $selected[0] : $selected];
    }
}
