<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Credit;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MysqlError;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoney;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoneyRefusal;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * CustomerCreditLedger: THE single write path for customer credit.
 *
 * Store credit ("wallet" in customer-facing copy) as an append-only ledger:
 * refund credits, goodwill, compensation, promotions IN; order payments and
 * manual corrections OUT. Balance = SUM(amount); the account row's `balance`
 * column is a cache refreshed under the same lock that serializes writes,
 * mirroring how #__alfa_inventory_stock caches the movements ledger.
 *
 * Concurrency contract: every write locks the user's account row
 * (SELECT ... FOR UPDATE, created on first use), recomputes the balance from
 * the ledger inside the lock, and only then applies the entry: two
 * simultaneous debits cannot both pass the sufficient-balance guard.
 *
 * No entry is ever updated or deleted. A mistake is corrected by a
 * counter-entry, exactly like the payments and inventory ledgers.
 *
 * @since  1.9.0
 */
final class CustomerCreditLedger
{
    /** @var string Credit issued by a credit note (source_id = document id). */
    public const SOURCE_CREDIT_NOTE = 'credit_note';

    /** @var string Credit spent on an order (source_id = order id). */
    public const SOURCE_ORDER = 'order';

    /** @var string Manual admin adjustment, either direction. */
    public const SOURCE_MANUAL = 'manual';

    /** @var string Marketing / promotional grant. */
    public const SOURCE_PROMOTION = 'promotion';

    /**
     * `#__alfa_documents.credit_destination` of a credit note whose money is
     * sent to the customer's credit account. The issuer freezes it on the note
     * with its number; a note that carries it holds what it has not been paid
     * yet against what its order can still give back.
     *
     * @var string
     * @since  1.14.0
     */
    public const CREDIT_NOTE_DESTINATION = 'customer_credit';

    /**
     * What the ceiling's two refusals add to their reason: something else
     * staff can do for the customer. It is advice, and it holds only while no
     * number is drawn. A note that is already numbered for the account waits
     * for its own credit, and credit added by hand beside it would pay the
     * customer twice, so the refusal carries the hint apart from its reason
     * and a sentence about a numbered note leaves it out.
     *
     * @var string
     * @since  1.14.0
     */
    private const GOODWILL_HINT = 'For goodwill unrelated to a refund, add manual credit from the customer profile.';

    /**
     * `#__alfa_documents.status` for a voided document; mirrors
     * DocumentHelper::STATUS_VOIDED, restated here so the ledger's voided
     * check needs no dependency on the documents helper.
     *
     * @var int
     * @since  1.9.0
     */
    private const DOCUMENT_STATUS_VOIDED = 2;

    /**
     * Add credit to a customer's account.
     *
     * @param int $userId The Joomla user id (must be a real account).
     * @param Money|string|int|float $amount POSITIVE amount to credit: a Money in the wallet currency or a
     *                                       decimal text exactly, a float as Money reads one.
     * @param string $sourceType One of the SOURCE_* constants.
     * @param int|null $sourceId PK in the source domain (document/order id).
     * @param string $note Human reason, mandatory for manual entries.
     * @param int|null $actorId Acting admin; null = current identity.
     * @param bool $manageTransaction False to join a caller's open transaction.
     * @param int|null $idOrderPayment The payments-ledger row this movement
     *                                 spends or returns money for. Structural link: the
     *                                 only honest answer to "how much of payment P has
     *                                 gone back", because the note is display text.
     *
     * @return object The inserted transaction row (with fresh balance on ->balance_after).
     *
     * @throws RuntimeException On invalid input or write failure.
     *
     * @since  1.9.0
     */
    public static function credit(
        int $userId,
        Money|string|int|float $amount,
        string $sourceType = self::SOURCE_MANUAL,
        ?int $sourceId = null,
        string $note = '',
        ?int $actorId = null,
        bool $manageTransaction = true,
        ?int $idOrderPayment = null,
    ): object {
        return self::write(
            userId:            $userId,
            amount:            self::walletMoney($amount)->abs(),
            entryType:         'credit',
            sourceType:        $sourceType,
            sourceId:          $sourceId,
            note:              $note,
            actorId:           $actorId,
            manageTransaction: $manageTransaction,
            idOrderPayment:    $idOrderPayment,
        );
    }

    /**
     * Take credit from a customer's account, guarded: the balance can
     * never go negative.
     *
     * @param int $userId The Joomla user id.
     * @param Money|string|int|float $amount POSITIVE amount to deduct (read as credit() reads it).
     * @param string $sourceType One of the SOURCE_* constants.
     * @param int|null $sourceId PK in the source domain.
     * @param string $note Human reason.
     * @param int|null $actorId Acting admin; null = current identity.
     * @param bool $manageTransaction False to join a caller's open transaction.
     * @param int|null $idOrderPayment The payments-ledger row this movement
     *                                 spends or returns money for. Structural link: the
     *                                 only honest answer to "how much of payment P has
     *                                 gone back", because the note is display text.
     *
     * @return object The inserted transaction row.
     *
     * @throws CreditNoteRefusedException When the balance is insufficient: the
     *                                    ledger's refusal, with a plain reason().
     *
     * @since  1.9.0
     */
    public static function debit(
        int $userId,
        Money|string|int|float $amount,
        string $sourceType = self::SOURCE_MANUAL,
        ?int $sourceId = null,
        string $note = '',
        ?int $actorId = null,
        bool $manageTransaction = true,
        ?int $idOrderPayment = null,
    ): object {
        return self::write(
            userId:            $userId,
            amount:            self::walletMoney($amount)->abs()->negate(),
            entryType:         'debit',
            sourceType:        $sourceType,
            sourceId:          $sourceId,
            note:              $note,
            actorId:           $actorId,
            manageTransaction: $manageTransaction,
            idOrderPayment:    $idOrderPayment,
        );
    }

    /**
     * A customer's current balance, from the ledger (authoritative).
     *
     * @param int $userId The Joomla user id.
     *
     *
     * @since  1.9.0
     */
    public static function balance(int $userId): float
    {
        return self::balanceMoney($userId)->getAmount();
    }

    /**
     * A customer's current balance, as wallet money: the ledger's sum read as
     * its decimal text, never through a float.
     *
     * @param int $userId The Joomla user id.
     *
     *
     * @since  1.14.0
     */
    public static function balanceMoney(int $userId): Money
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('COALESCE(SUM(' . $db->quoteName('amount') . '), 0)')
            ->from($db->quoteName('#__alfa_customer_credit_transactions'))
            ->where($db->quoteName('id_user') . ' = :userId')
            ->bind(':userId', $userId);
        $db->setQuery(query: $query);

