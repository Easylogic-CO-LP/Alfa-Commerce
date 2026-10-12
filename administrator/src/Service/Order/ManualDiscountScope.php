<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

defined('_JEXEC') or die;

/**
 * Whether an order line's manual staff discount applies to its custom price.
 *
 * The operator's choice for ONE line, stored in
 * `#__alfa_order_items.manual_discount_scope` and shown in the order line
 * popup as "Apply this discount to the custom price". It is not a
 * promotion's override policy and never shares a value, a column or a reader
 * with one: "Manual line-discount scope and promotion-rule override
 * eligibility are separate contracts and must never be conflated."
 *
 * Off by default, so a negotiated price is never cut a second time unless the
 * operator asked for it. The scope matters only while the line carries a
 * custom price; a line without one takes its manual discount in full.
 *
 * @since  1.14.0
 */
enum ManualDiscountScope: string
{
    /** The manual discount does not reduce the line's custom price. The default. */
    case ExcludeCustomPrice = 'exclude_custom_price';

    /** The operator asked for the manual discount to apply after the custom price. */
    case ApplyToCustomPrice = 'apply_to_custom_price';

    /**
     * The manual percentage that actually comes off a line.
     *
     * The stored percentage is the operator's intent and is kept whatever the
     * scope says; this is how much of it reduces the money. A line with no
     * custom price takes it in full. A custom-priced line takes it only when
     * the operator ticked the box.
     *
     * @param float $manualPercent The line's manual discount, 0-100.
     * @param bool $hasCustomPrice Whether the line carries a custom price.
     *
     *
     * @since  1.14.0
     */
    public function effectivePercent(float $manualPercent, bool $hasCustomPrice): float
    {
        return !$hasCustomPrice || $this === self::ApplyToCustomPrice ? $manualPercent : 0.0;
    }
}
