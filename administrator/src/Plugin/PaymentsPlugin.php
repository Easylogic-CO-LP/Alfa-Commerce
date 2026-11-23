<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Base Payment Plugin
 *
 * All payment plugins extend this class.
 * Provides fluent builder wrappers for payment operations
 * and empty action hooks for the admin UI.
 *
 *  FLUENT BUILDER API (recommended for programmatic use):
 *
 *    CREATE:
 *      $paymentId = $this->payment($order)->pending()->save();
 *      $paymentId = $this->payment($order)->authorized()->transactionId('ch_3M..')->save();
 *      $paymentId = $this->payment($order)->refund()->amount(50)->completed()->save();
 *
 *    UPDATE:
 *      $this->paymentUpdate($paymentId)->completed()->save();
 *      $this->paymentUpdate($paymentId)->cancelled()->note('Customer request')->save();
 *      $this->paymentUpdate($paymentId)->refunded()->save();
 *
 *  INTENT VERBS (prefer these over hand-driven status flips):
 *      $verdict = $this->supersedeAttempt($paymentId, 'New gateway visit');
 *      $result  = $this->refundPayment($paymentId);                 // full: null is the ONLY way to say "the remainder"
 *      $result  = $this->refundPayment($paymentId, $money);         // partial: a positive Money; zero and negative are refused
 *      $result  = $this->refundPayment($paymentId, null, ['gateway_moved' => true]);
 *      $refusal = $this->refundStartRefusal($order);                // the refund block: null = a refund may start
 *
 *  BOOKKEEPING WRITE (row-level fact, nothing merchant-visible):
 *      $this->paymentUpdate($id)->cancelled()->bookkeeping()->save();
 *
 *  READ / DELETE (no builder needed):
 *    $payment  = $this->getPayment($paymentId);
 *    $payments = $this->getPaymentsByOrder($orderId);
 *    $this->deletePayment($paymentId, $orderId);
 *
 *  THE GATEWAY'S BOOKS:
 *    $due     = $this->orderBalance(orderId: $orderId);                          // signed Money: > 0 owed, < 0 overpaid
 *    $room    = $this->collectableNow(paymentId: $paymentId);                    // what your open row may still collect when settled
 *    $answer  = $this->withLockWait(seconds: 8, work: fn () => ...);              // a notification's writes wait at most 8 s for the order
 *    $holders = $this->countReferenceHolders(reference: $txId, types: [self::TYPE_PAYMENT]);
 *    $rowId   = $this->findOwnRowByReference(reference: $refundId, types: [self::TYPE_REFUND]); // the one row, or null
 *    $kind    = $this->refundTypeFor(paymentId: $paymentId, amount: $refund);   // self::REFUND_FULL or self::REFUND_PARTIAL
 *    $mine    = $this->claimPendingAttempt(paymentId: $paymentId, reference: $sessionId); // one wins, the other is told false
 *    $first   = $this->firstMethodRow(methodId: $methodId, kind: self::FIRST_PAID); // a method's first settled payment
 *
 *  REFUNDS, OWN METHODS AND THE ACTIONS LOG:
 *    $ceiling = $this->bookedRefundable(orderId: $orderId);                     // Money: collected less returned
 *    $methods = $this->ownMethods();                                             // this plugin's published methods, by id
 *    $retry   = $this->failRefundAttempt($reservationId, $failedRefundId, $why); // keep the reservation for the retry
 *    $this->recordActionLog('PLG_ALFA_PAYMENTS_<YOU>_LOG_REFUND_STARTED', ['title' => '#12'], $orderId); // User Actions Log
 *
 *  WRITES OF A BUYER'S VISIT, each answering WHY (a PaymentWriteVerdict: decide on ->kind, then ->code):
 *    $open    = $this->latestPendingPayment($orderId);                           // 0. your own open attempt first: resume, claim or close it
 *    $verdict = $this->payment($order)->pending()->transactionId($minted)->saveWithVerdict(); // 1. the row first, a reference you minted
 *    // only VERDICT_WRITTEN goes on to the provider, with an idempotency key derived from $verdict->rowId or $minted;
 *    // VERDICT_REFUSED stops (REFUSAL_OPEN_ATTEMPT: another payment of this order is still open; REFUSAL_OVER_TOTAL:
 *    // covered); VERDICT_LOCK_NOT_TAKEN and VERDICT_WRITE_FAILED: try again with the SAME reference, no provider call made
 *    $verdict = $this->paymentUpdate($rowId)->transactionId($providerId)->saveWithVerdict(); // 3. the provider's id on the row
 *    $verdict = $this->settleAttemptVerdict($write, $orderId, $txId);            // the settle, its CRITICAL or WARNING line kept
 *    $verdict = $this->supersedeAttemptVerdict($paymentId, 'New gateway visit'); // close your own attempt, never another gateway's
 *    $verdict = $this->claimPendingAttemptVerdict(paymentId: $paymentId, reference: $sessionId); // a placement row without a reference
 *    // WRITTEN with ->existing true on a pending row: your provider call may have been made; make it safe to repeat
 *    // (the provider's idempotency key from the reference) or ask the provider by the reference before creating anything
 *    // call these outside any transaction of your own: inside one that has already read, the reference lookup sees its
 *    // older snapshot
 *
 *  A RENEWAL (supportsRecurring() answers true; your onPaymentChargeReference(ChargeReferenceEvent $event)):
 *    $verdict = $this->chargeAttempt($event)->saveWithVerdict();               // the attempt's row first, its reference renewalReference()
 *    // only VERDICT_WRITTEN calls the provider, keyed on that reference; settle your row, then answer naming it:
 *    $event->setSuccess($providerId, $verdict->rowId);                          // or setPending($ref, $why, $verdict->rowId)
 *    $event->setFailure($why, ChargeReferenceEvent::DECLINE_FINAL);             // or setNotSent($why, ChargeReferenceEvent::NOT_SENT_RETRY)
 *    $may = $this->renewalsAllowed($methodId);                                  // the gateway, the method published, its own setting
 *
 *  THE RULE: this class is the supported payment API. A gateway never calls
 *  PaymentLedger and never reads or writes #__alfa_order_payments itself;
 *  what it needs is a method here. A need this class does not cover is a
 *  request to core, not a query of the gateway's own.
 *
 *  LOGGING (inherited from Plugin.php):
 *    $logId = $this->log(['id_order' => $orderId, ...]);
 *    $logs  = $this->loadLogs($orderId, $paymentId);
 *    $xml   = $this->getLogsSchema();
 *
 *  ADMIN ACTIONS (Fluent API on event):
 *    $event->add('mark_paid', 'Mark as Paid')
 *        ->icon('checkmark')->css('btn-success')
 *        ->confirm('Mark as paid?')->priority(200);
 *
 * LOG IDENTIFIER: Sets logIdentifierField = 'id_order_payment'
 * LOG METHOD:     Sets logMethodField = 'id_payment_method' (method-level rows:
 *                 logForMethod() / loadMethodLogs())
 *
 * Path: administrator/components/com_alfa/src/Plugin/PaymentsPlugin.php
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

use Alfa\Component\Alfa\Administrator\Event\Payments\ChargeReferenceEvent;
use Alfa\Component\Alfa\Administrator\Helper\PaymentToken;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Plugin\Traits\HasActionLogTrait;
use Alfa\Component\Alfa\Administrator\Plugin\Traits\HasOrderContextTrait;
use Alfa\Component\Alfa\Administrator\Plugin\Traits\HasTransportUrlsTrait;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Administrator\Service\Order\MethodState;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Alfa\Component\Alfa\Administrator\Service\Order\PaymentEnvironment;
use Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger;
use Alfa\Component\Alfa\Administrator\Service\Order\PaymentWriteVerdict;
use Alfa\Component\Alfa\Administrator\Service\Order\Transition;
use Alfa\Component\Alfa\Site\Service\Checkout\SessionOrder;
use Alfa\Component\Alfa\Site\Service\Order\Order;
use Alfa\Component\Alfa\Site\Service\Order\Payment;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Closure;
use InvalidArgumentException;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;
use Joomla\Event\SubscriberInterface;
use LogicException;
use RuntimeException;
use Throwable;

defined('_JEXEC') or die;

abstract class PaymentsPlugin extends Plugin implements SubscriberInterface
{
    use HasActionLogTrait;
    use HasOrderContextTrait;
    use HasTransportUrlsTrait;

    // Payment status / type vocabulary
    // Re-exposed from PaymentLedger so a gateway references self::STATUS_* through
    // its own base class and never names the ledger directly. PaymentLedger stays
    // the single source of truth for the values.
    public const STATUS_PENDING = PaymentLedger::STATUS_PENDING;
    public const STATUS_AUTHORIZED = PaymentLedger::STATUS_AUTHORIZED;
    public const STATUS_COMPLETED = PaymentLedger::STATUS_COMPLETED;
    public const STATUS_FAILED = PaymentLedger::STATUS_FAILED;
    public const STATUS_CANCELLED = PaymentLedger::STATUS_CANCELLED;
    public const STATUS_REFUNDED = PaymentLedger::STATUS_REFUNDED;

    public const TYPE_PAYMENT = PaymentLedger::TYPE_PAYMENT;
    public const TYPE_REFUND = PaymentLedger::TYPE_REFUND;
    public const TYPE_AUTHORIZATION = PaymentLedger::TYPE_AUTHORIZATION;

    /**
     * @var string A provider fact undoing a refund or a chargeback; see recordReversal().
     *
     * @since  2.0.0
     */
    public const TYPE_REVERSAL = PaymentLedger::TYPE_REVERSAL;

    /**
     * @var string Money the customer's bank took back; see recordChargeback().
     *
     * @since  2.0.0
     */
    public const TYPE_CHARGEBACK = PaymentLedger::TYPE_CHARGEBACK;

    public const REFUND_FULL = PaymentLedger::REFUND_FULL;
    public const REFUND_PARTIAL = PaymentLedger::REFUND_PARTIAL;

    // The kinds of firstMethodRow(): a method's first settled payment, its first refund.
    public const FIRST_PAID = 'paid';
    public const FIRST_REFUND = 'refund';

    // Conditional-write verdicts (supersedeAttempt, updateIfStatus)
    public const WRITE_OK = PaymentLedger::WRITE_OK;
    public const WRITE_RACED = PaymentLedger::WRITE_RACED;
    public const WRITE_FAILED = PaymentLedger::WRITE_FAILED;

    // The kinds and codes of a write's verdict: saveWithVerdict(), settleAttemptVerdict(),
    // supersedeAttemptVerdict(), claimPendingAttemptVerdict(). A plugin decides by kind, and handles a code it does not
    // know as its kind; a value never changes and is never reused within the major version. Their meanings:
    // PaymentLedger's constants.
    public const VERDICT_WRITTEN = PaymentLedger::VERDICT_WRITTEN;
    public const VERDICT_REFUSED = PaymentLedger::VERDICT_REFUSED;
    public const VERDICT_LOCK_NOT_TAKEN = PaymentLedger::VERDICT_LOCK_NOT_TAKEN;
    public const VERDICT_WRITE_FAILED = PaymentLedger::VERDICT_WRITE_FAILED;
    public const VERDICT_RACED = PaymentLedger::VERDICT_RACED;
    public const REFUSAL_NO_ORDER = PaymentLedger::REFUSAL_NO_ORDER;
    public const REFUSAL_ORDER_UNREADABLE = PaymentLedger::REFUSAL_ORDER_UNREADABLE;
    public const REFUSAL_AMOUNT_CURRENCY = PaymentLedger::REFUSAL_AMOUNT_CURRENCY;
    public const REFUSAL_AMOUNT_SCALE = PaymentLedger::REFUSAL_AMOUNT_SCALE;
    public const REFUSAL_AMOUNT_INVALID = PaymentLedger::REFUSAL_AMOUNT_INVALID;
    public const REFUSAL_AMOUNT_NEGATIVE = PaymentLedger::REFUSAL_AMOUNT_NEGATIVE;
    public const REFUSAL_OVER_TOTAL = PaymentLedger::REFUSAL_OVER_TOTAL;
    public const REFUSAL_OPEN_ATTEMPT = PaymentLedger::REFUSAL_OPEN_ATTEMPT;
    public const REFUSAL_CURRENCY_MISMATCH = PaymentLedger::REFUSAL_CURRENCY_MISMATCH;
    public const REFUSAL_NOT_WRITER = PaymentLedger::REFUSAL_NOT_WRITER;
    public const REFUSAL_WALLET = PaymentLedger::REFUSAL_WALLET;
    public const REFUSAL_FACT_TYPE = PaymentLedger::REFUSAL_FACT_TYPE;
    public const REFUSAL_REFERENCE_TAKEN = PaymentLedger::REFUSAL_REFERENCE_TAKEN;
    public const REFUSAL_ROW_MISSING = PaymentLedger::REFUSAL_ROW_MISSING;
    public const REFUSAL_HISTORY_LOCKED = PaymentLedger::REFUSAL_HISTORY_LOCKED;
    public const REFUSAL_METHOD_FIXED = PaymentLedger::REFUSAL_METHOD_FIXED;
    public const REFUSAL_SETTLED_REFUND = PaymentLedger::REFUSAL_SETTLED_REFUND;
    public const REFUSAL_CLASSIFICATION = PaymentLedger::REFUSAL_CLASSIFICATION;
    public const REFUSAL_REFUND_SOURCE = PaymentLedger::REFUSAL_REFUND_SOURCE;
    public const REFUSAL_REFUND_DUPLICATE = PaymentLedger::REFUSAL_REFUND_DUPLICATE;
    public const REFUSAL_REFUND_PAST_PAYMENT = PaymentLedger::REFUSAL_REFUND_PAST_PAYMENT;
    public const REFUSAL_REFUND_PAST_ORDER = PaymentLedger::REFUSAL_REFUND_PAST_ORDER;
    public const REFUSAL_REFUND_START = PaymentLedger::REFUSAL_REFUND_START;
    public const REFUSAL_REFUND_DOCUMENT = PaymentLedger::REFUSAL_REFUND_DOCUMENT;

    // refundPayment() outcomes
    public const OUTCOME_OK = PaymentLedger::OUTCOME_OK;
    public const OUTCOME_ALREADY_REFUNDED = PaymentLedger::OUTCOME_ALREADY_REFUNDED;

    /**
     * @var string The refund reference is already on a refund row of this
     *             payment: the provider retrying one refund; nothing written.
     *
     * @since  2.0.0
     */
    public const OUTCOME_DUPLICATE = PaymentLedger::OUTCOME_DUPLICATE;
    public const OUTCOME_NOT_REFUNDABLE = PaymentLedger::OUTCOME_NOT_REFUNDABLE;
    public const OUTCOME_NOTHING_LEFT = PaymentLedger::OUTCOME_NOTHING_LEFT;
    public const OUTCOME_RACED = PaymentLedger::OUTCOME_RACED;
    public const OUTCOME_FAILED = PaymentLedger::OUTCOME_FAILED;
    public const OUTCOME_UNRECONCILED = PaymentLedger::OUTCOME_UNRECONCILED;

    /**
     * @var string Nothing written: a provider has reported refunds on the
     *             payment's order that the books do not hold; see refundStartRefusal().
     *
     * @since  2.1.0
     */
    public const OUTCOME_BLOCKED = PaymentLedger::OUTCOME_BLOCKED;

