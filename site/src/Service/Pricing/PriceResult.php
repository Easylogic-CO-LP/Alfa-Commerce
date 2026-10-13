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
 * Price Result - Professional e-commerce price structure
 *
 * NAMING CONVENTIONS:
 * - "Price" = per unit amount
 * - "Total" = aggregate amount (price x quantity)
 * - Order: base -> subtotal -> tax -> total
 *
 * All monetary values are Money objects for:
 * - Type safety
 * - Proper formatting
 * - Currency-aware operations
 * - Immutability
 *
 * @since  1.0.0
 */
class PriceResult
{
    // Total amounts (price x quantity)
    private Money $baseTotal;          // Original price x quantity (before discounts)
    private Money $subtotal;           // After discounts, before tax
    private Money $taxTotal;           // Total tax amount
    private Money $total;              // Final total customer pays

    private int $quantity;

    private DiscountSummary $discounts;
    private TaxSummary $taxes;
    private PriceBreakdown $breakdown;

    /** What the ordinary pricing would have charged, had no operator intervened. */
    private PriceReference $reference;

    /** The operator's instruction, as stated; null on an ordinary line. */
    private ?ExplicitPriceInstruction $explicitPrice;

    /** The instruction as APPLIED, whole-line, at the currency's scale. */
    private ?Money $explicitTotal;
    private ?Money $explicitTotalWithTax;

    /**
     * The line's gross before any discount, the struck "with tax" figure: the
     * exact gross of the base rounded once, as the engine states it (a pinned
     * gross with no discount before tax is that gross). Never a tax charged on
     * the rounded base, which could miss it by a minor unit and report a
     * saving no discount gave.
     *
     * @since  2.0.0
     */
    private Money $baseTotalWithTax;

    /**
     * The factors (each a count over 10^10) a net-stated authoritative unit is
     * taken through to state what the customer pays a piece; empty when the
     * unit is stated with tax.
     *
     * @var int[]
     *
     * @since  2.0.0
     */
    private array $payableFactors;

    /**
     * What the customer pays a piece before discounts, once it was asked for.
     *
     * @since  2.0.0
     */
    private ?string $payableUnit = null;

    /**
     * What the customer pays a piece before discounts, exactly, once it was asked for.
     *
     * @since  2.0.0
     */
    private ?string $payableUnitExact = null;

    /**
     * The unit this line is priced from, exactly as it is stated: a decimal
     * of at most eight places, on the axis it is stated on. An ordinary price
     * row and a sale on the net state the net; a sale typed as a final price,
     * a variant's gross target and an explicit custom rate state the gross.
     *
     * @since  2.0.0
     */
    private string $authoritativeUnit;

    /**
     * Where the stated unit comes from. Runtime provenance for presentation only.
     *
     * @since  2.0.0
     */
    private UnitOrigin $statedOrigin;

    /**
     * Which side of the tax the stated unit is on. Runtime provenance for presentation only.
     *
     * @since  2.0.0
     */
    private UnitAxis $statedAxis;

    /**
     * Whether a discount, or a sale below its list, took anything from the line, exactly.
     *
     * @since  2.0.0
     */
    private bool $reduced;

    /**
     * The price row's own value the line is listed at, before a sale states it.
     *
     * @since  2.0.0
     */
    private string $listUnit;

    /**
     * The line's exact net and gross after every discount, as decimal text; the net is null when the
     * chain cannot be divided. Runtime provenance for presentation only.
     *
     * @since  2.0.0
     */
    private ?string $shownNetLine;
    private string $shownGrossLine;

    /**
     * The exact discounted units, once they were asked for.
     *
     * @var array<string, string|null>
     *
     * @since  2.0.0
     */
    private array $discountedUnits = [];

