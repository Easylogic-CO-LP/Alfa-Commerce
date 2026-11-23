<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

/**
 * What a dispute write did, and why when it did not write: the answer of {@see DisputeLedger::save()}.
 *
 * A caller decides on $kind, and on $code when the kind is refused. A gateway answers its provider with
 * {@see self::providerStatus()} and never decides the status by itself. $message is a sentence for a log or for staff,
 * never parsed and worded freely between releases.
 *
 * The five kinds:
 *   written         the record is written, or already exactly as asked ($existing true when it was found, not made);
 *   refused         a rule refused it ($code says which); for a provider's word core has already kept a notice for staff;
 *   lock_not_taken  another operation on the order held its lock; nothing was written; send it again;
 *   write_failed    the database refused a row, or a failure was caught; nothing was written; send it again;
 *   stale           a provider's word older than the last one applied to the record; nothing was written.
 * A code's value never changes and is never reused within the major version. A later release may add codes, never
 * kinds, so a caller handles a code it does not know as its kind.
 *
 * @api ANSWERS: a read-only value; providerStatus() is the HTTP status a gateway answers its provider with.
 *
 * @since  2.3.0
 */
final class DisputeVerdict
{
    /** @since 2.3.0 */
    public const WRITTEN = 'written';

    /** @since 2.3.0 */
    public const REFUSED = 'refused';

    /** @since 2.3.0 */
    public const LOCK_NOT_TAKEN = 'lock_not_taken';

    /** @since 2.3.0 */
    public const WRITE_FAILED = 'write_failed';

    /** @since 2.3.0 */
    public const STALE = 'stale';

    /**
     * The payment collected nothing a bank can claim back: not a payment row, or not completed or refunded.
     *
     * @since 2.3.0
     */
    public const CODE_NOT_COLLECTED = 'dispute_not_collected';

    /**
     * The payment was paid from the customer's wallet: no bank stands behind it.
     *
     * @since 2.3.0
     */
    public const CODE_WALLET = 'dispute_wallet';

    /**
     * The payment, or the record, is another gateway's.
     *
     * @since 2.3.0
     */
    public const CODE_NOT_OWN = 'dispute_not_own';

    /**
     * The move between the two states is not one a dispute makes.
     *
     * @since 2.3.0
     */
    public const CODE_TRANSITION = 'dispute_transition';

    /**
     * The record is closed: a later dispute is a new case.
     *
     * @since 2.3.0
     */
    public const CODE_CLOSED = 'dispute_closed';

    /**
     * The amount is missing, not a positive decimal, finer than the order's scale, above the payment, or given in the
     * order's currency where only another currency's figure is asked.
     *
     * @since 2.3.0
     */
    public const CODE_AMOUNT = 'dispute_amount';

    /**
     * A call only staff make: a close as recorded in error, the reconcile answer, a reference typed by hand.
     *
     * @since 2.3.0
     */
    public const CODE_STAFF_ONLY = 'dispute_staff_only';

    /**
     * Staff changing a fact of a record that follows its provider (its amount, deadline, reason, reference, fee, link).
     *
     * @since 2.3.0
     */
    public const CODE_FOLLOWS_PROVIDER = 'dispute_follows_provider';

    /**
     * A gateway's word without the provider's dispute id.
     *
     * @since 2.3.0
     */
    public const CODE_NO_REFERENCE = 'dispute_no_reference';

    /**
     * The amount's currency is not an ISO 4217 code.
     *
     * @since 2.3.0
     */
    public const CODE_NO_CURRENCY = 'dispute_no_currency';

    /**
     * The payments ledger refused the booking the decision owes (its reason in the message).
     *
     * @since 2.3.0
     */
    public const CODE_BOOKS_REFUSED = 'dispute_books_refused';

    /**
     * The record's booking counter and its money rows disagree: staff look.
     *
     * @since 2.3.0
     */
    public const CODE_BOOKS_OUT_OF_STEP = 'dispute_books_out_of_step';

    /**
     * A value the chain was given is malformed: a date, a link, a reason, a fee.
     *
     * @since 2.3.0
     */
    public const CODE_VALUE = 'dispute_value';

    /**
     * The record asked for does not exist.
     *
     * @since 2.3.0
     */
    public const CODE_MISSING = 'dispute_missing';

    /**
     * @param string $kind One of the kinds above.
     * @param string $code '' unless refused; otherwise one of the codes above.
     * @param string $message A sentence for a log or for staff, never parsed.
     * @param int $disputeId The record written, found or addressed; 0 when none.
     * @param bool $existing True when the record was found, not made.
     * @param bool $waitsForStaff True when the record waits for a decision of staff before it books money.
     *
     * @since 2.3.0
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $code = '',
        public readonly string $message = '',
        public readonly int $disputeId = 0,
        public readonly bool $existing = false,
        public readonly bool $waitsForStaff = false,
    ) {
    }

    /**
     * The record is written, or already exactly as asked.
     *
     * @since 2.3.0
     */
    public function isWritten(): bool
    {
        return $this->kind === self::WRITTEN;
    }

    /**
     * A rule refused the write; $code says which.
     *
     * @since 2.3.0
     */
    public function isRefused(): bool
    {
        return $this->kind === self::REFUSED;
    }

    /**
     * An older provider word than the one applied: nothing was written.
     *
     * @since 2.3.0
     */
    public function isStale(): bool
    {
        return $this->kind === self::STALE;
    }

    /**
     * The HTTP status a gateway answers its provider with: 200 when the word needs no further delivery (written,
     * refused with core's notice to staff kept, stale), 503 when nothing was written for a reason a later delivery can
     * overcome (lock_not_taken, write_failed), so the provider sends it again.
     *
     * @since 2.3.0
     */
    public function providerStatus(): int
    {
        return \in_array($this->kind, [self::LOCK_NOT_TAKEN, self::WRITE_FAILED], true) ? 503 : 200;
    }
}
