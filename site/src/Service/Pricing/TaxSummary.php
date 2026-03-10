<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

/**
 * Tax Summary
 *
 * Aggregates all tax information
 */
class TaxSummary
{
    private Money $total;
    private float $effectiveRate;
    private array $applied; // Array of AppliedTax

    public function __construct(Money $total, float $effectiveRate, array $applied = [])
    {
        $this->total = $total;
        $this->effectiveRate = $effectiveRate;
        $this->applied = $applied;
    }

    public function getTotal(): Money
    {
        return $this->total;
    }

    public function getEffectiveRate(): float
    {
        return $this->effectiveRate;
    }

    public function getApplied(): array
    {
        return $this->applied;
    }

    public function hasTaxes(): bool
    {
        return $this->total->isPositive();
    }

    public function getCount(): int
    {
        return count($this->applied);
    }

    public function toArray(): array
    {
        return [
            'total' => [
                'amount' => $this->total->getAmount(),
                'formatted' => $this->total->format(),
            ],
            'effective_rate' => $this->effectiveRate,
            'count' => $this->getCount(),
            'applied' => array_map(fn ($t) => $t->toArray(), $this->applied),
        ];
    }
}
