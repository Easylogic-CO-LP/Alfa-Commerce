<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The question about an earlier charge attempt: what does the provider hold for it?
 *
 * Asked by the caller of a charge (a subscriptions extension) before it sends again an attempt whose outcome it does
 * not know (the handler threw, or answered nothing). Addressed to the gateway of the stored credential alone, on its
 * public method of this event's name (AlfaPluginHelper::bootSingle(), declares() and dispatchTo()); a gateway without
 * the method cannot be asked, and the caller keeps the attempt for staff.
 *
 * The gateway looks the attempt up at its provider by what it keyed the request on (the order, the credential, the
 * attempt's number and its amount) and answers; it charges nothing and writes nothing.
 *
 * Input:  getOrder(), getReference(), getAttempt(), getAmount(), each required.
 * Output: exactly ONE of
 *         setSuccess($transactionId)       the provider holds the attempt as charged;
 *         setPending($reference, $reason)  it holds it as still unresolved;
 *         setFailure($error, $decline)     it holds it as refused;
 *         setNotSent($reason)              it has no record of it: the same attempt may be sent again.
 *
 * @since  2.3.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

use Alfa\Component\Alfa\Site\Service\Order\Order;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use InvalidArgumentException;

\defined('_JEXEC') or die;

class ChargeQueryEvent extends ChargeAnswerEvent
{
    /**
     * The event's name: the gateway's public method of this name handles it.
     *
     * @var string
     * @since  2.3.0
     */
    public const NAME = 'onPaymentChargeQuery';

    /**
     * The arguments every question carries.
     *
     * @var string[]
     * @since  2.3.0
     */
    private const REQUIRED = ['order', 'reference', 'attempt', 'amount'];

    /**
     * @param string $name The event's name, self::NAME.
     * @param array<string, mixed> $arguments order (the order row or reader), reference (the stored credential),
     *                                        attempt (its number, from 1) and amount (a Money), each required.
     *
     * @throws InvalidArgumentException When an argument is missing or of the wrong kind.
     *
     * @since  2.3.0
     */
    public function __construct(string $name, array $arguments = [])
    {
        foreach (self::REQUIRED as $key) {
            if (!\array_key_exists($key, $arguments)) {
                throw new InvalidArgumentException('ChargeQueryEvent: the argument "' . $key . '" is required (' . implode(', ', self::REQUIRED) . ').');
            }
        }

        parent::__construct($name, $arguments);
    }

    /**
     * The order the attempt paid for.
     *
     * @since  2.3.0
     */
    public function getOrder(): Order
    {
        return Order::of($this->arguments['order']);
    }

    /**
     * The stored credential the attempt charged, as the gateway stored it.
     *
     * @return array<string, mixed>
     *
     * @since  2.3.0
     */
    public function getReference(): array
    {
        return $this->arguments['reference'];
    }

    /**
     * The attempt's number, from 1, as the charge carried it.
     *
     * @since  2.3.0
     */
    public function getAttempt(): int
    {
        return $this->arguments['attempt'];
    }

    /**
     * The attempt's amount, as the charge carried it.
     *
     * @since  2.3.0
     */
    public function getAmount(): Money
    {
        return $this->arguments['amount'];
    }

    /**
     * Report that the provider holds the attempt as charged.
     *
     * @param string $transactionId The provider's id of the charge, exactly as it gives it.
     *
     * @since  2.3.0
     */
    public function setSuccess(string $transactionId): void
    {
        $this->answer(outcome: self::OUTCOME_SUCCESS, reference: $transactionId, message: '');
    }

    /**
     * Report that the provider holds the attempt as still unresolved.
     *
     * @param string $reference What the provider holds it by.
     * @param string $reason Operator-safe reason (translated, no secrets).
     *
     * @since  2.3.0
     */
    public function setPending(string $reference, string $reason): void
    {
        $this->answer(outcome: self::OUTCOME_PENDING, reference: $reference, message: $reason);
    }

    /**
     * The 'order' argument: an order row or reader.
     *
     * @param mixed $value The caller's value.
     *
     * @throws InvalidArgumentException When it is not an object.
     *
     * @since  2.3.0
     */
    protected function onSetOrder(mixed $value): object
    {
        if (!\is_object($value)) {
            throw new InvalidArgumentException('ChargeQueryEvent: the argument "order" is the order row or its reader; got ' . get_debug_type($value) . '.');
        }

        return $value;
    }

    /**
     * The 'reference' argument: the stored credential, an array.
     *
     * @param mixed $value The caller's value.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When it is not an array.
     *
     * @since  2.3.0
     */
    protected function onSetReference(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new InvalidArgumentException('ChargeQueryEvent: the argument "reference" is the stored credential, an array; got ' . get_debug_type($value) . '.');
        }

        return $value;
    }

    /**
     * The 'attempt' argument: an integer of at least 1.
     *
     * @param mixed $value The caller's value.
     *
     * @throws InvalidArgumentException When it is not an integer of at least 1.
     *
     * @since  2.3.0
     */
    protected function onSetAttempt(mixed $value): int
    {
        if (!\is_int($value) || $value < 1) {
            throw new InvalidArgumentException('ChargeQueryEvent: the argument "attempt" is the attempt\'s number, an integer of at least 1; got ' . get_debug_type($value) . '.');
        }

        return $value;
    }

    /**
     * The 'amount' argument: a Money.
     *
     * @param mixed $value The caller's value.
     *
     * @throws InvalidArgumentException When it is not a Money.
     *
     * @since  2.3.0
     */
    protected function onSetAmount(mixed $value): Money
    {
        if (!$value instanceof Money) {
            throw new InvalidArgumentException('ChargeQueryEvent: the argument "amount" is a Money; got ' . get_debug_type($value) . '.');
        }

        return $value;
    }
}
