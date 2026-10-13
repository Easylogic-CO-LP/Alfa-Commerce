<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

/**
 * WHAT THE ORDINARY PRICING WOULD HAVE CHARGED, had no operator intervened.
 *
 * The pricing contract defines the canonical amount as "what the
 * normal Alfa pricing engine produced for this item/variant/context WITHOUT an
 * explicit staff override", and requires that the comparison figure be the
 * PRE-override one. This object is that result, carried beside the
 * authoritative one so a later reader never has to reconstruct it by comparing
 * saved money against today's catalogue, the reconstruction the custom-price contract forbids
 * in terms.
 *
 * IT CARRIES NO baseTotal OF ITS OWN, deliberately. The base is not replaced by
 * an instruction (the override stage runs strictly after the base is settled
 * and writes no variable the base stage reads), so there is exactly ONE
 * canonical amount and exactly one accessor pair for it,
 * {@see PriceResult::getBaseTotal()} and {@see PriceResult::getBaseTotalWithTax()}.
 * A getReferenceBaseTotal() synonym would be the second independent route to
 * one financial fact, which the pricing contract forbids.
 *
 * With NO instruction the reference IS the authoritative result: the engine
 * hands the same five values to both, so "no instruction means nothing moved"
 * is enforced by construction rather than by a nullable fallback that could
 * drift.
 *
 * @since  2.0.0
 */
final class PriceReference
{
    /**
     * @param Money $subtotal Whole-line NET the ordinary pricing reached.
     * @param Money $taxTotal The tax it charged.
     * @param Money $total Whole-line GROSS it would have charged.
     * @param DiscountSummary $discounts The discounts it applied: superseded by an
     *                                   override, readable, never re-applied.
     * @param PriceBreakdown $breakdown Its narrative, unchanged.
     */
    public function __construct(
        private readonly Money $subtotal,
        private readonly Money $taxTotal,
        private readonly Money $total,
        private readonly DiscountSummary $discounts,
        private readonly PriceBreakdown $breakdown,
    ) {
    }

    /**
     * Whole-line NET the ordinary pricing reached.
     *
     *
     * @since  2.0.0
     */
    public function getSubtotal(): Money
    {
        return $this->subtotal;
    }

    /**
     * The tax the ordinary pricing charged.
     *
     *
     * @since  2.0.0
     */
    public function getTaxTotal(): Money
    {
        return $this->taxTotal;
    }

    /**
     * Whole-line GROSS the ordinary pricing would have charged.
     *
     *
     * @since  2.0.0
     */
    public function getTotal(): Money
    {
        return $this->total;
    }

    /**
     * The discounts the ordinary pricing applied.
     *
     * On an overridden line these are SUPERSEDED, not applied: the
     * authoritative summary is empty in both halves, so nothing downstream
     * reports a promotion the customer did not receive. They stay readable here
     * because the provenance list must be able to explain how the line
     * reached its price without reconstructing it from final totals.
     *
     *
     * @since  2.0.0
     */
    public function getDiscounts(): DiscountSummary
    {
        return $this->discounts;
    }

    /**
     * The ordinary pricing's narrative.
     *
     *
     * @since  2.0.0
     */
    public function getBreakdown(): PriceBreakdown
    {
        return $this->breakdown;
    }
}
