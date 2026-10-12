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
 *
 *  BOOKKEEPING WRITE (row-level fact, nothing merchant-visible):
 *      $this->paymentUpdate($id)->cancelled()->bookkeeping()->save();
 *
 *  READ / DELETE (no builder needed):
 *    $payment  = $this->getPayment($paymentId);
 *    $payments = $this->getPaymentsByOrder($orderId);
 *    $this->deletePayment($paymentId, $orderId);
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

use Alfa\Component\Alfa\Administrator\Helper\PaymentToken;
use Alfa\Component\Alfa\Administrator\Plugin\Traits\HasOrderContextTrait;
use Alfa\Component\Alfa\Administrator\Plugin\Traits\HasTransportUrlsTrait;
use Alfa\Component\Alfa\Administrator\Service\Order\PaymentEnvironment;
use Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger;
use Alfa\Component\Alfa\Administrator\Service\Order\Transition;
use Alfa\Component\Alfa\Site\Service\Order\Order;
use Alfa\Component\Alfa\Site\Service\Order\Payment;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use RuntimeException;

defined('_JEXEC') or die;

abstract class PaymentsPlugin extends Plugin implements SubscriberInterface
{
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
     * @since  1.14.0
     */
    public const TYPE_REVERSAL = PaymentLedger::TYPE_REVERSAL;

    /**
     * @var string Money the customer's bank took back; see recordChargeback().
     *
     * @since  1.14.0
     */
    public const TYPE_CHARGEBACK = PaymentLedger::TYPE_CHARGEBACK;

    public const REFUND_FULL = PaymentLedger::REFUND_FULL;
    public const REFUND_PARTIAL = PaymentLedger::REFUND_PARTIAL;

    // Conditional-write verdicts (supersedeAttempt, updateIfStatus)
    public const WRITE_OK = PaymentLedger::WRITE_OK;
    public const WRITE_RACED = PaymentLedger::WRITE_RACED;
    public const WRITE_FAILED = PaymentLedger::WRITE_FAILED;

    // refundPayment() outcomes
    public const OUTCOME_OK = PaymentLedger::OUTCOME_OK;
    public const OUTCOME_ALREADY_REFUNDED = PaymentLedger::OUTCOME_ALREADY_REFUNDED;

    /**
     * @var string The refund reference is already on a refund row of this
     *             payment: the provider retrying one refund; nothing written.
     *
     * @since  1.14.0
     */
    public const OUTCOME_DUPLICATE = PaymentLedger::OUTCOME_DUPLICATE;
    public const OUTCOME_NOT_REFUNDABLE = PaymentLedger::OUTCOME_NOT_REFUNDABLE;
    public const OUTCOME_NOTHING_LEFT = PaymentLedger::OUTCOME_NOTHING_LEFT;
    public const OUTCOME_RACED = PaymentLedger::OUTCOME_RACED;
    public const OUTCOME_FAILED = PaymentLedger::OUTCOME_FAILED;
    public const OUTCOME_UNRECONCILED = PaymentLedger::OUTCOME_UNRECONCILED;

    // recordReversal() / recordChargeback() outcomes (with DUPLICATE,
    //    NOTHING_LEFT and FAILED above)

    /**
     * @var string The fact is written.
     *
     * @since  1.14.0
     */
    public const OUTCOME_RECORDED = PaymentLedger::OUTCOME_RECORDED;

    /**
     * @var string Nothing written: not a fact the ledger can take. A retry cannot help.
     *
     * @since  1.14.0
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
     * @since  1.9.1
     */
    private array $resolvedMethods = [];

    /**
     * The admin Payment model, kept for the request: it caches per id itself,
     * and a fresh instance would throw that cache away on every call.
     *
     *
     * @since  1.9.1
     */
    private ?object $paymentMethodModel = null;