    /**
     * THE NEW FACTS ARE REQUIRED PARAMETERS, NOT NULLABLE EXTRAS.
     *
     * This constructor is called in exactly ONE place in the whole tree (the
     * computation engine), so it can afford to demand them, and demanding them
     * is what makes "with no instruction the reference IS the authoritative
     * result" a constraint rather than a comment. A nullable fallback would let
     * the two drift the first time a caller forgot one.
     */
    public function __construct(
        Money $baseTotal,
        Money $subtotal,
        Money $taxTotal,
        Money $total,
        int $quantity,
        DiscountSummary $discounts,
        TaxSummary $taxes,
        PriceBreakdown $breakdown,
        PriceReference $reference,
        string $authoritativeUnit,
        Money $baseTotalWithTax,
        array $payableFactors,
        UnitOrigin $statedOrigin,
        UnitAxis $statedAxis,
        bool $reduced,
        string $listUnit,
        ?string $shownNetLine,
        string $shownGrossLine,
        ?ExplicitPriceInstruction $explicitPrice = null,
        ?Money $explicitTotal = null,
        ?Money $explicitTotalWithTax = null,
    ) {
        $this->baseTotal = $baseTotal;
        $this->subtotal = $subtotal;
        $this->taxTotal = $taxTotal;
        $this->total = $total;
        $this->quantity = $quantity;
        $this->discounts = $discounts;
        $this->taxes = $taxes;
        $this->breakdown = $breakdown;
        $this->reference = $reference;
        $this->authoritativeUnit = $authoritativeUnit;
        $this->explicitPrice = $explicitPrice;
        $this->explicitTotal = $explicitTotal;
        $this->explicitTotalWithTax = $explicitTotalWithTax;
        $this->baseTotalWithTax = $baseTotalWithTax;
        $this->payableFactors = $payableFactors;
        $this->statedOrigin = $statedOrigin;
        $this->statedAxis = $statedAxis;
        $this->reduced = $reduced;
        $this->listUnit = $listUnit;
        $this->shownNetLine = $shownNetLine;
        $this->shownGrossLine = $shownGrossLine;
    }

    // The explicit operator price, and what it superseded

    /**
     * What the ordinary pricing would have charged, had no operator intervened.
     *
     * With no instruction this carries the very same figures the authoritative
     * accessors do: not copies of them, the same values the engine computed
     * once.
     *
     *
     * @since  2.0.0
     */
    public function getReference(): PriceReference
    {
        return $this->reference;
    }

    /**
     * Was an explicit operator price supplied for this line?
     *
     * TRUE EVEN WHEN THE AMOUNT EQUALS THE CANONICAL AMOUNT, and true when it
     * is zero. The override state is a stored fact about a negotiation; it is
     * NEVER inferred by comparing money against today's catalogue, which is the
     * reconstruction the custom-price contract forbids and which the order-line writer still does
     * today.
     *
     *
     * @since  2.0.0
     */
    public function hasExplicitPrice(): bool
    {
        return $this->explicitPrice !== null;
    }

    /**
     * The operator's instruction, exactly as stated.
     *
     *
     * @since  2.0.0
     */
    public function getExplicitPrice(): ?ExplicitPriceInstruction
    {
        return $this->explicitPrice;
    }

    /**
     * The instruction as APPLIED: whole-line NET, at the currency's scale.
     *
     * Not a second arithmetic route to getSubtotal(). With no adjustment stage
     * inside the engine the two are equal BY CONSTRUCTION: this returns what
     * the override stage already quantised, never a re-derivation from the
     * typed amount. It exists because provenance must be able to distinguish
     * "the price override" from "what the line finally came to" when the order
     * freezes both.
     *
     *
     * @since  2.0.0
     */
    public function getExplicitPriceTotal(): ?Money
    {
        return $this->explicitTotal;
    }

    /**
     * The instruction as applied: whole-line GROSS, at the currency's scale.
     *
     *
     * @since  2.0.0
     */
    public function getExplicitPriceTotalWithTax(): ?Money
    {
        return $this->explicitTotalWithTax;
    }

    /**
     * WHAT THE OVERRIDE SAVED AGAINST WHAT THE ORDINARY PRICING WOULD HAVE
     * CHARGED: whole-line, NET, and never negative.
     *
     * Measured against the REFERENCE, not against the undiscounted base: the
     * canonical amount is what stage A produced, catalogue sale and automatic
     * discounts included. A line the operator priced ABOVE that saves nothing,
     * and reports 0 rather than a negative: "do not persist -20 as a discount;
     * do not produce a negative discount percentage."
     *
     * THIS IS NOT getSavingsTotal(), and the two must not be collapsed.
     * getSavingsTotal() answers "what did the discount stage remove?"; this
     * answers "what did the negotiation save?" A negotiated price is not a
     * promotional discount, and the ruling requires all three concepts (
     * promotional adjustment, price override, aggregate whole-line saving) to
     * stay distinguishable.
     *
     *
     * @since  2.0.0
     */
    public function getLineSaving(): Money
    {
        return $this->reference->getSubtotal()->subtract($this->subtotal)->nonNegative();
    }

