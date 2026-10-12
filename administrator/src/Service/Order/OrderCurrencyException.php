<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

defined('_JEXEC') or die;

use RuntimeException;
use Throwable;

/**
 * An order names no currency, so the command that asked is refused.
 *
 * Two readers, two texts. The message is the sentence staff read: it carries
 * no class name and no stored value, so every screen that prints a refusal's
 * message can print this one, escaped as it escapes any other. Why the order
 * names no currency is kept beside it for the log.
 *
 * An order id that has no row is refused with the sentence for an order that
 * was not found: it is not an order waiting for a repair.
 *
 * @since  1.14.0
 */
final class OrderCurrencyException extends RuntimeException
{
    /**
     * @param int $orderId The order that names no currency.
     * @param string $detail Why, in the log's words.
     * @param Throwable|null $previous What failed, if a read did.
     * @param bool $orderExists False when the order has no row.
     *
     * @since  1.14.0
     */
    public function __construct(
        private readonly int $orderId,
        private readonly string $detail,
        ?Throwable $previous = null,
        private readonly bool $orderExists = true,
    ) {
        // The code is the HTTP status a screen that turns an exception into
        // a page answers with: a conflict with the order's state, or no such
        // order.
        parent::__construct(
            message:  $orderExists ? OrderCurrency::refusal() : OrderCurrency::notFound(),
            code:     $orderExists ? 409 : 404,
            previous: $previous,
        );
    }

    /**
     * Whether the order has a row: false when the id names no order at all.
     *
     *
     * @since  1.14.0
     */
    public function orderExists(): bool
    {
        return $this->orderExists;
    }

    /**
     * The order that names no currency.
     *
     *
     * @since  1.14.0
     */
    public function orderId(): int
    {
        return $this->orderId;
    }

    /**
     * Why the order names no currency, as written to the log.
     *
     *
     * @since  1.14.0
     */
    public function detail(): string
    {
        return $this->detail;
    }
}
