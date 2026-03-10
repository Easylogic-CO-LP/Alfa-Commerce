<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

/**
 * Applied Discount
 *
 * Represents a discount that has been applied to a price
 */
class AppliedDiscount
{
    public int $id;
    public string $name;
    public string $code;
    public ?Money $amount;      // For fixed amount discounts
    public float $percent;      // For percentage discounts
    public string $type;        // 'fixed_amount' or 'percentage'
    public string $timing;      // 'before_tax' or 'after_tax'

    public function __construct(
        int $id,
        string $name,
        string $code,
        ?Money $amount,
        float $percent,
        string $type,
        string $timing,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->code = $code;
        $this->amount = $amount;
        $this->percent = $percent;
        $this->type = $type;
        $this->timing = $timing;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'amount' => $this->amount ? [
                'value' => $this->amount->getAmount(),
                'formatted' => $this->amount->format(),
            ] : null,
            'percent' => $this->percent,
            'type' => $this->type,
            'timing' => $this->timing,
        ];
    }
}