    /**
     * The same saving, whole-line and GROSS.
     *
     *
     * @since  2.0.0
     */
    public function getLineSavingWithTax(): Money
    {
        return $this->reference->getTotal()->subtract($this->total)->nonNegative();
    }

    /**
     * The same saving as a percentage: FLOORED AT ZERO by construction.
     *
     * Both figures are gross, so nothing is compared across a tax boundary, and
     * the numerator cannot be negative because getLineSavingWithTax() cannot be.
     *
     * Deliberately NOT the same method as getSavingsPercent(), which is
     * unfloored and can return a negative today on a multi-rate chain with no
     * discount at all (the blended-reconstruction defect). Flooring THAT one
     * would change an existing result for an existing caller; the two collapse
     * into one when the successor item re-authors the gross decomposition.
     *
     * @return float Percentage with 2 decimal places.
     *
     * @since  2.0.0
     */
    public function getLineSavingPercent(): float
    {
        // On minor units, by the one rule: no float quotient decides a tie.
        return Money::percentOf(
            part: $this->getLineSavingWithTax()->getMinorUnits(),
            whole: $this->reference->getTotal()->getMinorUnits(),
        );
    }

    // TOTAL AMOUNTS (price x quantity)

    /**
     * Get base total (original price x quantity, before any discounts)
     * @since  1.0.0
     */
    public function getBaseTotal(): Money
    {
        return $this->baseTotal;
    }

    /**
     * Get subtotal (after discounts, before tax)
     * @since  1.0.0
     */
    public function getSubtotal(): Money
    {
        return $this->subtotal;
    }

    /**
     * Get total tax amount
     * @since  1.0.0
     */
    public function getTaxTotal(): Money
    {
        return $this->taxTotal;
    }

    /**
     * Get final total (what customer pays)
     * @since  1.0.0
     */
    public function getTotal(): Money
    {
        return $this->total;
    }

    /**
     * Get the gross line total: base total plus tax, BEFORE any discounts.
     *
     * The tax-inclusive twin of getBaseTotal(), so a layout that shows a
     * before-discount "with tax" figure does no Money arithmetic of its own.
     * @since  2.0.0
     */
    public function getBaseTotalWithTax(): Money
    {
        // A fact the engine states: the base's exact gross, rounded once. It is
        // never charged here on the rounded base, rate by rate, which could miss
        // it by a minor unit and report a saving no discount gave.
        return $this->baseTotalWithTax;
    }

    // UNIT PRICES (per single item)

    /**
     * Get base price per unit (before any discounts)
     *
     * This is the original unit price before any calculations.
     * @since  1.0.0
     */
    public function getBasePrice(): Money
    {
        return $this->divideByQuantity($this->baseTotal);
    }

    /**
     * Get subtotal price per unit (after discounts, before tax)
     * @since  1.0.0
     */
    public function getSubtotalPrice(): Money
    {
        return $this->divideByQuantity($this->subtotal);
    }

    /**
     * Get tax per unit
     * @since  1.0.0
     */
    public function getTaxPrice(): Money
    {
        return $this->divideByQuantity($this->taxTotal);
    }

    /**
     * Get price per unit (final price per item)
     *
     * Most commonly used method for displaying unit price.
     * @since  1.0.0
     */
    public function getPrice(): Money
    {
        return $this->divideByQuantity($this->total);
    }

    /**
     * Get the gross unit price: base price plus tax, BEFORE any discounts.
     *
     * The tax-inclusive twin of getBasePrice(); the per-unit face of the
     * struck gross, divided like every other per-unit face. No tax is charged
     * on a per-unit figure already rounded for display.
     * @since  2.0.0
     */
    public function getBasePriceWithTax(): Money
    {
        return $this->divideByQuantity($this->baseTotalWithTax);
    }

    // Savings & discounts

    /**
     * Get total savings amount from all discounts
     * @since  1.0.0
     */
    public function getSavingsTotal(): Money
    {
        return $this->discounts->getTotal();
    }

    /**
     * Get savings per unit
     * @since  1.0.0
     */
    public function getSavingsPrice(): Money
    {
        return $this->divideByQuantity($this->getSavingsTotal());
    }

    /**
     * Check if product has active discount
     * @since  1.0.0
     */
    public function hasDiscount(): bool
    {
        return $this->discounts->hasDiscounts();
    }

