<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Credit;

defined('_JEXEC') or die;

use RuntimeException;

/**
 * The customer credit ledger refused what it was asked to do.
 *
 * Despite its name it is not only about credit notes. It carries every
 * refusal the ledger states as its own decision:
 *
 *   a credit note that cannot be paid into an account: the order has no
 *   account, the account no longer exists or an administrator blocked it, the
 *   note is in another currency or has no positive amount, the note or its
 *   order is gone, the note is voided, already paid in or already paid back,
 *   or it is for more than the order can still give back;
 *
 *   a spend an order cannot take from an account: a guest order, no positive
 *   amount, an order that names no currency or is in another one, or more
 *   than the order still owes;
 *
 *   a debit of more than the account holds.
 *
 * Three readers, three texts. The message is what the logs record: the
 * refusal under the ledger's class name. reason() is the same without the
 * name, the plain words a screen frames and escapes. reasonWithoutHint()
 * leaves out the hint some reasons end with.
 *
 * A reason states a fact. What to do next is said by the sentence it is shown
 * in, which knows whether a number has been drawn. A hint is advice the ledger
 * adds about something else staff can do, and it holds only before numbering:
 * a refusal shown then prints reason(), the reason and its hint. A sentence
 * about a note that is already numbered says itself what to do with that note
 * and prints reasonWithoutHint(), so the ledger's advice can never contradict
 * it. A reason with no hint reads the same through both.
 *
 * Only a refusal is carried here. A failure of the write itself (a database
 * error, a lock timeout) is not a decision about the note and stays a plain
 * RuntimeException: the caller shows its own plain sentence for a failed write
 * and logs the message, never shows it.
 *
 * One refusal can still arrive that way: an account deleted after its check,
 * while the credit waited for the order and document locks, is refused by the
 * write itself. A screen that offers the pay-in again shows the refusal in
 * plain words on that pay-in; a screen without one names only the failure, and
 * the log keeps the reason.
 *
 * @since  1.14.0
 */
final class CreditNoteRefusedException extends RuntimeException
{
    /**
     * @param string $reason The refusal as one plain sentence for staff: the fact alone.
     * @param string $hint What else staff can do, as one plain sentence, for a reason
     *                     that has such advice; it holds only before a number is drawn.
     *
     * @since  1.14.0
     */
    public function __construct(private readonly string $reason, private readonly string $hint = '')
    {
        parent::__construct(message: 'CustomerCreditLedger: ' . $this->reason());
    }

    /**
     * The refusal without the ledger's class name, for a screen to frame and
     * escape: the reason, followed by its hint where it has one. What a
     * refusal before a number is drawn shows.
     *
     *
     * @since  1.14.0
     */
    public function reason(): string
    {
        return $this->hint === '' ? $this->reason : $this->reason . ' ' . $this->hint;
    }

    /**
     * The reason alone, for a sentence about a note that is already numbered:
     * that sentence says what to do with the note, so the hint is left out.
     *
     *
     * @since  1.14.0
     */
    public function reasonWithoutHint(): string
    {
        return $this->reason;
    }
}
