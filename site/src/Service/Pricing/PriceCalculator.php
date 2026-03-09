<?php
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
		?PriceComputationEngine $calculator = null
	) {
		$this->loader = $loader ?? new PriceDataLoader();
		$this->calculator = $calculator ?? new PriceComputationEngine();
		$this->settings = ComponentHelper::getParams('com_alfa');
	}

	public function calculate($productIds, $quantities = 1, ?PriceContext $context = null)
	{
		$isSingle = !is_array($productIds);
		$productIds = (array) $productIds;
		$quantities = $this->normalizeQuantities($productIds, $quantities);
		$context = $context ?? PriceContext::fromSession();

		$priceData = $this->loader->loadBatch($productIds, $context);

		$results = [];
		foreach ($productIds as $productId) {
			$results[$productId] = $this->calculator->compute(
				$productId,
				$quantities[$productId],
				$priceData->getPricesFor($productId),
				$priceData->getDiscountsFor($productId),
				$priceData->getTaxesFor($productId),
				$context
			);
		}

		return $isSingle ? reset($results) : $results;
	}

	protected function normalizeQuantities(array $productIds, $quantities): array
	{
		if (is_array($quantities)) {
			return $quantities;
		}

		return array_fill_keys($productIds, $quantities);
	}
}