    /**
     * This family answers on the payment.* controller.
     *
     * @since  1.10.0
     */
    protected function transportTask(): string
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
        return [
            'onItemView' => 'onItemView',
            'onCartView' => 'onCartView',
            'onCartViewAssets' => 'onCartViewAssets',
            'onOrderProcessView' => 'onOrderProcessView',
        ];
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
    //  These return an PaymentLedger builder instance.
    //  The builder validates, auto-fills, and delegates to
    //  static CRUD methods internally.
    //
    //  For direct CRUD access (batch ops, admin form saves),
    //  use PaymentLedger::create/update/delete() directly.

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
     * @param object $order Full order object (->id, ->items, ->id_payment_method)
     *
     * @return PaymentLedger Builder: chain setters, finish with ->save()
     *
     * @since  1.0.0
     */
    protected function payment(object $order): PaymentLedger
    {
        return PaymentLedger::for($order);
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
     * @param object $order The hydrated order.
     *
     * @return float Amount still to collect, never negative.
     *
     * @since  1.9.0
     */
    protected function amountToCollect(object $order): float
    {
        return PaymentLedger::amountToCollect($order);
    }

    /**
     * How much may still be refunded on this order. PREFLIGHT this BEFORE
     * calling your gateway's refund API, and gate your Refund action on it.
     *
     * The ledger's over-refund guard runs when the row is WRITTEN, which is
     * after your gateway has already moved the money: without a preflight a
     * rejected row leaves a refund that exists at the bank and nowhere in
     * the books. Store credit already returned for this order's credit notes
     * is subtracted here, so a refunded-to-wallet order correctly reports
     * less (or nothing) as refundable by card.
     *
     * Usage:
     *   if ($amount - $this->refundableNow($order) > 0.01) { ...refuse... }
     *
     * @param object $order The hydrated order.
     *
     * @return float Refundable amount, never negative.
     *
     * @since  1.9.0
     */
    protected function refundableNow(object $order): Money
    {
        return PaymentLedger::refundableNow(Order::of($order)->id());
    }

    /**
     * What is left to give back on ONE payment row.
     *
     * The order-level {@see self::refundableNow()} answers "how much may this
     * ORDER still return", which is the right pre-flight before calling a
     * gateway. This answers "how much is left on THIS row": the figure to
     * actually send, because a row that already carries a partial refund must
     * not be refunded gross a second time.
     *
     * Send it to the gateway in minor units through the order's own currency,
     * never `* 100`:
     *
     *   $left  = $this->refundableRemainder((int) $payment->id);
     *   $minor = Money::of($left, $order->currency)->getMinorUnits();
     *
     * Alfa ships currencies with 0, 1, 3 and 4 decimals (JPY, MOP, OMR, ...), so
     * a hard-coded hundred is wrong on four of them.
     *
     * @param int $paymentId Original payment row PK.
     *
     * @return float Remaining refundable amount, 0.0 when nothing is left.
     *
     * @since  1.9.1
     */
    protected function refundableRemainder(int $paymentId): Money
    {
        return PaymentLedger::refundableRemainder($paymentId);
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
        return PaymentLedger::load($paymentId);
    }

    //  Intent verbs
    //
    //  One call per thing that happened. Prefer these over
    //  hand-driving the state machine with status flips; the
    //  sequence and its compensation live in the ledger, so every
    //  gateway gets the same one.

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
     * @param PaymentLedger $write Update builder with ->expectStatus() armed.
     * @param int $orderId Order the attempt belongs to.
     * @param string $reference Gateway transaction id or order reference,
     *                          for the CRITICAL line; this is what an
     *                          operator reconciles against.
     *
     * @return int self::WRITE_OK, self::WRITE_RACED or self::WRITE_FAILED.
     *
     * @since  1.9.1
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
     * and reload rather than hand the buyer to the gateway.
     *
     * Usage:
     *   if ($this->supersedeAttempt((int) $payment->id, 'New gateway visit') !== self::WRITE_OK) {
     *       throw new RuntimeException(Text::_('..._ERROR_NO_PENDING_PAYMENT'));
     *   }
     *
     * @param int $paymentId Pending attempt row PK.
     * @param string $reason Why it was abandoned, stamped into the row's note.
     *
     * @return int self::WRITE_OK, self::WRITE_RACED or self::WRITE_FAILED.
     *
     * @since  1.9.0
     */
    protected function supersedeAttempt(int $paymentId, string $reason = ''): int
    {
        return PaymentLedger::supersedeAttempt($paymentId, $reason);
    }

    /**
     * Refund a payment: flip, audit row and compensation as ONE idempotent
     * operation, so your plugin never re-derives the dance.
     *
     * The ledger decides full vs partial (covering the refundable remainder
     * is full: the original flips to 'refunded' and status_on_refunded fires;
     * less is partial and the original correctly stays 'completed'), writes
     * the linked audit row with the right refund_type and the ORIGINAL's
     * payment method, and unwinds its own flip if that row is refused.
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
     * CALL ORDER: refund at the gateway FIRST, then record it here, and
     * pass 'gateway_moved' => true when you do. The money has already left,
     * so the ledger must NOT roll the original back if it refuses the audit
     * row; it logs CRITICAL and answers OUTCOME_UNRECONCILED instead.
     * Preflight with refundableNow() BEFORE the gateway call.
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
     * @param int $paymentId Original payment row PK.
     * @param ?Money $amount Amount to return; null = the refundable
     *                       remainder. A zero or negative Money is REFUSED
     *                       (OUTCOME_NOT_REFUNDABLE); only absence means
     *                       "everything left". Must be in the order's
     *                       currency.
     * @param array $options 'reason', 'transaction_id', 'gateway_response',
     *                       'document', 'gateway_moved'; see
     *                       PaymentLedger::refundPayment().
     *
     * @return array ['outcome' => self::OUTCOME_*, 'refund_id' => int, 'reason' => string]
     *
     * @since  1.9.0
     */
    protected function refundPayment(int $paymentId, ?Money $amount = null, array $options = []): array
    {
        return PaymentLedger::refundPayment($paymentId, $amount, $options);
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
     * OUTCOME_FAILED so the provider redelivers. Never throws.
     *
     * @param int $rowId The refund or chargeback row.
     * @param Money|null $amount What did not stand; null means all not yet reversed.
     * @param array $options 'transaction_id' (required), 'reason',
     *                       'gateway_response', 'processed_at' (UTC Y-m-d H:i:s).
     *
     * @return array ['outcome', 'row_id', 'reason', 'amount', 'reopened']
     *
     * @since  1.14.0
     */
    protected function recordReversal(int $rowId, ?Money $amount = null, array $options = []): array
    {
        return PaymentLedger::recordReversal(rowId: $rowId, amount: $amount, options: $options);
    }

    /**
     * Record money the customer's bank took back from a completed payment of this gateway.
     *
     * Never capped, never flips the payment, never moves the order. Outcomes
     * and options as recordReversal().
     *
     * @param int $paymentId The completed payment.
     * @param Money $amount What the bank took back, in the order's currency.
     * @param array $options As recordReversal().
     *
     * @return array ['outcome', 'row_id', 'reason', 'amount', 'reopened']
     *
     * @since  1.14.0
     */
    protected function recordChargeback(int $paymentId, Money $amount, array $options = []): array
    {
        return PaymentLedger::recordChargeback(paymentId: $paymentId, amount: $amount, options: $options);
    }

    /**
     * The refund or chargeback row of a payment carrying this exact provider reference.
     *
     * A failed or cancelled row reserves nothing and is never returned. The
     * reference is compared whole and byte for byte.
     *
     * @param int $paymentId The payment.
     * @param string $type self::TYPE_REFUND or self::TYPE_CHARGEBACK.
     * @param string $reference The complete provider reference.
     *
     * @return object|null id, amount, transaction_status, transaction_id; or null.
     *
     * @since  1.14.0
     */
    protected function findLinkedRow(int $paymentId, string $type, string $reference): ?object
    {
        return PaymentLedger::findLinkedRow(paymentId: $paymentId, type: $type, reference: $reference);
    }

    /**
     * RECURRING CONTRACT: whether this gateway can charge a stored
     * credential off-session (merchant-initiated transactions).
     *
     * Gateways that return true implement two boot-and-call handlers:
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
     *     re-charge, ...); the caller writes the payment row and the method's
     *     status_on_<phase> automation map drives the order.
     *
     *     setSuccess($transactionId)
     *       The provider confirmed THIS charge as taken, for exactly the
     *       amount and currency asked. Pass the provider's id of the new
     *       charge, exactly as it gave it. The caller books it as completed;
     *       an id it cannot book exactly as sent (see setPending) is not
     *       booked, and the caller asks an operator to review at once.
     *
     *     setFailure($error)
     *       No money moved, and you know it: the provider answered with a
     *       definite refusal (declined, expired or revoked token, insufficient
     *       funds), or you refused to send the request (bad reference, wrong
     *       currency or scale, missing credentials). The caller books nothing
     *       and bills the cycle again: dunning retries it, and the renewal
     *       order stays owed. Once you have answered success or pending, a
     *       later setFailure() is ignored.
     *
     *     setPending($reference, $reason)
     *       The provider may have taken money; do not charge again; the
     *       webhook or Reconcile settles it. Use it whenever the request may
     *       have reached the provider and you hold no definite answer: a
     *       timeout or dropped connection after sending, HTTP 0 or 5xx, an
     *       unreadable body, a status the provider itself calls pending or
     *       processing, or a "success" whose amount, currency or identity does
     *       not match what was asked. $reference is what your webhook and
     *       Reconcile will find the row by through gatewayPaymentByReference():
     *       the provider's id of this charge when it gave one, else the
     *       merchant reference you sent with the request, exactly as sent,
     *       never trimmed or cut. The caller books a PENDING payment row of
     *       your gateway with that transaction_id and the charged amount, and
     *       never charges that cycle again. A reference that is empty,
     *       carries a control character, is not UTF-8, or is longer than the
     *       ledger's transaction_id column holds cannot be booked exactly as
     *       sent, so the caller books no row and asks an operator to review
     *       at once.
     *       Settle the row with the same finaliser as any other pending
     *       attempt: settleAttempt() with ->expectStatus(self::STATUS_PENDING) and
     *       ->completed() when the provider confirms, ->failed() when it
     *       definitively refuses.
     *
     *     Never throw and never return without answering: the caller treats
     *     both as an unknown outcome with no row to settle, books nothing,
     *     never charges again and asks an operator to review.
     *
     * Gateways without token support keep the default false; such methods
     * fall back to manual renewals (pay-link email) automatically.
     *
     * This states only what the gateway can do. Whether a method actually
     * renews on its own is renewalsAllowed(): this AND the method's own
     * setting (methodAllowsRenewals()).
     *
     * @since  1.3.0
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
     * @since  1.10.0
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
     * @since  1.14.0
     */
    public const PAYLOAD_ENVIRONMENT = 'environment';

    /**
     * Whether a renewal of this method may run without the customer: the
     * gateway can do it at all (supportsRecurring()) and the method's saved
     * settings allow it (methodAllowsRenewals()).
     *
     * The one answer the Subscriptions engine asks, both before it asks for a
     * mandate and before it charges a stored one. A method of another gateway,
     * or none, is false.
     *
     * @param int $methodId #__alfa_payments.id.
     *
     *
     * @since  1.14.0
     */
    final public function renewalsAllowed(int $methodId): bool
    {
        if (!$this->supportsRecurring()) {
            return false;
        }

        $method = $this->loadPaymentMethod($methodId);

        if ($method === null) {
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
     * @param array $params The method's saved params.
     *
     *
     * @since  1.14.0
     */
    protected function methodAllowsRenewals(array $params): bool
    {
        return false;
    }

    /**
     * The credential set an existing payment was made in, as its attempt
     * recorded it under PAYLOAD_ENVIRONMENT. Never guessed and never read from
     * the switch: a payment that recorded none answers null, and the gateway
     * then offers no follow-up action for it.
     *
     * @param object $payment The #__alfa_order_payments row, or its Payment reader.
     *
     * @return string|null PaymentEnvironment::SANDBOX, ::LIVE, or null when none was recorded.
     *
     * @throws InvalidArgumentException When the recorded value is not sandbox or live.
     *
     * @since  1.14.0
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
     * @since  1.3.0
     */
    protected function orderTransition(int $orderId): Transition
    {
        return Transition::for(orderId: $orderId)->byPlugin($this->_name);
    }

    //  PAYMENT READ / DELETE (no builder needed)

    /**
     * Delete an order payment record.
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
        return PaymentLedger::delete($id, $orderId);
    }

    /**
     * Load a single payment with method params attached.
     *
     *
     * @return object|null Payment record with ->params, or null
     *
     * @since  1.0.0
     */
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
     * Resolution, first hit wins, and every step is evidence rather than
     * inference: the id a payment row named -> the order's chosen method, but
     * only while the ORDER'S persisted `payment_method_type` says this gateway
     * -> the newest of this gateway's own attempts, scoped by each row's
     * persisted type.
     *
     * THERE IS NO FALLBACK. It returns NULL rather than reaching for a
     * plausible method, because every plausible method is a different set of
     * credentials. Foreign methods are never returned either: an order paid
     * through another gateway resolves to nothing here.
     *
     * @param int $orderId Order PK (0 = fallback only).
     * @param int $methodId #__alfa_payments.id a payment row named, if any.
     *
     * @return object|null NULL when this gateway has no method to answer with.
     *
     * @since  1.9.1
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
     * @param int $orderId Order PK (0 = fallback only).
     * @param int $methodId #__alfa_payments.id a payment row named, if any.
     *
     * @since  1.9.1
     */
    private function resolvePaymentMethod(int $orderId, int $methodId): ?object
    {
        // 1. The row the caller already holds. Type-guarded by loadPaymentMethod.
        if ($methodId > 0 && ($method = $this->loadPaymentMethod($methodId)) !== null) {
            return $method;
        }

        if ($orderId <= 0) {
            return null;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // 2. What the customer chose, but only if the ORDER says this gateway
        //    took it. The persisted type is the authority: the header's
        //    id_payment_method can be re-pointed, and on a split-payment order
        //    it names one gateway while another holds the money.
        $query = $db->getQuery(true)
            ->select([$db->quoteName('id_payment_method'), $db->quoteName('payment_method_type')])
            ->from($db->quoteName('#__alfa_orders'))
            ->where($db->quoteName('id') . ' = ' . (int) $orderId);
        $db->setQuery($query);

        $header = $db->loadObject();

        if ($header !== null && (string) ($header->payment_method_type ?? '') === $this->_name) {
            $chosenId = (int) $header->id_payment_method;

            if ($chosenId > 0 && $chosenId !== $methodId && ($method = $this->loadPaymentMethod($chosenId)) !== null) {
                return $method;
            }
        }

        // 3. What actually took money here, newest first, already scoped to
        //    this gateway by the row's own persisted type.
        foreach ($this->gatewayPayments($orderId) as $row) {
            $rowMethodId = (int) ($row->id_payment_method ?? 0);

            if ($rowMethodId > 0 && ($method = $this->loadPaymentMethod($rowMethodId)) !== null) {
                return $method;
            }
        }

        // NO FOURTH STEP. This used to fall back to "the oldest published method
        // of this type", so a deleted-and-recreated method let an in-flight
        // settlement land on a successor. That was a guess wearing a rationale:
        // the successor may carry different credentials, a different acquirer
        // MID, or a different account entirely, and the guess was invisible.
        //
        // Deletion is now REFUSED while orders or ledger rows reference a
        // method (PaymentModel::delete), so the case the fallback existed for
        // cannot arise, and where it somehow does, answering NULL makes the
        // gateway fail closed instead of settling against someone else's
        // credentials.
        return null;
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
     * @since  1.9.1
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
     * @param int $orderId Order PK.
     * @param int|array $methodId Narrow to one method or a SET of them (0 = any
     *                            of this gateway's). A set matters when several
     *                            published methods share credentials.
     *
     * @return array Rows, newest first ([] when none).
     *
     * @since  1.9.0
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
     * THE ROW READS ITS OWN PAYLOAD. `$this->pluginData($payment)` used to live
     * here and was a second way to ask what the gateway itself now answers:
     *
     *     $payment = $event->payments()[0];
     *     $payment->pluginData()->getString(key: 'wallet', default: '');
     *     $payment->pluginData()->has('wallet');
     *
     * WRITE IT BACK THROUGH THE LEDGER, WHICH MERGES:
     *
     *     $this->paymentUpdate($id)->pluginData(['wallet' => $v])->save();
     *     $this->paymentUpdate($id)->removePluginData(['wallet'])->save();
     *
     * Pass PHP arrays, never JSON: encoding belongs to the ledger. Only the
     * keys you name change, and the merge happens with the row held, so a
     * webhook writing one key and an admin action writing another cannot erase
     * each other. Storing null KEEPS the key (has() stays true, a typed getter
     * answers with its default); removing takes it away. The merge is one level
     * deep: a key you write replaces what was under it, wholesale.
     *
     * The core never looks inside any of it.
     */

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
     *
     * @return object|null The pending attempt, or NULL when there is none.
     *
     * @since  1.9.1
     */
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
     * @since  1.9.1
     */
    protected function orderFromReturnLink(): ?Order
    {
        $input = Factory::getApplication()->getInput();
        $orderId = (int) $input->getInt('id_order', 0);
        $token = (string) $input->getString('token', '');

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
     * @param string[] $references Reference and/or gateway transaction id ('' skipped).
     * @param int $orderId Order PK, or 0 when the payload names none.
     * @param int|array $methodId Method scope, as above.
     *
     * @return object|null NULL when nothing matches; settle nothing on a guess.
     *
     * @since  1.9.0
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
     * @since  1.9.0
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

    //  FRONTEND HOOKS (abstract: plugin MUST implement)

    /**
     * Order process view: redirect to gateway or show processing page.
     *
     * @param object $event OrderProcessViewEvent
     * @since  1.0.0
     */
    abstract public function onOrderProcessView($event): void;

    /**
     * Order complete view: show thank-you page.
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
     * @param object $event AdminOrderDeleteEvent
     *
     * @since  1.0.0
     */
    public function onAdminOrderDelete($event): void
    {
        $event->setResult(true);
    }
}
