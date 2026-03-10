<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

/**
 * Applied Tax
 *
 * Represents a tax that has been applied to a price
 */
class AppliedTax
{
    public int $id;
    public string $name;
    public float $rate;         // Tax rate as percentage
    public string $jurisdiction;

    public function __construct(
        int $id,
        string $name,
        float $rate,
        string $jurisdiction,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->rate = $rate;
        $this->jurisdiction = $jurisdiction;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'rate' => $this->rate,
            'jurisdiction' => $this->jurisdiction,
        ];
    }
}