    /**
     * @var string reserveRefund() / holdRefund(): the amount is reserved, nothing is booked.
     *
     * @since  2.1.0
     */
    public const OUTCOME_RESERVED = PaymentLedger::OUTCOME_RESERVED;

    /**
     * @var string reserveRefund(): nothing written; a refund row was written on the payment
     *             after the decision was drawn (the posted mark is out of date).
     *
     * @since  2.1.0
     */
    public const OUTCOME_STALE = PaymentLedger::OUTCOME_STALE;

    /**
     * @var string releaseRefund(): what the reservation held is given back; nothing is booked.
     *
     * @since  2.1.0
     */
    public const OUTCOME_RELEASED = PaymentLedger::OUTCOME_RELEASED;

    /**
     * @var string The 'status' of an entry of unbookedProviderRefunds(): the provider says
     *             the refund is complete, or has accepted it but not completed it.
     *
     * @since  2.1.0
     */
    public const REPORT_STATUS_SETTLED = PaymentLedger::REPORT_STATUS_SETTLED;
    public const REPORT_STATUS_PENDING = PaymentLedger::REPORT_STATUS_PENDING;

    /**
     * @var string The prefix of the reference a refund reservation carries while the
     *             provider has not named its refund ('request:' and the key the core made,
     *             {@see self::reserveRefund()}). Never the prefix of a listed report.
     *
     * @since  2.1.0
     */
    public const REPORT_REQUEST_PREFIX = PaymentLedger::REPORT_REQUEST_PREFIX;

    /**
     * @var string The prefix of the core's own reference for a refund the provider gave no refund id for, under one of
     *             this site's refund requests, that is not the request's reservation itself ('beyond:' and the key).
     *             Like REPORT_REQUEST_PREFIX it is never a provider refund id and never the prefix of a listed report.
     *
     * @since  2.2.1
     */
    public const REFUND_BEYOND_PREFIX = PaymentLedger::REFUND_BEYOND_PREFIX;

    // recordReversal() / recordChargeback() outcomes (with DUPLICATE,
    //    NOTHING_LEFT and FAILED above)

    /**
     * @var string The fact is written.
     *
     * @since  2.0.0
     */
    public const OUTCOME_RECORDED = PaymentLedger::OUTCOME_RECORDED;

    /**
     * @var string Nothing written: not a fact the ledger can take. A retry cannot help.
     *
     * @since  2.0.0
     */
    public const OUTCOME_REFUSED = PaymentLedger::OUTCOME_REFUSED;

    /**
     * Resolved methods for this request, keyed "orderId:methodId".
     *
     * A gateway reads its settings one at a time (client id here, secret
     * there, mode in a third place) and every one of those is a call to
     * {@see self::getPaymentMethod()}. Without a memo that is a model load
     * apiece; with it, the second and later reads are free, so a plugin can
     * write nine small accessors instead of one fat params blob.
     *
     * @var array<string, object|null>
     *
     * @since  2.0.0
     */
    private array $resolvedMethods = [];

    /**
     * The admin Payment model, kept for the request: it caches per id itself,
     * and a fresh instance would throw that cache away on every call.
     *
     *
     * @since  2.0.0
     */
    private ?object $paymentMethodModel = null;

    /**
     * This family answers on the payment.* controller.
     *
     * @since  2.0.0
     */
    protected static function transportTask(): string
    {
        return 'payment';
    }

    /** @var string Default completion page URL */
    protected string $completePageUrl;

    /** @var string Default process (payment-in-progress) page URL */
    protected string $processPageUrl;

    /**
     * Events this plugin subscribes to.
     *
     * @since  1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        // Every Alfa event a gateway handles is addressed to it alone, on its method of the event's name; a
        // gateway that needs an ambient Joomla event merges its own map.
        return [];
    }

    /**
     * Constructor.
     *
     * Sets logIdentifierField so $this->loadLogs($orderId, $paymentId)
     * correctly filters by id_order_payment in the plugin's log table,
     * logMethodField so method-level rows land in id_payment_method, and
     * registers the gateway's own text logger: the CRITICAL "reconcile
     * manually" lines are only worth writing if they land somewhere a person
     * will look, and every gateway that had to remember to register one for
     * itself was one that could forget.
     *
     * @param array $config Plugin configuration
     * @since  1.0.0
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->completePageUrl = 'index.php?option=com_alfa&view=cart&layout=default_order_completed';
        $this->processPageUrl = 'index.php?option=com_alfa&view=cart&layout=default_order_process';
        [$this->logIdentifierField, $this->logMethodField] = PluginLogTable::FAMILY_COLUMNS['alfa-payments'];

        $this->registerLogger();
    }

    /**
     * Get the order completion page URL.
     *
     * @api READ ONLY: the completion page's address.
     *
     * @since  1.0.0
     */
    public function getCompletePageUrl(): string
    {
        return $this->completePageUrl;
    }

    /**
     * Set the order completion page URL.
     *
     * @since  1.0.0
     */
    public function setCompletePageUrl(string $url): void
    {
        $this->completePageUrl = $url;
    }

    /**
     * Get the payment-in-progress (process) page URL.
     *
     * @api READ ONLY: the process page's address.
     *
     * @since  1.0.0
     */
    public function getProcessPageUrl(): string
    {
        return $this->processPageUrl;
    }

    /**
     * Set the payment-in-progress (process) page URL.
     *
     * @since  1.0.0
     */
    public function setProcessPageUrl(string $url): void
    {
        $this->processPageUrl = $url;
    }

    //  Payment fluent builder wrappers
    //
    //  These return a PaymentLedger builder instance. The builder
    //  validates, auto-fills, and delegates to the ledger internally.
    //
    //  The base-class API is the supported one: a gateway writes
    //  and reads its payments through this class (the builder wrappers,
    //  the intent verbs, the reads, and the books API: orderBalance(),
    //  countReferenceHolders(), refundTypeFor(), claimPendingAttempt()),
    //  and never calls PaymentLedger or writes #__alfa_order_payments
    //  itself.

    /**
     * Create a new payment (returns fluent builder in CREATE mode).
     *
     * Amount is auto-resolved from the best available source:
     *   Primary: $order->total_paid_tax_incl (always correct, Money object)
     *   Priority 2: OrderTotalHelper (items + shipping - discounts from DB)
     *   Priority 3: ->amount($x) manual override (always wins when called)
     *
     * Usage:
     *   $paymentId = $this->payment($order)->pending()->save();
     *   $paymentId = $this->payment($order)->authorized()->transactionId('ch_3M..')->save();
     *   $paymentId = $this->payment($order)->refund()->amount(50)->completed()->save();
     *
     * The row records this plugin's element, never the live type of a
     * method row: the method it names (the order's, or ->method($id)) must be one
     * of this plugin's, and a refund or chargeback must return this plugin's own
     * money, or the save answers false and writes nothing.
     *
     * @api ANSWERS: the ledger in builder mode with this plugin as the writer; its save() writes only on this plugin's
     *      own methods.
     *
     * @param object $order Full order object (->id, ->items, ->id_payment_method)
     *
     * @return PaymentLedger Builder: chain setters, finish with ->save()
     *
     * @since  1.0.0
     */
    protected function payment(object $order): PaymentLedger
    {
        // The row is this plugin's: it records this plugin's element, and the ledger refuses a method of another type.
        return PaymentLedger::for($order)->writtenBy(element: (string) $this->_name);
    }

    /**
     * The amount YOUR gateway should collect for this order: the one
     * number every payment plugin must build on.
     *
     * Grand total minus money already collected (e.g. a customer-credit
     * payment applied at placement). Contract for gateway authors:
     *
     *   - Charge amountToCollect($order), NEVER a raw order total field;
     *     with split payment the raw total over-charges the customer.
     *   - 0.00 = nothing to collect: skip your gateway, create no payment
     *     row, set no layout in onOrderProcessView; checkout falls through
     *     to the completed page on its own.
     *   - Verify callbacks against YOUR pending row's amount, never the
     *     order total; with split payment they differ.
     *
     * Usage:
     *   $due = $this->amountToCollect($order);
     *   if ($due <= 0.0) { return; }
     *   $this->payment($order)->amount($due)->pending()->save();
     *
     * @api READ ONLY: what a new charge should collect, clamped at zero.
     *
     * @param object $order The hydrated order.
     *
     * @return float Amount still to collect, never negative.
     *
     * @since  2.0.0
     */
    protected function amountToCollect(object $order): float
    {
        return PaymentLedger::amountToCollect($order);
    }

    /**
     * How much may still be refunded on this order: gate your Refund action on
     * it. A refund form's maximum is refundFormMaximum(), never this alone.
     *
     * Store credit already returned for this order's credit notes is
     * subtracted here, so a refunded-to-wallet order correctly reports less
     * (or nothing) as refundable by card. So is everything reserved, whichever
     * payment it is on: the order's pending provider refunds (see
     * unbookedProviderRefunds()) and its refunds in the books that are not
     * settled (pending or authorized refund rows, every refund reservation
     * among them). It is a read: the decision to start a refund
     * is taken by reserveRefund(), under the order's lock, before your
     * provider is asked; never hold the booking of money the provider already
     * moved to this figure.
     *
     * Usage:
     *   if (!$this->refundableNow($order)->isPositive()) { ...offer no Refund action... }
     *
     * @api READ ONLY: what a new refund start may take now.
     *
     * @param object $order The hydrated order.
     *
     * @return float Refundable amount, never negative.
     *
     * @since  2.0.0
     */
    protected function refundableNow(object $order): Money
    {
        return PaymentLedger::refundableNow(Order::of($order)->id());
    }

    /**
     * What is left to give back on ONE payment row.
     *
     * The order-level {@see self::refundableNow()} answers "how much may this
     * ORDER still return". This answers "how much is left on THIS row", because
     * a row that already carries a partial refund must not be refunded gross a
     * second time; the maximum a refund form offers is the smaller of the two,
     * {@see self::refundFormMaximum()}. What this payment's own pending
     * provider refunds reserve (see unbookedProviderRefunds()) is not left on it
     * either, nor what its refund rows in the books that are not settled
     * (pending or authorized, its refund reservations among them) reserve: that
     * money is held for them on this payment. A new refund is
     * held to both figures by reserveRefund(). Never hold the booking of money
     * the provider already moved to it: settleRefund() and refundPayment() with
     * gateway_moved book against the books alone.
     *
     * Send it to the gateway in minor units through the order's own currency,
     * never `* 100`:
     *
     *   $minor = $this->refundFormMaximum((int) $payment->id)->getMinorUnits();
     *
     * Alfa ships currencies with 0, 1, 3 and 4 decimals (JPY, MOP, OMR, ...), so
     * a hard-coded hundred is wrong on four of them.
     *
     * @api READ ONLY: what is left to refund on one payment row.
     *
     * @param int $paymentId Original payment row PK.
     *
     * @return Money Remaining refundable amount, zero when nothing is left.
     *
     * @since  2.0.0
     */
    protected function refundableRemainder(int $paymentId): Money
    {
        return PaymentLedger::refundableRemainder($paymentId);
    }

    /**
     * The maximum every refund form of this plugin offers for one payment, a
     * refund start or a record-only form alike: what is left on the payment
     * (refundableRemainder()) held to what the order may still refund
     * (refundableNow()), both counting the settled refunds and every
     * reservation the core counts ({@see PaymentLedger::refundFormMaximum()}).
     * The only figure a gateway uses for a form maximum; it keeps no
     * arithmetic of its own. The refund block is asked apart
     * (refundStartRefusal()), and reserveRefund() decides the start.
     *
     * Usage:
     *   $max = $this->refundFormMaximum((int) $payment->id);   // the form's maximum
     *
     * @api READ ONLY: the most the refund form offers for one payment.
     *
     * @param int $paymentId The payment.
     *
     * @return Money In the order's currency, never negative.
     *
     * @since  2.1.0
     */
    protected function refundFormMaximum(int $paymentId): Money
    {
        return PaymentLedger::refundFormMaximum(paymentId: $paymentId);
    }

    /**
     * Why no new refund may start on this order now, or null when one may: the
     * refund block, decided once in core for every gateway
     * ({@see PaymentLedger::refundStartRefusal()}).
     *
     * While any payment of the order has a refund its provider reported and the
     * books do not hold ({@see self::unbookedProviderRefunds()}) that holds the
     * block (settled, or pending with no exact amount in the order's currency,
     * or malformed), no new refund starts on any of them. A pending refund
     * with an exact amount does not refuse here: it reserves that amount
     * ({@see self::refundableNow()}). A gateway declares its Refund and
     * Partial refund actions with PluginAction::startsRefund() (the order screen then withholds them and the
     * action page refuses them before this plugin runs); in its refund handler
     * reserveRefund() decides the start again under the order's lock, with this
     * refusal, and reserves it before anything is sent; it keeps no check of its
     * own. Recording a refund the provider already made is never refused.
     *
     * Usage:
     *   $refusal = $this->refundStartRefusal($event->getOrder());
     *   if ($refusal !== null) { ...refuse with $refusal, send nothing... }
     *
     * @api READ ONLY: why a refund cannot start now, or null.
     *
     * @param object $order The hydrated order.
     *
     * @return string|null The reason, translated; null when a refund may start.
     *
     * @since  2.1.0
     */
    protected function refundStartRefusal(object $order): ?string
    {
        return PaymentLedger::refundStartRefusal(orderId: Order::of($order)->id());
    }

