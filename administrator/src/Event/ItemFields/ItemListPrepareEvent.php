<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Item List Prepare Event (onItemListPrepare)
 *
 * The listing-card twin of onItemView. Dispatched by the SITE ItemsModel per
 * (item x field the card draws: Show on listings, closed over ShowOn),
 * subject = the item, method = the field definition, to that field's plugin
 * (bootPlugin($field->type)), exactly as the single-item page dispatches
 * onItemView. A field the card does not draw gets no dispatch at all. A card
 * that drew a variant dimension without a selector is resolved and drawn a
 * second time, so a handler acts through the event only. The plugin draws on
 * the card through its surface builders (buyForm(), title(), image(),
 * specifications()) and decorates the card's CART region with
 * setCartLayout()/setCartLayoutData(); it operates on the subject, so no item
 * argument:
 *
 *   public function onItemListPrepare($event): void
 *   {
 *       if (!$event instanceof ItemListPrepareEvent) { return; }
 *       $item  = $event->getItem();
 *       $field = $event->getField();   // type, definition params, value pool
 *       $event->setCartLayout('cart_action');
 *   }
 *
 * Because the model dispatches per field, it KNOWS the owner: a plugin sets only
 * the layout (+ data) and the model fills the plugin group/name from the field's
 * type, exactly as Item/HtmlView fills a field's layout owner. A plugin may set
 * the owner explicitly to render another plugin's layout. Cart state lives in
 * plain properties (not event arguments) to avoid the Joomla onSet/set
 * magic-argument trap; the model harvests it onto $item->cart, which the
 * template renders through Layout::render().
 *
 * @since  2.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern\HasCartLayout;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern\HasSurfaces;
use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

class ItemListPrepareEvent extends AbstractImmutableEvent
{
    use HasCartLayout;
    use HasSurfaces;

    /**
     * The selector slot inside the card's buy form. Fluent, no enum import:
     * buyForm()->setLayout('item-radio')->setData([...]).
     *
     *
     * @since  2.0.0
     */
    public function buyForm(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemListSurface::BuyForm->value);
    }

    /**
     * A surface beside the card title, e.g. a badge.
     *
     *
     * @since  2.0.0
     */
    public function title(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemListSurface::Title->value);
    }

    /**
     * A surface on the card image: overlay/badge.
     *
     *
     * @since  2.0.0
     */
    public function image(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemListSurface::Image->value);
    }

    /**
     * The card's read-only specifications block: where informational fields
     * render. A passive surface: no selector, no input.
     *
     *
     * @since  2.0.0
     */
    public function specifications(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemListSurface::Specifications->value);
    }

    /**
     * The item being decorated (the event subject).
     *
     *
     * @since  2.0.0
     */
    public function getItem(): object
    {
        return $this->arguments['subject'];
    }

    /**
     * The field definition driving this dispatch (type, decoded params, value
     * pool with localized names); the field plays the role a payment method
     * plays in the payments group.
     *
     *
     * @since  2.0.0
     */
    public function getField(): object
    {
        return $this->arguments['method'];
    }
}
