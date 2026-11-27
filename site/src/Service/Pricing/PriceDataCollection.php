<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

class PriceDataCollection
{
    protected $prices = [];
    protected $discounts = [];
    protected $taxes = [];

    /**
     * The discount rules matched to each product by their scope, before the product's own switch: what a variant set
     * to take the rules reads while its product is excluded.
     *
     * @var array<int, object[]>
     *
     * @since  2.3.0
     */
    protected array $ruleDiscounts = [];

    /**
     * The products whose own switch excludes them from every discount rule.
     *
     * @var array<int, true>
     *
     * @since  2.3.0
     */
    protected array $excluded = [];

    /**
     * @param array $prices The price rows by product.
     * @param array $discounts The discount rules by product, after each product's own switch (none for an excluded product).
     * @param array $taxes The taxes by product.
     * @param array|null $ruleDiscounts The rules as their scope matched them, before the switch; the discounts when omitted.
     * @param int[] $excludedItemIds The products whose switch excludes them from every rule.
     */
    public function __construct(array $prices = [], array $discounts = [], array $taxes = [], ?array $ruleDiscounts = null, array $excludedItemIds = [])
    {
        $this->prices = $prices;
        $this->discounts = $discounts;
        $this->taxes = $taxes;
        $this->ruleDiscounts = $ruleDiscounts ?? $discounts;
        $this->excluded = array_fill_keys(array_map('intval', $excludedItemIds), true);
    }

    /**
     * Get the price rows for a product.
     *
     * @param int $productId The product id.
     *
     * @return array The price rows (empty when none loaded).
     * @since  1.0.0
     */
    public function getPricesFor(int $productId): array
    {
        return $this->prices[$productId] ?? [];
    }

    /**
     * Get the discount rows for a product.
     *
     * @param int $productId The product id.
     *
     * @return array The discount rows (empty when none loaded).
     * @since  1.0.0
     */
    public function getDiscountsFor(int $productId): array
    {
        return $this->discounts[$productId] ?? [];
    }

    /**
     * Get the tax rows for a product.
     *
     * @param int $productId The product id.
     *
     * @return array The tax rows (empty when none loaded).
     * @since  1.0.0
     */
    public function getTaxesFor(int $productId): array
    {
        return $this->taxes[$productId] ?? [];
    }

    /**
     * The discount rules matched to a product by their scope, before its own switch.
     *
     * @since  2.3.0
     */
    public function getRuleDiscountsFor(int $productId): array
    {
        return $this->ruleDiscounts[$productId] ?? [];
    }

    /**
     * Whether a product's own switch lets the discount rules apply to it.
     *
     * @since  2.3.0
     */
    public function rulesApplyFor(int $productId): bool
    {
        return !isset($this->excluded[$productId]);
    }
}
