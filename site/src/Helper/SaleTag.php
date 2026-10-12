<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemListSurface;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemSurface;

/**
 * Contributes the "sale tag" badge (a flagged discount's translatable NAME) to
 * the item's surface slot map, so it renders through the existing surface pipeline
 * with no template change. Core-owned, no plugin/event: the same direct slot append
 * the cart line presenter uses for cart-line field surfaces ({@see CartLinePresenter}).
 *
 * Each applied discount flagged for the context (card / product page) contributes one
 * badge descriptor to its chosen slot; the slot list stacks multiple badges in order.
 * The slot string is validated against the CONTEXT's own surface enum; a value that
 * is not a real slot for this context falls back to the image corner.
 *
 * @since  1.5.0
 */
final class SaleTag
{
    /**
     * Append a badge descriptor for every applied discount flagged for this context.
     *
     * @param object $item The rendered item (carries ->price and ->surfaces).
     * @param bool $isListing True for a catalogue card (ItemListSurface slots),
     *                        false for the product page (ItemSurface slots).
     *
     *
     * @since  1.5.0
     */
    public static function contribute(object $item, bool $isListing): void
    {
        $price = $item->price ?? null;

        if (!\is_object($price) || !method_exists($price, 'getDiscounts')) {
            return;
        }

        $applied = $price->getDiscounts()->getApplied();

        if (!$applied) {
            return;
        }

        $item->surfaces ??= [];

        foreach ($applied as $discount) {
            $show = $isListing ? ($discount->tagListShow ?? false) : ($discount->tagItemShow ?? false);
            $name = (string) ($discount->name ?? '');

            // Nothing to badge with (a flag but no name, or an unlabelled synthetic
            // discount, e.g. a per-unit sale override) is silently skipped.
            if (!$show || $name === '') {
                continue;
            }

            $slot = $isListing
                ? (ItemListSurface::tryFrom((string) ($discount->tagListSlot ?? 'image')) ?? ItemListSurface::Image)
                : (ItemSurface::tryFrom((string) ($discount->tagItemSlot ?? 'image')) ?? ItemSurface::Image);

            $item->surfaces[$slot->value][] = [
                'layout' => 'item.sale_tag',
                'layoutData' => [
                    'name' => $name,
                    'context' => $isListing ? 'list' : 'item',
                    'bg' => (string) ($discount->tagBg ?? ''),
                    'color' => (string) ($discount->tagColor ?? ''),
                    'class' => (string) ($discount->tagClass ?? ''),
                    'discount' => $discount,
                ],
                'layoutPluginType' => '',
                'layoutPluginName' => '',
                'showon' => '',
            ];
        }
    }
}
