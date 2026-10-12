<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

defined('_JEXEC') or die;

/**
 * Why a requested quantity is not the quantity that was allowed.
 *
 * One case per rule the policy applies, recorded in the order the rules fire so
 * a caller can say what happened without re-deriving it. Some are silent (a
 * request rounded down to a sellable multiple is a correction the shopper does
 * not need a sentence about), and which ones speak is the application's
 * decision, not this enum's. Nothing here names a language key or a message
 * type: the policy reports what it decided, the adapter decides what to say.
 *
 * @since  1.10.0
 */
enum QuantityReason: string
{
    /** The shelf has nothing left for this cart once its other lines are counted. */
    case StockExhausted = 'stock_exhausted';

    /** The request was below the merchant's minimum and was raised to it. */
    case RaisedToMinimum = 'raised_to_minimum';

    /** The request was above the merchant's maximum and was capped at it. */
    case CappedAtMaximum = 'capped_at_maximum';

    /** The request was not a multiple of the merchant's step and was rounded down. */
    case RoundedToStep = 'rounded_to_step';

    /** The merchant's rules leave the request below the minimum, or below one unit, while the shelf could supply the smallest sale. */
    case NotASaleQuantity = 'not_a_sale_quantity';

    /** More was asked for than the shelf can supply, so the request was cut to it. */
    case ClampedToStock = 'clamped_to_stock';

    /** What the shelf left was not a sellable multiple, so the step was applied again. */
    case RoundedToStepAfterStock = 'rounded_to_step_after_stock';

    /** What is available cannot reach the smallest sale, or the merchant's rules allow no sale at all. */
    case BelowMinimum = 'below_minimum';
}
