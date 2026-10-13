<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Items;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern\HasSurfaces;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemSurface;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\SurfaceBuilder;
use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

/**
 * Product page render event for FEATURE plugins: BOOT-AND-CALL, one fresh
 * event per alfa plugin, after the item's field surfaces are dispatched. The
 * feature-plugin twin of the per-field onItemView: the same typed slot
 * accessors onto the same page slots (ItemSurface), harvested onto the same
 * $item->surfaces map, so a contribution renders wherever the theme exposes
 * the slot, never HTML, a layout name + display data:
 *
 *   public function onItemDisplay(ItemDisplayEvent $event): void
 *   {
 *       $event->title()->setLayout('item_badge')->setData([...]);
 *   }
 *
 * The caller defaults the layout owner to the invoked plugin
 * (Surfaces::appendTo), exactly like the item-fields dispatches.
 *
 * @since  2.0.0
 */
class ItemDisplayEvent extends AbstractImmutableEvent
{
    use HasSurfaces;

    /**
     * A surface beside the product title, e.g. a badge.
     *
     *
     * @since  2.0.0
     */
    public function title(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemSurface::Title->value);
    }

    /**
     * A surface on the product image / gallery: overlay/badge.
     *
     *
     * @since  2.0.0
     */
    public function image(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemSurface::Image->value);
    }

    /**
     * A surface in the short description block.
     *
     *
     * @since  2.0.0
     */
    public function shortDescription(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemSurface::ShortDescription->value);
    }

    /**
     * A surface in the full description block.
     *
     *
     * @since  2.0.0
     */
    public function fullDescription(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemSurface::FullDescription->value);
    }

    /**
     * A surface inside the buy form.
     *
     *
     * @since  2.0.0
     */
    public function buyForm(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemSurface::BuyForm->value);
    }

    /**
     * A surface next to the add-to-cart button: extra note/control.
     *
     *
     * @since  2.0.0
     */
    public function nearAddToCart(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemSurface::NearAddToCart->value);
    }

    /**
     * A surface in the read-only specifications block.
     *
     *
     * @since  2.0.0
     */
    public function specifications(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemSurface::Specifications->value);
    }

    /**
     * The item being displayed.
     *
     * @return object The loaded item.
     *
     * @since  2.0.0
     */
    public function getItem(): object
    {
        return $this->arguments['item'];
    }
}
