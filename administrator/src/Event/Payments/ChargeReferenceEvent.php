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
 * sees amount + currency + the opaque reference it stored earlier, executes
 * the merchant-initiated transaction, and reports the outcome.
 *
 *   caller -> PaymentRequest (this event) -> plugin -> PaymentResult (this event)
 *          -> caller writes the #__alfa_order_payments row
 *          -> the method's status_on_<phase> automation map drives the order.
 *
 * Input:  getOrder() (the order being paid, e.g. a renewal order),
 *         getAmount(), getCurrencyCode(), getReference(), getOperation()
 * Output: exactly ONE of
 *         setSuccess($transactionId)       the provider confirmed the charge;
 *         setFailure($error)               the provider refused it, or it was
 *                                          never sent: no money moved;
 *         setPending($reference, $reason)  the provider may have taken the
 *                                          money and has not confirmed it: the
 *                                          caller records a PENDING row, never
 *                                          charges again, and the gateway's
 *                                          webhook or Reconcile settles it.
 *         A handler that throws, or returns without answering, is NOT a
 *         failure: nobody can say whether the request left, so the caller
 *         books nothing, never charges again and asks an operator to review.
 *         Once an answer says money may have moved (success or pending), a
 *         later setFailure() does not replace it.
 *
 * @since  2.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

use Alfa\Component\Alfa\Site\Service\Order\Order;
use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;

class ChargeReferenceEvent extends AbstractImmutableEvent
{
    /**
     * Outcome: the provider confirmed the charge.
     *
     * @since  2.0.0
     */
    public const OUTCOME_SUCCESS = 'success';

    /**
     * Outcome: the provider refused the charge, or it was never sent.
     *
     * @since  2.0.0
     */
    public const OUTCOME_FAILURE = 'failure';

    /**
     * Outcome: the provider may have taken the money and has not said so.
     *
     * @since  2.0.0
     */
    public const OUTCOME_PENDING = 'pending';

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
     * Operation kind: 'charge' today; room for 'capture'/'void' later
     * without a new event shape.
     *
     * @since  2.0.0
     */
    public function getOperation(): string
    {
        return (string) ($this->arguments['operation'] ?? 'charge');
    }

    /**
     * Report a charge the provider confirmed, for exactly getAmount() in
     * getCurrencyCode().
     *
     * @param string $transactionId The provider's id of the new charge,
     *                              exactly as it gave it. One that cannot be
     *                              booked exactly as sent (see setPending())
     *                              is not booked: the caller asks for review
     *                              at once and never charges again.
     *
     * @since  2.0.0
     */
    public function setSuccess(string $transactionId): void
    {
        $this->answer(outcome: self::OUTCOME_SUCCESS, reference: $transactionId, message: '');
    }

    /**
     * Report a charge that did not happen: the provider refused it, or the
     * plugin refused to send it. No money moved, so the caller may bill the
     * cycle again: dunning retries it, and the renewal order stays owed.
     *
     * Ignored after setSuccess() or setPending(): an answer that says money
     * may have moved is never taken back by a later error.
     *
     * @param string $error Operator-safe reason (translated, no secrets).
     *
     * @since  2.0.0
     */
    public function setFailure(string $error): void
    {
        $this->answer(outcome: self::OUTCOME_FAILURE, reference: '', message: $error);
    }

    /**
     * Report a charge whose outcome is unknown: the provider may have taken
     * the money and has not confirmed it. The caller records a PENDING
     * payment row carrying $reference and never charges the cycle again; the
     * gateway's webhook or Reconcile settles that row through its finaliser.
     *
     * @param string $reference What the webhook and Reconcile match the row by:
     *                          the provider's id of this charge when it gave
     *                          one, else the merchant reference sent with the
     *                          request, exactly as sent (never trimmed or cut).
     *                          One that is empty, carries a control character,
     *                          is not UTF-8, or is longer than the ledger's
     *                          reference column holds cannot be booked exactly
     *                          as sent: the caller then books no row and asks
     *                          for review at once.
     * @param string $reason Operator-safe reason (translated, no secrets).
     *
     * @since  2.0.0
     */
    public function setPending(string $reference, string $reason): void
    {
        $this->answer(outcome: self::OUTCOME_PENDING, reference: $reference, message: $reason);
    }

    /**
     * The plugin's answer: one of the OUTCOME_* constants, '' when it gave
     * none.
     *
     * @since  2.0.0
     */
    public function getOutcome(): string
    {
        return (string) ($this->arguments['outcome'] ?? '');
    }

    /**
     * Whether the provider confirmed the charge.
     *
     * @since  2.0.0
     */
    public function isSuccess(): bool
    {
        return $this->getOutcome() === self::OUTCOME_SUCCESS;
    }

    /**
     * Whether the outcome is unknown and awaits the provider's confirmation.
     *
     * @since  2.0.0
     */
    public function isPending(): bool
    {
        return $this->getOutcome() === self::OUTCOME_PENDING;
    }

    /**
     * The provider's id of a successful charge ('' otherwise).
     *
     * @since  2.0.0
     */
    public function getTransactionId(): string
    {
        return $this->isSuccess() ? (string) $this->arguments['answerReference'] : '';
    }

    /**
     * The reference a pending charge is matched by ('' otherwise).
     *
     * @since  2.0.0
     */
    public function getPendingReference(): string
    {
        return $this->isPending() ? (string) $this->arguments['answerReference'] : '';
    }

    /**
     * Why a pending charge is unconfirmed ('' otherwise).
     *
     * @since  2.0.0
     */
    public function getPendingReason(): string
    {
        return $this->isPending() ? (string) $this->arguments['answerMessage'] : '';
    }

    /**
     * The reason of a failed charge ('' otherwise).
     *
     * @since  2.0.0
     */
    public function getError(): string
    {
        return $this->getOutcome() === self::OUTCOME_FAILURE ? (string) $this->arguments['answerMessage'] : '';
    }

    /**
     * Record the plugin's answer; a later answer replaces an earlier one
     * whole, so no field of it survives into the next. The exception: a
     * failure never replaces an answer that says money may have moved.
     *
     * @param string $outcome One of the OUTCOME_* constants.
     * @param string $reference Transaction id or pending reference.
     * @param string $message Failure error or pending reason.
     *
     * @since  2.0.0
     */
    private function answer(string $outcome, string $reference, string $message): void
    {
        if ($outcome === self::OUTCOME_FAILURE && \in_array($this->getOutcome(), [self::OUTCOME_SUCCESS, self::OUTCOME_PENDING], true)) {
            return;
        }

        $this->arguments['outcome'] = $outcome;
        $this->arguments['answerReference'] = $reference;
        $this->arguments['answerMessage'] = $message;
    }
}
