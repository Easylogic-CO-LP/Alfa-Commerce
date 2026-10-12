<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Shipping;

use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;

defined('_JEXEC') or die;

/**
 * The shipment a shipping charge is priced for: which method, where it is
 * going, who it is for and in what money.
 *
 * ONE OBJECT, SO A SCOPE CAN BE ADDED WITHOUT A NEW SIGNATURE. A seller's own
 * entry mode or tax context, when Alfa has sellers, is a field here and a
 * branch in the resolvers; nothing that consumes the resulting charge learns
 * that it exists. It is core's own object and is never handed to a carrier.
 *
 * @since  1.14.0
 */
final class ShipmentPricingContext
{
    /**
     * @param int $methodId The shipping method being priced.
     * @param int[] $destination The place it ships to and every place containing it,
     *                           nearest first; empty when the destination is unknown.
     * @param PriceContext $audience The customer's groups, user and reference date.
     * @param Currency $currency The money the charge is stated in.
     *
     * @since  1.14.0
     */
    public function __construct(
        public readonly int $methodId,
        public readonly array $destination,
        public readonly PriceContext $audience,
        public readonly Currency $currency,
    ) {
    }
}
