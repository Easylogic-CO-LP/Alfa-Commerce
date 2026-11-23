<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Background\BackgroundWork;
use Alfa\Component\Alfa\Administrator\Helper\ActionLogHelper;
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\MysqlError;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationText;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderDisputeEmail;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderDisputeEvent;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundFeed;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundTopics;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\DisputeRemindJob;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoney;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoneyRefusal;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal;
use Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseDriver;
use RuntimeException;
use Throwable;

/**
 * Card disputes: the one writer of #__alfa_order_disputes, a fluent builder.
 *
 * A dispute is a case at the customer's bank about one payment row. Its record holds the case (state, the bank's last
 * decision, round, deadline, amounts, fee, the provider's reference) and no money. The money follows the bank's last
 * decision only, never the state: while a case is open or under review the payment stays paid; at a loss the books get
 * a chargeback row of the disputed amount; a win books nothing (a reversal of that chargeback when an earlier loss
 * turns into a win); a case opened again keeps its last decision until the bank decides again. Every save compares
 * what the decision asks the books to hold with what the case's rows hold and books the difference through the payments
 * ledger's two verbs, under the order's lock and in the save's one transaction, so a repeated or late word never books
 * twice and a corrected amount books only the difference. Partial disputes are cases of their own amount.
 *
 * The doors. A payment plugin's only way in is its base class's dispute(), which is
 * DisputeLedger::for($paymentId, $providerDisputeId, DisputeWriter::gateway($element)). Core (the order screen's model,
 * the REST verbs) enters through {@see self::for()} or {@see self::load()} and always names its writer. After the door
 * the chain is the same:
 *
 *     $verdict = $this->dispute(paymentId: $id, reference: 'du_1QxYz')
 *         ->open()
 *         ->amount(amount: '49.90', currency: 'EUR')
 *         ->reason(code: DisputeLedger::REASON_NOT_RECEIVED, text: 'product_not_received')
 *         ->evidenceDueBy(utc: '2026-12-04 23:59:59')
 *         ->save();
 *
 *     return $verdict->providerStatus();
 *
 * Idempotency: a record is unique per payment and reference; the order's row lock serialises two deliveries; the same
 * state and facts again answer written with nothing new; a provider's word older than the last one applied (asOf())
 * answers stale. A provider word for a payment that has a record made by hand and not yet joined to a provider waits for
 * staff (Same dispute, or A different one) and books nothing meanwhile: nothing is joined by inference.
 *
 * @api CONTRACT: the chain, its constants and save()'s DisputeVerdict are the contract a gateway reaches through
 *      PaymentsPlugin::dispute(); for(), load() and DisputeWriter are core's door.
 *
 * @since  2.2.2
 */
final class DisputeLedger
{
    use LedgerSupport {
        pluginData as private;
        removePluginData as private;
    }

    /** @since 2.2.2 */
    public const STATE_INQUIRY = 'inquiry';

    /** @since 2.2.2 */
    public const STATE_OPEN = 'open';

    /** @since 2.2.2 */
    public const STATE_UNDER_REVIEW = 'under_review';

    /** @since 2.2.2 */
    public const STATE_WON = 'won';

    /** @since 2.2.2 */
    public const STATE_LOST = 'lost';

    /** @since 2.2.2 */
    public const STATE_CLOSED = 'closed';

    /**
     * Every state, in the order a case passes through them.
     *
     * @since 2.2.2
     */
    public const STATES = [self::STATE_INQUIRY, self::STATE_OPEN, self::STATE_UNDER_REVIEW, self::STATE_WON, self::STATE_LOST, self::STATE_CLOSED];

    /**
     * The states in which the bank has not decided.
     *
     * @since 2.2.2
     */
    public const UNDECIDED_STATES = [self::STATE_INQUIRY, self::STATE_OPEN, self::STATE_UNDER_REVIEW];

    /**
     * closed(): an inquiry that ended with no chargeback.
     *
     * @since 2.2.2
     */
    public const CLOSED_INQUIRY_ENDED = 'inquiry_ended';

    /**
     * closed(): a record made by hand in error (staff only).
     *
     * @since 2.2.2
     */
    public const CLOSED_IN_ERROR = 'in_error';

    /**
     * A provider's record closed as the same case as a record made by hand (written by the reconcile answer only).
     *
     * @since 2.2.2
     */
    public const CLOSED_MERGED = 'merged';

    /** @since 2.2.2 */
    public const REASON_FRAUD = 'fraud';

    /** @since 2.2.2 */
    public const REASON_NOT_RECEIVED = 'not_received';

    /** @since 2.2.2 */
    public const REASON_NOT_AS_DESCRIBED = 'not_as_described';

    /** @since 2.2.2 */
    public const REASON_DUPLICATE = 'duplicate';

    /** @since 2.2.2 */
    public const REASON_CREDIT_NOT_PROCESSED = 'credit_not_processed';

    /** @since 2.2.2 */
    public const REASON_CANCELLED = 'cancelled';

    /** @since 2.2.2 */
    public const REASON_UNRECOGNISED = 'unrecognised';

    /** @since 2.2.2 */
    public const REASON_OTHER = 'other';

    /**
     * Core's reason categories.
     *
     * @since 2.2.2
     */
    public const REASONS = [
        self::REASON_FRAUD, self::REASON_NOT_RECEIVED, self::REASON_NOT_AS_DESCRIBED, self::REASON_DUPLICATE,
        self::REASON_CREDIT_NOT_PROCESSED, self::REASON_CANCELLED, self::REASON_UNRECOGNISED, self::REASON_OTHER,
    ];

    /** @since 2.2.2 */
    public const SOURCE_STAFF = 'staff';

    /** @since 2.2.2 */
    public const SOURCE_PROVIDER = 'provider';

    /** @since 2.2.2 */
    public const SOURCE_MIGRATED = 'migrated';

    /**
     * The record waits for staff to say whether it is the same case as a record made by hand.
     *
     * @since 2.2.2
     */
    public const WAITS_SAME_PAYMENT = 'same_payment';

    /**
     * The record is lost and its amount is known only in another currency: staff type the order's figure.
     *
     * @since 2.2.2
     */
    public const WAITS_AMOUNT_UNKNOWN = 'amount_unknown';

    /**
     * The prefix of a dispute's notice key: payments.dispute.<id>.
     *
     * @since 2.2.2
     */
    public const NOTICE_PREFIX = 'payments.dispute.';

    /**
     * The table, and the schema constants LedgerSupport's persistence helpers read.
     *
     * @since 2.2.2
     */
    private const TABLE = '#__alfa_order_disputes';

    /** @since 2.2.2 */
    private const PK = 'id';

    /** @since 2.2.2 */
    private const NULLABLE_INT_COLUMNS = ['merged_into'];

    /** @since 2.2.2 */
    private const NULLABLE_DATE_COLUMNS = ['evidence_due_by', 'evidence_sent_at', 'decided_at', 'provider_as_of'];

    /** @since 2.2.2 */
    private const NULLABLE_ENUM_COLUMNS = ['outcome', 'closed_reason', 'waits_for'];

    /**
     * The payments table, read for the disputed payment and the case's money rows.
     *
     * @since 2.2.2
     */
    private const PAYMENTS = '#__alfa_order_payments';

    /**
     * The facts compared between the record as stored and as the save leaves it; a change of any is a change.
     *
     * @since 2.2.2
     */
    private const COMPARED = [
        'state', 'outcome', 'closed_reason', 'merged_into', 'stage', 'reference', 'joined', 'amount', 'stated_amount',
        'stated_currency', 'reason_code', 'reason_text', 'evidence_due_by', 'evidence_sent_at', 'fee_amount',
        'fee_currency', 'fee_returned', 'provider_link', 'waits_for', 'note',
    ];

    /**
     * The facts a record that follows its provider takes from the provider only.
     *
     * @since 2.2.2
     */
    private const PROVIDER_FACTS = ['amount', 'reason', 'due', 'reference', 'fee', 'link'];

    // The door.

    /** @since 2.2.2 */
    private int $paymentId = 0;

    /** @since 2.2.2 */
    private int $disputeId = 0;

    /** @since 2.2.2 */
    private string $lookup = '';

    // What the chain asks.

    /** @since 2.2.2 */
    private ?string $stateAsked = null;

    /** @since 2.2.2 */
    private string $closedWhy = '';

    /** @since 2.2.2 */
    private bool $evidenceAsked = false;

    /** @since 2.2.2 */
    private ?string $evidenceAt = null;

    /** @var array{0: string, 1: string}|null */
    private ?array $amountAsked = null;

    /** @since 2.2.2 */
    private ?string $orderAmountAsked = null;

    /** @var array{0: string, 1: string}|null */
    private ?array $reasonAsked = null;

    /** @since 2.2.2 */
    private bool $dueAsked = false;

    /** @since 2.2.2 */
    private ?string $dueAt = null;

    /** @var array{0: string, 1: string}|null */
    private ?array $feeAsked = null;

    /** @var array{0: string, 1: string}|null */
    private ?array $feeReturnedAsked = null;

    /** @since 2.2.2 */
    private ?string $linkAsked = null;

    /** @since 2.2.2 */
    private ?string $noteAsked = null;

    /** @since 2.2.2 */
    private ?string $asOfAsked = null;

    /** @since 2.2.2 */
    private ?string $responseAsked = null;

    /** @since 2.2.2 */
    private ?string $referenceAsked = null;

    /** @since 2.2.2 */
    private int $sameAsAsked = 0;

    /** @since 2.2.2 */
    private bool $notTheSameAsked = false;

    /**
     * What the chain was given that cannot be read: [code, sentence], the first one wins.
     *
     * @var array{0: string, 1: string}|null
     *
     * @since 2.2.2
     */
    private ?array $malformed = null;

    /**
     * The verdict of the last save, for the caller that needs the record's id after a refusal (the REST verbs).
     *
     * @since 2.2.2
     */
    private ?DisputeVerdict $verdict = null;

    /**
     * What the save's last record booked, decimal text in the order's currency (negative: a reversal).
     *
     * @since 2.2.2
     */
    private string $booked = '0';

    /**
     * @param DisputeWriter $writer Who writes.
     *
     * @since 2.2.2
     */
    private function __construct(private readonly DisputeWriter $writer)
    {
    }

    /**
     * The case of a payment under a reference: found by the reference, or started. Core's door: the model behind the
     * order screen and the REST verbs name their writer; a payment plugin enters through its base class's dispute().
     *
     * @param int $paymentId The disputed payment row.
     * @param string $reference The provider's dispute id, or the bank's case number; '' for staff makes a new record by
     *                          hand with no reference (a gateway's word needs one).
     * @param DisputeWriter $writer Who writes.
     *
     * @since 2.2.2
     */
    public static function for(int $paymentId, string $reference, DisputeWriter $writer): self
    {
        $ledger = new self(writer: $writer);
        $ledger->paymentId = $paymentId;
        $ledger->lookup = $reference;

        return $ledger;
    }

    /**
     * An existing record, by its id: the order screen's card and the REST verbs address records by id.
     *
     * @param int $disputeId The record.
     * @param DisputeWriter $writer Who writes.
     *
     * @since 2.2.2
     */
    public static function load(int $disputeId, DisputeWriter $writer): self
    {
        $ledger = new self(writer: $writer);
        $ledger->disputeId = $disputeId;

        return $ledger;
    }