    /**
     * Get effective savings percentage
     *
     * Delegates calculation to DiscountSummary which owns the logic
     * for handling discount timing and aggregation.
     *
     * @return float Percentage with 2 decimal places
     * @since  1.0.0
     */
    public function getSavingsPercent(): float
    {
        // THE TRUTH RULE:
        // "Any displayed or persisted 'actual savings percentage' =
        //  (base - final) / base x 100, DERIVED FROM ACTUAL MONEY, never by
        //  summing nominal rule percentages."
        //
        // This used to delegate to a method of DiscountSummary that returned
        // each percentage rule's own nominal figure and SUMMED them. Two
        // stacked rules therefore reported the arithmetic sum rather than what
        // the customer actually saved: measured, 50% before tax and 5% after
        // tax reported 55.00% against a real 52.49%.
        //
        // One method feeds every surface the rule names (the storefront price
        // layout, the FROZEN order line's discount_percent, the admin order
        // line, the price index and the catalogue search payload), so deriving
        // it from money here is what makes the rule true everywhere at once.
        //
        // Both figures are GROSS, so nothing is compared across a tax boundary,
        // and the result can never exceed 100 the way a nominal sum can.
        //
        // On minor units, by the one rule: no float quotient decides a tie.
        $base = $this->getBaseTotalWithTax();

        return Money::percentOf(
            part: $base->subtract($this->total)->getMinorUnits(),
            whole: $base->getMinorUnits(),
        );
    }

    /**
     * Get discount breakdown by timing, per the truth rule.
     *
     * Every figure is derived from the money below, never from the rules'
     * nominal percentages; `total` is the same number {@see self::getSavingsPercent()}
     * reports, not the two stage figures added together.
     *
     * @return array ['before_tax' => float, 'after_tax' => float, 'total' => float]
     * @since  1.0.0
     */
    public function getSavingsPercentBreakdown(): array
    {
        return $this->discounts->getPercentBreakdown(
            $this->baseTotal,
            $this->subtotal,
            $this->taxTotal,
            $this->total,
            $this->getBaseTotalWithTax(),
        );
    }

    // Metadata

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    /**
     * The unit this line is priced from, exactly as it is stated.
     *
     * The line's own money before any discount is this unit times the
     * quantity, rounded once at the currency's scale. The per-unit accessors
     * above divide settled money back and so lose what is finer than that
     * scale: a unit of 0.004 reads 0.00 through them at quantity 1 and 0.01
     * at quantity 2. This is the unit itself, which is how a caller knows
     * that a line of 0.00 has a price.
     *
     * @return string A plain decimal of at most eight places, with no padding zeros; '0' when the line has
     *                no price row.
     *
     * @since  2.0.0
     */
    public function getAuthoritativeUnit(): string
    {
        return $this->authoritativeUnit;
    }

    /**
     * What the customer pays a piece before any discount, on the gross axis:
     * a unit stated with tax as it is stated, a net unit through the tax
     * chain, held at the eighth place at each factor (9.95161290 at 24% is
     * 12.34; 0.0045 at 24% is 0.00558). The line's own money before discounts
     * is about this unit times the quantity; it is how a caller judges whether
     * a quantity is charged anything at all.
     *
     * @return string A plain decimal of at most eight places, with no padding zeros; '0' when the line has
     *                no price row.
     *
     * @since  2.0.0
     */
    public function getPayableUnit(): string
    {
        // Each factor multiplied exactly and the unit held at the eighth place
        // after each, as a derived unit is held.
        return $this->payableUnit ??= ExactAmount::of($this->authoritativeUnit)
            ->throughFactors(counts: $this->payableFactors, places: 8)
            ->text();
    }

    /**
     * What the customer pays a piece before any discount, exactly: the stated
     * unit through each factor of the chain with nothing rounded (0.00471698
     * at 6% is 0.0049999988, where the unit held at the eighth place reads
     * 0.005). The quantity times this, rounded once at the currency's scale,
     * is the line's own money before discounts, to the minor unit: it is what
     * a fresh line is judged by when its quantity may cost nothing at all.
     *
     * @return string A plain decimal with no padding zeros, of as many places as the chain gives; '0' when the
     *                line has no price row.
     *
     * @since  2.0.0
     */
    public function getPayableUnitExact(): string
    {
        if ($this->payableUnitExact === null) {
            $amount = ExactAmount::of($this->authoritativeUnit);

            foreach ($this->payableFactors as $count) {
                $amount = $amount->timesCount(count: $count, places: 10);
            }

            $this->payableUnitExact = $amount->text();
        }

        return $this->payableUnitExact;
    }