    /**
     * The refunds the provider has reported made, or being made, on this
     * payment that the books do not hold: this gateway's answer to the refund
     * block ({@see self::refundStartRefusal()}).
     *
     * The contract every gateway keeps (the same signature in every payment
     * plugin, no default parameters):
     *   - a list, oldest report first, ONE entry per refund (never one per
     *     notice, delivery or check that reported it):
     *     'reportId' => the identity of the reported refund, built by the plugin
     *                   from the provider's facts only, so that every report of
     *                   the same refund (a webhook delivered again, a later
     *                   notice of another type, a later check or reconcile)
     *                   yields the same reportId and no other refund yields it.
     *                   The status is not part of the identity: a refund keeps
     *                   its reportId when it goes from pending to settled (its
     *                   pending and its settled report carry the same one).
     *                   The core keeps the report's outcome under it and books
     *                   one reportId once. It is the first of these the
     *                   provider's reports of its refunds always carry:
     *                     1. the provider names the refund:
     *                        'refund:' . <the provider's refund id, complete>;
     *                     2. it does not, but names the provider transaction
     *                        that carries the refund (a refund transaction or
     *                        operation id): 'txn:' . <that id, complete>;
     *                     3. neither: 'facts:' . the lowercase hex SHA-256 of the
     *                        provider's reference of the payment, the refunded
     *                        amount and currency as the provider stated them,
     *                        the provider's own time of the refund and, when the
     *                        provider states one, any further stable reference of
     *                        that refund (named in the gateway's docblock), joined
     *                        by a line feed. The time is one the provider states
     *                        identically in every report of that refund (its
     *                        creation time, say), never one that changes with its
     *                        state (an update or a settlement time).
     *                   ONE rule per provider, never per report: the first rule
     *                   whose id every report of a refund of that provider
     *                   carries. A provider whose reports of one refund do not
     *                   always carry its id (the refund id of rule 1, the
     *                   transaction id of rule 2) uses rule 3 for every report
     *                   of it. Never a row id of the plugin's log, a delivery
     *                   or event id, a time of receipt or any other value of
     *                   the plugin's own, which a repeat would change. Two
     *                   reports are the same refund exactly when they are about
     *                   the same payment of the same gateway and their reportIds
     *                   are equal byte for byte. Valid UTF-8.
     *                   A refund REQUEST this site sends is never listed:
     *                   the core
     *                   holds it as a reservation in the books, made before
     *                   the provider is asked (reserveRefund()). A reportId
     *                   that starts with REPORT_REQUEST_PREFIX is malformed
     *                   and holds the block,
     *     'id' => the provider's refund id exactly as the provider stated it, or
     *             null when the provider gave none (never invented); valid UTF-8,
     *     'amount' => the provider's figure as Money in the order's currency, or
     *                 null when no exact amount in that currency can be derived
     *                 (another currency, a finer figure),
     *     'providerAmount' => the amount as the provider stated it, decimal text,
     *                         at most 64 characters,
     *     'providerCurrency' => the currency as the provider stated it, at most
     *                           16 characters,
     *     'reportedOn' => when the plugin first kept that report, in the site's
     *                     time zone (Y-m-d H:i), at most 64 characters,
     *     'status' => 'settled' (self::REPORT_STATUS_SETTLED) when the provider
     *                 says the refund is complete: the money went back; 'pending'
     *                 (self::REPORT_STATUS_PENDING) while the provider has
     *                 accepted it but not completed it. A pending report is never
     *                 booked as a settled refund: Record this refund waits until
     *                 the plugin reports it settled;
     *   - what the core makes of each listed report: what may still be
     *     refunded on the order (refundableNow()) is its refund ceiling, less
     *     the refunds booked, less what the pending reports reserve. A pending
     *     report whose amount is known exactly in the order's currency (above
     *     zero) RESERVES that amount, once per reportId, whichever payment of
     *     the order it is on: it blocks nothing, and a new refund may start on
     *     any payment for what is left, on the payment the report is on for no
     *     more than that payment has left beyond its reservations
     *     (refundableRemainder()). A settled report not booked yet, a
     *     pending report with no exact amount, and a malformed entry hold the
     *     refund block: no new refund starts on the order;
     *   - ONE entry per refund at any moment. A refund this site started is a
     *     reservation in the books (a refund row, pending): once holdRefund()
     *     or settleRefund() has put the provider's refund id on it, every report
     *     of that refund is the row's own, left out here and counted once, by
     *     the row; listed before that, it is counted beside the row, which only
     *     refuses more. Its settle is settleRefund(): one write turns the
     *     reservation into the booking, so it is counted as reserved or as
     *     booked, never both and never neither. A refund no reservation covers
     *     (made in the provider's portal, found by a check or a notification)
     *     keeps its reportId from pending to settled and is booked once through
     *     refundPayment() with gateway_moved FIRST, sent outside any transaction
     *     of the plugin's, and listed no more only after that booking has
     *     committed: the core's readers take no lock of the plugin's and may
     *     read between the two steps; booked first, the refund is recognised as
     *     booked under its provider id while it is still listed, so it is
     *     never counted by nothing; a plugin that lists it no more before
     *     booking it leaves a moment in which it is counted by nothing. The
     *     core recognises a booking only under the provider's refund id, so a
     *     refund booked without one must be listed no more from that step on:
     *     until then it is counted twice, which only refuses more. When the
     *     provider reports that a pending refund failed or was cancelled, the
     *     plugin lists it no more and releases its reservation when it has one
     *     (releaseRefund()): nothing is booked. No lock (OrderMoneyLock, a
     *     transaction, a row lock) is ever held across a call to the provider;
     *   - a report is listed while the provider says that refund was made or is
     *     being made, no later report says it failed or was cancelled, and the
     *     books hold no refund row of this payment under its provider id (a
     *     failed or cancelled row holds none). The core leaves out, whatever the
     *     plugin lists, a report already booked under its provider id and one
     *     whose outcome it keeps (recorded, reconciled, duplicate or invalid);
     *     a malformed entry holds the block, shown with what is wrong;
     *   - read only from the plugin's own log and the books: it writes nothing,
     *     takes no lock and never calls the provider;
     *   - empty for a payment that is not this plugin's, or that does not exist.
     *
     * The core's reading of these reports for one payment is
     * unbookedProviderRefundsOfPayment(), never called from here: the
     * core refuses it with a LogicException and reads the payment as unread.
     *
     * This default answers empty: a payment method with no provider channel (the
     * bundled Standard method) can never hold a refund a provider reported.
     *
     * @api HOOK: the provider's refunds the books do not hold, one entry per refund, oldest first; core asks it of each
     *      payment's gateway when it decides a refund start and for the order screen's notice.
     *
     * @param int $paymentId The payment.
     *
     * @return list<array{reportId: string, id: ?string, amount: ?Money, providerAmount: string, providerCurrency: string, reportedOn: string, status: string}>
     *
     * @since  2.1.0
     */
    public function unbookedProviderRefunds(int $paymentId): array
    {
        return [];
    }

    /**
     * Update an existing payment (returns fluent builder in UPDATE mode).
     *
     * Only fields you explicitly set via setter methods are sent to the
     * database. If nothing is changed, save() is a no-op.
     *
     * Usage:
     *   $this->paymentUpdate($id)->completed()->save();
     *   $this->paymentUpdate($id)->cancelled()->note('Customer request')->save();
     *   $this->paymentUpdate($id)->refunded()->save();
     *
     * A plugin's write carries no staff mark, inside a staff user's request or not: the ledger's staff guard (a
     * gateway payment's provider id, method and pending refunds, for the payment form and the REST API) never refuses
     * it. Arm ->expectStatus() where a webhook and a return can race.
     *
     * The builder names this plugin as its writer, so save(), saveVerdict() and settleAttempt() alike write only a
     * payment recorded as this plugin's, and move it only to another method of this plugin; anything else is refused
     * and nothing is written.
     *
     * THE PAYMENT'S PLUGIN DATA. A payment reads its own payload, and a write goes back through this builder, which
     * merges:
     *
     *     $payment = $order->payments()[0];
     *     $payment->pluginData()->getString(key: 'wallet', default: '');
     *     $payment->pluginData()->has('wallet');
     *
     *     $this->paymentUpdate($id)->pluginData(['wallet' => $v])->save();
     *     $this->paymentUpdate($id)->removePluginData(['wallet'])->save();
     *
     * Pass PHP arrays, never JSON: encoding belongs to the ledger. Only the keys you name change, and the merge happens
     * with the row held, so a webhook writing one key and an admin action writing another cannot erase each other.
     * Storing null KEEPS the key (has() stays true, a typed getter answers with its default); removing takes it away.
     * The merge is one level deep: a key you write replaces what was under it, wholesale. Core never looks inside any
     * of it.
     *
     * @api ANSWERS: the ledger in builder mode for one row, naming this plugin as the writer, so its save() refuses
     *      another plugin's row; throws when the payment is not found.
     *
     * @param int $paymentId Existing payment row PK
     *
     * @return PaymentLedger Builder: chain setters, finish with ->save()
     *
     * @throws RuntimeException If payment not found in DB
     *
     * @since  1.0.0
     */
    protected function paymentUpdate(int $paymentId): PaymentLedger
    {
        return PaymentLedger::load($paymentId)->writtenBy(element: (string) $this->_name);
    }

    //  Intent verbs
    //
    //  One call per thing that happened. Prefer these over
    //  hand-driving the state machine with status flips; the
    //  sequence lives in the ledger, so every gateway gets the
    //  same one.

    /**
     * Settle a verified attempt exactly once, and be loud if the books refuse.
     *
     * The compare-and-set half of a gateway's success path. Every gateway
     * needs it and every gateway used to write its own, which is why the
     * failure branch drifted: the same condition was answered at three
     * different log levels and one gateway did not log it at all.
     *
     * The unrecoverable case is the whole point. The gateway has ALREADY
     * moved the money and the ledger would not record it. Nobody can find
     * that from the ledger (the row simply still says pending), so it has to
     * be shouted where an operator looks. That is written HERE, once, at
     * CRITICAL, so a gateway author cannot forget it and cannot downgrade it.
     *
     * What deliberately stays with the caller is the ANSWER, because it is
     * transport-specific and not ours to unify: a machine-to-machine webhook
     * replies with a status code whose retry semantics its own acquirer
     * defines, while a browser return has to redirect a human. Folding those
     * together would be a behaviour change dressed up as a refactor, so this
     * returns the verdict and each transport maps it.
     *
     * Takes the BUILDER, not a column array: PaymentLedger::saveToTable()
     * filters unknown keys out silently, so a typo in a hand-written array
     * writes a row that looks saved and never changed status. A mistyped
     * setter cannot even load. ->expectStatus() must be armed; without it
     * there is no race to report and saveVerdict() falls back to a plain
     * update.
     *
     * Payload staged on the builder with ->pluginData() is written by the same
     * compare-and-set as the status, and not at all when the verdict is not
     * WRITE_OK; no second save() for it.
     *
     * The builder names this gateway as its writer (paymentUpdate()), so the
     * compare-and-set holds the plain update's rule: only this gateway's own
     * rows, moved only to another method of its own; anything else is refused
     * and answers WRITE_FAILED.
     *
     * Usage:
     *   $write = $this->paymentUpdate((int) $payment->id)
     *       ->expectStatus(self::STATUS_PENDING)
     *       ->completed()
     *       ->transactionId($txId)
     *       ->processedAt($now);
     *
     *   switch ($this->settleAttempt($write, (int) $order->id, $txId)) {
     *       case self::WRITE_OK:    ... fire the success answer
     *       case self::WRITE_RACED: ... the other transport won; agree with it
     *       default:                ... refuse; the money is not in the books
     *   }
     *
     * @api ANSWERS: WRITE_OK, WRITE_RACED or WRITE_FAILED; the staged plugin data is written by the same
     *      compare-and-set as the status, or not at all.
     *
     * @param PaymentLedger $write Update builder with ->expectStatus() armed.
     * @param int $orderId Order the attempt belongs to.
     * @param string $reference Gateway transaction id or order reference,
     *                          for the CRITICAL line; this is what an
     *                          operator reconciles against.
     *
     * @return int self::WRITE_OK, self::WRITE_RACED or self::WRITE_FAILED.
     *
     * @since  2.0.0
     */
    protected function settleAttempt(PaymentLedger $write, int $orderId, string $reference = ''): int
    {
        $verdict = $write->saveVerdict();

        if ($verdict === self::WRITE_OK || $verdict === self::WRITE_RACED) {
            return $verdict;
        }

        // logCategory() is this plugin's own, and PaymentsPlugin's constructor
        // already registered a logger for it; no ensureLogger() here, that one
        // claims the COMPONENT's category and would file the line elsewhere.
        Log::add(
            sprintf(
                '%s: verified transaction %s on order #%d was rejected by the ledger: the money moved and the books did not. Reconcile manually.',
                ucfirst((string) $this->_name),
                $reference !== '' ? $reference : '(no reference)',
                $orderId,
            ),
            Log::CRITICAL,
            $this->logCategory(),
        );

        return $verdict;
    }

    /**
     * Settle a verified attempt exactly once, and answer what the write did.
     *
     * The write settleAttempt() makes, answered as a verdict: written, raced (the other transport won: agree with it),
     * refused (a rule refused it: repeating the settle is useless; $code says which), lock_not_taken (the settle waits
     * for the provider's next delivery or the buyer's return; the row stays pending and is settled once then), or
     * write_failed. Its line in this plugin's log: CRITICAL for refused and write_failed (the money moved and the books
     * did not: reconcile), WARNING for lock_not_taken, none for written and raced. What the gateway answers its provider
     * stays the plugin's decision.
     *
     * Usage:
     *   $verdict = $this->settleAttemptVerdict($write, (int) $order->id, $txId);
     *   switch ($verdict->kind) {
     *       case self::VERDICT_WRITTEN:
     *       case self::VERDICT_RACED:          ... fire the success answer
     *       case self::VERDICT_LOCK_NOT_TAKEN: ... ask the provider to deliver again
     *       default:                           ... reconcile; repeating is useless for refused
     *   }
     *
     * @api ANSWERS: a PaymentWriteVerdict for the settle (written, raced, refused, lock_not_taken or write_failed).
     *
     * @param PaymentLedger $write Update builder with ->expectStatus() armed.
     * @param int $orderId Order the attempt belongs to.
     * @param string $reference Gateway transaction id or order reference, for the log line.
     *
     * @return PaymentWriteVerdict What the write did.
     *
     * @since  2.2.2
     */
    protected function settleAttemptVerdict(PaymentLedger $write, int $orderId, string $reference = ''): PaymentWriteVerdict
    {
        $verdict = $write->saveWithVerdict();
        $shown = $reference !== '' ? $reference : '(no reference)';

        if ($verdict->kind === self::VERDICT_REFUSED || $verdict->kind === self::VERDICT_WRITE_FAILED) {
            Log::add(
                sprintf(
                    '%s: verified transaction %s on order #%d was rejected by the ledger (%s%s): the money moved and the books did not. Reconcile manually.',
                    ucfirst((string) $this->_name),
                    $shown,
                    $orderId,
                    $verdict->kind,
                    $verdict->code !== '' ? ', ' . $verdict->code : '',
                ),
                Log::CRITICAL,
                $this->logCategory(),
            );
        } elseif ($verdict->kind === self::VERDICT_LOCK_NOT_TAKEN) {
            Log::add(
                sprintf(
                    '%s: verified transaction %s on order #%d waits: another operation held the order; the provider\'s next delivery or the buyer\'s return settles it once.',
                    ucfirst((string) $this->_name),
                    $shown,
                    $orderId,
                ),
                Log::WARNING,
                $this->logCategory(),
            );
        }

        return $verdict;
    }

    /**
     * Close a gateway attempt nobody is going to pay: the buyer walked
     * away, or came back and started a new one.
     *
     * NOT a cancellation the customer or the merchant should see. A pending
     * row reserves its slice of the order total against the overpayment cap,
     * so an abandoned attempt left pending blocks the NEXT attempt from being
     * recorded; this stops the cap counting money that will never arrive.
     *
     * Do NOT reach for ->cancelled() here. That fires the method's
     * status_on_cancelled map, which moves the ORDER, writes its activity
     * entry, sends the status email and applies stock/entitlement effects,
     * on an order the buyer may be paying for in another tab. This writes
     * through the ledger's bookkeeping seam instead: row, diff and activity
     * log, nothing merchant-visible.
     *
     * Compare-and-set from pending, so an attempt that settled while you were
     * deciding reports WRITE_RACED and is left as the winner left it; treat
     * anything but WRITE_OK as "the ledger no longer says what I assumed",
     * and reload rather than hand the buyer to the gateway. Only this
     * gateway's own attempts: another gateway's row is refused, WRITE_FAILED.
     *
     * Usage:
     *   if ($this->supersedeAttempt((int) $payment->id, 'New gateway visit') !== self::WRITE_OK) {
     *       throw new RuntimeException(Text::_('..._ERROR_NO_PENDING_PAYMENT'));
     *   }
     *
     * @api ANSWERS: WRITE_OK, WRITE_RACED or WRITE_FAILED; this plugin's own attempts only.
     *
     * @param int $paymentId Pending attempt row PK.
     * @param string $reason Why it was abandoned, stamped into the row's note.
     *
     * @return int self::WRITE_OK, self::WRITE_RACED or self::WRITE_FAILED.
     *
     * @since  2.0.0
     */
    protected function supersedeAttempt(int $paymentId, string $reason = ''): int
    {
        return PaymentLedger::supersedeAttempt($paymentId, $reason, writer: (string) $this->_name);
    }

