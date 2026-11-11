<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Items;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern\ContributedSurfaces;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemSurface;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\SurfaceBuilder;
use InvalidArgumentException;
use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

/**
 * Product page render event for FEATURE plugins (onItemDisplay): raised once on the shared dispatcher after the item's
 * field surfaces (2.2.0, mode A, a display phase, isolated), so every enabled and loaded listener contributes to the
 * same event. The feature-plugin twin of the per-field onItemView: contributions land on the same page slots
 * (ItemSurface) and the same $item->surfaces map, never HTML, a layout name + display data, each naming its plugin:
 *
 *   public function onItemDisplay(ItemDisplayEvent $event): void
 *   {
 *       $event->addSurface('alfa.badges', ItemSurface::Title)->setLayout('item_badge')->setData([...]);
 *   }
 *
 * The contributor defaults the surface's layout owner to its own plugin (attribution, never an authorisation).
 *
 * @since  2.0.0
 */
class ItemDisplayEvent extends AbstractImmutableEvent
{
    use ContributedSurfaces;

    /**
     * Contribute a surface on one of the product page's slots.
     *
     * @param string $contributor The contributing plugin, group.element.
     * @param ItemSurface $surface The slot.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.2.0
     */
    public function addSurface(string $contributor, ItemSurface $surface): SurfaceBuilder
    {
        return $this->contributeSurface($contributor, $surface->value);
    }

    /**
     * The item being rendered.
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
