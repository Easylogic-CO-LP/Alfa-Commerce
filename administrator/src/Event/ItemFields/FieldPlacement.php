<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

defined('_JEXEC') or die;

/**
 * Where the product page shows an item field, as the back office stores it on the field definition
 * (#__alfa_item_fields.placement): each case names one product page slot ({@see self::surface()}), so a field
 * plugin asks {@see ItemViewEvent::placement()} for the builder of the slot the shop chose and never maps the
 * stored value itself. A field the buyer answers (a variation or an option) is drawn in the buy form whatever the
 * placement says, except Manual; the placement moves a field that is shown only.
 *
 * @since  2.3.0
 */
enum FieldPlacement: string
{
    /** In the buy box, as a specification: the Specifications slot inside the buy form. The default. */
    case Specifications = 'specifications';

    /** A row of the Specifications section's table, under the two columns. */
    case SpecificationsSection = 'specifications-section';

    /** A page section of its own, titled with the field's name. */
    case Section = 'section';

    /** Under the product title. */
    case Title = 'title';

    /** Under the price. */
    case Price = 'price';

    /** Beside the add to cart button. */
    case NearAddToCart = 'near-add-to-cart';

    /** Not shown automatically: a template override prints the field where it wants. */
    case Manual = 'manual';

    /**
     * The placement a field definition stores; the default when it stores none or an unknown value.
     *
     * @param object $field The field definition (->placement).
     *
     * @since  2.3.0
     */
    public static function of(object $field): self
    {
        return self::tryFrom((string) ($field->placement ?? '')) ?? self::Specifications;
    }

    /**
     * The product page slot this placement names.
     *
     * @since  2.3.0
     */
    public function surface(): ItemSurface
    {
        return match ($this) {
            self::Specifications => ItemSurface::Specifications,
            self::SpecificationsSection => ItemSurface::SpecificationsSection,
            self::Section => ItemSurface::Sections,
            self::Title => ItemSurface::Title,
            self::Price => ItemSurface::Price,
            self::NearAddToCart => ItemSurface::NearAddToCart,
            self::Manual => ItemSurface::Manual,
        };
    }
}
