<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;

/**
 * Main Price Calculator Service
 */
class PriceCalculator
{
    protected $loader;
    protected $calculator;
    protected $settings;

    public function __construct(
        ?PriceDataLoader $loader = null,
        ?PriceComputationEngine $calculator = null,
    ) {
        $this->loader = $loader ?? new PriceDataLoader();
        $this->calculator = $calculator ?? new PriceComputationEngine();
        $this->settings = ComponentHelper::getParams('com_alfa');
    }

    /**
     * Calculate prices for one or many products, batch-loading their pricing data and computing a result each.
     *
     * @param int|int[]       $productIds One product id or an array of product ids.
     * @param int|int[]       $quantities A single quantity applied to all, or a per-product-id quantity map.
     * @param PriceContext|null $context  The pricing context; resolved from session when null.
     *
     * @return PriceResult|PriceResult[] A single result when a scalar id was passed, otherwise a map keyed by product id.
     */
    public function calculate($productIds, $quantities = 1, ?PriceContext $context = null)
    {
        $isSingle = !is_array($productIds);
        $productIds = (array) $productIds;
        $quantities = $this->normalizeQuantities($productIds, $quantities);
        $context ??= PriceContext::fromSession();

        $priceData = $this->loader->loadBatch($productIds, $context);

        $results = [];
        foreach ($productIds as $productId) {
            $results[$productId] = $this->calculator->compute(
                $productId,
                $quantities[$productId],
                $priceData->getPricesFor($productId),
                $priceData->getDiscountsFor($productId),
                $priceData->getTaxesFor($productId),
                $context,
            );
        }

        return $isSingle ? reset($results) : $results;
    }

    /**
     * Expand a quantity argument into a per-product-id quantity map.
     *
     * @param int[]     $productIds The product ids.
     * @param int|int[] $quantities A single quantity for all ids, or an existing per-id map (returned as-is).
     *
     * @return array The quantity map keyed by product id.
     */
    protected function normalizeQuantities(array $productIds, $quantities): array
    {
        if (is_array($quantities)) {
            return $quantities;
        }

        return array_fill_keys($productIds, $quantities);
    }
}
