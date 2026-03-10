<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * @package    Alfa Commerce
 * @since      1.0.0
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

/**
 * Price Computation Engine
 *
 * Calculates product prices with:
 * - Tiered pricing based on quantity
 * - Multiple discounts (before/after tax)
 * - Multiple taxes (combined/sequential)
 * - Full price breakdown
 *
 * All calculations use Money objects for:
 * - Proper currency handling
 * - Accurate decimal arithmetic
 * - Type safety
 */
class PriceComputationEngine
{
    /**
     * Compute final price with all calculations
     *
     * @param int $productId Product identifier
     * @param int $quantity Quantity ordered
     * @param array $prices Array of price tier objects from PriceDataLoader
     * @param array $discounts Array of discount objects from PriceDataLoader
     * @param array $taxes Array of tax objects from PriceDataLoader
     * @param PriceContext $context Pricing context (currency, customer, location)
     *
     * @return PriceResult Complete price calculation result
     */
    public function compute(
        int $productId,
        int $quantity,
        array $prices,
        array $discounts,
        array $taxes,
        PriceContext $context,
    ): PriceResult {
        $currency = $this->getCurrency($context);
        $breakdown = new PriceBreakdown();

        // 1. Calculate base price
        $unitPrice = $this->selectBasePrice($prices, $quantity);
        $basePrice = Money::of($quantity * $unitPrice, $currency);

        $breakdown->addStep(
            'Base Price (' . $quantity . ' × ' . $currency->format($unitPrice, false) . ')',
            $basePrice,
            'set',
        );

        // 2. Process and apply before-tax discounts
        $appliedDiscounts = $this->processDiscounts($discounts, $currency);
        $beforeTaxDiscounts = array_filter($appliedDiscounts, fn ($d) => $d->timing === 'before_tax');

        $subtotalBeforeTax = $basePrice;
        $beforeTaxDiscountTotal = Money::zero($currency);

        foreach ($beforeTaxDiscounts as $discount) {
            $discountAmount = $this->calculateDiscountAmount($subtotalBeforeTax, $discount);
            $beforeTaxDiscountTotal = $beforeTaxDiscountTotal->add($discountAmount);
            $subtotalBeforeTax = $subtotalBeforeTax->subtract($discountAmount);

            $breakdown->addStep(
                $discount->name . ' (-' . $discount->percent . '%)',
                $discountAmount,
                'subtract',
            );
        }

        // 3. Calculate and apply taxes
        $appliedTaxes = $this->processTaxes($taxes, $currency);
        $taxTotal = Money::zero($currency);
        $subtotalAfterTax = $subtotalBeforeTax;

        foreach ($appliedTaxes as $tax) {
            $taxAmount = $subtotalAfterTax->percentage($tax->rate);
            $taxTotal = $taxTotal->add($taxAmount);
            $subtotalAfterTax = $subtotalAfterTax->add($taxAmount);

            $breakdown->addStep(
                $tax->name . ' (+' . $tax->rate . '%)',
                $taxAmount,
                'add',
            );
        }

        // 4. Apply after-tax discounts
        $afterTaxDiscounts = array_filter($appliedDiscounts, fn ($d) => $d->timing === 'after_tax');
        $afterTaxDiscountTotal = Money::zero($currency);
        $finalPrice = $subtotalAfterTax;

        foreach ($afterTaxDiscounts as $discount) {
            $discountAmount = $this->calculateDiscountAmount($finalPrice, $discount);
            $afterTaxDiscountTotal = $afterTaxDiscountTotal->add($discountAmount);
            $finalPrice = $finalPrice->subtract($discountAmount);

            $breakdown->addStep(
                $discount->name . ' (-' . $discount->percent . '%)',
                $discountAmount,
                'subtract',
            );
        }

        // 5. Build result
        $totalDiscounts = $beforeTaxDiscountTotal->add($afterTaxDiscountTotal);
        $effectiveTaxRate = $this->calculateEffectiveTaxRate($appliedTaxes);

        $discountSummary = new DiscountSummary(
            $totalDiscounts,
            $appliedDiscounts,
        );

        $taxSummary = new TaxSummary(
            $taxTotal,
            $effectiveTaxRate,
            $appliedTaxes,
        );

        return new PriceResult(
            $basePrice,
            $subtotalBeforeTax,
            $taxTotal,
            $finalPrice->nonNegative(), // Ensure non-negative
            $quantity,
            $discountSummary,
            $taxSummary,
            $breakdown,
        );
    }

