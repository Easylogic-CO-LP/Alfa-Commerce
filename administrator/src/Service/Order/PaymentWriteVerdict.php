<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\Money;

/**
 * What a payment write did, and why when it did not write (2.2.2).
 *
 * Answered by the builder's saveWithVerdict() and by the payment plugin's settleAttemptVerdict(),
 * supersedeAttemptVerdict() and claimPendingAttemptVerdict(). A plugin decides on $kind, and on $code when the kind is
 * PaymentLedger::VERDICT_REFUSED; it words the buyer's sentence in its own language strings and logs $message and
 * $figures in its own log category, never showing them to the buyer as core's words.
 *
 * The five kinds:
 *   written         the row is written, or already exactly as asked, or found by its reference ($existing true);
 *   refused         a money or business rule refused it; permanent for the order as it stands ($code says which);
 *   lock_not_taken  another operation on the order held its lock; nothing was written; temporary;
 *   write_failed    the database refused the row, or a failure was caught; nothing was written; temporary or a fault;
 *   raced           the row left the expected status first (an update with an expected status, a claim).
 * A code's value never changes and is never reused in 2.x. A later 2.x release may add codes, never kinds, so a plugin
 * handles a code it does not know as its kind.
 *
 * @api ANSWERS: a read-only value; a code's value never changes and is never reused in 2.x, and a later 2.x may add
 *      codes, never kinds.
 *
 * @since  2.2.2
 */
final class PaymentWriteVerdict
{
    /**
     * @param string $kind One of PaymentLedger::VERDICT_*.
     * @param string $code '' unless refused; otherwise one of PaymentLedger::REFUSAL_*.
     * @param string $message A sentence for the plugin's log only, never parsed and worded freely between releases:
     *                        for refused the sentence core makes for staff and API clients (it names no method and no
     *                        internal id), for lock_not_taken and write_failed a fixed sentence, '' otherwise.
     * @param int $rowId The row written, found or addressed; 0 when none.
     * @param bool $existing True only for the row a create found by its reference.
     * @param array<string, Money> $figures For the plugin's log only: the money a cap refusal compared (amount, total,
     *                                      held, pending, left; for a refund cap amount, total, left); empty otherwise.
     *
     * @since  2.2.2
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $code = '',
        public readonly string $message = '',
        public readonly int $rowId = 0,
        public readonly bool $existing = false,
        public readonly array $figures = [],
    ) {
    }

    /**
     * The row is written, already as asked, or found by its reference.
     *
     * @since  2.2.2
     */
    public function isWritten(): bool
    {
        return $this->kind === PaymentLedger::VERDICT_WRITTEN;
    }

    /**
     * A money or business rule refused the write; $code says which.
     *
     * @since  2.2.2
     */
    public function isRefused(): bool
    {
        return $this->kind === PaymentLedger::VERDICT_REFUSED;
    }

    /**
     * The row left the expected status first: another writer won.
     *
     * @since  2.2.2
     */
    public function isRaced(): bool
    {
        return $this->kind === PaymentLedger::VERDICT_RACED;
    }
}
