<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart\Plugin;

defined('_JEXEC') or die;

/**
 * What a placement event tells a plugin about the cart being converted.
 *
 * The cart's identity, its lines, the customer it is for and the carrier it
 * is placed with, frozen at dispatch. A plugin may validate against them,
 * refuse the placement, or perform its own documented side effects; it cannot
 * reach the cart, change what is on it, or hand back a different one.
 * Placement's state is placement's, for the whole of the placement.
 *
 * THE CARRIER IS HERE BECAUSE THE REQUEST IS NOT. A shipment plugin gating a
 * placement used to read `shipment_method` off the request, which is where
 * the storefront's POST puts it and where an operator's or an importer's
 * placement puts nothing, so those went ungated. The command that placed
 * the order knows the method it was placed with, and the context carries it.
 *
 * @since  2.0.0
 */
final class PlacementEventContext
{
    /**
     * @param int $cartId The cart, or 0 when there is none to convert.
     * @param PlacementLine[] $lines Its lines, in cart order.
     * @param int $customerId The customer the order is for; 0 for a guest.
     * @param int $shipmentMethodId The shipment method the placement chose; 0 for none.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly int $cartId,
        public readonly array $lines,
        public readonly int $customerId = 0,
        public readonly int $shipmentMethodId = 0,
    ) {
    }
}
