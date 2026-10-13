<?php

/**
 * What a cart comes to: the aggregation, and nothing that decides a price.
 *
 * THIS IS NOT A PRICING ENGINE. Every number it returns is a sum of money that
 * something else already decided: a line's price came from PriceCalculator
 * through the cart's own PriceContext, a carrier's price came from a shipment
 * plugin, a charge line came from a commercial plugin, a discount came from
 * CartRulesEngine. This class adds them up in the right order and in the cart's
 * currency. Base-price selection, rounding policy, tax treatment and rule
 * semantics all stay exactly where they are.
 *
 * WHAT IT DOES NOT DO, DELIBERATELY. It dispatches nothing. The carrier's price
 * and the commercial charge lines both arrive through plugin events, which are
 * the computation's to ask for, so this object is handed a callable that
 * produces those numbers and never boots a plugin itself. It also does not take the cart: it is given the lines, the
 * currency and the rules, which is everything a sum needs.
 *
 * THE ONE CYCLE, AND WHY IT TERMINATES. CartRules asks this object for the
 * carrier's GROSS price, because a shipping rule discounts a price it did not
 * set; this object asks CartRules for the shipping FINAL, because the cart's
 * total is what the shopper pays after those rules. The two questions are
 * different, and the first never consults the rules: shipmentCosts() reads the
 * dispatch callable and its own memo, so the chain stops there rather than
 * calling back into rule evaluation.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Site\Service\CartRules\CartRules;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingChargeBasis;
use Closure;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * @since  2.0.0
 */
final class CartTotals
{
    /**
     * The carrier's charge for this request: tax incl, tax excl and the rate.
     *
     * Memoised here because it is money and this object owns the cart's money.
     * The dispatch that produces it is the computation's, through the plugin
     * dispatch; what is cached is the answer, not the act.
     *
     * @since  2.0.0
     */
    private ?array $shipmentCosts = null;

    /**
     * @param Closure $lines (): iterable - the cart's payable line rows;
     *                       a line flagged unavailable is not one.
     * @param Closure $currency (): Currency
     * @param CartRules $rules The cart's rule owner.
     * @param Closure $shipmentDispatch (): ShippingQuote - the carrier's price, or its refusal.
     * @param Closure $contributions (): array - already-computed charge rows.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Closure $lines,
        private Closure $currency,
        private CartRules $rules,
        private Closure $shipmentDispatch,
        private Closure $contributions,
    ) {
    }

    /**
     * Every payable line price this cart holds, tax included.
     *
     * @since  2.0.0
     */
    public function total(): Money
    {
        $currency = ($this->currency)();
        $lineTotals = [];

        foreach (($this->lines)() as $cartItem) {
            if (isset($cartItem->data->price) && \is_object($cartItem->data->price)) {
                $lineTotals[] = $cartItem->data->price->getTotal();
            }
        }

        if (empty($lineTotals)) {
            return Money::zero($currency);
        }

        return Money::sum(...$lineTotals);
    }

    /**
     * The same, tax excluded.
     *
     * @since  2.0.0
     */
    public function totalExcl(): Money
    {
        $currency = ($this->currency)();
        $lineTotals = [];

        foreach (($this->lines)() as $cartItem) {
            if (isset($cartItem->data->price) && \is_object($cartItem->data->price)) {
                $lineTotals[] = $cartItem->data->price->getSubtotal();
            }
        }

        if (empty($lineTotals)) {
            return Money::zero($currency);
        }

        return Money::sum(...$lineTotals);
    }

    /**
     * What catalogue discounts already took off the lines.
     *
     * @since  2.0.0
     */
    public function discountTotal(): Money
    {
        $currency = ($this->currency)();
        $totalDiscount = Money::zero($currency);

        foreach (($this->lines)() as $cartItem) {
            if (
                isset($cartItem->data->price)
                && \is_object($cartItem->data->price)
                && $cartItem->data->price->hasDiscount()
            ) {
                $totalDiscount = $totalDiscount->add($cartItem->data->price->getSavingsTotal());
            }
        }

        return $totalDiscount;
    }

    /**
     * What the lines would have come to before those discounts.
     *
     * @since  2.0.0
     */
    public function totalBeforeDiscount(): Money
    {
        return $this->total()->add($this->discountTotal());
    }

    /**
     * The tax carried by the lines.
     *
     * @since  2.0.0
     */
    public function taxTotal(): Money
    {
        $currency = ($this->currency)();
        $totalTax = Money::zero($currency);

        foreach (($this->lines)() as $cartItem) {
            if (isset($cartItem->data->price) && \is_object($cartItem->data->price)) {
                $totalTax = $totalTax->add($cartItem->data->price->getTaxTotal());
            }
        }

        return $totalTax;
    }

