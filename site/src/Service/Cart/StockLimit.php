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
 * The shelf's say in a shopper's add or quantity change: the cart holds the available quantity of a product.
 *
 * A request for more than the stock leaves is cut to it, and one the cart already meets adds nothing. Neither is a
 * failure: the shopper is told the available quantity once, as a notice, and the figure travels with the outcome so
 * the add's answer, its card and the cart page say the same sentence with the same number
 * ({@see CartMessaging::stockSentence()}).
 *
 * The figure is what the cart holds of that stock once the change is made, in whole units: every line drawing on the
 * same stock is counted, as the quantity policy counts them.
 *
 * Immutable, and free of anything that could talk to a shopper.
 *
 * @since  2.2.2
 */
final class StockLimit
{
    /**
     * @param bool $added Whether the cart holds more than before the request.
     * @param int $quantity What the cart holds of that stock afterwards: the available quantity.
     * @param bool $lowered Whether a line was lowered to that quantity: the cart held more of that stock than there
     *                      is, so nothing went in, yet the cart changed.
     *
     * @since  2.2.2
     */
    private function __construct(
        public readonly bool $added,
        public readonly int $quantity,
        public readonly bool $lowered = false,
    ) {
    }

    /**
     * Nothing more went in: the cart already held the available quantity.
     *
     * @param int $quantity What the cart holds of that stock.
     * @param bool $lowered Whether a line was lowered to it on the way.
     *
     * @since  2.2.2
     */
    public static function held(int $quantity, bool $lowered = false): self
    {
        return new self(added: false, quantity: $quantity, lowered: $lowered);
    }

    /**
     * The request was cut: the cart now holds the available quantity.
     *
     * @param int $quantity What the cart holds of that stock after the change.
     *
     * @since  2.2.2
     */
    public static function reached(int $quantity): self
    {
        return new self(added: true, quantity: $quantity);
    }
}