    /**
     * Close a gateway attempt nobody is going to pay, and answer what the write did: the write
     * supersedeAttempt() makes, as a verdict. Written when this call closed it; raced when it was no longer pending;
     * refused with REFUSAL_ROW_MISSING (the row or its order is gone) or REFUSAL_NOT_WRITER (another gateway's row: a
     * gateway never closes another gateway's attempt); lock_not_taken; write_failed.
     *
     * Usage (step zero of opening an attempt: the plugin's own open attempt first):
     *   $open = $this->latestPendingPayment((int) $order->id);
     *   if ($open !== null && !$reusable) {
     *       $closed = $this->supersedeAttemptVerdict((int) $open->id, 'New gateway visit');
     *   }
     *
     * @api ANSWERS: a PaymentWriteVerdict for the close; another gateway's row is refused (REFUSAL_NOT_WRITER).
     *
     * @param int $paymentId Pending attempt row PK.
     * @param string $reason Why it was abandoned, stamped into the row's note.
     *
     * @return PaymentWriteVerdict What the write did.
     *
     * @since  2.2.2
     */
    protected function supersedeAttemptVerdict(int $paymentId, string $reason = ''): PaymentWriteVerdict
    {
        return PaymentLedger::supersedeAttemptVerdict($paymentId, $reason, writer: (string) $this->_name);
    }

    /**
     * Refund a payment: audit row and the payment's refunded state as ONE
     * idempotent operation, so your plugin never re-derives the dance.
     *
     * The ledger writes the linked audit row with the right refund_type and
     * the ORIGINAL's payment method under the order's lock, then decides the
     * payment's state from the books (refunds covering the payment: the
     * original flips to 'refunded' and status_on_refunded fires; less is
     * partial and the original correctly stays 'completed'). A refused row
     * changes nothing.
     *
     * IDEMPOTENT: a second call on an already-refunded payment reports
     * OUTCOME_ALREADY_REFUNDED and writes nothing; it never flips the row
     * back to completed, which would re-grant the download entitlements the
     * refund revoked.
     *
     * ONE PROVIDER REFUND, ONE ROW: pass the gateway's own REFUND id as
     * 'transaction_id', never the payment's. A reference already on a refund
     * row of this payment is answered OUTCOME_DUPLICATE and writes nothing,
     * decided under the ledger's order lock, so a plugin needs no lock or
     * "already recorded?" query of its own for it. An empty reference is never
     * de-duplicated.
     *
     * A REFUND YOUR PLUGIN STARTS never comes here: reserveRefund() FIRST (it
     * decides and reserves under the order's lock), then the provider with the
     * reservation's key and no lock held, then settleRefund(), holdRefund() or
     * releaseRefund(). This verb with 'gateway_moved' => true
     * books money a provider moved that no reservation covers (a refund made
     * in the provider's portal, found by a check or a notification): the money
     * has already left, so when the books refuse the audit row the ledger
     * logs CRITICAL and answers OUTCOME_UNRECONCILED. A reference a reservation of the payment carries settles that
     * reservation. Without 'gateway_moved' it is a refund with no provider
     * call (the bundled Standard method), decided and booked in one locked step.
     *
     * EVERY PROVIDER WORD (this verb with 'gateway_moved', settleRefund(),
     * holdRefund(), releaseRefund()) is sent outside any transaction of your
     * plugin's: sent inside one, it is answered OUTCOME_FAILED and nothing is
     * written. The core's reads there would be your transaction's snapshot,
     * taken before the order's lock, and a delivery committed meanwhile could
     * be booked a second time. Any transaction open on the
     * connection counts, one held by the caller of your hook included: send
     * the word once that transaction has committed.
     *
     * Usage:
     *   $result = $this->refundPayment((int) $payment->id, null, [
     *       'gateway_moved'    => true,
     *       'transaction_id'   => $refundTxId,
     *       'gateway_response' => json_encode($response),
     *       'reason'           => Text::sprintf('..._LOG_REFUNDED', $payment->id),
     *   ]);
     *
     *   if ($result['outcome'] !== self::OUTCOME_OK) {
     *       $event->setError(Text::sprintf('..._ERROR_REFUND', $result['reason']));
     *   }
     *
     * @api ANSWERS: an array whose 'outcome' is one of the OUTCOME_ constants of this class, with the refund's id and a
     *      reason.
     *
     * @param int $paymentId Original payment row PK.
     * @param ?Money $amount Amount to return; null = the refundable
     *                       remainder. A zero or negative Money is REFUSED
     *                       (OUTCOME_NOT_REFUNDABLE); only absence means
     *                       "everything left". Must be in the order's
     *                       currency.
     * @param array $options 'reason', 'transaction_id', 'gateway_response',
     *                       'document', 'gateway_moved', 'request_key',
     *                       'report_id'; see PaymentLedger::refundPayment().
     *                       'plugin' is set to this plugin: a start on
     *                       another plugin's payment is refused
     *                       (OUTCOME_REFUSED); money moved on it is kept for
     *                       staff (OUTCOME_UNRECONCILED).
     *
     * @return array ['outcome' => self::OUTCOME_*, 'refund_id' => int, 'reason' => string]
     *
     * @since  2.0.0
     */
    protected function refundPayment(int $paymentId, ?Money $amount = null, array $options = []): array
    {
        return PaymentLedger::refundPayment($paymentId, $amount, ['plugin' => $this->_name] + $options);
    }

    /**
     * Decide a refund start and reserve its amount, BEFORE your provider is asked
     * ({@see PaymentLedger::reserveRefund()}).
     *
     * Under the order's short lock: the refund block, the books, what the order
     * and this payment may still refund once everything reserved is kept apart,
     * and the double submit (the 'mark' your form was drawn with,
     * RefundMark::of()). It writes the reservation, a pending refund row, and
     * commits before it returns: from then on every other start counts it (call
     * it outside any transaction of yours, or it commits only with yours). Then
     * call your provider with NO lock held (no OrderMoneyLock, no transaction),
     * sending exactly 'amount' and the idempotency key 'key' (a resend is
     * unconditional: the same reservation, sent with the same key; the core has
     * already decided it: never reserve again for a resend), and report the answer:
     * settleRefund() when the provider completed it, holdRefund() when it
     * accepted it as pending, releaseRefund() when it definitely refused it;
     * nothing when the answer is unknown (timeout, HTTP 0 or 5xx, an unreadable
     * body): the reservation stays until a check, a notification or staff
     * resolve it. A reservation never makes the payment refunded.
     *
     * Usage:
     *   $r = $this->reserveRefund((int) $payment->id, $typedOrNull, ['mark' => $postedMark, 'reason' => $why]);
     *   if ($r['outcome'] !== self::OUTCOME_RESERVED) { ...refuse with $r['reason'], send nothing... }
     *   $answer = <provider refund of $r['amount'], idempotency key $r['key']>;
     *
     * @api ANSWERS: an array whose 'outcome' is one of the OUTCOME_ constants of this class, with the reservation and
     *      its key.
     *
     * @param int $paymentId The completed payment to refund.
     * @param Money|null $amount In the order's currency; null = the form's maximum: what may start on
     *                           the payment net of its own reservations, held to what the order has
     *                           left (refundFormMaximum()). 'full' in the answer: it takes all the
     *                           payment's refunds may still cover, net of its own other
     *                           reservations; informational only: always send 'amount' explicitly,
     *                           never a provider call for a full refund.
     * @param array $options 'mark' (int), 'reason' (string), 'document' (int); 'plugin' is set to
     *                       this plugin (a gateway acts on its own payments only).
     *
     * @return array{outcome: string, reservation: int, key: string, amount: ?Money, full: bool, reason: string}
     *
     * @since  2.1.0
     */
    protected function reserveRefund(int $paymentId, ?Money $amount = null, array $options = []): array
    {
        return PaymentLedger::reserveRefund(paymentId: $paymentId, amount: $amount, options: ['plugin' => $this->_name] + $options);
    }

    /**
     * The provider completed a reserved refund: book it ({@see PaymentLedger::settleRefund()}).
     *
     * Replaces refundPayment() with gateway_moved for a refund your plugin
     * reserved. The provider's figure is booked as it is (above the reservation
     * too: never refused for being above, never truncated); never refused for a
     * reservation. 'final' => true when this is the decision's last (or only)
     * provider refund. Idempotent per provider refund id; with no provider id it
     * is the request's one refund (taken whole), so a resent notice finds it
     * booked. A reservation carries one provider refund: another one under the
     * same key is booked on its own row beside it. When the books refuse it
     * (OUTCOME_UNRECONCILED: money moved that the books cannot hold) the core
     * keeps it on a row of its own (refund_id) and lists its own settled report
     * of it ('reservation:' and that row id): the order holds the refund block
     * until staff take each such report through the way out (Record this refund,
     * reconcile, duplicate or invalid). You need not keep listing that refund's
     * report when it has a provider id; with no provider id you
     * must not keep listing it (nothing could tell your report from the core's,
     * and both would offer Record this refund). You never list a 'reservation:'
     * reportId. Every provider word is passed on, also one whose report staff
     * already took through the way out (no check of your own with
     * reportOutcome()); name the report you list (or listed) it under in
     * 'report_id'. The core never books it over a staff resolution of any
     * report (yours or its own), matched by the provider id, else by that
     * report: it lists it once more for staff (OUTCOME_UNRECONCILED); once
     * staff resolved it again, the same word at the same figure is only
     * logged (OUTCOME_DUPLICATE). A report staff recorded answers
     * OUTCOME_DUPLICATE with its row. With no provider id, one refund request
     * is one refund: the same figure again is a duplicate, a different one is
     * kept for staff (OUTCOME_UNRECONCILED). Sent outside any transaction of
     * yours (inside one: OUTCOME_FAILED, nothing written; see refundPayment()).
     *
     * @api ANSWERS: an array whose 'outcome' is one of the OUTCOME_ constants of this class, with the refund's id and a
     *      reason.
     *
     * @param int $reservationId The reservation (or a pending refund row split off it).
     * @param Money $amount The provider's figure, in the order's currency.
     * @param string|null $providerRefundId The provider's refund id as given; null when it gives none.
     * @param array $options 'final' (bool), 'gateway_response', 'reason', 'processed_at', 'report_id' (the
     *                       report you list or listed this refund under); 'plugin' is set to this plugin.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    protected function settleRefund(int $reservationId, Money $amount, ?string $providerRefundId, array $options = []): array
    {
        return PaymentLedger::settleRefund(reservationId: $reservationId, amount: $amount, providerRefundId: $providerRefundId, options: ['plugin' => $this->_name] + $options);
    }

    /**
     * The provider accepted a reserved refund but has not completed it: it stays
     * reserved, under the provider's refund id ({@see PaymentLedger::holdRefund()}).
     * The provider's figure is held as it is: a larger one grows the reservation
     * (beyond the ceiling it is kept and every start is refused). Sent outside
     * any transaction of yours, as settleRefund() is.
     *
     * @api ANSWERS: an array whose 'outcome' is one of the OUTCOME_ constants of this class, with the refund's id and a
     *      reason.
     *
     * @param int $reservationId The reservation.
     * @param string|null $providerRefundId The provider's refund id as given; null when it gives none.
     * @param Money|null $amount The provider's figure; null = the whole reservation.
     * @param array $options 'final' (bool), 'gateway_response', 'report_id' (as settleRefund()); 'plugin' is set
     *                       to this plugin.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    protected function holdRefund(int $reservationId, ?string $providerRefundId, ?Money $amount = null, array $options = []): array
    {
        return PaymentLedger::holdRefund(reservationId: $reservationId, providerRefundId: $providerRefundId, amount: $amount, options: ['plugin' => $this->_name] + $options);
    }

    /**
     * Give back what a reservation still holds: the provider definitively refused
     * the refund, or the decision closed with this rest unused
     * ({@see PaymentLedger::releaseRefund()}). Never for an unknown outcome.
     * Sent outside any transaction of yours, as settleRefund() is.
     *
     * @api ANSWERS: an array whose 'outcome' is one of the OUTCOME_ constants of this class, with the refund's id and a
     *      reason.
     *
     * @param int $reservationId The reservation.
     * @param string $reason Why, plain text.
     * @param array $options 'status' (self::STATUS_FAILED or self::STATUS_CANCELLED),
     *                       'provider_refund_id', 'gateway_response'; 'plugin' is set to this plugin.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    protected function releaseRefund(int $reservationId, string $reason, array $options = []): array
    {
        return PaymentLedger::releaseRefund(reservationId: $reservationId, reason: $reason, options: ['plugin' => $this->_name] + $options);
    }

    /**
     * One attempt of a reserved refund failed at the provider and the request stands: keep the reservation for the
     * retry ({@see PaymentLedger::failRefundAttempt()}).
     *
     * For your provider's definite failure of the refund it held under $providerRefundId, when you will try again for
     * the same request. The reservation keeps its amount and is given a new key; send the retry with the answer's
     * 'key' and exactly its 'amount', then report the retry's outcome on the same reservation (holdRefund(),
     * settleRefund(), releaseRefund()). No figure moves: refundableNow(), refundFormMaximum() and bookedRefundable()
     * read the same before and after, so the retry is never reserved a second time beside it. The same failure sent
     * again answers self::OUTCOME_DUPLICATE and writes nothing. To give the request up instead, releaseRefund(). Sent
     * outside any transaction of yours, as settleRefund() is.
     *
     * Usage:
     *   $r = $this->failRefundAttempt($reservationId, $failedRefundId, $why);
     *   if ($r['outcome'] === self::OUTCOME_RESERVED) { ...retry: $r['amount'], idempotency key $r['key']... }
     *
     * @api ANSWERS: an array whose 'outcome' is one of the OUTCOME_ constants of this class, with the reservation's new
     *      key.
     *
     * @param int $reservationId The reservation.
     * @param string $providerRefundId The provider's id of the refund that failed, exactly as given.
     * @param string $reason Why, plain text.
     * @param array $options 'gateway_response' (string); 'plugin' is set to this plugin (a gateway acts on its own
     *                       payments only: another plugin's payment is refused).
     *
     * @return array{outcome: string, reservation: int, key: string, amount: ?Money, reason: string}
     *
     * @since  2.2.1
     */
    protected function failRefundAttempt(int $reservationId, string $providerRefundId, string $reason, array $options = []): array
    {
        return PaymentLedger::failRefundAttempt(reservationId: $reservationId, providerRefundId: $providerRefundId, reason: $reason, options: ['plugin' => $this->_name] + $options);
    }

    /**
     * One reservation as the books hold it, with its key while the provider has
     * not named the refund ({@see PaymentLedger::refundReservation()}). Read only.
     *
     * @api READ ONLY: the reservation, or null.
     *
     * @param int $reservationId The reservation.
     *
     * @return array{reservation: int, payment: int, order: int, amount: Money, status: string, key: ?string, providerRefundId: ?string}|null
     *
     * @since  2.1.0
     */
    protected function refundReservation(int $reservationId): ?array
    {
        return PaymentLedger::refundReservation(reservationId: $reservationId);
    }

