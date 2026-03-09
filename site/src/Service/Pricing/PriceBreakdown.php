<?php
namespace Alfa\Component\Alfa\Site\Service\Pricing;
/**
 * Price Breakdown
 *
 * Detailed step-by-step calculation breakdown
 */
class PriceBreakdown
{
	public array $steps = [];

	/**
	 * Add a calculation step
	 */
	public function addStep(string $description, Money $amount, string $operation): void
	{
		$this->steps[] = [
			'description' => $description,
			'amount' => [
				'value' => $amount->getAmount(),
				'formatted' => $amount->format(),
			],
			'operation' => $operation, // 'set', 'add', 'subtract'
		];
	}

	public function toArray(): array
	{
		return [
			'steps' => $this->steps,
		];
	}
}