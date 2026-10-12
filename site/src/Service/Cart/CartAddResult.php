<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Site\Service\Pricing\Money;

defined('_JEXEC') or die;

/**
 * The outcome of adding a product, and what the confirmation shows.
 *
 * The add answers with a line and two running totals rather than a cart,
 * because that is all the confirmation card and the drawer counter display.
 * Handing over the cart instead would let a layout ask it anything, and the
 * card would be one question away from re-pricing the whole basket mid-render.
 *
 * THE LINE IS THE ONE THE SHOPPER JUST GOT. It is re-read after the write, so
 * it carries the price the cart charges with it in place rather than the price
 * quoted before it existed.
 *
 * @since  1.10.0
 */
final class CartAddResult
{
    /**
     * @param bool $added Whether a line was stored.
     * @param int $lineId The line it landed on; 0 when nothing was stored.
     * @param object|null $line That line, priced in the cart it now belongs to.
     * @param int $itemCount Distinct lines in the cart afterwards.
     * @param int $quantity Units in the cart afterwards.
     * @param Money|null $grandTotal What the cart comes to afterwards, or null
     *                               when nothing was added and there is nothing
     *                               to show.
     *
     * @since  1.10.0
     */
    public function __construct(
        public readonly bool $added,
        public readonly int $lineId,
        public readonly ?object $line,
        public readonly int $itemCount,
        public readonly int $quantity,
        public readonly ?Money $grandTotal = null,
    ) {
    }
}
