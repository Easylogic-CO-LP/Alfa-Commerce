<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

use InvalidArgumentException;

defined('_JEXEC') or die;

/**
 * THE ONE IMPLEMENTATION OF "CHARGE THESE RATES ON THIS NET".
 *
 * A shop's applied taxes are an ORDERED CHAIN, and each rate charges on what
 * the one before it left: the net plus the earlier rates' charges, EXACTLY.
 * Each rate's charge is rounded once, at the currency's scale, as the money
 * that rate charged; the next rate is charged on the exact running amount,
 * never on a running amount rounded at each step, so 0.15 under 10% then 5%
 * is 0.17325 and charges 0.02 + 0.00, a gross of 0.17.
 *
 * A BLENDED RATE IS NOT THAT. Compounding (1 + rate/100) in floating point
 * across the chain and charging once produces a percentage no rate in the
 * shop ever had (10% then 5% blends to 15.50000000000002486900) and states
 * no rate's own money. So the blended rate is kept for ONE purpose only:
 * reporting the effective percentage a line ended up paying, and never for
 * computing money. Every split of a gross divides back through
 * {@see PriceComputationEngine::deriveFromGross()}, which charges the chain
 * forward here and shares its absorption rule.
 *
 * EVERY CHARGE AND EVERY SPLIT IS EXACT. A rate is the exact ratio it states
 * (24% is 31/25), a charge is the exact running amount times that rate, and a
 * net is the gross divided by the chain's exact factor, each rounded once,
 * half away from zero. No float product is rounded, so a tie is a tie on
 * every PHP version: 17.5% on 6.60 is 1.155 and charges 1.16.
 *
 * @since  1.12.0
 */
final class TaxChain
{
    /**
     * Charge an ordered chain of rates on a net amount.
     *
     * Each rate charges on the exact running amount (the net plus everything
     * the earlier rates charged, exactly), and its charge is rounded once, to
     * the currency's minor unit, as the money that rate charged. The gross is
     * the net and those charges.
     *
     * @param Money $net The amount the first rate charges on.
     * @param array $appliedTaxes The rates, in the order they apply.
     *
     * @return array{0: Money, 1: Money, 2: array} [gross, taxTotal, taxes carrying their money]
     *
     * @since  1.12.0
     */
    public static function chargeForward(Money $net, array $appliedTaxes): array
    {
        $currency = $net->getCurrency();
        $taxTotal = Money::zero($currency);
        $running = null;
        $last = array_key_last($appliedTaxes);
        $charged = [];

        foreach ($appliedTaxes as $position => $tax) {
            // 1 + rate / 100 as the exact ratio n / d (d divides 10^10): the
            // charge is the running amount times (n - d) / d, exactly.
            [$numerator, $denominator] = PriceComputationEngine::rateFactor(rate: $tax->rate);
            $scale = intdiv(10000000000, $denominator);

            if ($running === null) {
                // The first rate charges the net itself, rounded once.
                $taxAmount = $net->timesRatio(numerator: $numerator - $denominator, denominator: $denominator);

                // The exact running amount is carried only for a rate that follows.
                if ($position !== $last) {
                    $running = ExactAmount::ofMoney($net)->timesCount(count: $scale * $numerator, places: 10);
                }
            } else {
                $charge = $running->timesCount(count: $scale * ($numerator - $denominator), places: 10);
                $taxAmount = $charge->toMoney($currency);
                $running = $running->plus($charge);
            }

            $taxTotal = $taxTotal->add($taxAmount);
            $charged[] = $tax->withAmount($taxAmount);
        }

        return [$net->add($taxTotal), $taxTotal, $charged];
    }

    /**
     * THE ONE DIVISION OF A GROSS BACK THROUGH A CHAIN: the net a gross holds.
     *
     * The chain's factor is the exact product of its rates' ratios (24% is
     * 31/25; 10% then 5% is 231/200), and the net is the gross times the
     * factor's denominator over its numerator, formed on integers and rounded
     * ONCE, half away from zero: a gross of 0.14 at 12% is 0.125 net, a tie,
     * 0.13. The quotient is never rounded factor by factor.
     *
     * A chain whose factor is not positive (a rate at or below -100%) cannot
     * be divided back through: the gross is returned as it is.
     *
     * Every split of a gross divides here: a pinned gross, the re-derivation
     * after an after-tax discount and an explicit price (through
     * {@see PriceComputationEngine::deriveFromGross()}), and an order line's
     * gross at its stored rate.
     *
     * @param Money $gross The gross.
     * @param float[] $rates The chain's rates, as percentages, in the order they apply.
     *
     *
     * @throws InvalidArgumentException When a rate is not a number.
     *
     * @since  1.14.0
     */
    public static function netOf(Money $gross, array $rates): Money
    {
        $factor = PriceComputationEngine::chainFactor(rates: $rates);

        return $factor === null ? $gross : $gross->timesRatio(numerator: $factor[1], denominator: $factor[0]);
    }
}