    /**
     * Select the appropriate base price tier for the quantity.
     *
     * Iterates all price rows for the product and finds those whose
     * quantity_start/quantity_end range includes the requested quantity.
     * Among matches, the narrowest range wins; ties broken by lowest price.
     *
     * @param array $prices Price tier objects loaded by PriceDataLoader
     * @param int $quantity Quantity being priced
     *
     * @return float Unit price, or 0.0 if no row matched
     */
    protected function selectBasePrice(array $prices, int $quantity): float
    {
        if (empty($prices)) {
            return 0.0;
        }

        // Find all matching price tiers
        $matches = [];

        foreach ($prices as $price) {
            $start = $price->quantity_start ?? null;
            $end = $price->quantity_end ?? null;

            if (($start === null || $start <= $quantity) && ($end === null || $end >= $quantity)) {
                $matches[] = $price;
            }
        }

        if (empty($matches)) {
            return 0.0;
        }

        // Sort by narrowest range first, then by lowest price
        usort($matches, function ($a, $b) {
            $aRange = ($a->quantity_end ?? PHP_INT_MAX) - ($a->quantity_start ?? 0);
            $bRange = ($b->quantity_end ?? PHP_INT_MAX) - ($b->quantity_start ?? 0);

            return $aRange !== $bRange
                ? $aRange <=> $bRange
                : $a->value <=> $b->value;
        });

        return (float) $matches[0]->value;
    }

    /**
     * Process raw discount data into AppliedDiscount objects.
     *
     * Handles behavior modes:
     *   0 = "only this discount" — previous discounts cleared, loop stops
     *   1 = combine (additive stacking)
     *   2 = sequential (compound stacking)
     *
     * @param array $discounts Raw discount rows from PriceDataLoader
     * @param Currency $currency Active currency for fixed-amount discounts
     *
     * @return AppliedDiscount[]
     */
    protected function processDiscounts(array $discounts, Currency $currency): array
    {
        $applied = [];

        foreach ($discounts as $discount) {
            if (empty($discount->value)) {
                continue;
            }

            $type = $discount->is_amount == 1 ? 'fixed_amount' : 'percentage';
            $timing = $discount->apply_before_tax == 1 ? 'before_tax' : 'after_tax';

            // Handle behavior: 0=only this, 1=combine, 2=sequential
            if ($discount->behavior == 0) {
                // "Only this discount" — clear previous and use only this one
                $applied = [];
                $applied[] = new AppliedDiscount(
                    $discount->id,
                    $discount->name ?? 'Discount',
                    '',
                    $type === 'fixed_amount'
                        ? Money::of($discount->value, $currency)
                        : null,
                    $type === 'percentage' ? (float) $discount->value : 0.0,
                    $type,
                    $timing,
                );
                break;
            } else {
                // Add to list (combine or sequential handled in application)
                $applied[] = new AppliedDiscount(
                    $discount->id,
                    $discount->name ?? 'Discount',
                    '',
                    $type === 'fixed_amount'
                        ? Money::of($discount->value, $currency)
                        : null,
                    $type === 'percentage' ? (float) $discount->value : 0.0,
                    $type,
                    $timing,
                );
            }
        }

        return $applied;
    }

