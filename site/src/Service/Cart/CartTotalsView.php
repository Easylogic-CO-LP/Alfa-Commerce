<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Site\Service\Pricing\Money;

defined('_JEXEC') or die;

/**
 * What a cart costs, as of one observation.
 *
 * Money objects, not floats: a template formats them, and flattening them here
 * would decide the currency's presentation on the template's behalf. They are
 * resolved once when the snapshot is assembled and never re-asked, so two reads
 * of the same snapshot cannot disagree.
 *
 * @since  1.10.0
 */
final class CartTotalsView
{
    /**
     * @param Money $goods Line totals, tax included.
     * @param Money $goodsExcl Line totals, tax excluded.
     * @param Money $beforeDiscount What the lines came to before rules.
     * @param Money $tax Tax across the lines.
     * @param Money $discount What the rules took off.
     * @param Money $shipment The carrier's price as the cart pays it.
     * @param Money $shipmentGross The carrier's price before rules.
     * @param object $shippingBenefit The shipping rule's own summary.
     * @param object[] $contributions Folded plugin charges, by sort order.
     * @param Money $grand What the shopper pays.
     * @param Money $grandExcl The same, tax excluded.
     *
     * @since  1.10.0
     */
    public function __construct(
        public readonly Money $goods,
        public readonly Money $goodsExcl,
        public readonly Money $beforeDiscount,
        public readonly Money $tax,
        public readonly Money $discount,
        public readonly Money $shipment,
        public readonly Money $shipmentGross,
        public readonly object $shippingBenefit,
        public readonly array $contributions,
        public readonly Money $grand,
        public readonly Money $grandExcl,
    ) {
    }
}