    /**
     * The outcome the core keeps for one of this plugin's provider refund
     * reports, or null ({@see PaymentLedger::reportOutcome()}). Read only, and it
     * never asks this plugin, so unbookedProviderRefunds() may call it.
     *
     * @api READ ONLY: the outcome core keeps for one of this plugin's refund reports, or null; never asks the plugin.
     *
     * @param int $paymentId The payment.
     * @param string $reportId The report's reference.
     *
     * @return array{outcome: string, refundRow: ?int, bookedAmount: ?Money, amountTyped: bool, providerRefundId: ?string, created: string}|null
     *
     * @since  2.1.0
     */
    protected function reportOutcome(int $paymentId, string $reportId): ?array
    {
        return PaymentLedger::reportOutcome(paymentId: $paymentId, reportId: $reportId);
    }

    /**
     * The refunds this plugin's provider reports for one of its payments that the books do not hold, as the core
     * reads them ({@see PaymentLedger::unbookedProviderRefundsOfPayment()}): from the reports this plugin gives in
     * unbookedProviderRefunds(), those already booked under their provider id and those whose outcome the core keeps
     * are left out; 'reserved' lists the pending ones whose exact amount reserves the refundable pool, 'refunds' the
     * ones that hold the refund block, and 'unread' says what the core could not read of the answer (a malformed
     * entry, an answer that threw), or is null.
     *
     * Not this plugin's own report: that is unbookedProviderRefunds(), which the core asks to build this answer, so
     * this is never called from it. A call from it is refused with a LogicException; the core then reads the payment as
     * unread, and the refund block holds until the plugin is fixed.
     *
     * @api READ ONLY: writes nothing, takes no lock and never calls the provider; asks this plugin's
     *      unbookedProviderRefunds() once; a payment that is not this plugin's, or not a payment, answers the empty shape.
     *
     * @param int $paymentId The payment.
     *
     * @return array{refunds: list<array{reportId: string, id: ?string, amount: ?Money, providerAmount: string, providerCurrency: string, reportedOn: string, status: string}>, reserved: list<array{reportId: string, id: ?string, amount: Money, providerAmount: string, providerCurrency: string, reportedOn: string, status: string}>, unread: string|null}
     *
     * @throws LogicException When called from this plugin's own unbookedProviderRefunds().
     *
     * @since  2.2.2
     */
    final protected function unbookedProviderRefundsOfPayment(int $paymentId): array
    {
        // Another gateway's row is never read through this plugin, as for every scoped reader of the set: one row of this
        // plugin's element (the row's own payment_method_type, which keeps answering after the method row is deleted)
        // and of type payment, by its id, read raw: nothing is hydrated, so an order without a currency answers as
        // the ledger's reader answers it (unread), never with an exception of its own.
        $db = Factory::getContainer()->get('DatabaseDriver');
        $owned = $paymentId > 0 && (int) $db->setQuery(
            $db->getQuery(true)
                ->select('1')
                ->from($db->quoteName('#__alfa_order_payments'))
                ->where($db->quoteName('id') . ' = ' . $paymentId)
                ->where($db->quoteName('payment_method_type') . ' = ' . $db->quote($this->_name))
                ->where($db->quoteName('payment_type') . ' = ' . $db->quote(PaymentLedger::TYPE_PAYMENT)),
        )->loadResult() === 1;

        if (!$owned) {
            return ['refunds' => [], 'reserved' => [], 'unread' => null];
        }

        return PaymentLedger::unbookedProviderRefundsOfPayment(paymentId: $paymentId);
    }

    /**
     * Record that a booked refund, or a chargeback, did not stand at the provider.
     *
     * A fact the provider has already stated; no gateway is called. The refund
     * or chargeback row is never edited: a new 'reversal' row names it. A
     * reversed refund that leaves its payment holding money again puts the
     * payment back to completed as bookkeeping only: no status_on_completed,
     * no order move, no email. A won dispute is this call on the chargeback row.
     *
     * Answer the provider 200 on OUTCOME_RECORDED, OUTCOME_DUPLICATE and
     * OUTCOME_NOTHING_LEFT; 200 and report on OUTCOME_REFUSED; 503 on
     * OUTCOME_FAILED so the provider redelivers. Never throws. Only this
     * gateway's own rows: another gateway's row is refused.
     *
     * @api ANSWERS: ['outcome', 'row_id', 'reason', 'amount', 'reopened']; never throws; this plugin's own rows only.
     *
     * @param int $rowId The refund or chargeback row.
     * @param Money|null $amount What did not stand; null means all not yet reversed.
     * @param array $options 'transaction_id' (required), 'reason',
     *                       'gateway_response', 'processed_at' (UTC Y-m-d H:i:s).
     *
     * @return array ['outcome', 'row_id', 'reason', 'amount', 'reopened']
     *
     * @since  2.0.0
     */
    protected function recordReversal(int $rowId, ?Money $amount = null, array $options = []): array
    {
        return PaymentLedger::recordReversal(rowId: $rowId, amount: $amount, options: ['plugin' => $this->_name] + $options);
    }

    /**
     * Record money the customer's bank took back from a completed payment of this gateway.
     *
     * Never capped, never flips the payment, never moves the order. Outcomes
     * and options as recordReversal(); another gateway's payment is refused.
     *
     * @api ANSWERS: ['outcome', 'row_id', 'reason', 'amount', 'reopened']; never capped, never moves the order; another
     *      gateway's payment is refused.
     *
     * @param int $paymentId The completed payment.
     * @param Money $amount What the bank took back, in the order's currency.
     * @param array $options As recordReversal().
     *
     * @return array ['outcome', 'row_id', 'reason', 'amount', 'reopened']
     *
     * @since  2.0.0
     */
    protected function recordChargeback(int $paymentId, Money $amount, array $options = []): array
    {
        return PaymentLedger::recordChargeback(paymentId: $paymentId, amount: $amount, options: ['plugin' => $this->_name] + $options);
    }

    /**
     * The refund or chargeback row of a payment carrying this exact provider reference.
     *
     * A failed or cancelled row reserves nothing and is never returned. The
     * reference is compared whole and byte for byte.
     *
     * @api READ ONLY: the refund or chargeback row carrying the reference, matched byte for byte, or null.
     *
     * @param int $paymentId The payment.
     * @param string $type self::TYPE_REFUND or self::TYPE_CHARGEBACK.
     * @param string $reference The complete provider reference.
     *
     * @return object|null id, amount, transaction_status, transaction_id; or null.
     *
     * @since  2.0.0
     */
    protected function findLinkedRow(int $paymentId, string $type, string $reference): ?object
    {
        return PaymentLedger::findLinkedRow(paymentId: $paymentId, type: $type, reference: $reference);
    }

    /**
     * RECURRING CONTRACT: whether this gateway can charge a stored
     * credential off-session (merchant-initiated transactions).
     *
     * Gateways that return true implement these handlers, each the public
     * method of the event's name, called on this gateway alone:
     *
     *   onPaymentStoreReference(StoreReferenceEvent $event): void
     *     After a successful customer-present payment: extract whatever the
     *     gateway needs to charge again later (e.g. an initial transaction id
     *     + sourceCode) and $event->setReference([...]). The CALLER persists
     *     it; the plugin never stores it and never learns what it is for.
     *
     *   onPaymentChargeReference(ChargeReferenceEvent $event): void
     *     A GENERIC payment attempt: charge getAmount()/getCurrencyCode()
     *     against getReference() with no customer present, then answer
     *     exactly once. The gateway never knows WHY it is charging (renewal,
     *     re-charge, ...). The attempt's payment row is the gateway's, booked
     *     before the provider is called, as for a checkout payment; the
     *     caller books nothing and reads the row the answer names.
     *
     *     1. An earlier attempt of this order still unresolved (your own
     *        pending row of the order under another reference,
     *        latestPendingPayment(), or one your provider still holds): book
     *        nothing, send nothing, answer
     *        setNotSent($why, ChargeReferenceEvent::NOT_SENT_EARLIER_UNRESOLVED).
     *     2. Book the attempt's row:
     *          $verdict = $this->chargeAttempt($event)->saveWithVerdict();
     *        a pending payment of your gateway for getAmount(), its reference
     *        renewalReference() of the order, the credential and the attempt's
     *        number. The same attempt sent again answers the same row and
     *        writes nothing new. Only VERDICT_WRITTEN goes on to the provider.
     *        VERDICT_LOCK_NOT_TAKEN and VERDICT_WRITE_FAILED: setNotSent()
     *        with NOT_SENT_RETRY. VERDICT_REFUSED with REFUSAL_OPEN_ATTEMPT:
     *        setNotSent() with NOT_SENT_EARLIER_UNRESOLVED. Any other refusal
     *        (REFUSAL_OVER_TOTAL: the order is covered; REFUSAL_REFERENCE_TAKEN:
     *        this attempt's row is already closed, so this number is spent):
     *        setFailure() with DECLINE_CONFIGURATION.
     *     3. Call the provider with the row's reference as the merchant
     *        reference and the idempotency key. A provider that cannot take it
     *        as it is may be sent a form derived from it alone; your webhook
     *        and Reconcile still find the row by the row's own reference, or
     *        by the provider's id once you write it on the row.
     *     4. Settle your row as any attempt (settleAttemptVerdict(); the
     *        method's status_on_<phase> map runs when it completes), then
     *        answer, naming it:
     *
     *     setSuccess($transactionId, $verdict->rowId)
     *       The provider confirmed THIS charge as taken, for exactly the
     *       amount and currency asked, and your row is settled completed with
     *       the provider's id of the charge, exactly as it gave it.
     *
     *     setPending($reference, $reason, $verdict->rowId)
     *       The provider may have taken money: your row stays pending, the
     *       caller never charges that attempt again, and your webhook or
     *       Reconcile settles it (settleAttempt() with
     *       ->expectStatus(self::STATUS_PENDING) and ->completed() when the
     *       provider confirms, ->failed() when it definitively refuses). Use it
     *       whenever the request may have reached the provider and you hold no
     *       definite answer: a timeout or dropped connection after sending,
     *       HTTP 0 or 5xx, an unreadable body, a status the provider itself
     *       calls pending or processing, or a "success" whose amount, currency
     *       or identity does not match what was asked. $reference is what your
     *       webhook and Reconcile find the row by through
     *       gatewayPaymentByReference(): the provider's id of this charge when
     *       it gave one (write it on the row), else the row's reference.
     *
     *     setFailure($error, $decline)
     *       No money moved, and you know it: the provider answered with a
     *       definite refusal, or you refused to send the request. Close your
     *       row failed first. The attempt is spent; the kind says when the
     *       caller may try a new one: DECLINE_NOW (declined for now: funds, a
     *       generic decline; the default), DECLINE_FINAL (declined for good:
     *       stolen, closed account, revoked mandate, expired card),
     *       DECLINE_AUTHENTICATE (the customer must confirm the payment),
     *       DECLINE_NEW_CREDENTIAL (the customer must give a new credential),
     *       DECLINE_CONFIGURATION (the method's settings, the currency or scale,
     *       a credential of the other credential set, the order's books: staff
     *       must act). Once you have answered success or pending, a later
     *       failure is ignored.
     *
     *     setNotSent($reason, $why)
     *       Nothing reached the provider for this attempt and no money moved.
     *       NOT_SENT_RETRY (the default): leave your row pending as it is; the
     *       caller sends the same attempt again, with the same number, and
     *       finds it. NOT_SENT_EARLIER_UNRESOLVED: an earlier attempt of the
     *       order is unresolved; book nothing.
     *
     *     Never throw and never return without answering: the caller treats
     *     both as an unknown outcome, never charges again and asks an operator
     *     to review (or asks you first, below).
     *
     *   onPaymentChargeQuery(ChargeQueryEvent $event): void (optional)
     *     The caller asks, before it sends again an attempt whose outcome it
     *     does not know, what your provider holds for it: find it by what you
     *     keyed the request on (the order, the credential, the attempt and the
     *     amount) and answer setSuccess($transactionId), setPending($reference,
     *     $reason), setFailure($error, $decline), or setNotSent($reason) when
     *     the provider has no record of it. Read only: charge nothing, write
     *     nothing. A gateway without the method cannot be asked; the caller
     *     leaves the attempt to staff.
     *
     * Gateways without token support keep the default false: a renewal on
     * such a method is never charged by the gateway; its order stays owed, for
     * the customer to pay and staff to collect.
     *
     * This states only what the gateway can do. Whether a method actually
     * renews on its own is renewalsAllowed(): this, the method published, and
     * the method's own setting (methodAllowsRenewals()).
     *
     * @api HOOK: whether this gateway can charge a stored credential off-session; false by default.
     *
     * @since  2.0.0
     */
    public function supportsRecurring(): bool
    {
        return false;
    }

    /**
     * May a member of staff put an order through with this method?
     *
     * DEFAULT NO, and the default is the point. A storefront payment method
     * assumes a buyer with a browser: some do no more than write a row and wait
     * for money to arrive by other means, but others sign a request and hand
     * the buyer a form, and at least one contacts the gateway during placement
     * and creates a live order at the far end. Run that from a back office and
     * a real order exists at a real provider for a buyer who was never there,
     * and rolling back the database undoes none of it.
     *
     * So a gateway written before this question existed answers NO, and stays
     * unavailable to staff until somebody has thought about what it would do
     * with no shopper present. Return true only when the method completes
     * entirely server-side, needs no redirect, no browser session and no
     * request state, and books nothing at a provider merely by being invoked.
     *
     *
     * @api HOOK: whether staff may place an order with this method; false by default.
     *
     * @since  2.0.0
     */
    public function supportsOperatorPlacement(): bool
    {
        return false;
    }

    //  Credential sets and automatic renewals

    /**
     * The plugin_data key under which every attempt records the credential set
     * it was made with (PaymentEnvironment::SANDBOX or ::LIVE). Written when
     * the attempt is created; read back with paymentEnvironment().
     *
     * @var string
     *
     * @since  2.0.0
     */
    public const PAYLOAD_ENVIRONMENT = 'environment';

    /**
     * Whether a renewal of this method may run without the customer: the
     * gateway can do it at all (supportsRecurring()), the method is published
     * (MethodState::of()), and the method's saved settings allow it
     * (methodAllowsRenewals()).
     *
     * The one answer a subscriptions extension's billing engine asks, both before
     * it asks for a mandate and before it charges a stored one. A method of another gateway,
     * or none, is false; so is an unpublished, archived or trashed method: no new renewal
     * starts on it (settling an attempt already made stays the gateway's).
     *
     * @api READ ONLY: whether a renewal of the method may run without the customer; another gateway's method, a method
     *      that is not published, or none, is false.
     *
     * @param int $methodId #__alfa_payments.id.
     *
     *
     * @since  2.0.0
     */
    final public function renewalsAllowed(int $methodId): bool
    {
        if (!$this->supportsRecurring()) {
            return false;
        }

        $method = $this->loadPaymentMethod($methodId);

        if ($method === null || !MethodState::of(MethodState::PAYMENT, $methodId)->isPublished()) {
            return false;
        }

        return $this->methodAllowsRenewals((array) ($method->params ?? []));
    }

    /**
     * Whether this method's saved settings allow automatic renewals.
     *
     * A gateway that declares supportsRecurring() answers with its profile's
     * gated reading, MethodProfile::of($params)->automaticRenewals(), which
     * applies its own conditions (a card gateway: not on instant payments). The default is
     * false, so a gateway that does not answer never renews automatically.
     *
     * @api HOOK: whether the method's saved settings allow automatic renewals; false by default; read by
     *      renewalsAllowed().
     *
     * @param array $params The method's saved params.
     *
     *
     * @since  2.0.0
     */
    protected function methodAllowsRenewals(array $params): bool
    {
        return false;
    }

