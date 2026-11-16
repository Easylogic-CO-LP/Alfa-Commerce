<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

use Alfa\Component\Alfa\Site\Service\Place\PlaceTree;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;

defined('_JEXEC') or die;

/**
 * Where the cart page's prices are for (2.2.1).
 *
 * The cart prices its goods at its delivery place; the shop's other pages price the visitor at their own place. When
 * the two differ, the cart page names the delivery place (COM_ALFA_CART_PRICED_AT). A delivery place that may not
 * price a buyer prices as the unknown place, and is named only when that is not the visitor's own pricing either.
 *
 * @since  2.2.1
 */
final class CartPricedAt
{
    /**
     * The delivery place's name, when the cart is priced there and the visitor's own pages are not; else null.
     *
     * @param int|null $deliveryPlaceId The cart's delivery place (CartSnapshot::$placeId).
     *
     * @since  2.2.1
     */
    public static function place(?int $deliveryPlaceId): ?string
    {
        if ($deliveryPlaceId === null || $deliveryPlaceId <= 0) {
            return null;
        }

        $own = PriceContext::fromSession();

        return $own->withLocation($deliveryPlaceId)->getLocationId() === $own->getLocationId()
            ? null
            : PlaceTree::name(placeId: $deliveryPlaceId);
    }
}
