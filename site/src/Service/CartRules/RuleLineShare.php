<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\CartRules;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\Money;

/**
 * One cart rule's share of one cart line: the money the rule took off that line, as the engine shared the rule out.
 *
 * The gross is the engine's own split of the rule over the lines it applied to, on the basis it measured them on. The
 * net is each line's gross at that line's own net over gross, the rounding remainder placed so the shares' nets add up
 * to the rule's net exactly. A rule's shares add up to the rule, both figures, to the cent. Placement freezes each
 * share against its order line (#__alfa_order_item_cart_rule). Shipping rules have no shares: they reduce the shipping,
 * not a line.
 *
 * @since  2.3.0
 */
final class RuleLineShare
{
    /**
     * @param int $lineId The cart line (#__alfa_cart_items.id).
     * @param Money $taxIncl The share, tax included: what came off the line.
     * @param Money $taxExcl The share, tax excluded.
     *
     * @since  2.3.0
     */
    public function __construct(
        public readonly int $lineId,
        public readonly Money $taxIncl,
        public readonly Money $taxExcl,
    ) {
    }
}
