<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

use Alfa\Component\Alfa\Administrator\Event\General\LayoutEvent;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern\HasCartLayout;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern\HasSelections;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern\HasSurfaces;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Item-page render hook for alfa-item-fields plugins (same LayoutEvent
 * contract as payments/shipments: the plugin sets its layout fragment, the
 * item template renders it via PluginLayoutHelper).
 *
 * Carries the shared HasCartLayout contract too, so a plugin can replace the
 * add-to-cart MAIN slot on the product page with the SAME setCartLayout() it
 * uses on the listing card (ItemListPrepareEvent): one vocabulary, two surfaces.
 *
 * Arguments: subject = the item, method = the FIELD DEFINITION row (the
 * field plays the role a payment method plays in the payments group).
 *
 * @since  1.3.0
 */
class ItemViewEvent extends LayoutEvent
{
    use HasCartLayout;
    use HasSelections;
    use HasSurfaces;

    /**
     * The selector slot inside the buy form (the field's main control). Fluent,
     * no enum import: buyForm()->setLayout('item-radio')->setData([...]).
     *
     *
     * @since  1.3.0
     */
    public function buyForm(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemSurface::BuyForm->value);
    }

    /**
     * A surface beside the product title, e.g. a badge.
     *
     *
     * @since  1.3.0
     */
    public function title(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemSurface::Title->value);
    }

    /**
     * A surface on the product image / gallery: overlay/badge.
     *
     *
     * @since  1.3.0
     */
    public function image(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemSurface::Image->value);
    }

    /**
     * A surface in the short description block.
     *
     *
     * @since  1.3.0
     */
    public function shortDescription(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemSurface::ShortDescription->value);
    }

    /**
     * A surface in the full description block.
     *
     *
     * @since  1.3.0
     */
    public function fullDescription(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemSurface::FullDescription->value);
    }

    /**
     * A surface next to the add-to-cart button: extra control/note.
     *
     *
     * @since  1.3.0
     */
    public function nearAddToCart(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemSurface::NearAddToCart->value);
    }

    /**
     * The read-only specifications block: where informational fields render
     * (after the interactive selectors). A passive surface: no selector, no input.
     *
     *
     * @since  1.3.0
     */
    public function specifications(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemSurface::Specifications->value);
    }

    /**
     * The item being viewed.
     *
     * @return mixed
     *
     * @since  1.3.0
     */
    public function getItem()
    {
        return $this->getSubject();
    }

    /**
     * The field definition this event renders (display name, pool values,
     * decoded params, as assembled by getFieldsPopupData()).
     *
     *
     * @since  1.3.0
     */
    public function getField(): object
    {
        return $this->arguments['method'];
    }

    /**
     * The field's EFFECTIVE per-item config (e.g. a configurator's "areas"),
     * batch-loaded onto the field by ItemFieldsHelper::getDataForItems, so a
     * plugin reads it here with NO query. Empty Registry when nothing is stored.
     *
     *
     * @since  1.3.0
     */
    public function getConfig(): Registry
    {
        return $this->getField()->config ?? new Registry();
    }

    /**
     * This field's current stored value, for pre-fill on a RE-EDIT of an
     * existing cart line. A payload plugin (e.g. the configurator) seeds its
     * inputs from it; enumerable fields ignore it: they already pre-select
     * from sel[]/opt[]. Provenance-agnostic: the value is identical whether it
     * came from a cart line, a fresh request, or a future source (wishlist,
     * reorder), so a plugin never learns where it came from. NULL when nothing
     * is stored for this field.
     *
     * @return mixed
     *
     * @since  1.3.0
     */
    public function getCurrentValue()
    {
        return $this->arguments['currentValue'] ?? null;
    }
}
