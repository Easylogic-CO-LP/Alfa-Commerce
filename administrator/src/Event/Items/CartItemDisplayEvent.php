<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Items;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\CartSurface;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern\ContributedSurfaces;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\SurfaceBuilder;
use Alfa\Component\Alfa\Administrator\Plugin\SelectionContributorsInterface;
use InvalidArgumentException;
use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

/**
 * Cart line render event for FEATURE plugins (onCartItemDisplay), after the line's field surfaces are collected. One
 * event for the whole line (2.2.0): delivered in turn to each `alfa` plugin the CUSTOMER's view levels admit,
 * in their order, through the plugin's own targeted declaration, so the line reads the same whoever operates the cart;
 * a plugin that throws is logged and the next runs. Contributions land on the same cart-line slot map
 * ($cartItem->surfaces) the template already renders, never HTML, a layout name + display data, each naming its plugin:
 *
 *   public function onCartItemDisplay(CartItemDisplayEvent $event): void
 *   {
 *       $event->addSurface('alfa.terms', CartSurface::Main)->setLayout('cart_terms')->setData([...]);
 *   }
 *
 * A contribution naming another plugin than the one answering is dropped and logged.
 *
 * @since  2.0.0
 */
class CartItemDisplayEvent extends AbstractImmutableEvent implements SelectionContributorsInterface
{
    use ContributedSurfaces;

    /**
     * Contribute a surface on one of the cart line's slots.
     *
     * @param string $contributor The contributing plugin, group.element.
     * @param CartSurface $surface The slot.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.2.0
     */
    public function addSurface(string $contributor, CartSurface $surface): SurfaceBuilder
    {
        return $this->contributeSurface($contributor, $surface->value);
    }

    /**
     * The cart line being rendered.
     *
     * @return object The cart line (id_item, quantity, name, ...).
     *
     * @since  2.0.0
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
     * @since  2.0.0
     */
    public function getItem(): object
    {
        return $this->arguments['item'];
    }
}
