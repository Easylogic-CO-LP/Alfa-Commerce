<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart\Plugin;

use Alfa\Component\Alfa\Site\Service\Cart\Cart;

defined('_JEXEC') or die;

/**
 * What a contributor is asked to charge for.
 *
 * DELIBERATELY WITHOUT THE TOTAL. Contributions are folded into the cart's
 * money after they are collected, so a contributor that could read the grand
 * total would be reading a number its own answer is about to change. The lines
 * and the goods subtotal are settled; the total is not, and is not offered.
 *
 * $cart does not weaken that. It is {@see Cart}, built with the settled goods
 * subtotal (getSubtotal() answers) and no total (getTotal() throws at this
 * stage), so a contributor can see what is in the basket, where it is going, in
 * which currency and what the goods come to, and still cannot reach the figure
 * it is about to move. The rule is enforced by which class is handed over, not
 * by leaving the caller in the dark.
 *
 * @since  2.0.0
 */
final class ContributionRequest
{
    /**
     * @param ContributionLine[] $lines The cart's payable lines: a line flagged
     *                                  unavailable is shown but not charged for.
     * @param Cart $cart The cart being charged against, entire but for
     *                   the lines flagged unavailable.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly array $lines,
        public readonly Cart $cart,
    ) {
    }
}
