<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Site\Service\Shipping\ShippingCharge;

defined('_JEXEC') or die;

/**
 * What a carrier answered when it was asked to price a cart.
 *
 * TWO ANSWERS THAT ARE NOT THE SAME, AND USED TO BE. "This costs nothing" and
 * "I cannot carry this" both arrived as the float 0.00, because the quote hook
 * had no way to say the second. A carrier whose zone bands matched nothing
 * returned 0, the cart was charged 0, the method stayed selectable, and the
 * order was placed and frozen at 0.00 carriage: the shop shipped an oversized
 * parcel for free and nothing anywhere said so.
 *
 * So an unpriced quote is now a REFUSAL, not a zero:
 *
 *   ShippingQuote::of($charge)                     a price: net, tax and gross
 *   ShippingQuote::free()                          genuinely free, and offerable
 *   ShippingQuote::unavailable('PLG_..._TOO_BIG')  cannot be carried at all
 *   ShippingQuote::unanswered()                    the carrier said NOTHING
 *
 * AND SILENCE IS THE FOURTH, WHICH USED TO BE THE FIRST. A hook that ran and
 * set nothing left the seeded cost of 0 standing, so a carrier that had not
 * answered at all was read as a carrier quoting free, and offered, selected,
 * placed and frozen at 0.00 carriage. That is not a hypothetical: one carrier
 * shipped for months with its cost hook commented out, and every one of its orders
 * was carried at no charge no matter what the merchant had configured.
 *
 * Silence now FAILS CLOSED and stays distinguishable from the other three, by
 * `$answered` and not merely by a string: a merchant reading "no answer" is
 * told to fix a plugin, where "cannot carry" would have sent them to look at
 * their zone table for a fault that is not there.
 *
 * The distinction is carried through the totals (an unavailable quote adds no
 * money), through method reconciliation (the selection is dropped and the
 * shopper is told), and through placement (the order is refused before the
 * gateway is reached).
 *
 * NOT AN ELIGIBILITY ENGINE. This is the answer of the ONE carrier the cart has
 * already chosen; it is never asked of the others, so it cannot decide which
 * methods are offered. That is settled before a quote is asked, and the two
 * must never call each other: a list that quoted to filter, filtered by a
 * quote that read the list, would not terminate. Deciding the OFFERED list is
 * the eligibility hook's, and the eligibility hook prices nothing.
 *
 * @since  2.0.0
 */
final class ShippingQuote
{
    /**
     * @param bool $available Whether the carrier will carry this cart at all.
     * @param ShippingCharge|null $charge The carriage in canonical form; null when there is
     *                                    nothing to charge (free, refused or silent).
     * @param string $reasonKey Language key explaining a refusal; '' when available.
     * @param bool $answered Whether the carrier gave an answer of any kind. False only
     *                       for {@see self::unanswered()}: a plugin that ran and said
     *                       nothing, which is a defect in the plugin and not a verdict
     *                       on the cart.
     *
     * @since  2.0.0
     */
    private function __construct(
        public readonly bool $available,
        public readonly ?ShippingCharge $charge,
        public readonly string $reasonKey,
        public readonly bool $answered = true,
    ) {
    }

    /**
     * A priced quote: the carriage as core normalised it, net, tax and gross.
     *
     * @param ShippingCharge $charge The canonical charge.
     *
     *
     * @since  2.0.0
     */
    public static function of(ShippingCharge $charge): self
    {
        return new self(true, $charge, '');
    }

    /**
     * Carriage at no charge: a real offer, not a refusal.
     *
     * Declared separately from a priced zero so that reading the call site says
     * which of the two zeros is meant.
     *
     *
     * @since  2.0.0
     */
    public static function free(): self
    {
        return new self(true, null, '');
    }

    /**
     * The carrier will not carry this cart.
     *
     * @param string $reasonKey Language key shown to the shopper. Never English:
     *                          this reaches a storefront notice and an order
     *                          refusal, both of which a Greek shopper reads.
     *
     *
     * @since  2.0.0
     */
    public static function unavailable(string $reasonKey = ''): self
    {
        return new self(false, null, $reasonKey);
    }

    /**
     * The carrier was asked and said nothing at all.
     *
     * NOT A REFUSAL, AND CERTAINLY NOT FREE. The plugin's hook ran to
     * completion without naming a price or declining, so the shop has no
     * quote, and the one thing it must not do is invent the cheapest possible
     * one. It is unavailable so that nothing is sold at a carriage figure
     * nobody produced, and it carries `answered = false` so a merchant is sent
     * to the plugin rather than to a zone table that is not at fault.
     *
     *
     * @since  2.0.0
     */
    public static function unanswered(): self
    {
        return new self(false, null, 'COM_ALFA_SHIPMENT_NO_ANSWER', false);
    }
}
