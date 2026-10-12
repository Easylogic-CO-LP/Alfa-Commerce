<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Items;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\CartSurface;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern\HasSurfaces;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\SurfaceBuilder;
use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

/**
 * Cart line render event for FEATURE plugins: BOOT-AND-CALL, one fresh event
 * per alfa plugin, after the line's field surfaces are collected. The
 * feature-plugin twin of the per-field onCartItemView: contributions land on
 * the same cart-line slot map ($cartItem->surfaces) the template already
 * renders, never HTML, a layout name + display data:
 *
 *   public function onCartItemDisplay(CartItemDisplayEvent $event): void
 *   {
 *       $event->main()->setLayout('cart_terms')->setData([...]);
 *   }
 *
 * The caller defaults the layout owner to the invoked plugin
 * (Surfaces::appendTo), exactly like the item-fields dispatches.
 *
 * @since  1.3.0
 */
class CartItemDisplayEvent extends AbstractImmutableEvent
{
    use HasSurfaces;

    /**
     * A row in the line's main details area (under the name/config summary).
     *
     *
     * @since  1.3.0
     */
    public function main(): SurfaceBuilder
    {
        return $this->surfaceFor(CartSurface::Main->value);
    }

    /**
     * The cart line being rendered.
     *
     * @return object The cart line (id_item, quantity, name, ...).
     *
     * @since  1.3.0
     */
    public function getLine(): object
    {
        return $this->arguments['line'];
    }

    /**
     * The configured item behind the line.
     *
     * @return object The loaded item.
     *
     * @since  1.3.0
     */
    public function getItem(): object
    {
        return $this->arguments['item'];
    }
}
