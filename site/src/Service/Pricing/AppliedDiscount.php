<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

/**
 * Applied Discount
 *
 * Represents a discount that has been applied to a price
 * @since  1.0.0
 */
class AppliedDiscount
{
    public int $id;
    public string $name;
    public string $code;
    public ?Money $amount;      // A fixed-amount rule's money for the WHOLE line: its per-unit amount x quantity, as the rule offers it (what the line took is the breakdown step)
    public float $percent;      // For percentage discounts
    public string $type;        // 'fixed_amount' or 'percentage'
    public string $timing;      // 'before_tax' or 'after_tax'
    public bool $tagListShow;   // Badge this discount's name on catalogue cards
    public string $tagListSlot; // ItemListSurface slot for the card badge
    public bool $tagItemShow;   // Badge this discount's name on the product page
    public string $tagItemSlot; // ItemSurface slot for the product-page badge
    public string $tagBg;       // Badge background colour (CSS); '' = layout default
    public string $tagColor;    // Badge text colour (CSS); '' = layout default
    public string $tagClass;    // Extra CSS class(es) on the badge; shared both contexts

    /**
     * The rule's combination behaviour: 0 "only this", 1 Combined, 2 Sequential.
     *
     * It has to travel with the rule. The engine decides the money in compute(),
     * one stage at a time, long after processDiscounts() has thrown the raw rows
     * away, and Combined rules must be recognisable there, because a pool shares
     * ONE base while a sequential rule consumes the running remainder. Anything
     * outside {0, 1} is Sequential, which is the reading the engine has always
     * given an unrecognised value.
     *
     * @since  1.10.0
     */
    public int $behavior;

    public function __construct(
        int $id,
        string $name,
        string $code,
        ?Money $amount,
        float $percent,
        string $type,
        string $timing,
        bool $tagListShow = false,
        string $tagListSlot = 'image',
        bool $tagItemShow = false,
        string $tagItemSlot = 'image',
        string $tagBg = '',
        string $tagColor = '',
        string $tagClass = '',
        int $behavior = 2,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->code = $code;
        $this->amount = $amount;
        $this->percent = $percent;
        $this->type = $type;
        $this->timing = $timing;
        $this->tagListShow = $tagListShow;
        $this->tagListSlot = $tagListSlot;
        $this->tagItemShow = $tagItemShow;
        $this->tagItemSlot = $tagItemSlot;
        $this->tagBg = $tagBg;
        $this->tagColor = $tagColor;
        $this->tagClass = $tagClass;
        $this->behavior = $behavior;
    }

    /**
     * Export the applied discount as a plain array (formatting the amount when present).
     *
     * The amount is money text and the rule's percentage its decimal text
     * ({@see PublishedDecimal}); the engine holds the percentage as a float, read
     * at the fifteen significant digits it carries, which state every stored rule.
     * @since  1.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'amount' => $this->amount ? [
                'value' => PublishedDecimal::money($this->amount),
                'formatted' => $this->amount->format(),
            ] : null,
            'percent' => PublishedDecimal::decimal(ExactAmount::ofNumber($this->percent)),
            'type' => $this->type,
            'timing' => $this->timing,
            'tag_list_show' => $this->tagListShow,
            'tag_list_slot' => $this->tagListSlot,
            'tag_item_show' => $this->tagItemShow,
            'tag_item_slot' => $this->tagItemSlot,
            'tag_bg' => $this->tagBg,
            'tag_color' => $this->tagColor,
            'tag_class' => $this->tagClass,
        ];
    }
}