    /**
     * The reference of a renewal attempt's payment row: one value for the order, the stored credential and the
     * attempt's number, the same every time they are the same, so the same attempt sent again finds its own row and a
     * new attempt is a new row. The credential's keys are read in sorted order, so their order does not change it.
     *
     * The form: rn-<order id>-<16 hexadecimal digits of the credential's SHA-256>-a<attempt>, letters, digits and
     * hyphens, at most 60 characters. The row is booked with it (chargeAttempt()), and it is what the provider is sent
     * as the merchant reference and idempotency key.
     *
     * @api READ ONLY: a value computed from its arguments; reads and writes nothing.
     *
     * @param Order $order The order the attempt pays for.
     * @param array $credential The stored credential, as ChargeReferenceEvent::getReference() gives it.
     * @param int $attempt The attempt's number, from 1, as ChargeReferenceEvent::getAttempt() gives it.
     *
     * @return string The reference.
     *
     * @throws InvalidArgumentException An order without an id, an empty credential, or an attempt under 1.
     *
     * @since  2.3.0
     */
    final protected function renewalReference(Order $order, array $credential, int $attempt): string
    {
        if ($order->id() < 1 || $credential === [] || $attempt < 1) {
            throw new InvalidArgumentException('renewalReference(): an order with an id, a stored credential and an attempt of at least 1 are required.');
        }

        $sorted = static function (array $value) use (&$sorted): array {
            ksort($value, SORT_STRING);

            return array_map(static fn (mixed $part): mixed => \is_array($part) ? $sorted($part) : $part, $value);
        };
        $digest = hash('sha256', json_encode($sorted($credential), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return 'rn-' . $order->id() . '-' . substr($digest, 0, 16) . '-a' . $attempt;
    }

    /**
     * The builder of a renewal attempt's payment row, booked by the gateway before it calls the provider: a pending
     * payment of this gateway for the charge's amount, its reference renewalReference() of the charge's order,
     * credential and attempt. Finish it with ->saveWithVerdict() and go on to the provider only on VERDICT_WRITTEN; the
     * same attempt sent again answers the same row and writes nothing (supportsRecurring() states the whole sequence).
     *
     * Usage:
     *   $verdict = $this->chargeAttempt($event)->saveWithVerdict();
     *   if ($verdict->kind !== self::VERDICT_WRITTEN) { ...answer not sent, or a failure, by the verdict... }
     *
     * @api ANSWERS: the ledger in builder mode with this plugin as the writer, its saveWithVerdict() the verdict.
     *
     * @param ChargeReferenceEvent $event The charge this gateway was handed.
     *
     * @return PaymentLedger The builder; finish with ->saveWithVerdict().
     *
     * @throws InvalidArgumentException When the charge's order, credential or attempt cannot make a reference.
     *
     * @since  2.3.0
     */
    final protected function chargeAttempt(ChargeReferenceEvent $event): PaymentLedger
    {
        $order = $event->getOrder();

        return $this->payment($order)
            ->pending()
            ->amount($event->getAmount())
            ->transactionId($this->renewalReference(order: $order, credential: $event->getReference(), attempt: $event->getAttempt()));
    }

    /**
     * The credential set an existing payment was made in, as its attempt
     * recorded it under PAYLOAD_ENVIRONMENT. Never guessed and never read from
     * the switch: a payment that recorded none answers null, and the gateway
     * then offers no follow-up action for it.
     *
     * @api READ ONLY: the environment the payment's attempt recorded, or null.
     *
     * @param object $payment The #__alfa_order_payments row, or its Payment reader.
     *
     * @return string|null PaymentEnvironment::SANDBOX, ::LIVE, or null when none was recorded.
     *
     * @throws InvalidArgumentException When the recorded value is not sandbox or live.
     *
     * @since  2.0.0
     */
    protected function paymentEnvironment(object $payment): ?string
    {
        $reader = Payment::of($payment);
        $recorded = $reader->pluginData()->get(key: self::PAYLOAD_ENVIRONMENT);

        if ($recorded === null) {
            return null;
        }

        if (!\is_string($recorded)) {
            throw new InvalidArgumentException('Payment #' . $reader->id() . ' records an environment that is not text.');
        }

        return PaymentEnvironment::assert(environment: $recorded);
    }

    /**
     * Fluent order-status transition, pre-attributed to this plugin:
     * the order-side sibling of paymentUpdate():
     *
     *   $this->orderTransition($order->id)->to($statusId)->save();
     *
     * Runs the single core funnel (stock -> download entitlements -> status ->
     * activity log -> status email). Note that transitions mapped in the
     * method's params (status_on_<phase> in params.xml) fire automatically
     * when a transaction status changes; call this only for moments the
     * map cannot express.
     *
     * @param int $orderId Order PK.
     *
     * @since  2.0.0
     */
    protected function orderTransition(int $orderId): Transition
    {
        return Transition::for(orderId: $orderId)->byPlugin($this->_name);
    }

    //  PAYMENT READ / DELETE (no builder needed)

    /**
     * Delete an order payment record.
     *
     * Only a payment recorded as this plugin's: another plugin's row, or one that records no plugin, is refused and
     * nothing is deleted.
     *
     * @param int $id Payment row PK
     * @param int $orderId Order PK (for activity log)
     *
     * @return bool True on success
     *
     * @since  1.0.0
     */
    protected function deletePayment(int $id, int $orderId): bool
    {
        return PaymentLedger::delete($id, $orderId, writer: (string) $this->_name);
    }

    /**
     * THIS gateway's payment METHOD for an order: its settings, credentials
     * included, `$method->params` already decoded to an array.
     *
     *     $method = $this->getPaymentMethod($orderId);
     *     $secret = (string) ($method->params['shared_secret'] ?? '');
     *
     * No Registry, no json_decode, no shape check. It is the same record the
     * admin edits and the same one the order hangs on its payment rows, so
     * there is one answer to "where are my settings" no matter how you arrived
     * (a redirect out, a browser return, a webhook with no session at all).
     *
     * ASK BY ORDER, not by id: the order is the thing a gateway always has.
     * Pass `methodId` as well only when a PAYMENT ROW named one, and then it
     * wins: on an order carrying several attempts the row is the only record
     * of which method actually took that money.
     *
     *     $this->getPaymentMethod($orderId, (int) $payment->id_payment_method);
     *
     * EXACT, NEVER A SIBLING: a method id asked is answered with that
     * method, while it is one of this plugin's, or with nothing; it never falls
     * back to the order's chosen method or to another of this plugin's methods,
     * which may hold other credentials. Asked by order alone, the answer is the
     * order's chosen method, and only while the ORDER'S persisted
     * `payment_method_type` names this plugin and the method row is still one
     * of its own.
     *
     * THERE IS NO FALLBACK. It returns NULL rather than reaching for a
     * plausible method, because every plausible method is a different set of
     * credentials. Foreign methods are never returned either: an order paid
     * through another gateway resolves to nothing here.
     *
     * @api READ ONLY: this plugin's method only, in any state, trashed included; never a fallback or a sibling method.
     *
     * @param int $orderId Order PK (0 when only a method id is asked).
     * @param int $methodId #__alfa_payments.id a payment row named, if any.
     *
     * @return object|null NULL when this gateway has no method to answer with.
     *
     * @since  2.0.0
     */
    protected function getPaymentMethod(int $orderId = 0, int $methodId = 0): ?object
    {
        $memo = $orderId . ':' . $methodId;

        if (array_key_exists($memo, $this->resolvedMethods)) {
            return $this->resolvedMethods[$memo];
        }

        return $this->resolvedMethods[$memo] = $this->resolvePaymentMethod($orderId, $methodId);
    }

    /**
     * The resolution {@see self::getPaymentMethod()} memoises.
     *
     * @param int $orderId Order PK (0 when only a method id is asked).
     * @param int $methodId #__alfa_payments.id a payment row named, if any.
     *
     * @since  2.0.0
     */
    private function resolvePaymentMethod(int $orderId, int $methodId): ?object
    {
        // 1. The method asked for: exactly that one, type-guarded by loadPaymentMethod, or nothing.
        if ($methodId > 0) {
            return $this->loadPaymentMethod($methodId);
        }

        if ($orderId <= 0) {
            return null;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // 2. Asked by order alone: what the customer chose, but only if the ORDER says this gateway took it. The
        //    persisted type is the authority: the header's id_payment_method can be re-pointed, and on a
        //    split-payment order it names one gateway while another holds the money.
        $query = $db->getQuery(true)
            ->select([$db->quoteName('id_payment_method'), $db->quoteName('payment_method_type')])
            ->from($db->quoteName('#__alfa_orders'))
            ->where($db->quoteName('id') . ' = ' . (int) $orderId);
        $db->setQuery($query);

        $header = $db->loadObject();

        if ($header === null || (string) ($header->payment_method_type ?? '') !== $this->_name) {
            return null;
        }

        // NO OTHER STEP. A method id that names nothing of this plugin's used to fall to the order's chosen method,
        // and an order chosen elsewhere to the newest of this plugin's own rows: both could answer a sibling method,
        // another set of credentials. Before 2.0 a further step took "the oldest published method of this type";
        // method deletion is refused while orders or ledger rows reference a method (PaymentModel::delete).
        return $this->loadPaymentMethod((int) $header->id_payment_method);
    }

    /**
     * One method row, refused unless it belongs to THIS plugin.
     *
     * The type check is the guard that keeps `getPaymentMethod()` from ever
     * handing a gateway another gateway's credentials: an order header or a
     * caller-supplied id can name any method at all. The model caches per id
     * within the request.
     *
     * @param int $methodId #__alfa_payments.id.
     *
     * @return object|null NULL when the id names nothing, or names a method of
     *                     another type.
     *
     * @since  2.0.0
     */
    private function loadPaymentMethod(int $methodId): ?object
    {
        if ($methodId <= 0) {
            return null;
        }

        $this->paymentMethodModel ??= Factory::getApplication()->bootComponent('com_alfa')
            ->getMVCFactory()
            ->createModel('Payment', 'Administrator', ['ignore_request' => true]);

        $method = $this->paymentMethodModel->getItem($methodId);

        if (!is_object($method) || empty($method->id) || (string) ($method->type ?? '') !== $this->_name) {
            return null;
        }

        return $method;
    }

    /**
     * THIS gateway's payment rows on an order, newest first.
     *
     * {@see self::getPaymentsByOrder()} answers with every row on the order (
     * a gateway attempt, a manual admin entry, a customer-credit line), which is
     * exactly the wrong basis for "which attempt is mine". Scoping to the row's
     * own `payment_method_type` is what stops one gateway's notification from
     * settling another's row, and it keeps answering after the method row is
     * deleted, which a join to `#__alfa_payments` cannot.
     *
     * Refund rows are excluded: an attempt is a 'payment'. A refund can carry
     * the same transaction_id as the payment it returns, so including them
     * makes "the newest row with this reference" ambiguous.
     *
     * @api READ ONLY: only rows this plugin wrote (any of its methods, deleted ones included), newest first; throws
     *      OrderCurrencyException for an order that cannot be read.
     *
     * @param int $orderId Order PK.
     * @param int|array $methodId Narrow to one method or a SET of them (0 = any
     *                            of this gateway's). A set matters when several
     *                            published methods share credentials.
     *
     * @return array Rows, newest first ([] when none).
     *
     * @throws OrderCurrencyException When rows are found and their order does not exist or names no currency: a payment
     *                                row whose order cannot be read is an integrity fault, never an answer of no row.
     *
     * @since  2.0.0
     */
    protected function gatewayPayments(int $orderId, int|array $methodId = 0): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery($this->gatewayPaymentQuery($db, $orderId, $methodId));

        // Money past this line. This accessor queries the table itself rather
        // than going through PaymentLedger::get(), so it has to cross the same
        // boundary explicitly: a gateway must never be handed a bare number
        // whose scale and currency it would have to guess.
        return PaymentLedger::hydrateAll($db->loadObjectList() ?: [], $orderId);
    }

    /**
     * The order a browser-return link names, IF it proves it may see it.
     *
     * A gateway that comes back through the buyer's browser gets no session:
     * the VPOS POSTs cross-site and the SameSite cookie is dropped, so the
     * return URL carries `id_order` plus a payment-scoped HMAC of the order's
     * secure_key. This verifies that pair.
     *
     * WHAT THIS IS NOT: authentication of a PAYMENT. It answers "may this
     * browser look at this order", the signed-URL question, and nothing more.
     * Whether money actually moved is the gateway's own signed payload to
     * prove, and a plugin must never let this stand in for that. One card gateway
     * ignores it entirely and resolves from its digest-verified `orderid`;
     * gateways whose return carries no order reference of their own (one
     * returns only `?t=<transactionId>`) use it to find the order and then
     * still verify the transaction with the gateway before believing anything.
     *
     * Lives here rather than in the controller because it is the PLUGIN's
     * decision whether a link hint is worth anything; the controller knows
     * how the message arrived, not what it means.
     *
     * @return Order|null The order, or NULL when the link proves nothing.
     *
     * @since  2.0.0
     */
    protected function orderFromReturnLink(): ?Order
    {
        $input = Factory::getApplication()->getInput();
        $orderId = (int) RequestScalar::int($input, 'id_order');
        $token = (string) RequestScalar::string($input, 'token');

        if ($orderId <= 0 || $token === '') {
            return null;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select($db->quoteName('secure_key'))
            ->from($db->quoteName('#__alfa_orders'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $orderId);
        $db->setQuery($query);

        $secureKey = $db->loadResult();

        if ($secureKey === null) {
            // Constant-cost decoy: a missing id must not answer faster than a
            // wrong token, or the endpoint enumerates orders.
            PaymentToken::verify($orderId, str_repeat('0', 32), $token);

            return null;
        }

        if (!PaymentToken::verify($orderId, (string) $secureKey, $token)) {
            return null;
        }

        return $this->getOrder($orderId);
    }

    /**
     * Point the visitor's session at an order whose browser return this gateway has authenticated.
     *
     * The process page and the completed page's arrival show the session's order only when this session placed it
     * or a gateway said, through this method, that it authenticated a return for it. A browser return is a request
     * anyone can send: an order id it named alone would let the process page ask that order's gateway for a payment
     * page, and the arrival open that order's page to whoever sent it.
     *
     * Call it from onPaymentResponse once the return is authenticated for this order (the provider's signature or
     * digest, or the provider's own answer naming the attempt), never before, and in whatever session the return
     * arrives: a fresh one is the normal case (a hosted payment page outlives the PHP session, a cross-site POST
     * return carries no session cookie, a buyer may finish on another device). Call it for every authentic return,
     * whatever the payment's result (paid, failed, or cancelled by the buyer): the buyer must land on their own
     * order's page, where a failed attempt is tried again from the process page. Do not write com_alfa.order_id
     * yourself: an order id without its mark is refused, and the visitor is sent to the cart.
     *
     * Send a failed or cancelled return to the process page (getProcessPageUrl(), with a flag of your own), which
     * asks your plugin again and offers the retry; the completed page's arrival clears the session's order, and the
     * order's keyed page offers no retry. A valid signed return link (orderFromReturnLink()) grants exactly the right
     * this mark grants, that this browser may look at this order, so a return it authenticates may be marked; a
     * return address that carries the link is then as strong as the order's keyed address, and travels the same way.
     *
     * Idempotent: the same order again changes nothing; another order replaces the session's order and its mark.
     * It reads no table. Outside a storefront request there is no visitor's session, and nothing is set.
     *
     * @api SESSION ONLY: points the visitor's session at the order and its mark; reads no table; idempotent.
     *
     * @param int $orderId The order the authenticated return is for.
     *
     * @throws InvalidArgumentException When the id is below 1.
     *
     * @since  2.2.1
     */
    final public function authenticatedReturnFor(int $orderId): void
    {
        if ($orderId < 1) {
            throw new InvalidArgumentException('authenticatedReturnFor() names an order: an id of 1 or more, not ' . $orderId . '.');
        }

        // NEVER THROWS OTHERWISE: the gateway's handler goes on to its redirect whatever happens here, and a session
        // that could not be written leaves the order unmarked, so the pages refuse it and say where to look.
        try {
            $app = Factory::getApplication();

            if (!$app instanceof SiteApplication) {
                AlfaLog::register();
                Log::add('authenticatedReturnFor(' . $orderId . ') was called outside a storefront request; nothing was set.', Log::WARNING, 'com_alfa.payments');

                return;
            }

            SessionOrder::authenticatedReturn($app, $orderId);
        } catch (Throwable $e) {
            AlfaLog::register();
            Log::add('authenticatedReturnFor(' . $orderId . ') could not write the session (' . $e::class . ': ' . $e->getMessage() . '); the order pages will refuse it.', Log::ERROR, 'com_alfa.payments');
        }
    }

    /**
     * This gateway's newest attempt on the order that is still pending.
     *
     * Every gateway needs this and two had written it privately, with the same
     * body and INCOMPATIBLE empty answers: one returned null, the other
     * returned `new stdClass()`. That is why the second one's callers carried shape
     * checks, and why one of them was dead: `empty()` is never true for an
     * object, so `empty($payment) || empty($payment->id)` only ever tested the
     * second half. NULL is the honest answer to "there isn't one".
     *
     * Scoped through gatewayPayments(), so it can only ever return a row of
     * THIS plugin's type: the unscoped list holds every gateway's rows, and on
     * an order that also carries another gateway's pending row the naive
     * version handed back somebody else's payment. Rows arrive newest first,
     * so the first pending one genuinely is the latest.
     *
     * @api READ ONLY: this plugin's newest pending row of the order, or null.
     *
     * @param int $orderId Order PK.
     * @param int $methodId Narrow to one method (0 = any of this gateway's).
     *
     * @return object|null The pending attempt, or NULL when there is none.
     *
     * @throws OrderCurrencyException When rows are found and their order does not exist or names no currency: a payment
     *                                row whose order cannot be read is an integrity fault, never an answer of no row.
     *
     * @since  2.0.0
     */
    protected function latestPendingPayment(int $orderId, int $methodId = 0): ?object
    {
        foreach ($this->gatewayPayments($orderId, $methodId) as $row) {
            if (($row->transaction_status ?? '') === self::STATUS_PENDING) {
                return $row;
            }
        }

        return null;
    }

    /**
     * THIS gateway's payment row carrying one of these gateway references.
     *
     * The reference names the exact gateway visit, and `transaction_id` holds
     * it (first the reference the plugin minted, then the gateway's own id
     * once settled), so a redelivered notification still finds its row. Scoped
     * like {@see self::gatewayPayments()}: a verified message proves only that
     * the GATEWAY sent it, never that it is about this profile's attempt.
     *
     * A DELETED METHOD NO LONGER MATTERS HERE. This used to need a special
     * orphan mode, because scoping ran through a join to `#__alfa_payments`
     * and a deleted method dropped the row, so an in-flight charge arrived
     * matching nothing. The row now carries its own `payment_method_type`, so
     * it stays findable, and findable only by the gateway that took it.
     *
     * A reference is matched whole and byte for byte:
     * letter case and trailing spaces are part of it.
     *
     * @api READ ONLY: this plugin's row carrying the reference, matched byte for byte, or null; throws
     *      OrderCurrencyException for an order that cannot be read.
     *
     * @param string[] $references Reference and/or gateway transaction id ('' skipped).
     * @param int $orderId Order PK, or 0 when the payload names none.
     * @param int|array $methodId Method scope, as above.
     *
     * @return object|null NULL when nothing matches; settle nothing on a guess.
     *
     * @throws OrderCurrencyException When the row is found and its order does not exist or names no currency: a payment
     *                                row whose order cannot be read is an integrity fault, never an answer of no row.
     *
     * @since  2.0.0
     */
    protected function gatewayPaymentByReference(array $references, int $orderId = 0, int|array $methodId = 0): ?object
    {
        $refs = array_values(array_filter(array_unique($references), static fn ($ref) => (string) $ref !== ''));

        if ($refs === []) {
            return null;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $this->gatewayPaymentQuery($db, $orderId, $methodId)
            ->whereIn(
                $db->quoteName('op.transaction_id_hash'),
                array_map(static fn ($ref): string => (string) PaymentLedger::referenceHash(reference: (string) $ref), $refs),
                ParameterType::STRING,
            );

        $wanted = array_map('strval', $refs);

        // The key only narrows: a reference is matched whole and byte for byte,
        // newest row first, in the query's order.
        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            if (\in_array((string) $row->transaction_id, $wanted, true)) {
                // Same boundary as gatewayPayments(): this queries the table directly,
                // so it hydrates directly.
                return PaymentLedger::hydrate(row: $row, orderId: $orderId);
            }
        }

        return null;
    }

    /**
     * The scoped query both accessors above are built on.
     *
     * Scoped on the ROW'S OWN `payment_method_type`, never on a join to the
     * method table. The row records which gateway took the money; the method
     * table only says what that method is configured as TODAY, and says
     * nothing at all once it is deleted.
     *
     * That removes the orphan problem rather than working around it. The old
     * query INNER JOINed `#__alfa_payments` and scoped on `p.type`, so a
     * deleted method dropped its rows entirely, and the opt-in `$orphans`
     * mode matched `p.id IS NULL`, i.e. EVERY gateway's orphans, because a
     * dropped join row has no type to compare. One gateway could then read
     * another's payments. The persisted type answers for its own rows whether
     * the method still exists or not, so there is nothing to opt into.
     *
     * FAILS CLOSED: a row with an empty type matches nothing. Empty means the
     * gateway is unknown, and an unknown gateway must never be silently read
     * as this one.
     *
     * @param object $db Database driver.
     * @param int $orderId Order PK (0 = any order, reference lookups only).
     * @param int|array $methodId Method scope (0 = any of this gateway's).
     *
     * @return object The query, ready for further narrowing.
     *
     * @since  2.0.0
     */
    private function gatewayPaymentQuery(object $db, int $orderId, int|array $methodId): object
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('op') . '.*')
            ->from($db->quoteName('#__alfa_order_payments', 'op'))
            ->where($db->quoteName('op.payment_method_type') . ' = ' . $db->quote($this->_name))
            ->where($db->quoteName('op.payment_type') . ' = ' . $db->quote(PaymentLedger::TYPE_PAYMENT))
            ->order($db->quoteName('op.id') . ' DESC');

        if ($orderId > 0) {
            $query->where($db->quoteName('op.id_order') . ' = ' . (int) $orderId);
        }

        $methodIds = array_values(array_filter(array_map('intval', (array) $methodId), static fn ($id) => $id > 0));

        if ($methodIds !== []) {
            $query->whereIn($db->quoteName('op.id_payment_method'), $methodIds, ParameterType::INTEGER);
        }

        return $query;
    }

    /**
     * One payment row by its id, with its payment method's record attached ({@see PaymentLedger::get()}). Not scoped
     * to this plugin: the row may be another gateway's.
     *
     * @api READ ONLY: one row by id, not scoped to this plugin (check the row's plugin before acting).
     *
     * @param int $id The payment row's id.
     *
     * @return object|null The row, its amount as Money; NULL when the id names no row.
     *
     * @throws OrderCurrencyException When the row is found and its order does not exist or names no currency: a payment
     *                                row whose order cannot be read is an integrity fault, never an answer of no row.
     *
     * @since  1.0.0
     */
    protected function getPayment(int $id): ?object
    {
        return PaymentLedger::get($id);
    }

    /**
     * EVERY payment row on an order: all gateways, all types, newest first.
     *
     * Unscoped by design: it is the right answer for "what has this order ever
     * been charged and refunded", which is what a refund total needs.
     *
     * It is the WRONG answer for "which attempt is mine". An order can hold
     * another gateway's pending row, a manual admin entry or a customer-credit
     * line, and picking one of those settles the wrong money. For that use
     * {@see self::gatewayPayments()} or {@see self::gatewayPaymentByReference()},
     * which scope to this plugin.
     *
     * @api READ ONLY: every payment row of the order, whatever the gateway: read to refuse or explain, never to pick a
     *      row to write.
     *
     * @param int $orderId Order PK.
     *
     * @return array Raw rows with plain floats, newest first.
     *
     * @since  1.0.0
     */
    protected function getPaymentsByOrder(int $orderId): array
    {
        return PaymentLedger::getByOrder($orderId);
    }

    //  THE GATEWAY'S BOOKS
    //
    //  What a gateway asks of the books beyond the builder and the intent
    //  verbs: the order's signed balance, how many of its own rows hold a
    //  provider reference and the one row that does, the refund type of one of
    //  its payments, the claim of a pending attempt for a reference, and a
    //  method's first settled payment or refund. Each answers with a value
    //  (Money, a count, an id, a type, won or lost, three facts), never a row,
    //  a query or the ledger itself.

    /**
     * The order's signed balance, as the ledger computes it everywhere: the
     * order's total less what the shop still holds (collected payments less the
     * refunds and chargebacks that took part of them back).
     *
     *   > 0  the customer still owes it: no capture or charge may exceed it
     *   = 0  settled
     *   < 0  overpaid: the shop holds more than the order is owed
     *
     * Not amountToCollect(), which is clamped at zero and answers what a new
     * charge should be; this one is never clamped.
     *
     * Usage:
     *   $due = $this->orderBalance(orderId: (int) $order->id());
     *   if ($row->amount->greaterThan($due)) { ...refuse the capture... }
     *
     * @api READ ONLY: the order's signed balance, never clamped.
     *
     * @param int $orderId The order.
     *
     * @return Money Signed, in the order's currency and scale.
     *
     * @throws InvalidArgumentException An order id under 1.
     * @throws OrderCurrencyException When the order does not exist or names no currency.
     *
     * @since  2.2.0
     */
    protected function orderBalance(int $orderId): Money
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('orderBalance(): an order id is required.');
        }

        return PaymentLedger::balance(orderId: $orderId);
    }

