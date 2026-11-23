<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Charge Stored Reference Event (recurring contract, step 2)
 *
 * A GENERIC payment attempt against a stored credential: the gateway never
 * learns WHY it is charging (renewal, admin re-charge, future capture): it
 * sees amount + currency + the opaque reference it stored earlier, books the
 * attempt's payment row, executes the merchant-initiated transaction, and
 * reports the outcome.
 *
 *   caller -> this event -> gateway: books its pending row first
 *          (PaymentsPlugin::chargeAttempt()), calls the provider, settles its
 *          own row and answers naming it -> the caller reads the answer and
 *          the row it names; the method's status_on_<phase> map drives the
 *          order when the row completes.
 *
 * Input:  getOrder() (the order being paid, e.g. a renewal order),
 *         getAmount(), getCurrencyCode(), getReference(), getOperation(),
 *         getAttempt() (which attempt this is, from 1: the same number sent
 *         again is the same attempt, and finds the same row)
 * Output: exactly ONE of
 *         setSuccess($transactionId, $paymentId)  the provider confirmed the
 *                                          charge; the row it booked;
 *         setPending($reference, $reason, $paymentId)  the provider may have
 *                                          taken the money and has not
 *                                          confirmed it: the row stays
 *                                          pending, the caller never charges
 *                                          that attempt again, and the
 *                                          gateway's webhook or Reconcile
 *                                          settles it;
 *         setFailure($error, $decline)     the provider refused it, or the
 *                                          gateway refused to send it: the
 *                                          attempt is spent, no money moved;
 *                                          the kind says when a new attempt
 *                                          may follow;
 *         setNotSent($reason, $why)        nothing reached the provider: the
 *                                          same attempt may be sent again, or
 *                                          an earlier attempt of the order is
 *                                          unresolved.
 *         A handler that throws, or returns without answering, is NOT a
 *         failure: nobody can say whether the request left, so the caller
 *         never charges again and asks an operator to review. Once an answer
 *         says money may have moved (success or pending), a later failure or
 *         not-sent answer does not replace it.
 *
 * @since  2.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

use Alfa\Component\Alfa\Site\Service\Order\Order;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use InvalidArgumentException;

class ChargeReferenceEvent extends ChargeAnswerEvent
{
    /**
     * The event's name: the gateway's public method of this name handles it, addressed to the gateway of the stored
     * credential alone (AlfaPluginHelper::bootSingle(), declares() and dispatchTo()).
     *
     * @var string
     * @since  2.3.0
     */
    public const NAME = 'onPaymentChargeReference';

    /**
     * The order this charge pays for.
     *
     * @since  2.0.0
     */
    public function getOrder(): Order
    {
        return Order::of($this->arguments['order']);
    }

    /**
     * Amount to charge.
     *
     * MONEY, not float. This used to be `(float) $this->arguments['amount']`,
     * which is the silent degradation itself: hand it a Money and PHP yields
     * 1.0 with only a warning, so an off-session renewal charges one unit of
     * currency instead of the subscription price, unattended, with no buyer
     * watching. A gateway wanting minor units asks ->getMinorUnits().
     *
     * @since  2.0.0
     */
    public function getAmount(): Money
    {
        $amount = $this->arguments['amount'];

        if ($amount instanceof Money) {
            return $amount;
        }

        // A caller that still passes a scalar gets it scaled by the currency
        // it declared, rather than a guessed number of decimals.
        return Money::of((float) $amount, Currency::loadByCode((string) ($this->arguments['currencyCode'] ?? '')));
    }

    /**
     * ISO 4217 alphabetic currency code (e.g. 'EUR').
     *
     * @since  2.0.0
     */
    public function getCurrencyCode(): string
    {
        return (string) $this->arguments['currencyCode'];
    }

    /**
     * The opaque reference the plugin returned from StoreReferenceEvent.
     *
     * @since  2.0.0
     */
    public function getReference(): array
    {
        return (array) $this->arguments['reference'];
    }

    /**
     * The operation the caller asks of the stored reference: 'charge' when it
     * names none.
     *
     * @since  2.0.0
     */
    public function getOperation(): string
    {
        return (string) ($this->arguments['operation'] ?? 'charge');
    }

