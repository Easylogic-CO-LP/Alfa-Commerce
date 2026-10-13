<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Shipping;

use Alfa\Component\Alfa\Site\Service\Pricing\AppliedTax;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\TaxChain;
use InvalidArgumentException;

defined('_JEXEC') or die;

/**
 * A shipping charge in canonical form: net, tax and gross in one currency,
 * with the rate the tax was charged at, the ordered rates it was calculated
 * with, and how the figures were formed.
 *
 * THE ONLY SHAPE SHIPPING MONEY TAKES AFTER A CARRIER ANSWERS. The cart, the
 * order, discounts, documents, credits and refunds read these amounts; none
 * of them re-reads what the merchant entered, the entry mode or the tax rules.
 *
 * The net plus the tax is the gross to the minor unit, neither is ever below
 * zero, and a charge that carries tax names the rate it was taxed at: a rate
 * cannot be recovered from rounded amounts (8.06 net of 10.00 reads as
 * 24.069%), so it travels with them and is frozen with them.
 *
 * @since  2.0.0
 */
final class ShippingCharge
{
    /**
     * @param Money $taxExcl The net charge.
     * @param Money $tax The tax on it.
     * @param Money $taxIncl The gross charge.
     * @param float $taxRate The rate the tax was charged at, in percent, at three places: the chain's
     *                       combined rate, for display, reporting and reconciliation.
     * @param ShippingChargeBasis $basis How the figures were formed: under an entry mode, or as a carrier stated them.
     * @param string[] $chain The rates the charge was calculated with, in the order they apply, as exact
     *                        decimal texts; empty for an untaxed charge.
     *
     * @throws InvalidArgumentException When the three amounts are not one canonical charge.
     *
     * @since  2.0.0
     */
    private function __construct(
        public readonly Money $taxExcl,
        public readonly Money $tax,
        public readonly Money $taxIncl,
        public readonly float $taxRate,
        public readonly ShippingChargeBasis $basis,
        public readonly array $chain,
    ) {
        $currency = $taxIncl->getCurrency();

        if (!$taxExcl->getCurrency()->equals($currency) || !$tax->getCurrency()->equals($currency)) {
            throw new InvalidArgumentException('A shipping charge is stated in one currency.');
        }

        if ($taxExcl->isNegative() || $tax->isNegative()) {
            throw new InvalidArgumentException('A shipping charge and its tax are never below zero.');
        }

        if (!$taxExcl->add($tax)->equals($taxIncl)) {
            throw new InvalidArgumentException(sprintf(
                'A shipping charge\'s net %s and tax %s must make its gross %s exactly.',
                $taxExcl->getAmount(),
                $tax->getAmount(),
                $taxIncl->getAmount(),
            ));
        }

        if (!is_finite($taxRate) || $taxRate < 0.0 || $taxRate > 9999999.999) {
            throw new InvalidArgumentException('A shipping tax rate is a percentage of zero or more.');
        }

        if ($tax->isPositive() && $taxRate <= 0.0) {
            throw new InvalidArgumentException('A shipping charge that carries tax names the rate it was taxed at.');
        }
    }

    /**
     * A price as the merchant entered it, read through the entry mode and taxed
     * with the chain that applies to the shipment.
     *
     * Tax included: the entered figure is the gross; the net is the gross
     * divided by the chain's exact factor and rounded once, and the tax is what
     * remains, so the customer pays exactly the entered price. Tax excluded:
     * the entered figure is the net; each rate charges once, rounded, on the
     * exact running amount, and the gross is the net and those charges.
     *
     * @param Money $entered The price the carrier quoted, as configured.
     * @param ShippingPriceEntryMode $mode What that price means.
     * @param AppliedTax[] $chain The rates that apply, in order; empty = untaxed.
     * @param float $rate The chain's effective rate, in percent.
     *
     * @throws InvalidArgumentException When the price is negative or the chain cannot carry it.
     *
     * @since  2.0.0
     */
    public static function fromEntered(Money $entered, ShippingPriceEntryMode $mode, array $chain, float $rate): self
    {
        if ($entered->isNegative()) {
            throw new InvalidArgumentException('A carrier quoted a negative price: ' . $entered->getAmount() . '.');
        }

        $basis = ShippingChargeBasis::ofMode($mode);

        if ($chain === []) {
            return new self($entered, Money::zero($entered->getCurrency()), $entered, 0.0, $basis, []);
        }

        $rates = array_map(static fn (AppliedTax $tax): string => ExactAmount::ofNumber($tax->rate)->text(), array_values($chain));

        if ($mode === ShippingPriceEntryMode::TaxIncluded) {
            $net = TaxChain::netOf(gross: $entered, rates: array_map(static fn (AppliedTax $tax): float => $tax->rate, $chain));

            return new self($net, $entered->subtract($net), $entered, round($rate, 3), $basis, $rates);
        }

        [$gross, $tax] = TaxChain::chargeForward(net: $entered, appliedTaxes: $chain);

        return new self($entered, $tax, $gross, round($rate, 3), $basis, $rates);
    }

    /**
     * Amounts a carrier stated itself.
     *
     * Authoritative: no entry mode and no tax resolution is applied. They are
     * refused unless they are one canonical charge. The one rate the carrier
     * states is the whole of what Alfa knows of how it taxed them, so it is
     * the charge's chain.
     *
     * @param Money $taxExcl The net charge.
     * @param Money $tax The tax on it.
     * @param Money $taxIncl The gross charge.
     * @param float $taxRate The rate the tax was charged at, in percent.
     *
     * @throws InvalidArgumentException When the amounts are not one canonical charge.
     *
     * @since  2.0.0
     */
    public static function explicit(Money $taxExcl, Money $tax, Money $taxIncl, float $taxRate): self
    {
        $rate = round($taxRate, 3);

        return new self($taxExcl, $tax, $taxIncl, $rate, ShippingChargeBasis::CarrierStated, $rate > 0.0 ? [ExactAmount::ofNumber($rate)->text()] : []);
    }
}