    /**
     * What one of this plugin's payment rows may still collect when it is settled: the order's total less what the
     * order holds against it with this row left out (its pending, authorized and completed payments of every gateway,
     * less its chargebacks and the settled refunds of money still recorded), never below zero, in the order's currency
     * and scale.
     *
     * Ask it before the provider captures or charges an authorized or pending row, and take no more than it answers:
     * the row's settle checks the same rule under the order's lock and refuses what does not fit
     * (REFUSAL_OPEN_ATTEMPT while another payment of the order is open, REFUSAL_OVER_TOTAL otherwise), after the
     * provider has already taken the money. orderBalance() is not that check: it counts completed payments only, so a
     * capture it allows can still be refused at the settle.
     *
     * Read without the lock, so never a guarantee: the settle decides. A row of another gateway, a row that is not a
     * payment, and a payment no longer pending or authorized answer zero.
     *
     * Usage:
     *   $room = $this->collectableNow(paymentId: $paymentId);
     *   if ($capture->greaterThan($room)) { ...capture no more than $room, or offer no capture... }
     *
     * @api READ ONLY: what the row may still collect; zero for another gateway's row, a row that is not a payment, or
     *      a payment no longer pending or authorized.
     *
     * @param int $paymentId One of this plugin's payment rows.
     *
     * @return Money In the order's currency and scale, never negative.
     *
     * @throws OrderCurrencyException When the row's order does not exist or names no currency.
     *
     * @since  2.3.0
     */
    final protected function collectableNow(int $paymentId): Money
    {
        return PaymentLedger::collectableNow(paymentId: $paymentId, gateway: (string) $this->_name);
    }

    /**
     * Run ledger work with a bounded wait for the order's lock: inside $work, every write of the books on an order
     * waits at most $seconds for the order another operation holds, then answers as for any lock it could not take (a
     * verdict write VERDICT_LOCK_NOT_TAKEN, nothing written). Without it, a write waits the database server's own
     * limit (50 seconds by default).
     *
     * For your provider's notification handler, which must answer within the provider's window: past the bound,
     * answer "not accepted" (a 503) and let the provider deliver again; the next delivery writes once the order is
     * free. A bound inside a bound keeps the shorter.
     *
     * Usage:
     *   $verdict = $this->withLockWait(seconds: 8, work: fn () => $this->settleAttemptVerdict($write, $orderId, $txId));
     *   if ($verdict->kind === self::VERDICT_LOCK_NOT_TAKEN) { ...answer 503: the provider delivers again... }
     *
     * @api ANSWERS: what $work returns; the books' writes inside it wait at most the bound for an order's lock.
     *
     * @param int $seconds The bound, 1 to 30 seconds.
     * @param Closure $work The work, calls of this class's writes.
     *
     * @return mixed What $work returns.
     *
     * @throws InvalidArgumentException When the bound is outside 1 to 30; $work is not run.
     *
     * @since  2.3.0
     */
    final protected function withLockWait(int $seconds, Closure $work): mixed
    {
        return PaymentLedger::withLockWait(seconds: $seconds, work: $work);
    }

    /**
     * What the books still allow to be refunded on this order, ignoring what is reserved
     * ({@see PaymentLedger::bookedRefundable()}): the money ever collected less everything already returned, by
     * every route. A pure read: nothing is written or locked.
     *
     * The ceiling money a provider has already moved is booked against. It never decides whether a new refund may
     * start (refundableNow(), and reserveRefund() under the order's lock, decide that), nor the maximum of a form
     * (refundFormMaximum()).
     *
     * Usage:
     *   $ceiling = $this->bookedRefundable(orderId: (int) $order->id());
     *
     * @api READ ONLY: what the books still allow to be refunded on the order.
     *
     * @param int $orderId The order, above zero.
     *
     * @return Money In the order's currency and scale, never negative.
     *
     * @since  2.2.1
     */
    protected function bookedRefundable(int $orderId): Money
    {
        return PaymentLedger::bookedRefundable(orderId: $orderId);
    }

    /**
     * This plugin's own payment methods: the methods of Alfa Commerce whose type is this plugin's element, each the
     * record {@see self::getPaymentMethod()} answers (the method's settings, `params` decoded to an array).
     *
     * For what concerns every method of the plugin rather than one order's (the handshake key of a notification
     * address registered once per account, for instance). Never another plugin's method. Which methods are this
     * plugin's is read on each call; each record comes from the model getPaymentMethod() reads with.
     *
     * Usage:
     *   foreach ($this->ownMethods() as $methodId => $method) {
     *       $key = (string) ($method->params['webhook_key'] ?? '');
     *   }
     *
     * @api READ ONLY: this plugin's methods only.
     *
     * @param bool $publishedOnly True: the published methods only; false: every method of this plugin, whatever its
     *                            state.
     *
     * @return array<int, object> The methods, keyed by their id in ascending order; [] when there are none.
     *
     * @since  2.2.1
     */
    protected function ownMethods(bool $publishedOnly = true): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__alfa_payments'))
            ->where($db->quoteName('type') . ' = :element')
            ->bind(':element', $this->_name)
            ->order($db->quoteName('id') . ' ASC');

