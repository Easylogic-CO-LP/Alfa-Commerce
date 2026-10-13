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
 * Assembles the one {@see StateBag} a page render hands to every consumer. It is
 * the PHP side of the fixed build order: first every field writes its INPUT state
 * (keyed by the field's machine name, so a rule addresses `<name>.value`), then,
 * later, the Variant Resolver reads that input and writes DERIVED `variant.*` back
 * into the same bag. Builder, not consumer: it never evaluates a rule.
 *
 * A field contributes its slice either via its own {@see ProvidesState} producer
 * (complex fields: configurator) or, by default, via {@see FieldState} (enumerable
 * fields). The machine name is the address prefix and matches `data-showon-name`,
 * so the serialised bag and the DOM agree for the JS twin.
 *
 * @since  2.0.0
 */
final class StateBagBuilder
{
    /**
     * Build the input-state bag from a rendered item's fields.
     *
     * @param object $item The item (carries ->fields plus selection context).
     *
     *
     * @since  2.0.0
     */
    public static function fromItem(object $item): StateBag
    {
        $bag = new StateBag();

        foreach ($item->fields ?? [] as $field) {
            $name = (string) ($field->field_name ?? '');

            if ($name === '') {
                continue;
            }

            $bag->put(
                field: $name,
                slice: $field instanceof ProvidesState ? $field->getState() : EnumerableFieldState::of(field: $field, item: $item),
            );
        }

        // Derived state (variant.*) is written here next, once the Variant Resolver
        // is wired; it reads the input just put above and writes back into $bag.

        return $bag;
    }
}
