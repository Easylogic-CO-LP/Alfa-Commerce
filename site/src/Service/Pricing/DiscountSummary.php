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
 * Discount Summary
 *
 * Aggregates all discount information and owns discount calculation logic.
 *
 * Responsibilities:
 * - Track total discount amount
 * - Maintain list of applied discounts
 * - Calculate effective discount percentages
 * - Handle discount timing logic (before-tax vs after-tax)
 * @since  1.0.0
 */
class DiscountSummary
{
    private Money $total;
    private array $applied; // Array of AppliedDiscount objects

    public function __construct(Money $total, array $applied = [])
    {
        $this->total = $total;
        $this->applied = $applied;
    }

    // Basic getters

    /**
     * Get total discount amount
     * @since  1.0.0
     */
    public function getTotal(): Money
    {
        return $this->total;
    }

    /**
     * Get all applied discounts
     *
     * @return array Array of AppliedDiscount objects
     * @since  1.0.0
     */
    public function getApplied(): array
    {
        return $this->applied;
    }

    /**
     * Check if any discounts are applied
     * @since  1.0.0
     */
    public function hasDiscounts(): bool
    {
        return $this->total->isPositive();
    }

    /**
     * Get number of applied discounts
     * @since  1.0.0
     */
    public function getCount(): int
    {
        return count($this->applied);
    }

    // Percentage calculations

    /**
     * Get discount percentage breakdown by timing
     *
     * @param Money $baseTotal Original amount before discounts
     * @param Money $subtotal Amount after discounts, before tax
     * @param Money $taxTotal Tax amount
     * @return array ['before_tax' => float, 'after_tax' => float, 'total' => float]
     * @since  1.0.0
     */
    public function getPercentBreakdown(
        Money $baseTotal,
        Money $subtotal,
        Money $taxTotal,
        Money $total,
        Money $baseTotalWithTax,
    ): array {
        if ($baseTotal->isZero() || empty($this->applied)) {
            return [
                'before_tax' => 0.0,
                'after_tax' => 0.0,
                'total' => 0.0,
            ];
        }

        return $this->calculatePercentBreakdown($baseTotal, $subtotal, $taxTotal, $total, $baseTotalWithTax);
    }

    /**
     * Internal: the savings breakdown, per stage, FROM MONEY.
     *
     * THE TRUTH RULE: "any
     * displayed or persisted 'actual savings percentage' = (base - final) /
     * base x 100, derived from actual money, never by summing nominal rule
     * percentages", and it says it applies to EVERY surface, which includes
     * this one.
     *
     * This used to walk the applied rules and add up `$discount->percent`. Two
     * stacked 20% rules therefore reported 40.00% while the customer saved
     * 36.00%, because the second rule takes its cut from what the first one
     * left. Measured on the demo before this change: four rules reported
     * 45.00% against a real 38.44%.
     *
     * Each stage is measured against ITS OWN entry price, which is what makes
     * the two figures meaningful separately:
     *
     *     before_tax   (baseTotal - subtotal) / baseTotal
     *     after_tax    ((subtotal + tax) - total) / (subtotal + tax)
     *
     * and `total` is NOT their sum. Two percentages taken against different
     * bases do not add up to a percentage of anything; adding them is a second
     * form of the same error the rule forbids, so the total is derived once,
     * from the gross base, and therefore always equals
     * {@see PriceResult::getSavingsPercent()}, the figure every live surface
     * already shows.
     *
     * @since  1.0.0
     */
    private function calculatePercentBreakdown(
        Money $baseTotal,
        Money $subtotal,
        Money $taxTotal,
        Money $total,
        Money $baseTotalWithTax,
    ): array {
        $subtotalWithTax = $subtotal->add($taxTotal);

        // Each on minor units, by the one rule: no float quotient decides a
        // tie, and a stage that entered at nothing states 0.0.
        return [
            'before_tax' => Money::percentOf(
                part: $baseTotal->subtract($subtotal)->getMinorUnits(),
                whole: $baseTotal->getMinorUnits(),
            ),
            'after_tax' => Money::percentOf(
                part: $subtotalWithTax->subtract($total)->getMinorUnits(),
                whole: $subtotalWithTax->getMinorUnits(),
            ),
            'total' => Money::percentOf(
                part: $baseTotalWithTax->subtract($total)->getMinorUnits(),
                whole: $baseTotalWithTax->getMinorUnits(),
            ),
        ];
    }

    // NO PER-RULE PERCENTAGE HELPER LIVES HERE ANY MORE.
    //
    // calculateDiscountPercent() returned a rule's own NOMINAL figure (or a
    // fixed amount expressed against a base), and getDiscountsByTiming() split
    // the rules so those figures could be added up per stage. Both existed only
    // to serve calculatePercentBreakdown(), and that summing is precisely what
    // the truth rule forbids. The breakdown now measures money, so the two
    // helpers were removed rather than left as a loaded gun for the next caller
    // who wants "the percentage of this discount" and gets a number the shopper
    // never saw.
    //
    // A rule's nominal value is still available where it BELONGS: on the
    // AppliedDiscount itself ($percent), which is the rule as configured, not a
    // claim about what was saved.

    // Discount querying

    /**
     * Get discounts by type
     *
     * @param string $type 'percentage', 'fixed_amount', etc.
     * @return array Filtered discounts
     * @since  1.0.0
     */
    public function getDiscountsByType(string $type): array
    {
        return array_filter(
            $this->applied,
            fn ($discount) => $discount->type === $type,
        );
    }

    /**
     * Get discount by code
     *
     * @param string $code Discount code
     * @return object|null AppliedDiscount or null if not found
     * @since  1.0.0
     */
    public function getDiscountByCode(string $code): ?object
    {
        foreach ($this->applied as $discount) {
            if (isset($discount->code) && $discount->code === $code) {
                return $discount;
            }
        }

        return null;
    }

    /**
     * Check if a specific discount code was applied
     *
     * @param string $code Discount code
     * @since  1.0.0
     */
    public function hasDiscountCode(string $code): bool
    {
        return $this->getDiscountByCode($code) !== null;
    }

    // Export methods

    /**
     * Export as array for APIs/JSON: the total as money text ({@see PublishedDecimal}).
     * @since  1.0.0
     */
    public function toArray(): array
    {
        return [
            'total' => [
                'amount' => PublishedDecimal::money($this->total),
                'formatted' => $this->total->format(),
            ],
            'count' => $this->getCount(),
            'has_discounts' => $this->hasDiscounts(),
            'applied' => array_map(fn ($d) => $d->toArray(), $this->applied),
        ];
    }
}
