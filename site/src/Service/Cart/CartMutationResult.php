<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

defined('_JEXEC') or die;

/**
 * What happened to a cart, and what it looks like now.
 *
 * The answer to a mutation, in place of the cart that performed it. A caller
 * gets the verdict and a reading; it does not get the object that did the work,
 * so it cannot ask that object a second question whose answer has since moved.
 *
 * THE READING IS OPTIONAL, AND ITS ABSENCE IS NOT AN OVERSIGHT. Taking one asks
 * the cart for its contributions, which boots every commercial plugin. An
 * operation whose response never shows a total (or one that failed, and whose
 * caller renders nothing) must not pay for a reading nobody displays. So the
 * snapshot is here when the caller shows one and null when it does not.
 *
 * @since  2.0.0
 */
final class CartMutationResult
{
    /**
     * @param bool $succeeded Whether the operation did what was asked.
     * @param int $cartId The cart it resolved to; 0 when there was none.
     * @param CartSnapshot|null $snapshot One settled reading taken after the change,
     *                                    or null when the caller renders nothing.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly bool $succeeded,
        public readonly int $cartId,
        public readonly ?CartSnapshot $snapshot = null,
    ) {
    }
}