    /**
     * Where the stated unit comes from: a price row's stored value, a sale's own
     * price, an agreed custom rate, or a unit computed for a variant.
     *
     * Runtime provenance for presentation: it decides how a unit is shown, and
     * no amount is computed from it.
     *
     *
     * @since  2.0.0
     */
    public function getStatedOrigin(): UnitOrigin
    {
        return $this->statedOrigin;
    }

    /**
     * Which side of the tax the stated unit is on: the net, the gross, or both
     * when no rate changes it.
     *
     * Runtime provenance for presentation: it is not stored with a price row,
     * and no amount is computed from it.
     *
     *
     * @since  2.0.0
     */
    public function getStatedAxis(): UnitAxis
    {
        return $this->statedAxis;
    }

    /**
     * Whether a discount, or a sale below its list, took anything from the line,
     * exactly: a rule whose money rounds to nothing still reduced the exact line.
     * hasDiscount() answers the same question in money.
     *
     * Runtime provenance for presentation: no amount is computed from it.
     *
     *
     * @since  2.0.0
     */
    public function isReduced(): bool
    {
        return $this->reduced;
    }

    /**
     * The price row's own value the line is listed at: the stated unit, unless a
     * sale states the line, when it is the list the sale is measured against.
     *
     * Runtime provenance for presentation: no amount is computed from it.
     *
     * @return string A plain decimal of at most eight places; '0' when the line has no price row.
     *
     * @since  2.0.0
     */
    public function getListUnit(): string
    {
        return $this->listUnit;
    }

    /**
     * What a piece costs after every discount, exactly through the discounts
     * and the tax chain, held at the eighth place: 0.004 less 10% is 0.0036
     * net and 0.004464 with 24% tax, where the money of one piece reads 0.00.
     *
     * Runtime provenance for presentation: it is shown, never charged, and no
     * amount is computed from it.
     *
     * @param bool $withTax True for the unit with tax, false for the net unit.
     *
     * @return string|null A plain decimal of at most eight places; null for a net the chain cannot
     *                     divide, or a line of no quantity.
     *
     * @since  2.0.0
     */
    public function getDiscountedUnitExact(bool $withTax): ?string
    {
        $key = $withTax ? 'gross' : 'net';

        if (!\array_key_exists($key, $this->discountedUnits)) {
            $line = $withTax ? $this->shownGrossLine : $this->shownNetLine;
            $this->discountedUnits[$key] = $line === null || $this->quantity <= 0
                ? null
                : ExactAmount::of($line)->dividedBy(whole: $this->quantity, places: 8)->text();
        }

        return $this->discountedUnits[$key];
    }

    /**
     * Get the currency of this result (derived from the total).
     * @since  1.0.0
     */
    public function getCurrency(): Currency
    {
        return $this->total->getCurrency();
    }

    /**
     * Get the applied-discounts summary.
     * @since  1.0.0
     */
    public function getDiscounts(): DiscountSummary
    {
        return $this->discounts;
    }

    /**
     * Get the applied-taxes summary.
     * @since  1.0.0
     */
    public function getTaxes(): TaxSummary
    {
        return $this->taxes;
    }

    /**
     * Get the step-by-step calculation breakdown.
     * @since  1.0.0
     */
    public function getBreakdown(): PriceBreakdown
    {
        return $this->breakdown;
    }

    // Helper methods

    /**
     * Safely divide Money amount by quantity
     *
     * Returns zero Money object if quantity is zero to prevent division errors.
     *
     * @param Money $money Amount to divide
     * @return Money Divided amount or zero
     * @since  1.0.0
     */
    private function divideByQuantity(Money $money): Money
    {
        if ($this->quantity === 0) {
            return Money::zero($this->getCurrency());
        }

        return $money->divide($this->quantity);
    }

    // Formatting methods

