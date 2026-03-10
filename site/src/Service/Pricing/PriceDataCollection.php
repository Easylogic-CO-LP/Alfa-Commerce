<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
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

    public function getPricesFor(int $productId): array
    {
        return $this->prices[$productId] ?? [];
    }

    public function getDiscountsFor(int $productId): array
    {
        return $this->discounts[$productId] ?? [];
    }

    public function getTaxesFor(int $productId): array
    {
        return $this->taxes[$productId] ?? [];
    }
}