    /**
     * Calculate the actual money amount for one discount applied to a price.
     *
     * Fixed-amount discounts are capped at the current price (can never go negative).
     *
     * @param Money $price The price to discount
     * @param AppliedDiscount $discount The discount to apply
     *
     * @return Money The discount amount in money terms
     */
    protected function calculateDiscountAmount(Money $price, AppliedDiscount $discount): Money
    {
        if ($discount->type === 'fixed_amount') {
            // Can't discount more than the price
            if ($discount->amount->greaterThan($price)) {
                return $price;
            }

            return $discount->amount;
        } else {
            // Percentage discount
            return $price->percentage($discount->percent);
        }
    }

    /**
     * Process raw tax data into AppliedTax objects.
     *
     * Delegates rate calculation to calculateTaxRates() which handles
     * combined (additive) vs sequential (compound) behavior.
     *
     * @param array $taxes Raw tax rows from PriceDataLoader
     * @param Currency $currency Active currency (currently unused but kept for future use)
     *
     * @return AppliedTax[]
     */
    protected function processTaxes(array $taxes, Currency $currency): array
    {
        $applied = [];
        $taxRates = $this->calculateTaxRates($taxes);

        foreach ($taxes as $i => $tax) {
            if (empty($tax->value)) {
                continue;
            }

            $applied[] = new AppliedTax(
                $tax->id,
                $tax->name ?? 'Tax',
                $taxRates[$i] ?? (float) $tax->value,
                'national',
            );
        }

        return $applied;
    }

    /**
     * Calculate effective tax rates handling combined/sequential behavior.
     *
     * Behavior modes:
     *   0 = only this tax (replaces all others)
     *   1 = combine / additive (rates simply add up)
     *   2 = sequential / compound  (each rate stacks on the previous)
     *
     * @param array $taxes Raw tax rows from PriceDataLoader
     *
     * @return float[] One rate per AppliedTax index
     */
    protected function calculateTaxRates(array $taxes): array
    {
        $rates = [0.0];

        foreach ($taxes as $tax) {
            if (empty($tax->value)) {
                continue;
            }

            if ($tax->behavior == 0) {
                // Only this tax — discard any previously accumulated rates
                $rates = [(float) $tax->value];
                break;
            } elseif ($tax->behavior == 1) {
                // Combine (additive)
                $rates[0] += (float) $tax->value;
            } else {
                // Sequential (compounding)
                $rates[] = (float) $tax->value;
            }
        }

        return $rates;
    }

    /**
     * Calculate the single effective tax rate produced by multiple sequential taxes.
     *
     * For sequential taxes the compound effect is:
     *   10% + 5% sequential = 15.5% effective  (1.10 × 1.05 = 1.155)
     *
     * @param AppliedTax[] $appliedTaxes
     *
     * @return float Effective percentage rate
     */
    protected function calculateEffectiveTaxRate(array $appliedTaxes): float
    {
        if (empty($appliedTaxes)) {
            return 0.0;
        }

        $multiplier = 1.0;

        foreach ($appliedTaxes as $tax) {
            $multiplier *= (1 + $tax->rate / 100);
        }

        return ($multiplier - 1) * 100;
    }

    /**
     * Load the Currency object for the current context.
     *
     * CRITICAL: PriceContext stores the DB primary key from #__alfa_currencies
     * (e.g. id = 47), NOT the ISO numeric code (e.g. 978 for EUR).
     *
     * We MUST use Currency::loadById() here.
     * Currency::loadByNumber() would look up ISO codes and throw RuntimeException
     * for any id value like 47 that does not happen to be a valid ISO code —
     * silently killing every price computation.
     */
    protected function getCurrency(PriceContext $context): Currency
    {
        $currencyId = $context->getCurrencyId();

        if ($currencyId > 0) {
            // Load by DB primary key — the correct identifier stored in PriceContext
            return Currency::loadById($currencyId);
        }

        // currencyId = 0 means "use component default"
        return Currency::getDefault();
    }
}
