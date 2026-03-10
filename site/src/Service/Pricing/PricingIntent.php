<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * PricingIntent - Immutable, type-safe declaration of pricing context
 *
 * @package    Alfa Commerce
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

final class PricingIntent
{
    public const CATALOG = 'catalog';
    public const CART = 'cart';
    public const CHECKOUT = 'checkout';
    public const QUOTE = 'quote';

    private string $scope;
    private array $quantities;
    private int $minQuantity;

    /**
     * Private constructor enforces use of named constructors
     *
     * @param string $scope Pricing scope
     * @param array $quantities Item ID => quantity map
     * @param int $minQuantity Minimum quantity fallback
     */
    private function __construct(string $scope, array $quantities = [], int $minQuantity = 1)
    {
        $this->scope = $scope;
        // Defensive copy + type coercion for immutability and type safety
        $this->quantities = array_map('intval', $quantities);
        $this->minQuantity = max(1, $minQuantity);
    }

    /**
     * Catalog pricing - uses minimum order quantities from product data
     */
    public static function catalog(): self
    {
        return new self(self::CATALOG);
    }

    /**
     * Cart pricing - uses actual quantities in shopping cart
     *
     * @param array $quantities Map of item_id => quantity
     */
    public static function cart(array $quantities): self
    {
        return new self(self::CART, $quantities);
    }

    /**
     * Checkout pricing - uses quantities being checked out
     *
     * May differ from cart if user modifies quantities during checkout
     *
     * @param array $quantities Map of item_id => quantity
     */
    public static function checkout(array $quantities): self
    {
        return new self(self::CHECKOUT, $quantities);
    }

    /**
     * Quote pricing - custom quantities for quote/proposal generation
     *
     * @param array $quantities Map of item_id => quantity
     * @param int $minQuantity Optional minimum quantity override
     */
    public static function quote(array $quantities, int $minQuantity = 1): self
    {
        return new self(self::QUOTE, $quantities, $minQuantity);
    }

    /**
     * Get quantity for an item based on pricing intent
     *
     * Type-safe and defensive - ensures valid integer quantities
     *
     * @param object $item Item object with potential quantity_min property
     *
     * @return int Valid quantity (always >= 1)
     */
    public function getQuantityForItem(object $item): int
    {
        // For transactional contexts with explicit quantities
        if (isset($this->quantities[$item->id])) {
            return max($this->minQuantity, $this->quantities[$item->id]);
        }

        // For catalog or missing quantities, use product minimum
        $itemMin = isset($item->quantity_min) ? (int) $item->quantity_min : $this->minQuantity;

        return max($this->minQuantity, $itemMin);
    }

    /**
     * Get all quantities (immutable copy)
     *
     * @return array Copy of quantities array
     */
    public function getQuantities(): array
    {
        return $this->quantities; // PHP arrays are copy-on-write, safe to return
    }

    /**
     * Get pricing scope
     *
     * @return string One of the scope constants
     */
    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * Check if this is a transactional context (cart/checkout)
     *
     * Useful for:
     * - Stock validation (reserve stock for cart/checkout)
     * - Promotional rules (apply cart-specific discounts)
     * - Tax calculation (different rules for quotes vs. actual sales)
     * - Analytics (track conversion funnel)
     */
    public function isTransactional(): bool
    {
        return in_array($this->scope, [self::CART, self::CHECKOUT], true);
    }

    /**
     * Check if stock should be validated
     *
     * Catalog views don't need strict stock checks, but cart/checkout do
     */
    public function requiresStockValidation(): bool
    {
        return $this->isTransactional();
    }

    /**
     * Check if prices should be cached
     *
     * Catalog prices can be cached, transactional prices should not
     */
    public function isCacheable(): bool
    {
        return $this->scope === self::CATALOG;
    }

    /**
     * Get a debug-friendly representation
     */
    public function __toString(): string
    {
        $itemCount = count($this->quantities);
        return sprintf(
            'PricingIntent[scope=%s, items=%d, transactional=%s]',
            $this->scope,
            $itemCount,
            $this->isTransactional() ? 'yes' : 'no',
        );
    }
}
