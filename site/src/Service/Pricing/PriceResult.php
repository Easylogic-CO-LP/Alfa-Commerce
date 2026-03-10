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
 * - "Total" = aggregate amount (price × quantity)
 * - Order: base → subtotal → tax → total
 *
 * All monetary values are Money objects for:
 * - Type safety
 * - Proper formatting
 * - Currency-aware operations
 * - Immutability
 *
 * Inspired by: Shopify, Magento 2, Salesforce Commerce Cloud
 */
class PriceResult
{
    // Total amounts (price × quantity)
    private Money $baseTotal;          // Original price × quantity (before discounts)
    private Money $subtotal;           // After discounts, before tax
    private Money $taxTotal;           // Total tax amount
    private Money $total;              // Final total customer pays

    private int $quantity;

    private DiscountSummary $discounts;
    private TaxSummary $taxes;
    private PriceBreakdown $breakdown;

    public function __construct(
        Money $baseTotal,
        Money $subtotal,
        Money $taxTotal,
        Money $total,
        int $quantity,
        DiscountSummary $discounts,
        TaxSummary $taxes,
        PriceBreakdown $breakdown,
    ) {
        $this->baseTotal = $baseTotal;
        $this->subtotal = $subtotal;
        $this->taxTotal = $taxTotal;
        $this->total = $total;
        $this->quantity = $quantity;
        $this->discounts = $discounts;
        $this->taxes = $taxes;
        $this->breakdown = $breakdown;
    }

    // ========================================================================
    // TOTAL AMOUNTS (price × quantity)
    // ========================================================================

    /**
     * Get base total (original price × quantity, before any discounts)
     */
    public function getBaseTotal(): Money
    {
        return $this->baseTotal;
    }

    /**
     * Get subtotal (after discounts, before tax)
     */
    public function getSubtotal(): Money
    {
        return $this->subtotal;
    }

    /**
     * Get total tax amount
     */
    public function getTaxTotal(): Money
    {
        return $this->taxTotal;
    }

    /**
     * Get final total (what customer pays)
     */
    public function getTotal(): Money
    {
        return $this->total;
    }

    // ========================================================================
    // UNIT PRICES (per single item)
    // ========================================================================

    /**
     * Get base price per unit (before any discounts)
     *
     * This is the original unit price before any calculations.
     */
    public function getBasePrice(): Money
    {
        return $this->divideByQuantity($this->baseTotal);
    }

    /**
     * Get subtotal price per unit (after discounts, before tax)
     */
    public function getSubtotalPrice(): Money
    {
        return $this->divideByQuantity($this->subtotal);
    }

    /**
     * Get tax per unit
     */
    public function getTaxPrice(): Money
    {
        return $this->divideByQuantity($this->taxTotal);
    }

    /**
     * Get price per unit (final price per item)
     *
     * Most commonly used method for displaying unit price.
     */
    public function getPrice(): Money
    {
        return $this->divideByQuantity($this->total);
    }

    // ========================================================================
    // SAVINGS & DISCOUNTS
    // ========================================================================

    /**
     * Get total savings amount from all discounts
     */
    public function getSavingsTotal(): Money
    {
        return $this->discounts->getTotal();
    }

    /**
     * Get savings per unit
     */
    public function getSavingsPrice(): Money
    {
        return $this->divideByQuantity($this->getSavingsTotal());
    }

    /**
     * Check if product has active discount
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
     */
    public function getSavingsPercent(): float
    {
        return $this->discounts->getEffectivePercent(
            $this->baseTotal,
            $this->subtotal,
            $this->taxTotal,
        );
    }

    /**
     * Get discount breakdown by timing
     *
     * @return array ['before_tax' => float, 'after_tax' => float, 'total' => float]
     */
    public function getSavingsPercentBreakdown(): array
    {
        return $this->discounts->getPercentBreakdown(
            $this->baseTotal,
            $this->subtotal,
            $this->taxTotal,
        );
    }

    // ========================================================================
    // METADATA
    // ========================================================================

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getCurrency(): Currency
    {
        return $this->total->getCurrency();
    }

    public function getDiscounts(): DiscountSummary
    {
        return $this->discounts;
    }

    public function getTaxes(): TaxSummary
    {
        return $this->taxes;
    }

    public function getBreakdown(): PriceBreakdown
    {
        return $this->breakdown;
    }

    // ========================================================================
    // HELPER METHODS
    // ========================================================================

    /**
     * Safely divide Money amount by quantity
     *
     * Returns zero Money object if quantity is zero to prevent division errors.
     *
     * @param Money $money Amount to divide
     * @return Money Divided amount or zero
     */
    private function divideByQuantity(Money $money): Money
    {
        if ($this->quantity === 0) {
            return Money::zero($this->getCurrency());
        }

        return $money->divide($this->quantity);
    }

    // ========================================================================
    // FORMATTING METHODS
    // ========================================================================

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

    // ========================================================================
    // EXPORT METHODS
    // ========================================================================

    /**
     * Export as array for APIs/JSON
     *
     * Includes both raw amounts and formatted strings.
     */
    public function toArray(): array
    {
        return [
            'base_total' => [
                'amount' => $this->baseTotal->getAmount(),
                'formatted' => $this->baseTotal->format(),
            ],
            'subtotal' => [
                'amount' => $this->subtotal->getAmount(),
                'formatted' => $this->subtotal->format(),
            ],
            'tax_total' => [
                'amount' => $this->taxTotal->getAmount(),
                'formatted' => $this->taxTotal->format(),
            ],
            'total' => [
                'amount' => $this->total->getAmount(),
                'formatted' => $this->total->format(),
            ],
            'currency' => $this->getCurrency()->toArray(),
            'quantity' => $this->quantity,
            'price' => [
                'amount' => $this->getPrice()->getAmount(),
                'formatted' => $this->getPrice()->format(),
            ],
            'savings' => [
                'total_amount' => $this->getSavingsTotal()->getAmount(),
                'total_formatted' => $this->getSavingsTotal()->format(),
                'price_amount' => $this->getSavingsPrice()->getAmount(),
                'price_formatted' => $this->getSavingsPrice()->format(),
                'percent' => $this->getSavingsPercent(),
                'has_discount' => $this->hasDiscount(),
            ],
            'discounts' => $this->discounts->toArray(),
            'taxes' => $this->taxes->toArray(),
            'breakdown' => $this->breakdown->toArray(),
        ];
    }

    /**
     * Export as JSON
     */
    public function toJson(int $options = JSON_PRETTY_PRINT): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Export minimal data (for lightweight API responses)
     */
    public function toMinimalArray(): array
    {
        return [
            'total' => $this->total->getAmount(),
            'formatted' => $this->total->format(),
            'currency' => $this->getCurrency()->getCode(),
            'quantity' => $this->quantity,
        ];
    }
}