    /**
     * Format all prices for API responses/DTOs
     *
     *   PRESENTATION LAYER CONVENIENCE ONLY
     * - Use for bulk API responses, admin panels, data exports
     * - Do NOT use in templates (use ->getTotal()->format() directly)
     * - Do NOT add business logic here
     *
     * @internal This is a DTO adapter, not core domain logic
     * @api-dto
     *
     * @param bool $includeSymbol Include currency symbol
     * @param bool $includeUnitPrices Include per-unit prices
     * @return array Formatted prices
     * @since  1.0.0
     */
    public function formatAll(bool $includeSymbol = true, bool $includeUnitPrices = false): array
    {
        $formatted = [
            'base_total' => $this->baseTotal->format($includeSymbol),
            'subtotal' => $this->subtotal->format($includeSymbol),
            'tax_total' => $this->taxTotal->format($includeSymbol),
            'total' => $this->total->format($includeSymbol),
            'savings_total' => $this->getSavingsTotal()->format($includeSymbol),
        ];

        // Optionally include unit prices
        if ($includeUnitPrices) {
            $formatted['base_price'] = $this->getBasePrice()->format($includeSymbol);
            $formatted['subtotal_price'] = $this->getSubtotalPrice()->format($includeSymbol);
            $formatted['tax_price'] = $this->getTaxPrice()->format($includeSymbol);
            $formatted['price'] = $this->getPrice()->format($includeSymbol);
            $formatted['savings_price'] = $this->getSavingsPrice()->format($includeSymbol);
        }

        return $formatted;
    }

    // Export methods

    /**
     * Export as array for APIs/JSON
     *
     * Every amount is published as an exact decimal string, never a float: money
     * at the currency's scale, the agreed rate as its own digits, percentages and
     * rates as their decimal text ({@see PublishedDecimal}). Each figure's formatted
     * text is its own field.
     * @since  1.0.0
     */
    public function toArray(): array
    {
        return [
            'base_total' => [
                'amount' => PublishedDecimal::money($this->baseTotal),
                'formatted' => $this->baseTotal->format(),
            ],
            'subtotal' => [
                'amount' => PublishedDecimal::money($this->subtotal),
                'formatted' => $this->subtotal->format(),
            ],
            'tax_total' => [
                'amount' => PublishedDecimal::money($this->taxTotal),
                'formatted' => $this->taxTotal->format(),
            ],
            'total' => [
                'amount' => PublishedDecimal::money($this->total),
                'formatted' => $this->total->format(),
            ],
            'currency' => $this->getCurrency()->toArray(),
            'quantity' => $this->quantity,
            'price' => [
                'amount' => PublishedDecimal::money($this->getPrice()),
                'formatted' => $this->getPrice()->format(),
            ],
            'savings' => [
                'total_amount' => PublishedDecimal::money($this->getSavingsTotal()),
                'total_formatted' => $this->getSavingsTotal()->format(),
                'price_amount' => PublishedDecimal::money($this->getSavingsPrice()),
                'price_formatted' => $this->getSavingsPrice()->format(),
                'percent' => PublishedDecimal::percent($this->getSavingsPercent()),
                'has_discount' => $this->hasDiscount(),
            ],
            // The two new facts, so a serialised result is not silently
            // lossy. They reach the public REST catalogue payload through
            // SanitisesCatalogPayload, always false/null there, because that
            // path has no instruction and cannot have one.
            'has_explicit_price' => $this->hasExplicitPrice(),
            'explicit_price' => $this->explicitTotalWithTax === null ? null : [
                'unit_tax_incl' => $this->explicitPrice === null ? null : PublishedDecimal::unit(value: $this->explicitPrice->rate, scale: $this->getCurrency()->getDecimalPlaces()),
                'total_amount' => $this->explicitTotal === null ? null : PublishedDecimal::money($this->explicitTotal),
                'total_with_tax_amount' => PublishedDecimal::money($this->explicitTotalWithTax),
                'line_saving_amount' => PublishedDecimal::money($this->getLineSaving()),
                'line_saving_with_tax_amount' => PublishedDecimal::money($this->getLineSavingWithTax()),
                'line_saving_percent' => PublishedDecimal::percent($this->getLineSavingPercent()),
            ],
            'discounts' => $this->discounts->toArray(),
            'taxes' => $this->taxes->toArray(),
            'breakdown' => $this->breakdown->toArray(),
        ];
    }

    /**
     * Export as JSON
     * @since  1.0.0
     */
    public function toJson(int $options = JSON_PRETTY_PRINT): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Export minimal data (for lightweight API responses)
     * @since  1.0.0
     */
    public function toMinimalArray(): array
    {
        return [
            'total' => PublishedDecimal::money($this->total),
            'formatted' => $this->total->format(),
            'currency' => $this->getCurrency()->getCode(),
            'quantity' => $this->quantity,
        ];
    }
}