        return self::walletMoney((string) $db->loadResult());
    }

    /**
     * A customer's ledger, newest first.
     *
     * @param int $userId The Joomla user id.
     * @param int $limit 0 = all.
     *
     *
     * @since  1.9.0
     */
    public static function transactions(int $userId, int $limit = 0): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_customer_credit_transactions'))
            ->where($db->quoteName('id_user') . ' = :userId')
            ->order($db->quoteName('id') . ' DESC')
            ->bind(':userId', $userId);
        // Signature is setQuery($query, $offset = 0, $limit = 0); the named
        // params are offset/limit, and null is not accepted for either.
        $db->setQuery($query, 0, max(0, $limit));

        return $db->loadObjectList() ?: [];
    }

    /**
     * Is this currency something other than the one the shop settles in?
     *
     * The currency contract makes this an identity
     * question and forbids the alternative in as many words: "NO currency
     * guard may rely on the rate's value", and "no reader may treat 1.0 as
     * evidence two arbitrary currencies are compatible (currency compatibility
     * = identity of currency, never rate)".
     *
     * Both wallet guards used to read conversion_rate. Every writer hard-codes
     * that column to 1.0 (on the reference database it is the ONLY value in
     * #__alfa_orders), so neither guard could ever fire, while orders really
     * denominated in another currency existed alongside them. A foreign order
     * could be paid from, or credited to, a wallet holding a different
     * currency, and the balance silently mixed the two.
     *
     * Comparing ids IS comparing currencies: #__alfa_currencies.code carries a
     * UNIQUE key, so one currency is one row.
     *
     * A CURRENCY IS ASKED ABOUT, NEVER THE LACK OF ONE. Every caller names
     * the currency first. A spend reads its order's from the row it has
     * locked, through the one reader of an order's currency, which refuses an
     * order that names none. A credit note's is the one its header froze, or
     * its order's ({@see self::creditNoteCurrencyId()}). An id that is not set
     * is not the wallet's currency and is never taken for it.
     *
     * A SHOP CURRENCY THAT CANNOT BE READ IS NO ANSWER. Taken for "not
     * foreign", it would let a note in another currency into a wallet held in
     * the shop's, so the failure reaches the caller, which decides it:
     * creditNoteRefusal() refuses the note before it is numbered, and
     * applyCreditNote() fails as a write that could not be made. A spend reads
     * the shop's currency for its amount before it asks here.
     *
     * @param int $currencyId The id of a currency row.
     *
     * @return bool True when the currency is not the shop's.
     *
     * @throws RuntimeException When the shop's currency cannot be read.
     *
     * @since  1.10.0
     */
    private static function isForeignCurrency(int $currencyId): bool
    {
        return $currencyId !== \Alfa\Component\Alfa\Site\Service\Pricing\Currency::getDefault()->getId();
    }

    /**
     * May this credit note be paid into the customer's credit account?
     *
     * The one definition of whether a credit note may be paid into an account,
     * shared by every manual credit workflow and by applyCreditNote(), asked
     * before a number is drawn. In order: the order has an account; that
     * account still exists; no administrator has blocked it; the note's
     * currency is the wallet's; the amount is positive at the wallet's scale;
     * the amount is not above what the order can still give back once its
     * notes that wait for their credit are set aside.
     *
     * A note not numbered yet will carry its order's currency, so the order's
     * is the one asked about. An order that names none gives no credit to an
     * account: it is refused in the sentence every refused command on such an
     * order shows, never taken for an order in the wallet's currency.
     *
     * The caller takes the order row lock (SELECT ... FOR UPDATE) as the
     * first read of the transaction that will number the note, the
     * ORDER -> DOCUMENT -> ACCOUNT hierarchy of applyCreditNote(). Under
     * REPEATABLE READ a plain read before the lock pins the snapshot, and the
     * ceiling is then judged on a pool older than the lock.
     *
     * The lock covers the refunds and wallet credits already committed on the
     * order, and every credit note already numbered for the credit account.
     * Such a note is paid in after it is committed, as a step of its own, and
     * that step can wait: the write failed, or the account was blocked
     * meanwhile. Until it is paid in the note holds what it is still owed, so
     * a later note is not numbered on that money and the earlier one left
     * unpayable. What a note is still owed is read each time and stored
     * nowhere ({@see self::creditNoteOwed()}): its total, less what the
     * ledger shows paid in for it, less the refunds settled against it. A
     * voided note holds nothing.
     *
     * The hold is this contract's alone. A refund recorded on the order's
     * payments is judged by the payments ledger, which does not see it, so a
     * refund taken after a note was numbered can still leave less than the
     * note waits for; its credit is then refused when it is paid in.
     *
     * A check that cannot be made is decided here too, once for every caller:
     * when one of these reads fails without refusing (the account cannot be
     * read, say), the account could not be checked, so no note may be numbered
     * on it. The technical message is logged once at WARNING, and the answer is
     * a refusal in plain words. A refusal met while checking is answered like
     * any other, in its own plain words.
     *
     * @param int $userId The order's customer (0 = guest).
     * @param int $orderId The order the note is issued on.
     * @param int $currencyId The id of the currency row the order stores, which the note
     *                        will carry. Where it is not set, the order is asked through
     *                        the one reader of an order's currency.
     * @param string $currencyCode The note's currency code, as a refusal names it.
     * @param float $amount The note's total, tax included.
     *
     * @return string|null The refusal in plain words for staff, without the
     *                     ledger's class name, which the caller frames and
     *                     escapes; null when the account can take the note.
     *                     Nothing is numbered yet, so the ceiling's refusal is
     *                     followed by its hint. applyCreditNote() throws the
     *                     same refusal as a CreditNoteRefusedException, whose
     *                     message carries the prefix 'CustomerCreditLedger: '
     *                     that the logs record and whose reasonWithoutHint()
     *                     is the reason alone.
     *
     * @throws DocumentRefusedException When the account could not be checked:
     *                                  its operator text says so in plain words,
     *                                  and its message is the technical one.
     *
     * @since  1.14.0
     */
    public static function creditNoteRefusal(int $userId, int $orderId, int $currencyId, string $currencyCode, float $amount): ?string
    {
        try {
            return self::accountRefusal(userId: $userId, orderId: $orderId, currencyId: $currencyId, currencyCode: $currencyCode, amount: $amount, header: null)
                ?? self::ceilingRefusal(
                    refundable: \Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger::refundableNow(orderId: $orderId),
                    amount:     $amount,
                    orderId:    $orderId,
                    noteId:     0,
                )?->reason();
        } catch (CreditNoteRefusedException $e) {
            return $e->reason();
        } catch (Throwable $e) {
            // Non-fatal by contract, as the ledger's other lines: the refusal
            // below is what stops the note, whether or not the line lands.
            try {
                self::ensureLogger();
                Log::add(
                    entry:    sprintf('Order %d: the customer credit account could not be checked before numbering a credit note: %s', $orderId, $e->getMessage()),
                    priority: Log::WARNING,
                    category: 'com_alfa.credit',
                );
            } catch (Throwable) {
                // Swallowed: the refusal still stands.
            }

            throw new DocumentRefusedException(
                message:  'CustomerCreditLedger: the customer credit account could not be checked: ' . $e->getMessage(),
                key:      'COM_ALFA_CREDIT_ACCOUNT_CHECK_FAILED',
                previous: $e,
            );
        }
    }

    /**
     * The account half of creditNoteRefusal(): account, its existence, an
     * administrator's block, currency and a positive amount, in that order.
     *
     * Only an administrator's block is refused: credit paid into that account
     * is out of its customer's reach. The platform also blocks a new account
     * until it is activated, and that customer can spend the credit once
     * activated, so an account awaiting activation passes: one that carries an
     * activation code and has never signed in or asked for a password reset.
     * The code alone does not say so. A password reset keeps its token in the
     * same column, and neither a block nor an unblock clears the column. A
     * blocked account can neither sign in nor ask for a reset, so one that has
     * done either was active once and was blocked afterwards.
     *
     * The block is read here and nowhere else in the ledger. The ledger's own
     * write asks only that the account exists, so credit already in an
     * account that was blocked afterwards is still taken back when its note is
     * voided, and a spend on its order can still be made and undone.
     *
     * @param int $userId The order's customer (0 = guest).
     * @param int $orderId The note's order.
     * @param int $currencyId The id of the currency row the note's header froze, or
     *                        the one its order stores for a note not numbered yet.
     * @param string $currencyCode The note's currency code, as the refusal names it.
     * @param string|float $amount The note's total, tax included: its column's text.
     * @param object|null $header The numbered note's row (id_currency, currency_code,
     *                            currency_scale, id_order); null for a note that is
     *                            not numbered yet.
     *
     * @return string|null The refusal as a plain sentence, without the class
     *                     name, or null when the account can take the note.
     *
     * @throws CreditNoteRefusedException When the note's currency is its order's
     *                                    and the order names none.
     *
     * @since  1.14.0
     */
    private static function accountRefusal(int $userId, int $orderId, int $currencyId, string $currencyCode, string|float $amount, ?object $header): ?string
    {
        if ($userId <= 0) {
            return 'a guest order has no credit account.';
        }

        // "Must be a real account", enforced: a deleted customer's order
        // still carries its id_user snapshot; crediting it strands money in
        // a wallet nobody can ever reach.
        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            'SELECT ' . $db->quoteName('block') . ', ' . $db->quoteName('activation')
            . ', ' . $db->quoteName('lastvisitDate') . ', ' . $db->quoteName('lastResetTime')
            . ' FROM ' . $db->quoteName('#__users')
            . ' WHERE ' . $db->quoteName('id') . ' = ' . $userId,
        );
        $account = $db->loadObject();

        // The fact alone, with the number that finds the record. What to do
        // about it depends on whether the note is numbered, which the screen
        // showing this knows and the ledger does not.
        if ($account === null) {
            return "the customer's account (user #{$userId}) no longer exists.";
        }

        // A blocked account passes only while it awaits activation: a code,
        // never signed in, never asked for a password reset. No code is an
        // empty column or 0; the platform reads both as activated.
        $awaitingActivation = !\in_array((string) $account->activation, ['', '0'], true)
            && $account->lastvisitDate === null
            && $account->lastResetTime === null;

        if ((int) $account->block === 1 && !$awaitingActivation) {
            return 'the customer account is blocked; return the money another way, or unblock the account first.';
        }

        // The ledger is a single-currency (shop default) balance. Until a
        // conversion policy exists, refusing a foreign-currency document is
        // the only answer that cannot be silently wrong by an FX rate.
        if (self::isForeignCurrency(currencyId: self::creditNoteCurrencyId(orderId: $orderId, currencyId: $currencyId, header: $header))) {
            return 'the credit note is in a non-default currency ('
                . $currencyCode
                . '); customer credit in a foreign currency is not supported yet.';
        }

        // The guard above has just proved this document is in the wallet's
        // currency, which is what makes the wallet's scale the right one to
        // ask. Money against zero, never a tolerance: one minor unit of a
        // credit note is money the customer is owed.
        if (!self::walletMoney(amount: $amount)->isPositive()) {
            return 'the credit note has no positive amount.';
        }

        return null;
    }

    /**
     * The currency a credit note is in, as the id of its currency row.
     *
     * A credit note carries its order's currency. A numbered note froze it on
     * its header and is read as the document reader reads it
     * ({@see \Alfa\Component\Alfa\Administrator\Service\Document\DocumentMoney::currencyOf()}):
     * the currency row the header names; on a header that names none, the
     * currency code it froze beside it, which is how a document older than
     * that column states its currency; and on a header that froze neither,
     * its order's. A note that is not numbered yet has no header: the caller
     * hands in the id its order stores, and where that is not set the order
     * is asked through the one reader of an order's currency.
     *
     * No currency is put in the place of one that is not named. An order that
     * names none gives no credit to an account: the refusal is the sentence
     * every refused command on such an order shows, carried as the ledger's
     * refusal. An order that has no row is not this method's to refuse: the
     * reader's own answer leaves as it is.
     *
     * @param int $orderId The note's order.
     * @param int $currencyId The id the header froze, or the one the order stores
     *                        for a note not numbered yet.
     * @param object|null $header The numbered note's row; null for a note that is not
     *                            numbered yet.
     *
     * @return int The id of a currency row.
     *
     * @throws CreditNoteRefusedException When the note's currency is its order's
     *                                    and the order names none.
     *
     * @since  1.14.0
     */
    private static function creditNoteCurrencyId(int $orderId, int $currencyId, ?object $header): int
    {
        if ($currencyId > 0) {
            return $currencyId;
        }

        try {
            return $header !== null
                ? \Alfa\Component\Alfa\Administrator\Service\Document\DocumentMoney::currencyOf(document: $header)->getId()
                : \Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency::of(orderId: $orderId)->getId();
        } catch (\Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException $e) {
            if ($e->orderExists()) {
                throw new CreditNoteRefusedException(reason: $e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * The ceiling half of creditNoteRefusal(): the note may not be more than
     * the order can still give back once the other notes that wait for their
     * credit are set aside.
     *
     * @param \Alfa\Component\Alfa\Site\Service\Pricing\Money $refundable What the order can still give back.
     * @param string|float $amount The note's total, tax included: its column's text.
     * @param int $orderId The order the note is issued on.
     * @param int $noteId The note being paid in, which does not
     *                    wait on itself; 0 for a note not
     *                    numbered yet.
     *
     * @return CreditNoteRefusedException|null The refusal, built and not thrown, or
     *                                         null. Its reason names the note's amount and what
     *                                         the order can still give back to it; when a void of
     *                                         the order's other waiting notes would make room for
     *                                         the note, also what the order keeps for them and
     *                                         their numbers. Its hint is carried apart from the
     *                                         reason.
     *
     * @since  1.14.0
     */
    private static function ceilingRefusal(\Alfa\Component\Alfa\Site\Service\Pricing\Money $refundable, string|float $amount, int $orderId, int $noteId): ?CreditNoteRefusedException
    {
        // Money against Money, in the order's own scale; the 0.01 this
        // replaced was a whole unit on a JPY order and ten times coarser
        // than an OMR order's smallest unit.
        $wanted = \Alfa\Component\Alfa\Site\Service\Pricing\Money::of(amount: $amount, currency: $refundable->getCurrency());

        // What the order's other notes still wait for is theirs. It is set
        // aside on top of what the order can still give back, which itself
        // counts only money that has left.
        $waiting = self::waitingCreditNotes(orderId: $orderId, currency: $refundable->getCurrency(), exceptNoteId: $noteId);
        $free = $refundable->subtract(other: $waiting->held)->nonNegative();

        if (!$wanted->greaterThan(other: $free)) {
            return null;
        }

        // What the order keeps for the other notes is never more than it has:
        // a refund recorded on its payments can leave it less than they wait
        // for. The sentence says what is left for this note, what the order
        // keeps for the others and which they are, so its two figures add up
        // to what the order can still give back. Only a void frees it: paying
        // a waiting note in takes the same money.
        $setAside = $refundable->subtract(other: $free);

        // The other notes are named only when a void of them makes room for
        // this one: the note fits what the order can still give back in all.
        // A note that is, alone, for more than that is refused whatever the
        // others hold, and naming them would send staff to void a valid note
        // and still pay nothing.
        if ($setAside->isPositive() && !$wanted->greaterThan(other: $refundable)) {
            return new CreditNoteRefusedException(
                reason: sprintf(
                    '%1$s exceeds what this order can still give back (%2$s); '
                    . 'a further %3$s is held for credit notes issued to customer credit and not yet paid in (%4$s), '
                    . 'and is freed only if such a note is voided.',
                    $wanted->format(),
                    $free->format(),
                    $setAside->format(),
                    implode(', ', $waiting->numbers),
                ),
                hint:   self::GOODWILL_HINT,
            );
        }

        // No void of another note would make room: nothing is kept for one,
        // or this note is for more than the order has. The sentence states
        // the note's own two figures and does not say a void would help.
        return new CreditNoteRefusedException(
            reason: sprintf(
                '%s exceeds what this order can still give back (%s); '
                . 'money already refunded via payments cannot also become customer credit.',
                $wanted->format(),
                $refundable->format(),
            ),
            hint:   self::GOODWILL_HINT,
        );
    }

    /**
     * What an order's credit notes still wait to be paid into the credit
     * account.
     *
     * A note waits when the issuer froze the credit account as its destination
     * and it is not voided. It waits for what it is still owed
     * ({@see self::creditNoteOwed()}): its total less what the ledger shows
     * paid in for it and less the refunds settled against it, the note's own
     * ceiling, as applyCreditNote() and the payments ledger judge it. Nothing
     * of it is stored, so a note paid in or settled by a refund waits for
     * nothing whichever screen or retry paid it, and a note numbered before
     * destinations were frozen never waits.
     *
     * Three reads however many notes the order has: the notes, then what the
     * account holds for them and what refunds settled against them, each for
     * all of them at once. One read when no note of the order is frozen.
     *
     * @param int $orderId The order.
     * @param \Alfa\Component\Alfa\Site\Service\Pricing\Currency $currency The currency of what the order can still give back.
     * @param int $exceptNoteId A note left out: the one being paid in (0 = none).
     *
     * @return object {held: Money, numbers: string[]} What the waiting notes
     *                                                 hold together, and their numbers in the order they were
     *                                                 issued.
     *
     * @since  1.14.0
     */
    private static function waitingCreditNotes(int $orderId, \Alfa\Component\Alfa\Site\Service\Pricing\Currency $currency, int $exceptNoteId): object
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            'SELECT ' . $db->quoteName('id') . ', ' . $db->quoteName('number_formatted') . ', ' . $db->quoteName('total_tax_incl')
            . ' FROM ' . $db->quoteName('#__alfa_documents')
            . ' WHERE ' . $db->quoteName('id_order') . ' = ' . $orderId
            . ' AND ' . $db->quoteName('credit_destination') . ' = ' . $db->quote(self::CREDIT_NOTE_DESTINATION)
            . ' AND ' . $db->quoteName('status') . ' <> ' . self::DOCUMENT_STATUS_VOIDED
            . ' AND ' . $db->quoteName('id') . ' <> ' . $exceptNoteId
            . ' ORDER BY ' . $db->quoteName('id') . ' ASC',
        );

        $notes = $db->loadObjectList('id') ?: [];
        $owed = self::owedOnCreditNotes(
            totals:   array_map(static fn (object $note): string => (string) $note->total_tax_incl, $notes),
            currency: $currency,
        );

        $held = \Alfa\Component\Alfa\Site\Service\Pricing\Money::zero(currency: $currency);
        $numbers = [];

        foreach ($notes as $id => $note) {
            if ($owed[$id]->isPositive()) {
                $held = $held->add(other: $owed[$id]);
                $numbers[] = (string) $note->number_formatted;
            }
        }

        return (object) ['held' => $held, 'numbers' => $numbers];
    }

    /**
     * What a credit note is still owed.
     *
     * Its total, less what the credit account holds for it, less the refunds
     * settled against it, and never less than nothing. It is the figure a
     * note waiting for its credit holds on its order, answered here for one
     * note, so a screen that shows or retries a note reads the definition the
     * hold is made of. A voided note is owed nothing, and so is a note that
     * no longer exists. What the note was frozen with is not asked: a note
     * sent to another destination is owed its money all the same, it only
     * holds nothing on its order.
     *
     * A note is paid into the account whole or not at all. One that is owed
     * less than its total can therefore no longer take that credit, which
     * applyCreditNote() refuses as already paid in part; what is left of it
     * goes back by a refund that names it.
     *
     * A plain read, for display and for choosing the note to pay in.
     * applyCreditNote() judges the note again under its locks.
     *
     * @param int $documentId The credit note.
     *
     * @return \Alfa\Component\Alfa\Site\Service\Pricing\Money What the note is still owed, in its
     *                                                         order's currency; for a note that
     *                                                         does not exist, nothing, as money of
     *                                                         the credit account.
     *
     * @throws \Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException When the note's order names no currency.
     *
     * @since  1.14.0
     */
    public static function creditNoteOwed(int $documentId): \Alfa\Component\Alfa\Site\Service\Pricing\Money
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            'SELECT ' . $db->quoteName('id_order') . ', ' . $db->quoteName('status') . ', ' . $db->quoteName('total_tax_incl')
            . ' FROM ' . $db->quoteName('#__alfa_documents')
            . ' WHERE ' . $db->quoteName('id') . ' = ' . $documentId,
        );
        $note = $db->loadObject();

        if ($note === null) {
            return self::walletMoney(amount: 0.0);
        }

        $currency = \Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency::of(orderId: (int) $note->id_order);

        if ((int) $note->status === self::DOCUMENT_STATUS_VOIDED) {
            return \Alfa\Component\Alfa\Site\Service\Pricing\Money::zero(currency: $currency);
        }

        return self::owedOnCreditNotes(totals: [$documentId => (string) $note->total_tax_incl], currency: $currency)[$documentId];
    }

    /**
     * What each of some credit notes of one order is still owed.
     *
     * The one arithmetic of a note's remainder, for creditNoteOwed() and for
     * the hold of waitingCreditNotes(): the note's total, less what the
     * credit account holds for it, less the refunds settled against it, never
     * less than nothing. Two reads however many notes are asked, one over the
     * account's entries and one over the settled refunds. Each figure is
     * stated as creditNoteNet() and PaymentLedger::settledRefundForDocument()
     * state it for one note. A note's own ceiling also counts its refunds
     * still pending (PaymentLedger::refundsHoldingDocument()): such a refund
     * holds the note without paying it, so the note is still owed what it
     * waits for while a pay-in of it is refused.
     *
     * @param array<int, string> $totals The notes' totals, tax included, by document id, as their columns' text.
     * @param \Alfa\Component\Alfa\Site\Service\Pricing\Currency $currency The currency of the notes' order.
     *
     * @return array<int, \Alfa\Component\Alfa\Site\Service\Pricing\Money> What each note is still owed, by document id.
     *
     * @since  1.14.0
     */
    private static function owedOnCreditNotes(array $totals, \Alfa\Component\Alfa\Site\Service\Pricing\Currency $currency): array
    {
        if ($totals === []) {
            return [];
        }

        $ids = array_map('intval', array_keys($totals));
        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            'SELECT ' . $db->quoteName('source_id') . ', COALESCE(SUM(' . $db->quoteName('amount') . '), 0) AS ' . $db->quoteName('net')
            . ' FROM ' . $db->quoteName('#__alfa_customer_credit_transactions')
            . ' WHERE ' . $db->quoteName('source_type') . ' = ' . $db->quote(self::SOURCE_CREDIT_NOTE)
            . ' AND ' . $db->quoteName('source_id') . ' IN (' . implode(', ', $ids) . ')'
            . ' GROUP BY ' . $db->quoteName('source_id'),
        );
        $paidIn = $db->loadAssocList('source_id', 'net') ?: [];
        $settled = \Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger::settledRefundsForDocuments(documentIds: $ids);
        $owed = [];

        foreach ($totals as $id => $total) {
            // What the account holds for it and what refunds settled, added
            // as the order's money.
            $paid = \Alfa\Component\Alfa\Site\Service\Pricing\Money::of(amount: (string) ($paidIn[$id] ?? '0'), currency: $currency)
                ->add(other: \Alfa\Component\Alfa\Site\Service\Pricing\Money::of(amount: (float) ($settled[$id] ?? 0.0), currency: $currency)->nonNegative());

            $owed[(int) $id] = \Alfa\Component\Alfa\Site\Service\Pricing\Money::of(amount: (string) $total, currency: $currency)
                ->subtract(other: $paid)
                ->nonNegative();
        }

        return $owed;
    }

    /**
     * Apply a credit note's amount to the buyer's credit account.
     *
     * The seam the issue flow calls when the admin chose "customer credit"
     * as the destination. Guest orders have no account to credit; the
     * caller must offer the choice only when the order has a real user.
     *
     * @param object $document The issued credit document row.
     * @param int $userId The order's customer (> 0).
     * @param int|null $actorId Acting admin.
     *
     * @return object The credit transaction.
     *
     * @throws CreditNoteRefusedException When the ledger refuses the note, asked in
     *                                    this order: a guest order, an account that
     *                                    no longer exists, an account an
     *                                    administrator blocked, a foreign currency
     *                                    (or, for a note whose header froze none, an
     *                                    order that names no currency),
     *                                    no positive amount, the order or the note
     *                                    gone, a voided note, a note already in a
     *                                    wallet, a note already paid in part or in
     *                                    full, or more than the order can still give
     *                                    back once its other waiting notes are set
     *                                    aside. The note's own reasons come before
     *                                    the order's, so a note that cannot take its
     *                                    credit is told why, never what another note
     *                                    holds. reason() is the plain sentence for
     *                                    staff; the message adds the class name. The
     *                                    note is numbered when this runs, so a screen
     *                                    prints reasonWithoutHint(), which leaves the
     *                                    ceiling's hint out.
     * @throws RuntimeException When the write itself fails: a database
     *                          error, a lock timeout, or an account deleted
     *                          while the credit waited for its locks. It
     *                          decides nothing about the note, so the caller
     *                          shows its own plain sentence for a failed
     *                          write and logs the message. A screen that
     *                          offers a wallet retry shows any refusal in
     *                          plain words on that retry; a screen without
     *                          one names only the failure, and the log
     *                          keeps the reason.
     *
     * @since  1.9.0
     */
    public static function applyCreditNote(object $document, int $userId, ?int $actorId = null): object
    {
        // The account half of creditNoteRefusal(), before the transaction as
        // always; the ceiling half is asked again under the locks below. Every
        // refusal here is a CreditNoteRefusedException: its message keeps the
        // ledger's prefix for the logs, its reason() is the plain sentence a
        // screen shows when this runs after the note is committed.
        $refusal = self::accountRefusal(
            userId:       $userId,
            orderId:      (int) $document->id_order,
            currencyId:   (int) ($document->id_currency ?? 0),
            currencyCode: (string) ($document->currency_code ?? '?'),
            amount:       (string) $document->total_tax_incl,
            header:       $document,
        );

        if ($refusal !== null) {
            throw new CreditNoteRefusedException(reason: $refusal);
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $amount = (string) $document->total_tax_incl;

        // One document, one wallet credit
        // A credit note's money may reach the wallet at most once, and that
        // is a LEDGER invariant, not a caller's responsibility: every entry
        // point (the order screen's issue action, plg_alfa_returns' issue and
        // its wallet retry, anything written later) would otherwise have to
        // re-implement the same check, and any one of them getting it wrong
        // mints money.
        //
        // WHY THE DOCUMENT ROW IS THE LOCK. The obvious move, FOR UPDATE on
        // the (source_type, source_id) rows themselves, is wrong when no
        // such row exists yet: InnoDB has nothing to lock and takes a GAP
        // lock instead, so two concurrent writers both acquire it and then
        // deadlock on each other's insert intention. Measured, not guessed:
        // the first version of this guard produced "Deadlock found when
        // trying to get lock" rather than a refusal. The document row always
        // exists, so locking it is a plain row lock that ORDERS the two
        // writers: the loser waits, then reads the winner's committed entry
        // and is refused by name.
        //
        // Note this hole only opens when the order has SPARE refundable
        // headroom: with exactly enough for one credit, the refundableNow
        // ceiling below already refuses the second. On a €100 order with a
        // €10 note there is room for ten, which is the case this guards.
        //
        // credit() joins THIS transaction (manageTransaction: false); its
        // own START TRANSACTION would implicitly commit ours, which is the
        // trap write() documents.
        $db->transactionStart(true);

        try {
            // ORDER LOCK FIRST: the pool's serialization row
            // The refundable-pool ceiling below is the same check-then-act
            // PaymentLedger::create() closes, and it must be closed with the
            // SAME row in the SAME order or the two just race each other:
            // without this, a gateway refund and this wallet credit both read
            // a full pool and both take it, paying the customer twice.
            //
            // ORDER BEFORE DOCUMENT, always. The document row is locked
            // immediately below, and DocumentIssuer::void() takes both, so
            // if either path took them the other way round they would
            // deadlock instead of queueing. ORDER -> DOCUMENT -> ACCOUNT is the
            // one hierarchy every money path in the component follows.
            //
            // Taking it FIRST also fixes what the reads afterwards can see:
            // under REPEATABLE READ the first consistent read pins the
            // snapshot, so a pool computed before the serialization point was
            // acquired would be judged against a pre-lock view of the world.
            // A locking read bypasses the snapshot, so everything read after
            // it is current.
            $db->setQuery(
                'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__alfa_orders')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $document->id_order
                . ' FOR UPDATE',
            );

            if ((int) $db->loadResult() !== (int) $document->id_order) {
                throw new CreditNoteRefusedException(reason: 'the credit note\'s order no longer exists.');
            }

            $db->setQuery(
                'SELECT ' . $db->quoteName('status') . ' FROM ' . $db->quoteName('#__alfa_documents')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $document->id
                . ' FOR UPDATE',
            );
            $liveStatus = $db->loadResult();

            // A VOIDED note must never create wallet liability, and the
            // status has to be re-read HERE, under this lock: a caller that
            // checked it beforehand raced DocumentIssuer::void, which locks
            // the same row. Lose that race and the void's clawback has
            // already run against a zero net, so the credit that lands
            // afterwards can never be taken back: money minted permanently.
            if ($liveStatus === null) {
                throw new CreditNoteRefusedException(reason: 'the credit note no longer exists.');
            }

            if ((int) $liveStatus === self::DOCUMENT_STATUS_VOIDED) {
                throw new CreditNoteRefusedException(reason: sprintf(
                    'credit note %s has been voided; its money cannot be paid into the customer\'s credit account.',
                    (string) $document->number_formatted,
                ));
            }

            // BOTH refund directions must be closed. The payments-side guard
            // subtracts wallet credits from what a gateway may refund; this is
            // the mirror: a credit note wallet-credited AFTER a gateway refund
            // would double-pay the customer (€100 cash back AND €100 wallet).
            //
            // READ INSIDE THE LOCK. Read before transactionStart (as it was)
            // this is a promise about a number nobody is holding still: two
            // credit notes on one order both saw the whole pool and both
            // minted wallet money beyond what was ever collected. The order
            // row acquired above is what makes it a decision instead.
            $refundable = \Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger::refundableNow((int) $document->id_order);

            // THE NOTE'S OWN ANSWERS COME BEFORE THE ORDER'S. A note already
            // in the account, or already paid in part or in full, cannot take
            // this credit whatever the order still holds, and that is the
            // reason staff are given. Asked after the order's ceiling, such a
            // note was told what the order keeps for its OTHER notes and that
            // a void of one frees it: a void that changes nothing for this
            // note and loses a valid document.
            $db->setQuery(
                'SELECT COALESCE(SUM(' . $db->quoteName('amount') . '), 0)'
                . ' FROM ' . $db->quoteName('#__alfa_customer_credit_transactions')
                . ' WHERE ' . $db->quoteName('source_type') . ' = ' . $db->quote(self::SOURCE_CREDIT_NOTE)
                . ' AND ' . $db->quoteName('source_id') . ' = ' . (int) $document->id,
            );

            if (self::walletMoney((string) $db->loadResult())->isPositive()) {
                throw new CreditNoteRefusedException(reason: sprintf(
                    'credit note %s has already been paid into the customer\'s credit account; '
                    . 'its money cannot be paid in twice.',
                    (string) $document->number_formatted,
                ));
            }

            // ORDER MATTERS: the same-rail idempotency guard above runs FIRST.
            // A note already credited to the wallet is a DUPLICATE, and saying
            // so is both more precise and what wallet-idempotency-e2e asserts;
            // this ceiling is the outer rule that catches what that guard
            // cannot see: the OTHER rail. Placing it first made it pre-empt
            // the specific message with a general one.
            // AND THE NOTE'S OWN CEILING: the true mirror
            // The order's check is ORDER-WIDE, and an order is usually worth more
            // than any one note, so it cannot see a note paid twice: with spare
            // headroom a 50.00 note refunded 50.00 to the card and then
            // credited 50.00 to the wallet passes every order-level test while
            // the customer keeps 100.00 for a 50.00 return. Measured before
            // this guard existed: both rails together paid 100.00 on a 50.00
            // note with 130.00 still in the pool.
            //
            // PaymentLedger::create() already enforces exactly this ceiling on
            // its side, in the same words: "A credit note is its own promise
            // ... The two rails are not alternatives to be policed by
            // exclusion; they are two ways of paying one debt", and the
            // wallet side only ever counted its OWN rail. Same formula, one
            // definition of what the note has given
            // (PaymentLedger::refundsHoldingDocument(): a refund still pending
            // holds its amount), so the two can never disagree about one note.
            $notePaid = \Alfa\Component\Alfa\Site\Service\Pricing\Money::of(ExactAmount::ofMoney(self::creditNoteNetMoney((int) $document->id))->text(), $refundable->getCurrency())
                ->add(\Alfa\Component\Alfa\Site\Service\Pricing\Money::of(
                    \Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger::refundsHoldingDocument(
                        (int) $document->id,
                    ),
                    $refundable->getCurrency(),
                ));

            $noteCeiling = \Alfa\Component\Alfa\Site\Service\Pricing\Money::of(
                (string) $document->total_tax_incl,
                $refundable->getCurrency(),
            );
            $noteWanted = $notePaid->add(\Alfa\Component\Alfa\Site\Service\Pricing\Money::of($amount, $refundable->getCurrency()));

            if ($noteWanted->greaterThan($noteCeiling)) {
                throw new CreditNoteRefusedException(reason: sprintf(
                    'credit note %s has already been paid %s of %s through the customer\'s credit account'
                    . ' and refunds recorded against it; paying in %s more would pay it twice.',
                    (string) $document->number_formatted,
                    $notePaid->format(),
                    $noteCeiling->format(),
                    \Alfa\Component\Alfa\Site\Service\Pricing\Money::of($amount, $refundable->getCurrency())->format(),
                ));
            }

            // The order's ceiling, last
            // Only a note that can still take its credit is measured against
            // what the order can give back. What the order's other notes
            // still wait for is left to them; this note's own wait is not
            // counted against it.
            $refusal = self::ceilingRefusal(
                refundable: $refundable,
                amount:     $amount,
                orderId:    (int) $document->id_order,
                noteId:     (int) $document->id,
            );

            if ($refusal !== null) {
                throw $refusal;
            }

            $entry = self::credit(
                userId:            $userId,
                amount:            $amount,
                sourceType:        self::SOURCE_CREDIT_NOTE,
                sourceId:          (int) $document->id,
                note:              'Credit note ' . (string) $document->number_formatted,
                actorId:           $actorId,
                manageTransaction: false,
            );
        } catch (Throwable $e) {
            TransactionState::rollBackLevel($db, $e);

            throw $e;
        }

        // After the try: a COMMIT that fails is closed by commitLevel() itself,
        // and reaches the caller.
        TransactionState::commitLevel($db);

        self::logCommitted($entry);

        return $entry;
    }

    /**
     * The locked write path every entry goes through.
     *
     * @param int $userId The Joomla user id.
     * @param Money $amount SIGNED wallet money (credits +, debits -).
     * @param string $entryType 'credit' | 'debit'.
     * @param string $sourceType Source domain.
     * @param int|null $sourceId Source PK.
     * @param string $note Human reason.
     * @param int|null $actorId Acting admin.
     *
     * @return object The inserted row, with ->balance_after attached.
     *
     * @throws CreditNoteRefusedException When a debit is more than the account
     *                                    holds. It is the ledger's decision, not
     *                                    a failed write: reason() is the plain
     *                                    sentence, the message adds the ledger's
     *                                    name, and it is not wrapped again.
     * @throws RuntimeException When the entry cannot be written.
     *
     * @since  1.9.0
     */
    private static function write(
        int $userId,
        Money $amount,
        string $entryType,
        string $sourceType,
        ?int $sourceId,
        string $note,
        ?int $actorId,
        bool $manageTransaction = true,
        ?int $idOrderPayment = null,
    ): object {
        if ($userId <= 0) {
            throw new RuntimeException('CustomerCreditLedger: a credit entry needs a real user id.');
        }

        // Wallet money is money in the wallet currency, at its scale: a figure
        // finer than one minor unit is not money anyone can be offered or spend.
        $amount = self::walletMoney($amount);

        // EXACTLY zero, at the wallet's own scale, nothing wider. A sub-minor
        // figure has already been rounded away by walletMoney() above, so what
        // is left is either money or nothing at all.
        if ($amount->isZero()) {
            throw new RuntimeException('CustomerCreditLedger: a credit entry needs a non-zero amount.');
        }

        if ($sourceType === self::SOURCE_MANUAL && trim($note) === '') {
            throw new RuntimeException('CustomerCreditLedger: a manual entry requires a reason.');
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // Every entry, not just credit notes: money minted for a nonexistent
        // id creates an orphan wallet no one can ever see or spend.
        $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__users')
            . ' WHERE ' . $db->quoteName('id') . ' = ' . $userId,
        );

        if ((int) $db->loadResult() === 0) {
            throw new RuntimeException("CustomerCreditLedger: user #{$userId} does not exist.");
        }

        $now = Factory::getDate('now', 'UTC')->toSql();
        $actorId ??= self::currentUserId();

        // NEVER call with $manageTransaction=true while holding a transaction:
        // MySQL's START TRANSACTION implicitly COMMITS it, and a later caller
        // rollback would silently undo nothing. A caller inside its own
        // envelope (DocumentIssuer::void) passes false and owns the outcome.
        if ($manageTransaction) {
            $db->transactionStart(true);
        }

        try {
            // 1. The account row is the lock. First write creates it.
            $db->setQuery(
                'SELECT * FROM ' . $db->quoteName('#__alfa_customer_credit_accounts')
                . ' WHERE ' . $db->quoteName('id_user') . ' = ' . $userId . ' FOR UPDATE',
            );
            $account = $db->loadObject();

            if ($account === null) {
                $fresh = new stdClass();
                $fresh->id_user = $userId;
                $fresh->balance = StoredDecimal::of(self::walletMoney(0));
                $fresh->created = $now;
                // A concurrent first-write loses on the UNIQUE key; then the
                // re-read takes the lock the winner released at commit. ONLY
                // duplicate-key may fall through: a deadlock (1213) rolls the
                // whole transaction back server-side, and continuing would run
                // every following statement UNLOCKED in autocommit: the
                // double-spend this lock exists to prevent.
                try {
                    $db->insertObject('#__alfa_customer_credit_accounts', $fresh, 'id');
                } catch (Throwable $e) {
                    if (MysqlError::number($e) !== 1062) {
                        throw $e;
                    }
                }

                $db->setQuery(
                    'SELECT * FROM ' . $db->quoteName('#__alfa_customer_credit_accounts')
                    . ' WHERE ' . $db->quoteName('id_user') . ' = ' . $userId . ' FOR UPDATE',
                );
                $account = $db->loadObject();

                if ($account === null) {
                    throw new RuntimeException('could not create the credit account row.');
                }
            }

            // 2. Balance from the LEDGER, inside the lock
            // LOCKING read: under REPEATABLE READ a plain SELECT returns the
            // transaction's own snapshot, which is stale for a caller that
            // opened its transaction before another spend committed: two
            // concurrent spends on different orders would both pass. A
            // locking read sees the latest committed rows.
            // LOCK IN SHARE MODE, not FOR SHARE: the latter is MySQL 8 only
            // and is a syntax error on MariaDB (verified on 10.6).
            $db->setQuery(
                'SELECT COALESCE(SUM(' . $db->quoteName('amount') . '), 0)'
                . ' FROM ' . $db->quoteName('#__alfa_customer_credit_transactions')
                . ' WHERE ' . $db->quoteName('id_user') . ' = ' . $userId
                . ' LOCK IN SHARE MODE',
            );
            $balance = self::walletMoney((string) $db->loadResult());

            // The balance after this entry, added in the wallet's minor units.
            $balanceAfter = $balance->add($amount);

            if ($amount->isNegative() && $balanceAfter->isNegative()) {
                throw new CreditNoteRefusedException(reason: sprintf(
                    'insufficient credit: balance %s, requested %s.',
                    $balance->format(false),
                    $amount->negate()->format(false),
                ));
            }

            // 3. Append the entry
            $row = new stdClass();
            $row->id_user = $userId;
            $row->entry_type = $entryType;
            $row->amount = StoredDecimal::of($amount);
            $row->source_type = $sourceType;
            $row->source_id = $sourceId ?: null;
            // The STRUCTURAL link. Everything that asks "how much of payment
            // P has gone back?" reads this, never the note; the note is
            // display text and two writers spell it differently.
            $row->id_order_payment = $idOrderPayment ?: null;
            $row->note = mb_substr(trim($note), 0, 500);
            $row->created = $now;
            $row->created_by = $actorId;
            $db->insertObject('#__alfa_customer_credit_transactions', $row, 'id');

            // 4. Refresh the cache under the same lock
            $update = new stdClass();
            $update->id = (int) $account->id;
            $update->balance = StoredDecimal::of($balanceAfter);
            $update->modified = $now;
            $db->updateObject('#__alfa_customer_credit_accounts', $update, 'id');
        } catch (Throwable $e) {
            if ($manageTransaction) {
                TransactionState::rollBackLevel($db, $e);
            }

            // The ledger's own refusal is already in plain words, and its
            // message already carries the ledger's name: it leaves as it is.
            if ($e instanceof CreditNoteRefusedException) {
                throw $e;
            }

            throw new RuntimeException('CustomerCreditLedger: write failed: ' . $e->getMessage(), 0, $e);
        }

        if ($manageTransaction) {
            TransactionState::commitLevel($db);
        }

        // Non-fatal by contract: a broken log writer must never make a credit
        // entry look failed to the caller. POST-COMMIT only when this call owns
        // the transaction. With manageTransaction false the entry is still
        // inside the caller's open transaction, which may yet roll back, so
        // the line says STAGED and claims nothing durable (the owner writes the
        // committed line after its own COMMIT, through logCommitted() or, for
        // DocumentIssuer::void, its own reversal line). A file line is not
        // transactional and must never state a success a rollback can undo.
        try {
            self::ensureLogger();
            Log::add(
                // At the wallet currency's own scale. A log that prints a 10.005
                // movement as 10.01 is how a two-decimal assumption gets
                // re-derived by the next reader.
                entry:    sprintf(
                    $manageTransaction
                        ? 'Customer credit %s: user %d, %s (%s #%s), balance %s'
                        : 'Customer credit %s staged, not yet committed (it lands only if the caller\'s transaction commits): '
                          . 'user %d, %s (%s #%s), balance %s once committed',
                    $entryType,
                    $userId,
                    $amount->format(false),
                    $sourceType,
                    (string) ($sourceId ?? '-'),
                    $balanceAfter->format(false),
                ),
                priority: Log::INFO,
                category: 'com_alfa.credit',
            );
        } catch (Throwable $e) {
            // Swallowed: the ledger row is the audit trail that matters.
        }

        $row->balance_after = $balanceAfter->getAmount();

        return $row;
    }

    /**
     * The committed line for an entry written with manageTransaction false,
     * logged by the transaction's owner after its COMMIT.
     *
     * write() says STAGED when the caller holds the transaction, because the
     * caller may yet roll it back. The owner that has just committed states the
     * outcome here, in the same shape, so the log keeps one success line per
     * durable entry and none for an entry a rollback undid. The owners are
     * applyCreditNote(), spendOnOrder(), PaymentLedger::create(), update() and
     * updateIfStatus() (the rows settleWalletForPayment() wrote), and
     * OrderModel::delete() (the wallet returns of a deleted order). Call it
     * after the COMMIT and never on the rollback path.
     *
     * @param object $entry The row write() returned (carries balance_after).
     *
     *
     * @since  1.14.0
     */
    public static function logCommitted(object $entry): void
    {
        // Non-fatal by contract, as in write(): the ledger row is the record.
        try {
            self::ensureLogger();
            Log::add(
                entry:    sprintf(
                    'Customer credit %s committed: user %d, %s (%s #%s), balance %s',
                    (string) $entry->entry_type,
                    (int) $entry->id_user,
                    self::walletMoney((string) $entry->amount)->format(false),
                    (string) $entry->source_type,
                    (string) ($entry->source_id ?? '-'),
                    self::walletMoney($entry->balance_after)->format(false),
                ),
                priority: Log::INFO,
                category: 'com_alfa.credit',
            );
        } catch (Throwable $e) {
            // Swallowed: the ledger row is the audit trail that matters.
        }
    }

    /**
     * Pay (part of) an order from the customer's credit balance: the
     * order-side SPEND. Two rows move in lockstep:
     *
     *   ledger  -amount  source order #id   (guarded: balance, never negative)
     *   payment +amount  method "Customer credit", completed,
     *                    transaction_id 'credit:<ledger row id>'
     *
     * The debit lands first (it carries the guards); if the payment row is
     * then refused, a compensating credit restores the balance and the
     * operation fails loudly: the two sides can never drift apart silently.
     *
     * The amount must fit BOTH the balance and the order's outstanding
     * remainder (grand total minus collected payments): wallet money is a
     * payment, never an overpayment.
     *
     * The order's currency is the one its own row names, read under the order
     * lock through the one reader of an order's currency, at the scale the
     * order froze: the ruler the payments ledger measures the same order on.
     * The shop's currency is never put in its place. An order that names no
     * currency is paid nothing from a credit account, and one in another
     * currency than the wallet's is refused as it always was.
     *
     * @param object $order The loaded #__alfa_orders row.
     * @param float $amount POSITIVE amount to apply.
     * @param int|null $actorId Acting admin.
     *
     * @return object {entry: ledger row, payment_id: int}
     *
     * @throws CreditNoteRefusedException When the ledger refuses the spend: a
     *                                    guest order; no positive amount; an order
     *                                    that names no currency, in the sentence
     *                                    every refused command on such an order
     *                                    shows, or that is in another currency
     *                                    than the wallet's; more than the order
     *                                    still owes; more than the account holds.
     *                                    reason() is the plain sentence a screen
     *                                    shows, the message adds the ledger's name
     *                                    for the log.
     * @throws RuntimeException When the spend itself fails: the order is
     *                          gone, or a row could not be written.
     *
     * @since  1.9.0
     */
    public static function spendOnOrder(object $order, Money|string|int|float $amount, ?int $actorId = null, float $grandTotalHint = 0.0): object
    {
        $userId = (int) ($order->id_user ?? 0);
        // The spend as the exact decimal of its wallet money: the debit, the
        // payment row and the order's ruler below each read it as written.
        $amount = ExactAmount::ofMoney(self::walletMoney($amount)->abs())->text();

        if ($userId <= 0) {
            throw new CreditNoteRefusedException(reason: 'a guest order cannot be paid from a credit account.');
        }

        if (!self::walletMoney($amount)->isPositive()) {
            throw new CreditNoteRefusedException(reason: 'a positive amount is required.');
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // The hint mirrors PaymentLedger's grandTotalHint: at checkout the
        // shipment row does not exist yet, so the DB recomputation is short
        // by shipping; max() keeps this guard from rejecting a spend the
        // placement flow resolved from the in-memory cart total.
        $grand = max(
            \Alfa\Component\Alfa\Administrator\Helper\OrderTotalHelper::getOrderTotal((int) $order->id),
            $grandTotalHint,
        );

        // ONE transaction, anchored on the ORDER row
        // The outstanding check, the wallet debit and the payment insert all
        // sit inside a SELECT ... FOR UPDATE on the order, the pattern
        // DocumentIssuer::lockOrder already uses to serialize per-order money
        // ops. Without it the account lock is released at the debit's commit
        // and PaymentLedger's overpayment guard (an unlocked read-then-
        // insert) lets two concurrent spends both pass, overpaying the order
        // and draining the wallet twice.
        // Atomicity also replaces the old compensating-credit dance: a
        // refused payment row now rolls the debit back with it.
        $db->transactionStart(true);

        try {
            $db->setQuery(
                'SELECT ' . $db->quoteName('id') . ', ' . $db->quoteName('id_currency') . ', ' . $db->quoteName('currency_scale')
                . ' FROM ' . $db->quoteName('#__alfa_orders')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $order->id . ' FOR UPDATE',
            );
            $lockedOrder = $db->loadObject();

            if ($lockedOrder === null || (int) $lockedOrder->id <= 0) {
                throw new RuntimeException('order #' . (int) $order->id . ' does not exist.');
            }

            // The order's own currency, at the scale the order froze, answered
            // from the row this transaction has just locked: nothing of the
            // caller's copy of the order decides here. It is resolved first,
            // because the single-currency rule, the payment builder's hint and
            // the outstanding below are all stated in it. An order that names
            // no currency is paid nothing from a credit account. The refusal
            // is the sentence every refused command on such an order shows,
            // carried as the ledger's refusal, so a screen shows its reason()
            // and the log keeps the ledger's name.
            try {
                $currency = \Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency::ofRow(
                    orderId:    (int) $lockedOrder->id,
                    currencyId: $lockedOrder->id_currency,
                    scale:      $lockedOrder->currency_scale,
                );
            } catch (\Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException $e) {
                throw new CreditNoteRefusedException(reason: $e->getMessage());
            }

            // Same single-currency rule as applyCreditNote, asked of the
            // currency just read. The words are the ones this refusal has
            // always carried.
            if (self::isForeignCurrency(currencyId: $currency->getId())) {
                throw new CreditNoteRefusedException(reason: 'the order is in a non-default currency; credit spending is not supported yet.');
            }

            // The payment builder reads ITS overpayment hint from a Money object
            // on the order; a raw DB row has none (#__alfa_orders carries no
            // money columns): forward our hint, in the order's own currency,
            // so the two guards agree.
            if ($grandTotalHint > 0.0
                && (!isset($order->total_paid_tax_incl) || !is_object($order->total_paid_tax_incl))) {
                $order->total_paid_tax_incl = \Alfa\Component\Alfa\Site\Service\Pricing\Money::of($grandTotalHint, $currency);
            }

            // SAME basis as PaymentLedger's overpayment guard: everything not
            // failed/cancelled/refunded counts: a full-amount PENDING bank
            // transfer means nothing is outstanding for credit to cover, or
            // the order would overpay the moment that transfer completes.
            // Read at once: the connection is shared, and a statement run
            // between this one and its result would be the one read.
            $db->setQuery(
                'SELECT COALESCE(SUM(ABS(' . $db->quoteName('amount') . ')), 0)'
                . ' FROM ' . $db->quoteName('#__alfa_order_payments')
                . ' WHERE ' . $db->quoteName('id_order') . ' = ' . (int) $order->id
                . ' AND ' . \Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger::livePaymentPredicate(),
            );
            $collected = (string) $db->loadResult();

            // MONEY AT THE ORDER'S SCALE, not a hard-coded 0.01 and not a
            // hard-coded six decimals. The outstanding and the wanted spend are
            // measured on the SAME ruler (the order's own), so "more than is
            // owed" is an exact statement. A coarser ruler than the currency's
            // turns one minor unit into nothing to cover and refuses the spend
            // as an overpayment: round(..., 6) is not finer than every currency,
            // it is ten times coarser at scale 7 and a hundred times at scale 8.
            // The spend is read on its digits: one finer than the order's
            // money (an order kept at fewer places than the wallet now is) is
            // refused by name, never rounded.
            try {
                $wanted = ExactMoney::of(value: $amount, currency: $currency);
            } catch (ExactMoneyRefusal $e) {
                throw new CreditNoteRefusedException(reason: sprintf('order #%d: %s Nothing was written.', (int) $order->id, $e->getMessage()));
            }

            $coverable = \Alfa\Component\Alfa\Site\Service\Pricing\Money::of($grand, $currency)
                ->subtract(\Alfa\Component\Alfa\Site\Service\Pricing\Money::of($collected, $currency))
                ->nonNegative();

            if ($wanted->greaterThan($coverable)) {
                throw new CreditNoteRefusedException(reason: sprintf(
                    '%s exceeds the order\'s outstanding %s; customer credit is a payment, never an overpayment.',
                    $wanted->format(false),
                    $coverable->format(false),
                ));
            }

            $entry = self::debit(
                userId:            $userId,
                amount:            $amount,
                sourceType:        self::SOURCE_ORDER,
                sourceId:          (int) $order->id,
                note:              'Order #' . (string) ($order->order_number ?? $order->id) . ' payment',
                actorId:           $actorId,
                manageTransaction: false,
            );

            // BORN PENDING, then flipped below. A row born 'completed' is
            // exactly what the platform contract forbids (Standard.php:
            // "it saves fine and counts in the totals, but grants nothing and
            // moves no order status"); create() never runs the side effects,
            // so a fully-credit-paid order would never transition and a
            // digital one would never grant its downloads.
            // The order's OWN payment method rides the row (id_payment_method
            // from for($order)): applyStatusAutomation bails on method 0, and
            // that method's status_on_completed map is what must run.
            $paymentId = \Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger::for($order)
                ->amount($amount)
                ->pending()
                ->set('payment_method', 'Customer credit')
                // No gateway took this money. The row carries the ORDER'S
                // method id so status automation runs, so without this it
                // would claim whichever gateway that method names.
                ->set('payment_method_type', 'credit')
                // THE STRUCTURAL LINK. transaction_id keeps the readable
                // 'credit:<id>' spelling for support and for the logs, but it
                // is an ADMIN-EDITABLE column and must not be the key: the
                // refundable-pool query used to recover this id with a SQL
                // SUBSTRING over that text, so retyping a transaction
                // reference silently moved how much the order could refund.
                ->set('id_credit_transaction', (int) $entry->id)
                ->set('transaction_id', 'credit:' . (int) $entry->id)
                // Support snapshot: "why did my wallet use X?" answerable from
                // the payment row alone, no ledger archaeology.
                ->set('gateway_response', json_encode([
                    'credit_transaction_id' => (int) $entry->id,
                    'balance_before' => self::walletMoney($entry->balance_after)->add(self::walletMoney($amount))->getAmount(),
                    'balance_after' => (float) $entry->balance_after,
                ]))
                ->save();

            if (empty($paymentId)) {
                throw new RuntimeException('the payments ledger refused the row.');
            }

            // BACK-FILL THE STRUCTURAL LINK. The debit had to happen before
            // the payment row existed (the row's transaction_id names the
            // entry), so the entry cannot carry the payment id at insert
            // time. Stamping it here (still inside this transaction, so it
            // commits with the debit or not at all) is what lets
            // PaymentLedger answer "how much of payment P has gone back?"
            // as a SIGNED SUM over id_order_payment instead of a LIKE over
            // note text that two writers spell differently.
            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__alfa_customer_credit_transactions')
                . ' SET ' . $db->quoteName('id_order_payment') . ' = ' . (int) $paymentId
                . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $entry->id,
            )->execute();
        } catch (Throwable $e) {
            TransactionState::rollBackLevel($db, $e);

            // The ledger's own refusal is already in plain words, and its
            // message already carries the ledger's name: it leaves as it is.
            if ($e instanceof CreditNoteRefusedException) {
                throw $e;
            }

            throw new RuntimeException('CustomerCreditLedger: spend failed: ' . $e->getMessage(), 0, $e);
        }

        TransactionState::commitLevel($db);

        self::logCommitted($entry);

        // POST-COMMIT: flip to completed so the side effects fire
        // Deliberately outside the lock (DocumentIssuer does its stock effect
        // post-commit for the same reason): the UPDATE path runs entitlements
        // and the method's status_on_completed transition, which dispatches to
        // plugins: third-party code opening its own transaction would
        // implicitly commit ours.
        try {
            // The cap re-check on the UPDATE path measures against the DB
            // grand total, which at checkout is short by shipping (the
            // shipment row is written after us). Park the same figure the
            // create path used, and always clear it.
            \Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger::setUpdateGrandTotalHint($grand);

            try {
                $flipped = \Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger::load((int) $paymentId)
                    ->completed()
                    ->save();
            } finally {
                \Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger::setUpdateGrandTotalHint(0.0);
            }

            // save() reports a REFUSED write by returning false; it does not
            // throw. Without this the unwind below never armed and the order
            // wedged silently: wallet debited, row stuck pending (blocking
            // the gateway's row) while amountToCollect still read the full
            // total, and the caller reported success.
            if (empty($flipped)) {
                throw new RuntimeException('the payments ledger refused the completion (see its warning log).');
            }
        } catch (Throwable $e) {
            // The money is committed but the row is still pending: it blocks
            // the gateway's own row (the overpayment guard counts pending)
            // while amountToCollect() still reads the full total. Unwind
            // rather than leave the order wedged.
            self::ensureLogger();
            Log::add(
                sprintf(
                    'Order #%d: customer-credit payment #%d could not be completed (%s), unwinding.',
                    (int) $order->id,
                    (int) $paymentId,
                    $e->getMessage(),
                ),
                Log::CRITICAL,
                'com_alfa.credit',
            );

            try {
                $db->setQuery(
                    'UPDATE ' . $db->quoteName('#__alfa_order_payments')
                    . ' SET ' . $db->quoteName('transaction_status') . ' = ' . $db->quote(\Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger::STATUS_CANCELLED)
                    . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $paymentId,
                )->execute();

                // idOrderPayment: this unwind is a RETURN of that payment's
                // money, and without the link it was invisible to every
                // "already returned" question: a third writer whose note
                // shape nothing else recognised. With it, reviving the
                // cancelled row computes target == actual and returns
                // nothing a second time.
                self::credit(
                    userId:         $userId,
                    amount:         $amount,
                    sourceType:     self::SOURCE_ORDER,
                    sourceId:       (int) $order->id,
                    note:           'Reversal: payment could not be completed for order #' . (string) ($order->order_number ?? $order->id),
                    actorId:        $actorId,
                    idOrderPayment: (int) $paymentId,
                );
            } catch (Throwable $inner) {
                Log::add(
                    sprintf('Order #%d: UNWIND FAILED for credit payment #%d: %s', (int) $order->id, (int) $paymentId, $inner->getMessage()),
                    Log::CRITICAL,
                    'com_alfa.credit',
                );
            }

            throw new RuntimeException('CustomerCreditLedger: the credit payment could not be completed; the debit has been reversed.');
        }

        $entry->payment_id = (int) $paymentId;

        return (object) ['entry' => $entry, 'payment_id' => (int) $paymentId];
    }

    /**
     * The wallet money each customer still has applied to these orders, net.
     *
     * Read from the ledger, which is the truth; payment rows only mirror it.
     * Every movement a wallet makes FOR an order is written with source
     * `order` and that order's id, so their signed sum is exactly what the
     * order still holds of each wallet:
     *
     *   spend (spendOnOrder)                                  -
     *   its unwind when the payment row cannot complete       +
     *   a return: a refund to the wallet, a cancelled,
     *     failed or refunded payment (PaymentLedger)          +
     *   a clawback of a return when the payment revives       -
     *   an earlier deletion's return (OrderModel)             +
     *
     * Not counted: `manual` and `promotion` entries name no order, and a
     * credit note's `credit_note` entries are money the order GAVE, keyed by
     * the document; an order that has documents cannot be deleted
     * (OrderModel::ordersWithDocuments()), so none can meet this read.
     *
     * A net at or above zero holds nothing: the wallet has had everything
     * back, and more is never taken from it here. A LOCKING read: the caller
     * locks the orders first, so a refund settling its wallet leg (which
     * locks them too) is either fully in this sum or waits for the caller.
     *
     * @param int[] $orderIds The orders.
     *
     * @return array<int, array<int, \Alfa\Component\Alfa\Site\Service\Pricing\Money>>
     *                                                                                 [orderId => [userId => money still held]], positive amounts only.
     *
     * @since  1.14.0
     */
    public static function heldByOrders(array $orderIds): array
    {
        $orderIds = array_values(array_filter(array_map('intval', $orderIds)));

        if ($orderIds === []) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            'SELECT ' . $db->quoteName('source_id') . ', ' . $db->quoteName('id_user')
            . ', SUM(' . $db->quoteName('amount') . ') AS ' . $db->quoteName('net')
            . ' FROM ' . $db->quoteName('#__alfa_customer_credit_transactions')
            . ' WHERE ' . $db->quoteName('source_type') . ' = ' . $db->quote(self::SOURCE_ORDER)
            . ' AND ' . $db->quoteName('source_id') . ' IN (' . implode(',', $orderIds) . ')'
            . ' GROUP BY ' . $db->quoteName('source_id') . ', ' . $db->quoteName('id_user')
            // LOCK IN SHARE MODE, not FOR SHARE: the latter is MySQL 8 only
            // and a syntax error on MariaDB.
            . ' ORDER BY ' . $db->quoteName('source_id') . ', ' . $db->quoteName('id_user')
            . ' LOCK IN SHARE MODE',
        );

        $held = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $net = self::walletMoney((string) $row->net);

            if ($net->isNegative()) {
                $held[(int) $row->source_id][(int) $row->id_user] = $net->negate();
            }
        }

        return $held;
    }

    /**
     * The order's wallet movements: credits granted by its credit notes and
     * debits spent on it. Read-only: feeds the payments panel's
     * informational rows and PaymentLedger's settled-refund subtraction; the
     * DB stays truthful (no fake payment rows), the UI combines.
     *
     * @param int $orderId The order id.
     *
     * @return array Ledger rows, oldest first.
     *
     * @since  1.9.0
     */
    public static function forOrder(int $orderId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('t.*')
            ->from($db->quoteName('#__alfa_customer_credit_transactions', 't'))
            ->leftJoin(
                $db->quoteName('#__alfa_documents', 'd')
                . ' ON d.id = t.source_id AND t.source_type = ' . $db->quote(self::SOURCE_CREDIT_NOTE),
            )
            ->where('(' . $db->quoteName('d.id_order') . ' = :orderIdA'
                . ' OR (' . $db->quoteName('t.source_type') . ' = ' . $db->quote(self::SOURCE_ORDER)
                . ' AND ' . $db->quoteName('t.source_id') . ' = :orderIdB))')
            ->order($db->quoteName('t.id') . ' ASC')
            ->bind(':orderIdA', $orderId)
            ->bind(':orderIdB', $orderId);
        $db->setQuery(query: $query);

        return $db->loadObjectList() ?: [];
    }

    /**
     * Register the ledger's file logger once per request; without this,
     * Joomla silently DROPS every entry for an unregistered category.
     *
     * @since  1.9.0
     */
    public static function ensureLogger(): void
    {
        // Delegated: AlfaLog owns the whole map. Kept as an entry point for
        // requests that never boot com_alfa.
        // No guard here either: AlfaLog::register() is idempotent, and a
        // guard would go on claiming "done" after a Log::setInstance()
        // wiped the registry it was remembering.
        AlfaLog::register();
    }

    /**
     * An amount of wallet money, at the wallet currency's own scale.
     *
     * The wallet holds the shop default currency and nothing else (see
     * isForeignCurrency()), so every entry, balance and spend is money in that
     * currency, rounded through the one monetary rounding primitive, not to a
     * hard-coded six decimals that belong to no currency and that the caps, the
     * order screen and the checkout never agreed with.
     *
     * @param Money|string|int|float $amount The amount.
     *
     * @return float The amount, expressible in the wallet currency.
     *
     * @since  1.10.0
     */
    private static function toWalletScale(Money|string|int|float $amount): float
    {
        return self::walletMoney($amount)->getAmount();
    }

    /**
     * An amount of wallet money, as Money, in the currency the wallet is
     * denominated in.
     *
     * THE ONE PLACE THAT NAMES THAT CURRENCY. The wallet holds the shop
     * settlement currency and nothing else (see isForeignCurrency()), so every
     * guard that decides whether a figure is money asks this rather than a
     * fixed tolerance. A fixed tolerance encodes a scale it never declares:
     * 0.000001 is one hundred minor units of an 8-decimal currency, and
     * refusing them makes money this ledger can store and settle unusable.
     * One minor unit is money.
     *
     * A Money in the wallet currency is read on its exact digits at the
     * wallet's own scale (an order's frozen scale may differ from it): one
     * finer than the wallet's money is refused by name, never rounded, as the
     * payments ledger refuses the same case. One in another currency is
     * refused; a column's text is read as written, never through a float.
     *
     * @param Money|string|int|float $amount The amount.
     *
     * @return \Alfa\Component\Alfa\Site\Service\Pricing\Money The amount as wallet money.
     *
     * @throws RuntimeException When a Money in another currency, or one finer than the wallet's money, is handed in.
     *
     * @since  1.10.0
     */
    private static function walletMoney(Money|string|int|float $amount): \Alfa\Component\Alfa\Site\Service\Pricing\Money
    {
        $wallet = \Alfa\Component\Alfa\Site\Service\Pricing\Currency::getDefault();

        if ($amount instanceof Money) {
            if (!$amount->getCurrency()->equals($wallet)) {
                throw new RuntimeException(sprintf(
                    'CustomerCreditLedger: %s is not wallet money; the wallet holds %s.',
                    $amount->getCurrency()->getCode(),
                    $wallet->getCode(),
                ));
            }

            try {
                return ExactMoney::of(value: ExactAmount::ofMoney($amount)->text(), currency: $wallet);
            } catch (ExactMoneyRefusal $e) {
                throw new RuntimeException('CustomerCreditLedger: wallet money ' . $e->getMessage() . ' Nothing was written.', 0, $e);
            }
        }

        return \Alfa\Component\Alfa\Site\Service\Pricing\Money::of($amount, $wallet);
    }

    /**
     * Net ledger amount a credit note produced (0.0 = no wallet leg).
     *
     * @param int $documentId The credit document id.
     *
     * @return float
     *
     * @since  1.9.0
     */
    /**
     * The wallet leg of a credit note, as money in the wallet's own currency.
     *
     * The guards ask this rather than comparing the float against a fixed
     * tolerance: 0.000001 is a hundred minor units of an 8-decimal currency,
     * so a fixed figure decides a different question at every scale. The
     * wallet names its own currency in exactly one place and this borrows it.
     *
     * @param int $documentId Credit document id.
     *
     *
     * @since  1.13.0
     */
    public static function creditNoteNetMoney(int $documentId): \Alfa\Component\Alfa\Site\Service\Pricing\Money
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('COALESCE(SUM(' . $db->quoteName('amount') . '), 0)')
            ->from($db->quoteName('#__alfa_customer_credit_transactions'))
            ->where($db->quoteName('source_type') . ' = ' . $db->quote(self::SOURCE_CREDIT_NOTE))
            ->where($db->quoteName('source_id') . ' = :docId')
            ->bind(':docId', $documentId);
        $db->setQuery(query: $query);

        return self::walletMoney((string) $db->loadResult());
    }

    /**
     * The wallet leg of a credit note, as a bare float at the wallet scale.
     *
     * @param int $documentId Credit document id.
     *
     *
     * @since  1.11.0
     */
    public static function creditNoteNet(int $documentId): float
    {
        return self::creditNoteNetMoney($documentId)->getAmount();
    }

    /**
     * Reverse a voided credit note's wallet leg: the counter-entry, inside
     * the CALLER's transaction (the void envelope owns the outcome).
     *
     * @param int $documentId The voided credit document id.
     * @param string $numberFormatted The document's frozen number (note text).
     * @param int $userId The account that received the credit.
     * @param Money|string|int|float $amount The net amount to take back (read as credit() reads it).
     * @param int|null $actorId Acting admin.
     *
     * @return object The counter transaction.
     *
     * @throws RuntimeException When the balance is insufficient: the
     *                          customer already spent the credit; the void
     *                          must be blocked until an operator resolves it.
     *
     * @since  1.9.0
     */
    public static function reverseCreditNote(
        int $documentId,
        string $numberFormatted,
        int $userId,
        Money|string|int|float $amount,
        ?int $actorId = null,
    ): object {
        return self::write(
            userId:            $userId,
            amount:            self::walletMoney($amount)->abs()->negate(),
            entryType:         'debit',
            sourceType:        self::SOURCE_CREDIT_NOTE,
            sourceId:          $documentId,
            note:              'Reversal: credit note ' . $numberFormatted . ' voided',
            actorId:           $actorId,
            manageTransaction: false,
        );
    }

    /**
     * Current identity id (0 in CLI contexts).
     *
     * @since  1.9.0
     */
    private static function currentUserId(): int
    {
        try {
            return (int) (Factory::getApplication()->getIdentity()?->id ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }
}
