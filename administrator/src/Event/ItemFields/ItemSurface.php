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
 * The render slots a field plugin may target on the PRODUCT PAGE (onItemView):
 * each case is a REAL template position (not a business concept / field type),
 * so a plugin's surface lands exactly where the theme exposes it. Typed per
 * event: a wrong slot is an IDE/compile error, never a silent miss.
 *
 * @since  2.0.0
 */
enum ItemSurface: string
{
    /** Beside the product title (item-name), e.g. a badge. */
    case Title = 'title';

    /** The product image / gallery area (item-images-wrapper): overlay/badge. */
    case Image = 'image';

    /** The short description block (item-short-description). */
    case ShortDescription = 'short-description';

    /** The full description block (item-full-description). */
    case FullDescription = 'full-description';

    /** The selector slot inside the buy form (item-fields-surfaces). The default. */
    case BuyForm = 'buy-form';

    /** The payment methods area on the product page (a payment method's default slot). */
    case PaymentMethods = 'payment-methods';

    /** The shipment methods area on the product page (a shipment method's default slot). */
    case ShipmentMethods = 'shipment-methods';

    /** Next to the add-to-cart button (by cart.wrapper): extra control/note. */
    case NearAddToCart = 'near-add-to-cart';

    /** Read-only specifications block: informational fields render here, after
        the interactive (variation/option) selectors. */
    case Specifications = 'specifications';
}
