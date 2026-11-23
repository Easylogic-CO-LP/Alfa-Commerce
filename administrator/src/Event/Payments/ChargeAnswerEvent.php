<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * A gateway's answer about a charge of a stored credential: the shared part of the charge
 * (ChargeReferenceEvent) and of the question about an earlier attempt (ChargeQueryEvent).
 *
 * Exactly one answer is kept:
 *
 *   success   the provider holds the charge as taken;
 *   pending   the provider may have taken the money and has not said so;
 *   failure   the provider refused it, or the gateway refused to send it: the attempt is spent, no money moved;
 *             its kind says when a new attempt may follow (DECLINE_*);
 *   not sent  nothing reached the provider for this attempt, no money moved; its reason says what the caller does
 *             (NOT_SENT_*).
 *
 * A later answer replaces an earlier one whole, except that neither a failure nor a not-sent answer ever replaces
 * success or pending: an answer that says money may have moved is never taken back.
 *
 * @since  2.3.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

use InvalidArgumentException;
use Joomla\CMS\Event\AbstractImmutableEvent;

\defined('_JEXEC') or die;

abstract class ChargeAnswerEvent extends AbstractImmutableEvent
{
    /**
     * Outcome: the provider holds the charge as taken.
     *
     * @since  2.0.0
     */
    public const OUTCOME_SUCCESS = 'success';

    /**
     * Outcome: the provider refused the charge, or the gateway refused to send it; the attempt is spent.
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
     * Outcome: nothing reached the provider for this attempt; no money moved and the attempt is not spent.
     *
     * @since  2.3.0
     */
    public const OUTCOME_NOT_SENT = 'not_sent';

    /**
     * A failure's kind: declined for now (insufficient funds, a generic decline); a new attempt may follow later.
     * The kind of a failure given none.
     *
     * @since  2.3.0
     */
    public const DECLINE_NOW = 'now';

    /**
     * A failure's kind: declined for good (a stolen card, a closed account, a revoked mandate, an expired card); no
     * new attempt until the customer gives a new credential.
     *
     * @since  2.3.0
     */
    public const DECLINE_FINAL = 'final';

    /**
     * A failure's kind: the customer must confirm the payment (authentication asked by the bank); no new attempt
     * without the customer.
     *
     * @since  2.3.0
     */
    public const DECLINE_AUTHENTICATE = 'authenticate';

    /**
     * A failure's kind: the customer must give a new credential (the stored one can no longer be charged).
     *
     * @since  2.3.0
     */
    public const DECLINE_NEW_CREDENTIAL = 'new_credential';

    /**
     * A failure's kind: refused by configuration (the method's settings, the currency or its scale, a credential of
     * another credential set, the order's books); no new attempt until staff fix it.
     *
     * @since  2.3.0
     */
    public const DECLINE_CONFIGURATION = 'configuration';

    /**
     * A not-sent answer's reason: the same attempt (the same number) may be sent again (the order's lock was not
     * taken, a rate limit, a connection refused before anything was sent). The reason of a not-sent answer given
     * none.
     *
     * @since  2.3.0
     */
    public const NOT_SENT_RETRY = 'retry';

    /**
     * A not-sent answer's reason: an earlier attempt of this order is still unresolved; send nothing until it is.
     *
     * @since  2.3.0
     */
    public const NOT_SENT_EARLIER_UNRESOLVED = 'earlier_unresolved';

    /**
     * Every failure's kind.
     *
     * @var string[]
     *
     * @since  2.3.0
     */
    public const DECLINES = [
        self::DECLINE_NOW,
        self::DECLINE_FINAL,
        self::DECLINE_AUTHENTICATE,
        self::DECLINE_NEW_CREDENTIAL,
        self::DECLINE_CONFIGURATION,
    ];

    /**
     * Every not-sent answer's reason.
     *
     * @var string[]
     *
     * @since  2.3.0
     */
    public const NOT_SENT_REASONS = [
        self::NOT_SENT_RETRY,
        self::NOT_SENT_EARLIER_UNRESOLVED,
    ];