    // The chain: the state.

    /**
     * The bank asks for information; no money moved.
     *
     * @api CONTRACT: a state of the case.
     *
     * @since 2.2.2
     */
    public function inquiry(): static
    {
        return $this->state(state: self::STATE_INQUIRY);
    }

    /**
     * A chargeback: the shop answers by the deadline, or accepts. On a won or lost case, a new round.
     *
     * @api CONTRACT: a state of the case.
     *
     * @since 2.2.2
     */
    public function open(): static
    {
        return $this->state(state: self::STATE_OPEN);
    }

    /**
     * Evidence sent: stamps the time; an open chargeback moves to under review, an inquiry stays an inquiry, a won or
     * lost case starts a new round under review.
     *
     * @api CONTRACT: a state of the case.
     *
     * @param string|null $utc When it was sent, UTC Y-m-d H:i:s; null for now.
     *
     * @since 2.2.2
     */
    public function evidenceSent(?string $utc = null): static
    {
        $this->evidenceAsked = true;
        $this->evidenceAt = $utc === null ? null : $this->utc(value: $utc, what: 'evidenceSent()');

        return $this;
    }

    /**
     * Decided for the shop.
     *
     * @api CONTRACT: a state of the case.
     *
     * @since 2.2.2
     */
    public function won(): static
    {
        return $this->state(state: self::STATE_WON);
    }

    /**
     * Decided for the customer (also: accepted by the shop, or not answered in time): the order owes the amount again.
     *
     * @api CONTRACT: a state of the case.
     *
     * @since 2.2.2
     */
    public function lost(): static
    {
        return $this->state(state: self::STATE_LOST);
    }

    /**
     * Ended with no decision on money.
     *
     * @api CONTRACT: a state of the case.
     *
     * @param string $why self::CLOSED_INQUIRY_ENDED, or self::CLOSED_IN_ERROR (staff, a record made by hand only).
     *
     * @since 2.2.2
     */
    public function closed(string $why): static
    {
        if (!\in_array($why, [self::CLOSED_INQUIRY_ENDED, self::CLOSED_IN_ERROR], true)) {
            $this->malform(code: DisputeVerdict::CODE_VALUE, sentence: sprintf('closed() takes DisputeLedger::CLOSED_INQUIRY_ENDED or CLOSED_IN_ERROR, not "%s"', $why));
        }

        $this->closedWhy = $why;

        return $this->state(state: self::STATE_CLOSED);
    }

    // The chain: the facts.

    /**
     * The disputed amount as the provider or the bank states it.
     *
     * @api CONTRACT: a fact of the case.
     *
     * @param string $amount A positive decimal text, '49.90'.
     * @param string $currency Its ISO 4217 code, 'EUR'.
     *
     * @since 2.2.2
     */
    public function amount(string $amount, string $currency): static
    {
        if (!self::isDecimal(value: $amount) || self::isZeroDecimal(value: $amount)) {
            $this->malform(code: DisputeVerdict::CODE_AMOUNT, sentence: sprintf('the disputed amount "%s" is not a positive decimal', $amount));
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $this->malform(code: DisputeVerdict::CODE_NO_CURRENCY, sentence: sprintf('the currency "%s" is not an ISO 4217 code', $currency));
        }

        $this->amountAsked = [$amount, $currency];

        return $this;
    }

    /**
     * The disputed amount in the order's currency, when the provider stated it in another one (what left the shop's
     * account, from the provider's statement). Refused on a record whose stated currency is the order's.
     *
     * @api CONTRACT: a fact of the case.
     *
     * @param string $amount A positive decimal text at the order's scale.
     *
     * @since 2.2.2
     */
    public function amountInOrderCurrency(string $amount): static
    {
        if (!self::isDecimal(value: $amount) || self::isZeroDecimal(value: $amount)) {
            $this->malform(code: DisputeVerdict::CODE_AMOUNT, sentence: sprintf('the amount in the order\'s currency "%s" is not a positive decimal', $amount));
        }

        $this->orderAmountAsked = $amount;

        return $this;
    }

    /**
     * Why the customer disputes: core's category and the provider's own words.
     *
     * @api CONTRACT: a fact of the case.
     *
     * @param string $code One of self::REASONS.
     * @param string $text The provider's own reason, as given (up to 255 characters are kept).
     *
     * @since 2.2.2
     */
    public function reason(string $code, string $text = ''): static
    {
        if (!\in_array($code, self::REASONS, true)) {
            $this->malform(code: DisputeVerdict::CODE_VALUE, sentence: sprintf('the reason "%s" is not one of DisputeLedger::REASONS', $code));
        }

        $this->reasonAsked = [$code, mb_substr($text, 0, 255)];

        return $this;
    }

    /**
     * The deadline to answer by.
     *
     * @api CONTRACT: a fact of the case.
     *
     * @param string|null $utc UTC Y-m-d H:i:s; null for none.
     *
     * @since 2.2.2
     */
    public function evidenceDueBy(?string $utc): static
    {
        $this->dueAsked = true;
        $this->dueAt = $utc === null ? null : $this->utc(value: $utc, what: 'evidenceDueBy()');

        return $this;
    }

    /**
     * The provider's fee for the dispute, as it states it (often the shop's account currency). Never the customer's
     * money: it is shown, never booked.
     *
     * @api CONTRACT: a fact of the case.
     *
     * @param string $amount A decimal text.
     * @param string $currency Its ISO 4217 code.
     *
     * @since 2.2.2
     */
    public function fee(string $amount, string $currency): static
    {
        $this->feeAsked = $this->feeValue(amount: $amount, currency: $currency, what: 'fee()');

        return $this;
    }

    /**
     * The part of the fee the provider gave back, in the fee's currency.
     *
     * @api CONTRACT: a fact of the case.
     *
     * @param string $amount A decimal text.
     * @param string $currency Its ISO 4217 code.
     *
     * @since 2.2.2
     */
    public function feeReturned(string $amount, string $currency): static
    {
        $this->feeReturnedAsked = $this->feeValue(amount: $amount, currency: $currency, what: 'feeReturned()');

        return $this;
    }

    /**
     * The https address of the case at the provider.
     *
     * @api CONTRACT: a fact of the case.
     *
     * @param string $url The address.
     *
     * @since 2.2.2
     */
    public function providerLink(string $url): static
    {
        if (\strlen($url) > 2048 || preg_match('#^https://[^\s/$.?\#][^\s]*$#i', $url) !== 1) {
            $this->malform(code: DisputeVerdict::CODE_VALUE, sentence: 'the provider link is not an https address of up to 2048 characters');
        }

        $this->linkAsked = $url;

        return $this;
    }

    /**
     * A note on the case's timeline (an issuer's comment, staff's words). With no state call, a word that changes
     * nothing else.
     *
     * @api CONTRACT: a fact of the case.
     *
     * @param string $text The note (up to 255 characters are kept).
     *
     * @since 2.2.2
     */
    public function note(string $text): static
    {
        $this->noteAsked = mb_substr(trim($text), 0, 255);

        return $this;
    }

    /**
     * The provider's time of this word, so words delivered out of order never move a case backwards. A gateway that
     * reads the provider's current state before it calls may leave it out; core then takes the call's own time.
     *
     * @api CONTRACT: a fact of the word.
     *
     * @param string $utc UTC Y-m-d H:i:s, with microseconds when the provider gives them.
     *
     * @since 2.2.2
     */
    public function asOf(string $utc): static
    {
        $this->asOfAsked = $this->utc(value: $utc, what: 'asOf()', micro: true);

        return $this;
    }

    /**
     * The provider's payload, kept for staff and support.
     *
     * @api CONTRACT: a fact of the word.
     *
     * @param string $json The payload as received.
     *
     * @since 2.2.2
     */
    public function gatewayResponse(string $json): static
    {
        $this->responseAsked = $json;

        return $this;
    }

    /**
     * Staff, a record made by hand: the provider's or the bank's reference.
     *
     * @param string $reference The reference, complete.
     *
     * @since 2.2.2
     */
    public function reference(string $reference): static
    {
        $this->referenceAsked = $reference;

        return $this;
    }

    /**
     * Staff, the reconcile answer: this provider's record is the same case as the record made by hand given.
     *
     * @param int $disputeId The record made by hand.
     *
     * @since 2.2.2
     */
    public function sameAs(int $disputeId): static
    {
        $this->sameAsAsked = $disputeId;

        return $this;
    }

    /**
     * Staff, the reconcile answer: this provider's record is a different case; it books by its state from now on.
     *
     * @since 2.2.2
     */
    public function notTheSame(): static
    {
        $this->notTheSameAsked = true;

        return $this;
    }

    /**
     * The verdict of the last save(), or null before one.
     *
     * @since 2.2.2
     */
    public function verdict(): ?DisputeVerdict
    {
        return $this->verdict;
    }

    /**
     * Write it all in one transaction under the order's lock: the record, the money rows its decision owes by
     * difference, the timeline entry, the action log row of a staff act and the notice. Nothing is written unless
     * everything is. A provider's word that a rule refuses is still kept for staff, as a notice.
     *
     * @api ANSWERS: a DisputeVerdict; a gateway answers its provider with providerStatus().
     *
     * @since 2.2.2
     */
    public function save(): DisputeVerdict
    {
        return $this->verdict = $this->saveOnce(retried: false);
    }

    // Readers.

    /**
     * The records of an order, oldest first, each with what its rows book: the card, the REST order block, the e-mails.
     *
     * Each record is the row as stored, with 'booked' (what its chargeback rows book less their reversals, the order's
     * currency, decimal text at its scale), 'rows' (its money rows: id, payment_type, amount, transaction_id,
     * processed_at) and 'candidates' (for a record that waits for the reconcile answer: the ids of the records made by
     * hand of the same payment).
     *
     * @param int $orderId The order.
     *
     * @return list<object>
     *
     * @since 2.2.2
     */
    public static function forOrder(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $db = self::db();
        $records = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->order($db->quoteName('id') . ' ASC'),
        )->loadObjectList() ?: [];

