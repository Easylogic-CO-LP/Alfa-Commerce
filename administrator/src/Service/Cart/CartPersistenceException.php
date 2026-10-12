<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

use RuntimeException;
use Throwable;

defined('_JEXEC') or die;

/**
 * A cart write did not complete.
 *
 * This separates the two kinds of failure a caller has to tell apart. An
 * infrastructure failure (the database refused the statement) is never the
 * shopper's fault and never their business: the driver's text names tables,
 * columns and constraints, and on some drivers the failing statement itself.
 * A domain refusal (a quantity the stock cannot cover, a code no rule carries)
 * is a different thing entirely and is not modelled here.
 *
 * So the repository throws this and nothing else. It carries the operation that
 * failed and the cart it concerned, which is what a log entry needs, and it
 * keeps the driver's own message only in the previous exception, where a
 * presentation layer will not reach for it by accident.
 *
 * @since  1.10.0
 */
final class CartPersistenceException extends RuntimeException
{
    /**
     * @var string The repository operation that failed.
     * @since  1.10.0
     */
    private string $operation;

    /**
     * @var int The cart the operation concerned; 0 when it spanned several.
     * @since  1.10.0
     */
    private int $cartId;

    /**
     * @param string $operation The repository method that failed.
     * @param int $cartId The cart concerned, or 0.
     * @param Throwable $previous The driver failure.
     *
     * @since  1.10.0
     */
    public function __construct(string $operation, int $cartId, Throwable $previous)
    {
        $this->operation = $operation;
        $this->cartId = $cartId;

        parent::__construct(
            \sprintf('Cart persistence failed in %s%s.', $operation, $cartId > 0 ? ' for cart #' . $cartId : ''),
            0,
            $previous,
        );
    }

    /**
     * The repository operation that failed.
     *
     *
     * @since  1.10.0
     */
    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * The cart the failed operation concerned; 0 when it spanned several.
     *
     *
     * @since  1.10.0
     */
    public function getCartId(): int
    {
        return $this->cartId;
    }

    /**
     * The detail worth logging: the operation, the cart and the driver's own
     * message. Never show this to a shopper.
     *
     *
     * @since  1.10.0
     */
    public function getLogDetail(): string
    {
        return $this->getMessage() . ' ' . $this->getPrevious()?->getMessage();
    }
}
