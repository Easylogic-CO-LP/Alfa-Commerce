<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

/**
 * Price Breakdown
 *
 * Detailed step-by-step calculation breakdown
 * @since  1.0.0
 */
class PriceBreakdown
{
    public array $steps = [];

    /**
     * Add a calculation step.
     *
     * @return int The step's index, so a later stage can restate it.
     *
     * @since  1.0.0
     */
    public function addStep(string $description, Money $amount, string $operation): int
    {
        $this->steps[] = [
            'description' => $description,
            'amount' => [
                // Money text, never a float: the breakdown is published as data.
                'value' => PublishedDecimal::money($amount),
                'formatted' => $amount->format(),
            ],
            'operation' => $operation, // 'set', 'add', 'subtract'
        ];

        return array_key_last($this->steps);
    }

    /**
     * Rewrite one step's money, leaving its description and operation alone.
     *
     * The narrative is a story about ONE calculation. When a later stage
     * re-derives an earlier figure, the story is corrected where it was told;
     * appending a second, contradictory amount would leave the page quoting two
     * different VAT figures in the same list.
     *
     * @param int $index A step index returned by addStep().
     * @param Money $amount The money that step now carries.
     *
     *
     * @since  1.10.0
     */
    public function restate(int $index, Money $amount): void
    {
        if (!isset($this->steps[$index])) {
            return;
        }

        $this->steps[$index]['amount'] = [
            'value' => PublishedDecimal::money($amount),
            'formatted' => $amount->format(),
        ];
    }

    /**
     * How many steps the narrative holds: the index the next one will take.
     *
     *
     * @since  1.10.0
     */
    public function stepCount(): int
    {
        return \count($this->steps);
    }

    /**
     * Export the breakdown steps as a plain array.
     * @since  1.0.0
     */
    public function toArray(): array
    {
        return [
            'steps' => $this->steps,
        ];
    }
}
