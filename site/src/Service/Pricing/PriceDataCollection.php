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

    public function __construct(array $prices = [], array $discounts = [], array $taxes = [])
    {
        $this->prices = $prices;
        $this->discounts = $discounts;
        $this->taxes = $taxes;
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
}
