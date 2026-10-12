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
 * How a write boundary was told to mutate a line's persisted custom price.
 *
 * THREE STATES, NEVER COLLAPSED TO TWO. The contract says so
 * in terms, "do not collapse Leave and Clear into the same null", because
 * they are different acts with different authority:
 *
 *   Leave  the absence of a pricing mutation. Not a custom-price write at all,
 *          so it needs no pricing authority: an operator without the capability
 *          may still edit a quantity.
 *   Set    a mutation to a stated amount. Set(0.0) is a real free price.
 *   Clear  a mutation BACK to canonical catalogue/customer repricing.
 *
 * Collapsing Leave into Clear would make opening and saving a line silently
 * reprice it from the catalogue, which the custom-price contract forbids by name.
 *
 * @since  1.11.0
 */
enum CustomPriceAction
{
    case Leave;
    case Set;
    case Clear;
}
