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

    // ========================================================================
    // BASIC GETTERS
    // ========================================================================

    /**
     * Get total discount amount
     */
    public function getTotal(): Money
    {
        return $this->total;
    }

    /**
     * Get all applied discounts
     *
     * @return array Array of AppliedDiscount objects
     */
    public function getApplied(): array
    {
        return $this->applied;
    }

    /**
     * Check if any discounts are applied
     */
    public function hasDiscounts(): bool
    {
        return $this->total->isPositive();
    }

    /**
     * Get number of applied discounts
     */
    public function getCount(): int
    {
        return count($this->applied);
    }

    // ========================================================================
    // PERCENTAGE CALCULATIONS
    // ========================================================================

    /**
     * Calculate effective savings percentage
     *
     * Handles discount timing correctly:
     * - Before-tax discounts: % relative to base total
     * - After-tax discounts: % relative to (subtotal + tax)
     * - Mixed discounts: Combined effective percentage
     *
     * @param Money $baseTotal Original amount before discounts
     * @param Money $subtotal Amount after discounts, before tax
     * @param Money $taxTotal Tax amount
     * @return float Percentage with 2 decimal places
     */
    public function getEffectivePercent(
        Money $baseTotal,
        Money $subtotal,
        Money $taxTotal,
    ): float {
        if ($baseTotal->isZero() || empty($this->applied)) {
            return 0.0;
        }

        $breakdown = $this->calculatePercentBreakdown($baseTotal, $subtotal, $taxTotal);

        return $breakdown['total'];
    }

    /**
     * Get discount percentage breakdown by timing
     *
     * @param Money $baseTotal Original amount before discounts
     * @param Money $subtotal Amount after discounts, before tax
     * @param Money $taxTotal Tax amount
     * @return array ['before_tax' => float, 'after_tax' => float, 'total' => float]
     */
    public function getPercentBreakdown(
        Money $baseTotal,
        Money $subtotal,
        Money $taxTotal,
    ): array {
        if ($baseTotal->isZero() || empty($this->applied)) {
            return [
                'before_tax' => 0.0,
                'after_tax' => 0.0,
                'total' => 0.0,
            ];
        }

        return $this->calculatePercentBreakdown($baseTotal, $subtotal, $taxTotal);
    }

    /**
     * Internal: Calculate percentage breakdown
     *
     * Separates before-tax and after-tax discounts and calculates
     * each relative to the appropriate base amount.
     */
    private function calculatePercentBreakdown(
        Money $baseTotal,
        Money $subtotal,
        Money $taxTotal,
    ): array {
        $beforeTaxPercent = 0.0;
        $afterTaxPercent = 0.0;

        // Separate discounts by timing
        $beforeTaxDiscounts = $this->getDiscountsByTiming('before_tax');
        $afterTaxDiscounts = $this->getDiscountsByTiming('after_tax');

        // Calculate before-tax discount percentages (relative to base total)
        foreach ($beforeTaxDiscounts as $discount) {
            $percent = $this->calculateDiscountPercent(
                $discount,
                $baseTotal,
            );
            $beforeTaxPercent += $percent;
        }

        // Calculate after-tax discount percentages (relative to subtotal + tax)
        if (!empty($afterTaxDiscounts)) {
            $subtotalWithTax = $subtotal->add($taxTotal);

            if (!$subtotalWithTax->isZero()) {
                foreach ($afterTaxDiscounts as $discount) {
                    $percent = $this->calculateDiscountPercent(
                        $discount,
                        $subtotalWithTax,
                    );
                    $afterTaxPercent += $percent;
                }
            }
        }

        return [
            'before_tax' => round($beforeTaxPercent, 2),
            'after_tax' => round($afterTaxPercent, 2),
            'total' => round($beforeTaxPercent + $afterTaxPercent, 2),
        ];
    }

    /**
     * Calculate percentage for a single discount
     *
     * Handles both percentage and fixed_amount discount types.
     *
     * @param object $discount AppliedDiscount object
     * @param Money $baseAmount Base amount to calculate percentage against
     * @return float Percentage value
     */
    private function calculateDiscountPercent($discount, Money $baseAmount): float
    {
        if ($discount->type === 'percentage') {
            // Percentage discounts already have the percent value
            return $discount->percent;
        }

        if ($discount->type === 'fixed_amount' && $discount->amount) {
            // Convert fixed amount to percentage of base
            if ($baseAmount->isZero()) {
                return 0.0;
            }

            return ($discount->amount->getAmount() / $baseAmount->getAmount()) * 100;
        }

        return 0.0;
    }

    /**
     * Get discounts filtered by timing
     *
     * @param string $timing 'before_tax' or 'after_tax'
     * @return array Filtered discounts
     */
    private function getDiscountsByTiming(string $timing): array
    {
        return array_filter(
            $this->applied,
            fn ($discount) => $discount->timing === $timing,
        );
    }

    // ========================================================================
    // DISCOUNT QUERYING
    // ========================================================================

    /**
     * Get discounts by type
     *
     * @param string $type 'percentage', 'fixed_amount', etc.
     * @return array Filtered discounts
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
     */
    public function hasDiscountCode(string $code): bool
    {
        return $this->getDiscountByCode($code) !== null;
    }

    // ========================================================================
    // EXPORT METHODS
    // ========================================================================

    /**
     * Export as array for APIs/JSON
     */
    public function toArray(): array
    {
        return [
            'total' => [
                'amount' => $this->total->getAmount(),
                'formatted' => $this->total->format(),
            ],
            'count' => $this->getCount(),
            'has_discounts' => $this->hasDiscounts(),
            'applied' => array_map(fn ($d) => $d->toArray(), $this->applied),
        ];
    }
}
