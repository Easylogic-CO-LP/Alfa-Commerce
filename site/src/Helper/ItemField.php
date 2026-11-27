<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\FieldPlacement;
use AllowDynamicProperties;

defined('_JEXEC') or die;

/**
 * One entry of a product page's $item->fields, keyed by the field's machine name: the field as the item carries it
 * (every property of the definition, with the item's own values), and what a template override needs to print it
 * anywhere:
 *
 *   $field = $this->item->fields['colour'];
 *   $field->label;        the field's name in the page's language
 *   $field->value;        the pool values the item carries for it (its specification values; the chosen variation
 *                         or option value), as objects with display_name, [] when none
 *   $field->placement;    where the shop placed it (a FieldPlacement value)
 *   echo $field->render(); the field as its placement draws it: its surfaces, in the slots its plugin contributed
 *                         to, with their visibility rules and any template override of the field
 *
 * The definition's own properties stay readable (id, field_name, type, params_decoded, values, informational,
 * show_on_listing, ...), so everything that walks $item->fields reads what it read before.
 *
 * @since  2.3.0
 */
#[AllowDynamicProperties]
final class ItemField
{
    /**
     * The field's name in the page's language.
     *
     * @since  2.3.0
     */
    public string $label = '';

    /**
     * The pool values the item carries for the field: its specification values, or the chosen variation or option
     * value; [] when none.
     *
     * @var list<object>
     * @since  2.3.0
     */
    public array $value = [];

    /**
     * Where the shop placed the field on the product page, a FieldPlacement value.
     *
     * @since  2.3.0
     */
    public string $placement = FieldPlacement::Specifications->value;

    /**
     * @param object $owner The product the field is read on.
     *
     * @since  2.3.0
     */
    private function __construct(private readonly object $owner)
    {
    }

    /**
     * The entry for one of the item's fields.
     *
     * @param object $field The item's per-item field (the definition with the item's offered values).
     * @param object $owner The product.
     *
     * @since  2.3.0
     */
    public static function of(object $field, object $owner): self
    {
        $entry = new self($owner);

        foreach (get_object_vars($field) as $name => $value) {
            if ($name === 'owner') {
                continue;
            }

            $entry->$name = $value;
        }

        $entry->label = (string) ($field->display_name ?? $field->field_name ?? '');
        $entry->placement = FieldPlacement::of($field)->value;
        $entry->value = self::carried($field, $owner);

        return $entry;
    }

    /**
     * The field as its placement draws it: every surface its plugin contributed, in the slots it contributed to,
     * each with its visibility rules and through the per-field layout (a template override of the field applies).
     * Empty when the plugin drew nothing for it.
     *
     * @since  2.3.0
     */
    public function render(): string
    {
        $html = '';
        $fieldId = (int) ($this->id ?? 0);

        foreach ($this->owner->surfaces ?? [] as $slot => $descriptors) {
            $own = array_values(array_filter(
                (array) $descriptors,
                static fn (array $descriptor): bool => (int) ($descriptor['fieldId'] ?? 0) === $fieldId,
            ));

            if ($own === []) {
                continue;
            }

            $html .= Layout::render('item.surfaces', ['surfaces' => $own, 'slot' => (string) $slot, 'item' => $this->owner]);
        }

        return $html;
    }

    /**
     * The pool values the item carries for the field: a specification's values from the item's informational rows,
     * a variation's chosen value from the resolved variant, an option's chosen values from the buyer's selection.
     *
     * @param object $field The field (->values).
     * @param object $owner The product.
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    private static function carried(object $field, object $owner): array
    {
        $fieldId = (int) ($field->id ?? 0);
        $ids = [];

        if (!empty($field->informational)) {
            foreach ((array) ($owner->informational ?? []) as $row) {
                if ((int) ($row->item_field_id ?? 0) === $fieldId) {
                    $ids[(int) $row->id] = true;
                }
            }
        } elseif (isset($owner->variant->selection[$fieldId])) {
            $ids[(int) $owner->variant->selection[$fieldId]] = true;
        } else {
            foreach ((array) ($owner->selectedOptionValueIds ?? []) as $id) {
                $ids[(int) $id] = true;
            }
        }

        $values = [];

        foreach ((array) ($field->values ?? []) as $value) {
            if (\is_object($value) && isset($ids[(int) ($value->id ?? 0)])) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