    /**
     * The carrier's own charge, before any shipping rule touches it, as core
     * normalised it: the canonical net and gross and the rate the tax was
     * charged at. Nothing here re-reads what the merchant entered.
     *
     * A REFUSAL IS NOT A PRICE, AND IS NOT MONEY'S PROBLEM. When the carrier
     * says it cannot carry the cart the quote contributes nothing here, exactly
     * as a genuinely free carriage would; the difference between the two is
     * not monetary and is not this class's to express. Whether the method may
     * still be chosen is settled by the computation, reconciliation and
     * placement, all of which read the quote itself.
     *
     * @return array ['tax_incl' => Money, 'tax_excl' => Money, 'tax_rate' => float, 'basis' => ShippingChargeBasis|null,
     *               'chain' => string[]]
     * @since  2.0.0
     */
    public function shipmentCosts(): array
    {
        if ($this->shipmentCosts !== null) {
            return $this->shipmentCosts;
        }

        $currency = ($this->currency)();
        $quote = ($this->shipmentDispatch)();
        $charge = $quote->available ? $quote->charge : null;

        return $this->shipmentCosts = [
            'tax_incl' => $charge?->taxIncl ?? Money::zero($currency),
            'tax_excl' => $charge?->taxExcl ?? Money::zero($currency),
            'tax_rate' => $charge?->taxRate ?? 0.0,
            'basis' => $charge?->basis,
            'chain' => $charge?->chain ?? [],
        ];
    }

    /**
     * @return float The rate the carrier's charge was taxed at, in percent; 0.0 untaxed.
     * @since  2.0.0
     */
    public function shipmentTaxRate(): float
    {
        return $this->shipmentCosts()['tax_rate'];
    }

    /**
     * @return ShippingChargeBasis|null How the carrier's charge was formed; null when nothing is charged.
     * @since  2.0.0
     */
    public function shipmentChargeBasis(): ?ShippingChargeBasis
    {
        return $this->shipmentCosts()['basis'];
    }

    /**
     * @return string[] The rates the carrier's charge was calculated with, in the order they apply, as exact
     *                  decimal texts; empty when nothing is taxed.
     * @since  2.0.0
     */
    public function shipmentTaxChain(): array
    {
        return $this->shipmentCosts()['chain'];
    }

    /**
     * @return Money The carrier's gross price, tax included.
     * @since  2.0.0
     */
    public function shipmentGross(): Money
    {
        return $this->shipmentCosts()['tax_incl'];
    }

    /**
     * @return Money The carrier's gross price, tax excluded.
     * @since  2.0.0
     */
    public function shipmentGrossExcl(): Money
    {
        return $this->shipmentCosts()['tax_excl'];
    }

    /**
     * What the shopper actually pays for shipping, after the rules.
     *
     * @since  2.0.0
     */
    public function shipmentTotal(): Money
    {
        return Money::of($this->rules->result()->shipping->final, ($this->currency)());
    }

    /**
     * The same, tax excluded.
     *
     * @since  2.0.0
     */
    public function shipmentTotalExcl(): Money
    {
        return Money::of($this->rules->result()->shipping->final_excl, ($this->currency)());
    }

    /**
     * The shipping benefit block the rules produced.
     *
     * @since  2.0.0
     */
    public function shippingBenefit(): object
    {
        return $this->rules->result()->shipping;
    }

    /**
     * What the commercial plugins added, tax included.
     *
     * @since  2.0.0
     */
    public function contributionsTotal(): Money
    {
        $total = Money::zero(($this->currency)());

        foreach (($this->contributions)() as $row) {
            $total = $total->add($row->amount_tax_incl_money);
        }

        return $total;
    }

    /**
     * The same, tax excluded.
     *
     * @since  2.0.0
     */
    public function contributionsTotalExcl(): Money
    {
        $currency = ($this->currency)();
        $total = Money::zero($currency);

        foreach (($this->contributions)() as $row) {
            $total = $total->add(Money::of($row->amount_tax_excl, $currency));
        }

        return $total;
    }

    /**
     * Everything, in the order the cart has always added it up.
     *
     * @since  2.0.0
     */
    public function grandTotal(): Money
    {
        return $this->total()
            ->add($this->shipmentTotal())
            ->add($this->contributionsTotal())
            ->subtract($this->rules->total());
    }

    /**
     * The same, tax excluded.
     *
     * @since  2.0.0
     */
    public function grandTotalExcl(): Money
    {
        return $this->totalExcl()
            ->add($this->shipmentTotalExcl())
            ->add($this->contributionsTotalExcl())
            ->subtract($this->rules->totalExcl());
    }

    /**
     * Forget the carrier's price, so the next ask dispatches again.
     *
     * @since  2.0.0
     */
    public function forget(): void
    {
        $this->shipmentCosts = null;
    }
}
