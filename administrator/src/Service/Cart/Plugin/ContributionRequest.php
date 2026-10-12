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
 * DELIBERATELY WITHOUT TOTALS, AND STILL IS. Contributions are folded into the
 * cart's money after they are collected, so a contributor that could read the
 * grand total would be reading a number its own answer is about to change. The
 * lines are settled; the money is not, and is not offered.
 *
 * $cart does not weaken that. It is {@see Cart} (the settled state,
 * with no totals() and no computation behind it), so a contributor can see what
 * is in the basket, where it is going and in which currency, and still cannot
 * reach the figure it is about to move. The rule the docblock states is
 * enforced by which class is handed over, not by leaving the caller in the dark.
 *
 * @since  1.10.0
 */
final class ContributionRequest
{
    /**
     * @param ContributionLine[] $lines The cart's payable lines: a line flagged
     *                                  unavailable is shown but not charged for.
     * @param Cart $cart The cart being charged against, entire but for
     *                   the lines flagged unavailable.
     *
     * @since  1.10.0
     */
    public function __construct(
        public readonly array $lines,
        public readonly Cart $cart,
    ) {
    }
}