        if ($publishedOnly) {
            $query->where($db->quoteName('state') . ' = 1');
        }

        $methods = [];

        foreach (array_map('intval', $db->setQuery($query)->loadColumn() ?: []) as $methodId) {
            $method = $this->loadPaymentMethod($methodId);

            if ($method !== null) {
                $methods[$methodId] = $method;
            }
        }

        return $methods;
    }

    /**
     * How many of this gateway's rows of the given types hold exactly this
     * provider reference: the replay and settlement check (a reference held by
     * more than one row proves nothing about any of them).
     *
     * Only rows this gateway recorded count (their own payment_method_type, as
     * gatewayPayments() reads them), and a reference counts only as its whole
     * value, byte for byte: another letter case, a trailing space or a longer
     * value is another reference.
     *
     * Usage:
     *   if ($this->countReferenceHolders(reference: $txId, types: [self::TYPE_PAYMENT]) !== 1) { ...prove nothing... }
     *
     * @api READ ONLY: how many of this plugin's rows of the given types hold exactly the reference.
     *
     * @param string $reference The provider reference, whole.
     * @param string[] $types The row types to count, each one of self::TYPE_PAYMENT, self::TYPE_AUTHORIZATION,
     *                        self::TYPE_REFUND, self::TYPE_REVERSAL and self::TYPE_CHARGEBACK.
     *
     * @return int The rows, 0 when none holds it.
     *
     * @throws InvalidArgumentException An empty reference, no type, or a type the books do not know.
     *
     * @since  2.2.0
     */
    protected function countReferenceHolders(string $reference, array $types): int
    {
        return \count($this->referenceRows(method: 'countReferenceHolders()', reference: $reference, types: $types));
    }

    /**
     * The one row of this gateway, of the given types, that holds exactly this
     * provider reference: the row a provider event names (a refund row the
     * provider confirms by its own reference, for example). The same rows count
     * as for countReferenceHolders(): this gateway's own, the reference whole and
     * byte for byte.
     *
     * Usage:
     *   $refundRowId = $this->findOwnRowByReference(reference: $providerRefundId, types: [self::TYPE_REFUND]);
     *
     * @param string $reference The provider reference, whole.
     * @param string[] $types The row types to look in, each one of self::TYPE_PAYMENT, self::TYPE_AUTHORIZATION,
     *                        self::TYPE_REFUND, self::TYPE_REVERSAL and self::TYPE_CHARGEBACK.
     *
     * @return int|null The row's id when exactly one of this gateway's rows of those types holds the reference; null
     *                  when none does or several do (a reference held twice names no row). Never another gateway's.
     *
     * @throws InvalidArgumentException An empty reference, no type, or a type the books do not know.
     *
     * @since  2.2.0
     */
    protected function findOwnRowByReference(string $reference, array $types): ?int
    {
        $rows = $this->referenceRows(method: 'findOwnRowByReference()', reference: $reference, types: $types);

        return \count($rows) === 1 ? $rows[0] : null;
    }

    /**
     * The first ledger row that proves one of this plugin's methods in use: its first payment that settled
     * (self::FIRST_PAID: completed, or refunded in full since: the full refund flips the payment it returns, which
     * still took the money) or its first refund row (self::FIRST_REFUND). Only rows recorded as this plugin's count:
     * a wallet spend carrying the order's method id, or another gateway's money on a method retyped since, is not
     * this method's.
     *
     * A configuration and checkup read, for display only (the method's edit page: "proven in use"): never a basis for
     * choosing a method, a row or credentials, and no transaction path may call it.
     *
     * One query, LIMIT 1: each kind of row is one branch fixing the method, the row type and the status (the
     * idx_payment_method key), joined by UNION ALL; the lowest id wins.
     *
     * Usage:
     *   $first = $this->firstMethodRow(methodId: $methodId, kind: self::FIRST_PAID);
     *
     * @param int $methodId One of this plugin's methods.
     * @param string $kind self::FIRST_PAID or self::FIRST_REFUND.
     *
     * @return array{id: int, order: int, moment: string}|null The row's id, its order and when the money moved; null
     *                                                         when there is none, or the method is not one of this
     *                                                         plugin's.
     *
     * @throws InvalidArgumentException A kind this method does not know.
     *
     * @since  2.2.0
     */
    protected function firstMethodRow(int $methodId, string $kind): ?array
    {
        if (!\in_array($kind, [self::FIRST_PAID, self::FIRST_REFUND], true)) {
            throw new InvalidArgumentException('firstMethodRow(): the kind is self::FIRST_PAID or self::FIRST_REFUND.');
        }

        if ($this->loadPaymentMethod($methodId) === null) {
            return null;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $predicates = $kind === self::FIRST_PAID
            ? [
                PaymentLedger::settledPaymentPredicate(),
                '(' . $db->quoteName('payment_type') . ' = ' . $db->quote(self::TYPE_PAYMENT)
                    . ' AND ' . $db->quoteName('transaction_status') . ' = ' . $db->quote(self::STATUS_REFUNDED) . ')',
            ]
            : [PaymentLedger::refundPredicate()];
        $element = (string) $this->_name;
        $branches = array_map(
            static fn (string $predicate): QueryInterface => $db->getQuery(true)
                ->select($db->quoteName(['id', 'id_order']))
                ->select(PaymentLedger::moneyMoment() . ' AS ' . $db->quoteName('moment'))
                ->from($db->quoteName('#__alfa_order_payments'))
                ->where($db->quoteName('id_payment_method') . ' = ' . $methodId)
                ->where($predicate)
                ->where($db->quoteName('payment_method_type') . ' = ' . $db->quote($element))
                ->order($db->quoteName('id') . ' ASC')
                ->setLimit(1),
            $predicates,
        );
        $query = array_shift($branches);

        if ($branches !== []) {
            $query = $query->toQuerySet();

            foreach ($branches as $branch) {
                $query->unionAll($branch);
            }

            $query->order($db->quoteName('id') . ' ASC');
        }

        $row = $db->setQuery($query, 0, 1)->loadObject();

        return $row === null ? null : ['id' => (int) $row->id, 'order' => (int) $row->id_order, 'moment' => (string) $row->moment];
    }

    /**
     * The ids of this gateway's rows of the given types holding exactly a reference: the key narrows, the value is
     * compared whole and byte for byte.
     *
     * @param string $method The asking method, for its refusal.
     * @param string $reference The provider reference, whole.
     * @param string[] $types The row types.
     *
     * @return int[] Ascending.
     *
     * @throws InvalidArgumentException An empty reference, no type, or a type the books do not know.
     *
     * @since  2.2.0
     */
    private function referenceRows(string $method, string $reference, array $types): array
    {
        $known = [self::TYPE_PAYMENT, self::TYPE_AUTHORIZATION, self::TYPE_REFUND, self::TYPE_REVERSAL, self::TYPE_CHARGEBACK];
        $types = array_values(array_unique(array_map('strval', $types)));

        if ($reference === '' || $types === [] || array_diff($types, $known) !== []) {
            throw new InvalidArgumentException($method . ': a reference and at least one known row type are required.');
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $gateway = (string) $this->_name;
        $hash = (string) PaymentLedger::referenceHash(reference: $reference);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'transaction_id']))
            ->from($db->quoteName('#__alfa_order_payments'))
            ->where($db->quoteName('payment_method_type') . ' = :gateway')
            ->whereIn($db->quoteName('payment_type'), $types, ParameterType::STRING)
            ->where($db->quoteName('transaction_id_hash') . ' = :hash')
            ->order($db->quoteName('id') . ' ASC')
            ->bind(':gateway', $gateway)
            ->bind(':hash', $hash);

        return array_values(array_map(
            static fn (object $row): int => (int) $row->id,
            array_filter($db->setQuery($query)->loadObjectList() ?: [], static fn (object $row): bool => (string) $row->transaction_id === $reference),
        ));
    }

    /**
     * Whether a refund of this amount on one of this gateway's payments is a
     * full or a partial refund, as the ledger books it: full only when the
     * refunds alone then cover the payment (a chargeback never makes a refund
     * full).
     *
     * Usage:
     *   $bookedAs = $this->refundTypeFor(paymentId: $paymentId, amount: $refund) === self::REFUND_FULL ? 'refund' : 'refund_partial';
     *
     * @api READ ONLY: whether a refund of the amount on one of this plugin's payments is full or partial, as the ledger
     *      books it.
     *
     * @param int $paymentId One of this gateway's payment rows.
     * @param Money|null $amount The refund, positive, in the order's currency; null means what is left to refund on it.
     *
     * @return string self::REFUND_FULL or self::REFUND_PARTIAL.
     *
     * @throws InvalidArgumentException When the row is not a payment of this gateway, or the amount is not positive.
     *
     * @since  2.2.0
     */
    protected function refundTypeFor(int $paymentId, ?Money $amount = null): string
    {
        $row = $paymentId > 0 ? PaymentLedger::get($paymentId) : null;

        if ($row === null || (string) ($row->payment_method_type ?? '') !== (string) $this->_name || (string) ($row->payment_type ?? '') !== self::TYPE_PAYMENT) {
            throw new InvalidArgumentException('refundTypeFor(): payment #' . $paymentId . ' is not a payment of this gateway.');
        }

        if ($amount !== null && !$amount->isPositive()) {
            throw new InvalidArgumentException('refundTypeFor(): a refund amount is positive.');
        }

        return PaymentLedger::refundTypeFor(paymentId: $paymentId, amount: $amount);
    }

    /**
     * Claim one of this gateway's pending attempts for a provider reference:
     * the attempt's one exclusive write.
     *
     * The ledger writes the reference only while the row is still this
     * gateway's pending payment or authorization without a reference, in one
     * compare-and-set under the order's lock, and records it on the order's
     * timeline. Two deliveries of one provider event, or two visits racing
     * for one attempt, cannot both win: the loser is told false and nothing of
     * its call is written. No money and no status move here; a won claim
     * writes its amount through paymentUpdate() after.
     *
     * Usage:
     *   if ($this->claimPendingAttempt(paymentId: (int) $payment->id, reference: $sessionId)) {
     *       ...the attempt is this visit's...
     *   } else {
     *       ...another visit or delivery owns it: open a new attempt, or agree with the winner...
     *   }
     *
     * A reference is blank when nothing is left of it after trim(), and a
     * blank one is refused. Any other is stored whole, byte for byte, its
     * spaces and letter case kept, so a gateway claims with the very value
     * it later looks the attempt up by.
     *
     * @api ANSWERS: true when this call claimed the attempt, false when it is not, or no longer, this plugin's pending
     *      attempt without a reference; throws when the order cannot be locked.
     *
     * @param int $paymentId The pending attempt.
     * @param string $reference The provider reference, not blank, stored whole.
     *
     * @return bool True when this call claimed it; false when the row is not,
     *              or no longer, this gateway's pending attempt without a
     *              reference.
     *
     * @throws InvalidArgumentException A payment id under 1 or a blank reference.
     * @throws RuntimeException When the attempt's order cannot be locked: nothing is written and the attempt stays
     *                          claimable.
     *
     * @since  2.2.0
     */
    protected function claimPendingAttempt(int $paymentId, string $reference): bool
    {
        if ($paymentId <= 0 || trim($reference) === '') {
            throw new InvalidArgumentException('claimPendingAttempt(): a payment id and a reference that is not blank are required.');
        }

        return PaymentLedger::claimReference(id: $paymentId, gateway: (string) $this->_name, reference: $reference);
    }

    /**
     * Claim one of this gateway's pending attempts for a provider reference, and answer what the write did:
     * the claim claimPendingAttempt() makes, as a verdict. Written when this call claimed it; raced when it is
     * this gateway's but no longer a pending attempt without a reference (another visit or delivery owns it: agree with
     * the winner); refused with REFUSAL_ROW_MISSING (the row or its order is gone) or REFUSAL_NOT_WRITER (another
     * gateway's row, or not a payment or authorization); lock_not_taken where claimPendingAttempt() throws today (the
     * attempt stays claimable: try again); write_failed. A plugin never reads a refusal or a lock as "make a new row".
     *
     * @api ANSWERS: a PaymentWriteVerdict for the claim; another gateway's row is refused (REFUSAL_NOT_WRITER).
     *
     * @param int $paymentId The pending attempt.
     * @param string $reference The provider reference, not blank, stored whole.
     *
     * @return PaymentWriteVerdict What the write did.
     *
     * @throws InvalidArgumentException A payment id under 1 or a blank reference.
     *
     * @since  2.2.2
     */
    protected function claimPendingAttemptVerdict(int $paymentId, string $reference): PaymentWriteVerdict
    {
        if ($paymentId <= 0 || trim($reference) === '') {
            throw new InvalidArgumentException('claimPendingAttemptVerdict(): a payment id and a reference that is not blank are required.');
        }

        return PaymentLedger::claimReferenceVerdict(id: $paymentId, gateway: (string) $this->_name, reference: $reference);
    }

    //  FRONTEND HOOKS (abstract: plugin MUST implement)

    /**
     * Order process view: redirect to gateway or show processing page.
     *
     * @api HOOK: called for the process page (redirect to the gateway or show progress).
     *
     * @param object $event OrderProcessViewEvent
     * @since  1.0.0
     */
    abstract public function onOrderProcessView($event): void;

    /**
     * Order complete view: show thank-you page.
     *
     * @api HOOK: called for the completion page.
     *
     * @param object $event OrderCompleteViewEvent
     * @since  1.0.0
     */
    abstract public function onOrderCompleteView($event): void;

    //  ADMIN ACTION HOOKS (empty: plugin overrides as needed)

    /**
     * Register available action buttons for a payment.
     *
     * Override this to add buttons using the fluent API:
     *
     *   $event->add('mark_paid', 'Mark as Paid')
     *       ->icon('checkmark')->css('btn-success')
     *       ->confirm('Mark as paid?');
     *
     * Default: no actions.
     *
     * @api HOOK: the order screen's action buttons; none by default.
     *
     * @param object $event GetPaymentActionsEvent
     * @since  1.0.0
     */
    public function onGetActions($event): void
    {
        // Empty: plugin overrides to register actions
    }

    /**
     * Handle an action button click for a payment.
     *
     * Override and use match() to route actions:
     *
     *   match ($event->getAction()) {
     *       'mark_paid'  => $this->handleMarkPaid($event),
     *       'view_logs'  => $this->handleViewLogs($event),
     *       default      => $event->setError('Unknown action'),
     *   };
     *
     * Default: sets error "Unknown action".
     *
     * @api HOOK: a click on one of this plugin's buttons; "Unknown action" by default.
     *
     * @param object $event ExecutePaymentActionEvent
     * @since  1.0.0
     */
    public function onExecuteAction($event): void
    {
        $event->setError('Unknown action: ' . $event->getAction());
    }

    /**
     * Veto hook fired before an order is deleted in the admin.
     *
     * The default allows deletion. Override to block it (e.g. refuse to delete a
     * captured payment) via $event->setResult(false), or to run gateway-side
     * cleanup before the order and its related rows are removed.
     *
     * @api HOOK: before an order is deleted in the admin; vetoes with $event->setResult(false); allows by default.
     *
     * @param object $event AdminOrderDeleteEvent
     *
     * @since  1.0.0
     */
    public function onAdminOrderDelete($event): void
    {
        $event->setResult(true);
    }
}