    /**
     * Which attempt to charge this cycle this is, counted from 1.
     *
     * The dispatcher sets it (core never dispatches this event): 1 for the
     * first attempt, one more for each new attempt after a failure, and the
     * same number again when it sends the same attempt again (after a not-sent
     * answer with NOT_SENT_RETRY). The attempt's row reference is derived from
     * it with the order and the credential (PaymentsPlugin::renewalReference()),
     * so a new attempt is a new row and a new request at the provider, and the
     * same attempt sent again finds its own row. A dispatcher that does not set
     * it dispatches attempt 1.
     *
     * @return int The attempt's number, at least 1.
     *
     * @since  2.2.1
     */
    public function getAttempt(): int
    {
        return (int) ($this->arguments['attempt'] ?? 1);
    }

    /**
     * The 'attempt' argument, checked when the event is built: an integer of
     * at least 1. Anything else is refused, never read as 1.
     *
     * @param mixed $value The dispatcher's value.
     *
     * @return int The attempt's number.
     *
     * @throws InvalidArgumentException When it is not an integer of at least 1.
     *
     * @since  2.2.1
     */
    protected function onSetAttempt(mixed $value): int
    {
        if (!\is_int($value) || $value < 1) {
            throw new InvalidArgumentException('ChargeReferenceEvent: the argument "attempt" is the attempt\'s number, an integer of at least 1; got ' . get_debug_type($value) . '.');
        }

        return $value;
    }

    /**
     * Report a charge the provider confirmed, for exactly getAmount() in getCurrencyCode(), naming the payment row
     * the gateway booked for this attempt (chargeAttempt()) and settled completed.
     *
     * @param string $transactionId The provider's id of the new charge, exactly as it gave it.
     * @param int $paymentId The payment row of this attempt, booked by the gateway before the provider was called.
     *
     * @throws InvalidArgumentException When no row is named (an id under 1).
     *
     * @since  2.0.0
     */
    public function setSuccess(string $transactionId, int $paymentId): void
    {
        $this->answer(outcome: self::OUTCOME_SUCCESS, reference: $transactionId, message: '', paymentId: self::bookedRow($paymentId, 'setSuccess'));
    }

    /**
     * Report a charge whose outcome is unknown: the provider may have taken the money and has not confirmed it. The
     * gateway's row of this attempt stays pending and the caller never charges the attempt again; the gateway's
     * webhook or Reconcile settles the row.
     *
     * @param string $reference What the webhook and Reconcile match the row by: the provider's id of this charge when
     *                          it gave one, else the reference the row was booked with, exactly as sent.
     * @param string $reason Operator-safe reason (translated, no secrets).
     * @param int $paymentId The payment row of this attempt, booked by the gateway before the provider was called.
     *
     * @throws InvalidArgumentException When no row is named (an id under 1).
     *
     * @since  2.0.0
     */
    public function setPending(string $reference, string $reason, int $paymentId): void
    {
        $this->answer(outcome: self::OUTCOME_PENDING, reference: $reference, message: $reason, paymentId: self::bookedRow($paymentId, 'setPending'));
    }

    /**
     * The payment row a success or a pending answer names (0 for any other answer, or none).
     *
     * @since  2.3.0
     */
    public function getPaymentId(): int
    {
        return \in_array($this->getOutcome(), [self::OUTCOME_SUCCESS, self::OUTCOME_PENDING], true) ? (int) $this->arguments['answerPaymentId'] : 0;
    }

    /**
     * The row an answer that says money may have moved must name.
     *
     * @param int $paymentId The id given.
     * @param string $answer The answer's method, for the message.
     *
     * @return int The id.
     *
     * @throws InvalidArgumentException When it is under 1.
     *
     * @since  2.3.0
     */
    private static function bookedRow(int $paymentId, string $answer): int
    {
        if ($paymentId < 1) {
            throw new InvalidArgumentException('ChargeReferenceEvent::' . $answer . '(): name the payment row booked for this attempt (chargeAttempt()); got ' . $paymentId . '.');
        }

        return $paymentId;
    }
}