    /**
     * Report a charge that did not happen: the provider refused it, or the gateway refused to send it. No money
     * moved and the attempt is spent: a new attempt is a new number. Ignored after a success or a pending answer.
     *
     * @param string $error Operator-safe reason (translated, no secrets).
     * @param string $decline When a new attempt may follow, one of the DECLINE_* kinds; DECLINE_NOW when not given.
     *
     * @throws InvalidArgumentException When $decline is not one of the DECLINE_* kinds.
     *
     * @since  2.0.0
     */
    public function setFailure(string $error, string $decline = self::DECLINE_NOW): void
    {
        if (!\in_array($decline, self::DECLINES, true)) {
            throw new InvalidArgumentException(static::class . '::setFailure(): the kind is one of ' . implode(', ', self::DECLINES) . '; got "' . $decline . '".');
        }

        $this->answer(outcome: self::OUTCOME_FAILURE, reference: '', message: $error, kind: $decline);
    }

    /**
     * Report that nothing reached the provider for this attempt: no money moved and the attempt is not spent.
     * Ignored after a success or a pending answer.
     *
     * @param string $reason Operator-safe reason (translated, no secrets).
     * @param string $why What the caller does, one of the NOT_SENT_* reasons: NOT_SENT_RETRY (the same attempt may be
     *                    sent again; the default) or NOT_SENT_EARLIER_UNRESOLVED (send nothing while an earlier
     *                    attempt of the order is unresolved).
     *
     * @throws InvalidArgumentException When $why is not one of the NOT_SENT_* reasons.
     *
     * @since  2.3.0
     */
    public function setNotSent(string $reason, string $why = self::NOT_SENT_RETRY): void
    {
        if (!\in_array($why, self::NOT_SENT_REASONS, true)) {
            throw new InvalidArgumentException(static::class . '::setNotSent(): the reason is one of ' . implode(', ', self::NOT_SENT_REASONS) . '; got "' . $why . '".');
        }

        $this->answer(outcome: self::OUTCOME_NOT_SENT, reference: '', message: $reason, kind: $why);
    }

    /**
     * The gateway's answer: one of the OUTCOME_* constants, '' when it gave none.
     *
     * @since  2.0.0
     */
    public function getOutcome(): string
    {
        return (string) ($this->arguments['outcome'] ?? '');
    }

    /**
     * Whether the provider holds the charge as taken.
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
     * Whether nothing reached the provider for this attempt.
     *
     * @since  2.3.0
     */
    public function isNotSent(): bool
    {
        return $this->getOutcome() === self::OUTCOME_NOT_SENT;
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
     * The reason of a failure or of a not-sent answer ('' otherwise).
     *
     * @since  2.0.0
     */
    public function getError(): string
    {
        return \in_array($this->getOutcome(), [self::OUTCOME_FAILURE, self::OUTCOME_NOT_SENT], true) ? (string) $this->arguments['answerMessage'] : '';
    }

    /**
     * A failure's kind, one of the DECLINE_* constants ('' when the outcome is not a failure).
     *
     * @since  2.3.0
     */
    public function getDecline(): string
    {
        return $this->getOutcome() === self::OUTCOME_FAILURE ? (string) $this->arguments['answerKind'] : '';
    }

    /**
     * A not-sent answer's reason, one of the NOT_SENT_* constants ('' when the outcome is not "not sent").
     *
     * @since  2.3.0
     */
    public function getNotSentReason(): string
    {
        return $this->isNotSent() ? (string) $this->arguments['answerKind'] : '';
    }

    /**
     * Record the gateway's answer; a later answer replaces an earlier one whole, so no field of it survives into the
     * next. The exception: neither a failure nor a not-sent answer replaces an answer that says money may have moved.
     *
     * @param string $outcome One of the OUTCOME_* constants.
     * @param string $reference Transaction id or pending reference.
     * @param string $message Failure or not-sent reason, or pending reason.
     * @param string $kind A failure's kind or a not-sent answer's reason; '' for the others.
     * @param int $paymentId The payment row a success or a pending answer names; 0 when none.
     *
     * @since  2.3.0
     */
    protected function answer(string $outcome, string $reference, string $message, string $kind = '', int $paymentId = 0): void
    {
        if (\in_array($outcome, [self::OUTCOME_FAILURE, self::OUTCOME_NOT_SENT], true)
            && \in_array($this->getOutcome(), [self::OUTCOME_SUCCESS, self::OUTCOME_PENDING], true)) {
            return;
        }

        $this->arguments['outcome'] = $outcome;
        $this->arguments['answerReference'] = $reference;
        $this->arguments['answerMessage'] = $message;
        $this->arguments['answerKind'] = $kind;
        $this->arguments['answerPaymentId'] = $paymentId;
    }
}
