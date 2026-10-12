<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

use BackedEnum;

defined('_JEXEC') or die;

/**
 * Template-side one-liner for rendering a named page slot:
 *
 *   echo Surface::render($this->item, ItemSurface::Title);
 *   echo Surface::render($this->item, ItemSurface::PaymentMethods);
 *   echo Surface::render($cartItem,   CartSurface::Main);
 *
 * The owner ($item / cart line) carries an aggregated ->surfaces[slot] = [list of
 * descriptors] harvested from EVERY contributor (item fields, payment methods,
 * shipment methods). This renders them in insertion order, source-agnostic; the
 * template names only WHAT (the owner) and WHERE (typed slot), never WHO contributed.
 *
 * A template override may still pick item fields out of a slot, by field id (int)
 * or machine name (string), e.g. the colour swatches apart from the rest:
 *
 *   echo Surface::render($item, ItemListSurface::BuyForm, only: ['colour']);
 *   echo Surface::render($item, ItemListSurface::BuyForm, except: ['colour']);
 *
 * A contribution that is not an item field (payment, shipment) has no field, so
 * `only` drops it and `except` keeps it.
 *
 * @since  1.3.0
 */
final class Surface
{
    /**
     * Render every contribution to one slot, in order.
     *
     * @param object $owner The slot owner (item / cart line); carries ->surfaces.
     * @param BackedEnum $slot A *Surface enum case (its ->value is the slot key).
     * @param array $only Keep only these fields (int id / string field_name); [] = all.
     * @param array $except Drop these fields (int id / string field_name).
     *
     * @return string The rendered HTML ('' when nothing targets the slot).
     *
     * @since  1.3.0
     */
    public static function render(object $owner, BackedEnum $slot, array $only = [], array $except = []): string
    {
        $list = self::select($owner, $slot, $only, $except);

        if (!$list) {
            return '';
        }

        return Layout::render('item.surfaces', [
            'surfaces' => $list,
            'slot' => $slot->value,
            'item' => $owner,
        ]);
    }

    /**
     * Whether a slot has any contribution, for a template to decide whether to
     * render its section chrome (title, wrapper) at all. Takes the same filters
     * as render(), so the chrome follows what render() would draw.
     *
     * @param object $owner The slot owner.
     * @param BackedEnum $slot The slot.
     * @param array $only Keep only these fields (int id / string field_name); [] = all.
     * @param array $except Drop these fields (int id / string field_name).
     *
     *
     * @since  1.3.0
     */
    public static function has(object $owner, BackedEnum $slot, array $only = [], array $except = []): bool
    {
        return self::select($owner, $slot, $only, $except) !== [];
    }

    /**
     * One slot's descriptors, filtered by field.
     *
     * @param object $owner The slot owner.
     * @param BackedEnum $slot The slot.
     * @param array $only Keep only these fields; [] = all.
     * @param array $except Drop these fields.
     *
     * @return array The descriptors, in insertion order.
     *
     * @since  1.10.0
     */
    private static function select(object $owner, BackedEnum $slot, array $only, array $except): array
    {
        $list = $owner->surfaces[$slot->value] ?? [];

        if ($only !== []) {
            $list = array_filter($list, static fn (array $descriptor): bool => self::matches($descriptor, $only));
        }

        if ($except !== []) {
            $list = array_filter($list, static fn (array $descriptor): bool => !self::matches($descriptor, $except));
        }

        return array_values($list);
    }

    /**
     * Whether a descriptor's field is one of the keys (int = field id, string =
     * field_name). A descriptor with no field matches nothing.
     *
     * @param array $descriptor One surface descriptor.
     * @param array $keys Field ids and/or machine names.
     *
     *
     * @since  1.10.0
     */
    private static function matches(array $descriptor, array $keys): bool
    {
        if (($descriptor['fieldId'] ?? null) === null) {
            return false;
        }

        foreach ($keys as $key) {
            if (\is_int($key) ? $key === (int) $descriptor['fieldId'] : (string) $key === (string) ($descriptor['fieldName'] ?? '')) {
                return true;
            }
        }

        return false;
    }
}
