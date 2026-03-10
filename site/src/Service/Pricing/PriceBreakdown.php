<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

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
