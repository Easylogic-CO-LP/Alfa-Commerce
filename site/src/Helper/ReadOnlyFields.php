<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

defined('_JEXEC') or die;

/**
 * The product fields as plain read-only lists, in place of the buy form, in catalogue-only mode.
 *
 * The models call it as core's last word, after every pass that can change the buy area (the field events, the page's
 * feature plugins, a listing's whole-page pass): every surface in the buy form's slots is dropped, whatever it declares
 * and whoever contributed it (a field's selector, a configurator's own inputs, a feature plugin's control:
 * nothing selectable or clickable), and one core descriptor per listed field that offers values takes their place,
 * drawn by the layout `item.fields_readonly` (overridable in a template's html/layouts/com_alfa/item/). Nothing is
 * chosen, so the descriptors carry no showon rule and no plugin owner. A field with no values to list draws nothing. It
 * may be called again on its own result: the lists are dropped and drawn again. The display slots (the badges, the
 * descriptions, the specifications) are left as the plugins drew them.
 *
 * @since  2.3.0
 */
final class ReadOnlyFields
{
    /**
     * The layout that draws one field's values as text.
     *
     * @since  2.3.0
     */
    public const LAYOUT = 'item.fields_readonly';

    /**
     * Core's cart main slot in catalogue-only mode: no layout, which the cart wrapper renders as nothing. The child
     * slot (a wish list and the like) stays as the plugins set it.
     *
     * @since  2.3.0
     */
    public const NO_CART = [
        'layout' => '',
        'layoutData' => [],
        'layoutPluginType' => '',
        'layoutPluginName' => '',
    ];

    /**
     * The slot map with the buy form's field surfaces replaced by the read-only lists.
     *
     * @param array<string, list<array<string, mixed>>> $slots The surfaces by slot, as the field events left them.
     * @param object[] $fields The item's per-item fields (->id, ->display_name, ->values), in display order.
     * @param string[] $buySlots The buy form's slots; the lists go into the first.
     * @param int[]|null $listed The fields to list; null lists the fields that drew a surface in the buy form's slots.
     *
     * @return array<string, list<array<string, mixed>>> The slots: the buy slots hold only the lists, in the first one.
     *
     * @since  2.3.0
     */
    public static function replace(array $slots, array $fields, array $buySlots, ?array $listed = null): array
    {
        $drew = [];

        foreach ($buySlots as $slot) {
            if (!isset($slots[$slot])) {
                continue;
            }

            foreach ($slots[$slot] as $descriptor) {
                if (($descriptor['fieldId'] ?? null) !== null) {
                    $drew[(int) $descriptor['fieldId']] = true;
                }
            }

            unset($slots[$slot]);
        }

        $wanted = $listed === null ? $drew : array_flip(array_map('intval', $listed));
        $lists = [];

        foreach ($fields as $field) {
            $values = array_values(array_filter((array) ($field->values ?? []), 'is_object'));

            if (!isset($wanted[(int) $field->id]) || $values === []) {
                continue;
            }

            $lists[] = [
                'layout' => self::LAYOUT,
                'layoutData' => ['field' => $field, 'values' => $values],
                'layoutPluginType' => '',
                'layoutPluginName' => '',
                'showon' => '',
                'fieldId' => (int) $field->id,
                'fieldName' => (string) ($field->field_name ?? ''),
            ];
        }

        if ($lists !== [] && $buySlots !== []) {
            $slots[$buySlots[0]] = $lists;
        }

        return $slots;
    }
}