        if ($records === []) {
            return [];
        }

        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'id_dispute', 'payment_type', 'amount', 'transaction_id', 'processed_at', 'added', 'id_reversed_payment']))
                ->from($db->quoteName(self::PAYMENTS))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->where($db->quoteName('id_dispute') . ' IS NOT NULL')
                ->where($db->quoteName('transaction_status') . ' = ' . $db->quote(PaymentLedger::STATUS_COMPLETED))
                ->order($db->quoteName('id') . ' ASC'),
        )->loadObjectList() ?: [];

        $currency = OrderCurrency::find(orderId: $orderId);

        foreach ($records as $record) {
            $own = array_values(array_filter($rows, static fn (object $row): bool => (int) $row->id_dispute === (int) $record->id));
            $record->rows = $own;
            $record->booked = $currency === null ? null : PublishedDecimal::money(money: self::bookedOf(rows: $own, currency: $currency));
            $record->candidates = (string) $record->waits_for === self::WAITS_SAME_PAYMENT
                ? array_map(static fn (object $candidate): int => (int) $candidate->id, self::candidatesOf(db: $db, record: $record, records: $records))
                : [];
        }

        return $records;
    }

    /**
     * The ids of an order's disputes the bank has not decided (an inquiry, open, under review), oldest first.
     *
     * @param int $orderId The order.
     *
     * @return list<int>
     *
     * @since 2.2.2
     */
    public static function undecidedOf(int $orderId): array
    {
        $db = self::db();

        return array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->whereIn($db->quoteName('state'), self::UNDECIDED_STATES, ParameterType::STRING)
                ->order($db->quoteName('id') . ' ASC'),
        )->loadColumn() ?: []);
    }

    /**
     * One record as stored, or null.
     *
     * @param int $disputeId The record.
     *
     * @since 2.2.2
     */
    public static function get(int $disputeId): ?object
    {
        return $disputeId > 0 ? self::getRaw(id: $disputeId) : null;
    }

    /**
     * Whether a record follows its provider's words (made from a provider's word, or joined to one): its facts are the
     * provider's and staff change them no more.
     *
     * @param object $record The record.
     *
     * @since 2.2.2
     */
    public static function followsProvider(object $record): bool
    {
        return (string) $record->source === self::SOURCE_PROVIDER || (int) $record->joined === 1;
    }

    /**
     * Whether a record needs staff now: an inquiry or an open chargeback with no evidence sent, or a record that waits
     * for a decision of staff. Its notice resurfaces while it does.
     *
     * @param object $record The record.
     *
     * @since 2.2.2
     */
    public static function needsStaff(object $record): bool
    {
        return ((string) ($record->waits_for ?? '') !== '')
            || (\in_array((string) $record->state, [self::STATE_INQUIRY, self::STATE_OPEN], true) && ($record->evidence_sent_at ?? null) === null);
    }

    // The save.

    /**
     * One attempt of save(); a record made by another delivery of the same first word meanwhile (the unique key) is
     * read again once as the existing record.
     *
     * @param bool $retried This is the second attempt.
     *
     * @since 2.2.2
     */
    private function saveOnce(bool $retried): DisputeVerdict
    {
        $db = self::db();
        $nested = TransactionState::isOpen($db);
        $orderId = 0;

        try {
            $start = $this->startOf(db: $db);

            if ($start instanceof DisputeVerdict) {
                return $this->refusedWord(verdict: $start, orderId: 0, paymentId: $this->paymentId);
            }

            [$orderId, $paymentId] = $start;
            $db->transactionStart(true);

            try {
                if (!self::lockOrders($db, $orderId)) {
                    TransactionState::rollBackLevel($db);

                    return new DisputeVerdict(kind: DisputeVerdict::LOCK_NOT_TAKEN, message: sprintf('Order #%d was held by another operation; nothing was written.', $orderId), disputeId: $this->disputeId);
                }

                $verdict = $this->decideAndWrite(db: $db, orderId: $orderId, paymentId: $paymentId);

                if (!$verdict->isWritten()) {
                    TransactionState::rollBackLevel($db);

                    return $this->refusedWord(verdict: $verdict, orderId: $orderId, paymentId: $paymentId);
                }
            } catch (Throwable $e) {
                TransactionState::rollBackLevel($db, $e);

                throw $e;
            }

            TransactionState::commitLevel($db);

            return $verdict;
        } catch (Throwable $e) {
            // A failure that ended the caller's transaction is the caller's to hear.
            if ($nested && !TransactionState::isOpen(self::db())) {
                throw $e;
            }

            if (!$retried && MysqlError::number($e) === 1062) {
                return $this->saveOnce(retried: true);
            }

            self::logFailure(orderId: $orderId, paymentId: $this->paymentId, disputeId: $this->disputeId, failure: $e);

            return new DisputeVerdict(kind: DisputeVerdict::WRITE_FAILED, message: 'The dispute could not be written; nothing was written. The reason is in the payments log.', disputeId: $this->disputeId);
        }
    }

    /**
     * The order and the payment the save is about, read before the lock (neither changes for a record or a payment),
     * or the refusal when there is none.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @return array{0: int, 1: int}|DisputeVerdict
     *
     * @since 2.2.2
     */
    private function startOf(DatabaseDriver $db): array|DisputeVerdict
    {
        if ($this->disputeId > 0) {
            $record = self::getRaw(id: $this->disputeId);

            if ($record === null) {
                return self::refused(code: DisputeVerdict::CODE_MISSING, message: sprintf('Dispute #%d does not exist.', $this->disputeId));
            }

            $this->paymentId = (int) $record->id_payment;

            return [(int) $record->id_order, (int) $record->id_payment];
        }

        $orderId = (int) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id_order'))
                ->from($db->quoteName(self::PAYMENTS))
                ->where($db->quoteName('id') . ' = ' . $this->paymentId),
        )->loadResult();

        if ($orderId <= 0 || (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_orders') . ' WHERE ' . $db->quoteName('id') . ' = ' . $orderId)->loadResult() === 0) {
            return self::refused(code: DisputeVerdict::CODE_NOT_COLLECTED, message: sprintf('Payment #%d does not exist, or its order does not.', $this->paymentId));
        }

        return [$orderId, $this->paymentId];
    }

    /**
     * Decide the save under the order's lock and write it: the refusal, or written.
     *
     * @param DatabaseDriver $db The connection, in the save's transaction.
     * @param int $orderId The order, locked.
     * @param int $paymentId The disputed payment.
     *
     * @since 2.2.2
     */
    private function decideAndWrite(DatabaseDriver $db, int $orderId, int $paymentId): DisputeVerdict
    {
        if ($this->malformed !== null) {
            return self::refused(code: $this->malformed[0], message: ucfirst($this->malformed[1]) . '.');
        }

        try {
            $currency = OrderCurrency::of(orderId: $orderId);
        } catch (OrderCurrencyException $e) {
            return self::refused(code: DisputeVerdict::CODE_NOT_COLLECTED, message: $e->getMessage());
        }

        $payment = $db->setQuery('SELECT * FROM ' . $db->quoteName(self::PAYMENTS) . ' WHERE ' . $db->quoteName('id') . ' = ' . $paymentId)->loadObject();

        if ($payment === null || (int) $payment->id_order !== $orderId) {
            return self::refused(code: DisputeVerdict::CODE_NOT_COLLECTED, message: sprintf('Payment #%d moved or vanished; nothing was written.', $paymentId));
        }

        $staff = $this->writer->isStaff();

        // The gateway-only and staff-only calls.
        if (!$staff && ($this->closedWhy === self::CLOSED_IN_ERROR || $this->sameAsAsked > 0 || $this->notTheSameAsked || $this->referenceAsked !== null)) {
            return self::refused(code: DisputeVerdict::CODE_STAFF_ONLY, message: 'Only staff close a record as recorded in error, answer the reconcile question or type a reference.');
        }

        if ($staff && $this->asOfAsked !== null) {
            return self::refused(code: DisputeVerdict::CODE_VALUE, message: 'asOf() is the time of a provider\'s word; staff do not give one.');
        }

        // The record, read after the lock with a locking read: a delivery committed meanwhile is seen.
        $record = $this->disputeId > 0 ? self::lockedRecord(db: $db, where: $db->quoteName('id') . ' = ' . $this->disputeId) : null;

        if ($this->disputeId > 0 && $record === null) {
            return self::refused(code: DisputeVerdict::CODE_MISSING, message: sprintf('Dispute #%d does not exist.', $this->disputeId));
        }

        if ($record === null && $this->lookup !== '') {
            $record = self::lockedRecord(
                db: $db,
                where: $db->quoteName('id_payment') . ' = ' . $paymentId
                    . ' AND ' . $db->quoteName('reference_hash') . ' = ' . $db->quote(hash('sha256', $this->lookup))
                    . ' AND BINARY ' . $db->quoteName('reference') . ' = BINARY ' . $db->quote($this->lookup),
            );
        }

        if (!$staff && $record === null && $this->lookup === '') {
            return self::refused(code: DisputeVerdict::CODE_NO_REFERENCE, message: sprintf('A provider\'s word for payment #%d names no dispute id.', $paymentId));
        }

        // Whose it is.
        $element = (string) ($record->payment_method_type ?? $payment->payment_method_type ?? '');

        if (!$staff && $element !== $this->writer->element) {
            return self::refused(code: DisputeVerdict::CODE_NOT_OWN, message: sprintf(
                'Payment #%d is not money of the %s plugin (%s): a gateway writes the disputes of its own payments only.',
                $paymentId,
                $this->writer->element,
                $element !== '' ? $element : 'no plugin',
            ));
        }

        if ($this->sameAsAsked > 0 || $this->notTheSameAsked) {
            return $this->reconcile(db: $db, record: $record, currency: $currency, payment: $payment);
        }

        if ($record === null) {
            $refusal = self::collectedRefusal(payment: $payment);

            if ($refusal !== null) {
                return $refusal;
            }
        }

        // An older provider word than the one applied moves nothing.
        if (!$staff && $record !== null && $this->asOfAsked !== null && $record->provider_as_of !== null
            && strcmp(self::micro(value: $this->asOfAsked), self::micro(value: (string) $record->provider_as_of)) < 0) {
            return new DisputeVerdict(
                kind: DisputeVerdict::STALE,
                message: sprintf('Dispute #%d: the word of %s is older than the one applied (%s); nothing was written.', (int) $record->id, $this->asOfAsked, (string) $record->provider_as_of),
                disputeId: (int) $record->id,
                existing: true,
            );
        }

        $plan = $this->plan(db: $db, record: $record, payment: $payment, currency: $currency);

        if ($plan instanceof DisputeVerdict) {
            return $plan;
        }

        return $this->write(db: $db, plan: $plan, currency: $currency, payment: $payment);
    }

    /**
     * What the save leaves: the record's new row, what changed and what to tell; or the refusal.
     *
     * @param DatabaseDriver $db The connection.
     * @param object|null $record The record as stored, or null for a new one.
     * @param object $payment The disputed payment.
     * @param Currency $currency The order's currency at its frozen scale.
     *
     * @return array<string, mixed>|DisputeVerdict
     *
     * @since 2.2.2
     */
    private function plan(DatabaseDriver $db, ?object $record, object $payment, Currency $currency): array|DisputeVerdict
    {
        $staff = $this->writer->isStaff();
        $now = Factory::getDate('now', 'UTC')->toSql();
        $before = $record === null ? null : (array) $record;
        $after = $before ?? [
            'id' => 0,
            'id_order' => (int) $payment->id_order,
            'id_payment' => (int) $payment->id,
            'payment_method_type' => (string) ($payment->payment_method_type ?? ''),
            'source' => $staff ? self::SOURCE_STAFF : self::SOURCE_PROVIDER,
            'joined' => $staff ? 0 : 1,
            'state' => '',
            'outcome' => null,
            'closed_reason' => null,
            'merged_into' => null,
            'stage' => 1,
            'reference' => $this->lookup !== '' ? $this->lookup : null,
            'id_currency' => (int) $currency->getId(),
            'currency_scale' => (int) $currency->getDecimalPlaces(),
            'amount' => null,
            'stated_amount' => null,
            'stated_currency' => null,
            'reason_code' => self::REASON_OTHER,
            'reason_text' => '',
            'evidence_due_by' => null,
            'evidence_sent_at' => null,
            'fee_amount' => null,
            'fee_currency' => null,
            'fee_returned' => null,
            'provider_link' => '',
            'waits_for' => null,
            'bookings' => 0,
            'provider_as_of' => null,
            'gateway_response' => null,
            'note' => '',
            'decided_at' => null,
            'created' => $now,
            'created_by' => $this->writer->userId(),
            'modified' => $now,
            'modified_by' => $this->writer->userId(),
        ];
        $current = $before === null ? null : (string) $before['state'];
        $byHand = $before === null ? $staff : !self::followsProvider(record: $record);
        $events = [];

        // A closed case is final: only an exact repeat passes, as nothing new.
        if ($current === self::STATE_CLOSED && ($this->stateAsked !== self::STATE_CLOSED || $this->closedWhy !== (string) $before['closed_reason'])) {
            return self::refused(code: DisputeVerdict::CODE_CLOSED, message: sprintf('Dispute #%d is closed; a later dispute is a new case.', (int) $before['id']), disputeId: (int) $before['id']);
        }

        // A provider's word takes over a record made by hand whose reference it carries.
        if (!$staff && $before !== null && (int) $before['joined'] === 0) {
            $after['joined'] = 1;
            $events[] = ['event' => 'dispute.joined', 'text' => self::text('COM_ALFA_ORDER_HISTORY_DISPUTE_FOLLOWS', (int) $before['id'], (string) $payment->payment_method)];
        }

        // Staff change a provider's facts no more (a new round's deadline excepted).
        $reopening = $current !== null && \in_array($current, [self::STATE_WON, self::STATE_LOST], true)
            && ($this->stateAsked === self::STATE_OPEN || ($this->stateAsked === null && $this->evidenceAsked));
        $factsAsked = array_filter([
            'amount' => $this->amountAsked !== null,
            'reason' => $this->reasonAsked !== null,
            'due' => $this->dueAsked && !$reopening,
            'reference' => $this->referenceAsked !== null,
            'fee' => $this->feeAsked !== null,
            'link' => $this->linkAsked !== null,
        ]);

        if ($staff && !$byHand && array_intersect(array_keys($factsAsked), self::PROVIDER_FACTS) !== []) {
            return self::refused(code: DisputeVerdict::CODE_FOLLOWS_PROVIDER, message: sprintf(
                'Dispute #%d follows its provider: its %s is the provider\'s. Staff may mark evidence sent, add a note, close it with the outcome or type the amount in the order\'s currency.',
                (int) $before['id'],
                implode(', ', array_keys($factsAsked)),
            ), disputeId: (int) $before['id']);
        }

        // The state.
        $target = $this->targetState(current: $current, staff: $staff, byHand: $byHand, before: $before);

        if ($target instanceof DisputeVerdict) {
            return $target;
        }

        $after['state'] = $target;

        if ($target !== $current) {
            if ($current !== null && \in_array($current, [self::STATE_WON, self::STATE_LOST], true) && \in_array($target, [self::STATE_OPEN, self::STATE_UNDER_REVIEW], true)) {
                $after['stage'] = min(255, (int) $after['stage'] + 1);
                $events[] = ['event' => 'dispute.reopened', 'text' => self::text('COM_ALFA_ORDER_HISTORY_DISPUTE_REOPENED', 0, (int) $after['stage'])];
            } elseif ($target === self::STATE_WON || $target === self::STATE_LOST) {
                $events[] = ['event' => 'dispute.' . $target, 'text' => self::text('COM_ALFA_ORDER_HISTORY_DISPUTE_' . strtoupper($target), 0)];
            } elseif ($target === self::STATE_CLOSED) {
                $events[] = ['event' => 'dispute.closed', 'text' => self::text('COM_ALFA_ORDER_HISTORY_DISPUTE_CLOSED', 0, self::text('COM_ALFA_DISPUTE_CLOSED_' . strtoupper($this->closedWhy)))];
            } elseif ($current !== null && !($current === self::STATE_OPEN && $target === self::STATE_UNDER_REVIEW)) {
                $events[] = ['event' => 'dispute.changed', 'text' => self::text('COM_ALFA_ORDER_HISTORY_DISPUTE_MOVED', 0, self::stateText(state: $current), self::stateText(state: $target))];
            }
        }

        // The bank's last decision, and when it was made: kept by a reopen, emptied by a close with no decision.
        if ($target === self::STATE_WON || $target === self::STATE_LOST) {
            if ($target !== $current || $after['outcome'] !== $target) {
                $after['outcome'] = $target;
                $after['decided_at'] = $this->asOfAsked !== null ? substr($this->asOfAsked, 0, 19) : $now;
            }
        } elseif ($target === self::STATE_CLOSED) {
            $after['outcome'] = null;
            $after['closed_reason'] = $this->closedWhy;
        }

        // A new round opened again waits for its own evidence.
        if ($reopening && $target === self::STATE_OPEN) {
            $after['evidence_sent_at'] = null;
        }

        // Evidence sent.
        if ($this->evidenceAsked) {
            if ($this->evidenceAt !== null || $after['evidence_sent_at'] === null || $reopening) {
                $after['evidence_sent_at'] = $this->evidenceAt ?? substr($this->asOfAsked ?? $now, 0, 19);
            }

            if ($after['evidence_sent_at'] !== ($before['evidence_sent_at'] ?? null)) {
                $events[] = ['event' => 'dispute.evidence_sent', 'text' => self::text('COM_ALFA_ORDER_HISTORY_DISPUTE_EVIDENCE_SENT', 0)];
            }
        }

        // The facts.
        $amountRefusal = $this->applyAmount(after: $after, payment: $payment, currency: $currency);

        if ($amountRefusal !== null) {
            return $amountRefusal;
        }

        if ($this->reasonAsked !== null) {
            [$after['reason_code'], $after['reason_text']] = $this->reasonAsked;
        }

        if ($this->dueAsked) {
            if ($this->dueAt !== null && $this->dueAt > gmdate('Y-m-d H:i:s', (int) strtotime('+365 days'))) {
                return self::refused(code: DisputeVerdict::CODE_VALUE, message: sprintf('The deadline %s is more than a year ahead.', $this->dueAt), disputeId: (int) ($before['id'] ?? 0));
            }

            $after['evidence_due_by'] = $this->dueAt;
        }

        if ($this->feeAsked !== null) {
            [$after['fee_amount'], $after['fee_currency']] = $this->feeAsked;
        }

        if ($this->feeReturnedAsked !== null) {
            if ($after['fee_currency'] !== null && $after['fee_currency'] !== $this->feeReturnedAsked[1]) {
                return self::refused(code: DisputeVerdict::CODE_VALUE, message: sprintf('The fee returned is in %s, the fee in %s.', $this->feeReturnedAsked[1], (string) $after['fee_currency']), disputeId: (int) ($before['id'] ?? 0));
            }

            $after['fee_returned'] = $this->feeReturnedAsked[0];
            $after['fee_currency'] ??= $this->feeReturnedAsked[1];
        }

        if ($this->linkAsked !== null) {
            $after['provider_link'] = $this->linkAsked;
        }

        if ($this->referenceAsked !== null) {
            $after['reference'] = $this->referenceAsked !== '' ? $this->referenceAsked : null;

            if ($after['reference'] !== null && self::referenceTaken(db: $db, paymentId: (int) $payment->id, reference: (string) $after['reference'], except: (int) ($before['id'] ?? 0))) {
                return self::refused(code: DisputeVerdict::CODE_VALUE, message: sprintf('Another dispute of payment #%d carries that reference.', (int) $payment->id), disputeId: (int) ($before['id'] ?? 0));
            }
        }

        if ($this->noteAsked !== null && $this->noteAsked !== '' && $this->noteAsked !== (string) $after['note']) {
            $after['note'] = $this->noteAsked;
            $events[] = ['event' => 'dispute.noted', 'text' => self::text('COM_ALFA_ORDER_HISTORY_DISPUTE_NOTED', 0, $this->noteAsked)];
        }

        // A new record needs its amount; a new provider record of a payment with a record made by hand waits.
        if ($before === null) {
            if ($after['stated_amount'] === null) {
                return self::refused(code: DisputeVerdict::CODE_AMOUNT, message: sprintf('A new dispute of payment #%d states its amount: amount().', (int) $payment->id));
            }

            if (!$staff) {
                $candidates = self::candidatesOf(db: $db, record: (object) $after, records: null);

                if ($candidates !== []) {
                    $after['waits_for'] = self::WAITS_SAME_PAYMENT;
                    $after['joined'] = 0;
                }
            }
        }

        // What staff must still decide before the record books money.
        if ($after['waits_for'] !== self::WAITS_SAME_PAYMENT) {
            $after['waits_for'] = $after['outcome'] === self::STATE_LOST && $after['amount'] === null ? self::WAITS_AMOUNT_UNKNOWN : null;
        }

        if ($after['waits_for'] !== null && $after['waits_for'] !== ($before['waits_for'] ?? null)) {
            $events[] = ['event' => 'dispute.waits', 'text' => self::text('COM_ALFA_ORDER_HISTORY_DISPUTE_WAITS', 0, self::text('COM_ALFA_DISPUTE_WAITS_' . strtoupper((string) $after['waits_for'])))];
        }

        // The facts that changed, each with its old and new value.
        $changed = [];

        if ($before !== null) {
            foreach (['amount', 'stated_amount', 'stated_currency', 'reason_code', 'reason_text', 'evidence_due_by', 'fee_amount', 'fee_currency', 'fee_returned', 'provider_link', 'reference'] as $column) {
                if (!self::same(column: $column, a: $before[$column] ?? null, b: $after[$column] ?? null)) {
                    $changed[$column] = [$before[$column] ?? null, $after[$column] ?? null];
                }
            }

            // A figure stated in the order's currency is the amount: one entry says it.
            if (isset($changed['amount'], $changed['stated_amount']) && (string) $after['stated_currency'] === $currency->getCode()) {
                unset($changed['stated_amount']);
            }

            foreach ($changed as $column => [$old, $new]) {
                $events[] = ['event' => 'dispute.changed', 'text' => self::text('COM_ALFA_ORDER_HISTORY_DISPUTE_CHANGED', 0, self::text('COM_ALFA_DISPUTE_FACT_' . strtoupper($column)), self::shown(value: $old), self::shown(value: $new))];
            }
        }

        $dirty = $before === null;

        foreach (self::COMPARED as $column) {
            if (!$dirty && !self::same(column: $column, a: $before[$column] ?? null, b: $after[$column] ?? null)) {
                $dirty = true;
            }
        }

        if (!$dirty) {
            return new DisputeVerdict(kind: DisputeVerdict::WRITTEN, message: sprintf('Dispute #%d is already as asked; nothing new was written.', (int) $before['id']), disputeId: (int) $before['id'], existing: true, waitsForStaff: $after['waits_for'] !== null);
        }

        if (!$staff) {
            $after['provider_as_of'] = $this->asOfAsked !== null ? self::micro(value: $this->asOfAsked) : self::micro(value: Factory::getDate('now', 'UTC')->format('Y-m-d H:i:s.u'));

            if ($this->responseAsked !== null) {
                $after['gateway_response'] = $this->responseAsked;
            }
        }

        $after['modified'] = $now;
        $after['modified_by'] = $this->writer->userId();

        return ['before' => $before, 'after' => $after, 'events' => $events, 'changed' => $changed];
    }

    /**
     * The state the save moves the record to, or the refusal of the move.
     *
     * @param string|null $current The stored state; null for a new record.
     * @param bool $staff Staff write.
     * @param bool $byHand The record is made by hand and not joined to a provider.
     * @param array|null $before The record as stored.
     *
     * @since 2.2.2
     */
    private function targetState(?string $current, bool $staff, bool $byHand, ?array $before): string|DisputeVerdict
    {
        $asked = $this->stateAsked;
        $id = (int) ($before['id'] ?? 0);
        $refuse = static fn (string $to): DisputeVerdict => self::refused(code: DisputeVerdict::CODE_TRANSITION, message: sprintf(
            'Dispute%s cannot move from %s to %s.',
            $id > 0 ? ' #' . $id : '',
            $current ?? 'no record',
            $to,
        ), disputeId: $id);

        // Evidence sent with no state named: an open chargeback goes under review, an inquiry stays one, a decided case
        // starts a new round under review.
        if ($asked === null && $this->evidenceAsked) {
            $asked = match ($current) {
                null, self::STATE_OPEN, self::STATE_UNDER_REVIEW, self::STATE_WON, self::STATE_LOST => self::STATE_UNDER_REVIEW,
                default => $current,
            };
        } elseif ($asked === self::STATE_OPEN && $this->evidenceAsked) {
            $asked = self::STATE_UNDER_REVIEW;
        }

        if ($current === null) {
            if ($asked === null) {
                return self::refused(code: DisputeVerdict::CODE_TRANSITION, message: 'A new dispute names its state: inquiry(), open() and, from a provider, evidenceSent(), won() or lost().');
            }

            // Staff record by hand an inquiry or a chargeback; a provider's first word may come late, in a later state.
            $allowed = $staff ? [self::STATE_INQUIRY, self::STATE_OPEN] : [self::STATE_INQUIRY, self::STATE_OPEN, self::STATE_UNDER_REVIEW, self::STATE_WON, self::STATE_LOST];

            return \in_array($asked, $allowed, true) ? $asked : $refuse($asked);
        }

        if ($asked === null || $asked === $current) {
            return $current;
        }

        if ($asked === self::STATE_CLOSED) {
            if ($this->closedWhy === self::CLOSED_INQUIRY_ENDED) {
                return $current === self::STATE_INQUIRY ? self::STATE_CLOSED : $refuse('closed (an inquiry that ended)');
            }

            if (!$byHand) {
                return self::refused(code: DisputeVerdict::CODE_STAFF_ONLY, message: sprintf('Dispute #%d follows its provider: only a record made by hand is closed as recorded in error.', $id), disputeId: $id);
            }

            return self::STATE_CLOSED;
        }

        $moves = [
            self::STATE_INQUIRY => [self::STATE_OPEN, self::STATE_WON, self::STATE_LOST],
            self::STATE_OPEN => [self::STATE_UNDER_REVIEW, self::STATE_WON, self::STATE_LOST],
            self::STATE_UNDER_REVIEW => $staff ? [self::STATE_WON, self::STATE_LOST] : [self::STATE_OPEN, self::STATE_WON, self::STATE_LOST],
            self::STATE_WON => [self::STATE_LOST, self::STATE_OPEN, self::STATE_UNDER_REVIEW],
            self::STATE_LOST => [self::STATE_WON, self::STATE_OPEN, self::STATE_UNDER_REVIEW],
        ];

        return \in_array($asked, $moves[$current] ?? [], true) ? $asked : $refuse($asked);
    }

    /**
     * Apply the stated amount and the order's figure to the new row; the refusal when one cannot be taken.
     *
     * @param array<string, mixed> $after The new row, changed in place.
     * @param object $payment The disputed payment.
     * @param Currency $currency The order's currency at its frozen scale.
     *
     * @since 2.2.2
     */
    private function applyAmount(array &$after, object $payment, Currency $currency): ?DisputeVerdict
    {
        $id = (int) ($after['id'] ?? 0);
        $paymentAmount = Money::of((string) $payment->amount, $currency)->abs();

        if ($this->amountAsked !== null) {
            [$amount, $code] = $this->amountAsked;
            $restated = $after['stated_amount'] !== null && self::same(column: 'stated_amount', a: $after['stated_amount'], b: $amount) && (string) $after['stated_currency'] === $code;
            $after['stated_amount'] = $amount;
            $after['stated_currency'] = $code;

            if ($code === $currency->getCode()) {
                $exact = self::exact(amount: $amount, currency: $currency, id: $id);

                if ($exact instanceof DisputeVerdict) {
                    return $exact;
                }

                $after['amount'] = StoredDecimal::of($exact);
            } elseif (!$restated) {
                // Another currency: the order's figure is not known until the gateway or staff give it; core converts
                // nothing.
                $after['amount'] = null;
            }
        }

        if ($this->orderAmountAsked !== null) {
            if ($after['stated_currency'] === null || (string) $after['stated_currency'] === $currency->getCode()) {
                return self::refused(code: DisputeVerdict::CODE_AMOUNT, message: sprintf('Dispute #%d is stated in the order\'s currency: its amount is that figure; amountInOrderCurrency() is for a dispute stated in another currency.', $id), disputeId: $id);
            }

            $exact = self::exact(amount: $this->orderAmountAsked, currency: $currency, id: $id);

            if ($exact instanceof DisputeVerdict) {
                return $exact;
            }

            $after['amount'] = StoredDecimal::of($exact);
        }

        if ($after['amount'] !== null && Money::of((string) $after['amount'], $currency)->greaterThan($paymentAmount)) {
            return self::refused(code: DisputeVerdict::CODE_AMOUNT, message: sprintf(
                'The disputed amount %s is more than payment #%d (%s).',
                Money::of((string) $after['amount'], $currency)->format(),
                (int) $payment->id,
                $paymentAmount->format(),
            ), disputeId: $id);
        }

        return null;
    }

    /**
     * Write the plan: the record, the money its decision owes, and what tells it; the verdict.
     *
     * @param DatabaseDriver $db The connection, in the save's transaction.
     * @param array<string, mixed> $plan The plan.
     * @param Currency $currency The order's currency.
     * @param object $payment The disputed payment.
     *
     * @since 2.2.2
     */
    private function write(DatabaseDriver $db, array $plan, Currency $currency, object $payment): DisputeVerdict
    {
        $before = $plan['before'];
        $after = $plan['after'];
        $id = self::store(db: $db, row: $after);
        $after['id'] = $id;

        $booked = $this->book(db: $db, record: $after, before: $before, currency: $currency, changed: $plan['changed']);

        if ($booked instanceof DisputeVerdict) {
            return $booked;
        }

        $after['bookings'] = $booked;
        $this->tell(record: $after, before: $before, events: $plan['events'], payment: $payment, currency: $currency);

        return new DisputeVerdict(
            kind: DisputeVerdict::WRITTEN,
            message: sprintf('Dispute #%d is written: %s.', $id, (string) $after['state']),
            disputeId: $id,
            existing: $before !== null,
            waitsForStaff: $after['waits_for'] !== null,
        );
    }

    /**
     * The reconcile answer of staff on a provider's record that waits for it: Same dispute, or A different one.
     *
     * @param DatabaseDriver $db The connection, in the save's transaction.
     * @param object|null $record The provider's record.
     * @param Currency $currency The order's currency.
     * @param object $payment The disputed payment.
     *
     * @since 2.2.2
     */
    private function reconcile(DatabaseDriver $db, ?object $record, Currency $currency, object $payment): DisputeVerdict
    {
        $id = (int) ($record->id ?? 0);

        if ($record === null || (string) $record->waits_for !== self::WAITS_SAME_PAYMENT) {
            return self::refused(code: DisputeVerdict::CODE_TRANSITION, message: sprintf('Dispute #%d does not wait for the reconcile answer.', $id), disputeId: $id);
        }

        $before = (array) $record;
        $now = Factory::getDate('now', 'UTC')->toSql();

        if ($this->notTheSameAsked) {
            $after = $before;
            $after['waits_for'] = $after['outcome'] === self::STATE_LOST && $after['amount'] === null ? self::WAITS_AMOUNT_UNKNOWN : null;
            $after['joined'] = 1;
            $after['modified'] = $now;
            $after['modified_by'] = $this->writer->userId();
            $candidates = implode(', #', array_map(static fn (object $one): int => (int) $one->id, self::candidatesOf(db: $db, record: $record, records: null)));

            return $this->write(db: $db, plan: [
                'before' => $before,
                'after' => $after,
                'events' => [['event' => 'dispute.separate', 'text' => self::text('COM_ALFA_ORDER_HISTORY_DISPUTE_SEPARATE', 0, '#' . $candidates)]],
                'changed' => [],
            ], currency: $currency, payment: $payment);
        }

        $hand = self::lockedRecord(db: $db, where: $db->quoteName('id') . ' = ' . $this->sameAsAsked);
        $isCandidate = $hand !== null && \in_array((int) $hand->id, array_map(static fn (object $one): int => (int) $one->id, self::candidatesOf(db: $db, record: $record, records: null)), true);

        if (!$isCandidate) {
            return self::refused(code: DisputeVerdict::CODE_TRANSITION, message: sprintf('Dispute #%d is not a record made by hand of the same payment that waits to be joined.', $this->sameAsAsked), disputeId: $id);
        }

        // The provider's record closes as merged and gives up its reference first, so the record made by hand can take
        // it under the unique key; it never booked anything.
        $merged = $before;
        $merged['state'] = self::STATE_CLOSED;
        $merged['closed_reason'] = self::CLOSED_MERGED;
        $merged['merged_into'] = (int) $hand->id;
        $merged['outcome'] = null;
        $merged['waits_for'] = null;
        $merged['reference'] = null;
        $merged['modified'] = $now;
        $merged['modified_by'] = $this->writer->userId();
        self::store(db: $db, row: $merged);

        // The record made by hand takes the provider's reference and latest word, follows the provider from now on and
        // books by its state.
        $handBefore = (array) $hand;
        $joined = $handBefore;

        foreach (['state', 'outcome', 'stage', 'amount', 'stated_amount', 'stated_currency', 'reason_code', 'reason_text', 'evidence_due_by', 'evidence_sent_at', 'fee_amount', 'fee_currency', 'fee_returned', 'provider_link', 'provider_as_of', 'gateway_response', 'decided_at'] as $column) {
            $joined[$column] = $before[$column];
        }

        $joined['stage'] = max((int) $handBefore['stage'], (int) $before['stage']);
        $joined['reference'] = $before['reference'];
        $joined['joined'] = 1;
        $joined['closed_reason'] = null;
        $joined['waits_for'] = $joined['outcome'] === self::STATE_LOST && $joined['amount'] === null ? self::WAITS_AMOUNT_UNKNOWN : null;
        $joined['modified'] = $now;
        $joined['modified_by'] = $this->writer->userId();

        $this->tell(record: $merged, before: $before, events: [['event' => 'dispute.joined', 'text' => self::text('COM_ALFA_ORDER_HISTORY_DISPUTE_JOINED', 0, (int) $hand->id)]], payment: $payment, currency: $currency, act: false);

        return $this->write(db: $db, plan: [
            'before' => $handBefore,
            'after' => $joined,
            'events' => [['event' => 'dispute.joined', 'text' => self::text('COM_ALFA_ORDER_HISTORY_DISPUTE_JOINED', 0, $id)]],
            'changed' => [],
        ], currency: $currency, payment: $payment);
    }

    /**
     * Book the difference between what the record's last decision asks the books to hold and what its rows hold, through
     * the payments ledger's verbs; the number of the record's bookings, or the refusal that rolls the save back.
     *
     * The target is the amount when the last decision is lost and the amount is known in the order's currency, zero
     * otherwise; a record that waits for staff books nothing.
     *
     * @param DatabaseDriver $db The connection, in the save's transaction.
     * @param array<string, mixed> $record The record as written.
     * @param array<string, mixed>|null $before The record as stored before the save.
     * @param Currency $currency The order's currency.
     * @param array<string, array{0: mixed, 1: mixed}> $changed The facts that changed.
     *
     * @since 2.2.2
     */
    private function book(DatabaseDriver $db, array $record, ?array $before, Currency $currency, array $changed): int|DisputeVerdict
    {
        $id = (int) $record['id'];
        $bookings = (int) $record['bookings'];

        if ($record['waits_for'] !== null) {
            $this->booked = '0';

            return $bookings;
        }

        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'payment_type', 'amount', 'id_reversed_payment']))
                ->from($db->quoteName(self::PAYMENTS))
                ->where($db->quoteName('id_dispute') . ' = ' . $id)
                ->where($db->quoteName('transaction_status') . ' = ' . $db->quote(PaymentLedger::STATUS_COMPLETED))
                ->order($db->quoteName('id') . ' DESC'),
        )->loadObjectList() ?: [];
        $booked = self::bookedOf(rows: $rows, currency: $currency);
        $target = $record['outcome'] === self::STATE_LOST && $record['amount'] !== null ? Money::of((string) $record['amount'], $currency) : Money::zero($currency);
        $difference = $target->subtract($booked);

        if ($difference->isZero()) {
            $this->booked = '0';

            return $bookings;
        }

        $this->booked = ($difference->isNegative() ? '-' : '') . PublishedDecimal::money(money: $difference->abs());

        $at = $record['decided_at'] !== null && $record['outcome'] !== null ? (string) $record['decided_at'] : Factory::getDate('now', 'UTC')->toSql();
        $why = $this->bookingNote(record: $record, before: $before, changed: $changed, currency: $currency);

        if ($difference->isPositive()) {
            $bookings++;
            $result = PaymentLedger::recordChargeback(paymentId: (int) $record['id_payment'], amount: $difference, options: [
                'dispute' => $id,
                'transaction_id' => 'dispute:' . $id . ':' . $bookings,
                'reason' => $why,
                'processed_at' => $at,
            ]);
            $refusal = self::bookingRefusal(result: $result, id: $id);

            if ($refusal !== null) {
                return $refusal;
            }
        } else {
            // Newest chargeback row first, each by what of it is not reversed yet.
            $left = $difference->abs();
            $reversed = [];

            foreach ($rows as $row) {
                if ($row->payment_type === PaymentLedger::TYPE_REVERSAL) {
                    $reversed[(int) $row->id_reversed_payment] = ($reversed[(int) $row->id_reversed_payment] ?? Money::zero($currency))->add(Money::of((string) $row->amount, $currency)->abs());
                }
            }

            foreach ($rows as $row) {
                if ($row->payment_type !== PaymentLedger::TYPE_CHARGEBACK || !$left->isPositive()) {
                    continue;
                }

                $open = Money::of((string) $row->amount, $currency)->abs()->subtract($reversed[(int) $row->id] ?? Money::zero($currency));

                if (!$open->isPositive()) {
                    continue;
                }

                $take = $open->lessThan($left) ? $open : $left;
                $bookings++;
                $result = PaymentLedger::recordReversal(rowId: (int) $row->id, amount: $take, options: [
                    'dispute' => $id,
                    'transaction_id' => 'dispute:' . $id . ':' . $bookings,
                    'reason' => $why,
                    'processed_at' => $at,
                ]);
                $refusal = self::bookingRefusal(result: $result, id: $id);

                if ($refusal !== null) {
                    return $refusal;
                }

                $left = $left->subtract($take);
            }

            if ($left->isPositive()) {
                return self::refused(code: DisputeVerdict::CODE_BOOKS_OUT_OF_STEP, message: sprintf('Dispute #%d: its chargeback rows hold less than the decision gives back (%s left); staff look.', $id, $left->format()), disputeId: $id);
            }
        }

        $db->setQuery('UPDATE ' . $db->quoteName(self::TABLE) . ' SET ' . $db->quoteName('bookings') . ' = ' . $bookings . ' WHERE ' . $db->quoteName('id') . ' = ' . $id)->execute();

        return $bookings;
    }

    /**
     * The verdict a payments ledger answer leaves the save with: null to go on, else the refusal or the failure.
     *
     * @param array{outcome: string, reason: string} $result The verb's answer.
     * @param int $id The record.
     *
     * @since 2.2.2
     */
    private static function bookingRefusal(array $result, int $id): ?DisputeVerdict
    {
        $reason = (string) ($result['reason'] ?? '');

        return match ((string) ($result['outcome'] ?? '')) {
            PaymentLedger::OUTCOME_RECORDED => null,
            PaymentLedger::OUTCOME_REFUSED => self::refused(code: DisputeVerdict::CODE_BOOKS_REFUSED, message: sprintf('Dispute #%d: the payments ledger refused the booking: %s', $id, $reason), disputeId: $id),
            PaymentLedger::OUTCOME_DUPLICATE, PaymentLedger::OUTCOME_NOTHING_LEFT => self::refused(code: DisputeVerdict::CODE_BOOKS_OUT_OF_STEP, message: sprintf('Dispute #%d: its booking counter and its money rows disagree (%s); staff look.', $id, $reason), disputeId: $id),
            default => str_contains($reason, 'held by another writer')
                ? new DisputeVerdict(kind: DisputeVerdict::LOCK_NOT_TAKEN, message: sprintf('Dispute #%d: the order was held by another operation; nothing was written.', $id), disputeId: $id)
                : new DisputeVerdict(kind: DisputeVerdict::WRITE_FAILED, message: sprintf('Dispute #%d: the booking could not be written; nothing was written.', $id), disputeId: $id),
        };
    }

    /**
     * Why a booking is made, as its row's note says it.
     *
     * @param array<string, mixed> $record The record as written.
     * @param array<string, mixed>|null $before The record before.
     * @param array<string, array{0: mixed, 1: mixed}> $changed The facts that changed.
     * @param Currency $currency The order's currency.
     *
     * @since 2.2.2
     */
    private function bookingNote(array $record, ?array $before, array $changed, Currency $currency): string
    {
        $id = (int) $record['id'];
        $wasOutcome = $before['outcome'] ?? null;

        return match (true) {
            $record['state'] === self::STATE_CLOSED => Text::sprintf('COM_ALFA_DISPUTE_BOOKING_CLOSED', $id),
            $record['outcome'] === self::STATE_LOST && $wasOutcome === self::STATE_LOST && isset($changed['amount']) && $changed['amount'][0] !== null => Text::sprintf(
                'COM_ALFA_DISPUTE_BOOKING_CORRECTED',
                $id,
                Money::of((string) $changed['amount'][0], $currency)->format(),
                Money::of((string) $record['amount'], $currency)->format(),
            ),
            $record['outcome'] === self::STATE_LOST => Text::sprintf('COM_ALFA_DISPUTE_BOOKING_LOST', $id),
            default => Text::sprintf('COM_ALFA_DISPUTE_BOOKING_WON', $id),
        };
    }

    /**
     * Tell the save: the timeline entries, the action log row of a staff act and the notice, each in the save's
     * transaction; a failure throws and rolls the save back.
     *
     * @param array<string, mixed> $record The record as written.
     * @param array<string, mixed>|null $before The record before.
     * @param list<array{event: string, text: array}> $events The timeline entries owed.
     * @param object $payment The disputed payment.
     * @param Currency $currency The order's currency.
     * @param bool $act Whether this record carries the staff act's action log row.
     *
     * @since 2.2.2
     */
    private function tell(array $record, ?array $before, array $events, object $payment, Currency $currency, bool $act = true): void
    {
        $id = (int) $record['id'];
        $orderId = (int) $record['id_order'];
        $staff = $this->writer->isStaff();
        $amount = self::amountText(record: $record, currency: $currency);

        if ($before === null) {
            array_unshift($events, $staff
                ? ['event' => 'dispute.recorded', 'text' => self::text('COM_ALFA_ORDER_HISTORY_DISPUTE_RECORDED', $id, (int) $payment->id, self::stateText(state: (string) $record['state']), $amount)]
                : ['event' => 'dispute.reported', 'text' => self::text('COM_ALFA_ORDER_HISTORY_DISPUTE_REPORTED', $id, (string) $payment->payment_method, (int) $payment->id, self::stateText(state: (string) $record['state']), $amount)]);
        }

        foreach ($events as $entry) {
            // The record's id is known once it is written.
            $text = $entry['text'];

            if (($text['args'][0] ?? null) === 0) {
                $text['args'][0] = $id;
            }

            OrderModel::logOrderActivityStrict(
                orderId: $orderId,
                event: $entry['event'],
                summary: self::english(text: $text),
                context: [
                    'dispute' => $id,
                    'payment' => (int) $record['id_payment'],
                    'state_before' => $before['state'] ?? null,
                    'state_after' => (string) $record['state'],
                    'outcome' => $record['outcome'],
                    'amount' => $record['amount'],
                    'stated_amount' => $record['stated_amount'],
                    'stated_currency' => $record['stated_currency'],
                    'reference' => $record['reference'],
                    'writer' => $staff ? 'user:' . $this->writer->userId() : 'plugin:' . $this->writer->element,
                    'text' => $text,
                ],
                entityId: $id,
                actorId: $this->writer->userId(),
            );
        }

        if ($staff && $act) {
            $this->writeActionLog(record: $record, before: $before, events: $events, amount: $amount);
        }

        self::notify(record: $record);
        self::owe(record: $record, before: $before, booked: $this->booked);

        // The consumers of an order's dispute changes are owed this one, written with it, in the order's sequence. A
        // record the queue cannot write is logged CRITICAL and the save stands: the queue's rule for every writer.
        OutboundFeed::record(
            db: TransactionState::driver(self::db()),
            topic: OutboundTopics::ORDER_DISPUTE,
            subjectId: $orderId,
            payload: $id . ':' . (string) $record['state'],
        );
    }

    /**
     * What a save owes after its commit, written in its transaction: the staff e-mail when a dispute is recorded or
     * first reported and at its outcome (only when the shop has a store e-mail address), the reminder of a deadline set
     * or moved, and onAlfaDisputeChanged when the event has a listener.
     *
     * @param array<string, mixed> $record The record as written.
     * @param array<string, mixed>|null $before The record before.
     * @param string $booked What the save booked, decimal text in the order's currency.
     *
     * @since 2.2.2
     */
    private static function owe(array $record, ?array $before, string $booked): void
    {
        $state = (string) $record['state'];
        $from = $before === null ? '' : (string) $before['state'];
        $started = $before === null && \in_array($state, self::UNDECIDED_STATES, true);
        $decided = $state !== $from && \in_array($state, [self::STATE_WON, self::STATE_LOST, self::STATE_CLOSED], true)
            && (string) ($record['closed_reason'] ?? '') !== self::CLOSED_MERGED;

        if (($started || $decided) && OrderDisputeEmail::recipient() !== '') {
            BackgroundWork::core()->task(OrderDisputeEmail::NAME)->about('order', (int) $record['id_order'])->with(['dispute' => (int) $record['id'], 'state' => $state])->enqueue();
        }

        // A deadline set or moved on a case that waits for its answer owes its reminder; one of another deadline ends
        // with nothing.
        $due = $record['evidence_due_by'];

        if ($due !== null && $due !== ($before['evidence_due_by'] ?? null) && \in_array($state, [self::STATE_INQUIRY, self::STATE_OPEN], true) && $record['evidence_sent_at'] === null) {
            DisputeRemindJob::owe(disputeId: (int) $record['id'], dueBy: (string) $due);
        }

        if (OrderDisputeEvent::hasListeners()) {
            BackgroundWork::core()->task(OrderDisputeEvent::NAME)->about('order', (int) $record['id_order'])->with([
                'dispute' => (int) $record['id'],
                'payment' => (int) $record['id_payment'],
                'from' => $from,
                'to' => $state,
                'booked' => $booked,
            ])->enqueue();
        }
    }

    /**
     * The row in Joomla's User Actions Log for a staff act; a row that cannot be written fails the save.
     *
     * @param array<string, mixed> $record The record as written.
     * @param array<string, mixed>|null $before The record before.
     * @param list<array{event: string, text: array}> $events The timeline entries of the save.
     * @param string $amount The disputed amount, as shown.
     *
     * @since 2.2.2
     */
    private function writeActionLog(array $record, ?array $before, array $events, string $amount): void
    {
        $kinds = array_column($events, 'event');
        $key = match (true) {
            $before === null => 'COM_ALFA_ACTIONLOG_DISPUTE_RECORDED',
            \in_array('dispute.joined', $kinds, true), \in_array('dispute.separate', $kinds, true) => 'COM_ALFA_ACTIONLOG_DISPUTE_RECONCILED',
            $this->orderAmountAsked !== null => 'COM_ALFA_ACTIONLOG_DISPUTE_AMOUNT_TYPED',
            \in_array('dispute.won', $kinds, true), \in_array('dispute.lost', $kinds, true), \in_array('dispute.closed', $kinds, true) => 'COM_ALFA_ACTIONLOG_DISPUTE_CLOSED',
            \in_array('dispute.reopened', $kinds, true) => 'COM_ALFA_ACTIONLOG_DISPUTE_REOPENED',
            \in_array('dispute.evidence_sent', $kinds, true) => 'COM_ALFA_ACTIONLOG_DISPUTE_EVIDENCE_SENT',
            default => 'COM_ALFA_ACTIONLOG_DISPUTE_CHANGED',
        };
        $orderId = (int) $record['id_order'];
        $written = ActionLogHelper::write(
            extension: 'com_alfa',
            languageKey: $key,
            facts: [
                'title' => '#' . $orderId,
                'itemlink' => 'index.php?option=com_alfa&task=order.edit&id=' . $orderId,
                'dispute' => (int) $record['id'],
                'payment' => (int) $record['id_payment'],
                'state' => (string) $record['state'],
                'outcome' => (string) ($record['outcome'] ?? ''),
                'amount' => $amount,
            ],
            itemId: $orderId,
            actor: $this->writer->user ?? new User(0),
        );

        if (!$written) {
            throw new RuntimeException(sprintf('The action log row of dispute #%d could not be written.', (int) $record['id']));
        }
    }

    /**
     * The record's one notice, re-pushed at each state with new words: payments.dispute.<id>; it resurfaces while the
     * case needs staff.
     *
     * @param array<string, mixed> $record The record as written.
     *
     * @since 2.2.2
     */
    private static function notify(array $record): void
    {
        $orderId = (int) $record['id_order'];
        [$title, $message, $severity] = self::noticeTexts(record: (object) $record, state: (string) $record['state']);

        NotificationHelper::push(
            dedupKey: self::NOTICE_PREFIX . (int) $record['id'],
            title:    $title,
            options:  [
                'severity' => $severity,
                'group' => NotificationHelper::GROUP_PAYMENTS,
                'message' => $message,
                'constant' => self::needsStaff(record: (object) $record),
                'url' => 'index.php?option=com_alfa&task=order.edit&id=' . $orderId,
                'view' => ['action' => 'alfa.orders.view', 'asset' => 'com_alfa'],
                'link' => ['action' => 'alfa.orders.edit', 'asset' => 'com_alfa'],
            ],
        );
    }

    /**
     * What a dispute's notice and its staff e-mail say for a state: the title, the message and the notice's severity.
     * What happened, whether it matters, what Alfa did and what to do; a record that waits for staff asks its question.
     *
     * @param object $record The record (as stored or as written).
     * @param string $state The state to speak of (the e-mail's state when it was owed).
     *
     * @return array{0: NotificationText, 1: NotificationText, 2: string}
     *
     * @since 2.2.2
     */
    public static function noticeTexts(object $record, string $state): array
    {
        $db = self::db();
        $id = (int) $record->id;
        $orderId = (int) $record->id_order;
        $payment = $db->setQuery('SELECT ' . $db->quoteName(['id', 'payment_method']) . ' FROM ' . $db->quoteName(self::PAYMENTS) . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $record->id_payment)->loadObject();
        $provider = (string) ($payment->payment_method ?? '');
        $paymentId = (int) $record->id_payment;
        $currency = OrderCurrency::find(orderId: $orderId);
        $amount = $currency === null ? (string) $record->stated_amount . ' ' . (string) $record->stated_currency : self::amountText(record: (array) $record, currency: $currency);
        $due = ($record->evidence_due_by ?? null) !== null ? NotificationText::date(utc: (string) $record->evidence_due_by) : null;
        $waits = (string) ($record->waits_for ?? '');

        if ($waits === self::WAITS_SAME_PAYMENT) {
            $candidates = self::candidatesOf(db: $db, record: $record, records: null);

            return [
                NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_SAME_TITLE', $orderId),
                NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_SAME_MSG', $provider, (string) $record->reference, $paymentId, '#' . implode(', #', array_map(static fn (object $one): int => (int) $one->id, $candidates))),
                'warning',
            ];
        }

        if ($waits === self::WAITS_AMOUNT_UNKNOWN) {
            return [NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_AMOUNT_TITLE', $orderId), NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_AMOUNT_MSG', $id, $amount), 'danger'];
        }

        if ($state === OrderDisputeEmail::REMINDER) {
            return [NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_REMIND_TITLE', $orderId, $due ?? ''), NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_REMIND_MSG', $id, $amount, $due ?? '', $provider), 'danger'];
        }

        return match ($state) {
            self::STATE_INQUIRY => [
                NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_INQUIRY_TITLE', $orderId),
                $due === null ? NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_INQUIRY_MSG', $amount, $paymentId, $provider) : NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_INQUIRY_DUE_MSG', $amount, $paymentId, $provider, $due),
                'warning',
            ],
            self::STATE_OPEN => [
                $due === null ? NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_OPEN_TITLE', $orderId) : NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_OPEN_DUE_TITLE', $orderId, $due),
                $due === null ? NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_OPEN_MSG', $amount, $paymentId, $provider) : NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_OPEN_DUE_MSG', $amount, $paymentId, $provider, $due),
                'danger',
            ],
            self::STATE_UNDER_REVIEW => [NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_REVIEW_TITLE', $orderId), NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_REVIEW_MSG', $id, $amount), 'info'],
            self::STATE_WON => [NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_WON_TITLE', $orderId), NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_WON_MSG', $id, $amount), 'success'],
            self::STATE_LOST => [NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_LOST_TITLE', $orderId), NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_LOST_MSG', $id, $amount), 'danger'],
            default => [NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_CLOSED_TITLE', $orderId), NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_CLOSED_MSG', $id), 'info'],
        };
    }

    /**
     * A provider's word a rule refused, made durable for staff in its own transaction: a notice of what the provider
     * reported and why Alfa did not record it. The refusal, or write_failed when the notice cannot be written. Staff's
     * refusals and the other kinds pass as they are.
     *
     * @param DisputeVerdict $verdict The verdict.
     * @param int $orderId The order, 0 when not known.
     * @param int $paymentId The payment.
     *
     * @since 2.2.2
     */
    private function refusedWord(DisputeVerdict $verdict, int $orderId, int $paymentId): DisputeVerdict
    {
        if ($this->writer->isStaff() || !$verdict->isRefused()) {
            return $verdict;
        }

        try {
            $key = self::NOTICE_PREFIX . 'refused.' . $paymentId . '.' . substr(hash('sha256', $this->lookup), 0, 16);
            NotificationHelper::push(
                dedupKey: $key,
                title:    NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_REFUSED_TITLE', $orderId),
                options:  [
                    'severity' => 'danger',
                    'group' => NotificationHelper::GROUP_PAYMENTS,
                    'message' => NotificationText::of('COM_ALFA_NOTIFY_DISPUTE_REFUSED_MSG', $this->writer->element, $this->lookup !== '' ? $this->lookup : '-', $paymentId, $orderId, $verdict->message),
                    'url' => $orderId > 0 ? 'index.php?option=com_alfa&task=order.edit&id=' . $orderId : 'index.php?option=com_alfa&view=orders',
                    'view' => ['action' => 'alfa.orders.view', 'asset' => 'com_alfa'],
                    'link' => ['action' => 'alfa.orders.edit', 'asset' => 'com_alfa'],
                ],
            );
        } catch (Throwable $e) {
            self::logFailure(orderId: $orderId, paymentId: $paymentId, disputeId: $verdict->disputeId, failure: $e);

            return new DisputeVerdict(kind: DisputeVerdict::WRITE_FAILED, message: 'A refused provider word could not be kept for staff; nothing was written. The reason is in the payments log.', disputeId: $verdict->disputeId);
        }

        return $verdict;
    }

    // Helpers.

    /**
     * Record the state the chain asks.
     *
     * @param string $state The state.
     *
     * @since 2.2.2
     */
    private function state(string $state): static
    {
        if ($this->stateAsked !== null && $this->stateAsked !== $state) {
            $this->malform(code: DisputeVerdict::CODE_TRANSITION, sentence: sprintf('one word names one state; it named %s and %s', $this->stateAsked, $state));
        }

        $this->stateAsked = $state;

        return $this;
    }

    /**
     * Keep the first malformed value the chain was given.
     *
     * @param string $code The refusal code.
     * @param string $sentence What is wrong.
     *
     * @since 2.2.2
     */
    private function malform(string $code, string $sentence): void
    {
        $this->malformed ??= [$code, $sentence];
    }

    /**
     * A UTC time as stored, or the time given when it is malformed (the save then refuses it).
     *
     * @param string $value The time.
     * @param string $what The call, for the refusal.
     * @param bool $micro Microseconds are allowed.
     *
     * @since 2.2.2
     */
    private function utc(string $value, string $what, bool $micro = false): string
    {
        $pattern = $micro ? '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/' : '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
        $parsed = preg_match($pattern, $value) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($value, 0, 19), new DateTimeZone('UTC')) : false;
        $valid = $parsed !== false && $parsed->format('Y-m-d H:i:s') === substr($value, 0, 19);

        if (!$valid) {
            $this->malform(code: DisputeVerdict::CODE_VALUE, sentence: sprintf('%s takes a UTC time as Y-m-d H:i:s, not "%s"', $what, $value));
        }

        return $value;
    }

    /**
     * A fee's figure and currency, as stored.
     *
     * @param string $amount The figure.
     * @param string $currency Its ISO 4217 code.
     * @param string $what The call, for the refusal.
     *
     * @return array{0: string, 1: string}
     *
     * @since 2.2.2
     */
    private function feeValue(string $amount, string $currency, string $what): array
    {
        if (!self::isDecimal(value: $amount) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $this->malform(code: DisputeVerdict::CODE_VALUE, sentence: sprintf('%s takes a decimal text and an ISO 4217 code, not "%s %s"', $what, $amount, $currency));
        }

        return [$amount, $currency];
    }

    /**
     * Whether a text is a non-negative decimal a decimal(24,8) column holds.
     *
     * @param string $value The text.
     *
     * @since 2.2.2
     */
    private static function isDecimal(string $value): bool
    {
        return preg_match('/^\d{1,16}(\.\d{1,8})?$/', $value) === 1;
    }

    /**
     * Whether a decimal text is zero.
     *
     * @param string $value The text.
     *
     * @since 2.2.2
     */
    private static function isZeroDecimal(string $value): bool
    {
        return preg_match('/^0+(\.0+)?$/', $value) === 1;
    }

    /**
     * An amount in the order's currency, exactly, or the refusal (not a number, finer than the order's scale).
     *
     * @param string $amount The decimal text.
     * @param Currency $currency The order's currency at its frozen scale.
     * @param int $id The record, for the refusal.
     *
     * @since 2.2.2
     */
    private static function exact(string $amount, Currency $currency, int $id): Money|DisputeVerdict
    {
        try {
            return ExactMoney::of(value: $amount, currency: $currency);
        } catch (ExactMoneyRefusal $e) {
            return self::refused(code: DisputeVerdict::CODE_AMOUNT, message: sprintf(
                'The amount %s cannot be taken in %s at %d decimals: %s',
                $amount,
                $currency->getCode(),
                $currency->getDecimalPlaces(),
                $e->words(field: 'amount'),
            ), disputeId: $id);
        }
    }

    /**
     * Why a payment cannot be disputed, or null: only a collected payment row of a bank's money (completed or refunded,
     * not from the customer's wallet).
     *
     * @param object $payment The payment row.
     *
     * @since 2.2.2
     */
    private static function collectedRefusal(object $payment): ?DisputeVerdict
    {
        $id = (int) $payment->id;

        if ((string) $payment->payment_type !== PaymentLedger::TYPE_PAYMENT
            || !\in_array((string) $payment->transaction_status, [PaymentLedger::STATUS_COMPLETED, PaymentLedger::STATUS_REFUNDED], true)) {
            return self::refused(code: DisputeVerdict::CODE_NOT_COLLECTED, message: sprintf('Row #%d is a %s %s: only a completed or refunded payment can be disputed.', $id, (string) $payment->transaction_status, (string) $payment->payment_type));
        }

        if (\in_array((string) $payment->payment_method_type, PaymentLedger::NON_GATEWAY_TYPES, true) || (int) ($payment->id_credit_transaction ?? 0) > 0) {
            return self::refused(code: DisputeVerdict::CODE_WALLET, message: sprintf('Payment #%d was paid from the customer\'s wallet; no bank can dispute it.', $id));
        }

        return null;
    }

    /**
     * A record read with a locking read, so a delivery committed meanwhile is seen; null when none.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $where The condition.
     *
     * @since 2.2.2
     */
    private static function lockedRecord(DatabaseDriver $db, string $where): ?object
    {
        return $db->setQuery('SELECT * FROM ' . $db->quoteName(self::TABLE) . ' WHERE ' . $where . ' FOR UPDATE')->loadObject() ?: null;
    }

    /**
     * The records made by hand of the same payment that a provider's record may be the same case as: not yet joined to a
     * provider and not closed.
     *
     * @param DatabaseDriver $db The connection.
     * @param object $record The provider's record (id, id_payment).
     * @param list<object>|null $records The order's records when already read; null to read them.
     *
     * @return list<object>
     *
     * @since 2.2.2
     */
    private static function candidatesOf(DatabaseDriver $db, object $record, ?array $records): array
    {
        $records ??= $db->setQuery(
            'SELECT * FROM ' . $db->quoteName(self::TABLE) . ' WHERE ' . $db->quoteName('id_payment') . ' = ' . (int) $record->id_payment,
        )->loadObjectList() ?: [];

        return array_values(array_filter($records, static fn (object $one): bool => (int) $one->id !== (int) ($record->id ?? 0)
            && (int) $one->id_payment === (int) $record->id_payment
            && \in_array((string) $one->source, [self::SOURCE_STAFF, self::SOURCE_MIGRATED], true)
            && (int) $one->joined === 0
            && (string) $one->state !== self::STATE_CLOSED));
    }

    /**
     * Whether another record of the payment carries a reference.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $paymentId The payment.
     * @param string $reference The reference.
     * @param int $except The record itself, 0 for none.
     *
     * @since 2.2.2
     */
    private static function referenceTaken(DatabaseDriver $db, int $paymentId, string $reference, int $except): bool
    {
        return (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName(self::TABLE)
            . ' WHERE ' . $db->quoteName('id_payment') . ' = ' . $paymentId
            . ' AND ' . $db->quoteName('reference_hash') . ' = ' . $db->quote(hash('sha256', $reference))
            . ' AND ' . $db->quoteName('id') . ' <> ' . $except,
        )->loadResult() > 0;
    }

    /**
     * Insert or update a record; its id.
     *
     * @param DatabaseDriver $db The connection.
     * @param array<string, mixed> $row The record.
     *
     * @since 2.2.2
     */
    private static function store(DatabaseDriver $db, array $row): int
    {
        $columns = array_diff(array_keys($db->getTableColumns(self::TABLE)), ['reference_hash']);
        $object = (object) array_intersect_key($row, array_flip($columns));

        if ((int) ($row['id'] ?? 0) > 0) {
            $db->updateObject(self::TABLE, $object, 'id', true);

            return (int) $row['id'];
        }

        unset($object->id);
        $db->insertObject(self::TABLE, $object, 'id');

        return (int) $object->id;
    }

    /**
     * What a case's rows book: its chargebacks less every completed reversal that names one of them.
     *
     * @param list<object> $rows The case's completed rows (payment_type, amount, id_reversed_payment).
     * @param Currency $currency The order's currency.
     *
     * @since 2.2.2
     */
    private static function bookedOf(array $rows, Currency $currency): Money
    {
        $booked = Money::zero($currency);
        $chargebacks = [];

        foreach ($rows as $row) {
            if ($row->payment_type === PaymentLedger::TYPE_CHARGEBACK) {
                $chargebacks[(int) $row->id] = true;
                $booked = $booked->add(Money::of((string) $row->amount, $currency)->abs());
            }
        }

        foreach ($rows as $row) {
            if ($row->payment_type === PaymentLedger::TYPE_REVERSAL && isset($chargebacks[(int) $row->id_reversed_payment])) {
                $booked = $booked->subtract(Money::of((string) $row->amount, $currency)->abs());
            }
        }

        return $booked;
    }

    /**
     * Whether two stored values of a column are the same: decimals by value, the rest by text.
     *
     * @param string $column The column.
     * @param mixed $a One value.
     * @param mixed $b The other.
     *
     * @since 2.2.2
     */
    private static function same(string $column, mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }

        if (\in_array($column, ['amount', 'stated_amount', 'fee_amount', 'fee_returned'], true)) {
            return self::normalised(value: (string) $a) === self::normalised(value: (string) $b);
        }

        return (string) $a === (string) $b;
    }

    /**
     * A decimal text without leading and trailing zeros.
     *
     * @param string $value The text.
     *
     * @since 2.2.2
     */
    private static function normalised(string $value): string
    {
        $value = ltrim($value, '0');

        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '' || $value[0] === '.' ? '0' . $value : $value;
    }

    /**
     * A time as provider_as_of stores it: Y-m-d H:i:s.uuuuuu.
     *
     * @param string $value The time.
     *
     * @since 2.2.2
     */
    private static function micro(string $value): string
    {
        [$seconds, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $seconds . '.' . str_pad(substr($fraction, 0, 6), 6, '0');
    }

    /**
     * A value of a changed fact as the timeline shows it.
     *
     * @param mixed $value The stored value.
     *
     * @since 2.2.2
     */
    private static function shown(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return \is_string($value) && preg_match('/^\d+\.\d+$/', $value) === 1 ? PublishedDecimal::decimal($value) : (string) $value;
    }

    /**
     * The disputed amount as staff read it: in the order's currency, or as the provider stated it in another one.
     *
     * @param array<string, mixed> $record The record.
     * @param Currency $currency The order's currency.
     *
     * @since 2.2.2
     */
    private static function amountText(array $record, Currency $currency): string
    {
        if ($record['amount'] !== null) {
            return Money::of((string) $record['amount'], $currency)->format();
        }

        return $record['stated_amount'] === null ? '-' : PublishedDecimal::decimal((string) $record['stated_amount']) . ' ' . (string) $record['stated_currency'];
    }

    /**
     * A refusal.
     *
     * @param string $code The code.
     * @param string $message The sentence.
     * @param int $disputeId The record, 0 when none.
     *
     * @since 2.2.2
     */
    private static function refused(string $code, string $message, int $disputeId = 0): DisputeVerdict
    {
        return new DisputeVerdict(kind: DisputeVerdict::REFUSED, code: $code, message: $message, disputeId: $disputeId, existing: $disputeId > 0);
    }

    /**
     * A text of the order's timeline: a key and its arguments, translated when the History tab shows it.
     *
     * @param string $key The key.
     * @param mixed ...$args Its arguments (a nested text is an array of its own).
     *
     * @return array{key: string, args: list<mixed>}
     *
     * @since 2.2.2
     */
    private static function text(string $key, mixed ...$args): array
    {
        return ['key' => $key, 'args' => array_values($args)];
    }

    /**
     * A state's name as a nested text.
     *
     * @param string $state The state.
     *
     * @return array{key: string, args: list<mixed>}
     *
     * @since 2.2.2
     */
    private static function stateText(string $state): array
    {
        return self::text('COM_ALFA_DISPUTE_STATE_' . strtoupper($state));
    }

    /**
     * A text in English, as every timeline summary: the snapshot the record keeps beside its key.
     *
     * @param array{key: string, args: list<mixed>} $text The text.
     *
     * @since 2.2.2
     */
    private static function english(array $text): string
    {
        static $language = null;

        if (!$language instanceof Language) {
            $language = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage('en-GB');
            $language->load(extension: 'com_alfa', basePath: JPATH_ADMINISTRATOR);
        }

        $args = array_map(static fn (mixed $arg): mixed => \is_array($arg) ? self::english(text: $arg) : $arg, $text['args']);

        return vsprintf($language->_($text['key']), $args);
    }

    /**
     * Log a failure of a save: one ERROR line with the ids and the failure's text made safe for a log line.
     *
     * @param int $orderId The order, 0 when not known.
     * @param int $paymentId The payment.
     * @param int $disputeId The record, 0 when none.
     * @param Throwable $failure What failed.
     *
     * @since 2.2.2
     */
    private static function logFailure(int $orderId, int $paymentId, int $disputeId, Throwable $failure): void
    {
        try {
            AlfaLog::register();
            Log::add(
                sprintf('Dispute save failed (dispute #%d, payment #%d, order #%d): %s', $disputeId, $paymentId, $orderId, ErrorText::of($failure)),
                Log::ERROR,
                'com_alfa.payments',
            );
        } catch (Throwable) {
            // The verdict still says nothing was written.
        }
    }
}
