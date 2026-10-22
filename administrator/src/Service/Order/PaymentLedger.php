<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Order Payment Helper: Unified CRUD Engine + Fluent Builder
 *
 *  TWO APIs, ONE CLASS: accessible from anywhere
 *
 *  HIGH-LEVEL - Fluent Builder (recommended for 95% of use cases):
 *
 *    CREATE:  PaymentLedger::for($order)->pending()->save();
 *    UPDATE:  PaymentLedger::load($id)->completed()->save();
 *    PLUGIN:  $this->payment($order)->pending()->save();
 *    PLUGIN:  $this->paymentUpdate($id)->completed()->transactionId('ch_3M..')->save();
 *    RACE:    $this->paymentUpdate($id)->expectStatus('pending')->completed()->save();
 *    BOOKS:   $this->paymentUpdate($id)->cancelled()->bookkeeping()->save();
 *    ADMIN:   PaymentLedger::for($order)->amount(50)->pending()->save();
 *    STAFF:   PaymentLedger::update($id, $data, staff: true);   // a staff entry point: held to staffWriteRefusal()
 *    CLI:     PaymentLedger::load($id)->completed()->note('Webhook')->save();
 *
 *  LOW-LEVEL - Static CRUD (escape hatch for exotic columns, batch ops):
 *
 *    PaymentLedger::create(['id_order' => 1, 'amount' => 100, ...]);
 *    PaymentLedger::update(5, ['transaction_status' => 'completed', ...]);
 *    PaymentLedger::updateIfStatus(5, [...], 'pending');   // settle exactly once
 *    PaymentLedger::delete(5, 1);
 *    PaymentLedger::get(5);
 *    PaymentLedger::getByOrder(1);
 *
 *  INTENT VERBS - one call per THING THAT HAPPENED, so no plugin has to
 *  drive the state machine by hand (see the INTENT VERBS section):
 *
 *    PaymentLedger::supersedeAttempt(5, 'New gateway visit');  // bookkeeping
 *    PaymentLedger::refundPayment(5);                          // full, idempotent
 *    PaymentLedger::refundPayment(5, $money);                  // partial
 *    PaymentLedger::refundPayment(5, Money::zero($c));         // REFUSED: zero is not "everything"
 *    PaymentLedger::recordReversal(9, null, ['transaction_id' => 'rf_1']);  // a refund or chargeback that did not stand
 *    PaymentLedger::recordChargeback(5, $money, ['transaction_id' => 'cb_1']); // the bank took money back
 *    PaymentLedger::recordReportedRefund(5, 'rpt-1');          // a refund the provider made outside Alfa, as reported
 *    PaymentLedger::recordReportedRefundTyped(5, 'rpt-2', $money, 'why'); // no exact amount: typed by an authorised user
 *    PaymentLedger::reconcileReportedRefund(5, 'rpt-3', 12, 'why');      // the report is a refund already in the books
 *    PaymentLedger::dismissReportedRefund(5, 'rpt-4', 'why');            // a duplicate or invalid report
 *    PLUGIN:  $this->supersedeAttempt($id, '...');
 *    PLUGIN:  $this->refundPayment($id, null, ['gateway_moved' => true]);
 *    PLUGIN:  $this->recordReversal(rowId: $id, amount: null, options: [...]);
 *
 *  MONEY GUARDS (create / update / updateIfStatus):
 *
 *    Payment: capped by the order grand total. A REFUNDED original
 *      reserves nothing: its money came in and went back, so a
 *      replacement payment must fit.
 *
 *    Refund: EVERY refund is capped by the order's refundable pool
 *      (payments completed OR refunded: a flipped original stays in the
 *      pool, that is exactly the money being returned) AND, when linked,
 *      by its original's gross as the tighter extra constraint.
 *
 *    update() re-checks whenever amount, transaction_status,
 *    payment_type, id_refunded_payment or id_order changes; the
 *    concurrent-settle race (webhook vs buyer return) is closed by
 *    updateIfStatus() / ->expectStatus(), which write through a single
 *    conditional UPDATE so a payment is settled exactly once.
 *
 *    Refund document: a refund that names a credit document is written
 *      only against an issued document of its order, within the note's
 *      own ceiling (what the customer's credit account and every refund
 *      of the note not failed or cancelled have taken from it): on
 *      create, on an update that changes its document, amount, order or
 *      type, and on a settle. Staff settle through update() (the order
 *      screen, the REST settle verb) and are refused. A settle through
 *      updateIfStatus() is a payment plugin confirming money that has
 *      already moved: it is never refused for its document; it is
 *      written, logged CRITICAL and marked for staff.
 *
 *  AMOUNT RESOLUTION (create mode, when ->amount() NOT called):
 *
 *    Primary: $order->total_paid_tax_incl  (always correct)
 *      -> During checkout: overridden by OrderPlacement from the placement cart
 *      -> During admin/API: computed by OrderModel::getItem() from DB
 *      -> Money object; getAmount() extracts the float
 *
 *    Fallback: OrderTotalHelper::getExactBreakdown($orderId, $order)
 *      -> Recomputes from DB tables if total_paid_tax_incl is missing
 *
 *    Priority 3: ->amount($x)  (manual override, always wins when called)
 *    Priority 4: ->itemsOnly()  (items total, no shipping/discounts)
 *
 *  save() flow:
 *
 *  CREATE mode (::for($order)):
 *    1. Developer chains: ->pending()->amount(100)->transactionId('ch_3M..')
 *    2. ->save() validates transaction_status is set (throws if not)
 *    3. ->save() resolves amount if not manually set (priority chain above)
 *    4. ->save() delegates to static::create($data)
 *    5. create() auto-fills: added, id_employee, id_currency, method name
 *    6. create() filters unknown columns, inserts row, logs activity
 *    7. Returns new payment row ID
 *
 *  UPDATE mode (::load($id)):
 *    1. load() reads existing payment from DB (validates existence, gets id_order)
 *    2. Developer chains: ->completed()->note('Admin verified')
 *    3. ->save() collects ONLY explicitly-set fields (no unintended overwrites)
 *    4. ->save() delegates to static::update($id, $data)
 *    5. update() diffs old vs new, updates row, logs only actual changes
 *    6. Returns payment ID on success, false on failure
 *    7. No-op if nothing was changed (returns ID without DB write)
 *
 * Path: administrator/components/com_alfa/src/Service/Order/PaymentLedger.php
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MysqlError;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderTotalHelper;
use Alfa\Component\Alfa\Administrator\Helper\PostCommit;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Administrator\Plugin\PaymentsPlugin;
use Alfa\Component\Alfa\Administrator\Service\Document\AutoIssuePolicy;
use Alfa\Component\Alfa\Administrator\Service\Download\Entitlements;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Site\Service\Data\ValueException;
use Alfa\Component\Alfa\Site\Service\Order\Order;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal;
use Closure;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\ParameterType;
use RuntimeException;
use Throwable;

class PaymentLedger
{
    use LedgerSupport;

    //  CONSTANTS: Transaction Status
    //
    //  The ONLY valid values for #__alfa_order_payments.transaction_status.
    //  Use builder shortcut methods for IDE autocomplete and typo safety.

    /** @var string Awaiting confirmation (offline payment, bank transfer) */
    public const STATUS_PENDING = 'pending';

    /** @var string Funds reserved by gateway, not yet captured */
    public const STATUS_AUTHORIZED = 'authorized';

    /** @var string Funds captured: money received */
    public const STATUS_COMPLETED = 'completed';

    /** @var string Gateway declined, timeout, or insufficient funds */
    public const STATUS_FAILED = 'failed';

    /** @var string Cancelled before completion (by customer or admin) */
    public const STATUS_CANCELLED = 'cancelled';

    /** @var string Funds returned to customer */
    public const STATUS_REFUNDED = 'refunded';

    /**
     * Statuses whose rows reserve nothing against the order total.
     *
     * ONE list, used by both the overpayment sum and the update-path guard: a
     * row heading INTO one of these must not be measured on the way, and a row
     * already in one must not be counted. When the two drifted apart, a full
     * refund was refused because the completed->refunded flip was weighed as
     * live money against a total that had already shrunk.
     *
     * @var string[]
     *
     * @since  2.0.0
     */
    public const UNRECORDED_PAYMENT_STATUSES = [
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
    ];

    /**
     * The statuses of a settled refund: its money has gone back, and the books count it
     * ({@see self::sumRefundsAgainst()}).
     *
     * A refund's status is a lifecycle: pending or authorized, then settled, then failed or
     * cancelled only when it must be invalidated. A settled refund never goes back to pending
     * or authorized ({@see self::statusesFrom()}).
     *
     * @var string[]
     *
     * @since  2.1.0
     */
    public const REFUND_SETTLED_STATUSES = [
        self::STATUS_REFUNDED,
        self::STATUS_COMPLETED,
    ];

    /**
     * The statuses of a refund that is in the books but not settled: it RESERVES its
     * amount. It is kept out of what the order may still refund and out of what
     * its payment has left, once ({@see self::refundableNow()},
     * {@see self::refundableRemainder()}), but it is not refunded money: it never makes
     * its payment refunded, and only its settle books it. A refund reservation made before
     * a provider is asked ({@see self::reserveRefund()}) is such a row.
     *
     * @var string[]
     *
     * @since  2.1.0
     */
    public const REFUND_RESERVING_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_AUTHORIZED,
    ];

    //  CONSTANTS: Payment Type
    //
    //  Controls financial calculations:
    //    total_paid_real = SUM(amount)
    //      WHERE payment_type = 'payment' AND transaction_status = 'completed'

    /** @var string Normal payment (counts toward total_paid_real when completed) */
    public const TYPE_PAYMENT = 'payment';

    /** @var string Refund audit trail (never counted in total_paid_real) */
    public const TYPE_REFUND = 'refund';

    /** @var string Authorization hold (funds reserved, capture later) */
    public const TYPE_AUTHORIZATION = 'authorization';

    /**
     * @var string A provider fact that undoes all or part of a refund row or a
     *             chargeback row. Written only by {@see self::recordReversal()}.
     *
     * @since  2.0.0
     */
    public const TYPE_REVERSAL = 'reversal';

    /**
     * @var string Money the customer's bank took back from a collected gateway
     *             payment. Written only by {@see self::recordChargeback()}.
     *
     * @since  2.0.0
     */
    public const TYPE_CHARGEBACK = 'chargeback';

    /**
     * @var string[] The types only the ledger's own verbs write. A row of one of
     *               them is history: it is never edited or deleted.
     *
     * @since  2.0.0
     */
    public const FACT_TYPES = [self::TYPE_REVERSAL, self::TYPE_CHARGEBACK];

    /**
     * @var string The payment_method_type of money that moved through the
     *             customer's wallet: a wallet spend, and a refund of one.
     *
     * @since  2.0.0
     */
    public const METHOD_TYPE_CREDIT = 'credit';

    /**
     * @var string[] payment_method_type values that name NO gateway.
     *
     * The type column normally holds a plugin element, resolved from
     * id_payment_method; a caller that can name the gateway can name the
     * wrong one. Two kinds of row are classified from what the LEDGER knows
     * instead, and neither the method id nor the caller decides them:
     *
     *   a wallet spend   a payment whose id_credit_transaction names an
     *                    authentic wallet debit of its order is credit. The
     *                    link is the only thing that makes money wallet
     *                    money; a caller's own non-gateway value is ignored.
     *   a linked refund  inherits the classification of the payment it
     *                    refunds, so a refund to the wallet is credit too.
     *
     * The method id on a spend is the ORDER'S method (so status automation
     * runs) and would otherwise make the row claim a gateway that never
     * touched it. createLocked() classifies at write time; update() and
     * updateIfStatus() re-derive it through classifyOwned(). A caller never
     * names a class: a row with no method id, no spend link and no linked
     * original stays unclassified ('').
     */
    public const NON_GATEWAY_TYPES = [self::METHOD_TYPE_CREDIT];

    /**
     * @var string The element of the bundled Standard payment method (the manual method): a payment plugin with no
     *             provider channel. A refund of its payment is not a gateway refund ({@see self::gatewayPaymentOf()}).
     *
     * @since  2.1.0
     */
    private const STANDARD_METHOD = 'standard';

    /** @var string Full refund: entire original amount returned */
    public const REFUND_FULL = 'full';

    /** @var string Partial refund: only some of the original amount returned */
    public const REFUND_PARTIAL = 'partial';

    //  PREDICATES: what a payment row MEANS, in SQL
    //
    //  Before these existed, eleven queries each spelled out their own
    //  status/type pair, and one of them spelled it wrong: the orders
    //  "Refunded" filter and every dashboard refund figure asked for
    //  completed + refund, a pair this ledger has never written. It matched
    //  nothing for as long as it shipped, silently, because "no refunds" and
    //  "no rows" look identical on a screen.
    //
    //  THESE ARE NOT INTERCHANGEABLE. Four of them differ by rows that a
    //  casual reader would call the same, and merging any two changes money:
    //
    //    settledPayment  type = payment,  status = completed
    //    livePayment     type = payment,  status NOT IN (failed, cancelled,
    //                    refunded), ALSO pending and authorized
    //    failedAttempt   status IN (failed, cancelled), type <> refund -
    //                    <> refund, not = payment, so authorizations count
    //    refund          type = refund,   status = refunded
    //
    //  settledPayment answers "what does the shop hold". livePayment answers
    //  "what is standing against the order total", which must include a
    //  pending bank transfer or credit would cover money already on its way.

    /**
     * Money the shop has actually received.
     *
     * The narrowest reading: a payment row that completed. Not an authorization,
     * not a pending transfer, not a refund. This is the basis for "is it paid",
     * takings, and every figure a merchant would recognise as revenue.
     *
     * @param string $alias Table alias, '' when the query names no alias.
     *
     * @return string A parenthesised SQL predicate.
     *
     * @since  2.0.0
     */
    public static function settledPaymentPredicate(string $alias = ''): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $c = static fn (string $col): string => $db->quoteName(($alias === '' ? '' : $alias . '.') . $col);

        return '(' . $c('payment_type') . ' = ' . $db->quote(self::TYPE_PAYMENT)
            . ' AND ' . $c('transaction_status') . ' = ' . $db->quote(self::STATUS_COMPLETED) . ')';
    }

    /**
     * Money still standing against what the order is owed.
     *
     * Wider than {@see self::settledPaymentPredicate()} on purpose: a full-amount
     * PENDING bank transfer means nothing is outstanding, and treating it as
     * absent would let store credit cover money already on its way and overpay
     * the order the moment it clears. The excluded set is
     * {@see self::UNRECORDED_PAYMENT_STATUSES}, so this predicate and the
     * ledger's own overpayment guard cannot drift apart.
     *
     * @param string $alias Table alias, '' when the query names no alias.
     *
     * @return string A parenthesised SQL predicate.
     *
     * @since  2.0.0
     */
    public static function livePaymentPredicate(string $alias = ''): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $c = static fn (string $col): string => $db->quoteName(($alias === '' ? '' : $alias . '.') . $col);

        $dead = implode(',', array_map(static fn (string $v): string => $db->quote($v), self::UNRECORDED_PAYMENT_STATUSES));

        return '(' . $c('payment_type') . ' = ' . $db->quote(self::TYPE_PAYMENT)
            . ' AND ' . $c('transaction_status') . ' NOT IN (' . $dead . '))';
    }

    /**
     * An attempt to take money that did not succeed.
     *
     * `<> refund` rather than `= payment` deliberately: a failed AUTHORIZATION is
     * a failed attempt on the shopper's card and belongs in the gateway-trouble
     * figures, while a cancelled refund is not a payment failure at all.
     *
     * @param string $alias Table alias, '' when the query names no alias.
     *
     * @return string A parenthesised SQL predicate.
     *
     * @since  2.0.0
     */
    public static function failedAttemptPredicate(string $alias = ''): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $c = static fn (string $col): string => $db->quoteName(($alias === '' ? '' : $alias . '.') . $col);

        return '(' . $c('transaction_status') . ' IN (' . $db->quote(self::STATUS_FAILED) . ',' . $db->quote(self::STATUS_CANCELLED) . ')'
            . ' AND ' . $c('payment_type') . ' <> ' . $db->quote(self::TYPE_REFUND) . ')';
    }

    /**
     * A refund that actually happened.
     *
     * STATUS_REFUNDED, not STATUS_COMPLETED: a refund row is written by
     * {@see self::refunded()} and has never carried `completed` in this ledger's
     * history. Getting this pair wrong is the defect these predicates exist to
     * make unrepresentable.
     *
     * @param string $alias Table alias, '' when the query names no alias.
     *
     * @return string A parenthesised SQL predicate.
     *
     * @since  2.0.0
     */
    public static function refundPredicate(string $alias = ''): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $c = static fn (string $col): string => $db->quoteName(($alias === '' ? '' : $alias . '.') . $col);

        return '(' . $c('payment_type') . ' = ' . $db->quote(self::TYPE_REFUND)
            . ' AND ' . $c('transaction_status') . ' = ' . $db->quote(self::STATUS_REFUNDED) . ')';
    }

    /**
     * A row where money moved, in either direction.
     *
     * For the takings/refunds series, which fetches both and splits them by
     * `payment_type` in PHP. The completed side is `<> refund` rather than
     * `= payment` so a completed authorization still reaches the caller to be
     * classified there, exactly as it did before this predicate existed.
     *
     * It also yields reversal and chargeback rows (completed, not refund); the
     * caller classifies them with the target type from reversalTargetJoin().
     *
     * @param string $alias Table alias, '' when the query names no alias.
     *
     * @return string A parenthesised SQL predicate.
     *
     * @since  2.0.0
     */
    public static function moneyMovedPredicate(string $alias = ''): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $c = static fn (string $col): string => $db->quoteName(($alias === '' ? '' : $alias . '.') . $col);

        return '((' . $c('transaction_status') . ' = ' . $db->quote(self::STATUS_COMPLETED)
            . ' AND ' . $c('payment_type') . ' <> ' . $db->quote(self::TYPE_REFUND) . ')'
            . ' OR ' . self::refundPredicate($alias) . ')';
    }

    /**
     * The instant a payment row's money actually moved.
     *
     * `processed_at` is stamped only for STATUS_COMPLETED (see the write path in
     * {@see self::persist()}), and a refund carries STATUS_REFUNDED, so it is
     * NULL on every refund row ever written, and a date window on the bare column
     * drops all of them silently. `added` is the refund's real moment and stands
     * in. A payment keeps its confirmation time, which is not its creation time
     * for anything that settles late.
     *
     * Reading, not writing: nothing here changes when `processed_at` is stamped.
     *
     * @param string $alias Table alias, '' when the query names no alias.
     *
     * @return string An SQL expression naming a datetime.
     *
     * @since  2.0.0
     */
    public static function moneyMoment(string $alias = ''): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $c = static fn (string $col): string => $db->quoteName(($alias === '' ? '' : $alias . '.') . $col);

        return 'COALESCE(' . $c('processed_at') . ', ' . $c('added') . ')';
    }

    /**
     * A recorded reversal: a provider undid all or part of a refund or a chargeback.
     *
     * Reversal rows are born completed and never change (recordReversal()).
     *
     * @param string $alias Table alias, '' when the query names no alias.
     *
     * @return string A parenthesised SQL predicate.
     *
     * @since  2.0.0
     */
    public static function reversalPredicate(string $alias = ''): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $c = static fn (string $col): string => $db->quoteName(($alias === '' ? '' : $alias . '.') . $col);

        return '(' . $c('payment_type') . ' = ' . $db->quote(self::TYPE_REVERSAL)
            . ' AND ' . $c('transaction_status') . ' = ' . $db->quote(self::STATUS_COMPLETED) . ')';
    }

    /**
     * A recorded chargeback: money the customer's bank took back.
     *
     * Not a refund: refund figures leave it out, and it is its own figure.
     *
     * @param string $alias Table alias, '' when the query names no alias.
     *
     * @return string A parenthesised SQL predicate.
     *
     * @since  2.0.0
     */
    public static function chargebackPredicate(string $alias = ''): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $c = static fn (string $col): string => $db->quoteName(($alias === '' ? '' : $alias . '.') . $col);

        return '(' . $c('payment_type') . ' = ' . $db->quote(self::TYPE_CHARGEBACK)
            . ' AND ' . $c('transaction_status') . ' = ' . $db->quote(self::STATUS_COMPLETED) . ')';
    }

    /**
     * The join that gives a reversal row its target, so a query can tell a
     * reversed refund from a reversed chargeback.
     *
     * @param string $alias Alias of the payments table in the query.
     * @param string $targetAlias Alias given to the joined target row.
     *
     * @return string A LEFT JOIN clause.
     *
     * @since  2.0.0
     */
    public static function reversalTargetJoin(string $alias, string $targetAlias): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        return ' LEFT JOIN ' . $db->quoteName(self::TABLE, $targetAlias)
            . ' ON ' . $db->quoteName($targetAlias . '.id') . ' = ' . $db->quoteName($alias . '.id_reversed_payment');
    }

    /**
     * The join that gives a payment row its order, so a report can value the
     * payment in the currency that order names.
     *
     * A payment is money in its order's currency, and in no other: the order
     * names it, and the stamp on the payment row itself is not asked. A
     * report that filters on the joined order's id_currency therefore counts
     * a payment where its order is, and leaves out a payment whose order has
     * no row. With a currency alias the currency row is joined too, so an
     * order that names no currency row is left out as well.
     *
     * @param string $alias Alias of the payments table in the query.
     * @param string $orderAlias Alias given to the joined order row.
     * @param string|null $currencyAlias Alias given to the joined currency row; null = not joined.
     *
     * @return string One INNER JOIN clause, or two.
     *
     * @since  2.0.0
     */
    public static function orderJoin(string $alias, string $orderAlias, ?string $currencyAlias = null): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $join = ' INNER JOIN ' . $db->quoteName('#__alfa_orders', $orderAlias)
            . ' ON ' . $db->quoteName($orderAlias . '.id') . ' = ' . $db->quoteName($alias . '.id_order');

        if ($currencyAlias === null) {
            return $join;
        }

        return $join . ' INNER JOIN ' . $db->quoteName('#__alfa_currencies', $currencyAlias)
            . ' ON ' . $db->quoteName($currencyAlias . '.id') . ' = ' . $db->quoteName($orderAlias . '.id_currency');
    }

    /**
     * The rows that make up takings: settled payments, chargebacks (taken back)
     * and reversals of chargebacks (given back again). Needs reversalTargetJoin().
     *
     * Sum them with {@see self::takingsAmount()}.
     *
     * @param string $alias Alias of the payments table in the query.
     * @param string $targetAlias Alias of the joined reversal target.
     *
     * @return string A parenthesised SQL predicate.
     *
     * @since  2.0.0
     */
    public static function takingsPredicate(string $alias, string $targetAlias): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        return '(' . self::settledPaymentPredicate($alias)
            . ' OR ' . self::chargebackPredicate($alias)
            . ' OR (' . self::reversalPredicate($alias)
            . ' AND ' . $db->quoteName($targetAlias . '.payment_type') . ' = ' . $db->quote(self::TYPE_CHARGEBACK) . '))';
    }

    /**
     * The signed amount a takings row contributes: a chargeback subtracts.
     *
     * @param string $alias Alias of the payments table in the query.
     *
     * @return string An SQL expression.
     *
     * @since  2.0.0
     */
    public static function takingsAmount(string $alias): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        return 'CASE WHEN ' . $db->quoteName($alias . '.payment_type') . ' = ' . $db->quote(self::TYPE_CHARGEBACK)
            . ' THEN -' . $db->quoteName($alias . '.amount') . ' ELSE ' . $db->quoteName($alias . '.amount') . ' END';
    }

    /**
     * The rows that make up the refund flow: refunds, and the reversals of
     * refunds (money that came back). Needs reversalTargetJoin().
     *
     * A reversal counts only when its target is a refund refundPredicate()
     * counts: a figure never nets a reversal of a refund it left out.
     *
     * Sum them with {@see self::refundFlowAmount()}.
     *
     * @param string $alias Alias of the payments table in the query.
     * @param string $targetAlias Alias of the joined reversal target.
     *
     * @return string A parenthesised SQL predicate.
     *
     * @since  2.0.0
     */
    public static function refundFlowPredicate(string $alias, string $targetAlias): string
    {
        return '(' . self::refundPredicate($alias)
            . ' OR (' . self::reversalPredicate($alias)
            . ' AND ' . self::refundPredicate(alias: $targetAlias) . '))';
    }

    /**
     * The signed amount a refund-flow row contributes: a reversal subtracts.
     *
     * @param string $alias Alias of the payments table in the query.
     *
     * @return string An SQL expression.
     *
     * @since  2.0.0
     */
    public static function refundFlowAmount(string $alias): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        return 'CASE WHEN ' . $db->quoteName($alias . '.payment_type') . ' = ' . $db->quote(self::TYPE_REVERSAL)
            . ' THEN -' . $db->quoteName($alias . '.amount') . ' ELSE ' . $db->quoteName($alias . '.amount') . ' END';
    }

    /**
     * A refund that still stands: settled, and not reversed in full.
     *
     * @param string $alias Table alias, '' when the query names no alias.
     *
     * @return string A parenthesised SQL predicate.
     *
     * @since  2.0.0
     */
    public static function liveRefundPredicate(string $alias = ''): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $c = static fn (string $col): string => $db->quoteName(($alias === '' ? '' : $alias . '.') . $col);

        // Inside the subquery a bare `id` would be the reversal's own, so the
        // outer row is named by its table when the query gives it no alias.
        $outerId = $db->quoteName(($alias === '' ? self::TABLE : $alias) . '.id');

        return '(' . self::refundPredicate($alias)
            . ' AND ' . $c('amount') . ' > COALESCE((SELECT SUM(' . $db->quoteName('alfa_rv.amount') . ')'
            . ' FROM ' . $db->quoteName(self::TABLE, 'alfa_rv')
            . ' WHERE ' . $db->quoteName('alfa_rv.id_reversed_payment') . ' = ' . $outerId
            . ' AND ' . $db->quoteName('alfa_rv.payment_type') . ' = ' . $db->quote(self::TYPE_REVERSAL)
            . ' AND ' . $db->quoteName('alfa_rv.transaction_status') . ' = ' . $db->quote(self::STATUS_COMPLETED) . '), 0))';
    }

    /**
     * The lookup key of a provider reference: the lowercase hex SHA-256 of the complete value.
     *
     * Equality of references is equality of the complete value; the key only narrows the
     * lookup. It equals the transaction_id_hash the database
     * generates from the same bytes. An empty reference names nothing.
     *
     * @param string $reference The complete provider reference.
     *
     * @return string|null 64 hex characters, or null for ''.
     *
     * @since  2.0.0
     */
    public static function referenceHash(string $reference): ?string
    {
        return $reference === '' ? null : hash('sha256', $reference);
    }

    //  CONSTANTS: updateIfStatus() verdicts

    /** @var int Conditional write applied: this caller made the change */
    public const WRITE_OK = 1;

    /** @var int Row was no longer in the expected status: someone else settled it, nothing written */
    public const WRITE_RACED = 0;

    /** @var int Write rejected (money caps) or DB error */
    public const WRITE_FAILED = -1;

    //  CONSTANTS: refundPayment() outcomes
    //
    //  One verdict per way the refund dance can end. None of them names a
    //  transaction_status: the caller reports the OUTCOME and never has to
    //  re-derive what the ledger did to the rows.

    /** @var string Money returned: the linked audit row exists */
    public const OUTCOME_OK = 'refunded';

    /** @var string Nothing written: this payment was already refunded (idempotent re-run) */
    public const OUTCOME_ALREADY_REFUNDED = 'already_refunded';

    /**
     * @var string Nothing written: a refund row of this payment already carries
     *             this transaction_id: the provider retrying one refund, not a
     *             second one. Never reported for an empty reference.
     *
     * @since  2.0.0
     */
    public const OUTCOME_DUPLICATE = 'duplicate';

    /** @var string Nothing written: wrong type/status, an amount that is not a positive Money, or one over a cap */
    public const OUTCOME_NOT_REFUNDABLE = 'not_refundable';

    /** @var string Nothing written: the payment's refundable remainder is exhausted */
    public const OUTCOME_NOTHING_LEFT = 'nothing_left';

    /** @var string Nothing written: another caller moved the row mid-flight */
    public const OUTCOME_RACED = 'raced';

    /** @var string Refused and unwound: the ledger is back where it started */
    public const OUTCOME_FAILED = 'failed';

    /** @var string Gateway moved the money, the ledger refused the row, no rollback: logged CRITICAL */
    public const OUTCOME_UNRECONCILED = 'unreconciled';

    /**
     * @var string Nothing written: a provider has reported refunds on this
     *             payment's order that the books do not hold, so no new refund
     *             starts until they are recorded ({@see self::refundStartRefusal()}).
     *             Never the answer to recording money a provider already moved.
     *
     * @since  2.1.0
     */
    public const OUTCOME_BLOCKED = 'blocked';

    /**
     * @var string reserveRefund() / holdRefund(): the refund's amount is reserved by a
     *             pending refund row; nothing is booked.
     *
     * @since  2.1.0
     */
    public const OUTCOME_RESERVED = 'reserved';

    /**
     * @var string reserveRefund(): nothing written, because a refund row was written on
     *             the payment after the decision was drawn (its refund mark moved).
     *
     * @since  2.1.0
     */
    public const OUTCOME_STALE = 'stale';

    /**
     * @var string releaseRefund(): what the reservation still held is given back; nothing is booked.
     *
     * @since  2.1.0
     */
    public const OUTCOME_RELEASED = 'released';

    /**
     * @var string recordReversal() / recordChargeback(): the fact is written.
     *
     * @since  2.0.0
     */
    public const OUTCOME_RECORDED = 'recorded';

    /**
     * @var string recordReversal() / recordChargeback(): nothing written, because
     *             the request is not a fact this ledger can take. A retry cannot help.
     *
     * @since  2.0.0
     */
    public const OUTCOME_REFUSED = 'refused';

    //  CONSTANTS: the outcome of a provider refund report

    /**
     * @var string The report is booked: a refund row records it (Record this refund, typed or not).
     *
     * @since  2.1.0
     */
    public const REPORT_RECORDED = 'recorded';

    /**
     * @var string The report is resolved as a refund already in the books: no refund booked.
     *
     * @since  2.1.0
     */
    public const REPORT_RECONCILED = 'reconciled';

    /**
     * @var string The report is resolved as a duplicate or invalid report: no refund booked.
     *
     * @since  2.1.0
     */
    public const REPORT_DISMISSED = 'duplicate_or_invalid';

    //  CONSTANTS: the state of a reported refund at the provider

    /**
     * @var string The provider says the reported refund is complete: the money went back.
     *             Such a report is recorded with its figures.
     *
     * @since  2.1.0
     */
    public const REPORT_STATUS_SETTLED = 'settled';

    /**
     * @var string The provider has accepted the reported refund but not completed it.
     *             Such a report is never booked as a settled refund. While its exact amount
     *             in the order's currency is known, it reserves that amount in the order's
     *             refundable pool ({@see self::refundableNow()}); otherwise it holds the
     *             refund block.
     *
     * @since  2.1.0
     */
    public const REPORT_STATUS_PENDING = 'pending';

    /**
     * @var string The prefix of the reference a refund reservation carries
     *             ({@see self::reserveRefund()}) while the provider has not named its
     *             refund: 'request:' and the idempotency key the core made for it. A
     *             gateway never lists an entry under it: the reservation is in the books.
     *
     * @since  2.1.0
     */
    public const REPORT_REQUEST_PREFIX = 'request:';

    /**
     * @var string The prefix of the core's own reference for a refund a provider gave no refund id for, under one
     *             of this site's refund requests, that is not the request's reservation itself: 'beyond:' and the
     *             request's key for the request's one refund with no provider id (the first one beyond the
     *             reservation), 'beyond:', the key, ':' and the figure for a different figure the provider states
     *             for it afterwards (kept for staff, never booked by itself). Like REPORT_REQUEST_PREFIX it is
     *             never a provider refund id: the row's report names no provider id. create() refuses a second row
     *             under the same reference under the order's lock, so one provider word is one row.
     *
     * @since  2.1.0
     */
    public const REFUND_BEYOND_PREFIX = 'beyond:';

    /**
     * @var string The reportId prefix of the report the core itself makes for a refund reservation its
     *             provider settled while the books refused the booking ({@see self::EVENT_REFUND_UNRECONCILED}):
     *             'reservation:' and the reservation's row id, Alfa's own immutable reference. Such a report
     *             holds the refund block and is ended through the canonical way out (Record this refund,
     *             reconcile to an existing refund, duplicate or invalid report), never by editing the row.
     *
     * @since  2.1.0
     */
    public const REPORT_RESERVATION_PREFIX = 'reservation:';

    /**
     * @var int The longest reason an operator may give when booking or resolving a report, in characters.
     *
     * @since  2.1.0
     */
    public const REPORT_REASON_MAX = 1000;

    /**
     * @var string The order activity event of a payment set back from refunded to completed
     *             because a refund of it became failed or cancelled.
     *
     * @since  2.1.0
     */
    public const EVENT_REFUND_REEVALUATED = 'payment.refund_reevaluated';

    /**
     * @var string The order activity event that marks the row of one provider refund (a reservation, or a
     *             pending row split off it or held beside it) that the provider settled while the books refused
     *             the booking: money moved that the books do not hold. One provider refund, one row, one mark.
     *             While that row is still pending or authorized the core lists its report
     *             (REPORT_RESERVATION_PREFIX and the row id), which holds the refund block
     *             ({@see self::refundStartRefusal()}) until it goes through the way out: Record this refund,
     *             reconcile to an existing refund, or a duplicate or invalid report (the provider's own failure
     *             report, releaseRefund(), is added to that report since round 8; it no longer ends the row).
     *             Staff cannot fail, cancel, re-amount or delete it by hand.
     *
     * @since  2.1.0
     */
    public const EVENT_REFUND_UNRECONCILED = 'payment.refund_unreconciled';

    /**
     * @var string The order activity event of a later provider word about a word core keeps for staff: another
     *             figure under the same identity, a hold after a settle or a settle after a hold, written once per new
     *             fact on the row that keeps it (entity_id), with what the provider stated (provider_refund_id,
     *             provider_amount, provider_currency) and its kind (REPORT_STATUS_SETTLED or REPORT_STATUS_PENDING).
     *             The row's report shows every word, latest first; nothing is booked or changed by it
     *             ({@see self::keptWordAdded()}).
     *
     * @since  2.1.0
     */
    public const EVENT_REFUND_WORD_ADDED = 'payment.refund_word_added';

    /**
     * @var string The order activity event of a refund row that took the provider's refund id it had none of: the
     *             request's ONE refund with no provider id (kept, main's N8-2, or booked, main's decision of round 8d),
     *             named by the provider with its id at its figure. Written on the row (entity_id) with the id
     *             (provider_refund_id) and the request's key (request_key), so a later word under that key still
     *             finds its one refund ({@see self::keyRows()}).
     *
     * @since  2.1.0
     */
    public const EVENT_REFUND_IDENTIFIED = 'payment.refund_identified';

    /**
     * @var string The order activity event that surfaces money a provider moved under one of this site's refund
     *             requests beyond what was decided and reserved for it (its reservation used up or released):
     *             booked when the books hold it, else kept on a row of its own that holds the refund block.
     *             Notified to staff as well.
     *
     * @since  2.1.0
     */
    public const EVENT_REFUND_BEYOND_DECISION = 'payment.refund_beyond_decision';

    /**
     * @var string The order activity event raised the first time core sees a payment's gateway list a refund its
     *             provider settled that the books do not hold (a report that holds the refund block, its amount
     *             known or not), with the danger notification of the same report; that notification is archived
     *             when the report is resolved.
     *
     * @since  2.1.0
     */
    public const EVENT_REFUND_UNBOOKED_REPORTED = 'payment.refund_unbooked_reported';

    /**
     * @var string Where the outcome of each provider refund report is kept.
     *
     * @since  2.1.0
     */
    private const REPORT_OUTCOMES = '#__alfa_refund_report_outcomes';

    //  CONSTANTS: Table Configuration

    /** @var string Database table */
    private const TABLE = '#__alfa_order_payments';

    /** @var string Primary key column */
    private const PK = 'id';

    /**
     * Nullable integer columns: form sends '', MySQL rejects for int.
     */
    private const NULLABLE_INT_COLUMNS = ['id_refunded_payment', 'id_reversed_payment', 'id_document', 'id_credit_transaction', 'id_employee'];

    /**
     * Nullable datetime columns: empty/zero -> null.
     */
    private const NULLABLE_DATE_COLUMNS = ['processed_at'];

    /**
     * Nullable enum columns: '' is not a valid enum value in strict mode.
     */
    private const NULLABLE_ENUM_COLUMNS = ['refund_type', 'refund_reason'];

    //  BUILDER STATE (instance properties, used only in fluent mode)

    /** @var array Data accumulator for create/update */
    protected array $data = [];

    /** @var object|null Order context (null in update mode) */
    protected ?Order $order = null;

    /** @var string 'create' or 'update': determines save() behaviour */
    protected string $mode = 'create';

    /** @var int|null Existing payment PK (update mode only) */
    protected ?int $paymentId = null;

    /** @var bool Whether ->amount() or ->itemsOnly() was explicitly called */
    protected bool $amountOverridden = false;

    /** @var string|null ->expectStatus() guard; null = plain update() path */
    protected ?string $expectedStatus = null;

    /** @var bool ->bookkeeping(): write without the merchant-visible side effects */
    protected bool $bookkeeping = false;

    /** @var bool ->asStaff(): the write of a staff entry point, held to the staff guard ({@see self::staffWriteRefusal()}) */
    protected bool $staff = false;

    //  FLUENT BUILDER API: the recommended developer interface

    /**
     * CREATE mode: build a new payment for an order.
     *
     * Auto-fills from order context: id_order, id_payment_method, payment_type.
     * Amount auto-resolved on save() if not manually set.
     *
     * Usage:
     *   PaymentLedger::for($order)->pending()->save();
     *   PaymentLedger::for($order)->authorized()->transactionId('ch_3M..')->save();
     *   PaymentLedger::for($order)->refund()->amount(25)->completed()->save();
     *
     * @param object $order Full order object (->id, ->items, ->id_payment_method)
     *
     * @return static Builder in create mode; chain setters, finish with ->save()
     *
     * @since  1.0.0
     */
    public static function for(object $order): static
    {
        // READ THROUGH THE ORDER READER, NOT OFF THE ROW. Plugins are handed an
        // Order by $event->getOrder() and the admin hands a loaded row; both
        // arrive here, and reading `->id` off the first wrote every payment
        // against order 0: the shopper was asked for nothing and the order
        // carried no payment row at all.
        $order = Order::of($order);

        $builder = new static();
        $builder->mode = 'create';
        $builder->order = $order;
        $builder->data = [
            'id_order' => $order->id(),
            'id_payment_method' => $order->paymentMethodId(),
            'payment_type' => self::TYPE_PAYMENT,
        ];

        return $builder;
    }

    /**
     * UPDATE mode: modify an existing payment record.
     *
     * Loads the existing payment from DB to validate existence and get
     * id_order for activity logging. Only fields you explicitly set
     * via setter methods are sent to the database.
     *
     * Usage:
     *   PaymentLedger::load($id)->completed()->save();
     *   PaymentLedger::load($id)->cancelled()->note('Customer request')->save();
     *   PaymentLedger::load($id)->amount(80.00)->save();
     *
     * @param int $paymentId Existing payment row PK
     *
     * @return static Builder in update mode; chain setters, finish with ->save()
     *
     * @throws RuntimeException If payment not found in DB
     *
     * @since  1.0.0
     */
    public static function load(int $paymentId): static
    {
        $existing = self::getRaw($paymentId);

        if (!$existing) {
            throw new RuntimeException(
                "PaymentLedger::load(): Payment #{$paymentId} not found",
            );
        }

        $builder = new static();
        $builder->mode = 'update';
        $builder->paymentId = $paymentId;
        $builder->data = [
            'id_order' => (int) ($existing->id_order ?? 0),
        ];

        return $builder;
    }

    // Status Setters (one per valid status: IDE autocomplete)

    /**
     * Set status to PENDING.
     * Use for: offline payments (cash, bank transfer), awaiting gateway callback.
     * @since  1.0.0
     */
    public function pending(): static
    {
        $this->data['transaction_status'] = self::STATUS_PENDING;
        return $this;
    }

    /**
     * Set status to AUTHORIZED.
     * Use for: gateway pre-auth (funds reserved, capture later with ->completed()).
     * @since  1.0.0
     */
    public function authorized(): static
    {
        $this->data['transaction_status'] = self::STATUS_AUTHORIZED;
        return $this;
    }

    /**
     * Set status to COMPLETED.
     * Use for: payment captured, money in the bank.
     * @since  1.0.0
     */
    public function completed(): static
    {
        $this->data['transaction_status'] = self::STATUS_COMPLETED;
        return $this;
    }

    /**
     * Set status to FAILED.
     * Use for: declined card, gateway timeout, insufficient funds.
     * @since  1.0.0
     */
    public function failed(): static
    {
        $this->data['transaction_status'] = self::STATUS_FAILED;
        return $this;
    }

    /**
     * Set status to CANCELLED.
     * Use for: admin or customer cancels before payment completes.
     * @since  1.0.0
     */
    public function cancelled(): static
    {
        $this->data['transaction_status'] = self::STATUS_CANCELLED;
        return $this;
    }

    /**
     * Set status to REFUNDED.
     * Use for: marking the original payment as refunded.
     * @since  1.0.0
     */
    public function refunded(): static
    {
        $this->data['transaction_status'] = self::STATUS_REFUNDED;
        return $this;
    }

    // Payment Type Setters

    /**
     * Mark as refund audit record.
     * Creates a separate record with payment_type = 'refund' for the activity log.
     *
     * Example: $this->payment($order)->refund()->amount(50)->completed()->save();
     * @since  1.0.0
     */
    public function refund(): static
    {
        $this->data['payment_type'] = self::TYPE_REFUND;
        return $this;
    }

    /**
     * Mark as authorization hold.
     * For gateway pre-auth flows: hold funds now, capture later.
     * @since  1.0.0
     */
    public function authorization(): static
    {
        $this->data['payment_type'] = self::TYPE_AUTHORIZATION;
        return $this;
    }

    // Amount Setters

    /**
     * Set amount manually: overrides all auto-calculation.
     *
     * Use for: partial payments, split payments, specific refund amounts,
     * deposit payments, installments.
     *
     * A Money in the order's currency, or a decimal text, is written exactly;
     * a float is read as Money reads one.
     *
     * @param Money|string|int|float $amount Payment amount (tax inclusive)
     * @since  1.0.0
     */
    public function amount(Money|string|int|float $amount): static
    {
        $this->data['amount'] = $amount;
        $this->amountOverridden = true;
        return $this;
    }

    /**
     * Set amount = items total only (no shipping, no discounts).
     *
     * Use for: deposit payments, item-only charges before shipping is calculated.
     * @since  1.0.0
     */
    public function itemsOnly(): static
    {
        if ($this->order) {
            $this->data['amount'] = OrderTotalHelper::getItemsTotal(
                $this->order->id(),
                $this->order->row(),
            );
            $this->amountOverridden = true;
        }

        return $this;
    }

    // Data Setters

    /**
     * Set the external gateway transaction ID.
     *
     * Stored for reconciliation (the payment gateway's charge ID, txn ID, etc.)
     *
     * @param string $id Gateway reference (e.g. 'ch_3MqBE2...')
     * @since  1.0.0
     */
    public function transactionId(string $id): static
    {
        $this->data['transaction_id'] = $id;
        return $this;
    }

    /**
     * Label this money movement for the admin.
     *
     * A payment row is ONE movement, so a deposit and its balance are two
     * otherwise-identical rows; this is what tells them apart in the payments
     * grid. Internal: never shown to the customer. Max 255 chars.
     *
     * @param string $note e.g. 'Deposit 30%', 'Balance'
     * @since  1.0.0
     */
    public function note(string $note): static
    {
        $this->data['note'] = $note;
        return $this;
    }

    /**
     * Link this refund to the original payment it refunds.
     *
     * Sets id_refunded_payment FK. Used by plugins when creating
     * refund records so the audit trail links back.
     *
     * @param int $paymentId Original payment row PK
     *
     *
     * @since  1.0.0
     */
    public function refundedPayment(int $paymentId): static
    {
        $this->data['id_refunded_payment'] = $paymentId;
        return $this;
    }

    /**
     * Link this refund to the credit document it settles.
     *
     * The FK lives on the PAYMENT, never on the document: an issued document
     * is immutable, so the mutable ledger row is the only side that may be
     * stamped after the fact. Leave it unset for a goodwill or offline refund
     * that has no document; NULL is a legitimate answer, not a gap.
     *
     * @param int $documentId #__alfa_documents.id (a credit invoice/receipt)
     *
     * @since  2.0.0
     */
    public function document(int $documentId): static
    {
        $this->data['id_document'] = $documentId;
        return $this;
    }

    /**
     * Set the refund reason (free text from admin or gateway).
     *
     * @param string $reason Human-readable reason
     *
     *
     * @since  1.0.0
     */
    public function refundReason(string $reason): static
    {
        $this->data['refund_reason'] = $reason;
        return $this;
    }

    /**
     * Set refund type: full or partial.
     *
     * NOT auto-detected: nothing compares the refund amount to the original,
     * so the caller decides which this is. The TOTAL is capped though:
     * create() rejects a refund that would take the refunds against one
     * payment past its amount, provided the refund names its original with
     * ->refundedPayment().
     *
     * @param string $type 'full' or 'partial' (use REFUND_FULL / REFUND_PARTIAL)
     *
     *
     * @since  1.0.0
     */
    public function refundType(string $type): static
    {
        $this->data['refund_type'] = $type;
        return $this;
    }

    /**
     * Mark as full refund.
     *
     * Shortcut for ->refundType(self::REFUND_FULL).
     *
     *
     * @since  1.0.0
     */
    public function fullRefund(): static
    {
        $this->data['refund_type'] = self::REFUND_FULL;
        return $this;
    }

    /**
     * Mark as partial refund.
     *
     * Shortcut for ->refundType(self::REFUND_PARTIAL).
     *
     *
     * @since  1.0.0
     */
    public function partialRefund(): static
    {
        $this->data['refund_type'] = self::REFUND_PARTIAL;
        return $this;
    }

    /**
     * Set the processed_at timestamp (when gateway confirmed).
     *
     * @param string $datetime SQL datetime string (UTC)
     *
     *
     * @since  1.0.0
     */
    public function processedAt(string $datetime): static
    {
        $this->data['processed_at'] = $datetime;
        return $this;
    }

    /**
     * Store raw gateway response JSON for debugging/auditing.
     *
     * @param string $json Raw JSON from the payment gateway.
     *
     *
     * @since  1.0.0
     */
    public function gatewayResponse(string $json): static
    {
        $this->data['gateway_response'] = $json;
        return $this;
    }

    /**
     * Override the payment method ID.
     *
     * Normally auto-filled from $order->id_payment_method.
     * Use when creating a payment for a different method than the order's default.
     *
     * @param int $methodId Payment method PK
     * @since  1.0.0
     */
    public function method(int $methodId): static
    {
        $this->data['id_payment_method'] = $methodId;
        return $this;
    }

    /**
     * Set any arbitrary column value.
     *
     * Escape hatch for plugin-specific columns or future schema additions.
     * Unknown columns are safely filtered out by saveToTable().
     *
     * @param string $key Column name
     * @param mixed $value Value to set
     * @since  1.0.0
     */
    public function set(string $key, mixed $value): static
    {
        if ($key === 'plugin_data') {
            // Straight past the merge, the validation and the lock, and as a
            // pre-encoded string, which the next merge would double-encode.
            throw new ValueException(
                'PaymentLedger::set(): write the payload with ->pluginData([...]) '
                . 'and clear keys with ->removePluginData([...]).',
            );
        }

        $this->data[$key] = $value;
        return $this;
    }

    /**
     * Only write if the row is STILL in $status: settle exactly once.
     *
     * Gateways deliver concurrently: the buyer's return and the webhook
     * both try pending->completed at essentially the same instant. With
     * this armed, save() delegates to updateIfStatus(), so the losing
     * caller writes nothing and fires no side effects (emails, status
     * automation, document auto-issue).
     *
     * save() returns false for BOTH the raced and the failed outcome; a
     * plugin that must tell them apart calls updateIfStatus() directly.
     *
     * Update mode only; ignored in create mode.
     *
     * @param string $status The transaction_status the row must still hold
     *
     * @since  2.0.0
     */
    public function expectStatus(string $status): static
    {
        $this->expectedStatus = $status;
        return $this;
    }

    /**
     * Record this write as BOOKKEEPING, not as a merchant-visible
     * transition.
     *
     * Some writes only correct what the ledger says about a row nobody is
     * looking at: a dead gateway attempt being closed, a stale hold being
     * tidied. The row still moves and the books still show it, but nothing
     * happened that the customer or the merchant should be told about.
     *
     * STILL RUNS: the DB write, the change diff, the payment.edited
     * activity log (the audit trail is the whole point) and, on the
     * completed->cancelled/failed edge, the customer-credit reversal:
     * returning spent wallet money is money truth, not presentation; and
     * when the write settles a linked refund whose settled refunds now cover
     * its payment, that PAYMENT's flip to refunded with its own status
     * automation (status_on_refunded, once): the payment reached that phase
     * whichever door settled the refund (since 2.1.0).
     *
     * SKIPPED:
     *   - applyStatusAutomation(): the method's status_on_<phase> map runs
     *     through Transition, which moves the ORDER's status, writes its own
     *     activity entry, SENDS THE STATUS EMAIL and applies stock effects.
     *     On an abandoned attempt that means emailing "your order was
     *     cancelled" to a buyer who is at that moment paying on another tab.
     *   - Entitlements::handlePaymentTransition(): grants and revokes
     *     download access for the WHOLE order. A dead attempt must not
     *     revoke files the buyer already paid for on another row.
     *
     * Use it ONLY when the write records a fact about the ROW. If the
     * ORDER genuinely reached a new phase, do not reach for this; the
     * suppressed effects are exactly what makes that phase real.
     *
     * Update mode only; ignored in create mode.
     *
     * @since  2.0.0
     */
    public function bookkeeping(): static
    {
        $this->bookkeeping = true;
        return $this;
    }

    /**
     * Mark this write as a STAFF write: the write of one of the core's staff entry points (the REST settle verb; the
     * payment form passes update()'s $staff), held to the staff guard at the write door
     * ({@see self::staffWriteRefusal()}). Only those entry points mark their writes. A payment plugin never does:
     * its writes, inside a staff user's request or not, are not held to the guard.
     *
     * Update mode only; ignored in create mode.
     *
     * @since  2.1.0
     */
    public function asStaff(): static
    {
        $this->staff = true;

        return $this;
    }

    // Terminal Operation

    /**
     * Validate and persist the payment record.
     *
     * CREATE mode: validates status, resolves amount, calls create().
     * UPDATE mode: sends only changed fields, calls update(). No-op if nothing changed.
     * With ->expectStatus() armed, update mode routes through updateIfStatus()
     * and returns false for both the raced and the failed outcome.
     *
     * @return int|false Payment row ID on success, false on failure
     *
     * @throws RuntimeException If transaction_status not set (create mode)
     * @throws RuntimeException If payment ID missing (update mode)
     *
     * @since  1.0.0
     */
    public function save(): int|false
    {
        if ($this->mode === 'update') {
            return $this->doBuilderUpdate();
        }

        return $this->doBuilderCreate();
    }

    /**
     * Persist and report WHICH of the three things happened.
     *
     * Same write as {@see self::save()}, but it answers with the WRITE_*
     * verdict instead of collapsing "someone else got there first" and "the
     * write was refused" into one `false`. A gateway handler needs that
     * difference: a lost race is a 200 (the payment IS settled, by the other
     * channel), a refusal is a 5xx (come back and try again).
     *
     * Exists so a plugin never has to drop out of the builder and hand-write a
     * column array: a mistyped key there is silently discarded by
     * saveToTable()'s column filter, which turns a missed status change into a
     * successful-looking write. The setters cannot be mistyped.
     *
     * Requires ->expectStatus(); without it there is no race to report and it
     * falls back to a plain update.
     *
     * Staged ->pluginData() / ->removePluginData() travel with the verdict:
     * they are merged under the order lock the write takes and go out in the
     * SAME conditional UPDATE as the status, so a lost race or a refusal
     * writes none of them. A verdict the row still matches but that changes
     * nothing, such as a redelivered payload already stored, is WRITE_OK.
     *
     * @return int WRITE_OK | WRITE_RACED | WRITE_FAILED
     *
     * @since  2.0.0
     */
    public function saveVerdict(): int
    {
        if ($this->mode !== 'update' || !$this->paymentId) {
            return $this->save() !== false ? self::WRITE_OK : self::WRITE_FAILED;
        }

        $changes = array_diff_key($this->data, ['id_order' => true]);

        // A payload-only verdict is a write, exactly as it is for save().
        if (empty($changes) && !$this->hasStagedPluginData()) {
            // Nothing to write is not a failure: the row already says this.
            return self::WRITE_OK;
        }

        return $this->writeUpdate();
    }

    // Introspection (for tooling, validation, documentation)

    /**
     * Get all valid transaction_status values.
     *
     * Useful for validation dropdowns, documentation generation.
     *
     * @return array ['pending', 'authorized', 'completed', 'failed', 'cancelled', 'refunded']
     * @since  1.0.0
     */
    public static function allStatuses(): array
    {
        return [
            self::STATUS_PENDING, self::STATUS_AUTHORIZED, self::STATUS_COMPLETED,
            self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_REFUNDED,
        ];
    }

    /**
     * The statuses an existing row may be written with: every status, except that a settled
     * refund (refunded or completed) is never set back to pending or authorized. Its way out
     * is failed or cancelled, which re-evaluates its payment. The one rule the ledger holds
     * every write to ({@see self::settledRefundRefusal()}) and the payment form offers.
     *
     * @param string $paymentType The row's payment_type as stored.
     * @param string $status The row's transaction_status as stored.
     *
     * @return string[]
     *
     * @since  2.1.0
     */
    public static function statusesFrom(string $paymentType, string $status): array
    {
        if ($paymentType !== self::TYPE_REFUND || !\in_array($status, self::REFUND_SETTLED_STATUSES, true)) {
            return self::allStatuses();
        }

        return array_values(array_diff(self::allStatuses(), [self::STATUS_PENDING, self::STATUS_AUTHORIZED]));
    }

    /**
     * Get all valid payment_type values, in the column's enum order.
     *
     * @return array ['payment', 'refund', 'authorization', 'reversal', 'chargeback']
     * @since  1.0.0
     */
    public static function allTypes(): array
    {
        return [self::TYPE_PAYMENT, self::TYPE_REFUND, self::TYPE_AUTHORIZATION, self::TYPE_REVERSAL, self::TYPE_CHARGEBACK];
    }

    /**
     * The payment_type values a hand-made row may have.
     *
     * A reversal or a chargeback is written only by the ledger's own verbs,
     * so no form or caller offers them.
     *
     * @return array ['payment', 'refund', 'authorization']
     *
     * @since  2.0.0
     */
    public static function recordableTypes(): array
    {
        return [self::TYPE_PAYMENT, self::TYPE_REFUND, self::TYPE_AUTHORIZATION];
    }

    /**
     * Get all valid refund_type values.
     *
     * @return array ['full', 'partial']
     *
     * @since  1.0.0
     */
    public static function allRefundTypes(): array
    {
        return [self::REFUND_FULL, self::REFUND_PARTIAL];
    }

    /**
     * Get current builder mode.
     *
     * @return string 'create' or 'update'
     * @since  1.0.0
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Preview the data array without saving.
     *
     * Resolves amount for create mode (shows what save() would compute).
     * Useful for debugging and testing.
     *
     * @return array The data that would be passed to create() or update()
     * @since  1.0.0
     */
    public function toArray(): array
    {
        $data = $this->data;

        if ($this->mode === 'create' && !$this->amountOverridden && $this->order) {
            $data['amount'] = $this->resolveAmount();
        }

        return $data;
    }

    // Builder Internal Methods

    /**
     * Execute the CREATE flow.
     * @since  1.0.0
     */
    protected function doBuilderCreate(): int|false
    {
        // Validate: status is required for create
        if (empty($this->data['transaction_status'])) {
            throw new RuntimeException(
                'PaymentLedger: transaction_status is required. '
                . 'Call ->pending(), ->authorized(), ->completed(), ->failed(), '
                . '->cancelled(), or ->refunded() before ->save(). '
                . 'Valid values: ' . implode(', ', self::allStatuses()),
            );
        }

        // Resolve amount if not manually overridden
        if (!$this->amountOverridden) {
            $this->data['amount'] = $this->resolveAmount();
        }

        // Grand-total hint for the overpayment guard: the same in-memory
        // figure resolveAmount() trusts. At checkout the payment plugin's
        // onOrderAfterPlace fires BEFORE the shipment plugin writes its
        // row, so the DB recomputation is short by shipping there; the
        // guard must never reject an amount the ledger itself resolved.
        $grandTotalHint = 0.0;

        if ($this->order && $this->order->hasComputedTotal()) {
            $grandTotalHint = $this->order->total();
        }

        // No row to merge with yet, so the staged keys are the whole payload.
        $this->stagePluginDataForCreate();

        // Delegate to the static CRUD engine
        return static::create($this->data, $grandTotalHint);
    }

    /**
     * Execute the UPDATE flow.
     *
     * Only sends fields that were explicitly set by the developer.
     * Returns the payment ID on success (including no-op), false on failure.
     * @since  1.0.0
     */
    protected function doBuilderUpdate(): int|false
    {
        if (!$this->paymentId) {
            throw new RuntimeException(
                'PaymentLedger: No payment ID for update. Use ::load($id) first.',
            );
        }

        // Only fields explicitly set (beyond the always-present id_order)
        $changes = array_diff_key($this->data, ['id_order' => true]);

        // A save whose only change is a payload key is still a save: the staged
        // keys are not in $this->data yet, because they are merged against the
        // stored row at write time rather than at build time.
        if (empty($changes) && !$this->hasStagedPluginData()) {
            // No-op: nothing to change, return current ID
            return $this->paymentId;
        }

        // Raced and failed both collapse to false here; a caller needing the
        // distinction uses saveVerdict(), which runs the same write.
        return $this->writeUpdate() === self::WRITE_OK ? $this->paymentId : false;
    }

    /**
     * The one update write behind save() and saveVerdict().
     *
     * Both used to carry their own copy, and only save()'s ran inside the
     * payload merge: saveVerdict() handed updateIfStatus() the columns alone,
     * so every payload key staged on a compare-and-set settle was dropped
     * without a word. One body now serves both, so neither can drift.
     *
     * Armed via ->expectStatus() it is the compare-and-set; otherwise a plain
     * update(). A staged payload is merged BY that write, under the order
     * lock it already takes, and goes out in its UPDATE: a raced or refused
     * write stores none of it, and a payload must not record what a refused
     * write did not do.
     *
     * Not the trait's writeWithPluginData(), which wraps the write in a
     * transaction of its own. That held the payment row before the order row
     * (against the ORDER-first hierarchy every other money write follows)
     * and turned the write's post-commit side effects (status automation,
     * entitlements, document auto-issue) into work done inside an open
     * transaction, where a throwing plugin rolled a settled payment back.
     *
     * @return int WRITE_OK | WRITE_RACED | WRITE_FAILED
     *
     * @since  2.0.0
     */
    private function writeUpdate(): int
    {
        $payloadMerge = null;

        if ($this->hasStagedPluginData()) {
            $payloadMerge = function (): string {
                $this->mergeStagedPluginData($this->paymentId);

                return (string) $this->data['plugin_data'];
            };
        }

        if ($this->expectedStatus !== null) {
            return static::updateIfStatus(
                $this->paymentId,
                $this->data,
                $this->expectedStatus,
                $this->bookkeeping,
                $payloadMerge,
                staff: $this->staff,
            );
        }

        return static::update($this->paymentId, $this->data, $this->bookkeeping, $payloadMerge, staff: $this->staff)
            ? self::WRITE_OK
            : self::WRITE_FAILED;
    }

    /**
     * Resolve payment amount from the order's grand total.
     *
     * total_paid_tax_incl is always correct regardless of context:
     *   - Checkout: overridden by OrderPlacement from the placement cart
     *   - Admin/API: computed by OrderModel::getItem() from items + shipping - discounts
     *
     * Falls back to OrderTotalHelper if total_paid_tax_incl is not set
     * (defensive: shouldn't happen in normal flow).
     *
     * Called automatically by doBuilderCreate() when ->amount() was not called.
     * The amount is the order's Money, so the storage door writes its digits.
     *
     * @return Money|int The amount to collect; 0 when the builder holds no order.
     *
     * @since  1.0.0
     */
    protected function resolveAmount(): Money|int
    {
        if (!$this->order) {
            return 0;
        }

        // The public gateway contract: grand total minus money already
        // collected. One seam, every plugin (auto-resolve path) is
        // remainder-aware; explicit ->amount() calls are unaffected.
        return self::amountToCollectMoney($this->order);
    }

    //  STATIC CRUD ENGINE: the low-level database operations
    //
    //  The fluent builder delegates here. Also callable directly
    //  when the builder is overkill (batch operations, exotic plugins).

    /**
     * Create a new order payment record.
     *
     * Auto-fills server-side defaults for missing fields:
     *   - added          -> current UTC timestamp
     *   - id_employee    -> current admin user ID
     *   - id_currency    -> inherited from the parent order
     *   - payment_method -> name snapshot (survives method deletion)
     *
     * @param array $data Payment data. 'id_order' is REQUIRED.
     * @param Money|float $grandTotalHint In-memory order grand total the builder
     *                                    resolved its amount from (0 = none). The
     *                                    overpayment guard measures against
     *                                    max(DB total, hint); see the guard.
     * @param bool $refundStart True when a refund row is a new refund START (no
     *                          provider has moved its money yet: refundPayment()
     *                          without gateway_moved, a refund typed by staff). It
     *                          is then decided again under the order's lock: refused
     *                          while the refund block holds, and held to what the
     *                          order may still refund once the pending provider
     *                          refunds are reserved ({@see self::refundableNow()}).
     *                          False for money already moved, which the books'
     *                          caps alone judge (since 2.1.0).
     *
     * @return int|false New payment row ID, or false on failure
     *
     * @throws OrderCurrencyException When the order names no currency: nothing is written.
     *
     * @since  1.0.0
     */
    public static function create(array $data, Money|float $grandTotalHint = 0.0, bool $refundStart = false): int|false
    {
        $orderId = (int) ($data['id_order'] ?? 0);

        if ($orderId <= 0) {
            self::warn('create(): id_order is required');
            return false;
        }

        // A payment is money in its order's currency: an order that names
        // none takes no payment row.
        OrderCurrency::of(orderId: $orderId);

        // Ensure this is treated as an insert (strip any stale PK)
        unset($data[self::PK]);

        $db = self::db();

        // The serialization point for order money
        // Every guard below is a read of order-scoped money followed by an
        // insert that changes it. Unserialized, that is check-then-act: two
        // concurrent refunds both read the same pool, both fit, both write,
        // and the order pays back more than it ever collected. The same
        // window let a credit note's wallet leg and a gateway refund each
        // claim the whole pool.
        //
        // The lock lives HERE, not in the callers, because create() is the
        // one chokepoint every writer already passes through: the admin
        // form, refundPayment(), the subscriptions engine, the credit
        // ledger's spend, and every third-party gateway (its reservation,
        // reserveRefund(), and the outcomes that move it). Locking in
        // the callers would put money semantics in plugins, which is exactly
        // what the core/plugin boundary forbids; here, they get it for free
        // and never have to know it exists.
        //
        // #__alfa_orders is the row because "money refundable on this order"
        // is an order-scoped quantity, and it is already the serialization
        // row DocumentIssuer::lockOrder and CustomerCreditLedger::spendOnOrder
        // use. It is the TOP of the lock hierarchy (ORDER -> DOCUMENT ->
        // ACCOUNT), so every path takes these in the same order and none can
        // deadlock against another.
        //
        // SAVEPOINT SEMANTICS ARE MANDATORY, NOT A STYLE CHOICE. create() is
        // already called from inside a caller's transaction (spendOnOrder
        // holds this very lock when it inserts the credit payment), and
        // Joomla's transactionStart(false) issues a second begin_transaction()
        // that MySQL turns into an implicit COMMIT of that caller's work.
        // transactionStart(true) degrades to a SAVEPOINT when nested and
        // opens a real transaction when it is not: the InventoryService
        // pattern.
        //
        // EVERY TRANSACTION OF THIS LEDGER IS CLOSED ONCE, BY THE METHOD THAT
        // OPENED IT: a refusal rolls back and returns, a throw is rolled back
        // by the catch, and success commits after the try, so a failed commit
        // is never closed a second time. Nothing called inside commits or
        // rolls back. A second close of one level closes the CALLER's: its
        // work is rolled back, or every later write runs without a
        // transaction at all.
        $db->transactionStart(true);

        try {
            if (!self::lockOrders($db, $orderId)) {
                TransactionState::rollBackLevel($db);

                return false;
            }

            $newId = self::createLocked($data, $orderId, $grandTotalHint, refundStart: $refundStart);

            if ($newId === false) {
                TransactionState::rollBackLevel($db);

                return false;
            }

            // Bring the wallet in line with the books. FAIL-CLOSED here: this row
            // is a refund, and a settled refund whose wallet leg cannot move must
            // never commit: it would be a refund on the books that the customer
            // never received. The reconciler no-ops for anything that is not a
            // credit payment or a refund of one.
            $walletEntries = self::settleWalletForPayment($newId, $orderId, failClosed: true);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel($db, $e);

            throw $e;
        }

        TransactionState::commitLevel($db);

        // The wallet rows' committed lines, after the COMMIT and never on a
        // rollback path. Post-commit only when this call owns the
        // transaction: nested in a caller's (spendOnOrder, or any caller
        // holding one), transactionStart(true) was a SAVEPOINT and this
        // commit only released it, as with write()'s own-transaction line.
        foreach ($walletEntries as $entry) {
            \Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger::logCommitted($entry);
        }

        // Post-commit: the money row is durable, so the timeline entry is
        // best-effort by definition and can no longer affect the write.
        self::logCreatedRow($newId, $orderId);

        // A linked refund born settled (a plugin's builder ->refund()->...->completed()->save()): its payment is
        // refunded once its settled refunds cover it, as on every settle path (since 2.1.0). A part split off a
        // reservation is flipped by its settle once the split has committed ({@see self::$deferFlip}).
        if (!self::$deferFlip
            && (string) ($data['payment_type'] ?? '') === self::TYPE_REFUND
            && \in_array((string) ($data['transaction_status'] ?? ''), self::REFUND_SETTLED_STATUSES, true)
            && (int) ($data['id_refunded_payment'] ?? 0) > 0) {
            self::flipIfCovered(paymentId: (int) $data['id_refunded_payment'], orderId: $orderId);
        }

        return $newId;
    }

    /**
     * The body of {@see self::create()}, run under the order row lock.
     *
     * Split out only so the guards can keep returning false without every
     * one of them repeating the rollback. NEVER call this directly: it
     * assumes the caller holds the #__alfa_orders lock, and without it every
     * cap below is a plain check-then-act again.
     *
     * @param array $data Payment data, already normalised
     * @param int $orderId Parent order PK (locked)
     * @param Money|float $grandTotalHint See create()
     * @param bool $ledgerFact True only from recordReversal() and
     *                         recordChargeback(): the one door a reversal
     *                         or a chargeback row is written through.
     * @param bool $refundStart See create() (since 2.1.0).
     *
     * @return int|false New payment row ID, or false if a guard refused
     *
     * @since  2.0.0
     */
    private static function createLocked(array $data, int $orderId, Money|float $grandTotalHint, bool $ledgerFact = false, bool $refundStart = false): int|false
    {
        // Money -> the column's decimal, refusing a currency the order is not
        // in. Here, the one door every new row passes, so a verb that writes
        // its own row hands the Money as it is.
        $stored = self::moneyForStorage(value: $data['amount'] ?? 0, orderId: $orderId, field: 'amount');

        if ($stored === false) {
            return false;
        }

        $data['amount'] = $stored;

        // Facts are written by the verbs only
        // A reversal or a chargeback is a provider fact the ledger records
        // through its own verbs, which check the target, the reference and
        // the remainder under the lock. No other caller may write one.
        $rowType = (string) ($data['payment_type'] ?? self::TYPE_PAYMENT);

        if (!$ledgerFact && \in_array($rowType, self::FACT_TYPES, true)) {
            self::warn(sprintf('create(): a %s is recorded by PaymentLedger::recordReversal() or ::recordChargeback()', $rowType));

            return false;
        }

        if ($rowType !== self::TYPE_REVERSAL && (int) ($data['id_reversed_payment'] ?? 0) > 0) {
            self::warn('create(): only a reversal names a reversed row');

            return false;
        }

        // Money guards
        // Ledger rows are BORN here: plugins, the admin form and CLI all
        // insert through create(), so the caps sit here: a payment is
        // capped by the order grand total (a REFUNDED original reserves
        // nothing), EVERY refund by the order's refundable pool, and a
        // linked refund additionally by its original's gross. update()
        // re-runs whichever cap a change to amount, status, type, refund
        // link or order could move (recheckCapsOnUpdate), so a saved row
        // can be neither raised nor settled past its cap; the concurrent
        // settle race is closed by updateIfStatus().
        //
        // A row born failed/cancelled moves no money and is always
        // recordable (declined-attempt audit trail); reviving it later
        // goes through the update() re-check.
        $bornVoided = in_array(
            (string) ($data['transaction_status'] ?? ''),
            [self::STATUS_FAILED, self::STATUS_CANCELLED],
            true,
        );

        // Sign
        // Direction is carried by payment_type, never by the sign: a refund
        // is a positive row of type 'refund', and all 462 rows on the demo
        // agree; the column comment claiming "negative for refunds" is
        // older than the design and has been corrected.
        //
        // Nothing enforced that. A refund of -5.00 inserted cleanly, and
        // afterwards the books disagreed with themselves: the readers that
        // sum ABS() counted it as five euro returned while a plain SUM
        // counted it as five euro un-returned. Every cap below is a
        // comparison against a total, so one negative row moves the ceiling
        // instead of being stopped by it: a way to refund more than was
        // collected, using a minus sign.
        //
        // abs() is deliberately NOT applied: silently turning -5.00 into
        // 5.00 would record money the caller never asked to move.
        if ((float) ($data['amount'] ?? 0) < 0.0) {
            self::warn(sprintf(
                'create(): amount %.2f is negative; direction is carried by payment_type, not by the sign',
                (float) $data['amount'],
            ));

            return false;
        }

        // Overpayment guard
        // Authorization-type rows are deliberately NOT counted: a hold is
        // not a collection.
        if (!$bornVoided && ($data['payment_type'] ?? self::TYPE_PAYMENT) === self::TYPE_PAYMENT) {
            $thisPayment = self::money($data['amount'], $orderId)->abs();
            $alreadyRecorded = self::sumRecordedPayments($orderId);

            // The hint exists because at checkout the shipment row does not
            // exist yet (onOrderAfterPlace fires before the shipment plugin
            // writes it), so the DB recomputation reads shipping as 0 while
            // the builder resolved this amount from the in-memory order
            // WITH shipping; the larger keeps the guard from rejecting the
            // ledger's own resolved amount. Both are the order's Money, so
            // the hint's digits are compared as they are.
            $grandTotal = self::money(self::orderGrandTotal($orderId), $orderId);
            $hintTotal = self::money($grandTotalHint, $orderId);

            if ($hintTotal->greaterThan($grandTotal)) {
                $grandTotal = $hintTotal;
            }

            // exceedsCap() rounds both sides to the ORDER CURRENCY'S scale
            // first (Alfa admits Currency::SCALE_MIN..SCALE_MAX), so paying
            // the exact remainder is never rejected on a decimal(24,8)
            // accumulation artefact.
            if (self::exceedsCap([$alreadyRecorded, $thisPayment], $grandTotal, $orderId)) {
                // Both figures at the order's own scale: at two places a refusal
                // on the last minor units of an 8-place total cannot be read.
                self::warn(sprintf(
                    'create(): payment of %s would exceed order #%d total: %s of %s already recorded',
                    PublishedDecimal::money(money: $thisPayment),
                    $orderId,
                    PublishedDecimal::money(money: self::money($alreadyRecorded, $orderId)),
                    PublishedDecimal::money(money: $grandTotal),
                ));

                return false;
            }
        }

        // Over-refund guard (linked)
        // Because a partial refund correctly leaves the original
        // 'completed', the refund action stays available and a second one is
        // reachable; without this, they would just accumulate. Born-voided
        // rows are exempt like the other guards: a declined refund attempt
        // must stay recordable.
        if (
            !$bornVoided
            && ($data['payment_type'] ?? '') === self::TYPE_REFUND
            && (int) ($data['id_refunded_payment'] ?? 0) > 0
        ) {
            $originalId = (int) $data['id_refunded_payment'];
            $original = self::getRaw($originalId);

            if (!$original) {
                self::warn("create(): refund names payment #{$originalId}, which does not exist");
                return false;
            }

            // Only a payment holds money to return; refundPayment() asks the same.
            if ((string) ($original->payment_type ?? '') !== self::TYPE_PAYMENT) {
                self::warn(sprintf('create(): refund names row #%d, which is a %s, not a payment', $originalId, (string) ($original->payment_type ?? '?')));
                return false;
            }

            // One provider refund, one row
            // A reference already on a refund row of this payment is the
            // provider retrying that refund, not a second one. Decided here,
            // under the order lock, because this is the only read two
            // concurrent retries cannot both pass; refundPayment()'s own
            // earlier check only saves the flip.
            $booked = self::refundUnderReference($originalId, (string) ($data['transaction_id'] ?? ''));

            if ($booked !== null) {
                self::warn(sprintf(
                    'create(): refund %s is already recorded against payment #%d as refund row #%d; a provider retry is not a second refund',
                    json_encode((string) $data['transaction_id'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $originalId,
                    (int) $booked->id,
                ));

                return false;
            }

            // Net refunds and net chargebacks: money the bank took back is
            // not in hand to be returned a second time.
            $alreadyRefunded = self::money(self::sumRefundsAgainst($originalId), $orderId)
                ->add(self::money(self::sumChargebacksAgainst($originalId), $orderId));
            $thisRefund = self::money($data['amount'], $orderId)->abs();
            $gross = self::money($original->amount, $orderId)->abs();

            // exceedsCap() rounds both sides to the ORDER CURRENCY'S scale
            // first (Alfa admits Currency::SCALE_MIN..SCALE_MAX), so
            // refunding the exact remainder is never rejected on a
            // decimal(24,8) accumulation artefact. A provider's pending word
            // is kept as it is (providerFact()).
            if (!self::providerFactRow($data) && self::exceedsCap([$alreadyRefunded, $thisRefund], $gross, $orderId)) {
                self::warn(sprintf(
                    'create(): refund of %.2f would exceed payment #%d: %.2f of %.2f already refunded',
                    $thisRefund->getAmount(),
                    $originalId,
                    $alreadyRefunded->getAmount(),
                    $gross->getAmount(),
                ));

                return false;
            }
        }

        // Over-refund guard (order pool)
        // EVERY refund (linked and unlinked alike) must fit the order's
        // refundable pool: settled refunds plus this one may not pass what
        // was ever collected (payments completed OR refunded: a flipped
        // original stays in the pool, that is exactly the money being
        // returned). The linked gross cap above is the tighter extra
        // constraint, not a replacement: without this pool cap an unlinked
        // settled refund leaves a linked one free to return the same money
        // twice.
        if (
            !$bornVoided
            && ($data['payment_type'] ?? '') === self::TYPE_REFUND
            && !self::providerFactRow($data)
        ) {
            $thisRefund = self::money($data['amount'], $orderId)->abs();
            // Wallet credits settling this order's credit notes ARE money
            // already returned; without them a gateway refund of the same
            // credit note pays the customer twice (Model A + link: the
            // credit ledger stays separate, the guard reads across).
            // The candidate is folded INTO the returned total, not added
            // beside it: on this path the wallet money is returned before
            // this refund row exists, so the row is what COVERS it. Add it
            // outside and every full refund of a credit payment is refused.
            $alreadySettled = self::returnedTotal(
                orderId:             $orderId,
                candidateOriginalId: (int) ($data['id_refunded_payment'] ?? 0),
                candidateAmount:     $thisRefund,
            );
            $paidPool = self::sumRefundablePool($orderId);

            if (self::exceedsCap([$alreadySettled], $paidPool, $orderId)) {
                self::warn(sprintf(
                    'create(): refund of %.2f would exceed order #%d refundable total: %.2f of %.2f already refunded',
                    $thisRefund->getAmount(),
                    $orderId,
                    $alreadySettled->getAmount(),
                    $paidPool,
                ));

                return false;
            }

            // A refund START is decided again here, under the lock: refused while
            // the refund block holds, and held to the pool less what the pending
            // provider refunds reserve, and to its payment's remainder less what
            // that payment's own pending provider refunds reserve. Money a
            // provider already moved is not: a reservation never refuses the
            // booking of its own refund.
            if ($refundStart) {
                $refused = self::startRefusalLocked(
                    orderId: $orderId,
                    requested: $thisRefund,
                    settled: $alreadySettled,
                    pool: $paidPool,
                    paymentId: (int) ($data['id_refunded_payment'] ?? 0),
                );

                if ($refused !== null) {
                    self::$startRefused = $refused;
                    self::warn($refused['reason'], 'create()');

                    return false;
                }
            }

            // And the document it names
            // A refund that names a credit document is recorded only against
            // a document of this order that is issued, within the note's own
            // ceiling. The same answer judges every later write of the row.
            $docId = (int) ($data['id_document'] ?? 0);

            if ($docId > 0) {
                $refusal = self::refundDocumentRefusal(documentId: $docId, orderId: $orderId, amount: $thisRefund, caller: 'create()');

                if ($refusal !== null) {
                    self::warn($refusal['reason']);

                    return false;
                }
            }
        }

        // A refund inherits the identity of what it refunds
        // Its CLASSIFICATION and its NAME both come from the original, read
        // here and never from the method id. The id cannot answer for a
        // refund: a wallet spend carries the ORDER's method (so its status
        // automation runs), so resolving from it typed a refund to the wallet
        // as that order's gateway (which the order screen and the delete
        // veto then asked about money it never held) and on an order with no
        // method it stayed untyped, which every load of the order reported as
        // "Cannot resolve plugin". A gateway refund resolved from the id was
        // wrong too once the method row had been retyped or deleted.
        //
        // The classification is set UNCONDITIONALLY: a caller's value is
        // ignored exactly as it is for the id-resolved element, because a
        // caller that can name the type can name the wrong one. The name is a
        // label and stays caller-overridable; when the caller gave none it is
        // the original's, before the id-based name below could label a wallet
        // refund with the order's gateway. A born-voided refund skipped the
        // linked guard's read above, so this block reads the original itself.
        //
        // Deliberately NOT id_credit_transaction: that column is the wallet
        // spend a row IS, and ReconciliationFacts reads NULL there as "not a
        // spend". A refund of a spend is not the spend.
        //
        // A caller never names the class: it
        // comes from the linked original, the spend link or the method id,
        // or stays empty.
        unset($data['payment_method_type']);

        // The source a row takes its identity from: the payment a refund or a
        // chargeback takes money back from, or the row a reversal undoes.
        $refunded = null;
        $sourceId = 0;

        if (\in_array($rowType, [self::TYPE_REFUND, self::TYPE_CHARGEBACK], true) && (int) ($data['id_refunded_payment'] ?? 0) > 0) {
            $sourceId = (int) $data['id_refunded_payment'];
        } elseif ($rowType === self::TYPE_REVERSAL) {
            $sourceId = (int) ($data['id_reversed_payment'] ?? 0);
        }

        if ($sourceId > 0 || $rowType === self::TYPE_REVERSAL) {
            $refunded = self::getRaw($sourceId);

            // Whatever the status: a born-voided refund skipped the live
            // guard above, and must not name a payment that does not exist.
            if ($refunded === null) {
                self::warn($rowType === self::TYPE_REVERSAL
                    ? sprintf('create(): reversal names row #%d, which does not exist', $sourceId)
                    : sprintf('create(): %s names payment #%d, which does not exist', $rowType, $sourceId));

                return false;
            }

            if ($rowType !== self::TYPE_REVERSAL && (string) ($refunded->payment_type ?? '') !== self::TYPE_PAYMENT) {
                self::warn(sprintf(
                    'create(): %s names row #%d, which is a %s, not a payment',
                    $rowType,
                    $sourceId,
                    (string) ($refunded->payment_type ?? '?'),
                ));

                return false;
            }
        }

        // A refund returns money its OWN order collected. Linked to another
        // order's payment it would lend that order's classification and
        // spend this order's pool on money this order never took. A fact
        // is bound to its target's order the same way.
        if ($refunded !== null && (int) $refunded->id_order !== $orderId) {
            self::warn($rowType === self::TYPE_REFUND
                ? sprintf(
                    'create(): refund names payment #%d, which belongs to order #%d, not to order #%d',
                    (int) $refunded->id,
                    (int) $refunded->id_order,
                    $orderId,
                )
                : sprintf(
                    'create(): %s names row #%d, which belongs to order #%d, not to order #%d',
                    $rowType,
                    (int) $refunded->id,
                    (int) $refunded->id_order,
                    $orderId,
                ));

            return false;
        }

        if ($refunded !== null && $ledgerFact) {
            // A fact is its target's: class, name and method id, never a caller's.
            $data['payment_method_type'] = (string) ($refunded->payment_method_type ?? '');
            $data['payment_method'] = (string) ($refunded->payment_method ?? '');
            $data['id_payment_method'] = (int) ($refunded->id_payment_method ?? 0);
        } elseif ($refunded !== null) {
            $data['payment_method_type'] = (string) ($refunded->payment_method_type ?? '');

            // An empty name is also what the NOT NULL, NO DEFAULT column turned
            // into a hard INSERT error on a method-0 order, which stranded a
            // successful gateway refund with no row behind it (measured at
            // 165.00). A row that exists has a name to lend.
            if (empty($data['payment_method']) && (string) ($refunded->payment_method ?? '') !== '') {
                $data['payment_method'] = (string) $refunded->payment_method;
            }
        }

        // Wallet money is what the spend link says
        // A payment is store credit because it names the wallet debit that
        // paid it, never because its caller said so: a caller-supplied
        // 'credit' made a gateway payment read as wallet money, so the order
        // screen offered to return it to a wallet it never came from and the
        // delete stopped reporting it as gateway money. The link is checked
        // here, where it is written, with the same test the wallet leg applies
        // before moving money, and one debit pays for one payment row.
        $spendLink = (int) ($data['id_credit_transaction'] ?? 0);

        if ($spendLink > 0) {
            $linkDb = self::db();
            $refusal = null;

            if (($data['payment_type'] ?? self::TYPE_PAYMENT) !== self::TYPE_PAYMENT) {
                $refusal = 'only a payment can be a wallet spend';
            } elseif (self::authenticSpendEntry($spendLink, $orderId, $data['amount']) === null) {
                $refusal = sprintf('wallet entry #%d is not a debit of order #%d for this amount', $spendLink, $orderId);
            } elseif ((int) $linkDb->setQuery(
                'SELECT COUNT(*) FROM ' . $linkDb->quoteName(self::TABLE)
                . ' WHERE ' . $linkDb->quoteName('id_credit_transaction') . ' = ' . $spendLink,
            )->loadResult() > 0) {
                $refusal = sprintf('wallet entry #%d already pays for another payment row', $spendLink);
            }

            if ($refusal !== null) {
                self::warn('create(): ' . $refusal);

                return false;
            }

            $data['payment_method_type'] = self::METHOD_TYPE_CREDIT;
        }

        // Snapshot payment method name (survives method deletion). The name is
        // a LABEL and stays caller-overridable: CustomerCreditLedger writes
        // 'Customer credit' onto a row carrying the order's real method id.
        if (!empty($data['id_payment_method']) && empty($data['payment_method'])) {
            $data['payment_method'] = self::resolveMethodName(
                'Payment',
                (int) $data['id_payment_method'],
            );
        }

        // Snapshot the plugin element. IDENTITY, not a label: resolved from
        // the id, because this is what boots the gateway that can refund this
        // money once the method row is gone. Not for a linked refund or a
        // wallet spend, which took their classification from the ledger above.
        if ($refunded === null
            && $spendLink <= 0
            && !empty($data['id_payment_method'])) {
            $data['payment_method_type'] = self::resolveMethodType(
                'Payment',
                (int) $data['id_payment_method'],
            );
        }

        // Auto-fill server-side defaults
        if (empty($data['added'])) {
            $data['added'] = Factory::getDate('now', 'UTC')->toSql();
        }
        if (empty($data['id_employee'])) {
            $data['id_employee'] = self::getCurrentUserId();
        }
        if (empty($data['id_currency']) && $orderId > 0) {
            $data['id_currency'] = OrderCurrency::idOf(orderId: $orderId);
        }

        // ONE ORDER, ONE CURRENCY: enforced here, where the row is born.
        //
        // The default above only fills a currency the caller OMITTED. A caller
        // that states one explicitly (a plugin, the REST add-payment route, an
        // import) could until now write a payment in another currency against
        // this order, and every reader would then sum it at face value: the
        // facts add the amount straight into the order's currency without ever
        // selecting id_currency, so 100 of a foreign unit became 100 of the
        // order's. That is a conversion at an implied rate of 1: silent, and
        // the one thing the currency contract forbids outright, since Alfa does
        // no FX at all.
        //
        // Refusing it at the write boundary rather than filtering it at read
        // time is deliberate: it keeps a single currency per order true BY
        // CONSTRUCTION, which is what makes the readers' face-value arithmetic
        // correct rather than merely lucky. Measured before adding: of 2057
        // payment rows, zero violate this, so nothing legitimate is being
        // refused.
        if ($orderId > 0 && !empty($data['id_currency'])) {
            $orderCurrencyId = OrderCurrency::idOf(orderId: $orderId);

            if ((int) $data['id_currency'] !== $orderCurrencyId) {
                self::warn(sprintf(
                    'create(): payment currency %d is not order #%d\'s currency %d; an order holds'
                    . ' its money in one currency and Alfa performs no conversion.',
                    (int) $data['id_currency'],
                    $orderId,
                    $orderCurrencyId,
                ));

                return false;
            }
        }

        // THE SCALE THIS PAYMENT'S AMOUNT MEANS, FROZEN WITH IT.
        //
        // A payment is compared against order and document money, and that
        // comparison happens IN a scale. Taking the scale from the currency
        // table at comparison time is what let an edit to decimal_place
        // re-settle a historical order. A payment inherits its order's frozen
        // scale, so the two are always compared in the one scale they were
        // both created under, and no conversion, FX or otherwise, is
        // introduced to do it.
        //
        // array_key_exists, NOT empty(): scale 0 is falsy, and a caller that
        // legitimately states zero decimals must not have it silently
        // re-derived. NULL stays NULL: a pre-v1 order cannot lend a scale it
        // never recorded, and 0 is a real zero-decimal scale.
        if (!\array_key_exists('currency_scale', $data) && $orderId > 0) {
            try {
                $scaleDb = self::db();
                $storedScale = $scaleDb->setQuery(
                    $scaleDb->getQuery(true)
                        ->select($scaleDb->quoteName('currency_scale'))
                        ->from($scaleDb->quoteName('#__alfa_orders'))
                        ->where($scaleDb->quoteName('id') . ' = ' . (int) $orderId),
                )->loadResult();

                $data['currency_scale'] = $storedScale === null ? null : (int) $storedScale;
            } catch (Throwable $e) {
                $data['currency_scale'] = null;
            }
        }

        // Auto-set processed_at for completed payments
        if (($data['transaction_status'] ?? '') === self::STATUS_COMPLETED && empty($data['processed_at'])) {
            $data['processed_at'] = Factory::getDate('now', 'UTC')->toSql();
        }

        // Persist to database
        self::withoutReferenceHash($data);
        $success = self::saveToTable($data);

        if (!$success) {
            return false;
        }

        $newId = (int) $data[self::PK];

        // Domain rule: an order created without a payment method (admin
        // shell orders) adopts the method of its first real payment: the
        // order's frozen method name is the display source for email
        // tokens and documents. Never overwrites an existing choice.
        if (
            !empty($data['id_payment_method'])
            && ($data['payment_type'] ?? 'payment') === 'payment'
            && ($data['payment_method'] ?? '') !== ''
        ) {
            self::adoptMethodOnOrderIfEmpty(
                orderId:    $orderId,
                methodId:   (int) $data['id_payment_method'],
                methodName: (string) $data['payment_method'],
            );
        }

        // The wallet leg is settled by create(), which owns the transaction and
        // so logs the rows it wrote once they are committed.
        //
        // The timeline entry is written by create() AFTER the commit; see
        // logCreatedRow(). Writing it here put a best-effort insert inside the
        // open transaction: a failure would either commit the payment with no
        // record, or (on a lock timeout) roll the payment back while this
        // method still returned its id.

        return $newId;
    }

    /**
     * Write the timeline entry for a committed payment row.
     *
     * Called after the transaction closes: a refund row is money going out and
     * must read as 'payment.refunded', never as an incoming 'payment.added'; one
     * that is not settled yet (pending or authorized, a reservation) reads as
     * 'payment.refund_reserved' (since 2.1.0).
     * Non-fatal by contract: the row is committed, so an entry that cannot be
     * written is logged and never turns a recorded payment into a throw.
     *
     * @param int $paymentId Committed payment row PK.
     * @param int $orderId Order PK.
     *
     *
     * @since  2.0.0
     */
    private static function logCreatedRow(int $paymentId, int $orderId): void
    {
        try {
            self::writeCreatedRowEntry($paymentId, $orderId);
        } catch (Throwable $e) {
            try {
                self::ensureLogger();
                Log::add(
                    sprintf('Payment #%d of order #%d is recorded, but its timeline entry was not written: %s', $paymentId, $orderId, $e->getMessage()),
                    Log::WARNING,
                    'com_alfa.payments',
                );
            } catch (Throwable) {
                // The payment row is the record that matters.
            }
        }
    }

    /**
     * The timeline entry of {@see self::logCreatedRow()}.
     *
     * @param int $paymentId Committed payment row PK.
     * @param int $orderId Order PK.
     *
     *
     * @since  2.0.0
     */
    private static function writeCreatedRowEntry(int $paymentId, int $orderId): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName(self::PK) . ' = ' . $paymentId),
        );
        $row = $db->loadObject();

        if ($row === null) {
            return;
        }

        $methodName = (string) ($row->payment_method ?? '');
        $amount = number_format((float) ($row->amount ?? 0), 2);
        $isRefundRow = ($row->payment_type ?? self::TYPE_PAYMENT) === self::TYPE_REFUND;
        // A refund that is in the books but not settled (a reservation) is not money returned yet.
        $reserving = $isRefundRow && \in_array((string) ($row->transaction_status ?? ''), self::REFUND_RESERVING_STATUSES, true);

        OrderModel::logOrderActivity(
            $orderId,
            $reserving ? 'payment.refund_reserved' : ($isRefundRow ? 'payment.refunded' : 'payment.added'),
            $reserving
                ? "Refund reserved, not completed yet: {$methodName} - {$amount}"
                : ($isRefundRow ? "Refunded: {$methodName} - {$amount}" : "Added payment: {$methodName} - {$amount}"),
            [
                'amount' => (float) ($row->amount ?? 0),
                'payment_method' => $methodName,
                'transaction_status' => $row->transaction_status ?? '',
                'payment_type' => $row->payment_type ?? '',
                'transaction_id' => $row->transaction_id ?? '',
                'refund_type' => $row->refund_type ?? null,
                'refund_reason' => $row->refund_reason ?? null,
                'id_refunded_payment' => $row->id_refunded_payment ?? null,
            ],
            $paymentId,
        );
    }

    /**
     * Update an existing order payment record.
     *
     * Only changed fields are logged. Numeric values are compared as
     * floats to avoid false-positive diffs ("0.000000" vs "0").
     *
     * @param int $id Payment row PK
     * @param array $data Fields to update. 'id_order' is REQUIRED for logging.
     * @param bool $bookkeeping Record the change WITHOUT the merchant-visible
     *                          side effects: status automation and download
     *                          entitlements are skipped, the write, diff and
     *                          activity log are not. See ->bookkeeping().
     * @param ?Closure $payloadMerge fn(): string - the builder's staged
     *                               payload merged over the stored one. Run under
     *                               the order lock, and its result is written with
     *                               the row. Builder-internal: stage payload with
     *                               ->pluginData().
     * @param bool $staff The write of a staff entry point (the payment form's save, the REST settle verb): held to
     *                    the staff guard under the lock ({@see self::staffWriteRefusal()}). Never set by a payment
     *                    plugin or by the ledger's own writes (since 2.1.0).
     *
     * @return bool True on success
     *
     * @throws OrderCurrencyException When the order names no currency: nothing is written.
     *
     * @since  1.0.0
     */
    public static function update(int $id, array $data, bool $bookkeeping = false, ?Closure $payloadMerge = null, bool $staff = false): bool
    {
        if ($id <= 0) {
            self::warn('update(): invalid payment ID');
            return false;
        }

        $orderId = (int) ($data['id_order'] ?? 0);

        if ($orderId <= 0) {
            self::warn('update(): id_order is required for activity logging');
            return false;
        }

        // No payment row of an order that names no currency is written.
        OrderCurrency::of(orderId: $orderId);

        // The ledger decides the class; a caller's value is never read.
        unset($data['payment_method_type']);

        // Force the correct PK into the data array
        $data[self::PK] = $id;

        // Money -> the column's decimal, refusing a currency the order is not in
        if (array_key_exists('amount', $data)) {
            $stored = self::moneyForStorage(value: $data['amount'], orderId: $orderId, field: 'amount');

            if ($stored === false) {
                return false;
            }

            $data['amount'] = $stored;
        }

        $db = self::db();

        // Which order does this row belong to TODAY? An admin may re-point a
        // payment at a different order, which moves money between two pools,
        // so both have to be held. Unlocked read, used only to choose the
        // rows to lock; the authoritative snapshot is taken under them.
        $previousOrderId = (int) (self::getRaw($id)?->id_order ?? 0);

        // The settle path is a pool writer too. A refund born 'pending' costs
        // the pool nothing; flipping it to 'completed' is what actually
        // spends it, and recheckCapsOnUpdate is the same read-then-write
        // create() had. Serializing only create() would leave the pool
        // guarded on one path and open on the other, and a lock only one
        // path takes is not a lock. Same row, same order, same savepoint
        // semantics; see create() for why all three are mandatory.
        $db->transactionStart(true);

        try {
            // Ascending, because two concurrent moves in opposite directions
            // would otherwise take the same two rows in opposite orders.
            if (!self::lockOrders($db, $orderId, $previousOrderId)) {
                TransactionState::rollBackLevel($db);

                return false;
            }

            // Snapshot old values for change detection. Read INSIDE the lock:
            // read before it, a concurrent settle could commit in between and
            // the cap below would be judged against a row that no longer
            // exists in that shape.
            $oldSnapshot = self::getRaw($id);

            // The pre-read that chose the locks raced a concurrent move. Bail
            // rather than write: the pool we would be measuring against is one
            // nobody is holding still.
            if ($oldSnapshot && (int) $oldSnapshot->id_order !== $previousOrderId) {
                self::warn("update(): payment #{$id} moved order while acquiring locks; retry");
                TransactionState::rollBackLevel($db);

                return false;
            }

            // History the ledger may not rewrite: a provider fact, a reversed
            // refund's money, a charged-back payment's money, and a reference
            // another active refund of the payment owns.
            $locked = $oldSnapshot ? self::refuseLockedWrite($oldSnapshot, $data, $orderId) : null;

            if ($locked !== null) {
                self::warn('update(): ' . $locked);
                TransactionState::rollBackLevel($db);

                return false;
            }

            // A payment that live refunds or provider facts name keeps its method (since 2.1.0).
            $methodFixed = $oldSnapshot ? self::methodNamedRefusal(stored: $oldSnapshot, data: $data) : null;

            if ($methodFixed !== null) {
                self::warn($methodFixed, 'update()');
                TransactionState::rollBackLevel($db);

                return false;
            }

            // The staff guard, for the write of a staff entry point only (since 2.1.0).
            $staffRefused = $staff && $oldSnapshot ? self::staffWriteRefusal(stored: $oldSnapshot, data: $data) : null;

            if ($staffRefused !== null) {
                self::warn($staffRefused, 'update()');
                TransactionState::rollBackLevel($db);

                return false;
            }

            // A settled refund is never set back to pending or authorized.
            $backward = $oldSnapshot ? self::settledRefundRefusal(stored: $oldSnapshot, data: $data) : null;

            if ($backward !== null) {
                self::warn($backward, 'update()');
                TransactionState::rollBackLevel($db);

                return false;
            }

            // The payload is read and merged only now, with the order held, so
            // it rides this row's own write: order first, then the row.
            if ($payloadMerge !== null) {
                $data['plugin_data'] = $payloadMerge();
            }

            // Money-cap re-check: the create() guards, re-run against the row
            // this write would produce. Rejected = nothing written.
            if ($oldSnapshot && !self::recheckCapsOnUpdate($id, $orderId, $oldSnapshot, $data)) {
                TransactionState::rollBackLevel($db);

                return false;
            }

            // A refund brought (back) into the books is a refund start, decided here
            // under the lock as create() decides one.
            $startRefused = $oldSnapshot ? self::revivalRefusalLocked(id: $id, orderId: $orderId, stored: $oldSnapshot, data: $data) : null;

            if ($startRefused !== null) {
                self::warn($startRefused['reason'], 'update()');
                TransactionState::rollBackLevel($db);

                return false;
            }

            // The document a refund names, judged as create() judges it.
            $documentRefusal = $oldSnapshot
                ? self::refundDocumentRefusalOnUpdate(id: $id, orderId: $orderId, old: $oldSnapshot, data: $data)
                : null;

            if ($documentRefusal !== null) {
                self::warn($documentRefusal['reason']);
                TransactionState::rollBackLevel($db);

                return false;
            }

            // A wallet spend, and a refund of one, keep their method and name:
            // the popup posts the first method of its list, and the wallet
            // money never passed through that method.
            if ($oldSnapshot && self::keepsWalletLabel($oldSnapshot)) {
                unset($data['id_payment_method'], $data['payment_method']);
            }

            // Re-snapshot when the method CHANGES. The old guard only filled a
            // BLANK name, so the admin subform (which posts a hidden, stale
            // payment_method beside an editable id_payment_method) wrote the
            // previous method's name against the new id.
            if (!empty($data['id_payment_method'])) {
                $movedTo = (int) $data['id_payment_method'];
                // $oldSnapshot is the row read under the lock a few lines up:
                // no second query, and it is the one the caps were judged on.
                $movedFrom = (int) ($oldSnapshot->id_payment_method ?? 0);

                if ($movedFrom !== $movedTo || empty($data['payment_method'])) {
                    $data['payment_method'] = self::resolveMethodName('Payment', $movedTo);
                }

                // The type records the plugin that took the money: it moves with the id only (or fills a row that
                // records none), never with a later edit of the method's own type (since 2.1.0).
                if ($movedFrom !== $movedTo || (string) ($oldSnapshot->payment_method_type ?? '') === '') {
                    $data['payment_method_type'] = self::resolveMethodType('Payment', $movedTo);
                }
            }

            // ...except where the method id never decided the classification:
            // a wallet spend and a linked refund are classified by the ledger.
            $refusal = $oldSnapshot ? self::classifyOwned($data, $oldSnapshot) : null;

            if ($refusal !== null) {
                self::warn('update(): ' . $refusal);
                TransactionState::rollBackLevel($db);

                return false;
            }

            self::withoutReferenceHash($data);
            $success = self::saveToTable($data);

            // INSIDE the transaction, before the commit: a wallet movement
            // that cannot happen must take the status write down with it.
            // failClosed:false - a status flip is allowed to proceed when the
            // order has nothing left to give (the row is leaving the pool
            // anyway); only a REFUND row must fail closed.
            $walletEntries = [];

            if ($success) {
                // A refund turned failed or cancelled re-evaluates its payment
                // first, so the wallet leg reconciles the payment as it now stands.
                if ($oldSnapshot) {
                    self::reevaluateAfterRefundEnded(db: $db, stored: $oldSnapshot, data: $data, orderId: $orderId);
                }

                $walletEntries = self::settleWalletForPayment($id, $orderId, failClosed: false);
            }
        } catch (Throwable $e) {
            TransactionState::rollBackLevel($db, $e);

            throw $e;
        }

        TransactionState::commitLevel($db);

        // The wallet rows' committed lines, after the COMMIT; the rollback path
        // above rethrows before reaching them. Post-commit only when this call
        // owns the transaction: nested in a caller's (any caller holding
        // one), transactionStart(true) was a SAVEPOINT and this commit only
        // released it, as with write()'s own-transaction line.
        foreach ($walletEntries as $entry) {
            \Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger::logCommitted($entry);
        }

        // POST-COMMIT, deliberately. Status automation dispatches to plugins,
        // and third-party code opening its own transaction would implicitly
        // commit ours: the same reason spendOnOrder flips its row outside
        // the lock and DocumentIssuer does its stock effect after committing.
        if ($success && $oldSnapshot) {
            self::applyWriteSideEffects($id, $orderId, $oldSnapshot, $data, $bookkeeping);

            // A linked refund settled (or re-amounted while settled) by this write: its payment is refunded once its
            // settled refunds cover it, as on every other settle path (since 2.1.0).
            self::flipAfterSettle(stored: $oldSnapshot, data: $data, orderId: $orderId);
        }

        return $success;
    }

    /**
     * After a write of a linked refund that leaves it settled and either brought it into a settled status or changed
     * its amount: {@see self::flipIfCovered()} for its payment, the one rule every settle path follows (the payment
     * form, the REST settle verb, a plugin's builder or compare-and-set, the ledger's own verbs). The payment becomes
     * refunded only when its settled refunds cover it, decided under the order's lock, and status_on_refunded fires
     * only on that transition: a repeated save or settle of a refund already settled flips nothing. A settled refund
     * that later fails or is cancelled re-evaluates its payment ({@see self::reevaluateAfterRefundEnded()}).
     *
     * @param object $stored The refund row as read under the lock, before the write.
     * @param array $data The write.
     * @param int $orderId Its order.
     *
     * @since  2.1.0
     */
    private static function flipAfterSettle(object $stored, array $data, int $orderId): void
    {
        $paymentId = (int) ($stored->id_refunded_payment ?? 0);
        $from = (string) ($stored->transaction_status ?? '');
        $to = (string) ($data['transaction_status'] ?? $from);
        $amountMoves = \array_key_exists('amount', $data)
            && self::money($data['amount'] ?? 0, $orderId)->getMinorUnits() !== self::money($stored->amount ?? 0, $orderId)->getMinorUnits();

        if ((string) ($stored->payment_type ?? '') !== self::TYPE_REFUND
            || $paymentId <= 0
            || !\in_array($to, self::REFUND_SETTLED_STATUSES, true)
            || (\in_array($from, self::REFUND_SETTLED_STATUSES, true) && !$amountMoves)) {
            return;
        }

        self::flipIfCovered(paymentId: $paymentId, orderId: $orderId);
    }

    /**
     * Lock one or more order rows FOR UPDATE, in a canonical order.
     *
     * Ascending by id, de-duplicated: when a write touches two orders, two
     * concurrent writes touching the same pair must take them in the same
     * sequence or they deadlock on each other instead of queueing.
     *
     * A missing row is refused rather than skipped: FOR UPDATE on an absent
     * id takes a GAP lock, and two writers aiming at the same absent id
     * deadlock rather than order.
     *
     * @param object $db Database driver (already inside a transaction)
     * @param int ...$orderIds Order PKs; zero and duplicates are ignored
     *
     * @return bool False if any named order does not exist, or if the row
     *              cannot be locked (another writer holds it)
     *
     * @since  2.0.0
     */
    private static function lockOrders(object $db, int ...$orderIds): bool
    {
        $ids = array_values(array_unique(array_filter($orderIds, static fn (int $id): bool => $id > 0)));
        sort($ids);

        foreach ($ids as $id) {
            $db->setQuery(
                'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__alfa_orders')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $id . ' FOR UPDATE',
            );

            try {
                $locked = (int) $db->loadResult();
            } catch (Throwable $e) {
                // ONLY a lock-wait timeout (1205) is an ordinary refusal: it
                // rolls back the statement, leaving the caller's transaction
                // intact to be refused cleanly. A deadlock (1213) rolls back
                // the whole transaction server-side, so reporting it as "busy"
                // would let the caller commit a transaction the server has
                // already discarded, and every other error is a real fault.
                // Both must keep throwing.
                if (MysqlError::number($e) !== 1205) {
                    throw $e;
                }

                self::warn("lockOrders(): order #{$id} is held by another writer: " . $e->getMessage());

                return false;
            }

            if ($locked !== $id) {
                self::warn("lockOrders(): order #{$id} does not exist");

                return false;
            }
        }

        return true;
    }

    /**
     * Compare-and-set update: write ONLY if the row still holds an
     * expected transaction_status.
     *
     * WHY: gateways deliver concurrently; the gateway's webhook and the buyer's
     * return race for the same pending row as the NORMAL case, and a plain
     * update() lets BOTH pass its read-then-write window: double status
     * automation, double emails, double auto-issued documents. A payment
     * may be settled exactly once, so the decision is a single conditional
     * UPDATE carrying "AND transaction_status = :expected", judged by the
     * driver's affected-row count, never by a preceding SELECT.
     * Post-write side effects (diff logging, entitlements, status
     * automation) fire only for the caller that actually flipped the row.
     *
     * The money caps still apply (recheckCapsOnUpdate), and so does the
     * document a refund names, except on a settle: this is the door of a
     * payment plugin, and a refund it moves into settled is the provider
     * confirming money that has already left, so it is written even against
     * a document that no longer stands or a note it pays past its ceiling,
     * and logged CRITICAL and marked for staff once committed. Staff settle
     * through update(), where the same write is refused. Meant for status
     * transitions. A write the row still matches but that changes nothing
     * (an identical value, a payload already stored) is WRITE_OK with no side
     * effects: only a row that left $expectedStatus is a lost race.
     *
     * Builder counterpart: ->expectStatus($status), whose save() returns
     * false for both raced and failed.
     *
     * @param int $id Payment row PK
     * @param array $data Fields to update. 'id_order' is REQUIRED.
     * @param string $expectedStatus transaction_status the row must still hold
     * @param bool $bookkeeping Record the change WITHOUT the
     *                          merchant-visible side effects: status
     *                          automation and download entitlements are
     *                          skipped, the write, diff and activity log
     *                          are not. See ->bookkeeping().
     * @param ?Closure $payloadMerge fn(): string - the builder's staged
     *                               payload merged over the stored one. Run
     *                               under the order lock; its result goes out
     *                               in the same conditional UPDATE, so a raced
     *                               or refused write stores none of it.
     *                               Builder-internal: stage payload with
     *                               ->pluginData().
     * @param ?Closure $precondition fn(): bool - asked under the order lock,
     *                               after the row is read and before anything
     *                               is written. False means what the caller
     *                               decided on no longer holds: nothing is
     *                               written and the answer is WRITE_RACED.
     * @param bool $staff The write of a staff entry point (the REST settle verb writes a payment row through this
     *                    door): held to the staff guard under the lock ({@see self::staffWriteRefusal()}). Never
     *                    set by a payment plugin or by the ledger's own writes (since 2.1.0).
     *
     * @return int WRITE_OK (1) this caller made the change, or the row still
     *             holds $expectedStatus and the write changed nothing;
     *             WRITE_RACED (0) row no longer in $expectedStatus, or the
     *             precondition refused, nothing written;
     *             WRITE_FAILED (-1) rejected or DB error
     *
     * @throws OrderCurrencyException When the order names no currency: nothing is written.
     *
     * @since  2.0.0
     */
    public static function updateIfStatus(
        int $id,
        array $data,
        string $expectedStatus,
        bool $bookkeeping = false,
        ?Closure $payloadMerge = null,
        ?Closure $precondition = null,
        bool $staff = false,
    ): int {
        if ($id <= 0) {
            self::warn('updateIfStatus(): invalid payment ID');
            return self::WRITE_FAILED;
        }

        $orderId = (int) ($data['id_order'] ?? 0);

        if ($orderId <= 0) {
            self::warn('updateIfStatus(): id_order is required for activity logging');
            return self::WRITE_FAILED;
        }

        // No payment row of an order that names no currency is written.
        OrderCurrency::of(orderId: $orderId);

        // The ledger decides the class; a caller's value is never read.
        unset($data['payment_method_type']);

        $db = self::db();

        // See update(): the row may be re-pointed at another order, so the
        // pool it is leaving is locked alongside the one it is joining.
        $previousOrderId = (int) (self::getRaw($id)?->id_order ?? 0);

        // THE COMPARE-AND-SET IS NOT THE POOL LOCK. The conditional UPDATE
        // below makes the flip of THIS row exactly-once, which is all it ever
        // claimed; it says nothing about the ORDER's refundable pool. Two
        // different pending refunds on one order, settled concurrently, each
        // read a pool in which the other is still pending: both fit, both
        // flip, and the order refunds more than it collected. That is the
        // same check-then-act create() closes, so it is closed the same way,
        // on the same row, in the same sequence.
        $db->transactionStart(true);

        try {
            if (!self::lockOrders($db, $orderId, $previousOrderId)) {
                TransactionState::rollBackLevel($db);

                return self::WRITE_FAILED;
            }

            $oldSnapshot = self::getRaw($id);

            if (!$oldSnapshot) {
                self::warn("updateIfStatus(): Payment #{$id} not found");
                TransactionState::rollBackLevel($db);

                return self::WRITE_FAILED;
            }

            if ((int) $oldSnapshot->id_order !== $previousOrderId) {
                self::warn("updateIfStatus(): payment #{$id} moved order while acquiring locks; retry");
                TransactionState::rollBackLevel($db);

                return self::WRITE_FAILED;
            }

            // The caller's own question, asked where no concurrent writer can
            // change the answer before this write lands.
            if ($precondition !== null && $precondition() !== true) {
                TransactionState::rollBackLevel($db);

                return self::WRITE_RACED;
            }

            // See update(): merged with the order held, written by the
            // conditional UPDATE below together with the status.
            if ($payloadMerge !== null) {
                $data['plugin_data'] = $payloadMerge();
            }

            $unreconciled = null;
            $affected = self::updateIfStatusLocked($db, $id, $data, $expectedStatus, $oldSnapshot, $unreconciled, staff: $staff);

            // The driver counts CHANGED rows, so a row that still holds
            // $expectedStatus but already carries these values also answers 0.
            // That is not a lost race: the write matched and had nothing to do.
            $unchanged = $affected === 0 && self::stillHoldsStatus($db, $id, $expectedStatus);

            // Only the caller that actually flipped the row reconciles it,
            // and it does so before the commit; see update(). A refund turned
            // failed or cancelled re-evaluates its payment first.
            $walletEntries = [];

            if ($affected >= 1) {
                self::reevaluateAfterRefundEnded(db: $db, stored: $oldSnapshot, data: $data, orderId: $orderId);
                $walletEntries = self::settleWalletForPayment($id, $orderId, failClosed: false);
            }
        } catch (Throwable $e) {
            TransactionState::rollBackLevel($db, $e);

            throw $e;
        }

        TransactionState::commitLevel($db);

        // After the COMMIT, as in update(), with the same caveat when nested.
        foreach ($walletEntries as $entry) {
            \Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger::logCommitted($entry);
        }

        if ($affected < 0) {
            return self::WRITE_FAILED;
        }

        // Nothing changed, so there is nothing to react to: no side effects.
        if ($unchanged) {
            return self::WRITE_OK;
        }

        if ($affected < 1) {
            // Lost the race: the row left $expectedStatus between the
            // caller's read and this write. Nothing written, no side effects.
            return self::WRITE_RACED;
        }

        // The provider's settle the ledger would have refused: written, and
        // only now, with the row committed, logged and marked for staff.
        if ($unreconciled !== null) {
            self::markUnreconciledSettle(refundId: $id, orderId: $orderId, refusal: $unreconciled);
        }

        // POST-COMMIT: status automation dispatches to plugins, whose own
        // transactions would implicitly commit ours.
        self::applyWriteSideEffects($id, $orderId, $oldSnapshot, $data, $bookkeeping);

        // A linked refund this compare-and-set settled: its payment is refunded once covered (since 2.1.0).
        self::flipAfterSettle(stored: $oldSnapshot, data: $data, orderId: $orderId);

        return self::WRITE_OK;
    }

    /**
     * The compare-and-set write of {@see self::updateIfStatus()}, run under
     * the order row lock.
     *
     * NEVER call this directly: without the lock the cap re-check below is a
     * plain check-then-act again.
     *
     * @param object $db Database driver (inside the locked transaction)
     * @param int $id Payment row PK
     * @param array $data Fields to update; mutated in place so the
     *                    caller's post-commit side effects see the
     *                    normalised values
     * @param string $expectedStatus transaction_status the row must still hold
     * @param object $oldSnapshot Pre-update row, already read under the lock
     * @param ?array $unreconciled Set to the document refusal of a settle the
     *                             provider confirmed, which is written
     *                             anyway; null otherwise
     * @param bool $staff The write of a staff entry point: the staff guard is asked (since 2.1.0)
     *
     * @return int Affected rows, or -1 when a guard refused the write
     *
     * @since  2.0.0
     */
    private static function updateIfStatusLocked(
        object $db,
        int $id,
        array &$data,
        string $expectedStatus,
        object $oldSnapshot,
        ?array &$unreconciled = null,
        bool $staff = false,
    ): int {
        $orderId = (int) ($data['id_order'] ?? 0);

        // Money -> the column's decimal, refusing a currency the order is not
        // in; as update() does, before the locks read it.
        if (array_key_exists('amount', $data)) {
            $stored = self::moneyForStorage(value: $data['amount'], orderId: $orderId, field: 'amount');

            if ($stored === false) {
                return -1;
            }

            $data['amount'] = $stored;
        }

        // Same history locks as update().
        $locked = self::refuseLockedWrite($oldSnapshot, $data, $orderId);

        if ($locked !== null) {
            self::warn('updateIfStatus(): ' . $locked);

            return -1;
        }

        // Same method lock as update().
        $methodFixed = self::methodNamedRefusal(stored: $oldSnapshot, data: $data);

        if ($methodFixed !== null) {
            self::warn($methodFixed, 'updateIfStatus()');

            return -1;
        }

        // Same staff guard as update(), for the write of a staff entry point only (since 2.1.0).
        $staffRefused = $staff ? self::staffWriteRefusal(stored: $oldSnapshot, data: $data) : null;

        if ($staffRefused !== null) {
            self::warn($staffRefused, 'updateIfStatus()');

            return -1;
        }

        // Same lifecycle as update(): a settled refund is never set back to pending or
        // authorized. Asked only when the row still holds the expected status: otherwise
        // nothing can be written and the caller is told it lost the race.
        $backward = (string) ($oldSnapshot->transaction_status ?? '') === $expectedStatus
            ? self::settledRefundRefusal(stored: $oldSnapshot, data: $data)
            : null;

        if ($backward !== null) {
            self::warn($backward, 'updateIfStatus()');

            return -1;
        }

        // Same money caps as update(); rejected = nothing written
        if (!self::recheckCapsOnUpdate($id, $orderId, $oldSnapshot, $data)) {
            return -1;
        }

        // Same refund start as update(): a refund brought (back) into the books is
        // decided as create() decides one. Asked only when the row still holds the
        // expected status, as the lifecycle above.
        $startRefused = (string) ($oldSnapshot->transaction_status ?? '') === $expectedStatus
            ? self::revivalRefusalLocked(id: $id, orderId: $orderId, stored: $oldSnapshot, data: $data, providerSettle: true)
            : null;

        if ($startRefused !== null) {
            self::warn($startRefused['reason'], 'updateIfStatus()');

            return -1;
        }

        // Same document judgement as update(), with one exception. A write
        // that moves a refund INTO settled through the compare-and-set is a
        // payment plugin confirming money that has already left: refusing it
        // would leave the books behind the provider. It is written as it is,
        // and updateIfStatus() logs and marks it once the row is committed.
        $documentRefusal = self::refundDocumentRefusalOnUpdate(id: $id, orderId: $orderId, old: $oldSnapshot, data: $data);

        if ($documentRefusal !== null) {
            $settled = [self::STATUS_REFUNDED, self::STATUS_COMPLETED];
            $confirms = \in_array((string) ($data['transaction_status'] ?? ''), $settled, true)
                && !\in_array((string) ($oldSnapshot->transaction_status ?? ''), $settled, true);

            if (!$confirms) {
                self::warn($documentRefusal['reason']);

                return -1;
            }

            $unreconciled = $documentRefusal;
        }

        // Same rule as update(): a wallet row keeps its method and name.
        if (self::keepsWalletLabel($oldSnapshot)) {
            unset($data['id_payment_method'], $data['payment_method']);
        }

        // Same rule as update(): re-snapshot on a method change, and re-resolve
        // a gateway row's type from the id.
        if (!empty($data['id_payment_method'])) {
            $movedTo = (int) $data['id_payment_method'];
            $movedFrom = (int) ($oldSnapshot->id_payment_method ?? 0);

            if ($movedFrom !== $movedTo || empty($data['payment_method'])) {
                $data['payment_method'] = self::resolveMethodName('Payment', $movedTo);
            }

            if ($movedFrom !== $movedTo || (string) ($oldSnapshot->payment_method_type ?? '') === '') {
                $data['payment_method_type'] = self::resolveMethodType('Payment', $movedTo);
            }
        }

        // Same exception as update(): a wallet spend and a linked refund are
        // classified by the ledger, never by the method id.
        $refusal = self::classifyOwned($data, $oldSnapshot);

        if ($refusal !== null) {
            self::warn('updateIfStatus(): ' . $refusal);

            return -1;
        }

        self::withoutReferenceHash($data);
        $columns = array_intersect_key($data, $db->getTableColumns(self::TABLE));
        unset($columns[self::PK]);

        // Same nullable sanitising saveToTable() applies: '' -> NULL
        foreach (self::NULLABLE_INT_COLUMNS as $col) {
            if (array_key_exists($col, $columns) && $columns[$col] === '') {
                $columns[$col] = null;
            }
        }
        foreach (self::NULLABLE_DATE_COLUMNS as $col) {
            if (array_key_exists($col, $columns) && empty($columns[$col])) {
                $columns[$col] = null;
            }
        }
        foreach (self::NULLABLE_ENUM_COLUMNS as $col) {
            if (array_key_exists($col, $columns) && $columns[$col] === '') {
                $columns[$col] = null;
            }
        }

        if (empty($columns)) {
            self::warn('updateIfStatus(): no valid columns after filtering');

            return -1;
        }

        $query = $db->getQuery(true)
            ->update($db->quoteName(self::TABLE));

        foreach ($columns as $col => $value) {
            $query->set(
                $db->quoteName($col) . ' = '
                . ($value === null ? 'NULL' : $db->quote((string) $value)),
            );
        }

        $query
            ->where($db->quoteName(self::PK) . ' = ' . (int) $id)
            ->where($db->quoteName('transaction_status') . ' = ' . $db->quote($expectedStatus));

        try {
            $db->setQuery($query);
            $db->execute();

            return (int) $db->getAffectedRows();
        } catch (Exception $e) {
            if (TransactionState::endedBy($db, $e)) {
                throw $e;
            }

            self::warn('updateIfStatus() failed: ' . $e->getMessage());

            return -1;
        }
    }

    /**
     * Whether a payment row still matches the compare-and-set's own predicate.
     *
     * Asked only after the conditional UPDATE changed nothing, inside the same
     * transaction and as a locking read, so it sees the row as it is now and
     * not as this transaction first read it. The predicate is the UPDATE's,
     * evaluated by the database, so both answer the same question.
     *
     * @param object $db Database driver (inside the locked transaction)
     * @param int $id Payment row PK
     * @param string $expectedStatus transaction_status the write required
     *
     * @return bool True when the row still holds $expectedStatus.
     *
     * @since  2.0.0
     */
    private static function stillHoldsStatus(object $db, int $id, string $expectedStatus): bool
    {
        $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName(self::PK) . ' = ' . $id)
                ->where($db->quoteName('transaction_status') . ' = ' . $db->quote($expectedStatus))
            . ' FOR UPDATE',
        );

        return (int) $db->loadResult() === 1;
    }

    /**
     * Re-derive, through an UPDATE, the classification the ledger owns.
     *
     * The id-based re-resolve update() and updateIfStatus() run is right for a
     * GATEWAY row and wrong for the two kinds of row whose payment_method_type
     * the method id never decided. Both are judged on the row the write WILL
     * produce, not on what was stored, so a classification never outlives
     * what made it:
     *
     *   a wallet spend   (id_credit_transaction) stays credit. Its identity is
     *                    fixed: the link, its order and its being a payment
     *                    are what the wallet leg reconciles against, so a
     *                    write that would change any of them is refused;
     *   a linked refund  keeps the classification it inherited when it was
     *                    written (createLocked()). Its link is written once:
     *                    a re-link, an un-link, a link given to an unlinked
     *                    refund, and a change of type on either side are
     *                    refused. A move to
     *                    another order must follow its original there.
     *
     * Any other row is classified by its method id. A caller never names the
     * class (update() and updateIfStatus() drop a posted one); a non-gateway
     * value left on a row with no spend behind it (a legacy row) is
     * re-derived from its method id: "credit" there offered a refund to a
     * wallet the money never came from.
     *
     * Nothing is added to a write that touches no key able to move the
     * classification, so a plain status flip is unchanged.
     *
     * @param array $data The update payload, after the id-based step; mutated.
     * @param object $stored The row as read under the order lock.
     *
     * @return string|null Why the write is refused, or null when it may proceed.
     *
     * @since  2.0.0
     */
    private static function classifyOwned(array &$data, object $stored): ?string
    {
        $classifying = [
            'id_payment_method' => 1,
            'payment_method_type' => 1,
            'payment_type' => 1,
            'id_refunded_payment' => 1,
            'id_credit_transaction' => 1,
        ];
        $rowId = (int) ($stored->id ?? 0);
        $storedOrder = (int) ($stored->id_order ?? 0);
        $order = (int) ($data['id_order'] ?? $storedOrder);
        $orderMoved = $order !== $storedOrder;

        if (!$orderMoved && array_intersect_key($data, $classifying) === []) {
            return null;
        }

        $storedType = (string) ($stored->payment_method_type ?? '');
        $storedSpend = (int) ($stored->id_credit_transaction ?? 0);
        $type = (string) ($data['payment_type'] ?? '') !== ''
            ? (string) $data['payment_type']
            : (string) ($stored->payment_type ?? self::TYPE_PAYMENT);

        if (\array_key_exists('id_credit_transaction', $data) && (int) $data['id_credit_transaction'] !== $storedSpend) {
            return sprintf('the wallet spend link of payment #%d is written once, by the spend itself', $rowId);
        }

        if ($storedSpend > 0) {
            if ($type !== self::TYPE_PAYMENT || $orderMoved) {
                return sprintf('payment #%d is a wallet spend of order #%d and stays a payment of that order', $rowId, $storedOrder);
            }

            $data['payment_method_type'] = self::METHOD_TYPE_CREDIT;

            return null;
        }

        // THE REFUND LINK IS WRITTEN ONCE. A link counts only on a refund, so
        // the stored and the resulting link are compared as a refund sees them.
        $storedLink = (string) ($stored->payment_type ?? '') === self::TYPE_REFUND
            ? (int) ($stored->id_refunded_payment ?? 0)
            : 0;
        $link = \array_key_exists('id_refunded_payment', $data)
            ? (int) $data['id_refunded_payment']
            : (int) ($stored->id_refunded_payment ?? 0);
        $linkAfter = $type === self::TYPE_REFUND ? $link : 0;
        $linkRefusal = sprintf(
            'refund #%d: the link between a refund and the payment it returns is written once, when the refund is recorded',
            $rowId,
        );

        if ($type === self::TYPE_REFUND) {
            if ($link > 0 && $link === $storedLink && !$orderMoved) {
                $data['payment_method_type'] = $storedType;

                return null;
            }

            $original = $link > 0 ? self::getRaw($link) : null;

            if ($original !== null && (int) $original->id_order !== $order) {
                return sprintf(
                    'refund #%d would name payment #%d, which belongs to order #%d, not to order #%d',
                    $rowId,
                    $link,
                    (int) $original->id_order,
                    $order,
                );
            }

            if ($linkAfter !== $storedLink) {
                return $linkRefusal;
            }

            if ($original !== null) {
                $data['payment_method_type'] = (string) ($original->payment_method_type ?? '');

                return null;
            }
        }

        if ($linkAfter !== $storedLink) {
            return $linkRefusal;
        }

        if (!\array_key_exists('payment_method_type', $data) && \in_array($storedType, self::NON_GATEWAY_TYPES, true)) {
            $methodId = (int) ($data['id_payment_method'] ?? $stored->id_payment_method ?? 0);

            $data['payment_method_type'] = $methodId > 0
                ? self::resolveMethodType('Payment', $methodId)
                : (\in_array($storedType, self::NON_GATEWAY_TYPES, true) ? '' : $storedType);
        }

        return null;
    }

    /**
     * Why a write to a row the ledger holds as history is refused, or null.
     *
     * The rule for provider facts: they are immutable, and the original
     * refund/payment history is never rewritten.
     * Asked under the order lock by update() and updateIfStatus(), before any
     * cap or classification, on the row read under that lock:
     *
     *   1. a reversal or chargeback row is never edited, not even its note;
     *   2. no row becomes one;
     *   3. a reversal link is written once, by the reversal;
     *   4. a refund with a recorded reversal keeps its amount, status, type,
     *      link and order;
     *   5. a payment with a recorded chargeback keeps its amount, type and
     *      order, and stays collected: completed, or refunded only once every
     *      chargeback is won back (the ledger's own flips pass);
     *   6. a linked refund entering, or staying in, a money-counting status
     *      may not carry a reference another active refund of its payment
     *      owns. A failed or cancelled row
     *      reserves nothing and is exempt;
     *   7. a refund that records a provider refund report, or that a report
     *      was reconciled to, keeps its amount, type, link and order: the
     *      report's outcome stands on it. Its status moves only to failed or
     *      cancelled (since 2.1.0): the one correction a settled refund takes
     *      (a settled refund is never set back to pending or authorized), for a
     *      refund the provider later reports failed; its payment is then
     *      re-evaluated. The kept outcome stays, so the report is
     *      never listed or recorded again.
     *
     * @param object $stored The row as read under the order lock.
     * @param array $data The write.
     * @param int $orderId The order the write names.
     *
     * @return string|null Why the write is refused, or null.
     *
     * @since  2.0.0
     */
    private static function refuseLockedWrite(object $stored, array $data, int $orderId): ?string
    {
        $rowId = (int) ($stored->id ?? 0);
        $storedType = (string) ($stored->payment_type ?? '');

        // 1.
        if (\in_array($storedType, self::FACT_TYPES, true)) {
            return sprintf('row #%d is a %s recorded from the provider; it cannot be edited', $rowId, $storedType);
        }

        // 2.
        $newType = (string) ($data['payment_type'] ?? '');

        if (\in_array($newType, self::FACT_TYPES, true)) {
            return sprintf('row #%d cannot become a %s; only recordReversal() and recordChargeback() write one', $rowId, $newType);
        }

        // 3.
        if (\array_key_exists('id_reversed_payment', $data)
            && (int) $data['id_reversed_payment'] !== (int) ($stored->id_reversed_payment ?? 0)) {
            return sprintf('the reversal link of row #%d is written once, by the reversal itself', $rowId);
        }

        // "Changed" as recheckCapsOnUpdate() reads it: money by minor units at
        // the order's scale, everything else by value.
        $amountMoves = \array_key_exists('amount', $data)
            && self::money($data['amount'] ?? 0, $orderId)->getMinorUnits()
                !== self::money($stored->amount ?? 0, $orderId)->getMinorUnits();
        $statusMoves = \array_key_exists('transaction_status', $data)
            && (string) $data['transaction_status'] !== (string) ($stored->transaction_status ?? '');
        $typeMoves = \array_key_exists('payment_type', $data)
            && (string) $data['payment_type'] !== $storedType;
        $linkMoves = \array_key_exists('id_refunded_payment', $data)
            && (int) $data['id_refunded_payment'] !== (int) ($stored->id_refunded_payment ?? 0);
        $orderMoves = \array_key_exists('id_order', $data)
            && (int) $data['id_order'] !== (int) ($stored->id_order ?? 0);

        // 4.
        if ($storedType === self::TYPE_REFUND
            && ($amountMoves || $statusMoves || $typeMoves || $linkMoves || $orderMoves)
            && self::countFactsNaming(column: 'id_reversed_payment', rowId: $rowId, type: self::TYPE_REVERSAL) > 0) {
            return sprintf('refund #%d has a recorded reversal: its amount, status, type, link and order are fixed', $rowId);
        }

        // 7. A status move to failed or cancelled is the one correction a settled
        // refund takes; every other change of the row stays refused.
        $correction = $statusMoves && \in_array((string) $data['transaction_status'], [self::STATUS_FAILED, self::STATUS_CANCELLED], true);

        if ($storedType === self::TYPE_REFUND
            && ($amountMoves || ($statusMoves && !$correction) || $typeMoves || $linkMoves || $orderMoves)
            && self::linkedToReport(rowId: $rowId)) {
            return sprintf('refund #%d records a provider refund report: its amount, status, type, link and order are fixed', $rowId);
        }

        // 5. 'refunded' is still collected only once no chargeback stands: the
        // ledger flips to it only when refunds alone cover the payment, and a
        // hand-set 'refunded' on money the bank already took back would take
        // the same money out of the books twice.
        $statusTo = (string) ($data['transaction_status'] ?? '');
        $leavesCollected = $statusMoves
            && ($statusTo === self::STATUS_REFUNDED
                ? self::money(amount: self::sumChargebacksAgainst(paymentId: $rowId), orderId: $orderId)->isPositive()
                : $statusTo !== self::STATUS_COMPLETED);

        if ($storedType === self::TYPE_PAYMENT
            && ($amountMoves || $typeMoves || $orderMoves || $leavesCollected)
            && self::countFactsNaming(column: 'id_refunded_payment', rowId: $rowId, type: self::TYPE_CHARGEBACK) > 0) {
            return sprintf('payment #%d has a recorded chargeback: its amount, type and order are fixed and it stays collected', $rowId);
        }

        // 6. Every transition into a money-counting refund state runs the
        // duplicate-reference invariant createLocked() runs for a new row.
        $referenceMoves = \array_key_exists('transaction_id', $data)
            && (string) ($data['transaction_id'] ?? '') !== (string) ($stored->transaction_id ?? '');
        $statusAfter = (string) ($data['transaction_status'] ?? $stored->transaction_status);
        $referenceAfter = \array_key_exists('transaction_id', $data)
            ? (string) ($data['transaction_id'] ?? '')
            : (string) ($stored->transaction_id ?? '');

        if ($storedType === self::TYPE_REFUND
            && (int) ($stored->id_refunded_payment ?? 0) > 0
            && ($statusMoves || $referenceMoves)
            && !\in_array($statusAfter, [self::STATUS_FAILED, self::STATUS_CANCELLED], true)
            && $referenceAfter !== '') {
            $owner = self::rowUnderReference('id_refunded_payment', (int) $stored->id_refunded_payment, self::TYPE_REFUND, $referenceAfter);

            if ($owner !== null && (int) $owner->id !== $rowId) {
                return sprintf(
                    'refund #%d cannot take reference %s: refund row #%d of payment #%d already records it',
                    $rowId,
                    json_encode($referenceAfter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    (int) $owner->id,
                    (int) $stored->id_refunded_payment,
                );
            }
        }

        return null;
    }

    /**
     * Why a write may not change a payment's provider transaction id, or null. A payment its gateway took (its recorded
     * payment_method_type is a gateway's: not empty, not the customer's credit, not the Standard method) keeps the
     * provider's transaction id once it has one: its gateway finds it by that id and by that type, and the method
     * lock ({@see self::gatewayMethodRefusal()}) reads it. Staff may still set the payment pending or cancelled. A clause
     * of the staff guard ({@see self::staffWriteRefusal()}): asked for the write of a staff entry point only, through
     * update() or the compare-and-set, never for a payment plugin's write (since 2.1.0).
     *
     * @param object $stored The row as read under the order lock (id, payment_type, payment_method_type, transaction_id).
     * @param array $data The write.
     *
     * @return string|null The refusal, translated; null when the write may go on.
     *
     * @since  2.1.0
     */
    private static function providerIdRefusal(object $stored, array $data): ?string
    {
        $element = (string) ($stored->payment_method_type ?? '');
        $storedId = (string) ($stored->transaction_id ?? '');

        if ((string) ($stored->payment_type ?? '') !== self::TYPE_PAYMENT || $storedId === '' || !\array_key_exists('transaction_id', $data)
            || (string) ($data['transaction_id'] ?? '') === $storedId
            || $element === '' || $element === self::STANDARD_METHOD || \in_array($element, self::NON_GATEWAY_TYPES, true)) {
            return null;
        }

        return Text::sprintf('COM_ALFA_PAYMENT_PROVIDER_ID_FIXED', (int) ($stored->id ?? 0), $element, $storedId);
    }

    /**
     * Whether a write changes a payment's method: a payment row, a method id given that differs from the stored one,
     * and not a wallet spend (it keeps its method whatever is posted, {@see self::keepsWalletLabel()}).
     *
     * @param object $stored The row as read under the order lock (id, payment_type, id_payment_method, its wallet link).
     * @param array $data The write.
     *
     * @since  2.1.0
     */
    private static function methodMoves(object $stored, array $data): bool
    {
        return (int) ($stored->id ?? 0) > 0
            && (string) ($stored->payment_type ?? '') === self::TYPE_PAYMENT
            && \array_key_exists('id_payment_method', $data)
            && (int) $data['id_payment_method'] !== (int) ($stored->id_payment_method ?? 0)
            && !self::keepsWalletLabel($stored);
    }

    /**
     * Why a write may not change a payment's method, or null: a payment that refunds or provider facts name (a refund
     * not failed or cancelled: settled, reserved, held or kept for staff; a chargeback; a reversal) keeps its method,
     * and so the payment plugin its payment_method_type names: that plugin settles its refunds (it acts only on its
     * own payments), and a refund of a gateway payment keeps its amount and reference for staff while the provider
     * may complete it. Re-methoding the payment would lift both. Asked by update() and updateIfStatus() for every
     * write (since 2.1.0).
     *
     * @param object $stored The row as read under the order lock (id, payment_type, id_payment_method, its wallet link).
     * @param array $data The write.
     *
     * @return string|null The refusal, translated, naming the rows; null when the write may go on.
     *
     * @since  2.1.0
     */
    private static function methodNamedRefusal(object $stored, array $data): ?string
    {
        if (!self::methodMoves(stored: $stored, data: $data)) {
            return null;
        }

        $paymentId = (int) $stored->id;
        $db = self::db();
        $naming = array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName(self::TABLE))
                ->where('(' . $db->quoteName('id_refunded_payment') . ' = ' . $paymentId . ' OR ' . $db->quoteName('id_reversed_payment') . ' = ' . $paymentId . ')')
                ->where($db->quoteName('transaction_status') . ' NOT IN (' . $db->quote(self::STATUS_FAILED) . ', ' . $db->quote(self::STATUS_CANCELLED) . ')')
                ->order($db->quoteName('id') . ' ASC'),
        )->loadColumn() ?: []);

        return $naming !== [] ? Text::sprintf('COM_ALFA_PAYMENT_METHOD_FIXED', $paymentId, '#' . implode(', #', $naming)) : null;
    }

    /**
     * Why a staff write may not change a payment's method, or null (main's rule 1): a payment its gateway collected
     * (its recorded payment_method_type is a gateway's: not empty, not the customer's credit, not the Standard method;
     * and it is completed or refunded, or carries the provider's transaction id) keeps its method at any time: the
     * gateway finds its payments by that type, so a refund the provider makes later still reaches the books. A pending
     * checkout attempt with no provider id may still change its method. A clause of the staff guard
     * ({@see self::staffWriteRefusal()}), since 2.1.0. A payment refunds or provider facts name is answered with
     * {@see self::methodNamedRefusal()}'s sentence, which names them (that rule holds for every write).
     *
     * @param object $stored The row as read (id, payment_type, id_payment_method, payment_method_type, transaction_status, transaction_id).
     * @param array $data The write.
     *
     * @return string|null The refusal, translated; null when the write may go on.
     *
     * @since  2.1.0
     */
    private static function gatewayMethodRefusal(object $stored, array $data): ?string
    {
        if (!self::methodMoves(stored: $stored, data: $data)) {
            return null;
        }

        $named = self::methodNamedRefusal(stored: $stored, data: $data);

        if ($named !== null) {
            return $named;
        }

        $element = (string) ($stored->payment_method_type ?? '');
        $collected = \in_array((string) ($stored->transaction_status ?? ''), [self::STATUS_COMPLETED, self::STATUS_REFUNDED], true)
            || (string) ($stored->transaction_id ?? '') !== '';

        return $collected && $element !== '' && $element !== self::STANDARD_METHOD && !\in_array($element, self::NON_GATEWAY_TYPES, true)
            ? Text::sprintf('COM_ALFA_PAYMENT_METHOD_GATEWAY_FIXED', (int) $stored->id, $element)
            : null;
    }

    /**
     * How many completed fact rows of one type name a row.
     *
     * @param string $column id_reversed_payment (reversals) or id_refunded_payment (chargebacks).
     * @param int $rowId The named row.
     * @param string $type TYPE_REVERSAL or TYPE_CHARGEBACK.
     *
     *
     * @since  2.0.0
     */
    private static function countFactsNaming(string $column, int $rowId, string $type): int
    {
        if ($rowId <= 0) {
            return 0;
        }

        $db = self::db();

        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName($column) . ' = ' . $rowId)
                ->where($db->quoteName('payment_type') . ' = ' . $db->quote($type)),
        )->loadResult();
    }

    /**
     * Whether a stored row keeps its method and name through any write.
     *
     * Exactly "a spend and a refund of one":
     * a wallet spend, or a linked refund that inherited the credit class from
     * one. A legacy row merely stored as credit is neither.
     *
     * @param object $stored The row as read under the order lock.
     *
     *
     * @since  2.0.0
     */
    private static function keepsWalletLabel(object $stored): bool
    {
        return (int) ($stored->id_credit_transaction ?? 0) > 0
            || ((string) ($stored->payment_type ?? '') === self::TYPE_REFUND
                && (int) ($stored->id_refunded_payment ?? 0) > 0
                && (string) ($stored->payment_method_type ?? '') === self::METHOD_TYPE_CREDIT);
    }

    /**
     * Drops transaction_id_hash from a write: the database generates it from transaction_id.
     *
     * @param array $data The row data about to be written (by reference).
     *
     *
     * @since  2.0.0
     */
    private static function withoutReferenceHash(array &$data): void
    {
        unset($data['transaction_id_hash']);
    }

    /**
     * Why a write would set a settled refund back to pending or authorized, or null.
     *
     * A refund's status is a lifecycle, not a label: once its money has gone back (refunded
     * or completed) it never becomes unsettled again. A settled refund that did not happen
     * is marked failed or cancelled, which re-evaluates its payment
     * ({@see self::reevaluateAfterRefundEnded()}). Asked under the order's lock by update()
     * and updateIfStatus() on the row read under it, so the payment form, the REST settle
     * verb, the builder and a gateway's compare-and-set are all held to it.
     *
     * @param object $stored The row as read under the order lock.
     * @param array $data The write.
     *
     * @return string|null The refusal, translated, for the administrator; null when the write may go on.
     *
     * @since  2.1.0
     */
    private static function settledRefundRefusal(object $stored, array $data): ?string
    {
        $type = (string) ($stored->payment_type ?? '');
        $from = (string) ($stored->transaction_status ?? '');
        $to = (string) ($data['transaction_status'] ?? $from);

        if (!\in_array($to, self::allStatuses(), true) || \in_array($to, self::statusesFrom(paymentType: $type, status: $from), true)) {
            return null;
        }

        return Text::sprintf(
            'COM_ALFA_PAYMENT_REFUND_SETTLED_FIXED',
            (int) ($stored->id ?? 0),
            Text::_('COM_ALFA_STATUS_' . strtoupper($from)),
            Text::_('COM_ALFA_STATUS_' . strtoupper($to)),
            Text::_('COM_ALFA_STATUS_FAILED'),
            Text::_('COM_ALFA_STATUS_CANCELLED'),
        );
    }

    /**
     * Set a refunded payment back to completed when the refunds still standing no
     * longer cover it: the one re-evaluation of a payment's refunded state.
     *
     * Asked under the order's lock, inside the caller's transaction, wherever money
     * leaves the refunds a payment stands on: a recorded reversal
     * ({@see self::recordReversal()}) and a refund row turning failed or cancelled
     * ({@see self::reevaluateAfterRefundEnded()}). The refunds standing are the one
     * figure the flip to refunded is decided by: the settled refunds recorded
     * against the payment, net of their reversals (sumRefundsAgainst(), which
     * refundTypeFor() and refundableRemainder() read). A chargeback is not a refund
     * and covers nothing. Compare-and-set from refunded, the status alone: no cap,
     * no status automation, no entitlement, no email.
     *
     * @param object $db Database driver (inside the locked transaction).
     * @param int $paymentId The payment the refunds return money from; 0 = none.
     * @param int $orderId Its order, locked by the caller.
     *
     * @return object|null The payment as it was before it was set back; null when it was not.
     *
     * @since  2.1.0
     */
    private static function reopenIfUncovered(object $db, int $paymentId, int $orderId): ?object
    {
        $payment = $paymentId > 0 ? self::getRaw(id: $paymentId) : null;

        if ($payment === null
            || (string) $payment->payment_type !== self::TYPE_PAYMENT
            || (string) $payment->transaction_status !== self::STATUS_REFUNDED
            || !self::money(amount: self::sumRefundsAgainst(paymentId: $paymentId), orderId: $orderId)
                ->lessThan(self::money(amount: $payment->amount, orderId: $orderId)->abs())) {
            return null;
        }

        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName(self::TABLE))
                ->set($db->quoteName('transaction_status') . ' = ' . $db->quote(self::STATUS_COMPLETED))
                ->where($db->quoteName(self::PK) . ' = ' . $paymentId)
                ->where($db->quoteName('transaction_status') . ' = ' . $db->quote(self::STATUS_REFUNDED)),
        )->execute();

        return (int) $db->getAffectedRows() === 1 ? $payment : null;
    }

    /**
     * Re-evaluate the payment a refund returns money from, when a write turns that
     * refund failed or cancelled.
     *
     * A refund that failed or was cancelled returned nothing, so a payment it had
     * marked refunded may no longer be justified: it is set back to completed when
     * the refunds still standing no longer cover it ({@see self::reopenIfUncovered()}),
     * and stays refunded while they do. Asked by update() and updateIfStatus() under
     * the order's lock, after the refund row is written and before the wallet leg,
     * so both commit together. Only a linked refund that leaves a state other than
     * failed or cancelled is asked; a repeat changes nothing and records nothing.
     *
     * The change is recorded on the order's timeline in the same transaction (the
     * write rolls back without it). What the refunded state set off earlier (the
     * order's status, stock, download access, emails) is not reversed: the order
     * screen tells staff to review it ({@see self::refundReevaluations()}).
     *
     * @param object $db Database driver (inside the locked transaction).
     * @param object $stored The refund row as read under the lock, before the write.
     * @param array $data The write.
     * @param int $orderId Its order, locked.
     *
     * @return object|null The payment as it was before it was set back; null when it was not.
     *
     * @throws Throwable When the timeline entry cannot be written: the caller rolls back.
     *
     * @since  2.1.0
     */
    private static function reevaluateAfterRefundEnded(object $db, object $stored, array $data, int $orderId): ?object
    {
        $ended = [self::STATUS_FAILED, self::STATUS_CANCELLED];
        $paymentId = (int) ($stored->id_refunded_payment ?? 0);
        $to = (string) ($data['transaction_status'] ?? '');

        if ((string) ($stored->payment_type ?? '') !== self::TYPE_REFUND
            || $paymentId <= 0
            || \in_array((string) ($stored->transaction_status ?? ''), $ended, true)
            || !\in_array($to, $ended, true)) {
            return null;
        }

        $payment = self::reopenIfUncovered(db: $db, paymentId: $paymentId, orderId: $orderId);

        if ($payment === null) {
            return null;
        }

        $standing = self::money(amount: self::sumRefundsAgainst(paymentId: $paymentId), orderId: $orderId);
        $gross = self::money(amount: $payment->amount, orderId: $orderId)->abs();
        $refundId = (int) ($stored->id ?? 0);

        OrderModel::logOrderActivityStrict(
            orderId:  $orderId,
            event:    self::EVENT_REFUND_REEVALUATED,
            summary:  sprintf(
                'Payment #%d set back from refunded to completed automatically: refund #%d became %s, and the refunds still standing (%s) no longer cover the payment (%s). What the refund set off earlier was not reversed.',
                $paymentId,
                $refundId,
                $to,
                $standing->format(),
                $gross->format(),
            ),
            context:  [
                'id_payment' => $paymentId,
                'id_refund' => $refundId,
                'refund_status' => $to,
                'refunds_standing' => PublishedDecimal::money(money: $standing),
                'payment_amount' => PublishedDecimal::money(money: $gross),
                'changes' => ['transaction_status' => ['from' => self::STATUS_REFUNDED, 'to' => self::STATUS_COMPLETED]],
            ],
            entityId: $paymentId,
        );

        return $payment;
    }

    //  INTENT VERBS - one call per THING THAT HAPPENED
    //
    //  The CRUD engine above writes a status; these say WHY, and own the
    //  multi-step sequence that WHY implies. They exist because a plugin
    //  given only raw status flips has to drive the state machine by hand,
    //  and every plugin that did so derived it slightly differently: the
    //  differences were the defects (a supersede that emailed the buyer a
    //  cancellation; a refund that, run twice, flipped a refunded payment
    //  back to completed and re-granted its entitlements).
    //
    //  They add NO state machine of their own: the caps live in create(),
    //  the exactly-once decision lives in updateIfStatus(), and these
    //  compose those two.

    /**
     * Close a gateway attempt nobody is ever going to pay.
     *
     * NOT A CANCELLATION, not one the customer or the merchant should ever
     * see. It exists for ONE reason: a pending row reserves its slice of the
     * order total against the overpayment cap (sumRecordedPayments counts
     * every live payment row), so an abandoned attempt left pending blocks
     * the buyer's NEXT attempt from being recorded at all. Superseding it
     * stops the cap counting money nobody will send.
     *
     * The row lands on 'cancelled' because that is the vocabulary the
     * column has for dead, but it is written through the bookkeeping seam,
     * so the method's status_on_cancelled map does NOT fire: no order status
     * change, no cancellation email, no stock release, no entitlement
     * revocation, on an order the buyer may be paying for right now. The
     * write, the diff and the payment.edited activity entry all still
     * happen; the attempt is auditable, it just is not an event.
     *
     * Compare-and-set from PENDING: an attempt that settled while this
     * caller was deciding reports WRITE_RACED and is left exactly as the
     * winner left it.
     *
     * Usage:
     *   $verdict = PaymentLedger::supersedeAttempt($id, 'New gateway visit');
     *   if ($verdict !== PaymentLedger::WRITE_OK) { ...reload, do not sign... }
     *
     * @param int $paymentId Pending attempt row PK
     * @param string $reason Why it was abandoned; stamped into the row's note
     *
     * @return int WRITE_OK (1) this caller closed it;
     *             WRITE_RACED (0) no longer pending, nothing written;
     *             WRITE_FAILED (-1) row missing, rejected or DB error
     *
     * @since  2.0.0
     */
    public static function supersedeAttempt(int $paymentId, string $reason = ''): int
    {
        $row = self::getRaw($paymentId);

        if (!$row) {
            self::warn("supersedeAttempt(): Payment #{$paymentId} not found");

            return self::WRITE_FAILED;
        }

        $orderId = (int) ($row->id_order ?? 0);

        if ($orderId <= 0) {
            self::warn("supersedeAttempt(): Payment #{$paymentId} has no order");

            return self::WRITE_FAILED;
        }

        // The stamp goes FIRST: note is varchar(255), so what gets clipped
        // has to be the older label, never the reason this row died.
        $stamp = $reason !== '' ? 'Superseded: ' . $reason : 'Superseded';
        $existing = trim((string) ($row->note ?? ''));
        $note = $existing !== '' ? $stamp . ' | ' . $existing : $stamp;

        return self::updateIfStatus(
            $paymentId,
            [
                'id_order' => $orderId,
                'transaction_status' => self::STATUS_CANCELLED,
                'note' => mb_substr($note, 0, 255),
            ],
            self::STATUS_PENDING,
            bookkeeping: true,
        );
    }

    /**
     * Refund a payment: the refund row and the payment's refunded state as ONE
     * idempotent operation.
     *
     * WHAT IT OWNS, so no plugin re-derives it:
     *
     *  1. REFUSAL. Only a completed 'payment' row holds money to give back.
     *     A row already 'refunded' reports OUTCOME_ALREADY_REFUNDED and is
     *     NEVER touched; that is the idempotency that stops a re-run (a
     *     double-clicked admin action, a retried job) flipping a refunded
     *     payment back to completed and re-granting the download
     *     entitlements the refund revoked.
     *  2. AMOUNT. Only an OMITTED (null) amount defaults to
     *     refundableRemainder($paymentId): the remainder, never the gross,
     *     because partial refunds may precede (for a new start, less what the
     *     payment's own pending provider refunds reserve; for money a provider
     *     already moved, the remainder the books leave). A zero or negative
     *     Money is refused: a monetary zero never means "no amount was
     *     supplied".
     *  3. FULL vs PARTIAL. Covering the remainder is FULL: once its refund
     *     row is in the books, the original is flipped to 'refunded' (a real,
     *     merchant-visible transition: the status_on_refunded map SHOULD fire
     *     here), when its settled refunds cover it, asked under the order's
     *     lock from the books as they are then (since 2.1.0: never before the
     *     row exists, never from a decision taken before the lock). Anything less is
     *     PARTIAL and the original deliberately stays 'completed', because
     *     total_paid_real counts only completed 'payment' rows: flipping it
     *     would drop the whole amount out of the paid total over a refund of
     *     a few euros and fire the refunded automation across the entire
     *     order (stock release, every mailed download link dead). Refunds
     *     alone decide it: a chargeback is not a refund and never makes one
     *     'full' (see refundTypeFor()).
     *  4. THE AUDIT ROW. A linked 'refund' row carrying the right
     *     refund_type and the ORIGINAL's payment method, not the order's,
     *     which differs on a split-payment order.
     *  5. A REFUSED ROW changes nothing: the payment is flipped only after its
     *     row is written (since 2.1.0), so there is nothing to undo. When the
     *     gateway already moved the money (see 'gateway_moved') and the books
     *     refuse it, the answer is OUTCOME_UNRECONCILED with a CRITICAL
     *     reconcile log.
     *  6. ONE PROVIDER REFUND, ONE ROW. A non-empty 'transaction_id' already
     *     on a refund row of this payment is the provider retrying that
     *     refund: OUTCOME_DUPLICATE, nothing written. Asked first and decided
     *     for good inside create()'s order lock, so two concurrent retries
     *     cannot both book it and a retry never flips the original. The complete reference is compared byte for byte (the column
     *     stores it whole); an empty reference is never de-duplicated, the
     *     same reference on another payment is another refund, and a failed
     *     or cancelled refund row reserves nothing. Money a provider moved
     *     ('gateway_moved') under the reference of a refund row of this
     *     payment that is still pending or authorized (a reservation) settles
     *     that row ({@see self::settleRefund()}, final) instead (since 2.1.0).
     *  7. A NEW START (no 'gateway_moved') waits while the refund block holds
     *     (OUTCOME_BLOCKED) and is held to what the order may still refund
     *     once its pending provider refunds are reserved (refundableNow();
     *     OUTCOME_NOT_REFUNDABLE, naming the reservations), and to what its
     *     own payment has left once that payment's pending provider refunds
     *     are reserved (refundableRemainder()): asked first, and decided
     *     again inside create()'s order lock, from one read of the gateways'
     *     answers each time. Money a provider already moved
     *     ('gateway_moved') is booked against the books alone (since 2.1.0).
     *
     * This calls no gateway. A payment plugin that refunds through its
     * provider does not start with this: it reserves first
     * ({@see self::reserveRefund()}), calls its provider with no lock held,
     * and reports the answer (settleRefund(), holdRefund(), releaseRefund()).
     * This books a refund that moves no provider money (the Standard method,
     * the REST refund verb, the credit refunds) and, with 'gateway_moved',
     * money a provider already moved outside a reservation.
     *
     * Options:
     *   'reason'           string  Human-readable reason (refund_reason)
     *   'transaction_id'   string  Gateway REFUND reference: the provider's id
     *                              for this refund, never the original
     *                              payment's (see 6.)
     *   'gateway_response' string  Raw gateway JSON, for reconciliation
     *   'document'         int     Credit document this refund settles
     *   'gateway_moved'    bool    The money ALREADY left at the gateway:
     *                              booked against the books alone; a row
     *                              they refuse is logged CRITICAL and
     *                              answered OUTCOME_UNRECONCILED. Set it
     *                              whenever the gateway API succeeded
     *                              before this call.
     *   'request_key'      string  With gateway_moved: the key of this site's
     *                              refund request the provider moved it under
     *                              (since 2.1.0). An open reservation under
     *                              the key settles instead; otherwise it is
     *                              money beyond that decision: booked as the
     *                              books decide, kept on a row of its own
     *                              that holds the refund block when they
     *                              refuse it (OUTCOME_UNRECONCILED), and
     *                              surfaced to staff either way. With no
     *                              provider id, one request key is one
     *                              such refund: the same figure again is a
     *                              duplicate, a different one is kept for
     *                              staff (OUTCOME_UNRECONCILED), never
     *                              booked by itself.
     *   'report_id'        string  With gateway_moved: the report the
     *                              gateway lists (or listed) this provider
     *                              refund under in unbookedProviderRefunds(),
     *                              exactly as listed (since 2.1.0). A report
     *                              staff recorded: OUTCOME_DUPLICATE (its
     *                              row). One staff resolved (duplicate or
     *                              invalid, reconciled) with no provider id
     *                              to match: the word comes back once for
     *                              staff, as one matched by its provider id;
     *                              with no provider id and no request_key
     *                              either, OUTCOME_REFUSED, nothing written.
     *   'plugin'           string  The calling payment plugin's element
     *                              (PaymentsPlugin passes it): a start on a
     *                              payment not its own is OUTCOME_REFUSED,
     *                              nothing written; money moved on it
     *                              (gateway_moved) is kept for staff,
     *                              OUTCOME_UNRECONCILED, never refused
     *                              (since 2.1.0).
     *
     * Usage:
     *   $r = PaymentLedger::refundPayment($id, null, ['gateway_moved' => true]);
     *   if ($r['outcome'] !== PaymentLedger::OUTCOME_OK) { ...$r['reason']... }
     *
     * @param int $paymentId Original payment row PK
     * @param ?Money $amount Amount to return; null = the refundable
     *                       remainder. A zero or negative Money is REFUSED
     *                       (OUTCOME_NOT_REFUNDABLE); only absence means
     *                       "everything left". Must be in the ORDER'S
     *                       currency: anything else is refused, not
     *                       converted.
     * @param array $options See above
     *
     * @return array ['outcome' => OUTCOME_*, 'refund_id' => int (0 when no
     *               row was written), 'reason' => string (empty on success)]
     *
     * @since  2.0.0
     */
    public static function refundPayment(int $paymentId, ?Money $amount = null, array $options = []): array
    {
        // Money a provider moved is a provider's word: one transaction under the order's lock, from the first read to
        // the commit (round 8d). A refund start (no gateway_moved) is decided under the lock by create(), as before.
        $framed = (bool) ($options['gateway_moved'] ?? false)
            ? self::inWord(rowId: $paymentId, word: static fn (): array => self::refundPayment(paymentId: $paymentId, amount: $amount, options: $options))
            : null;

        if ($framed !== null) {
            return $framed;
        }

        // Money a provider moved that the books refuse is kept, never refused (since 2.1.0): the outermost call asks
        // once the verb has answered; a call made inside it (money beyond a decision keeps its own) never does.
        if (!self::$keepsRefused) {
            self::$keepsRefused = true;
            self::$writeThrew = false;

            try {
                $result = self::refundPayment(paymentId: $paymentId, amount: $amount, options: $options);
            } finally {
                self::$keepsRefused = false;
            }

            return self::keepRefusedMoney(paymentId: $paymentId, amount: $amount, options: $options, result: $result) ?? $result;
        }

        $nested = TransactionState::isOpen(self::db());
        $original = self::getRaw($paymentId);

        if (!$original) {
            return self::refundResult(
                self::OUTCOME_NOT_REFUNDABLE,
                reason: "payment #{$paymentId} does not exist",
            );
        }

        $orderId = (int) ($original->id_order ?? 0);
        $status = (string) ($original->transaction_status ?? '');
        $type = (string) ($original->payment_type ?? '');

        // A payment plugin acts only on its own payments' refunds (its wrapper names it, 'plugin'): a start is refused;
        // money its provider already moved is never refused, it is kept for staff (since 2.1.0).
        $notTheirs = self::pluginRefusal(payment: $original, options: $options);

        if ($notTheirs !== null) {
            return (bool) ($options['gateway_moved'] ?? false)
                ? self::keepNotTheirs(payment: $original, amount: $amount, options: $options, why: $notTheirs, held: false)
                : self::refundResult(outcome: self::OUTCOME_REFUSED, reason: $notTheirs);
        }

        // A refund returns money in the order's currency: none is booked on
        // an order that names no currency.
        try {
            OrderCurrency::of(orderId: $orderId);
        } catch (OrderCurrencyException $e) {
            return self::refundResult(self::OUTCOME_NOT_REFUNDABLE, reason: $e->getMessage());
        }

        // Money moved that the report its gateway listed it under names, and staff recorded that report: it is in
        // the books already (since 2.1.0).
        $recorded = (bool) ($options['gateway_moved'] ?? false) ? self::recordedReport(payment: $original, options: $options) : null;

        if ($recorded !== null) {
            return $recorded;
        }

        // Money a provider moved under one of this site's refund requests that no open reservation of it covers
        // (its key given): booked as the books decide, kept and blocking when they refuse it, and surfaced to
        // staff either way (since 2.1.0).
        $requestKey = (bool) ($options['gateway_moved'] ?? false) ? trim((string) ($options['request_key'] ?? '')) : '';

        if ($requestKey !== '') {
            return self::beyondDecision(original: $original, amount: $amount, requestKey: $requestKey, options: $options);
        }

        // Money moved with no provider id and no request key that the books refused before was kept on a row of its own
        // (keepRefusedMoney()): the same word again is that row, settled or kept (since 2.1.0).
        if ((bool) ($options['gateway_moved'] ?? false) && !self::$staffWayOut && $amount !== null && (string) ($options['transaction_id'] ?? '') === '') {
            $keptBare = self::refundUnderReference($paymentId, self::bareReference(paymentId: $paymentId, amount: $amount));

            if ($keptBare !== null) {
                return \in_array((string) $keptBare->transaction_status, self::REFUND_RESERVING_STATUSES, true)
                    ? self::settleRefund(reservationId: (int) $keptBare->id, amount: $amount, providerRefundId: null, options: ['final' => true] + array_intersect_key($options, ['gateway_response' => true, 'reason' => true]))
                    : self::refundResult(outcome: self::OUTCOME_DUPLICATE, refundId: (int) $keptBare->id, reason: sprintf('the provider\'s word of %s with no refund id is refund row #%d already', $amount->format(), (int) $keptBare->id));
            }
        }

        // Idempotency first and cheapest: an already-refunded original is a
        // FINISHED operation, not an error and not an invitation to write.
        if ($type === self::TYPE_PAYMENT && $status === self::STATUS_REFUNDED) {
            return self::refundResult(
                self::OUTCOME_ALREADY_REFUNDED,
                reason: "payment #{$paymentId} is already refunded",
            );
        }

        if ($type !== self::TYPE_PAYMENT || $status !== self::STATUS_COMPLETED) {
            return self::refundResult(
                self::OUTCOME_NOT_REFUNDABLE,
                reason: sprintf(
                    'payment #%d is %s/%s; only a completed payment holds money to return',
                    $paymentId,
                    $type !== '' ? $type : '?',
                    $status !== '' ? $status : '?',
                ),
            );
        }

        // A retry is a retry whatever it asks for, so this is asked before any
        // amount is weighed, and above all before a full refund's flip, which
        // a retry must never make. create() repeats the question under the
        // order lock; this one only answers the common case early.
        $reference = (string) ($options['transaction_id'] ?? '');
        $booked = self::refundUnderReference($paymentId, $reference);

        // Money a provider moved under the reference of a refund the books hold
        // unsettled (a reservation: pending or authorized) is that refund
        // settling: the row becomes the booking, in one write, never a second
        // row and never a duplicate. Not under the core's own reference of a
        // request's one refund with no provider id ('beyond:' and the key): a
        // word with no id is judged against that row by its figure (the
        // caller asks again on the duplicate), never settled over it.
        if ($booked !== null
            && (bool) ($options['gateway_moved'] ?? false)
            && !str_starts_with($reference, self::REFUND_BEYOND_PREFIX)
            && \in_array((string) $booked->transaction_status, self::REFUND_RESERVING_STATUSES, true)) {
            return self::settleRefund(
                reservationId: (int) $booked->id,
                amount: $amount ?? self::money($booked->amount, $orderId)->abs(),
                providerRefundId: $reference,
                options: ['final' => true] + array_intersect_key($options, ['gateway_response' => true, 'reason' => true]),
            );
        }

        if ($booked !== null) {
            return self::refundResult(
                self::OUTCOME_DUPLICATE,
                reason: self::duplicateReason($paymentId, $reference, $booked),
            );
        }

        // A provider refund staff resolved through the way out (any report: the core's or one its gateway listed,
        // matched by the provider id, else by the report the gateway names) is never booked over that resolution:
        // it comes back once as a new report that holds the refund block (since 2.1.0).
        $reportId = (string) ($options['report_id'] ?? '');
        $resolved = (bool) ($options['gateway_moved'] ?? false) && ($reference !== '' || $reportId !== '') ? self::resolvedByStaff(paymentId: $paymentId, providerRefundId: $reference, reportId: $reportId) : null;

        if ($resolved !== null && $reference === '') {
            // No provider id and no request key: nothing identifies this word to keep it once for staff. Never
            // booked over the resolution; staff decided it.
            $line = sprintf('Payment #%d: money moved under report %s, which staff resolved (%s, %s), was passed again with no provider refund id and no request key; it is not booked over that resolution, nothing was written.', $paymentId, $reportId, (string) $resolved->report_id, (string) $resolved->outcome);

            try {
                self::ensureLogger();
                Log::add($line, Log::WARNING, 'com_alfa.payments');
            } catch (Throwable) {
                // The answer below carries the same fact.
            }

            return self::refundResult(outcome: self::OUTCOME_REFUSED, reason: $line);
        }

        if ($resolved !== null) {
            $figure = $amount ?? self::bookedRemainder(original: $original);

            return self::resolutionStands(payment: $original, resolved: $resolved, figure: $figure, named: $reference)
                ?? self::resurface(payment: $original, amount: $figure, providerRefundId: $reference, resolved: $resolved, options: $options);
        }

        // THE REFUND BLOCK. A refund no provider has moved yet starts here, so it
        // waits while a provider has reported refunds on this order that the books
        // do not hold: it could pay the same money twice. Only recording money a
        // provider already moved ('gateway_moved': a gateway booking a refund it
        // made, or one its own check or notification found, and Record this
        // refund) passes; a refund under a reported id without it is a new start.
        // A new start also leaves alone what the pending provider refunds reserve:
        // the block and the reservations come from ONE read of the gateways'
        // answers, so a report that moves from pending to settled meanwhile is
        // counted by one of them, never by neither.
        $gatewayMoved = (bool) ($options['gateway_moved'] ?? false);
        $unbooked = null;

        if (!$gatewayMoved) {
            $unbooked = self::unbookedProviderRefunds(orderId: $orderId);
            $blocked = self::unbookedRefusal(unbooked: $unbooked);

            if ($blocked !== null) {
                return self::refundResult(outcome: self::OUTCOME_BLOCKED, reason: $blocked);
            }
        }

        // Same figure the linked over-refund cap enforces, so nothing this
        // verb accepts can be rejected at write time over refunds that
        // already happened. Guard epsilon: dust below it is exhausted.
        $remainder = self::bookedRemainder(original: $original);

        // Money, so "nothing left" is a question about the currency's smallest
        // unit rather than a hand-picked 0.0001 that means different things on
        // JPY and OMR.
        if (!$remainder->isPositive()) {
            return self::refundResult(
                self::OUTCOME_NOTHING_LEFT,
                reason: sprintf('payment #%d has no refundable remainder left', $paymentId),
            );
        }

        // A ZERO or a NEGATIVE is a caller bug and must never become a full
        // refund: ONLY the ABSENCE of an amount asks for the remainder. Admin
        // input reaches here, and a blank field that collapsed to 0.0 used to
        // return the customer's whole remaining balance and fire the order's
        // refunded automation with it.
        if ($amount !== null && !$amount->isPositive()) {
            return self::refundResult(
                self::OUTCOME_NOT_REFUNDABLE,
                reason: $amount->isNegative()
                    ? 'a refund amount cannot be negative'
                    : 'a refund amount cannot be zero; omit the amount to refund the remainder',
            );
        }

        // Currency is checked HERE, before any Money comparison. Money's own
        // operators throw on a mismatch, and a refund verb must answer with a
        // refusal a caller can log, not a fatal halfway through the books.
        if ($amount !== null && !$amount->getCurrency()->equals($remainder->getCurrency())) {
            return self::refundResult(
                self::OUTCOME_NOT_REFUNDABLE,
                reason: sprintf(
                    'refund requested in %s, order #%d is in %s; convert before refunding',
                    $amount->getCurrency()->getCode(),
                    $orderId,
                    $remainder->getCurrency()->getCode(),
                ),
            );
        }

        // A new start also leaves alone what this payment's own pending provider
        // refunds reserve (from the same read as the block): its remainder for a
        // start is the books' remainder less them. Money a provider already moved
        // is booked against the books alone.
        $ownReserved = $unbooked === null
            ? Money::zero($remainder->getCurrency())
            : self::reservedTotal(unbooked: self::reservationsOf(unbooked: $unbooked, paymentId: $paymentId), orderId: $orderId);
        $startable = $remainder->subtract($ownReserved)->nonNegative();

        // ONLY null means "the remainder"; a zero was refused above, because a
        // monetary zero must never stand for "no amount was supplied".
        // Everything below is Money in the ORDER'S scale, so the rounding
        // happens once, correctly, instead of at a hard-coded six decimals.
        // With nothing left for a start, the books' remainder is asked and
        // refused below, naming the reservations.
        $requested = $amount ?? ($startable->isPositive() ? $startable : $remainder);

        if ($requested->greaterThan($remainder)) {
            return self::refundResult(
                self::OUTCOME_NOT_REFUNDABLE,
                reason: sprintf(
                    'requested %s, refundable remainder on payment #%d is %s',
                    $requested->format(),
                    $paymentId,
                    $remainder->format(),
                ),
            );
        }

        // Order-level preflight: the pool cap binds every refund, and store
        // credit already returned for this order's credit notes counts as
        // paid out. A new start is also held to what the pending provider
        // refunds leave (read above, with the block); money a provider already
        // moved is booked against the books alone, so a reservation never
        // refuses the booking of its own refund. Checking here makes the
        // refusal a sentence rather than a silent false from create(); the cap
        // itself is still the backstop, and create() decides a start again
        // under the order's lock.
        $pool = self::bookedRefundable(orderId: $orderId);
        $reserved = $unbooked === null ? Money::zero($pool->getCurrency()) : self::reservedTotal(unbooked: $unbooked, orderId: $orderId);
        $left = $pool->subtract($reserved)->nonNegative();

        if ($requested->greaterThan($left)) {
            return self::refundResult(
                self::OUTCOME_NOT_REFUNDABLE,
                reason: $reserved->isPositive()
                    ? self::reservedRefusal(requested: $requested, orderId: $orderId, left: $left, reserved: $reserved, unbooked: $unbooked)
                    : sprintf(
                        'requested %s, order #%d has %s refundable; store credit already returned counts as paid out',
                        $requested->format(),
                        $orderId,
                        $pool->format(),
                    ),
            );
        }

        // And to what this payment has left once its own pending provider refunds
        // are reserved: the provider holds that money for them on this payment.
        $paymentRefusal = $unbooked === null ? null : self::paymentReservedRefusal(paymentId: $paymentId, requested: $requested, unbooked: $unbooked, orderId: $orderId);

        if ($paymentRefusal !== null) {
            return self::refundResult(outcome: self::OUTCOME_NOT_REFUNDABLE, reason: $paymentRefusal);
        }

        // "Full" means the refunds cover the payment, asked as a Money
        // question: the old (remainder - amount) <= 0.0001 called a 1-yen
        // shortfall on a JPY order full, and a 3-decimal OMR order's genuine
        // remainder dust not-full. Refunds alone decide it (refundTypeFor()).
        $isFull = self::refundTypeFor(paymentId: $paymentId, amount: $requested) === self::REFUND_FULL;

        // The row is handed the Money itself, so its column is written the
        // exact decimal of it; the log lines quote this figure. Never
        // reassigned over the $amount parameter: every decision above this
        // line was taken on Money.
        $writeAmount = $requested->getAmount();

        // THE REFUND ROW FIRST, then the payment. The row is decided and written under the
        // order's lock (create()); only once it is in the books is the payment flipped to
        // refunded, and only when its settled refunds cover it, asked under the lock from the
        // books as they are then (flipIfCovered()). A payment is never refunded before the
        // refund row that justifies it exists: a flip committed ahead of its row was visible
        // to every other writer, so a refund ended meanwhile re-evaluated the payment
        // (reevaluateAfterRefundEnded()) back to completed under a full refund, or the
        // flip's undo found it already undone and reported a payment stranded that was not.
        $data = [
            'id_order' => $orderId,
            'payment_type' => self::TYPE_REFUND,
            'transaction_status' => self::STATUS_REFUNDED,
            'amount' => $requested,
            'id_refunded_payment' => $paymentId,
            'refund_type' => $isFull ? self::REFUND_FULL : self::REFUND_PARTIAL,
            // The ORIGINAL's method, never the order's: on a split-payment
            // order the audit row would otherwise name the wrong gateway.
            'id_payment_method' => (int) ($original->id_payment_method ?? 0),
        ];

        if ((string) ($options['reason'] ?? '') !== '') {
            $data['refund_reason'] = (string) $options['reason'];
        }
        if ((string) ($options['transaction_id'] ?? '') !== '') {
            $data['transaction_id'] = (string) $options['transaction_id'];
        }
        if ((string) ($options['gateway_response'] ?? '') !== '') {
            $data['gateway_response'] = (string) $options['gateway_response'];
        }
        if ((int) ($options['document'] ?? 0) > 0) {
            $data['id_document'] = (int) $options['document'];
        }

        // A THROW HERE IS THE SAME EVENT AS A FALSE: create() rolled its own
        // level back, so nothing was written; money a provider already moved
        // is then the reconcile case below, and anything else a refusal.
        $createFailure = null;

        // A new start is decided again by create() under the order's lock; the
        // answer it gives when it refuses one is read below.
        self::$startRefused = null;

        try {
            $refundId = static::create(data: $data, refundStart: !$gatewayMoved);
        } catch (Throwable $e) {
            // A failure that ended the caller's transaction is the caller's to
            // hear: the answers below would be given outside it.
            if ($nested && !TransactionState::isOpen(self::db())) {
                if ($gatewayMoved) {
                    self::ensureLogger();
                    Log::add(
                        sprintf(
                            'Payment #%d: gateway refund of %.2f succeeded but the ledger row was not written (%s); reconcile manually.',
                            $paymentId,
                            $writeAmount,
                            $e->getMessage(),
                        ),
                        Log::CRITICAL,
                        'com_alfa.payments',
                    );
                }

                throw $e;
            }

            $refundId = false;
            $createFailure = $e;
            self::$writeThrew = true;
        }

        if ($refundId !== false) {
            // A full refund, or one that completes the payment's cover beside a concurrent booking: the payment
            // becomes refunded, a merchant-visible phase (status_on_refunded fires).
            self::flipIfCovered(paymentId: $paymentId, orderId: $orderId);

            return self::refundResult(self::OUTCOME_OK, (int) $refundId, amount: $writeAmount);
        }

        // THE ROW WAS REFUSED, or threw; the payment was never touched.
        // Both arrive here. A refusal names its own reason through warn();
        // an exception's message is carried along, because "the write threw"
        // and "the write declined" need different fixes and the log line is
        // the only place that distinction survives.
        $why = $createFailure === null ? 'rejected' : 'threw: ' . $createFailure->getMessage();

        // A PROVIDER RETRY THAT REACHED THE LOCK SECOND. The early check ran
        // before the lock, so a concurrent booking of the same reference is
        // seen only by create(), which refused this row. Nothing is missing
        // from the books (the row that records this refund exists), so it is
        // no reconcile case: the answer is the duplicate.
        $duplicateOf = $createFailure === null ? self::refundUnderReference($paymentId, $reference) : null;

        // The gateway already paid the customer and the books cannot hold it:
        // the payment stays as the books justify it, and staff are told loudly.
        if ($gatewayMoved && $duplicateOf === null) {
            self::ensureLogger();
            Log::add(
                sprintf(
                    'Payment #%d: gateway refund of %.2f succeeded but the ledger row was %s; reconcile manually.',
                    $paymentId,
                    $writeAmount,
                    $why,
                ),
                Log::CRITICAL,
                'com_alfa.payments',
            );

            return self::refundResult(
                self::OUTCOME_UNRECONCILED,
                reason: sprintf('gateway refund of %.2f is not in the books; reconcile manually', $writeAmount),
            );
        }

        if ($duplicateOf !== null) {
            return self::refundResult(
                self::OUTCOME_DUPLICATE,
                reason: self::duplicateReason($paymentId, $reference, $duplicateOf),
            );
        }

        // The start, decided again under the order's lock, was refused there:
        // the block or the reservations changed while this call waited.
        $refused = self::takeStartRefusal();

        if ($createFailure === null && $refused !== null) {
            return self::refundResult(outcome: $refused['outcome'], reason: $refused['reason']);
        }

        // A start the books' own caps refused under the lock (a concurrent booking took the room after the checks
        // above): the books are asked once more, and the answer is the refusal a start asked now gets, with its
        // sentence, never a failure to retry (since 2.1.0).
        if ($createFailure === null && !$gatewayMoved) {
            $now = self::getRaw(id: $paymentId);
            $remainderNow = $now === null ? Money::zero($requested->getCurrency()) : self::bookedRemainder(original: $now);
            $poolNow = self::bookedRefundable(orderId: $orderId);

            if ($requested->greaterThan($remainderNow)) {
                return self::refundResult(
                    self::OUTCOME_NOT_REFUNDABLE,
                    reason: sprintf('requested %s, refundable remainder on payment #%d is %s', $requested->format(), $paymentId, $remainderNow->format()),
                );
            }

            if ($requested->greaterThan($poolNow)) {
                return self::refundResult(
                    self::OUTCOME_NOT_REFUNDABLE,
                    reason: sprintf('requested %s, order #%d has %s refundable; store credit already returned counts as paid out', $requested->format(), $orderId, $poolNow->format()),
                );
            }
        }

        return self::refundResult(
            self::OUTCOME_FAILED,
            reason: sprintf('the refund row for payment #%d was rejected by the ledger', $paymentId),
        );
    }

    //  THE REFUND RESERVATION (2.1.0)
    //
    //  A refund that a provider must be asked to make is decided and RESERVED
    //  first, under the order's short lock, as a refund row in the books that
    //  is not settled (pending): from its commit every reader of what may
    //  still be refunded counts it (REFUND_RESERVING_STATUSES). Only then is
    //  the provider asked, with no lock held. Its answer moves the same row:
    //  settled books it (settleRefund()), pending keeps it reserved under the
    //  provider's id (holdRefund()), a definite failure releases it
    //  (releaseRefund()). An unknown answer leaves it reserved until a check,
    //  a notification or staff resolve it; it is never released by time.

    /**
     * Decide a refund start and reserve its amount, before any provider is asked.
     *
     * Under the order's lock, in one short transaction committed before this
     * returns: the payment must be a completed payment; a refund row written on
     * it since the decision was drawn refuses it ('mark', OUTCOME_STALE); the
     * refund block refuses it; it is held to the payment's books remainder, to
     * the order's pool less every reservation and to the payment's remainder
     * less its own reservations (the sentences of {@see self::refundPayment()}).
     * Then a refund row in status pending is written (create() decides it once
     * more under the same lock): the reservation. Its reference is
     * REPORT_REQUEST_PREFIX and a key the core makes (a version 4 UUID, never
     * reused), the idempotency key the gateway sends with its request. The
     * reservation never makes its payment refunded; only its settle can. 'full'
     * says it takes everything left to refund on the payment now, net: at least
     * the form's maximum ({@see self::refundFormMaximum()}: the payment's
     * remainder less its own reservations, held to what the order may still
     * refund less every reservation, chargebacks included), decided under the
     * lock from the same figures (round 8d); it is not the payment's coverage
     * and not the row's refund type. A resend is never decided again: it is the
     * same reservation, sent with the same key.
     *
     * A payment from the customer's credit is refunded with refundPayment(),
     * which returns the wallet money in the same step: it is never reserved.
     *
     * Call it outside any open transaction: inside a caller's transaction the
     * reservation commits only with it, so other starts would not see it while
     * the provider is asked.
     *
     * Answers: OUTCOME_RESERVED; or, with nothing written, OUTCOME_STALE, OUTCOME_BLOCKED, OUTCOME_NOT_REFUNDABLE,
     * OUTCOME_NOTHING_LEFT, OUTCOME_ALREADY_REFUNDED, OUTCOME_REFUSED (another plugin's payment), OUTCOME_RACED
     * (the order could not be locked) or OUTCOME_FAILED (the row was refused). A database failure throws, with
     * nothing written (the ledger's transaction rule).
     *
     * @param int $paymentId The completed payment to refund.
     * @param Money|null $amount In the order's currency, above zero, exact at the order's scale;
     *                           null = what may start on the payment (its remainder less its own
     *                           reservations), held to what the order has left once every
     *                           reservation is kept apart: the form's maximum.
     * @param array $options 'mark' (int: RefundMark::of() when the decision was drawn; absent = not
     *                       asked), 'reason' (string, refund_reason), 'document' (int, the credit
     *                       document it settles), 'plugin' (string: the calling payment plugin's
     *                       element; the payment must be one of its own; PaymentsPlugin passes it).
     *
     * @return array{outcome: string, reservation: int, key: string, amount: ?Money, full: bool, reason: string}
     *
     * @since  2.1.0
     */
    public static function reserveRefund(int $paymentId, ?Money $amount = null, array $options = []): array
    {
        $refused = static fn (string $outcome, string $reason): array => [
            'outcome' => $outcome, 'reservation' => 0, 'key' => '', 'amount' => null, 'full' => false, 'reason' => $reason,
        ];
        $original = $paymentId > 0 ? self::getRaw($paymentId) : null;

        if ($original === null) {
            return $refused(self::OUTCOME_NOT_REFUNDABLE, sprintf('payment #%d does not exist', $paymentId));
        }

        $orderId = (int) ($original->id_order ?? 0);

        try {
            $currency = OrderCurrency::of(orderId: $orderId);
        } catch (OrderCurrencyException $e) {
            return $refused(self::OUTCOME_NOT_REFUNDABLE, $e->getMessage());
        }

        if ((string) ($original->payment_method_type ?? '') === self::METHOD_TYPE_CREDIT) {
            return $refused(self::OUTCOME_NOT_REFUNDABLE, sprintf('payment #%d is paid from the customer\'s credit: refund it with refundPayment(), never through a reservation', $paymentId));
        }

        $notTheirs = self::pluginRefusal(payment: $original, options: $options);

        if ($notTheirs !== null) {
            return $refused(self::OUTCOME_REFUSED, $notTheirs);
        }

        $wrongAmount = $amount === null ? null : self::reservationAmountProblem(amount: $amount, currency: $currency);

        if ($wrongAmount !== null) {
            return $refused(self::OUTCOME_NOT_REFUNDABLE, $wrongAmount);
        }

        $key = self::newRequestKey();
        $db = self::db();
        $db->transactionStart(true);

        // The ledger's one transaction rule: a refusal inside the try closes its
        // level and returns at once; the commit comes after the try.
        try {
            if (!self::lockOrders($db, $orderId)) {
                TransactionState::rollBackLevel($db);

                return $refused(self::OUTCOME_RACED, sprintf('order #%d could not be locked; nothing was written', $orderId));
            }

            $decided = self::reserveDecision(paymentId: $paymentId, orderId: $orderId, amount: $amount, options: $options);

            if (isset($decided['refused'])) {
                TransactionState::rollBackLevel($db);

                return $refused($decided['refused']['outcome'], $decided['refused']['reason']);
            }

            $data = [
                'id_order' => $orderId,
                'payment_type' => self::TYPE_REFUND,
                'transaction_status' => self::STATUS_PENDING,
                'amount' => $decided['requested'],
                'id_refunded_payment' => $paymentId,
                'refund_type' => $decided['covers'] ? self::REFUND_FULL : self::REFUND_PARTIAL,
                'id_payment_method' => (int) ($decided['original']->id_payment_method ?? 0),
                'transaction_id' => self::REPORT_REQUEST_PREFIX . $key,
            ];

            if ((string) ($options['reason'] ?? '') !== '') {
                $data['refund_reason'] = mb_substr((string) $options['reason'], 0, 255);
            }

            if ((int) ($options['document'] ?? 0) > 0) {
                $data['id_document'] = (int) $options['document'];
            }

            self::$startRefused = null;
            $reservationId = static::create(data: $data, refundStart: true);

            if ($reservationId === false) {
                $why = self::takeStartRefusal();
                TransactionState::rollBackLevel($db);

                return $refused($why['outcome'] ?? self::OUTCOME_FAILED, $why['reason'] ?? sprintf('the reservation of a refund of payment #%d was refused by the ledger', $paymentId));
            }
        } catch (Throwable $e) {
            TransactionState::rollBackLevel($db, $e);

            throw $e;
        }

        TransactionState::commitLevel($db);

        return [
            'outcome' => self::OUTCOME_RESERVED,
            'reservation' => (int) $reservationId,
            'key' => $key,
            'amount' => $decided['requested'],
            'full' => $decided['full'],
            'reason' => '',
        ];
    }

    /**
     * The decision of {@see self::reserveRefund()}, under the order's lock: the
     * payment as it is now, the mark, the block, the books, the pool and the
     * payment's own reservations, from one read of the reservations.
     *
     * @param int $paymentId The payment.
     * @param int $orderId Its order, locked by the caller.
     * @param Money|null $amount The amount asked, already checked; null = what may start.
     * @param array $options The caller's options ('mark').
     *
     * @return array{refused: array{outcome: string, reason: string}}|array{requested: Money, full: bool, covers: bool, original: object}
     *
     * @since  2.1.0
     */
    private static function reserveDecision(int $paymentId, int $orderId, ?Money $amount, array $options): array
    {
        $original = self::getRaw($paymentId);

        if ($original === null || (int) $original->id_order !== $orderId) {
            return ['refused' => ['outcome' => self::OUTCOME_RACED, 'reason' => sprintf('payment #%d changed while its order was locked; nothing was written', $paymentId)]];
        }

        $type = (string) ($original->payment_type ?? '');
        $status = (string) ($original->transaction_status ?? '');

        if ($type === self::TYPE_PAYMENT && $status === self::STATUS_REFUNDED) {
            return ['refused' => ['outcome' => self::OUTCOME_ALREADY_REFUNDED, 'reason' => sprintf('payment #%d is already refunded', $paymentId)]];
        }

        if ($type !== self::TYPE_PAYMENT || $status !== self::STATUS_COMPLETED) {
            return ['refused' => ['outcome' => self::OUTCOME_NOT_REFUNDABLE, 'reason' => sprintf(
                'payment #%d is %s/%s; only a completed payment holds money to return',
                $paymentId,
                $type !== '' ? $type : '?',
                $status !== '' ? $status : '?',
            )]];
        }

        // The double submit: a refund row written on the payment since the
        // decision was drawn (a reservation moves the mark as a booking does).
        if (\array_key_exists('mark', $options) && $options['mark'] !== null && (int) $options['mark'] !== RefundMark::of(paymentId: $paymentId)) {
            return ['refused' => ['outcome' => self::OUTCOME_STALE, 'reason' => Text::sprintf('COM_ALFA_REFUND_STALE', $paymentId)]];
        }

        $unbooked = self::reservationRead(orderId: $orderId);
        $blocked = self::unbookedRefusal(unbooked: $unbooked);

        if ($blocked !== null) {
            return ['refused' => ['outcome' => self::OUTCOME_BLOCKED, 'reason' => $blocked]];
        }

        $remainder = self::bookedRemainder(original: $original);

        if (!$remainder->isPositive()) {
            return ['refused' => ['outcome' => self::OUTCOME_NOTHING_LEFT, 'reason' => sprintf('payment #%d has no refundable remainder left', $paymentId)]];
        }

        $own = self::reservedTotal(unbooked: self::reservationsOf(unbooked: $unbooked, paymentId: $paymentId), orderId: $orderId);
        $startable = $remainder->subtract($own)->nonNegative();
        $pool = self::bookedRefundable(orderId: $orderId);
        $reserved = self::reservedTotal(unbooked: $unbooked, orderId: $orderId);
        $left = $pool->subtract($reserved)->nonNegative();

        // No amount: what may start on this payment, net of its own reservations, held to what the order
        // has left once every reservation is kept apart (the form's maximum). With nothing left anywhere,
        // the payment's remainder is asked and refused below, naming the reservations.
        $requested = $amount ?? ($startable->isPositive() ? $startable : $remainder);

        if ($amount === null && $left->isPositive() && $requested->greaterThan($left)) {
            $requested = $left;
        }

        if ($requested->greaterThan($remainder)) {
            return ['refused' => ['outcome' => self::OUTCOME_NOT_REFUNDABLE, 'reason' => sprintf(
                'requested %s, refundable remainder on payment #%d is %s',
                $requested->format(),
                $paymentId,
                $remainder->format(),
            )]];
        }

        if ($requested->greaterThan($left)) {
            return ['refused' => ['outcome' => self::OUTCOME_NOT_REFUNDABLE, 'reason' => $reserved->isPositive()
                ? self::reservedRefusal(requested: $requested, orderId: $orderId, left: $left, reserved: $reserved, unbooked: $unbooked)
                : sprintf('requested %s, order #%d has %s refundable; store credit already returned counts as paid out', $requested->format(), $orderId, $pool->format())]];
        }

        $paymentRefusal = self::paymentReservedRefusal(paymentId: $paymentId, requested: $requested, unbooked: $unbooked, orderId: $orderId);

        if ($paymentRefusal !== null) {
            return ['refused' => ['outcome' => self::OUTCOME_NOT_REFUNDABLE, 'reason' => $paymentRefusal]];
        }

        // The row's refund type: it takes all that the payment's refunds may still cover, net of its own other
        // reservations (refunds alone decide it, as refundTypeFor(): a chargeback never makes one full).
        $room = self::money($original->amount, $orderId)->abs()
            ->subtract(self::money(self::sumRefundsAgainst($paymentId), $orderId))
            ->subtract($own);
        // 'full' (main's L17 rule, round 8d): the reservation takes EVERYTHING left to refund on this payment now, net:
        // the form's maximum (refundFormMaximum(): the payment's remainder less its own reservations, held to what the
        // order may still refund less every reservation), from the same figures read here under the lock. Never the
        // payment's coverage (flipIfCovered() keeps its own test) and never the row's refund type.
        $formMaximum = $startable->lessThan($left) ? $startable : $left;

        return [
            'requested' => $requested,
            'full' => !$requested->lessThan($formMaximum),
            'covers' => !$requested->lessThan($room),
            'original' => $original,
        ];
    }

    /**
     * The provider completed a reserved refund: book it against the reservation.
     *
     * Money that moved, at the provider's figure: judged by the books' caps alone,
     * never refused for a reservation (its own or another), never for being above
     * what the reservation holds, never truncated. The whole (one write: the
     * reservation row itself becomes the settled refund at the provider's figure,
     * whatever it held beyond the figure released by that write, the reserved
     * amount kept in the row's audit): 'final'; a figure at or above what it holds;
     * no provider id (the request's one provider refund: its key is its identity, so
     * a resent notice finds it booked); or the id the reservation already carries.
     * Less, not 'final', under a provider id the reservation does not carry yet
     * (several provider refunds under one decision): one transaction writes a settled
     * refund row for this provider refund and lowers the reservation by the same
     * amount. A reservation carries at most one provider refund: under a DIFFERENT
     * id than the one it carries, this refund is booked on its own row beside it,
     * and the reservation keeps its refund, its figure and its id ('final' never
     * releases it). A provider id another refund row of the payment carries names
     * that row: still reserving, the settle books it; booked, the answer is a
     * duplicate. After a booking the payment is flipped to refunded when its settled
     * refunds cover it, asked under the order's lock (status_on_refunded fires).
     *
     * A settle the books refuse (OUTCOME_UNRECONCILED: the money moved and the books
     * cannot hold it) is kept on a row of its own provider refund: the reservation
     * itself for the whole, or a pending row split off it for a part (its figure,
     * its id); that row is marked (EVENT_REFUND_UNRECONCILED) and the core lists its
     * settled report ('reservation:' and the row id), so the order holds the refund
     * block until every such report goes through the way out (Record this refund,
     * reconcile, duplicate or invalid); logged CRITICAL and notified. refund_id names
     * that row: a retried settleRefund() of the same word books it once the books have room. The same keeps the
     * provider's word when the reservation's payment is no longer collected (staff set it pending or cancelled while
     * the provider was asked), whatever the order's other payments hold (since 2.1.0).
     *
     * A later word about the refund core keeps for staff on the row it names (another figure under its provider id,
     * or with no id about the request's one refund it keeps; a settle after a hold) is only added to that row's
     * report: OUTCOME_UNRECONCILED naming the row, nothing booked or changed, and the retry of the row's first word no
     * longer books it once the report shows more than one word ({@see self::keptWordAdded()}; since 2.1.0). Record
     * this refund books the report's latest settled figure on the row itself. A provider refund id the row does not
     * carry (also on a row core keeps with none) is ANOTHER refund: booked or kept on a row of its own beside it,
     * never added to this one ({@see self::anotherRefund()}); except the request's one refund core keeps with no id,
     * named with its id at a figure its report shows: that refund identified, added, and the row takes the id. A
     * provider refund id no row of the payment carries, named on a reservation that is already settled or released, is
     * a new refund too: booked on a row of its own when the books take it, else kept there (round 8c).
     *
     * With no provider id, one request key is one refund (its reservation booked with no provider id, or the first
     * such refund beyond it): the same figure again is a duplicate; a different figure is never booked by itself
     * and never silent: it is kept on a pending row of its own that waits for staff and holds the refund block
     * (OUTCOME_UNRECONCILED): with no provider id that word is named by its figure, so each different figure keeps a
     * row of its own, also when the gateway names one of those rows ({@see self::figureWordElsewhere()}); once staff
     * resolved that refund through the way out, every later word under the key comes back for them the same way,
     * once per figure (since 2.1.0).
     *
     * A provider refund staff resolved through the way out, whatever report it was listed under (the core's or the
     * gateway's own), matched by its provider id or else by the report the gateway names ('report_id'), is never
     * booked over that resolution: it comes back once for staff (OUTCOME_UNRECONCILED; on this reservation while
     * it is still open under its request, else on a row of its own); once staff resolved it again, the same word
     * at the same figure only writes a WARNING line to the payments log (OUTCOME_DUPLICATE). A report staff
     * recorded is a duplicate of the row it booked (since 2.1.0).
     *
     * Answers: OUTCOME_OK (refund_id the booked row); OUTCOME_DUPLICATE (this provider refund is already on a refund
     * row of the payment: nothing written); OUTCOME_UNRECONCILED; OUTCOME_REFUSED (not a reservation, another
     * plugin's, or a figure or id the books cannot take: a retry cannot help); OUTCOME_RACED or OUTCOME_FAILED (a
     * retry may succeed: nothing was written).
     *
     * @param int $reservationId A refund row of a payment, pending or authorized.
     * @param Money $amount The provider's figure, in the order's currency, above zero, exact at its scale.
     * @param string|null $providerRefundId The provider's refund id exactly as given; null when it gives none.
     * @param array $options 'final' (bool, default false), 'gateway_response' (string), 'reason'
     *                       (string), 'processed_at' (UTC Y-m-d H:i:s; absent = now), 'plugin'
     *                       (string: the calling payment plugin's element), 'report_id' (string: the report
     *                       the gateway lists or listed this provider refund under, exactly as listed: a report
     *                       staff recorded is a duplicate of its row; one staff resolved comes back once for
     *                       staff, also with no provider id; since 2.1.0).
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    public static function settleRefund(int $reservationId, Money $amount, ?string $providerRefundId, array $options = []): array
    {
        // One transaction under the order's lock, from the first read to the commit (round 8d).
        $framed = self::inWord(rowId: $reservationId, word: static fn (): array => self::settleRefund(reservationId: $reservationId, amount: $amount, providerRefundId: $providerRefundId, options: $options));

        if ($framed !== null) {
            return $framed;
        }

        $found = self::reservationFor(reservationId: $reservationId, amount: $amount, providerRefundId: $providerRefundId, options: $options);

        if (isset($found['result'])) {
            return $found['result'];
        }

        $row = $found['row'];
        $orderId = (int) $row->id_order;
        $paymentId = (int) $row->id_refunded_payment;
        $held = self::money($row->amount, $orderId)->abs();
        $carried = self::providerIdOf(row: $row);
        $another = self::anotherRefund(row: $row, providerRefundId: $providerRefundId, figure: $amount);

        // Another provider refund (a refund id the row does not carry) is booked or kept on a row of its own below. An
        // id-less word named by its figure at another figure is a word of its own; a later word about the refund core
        // keeps for staff on this row is only added to what staff see (since 2.1.0).
        $added = $another ? null : self::figureWordElsewhere(row: $row, kind: self::REPORT_STATUS_SETTLED, figure: $amount, providerRefundId: $providerRefundId, options: $options)
            ?? self::keptWordAdded(row: $row, kind: self::REPORT_STATUS_SETTLED, figure: $amount, providerRefundId: $providerRefundId);

        if ($added !== null) {
            return $added;
        }

        $waiting = $another ? null : self::waitsForStaff(row: $row);

        if ($waiting !== null) {
            return $waiting;
        }

        // The payment is no longer collected (set pending or cancelled by hand while the provider was asked): the
        // money moved and the order's books do not hold that payment's money, whatever its other payments hold.
        // The provider's word is kept on its own row, unreconciled, and holds the refund block (never booked).
        $uncollected = self::uncollected(paymentId: $paymentId);

        // Another provider refund than the one the row carries (or than none, on a row core keeps for staff): its own row,
        // beside it, booked when the books take it, else kept for staff; never merged into this row (since 2.1.0).
        if ($another) {
            return self::ownRow(row: $row, amount: $amount, providerRefundId: (string) $providerRefundId, options: $options, settled: true, keep: $uncollected);
        }

        $whole = (bool) ($options['final'] ?? false) || !$amount->lessThan($held) || $providerRefundId === null || ($carried !== null && $carried === $providerRefundId);

        if ($uncollected !== null) {
            return $whole
                ? self::unreconciledSettle(row: $row, amount: $amount, providerRefundId: $providerRefundId, why: $uncollected, booksChecked: true)
                : self::refusedPart(row: $row, amount: $amount, providerRefundId: (string) $providerRefundId, why: $uncollected, booksChecked: true);
        }

        if ($whole) {
            return self::settleWhole(row: $row, amount: $amount, providerRefundId: $providerRefundId, options: $options);
        }

        $isFull = self::refundTypeFor(paymentId: $paymentId, amount: $amount) === self::REFUND_FULL;
        $child = [
            'id_order' => $orderId,
            'payment_type' => self::TYPE_REFUND,
            'transaction_status' => self::STATUS_REFUNDED,
            'amount' => $amount,
            'id_refunded_payment' => $paymentId,
            'refund_type' => $isFull ? self::REFUND_FULL : self::REFUND_PARTIAL,
            'id_payment_method' => (int) ($row->id_payment_method ?? 0),
            'processed_at' => (string) ($options['processed_at'] ?? Factory::getDate('now', 'UTC')->toSql()),
        ] + self::outcomeColumns(row: $row, providerRefundId: $providerRefundId, options: $options) + array_filter(['refund_reason' => (string) ($row->refund_reason ?? '')]);
        $split = self::splitReservation(row: $row, amount: $amount, child: $child);

        if ($split['outcome'] === 'whole') {
            return self::settleWhole(row: $split['row'], amount: $amount, providerRefundId: $providerRefundId, options: $options);
        }

        if ($split['outcome'] !== self::OUTCOME_OK) {
            return $split['outcome'] === self::OUTCOME_UNRECONCILED
                ? self::refusedPart(row: $row, amount: $amount, providerRefundId: $providerRefundId, why: $split['reason'])
                : self::refundResult(outcome: $split['outcome'], reason: $split['reason']);
        }

        self::flipIfCovered(paymentId: $paymentId, orderId: $orderId);

        return self::refundResult(outcome: self::OUTCOME_OK, refundId: $split['id'], amount: (float) $amount->getAmount());
    }

    /**
     * The provider accepted a reserved refund but has not completed it: the amount
     * stays reserved, now under the provider's refund id, so the plugin's pending
     * report of it is the row's own and counted once.
     *
     * The provider's figure is held as it is. Below what the reservation holds, not
     * 'final', under a provider id the reservation does not carry yet: a pending
     * refund row for this provider refund is split off in one transaction, and the
     * rest stays reserved under the key. Below otherwise ('final', no provider id: the
     * request's one provider refund, or the id the reservation already carries): the
     * reservation takes the figure (the rest is released). Above: the reservation grows
     * to it in one write (the provider's word, never a start); beyond the ceiling it is
     * kept, refundableNow() falls to zero and every start is refused. Under a DIFFERENT
     * id than the one the reservation carries: that provider refund is held on its own
     * pending row beside it (its figure is then required). When the reservation's payment is no longer collected
     * (staff set it pending or cancelled while the provider was asked) the held row is kept as it is and holds the
     * refund block, reported pending until the payment is collected again: OUTCOME_UNRECONCILED (since 2.1.0). A
     * hold about the refund core keeps for staff on the row it names (a hold after a settle, another figure under the
     * same identity) is only added to that row's report: OUTCOME_UNRECONCILED naming the row, nothing re-figured,
     * re-marked or split ({@see self::keptWordAdded()}; since 2.1.0); a provider refund id the row does not carry is
     * another refund, held on a row of its own ({@see self::anotherRefund()}). A provider refund id no row of the
     * payment carries, named on a reservation that is already settled or released, is a new refund: held on a pending
     * row of its own that waits for staff, listed pending and blocking (its figure is then required; round 8c).
     *
     * Answers: OUTCOME_RESERVED (refund_id the row that now reserves it); OUTCOME_DUPLICATE (already so: nothing
     * written); OUTCOME_UNRECONCILED; OUTCOME_REFUSED; OUTCOME_RACED or OUTCOME_FAILED.
     *
     * @param int $reservationId A refund row of a payment, pending or authorized.
     * @param string|null $providerRefundId The provider's refund id exactly as given; null when it gives none.
     * @param Money|null $amount The provider's figure; null = the whole reservation.
     * @param array $options 'final' (bool, default false), 'gateway_response' (string), 'plugin' (string: the
     *                       calling payment plugin's element), 'report_id' (string: as settleRefund()).
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    public static function holdRefund(int $reservationId, ?string $providerRefundId, ?Money $amount = null, array $options = []): array
    {
        // One transaction under the order's lock, from the first read to the commit (round 8d).
        $framed = self::inWord(rowId: $reservationId, word: static fn (): array => self::holdRefund(reservationId: $reservationId, providerRefundId: $providerRefundId, amount: $amount, options: $options));

        if ($framed !== null) {
            return $framed;
        }

        $found = self::reservationFor(reservationId: $reservationId, amount: $amount, providerRefundId: $providerRefundId, options: $options, holding: true);

        if (isset($found['result'])) {
            return $found['result'];
        }

        $row = $found['row'];
        $another = self::anotherRefund(row: $row, providerRefundId: $providerRefundId, figure: $amount, whole: $amount === null);

        // Another provider refund (a refund id the row does not carry) is held on a row of its own below. An id-less
        // word named by its figure at another figure is a word of its own; a later word about the refund core keeps
        // for staff on this row is only added to what staff see (since 2.1.0).
        $added = $another ? null : self::figureWordElsewhere(row: $row, kind: self::REPORT_STATUS_PENDING, figure: $amount, providerRefundId: $providerRefundId, options: $options)
            ?? self::keptWordAdded(row: $row, kind: self::REPORT_STATUS_PENDING, figure: $amount, providerRefundId: $providerRefundId);

        if ($added !== null) {
            return $added;
        }

        $waiting = $another ? null : self::waitsForStaff(row: $row);

        if ($waiting !== null) {
            return $waiting;
        }

        $result = match (true) {
            !$another => self::holdOn(row: $row, providerRefundId: $providerRefundId, amount: $amount, options: $options),
            $amount === null => self::refundResult(outcome: self::OUTCOME_REFUSED, reason: sprintf('row #%d keeps another provider refund; name the figure the provider holds for %s', (int) $row->id, (string) $providerRefundId)),
            default => self::ownRow(row: $row, amount: $amount, providerRefundId: (string) $providerRefundId, options: $options, settled: false),
        };
        $uncollected = self::uncollected(paymentId: (int) $row->id_refunded_payment);
        $kept = $uncollected !== null && \in_array($result['outcome'], [self::OUTCOME_RESERVED, self::OUTCOME_DUPLICATE], true) && $result['refund_id'] > 0
            ? self::getRaw(id: (int) $result['refund_id'])
            : null;

        // The payment is no longer collected (set pending or cancelled by hand while the provider was asked): the
        // provider's pending word is kept as it is and holds the refund block until staff resolve it.
        return $kept === null
            ? $result
            : self::unreconciledSettle(row: $kept, amount: self::money($kept->amount, (int) $kept->id_order)->abs(), providerRefundId: self::providerIdOf(row: $kept), why: $uncollected, held: true, booksChecked: true);
    }

    /**
     * A later provider word about a word core keeps for staff (since 2.1.0). A word is KEPT while core holds it on a
     * row for staff: a refund row of a payment, pending or authorized, that carries the mark EVENT_REFUND_UNRECONCILED
     * and whose report core lists (a settle or hold kept while its payment was not collected or the books refused it,
     * a word beyond its request or kept after a staff resolution, a part split off a reservation, a reservation that
     * keeps the word, a gateway_moved word the books refused, a word on a payment that is not the gateway's own). Every
     * later provider word about it is only ADDED to what staff see; it is never booked, re-figured, re-marked as
     * another state, released or split by itself:
     *  - the row's own one word again (the same provider id, or with none the same row, request key or report; the
     *    same figure and the same kind; while the row holds that word alone): null, and the caller answers as it did
     *    (a retry: a word the books refused is booked by it once the books have room; the booking door asks again
     *    under the order's lock whether the row still holds that word alone, {@see self::settleWhole()});
     *  - the row's latest word again: nothing is written (OUTCOME_UNRECONCILED naming the row);
     *  - any other figure, a hold after a settle, a settle after a hold, a failure or release the provider reports
     *    about it (releaseRefund(): it never ends a row that keeps a word for staff), or an EARLIER word of the row
     *    again (the provider's latest word is that one now: main's N8-1, arrival order, repeats included): the row's
     *    report records it: it shows every figure and id received, latest first, with its kind, and Record this refund
     *    books the latest settled figure received ({@see self::unreconciledMarks()}); one timeline entry per word
     *    (EVENT_REFUND_WORD_ADDED), and the payments log and the danger notification name the words.
     * Which words are about the row: a word under the provider id it carries, and with no provider id the row's own
     * refund (its request's one refund while core keeps it, however core kept it: {@see self::idlessUnderKey()}). A
     * different provider refund id is a different refund, booked or kept on a row of its own
     * ({@see self::anotherRefund()}), except the request's one refund kept with no provider id named with its id at
     * the same figure (main's N8-2): the row takes that id with the word. A word with no provider id that is named by
     * its figure (kept beyond its request's booked refund, or bare) is about this row only at its figure
     * ({@see self::figureWordElsewhere()}).
     * The answer is then OUTCOME_UNRECONCILED naming the row (refund_id > 0): it stays listed and blocking, and
     * nothing in the books changes. Every decision is taken under the order's lock from the row and its words as they
     * are then (two deliveries of one word write one entry; a row booked, released or no longer kept meanwhile
     * answers null, and the caller answers as it does for such a row); the order not locked within the lock wait
     * answers OUTCOME_FAILED, nothing written, to retry; a write that throws leaves this call with the exception,
     * nothing written (the ledger's one transaction rule). Never asked while staff take a report through the way out.
     *
     * @param object $row The row the word reaches (id, id_order, id_refunded_payment, transaction_status, amount).
     * @param string $kind REPORT_STATUS_SETTLED (a settle: the money moved), REPORT_STATUS_PENDING (a hold), or
     *                     STATUS_FAILED / STATUS_CANCELLED (the provider reports it failed or not made: no figure).
     * @param Money|null $figure The provider's figure; null = the figure the row's report shows (a hold of the whole).
     * @param string|null $providerRefundId The provider's refund id; null when it gives none.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}|null
     *
     * @since  2.1.0
     */
    private static function keptWordAdded(object $row, string $kind, ?Money $figure, ?string $providerRefundId): ?array
    {
        $orderId = (int) $row->id_order;

        if (self::$staffWayOut || !\in_array((string) $row->transaction_status, self::REFUND_RESERVING_STATUSES, true)) {
            return null;
        }

        $paymentId = (int) $row->id_refunded_payment;

        // Not kept: no mark, or a word the provider only held whose payment is collected again (its report is not
        // listed: the row is a reservation as any other).
        if (!self::keepsForStaff(row: $row)) {
            return null;
        }

        $failure = \in_array($kind, [self::STATUS_FAILED, self::STATUS_CANCELLED], true);
        $id = $providerRefundId ?? '';
        // The provider names the refund this row keeps with no provider id with its id (N8-2): the row takes it.
        $takesId = $id !== '' && !$failure && self::providerIdOf(row: $row) === null;
        $report = self::REPORT_RESERVATION_PREFIX . (int) $row->id;
        $named = $id !== '' ? $id : 'no provider id';
        $db = self::db();
        $db->transactionStart(true);

        try {
            if (!self::lockOrders($db, $orderId)) {
                TransactionState::rollBackLevel($db);

                return self::retryLater(rowId: (int) $row->id);
            }

            // The row as it is under the lock: booked, released or no longer kept meanwhile, the caller answers as it
            // does for such a row (R8-2: never a word added to a row that no longer keeps one).
            $now = self::getRaw(id: (int) $row->id);
            $mark = $now !== null && \in_array((string) $now->transaction_status, self::REFUND_RESERVING_STATUSES, true) && self::keepsForStaff(row: $now)
                ? (self::unreconciledMarks(orderId: $orderId)[(int) $now->id] ?? null)
                : null;

            if ($now === null || $mark === null) {
                TransactionState::rollBackLevel($db);

                return null;
            }

            try {
                $figure ??= self::money($mark['amount'] !== '' ? $mark['amount'] : $now->amount, $orderId)->abs();
            } catch (Throwable) {
                $figure = self::money($now->amount, $orderId)->abs();
            }

            $words = $mark['words'];
            $same = static function (array $word) use ($kind, $id, $figure, $orderId, $failure): bool {
                return self::sameWord(word: $word, kind: $kind, providerRefundId: $id, figure: $failure ? null : $figure, orderId: $orderId);
            };
            $verb = $kind === self::REPORT_STATUS_PENDING ? 'holds' : 'settled';
            $said = $failure ? sprintf('reports %s', $kind === self::STATUS_CANCELLED ? 'not made (cancelled)' : 'failed') : sprintf('%s %s', $verb, $figure->format());

            // The row's own one word again, decided here (R8-2): the caller answers as it did.
            if (!$takesId && \count($words) === 1 && $same($words[0])) {
                TransactionState::rollBackLevel($db);

                return null;
            }

            // The row's latest word again: nothing is written.
            if (!$takesId && $words !== [] && $same($words[0])) {
                TransactionState::rollBackLevel($db);

                return self::refundResult(outcome: self::OUTCOME_UNRECONCILED, refundId: (int) $now->id, reason: sprintf('row #%d keeps this provider refund for staff and its report %s already shows this word as its latest (%s, %s): nothing was written', (int) $now->id, $report, $said, $named));
            }

            // An earlier word of the row again is the provider's latest word now (N8-1: arrival order, repeats included).
            $again = !$takesId && array_filter(\array_slice($words, 1), $same) !== [];

            if ($takesId) {
                $owner = self::refundUnderReference($paymentId, $id);

                // Another row took the id meanwhile: that row is this provider refund; asked again, the call finds it.
                if ($owner !== null && (int) $owner->id !== (int) $now->id) {
                    TransactionState::rollBackLevel($db);

                    return self::refundResult(outcome: self::OUTCOME_RACED, reason: sprintf('provider refund %s is on refund row #%d now; nothing was written: try again', $id, (int) $owner->id));
                }

                $identifiedKey = (string) self::requestKeyOf(reference: (string) ($now->transaction_id ?? ''));

                if (self::updateIfStatus((int) $now->id, ['id_order' => $orderId, 'transaction_id' => $id], (string) $now->transaction_status, bookkeeping: true) !== self::WRITE_OK) {
                    TransactionState::rollBackLevel($db);

                    return self::retryLater(rowId: (int) $now->id);
                }

                self::logIdentified(row: $now, providerRefundId: $id, key: $identifiedKey);
            }

            $summary = sprintf(
                'Refund #%d: the provider %s (%s)%s about the refund this row keeps for staff%s (its words so far, latest first: %s). Nothing is booked, changed or released by it: its report %s shows every word and holds the refund block until staff record it (the latest settled figure received is booked), reconcile it or resolve it as a duplicate or invalid report.',
                (int) $now->id,
                $said,
                $named,
                $again ? ' again, now its latest word' : '',
                $takesId ? sprintf(', which carried no provider refund id: the row carries %s now', $id) : '',
                self::wordsText(words: $words),
                $report,
            );

            OrderModel::logOrderActivityStrict(
                orderId: $orderId,
                event: self::EVENT_REFUND_WORD_ADDED,
                summary: $summary,
                context: [
                    'id_refund' => (int) $now->id,
                    'id_payment' => $paymentId,
                    'provider_refund_id' => $id,
                    'provider_amount' => $failure ? '' : PublishedDecimal::money(money: $figure),
                    'provider_currency' => $failure ? '' : $figure->getCurrency()->getCode(),
                    'kind' => $kind,
                ] + ($again ? ['again' => 1] : []) + ($takesId ? ['identified' => 1] : []),
                entityId: (int) $now->id,
            );
        } catch (Throwable $e) {
            TransactionState::rollBackLevel($db, $e);

            throw $e;
        }

        TransactionState::commitLevel($db);

        try {
            self::ensureLogger();
            Log::add(sprintf('Payment #%d: %s', $paymentId, $summary), Log::WARNING, 'com_alfa.payments');
        } catch (Throwable) {
            // The timeline entry above carries the same fact.
        }

        try {
            self::pushUnreconciled(paymentId: $paymentId, orderId: $orderId, realert: true, message: sprintf('Payment #%d: %s', $paymentId, $summary));
        } catch (Throwable) {
            // The timeline entry and the listing carry the same fact.
        }

        return self::refundResult(outcome: self::OUTCOME_UNRECONCILED, refundId: (int) $now->id, reason: sprintf('the provider %s (%s)%s about the refund row #%d keeps for staff: added to its report %s, which shows every word, latest first, and holds the refund block until staff decide; nothing in the books changed', $said, $named, $again ? ' again, now its latest word,' : '', (int) $now->id, $report));
    }

    /**
     * Whether a provider word is a word a kept row's report shows: the same kind, the same figure (none for a failure
     * the provider reports) and, when the word names a provider id, the same id; a word with no provider id matches a
     * shown word under any id ({@see self::unreconciledMarks()}: one fact once).
     *
     * @param array{kind: string, id: string, amount: string} $word A word of the report.
     * @param string $kind The new word's kind.
     * @param string $providerRefundId The new word's provider id; '' when it gives none.
     * @param Money|null $figure The new word's figure; null for a failure the provider reports.
     * @param int $orderId The order.
     *
     * @since  2.1.0
     */
    private static function sameWord(array $word, string $kind, string $providerRefundId, ?Money $figure, int $orderId): bool
    {
        if ($word['kind'] !== $kind || ($providerRefundId !== '' && $word['id'] !== $providerRefundId)) {
            return false;
        }

        if ($figure === null) {
            return true;
        }

        try {
            return self::money($word['amount'], $orderId)->getMinorUnits() === $figure->getMinorUnits();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The words a kept row's report shows, as one line of plain text, latest first.
     *
     * @param list<array{kind: string, id: string, amount: string, currency: string, at: string}> $words The words.
     *
     * @since  2.1.0
     */
    private static function wordsText(array $words): string
    {
        return implode('; ', array_map(
            static fn (array $word): string => sprintf(
                '%s %s %s (%s) on %s',
                match ($word['kind']) {
                    self::REPORT_STATUS_PENDING => 'held',
                    self::STATUS_FAILED => 'reported failed',
                    self::STATUS_CANCELLED => 'reported not made',
                    default => 'settled',
                },
                $word['amount'],
                $word['currency'],
                $word['id'] !== '' ? $word['id'] : 'no provider id',
                $word['at'],
            ),
            $words,
        ));
    }

    /**
     * Whether a provider word under a refund id is ANOTHER refund than the one a row holds: an id the row does not carry,
     * where the row carries one, or any id where the row is one core keeps for staff with none. A different provider
     * refund id is different money: it is booked or kept on a row of its own, never added to another id's row or
     * answered as its duplicate (main's correction of 20 October). A reservation still open under its request with no
     * provider id takes the request's refund under the provider's id, as before. One exception (main's N8-2, round
     * 8c): the request's ONE refund core keeps with no provider id (its reservation, or 'beyond:' and the key), named
     * with a provider id at a figure its report shows, is that refund being identified, not another one: the word is
     * added to the row, which takes the id ({@see self::keptWordAdded()}); another figure with an id is another refund.
     *
     * @param object $row The row the word names (id, id_order, id_refunded_payment, transaction_status, transaction_id).
     * @param string|null $providerRefundId The provider's refund id; null when it gives none.
     * @param Money|null $figure The word's figure; null when it states none (a failure the provider reports).
     * @param bool $whole A hold with no figure: the figure the row's report states.
     *
     * @since  2.1.0
     */
    private static function anotherRefund(object $row, ?string $providerRefundId, ?Money $figure = null, bool $whole = false): bool
    {
        if ($providerRefundId === null) {
            return false;
        }

        $carried = self::providerIdOf(row: $row);

        if ($carried !== null) {
            return $carried !== $providerRefundId;
        }

        return self::keepsForStaff(row: $row) && !self::requestRefundNamed(row: $row, figure: $figure, whole: $whole);
    }

    /**
     * Whether a word with a provider id at this figure names the request's ONE refund that core keeps on this row with
     * no provider id (main's N8-2): the row is the request's reservation or its 'beyond:' row of the key (never a word
     * named by its figure: 'beyond:', the key, ':' and a figure, or a bare word), and its report shows a settled or
     * held word at that figure (since 2.1.0).
     *
     * @param object $row The kept row (id, id_order, transaction_id, amount).
     * @param Money|null $figure The word's figure; null with $whole = the figure the report states, else none.
     * @param bool $whole A hold with no figure.
     *
     * @since  2.1.0
     */
    private static function requestRefundNamed(object $row, ?Money $figure, bool $whole): bool
    {
        $keyed = self::oneRefundOfKey(reference: (string) ($row->transaction_id ?? ''));
        $mark = $keyed && ($figure !== null || $whole) ? (self::unreconciledMarks(orderId: (int) $row->id_order)[(int) $row->id] ?? null) : null;

        if ($mark === null) {
            return false;
        }

        $orderId = (int) $row->id_order;

        try {
            $minor = ($figure ?? self::money($mark['amount'] !== '' ? $mark['amount'] : $row->amount, $orderId)->abs())->getMinorUnits();
        } catch (Throwable) {
            return false;
        }

        foreach ($mark['words'] as $word) {
            try {
                if (\in_array($word['kind'], [self::REPORT_STATUS_SETTLED, self::REPORT_STATUS_PENDING], true) && self::money($word['amount'], $orderId)->getMinorUnits() === $minor) {
                    return true;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * Whether a refund row's reference is the core's own of a request's ONE refund with no provider id: its reservation
     * (REPORT_REQUEST_PREFIX and the key) or the first such refund beyond it (REFUND_BEYOND_PREFIX and the key); never
     * a word named by its figure (REFUND_BEYOND_PREFIX, the key, ':' and the figure) or a bare word (since 2.1.0).
     *
     * @param string $reference The row's transaction_id.
     *
     * @since  2.1.0
     */
    private static function oneRefundOfKey(string $reference): bool
    {
        return str_starts_with($reference, self::REPORT_REQUEST_PREFIX)
            || (str_starts_with($reference, self::REFUND_BEYOND_PREFIX) && !str_contains(substr($reference, \strlen(self::REFUND_BEYOND_PREFIX)), ':'));
    }

    /**
     * The id given late for the request's one refund BOOKED with no provider id (main's decision of round 8d, as N8-2
     * for a kept one): a word with a provider refund id no row carries, at the booked figure, naming that refund (its
     * reservation, or its request key) is that refund being identified, not another one. The booked row takes the id
     * (a bookkeeping write), the identification is on the order's timeline (EVENT_REFUND_IDENTIFIED), and the answer
     * is OUTCOME_DUPLICATE naming it: nothing is booked twice. Asked inside the word's frame ({@see self::inWord()}).
     *
     * @param object $row The booked row (id, id_order, id_refunded_payment, transaction_status, transaction_id).
     * @param string $providerRefundId The provider's refund id.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    private static function identifyBooked(object $row, string $providerRefundId): array
    {
        $key = (string) self::requestKeyOf(reference: (string) ($row->transaction_id ?? ''));

        if (self::updateIfStatus((int) $row->id, ['id_order' => (int) $row->id_order, 'transaction_id' => $providerRefundId], (string) $row->transaction_status, bookkeeping: true) !== self::WRITE_OK) {
            return self::refundResult(outcome: self::OUTCOME_RACED, reason: sprintf('refund row #%d could not take provider refund %s; nothing was written: try again', (int) $row->id, $providerRefundId));
        }

        self::logIdentified(row: $row, providerRefundId: $providerRefundId, key: $key);

        return self::refundResult(outcome: self::OUTCOME_DUPLICATE, refundId: (int) $row->id, reason: sprintf('provider refund %s is refund row #%d already: the one refund of refund request %s, booked with no provider refund id; it carries that id now and nothing was booked again', $providerRefundId, (int) $row->id, $key));
    }

    /**
     * Write the timeline entry of a row that took the provider's refund id it had none of
     * ({@see self::EVENT_REFUND_IDENTIFIED}), inside the caller's transaction (a failure reaches the caller).
     *
     * @param object $row The row (id, id_order, id_refunded_payment).
     * @param string $providerRefundId The id it took.
     * @param string $key The request's key.
     *
     * @since  2.1.0
     */
    private static function logIdentified(object $row, string $providerRefundId, string $key): void
    {
        OrderModel::logOrderActivityStrict(
            orderId: (int) $row->id_order,
            event: self::EVENT_REFUND_IDENTIFIED,
            summary: sprintf('Refund #%d: the provider named this refund with its refund id %s; it had none (the one refund of refund request %s) and carries that id now.', (int) $row->id, $providerRefundId, $key),
            context: [
                'id_refund' => (int) $row->id,
                'id_payment' => (int) $row->id_refunded_payment,
                'provider_refund_id' => $providerRefundId,
                'request_key' => $key,
            ],
            entityId: (int) $row->id,
        );
    }

    /**
     * Whether core keeps a word for staff on this row ({@see self::keptWordAdded()}): a refund row, pending or
     * authorized, with the mark EVENT_REFUND_UNRECONCILED, whose report core lists (a word the provider only held on a
     * payment collected again is not listed: that row is a reservation as any other).
     *
     * @param object $row The row (id, id_order, id_refunded_payment, transaction_status).
     *
     * @since  2.1.0
     */
    private static function keepsForStaff(object $row): bool
    {
        $mark = \in_array((string) $row->transaction_status, self::REFUND_RESERVING_STATUSES, true)
            ? (self::unreconciledMarks(orderId: (int) $row->id_order)[(int) $row->id] ?? null)
            : null;

        return $mark !== null && !($mark['held'] && !$mark['staff'] && self::uncollected(paymentId: (int) $row->id_refunded_payment) === null);
    }

    /**
     * The answer to a provider word with no refund id that names a row whose word is named by its figure (a word kept
     * beyond its request's booked refund: REFUND_BEYOND_PREFIX, the key, ':' and the figure; a bare word: the bare
     * reference of its payment and figure) at ANOTHER figure, or null. With no provider id such a word's figure is its
     * identity (decision (b): one request key, one refund with no provider id; main's ruling of 20 October): another
     * figure is another word, handled exactly as that word arriving by itself (kept on a row of its own for staff, or
     * for a bare settle booked when the books hold it, as refundPayment() with gateway_moved books it), never added to
     * the row it names, which is untouched. Each such row is resolved on its own through the way out (since 2.1.0).
     *
     * @param object $row The row the word names (id, id_order, id_refunded_payment, transaction_id).
     * @param string $kind REPORT_STATUS_SETTLED or REPORT_STATUS_PENDING.
     * @param Money|null $figure The provider's figure; null = the row's own (no other figure).
     * @param string|null $providerRefundId The provider's refund id; null when it gives none.
     * @param array $options The call's options ('plugin', 'gateway_response', 'report_id').
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}|null
     *
     * @since  2.1.0
     */
    private static function figureWordElsewhere(object $row, string $kind, ?Money $figure, ?string $providerRefundId, array $options): ?array
    {
        $reference = (string) ($row->transaction_id ?? '');
        $named = str_starts_with($reference, self::REFUND_BEYOND_PREFIX) ? substr($reference, \strlen(self::REFUND_BEYOND_PREFIX)) : '';

        if ($providerRefundId !== null || $figure === null || !str_contains($named, ':')) {
            return null;
        }

        $orderId = (int) $row->id_order;

        try {
            if (self::money(substr($named, (int) strrpos($named, ':') + 1), $orderId)->getMinorUnits() === $figure->getMinorUnits()) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $payment = self::getRaw(id: (int) $row->id_refunded_payment);

        if ($payment === null) {
            return null;
        }

        $held = $kind === self::REPORT_STATUS_PENDING;
        $bare = str_starts_with($named, 'bare-');
        $key = $bare ? '' : (string) self::requestKeyOf(reference: $reference);
        $passOn = array_intersect_key($options, ['gateway_response' => true, 'report_id' => true]);
        $notTheirs = self::pluginRefusal(payment: $payment, options: $options);

        if ($notTheirs !== null) {
            return self::keepNotTheirs(payment: $payment, amount: $figure, options: array_filter(['request_key' => $key !== '' ? $key : null]) + $passOn, why: sprintf('row #%d: %s', (int) $row->id, $notTheirs), held: $held);
        }

        if (!$bare) {
            return self::keepForStaff(payment: $payment, key: $key, figure: $figure, options: $passOn, surface: false, held: $held);
        }

        if (!$held) {
            return self::refundPayment(paymentId: (int) $payment->id, amount: $figure, options: ['gateway_moved' => true] + array_intersect_key($options, ['gateway_response' => true, 'plugin' => true, 'report_id' => true]));
        }

        // A held bare word at another figure: kept for staff on a pending row of its own (the bare reference of its figure).
        $own = self::bareReference(paymentId: (int) $payment->id, amount: $figure);
        $existing = self::refundUnderReference((int) $payment->id, $own);

        if ($existing === null) {
            $data = [
                'id_order' => $orderId,
                'payment_type' => self::TYPE_REFUND,
                'transaction_status' => self::STATUS_PENDING,
                'amount' => $figure,
                'id_refunded_payment' => (int) $payment->id,
                'refund_type' => self::REFUND_PARTIAL,
                'id_payment_method' => (int) ($payment->id_payment_method ?? 0),
                'transaction_id' => $own,
            ] + array_filter(['gateway_response' => (string) ($options['gateway_response'] ?? '')]);
            $id = self::providerFact(static fn (): int|false => static::create(data: $data));
            $kept = $id === false ? null : self::getRaw(id: (int) $id);

            if ($kept !== null) {
                return self::unreconciledSettle(row: $kept, amount: $figure, providerRefundId: null, why: sprintf('held with no provider refund id at a figure other than the word on row #%d', (int) $row->id), waitForStaff: true, held: true);
            }

            $existing = self::refundUnderReference((int) $payment->id, $own);
        }

        if ($existing === null) {
            return self::refundResult(outcome: self::OUTCOME_FAILED, reason: sprintf('the provider\'s held word of %s with no refund id could not be kept; nothing was written: try again', $figure->format()));
        }

        $kept = self::getRaw(id: (int) $existing->id);

        return ($kept === null ? null : self::keptWordAdded(row: $kept, kind: $kind, figure: $figure, providerRefundId: null))
            ?? self::keptAnswer(row: $existing, what: sprintf('the provider\'s held word of %s with no refund id', $figure->format()));
    }

    /**
     * The refund rows of a payment made for one of this site's refund requests (their creation recorded the request's
     * key) whose reference no longer carries it: a reservation held or kept under the provider's refund id. Any status,
     * oldest first (since 2.1.0).
     *
     * @param object $payment The payment (id, id_order).
     * @param string $key The request's key.
     *
     * @return list<object> id, id_order, id_refunded_payment, amount, transaction_status, transaction_id.
     *
     * @since  2.1.0
     */
    private static function rowsMovedFromKey(object $payment, string $key): array
    {
        $db = self::db();
        $made = self::REPORT_REQUEST_PREFIX . $key;
        $ids = [];

        foreach ($db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['entity_id', 'context']))
                ->from($db->quoteName('#__alfa_order_activity_log'))
                ->where($db->quoteName('id_order') . ' = ' . (int) $payment->id_order)
                ->where($db->quoteName('event') . ' = ' . $db->quote('payment.refund_reserved'))
                ->order($db->quoteName('id') . ' ASC'),
        )->loadObjectList() ?: [] as $entry) {
            $context = json_decode((string) $entry->context, true);

            if (\is_array($context) && (string) ($context['transaction_id'] ?? '') === $made) {
                $ids[] = (int) $entry->entity_id;
            }
        }

        if ($ids === []) {
            return [];
        }

        return array_values(array_filter(
            $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'id_order', 'id_refunded_payment', 'amount', 'transaction_status', 'transaction_id']))
                    ->from($db->quoteName(self::TABLE))
                    ->whereIn($db->quoteName('id'), array_values(array_unique($ids)))
                    ->where($db->quoteName('id_refunded_payment') . ' = ' . (int) $payment->id)
                    ->where($db->quoteName('payment_type') . ' = ' . $db->quote(self::TYPE_REFUND))
                    ->order($db->quoteName('id') . ' ASC'),
            )->loadObjectList() ?: [],
            static fn (object $row): bool => (string) $row->transaction_id !== $made,
        ));
    }

    /**
     * The request's one refund BOOKED with no provider id, or null: its reservation booked with none (still under
     * REPORT_REQUEST_PREFIX and the key), else the first such refund beyond it (REFUND_BEYOND_PREFIX and the key),
     * settled (since 2.1.0).
     *
     * @param int $paymentId The payment.
     * @param string $key The request's key.
     *
     * @return object|null The row as stored.
     *
     * @since  2.1.0
     */
    private static function bookedOneOfKey(int $paymentId, string $key): ?object
    {
        foreach ([self::REPORT_REQUEST_PREFIX . $key, self::REFUND_BEYOND_PREFIX . $key] as $reference) {
            foreach (self::rowsOfReference(paymentId: $paymentId, reference: $reference) as $row) {
                if (\in_array((string) $row->transaction_status, self::REFUND_SETTLED_STATUSES, true)) {
                    return self::getRaw(id: (int) $row->id);
                }
            }
        }

        return null;
    }

    /**
     * The refund rows of a payment, beyond those already known, that hold a request's one refund outside the request's
     * reference: a row core keeps it on marked with the request's key (beyondDecision(), also under the provider's
     * id), and a row that carries a provider refund id one of those rows or a known one carries (one provider id, one
     * refund: resurface() keeps it again under that id after a staff resolution). Any status, oldest first (R8-1,
     * round 8c; since 2.1.0).
     *
     * @param object $payment The payment (id, id_order).
     * @param string $key The request's key.
     * @param list<object> $known The rows of the key already found (under its references, or moved off them).
     *
     * @return list<object> id, id_order, id_refunded_payment, amount, transaction_status, transaction_id.
     *
     * @since  2.1.0
     */
    private static function keyRows(object $payment, string $key, array $known): array
    {
        $paymentId = (int) $payment->id;
        $marked = array_keys(array_filter(self::unreconciledMarks(orderId: (int) $payment->id_order), static fn (array $mark): bool => $mark['key'] === $key));
        $marked = array_merge($marked, self::identifiedRows(orderId: (int) $payment->id_order, key: $key));
        $db = self::db();
        $columns = $db->quoteName(['id', 'id_order', 'id_refunded_payment', 'amount', 'transaction_status', 'transaction_id']);
        $rows = $marked === [] ? [] : ($db->setQuery(
            $db->getQuery(true)
                ->select($columns)
                ->from($db->quoteName(self::TABLE))
                ->whereIn($db->quoteName('id'), array_map('intval', $marked))
                ->where($db->quoteName('id_refunded_payment') . ' = ' . $paymentId)
                ->where($db->quoteName('payment_type') . ' = ' . $db->quote(self::TYPE_REFUND)),
        )->loadObjectList() ?: []);
        $carried = array_values(array_unique(array_filter(array_map(static fn (object $row): ?string => self::providerIdOf(row: $row), array_merge($known, $rows)))));

        foreach ($carried as $providerRefundId) {
            foreach (self::rowsOfReference(paymentId: $paymentId, reference: $providerRefundId) as $row) {
                $rows[] = $row;
            }
        }

        $knownIds = array_map(static fn (object $row): int => (int) $row->id, $known);
        $found = [];

        foreach ($rows as $row) {
            if (!\in_array((int) $row->id, $knownIds, true)) {
                $found[(int) $row->id] = $row;
            }
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * The refund rows of an order that took the provider's refund id as the one refund of a request
     * ({@see self::EVENT_REFUND_IDENTIFIED}), by the request's key: their ids, oldest first (since 2.1.0).
     *
     * @param int $orderId The order.
     * @param string $key The request's key.
     *
     * @return list<int>
     *
     * @since  2.1.0
     */
    private static function identifiedRows(int $orderId, string $key): array
    {
        $db = self::db();
        $ids = [];

        foreach ($db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['entity_id', 'context']))
                ->from($db->quoteName('#__alfa_order_activity_log'))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->where($db->quoteName('event') . ' = ' . $db->quote(self::EVENT_REFUND_IDENTIFIED))
                ->order($db->quoteName('id') . ' ASC'),
        )->loadObjectList() ?: [] as $entry) {
            $context = json_decode((string) $entry->context, true);

            if (\is_array($context) && (string) ($context['request_key'] ?? '') === $key) {
                $ids[] = (int) $entry->entity_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The hold itself, on the row {@see self::holdRefund()} found: split, beside, in place or grown.
     *
     * @param object $row The reservation, pending or authorized.
     * @param string|null $providerRefundId The provider's refund id.
     * @param Money|null $amount The provider's figure; null = the whole reservation.
     * @param array $options As holdRefund().
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    private static function holdOn(object $row, ?string $providerRefundId, ?Money $amount, array $options): array
    {
        $orderId = (int) $row->id_order;
        $held = self::money($row->amount, $orderId)->abs();
        $figure = $amount ?? $held;
        $carried = self::providerIdOf(row: $row);

        // Another provider refund than the one the reservation carries: held on its own row, beside it.
        if ($providerRefundId !== null && $carried !== null && $carried !== $providerRefundId) {
            return $amount === null
                ? self::refundResult(outcome: self::OUTCOME_REFUSED, reason: sprintf('reservation #%d holds provider refund %s; name the figure the provider holds for %s', (int) $row->id, $carried, $providerRefundId))
                : self::ownRow(row: $row, amount: $amount, providerRefundId: $providerRefundId, options: $options, settled: false);
        }

        if ($figure->lessThan($held) && !(bool) ($options['final'] ?? false) && $providerRefundId !== null && $carried === null) {
            $child = [
                'id_order' => $orderId,
                'payment_type' => self::TYPE_REFUND,
                'transaction_status' => (string) $row->transaction_status,
                'amount' => $figure,
                'id_refunded_payment' => (int) $row->id_refunded_payment,
                'refund_type' => (string) ($row->refund_type ?? self::REFUND_PARTIAL),
                'id_payment_method' => (int) ($row->id_payment_method ?? 0),
            ] + self::outcomeColumns(row: $row, providerRefundId: $providerRefundId, options: $options) + array_filter(['refund_reason' => (string) ($row->refund_reason ?? '')]);
            // The provider's pending word: its own row only re-labels what the reservation holds (never a start).
            $split = self::providerFact(static fn (): array => self::splitReservation(row: $row, amount: $figure, child: $child));

            if ($split['outcome'] === 'whole') {
                $row = $split['row'];
            } else {
                return $split['outcome'] === self::OUTCOME_OK
                    ? self::refundResult(outcome: self::OUTCOME_RESERVED, refundId: $split['id'], amount: (float) $figure->getAmount())
                    : self::refundResult(outcome: $split['outcome'] === self::OUTCOME_UNRECONCILED ? self::OUTCOME_FAILED : $split['outcome'], reason: $split['reason']);
            }
        }

        $data = ['id_order' => $orderId] + self::outcomeColumns(row: $row, providerRefundId: $providerRefundId, options: $options);
        $grows = $figure->greaterThan($held);

        if ($figure->lessThan($held) || $grows) {
            $data['amount'] = $figure;
        }

        $changes = array_filter(
            $data,
            static fn ($value, string $column): bool => $column !== 'id_order' && !($column === 'transaction_id' && $value === (string) $row->transaction_id),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($changes === []) {
            return self::refundResult(outcome: self::OUTCOME_DUPLICATE, refundId: (int) $row->id, reason: sprintf('reservation #%d already reserves this refund', (int) $row->id), amount: (float) $held->getAmount());
        }

        // A larger figure is the provider's word: the reservation grows to it (never a start).
        $write = static fn (): int => self::updateIfStatus((int) $row->id, $data, (string) $row->transaction_status, bookkeeping: true);
        $verdict = $grows ? self::providerFact($write) : $write();

        return match ($verdict) {
            self::WRITE_OK => self::refundResult(outcome: self::OUTCOME_RESERVED, refundId: (int) $row->id, amount: (float) $figure->getAmount()),
            self::WRITE_RACED => self::refundResult(outcome: self::OUTCOME_RACED, reason: sprintf('reservation #%d changed meanwhile; nothing was written', (int) $row->id)),
            default => self::refundResult(outcome: self::OUTCOME_FAILED, reason: sprintf('reservation #%d could not be written; see the com_alfa.orders log', (int) $row->id)),
        };
    }

    /**
     * Give back what a reservation still holds: the provider definitively refused or
     * failed the refund, or the decision it was made for closed with this rest unused
     * (or was never sent). Never for an unknown outcome.
     *
     * The row becomes failed (default) or cancelled through the compare-and-set
     * door: nothing is booked, the payment's status does not move (a reservation
     * never made it refunded), and the payment is re-evaluated as for any refund
     * that ends. The reason is kept in the row's note. A row that keeps a word for staff is never ended or emptied here:
     * the provider's failure report about that word is added to its report (OUTCOME_UNRECONCILED naming the row,
     * listed and blocking; staff resolve it), and one naming another provider refund than the kept one, with no row
     * of its own, ends nothing (OUTCOME_REFUSED); a release naming a provider id ends only that id's own row (since
     * 2.1.0).
     *
     * Answers: OUTCOME_RELEASED; OUTCOME_DUPLICATE (already failed or cancelled: nothing written); OUTCOME_REFUSED
     * (settled, or not a reservation: a settled refund is corrected in the payment form, R4); OUTCOME_UNRECONCILED (a
     * row that keeps a word for staff: the failure added to its report); OUTCOME_RACED or OUTCOME_FAILED.
     *
     * @param int $reservationId A refund row of a payment, pending or authorized.
     * @param string $reason Why, plain text (the first 255 characters are kept).
     * @param array $options 'status' (STATUS_FAILED, the default, or STATUS_CANCELLED);
     *                       'provider_refund_id' (the failed refund: recorded on a row that
     *                       carries no provider id yet; the row of the payment that carries it
     *                       is released instead when that is another one; never a row that
     *                       carries another provider refund); 'gateway_response' (string);
     *                       'plugin' (string: the calling payment plugin's element).
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    public static function releaseRefund(int $reservationId, string $reason, array $options = []): array
    {
        // One transaction under the order's lock, from the first read to the commit (round 8d).
        $framed = self::inWord(rowId: $reservationId, word: static fn (): array => self::releaseRefund(reservationId: $reservationId, reason: $reason, options: $options));

        if ($framed !== null) {
            return $framed;
        }

        $to = (string) ($options['status'] ?? self::STATUS_FAILED);

        if (!\in_array($to, [self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
            return self::refundResult(outcome: self::OUTCOME_REFUSED, reason: sprintf("a reservation is released as '%s' or '%s', not as '%s'", self::STATUS_FAILED, self::STATUS_CANCELLED, $to));
        }

        $providerRefundId = isset($options['provider_refund_id']) ? (string) $options['provider_refund_id'] : null;
        $found = self::reservationFor(reservationId: $reservationId, amount: null, providerRefundId: $providerRefundId, releasing: true, options: $options);

        if (isset($found['result'])) {
            return $found['result'];
        }

        $row = $found['row'];

        // A release or failure the provider reports about a word core keeps for staff never ends that row or drops the
        // word: it is added to the row's report (listed and blocking; staff resolve it). One naming another provider
        // refund than the kept one, with no row of its own, ends nothing (since 2.1.0).
        if (self::keepsForStaff(row: $row)) {
            return self::anotherRefund(row: $row, providerRefundId: $providerRefundId)
                ? self::refundResult(outcome: self::OUTCOME_REFUSED, reason: sprintf('row #%d keeps another provider refund for staff and no row of %s is open: nothing was released', (int) $row->id, (string) $providerRefundId))
                : (self::keptWordAdded(row: $row, kind: $to, figure: null, providerRefundId: $providerRefundId)
                    ?? self::keptAnswer(row: $row, what: sprintf('the provider\'s failure report about refund #%d', (int) $row->id)));
        }

        $orderId = (int) $row->id_order;
        $stamp = 'Released: ' . trim($reason);
        $existing = trim((string) ($row->note ?? ''));
        $data = [
            'id_order' => $orderId,
            'transaction_status' => $to,
            'note' => mb_substr($existing !== '' ? $stamp . ' | ' . $existing : $stamp, 0, 255),
        ];

        if ($providerRefundId !== null && str_starts_with((string) $row->transaction_id, self::REPORT_REQUEST_PREFIX)) {
            $data['transaction_id'] = $providerRefundId;
        }

        if ((string) ($options['gateway_response'] ?? '') !== '') {
            $data['gateway_response'] = (string) $options['gateway_response'];
        }

        $verdict = self::updateIfStatus((int) $row->id, $data, (string) $row->transaction_status);
        $held = (float) self::money($row->amount, $orderId)->abs()->getAmount();

        if ($verdict === self::WRITE_OK) {
            return self::refundResult(outcome: self::OUTCOME_RELEASED, refundId: (int) $row->id, amount: $held);
        }

        $now = self::getRaw(id: (int) $row->id);

        if ($verdict === self::WRITE_RACED && \in_array((string) ($now->transaction_status ?? ''), [self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
            return self::refundResult(outcome: self::OUTCOME_DUPLICATE, refundId: (int) $row->id, reason: sprintf('reservation #%d is already %s', (int) $row->id, (string) $now->transaction_status));
        }

        return self::refundResult(
            outcome: $verdict === self::WRITE_RACED ? self::OUTCOME_RACED : self::OUTCOME_FAILED,
            reason: sprintf('reservation #%d could not be released; nothing was written', (int) $row->id),
        );
    }

    /**
     * One reservation as the books hold it: a refund row of a payment. Read only.
     *
     * 'key' is set while the row still carries REPORT_REQUEST_PREFIX and its key (else null), 'providerRefundId' once
     * it carries the provider's id (else null: never the core's own references, REPORT_REQUEST_PREFIX or
     * REFUND_BEYOND_PREFIX); the answer is null for a row that is not a refund of a payment.
     *
     * @param int $reservationId The refund row.
     *
     * @return array{reservation: int, payment: int, order: int, amount: Money, status: string, key: ?string, providerRefundId: ?string}|null
     *
     * @since  2.1.0
     */
    public static function refundReservation(int $reservationId): ?array
    {
        $row = $reservationId > 0 ? self::getRaw(id: $reservationId) : null;

        if ($row === null || (string) $row->payment_type !== self::TYPE_REFUND || (int) ($row->id_refunded_payment ?? 0) <= 0) {
            return null;
        }

        $reference = (string) ($row->transaction_id ?? '');
        $isKey = str_starts_with($reference, self::REPORT_REQUEST_PREFIX);

        return [
            'reservation' => (int) $row->id,
            'payment' => (int) $row->id_refunded_payment,
            'order' => (int) $row->id_order,
            'amount' => self::money($row->amount, (int) $row->id_order)->abs(),
            'status' => (string) $row->transaction_status,
            'key' => $isKey ? substr($reference, \strlen(self::REPORT_REQUEST_PREFIX)) : null,
            'providerRefundId' => self::providerIdOf(row: $row),
        ];
    }

    /**
     * The outcome the core keeps for one provider refund report of a payment, or null.
     *
     * Read only, and never asks the gateway: a payment plugin may call it inside
     * its own unbookedProviderRefunds(). Matched on the payment's own plugin
     * element and the whole reportId, byte for byte.
     *
     * 'outcome' is REPORT_RECORDED, REPORT_RECONCILED or REPORT_DISMISSED; the answer is null when no outcome is
     * kept or the row is not a payment.
     *
     * @param int $paymentId The payment the report is about.
     * @param string $reportId The report's reference.
     *
     * @return array{outcome: string, refundRow: ?int, bookedAmount: ?Money, amountTyped: bool, providerRefundId: ?string, created: string}|null
     *
     * @since  2.1.0
     */
    public static function reportOutcome(int $paymentId, string $reportId): ?array
    {
        $payment = $paymentId > 0 && $reportId !== '' ? self::getRaw(id: $paymentId) : null;

        if ($payment === null || (string) ($payment->payment_type ?? '') !== self::TYPE_PAYMENT) {
            return null;
        }

        $kept = self::keptOutcome(element: (string) ($payment->payment_method_type ?? ''), paymentId: $paymentId, reportId: $reportId);

        if ($kept === null) {
            return null;
        }

        $orderId = (int) $payment->id_order;

        return [
            'outcome' => (string) $kept->outcome,
            'refundRow' => $kept->id_refund_row === null ? null : (int) $kept->id_refund_row,
            'bookedAmount' => $kept->booked_amount === null ? null : self::money($kept->booked_amount, $orderId),
            'amountTyped' => (int) $kept->amount_typed === 1,
            'providerRefundId' => $kept->provider_refund_id === null ? null : (string) $kept->provider_refund_id,
            'created' => (string) $kept->created,
        ];
    }

    /**
     * Run one provider word, or a provider's release, entirely inside ONE transaction that holds the order's lock from
     * before its first read to its commit (main's rule of round 8d, by construction): settleRefund(), holdRefund(),
     * releaseRefund() and refundPayment() with gateway_moved call it first. Everything the word decides is read, decided
     * and written under that lock (rows and their marks together), and the answer is built from what was read or
     * written there: two words about one order queue on its lock and each sees the other's whole outcome. The row the
     * word names is read once before the lock only to find its order, and again under it. A word routed from one entry
     * to another inside the frame of the same order runs in that frame (null: the caller goes on); inside a caller's
     * transaction the frame is a savepoint of it. The flip of a payment its settled refunds now cover waits for the
     * frame's commit when the frame is the connection's outermost transaction ({@see self::flipIfCovered()}): its
     * status automation then never runs while the lock is held (inside a caller's transaction it runs there, as every
     * nested write's side effects do). No outgoing call is made inside a frame (a provider's word is inbound).
     *
     * @param int $rowId The row the word names: the refund row (settle, hold, release) or the payment (refundPayment).
     * @param Closure $word fn(): array The entry itself, called again inside the frame.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}|null The answer; null when the
     *                                                                                    caller runs the word itself
     *                                                                                    (inside the frame already, or
     *                                                                                    no such row: refused there).
     *
     * @since  2.1.0
     */
    private static function inWord(int $rowId, Closure $word): ?array
    {
        $orderId = $rowId > 0 ? (int) (self::getRaw(id: $rowId)->id_order ?? 0) : 0;

        if ($orderId <= 0 || self::$wordOrder === $orderId) {
            return null;
        }

        $outer = self::$wordOrder;
        $outerDefers = self::$wordDefersFlip;
        self::$wordOrder = $orderId;
        self::$wordDefersFlip = $outer === 0 ? !TransactionState::isOpen(self::db()) : $outerDefers;

        try {
            return self::wordLocked(orderId: $orderId, rowId: $rowId, word: $word);
        } finally {
            self::$wordOrder = $outer;
            self::$wordDefersFlip = $outerDefers;
        }
    }

    /**
     * The transaction of {@see self::inWord()}: the order's lock first, the row's order read again under it, the word,
     * the commit. The order not locked within the lock wait answers OUTCOME_FAILED, nothing written, to retry; a row
     * moved to another order meanwhile answers OUTCOME_RACED; a failure inside rolls the whole word back and reaches
     * the caller (the ledger's one transaction rule).
     *
     * @param int $orderId The order, as read before the lock.
     * @param int $rowId The row the word names.
     * @param Closure $word fn(): array The entry.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    private static function wordLocked(int $orderId, int $rowId, Closure $word): array
    {
        $db = self::db();
        $db->transactionStart(true);

        try {
            if (!self::lockOrders($db, $orderId)) {
                TransactionState::rollBackLevel($db);

                return self::refundResult(outcome: self::OUTCOME_FAILED, reason: sprintf('order #%d is held by another writer: the provider\'s word was not processed and nothing was written; try again', $orderId));
            }

            if ((int) (self::getRaw(id: $rowId)->id_order ?? 0) !== $orderId) {
                TransactionState::rollBackLevel($db);

                return self::refundResult(outcome: self::OUTCOME_RACED, reason: sprintf('row #%d moved to another order meanwhile; nothing was written: try again', $rowId));
            }

            $answer = $word();
        } catch (Throwable $e) {
            TransactionState::rollBackLevel($db, $e);

            throw $e;
        }

        TransactionState::commitLevel($db);

        return $answer;
    }

    /**
     * The reservation an outcome call names, checked, or the answer that refuses it.
     *
     * @param int $reservationId The refund row.
     * @param Money|null $amount The provider's figure, when one is given.
     * @param string|null $providerRefundId The provider's refund id, when one is given.
     * @param bool $releasing True for releaseRefund(): a failed or cancelled row is a repeat.
     * @param array $options The call's options ('plugin': the calling payment plugin's element).
     * @param bool $holding True for holdRefund(): the provider holds the refund, it has not completed it.
     *
     * @return array{row: object}|array{result: array} The row (pending or authorized), or the answer.
     *
     * @since  2.1.0
     */
    private static function reservationFor(int $reservationId, ?Money $amount, ?string $providerRefundId, bool $releasing = false, array $options = [], bool $holding = false): array
    {
        $row = $reservationId > 0 ? self::getRaw(id: $reservationId) : null;
        $refuse = static fn (string $why): array => ['result' => self::refundResult(outcome: self::OUTCOME_REFUSED, reason: $why)];

        if ($row === null || (string) $row->payment_type !== self::TYPE_REFUND || (int) ($row->id_refunded_payment ?? 0) <= 0) {
            return $refuse(sprintf('row #%d is not a refund of a payment', $reservationId));
        }

        $orderId = (int) $row->id_order;

        try {
            $currency = OrderCurrency::of(orderId: $orderId);
        } catch (OrderCurrencyException $e) {
            return $refuse($e->getMessage());
        }

        if ($providerRefundId !== null && ($providerRefundId === '' || !mb_check_encoding($providerRefundId, 'UTF-8'))) {
            return $refuse('a provider refund id is a non-empty valid UTF-8 text, or null when the provider gives none');
        }

        $wrongAmount = $amount === null ? null : self::reservationAmountProblem(amount: $amount, currency: $currency);

        if ($wrongAmount !== null) {
            return $refuse($wrongAmount);
        }

        $payment = self::getRaw(id: (int) $row->id_refunded_payment);
        $notTheirs = $payment === null ? null : self::pluginRefusal(payment: $payment, options: $options);

        // Another plugin's payment: a release is refused (nothing moved); what the provider settled or holds is kept for
        // staff, never refused (since 2.1.0).
        if ($notTheirs !== null) {
            if ($releasing) {
                return $refuse(sprintf('reservation #%d: %s', (int) $row->id, $notTheirs));
            }

            // An id-less word named by its figure at another figure is a word of its own (since 2.1.0).
            $elsewhere = self::figureWordElsewhere(row: $row, kind: $holding ? self::REPORT_STATUS_PENDING : self::REPORT_STATUS_SETTLED, figure: $amount, providerRefundId: $providerRefundId, options: $options);

            if ($elsewhere !== null) {
                return ['result' => $elsewhere];
            }

            // A word about the word core keeps for staff on the row it names: only added to what staff see; its own
            // word again is that row's, never kept a second time on another row (since 2.1.0).
            if (self::keepsForStaff(row: $row) && !self::anotherRefund(row: $row, providerRefundId: $providerRefundId, figure: $amount, whole: $amount === null)) {
                return ['result' => self::keptWordAdded(row: $row, kind: $holding ? self::REPORT_STATUS_PENDING : self::REPORT_STATUS_SETTLED, figure: $amount, providerRefundId: $providerRefundId)
                    ?? self::keptAnswer(row: $row, what: sprintf('the provider\'s word about refund #%d', (int) $row->id))];
            }

            $key = self::requestKeyOf(reference: (string) ($row->transaction_id ?? ''));

            return ['result' => self::keepNotTheirs(payment: $payment, amount: $amount ?? self::money($row->amount, $orderId)->abs(), options: array_filter(['transaction_id' => $providerRefundId, 'request_key' => $providerRefundId === null ? $key : null]) + $options, why: sprintf('reservation #%d: %s', (int) $row->id, $notTheirs), held: $holding)];
        }

        // A word whose report staff recorded is in the books already.
        $recorded = !$releasing && $payment !== null ? self::recordedReport(payment: $payment, options: $options) : null;

        if ($recorded !== null) {
            return ['result' => $recorded];
        }

        $reportId = (string) ($options['report_id'] ?? '');
        $owner = null;

        // One provider refund, one row: an id another refund row of the payment carries names that row. Still
        // reserving (split off or held beside the reservation), the call acts on it; booked, it is a duplicate
        // (a release reads that row's status below). A release never ends a row that carries another provider
        // refund for this one.
        if ($providerRefundId !== null) {
            $owner = self::refundUnderReference((int) $row->id_refunded_payment, $providerRefundId);
            $carried = self::providerIdOf(row: $row);

            if ($owner !== null && (int) $owner->id !== (int) $row->id) {
                if (!$releasing && !\in_array((string) $owner->transaction_status, self::REFUND_RESERVING_STATUSES, true)) {
                    return ['result' => self::refundResult(outcome: self::OUTCOME_DUPLICATE, refundId: (int) $owner->id, reason: self::duplicateReason((int) $row->id_refunded_payment, $providerRefundId, $owner))];
                }

                $row = self::getRaw(id: (int) $owner->id) ?? $row;
            } elseif ($owner === null && $releasing && $carried !== null && $carried !== $providerRefundId) {
                return $refuse(sprintf('reservation #%d holds provider refund %s; no reservation of %s is open: nothing was released', (int) $row->id, $carried, $providerRefundId));
            }

            // A provider refund staff resolved through the way out (a duplicate or invalid report, or reconciled
            // to a refund already booked) is never booked over that resolution: it comes back as a new report
            // that holds the refund block, so staff decide again.
            $resolved = $owner === null && !$releasing && $payment !== null ? self::resolvedByStaff(paymentId: (int) $payment->id, providerRefundId: $providerRefundId, reportId: $reportId) : null;

            if ($resolved !== null) {
                $figure = $amount ?? self::money($row->amount, $orderId)->abs();

                return ['result' => self::resolutionStands(payment: $payment, resolved: $resolved, figure: $figure, named: $providerRefundId, settle: !$holding)
                    ?? (self::keepsWordItself(row: $row)
                        ? self::keepApart(row: $row, figure: $figure, providerRefundId: $providerRefundId, resolved: $resolved, held: $holding, final: (bool) ($options['final'] ?? false))
                        : self::resurface(payment: $payment, amount: $figure, providerRefundId: $providerRefundId, resolved: $resolved, options: $options, held: $holding))];
            }
        } elseif (!$releasing && $payment !== null && $reportId !== '' && self::keepsWordItself(row: $row)) {
            // With no provider id, the report the gateway listed it under names the word: staff resolved it, so it
            // is kept for staff on its own row split off the reservation, under the request's key and its figure
            // (once; a word with none and no such report is the request's own refund below).
            $resolved = self::resolvedByStaff(paymentId: (int) $payment->id, providerRefundId: '', reportId: $reportId);

            if ($resolved !== null) {
                $figure = $amount ?? self::money($row->amount, $orderId)->abs();
                $key = (string) self::requestKeyOf(reference: (string) ($row->transaction_id ?? ''));
                $kept = self::REFUND_BEYOND_PREFIX . $key . ':' . PublishedDecimal::money(money: $figure);

                // Kept before (one word, one row), or kept and resolved again (the resolution stands).
                if (self::refundUnderReference((int) $payment->id, $kept) !== null) {
                    return ['result' => self::keepForStaff(payment: $payment, key: $key, figure: $figure, options: $options, surface: false, held: $holding)];
                }

                $last = self::resolvedRelease(payment: $payment, rows: self::rowsOfReference(paymentId: (int) $payment->id, reference: $kept));

                return ['result' => ($last === null ? null : self::resolutionStands(payment: $payment, resolved: $last, figure: $figure, named: $kept, settle: !$holding))
                    ?? self::keepApart(row: $row, figure: $figure, providerRefundId: null, resolved: $last ?? $resolved, held: $holding, final: (bool) ($options['final'] ?? false))];
            }
        }

        $status = (string) $row->transaction_status;
        $reference = (string) ($row->transaction_id ?? '');

        // A word with no provider id on a row under one of this site's requests: the request's one refund with no
        // provider id decides it ({@see self::idlessUnderKey()}), a released or a booked row alike.
        $key = !$releasing && $providerRefundId === null && $payment !== null && !\in_array($status, self::REFUND_RESERVING_STATUSES, true)
            ? self::requestKeyOf(reference: $reference)
            : null;
        $keyed = $key === null ? null : self::idlessUnderKey(payment: $payment, key: $key, amount: $amount, options: $options, surface: false, held: $holding);

        if ($keyed !== null) {
            return ['result' => $keyed];
        }

        // A provider refund id no row of the payment carries, named on a reservation that is settled or released: a NEW
        // refund (main's rule of 20 October; round 8c), whatever state the reservation is in. A
        // settle is booked on a row of its own when the books take it, else kept there for staff; a hold is held on a
        // row of its own that waits for staff, listed PENDING and blocking (R3-1). Never merged into the reservation.
        if (!$releasing && $providerRefundId !== null && $owner === null && $payment !== null && !\in_array($status, self::REFUND_RESERVING_STATUSES, true)) {
            // The request's one refund BOOKED with no provider id, named with an id at its figure (a hold with no figure:
            // its own): that refund being identified (main's decision of round 8d): it takes the id; DUPLICATE.
            if (\in_array($status, self::REFUND_SETTLED_STATUSES, true) && self::providerIdOf(row: $row) === null && self::oneRefundOfKey(reference: $reference)
                && ($amount ?? self::money($row->amount, $orderId)->abs())->getMinorUnits() === self::money($row->amount, $orderId)->abs()->getMinorUnits()) {
                return ['result' => self::identifyBooked(row: $row, providerRefundId: $providerRefundId)];
            }

            if ($amount === null) {
                return $refuse(sprintf('reservation #%d is %s; provider refund %s is another refund: name the figure the provider holds', (int) $row->id, $status, $providerRefundId));
            }

            return ['result' => $holding
                ? self::heldBesideClosed(row: $row, amount: $amount, providerRefundId: $providerRefundId, options: $options)
                : self::ownRow(row: $row, amount: $amount, providerRefundId: $providerRefundId, options: $options, settled: true, keep: self::uncollected(paymentId: (int) $row->id_refunded_payment))];
        }

        if (\in_array($status, [self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
            return $releasing
                ? ['result' => self::refundResult(outcome: self::OUTCOME_DUPLICATE, refundId: (int) $row->id, reason: sprintf('reservation #%d is already %s', (int) $row->id, $status))]
                : $refuse(sprintf('reservation #%d was released (%s); a refund it was made for is booked with refundPayment() and gateway_moved', (int) $row->id, $status));
        }

        if (\in_array($status, self::REFUND_SETTLED_STATUSES, true)) {
            if ($releasing || ($providerRefundId !== null && $providerRefundId !== $reference)) {
                return $refuse(sprintf('reservation #%d is already settled; a settled refund is corrected only to failed or cancelled, in the payment form', (int) $row->id));
            }

            // The same refund again (its id, or the same figure with none): nothing to write. A different figure with
            // no id on a refund booked under a provider id cannot be matched to it here.
            return $providerRefundId !== null || $amount === null || $amount->getMinorUnits() === self::money($row->amount, $orderId)->abs()->getMinorUnits()
                ? ['result' => self::refundResult(outcome: self::OUTCOME_DUPLICATE, refundId: (int) $row->id, reason: sprintf('reservation #%d is already settled', (int) $row->id))]
                : $refuse(sprintf('reservation #%d is settled%s; a figure of %s with no refund id cannot be matched to it: book money moved under its refund request with refundPayment(), gateway_moved and request_key', (int) $row->id, $reference !== '' ? ' under provider refund ' . $reference : '', $amount->format()));
        }

        return ['row' => $row];
    }

    /**
     * A provider hold under a refund id no row of the payment carries, naming a reservation that is settled or released
     * (round 8c): a new refund, held on a pending row of its own (the provider-fact door: it reserves its figure)
     * marked held and waiting for staff, so the core lists its report PENDING and it holds the refund block until staff
     * decide (R3-1); a later settle of it is added to that report ({@see self::keptWordAdded()}). The same hold
     * delivered twice finds the one row (since 2.1.0).
     *
     * @param object $row The reservation it names (settled, failed or cancelled).
     * @param Money $amount The provider's figure.
     * @param string $providerRefundId The provider's refund id.
     * @param array $options The call's options ('gateway_response').
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    private static function heldBesideClosed(object $row, Money $amount, string $providerRefundId, array $options): array
    {
        $paymentId = (int) $row->id_refunded_payment;
        $data = [
            'id_order' => (int) $row->id_order,
            'payment_type' => self::TYPE_REFUND,
            'transaction_status' => self::STATUS_PENDING,
            'amount' => $amount,
            'id_refunded_payment' => $paymentId,
            'refund_type' => self::REFUND_PARTIAL,
            'id_payment_method' => (int) ($row->id_payment_method ?? 0),
            'transaction_id' => $providerRefundId,
        ] + array_filter(['gateway_response' => (string) ($options['gateway_response'] ?? '')]);
        $id = self::providerFact(static fn (): int|false => static::create(data: $data));
        $kept = $id === false ? null : self::getRaw(id: (int) $id);

        if ($kept !== null) {
            return self::unreconciledSettle(row: $kept, amount: $amount, providerRefundId: $providerRefundId, why: sprintf('held under a provider refund id no row carried, naming reservation #%d, which is %s', (int) $row->id, (string) $row->transaction_status), waitForStaff: true, held: true);
        }

        // Another delivery held it first (one provider refund, one row).
        $first = self::refundUnderReference($paymentId, $providerRefundId);
        $existing = $first === null ? null : self::getRaw(id: (int) $first->id);

        return $existing === null
            ? self::refundResult(outcome: self::OUTCOME_FAILED, reason: sprintf('provider refund %s of payment #%d could not be held on its own row; nothing was written: try again', $providerRefundId, $paymentId))
            : (self::keptWordAdded(row: $existing, kind: self::REPORT_STATUS_PENDING, figure: $amount, providerRefundId: $providerRefundId) ?? self::keptAnswer(row: $existing, what: sprintf('provider refund %s', $providerRefundId)));
    }

    /**
     * What is wrong with a figure a reservation is asked or told, or null: Money of
     * the order's currency, above zero, exact at the order's scale.
     *
     * @param Money $amount The figure.
     * @param Currency $currency The order's currency at its frozen scale.
     *
     * @since  2.1.0
     */
    private static function reservationAmountProblem(Money $amount, Currency $currency): ?string
    {
        if (!$amount->getCurrency()->equals($currency)) {
            return sprintf('the amount is in %s, the order in %s; convert before refunding', $amount->getCurrency()->getCode(), $currency->getCode());
        }

        if (!$amount->isPositive()) {
            return 'a refund amount is above zero';
        }

        return self::frozenScaleMoney(value: $amount, currency: $currency) === null
            ? sprintf('%s is finer than the order\'s scale', $amount->getAmount())
            : null;
    }

    /**
     * The columns an outcome writes on the row it names: the provider's id, its
     * response, and a reason when one is given (else the row keeps its own).
     *
     * @param object $row The reservation.
     * @param string|null $providerRefundId The provider's refund id.
     * @param array $options 'gateway_response', 'reason'.
     *
     * @return array<string, string>
     *
     * @since  2.1.0
     */
    private static function outcomeColumns(object $row, ?string $providerRefundId, array $options): array
    {
        $columns = [];

        if ($providerRefundId !== null) {
            $columns['transaction_id'] = $providerRefundId;
        }

        if ((string) ($options['gateway_response'] ?? '') !== '') {
            $columns['gateway_response'] = (string) $options['gateway_response'];
        }

        if ((string) ($options['reason'] ?? '') !== '') {
            $columns['refund_reason'] = mb_substr((string) $options['reason'], 0, 255);
        }

        return $columns;
    }

    /**
     * The reservation row becomes the settled refund at the provider's figure, in one
     * compare-and-set write; then its payment is flipped when its refunds cover it.
     *
     * @param object $row The reservation as read.
     * @param Money $amount The provider's figure.
     * @param string|null $providerRefundId The provider's refund id.
     * @param array $options As settleRefund().
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    private static function settleWhole(object $row, Money $amount, ?string $providerRefundId, array $options): array
    {
        $orderId = (int) $row->id_order;
        $paymentId = (int) $row->id_refunded_payment;
        $isFull = self::refundTypeFor(paymentId: $paymentId, amount: $amount) === self::REFUND_FULL;
        $data = [
            'id_order' => $orderId,
            'transaction_status' => self::STATUS_REFUNDED,
            'amount' => $amount,
            'refund_type' => $isFull ? self::REFUND_FULL : self::REFUND_PARTIAL,
            'processed_at' => (string) ($options['processed_at'] ?? Factory::getDate('now', 'UTC')->toSql()),
        ] + self::outcomeColumns(row: $row, providerRefundId: $providerRefundId, options: $options);
        $id = (int) $row->id;
        // Asked again under the order's lock: the provider id is still this row's, and a row core keeps a word on
        // still holds this word alone (R8-2: another word added meanwhile is never booked over by the retry).
        $verdict = self::updateIfStatus(
            $id,
            $data,
            (string) $row->transaction_status,
            precondition: static function () use ($paymentId, $providerRefundId, $id, $amount): bool {
                $owner = $providerRefundId === null ? null : self::refundUnderReference($paymentId, $providerRefundId);

                return ($owner === null || (int) $owner->id === $id) && self::stillOwnWord(rowId: $id, figure: $amount, providerRefundId: $providerRefundId);
            },
        );

        // Booked: the compare-and-set itself flips the payment once its settled refunds cover it (updateIfStatus()).
        if ($verdict === self::WRITE_OK) {
            return self::refundResult(outcome: self::OUTCOME_OK, refundId: $id, amount: (float) $amount->getAmount());
        }

        $now = self::getRaw(id: $id);

        if ($verdict === self::WRITE_RACED) {
            $owner = $providerRefundId === null ? null : self::refundUnderReference($paymentId, $providerRefundId);

            if ($owner !== null && (int) $owner->id !== $id) {
                return self::refundResult(outcome: self::OUTCOME_DUPLICATE, refundId: (int) $owner->id, reason: self::duplicateReason($paymentId, $providerRefundId, $owner));
            }

            // The row core keeps this word on received another word meanwhile (R8-2): its report shows both; staff decide.
            if ($now !== null && !self::stillOwnWord(rowId: $id, figure: $amount, providerRefundId: $providerRefundId)) {
                return self::refundResult(outcome: self::OUTCOME_UNRECONCILED, refundId: $id, reason: sprintf('row #%d keeps this provider refund for staff and received another word meanwhile: its report %s shows this word (%s) among the others and holds the refund block until staff decide; nothing was booked', $id, self::REPORT_RESERVATION_PREFIX . $id, $amount->format()));
            }

            if ($owner !== null) {
                return self::refundResult(outcome: self::OUTCOME_DUPLICATE, refundId: (int) $owner->id, reason: self::duplicateReason($paymentId, $providerRefundId, $owner));
            }

            return self::refundResult(outcome: self::OUTCOME_RACED, reason: sprintf('reservation #%d is %s now; nothing was written', $id, (string) ($now->transaction_status ?? '?')));
        }

        return self::unreconciledSettle(row: $row, amount: $amount, providerRefundId: $providerRefundId, why: 'refused by the books');
    }

    /**
     * Whether a settle may book the row it names as far as class A is concerned: the row is not one core keeps a word on
     * for staff, or it still holds this settled word ALONE (its own one word: a retry the books may take). Asked under
     * the order's lock by the booking door ({@see self::settleWhole()}), where no other word can be added meanwhile;
     * staff's own Record this refund is never held to it (R8-2; since 2.1.0).
     *
     * @param int $rowId The row.
     * @param Money $figure The settled figure.
     * @param string|null $providerRefundId The provider's refund id; null when it gives none.
     *
     * @since  2.1.0
     */
    private static function stillOwnWord(int $rowId, Money $figure, ?string $providerRefundId): bool
    {
        $row = self::$staffWayOut ? null : self::getRaw(id: $rowId);

        if ($row === null || !\in_array((string) $row->transaction_status, self::REFUND_RESERVING_STATUSES, true) || !self::keepsForStaff(row: $row)) {
            return true;
        }

        $words = self::unreconciledMarks(orderId: (int) $row->id_order)[$rowId]['words'] ?? [];

        return \count($words) === 1 && self::sameWord(word: $words[0], kind: self::REPORT_STATUS_SETTLED, providerRefundId: $providerRefundId ?? '', figure: $figure, orderId: (int) $row->id_order);
    }

    /**
     * Split a provider refund off a reservation: in one transaction under the order's
     * lock, write its own row (settled or pending, as $child says) and lower the
     * reservation by its amount, so the total counted never moves.
     *
     * Answers: OUTCOME_OK with the new row's id; 'whole' with the row read under the lock when the reservation holds
     * no more than the part any more; OUTCOME_RACED (the reservation moved meanwhile), OUTCOME_FAILED (the order not
     * locked within the lock wait, or a write refused), or OUTCOME_UNRECONCILED when the books refuse the part.
     *
     * @param object $row The reservation as read.
     * @param Money $amount The part split off, below what it holds.
     * @param array $child The new row's columns.
     * @param bool $apart A provider word kept apart for staff ({@see self::keepApart()}): written whatever the
     *                    reservation holds; the reservation is released with it when it holds no more than the
     *                    word, or when $releaseRest.
     * @param bool $releaseRest With $apart: release the reservation's rest (the word was final).
     *
     * @return array{outcome: string, id?: int, reason?: string, row?: object}
     *
     * @since  2.1.0
     */
    private static function splitReservation(object $row, Money $amount, array $child, bool $apart = false, bool $releaseRest = false): array
    {
        $orderId = (int) $row->id_order;
        $id = (int) $row->id;
        $db = self::db();
        $db->transactionStart(true);

        try {
            // The order not locked within the lock wait: nothing was written, a failure to retry (as for a whole settle).
            if (!self::lockOrders($db, $orderId)) {
                TransactionState::rollBackLevel($db);

                return ['outcome' => self::OUTCOME_FAILED, 'reason' => sprintf('order #%d could not be locked; nothing was written: try again', $orderId)];
            }

            $now = self::getRaw(id: $id);

            if ($now === null || (string) $now->transaction_status !== (string) $row->transaction_status) {
                TransactionState::rollBackLevel($db);

                return ['outcome' => self::OUTCOME_RACED, 'reason' => sprintf('reservation #%d changed meanwhile; nothing was written', $id)];
            }

            $held = self::money($now->amount, $orderId)->abs();
            $takesAll = $releaseRest || !$amount->lessThan($held);

            if ($takesAll && !$apart) {
                TransactionState::rollBackLevel($db);

                return ['outcome' => 'whole', 'row' => $now];
            }

            // The settle flips the payment once this transaction has committed, never inside it.
            $was = self::$deferFlip;
            self::$deferFlip = true;

            try {
                $childId = static::create(data: $child);
            } finally {
                self::$deferFlip = $was;
            }

            if ($childId === false) {
                TransactionState::rollBackLevel($db);

                return ['outcome' => (string) $child['transaction_status'] === self::STATUS_REFUNDED ? self::OUTCOME_UNRECONCILED : self::OUTCOME_FAILED, 'reason' => 'refused by the books'];
            }

            // The core's own write (never a staff edit): through the compare-and-set door. A word kept apart that takes
            // the whole reservation (or closes the request) releases it instead.
            $rest = $takesAll
                ? ['id_order' => $orderId, 'transaction_status' => self::STATUS_CANCELLED, 'note' => mb_substr(sprintf('Released: the provider\'s word for this request is kept on refund #%d for staff.', (int) $childId), 0, 255)]
                : ['id_order' => $orderId, 'amount' => $held->subtract($amount)];

            if (self::updateIfStatus($id, $rest, (string) $now->transaction_status, bookkeeping: true) !== self::WRITE_OK) {
                TransactionState::rollBackLevel($db);

                return ['outcome' => self::OUTCOME_FAILED, 'reason' => sprintf('reservation #%d could not be lowered; nothing was written', $id)];
            }
        } catch (Throwable $e) {
            TransactionState::rollBackLevel($db, $e);

            throw $e;
        }

        TransactionState::commitLevel($db);

        return ['outcome' => self::OUTCOME_OK, 'id' => (int) $childId];
    }

    /**
     * A provider's word the books do not take: the money moved (or the provider holds it) and is not in the books.
     * The row of that provider refund (the reservation, or the pending row of a part split off it, of a refund held
     * beside it, or of one kept for staff) stays and now holds the refund block (O5's settled-but-unbooked case):
     * it carries the provider's id when there is one and the mark EVENT_REFUND_UNRECONCILED, and the core lists its
     * report (REPORT_RESERVATION_PREFIX and the row id) among the payment's unbooked provider refunds, so no new
     * refund starts on the order until that report goes through the canonical way out. One provider refund, one
     * row, one mark. Logged CRITICAL and notified, as refundPayment() does for moved money.
     *
     * @param object $row The row of this provider refund.
     * @param Money $amount The provider's figure.
     * @param string|null $providerRefundId The provider's refund id.
     * @param string $why What keeps it out of the books.
     * @param object|null $resolved The kept outcome of the report staff resolved for this provider refund
     *                              before ({@see self::resurface()}): the row then waits for staff, whatever
     *                              the books could hold.
     * @param bool $notify False when the caller tells staff itself ({@see self::surfaceBeyondDecision()}).
     *                     Only the row's first mark notifies: a retry never notifies again.
     * @param bool $waitForStaff The row waits for staff whatever the books could hold: a provider word with no
     *                           refund id that contradicts the one booked under its request
     *                           ({@see self::keepForStaff()}).
     * @param bool $held The provider holds the refund (pending), it has not completed it: the core's report of
     *                   it says pending, so Record this refund waits; listed only while its payment is not
     *                   collected ({@see self::uncollected()}).
     * @param bool $booksChecked The caller established why the books do not take it ($why); they are not asked.
     * @param string $requestKey The request whose one refund the row keeps outside its request reference
     *                           ({@see self::beyondDecision()}): the mark names it (since 2.1.0).
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    private static function unreconciledSettle(object $row, Money $amount, ?string $providerRefundId, string $why, ?object $resolved = null, bool $notify = true, bool $waitForStaff = false, bool $held = false, bool $booksChecked = false, string $requestKey = ''): array
    {
        $orderId = (int) $row->id_order;
        $paymentId = (int) $row->id_refunded_payment;

        if ($resolved === null && !$waitForStaff && !$booksChecked && !self::booksRefuse(paymentId: $paymentId, orderId: $orderId, amount: $amount)) {
            return self::retryLater(rowId: (int) $row->id);
        }

        $notify = $notify && !isset(self::unreconciledMarks(orderId: $orderId)[(int) $row->id]);
        $named = $providerRefundId ?? '(no provider id)';
        $report = self::REPORT_RESERVATION_PREFIX . (int) $row->id;

        $line = sprintf(
            'Payment #%d: the provider %s refund %s of %s for reservation #%d, but the ledger %s; the reservation stays. Reconcile manually.',
            $paymentId,
            $held ? 'holds' : 'settled',
            $named,
            $amount->format(),
            (int) $row->id,
            $why,
        );

        // The provider's id goes on the row (its reports are then its own), and the mark that makes the order
        // hold the refund block until the row's report goes through the way out.
        if ($providerRefundId !== null && str_starts_with((string) ($row->transaction_id ?? ''), self::REPORT_REQUEST_PREFIX)) {
            self::updateIfStatus((int) $row->id, ['id_order' => $orderId, 'transaction_id' => $providerRefundId], (string) $row->transaction_status, bookkeeping: true);
        }

        $summary = match (true) {
            $resolved !== null => sprintf('Refund #%d: the provider settled %s (%s) after staff resolved its report %s (%s); it is not booked over that resolution: its new report %s holds the refund block until staff decide again.', (int) $row->id, $named, $amount->format(), (string) $resolved->report_id, (string) $resolved->outcome, $report),
            $waitForStaff || $booksChecked => sprintf('Refund #%d: the provider %s %s (%s), which is not booked: %s; the order holds the refund block until its report %s is recorded, reconciled to an existing refund or resolved as a duplicate or invalid report.', (int) $row->id, $held ? 'holds' : 'settled', $named, $amount->format(), $why, $report),
            default => sprintf('Refund #%d: the provider settled %s (%s), which the books refused; the order holds the refund block until its report %s is recorded, reconciled to an existing refund or resolved as a duplicate or invalid report.', (int) $row->id, $named, $amount->format(), $report),
        };

        try {
            OrderModel::logOrderActivityStrict(
                orderId: $orderId,
                event: self::EVENT_REFUND_UNRECONCILED,
                summary: $summary,
                context: [
                    'id_refund' => (int) $row->id,
                    'id_payment' => $paymentId,
                    'provider_refund_id' => $providerRefundId ?? '',
                    'provider_amount' => PublishedDecimal::money(money: $amount),
                    'provider_currency' => $amount->getCurrency()->getCode(),
                    'refused' => $why,
                ] + ($resolved === null ? [] : ['resolved_report' => (string) $resolved->report_id, 'resolution' => (string) $resolved->outcome])
                    + ($resolved !== null || $waitForStaff ? ['staff_decides' => 1] : [])
                    + ($held ? ['held' => 1] : [])
                    + ($requestKey !== '' ? ['request_key' => $requestKey] : []),
                entityId: (int) $row->id,
            );
        } catch (Throwable $e) {
            $line .= ' The block mark could not be written: ' . $e->getMessage() . '.';
        }

        try {
            self::ensureLogger();
            Log::add($line, Log::CRITICAL, 'com_alfa.payments');
        } catch (Throwable) {
            // The answer below carries the same fact.
        }

        try {
            if ($notify) {
                self::pushUnreconciled(paymentId: $paymentId, orderId: $orderId, realert: $resolved !== null || $waitForStaff);
            }
        } catch (Throwable) {
            // The answer below carries the same fact.
        }

        return self::refundResult(outcome: self::OUTCOME_UNRECONCILED, refundId: (int) $row->id, reason: match (true) {
            $resolved !== null => sprintf('provider refund %s was resolved by staff (%s, %s); it is not booked over that resolution: row #%d keeps it and its report %s holds the refund block until staff decide again', $named, (string) $resolved->report_id, (string) $resolved->outcome, (int) $row->id, $report),
            $waitForStaff || $booksChecked => sprintf('the provider %s %s, which is not booked: %s; row #%d keeps it and the order holds the refund block until its report %s is recorded, reconciled or resolved on the payment', $held ? 'holds' : 'settled', $amount->format(), $why, (int) $row->id, $report),
            default => sprintf('the provider settled %s, which the books refused; row #%d keeps it and the order holds the refund block until its report %s is recorded, reconciled or resolved on the payment', $amount->format(), (int) $row->id, $report),
        });
    }

    /**
     * The danger notification of a provider refund the books refused (one per payment, deduplicated by its key).
     *
     * @param int $paymentId The payment.
     * @param int $orderId Its order.
     * @param bool $realert A provider refund that comes back for staff (resolved before, or a word kept for them):
     *                      the notice is raised again, unread, even when staff dismissed it earlier.
     * @param string $message What the notice says; '' = the standing sentence. A later word about a kept word names
     *                        every word of its row ({@see self::keptWordAdded()}).
     *
     * @since  2.1.0
     */
    private static function pushUnreconciled(int $paymentId, int $orderId, bool $realert = false, string $message = ''): void
    {
        NotificationHelper::push(
            'payments.refund.unreconciled.' . $paymentId,
            'Payment #' . $paymentId . ': refund left unreconciled',
            [
                'severity' => 'danger',
                'group' => 'Payments',
                'message' => $message !== '' ? $message : 'The provider settled a refund that the books refused. Its reservation stays; this order needs manual reconciliation.',
                'url' => 'index.php?option=com_alfa&task=order.edit&id=' . $orderId,
                'view' => ['action' => 'alfa.orders.view', 'asset' => 'com_alfa'],
                'link' => ['action' => 'alfa.orders.edit', 'asset' => 'com_alfa'],
                'constant' => $realert,
            ],
        );
    }

    /**
     * The answer to a gateway's call on a row that waits for staff (a provider refund staff resolved before, back as a
     * new report: {@see self::resurface()}; or a word kept for them), or null when the row does not wait for them.
     * Nothing is written: staff decide it on the payment (Record this refund books it; a resolution ends it); the
     * provider's own failure report (releaseRefund()) is added to its report, never ends it. A later word of another
     * figure or kind is added to the row's report before this is asked ({@see self::keptWordAdded()}); another
     * provider refund id is another refund ({@see self::anotherRefund()}).
     *
     * @param object $row The row (id, id_order).
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}|null
     *
     * @since  2.1.0
     */
    private static function waitsForStaff(object $row): ?array
    {
        $mark = self::$staffWayOut ? null : (self::unreconciledMarks(orderId: (int) $row->id_order)[(int) $row->id] ?? null);

        if ($mark === null || !$mark['staff']) {
            return null;
        }

        return self::refundResult(outcome: self::OUTCOME_UNRECONCILED, refundId: (int) $row->id, reason: sprintf('row #%d holds a provider refund staff resolved before, back as its report %s: staff decide it on the payment; nothing was written', (int) $row->id, self::REPORT_RESERVATION_PREFIX . (int) $row->id));
    }

    /**
     * The kept outcome by which staff resolved a provider refund of a payment through the way out (a duplicate
     * or invalid report, or reconciled to a refund already in the books), or null. Any report counts: the core's
     * own or one the payment's gateway listed. Matched on the provider's refund id, byte for byte, the latest
     * first; else, when the gateway names the report it listed the word under ('report_id'), on that report's id.
     * Never asked while staff themselves take a report through the way out.
     *
     * @param int $paymentId The payment.
     * @param string $providerRefundId The provider's refund id; '' when it gives none.
     * @param string $reportId The report the gateway listed this word under; '' when it names none.
     *
     * @return object|null The outcome row (report_id, outcome, provider_refund_id, id_refund_row, created).
     *
     * @since  2.1.0
     */
    private static function resolvedByStaff(int $paymentId, string $providerRefundId, string $reportId = ''): ?object
    {
        if (self::$staffWayOut || $paymentId <= 0 || ($providerRefundId === '' && $reportId === '')) {
            return null;
        }

        $db = self::db();
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['report_id', 'outcome', 'provider_refund_id', 'id_refund_row', 'created']))
                ->from($db->quoteName(self::REPORT_OUTCOMES))
                ->where($db->quoteName('id_payment') . ' = ' . $paymentId)
                ->whereIn($db->quoteName('outcome'), [self::REPORT_RECONCILED, self::REPORT_DISMISSED], ParameterType::STRING)
                ->order($db->quoteName('id') . ' DESC'),
        )->loadObjectList() ?: [];

        foreach ($rows as $row) {
            if ($providerRefundId !== '' && (string) ($row->provider_refund_id ?? '') === $providerRefundId) {
                return $row;
            }
        }

        foreach ($rows as $row) {
            if ($reportId !== '' && (string) $row->report_id === $reportId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The answer to a provider word whose report (the gateway's 'report_id') staff already RECORDED, or null:
     * that refund is in the books as the row Record this refund booked, so the word is a duplicate of it and
     * nothing is written, with or without a provider id. Never asked while staff take a report through the way out.
     *
     * @param object $payment The payment (id, payment_method_type).
     * @param array $options The call's options ('report_id').
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}|null
     *
     * @since  2.1.0
     */
    private static function recordedReport(object $payment, array $options): ?array
    {
        $reportId = (string) ($options['report_id'] ?? '');
        $kept = self::$staffWayOut || $reportId === ''
            ? null
            : self::keptOutcome(element: (string) ($payment->payment_method_type ?? ''), paymentId: (int) $payment->id, reportId: $reportId);

        if ($kept === null || (string) $kept->outcome !== self::REPORT_RECORDED || (int) ($kept->id_refund_row ?? 0) <= 0) {
            return null;
        }

        return self::refundResult(outcome: self::OUTCOME_DUPLICATE, refundId: (int) $kept->id_refund_row, reason: sprintf('report %s of payment #%d was recorded by staff as refund row #%d: nothing was written', $reportId, (int) $payment->id, (int) $kept->id_refund_row));
    }

    /**
     * Whether a provider word that came back after a staff resolution is split off the reservation it names
     * ({@see self::keepApart()}) rather than kept on a row beside it: the reservation is still open under its
     * request (no provider id on it yet) and does not wait for staff. Nothing is reserved twice.
     *
     * @param object $row The reservation the call names.
     *
     * @since  2.1.0
     */
    private static function keepsWordItself(object $row): bool
    {
        $mark = self::unreconciledMarks(orderId: (int) $row->id_order)[(int) $row->id] ?? null;

        return \in_array((string) $row->transaction_status, self::REFUND_RESERVING_STATUSES, true)
            && str_starts_with((string) ($row->transaction_id ?? ''), self::REPORT_REQUEST_PREFIX)
            && !($mark['staff'] ?? false);
    }

    /**
     * Keep a provider word that came back after a staff resolution, while its reservation is still open under its
     * request, on a pending row of its own split off that reservation (its figure; its provider id, or with none
     * REFUND_BEYOND_PREFIX, the key, ':' and the figure), marked to wait for staff. The reservation stays the
     * request's open remainder and works as any other (another provider word on it, a release of the rest);
     * 'final', or a figure at or above what it holds, releases it with the split (its refund is that row now).
     * Nothing is reserved twice.
     *
     * @param object $row The open reservation (under REPORT_REQUEST_PREFIX).
     * @param Money $figure The provider's figure.
     * @param string|null $providerRefundId The provider's refund id; null when it gives none.
     * @param object $resolved The kept outcome of the staff resolution it came back after.
     * @param bool $held The provider holds it (pending), it has not completed it.
     * @param bool $final The provider's word closes the request.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    private static function keepApart(object $row, Money $figure, ?string $providerRefundId, object $resolved, bool $held, bool $final): array
    {
        $key = (string) self::requestKeyOf(reference: (string) ($row->transaction_id ?? ''));
        $child = [
            'id_order' => (int) $row->id_order,
            'payment_type' => self::TYPE_REFUND,
            'transaction_status' => self::STATUS_PENDING,
            'amount' => $figure,
            'id_refunded_payment' => (int) $row->id_refunded_payment,
            'refund_type' => self::REFUND_PARTIAL,
            'id_payment_method' => (int) ($row->id_payment_method ?? 0),
            'transaction_id' => $providerRefundId ?? self::REFUND_BEYOND_PREFIX . $key . ':' . PublishedDecimal::money(money: $figure),
        ];
        $split = self::providerFact(static fn (): array => self::splitReservation(row: $row, amount: $figure, child: $child, apart: true, releaseRest: $final));
        $part = $split['outcome'] === self::OUTCOME_OK ? self::getRaw(id: (int) $split['id']) : null;

        return $part === null
            ? self::refundResult(outcome: $split['outcome'] === self::OUTCOME_RACED ? self::OUTCOME_RACED : self::OUTCOME_FAILED, reason: (string) ($split['reason'] ?? sprintf('reservation #%d could not keep the provider\'s word apart; nothing was written', (int) $row->id)))
            : self::unreconciledSettle(row: $part, amount: $figure, providerRefundId: $providerRefundId, why: 'resolved by staff before', resolved: $resolved, held: $held);
    }

    /**
     * A provider refund staff resolved before, settled or reported again by its gateway: never booked over
     * that resolution. It is kept on a pending row of its own (the provider's figure and id, through the
     * provider-fact door) marked to wait for staff, and the core lists its report, which holds the refund
     * block until staff decide again (Record this refund books it; a resolution ends it).
     *
     * @param object $payment The payment (id, id_order, id_payment_method).
     * @param Money $amount The provider's figure.
     * @param string $providerRefundId The provider's refund id.
     * @param object $resolved The kept outcome of the earlier resolution.
     * @param array $options The call's options ('gateway_response').
     * @param bool $held The provider holds the refund (a pending word): its report is pending, never settled.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    private static function resurface(object $payment, Money $amount, string $providerRefundId, object $resolved, array $options, bool $held = false): array
    {
        $orderId = (int) $payment->id_order;
        $problem = self::reservationAmountProblem(amount: $amount, currency: OrderCurrency::of(orderId: $orderId));

        if ($problem !== null) {
            return self::refundResult(outcome: self::OUTCOME_REFUSED, reason: $problem);
        }

        $data = [
            'id_order' => $orderId,
            'payment_type' => self::TYPE_REFUND,
            'transaction_status' => self::STATUS_PENDING,
            'amount' => $amount,
            'id_refunded_payment' => (int) $payment->id,
            'refund_type' => self::REFUND_PARTIAL,
            'id_payment_method' => (int) ($payment->id_payment_method ?? 0),
            'transaction_id' => $providerRefundId,
        ] + array_filter(['gateway_response' => (string) ($options['gateway_response'] ?? '')]);
        $id = self::providerFact(static fn (): int|false => static::create(data: $data));
        $row = $id === false ? null : self::getRaw(id: (int) $id);

        return $row === null
            ? self::refundResult(outcome: self::OUTCOME_FAILED, reason: sprintf('provider refund %s of payment #%d could not be kept on its own row; nothing was written', $providerRefundId, (int) $payment->id))
            : self::unreconciledSettle(row: $row, amount: $amount, providerRefundId: $providerRefundId, why: 'resolved by staff before', resolved: $resolved, held: $held);
    }

    /**
     * Run one way-out step that staff themselves take ({@see self::$staffWayOut}); the flag is put back
     * whatever happens.
     *
     * @param Closure $call fn(): array The step.
     *
     * @return array What it answered.
     *
     * @since  2.1.0
     */
    private static function staffDecides(Closure $call): array
    {
        $was = self::$staffWayOut;
        self::$staffWayOut = true;

        try {
            return $call();
        } finally {
            self::$staffWayOut = $was;
        }
    }

    /**
     * Money a provider moved under one of this site's refund requests (its key) that no open reservation of it
     * covers any more (used up or released): the gateway books it with refundPayment() and gateway_moved, at
     * the provider's exact figure. An open reservation under the key is that reservation's settle instead.
     * Otherwise the books decide: booked, or, when they refuse it, kept on a pending row of its own (the
     * provider-fact door) marked unreconciled, which holds the refund block (OUTCOME_UNRECONCILED). Either way
     * it is surfaced to staff: the order's timeline (EVENT_REFUND_BEYOND_DECISION), a danger notification and
     * the payments log, with the key and the figures.
     *
     * @param object $original The payment.
     * @param Money|null $amount The provider's figure (required to keep a refused one).
     * @param string $requestKey The request's key (without REPORT_REQUEST_PREFIX).
     * @param array $options refundPayment()'s options.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    private static function beyondDecision(object $original, ?Money $amount, string $requestKey, array $options): array
    {
        $paymentId = (int) $original->id;
        $orderId = (int) $original->id_order;
        $reference = (string) ($options['transaction_id'] ?? '');
        $passOn = array_intersect_key($options, ['gateway_response' => true, 'reason' => true, 'report_id' => true]);

        if ($reference === '') {
            // No provider refund id: one request key is one such refund, decided from the rows under the key.
            $keyed = self::idlessUnderKey(payment: $original, key: $requestKey, amount: $amount, options: $options, surface: true);

            if ($keyed !== null) {
                return $keyed;
            }
        } else {
            $open = self::refundUnderReference($paymentId, self::REPORT_REQUEST_PREFIX . $requestKey);

            if ($open !== null && \in_array((string) $open->transaction_status, self::REFUND_RESERVING_STATUSES, true)) {
                return self::settleRefund(reservationId: (int) $open->id, amount: $amount ?? self::money($open->amount, $orderId)->abs(), providerRefundId: $reference, options: $passOn);
            }

            // A reservation of the payment that already carries this provider refund (held under its id, so no
            // longer under the key): its own settle, not money beyond the decision.
            $own = self::refundUnderReference($paymentId, $reference);

            if ($own !== null && \in_array((string) $own->transaction_status, self::REFUND_RESERVING_STATUSES, true)) {
                return self::settleRefund(reservationId: (int) $own->id, amount: $amount ?? self::money($own->amount, $orderId)->abs(), providerRefundId: $reference, options: ['final' => true] + $passOn);
            }

            // The request's one refund core keeps with no provider id on 'beyond:' and the key, named now with its id at
            // a figure its report shows: that refund identified (main's N8-2, round 8c): added there, the row takes it.
            $beyondRow = $own === null && $amount !== null ? array_values(array_filter(
                self::rowsOfReference(paymentId: $paymentId, reference: self::REFUND_BEYOND_PREFIX . $requestKey),
                static fn (object $row): bool => \in_array((string) $row->transaction_status, self::REFUND_RESERVING_STATUSES, true),
            ))[0] ?? null : null;
            $kept = $beyondRow === null ? null : self::getRaw(id: (int) $beyondRow->id);

            if ($kept !== null && self::keepsForStaff(row: $kept) && self::requestRefundNamed(row: $kept, figure: $amount, whole: false)) {
                return self::settleRefund(reservationId: (int) $kept->id, amount: $amount, providerRefundId: $reference, options: ['final' => true] + $passOn);
            }

            // The request's one refund BOOKED with no provider id (its reservation, else 'beyond:' and the key), named now
            // with its id at its figure: that refund being identified (main's decision of round 8d): it takes the id;
            // OUTCOME_DUPLICATE, nothing booked twice. Another figure with an id is another refund (booked below).
            $booked = $own === null && $amount !== null ? self::bookedOneOfKey(paymentId: $paymentId, key: $requestKey) : null;

            if ($booked !== null && self::money($booked->amount, $orderId)->abs()->getMinorUnits() === $amount->getMinorUnits()) {
                return self::identifyBooked(row: $booked, providerRefundId: $reference);
            }
        }

        // Nothing under the key yet: booked as the books decide. With no provider id it carries the core's own
        // reference for the request's one such refund, which create() de-duplicates under the order's lock.
        $bookAs = $reference !== '' ? $reference : self::REFUND_BEYOND_PREFIX . $requestKey;
        $resolvedBefore = $reference !== '' ? self::resolvedByStaff(paymentId: $paymentId, providerRefundId: $reference, reportId: (string) ($options['report_id'] ?? '')) : null;
        $result = self::refundPayment(paymentId: $paymentId, amount: $amount, options: ['transaction_id' => $bookAs] + array_diff_key($options, ['request_key' => true]));

        // A provider refund staff resolved before: it came back for staff (its own notice) or the resolution stands.
        // Not money beyond the decision a second time: one notice.
        if ($resolvedBefore !== null) {
            return $result;
        }

        if ($result['outcome'] === self::OUTCOME_OK || ($result['outcome'] === self::OUTCOME_UNRECONCILED && $result['refund_id'] > 0)) {
            self::surfaceBeyondDecision(original: $original, requestKey: $requestKey, amount: self::money($result['amount'] ?: ($amount?->getAmount() ?? 0), $orderId), reference: $reference, rowId: (int) $result['refund_id'], booked: $result['outcome'] === self::OUTCOME_OK);

            return $result;
        }

        // Another delivery under the key booked first (two words arriving together): the request's one refund with no
        // provider id now exists, so this word is judged against it (the same figure a duplicate, another kept for
        // staff), never answered as a duplicate of a refund it does not repeat.
        if ($reference === '' && $result['outcome'] === self::OUTCOME_DUPLICATE) {
            $again = self::idlessUnderKey(payment: $original, key: $requestKey, amount: $amount, options: $options, surface: true);

            if ($again !== null) {
                return $again;
            }
        }

        $refusedByBooks = \in_array($result['outcome'], [self::OUTCOME_UNRECONCILED, self::OUTCOME_ALREADY_REFUNDED, self::OUTCOME_NOTHING_LEFT, self::OUTCOME_NOT_REFUNDABLE], true);

        if (!$refusedByBooks || $amount === null || self::reservationAmountProblem(amount: $amount, currency: OrderCurrency::of(orderId: $orderId)) !== null) {
            return $result;
        }

        // The money moved and the books cannot hold it: its own pending row keeps the provider's word and its mark.
        $data = [
            'id_order' => $orderId,
            'payment_type' => self::TYPE_REFUND,
            'transaction_status' => self::STATUS_PENDING,
            'amount' => $amount,
            'id_refunded_payment' => $paymentId,
            'refund_type' => self::REFUND_PARTIAL,
            'id_payment_method' => (int) ($original->id_payment_method ?? 0),
            'transaction_id' => $bookAs,
        ] + array_filter(['gateway_response' => (string) ($options['gateway_response'] ?? ''), 'refund_reason' => mb_substr((string) ($options['reason'] ?? ''), 0, 255)]);
        // The row and its mark are written together (round 8c, R8-1): another delivery under the key finds the row
        // kept, never a row without its mark. The mark names the request: the row keeps the request's one refund
        // outside its reference, so a later word under the key finds it, also once it carries the provider's id.
        $keep = self::keepBeyondDecision(data: $data, amount: $amount, reference: $reference, requestKey: $requestKey, refused: $result['outcome']);

        if ($keep === null) {
            return self::refundResult(outcome: self::OUTCOME_FAILED, reason: sprintf('order #%d is held by another writer: what the provider moved under refund request %s was not kept; nothing was written: try again', $orderId, $requestKey));
        }

        [$row, $kept] = $keep;

        if ($row === null) {
            // Another delivery kept its word first (one reference, one row): with no provider id this word is judged
            // against that one (the same figure: the same row; another: kept for staff on its own).
            $again = $reference === '' ? self::idlessUnderKey(payment: $original, key: $requestKey, amount: $amount, options: $options, surface: true) : null;

            if ($again !== null) {
                return $again;
            }

            $first = self::refundUnderReference($paymentId, $bookAs);

            return $first === null ? $result : self::keptAnswer(row: $first, what: sprintf('what the provider moved under refund request %s beyond its decision', $requestKey));
        }

        self::surfaceBeyondDecision(original: $original, requestKey: $requestKey, amount: $amount, reference: $reference, rowId: (int) $row->id, booked: false);

        return $kept ?? self::keptAnswer(row: $row, what: sprintf('what the provider moved under refund request %s beyond its decision', $requestKey));
    }

    /**
     * Keep money a provider moved under a refund request beyond its decision, which the books refused, on a pending row
     * of its own (the provider-fact door) and mark it (EVENT_REFUND_UNRECONCILED, naming the request), in ONE
     * transaction under the order's lock: a concurrent delivery under the same key waits, then finds the row kept and
     * adds its word there ({@see self::idlessUnderKey()}), never a row without its mark (round 8c, R8-1; since 2.1.0).
     *
     * @param array $data The pending row.
     * @param Money $amount The provider's figure.
     * @param string $reference The provider's refund id, or ''.
     * @param string $requestKey The request's key.
     * @param string $refused The outcome the books answered.
     *
     * @return array{0: ?object, 1: ?array}|null [the kept row, the answer], [null, null] when another delivery kept a
     *                                           row under this reference first; null when the order was not locked
     *                                           within the lock wait (nothing written).
     *
     * @since  2.1.0
     */
    private static function keepBeyondDecision(array $data, Money $amount, string $reference, string $requestKey, string $refused): ?array
    {
        $db = self::db();
        $db->transactionStart(true);

        try {
            if (!self::lockOrders($db, (int) $data['id_order'])) {
                TransactionState::rollBackLevel($db);

                return null;
            }

            $id = self::providerFact(static fn (): int|false => static::create(data: $data));
            $row = $id === false ? null : self::getRaw(id: (int) $id);
            $kept = $row === null ? null : self::unreconciledSettle(row: $row, amount: $amount, providerRefundId: $reference !== '' ? $reference : null, why: 'beyond its decision, refused by the books (' . $refused . ')', notify: false, requestKey: $requestKey);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel($db, $e);

            throw $e;
        }

        TransactionState::commitLevel($db);

        return [$row, $kept];
    }

    /**
     * A provider's word with no refund id under one of this site's refund requests (its key): one request key is ONE
     * refund with no provider id. That one is the request's reservation when it was booked with no provider id,
     * else the first such refund beyond it (REFUND_BEYOND_PREFIX and the key). An open reservation under the key is
     * that refund's settle. The same figure as that one again is a resend: a duplicate (or its retry, while the
     * books have not taken it yet). A different figure is never booked by itself and never silent: it is kept on
     * a pending row of its own (REFUND_BEYOND_PREFIX, the key, ':' and the figure) that waits for staff, holds the
     * refund block and is listed ({@see self::keepForStaff()}). Once staff resolved the request's one refund
     * through the way out (duplicate or invalid, reconciled), every later word under the key comes back for staff
     * the same way. Null when nothing is under the key yet: the caller books it beyond the decision.
     *
     * The request's one refund is found wherever core holds it (round 8c, REVIEW-c9 R8-1): with no reservation open
     * under the key, the first of its rows that is still open under the provider's id (the reservation held under
     * it) or that core keeps for staff, whatever kept it (the reservation, 'beyond:' and the key, beyondDecision()
     * under the provider's id, resurface() after a staff resolution: {@see self::keyRows()}), takes the word: the same
     * figure is its own word, answered as before; another figure is added to its report, the latest received wins
     * ({@see self::keptWordAdded()}); only a BOOKED refund of the key keeps another figure on a row of its own.
     *
     * Decided from durable rows (the references create() de-duplicates under the order's lock, the creation entry of
     * a reservation and the marks core keeps words with).
     *
     * @param object $payment The payment (id, id_order, id_payment_method, payment_method_type).
     * @param string $key The request's key.
     * @param Money|null $amount The provider's figure; null = the figure already under the key.
     * @param array $options The call's options ('gateway_response', 'reason').
     * @param bool $surface True on refundPayment()'s request_key lane: a row kept for staff is surfaced as money
     *                      beyond the decision ({@see self::surfaceBeyondDecision()}).
     * @param bool $held True when the provider holds the refund (pending) rather than settled it.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}|null
     *
     * @since  2.1.0
     */
    private static function idlessUnderKey(object $payment, string $key, ?Money $amount, array $options, bool $surface, bool $held = false): ?array
    {
        $paymentId = (int) $payment->id;
        $orderId = (int) $payment->id_order;
        $problem = $amount === null ? null : self::reservationAmountProblem(amount: $amount, currency: OrderCurrency::of(orderId: $orderId));

        if ($problem !== null) {
            return self::refundResult(outcome: self::OUTCOME_REFUSED, reason: $problem);
        }

        $requests = self::rowsOfReference(paymentId: $paymentId, reference: self::REPORT_REQUEST_PREFIX . $key);
        $beyond = self::rowsOfReference(paymentId: $paymentId, reference: self::REFUND_BEYOND_PREFIX . $key);
        // The request's reservation that now carries the provider's refund id (held or kept under it) is still the
        // request's; so is a row core keeps the request's one refund on outside its reference (beyondDecision(), also
        // under the provider's id) and a row that carries a provider id one of them carries (resurface() after a staff
        // resolution: one provider id, one refund). R8-1, round 8c.
        $moved = self::rowsMovedFromKey(payment: $payment, key: $key);
        $related = self::keyRows(payment: $payment, key: $key, known: array_merge($requests, $beyond, $moved));
        $live = static fn (array $rows): ?object => array_values(array_filter($rows, static fn (object $row): bool => !\in_array((string) $row->transaction_status, [self::STATUS_FAILED, self::STATUS_CANCELLED], true)))[0] ?? null;
        $reserving = static fn (object $row): bool => \in_array((string) $row->transaction_status, self::REFUND_RESERVING_STATUSES, true);
        $request = $live($requests);
        $passOn = ['final' => true] + array_intersect_key($options, ['gateway_response' => true, 'reason' => true, 'report_id' => true]);
        $on = static function (object $row) use ($held, $amount, $passOn, $orderId): array {
            return $held
                ? self::holdRefund(reservationId: (int) $row->id, providerRefundId: null, amount: $amount, options: $passOn)
                : self::settleRefund(reservationId: (int) $row->id, amount: $amount ?? self::money($row->amount, $orderId)->abs(), providerRefundId: null, options: $passOn);
        };

        if ($request !== null && $reserving($request)) {
            return $on($request);
        }

        // No reservation open under the key: the request's one refund is the first of its rows that is still open under
        // the provider's id or that core keeps for staff, whatever kept it (the reservation, 'beyond:' and the key,
        // beyondDecision() under the provider's id, resurface()). The word is about it: keptWordAdded() decides (the same
        // figure is its own word, answered as before; another figure is added, the latest received wins).
        $candidates = $request === null ? array_merge(
            array_values(array_filter($moved, $reserving)),
            array_values(array_filter(array_merge($beyond, $related), static fn (object $row): bool => $reserving($row) && ($full = self::getRaw(id: (int) $row->id)) !== null && self::keepsForStaff(row: $full))),
        ) : [];
        usort($candidates, static fn (object $a, object $b): int => (int) $a->id <=> (int) $b->id);

        if ($candidates !== []) {
            return $on($candidates[0]);
        }

        // The request's one refund BOOKED: its reservation or 'beyond:' row, else a row that took the provider's id as
        // that refund (identified, round 8d) and was booked.
        $identifiedIds = self::identifiedRows(orderId: $orderId, key: $key);
        $one = $request ?? $live($beyond) ?? (array_values(array_filter(array_merge($moved, $related), static fn (object $row): bool => \in_array((int) $row->id, $identifiedIds, true) && \in_array((string) $row->transaction_status, self::REFUND_SETTLED_STATUSES, true)))[0] ?? null);

        if ($one !== null) {
            $figure = $amount ?? self::money($one->amount, $orderId)->abs();

            if ($figure->getMinorUnits() !== self::money($one->amount, $orderId)->abs()->getMinorUnits()) {
                return self::keepForStaff(payment: $payment, key: $key, figure: $figure, options: $options, surface: $surface, held: $held);
            }

            if (\in_array((string) $one->transaction_status, self::REFUND_RESERVING_STATUSES, true)) {
                return $held
                    ? self::holdRefund(reservationId: (int) $one->id, providerRefundId: null, amount: $figure, options: $passOn)
                    : self::settleRefund(reservationId: (int) $one->id, amount: $figure, providerRefundId: null, options: $passOn);
            }

            return self::refundResult(outcome: self::OUTCOME_DUPLICATE, refundId: (int) $one->id, reason: sprintf('the refund with no provider id under refund request %s is refund row #%d already', $key, (int) $one->id), amount: (float) $figure->getAmount());
        }

        $mine = array_merge($requests, $beyond, $moved, $related);
        usort($mine, static fn (object $a, object $b): int => (int) $a->id <=> (int) $b->id);
        $resolved = self::resolvedRelease(payment: $payment, rows: $mine);

        // The request's own refund came back for staff once and staff resolved it again: that resolution stands.
        $stands = $resolved !== null && $amount !== null
            ? self::resolutionStands(payment: $payment, resolved: $resolved, figure: $amount, named: self::REPORT_REQUEST_PREFIX . $key, settle: !$held)
            : null;

        if ($stands !== null) {
            return $stands;
        }

        // Else a report the gateway listed this word under (its 'report_id') that staff resolved.
        $resolved ??= self::resolvedByStaff(paymentId: $paymentId, providerRefundId: '', reportId: (string) ($options['report_id'] ?? ''));

        if ($resolved === null) {
            return null;
        }

        return $amount === null
            ? self::refundResult(outcome: self::OUTCOME_REFUSED, reason: sprintf('staff resolved the refund of refund request %s through the way out; name the provider\'s figure to have it kept for them', $key))
            : self::keepForStaff(payment: $payment, key: $key, figure: $amount, options: $options, surface: $surface, held: $held, resolved: $resolved);
    }

    /**
     * Keep a provider's word with no refund id that the request's one refund does not account for (a different
     * figure, or any figure once staff resolved that one through the way out), for staff: a pending row of its own
     * (REFUND_BEYOND_PREFIX, the key, ':' and the figure; the provider-fact door) that waits for staff, holds the
     * refund block and is listed as the core's report; nothing is booked by itself. The same word again finds that
     * row: one word, one row, one notice. With no provider id such a word is named by its figure: another figure is
     * another word, on a row of its own, never added to this one (decision (b); since 2.1.0). Staff's Record this
     * refund books it; a resolution releases it.
     *
     * @param object $payment The payment.
     * @param string $key The request's key.
     * @param Money $figure The provider's figure.
     * @param array $options The call's options ('gateway_response').
     * @param bool $surface Surfaced as money beyond the decision (the request_key lane) instead of the unreconciled
     *                      notification.
     * @param bool $held The provider holds it (pending).
     * @param object|null $resolved The kept outcome of the staff resolution that came first, when there is one.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    private static function keepForStaff(object $payment, string $key, Money $figure, array $options, bool $surface, bool $held, ?object $resolved = null): array
    {
        $paymentId = (int) $payment->id;
        $orderId = (int) $payment->id_order;
        $reference = self::REFUND_BEYOND_PREFIX . $key . ':' . PublishedDecimal::money(money: $figure);
        $existing = self::refundUnderReference($paymentId, $reference);

        // Staff resolved this word before (a row under its reference, released through the way out): it comes back
        // once; once staff resolved it again after it came back, the resolution stands.
        $last = $existing === null ? self::resolvedRelease(payment: $payment, rows: self::rowsOfReference(paymentId: $paymentId, reference: $reference)) : null;

        if ($last !== null) {
            $stands = self::resolutionStands(payment: $payment, resolved: $last, figure: $figure, named: $reference, settle: !$held);

            if ($stands !== null) {
                return $stands;
            }

            $resolved = $last;
        }

        if ($existing === null) {
            $data = [
                'id_order' => $orderId,
                'payment_type' => self::TYPE_REFUND,
                'transaction_status' => self::STATUS_PENDING,
                'amount' => $figure,
                'id_refunded_payment' => $paymentId,
                'refund_type' => self::REFUND_PARTIAL,
                'id_payment_method' => (int) ($payment->id_payment_method ?? 0),
                'transaction_id' => $reference,
            ] + array_filter(['gateway_response' => (string) ($options['gateway_response'] ?? '')]);
            $id = self::providerFact(static fn (): int|false => static::create(data: $data));
            $row = $id === false ? null : self::getRaw(id: (int) $id);

            if ($row !== null) {
                $why = $resolved === null
                    ? sprintf('no provider refund id and a figure other than the refund under refund request %s', $key)
                    : 'resolved by staff before';
                $kept = self::unreconciledSettle(row: $row, amount: $figure, providerRefundId: null, why: $why, resolved: $resolved, notify: !$surface, waitForStaff: true, held: $held);

                if ($surface) {
                    self::surfaceBeyondDecision(original: $payment, requestKey: $key, amount: $figure, reference: '', rowId: (int) $row->id, booked: false);
                }

                return $kept;
            }

            // Another delivery of the same word kept it first.
            $existing = self::refundUnderReference($paymentId, $reference);

            if ($existing === null) {
                return self::refundResult(outcome: self::OUTCOME_FAILED, reason: sprintf('the provider\'s word under refund request %s could not be kept; nothing was written: try again', $key));
            }
        }

        // A settle of a word it held for staff (or a hold of one it settled) is added to the row's report.
        $row = self::getRaw(id: (int) $existing->id);
        $added = $row === null ? null : self::keptWordAdded(row: $row, kind: $held ? self::REPORT_STATUS_PENDING : self::REPORT_STATUS_SETTLED, figure: $figure, providerRefundId: null);

        return $added ?? self::keptAnswer(row: $existing, what: sprintf('the provider\'s word of %s with no refund id under refund request %s', $figure->format(), $key));
    }

    /**
     * The answer to a provider word already on a row: kept (pending or authorized, it waits for its report's way
     * out: OUTCOME_UNRECONCILED, nothing written) or booked (OUTCOME_DUPLICATE).
     *
     * @param object $row The row (id, amount, transaction_status).
     * @param string $what How the word is named.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    private static function keptAnswer(object $row, string $what): array
    {
        // The row as it is now (round 8d, R8C-3): an answer is never built from a read taken before a write.
        $row = self::getRaw(id: (int) $row->id) ?? $row;

        return \in_array((string) $row->transaction_status, self::REFUND_RESERVING_STATUSES, true)
            ? self::refundResult(outcome: self::OUTCOME_UNRECONCILED, refundId: (int) $row->id, reason: sprintf('%s is kept on row #%d already; its report %s holds the refund block until staff record, reconcile or resolve it: nothing was written', $what, (int) $row->id, self::REPORT_RESERVATION_PREFIX . (int) $row->id))
            : self::refundResult(outcome: self::OUTCOME_DUPLICATE, refundId: (int) $row->id, reason: sprintf('%s is refund row #%d already', $what, (int) $row->id));
    }

    /**
     * Every refund row of one payment under one exact reference, in any status, oldest first.
     *
     * @param int $paymentId The payment.
     * @param string $reference The reference, compared byte for byte.
     *
     * @return list<object> id, amount, transaction_status, transaction_id.
     *
     * @since  2.1.0
     */
    private static function rowsOfReference(int $paymentId, string $reference): array
    {
        if ($paymentId <= 0 || $reference === '') {
            return [];
        }

        $db = self::db();

        return array_values(array_filter(
            $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'amount', 'transaction_status', 'transaction_id']))
                    ->from($db->quoteName(self::TABLE))
                    ->where($db->quoteName('id_refunded_payment') . ' = ' . $paymentId)
                    ->where($db->quoteName('payment_type') . ' = ' . $db->quote(self::TYPE_REFUND))
                    ->where($db->quoteName('transaction_id_hash') . ' = ' . $db->quote(self::referenceHash(reference: $reference)))
                    ->order($db->quoteName('id') . ' ASC'),
            )->loadObjectList() ?: [],
            static fn (object $row): bool => (string) $row->transaction_id === $reference,
        ));
    }

    /**
     * The answer to a provider word that repeats a refund staff resolved twice, or null. A provider refund (by its
     * id, or with none by its REFUND_BEYOND_PREFIX key and figure) blocks the order at most once after a staff
     * resolution: when the latest resolution was of a row that had already come back for staff (resurfaced after a
     * first resolution) and the word is the same (its figure), that resolution stands. A WARNING line in the payments
     * log, and nothing else: no row, no block, no notice; the answer is OUTCOME_DUPLICATE. Another figure (or another
     * provider id) is a new fact: null, and it is kept as usual. A word staff reconciled to a refund in the books, at
     * that refund's figure, is that refund: OUTCOME_DUPLICATE naming it, nothing written (since 2.1.0).
     *
     * @param object $payment The payment (id).
     * @param object $resolved The latest kept outcome of a staff resolution of this word (report_id, outcome).
     * @param Money $figure The provider's figure now.
     * @param string $named The provider's refund id, or the core's reference of a word with none.
     * @param bool $settle The word is a settle (money moved). Staff who resolved a word the provider only HELD
     *                     (its report pending) never resolved this settle: it comes back once, as settled.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}|null
     *
     * @since  2.1.0
     */
    private static function resolutionStands(object $payment, object $resolved, Money $figure, string $named, bool $settle = true): ?array
    {
        // Staff reconciled this provider refund to a refund in the books: the same figure IS that refund.
        $reconciled = (string) $resolved->outcome === self::REPORT_RECONCILED && (int) ($resolved->id_refund_row ?? 0) > 0
            ? self::getRaw(id: (int) $resolved->id_refund_row)
            : null;

        // Only while that refund stands: one staff set failed or cancelled since represents nothing.
        if ($reconciled !== null && !\in_array((string) $reconciled->transaction_status, self::REFUND_SETTLED_STATUSES, true)) {
            $reconciled = null;
        }

        if ($reconciled !== null && $figure->getMinorUnits() === self::money($reconciled->amount, (int) $reconciled->id_order)->abs()->getMinorUnits()) {
            return self::refundResult(outcome: self::OUTCOME_DUPLICATE, refundId: (int) $reconciled->id, reason: sprintf('staff reconciled this provider refund (%s) to refund #%d (%s): the same figure is that refund; nothing was written', $named, (int) $reconciled->id, (string) $resolved->report_id), amount: (float) $figure->getAmount());
        }

        $rowId = self::reservationOfReport(reportId: (string) $resolved->report_id);
        $row = $rowId > 0 ? self::getRaw(id: $rowId) : null;
        $mark = $row === null ? null : (self::unreconciledMarks(orderId: (int) $row->id_order)[$rowId] ?? null);

        // The figure the word came back with: its mark's (the provider's), else the row's.
        $was = $mark === null || $mark['amount'] === '' ? $row?->amount : $mark['amount'];

        if ($row === null || $mark === null || !$mark['resurfaced'] || ($settle && $mark['held'])
            || $figure->getMinorUnits() !== self::money((string) $was, (int) $row->id_order)->abs()->getMinorUnits()) {
            return null;
        }

        try {
            self::ensureLogger();
            Log::add(
                sprintf('Payment #%d: provider refund %s (%s) was reported again after staff resolved it a second time (%s, %s on row #%d); the resolution stands, nothing was written.', (int) $payment->id, $named, $figure->format(), (string) $resolved->report_id, (string) $resolved->outcome, $rowId),
                Log::WARNING,
                'com_alfa.payments',
            );
        } catch (Throwable) {
            // The answer below carries the same fact.
        }

        return self::refundResult(outcome: self::OUTCOME_DUPLICATE, refundId: $rowId, reason: sprintf('this provider refund was resolved by staff again after it came back (%s, %s): the resolution stands; nothing was written', (string) $resolved->report_id, (string) $resolved->outcome), amount: (float) $figure->getAmount());
    }

    /**
     * The kept outcome by which staff released one of these rows through the way out (its core report
     * REPORT_RESERVATION_PREFIX and the row id resolved as a duplicate or invalid report, or reconciled to a
     * refund already booked), the latest first, or null. Never asked while staff themselves take a report through
     * the way out.
     *
     * @param object $payment The payment (id, payment_method_type).
     * @param list<object> $rows The rows (id, transaction_status).
     *
     * @return object|null The outcome row (report_id, outcome, provider_refund_id, created).
     *
     * @since  2.1.0
     */
    private static function resolvedRelease(object $payment, array $rows): ?object
    {
        if (self::$staffWayOut) {
            return null;
        }

        foreach (array_reverse($rows) as $row) {
            if ((string) $row->transaction_status !== self::STATUS_CANCELLED) {
                continue;
            }

            $kept = self::keptOutcome(element: (string) ($payment->payment_method_type ?? ''), paymentId: (int) $payment->id, reportId: self::REPORT_RESERVATION_PREFIX . (int) $row->id);

            if ($kept !== null && \in_array((string) $kept->outcome, [self::REPORT_RECONCILED, self::REPORT_DISMISSED], true)) {
                return $kept;
            }
        }

        return null;
    }

    /**
     * Tell staff that a provider moved money under a refund request beyond what was decided and reserved for
     * it: the order's timeline (EVENT_REFUND_BEYOND_DECISION), the payments log (CRITICAL) and a danger
     * notification, with the request's key and the figures. Best effort: the booking or the kept row stands.
     *
     * @param object $original The payment.
     * @param string $requestKey The request's key.
     * @param Money $amount The provider's figure.
     * @param string $reference The provider's refund id, or ''.
     * @param int $rowId The row that books it, or that keeps it.
     * @param bool $booked True when the books hold it.
     *
     * @since  2.1.0
     */
    private static function surfaceBeyondDecision(object $original, string $requestKey, Money $amount, string $reference, int $rowId, bool $booked): void
    {
        $paymentId = (int) $original->id;
        $orderId = (int) $original->id_order;
        $db = self::db();

        // Once per provider refund: a retry (the same row) tells nobody again.
        $told = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_order_activity_log'))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->where($db->quoteName('event') . ' = ' . $db->quote(self::EVENT_REFUND_BEYOND_DECISION))
                ->where($db->quoteName('entity_id') . ' = ' . $rowId),
        )->loadResult();

        if ($told > 0) {
            return;
        }
        $summary = sprintf(
            'Payment #%d: the provider refunded %s under refund request %s%s, beyond what was decided and reserved for it; %s.',
            $paymentId,
            $amount->format(),
            $requestKey,
            $reference !== '' ? ' (provider refund ' . $reference . ')' : '',
            $booked
                ? sprintf('booked as refund #%d', $rowId)
                : sprintf('the books refused it: row #%d keeps it and holds the refund block until its report %s goes through the way out', $rowId, self::REPORT_RESERVATION_PREFIX . $rowId),
        );

        try {
            OrderModel::logOrderActivity(
                $orderId,
                self::EVENT_REFUND_BEYOND_DECISION,
                $summary,
                [
                    'id_payment' => $paymentId,
                    'id_refund' => $rowId,
                    'request_key' => $requestKey,
                    'provider_refund_id' => $reference,
                    'provider_amount' => PublishedDecimal::money(money: $amount),
                    'provider_currency' => $amount->getCurrency()->getCode(),
                    'booked' => $booked ? 1 : 0,
                ],
                $rowId,
            );
        } catch (Throwable) {
            // The notification and the log below carry the same fact.
        }

        try {
            self::ensureLogger();
            Log::add($summary, Log::CRITICAL, 'com_alfa.payments');
            NotificationHelper::push(
                'payments.refund.beyond.' . $paymentId . '.' . $rowId,
                'Payment #' . $paymentId . ': the provider refunded beyond the staff decision',
                [
                    'severity' => 'danger',
                    'group' => 'Payments',
                    'message' => $summary,
                    'url' => 'index.php?option=com_alfa&task=order.edit&id=' . $orderId,
                    'view' => ['action' => 'alfa.orders.view', 'asset' => 'com_alfa'],
                    'link' => ['action' => 'alfa.orders.edit', 'asset' => 'com_alfa'],
                ],
            );
        } catch (Throwable) {
            // The answer to the gateway carries the outcome.
        }
    }

    /**
     * Whether the books cannot hold a provider's figure on a payment: above the payment's books remainder or
     * above what the order's books leave. Only then is a refused settle unreconciled; a write refused for
     * another reason (the order held by another writer past the lock wait, a database refusal) wrote nothing
     * and is answered as a failure to retry.
     *
     * @param int $paymentId The payment.
     * @param int $orderId Its order.
     * @param Money $amount The provider's figure.
     *
     * @since  2.1.0
     */
    private static function booksRefuse(int $paymentId, int $orderId, Money $amount): bool
    {
        $payment = self::getRaw(id: $paymentId);

        return $payment === null
            || $amount->greaterThan(self::bookedRemainder(original: $payment))
            || $amount->greaterThan(self::bookedRefundable(orderId: $orderId));
    }

    /**
     * The answer to a settle that could not be written now and wrote nothing: retry.
     *
     * @param int $rowId The row the settle named.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    private static function retryLater(int $rowId): array
    {
        return self::refundResult(outcome: self::OUTCOME_FAILED, reason: sprintf('the settle of reservation #%d could not be written now (the order is busy, or the database refused it); nothing was written: try again', $rowId));
    }

    /**
     * A part of a reservation (a provider refund under its own id, below what the reservation holds) that the
     * books refused: the money moved. It is split off into a pending row of its own (its figure, its id;
     * the reservation lowered by as much, so nothing is counted twice), and that row is marked unreconciled
     * and listed on its own; the rest of the reservation and every other refused part stay as they are.
     *
     * @param object $row The reservation.
     * @param Money $amount The provider's figure.
     * @param string $providerRefundId The provider's refund id.
     * @param string $why What refused it.
     * @param bool $booksChecked The caller established why the books do not take it ($why).
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    private static function refusedPart(object $row, Money $amount, string $providerRefundId, string $why, bool $booksChecked = false): array
    {
        $orderId = (int) $row->id_order;
        $paymentId = (int) $row->id_refunded_payment;

        if (!$booksChecked && !self::booksRefuse(paymentId: $paymentId, orderId: $orderId, amount: $amount)) {
            return self::retryLater(rowId: (int) $row->id);
        }

        $child = [
            'id_order' => $orderId,
            'payment_type' => self::TYPE_REFUND,
            'transaction_status' => (string) $row->transaction_status,
            'amount' => $amount,
            'id_refunded_payment' => $paymentId,
            'refund_type' => self::REFUND_PARTIAL,
            'id_payment_method' => (int) ($row->id_payment_method ?? 0),
            'transaction_id' => $providerRefundId,
        ] + array_filter(['refund_reason' => (string) ($row->refund_reason ?? '')]);
        $split = self::providerFact(static fn (): array => self::splitReservation(row: $row, amount: $amount, child: $child));

        if ($split['outcome'] === 'whole') {
            return self::unreconciledSettle(row: $split['row'], amount: $amount, providerRefundId: $providerRefundId, why: $why, booksChecked: $booksChecked);
        }

        $part = $split['outcome'] === self::OUTCOME_OK ? self::getRaw(id: (int) $split['id']) : null;

        return $part === null
            ? self::refundResult(outcome: $split['outcome'] === self::OUTCOME_RACED ? self::OUTCOME_RACED : self::OUTCOME_FAILED, reason: (string) ($split['reason'] ?? sprintf('reservation #%d could not be split; nothing was written', (int) $row->id)))
            : self::unreconciledSettle(row: $part, amount: $amount, providerRefundId: $providerRefundId, why: $why, booksChecked: $booksChecked);
    }

    /**
     * Another provider refund than the one a reservation carries, on its own row beside it (a reservation
     * carries at most one provider refund): settled, judged by the books' caps alone and kept as an
     * unreconciled pending row of its own when they refuse it; or pending, the provider's word held as it is.
     * The reservation is never lowered, overwritten or released for it.
     *
     * @param object $row The reservation.
     * @param Money $amount The provider's figure.
     * @param string $providerRefundId The provider's refund id.
     * @param array $options The outcome call's options.
     * @param bool $settled True for a settle, false for a hold.
     * @param string|null $keep Why a settle is not booked whatever the books could hold (its payment is no longer
     *                          collected, {@see self::uncollected()}): kept on its own pending row at once.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    private static function ownRow(object $row, Money $amount, string $providerRefundId, array $options, bool $settled, ?string $keep = null): array
    {
        $orderId = (int) $row->id_order;
        $paymentId = (int) $row->id_refunded_payment;
        $data = [
            'id_order' => $orderId,
            'payment_type' => self::TYPE_REFUND,
            'transaction_status' => $settled ? self::STATUS_REFUNDED : self::STATUS_PENDING,
            'amount' => $amount,
            'id_refunded_payment' => $paymentId,
            'refund_type' => self::refundTypeFor(paymentId: $paymentId, amount: $amount),
            'id_payment_method' => (int) ($row->id_payment_method ?? 0),
        ] + self::outcomeColumns(row: $row, providerRefundId: $providerRefundId, options: $options) + array_filter(['refund_reason' => (string) ($row->refund_reason ?? '')]);

        if (!$settled) {
            $id = self::providerFact(static fn (): int|false => static::create(data: $data));

            return $id === false
                ? self::refundResult(outcome: self::OUTCOME_FAILED, reason: sprintf('provider refund %s of payment #%d could not be held on its own row; nothing was written', $providerRefundId, $paymentId))
                : self::refundResult(outcome: self::OUTCOME_RESERVED, refundId: (int) $id, amount: (float) $amount->getAmount());
        }

        $id = $keep === null ? static::create(data: $data + ['processed_at' => (string) ($options['processed_at'] ?? Factory::getDate('now', 'UTC')->toSql())]) : false;

        if ($id !== false) {
            self::flipIfCovered(paymentId: $paymentId, orderId: $orderId);

            return self::refundResult(outcome: self::OUTCOME_OK, refundId: (int) $id, amount: (float) $amount->getAmount());
        }

        if ($keep === null && !self::booksRefuse(paymentId: $paymentId, orderId: $orderId, amount: $amount)) {
            return self::retryLater(rowId: (int) $row->id);
        }

        // The money moved and the books cannot hold it: its own pending row keeps the provider's word and its mark.
        $pending = self::providerFact(static fn (): int|false => static::create(data: ['transaction_status' => self::STATUS_PENDING] + $data));
        $kept = $pending === false ? null : self::getRaw(id: (int) $pending);

        return $kept === null
            ? self::refundResult(outcome: self::OUTCOME_FAILED, reason: sprintf('provider refund %s of payment #%d could not be kept on its own row; nothing was written', $providerRefundId, $paymentId))
            : self::unreconciledSettle(row: $kept, amount: $amount, providerRefundId: $providerRefundId, why: $keep ?? 'refused by the books', booksChecked: $keep !== null);
    }

    /**
     * Flip a payment to refunded once its settled refunds cover it: the merchant-visible
     * phase (status_on_refunded fires), after the booking committed. Whether they cover
     * it is asked under the order's lock, from the books as they are then (never from a
     * decision taken before the lock: a concurrent settle or a refund set failed
     * meanwhile counts). Only settled refunds decide it; a reservation never does.
     *
     * @param int $paymentId The payment.
     * @param int $orderId Its order.
     *
     * @since  2.1.0
     */
    private static function flipIfCovered(int $paymentId, int $orderId): void
    {
        // Inside a provider word's frame the flip waits for the outermost commit: decided then under its own lock,
        // its status automation runs with no lock held (round 8d, {@see self::inWord()}).
        if (self::$wordOrder !== 0 && self::$wordDefersFlip && TransactionState::isOpen(self::db())) {
            PostCommit::defer(db: self::db(), label: sprintf('flip payment #%d once its settled refunds cover it', $paymentId), work: static function () use ($paymentId, $orderId): void {
                self::flipIfCovered(paymentId: $paymentId, orderId: $orderId);
            });

            return;
        }

        $verdict = self::updateIfStatus(
            $paymentId,
            ['id_order' => $orderId, 'transaction_status' => self::STATUS_REFUNDED],
            self::STATUS_COMPLETED,
            precondition: static function () use ($paymentId, $orderId): bool {
                $payment = self::getRaw(id: $paymentId);

                return $payment !== null
                    && !self::money(self::sumRefundsAgainst($paymentId), $orderId)->lessThan(self::money($payment->amount, $orderId)->abs());
            },
        );

        // Raced: not covered under the lock, or no longer completed (flipped by the other writer).
        if ($verdict === self::WRITE_FAILED) {
            self::warn(sprintf('flipIfCovered(): payment #%d is covered by its settled refunds but could not be marked refunded; it stays as it is', $paymentId));
        }
    }

    /**
     * Run one write of the core from a provider's own word: a refund row that is not settled (pending or
     * authorized) and only adds to what is reserved, so the books' over-refund guards and the start decision
     * do not judge it ({@see self::$providerFact}); a settled row is judged as always. The flag is put back
     * whatever happens.
     *
     * @param Closure $write fn(): mixed The write.
     *
     * @return mixed What the write answered.
     *
     * @since  2.1.0
     */
    private static function providerFact(Closure $write): mixed
    {
        $was = self::$providerFact;
        self::$providerFact = true;

        try {
            return $write();
        } finally {
            self::$providerFact = $was;
        }
    }

    /**
     * Whether a row is written through the provider-fact door: the door is open and the row is a refund that is
     * not settled (pending or authorized).
     *
     * @param array $data The row (payment_type, transaction_status).
     *
     * @since  2.1.0
     */
    private static function providerFactRow(array $data): bool
    {
        return self::$providerFact
            && (string) ($data['payment_type'] ?? '') === self::TYPE_REFUND
            && \in_array((string) ($data['transaction_status'] ?? ''), self::REFUND_RESERVING_STATUSES, true);
    }

    /**
     * The provider refund id a refund row carries, or null while it carries a request key or nothing.
     *
     * @param object $row The row (transaction_id).
     *
     * @since  2.1.0
     */
    private static function providerIdOf(object $row): ?string
    {
        $reference = (string) ($row->transaction_id ?? '');

        return $reference === '' || str_starts_with($reference, self::REPORT_REQUEST_PREFIX) || str_starts_with($reference, self::REFUND_BEYOND_PREFIX) ? null : $reference;
    }

    /**
     * The request key a refund row's reference carries (REPORT_REQUEST_PREFIX or REFUND_BEYOND_PREFIX and the key,
     * with a figure after it for a word kept for staff), or null.
     *
     * @param string $reference The row's transaction_id.
     *
     * @since  2.1.0
     */
    private static function requestKeyOf(string $reference): ?string
    {
        foreach ([self::REPORT_REQUEST_PREFIX, self::REFUND_BEYOND_PREFIX] as $prefix) {
            if (str_starts_with($reference, $prefix)) {
                $key = explode(':', substr($reference, \strlen($prefix)), 2)[0];

                return $key !== '' ? $key : null;
            }
        }

        return null;
    }

    /**
     * Why the order's books no longer hold a payment's money, or null while they do: the payment is not completed
     * or refunded any more (set pending or cancelled by hand), or it is gone. A provider's word about a refund of
     * it is then kept, unreconciled, whatever the order's other payments hold.
     *
     * @param int $paymentId The payment.
     *
     * @since  2.1.0
     */
    private static function uncollected(int $paymentId): ?string
    {
        $payment = $paymentId > 0 ? self::getRaw(id: $paymentId) : null;
        $status = (string) ($payment->transaction_status ?? '');

        if ($payment !== null && \in_array($status, [self::STATUS_COMPLETED, self::STATUS_REFUNDED], true)) {
            return null;
        }

        return $payment === null
            ? sprintf('payment #%d is gone: the order\'s books do not hold its money', $paymentId)
            : sprintf('payment #%d is %s: the order\'s books no longer hold its money', $paymentId, $status);
    }

    /**
     * The core's own reference of a provider word with no refund id and no request key that the books refused: one
     * such word per payment and figure, one kept row (a repeat finds it).
     *
     * @param int $paymentId The payment.
     * @param Money $amount The provider's figure.
     *
     * @since  2.1.0
     */
    private static function bareReference(int $paymentId, Money $amount): string
    {
        return self::REFUND_BEYOND_PREFIX . 'bare-' . $paymentId . ':' . PublishedDecimal::money(money: $amount);
    }

    /**
     * Money a provider moved that refundPayment() with gateway_moved could not book because the books refused it (over
     * the payment's or the order's room, the payment not collected or already refunded): kept, never refused (rulings
     * 43-45, as settleRefund() keeps a settle above the books). A pending row of its own carries the provider's figure
     * and its refund id (with none, the core's reference {@see self::bareReference()}), marked unreconciled, listed
     * and holding the refund block; the way out decides, and a retry books it once the books have room. Not asked for
     * a word under a request key (money beyond a decision keeps its own), inside staff's way out, for a write that
     * threw (nothing is kept: refund_id 0), or for a figure the order cannot carry. The CRITICAL log line stays.
     *
     * @param int $paymentId The payment.
     * @param Money|null $amount The provider's figure; null keeps nothing.
     * @param array $options refundPayment()'s options.
     * @param array $result What refundPayment() answered.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}|null The kept answer, or null.
     *
     * @since  2.1.0
     */
    private static function keepRefusedMoney(int $paymentId, ?Money $amount, array $options, array $result): ?array
    {
        $refused = \in_array($result['outcome'], [self::OUTCOME_UNRECONCILED, self::OUTCOME_ALREADY_REFUNDED, self::OUTCOME_NOTHING_LEFT, self::OUTCOME_NOT_REFUNDABLE], true)
            && (int) $result['refund_id'] === 0;

        if (!$refused || $amount === null || self::$staffWayOut || self::$writeThrew
            || !(bool) ($options['gateway_moved'] ?? false) || trim((string) ($options['request_key'] ?? '')) !== '') {
            return null;
        }

        $payment = self::getRaw(id: $paymentId);

        if ($payment === null || (string) $payment->payment_type !== self::TYPE_PAYMENT) {
            return null;
        }

        $orderId = (int) $payment->id_order;

        try {
            if (self::reservationAmountProblem(amount: $amount, currency: OrderCurrency::of(orderId: $orderId)) !== null) {
                return null;
            }
        } catch (OrderCurrencyException) {
            return null;
        }

        $providerId = (string) ($options['transaction_id'] ?? '');
        $reference = $providerId !== '' ? $providerId : self::bareReference(paymentId: $paymentId, amount: $amount);
        $existing = self::refundUnderReference($paymentId, $reference);

        if ($existing === null) {
            $data = [
                'id_order' => $orderId,
                'payment_type' => self::TYPE_REFUND,
                'transaction_status' => self::STATUS_PENDING,
                'amount' => $amount,
                'id_refunded_payment' => $paymentId,
                'refund_type' => self::REFUND_PARTIAL,
                'id_payment_method' => (int) ($payment->id_payment_method ?? 0),
                'transaction_id' => $reference,
            ] + array_filter(['gateway_response' => (string) ($options['gateway_response'] ?? ''), 'refund_reason' => mb_substr((string) ($options['reason'] ?? ''), 0, 255)]);
            $id = self::providerFact(static fn (): int|false => static::create(data: $data));
            $row = $id === false ? null : self::getRaw(id: (int) $id);

            if ($row !== null) {
                return self::unreconciledSettle(row: $row, amount: $amount, providerRefundId: $providerId !== '' ? $providerId : null, why: 'refused by the books (' . $result['outcome'] . ': ' . $result['reason'] . ')', booksChecked: true);
            }

            $existing = self::refundUnderReference($paymentId, $reference);
        }

        if ($existing === null) {
            return null;
        }

        // A reserving row under that reference (a reservation the provider held under its id): the word is that row's
        // settle, which keeps it marked, listed and blocking whatever the payment's state (since 2.1.0).
        return \in_array((string) $existing->transaction_status, self::REFUND_RESERVING_STATUSES, true)
            ? self::settleRefund(reservationId: (int) $existing->id, amount: $amount, providerRefundId: $providerId !== '' ? $providerId : null, options: ['final' => true] + array_intersect_key($options, ['gateway_response' => true, 'reason' => true, 'plugin' => true, 'report_id' => true]))
            : self::keptAnswer(row: $existing, what: sprintf('the provider\'s word of %s', $amount->format()));
    }

    /**
     * Money a provider moved (or holds) that a payment plugin reports for a payment that is not its own by the
     * payment's recorded type ({@see self::pluginRefusal()}): never refused. It is kept for staff on a pending row of
     * its own (its provider id; with none and a request key, REFUND_BEYOND_PREFIX, the key, ':' and the figure; with
     * neither, no reference: not idempotent, as the bare lane), marked to wait for staff, listed against its payment
     * and holding the refund block; the way out decides (Record this refund, reconcile, duplicate or invalid). A
     * provider id another row already carries answers as that row: a reserving row is marked to keep the word, a
     * kept one answers kept, a settled one is a duplicate.
     *
     * @param object $payment The payment (id, id_order, id_payment_method).
     * @param Money|null $amount The provider's figure; null = the payment's books remainder.
     * @param array $options 'transaction_id' (the provider's refund id), 'request_key', 'gateway_response'.
     * @param string $why The ownership refusal, kept in the mark.
     * @param bool $held The provider holds it (pending) rather than settled it.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float}
     *
     * @since  2.1.0
     */
    private static function keepNotTheirs(object $payment, ?Money $amount, array $options, string $why, bool $held): array
    {
        $paymentId = (int) $payment->id;
        $orderId = (int) $payment->id_order;
        $figure = $amount ?? self::bookedRemainder(original: $payment);
        $problem = self::reservationAmountProblem(amount: $figure, currency: OrderCurrency::of(orderId: $orderId));

        if ($problem !== null) {
            return self::refundResult(outcome: self::OUTCOME_REFUSED, reason: $why . '; ' . $problem);
        }

        $providerId = (string) ($options['transaction_id'] ?? '');
        $key = trim((string) ($options['request_key'] ?? ''));
        $reference = $providerId !== '' ? $providerId : ($key !== '' ? self::REFUND_BEYOND_PREFIX . $key . ':' . PublishedDecimal::money(money: $figure) : '');
        $existing = $reference === '' ? null : self::refundUnderReference($paymentId, $reference);

        if ($existing === null) {
            $data = [
                'id_order' => $orderId,
                'payment_type' => self::TYPE_REFUND,
                'transaction_status' => self::STATUS_PENDING,
                'amount' => $figure,
                'id_refunded_payment' => $paymentId,
                'refund_type' => self::REFUND_PARTIAL,
                'id_payment_method' => (int) ($payment->id_payment_method ?? 0),
            ] + array_filter(['transaction_id' => $reference, 'gateway_response' => (string) ($options['gateway_response'] ?? '')]);
            $id = self::providerFact(static fn (): int|false => static::create(data: $data));
            $row = $id === false ? null : self::getRaw(id: (int) $id);

            if ($row !== null) {
                return self::unreconciledSettle(row: $row, amount: $figure, providerRefundId: $providerId !== '' ? $providerId : null, why: $why, waitForStaff: true, held: $held);
            }

            $existing = $reference === '' ? null : self::refundUnderReference($paymentId, $reference);
        }

        if ($existing === null) {
            return self::refundResult(outcome: self::OUTCOME_FAILED, reason: sprintf('%s; the provider\'s word could not be kept for staff; nothing was written: try again', $why));
        }

        // A reserving row under that id the core has not marked yet: it keeps the word for staff.
        if (\in_array((string) $existing->transaction_status, self::REFUND_RESERVING_STATUSES, true) && !isset(self::unreconciledMarks(orderId: $orderId)[(int) $existing->id])) {
            $row = self::getRaw(id: (int) $existing->id);

            if ($row !== null) {
                return self::unreconciledSettle(row: $row, amount: $figure, providerRefundId: $providerId !== '' ? $providerId : null, why: $why, waitForStaff: true, held: $held);
            }
        }

        // A later word about the word kept on that row: added to its report.
        $kept = self::getRaw(id: (int) $existing->id);
        $added = $kept === null ? null : self::keptWordAdded(row: $kept, kind: $held ? self::REPORT_STATUS_PENDING : self::REPORT_STATUS_SETTLED, figure: $figure, providerRefundId: $providerId !== '' ? $providerId : null);

        return $added ?? self::keptAnswer(row: $existing, what: sprintf('the provider\'s word %s', $reference));
    }

    /**
     * Why a payment plugin may not act on a payment, or null: a plugin acts only on its own payments' refunds.
     * Asked only when the call names its plugin ('plugin', which PaymentsPlugin passes). A start or a release is
     * refused; money the provider moved or holds is kept for staff instead ({@see self::keepNotTheirs()}).
     *
     * @param object $payment The payment row (id, payment_method_type).
     * @param array $options The call's options.
     *
     * @since  2.1.0
     */
    private static function pluginRefusal(object $payment, array $options): ?string
    {
        if (!isset($options['plugin'])) {
            return null;
        }

        $element = (string) ($payment->payment_method_type ?? '');

        return $element === (string) $options['plugin']
            ? null
            : sprintf('payment #%d is not a payment of the %s plugin (%s): a gateway acts only on its own payments\' refunds', (int) $payment->id, (string) $options['plugin'], $element !== '' ? $element : 'no plugin');
    }

    /**
     * Why an order may not be deleted for its refunds, or null: a refund row of it that is not settled
     * (pending or authorized: a gateway's request in flight, a provider refund held, one whose money the
     * provider moved and the books do not hold yet, a refund typed and not settled) reserves its amount, and
     * deleting the order would discard it with no trace. Asked by every order delete path (the order model:
     * the admin list, the edit screen, the REST API), before any plugin is asked and again under the order's
     * lock. A settled, failed or cancelled refund does not stand in the way here.
     *
     * @param int $orderId The order.
     *
     * @return string|null The refusal, translated, naming each such row; null when none.
     *
     * @since  2.1.0
     */
    public static function orderDeleteRefusal(int $orderId): ?string
    {
        if ($orderId <= 0) {
            return null;
        }

        $db = self::db();
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'amount', 'transaction_status']))
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->where($db->quoteName('payment_type') . ' = ' . $db->quote(self::TYPE_REFUND))
                ->whereIn($db->quoteName('transaction_status'), self::REFUND_RESERVING_STATUSES, ParameterType::STRING)
                ->order($db->quoteName('id') . ' ASC'),
        )->loadObjectList() ?: [];

        if ($rows === []) {
            return null;
        }

        $items = array_map(
            static fn (object $row): string => sprintf('#%d (%s, %s)', (int) $row->id, self::money($row->amount, $orderId)->abs()->format(), Text::_('COM_ALFA_STATUS_' . strtoupper((string) $row->transaction_status))),
            $rows,
        );

        return Text::sprintf('COM_ALFA_ORDER_DELETE_REFUND_RESERVED', $orderId, implode(', ', $items));
    }

    /**
     * The one figure every refund form of a payment offers as its maximum, a refund start or a record-only form
     * alike: what may still be refunded on the payment ({@see self::refundableRemainder()}: its books remainder
     * less its own reservations) held to what the order may still refund ({@see self::refundableNow()}: its books
     * less every reservation the core counts). The refund block is asked apart ({@see self::refundStartRefusal()}).
     * Zero unless the payment is completed: only a completed payment holds money a refund may start on (every
     * start and every booking of a refund of any other payment is refused). Read only.
     *
     * @param int $paymentId The payment.
     *
     * @return Money In the order's currency, never negative; zero when the row is not a completed payment.
     *
     * @since  2.1.0
     */
    public static function refundFormMaximum(int $paymentId): Money
    {
        // Asked first: for a row that does not exist it is the zero in the currency a new order takes.
        $remainder = self::refundableRemainder($paymentId);
        $payment = $paymentId > 0 ? self::getRaw(id: $paymentId) : null;

        if ($payment === null
            || (string) ($payment->payment_type ?? '') !== self::TYPE_PAYMENT
            || (string) ($payment->transaction_status ?? '') !== self::STATUS_COMPLETED) {
            return Money::zero($remainder->getCurrency());
        }

        $now = self::refundableNow(orderId: (int) $payment->id_order);

        return $remainder->lessThan($now) ? $remainder : $now;
    }

    /**
     * A new idempotency key for a refund reservation: a lowercase RFC 4122 version 4
     * UUID from 122 random bits, never derived from anything that can repeat.
     *
     * @since  2.1.0
     */
    private static function newRequestKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * The refund row of one payment that already carries a provider reference.
     *
     * {@see self::rowUnderReference()} for refunds: one provider refund is one row.
     *
     * @param int $paymentId The original payment's PK.
     * @param string $reference The refund's transaction_id; '' names nothing.
     *
     * @return object|null The earliest matching row (id, amount, transaction_status,
     *                     transaction_id), or null.
     *
     * @since  2.0.0
     */
    private static function refundUnderReference(int $paymentId, string $reference): ?object
    {
        return self::rowUnderReference('id_refunded_payment', $paymentId, self::TYPE_REFUND, $reference);
    }

    /**
     * The row of one type, linked to one row, that already carries a provider reference.
     *
     * Only rows of this type linked to THIS row count, in any status but failed
     * or cancelled: a failed or cancelled row is
     * history and reserves nothing, so a genuine booking under its reference
     * writes a new row beside it.
     *
     * The reference is the complete value: the column stores it whole, and the
     * lookup key is the SHA-256 the database generates from it.
     * The key narrows; the match is made byte for
     * byte here, so letter case and trailing spaces count.
     *
     * @param string $linkColumn id_refunded_payment (refund, chargeback) or
     *                           id_reversed_payment (reversal). Internal callers only.
     * @param int $linkId The row the wanted row names.
     * @param string $type The wanted row's payment_type.
     * @param string $reference The provider reference; '' names nothing.
     *
     * @return object|null The earliest matching row (id, amount, transaction_status,
     *                     transaction_id), or null.
     *
     * @since  2.0.0
     */
    private static function rowUnderReference(string $linkColumn, int $linkId, string $type, string $reference): ?object
    {
        if ($reference === '' || $linkId <= 0) {
            return null;
        }

        $db = self::db();

        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'amount', 'transaction_status', 'transaction_id']))
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName($linkColumn) . ' = ' . $linkId)
                ->where($db->quoteName('payment_type') . ' = ' . $db->quote($type))
                ->where($db->quoteName('transaction_status') . ' NOT IN (' . $db->quote(self::STATUS_FAILED) . ', ' . $db->quote(self::STATUS_CANCELLED) . ')')
                ->where($db->quoteName('transaction_id_hash') . ' = ' . $db->quote(self::referenceHash(reference: $reference)))
                ->order($db->quoteName('id') . ' ASC'),
        )->loadObjectList() ?: [];

        foreach ($rows as $row) {
            if ((string) $row->transaction_id === $reference) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The refund or chargeback row of one payment that carries this exact provider reference.
     *
     * For gateways: the row a provider's refund-failed or dispute event is about.
     * A failed or cancelled row reserves nothing and is never returned.
     *
     * @param int $paymentId The payment the row takes money back from.
     * @param string $type TYPE_REFUND or TYPE_CHARGEBACK; any other gives null.
     * @param string $reference The complete provider reference, compared byte for byte.
     *
     * @return object|null id, amount, transaction_status and transaction_id; or null.
     *
     * @since  2.0.0
     */
    public static function findLinkedRow(int $paymentId, string $type, string $reference): ?object
    {
        if (!\in_array($type, [self::TYPE_REFUND, self::TYPE_CHARGEBACK], true)) {
            return null;
        }

        return self::rowUnderReference('id_refunded_payment', $paymentId, $type, $reference);
    }

    /**
     * What of a refund or a chargeback has not been reversed yet.
     *
     * @param int $rowId The refund or chargeback row.
     *
     * @return Money The row's amount minus its completed reversals, never negative;
     *               zero for a missing row or another type.
     *
     * @since  2.0.0
     */
    public static function unreversedRemainder(int $rowId): Money
    {
        $row = $rowId > 0 ? self::getRaw($rowId) : null;

        // A row that does not exist belongs to no order.
        if ($row === null) {
            return Money::zero(OrderCurrency::forNewOrder());
        }

        $orderId = (int) $row->id_order;

        if (!\in_array((string) ($row->payment_type ?? ''), [self::TYPE_REFUND, self::TYPE_CHARGEBACK], true)) {
            return Money::zero(self::orderCurrency($orderId));
        }

        $db = self::db();

        $reversed = (string) $db->setQuery(
            $db->getQuery(true)
                ->select('COALESCE(SUM(ABS(' . $db->quoteName('amount') . ')), 0)')
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('id_reversed_payment') . ' = ' . (int) $row->id)
                ->where(self::reversalPredicate()),
        )->loadResult();

        return self::money($row->amount, $orderId)->abs()
            ->subtract(self::money($reversed, $orderId))
            ->nonNegative();
    }

    /**
     * Whether a refund of this amount would be a full or a partial refund.
     *
     * Full only when the refunds alone cover the payment: a chargeback is not a
     * refund and never makes one full, so status_on_refunded never fires because
     * of one. refundPayment() decides by it.
     *
     * @param int $paymentId The payment to refund.
     * @param Money|null $amount The refund, in the order's currency; null means
     *                           the refundable remainder.
     *
     * @return string REFUND_FULL or REFUND_PARTIAL. A payment that does not
     *                exist answers partial: no refund of it can be full.
     *
     * @since  2.0.0
     */
    public static function refundTypeFor(int $paymentId, ?Money $amount): string
    {
        $original = self::getRaw($paymentId);

        if (!$original) {
            return self::REFUND_PARTIAL;
        }

        $orderId = (int) $original->id_order;
        $requested = $amount ?? self::refundableRemainder($paymentId);
        $refundRoom = self::money($original->amount, $orderId)->abs()
            ->subtract(self::money(self::sumRefundsAgainst($paymentId), $orderId));

        return $requested->lessThan($refundRoom) ? self::REFUND_PARTIAL : self::REFUND_FULL;
    }

    /**
     * Record that a booked refund, or a chargeback, did not stand at the provider.
     *
     * Records a fact the provider has already stated and calls no gateway. The
     * refund or chargeback row is never edited: a new 'reversal' row names it.
     *
     * When a reversed refund leaves its payment with money held again, the
     * payment's bookkeeping is corrected (refunded -> completed) and nothing
     * else moves: no status_on_completed, no order status, no email, no
     * entitlement.
     *
     * Every answer is an outcome, except a failure that ended the transaction
     * this was called inside: that one is rethrown to its caller.
     *
     * @param int $rowId The refund or chargeback row that did not stand.
     * @param Money|null $amount What did not stand, in the order's currency; null
     *                           means everything not yet reversed.
     * @param array $options 'transaction_id' (required: the provider's reference
     *                       for this event, stored whole), 'reason' (stored in
     *                       the note), 'gateway_response', 'processed_at' (UTC
     *                       Y-m-d H:i:s; absent means now).
     *
     * @return array{outcome:string,row_id:int,reason:string,amount:float,reopened:bool}
     *                                                                                   OUTCOME_RECORDED, OUTCOME_DUPLICATE, OUTCOME_NOTHING_LEFT,
     *                                                                                   OUTCOME_REFUSED (a retry cannot help) or OUTCOME_FAILED (lock
     *                                                                                   or database; a retry may succeed).
     *
     * @since  2.0.0
     */
    public static function recordReversal(int $rowId, ?Money $amount = null, array $options = []): array
    {
        // Plain text in every reason: each sink escapes it once (the action
        // page, the plugins' logs and notices).
        $reference = (string) ($options['transaction_id'] ?? '');
        $reopened = false;
        $p = null;
        $nested = TransactionState::isOpen(self::db());

        try {
            // 1. The target.
            $target = self::getRaw($rowId);

            if (!$target) {
                return self::factResult(self::OUTCOME_REFUSED, reason: sprintf('row #%d does not exist', $rowId));
            }

            $orderId = (int) $target->id_order;
            $kind = (string) ($target->payment_type ?? '');

            // No fact is recorded on an order that names no currency.
            try {
                OrderCurrency::of(orderId: $orderId);
            } catch (OrderCurrencyException $e) {
                return self::factResult(self::OUTCOME_REFUSED, reason: $e->getMessage());
            }

            // 2. Only a refund or a chargeback can be reversed.
            if (!\in_array($kind, [self::TYPE_REFUND, self::TYPE_CHARGEBACK], true)) {
                return self::factResult(self::OUTCOME_REFUSED, reason: sprintf(
                    'row #%d is a %s; only a refund or a chargeback can be reversed',
                    $rowId,
                    $kind !== '' ? $kind : '?',
                ));
            }

            // 3. The provider's reference.
            if ($reference === '') {
                return self::factResult(self::OUTCOME_REFUSED, reason: "a reversal must carry the provider's reference");
            }

            // 4. One event, one row.
            $booked = self::rowUnderReference('id_reversed_payment', $rowId, self::TYPE_REVERSAL, $reference);

            if ($booked !== null) {
                return self::factResult(self::OUTCOME_DUPLICATE, reason: sprintf(
                    'reversal %s is already recorded against row #%d as row #%d: a repeat of the same event, nothing written',
                    $reference,
                    $rowId,
                    (int) $booked->id,
                ));
            }

            // 5. Only money that moved can come back.
            $status = (string) ($target->transaction_status ?? '');

            if ($kind === self::TYPE_REFUND && !\in_array($status, [self::STATUS_REFUNDED, self::STATUS_COMPLETED], true)) {
                return self::factResult(self::OUTCOME_REFUSED, reason: sprintf('refund #%d is %s; only a settled refund can be reversed', $rowId, $status));
            }

            if ($kind === self::TYPE_CHARGEBACK && $status !== self::STATUS_COMPLETED) {
                return self::factResult(self::OUTCOME_REFUSED, reason: sprintf('chargeback #%d is %s; only a recorded chargeback can be reversed', $rowId, $status));
            }

            // 6. No provider can fail a refund to the wallet.
            if (\in_array((string) ($target->payment_method_type ?? ''), self::NON_GATEWAY_TYPES, true)) {
                return self::factResult(self::OUTCOME_REFUSED, reason: sprintf(
                    "refund #%d returned money to the customer's wallet: no provider can fail it; cancel that refund instead",
                    $rowId,
                ));
            }

            // 7. The amount, when one is given.
            $refusal = self::factAmountRefusal(amount: $amount, orderId: $orderId, noun: 'reversal', verb: 'requested', zeroHint: '; omit the amount to reverse what is left');

            if ($refusal !== null) {
                return self::factResult(self::OUTCOME_REFUSED, reason: $refusal);
            }

            // 8. The provider's event time.
            if (!self::validProcessedAt($options)) {
                return self::factResult(self::OUTCOME_REFUSED, reason: 'processed_at must be a UTC Y-m-d H:i:s');
            }

            $db = self::db();
            $db->transactionStart(true);

            try {
                if (!self::lockOrders($db, $orderId)) {
                    TransactionState::rollBackLevel($db);

                    return self::factResult(self::OUTCOME_FAILED, reason: sprintf('order #%d is held by another writer', $orderId));
                }

                // 9. Decided under the lock: two concurrent retries cannot both pass.
                $target = self::getRaw($rowId);

                if (!$target || (int) $target->id_order !== $orderId) {
                    TransactionState::rollBackLevel($db);

                    return self::factResult(self::OUTCOME_FAILED, reason: sprintf(
                        'row #%d moved or vanished while the ledger waited for its order; nothing written',
                        $rowId,
                    ));
                }

                $kind = (string) ($target->payment_type ?? '');
                $status = (string) ($target->transaction_status ?? '');

                // Check 2 again: an edit may have retyped the row while the verb waited.
                if (!\in_array($kind, [self::TYPE_REFUND, self::TYPE_CHARGEBACK], true)) {
                    TransactionState::rollBackLevel($db);

                    return self::factResult(self::OUTCOME_REFUSED, reason: sprintf(
                        'row #%d is a %s; only a refund or a chargeback can be reversed',
                        $rowId,
                        $kind !== '' ? $kind : '?',
                    ));
                }

                // Check 4 again, under the lock.
                $booked = self::rowUnderReference('id_reversed_payment', $rowId, self::TYPE_REVERSAL, $reference);

                if ($booked !== null) {
                    TransactionState::rollBackLevel($db);

                    return self::factResult(self::OUTCOME_DUPLICATE, reason: sprintf(
                        'reversal %s is already recorded against row #%d as row #%d: a repeat of the same event, nothing written',
                        $reference,
                        $rowId,
                        (int) $booked->id,
                    ));
                }

                // Check 5 again, under the lock.
                if ($kind === self::TYPE_REFUND && !\in_array($status, [self::STATUS_REFUNDED, self::STATUS_COMPLETED], true)) {
                    TransactionState::rollBackLevel($db);

                    return self::factResult(self::OUTCOME_REFUSED, reason: sprintf('refund #%d is %s; only a settled refund can be reversed', $rowId, $status));
                }

                if ($kind === self::TYPE_CHARGEBACK && $status !== self::STATUS_COMPLETED) {
                    TransactionState::rollBackLevel($db);

                    return self::factResult(self::OUTCOME_REFUSED, reason: sprintf('chargeback #%d is %s; only a recorded chargeback can be reversed', $rowId, $status));
                }

                // Check 6 again, under the lock.
                if (\in_array((string) ($target->payment_method_type ?? ''), self::NON_GATEWAY_TYPES, true)) {
                    TransactionState::rollBackLevel($db);

                    return self::factResult(self::OUTCOME_REFUSED, reason: sprintf(
                        "refund #%d returned money to the customer's wallet: no provider can fail it; cancel that refund instead",
                        $rowId,
                    ));
                }

                // 10. What is left to reverse.
                $left = self::unreversedRemainder($rowId);

                if (!$left->isPositive()) {
                    TransactionState::rollBackLevel($db);

                    return self::factResult(self::OUTCOME_NOTHING_LEFT, reason: sprintf('row #%d is already reversed in full', $rowId));
                }

                // 11. Never more than is left.
                $amount ??= $left;

                if ($amount->greaterThan($left)) {
                    TransactionState::rollBackLevel($db);

                    return self::factResult(self::OUTCOME_REFUSED, reason: sprintf(
                        'requested %s, %s of row #%d is left to reverse',
                        $amount->format(),
                        $left->format(),
                        $rowId,
                    ));
                }

                // 12. The fact row. Class, name and method id come from the target.
                $data = [
                    'id_order' => $orderId,
                    'payment_type' => self::TYPE_REVERSAL,
                    'transaction_status' => self::STATUS_COMPLETED,
                    'amount' => $amount,
                    'id_reversed_payment' => $rowId,
                    'id_payment_method' => (int) ($target->id_payment_method ?? 0),
                    'transaction_id' => $reference,
                ] + self::factOptions($options);

                $newId = self::createLocked($data, $orderId, 0.0, ledgerFact: true);

                if ($newId === false) {
                    TransactionState::rollBackLevel($db);

                    return self::factResult(self::OUTCOME_FAILED, reason: 'the reversal row was rejected by the ledger');
                }

                // 13. The bookkeeping flip: a payment a full refund flipped
                // holds money again, so its row reads completed again. Compare-and-set
                // under this lock; no cap, no status automation (the order is not
                // moved, no email is sent). An order that no longer covers the money
                // reads as overpaid, which OrderFacts shows.
                $paymentId = $kind === self::TYPE_REFUND ? (int) ($target->id_refunded_payment ?? 0) : 0;
                $p = self::reopenIfUncovered(db: $db, paymentId: $paymentId, orderId: $orderId);
                $reopened = $p !== null;
            } catch (Throwable $e) {
                TransactionState::rollBackLevel($db, $e);

                throw $e;
            }

            TransactionState::commitLevel($db);
        } catch (Throwable $e) {
            // A failure that ended the caller's transaction is the caller's to hear.
            if ($nested && !TransactionState::isOpen(self::db())) {
                throw $e;
            }

            return self::factFailure(verb: 'recordReversal', rowId: $rowId, error: $e);
        }

        // After the commit, never on a rollback path. Each step is best-effort:
        // the fact is durable, so none of them can change the answer.
        self::afterFact(newId: (int) $newId, orderId: $orderId, amount: $amount, targetId: $rowId, kind: $kind === self::TYPE_REFUND ? 'refund_reversed' : 'chargeback_reversed');

        if ($reopened && $p !== null) {
            try {
                self::applyWriteSideEffects(
                    id:          $paymentId,
                    orderId:     $orderId,
                    oldSnapshot: $p,
                    data:        ['id_order' => $orderId, 'transaction_status' => self::STATUS_COMPLETED],
                    bookkeeping: true,
                );
            } catch (Throwable $e) {
                self::ensureLogger();
                Log::add(
                    sprintf('recordReversal(): row #%d is recorded, but the edit entry of payment #%d failed: %s', (int) $newId, $paymentId, $e->getMessage()),
                    Log::ERROR,
                    'com_alfa.payments',
                );
            }
        }

        return self::factResult(self::OUTCOME_RECORDED, (int) $newId, amount: $amount->getAmount(), reopened: $reopened);
    }

    /**
     * Record money the customer's bank took back from a collected gateway payment.
     *
     * An orthogonal fact: the payment row is not flipped and no order status,
     * entitlement or email moves. The amount is recorded as the provider reports it.
     *
     * Every answer is an outcome, except a failure that ended the transaction
     * this was called inside: that one is rethrown to its caller. A won
     * dispute is recorded with {@see self::recordReversal()} against the
     * chargeback row.
     *
     * @param int $paymentId The completed gateway payment.
     * @param Money $amount What the bank took back, in the order's currency.
     *                      Never capped: a bank can take back a whole payment
     *                      after a partial refund.
     * @param array $options As {@see self::recordReversal()}.
     *
     * @return array{outcome:string,row_id:int,reason:string,amount:float,reopened:bool}
     *                                                                                   As recordReversal(); 'reopened' is always false.
     *
     * @since  2.0.0
     */
    public static function recordChargeback(int $paymentId, Money $amount, array $options = []): array
    {
        // Plain text in every reason: each sink escapes it once.
        $reference = (string) ($options['transaction_id'] ?? '');
        $nested = TransactionState::isOpen(self::db());

        try {
            // 1. A payment.
            $payment = self::getRaw($paymentId);

            if (!$payment) {
                return self::factResult(self::OUTCOME_REFUSED, reason: sprintf('payment #%d does not exist', $paymentId));
            }

            $orderId = (int) $payment->id_order;
            $kind = (string) ($payment->payment_type ?? '');

            // No fact is recorded on an order that names no currency.
            try {
                OrderCurrency::of(orderId: $orderId);
            } catch (OrderCurrencyException $e) {
                return self::factResult(self::OUTCOME_REFUSED, reason: $e->getMessage());
            }

            if ($kind !== self::TYPE_PAYMENT) {
                return self::factResult(self::OUTCOME_REFUSED, reason: sprintf(
                    'row #%d is a %s; only a payment can be charged back',
                    $paymentId,
                    $kind !== '' ? $kind : '?',
                ));
            }

            // 2. The provider's reference.
            if ($reference === '') {
                return self::factResult(self::OUTCOME_REFUSED, reason: "a chargeback must carry the provider's reference");
            }

            // 3. One event, one row.
            $booked = self::rowUnderReference('id_refunded_payment', $paymentId, self::TYPE_CHARGEBACK, $reference);

            if ($booked !== null) {
                return self::factResult(self::OUTCOME_DUPLICATE, reason: sprintf(
                    'chargeback %s is already recorded against payment #%d as row #%d: a repeat of the same event, nothing written',
                    $reference,
                    $paymentId,
                    (int) $booked->id,
                ));
            }

            // 4. Against a completed payment only.
            $status = (string) ($payment->transaction_status ?? '');

            if ($status !== self::STATUS_COMPLETED) {
                return self::factResult(self::OUTCOME_REFUSED, reason: sprintf('payment #%d is %s; only a completed payment can be charged back', $paymentId, $status));
            }

            // 5. No bank stands behind wallet money.
            if ((string) ($payment->payment_method_type ?? '') === self::METHOD_TYPE_CREDIT || (int) ($payment->id_credit_transaction ?? 0) > 0) {
                return self::factResult(self::OUTCOME_REFUSED, reason: sprintf("payment #%d was paid from the customer's wallet; a bank cannot charge it back", $paymentId));
            }

            // 6. The amount and the event time.
            $refusal = self::factAmountRefusal(amount: $amount, orderId: $orderId, noun: 'chargeback', verb: 'reported', zeroHint: '');

            if ($refusal !== null) {
                return self::factResult(self::OUTCOME_REFUSED, reason: $refusal);
            }

            if (!self::validProcessedAt($options)) {
                return self::factResult(self::OUTCOME_REFUSED, reason: 'processed_at must be a UTC Y-m-d H:i:s');
            }

            $db = self::db();
            $db->transactionStart(true);

            try {
                if (!self::lockOrders($db, $orderId)) {
                    TransactionState::rollBackLevel($db);

                    return self::factResult(self::OUTCOME_FAILED, reason: sprintf('order #%d is held by another writer', $orderId));
                }

                $payment = self::getRaw($paymentId);

                if (!$payment || (int) $payment->id_order !== $orderId) {
                    TransactionState::rollBackLevel($db);

                    return self::factResult(self::OUTCOME_FAILED, reason: sprintf(
                        'row #%d moved or vanished while the ledger waited for its order; nothing written',
                        $paymentId,
                    ));
                }

                $kind = (string) ($payment->payment_type ?? '');
                $status = (string) ($payment->transaction_status ?? '');

                // Check 1's type test again: an edit may have retyped the row.
                if ($kind !== self::TYPE_PAYMENT) {
                    TransactionState::rollBackLevel($db);

                    return self::factResult(self::OUTCOME_REFUSED, reason: sprintf(
                        'row #%d is a %s; only a payment can be charged back',
                        $paymentId,
                        $kind !== '' ? $kind : '?',
                    ));
                }

                // Check 3 again, under the lock.
                $booked = self::rowUnderReference('id_refunded_payment', $paymentId, self::TYPE_CHARGEBACK, $reference);

                if ($booked !== null) {
                    TransactionState::rollBackLevel($db);

                    return self::factResult(self::OUTCOME_DUPLICATE, reason: sprintf(
                        'chargeback %s is already recorded against payment #%d as row #%d: a repeat of the same event, nothing written',
                        $reference,
                        $paymentId,
                        (int) $booked->id,
                    ));
                }

                // Check 4 again, under the lock.
                if ($status !== self::STATUS_COMPLETED) {
                    TransactionState::rollBackLevel($db);

                    return self::factResult(self::OUTCOME_REFUSED, reason: sprintf('payment #%d is %s; only a completed payment can be charged back', $paymentId, $status));
                }

                // Check 5 again, under the lock.
                if ((string) ($payment->payment_method_type ?? '') === self::METHOD_TYPE_CREDIT || (int) ($payment->id_credit_transaction ?? 0) > 0) {
                    TransactionState::rollBackLevel($db);

                    return self::factResult(self::OUTCOME_REFUSED, reason: sprintf("payment #%d was paid from the customer's wallet; a bank cannot charge it back", $paymentId));
                }

                // The fact row, never capped. Class, name and method id come from the payment.
                $data = [
                    'id_order' => $orderId,
                    'payment_type' => self::TYPE_CHARGEBACK,
                    'transaction_status' => self::STATUS_COMPLETED,
                    'amount' => $amount,
                    'id_refunded_payment' => $paymentId,
                    'id_payment_method' => (int) ($payment->id_payment_method ?? 0),
                    'transaction_id' => $reference,
                ] + self::factOptions($options);

                $newId = self::createLocked($data, $orderId, 0.0, ledgerFact: true);

                if ($newId === false) {
                    TransactionState::rollBackLevel($db);

                    return self::factResult(self::OUTCOME_FAILED, reason: 'the chargeback row was rejected by the ledger');
                }
            } catch (Throwable $e) {
                TransactionState::rollBackLevel($db, $e);

                throw $e;
            }

            TransactionState::commitLevel($db);
        } catch (Throwable $e) {
            // A failure that ended the caller's transaction is the caller's to hear.
            if ($nested && !TransactionState::isOpen(self::db())) {
                throw $e;
            }

            return self::factFailure(verb: 'recordChargeback', rowId: $paymentId, error: $e);
        }

        // No flip, no side effects, no Transition: the timeline and the notice only.
        self::afterFact(newId: (int) $newId, orderId: $orderId, amount: $amount, targetId: $paymentId, kind: 'charged_back');

        return self::factResult(self::OUTCOME_RECORDED, (int) $newId, amount: $amount->getAmount());
    }

    /**
     * Why a fact's amount cannot be recorded, or null when it can.
     *
     * @param Money|null $amount The amount; null passes (a reversal of what is left).
     * @param int $orderId The order whose currency the amount must be in.
     * @param string $noun 'reversal' or 'chargeback'.
     * @param string $verb 'requested' or 'reported'.
     * @param string $zeroHint Appended to the zero refusal.
     *
     *
     * @since  2.0.0
     */
    private static function factAmountRefusal(?Money $amount, int $orderId, string $noun, string $verb, string $zeroHint): ?string
    {
        if ($amount === null) {
            return null;
        }

        if ($amount->isNegative()) {
            return sprintf('a %s amount cannot be negative', $noun);
        }

        if ($amount->isZero()) {
            return sprintf('a %s amount cannot be zero', $noun) . $zeroHint;
        }

        $orderCurrency = self::orderCurrency($orderId);

        if (!$amount->getCurrency()->equals($orderCurrency)) {
            return sprintf(
                '%s %s in %s, order #%d is in %s; convert before recording',
                $noun,
                $verb,
                $amount->getCurrency()->getCode(),
                $orderId,
                $orderCurrency->getCode(),
            );
        }

        return null;
    }

    /**
     * Whether a fact's 'processed_at' option is absent or a UTC Y-m-d H:i:s.
     *
     * @param array $options The verb's options.
     *
     *
     * @since  2.0.0
     */
    private static function validProcessedAt(array $options): bool
    {
        if (!\array_key_exists('processed_at', $options)) {
            return true;
        }

        return (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $options['processed_at']);
    }

    /**
     * The optional columns a fact row takes from the verb's options.
     *
     * @param array $options 'reason', 'gateway_response', 'processed_at'.
     *
     * @return array Columns to add to the row.
     *
     * @since  2.0.0
     */
    private static function factOptions(array $options): array
    {
        $columns = [];

        if ((string) ($options['reason'] ?? '') !== '') {
            $columns['note'] = mb_substr((string) $options['reason'], 0, 255);
        }

        if ((string) ($options['gateway_response'] ?? '') !== '') {
            $columns['gateway_response'] = (string) $options['gateway_response'];
        }

        if (\array_key_exists('processed_at', $options)) {
            $columns['processed_at'] = (string) $options['processed_at'];
        }

        return $columns;
    }

    /**
     * Answer a verb that threw: nothing is written, and the error is logged.
     *
     * @param string $verb The verb's name, for the log line.
     * @param int $rowId The row it was asked about.
     * @param Throwable $error What was thrown.
     *
     * @return array A FAILED result.
     *
     * @since  2.0.0
     */
    private static function factFailure(string $verb, int $rowId, Throwable $error): array
    {
        try {
            self::ensureLogger();
            Log::add(
                sprintf('%s(): row #%d: %s: %s', $verb, $rowId, $error::class, $error->getMessage()),
                Log::ERROR,
                'com_alfa.payments',
            );
        } catch (Throwable $logError) {
            // The answer below still says what happened.
        }

        return self::factResult(self::OUTCOME_FAILED, reason: 'the ledger could not record it: ' . $error->getMessage());
    }

    /**
     * The timeline entry and the admin notice of a committed fact row.
     *
     * Each runs on its own: a failure is logged ERROR with the row id and never
     * changes the verb's answer.
     *
     * @param int $newId The fact row just committed.
     * @param int $orderId Its order.
     * @param Money $amount Its amount.
     * @param int $targetId The refund, chargeback or payment it names.
     * @param string $kind 'refund_reversed', 'chargeback_reversed' or 'charged_back'.
     *
     *
     * @since  2.0.0
     */
    private static function afterFact(int $newId, int $orderId, Money $amount, int $targetId, string $kind): void
    {
        try {
            self::logFactRow($newId, $orderId);
        } catch (Throwable $e) {
            self::ensureLogger();
            Log::add(sprintf('fact row #%d is recorded, but its timeline entry failed: %s', $newId, $e->getMessage()), Log::ERROR, 'com_alfa.payments');
        }

        try {
            self::notifyFact(kind: $kind, newId: $newId, orderId: $orderId, amount: $amount, targetId: $targetId);
        } catch (Throwable $e) {
            self::ensureLogger();
            Log::add(sprintf('fact row #%d is recorded, but its notification failed: %s', $newId, $e->getMessage()), Log::ERROR, 'com_alfa.payments');
        }
    }

    /**
     * The sentence an OUTCOME_DUPLICATE carries.
     *
     * The reference comes from the provider and this sentence reaches the
     * operator's screen through the plugins' messages, so it is escaped here.
     *
     * @param int $paymentId The original payment's PK.
     * @param string $reference The repeated reference.
     * @param object $booked The refund row that already records it.
     *
     *
     * @since  2.0.0
     */
    private static function duplicateReason(int $paymentId, string $reference, object $booked): string
    {
        return sprintf(
            'refund %s is already recorded on payment #%d as refund row #%d: a repeat of the same refund, nothing written',
            htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $paymentId,
            (int) $booked->id,
        );
    }

    /**
     * Shape one refundPayment() verdict.
     *
     *
     * @return array{outcome:string,refund_id:int,reason:string,amount:float}
     *
     * @since  2.0.0
     */
    /**
     * Register the ledger's own file logger once per request.
     *
     * The lines below it are the ones a person has to act on: "the gateway
     * moved the money and the books did not". Joomla DROPS an entry whose
     * category no logger claims, and `com_alfa.payments` is the COMPONENT's
     * category: the gateways each own `com_alfa.payments.<name>` and their
     * own file, so none of them can be relied on to carry these. A site with
     * no payment plugin installed at all still runs this code.
     *
     *
     * @since  2.0.0
     */
    public static function ensureLogger(): void
    {
        // Delegated: AlfaLog owns the whole map. Kept as an entry point
        // because this ledger runs in requests that never boot com_alfa:
        // a com_ajax subscription renewal calls create() directly.
        // No guard here either: AlfaLog::register() is idempotent, and a
        // guard would go on claiming "done" after a Log::setInstance()
        // wiped the registry it was remembering.
        AlfaLog::register();
    }

    private static function refundResult(string $outcome, int $refundId = 0, string $reason = '', float $amount = 0.0): array
    {
        return [
            'outcome' => $outcome,
            'refund_id' => $refundId,
            'reason' => $reason,
            'amount' => $amount,
        ];
    }

    /**
     * Shape one recordReversal() / recordChargeback() verdict.
     *
     * @param string $outcome One of the OUTCOME_* constants.
     * @param int $rowId The fact row written (0 unless recorded).
     * @param string $reason Why, in plain words ('' when recorded).
     * @param float $amount The amount written.
     * @param bool $reopened A reversed refund put its payment back to completed.
     *
     * @return array{outcome:string,row_id:int,reason:string,amount:float,reopened:bool}
     *
     * @since  2.0.0
     */
    private static function factResult(string $outcome, int $rowId = 0, string $reason = '', float $amount = 0.0, bool $reopened = false): array
    {
        return [
            'outcome' => $outcome,
            'row_id' => $rowId,
            'reason' => $reason,
            'amount' => $amount,
            'reopened' => $reopened,
        ];
    }

    /**
     * Write the timeline entry for a committed reversal or chargeback row.
     *
     * Called after the transaction closes, as logCreatedRow() is. The amount is
     * shown at the order's frozen scale.
     *
     * @param int $rowId Committed fact row PK.
     * @param int $orderId Order PK.
     *
     *
     * @since  2.0.0
     */
    private static function logFactRow(int $rowId, int $orderId): void
    {
        $row = self::getRaw($rowId);

        if ($row === null) {
            return;
        }

        $method = (string) ($row->payment_method ?? '');
        $amount = self::money($row->amount, $orderId)->format();

        if ((string) $row->payment_type === self::TYPE_CHARGEBACK) {
            $event = 'payment.charged_back';
            $summary = sprintf('Charged back: %s - %s (payment #%d)', $method, $amount, (int) $row->id_refunded_payment);
        } else {
            $targetId = (int) $row->id_reversed_payment;
            $target = self::getRaw($targetId);
            $chargeback = $target !== null && (string) $target->payment_type === self::TYPE_CHARGEBACK;
            $event = $chargeback ? 'payment.chargeback_reversed' : 'payment.refund_reversed';
            $summary = $chargeback
                ? sprintf('Chargeback reversed: %s - %s (chargeback #%d)', $method, $amount, $targetId)
                : sprintf('Refund reversed: %s - %s (refund #%d)', $method, $amount, $targetId);
        }

        OrderModel::logOrderActivity(
            orderId:  $orderId,
            event:    $event,
            summary:  $summary,
            context:  [
                'amount' => (float) $row->amount,
                'payment_method' => $method,
                'transaction_id' => (string) ($row->transaction_id ?? ''),
                'id_reversed_payment' => $row->id_reversed_payment ?? null,
                'id_refunded_payment' => $row->id_refunded_payment ?? null,
                'note' => $row->note ?? null,
            ],
            entityId: $rowId,
        );
    }

    /**
     * Raise the admin notification for a committed fact row.
     *
     * Advisory, like a transition's notices: the caller wraps it and a failure
     * never changes the verb's answer.
     *
     * @param string $kind 'refund_reversed', 'chargeback_reversed' or 'charged_back'.
     * @param int $newId The fact row.
     * @param int $orderId Its order.
     * @param Money $amount Its amount.
     * @param int $targetId The refund, chargeback or payment it names.
     *
     *
     * @since  2.0.0
     */
    private static function notifyFact(string $kind, int $newId, int $orderId, Money $amount, int $targetId): void
    {
        // Raised from any request, a gateway webhook included, so the
        // administrator strings are loaded explicitly.
        Factory::getApplication()->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);

        [$key, $titleKey, $messageKey, $severity] = match ($kind) {
            'refund_reversed' => ['payments.refund.reversed.' . $newId, 'COM_ALFA_NOTIFY_REFUND_REVERSED_TITLE', 'COM_ALFA_NOTIFY_REFUND_REVERSED_MSG', 'danger'],
            'chargeback_reversed' => ['payments.chargeback.reversed.' . $newId, 'COM_ALFA_NOTIFY_CHARGEBACK_REVERSED_TITLE', 'COM_ALFA_NOTIFY_CHARGEBACK_REVERSED_MSG', 'success'],
            default => ['payments.chargeback.' . $newId, 'COM_ALFA_NOTIFY_CHARGEBACK_TITLE', 'COM_ALFA_NOTIFY_CHARGEBACK_MSG', 'danger'],
        };

        NotificationHelper::push(
            dedupKey: $key,
            title:    Text::sprintf($titleKey, $orderId),
            options:  [
                'severity' => $severity,
                'group' => 'Payments',
                'message' => Text::sprintf($messageKey, $amount->format(), $targetId),
                'url' => 'index.php?option=com_alfa&task=order.edit&id=' . $orderId,
                'view' => ['action' => 'alfa.orders.view', 'asset' => 'com_alfa'],
                'link' => ['action' => 'alfa.orders.edit', 'asset' => 'com_alfa'],
            ],
        );
    }

    /**
     * Log and mark a refund the provider settled against the ledger's check.
     *
     * The books follow the provider: the row is already settled and committed.
     * What is left is to make the case impossible to miss, in the form a
     * refund the gateway paid but the ledger did not record takes: a CRITICAL
     * line in the payments log, in the ledger's own words, and a notice in the
     * administrator's bell, in plain words, that links to the order. Neither
     * is ever fatal: a log or a notice that cannot be written does not turn a
     * committed settle into a failure for its caller.
     *
     * @param int $refundId The refund row just settled.
     * @param int $orderId Its order.
     * @param array $refusal The refusal the ledger would have given
     *                       ({@see self::refundDocumentRefusal()}).
     *
     *
     * @since  2.0.0
     */
    private static function markUnreconciledSettle(int $refundId, int $orderId, array $refusal): void
    {
        try {
            self::ensureLogger();
            Log::add(
                sprintf(
                    'Refund #%d of order #%d was settled as the provider confirmed it, against the ledger\'s check: %s; reconcile manually.',
                    $refundId,
                    $orderId,
                    rtrim((string) $refusal['reason'], '.'),
                ),
                Log::CRITICAL,
                'com_alfa.payments',
            );
        } catch (Throwable) {
            // The notice below still reaches staff.
        }

        try {
            // Raised from any request, a gateway webhook included, so the
            // administrator strings are loaded explicitly.
            Factory::getApplication()->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);

            $amount = $refusal['amount']->format();
            $message = match ($refusal['kind']) {
                'voided' => Text::sprintf('COM_ALFA_NOTIFY_REFUND_UNRECONCILED_VOIDED', $refundId, $amount, $refusal['document']),
                'order' => Text::sprintf('COM_ALFA_NOTIFY_REFUND_UNRECONCILED_OTHER_ORDER', $refundId, $amount, $refusal['documentId']),
                default => Text::sprintf(
                    'COM_ALFA_NOTIFY_REFUND_UNRECONCILED_CEILING',
                    $refundId,
                    $amount,
                    $refusal['document'],
                    $refusal['total']->format(),
                    $refusal['paid']->format(),
                ),
            };

            NotificationHelper::push(
                dedupKey: 'payments.refund.unreconciled.' . $refundId,
                title:    Text::sprintf('COM_ALFA_NOTIFY_REFUND_UNRECONCILED_TITLE', $orderId),
                options:  [
                    'severity' => 'danger',
                    'group' => 'Payments',
                    'message' => $message,
                    'url' => 'index.php?option=com_alfa&task=order.edit&id=' . $orderId,
                    'view' => ['action' => 'alfa.orders.view', 'asset' => 'com_alfa'],
                    'link' => ['action' => 'alfa.orders.edit', 'asset' => 'com_alfa'],
                ],
            );
        } catch (Throwable $e) {
            try {
                Log::add(
                    sprintf('Refund #%d of order #%d: the staff notice of its settle could not be written: %s', $refundId, $orderId, $e->getMessage()),
                    Log::ERROR,
                    'com_alfa.payments',
                );
            } catch (Throwable) {
                // Nothing is left to tell.
            }
        }
    }

    /**
     * Post-write work shared by update() and updateIfStatus(): change-diff
     * activity logging, download entitlements and payment-method status
     * automation. The CAS path calls it only on WRITE_OK, so the settle
     * side effects fire exactly once per payment.
     *
     * With $bookkeeping the merchant-visible half is suppressed; see the
     * seam comment at the transaction_status branch below. It runs only for
     * a row that takes money in ({@see self::takesMoneyIn()}).
     *
     * @param int $id Payment row PK just written
     * @param int $orderId Parent order PK
     * @param object $oldSnapshot Pre-write row snapshot
     * @param array $data Fields the write carried (amount already float)
     * @param bool $bookkeeping Suppress the merchant-visible side effects
     *
     * @since  2.0.0
     */
    private static function applyWriteSideEffects(
        int $id,
        int $orderId,
        object $oldSnapshot,
        array $data,
        bool $bookkeeping = false,
    ): void {
        // Diff ONLY the fields this call actually carries. The builder sends just
        // what was set, so diffing the whole list would read every absent column
        // as '' (buildDiff defaults a miss to '') and log a phantom change:
        // e.g. every refund reporting amount "100.000000 -> ''".
        $trackFields = array_intersect([
            'amount',
            'transaction_status',
            'payment_type',
            'id_payment_method',
            'payment_method',
            'transaction_id',
            'refund_type',
            'refund_reason',
            'id_document',
            'note',
            'processed_at',
        ], array_keys($data));

        $changes = AlfaHelper::buildDiff($oldSnapshot, $data, $trackFields);

        if (!empty($changes)) {
            $changedKeys = implode(', ', array_keys($changes));
            OrderModel::logOrderActivity(
                $orderId,
                'payment.edited',
                "Edited payment #{$id}: {$changedKeys}",
                $changes,
                $id,
            );
        }

        if (array_key_exists('transaction_status', $data)) {
            // SUPPRESSION SEAM (->bookkeeping() / $bookkeeping)
            // These two are the merchant-visible half of a status change:
            // Entitlements opens and closes download access for the WHOLE
            // order, and the automation map drives the ORDER's status through
            // Transition (status email, stock, activity entry). A bookkeeping
            // write records where one ROW ended up (it does not claim the
            // order reached a phase), so both are skipped, or closing a dead
            // gateway attempt would email a cancellation to a buyer who is
            // paying on another tab and revoke files another row paid for.
            // Only a row that takes the customer's money in speaks for the
            // order: a refund, reversal or chargeback records money going back,
            // so its status never grants files, retries a document or moves
            // the order's workflow.
            if (!$bookkeeping && self::takesMoneyIn(row: $oldSnapshot, data: $data)) {
                // A payment turning completed grants download entitlements;
                // this catches the admin mark-paid action AND every gateway
                // plugin writing through this helper. Idempotent, never throws.
                // Runs BEFORE the automation map so freshly minted raw tokens
                // are cached when the transition's status email renders links.
                Entitlements::handlePaymentTransition(
                    orderId:   $orderId,
                    oldStatus: (string) ($oldSnapshot->transaction_status ?? ''),
                    newStatus: (string) $data['transaction_status'],
                );

                // A status that issues documents only when paid skipped at
                // transition time while money was due; the order will not
                // transition again, so settling it is the second chance.
                AutoIssuePolicy::retryAfterPayment($orderId);

                self::applyStatusAutomation(
                    orderId:   $orderId,
                    payment:   $oldSnapshot,
                    oldStatus: (string) ($oldSnapshot->transaction_status ?? ''),
                    newStatus: (string) $data['transaction_status'],
                );
            }

            // NEVER suppressed: returning spent wallet money is money truth,
            // not presentation: leaving a customer's credit consumed by a
            // row the books just voided is the one outcome no caller wants.
            // THE WALLET LEG IS NOT HERE ANY MORE. It used to fire from this
            // post-commit block, which is why a compensating rollback could
            // not take the money back with it (the refunded->completed edge is
            // not a reversal trigger), and why a PHP Error thrown by a plugin
            // in Entitlements or applyStatusAutomation above (both caught
            // Exception, not Throwable) skipped the return while the status
            // flip stood. settleWalletForPayment() now runs inside the write's
            // own transaction; see update()/updateIfStatus().
        }
    }

    /**
     * Whether a status write on this row may act for the order: only a row
     * that takes the customer's money in, a payment or an authorization
     * holding it, both as stored and as the write leaves it.
     *
     * A refund, a reversal and a chargeback record money going back, or the
     * provider's account of it. Their status is the state of that movement,
     * never a phase of the order, so it grants no downloads, retries no
     * document and runs no status map.
     *
     * A write that also changes what the row is corrects the record; it does
     * not move a payment through its life. A refund saved as a payment is
     * that payment's first appearance and a payment saved as a refund is its
     * removal, and neither a row's creation nor its removal acts for the
     * order. A hold saved as a payment is still money taken in.
     *
     * @param object $row The row as read before the write.
     * @param array $data The fields the write carried.
     *
     *
     * @since  2.0.0
     */
    private static function takesMoneyIn(object $row, array $data): bool
    {
        $moneyIn = [self::TYPE_PAYMENT, self::TYPE_AUTHORIZATION];
        $stored = (string) ($row->payment_type ?? '');
        $written = (string) ($data['payment_type'] ?? '') !== ''
            ? (string) $data['payment_type']
            : $stored;

        return \in_array($stored, $moneyIn, true) && \in_array($written, $moneyIn, true);
    }

    /**
     * Payment-method automation: move the ORDER when a transaction enters a
     * phase the method has mapped.
     *
     * The map is PLUGIN-DECLARED: each payment plugin's params/params.xml
     * exposes only the phases its lifecycle reaches, as fields named
     * `status_on_<transaction_status>` (stored in the method's params JSON).
     * This helper just reads the conventional key and executes through
     * Transition, so stock/downloads/emails/activity-log can
     * never diverge from a manual admin change. Guards inherited from the
     * builder: same-status = no-op (duplicate callbacks harmless) and no
     * automatic exit from the cancelled family (no force() here, ever).
     *
     * Never throws; automation failure must not break the payment write.
     *
     *
     * @since  2.0.0
     */
    /**
     * Sum every refund already recorded against one payment.
     *
     * Counts only refund rows that actually moved money: the reference
     * plugins write them 'refunded', some gateways write 'completed'; a
     * cancelled or failed refund is not money back. Amounts are stored
     * positive, hence ABS().
     *
     * @param int $paymentId The original payment's PK
     *
     * @return float Total already refunded, in major units
     *
     * @since  2.0.0
     */
    /**
     * Net customer-credit ("wallet") amount settling this order's credit
     * notes. Void claw-backs are negative entries with the same source, so
     * the SUM self-corrects; clamped at zero against stray data.
     *
     *
     * @return float
     *
     * @since  2.0.0
     */
    /**
     * The ONE note shape for every wallet return of a credit payment.
     *
     * Both return paths (the partial refund's own hook and the status edge's
     * reversal) write through this, so "how much of payment #P has already
     * gone back?" is answerable by prefix. They used to spell it two different
     * ways ('Return: refund #R of credit payment #P' and 'Reversal: credit
     * payment #P (entry #N)'), and that was not a cosmetic split: each path's
     * idempotency check only ever saw its OWN spelling, so the two could not
     * see each other and a partial followed by a final returned the money
     * twice. Fixing the arithmetic without unifying the key would have left
     * the second route able to bypass the guard.
     *
     * The `(` delimiter matters: it is what stops payment #1's prefix matching
     * payment #10's rows.
     *
     * @param int $paymentId The credit payment being returned.
     *
     * @return string Prefix shared by every return note for that payment.
     *
     * @since  2.0.0
     */
    private static function walletReturnPrefix(int $paymentId): string
    {
        return 'Return: credit payment #' . $paymentId . ' (';
    }

    /**
     * How much of a credit payment's money is BACK in the wallet, net.
     *
     * SIGNED sum over the structural link: every return of that payment
     * (positive) minus every clawback (negative), and deliberately NOT the
     * spend itself, so this compares like-with-like against the target in
     * {@see self::settleWalletForPayment()}. Rows written against a REFUND of
     * this payment count too: that refund's wallet leg returns the same
     * payment's money.
     *
     * WHY A COLUMN AND NOT THE NOTE. This used to LIKE the note prefix, and
     * a note is display text that different writers spell differently: it
     * missed money returned by a CREDIT NOTE ('Credit note X') and money
     * returned by spendOnOrder's unwind ('Reversal: payment could not be
     * completed...'), so a payment whose money was already home read as "never
     * returned" and was returned again. Signed arithmetic over a real link
     * also makes idempotency a subtraction rather than a COUNT(*) on free
     * text with no unique index behind it.
     *
     * @param int $paymentId The credit payment.
     * @param int $orderId Its order; supplies the currency to round in.
     *
     * @return Money Net returned so far, in the ORDER'S OWN currency and scale.
     *
     * @since  2.0.0
     */
    private static function walletReturnedNet(int $paymentId, int $orderId): Money
    {
        if ($paymentId <= 0) {
            return Money::zero(self::orderCurrency($orderId));
        }

        $db = self::db();

        $db->setQuery(
            'SELECT COALESCE(SUM(t.' . $db->quoteName('amount') . '), 0)'
            . ' FROM ' . $db->quoteName('#__alfa_customer_credit_transactions', 't')
            . ' LEFT JOIN ' . $db->quoteName('#__alfa_order_payments', 'r')
            . ' ON r.' . $db->quoteName('id') . ' = t.' . $db->quoteName('id_order_payment')
            . ' AND r.' . $db->quoteName('payment_type') . ' = ' . $db->quote(self::TYPE_REFUND)
            . ' WHERE (t.' . $db->quoteName('id_order_payment') . ' = ' . $paymentId
            . ' OR r.' . $db->quoteName('id_refunded_payment') . ' = ' . $paymentId . ')'
            // Exclude the SPEND itself: it is the negative row that named this
            // payment, and the target below is "how much should be BACK".
            // EXCLUDE THE SPEND BY ITS EXACT IDENTITY: the entry the
            // payment row POINTS AT. Excluding it by SHAPE (negative + source
            // order + debit) also matched the CLAWBACK, which is negative,
            // order-sourced and a debit too: the clawback vanished from the
            // net, a fully-returned-then-reclaimed payment read as still
            // returned, and refundableNow reported 0 on an order that had
            // collected its money back.
            //
            // The identity is now the id_credit_transaction COLUMN. It used
            // to be recovered with a SQL SUBSTRING over transaction_id (an
            // admin-editable free-text field), so retyping a transaction
            // reference on the payment screen silently changed how much the
            // order could still refund. A pool that moves when someone
            // corrects a typo is not a pool.
            . ' AND t.' . $db->quoteName('id') . ' <> COALESCE(('
            . 'SELECT p2.' . $db->quoteName('id_credit_transaction')
            . ' FROM ' . $db->quoteName(self::TABLE, 'p2')
            . ' WHERE p2.' . $db->quoteName('id') . ' = ' . $paymentId
            . '), 0)',
        );

        return self::money((string) $db->loadResult(), $orderId);
    }

    /**
     * Wallet money returned for this order that NO settled refund row
     * accounts for: the term the refundable pool was missing.
     *
     * THE ASYMMETRY THIS EXISTS FOR. On the normal refund path a wallet
     * return is always accompanied by a settled refund row, and
     * sumSettledRefunds already counts that row; so simply adding wallet rows
     * to the pool's subtrahend would DOUBLE-count (a 100 credit payment with
     * a 40 partial would read 100 - 40 - 40 = 20 instead of 60, and the write
     * cap would then refuse a legitimate 60). Only the UNCOVERED excess is a
     * second claim on the money, so only the excess is subtracted.
     *
     * Scoped to payments the pool still counts (completed or refunded, the
     * SAME statusIn as {@see self::sumRefundablePool()}), load-bearing: a
     * cancelled credit payment has already left the pool, so subtracting its
     * return as well would eat a co-existing gateway payment's refundable
     * money.
     *
     * @param int $orderId The order.
     * @param int $excludeRefundId Refund row to ignore (the row being re-checked).
     * @param int $candidateOriginalId The original a not-yet-written refund names.
     * @param Money|string|float $candidateAmount That refund's amount, folded in as coverage,
     *                                            because on the refund path the wallet money is
     *                                            returned BEFORE its refund row exists.
     * @param int $excludePaymentId Payment to ignore (the one being reconciled).
     *
     * @return Money Uncovered wallet returns, in the order's own currency.
     *
     * @since  2.0.0
     */
    private static function sumUncoveredWalletReturns(
        int $orderId,
        int $excludeRefundId = 0,
        int $candidateOriginalId = 0,
        Money|string|float $candidateAmount = 0.0,
        int $excludePaymentId = 0,
    ): Money {
        $zero = Money::zero(self::orderCurrency(max(0, $orderId)));

        if ($orderId <= 0) {
            return $zero;
        }

        $db = self::db();

        $db->setQuery(
            'SELECT p.' . $db->quoteName('id') . ' AS ' . $db->quoteName('pid')
            . ', COALESCE((SELECT SUM(t.' . $db->quoteName('amount') . ')'
            . ' FROM ' . $db->quoteName('#__alfa_customer_credit_transactions', 't')
            . ' LEFT JOIN ' . $db->quoteName('#__alfa_order_payments', 'r2')
            . ' ON r2.' . $db->quoteName('id') . ' = t.' . $db->quoteName('id_order_payment')
            . ' AND r2.' . $db->quoteName('payment_type') . ' = ' . $db->quote(self::TYPE_REFUND)
            . ' WHERE (t.' . $db->quoteName('id_order_payment') . ' = p.' . $db->quoteName('id')
            . ' OR r2.' . $db->quoteName('id_refunded_payment') . ' = p.' . $db->quoteName('id') . ')'
            // Same exact-identity exclusion as walletReturnedNet(): the spend
            // is the entry this payment POINTS AT, never "any negative
            // order-sourced debit"; that shape is a clawback too. Through the
            // id_credit_transaction column for the same reason as there: the
            // transaction_id text it replaced is admin-editable, and a pool
            // that changes when someone corrects a typo is not a pool.
            . ' AND t.' . $db->quoteName('id') . ' <> COALESCE(p.'
            . $db->quoteName('id_credit_transaction') . ', 0)'
            . '), 0) AS ' . $db->quoteName('returned')
            . ', COALESCE((SELECT SUM(ABS(r.' . $db->quoteName('amount') . '))'
            . ' FROM ' . $db->quoteName('#__alfa_order_payments', 'r')
            . ' WHERE r.' . $db->quoteName('id_refunded_payment') . ' = p.' . $db->quoteName('id')
            . ' AND r.' . $db->quoteName('payment_type') . ' = ' . $db->quote(self::TYPE_REFUND)
            . ' AND r.' . $db->quoteName('transaction_status')
            . ' IN (' . $db->quote(self::STATUS_REFUNDED) . ', ' . $db->quote(self::STATUS_COMPLETED) . ')'
            . ($excludeRefundId > 0 ? ' AND r.' . $db->quoteName('id') . ' <> ' . $excludeRefundId : '')
            . '), 0) AS ' . $db->quoteName('settled')
            . ' FROM ' . $db->quoteName(self::TABLE, 'p')
            . ' WHERE p.' . $db->quoteName('id_order') . ' = ' . $orderId
            . ' AND p.' . $db->quoteName('payment_type') . ' = ' . $db->quote(self::TYPE_PAYMENT)
            . ' AND p.' . $db->quoteName('transaction_status')
            . ' IN (' . $db->quote(self::STATUS_COMPLETED) . ', ' . $db->quote(self::STATUS_REFUNDED) . ')'
            . ($excludePaymentId > 0 ? ' AND p.' . $db->quoteName('id') . ' <> ' . $excludePaymentId : ''),
        );

        $total = $zero;

        // Per payment, in the order's scale: only the EXCESS over what settled
        // refunds cover is a second claim on the money. nonNegative() is the
        // max(0, ...): a payment whose refunds exceed its wallet return does
        // not lend that surplus to another payment.
        foreach ($db->loadObjectList() ?: [] as $row) {
            $covered = self::money((string) $row->settled, $orderId);

            if ((int) $row->pid === $candidateOriginalId) {
                $covered = $covered->add(self::money($candidateAmount, $orderId));
            }

            $total = $total->add(
                self::money((string) $row->returned, $orderId)->subtract($covered)->nonNegative(),
            );
        }

        return $total;
    }

    /**
     * Everything this order has already returned, by every route.
     *
     * ONE definition, so the read-side ({@see self::refundableNow()}) and both
     * write-time caps cannot drift: when they drifted, the guard and the
     * preflight disagreed about the same order.
     *
     * @param int $orderId The order.
     * @param int $excludeId Refund row to leave out (being re-checked).
     * @param int $candidateOriginalId Original named by a refund about to be written.
     * @param Money|string|float $candidateAmount That refund's amount.
     *
     * @return Money Total returned, in the order's own currency and scale.
     *
     * @since  2.0.0
     */
    private static function returnedTotal(
        int $orderId,
        int $excludeId = 0,
        int $candidateOriginalId = 0,
        Money|string|float $candidateAmount = 0.0,
    ): Money {
        // Each term becomes Money in the ORDER'S currency before any
        // arithmetic: the pattern refundableNow() already used, and the
        // reason it stopped rounding to a hard-coded 6 places: the shop
        // ships 0-, 1-, 3- and 4-decimal currencies, so 6 is wrong for all
        // but one of them and silently invents sub-unit dust on the rest.
        return self::money(self::sumSettledRefunds($orderId, excludeId: $excludeId), $orderId)
            ->add(self::money($candidateAmount, $orderId))
            // Money the customer's bank took back has left as surely as a refund.
            ->add(self::money(self::sumChargebacks($orderId), $orderId))
            ->add(self::sumWalletSettled($orderId))
            ->add(self::sumUncoveredWalletReturns(
                orderId:             $orderId,
                excludeRefundId:     $excludeId,
                candidateOriginalId: $candidateOriginalId,
                candidateAmount:     $candidateAmount,
            ));
    }

    /**
     * What the order's credit notes put into the customer's credit account,
     * net of what was taken back, never below zero: the order's money.
     *
     * @param int $orderId Order PK.
     *
     *
     * @since  2.0.0
     */
    private static function sumWalletSettled(int $orderId): Money
    {
        $db = self::db();

        $query = $db->getQuery(true)
            ->select('COALESCE(SUM(' . $db->quoteName('t.amount') . '), 0)')
            ->from($db->quoteName('#__alfa_customer_credit_transactions', 't'))
            ->innerJoin(
                $db->quoteName('#__alfa_documents', 'd')
                . ' ON d.id = t.source_id',
            )
            ->where([
                $db->quoteName('t.source_type') . ' = ' . $db->quote('credit_note'),
                $db->quoteName('d.id_order') . ' = :orderId',
            ])
            ->bind(':orderId', $orderId);
        $db->setQuery(query: $query);

        return self::money((string) $db->loadResult(), $orderId)->nonNegative();
    }

    /**
     * Settled refunds recorded against one original payment, net of their reversals.
     *
     * Only rows the money actually left on (refunded or completed), so a
     * refund still pending never consumes the per-payment cap. A refund the
     * provider reversed gave nothing back for the part it reversed. Feeds both
     * that cap and {@see self::refundableRemainder()}.
     *
     * @param int $paymentId The original payment PK
     * @param int $excludeId Refund row PK to leave out, with its reversals:
     *                       the row being re-checked (0 = none)
     *
     * @return string Settled refund total in major units, as the SUM's decimal text
     *
     * @since  2.0.0
     */
    private static function sumRefundsAgainst(int $paymentId, int $excludeId = 0): string
    {
        if ($paymentId <= 0) {
            return '0';
        }

        return self::sumNetAmounts(
            type:       self::TYPE_REFUND,
            statusIn:   [self::STATUS_REFUNDED, self::STATUS_COMPLETED],
            refundedId: $paymentId,
            excludeId:  $excludeId,
        );
    }

    /**
     * Chargebacks recorded against one payment, net of their reversals (won disputes).
     *
     * @param int $paymentId The payment PK
     *
     * @return string Net chargeback total in major units, as the SUM's decimal text
     *
     * @since  2.0.0
     */
    private static function sumChargebacksAgainst(int $paymentId): string
    {
        if ($paymentId <= 0) {
            return '0';
        }

        return self::sumNetAmounts(
            type:       self::TYPE_CHARGEBACK,
            statusIn:   [self::STATUS_COMPLETED],
            refundedId: $paymentId,
        );
    }

    /**
     * SUM of a row's amount net of its completed reversals, over the rows of one
     * payment_type: the net sibling of {@see self::sumAbsAmounts()}.
     *
     * @param string $type payment_type to sum (refund or chargeback)
     * @param array $statusIn Count only these transaction_status values
     * @param int $orderId Scope: rows of this order (0 = unscoped)
     * @param int $refundedId Scope: rows naming this payment (0 = unscoped)
     * @param int $excludeId Row PK to leave out, with its reversals (0 = none)
     *
     * @return string Net total in major units, as the SUM's decimal text
     *
     * @since  2.0.0
     */
    private static function sumNetAmounts(
        string $type,
        array $statusIn,
        int $orderId = 0,
        int $refundedId = 0,
        int $excludeId = 0,
    ): string {
        // Never sum unscoped; that would cross order boundaries
        if ($orderId <= 0 && $refundedId <= 0) {
            return '0';
        }

        $db = self::db();
        $query = $db->getQuery(true)
            ->select('COALESCE(SUM(' . self::netAmountSql('r') . '), 0)')
            ->from($db->quoteName(self::TABLE, 'r'))
            ->where($db->quoteName('r.payment_type') . ' = ' . $db->quote($type))
            ->whereIn($db->quoteName('r.transaction_status'), $statusIn, ParameterType::STRING);

        if ($orderId > 0) {
            $query->where($db->quoteName('r.id_order') . ' = ' . $orderId);
        }

        if ($refundedId > 0) {
            $query->where($db->quoteName('r.id_refunded_payment') . ' = ' . $refundedId);
        }

        if ($excludeId > 0) {
            $query->where($db->quoteName('r.' . self::PK) . ' != ' . $excludeId);
        }

        $db->setQuery($query);

        return (string) $db->loadResult();
    }

    /**
     * A refund's or a chargeback's amount, net of its completed reversals, in SQL.
     *
     * @param string $alias Alias of the refund or chargeback row in the query.
     *
     * @return string An SQL expression; served by idx_id_reversed_payment.
     *
     * @since  2.0.0
     */
    private static function netAmountSql(string $alias): string
    {
        $db = self::db();

        return 'ABS(' . $db->quoteName($alias . '.amount') . ') - COALESCE((SELECT SUM(ABS(' . $db->quoteName('v.amount') . '))'
            . ' FROM ' . $db->quoteName(self::TABLE, 'v')
            . ' WHERE ' . $db->quoteName('v.id_reversed_payment') . ' = ' . $db->quoteName($alias . '.id')
            . ' AND ' . $db->quoteName('v.payment_type') . ' = ' . $db->quote(self::TYPE_REVERSAL)
            . ' AND ' . $db->quoteName('v.transaction_status') . ' = ' . $db->quote(self::STATUS_COMPLETED) . '), 0)';
    }

    /**
     * Return a cancelled/failed customer-credit payment to the wallet.
     *
    /**
     * In-memory grand total for the UPDATE-path cap re-check.
     *
     * create() takes its hint as an argument; update() is reached through
     * load()->save() with no such channel, so callers that KNOW the true
     * total (checkout, where the shipment row is not written yet) park it
     * here for the duration of one flip. Always reset in a finally.
     *
     * @since  2.0.0
     */
    private static float $updateGrandTotalHint = 0.0;

    /**
     * Why create() refused the refund start it was last asked to decide under the
     * order's lock (the refund block, or what the pending provider refunds leave),
     * so refundPayment() answers with it instead of a bare failure. Reset by
     * refundPayment() before and after each create().
     *
     * @var array{outcome: string, reason: string}|null
     *
     * @since  2.1.0
     */
    private static ?array $startRefused = null;

    /**
     * True only while the core writes a refund row that is not settled (pending or authorized) from a
     * provider's own word ({@see self::providerFact()}): a refused part split off its reservation, a held
     * figure grown, a different provider refund's own row. Such a row only adds to what is reserved, so the
     * books' over-refund guards and the start decision do not judge it; beyond the ceiling it is kept and
     * refundableNow() falls to zero. Never set by a caller.
     *
     * @since  2.1.0
     */
    private static bool $providerFact = false;

    /**
     * True only while staff take a report through the way out (Record this refund, its typed form): their own
     * decision books a provider refund that staff resolved before, or one that waits for them
     * ({@see self::resolvedByStaff()}). Never set by a caller.
     *
     * @since  2.1.0
     */
    private static bool $staffWayOut = false;

    /**
     * True only while splitReservation() writes a part inside its own transaction: create() then leaves the flip of
     * the payment to the settle, which asks it once the split has committed ({@see self::flipIfCovered()}). Never
     * set by a caller.
     *
     * @since  2.1.0
     */
    private static bool $deferFlip = false;

    /** @var bool Set while the outermost refundPayment() call runs ({@see self::keepRefusedMoney()}). */
    private static bool $keepsRefused = false;

    /**
     * The order whose provider word is being processed inside its one transaction under its lock
     * ({@see self::inWord()}); 0 outside every such frame. Never set by a caller.
     *
     * @since  2.1.0
     */
    private static int $wordOrder = 0;

    /**
     * True while the outermost provider word frame is the connection's outermost transaction: the flip of a payment
     * then waits for its commit ({@see self::flipIfCovered()}); inside a caller's transaction it runs there, as every
     * nested write's side effects do. Never set by a caller.
     *
     * @since  2.1.0
     */
    private static bool $wordDefersFlip = false;

    /** @var bool The current refundPayment() call's row write threw (a real failure to write: nothing is kept). */
    private static bool $writeThrew = false;

    /**
     * The refusal create() kept for the refund start it last decided under the
     * order's lock, cleared as it is read.
     *
     * @return array{outcome: string, reason: string}|null
     *
     * @since  2.1.0
     */
    private static function takeStartRefusal(): ?array
    {
        $refused = self::$startRefused;
        self::$startRefused = null;

        return $refused;
    }

    /**
     * Park (or clear with 0.0) the grand-total hint the next update-path cap
     * re-check should measure against. Scope it tightly: set, save, reset.
     *
     * @param float $total In-memory grand total, 0.0 to clear.
     *
     *
     * @since  2.0.0
     */
    public static function setUpdateGrandTotalHint(float $total): void
    {
        self::$updateGrandTotalHint = max(0.0, $total);
    }

    /**
     * Bring one credit payment's WALLET POSITION in line with the books.
     *
     * THE ONE WRITER. This replaces two uncoordinated hooks (a partial-refund
     * hook that fired from create(), and a status-edge reversal that fired
     * post-commit), which between them could return the same money twice,
     * return money nothing could see, or (on the compensation path) return
     * money and then leave it out when the payment came back.
     *
     * It is a RECONCILER, not an event handler: it asks what the books say
     * SHOULD be back (target), asks the ledger what IS back (actual), and
     * moves the difference. That is why it is idempotent for free, why the
     * order of the edges no longer matters, and why re-running it is always
     * safe. Nothing is keyed on a status word, a flag, or note text.
     *
     * TARGET, by the row's own state:
     *   cancelled | failed | refunded -> the whole spend (the row has left the
     *                                   collected set, so the money is owed back)
     *   completed                     -> what settled refunds against it justify
     *   pending | authorized          -> nothing yet
     *
     * MUST RUN INSIDE THE WRITE'S TRANSACTION, before it commits. The old
     * reversal ran post-commit, which meant a compensating rollback could not
     * take the money back with it, and a PHP Error thrown by a plugin earlier
     * in the post-commit block (Entitlements and applyStatusAutomation both
     * catch Exception, not Throwable) skipped the wallet leg entirely while
     * the status flip stood.
     *
     * @param int $paymentId The payment row that just changed (payment or refund).
     * @param int $orderId Its order.
     * @param bool $failClosed True when the caller is writing a REFUND row: a
     *                         settled refund whose wallet leg cannot move must
     *                         never commit, so an over-room return THROWS, and
     *                         so does a credit-classified payment with no
     *                         authentic spend behind it. False for a status
     *                         flip, where the row is leaving the pool anyway
     *                         and clamping keeps the books true.
     *
     * @return object[] The wallet rows written, still uncommitted: the caller
     *                  owns the transaction and logs each one through
     *                  CustomerCreditLedger::logCommitted() after its COMMIT.
     *
     * @throws RuntimeException When failClosed and the money cannot move, or
     *                          when a clawback finds the credit already spent.
     *
     * @since  2.0.0
     */
    private static function settleWalletForPayment(int $paymentId, int $orderId, bool $failClosed): array
    {
        $db = self::db();

        // SAVEPOINT semantics, never (false): every caller is already inside
        // its own transaction, and a plain start there is an implicit COMMIT
        // of their work.
        $db->transactionStart(true);

        try {
            $written = self::settleWalletLocked($paymentId, $orderId, $failClosed);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel($db, $e);

            throw $e;
        }

        // The order could not be locked: nothing was written.
        if ($written === null) {
            TransactionState::rollBackLevel($db);

            return [];
        }

        TransactionState::commitLevel($db);

        return $written;
    }

    /**
     * The reconciliation of {@see self::settleWalletForPayment()}, run inside
     * the transaction level that method opened and closes: nothing here
     * commits or rolls back.
     *
     * @param int $paymentId The payment row that just changed (payment or refund).
     * @param int $orderId Its order.
     * @param bool $failClosed See settleWalletForPayment().
     *
     * @return object[]|null The wallet rows written; null when the order could
     *                       not be locked and nothing was written.
     *
     * @throws RuntimeException As settleWalletForPayment().
     *
     * @since  2.0.0
     */
    private static function settleWalletLocked(int $paymentId, int $orderId, bool $failClosed): ?array
    {
        $db = self::db();
        $written = [];

        // The FIRST statement is the locking read: under REPEATABLE READ
        // a plain read here would pin the snapshot before the
        // serialization point and everything after it would judge a
        // pre-lock world.
        if (!self::lockOrders($db, $orderId)) {
            // Returning quietly here would defeat failClosed: the caller
            // would commit a settled refund whose wallet leg never moved.
            if ($failClosed) {
                throw new RuntimeException(
                    'settleWalletForPayment: order #' . $orderId . ' could not be locked; '
                    . 'the wallet leg cannot be settled, so the write must not commit.',
                );
            }

            return null;
        }

        $row = self::getRaw($paymentId);

        if (!$row) {
            return [];
        }

        // A REFUND row reconciles its ORIGINAL (that is the payment whose
        // wallet position moved) inside this same transaction level: the
        // original's wallet leg is part of the write that changed the
        // refund, and stands or falls with it.
        if ((string) ($row->payment_type ?? '') === self::TYPE_REFUND) {
            $originalId = (int) ($row->id_refunded_payment ?? 0);

            return $originalId > 0 ? self::settleWalletLocked($originalId, $orderId, $failClosed) : [];
        }

        // THE COLUMN IS THE LINK, and the only one. The 'credit:<id>' text
        // in transaction_id is a label an operator can retype; reading it
        // as a fallback let a gateway payment whose reference happened to
        // name a spend return that spend's money to the wallet when it was
        // cancelled. Measured before removing it: no row carries the text
        // without the column, so nothing depended on the fallback.
        $entryId = (int) ($row->id_credit_transaction ?? 0);

        if ($entryId <= 0) {
            // A row CLASSIFIED as wallet money with no spend behind it is
            // an integrity fault to report, never a gap to guess across.
            $classification = (string) ($row->payment_method_type ?? '');

            if (\in_array($classification, self::NON_GATEWAY_TYPES, true)) {
                self::ensureLogger();
                Log::add(
                    sprintf('Payment #%d is classified %s but names no wallet spend; wallet untouched.', $paymentId, $classification),
                    Log::WARNING,
                    'com_alfa.payments',
                );

                // A REFUND of it is money the books would say went back to
                // a wallet that never moved: the same write failClosed
                // exists to stop, so it is refused, not committed.
                if ($failClosed) {
                    throw new RuntimeException(sprintf(
                        'payment #%d is classified %s but names no wallet spend; a refund of it cannot reach the wallet',
                        $paymentId,
                        $classification,
                    ));
                }
            }

            return [];
        }

        // AUTHENTICITY: the column is ours, but the check costs nothing and
        // refuses a link that names another order's entry or a wrong amount.
        $entry = self::authenticSpendEntry($entryId, $orderId, $row->amount ?? 0);

        if ($entry === null) {
            self::ensureLogger();
            Log::add(
                sprintf('Payment #%d: wallet entry #%d is not an authentic credit spend on order #%d; wallet untouched.', $paymentId, $entryId, $orderId),
                Log::WARNING,
                'com_alfa.payments',
            );

            // Same reason as above: a refund whose wallet leg cannot move
            // must never commit.
            if ($failClosed) {
                throw new RuntimeException(sprintf(
                    'payment #%d names wallet entry #%d, which is not an authentic credit spend on order #%d; a refund of it cannot reach the wallet',
                    $paymentId,
                    $entryId,
                    $orderId,
                ));
            }

            return [];
        }

        // EVERY figure below is Money in the ORDER'S currency. A float
        // with a round(..., 6) would be a hard-coded scale: 6 places is
        // finer than any real currency, so a difference of 0.004 on a
        // 2-decimal order reads as a real delta and the reconciler tries
        // to move four tenths of a cent. Money rounds to the currency's
        // own minor unit, so "is anything owed?" has one answer.
        $gross = self::money($entry->amount, $orderId)->abs();
        $userId = (int) $entry->id_user;
        $status = (string) ($row->transaction_status ?? '');
        $settledAgainst = self::money(self::sumRefundsAgainst($paymentId), $orderId);

        $target = match ($status) {
            // The row has left the collected set; the whole spend is owed.
            self::STATUS_CANCELLED, self::STATUS_FAILED, self::STATUS_REFUNDED => $gross,
            // Still collected: only what settled refunds justify.
            self::STATUS_COMPLETED => $settledAgainst->greaterThan($gross) ? $gross : $settledAgainst,
            default => Money::zero($gross->getCurrency()),
        };

        $actual = self::walletReturnedNet($paymentId, $orderId);
        $delta = $target->subtract($actual);

        if ($delta->isZero()) {
            return [];
        }

        if ($delta->isPositive()) {
            // Room to return: what settled refunds already cover (costs the
            // pool nothing), plus whatever the ORDER still has left after
            // every other rail. Without this a credit note that already
            // returned the money would be paid a second time here.
            $headroom = $settledAgainst->subtract($actual)->nonNegative();
            $poolWithP = self::money(self::sumRefundablePool($orderId), $orderId);

            // A cancelled/failed row has ALREADY left sumRefundablePool,
            // so add it back before asking what the order can give: the
            // money it collected is exactly what it may return.
            if (\in_array($status, [self::STATUS_CANCELLED, self::STATUS_FAILED], true)) {
                $poolWithP = $poolWithP->add($gross);
            }

            $allowed = $poolWithP
                ->subtract(self::money(self::sumSettledRefunds($orderId), $orderId))
                ->subtract(self::sumWalletSettled($orderId))
                ->subtract(self::sumUncoveredWalletReturns(orderId: $orderId, excludePaymentId: $paymentId))
                ->nonNegative();
            $max = $headroom->add($allowed);

            if ($delta->greaterThan($max)) {
                if ($failClosed) {
                    throw new RuntimeException(sprintf(
                        'wallet return of %s for payment #%d exceeds what order #%d has left to give back (%s)',
                        $delta->format(),
                        $paymentId,
                        $orderId,
                        $max->format(),
                    ));
                }

                self::ensureLogger();
                Log::add(
                    sprintf('Payment #%d: wallet return clamped from %s to %s; the order has no more to give back.', $paymentId, $delta->format(), $max->format()),
                    Log::WARNING,
                    'com_alfa.payments',
                );
                $delta = $max;
            }

            if ($delta->isPositive()) {
                $written[] = \Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger::credit(
                    userId:            $userId,
                    amount:            $delta,
                    sourceType:        \Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger::SOURCE_ORDER,
                    sourceId:          $orderId,
                    note:              self::walletReturnPrefix($paymentId) . 'status ' . $status . ')',
                    manageTransaction: false,
                    idOrderPayment:    $paymentId,
                );
            }
        } else {
            // CLAW BACK. A revived payment (cancelled/refunded -> completed)
            // is collected again, so money returned for it must come back.
            // An insufficient balance THROWS, and that rolls back the
            // status write that triggered it: a change that cannot move
            // the money does not commit.
            $written[] = \Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger::debit(
                userId:            $userId,
                amount:            $delta->abs(),
                sourceType:        \Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger::SOURCE_ORDER,
                sourceId:          $orderId,
                note:              self::walletReturnPrefix($paymentId) . 'clawback, status ' . $status . ')',
                manageTransaction: false,
                idOrderPayment:    $paymentId,
            );
        }

        return $written;
    }

    /**
     * The wallet debit a payment row names, when it is an authentic spend.
     *
     * One test, used where a spend link is WRITTEN (createLocked()) and where
     * the wallet leg is about to move money for it (settleWalletForPayment()),
     * so the two can never accept different links. Authentic means: a debit
     * sourced to this order whose size is the payment's amount.
     *
     * The amount test is a Money equality at the ORDER'S scale, not a
     * hard-coded 0.01: on a 2-decimal currency a whole cent IS a difference,
     * and on a 3-decimal one 0.01 is ten of the currency's own units.
     *
     * @param int $entryId The wallet entry the row names (id_credit_transaction).
     * @param int $orderId The row's order.
     * @param Money|string|float $amount The row's amount.
     *
     * @return object|null The entry (id_user, amount, source_type, source_id),
     *                     or null when it is not an authentic spend.
     *
     * @since  2.0.0
     */
    private static function authenticSpendEntry(int $entryId, int $orderId, Money|string|float $amount): ?object
    {
        $db = self::db();
        $db->setQuery(
            'SELECT ' . $db->quoteName('id_user') . ', ' . $db->quoteName('amount')
            . ', ' . $db->quoteName('source_type') . ', ' . $db->quoteName('source_id')
            . ' FROM ' . $db->quoteName('#__alfa_customer_credit_transactions')
            . ' WHERE ' . $db->quoteName('id') . ' = ' . $entryId,
        );
        $entry = $db->loadObject();

        if ($entry === null
            || (string) $entry->source_type !== \Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger::SOURCE_ORDER
            || (int) $entry->source_id !== $orderId
            || (float) $entry->amount >= 0.0
            || !self::money($amount, $orderId)->equals(self::money($entry->amount, $orderId)->abs())) {
            return null;
        }

        return $entry;
    }

    /**
     * Move the ORDER when a payment changes status, per the method's map.
     *
     * Reads `status_on_<newStatus>` from the payment method's params: a
     * PLUGIN-DECLARED map, so a gateway that never declares the fields simply
     * automates nothing. Never throws and never forces: automation must not
     * pull an order out of the cancelled family.
     *
     * @param int $orderId Parent order PK
     * @param object $payment Pre-update row snapshot (carries the method id)
     * @param string $oldStatus Status the row left
     * @param string $newStatus Status the row reached
     *
     *
     * @since  2.0.0
     */
    private static function applyStatusAutomation(
        int $orderId,
        object $payment,
        string $oldStatus,
        string $newStatus,
    ): void {
        $nested = TransactionState::isOpen(self::db());

        try {
            $methodId = (int) ($payment->id_payment_method ?? 0);

            if ($newStatus === '' || $newStatus === $oldStatus || $methodId <= 0 || $orderId <= 0) {
                return;
            }

            $db = self::db();

            $query = $db->getQuery(true)
                ->select($db->quoteName(['type', 'params']))
                ->from($db->quoteName('#__alfa_payments'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $methodId);
            $db->setQuery(query: $query);
            $method = $db->loadObject();

            if ($method === null) {
                return;
            }

            $params = json_decode((string) $method->params, true) ?: [];
            $targetStatusId = (int) ($params['status_on_' . $newStatus] ?? 0);

            if ($targetStatusId <= 0) {
                return;
            }

            $moved = Transition::for($orderId)
                ->to($targetStatusId)
                ->byPlugin((string) $method->type)
                ->note('Payment #' . (int) ($payment->id ?? 0) . ' -> ' . $newStatus)
                ->save();

            if (!$moved) {
                self::reportMoveNotMade(
                    orderId:        $orderId,
                    targetStatusId: $targetStatusId,
                    cause:          'payment #' . (int) ($payment->id ?? 0) . ' -> ' . $newStatus,
                );
            }
        } catch (Throwable $e) {
            // A deadlock that ended the caller's transaction is the caller's
            // to hear: answered here, the caller would carry on as if its
            // work were still pending.
            if ($nested && !TransactionState::isOpen(self::db())) {
                throw $e;
            }

            // Throwable, not Exception: the payment row is committed by the
            // time this runs, so an Error raised under the transition must be
            // logged here and never reach the caller of the write as a throw.
            Log::add(
                entry:    'Payment status automation failed for order ' . $orderId . ': ' . $e->getMessage(),
                priority: Log::ERROR,
                category: 'com_alfa.orders',
            );
        }
    }

    /**
     * Record that a status automation's move was not made.
     *
     * The transition refused it (the reason is on the order's timeline and in
     * the log) or another writer held the order. An order that already stands
     * in the target status lost nothing, and is not reported.
     *
     * @param int $orderId The order.
     * @param int $targetStatusId The status the automation asked for.
     * @param string $cause What asked for it.
     *
     *
     * @since  2.0.0
     */
    private static function reportMoveNotMade(int $orderId, int $targetStatusId, string $cause): void
    {
        try {
            $db = self::db();
            $db->setQuery(
                'SELECT ' . $db->quoteName('id_order_status') . ' FROM ' . $db->quoteName('#__alfa_orders')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $orderId,
            );

            if ((int) $db->loadResult() === $targetStatusId) {
                return;
            }

            Log::add(
                entry:    "Status automation did not move order {$orderId} to status {$targetStatusId} ({$cause}).",
                priority: Log::WARNING,
                category: 'com_alfa.orders',
            );
        } catch (Throwable) {
            // A report that cannot be written changes nothing that happened.
        }
    }

    /**
     * Re-run the create() money guards against the row an update would
     * produce.
     *
     * Fires only when the write changes amount, transaction_status,
     * payment_type, id_refunded_payment or id_order: the fields the caps
     * read. The candidate row is the stored snapshot merged with the
     * incoming fields, and every sum EXCLUDES the row itself (its stored
     * values are being replaced). Closes the two update-path loopholes
     * create() alone left open: raising a saved row past its cap, and a
     * second pending refund settling after the first consumed the pool.
     *
     * @param int $id Payment row PK being updated
     * @param int $orderId Parent order PK
     * @param object $old Pre-update row snapshot
     * @param array $data Incoming update fields (amount already float)
     *
     * @return bool True when the write may proceed, false when capped (logged)
     *
     * @since  2.0.0
     */
    private static function recheckCapsOnUpdate(int $id, int $orderId, object $old, array $data): bool
    {
        // EXACT, AND AT THE ORDER'S OWN SCALE. This decides whether ANY cap
        // below runs, so a tolerance here is a tolerance on every cap at once,
        // and the one that was here, a hand-picked 0.0000001, is TEN TIMES
        // COARSER than a minor unit at scale 8 (0.00000001). Measured: raising
        // a settled payment by one minor unit on a scale-8 order was read as
        // "nothing changed", skipped every cap, and left the order overpaid
        // through a write no guard ever saw. Money compares integer minor units
        // at the scale the order froze, so "changed" means changed by money.
        $amountChanged = array_key_exists('amount', $data)
            && self::money($data['amount'] ?? 0, $orderId)->getMinorUnits()
                !== self::money($old->amount ?? 0, $orderId)->getMinorUnits();
        $statusChanged = array_key_exists('transaction_status', $data)
            && (string) $data['transaction_status'] !== (string) ($old->transaction_status ?? '');
        $linkChanged = array_key_exists('id_refunded_payment', $data)
            && (int) $data['id_refunded_payment'] !== (int) ($old->id_refunded_payment ?? 0);

        // payment_type and id_order both re-route which sums a cap reads:
        // an admin flipping refund->payment (or moving the row to another
        // order) must not dodge the guards.
        $typeChanged = array_key_exists('payment_type', $data)
            && (string) $data['payment_type'] !== (string) ($old->payment_type ?? '');
        $orderChanged = array_key_exists('id_order', $data)
            && (int) $data['id_order'] !== (int) ($old->id_order ?? 0);

        if (!$amountChanged && !$statusChanged && !$linkChanged && !$typeChanged && !$orderChanged) {
            return true;
        }

        // Candidate row: snapshot merged with the incoming change
        $type = (string) (($data['payment_type'] ?? '') !== ''
            ? $data['payment_type']
            : ($old->payment_type ?? self::TYPE_PAYMENT));
        $status = (string) ($data['transaction_status'] ?? $old->transaction_status ?? '');
        $amount = self::money(array_key_exists('amount', $data) ? ($data['amount'] ?? 0) : ($old->amount ?? 0), $orderId)->abs();
        $linkId = (int) (array_key_exists('id_refunded_payment', $data)
            ? $data['id_refunded_payment']
            : ($old->id_refunded_payment ?? 0));

        // A row headed OUT of the recorded set reserves nothing the moment it
        // lands, so the caps must not measure it on the way there. The list is
        // shared with sumRecordedPayments() precisely so the guard and the sum
        // can never drift: when they did, a full refund was refused because the
        // flip to 'refunded' was re-measured as live money against an order
        // total that had meanwhile shrunk (a cancelled shipment drops out of
        // the grand total, which is the normal first step before refunding).
        // failed/cancelled reserve nothing on EITHER side: sumRecordedPayments
        // excludes them and sumSettledRefunds does not count them.
        if (in_array($status, [self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
            return true;
        }

        // A provider's pending word the core writes as it is (providerFact()): it only adds to what is reserved.
        if (self::providerFactRow(['payment_type' => $type, 'transaction_status' => $status])) {
            return true;
        }

        // A write that only LOWERS a refund that stays pending or authorized gives back what it reserved: no money
        // goes out and it is never a start, so no cap judges it (a part split off a reservation, a lower figure the
        // provider holds). Judged as if settled, its rest would be refused whenever the books shrank after the
        // reservation (a chargeback), and a part the books can hold would never be written.
        if ($type === self::TYPE_REFUND
            && (string) ($old->payment_type ?? '') === self::TYPE_REFUND
            && \in_array((string) ($old->transaction_status ?? ''), self::REFUND_RESERVING_STATUSES, true)
            && \in_array($status, self::REFUND_RESERVING_STATUSES, true)
            && $amountChanged && !$linkChanged && !$typeChanged && !$orderChanged
            && $amount->lessThan(self::money($old->amount ?? 0, $orderId)->abs())) {
            return true;
        }

        // 'refunded' MEANS OPPOSITE THINGS PER TYPE, and treating it as
        // universally unrecorded was a hole big enough to drive the whole
        // pool guard through. For a PAYMENT row it is the exit the comment
        // above describes. For a REFUND row it is a SETTLED status:
        // sumSettledRefunds counts refunded AND completed, and 'refunded' is
        // exactly what refundPayment() writes on a settled refund. Exempting
        // it meant the order-pool cap (and the wallet term added with it)
        // never ran on the update path for the very rows it exists to bound:
        // two pending refunds spelled ->refunded() both settled on a
        // single-refund pool, and editing a settled refund's amount upward
        // was unguarded entirely. The pool-serialization suite missed it by
        // spelling the settle ->completed().
        //
        // Even for a PAYMENT the exemption only covers the STATUS FLIP
        // itself. Raising the amount of an already-'refunded' payment must
        // still face the overpayment cap: sumRefundablePool counts refunded
        // originals, so an unguarded edit there inflates what may later be
        // refunded.
        if ($status === self::STATUS_REFUNDED
            && $type === self::TYPE_PAYMENT
            && !$amountChanged
            && !$linkChanged
            && !$orderChanged) {
            return true;
        }

        if ($type === self::TYPE_REFUND) {
            // Order-pool cap binds EVERY refund, linked or not; see the
            // create() pool guard for why the pool counts refunded originals.
            //
            // THE WALLET TERM IS NOT OPTIONAL, and leaving it out of this copy
            // of the guard opened a double-payment with no concurrency in it
            // at all: park a refund as PENDING while the pool is free (create()
            // allows it, and refundableNow ignores pending rows), wallet-credit
            // a note for the same money, then settle the parked refund: this
            // cap saw only settled refunds, found the pool untouched, and let
            // it through. Measured at 31.00 returned on a 15.50 pool. The two
            // guards have to count the same things or the pool means something
            // different depending on which door the write came through.
            $alreadySettled = self::returnedTotal(
                orderId:             $orderId,
                excludeId:           $id,
                candidateOriginalId: $linkId,
                candidateAmount:     $amount,
            );
            $paidPool = self::sumRefundablePool($orderId, excludeId: $id);

            if (self::exceedsCap([$alreadySettled], $paidPool, $orderId)) {
                self::warn(sprintf(
                    'update(): refund of %.2f would exceed order #%d refundable total: %.2f of %.2f already refunded',
                    $amount->getAmount(),
                    $orderId,
                    $alreadySettled->getAmount(),
                    $paidPool,
                ));

                return false;
            }

            // Linked refund: additionally capped by its original's gross
            if ($linkId > 0) {
                $original = self::getRaw($linkId);

                if (!$original) {
                    self::warn("update(): refund names payment #{$linkId}, which does not exist");
                    return false;
                }

                $alreadyRefunded = self::money(self::sumRefundsAgainst($linkId, excludeId: $id), $orderId)
                    ->add(self::money(self::sumChargebacksAgainst($linkId), $orderId));
                $gross = self::money($original->amount, $orderId)->abs();

                if (self::exceedsCap([$alreadyRefunded, $amount], $gross, $orderId)) {
                    self::warn(sprintf(
                        'update(): refund of %.2f would exceed payment #%d: %.2f of %.2f already refunded',
                        $amount->getAmount(),
                        $linkId,
                        $alreadyRefunded->getAmount(),
                        $gross->getAmount(),
                    ));

                    return false;
                }
            }

            return true;
        }

        if ($type === self::TYPE_PAYMENT) {
            $alreadyRecorded = self::sumRecordedPayments($orderId, excludeId: $id);
            // SAME hint seam as create(): at checkout the shipment row does
            // not exist yet, so the DB recompute is short by shipping. Without
            // this a 100%-wallet order WITH shipping fails its pending->
            // completed flip (create passed on the hint, update measured
            // against the shipping-less total): the wallet is debited, the
            // row wedges pending and no completion side effects ever run.
            // The larger of the two is taken as the order's Money, as in create().
            $grandTotal = self::money(self::orderGrandTotal(orderId: $orderId), $orderId);
            $hintTotal = self::money(self::$updateGrandTotalHint, $orderId);

            if ($hintTotal->greaterThan($grandTotal)) {
                $grandTotal = $hintTotal;
            }

            if (self::exceedsCap([$alreadyRecorded, $amount], $grandTotal, $orderId)) {
                self::warn(sprintf(
                    'update(): payment of %s would exceed order #%d total: %s of %s already recorded',
                    PublishedDecimal::money(money: $amount),
                    $orderId,
                    PublishedDecimal::money(money: self::money($alreadyRecorded, $orderId)),
                    PublishedDecimal::money(money: $grandTotal),
                ));

                return false;
            }
        }

        return true;
    }

    /**
     * Why a refund may not name this document, or null when it may.
     *
     * The one answer for every write of a refund row that names a document:
     * create() asks it for a new refund, and an edit or a settle asks it
     * through {@see self::refundDocumentRefusalOnUpdate()}. Run under the
     * order lock, which every caller already holds.
     *
     * Three things are asked, in this order:
     *
     *   the document is of the refund's order: a refund stamped with another
     *     order's note is an attribution error, not a licence to skip the cap;
     *   it is issued: a voided note gives nothing back, because what replaced
     *     it restates the credit and is settled in its own right, so a refund
     *     recorded against the voided one would pay the same credit twice;
     *   the amount fits the note's own ceiling: the order's cap is order-wide
     *     and an order is usually worth more than one note, so a note paid in
     *     full to the customer's credit account and refunded in full to a card
     *     would pass it. What the account has been given for the note, plus
     *     every other refund of it that is neither failed nor cancelled, plus
     *     this refund, may not exceed the note
     *     ({@see self::refundsHoldingDocument()}). A refund still pending
     *     holds its amount: it is refused here, before any money moves at a
     *     provider, rather than when it settles.
     *
     * @param int $documentId The document the refund names (positive).
     * @param int $orderId The refund's order (locked).
     * @param Money|string|float $amount The refund's amount, weighed against the note's ceiling.
     * @param string $caller The ledger method named in the technical refusals.
     * @param int $excludeId The refund row being re-judged, left out of what
     *                       the note has already given (0 = a new row).
     * @param bool $settle The write settles the row: a voided document is
     *                     then told how to go on.
     *
     * @return array{reason: string, kind: string, documentId: int, document: string, amount: Money, paid: ?Money, total: ?Money}|null
     *                                                                                                                                 The refusal (its sentence, the words staff read, and its facts), or null.
     *
     * @since  2.0.0
     */
    private static function refundDocumentRefusal(
        int $documentId,
        int $orderId,
        Money|string|float $amount,
        string $caller,
        int $excludeId = 0,
        bool $settle = false,
    ): ?array {
        // The named document as it stands now. A locking read: under the order
        // lock a void can no longer be in flight, and one committed since this
        // transaction's first read is seen. ORDER, then DOCUMENT: the hierarchy
        // every money path takes.
        $db = self::db();
        $db->setQuery(
            'SELECT ' . $db->quoteName('total_tax_incl') . ', ' . $db->quoteName('status') . ', ' . $db->quoteName('number_formatted')
            . ' FROM ' . $db->quoteName('#__alfa_documents')
            . ' WHERE ' . $db->quoteName('id') . ' = ' . $documentId
            . ' AND ' . $db->quoteName('id_order') . ' = ' . $orderId
            . ' FOR UPDATE',
        );
        $named = $db->loadObject();
        $noteTotal = $named->total_tax_incl ?? null;
        $refusal = [
            'documentId' => $documentId,
            'document' => '#' . $documentId,
            'amount' => self::money($amount, $orderId),
            'paid' => null,
            'total' => null,
        ];

        if ($noteTotal === null) {
            return [
                'reason' => sprintf('%s: refund names document #%d, which is not a document of order #%d', $caller, $documentId, $orderId),
                'kind' => 'order',
            ] + $refusal;
        }

        if ((string) $named->number_formatted !== '') {
            $refusal['document'] = (string) $named->number_formatted;
        }

        // Plain words: staff read these two.
        if ((int) $named->status !== \Alfa\Component\Alfa\Administrator\Helper\DocumentHelper::STATUS_ISSUED) {
            return [
                'reason' => $settle
                    ? sprintf('This refund names document %s, which has been voided. Record it against the document that replaced it, or against no document.', $refusal['document'])
                    : sprintf('This refund names document %s, which has been voided. A refund is recorded only against an issued document, so nothing was recorded.', $refusal['document']),
                'kind' => 'voided',
            ] + $refusal;
        }

        $noteCeiling = self::money((string) $noteTotal, $orderId);
        $notePaid = self::money(\Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger::creditNoteNet($documentId), $orderId)
            ->add(self::money(self::refundsHoldingDocument(documentId: $documentId, excludeId: $excludeId), $orderId)->nonNegative());

        if ($notePaid->add($refusal['amount'])->greaterThan($noteCeiling)) {
            return [
                'reason' => sprintf(
                    '%s: refund of %.2f would pay credit note #%d twice: %s of %s already returned'
                    . ' to the customer\'s credit account and through the gateway, refunds still pending included',
                    $caller,
                    $refusal['amount']->getAmount(),
                    $documentId,
                    $notePaid->format(),
                    $noteCeiling->format(),
                ),
                'kind' => 'ceiling',
                'paid' => $notePaid,
                'total' => $noteCeiling,
            ] + $refusal;
        }

        return null;
    }

    /**
     * Judge the document of a refund row as an update would leave it.
     *
     * The row as written (the stored row merged with the change) is judged
     * only when it is a refund, not failed or cancelled, that names a document:
     *
     *   its document, amount, order or type changes: the checks a new refund
     *     passes ({@see self::refundDocumentRefusal()}), with the row's own
     *     stored refund left out of what the note has already given;
     *   otherwise its status moves into settled (refunded or completed): the
     *     same checks for its own amount, the row left out; a voided document
     *     is told how to go on;
     *   otherwise nothing is asked: a note, a reference, or the same values
     *     posted again change nothing the document answers for.
     *
     * A row that names no document is never judged here.
     *
     * @param int $id Payment row PK being updated.
     * @param int $orderId The order the write names (locked).
     * @param object $old The row as read under the order lock.
     * @param array $data The write (amount already normalised).
     *
     * @return array|null The refusal ({@see self::refundDocumentRefusal()}), or
     *                    null when the write may proceed.
     *
     * @since  2.0.0
     */
    private static function refundDocumentRefusalOnUpdate(int $id, int $orderId, object $old, array $data): ?array
    {
        $type = (string) (($data['payment_type'] ?? '') !== '' ? $data['payment_type'] : ($old->payment_type ?? self::TYPE_PAYMENT));
        $status = (string) ($data['transaction_status'] ?? $old->transaction_status ?? '');

        if ($type !== self::TYPE_REFUND || \in_array($status, [self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
            return null;
        }

        $documentId = (int) (\array_key_exists('id_document', $data) ? $data['id_document'] : ($old->id_document ?? 0));

        if ($documentId <= 0) {
            return null;
        }

        $amount = self::money(\array_key_exists('amount', $data) ? ($data['amount'] ?? 0) : ($old->amount ?? 0), $orderId)->abs();

        // "Changed" as recheckCapsOnUpdate() reads it: money by minor units at
        // the order's scale, everything else by value.
        $changes = $documentId !== (int) ($old->id_document ?? 0)
            || (\array_key_exists('amount', $data)
                && self::money($data['amount'] ?? 0, $orderId)->getMinorUnits()
                    !== self::money($old->amount ?? 0, $orderId)->getMinorUnits())
            || (int) ($old->id_order ?? 0) !== $orderId
            || (string) ($old->payment_type ?? '') !== self::TYPE_REFUND;

        if ($changes) {
            return self::refundDocumentRefusal(documentId: $documentId, orderId: $orderId, amount: $amount, caller: 'update()', excludeId: $id);
        }

        $settled = [self::STATUS_REFUNDED, self::STATUS_COMPLETED];

        if (\in_array($status, $settled, true) && !\in_array((string) ($old->transaction_status ?? ''), $settled, true)) {
            return self::refundDocumentRefusal(
                documentId: $documentId,
                orderId:    $orderId,
                amount:     $amount,
                caller:     'update()',
                excludeId:  $id,
                settle:     true,
            );
        }

        return null;
    }

    /**
     * SUM(ABS(amount)) of ledger rows of one payment_type, filtered by
     * status and scope.
     *
     * The single implementation behind every money guard and balance
     * figure: scoped to an order (id_order) or to the refunds naming one
     * original (id_refunded_payment); at least one scope is required.
     * Amounts are stored positive; ABS() defends against a hand-inserted
     * negative.
     *
     * @param string $type payment_type to sum
     * @param array $statusIn Count only these transaction_status values ([] = all)
     * @param array $statusNotIn Skip these transaction_status values ([] = none)
     * @param int $orderId Scope: rows of this order (0 = unscoped)
     * @param int $refundedId Scope: refunds naming this original (0 = unscoped)
     * @param int $excludeId Row PK to leave out: the row being re-checked (0 = none)
     *
     * @return string Total in major units, as the SUM's decimal text
     *
     * @since  2.0.0
     */
    private static function sumAbsAmounts(
        string $type,
        array $statusIn = [],
        array $statusNotIn = [],
        int $orderId = 0,
        int $refundedId = 0,
        int $excludeId = 0,
    ): string {
        // Never sum unscoped; that would cross order boundaries
        if ($orderId <= 0 && $refundedId <= 0) {
            return '0';
        }

        $db = self::db();
        $query = $db->getQuery(true)
            ->select('COALESCE(SUM(ABS(' . $db->quoteName('amount') . ')), 0)')
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('payment_type') . ' = ' . $db->quote($type));

        if ($orderId > 0) {
            $query->where($db->quoteName('id_order') . ' = ' . $orderId);
        }

        if ($refundedId > 0) {
            $query->where($db->quoteName('id_refunded_payment') . ' = ' . $refundedId);
        }

        if ($statusIn !== []) {
            $query->whereIn($db->quoteName('transaction_status'), $statusIn, ParameterType::STRING);
        }

        if ($statusNotIn !== []) {
            $query->whereNotIn($db->quoteName('transaction_status'), $statusNotIn, ParameterType::STRING);
        }

        if ($excludeId > 0) {
            $query->where($db->quoteName(self::PK) . ' != ' . $excludeId);
        }

        $db->setQuery($query);

        return (string) $db->loadResult();
    }

    /**
     * Sum every payment recorded toward an order's total.
     *
     * Counts 'payment' rows in any live status: a pending instalment
     * reserves its slice of the total so the same money cannot be promised
     * twice; failed/cancelled rows are dead, and a REFUNDED row reserves
     * nothing either (its money came in and went back, so a replacement
     * payment must fit). The refund pool (sumRefundablePool) deliberately
     * STILL counts refunded originals (that is the collected money being
     * returned), while this overpayment figure does not. Authorization-type
     * rows never enter (own payment_type).
     *
     * @param int $orderId Order PK
     *
     * @return float Recorded payment total in major units
     *
     * @since  2.0.0
     */
    /**
     * The order's own currency, as a Currency object.
     *
     * Every figure this ledger reports is money IN SOME CURRENCY, and until
     * now that second half was thrown away at the door: sums came back as
     * bare floats, callers compared them with a hard-coded 0.01, and the
     * rounding was a hard-coded 6 decimals. Alfa ships currencies with 0, 1,
     * 3 and 4 decimals, so both of those constants are wrong somewhere.
     *
     * The answer is {@see OrderCurrency}'s: the currency the order names, at
     * the scale the order froze, so a guard here and the facts it guards are
     * measured on one ruler. Kept per request, because the balance API asks
     * several times for one answer and an order's currency cannot change
     * under it.
     *
     * @param int $orderId Order PK.
     *
     *
     * @throws OrderCurrencyException When the order names no currency.
     *
     * @since  2.0.0
     */
    private static function orderCurrency(int $orderId): Currency
    {
        return self::$orderCurrencies[$orderId] ??= OrderCurrency::of(orderId: $orderId);
    }

    /**
     * Order currencies already resolved for this request, keyed by order id.
     *
     * Class-level rather than local to {@see self::orderCurrency()} so
     * {@see self::warmOrderCurrencies()} can fill it for a whole page at once.
     *
     * @var array<int, Currency>
     *
     * @since  2.0.0
     */
    private static array $orderCurrencies = [];

    /**
     * Resolve the currencies of many orders in one read.
     *
     * {@see self::getByOrders()} hydrates a page of payment rows, and each row
     * asked {@see self::orderCurrency()} for its order's currency with a query of
     * its own: one per order on the page, behind a method that promises one query.
     *
     * Each order resolves exactly as the point lookup does, through
     * {@see OrderCurrency}. Orders already resolved are left alone, so the memo
     * holds the same answers whichever path filled it. An order that names no
     * currency, or has no row, is not put in the memo: it is answered back to
     * the caller, which then has no money to hydrate for it. A failed read
     * caches nothing and the point lookup runs as before: warming is an
     * optimisation, never a requirement.
     *
     * @param int[] $orderIds Orders about to be hydrated.
     *
     * @return array<int, true> The orders among them that name no currency, by id.
     *
     * @since  2.0.0
     */
    private static function warmOrderCurrencies(array $orderIds): array
    {
        $orderIds = array_values(array_filter(
            array_unique(array_map('intval', $orderIds)),
            static fn (int $id): bool => $id > 0 && !isset(self::$orderCurrencies[$id]),
        ));

        if ($orderIds === []) {
            return [];
        }

        try {
            $db = self::db();
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'id_currency', 'currency_scale']))
                ->from($db->quoteName('#__alfa_orders'))
                ->whereIn($db->quoteName('id'), $orderIds);
            $db->setQuery($query);

            // THE SAME CACHE the single-order path fills, so it must freeze the
            // same way. Warming with live-scale currencies would have made an
            // order's scale depend on whether a list view ran first.
            $rows = $db->loadAssocList('id');
        } catch (Throwable $e) {
            return [];
        }

        $without = [];

        foreach ($orderIds as $orderId) {
            $row = $rows[$orderId] ?? [];

            $currency = OrderCurrency::findRow(
                orderId:    $orderId,
                currencyId: $row['id_currency'] ?? null,
                scale:      $row['currency_scale'] ?? null,
            );

            if ($currency === null) {
                $without[$orderId] = true;

                continue;
            }

            self::$orderCurrencies[$orderId] = $currency;
        }

        return $without;
    }

    /**
     * An amount, as money in the order's currency.
     *
     * The one place a figure becomes the order's Money, so the rounding happens
     * once, in the currency's own scale, instead of at every caller with a
     * guess. A stored column's text is read as written: never through a float,
     * which holds no more than fifteen of its digits. A Money of the order's
     * currency is read again at the order's frozen scale; one finer than that
     * scale, or of another currency, is kept as it is here, and the storage
     * door refuses it by name before anything is written.
     *
     * @param Money|string|int|float $amount A Money, a column's text, or a number.
     * @param int $orderId Order whose frozen currency the money is in.
     *
     *
     * @since  2.0.0
     */
    private static function money(Money|string|int|float $amount, int $orderId): Money
    {
        $currency = self::orderCurrency($orderId);

        if (!$amount instanceof Money) {
            return Money::of($amount, $currency);
        }

        return $amount->getCurrency()->equals($currency)
            ? (self::frozenScaleMoney(value: $amount, currency: $currency) ?? $amount)
            : $amount;
    }

    /**
     * Does a candidate total break a money cap?
     *
     * Both sides become Money in the order's own FROZEN currency first, because
     * that is the only scale the money exists at: a gateway is handed 117.38
     * while the order's computed grand total can be 117.378 after a percentage
     * discount. Comparing the raw figures refused a payment for the exact
     * amount the customer had already been charged: the difference was 0.002,
     * which is not money, it is arithmetic. Quantising to the scale is what
     * forgives that, and it is the whole of the forgiveness: once both sides are
     * integer minor units, any surviving difference is at least one real unit.
     *
     * The scale is per currency, never assumed: JPY has none, KWD has three, a
     * crypto has eight.
     *
     * WHAT THIS REPLACED, named so it cannot come back:
     *
     *     $scale     = self::currencyScale($orderId);
     *     $minorUnit = 10 ** -$scale;
     *     return round($candidate, $scale) > round($limit, $scale) + ($minorUnit / 2);
     *
     * Two defects in three lines. The first is the half-minor-unit TOLERANCE on
     * a monetary admission rule: harmless in its effect, since both sides were
     * already rounded to the scale, but it left a float epsilon deciding
     * whether money may be written, underneath payment facts that had been made
     * exact everywhere else. The second is worse and was not cosmetic:
     * currencyScale() read the LIVE #__alfa_currencies.decimal_place through a
     * join, while OrderFacts reads the order's write-once currency_scale. Edit
     * a currency's decimal_place and this cap began admitting or refusing money
     * on a different ruler from the facts it was guarding.
     *
     * Money compares integer minor units, so the three answers the contract
     * requires fall out of the type rather than out of a tolerance: exactly at
     * the cap is admitted, one minor unit under is admitted, one minor unit over
     * is refused. There is no FX anywhere on this path: both operands are
     * already in the order's currency.
     *
     * What the ledger would hold is handed in its parts and added here as the
     * order's money, so no float sum stands between the figures and the cap.
     *
     * @param array<Money|string|float> $candidate The parts of what the ledger would hold after this write
     * @param Money|string|float $limit What it may hold
     * @param int $orderId Order whose frozen currency sets the scale
     *
     * @return bool True when the write must be refused
     *
     * @since  2.0.0
     */
    private static function exceedsCap(array $candidate, Money|string|float $limit, int $orderId): bool
    {
        $held = Money::zero(self::orderCurrency($orderId));

        foreach ($candidate as $part) {
            $held = $held->add(self::money($part, $orderId));
        }

        return $held->greaterThan(self::money($limit, $orderId));
    }

    private static function sumRecordedPayments(int $orderId, int $excludeId = 0): string
    {
        return self::sumAbsAmounts(
            type:        self::TYPE_PAYMENT,
            statusNotIn: self::UNRECORDED_PAYMENT_STATUSES,
            orderId:     $orderId,
            excludeId:   $excludeId,
        );
    }

    /**
     * Sum the money actually collected for an order.
     *
     * Completed 'payment' rows only: the refundable pool, and the figure
     * total_paid_real is defined as.
     *
     * @param int $orderId Order PK
     * @param int $excludeId Row PK to leave out (0 = none)
     *
     * @return string Collected total in major units, as the SUM's decimal text
     *
     * @since  2.0.0
     */
    private static function sumCollectedPayments(int $orderId, int $excludeId = 0): string
    {
        return self::sumAbsAmounts(
            type:      self::TYPE_PAYMENT,
            statusIn:  [self::STATUS_COMPLETED],
            orderId:   $orderId,
            excludeId: $excludeId,
        );
    }

    /**
     * The order's refundable pool: every payment whose money was ever
     * collected.
     *
     * Counts 'payment' rows completed OR refunded: a payment flipped to
     * 'refunded' collected its money as much as a completed one, and a
     * completed-only pool would drop that money while still counting the
     * refunds that returned it, refusing every later booking against the
     * order. Deliberately NOT
     * sumCollectedPayments()/paidTotal(), which stay completed-only to
     * mirror OrdersModel's total_paid_real.
     *
     * @param int $orderId Order PK
     * @param int $excludeId Row PK to leave out (0 = none)
     *
     * @return string Ever-collected total in major units, as the SUM's decimal text
     *
     * @since  2.0.0
     */
    private static function sumRefundablePool(int $orderId, int $excludeId = 0): string
    {
        return self::sumAbsAmounts(
            type:      self::TYPE_PAYMENT,
            statusIn:  [self::STATUS_COMPLETED, self::STATUS_REFUNDED],
            orderId:   $orderId,
            excludeId: $excludeId,
        );
    }

    /**
     * Sum every settled refund on an order, linked and unlinked alike, net of
     * their reversals.
     *
     * Same money-moved statuses as sumRefundsAgainst(), scoped to the
     * whole order instead of one original. Chargebacks are not refunds and
     * are left out; see sumChargebacks().
     *
     * @param int $orderId Order PK
     * @param int $excludeId Refund row PK to leave out, with its reversals (0 = none)
     *
     * @return string Settled refund total in major units, as the SUM's decimal text
     *
     * @since  2.0.0
     */
    private static function sumSettledRefunds(int $orderId, int $excludeId = 0): string
    {
        return self::sumNetAmounts(
            type:      self::TYPE_REFUND,
            statusIn:  [self::STATUS_REFUNDED, self::STATUS_COMPLETED],
            orderId:   $orderId,
            excludeId: $excludeId,
        );
    }

    /**
     * Sum every recorded chargeback on an order, net of its reversals.
     *
     * @param int $orderId Order PK
     *
     * @return string Net chargeback total in major units, as the SUM's decimal text
     *
     * @since  2.0.0
     */
    private static function sumChargebacks(int $orderId): string
    {
        return self::sumNetAmounts(
            type:     self::TYPE_CHARGEBACK,
            statusIn: [self::STATUS_COMPLETED],
            orderId:  $orderId,
        );
    }

    /**
     * The order grand total the money guards measure against.
     *
     * Same source the amount still to collect is recomputed from, so a
     * guard can never disagree with the amount it auto-resolved. The exact
     * sum, never its float: at 8 places a float of an order above 2^26 units
     * loses its last minor units, and the cap then refused the order's own
     * exact payment.
     *
     * @param int $orderId Order PK
     * @param object|null $order The loaded order row, when one is in hand.
     *
     * @return string Grand total (tax incl) as exact decimal text
     *
     * @since  2.0.0
     */
    private static function orderGrandTotal(int $orderId, ?object $order = null): string
    {
        return OrderTotalHelper::getExactBreakdown(orderId: $orderId, order: $order)->grand_total_tax_incl;
    }

    /**
     * Delete an order payment record.
     *
     * Refused for a 'payment' row that a refund names which is not failed or
     * cancelled (settled, or pending or authorized: a reservation, a held or
     * an unreconciled provider refund), whatever the payment's own status:
     * deleting it would leave those refunds naming nothing, their
     * reservations and reports beyond any way out. Anything else deletes as
     * before.
     *
     * No builder needed; call statically:
     *   PaymentLedger::delete($id, $orderId);
     *
     * @param int $id Payment row PK
     * @param int $orderId Order PK (for activity log)
     *
     * @return bool True on success
     *
     * @since  1.0.0
     */
    public static function delete(int $id, int $orderId): bool
    {
        $payment = self::getRaw($id);

        // No payment row of an order that names no currency is removed.
        OrderCurrency::of(orderId: (int) ($payment->id_order ?? $orderId));

        // A PROVIDER FACT IS HISTORY. A reversal or a chargeback is never
        // deleted, and neither is a row one of them names.
        if ($payment && \in_array((string) ($payment->payment_type ?? ''), self::FACT_TYPES, true)) {
            self::warn(sprintf(
                'delete(): row #%d is a %s recorded from the provider; it is history and cannot be deleted',
                $id,
                (string) $payment->payment_type,
            ));

            return false;
        }

        // A refund of a payment that is not settled reserves its amount: a gateway's request in flight, a
        // provider refund held or one the books refused (whose money moved), a refund typed and not settled.
        // Deleting it would give that amount back with no trace and, for moved money, lift the refund block.
        // It is released by setting it failed or cancelled (audited; refused for moved money), or through its
        // report's way out; never by deleting it.
        if ($payment
            && (string) ($payment->payment_type ?? '') === self::TYPE_REFUND
            && \in_array((string) ($payment->transaction_status ?? ''), self::REFUND_RESERVING_STATUSES, true)
            && ((int) ($payment->id_refunded_payment ?? 0) > 0 || isset(self::unreconciledMarks(orderId: (int) $payment->id_order)[$id]))) {
            self::warn(sprintf(
                'delete(): row #%d is a refund not settled yet and reserves its amount; it is released by setting it failed or cancelled, or through its report, never deleted',
                $id,
            ));

            return false;
        }

        if ($payment
            && (self::countFactsNaming(column: 'id_reversed_payment', rowId: $id, type: self::TYPE_REVERSAL) > 0
                || self::countFactsNaming(column: 'id_refunded_payment', rowId: $id, type: self::TYPE_CHARGEBACK) > 0)) {
            self::warn(sprintf(
                'delete(): row #%d is named by a recorded reversal or chargeback; it is history and cannot be deleted',
                $id,
            ));

            return false;
        }

        // A payment a live refund names (settled, reserved, held or unreconciled) is history for that refund,
        // whatever the payment's own status (staff may have set it pending or cancelled while a provider refund
        // was asked): deleted, the refund would name nothing, its report could not be recorded or resolved any
        // more and the refund block would lift.
        $naming = $payment && (string) ($payment->payment_type ?? '') === self::TYPE_PAYMENT ? self::liveRefundsOf(paymentId: $id) : [];

        if ($naming !== []) {
            self::warn(sprintf(
                'delete(): payment #%d is named by refund row(s) #%s that are not failed or cancelled; it cannot be deleted while they stand',
                $id,
                implode(', #', $naming),
            ));

            return false;
        }

        // A ROW WHOSE MONEY MOVED IS HISTORY, NOT A DRAFT.
        //
        // The guard above protects one direction only (a payment that has
        // refunds against it) and says nothing about the refund rows
        // themselves or about money collected without one. Both gaps are
        // reachable from the same button, and both rewrite the past:
        //
        //   deleting a SETTLED REFUND lowers what the order has returned
        //   without anything coming back, and re-opens the refundable pool by
        //   exactly that amount. Measured: a 20.00 payment refunded by 8.00
        //   went back to offering 20.00 refundable the moment the refund row
        //   was removed, so the same 8.00 could be paid out a second time.
        //
        //   deleting a COMPLETED PAYMENT lowers what the order has collected
        //   while the money stays collected, and for a wallet spend the
        //   customer's balance stays debited with nothing left to justify it.
        //
        // Money that has moved is corrected by RECORDING THE REVERSAL, which
        // is what refund and void are for. That keeps both figures true and
        // leaves the audit trail intact, which deletion cannot do by
        // construction. Rows that never moved money (pending, authorised,
        // failed, cancelled) stay deletable, because removing them changes
        // neither figure; a refund of a payment that is not settled is the
        // exception above (it reserves its amount).
        $movedMoney = $payment
            && in_array((string) ($payment->payment_type ?? ''), [self::TYPE_PAYMENT, self::TYPE_REFUND], true)
            && in_array(
                (string) ($payment->transaction_status ?? ''),
                [self::STATUS_COMPLETED, self::STATUS_REFUNDED],
                true,
            );

        if ($movedMoney) {
            $isRefund = (string) ($payment->payment_type ?? '') === self::TYPE_REFUND;

            self::warn(sprintf(
                $isRefund
                    ? 'delete(): row #%d is a settled refund; deleting it would un-return money that was given back'
                      . ' and re-open the refundable pool. Record the reversal instead.'
                    : 'delete(): payment #%d has collected money; deleting it would un-collect it while the money'
                      . ' stays collected. Refund or void it instead.',
                $id,
            ));

            return false;
        }

        $success = self::deleteFromTable($id);

        if ($success && $payment) {
            $methodName = $payment->payment_method ?? '';
            $amount = number_format((float) ($payment->amount ?? 0), 2);

            OrderModel::logOrderActivity(
                $orderId,
                'payment.deleted',
                Text::sprintf('COM_ALFA_PAYMENT_DELETED_LOG', $methodName, $amount),
                [
                    'amount' => (float) ($payment->amount ?? 0),
                    'payment_method' => $methodName,
                    'transaction_status' => $payment->transaction_status ?? '',
                    'payment_type' => $payment->payment_type ?? '',
                ],
                $id,
            );
        }

        return $success;
    }

    /**
     * The refund rows that name a payment and are not failed or cancelled, oldest first.
     *
     * @param int $paymentId The payment.
     *
     * @return list<int> Their ids.
     *
     * @since  2.1.0
     */
    private static function liveRefundsOf(int $paymentId): array
    {
        $db = self::db();

        return array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('id_refunded_payment') . ' = ' . $paymentId)
                ->where($db->quoteName('payment_type') . ' = ' . $db->quote(self::TYPE_REFUND))
                ->where($db->quoteName('transaction_status') . ' NOT IN (' . $db->quote(self::STATUS_FAILED) . ', ' . $db->quote(self::STATUS_CANCELLED) . ')')
                ->order($db->quoteName('id') . ' ASC'),
        )->loadColumn() ?: []);
    }

    //  Static read API

    /**
     * Load a single payment with its method params attached.
     *
     * Returns the raw DB row enriched with:
     *   - params -> full payment method record (from PaymentModel)
     *
     *
     * @return object|null Payment record with params, or null if not found
     *
     * @since  1.0.0
     */
    /**
     * Give a payment row's amount its currency.
     *
     * THE BOUNDARY. A row arrives from the driver with `amount` as a string,
     * a number with no scale and no currency, and every consumer that then
     * did arithmetic on it was inventing both. Past this line a payment amount
     * is Money, in the order's own scale, and it stays Money.
     *
     * DELIBERATELY NOT APPLIED TO {@see LedgerSupport::getRaw()}. That is the
     * persistence escape hatch: compare-and-set reads the row to write it back,
     * and a write path must see exactly what the column holds. Raw in, raw out.
     *
     *   driver -> getRaw()      -> raw persistence representation
     *   driver -> read accessor -> Money -> business logic
     *
     * Idempotent: a row that already carries Money is returned untouched, so
     * an accessor built on another accessor cannot double-convert.
     *
     * @param object|null $row Payment row, or null.
     * @param int $orderId Order to take the currency from; 0 = the
     *                     row's own id_order.
     *
     * @return object|null The same row, amount hydrated.
     *
     * @since  2.0.0
     */
    public static function hydrate(?object $row, int $orderId = 0): ?object
    {
        if ($row === null || !property_exists($row, 'amount') || $row->amount instanceof Money) {
            return $row;
        }

        $orderId = $orderId > 0 ? $orderId : (int) ($row->id_order ?? 0);
        $row->amount = self::money($row->amount, $orderId);

        return $row;
    }

    /**
     * {@see self::hydrate()} over a list.
     *
     * @param array $rows Payment rows.
     * @param int $orderId Shared order id when the caller knows it; saves a
     *                     currency lookup per row.
     *
     * @return array The same rows, amounts hydrated.
     *
     * @since  2.0.0
     */
    public static function hydrateAll(array $rows, int $orderId = 0): array
    {
        foreach ($rows as $row) {
            self::hydrate($row, $orderId);
        }

        return $rows;
    }

    public static function get(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $db = self::db();
        $query = $db->getQuery(true)
            ->select('a.*')
            ->from($db->quoteName(self::TABLE, 'a'))
            ->where($db->quoteName('a.id') . ' = ' . (int) $id);

        $db->setQuery($query);
        $payment = $db->loadObject();

        if (!$payment) {
            return null;
        }

        // Attach the payment method configuration
        try {
            $paymentModel = self::getRelatedModel('Payment');
            $payment->params = $paymentModel->getItem($payment->id_payment_method);
        } catch (Exception $e) {
            $payment->params = null;
        }

        return self::hydrate($payment);
    }

    /**
     * Load all payments for an order (lightweight: no method params).
     *
     * For fully-enriched records, call get($id) on individual items.
     *
     * @param int $orderId Order PK
     *
     * @return array Array of payment row objects
     *
     * @since  1.0.0
     */
    public static function getByOrder(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $db = self::db();
        $query = $db->getQuery(true)
            ->select('*')
            ->from(self::TABLE)
            ->where('id_order = ' . (int) $orderId)
            // NEWEST FIRST, deterministically. Without an ORDER BY the driver
            // answers in whatever order it likes: in practice insertion order,
            // which made every caller looking for "the latest pending row"
            // silently pick the OLDEST one.
            ->order($db->quoteName('id') . ' DESC');

        $db->setQuery($query);

        return self::hydrateAll($db->loadObjectList() ?: [], $orderId);
    }

    /**
     * Load payments for MANY orders in one query, grouped by order.
     *
     * The batch sibling of getByOrder(). A listing showing 20 orders wants 20
     * sets of payments and one query, not twenty, but it must not buy that
     * with its own SELECT, because a second query is a second definition of
     * what a payment row is, and the two drift. This keeps the single query
     * and the boundary: amounts come back as Money, exactly like every other
     * ledger read.
     *
     * @param int[] $orderIds Order PKs
     * @param bool $oldestFirst Chronological within each order (a statement
     *                          reads forwards); default newest-first, as
     *                          getByOrder() answers.
     *
     * @return array<int, object[]> [ orderId => rows ] - orders with no
     *                              payments are absent, not empty. The rows of
     *                              an order that names no currency carry the
     *                              stored amount, not Money.
     *
     * @since  2.0.0
     */
    public static function getByOrders(array $orderIds, bool $oldestFirst = false): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));

        if (!$orderIds) {
            return [];
        }

        $db = self::db();
        $query = $db->getQuery(true)
            ->select('*')
            ->from(self::TABLE)
            ->whereIn($db->quoteName('id_order'), $orderIds)
            // Tie-broken by id: 'added' is a datetime, and rows written in the
            // same second would otherwise come back in the driver's own order.
            ->order($db->quoteName('added') . ($oldestFirst ? ' ASC' : ' DESC'))
            ->order($db->quoteName('id') . ($oldestFirst ? ' ASC' : ' DESC'));

        $db->setQuery($query);

        $rows = $db->loadObjectList() ?: [];

        // Only the orders that actually came back with payments: an order with
        // none has nothing to hydrate and nothing to resolve.
        $withoutCurrency = self::warmOrderCurrencies(array_column($rows, 'id_order'));

        $grouped = [];

        foreach ($rows as $row) {
            // A page is not refused for one order. A row of an order that
            // names no currency keeps its stored amount: it is not money a
            // list can format, and the list says so in its place.
            if (!isset($withoutCurrency[(int) $row->id_order])) {
                self::hydrate($row);
            }

            $grouped[(int) $row->id_order][] = $row;
        }

        return $grouped;
    }

    //  Static balance API
    //
    //  The ledger's money figures as one authoritative source: the SAME
    //  sums the guards enforce, so a display can never disagree with a cap.

    /**
     * Money actually collected for an order.
     *
     * SUM(ABS(amount)) of completed 'payment' rows: the SAME figure the
     * admin Totals card and the orders-list payment ladder derive, served
     * from the guards' own sum.
     *
     *
     * @return float Collected total in major units
     *
     * @since  2.0.0
     */
    /**
     * THE amount a gateway should collect for an order: the ONE public
     * contract every payment plugin (core or third-party) builds on.
     *
     * Grand total minus what the shop is STILL HOLDING (collected money less
     * anything given back), clamped at zero and measured at the order's frozen
     * currency scale. It is OrderFacts' balance, floored: one authority, read
     * from the collecting end. Money that was collected and then refunded is
     * owed again, and this says so. Rules for gateway authors:
     *
     *   - Charge amountToCollect($order), never a raw order field: reading
     *     total_paid_tax_incl directly charges the FULL total on top of any
     *     credit already applied.
     *   - 0.00 means NOTHING to collect: skip your gateway entirely, create
     *     no payment row, set no layout in onOrderProcessView; the checkout
     *     falls through to the completed page by itself.
     *   - Verify a gateway callback against YOUR OWN pending row's amount,
     *     never against the order total: with split payment they differ.
     *
     * Prefers the in-memory Money total when the caller passes a hydrated
     * order (at placement the shipment row does not exist yet, so the DB
     * recomputation is short by shipping), falls back to OrderTotalHelper.
     *
     * @param object $order Loaded order (ideally with ->total_paid_tax_incl Money).
     *
     * @return float Amount still to collect, never negative.
     *
     * @since  2.0.0
     */
    public static function amountToCollect(object $order): float
    {
        return self::amountToCollectMoney($order)->getAmount();
    }

    /**
     * {@see self::amountToCollect()} as the order's Money: what the builder
     * hands the storage door, so its digits are written as they are.
     *
     * @param object $order Loaded order (ideally with ->total_paid_tax_incl Money).
     *
     * @return Money Amount still to collect at the order's frozen scale, never negative.
     *
     * @since  2.0.0
     */
    private static function amountToCollectMoney(object $order): Money
    {
        // A ROW AND A HYDRATED ORDER ARE NOT THE SAME NUMBER, which is the whole
        // point of the branch: at placement the shipment row does not exist yet,
        // so recomputing from the table is short by the carriage. The reader
        // says which is in hand; the row is passed on for the recomputation.
        $order = Order::of($order);

        $grand = $order->hasComputedTotal()
            ? $order->total()
            : self::money(self::orderGrandTotal(orderId: $order->id(), order: $order->row()), $order->id());

        // ONE DEFINITION OF WHAT IS STILL OWED. This used to be its own
        // subtraction, and it was wrong in two ways that only showed up
        // together with a refund or a coarse currency:
        //
        //   - its base was sumCollectedPayments(), i.e. money EVER collected,
        //     not money STILL HELD. Refund 30 of a settled 100 and the shop is
        //     owed 30 again, while this returned 0.00, and its own contract
        //     says 0.00 means skip the gateway entirely. The customer could not
        //     pay what they now owed.
        //   - it rounded the difference at a hard-coded 6 decimals rather than
        //     the order's frozen scale, the same hand-picked-ruler defect this
        //     class rejects by name for exceedsCap().
        //
        // OrderFacts already answers this exactly, at the order's own scale,
        // in integer minor units. The clamp stays: a gateway is never asked to
        // charge a negative amount, and an overpaid order has exactly nothing
        // left to collect. The excess is a fact to read, not a charge to make.
        return OrderFacts::balanceOf(orderId: $order->id(), grandTotal: $grand)->nonNegative();
    }

    /**
     * Money actually collected for an order.
     *
     * Completed 'payment' rows only: the same figure the orders list derives
     * as total_paid_real and the Totals card shows as Paid.
     *
     * @param int $orderId Order PK; 0 = no order.
     *
     * @return Money Collected total in the order's currency; zero, in the
     *               currency a new order takes, when there is no order.
     *
     * @since  2.0.0
     */
    public static function paidTotal(int $orderId): Money
    {
        // No order: nothing was collected, in the currency a new order takes.
        if ($orderId <= 0) {
            return Money::zero(OrderCurrency::forNewOrder());
        }

        return self::money(self::sumCollectedPayments($orderId), $orderId);
    }

    /**
     * Money returned to the customer for an order.
     *
     * SUM(ABS(amount)) of settled refund rows ('refunded' or 'completed',
     * linked and unlinked alike): the SAME figure the admin Totals card
     * and the orders-list payment ladder derive.
     *
     * @param int $orderId Order PK; 0 = no order.
     *
     * @return Money Settled refund total in the order's currency; zero, in
     *               the currency a new order takes, when there is no order.
     *
     * @since  2.0.0
     */
    public static function refundedTotal(int $orderId): Money
    {
        // No order: nothing was given back, in the currency a new order takes.
        if ($orderId <= 0) {
            return Money::zero(OrderCurrency::forNewOrder());
        }

        return self::money(self::sumSettledRefunds($orderId), $orderId);
    }

    /**
     * The signed due on an order.
     *
     * Current canonical total minus what the shop is actually still holding
     * (collected payments less the refunds that gave part of them back):
     *
     *   > 0  collectable: the customer still owes this
     *   = 0  settled
     *   < 0  OVERPAID: the shop is holding money it is no longer owed
     *
     * Nothing clamps it. The clamped `outstanding()` this replaces reported
     * an overpaid order as "0.00 due", so an order whose total was reduced
     * after payment looked settled while the customer was owed a refund.
     * The list, the totals card and the API all read it through
     * {@see OrderFacts}.
     *
     * @param int $orderId Order PK
     *
     * @return Money Signed balance in the order's currency and scale
     *
     * @since  2.0.0
     */
    public static function balance(int $orderId): Money
    {
        if ($orderId <= 0) {
            return Money::zero(OrderCurrency::forNewOrder());
        }

        return self::money(OrderFacts::forOrder($orderId)->balance, $orderId);
    }

    /**
     * What the shop is still holding for an order: collected payments minus
     * the refunds that returned part of them.
     *
     * @param int $orderId Order PK
     *
     * @return Money Money still held, in the order's currency and scale
     *
     * @since  2.0.0
     */
    public static function stillHeld(int $orderId): Money
    {
        if ($orderId <= 0) {
            return Money::zero(OrderCurrency::forNewOrder());
        }

        return self::money(OrderFacts::forOrder($orderId)->still_held, $orderId);
    }

    /**
     * How much of one payment can still be refunded.
     *
     * The payment's gross minus every settled refund recorded against it:
     * the SAME per-payment remainder the admin Totals card and the
     * orders-list payment ladder derive, and the exact figure the linked
     * over-refund guard enforces.
     *
     *
     * @return float Remaining refundable amount, never negative
     *               (0.0 when the row is missing)
     *
     * @since  2.0.0
     */
    /**
     * How much may still be refunded on this order, once every reservation the
     * core counts is kept apart.
     *
     * Ever-collected pool minus refunds already settled minus store credit
     * already handed back for this order's credit notes, minus what the
     * order's pending provider refunds reserve. A refund the provider has
     * accepted but not completed, whose exact amount in the order's currency
     * is known, keeps that amount out of every new refund start, whichever
     * payment of the order it is on, once per report
     * ({@see self::unbookedProviderRefunds()}); it is not refunded money in
     * the books, and it is released when the provider reports that it failed
     * or was cancelled. A report that holds the refund block instead is not
     * counted here: while it holds, no refund starts at all
     * ({@see self::refundStartRefusal()}). Contract for gateway authors:
     *
     *   - A refund your provider moves is reserved first
     *     (reserveRefund()), which decides it under the order's lock; this
     *     figure only informs. A form's maximum is refundFormMaximum().
     *   - Gate your Refund action on it too, so the button is not offered
     *     for money that is no longer refundable.
     *   - Never hold a booking of money the provider already moved to it:
     *     refundPayment() with gateway_moved books against the books alone,
     *     so a pending refund's reservation never refuses its own booking.
     *
     * @param int $orderId Order PK; 0 = no order.
     *
     * @return Money Refundable amount, never negative; zero, in the currency
     *               a new order takes, when there is no order.
     *
     * @since  2.0.0
     */
    public static function refundableNow(int $orderId): Money
    {
        // No order: nothing can be refunded, in the currency a new order takes.
        if ($orderId <= 0) {
            return Money::zero(OrderCurrency::forNewOrder());
        }

        // The reservations are read before the books: a reservation settling in
        // between is then counted twice (refuses more), never by neither.
        $reserved = self::reservedTotal(unbooked: self::unbookedProviderRefunds(orderId: $orderId), orderId: $orderId);

        return self::bookedRefundable(orderId: $orderId)
            ->subtract($reserved)
            ->nonNegative();
    }

    /**
     * What the books still allow to be refunded on an order, ignoring the
     * pending provider refunds' reservations: the ever-collected pool minus
     * everything already returned, by every route.
     *
     * The ceiling money a provider already moved is booked against; a new
     * refund start is held to it less the pending provider refunds'
     * reservations ({@see self::refundableNow()}).
     *
     * Public for offering record-only actions (recording money a provider
     * already moved) and for a gateway's display or close decisions. It NEVER
     * decides whether moved money gets booked (refundPayment() with
     * 'gateway_moved' decides that) or whether a new refund may start
     * (refundableNow() decides that). A pure read: nothing is written or locked.
     *
     * @param int $orderId Order PK, above zero.
     *
     * @return Money In the order's currency and scale, never negative.
     *
     * @since  2.1.0
     */
    public static function bookedRefundable(int $orderId): Money
    {
        // The round(..., 6) this replaced was a hard-coded scale in a system that
        // ships 0-, 1-, 3- and 4-decimal currencies. Money rounds to the
        // order's own scale, once, when the sums become money.
        // ONE definition of "already returned", shared with both write-time
        // caps, so the preflight and the guard can never disagree about the
        // same order. It gained the uncovered-wallet term: money returned to
        // a wallet that no settled refund row accounts for is money the order
        // has ALREADY given back, and without it a hand-flipped credit
        // payment reported its full amount as still refundable while the
        // customer was holding it.
        return self::money(self::sumRefundablePool($orderId), $orderId)
            ->subtract(self::returnedTotal($orderId))
            ->nonNegative();
    }

    /**
     * Why no new refund may start on this order now: the refund block.
     *
     * A provider has reported a refund on one of the order's payments that the
     * books do not hold (made in the provider's own portal, or made after Alfa
     * closed its request), and its money cannot simply be kept apart: the
     * provider says it is complete (settled), or it is pending but its amount
     * cannot be quantified exactly in the order's currency. Until it is
     * recorded or resolved, a new refund could pay the same money a second
     * time, so none starts on any payment of the order: the refundable pool is
     * the order's ({@see self::refundableNow()}). A pending refund with an exact
     * amount does not block: it reserves that amount in the pool. Each gateway
     * answers for its own payments from its own log and the books
     * ({@see PaymentsPlugin::unbookedProviderRefunds()}); a method with no
     * provider channel (the bundled Standard method, the customer's credit)
     * reports none. A gateway whose answer cannot be read refuses too: nothing
     * then shows that the books hold every refund it reported.
     *
     * The one place this rule is decided. Asked by the order screen and the
     * action page for every action declared as starting a refund
     * (PluginAction::startsRefund()), by {@see self::refundPayment()} for a
     * refund no provider has moved yet (the REST refund verb with it), before a
     * credit note is paid into the customer's credit, and by reserveRefund() under
     * the order's lock before a gateway sends a refund.
     * Recording a refund the provider already made is never refused; the
     * operator records each reported one with Record this refund
     * ({@see self::recordReportedRefund()}).
     *
     * @param int $orderId The order.
     *
     * @return string|null The reason, translated: per payment, each reported
     *                     refund with its amount and when it was reported, and
     *                     the way out; null when a new refund may start.
     *
     * @since  2.1.0
     */
    public static function refundStartRefusal(int $orderId): ?string
    {
        return self::unbookedRefusal(unbooked: self::unbookedProviderRefunds(orderId: $orderId));
    }

    /**
     * The refunds the gateways of an order's payments report and the books do
     * not hold, per payment, read from each gateway's own answer.
     *
     * The one reader of the refund block and of the reservations:
     * {@see self::refundStartRefusal()} and {@see self::refundableNow()} decide
     * from it, and the order screen's notice shows it. Only payment rows hold
     * money a provider can refund. A row of a method with no gateway (the
     * customer's credit) or with no recorded method is not asked; nor is a row
     * whose plugin is not an Alfa payment plugin (it is not installed). Each
     * payment's answer is read entry by entry ({@see self::askGateway()}): the
     * well-formed reports still unsettled are listed, under 'reserved' those
     * that reserve their amount (pending, an exact amount in the order's
     * currency: the amount given at the order's scale) and under 'refunds'
     * those that hold the block; every malformed entry is kept as unread with
     * what is wrong, and an answer that throws with a fixed sentence naming
     * the payment plugin (the exception is logged). All three lists are keyed by
     * payment id, in payment order.
     *
     * With them, under 'rows', the order's refund rows that are in the books but
     * not settled (pending or authorized, {@see self::REFUND_RESERVING_STATUSES}):
     * each reserves its amount exactly as a reserving report does, keyed
     * by the payment it returns money from (0 for a refund of the order that
     * names no payment). A report whose provider id such a row carries is the
     * row's own report: it is left out of the lists above, so the amount is
     * counted once, by the row.
     *
     * @param int $orderId The order.
     *
     * @return array{refunds: array<int, list<array{reportId: string, id: ?string, amount: ?Money, providerAmount: string, providerCurrency: string, reportedOn: string, status: string}>>, reserved: array<int, list<array{reportId: string, id: ?string, amount: Money, providerAmount: string, providerCurrency: string, reportedOn: string, status: string}>>, unread: array<int, string>, rows: array<int, list<array{id: int, amount: Money, status: string, reference: string, added: string, unreconciled: array{provider: string, amount: string, currency: string, at: string, staff: bool, held: bool, resurfaced: bool, words: list<array{kind: string, id: string, amount: string, currency: string, at: string}>}|null}>>}
     *
     * @since  2.1.0
     */
    public static function unbookedProviderRefunds(int $orderId): array
    {
        return self::reservationRead(orderId: $orderId);
    }

    /**
     * {@see self::unbookedProviderRefunds()}, leaving out one refund row: the row a
     * write is deciding, whose own amount is the candidate.
     *
     * @param int $orderId The order.
     * @param int $excludeRow The refund row left out of 'rows'; 0 = none.
     *
     * @return array The answer of {@see self::unbookedProviderRefunds()}.
     *
     * @since  2.1.0
     */
    private static function reservationRead(int $orderId, int $excludeRow = 0): array
    {
        $unbooked = ['refunds' => [], 'reserved' => [], 'unread' => [], 'rows' => []];

        if ($orderId <= 0) {
            return $unbooked;
        }

        $unbooked['rows'] = self::reservingRows(orderId: $orderId, excludeRow: $excludeRow);

        $db = self::db();
        $payments = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'id_order', 'payment_type', 'payment_method_type', 'transaction_status']))
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->where($db->quoteName('payment_type') . ' = ' . $db->quote(self::TYPE_PAYMENT))
                ->order($db->quoteName('id') . ' ASC'),
        )->loadObjectList() ?: [];

        foreach ($payments as $payment) {
            $paymentId = (int) $payment->id;
            $answer = self::askGateway(payment: $payment);

            if ($answer['refunds'] !== []) {
                $unbooked['refunds'][$paymentId] = $answer['refunds'];
            }

            if ($answer['reserved'] !== []) {
                $unbooked['reserved'][$paymentId] = $answer['reserved'];
            }

            if ($answer['unread'] !== null) {
                $unbooked['unread'][$paymentId] = $answer['unread'];
            }
        }

        return $unbooked;
    }

    /**
     * What the pending provider refunds of an order reserve in its refundable
     * pool: their total, and each one named as a refusal quotes it (payment,
     * refund, amount, when reported), from one read of
     * {@see self::unbookedProviderRefunds()}.
     *
     * @param int $orderId The order.
     *
     * @return array{total: Money, list: string} The total in the order's currency (zero when nothing is reserved), and the list ('' when empty).
     *
     * @since  2.1.0
     */
    public static function refundReservations(int $orderId): array
    {
        // No order reserves nothing: the zero refundableNow() answers for no order.
        if ($orderId <= 0) {
            return ['total' => self::refundableNow(orderId: $orderId), 'list' => ''];
        }

        $unbooked = self::unbookedProviderRefunds(orderId: $orderId);

        return ['total' => self::reservedTotal(unbooked: $unbooked, orderId: $orderId), 'list' => self::reservationList(unbooked: $unbooked)];
    }

    /**
     * What a listed report reserves in its order's refundable pool, or null when
     * it reserves nothing and holds the refund block instead.
     *
     * A report the provider has not completed (pending) reserves its amount
     * when that amount is known exactly in the order's currency: Money of that
     * currency, above zero, no finer than the order's scale. Any other listed
     * report (settled, or pending with no exact amount) holds the block: its
     * money is never reserved by a guess.
     *
     * @param array $report A well-formed report.
     * @param Currency $currency The order's currency at its frozen scale.
     *
     * @return Money|null The amount reserved, at the order's scale.
     *
     * @since  2.1.0
     */
    private static function reservedAmount(array $report, Currency $currency): ?Money
    {
        $amount = $report['amount'] ?? null;

        if (($report['status'] ?? '') !== self::REPORT_STATUS_PENDING
            || !$amount instanceof Money
            || !$amount->isPositive()
            || !$amount->getCurrency()->equals($currency)) {
            return null;
        }

        return self::frozenScaleMoney(value: $amount, currency: $currency);
    }

    /**
     * The total the reserving reports of an answer of
     * {@see self::unbookedProviderRefunds()} keep out of the order's pool.
     *
     * @param array $unbooked The answer.
     * @param int $orderId Its order, above zero.
     *
     * @return Money In the order's currency, exact; zero when nothing is reserved.
     *
     * @since  2.1.0
     */
    private static function reservedTotal(array $unbooked, int $orderId): Money
    {
        $total = Money::zero(self::orderCurrency($orderId));

        foreach ($unbooked['reserved'] ?? [] as $reports) {
            foreach ($reports as $report) {
                $total = $total->add(self::money($report['amount'], $orderId));
            }
        }

        foreach ($unbooked['rows'] ?? [] as $rows) {
            foreach ($rows as $row) {
                $total = $total->add($row['amount']);
            }
        }

        return $total;
    }

    /**
     * The refund rows of an order that are in the books but not settled (pending or
     * authorized): each reserves its amount, keyed by the payment it returns
     * money from, 0 for a refund of the order that names no payment.
     *
     * @param int $orderId The order.
     * @param int $excludeRow A refund row left out (the one a write decides); 0 = none.
     *
     * @return array<int, list<array{id: int, amount: Money, status: string, reference: string, added: string, unreconciled: array{provider: string, amount: string, currency: string, at: string, staff: bool, held: bool, resurfaced: bool, words: list<array{kind: string, id: string, amount: string, currency: string, at: string}>}|null}>> In row order; the amount exact at the order's scale.
     *
     * @since  2.1.0
     */
    private static function reservingRows(int $orderId, int $excludeRow = 0): array
    {
        $db = self::db();
        $statuses = implode(', ', array_map([$db, 'quote'], self::REFUND_RESERVING_STATUSES));
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'amount', 'transaction_status', 'transaction_id', 'id_refunded_payment', 'added']))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('id_order') . ' = ' . $orderId)
            ->where($db->quoteName('payment_type') . ' = ' . $db->quote(self::TYPE_REFUND))
            ->where($db->quoteName('transaction_status') . ' IN (' . $statuses . ')')
            ->order($db->quoteName('id') . ' ASC');

        if ($excludeRow > 0) {
            $query->where($db->quoteName('id') . ' <> ' . $excludeRow);
        }

        $rows = [];
        $found = $db->setQuery($query)->loadObjectList() ?: [];
        $marked = $found === [] ? [] : self::unreconciledMarks(orderId: $orderId);

        foreach ($found as $row) {
            $rows[(int) ($row->id_refunded_payment ?? 0)][] = [
                'id' => (int) $row->id,
                'amount' => self::money($row->amount, $orderId)->abs(),
                'status' => (string) $row->transaction_status,
                'reference' => (string) ($row->transaction_id ?? ''),
                'added' => (string) $row->added,
                'unreconciled' => $marked[(int) $row->id] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * The refund rows of an order marked as settled by the provider while the books refused the
     * booking ({@see self::EVENT_REFUND_UNRECONCILED}), with what the provider stated.
     *
     * Each row's WORDS are every provider word core kept on it for staff: its marks and the later words added to it (a
     * failure or release the provider reported about it is a word of kind STATUS_FAILED or STATUS_CANCELLED, shown and
     * never stated)
     * ({@see self::EVENT_REFUND_WORD_ADDED}, {@see self::keptWordAdded()}), one per fact (kind, provider id and
     * figure), latest first, in the order the words arrived: a fact the provider sends again after another word is its
     * latest word again and moves to the top (main's N8-1: arrival order, repeats included; a mark written again by a
     * retry moves nothing). What the row's report states is the provider's latest SETTLED word (its figure, and its id
     * or else the latest id any word gave), or, while no word says settled, the latest word: Record this refund books
     * the latest figure the provider settled; a hold is never booked (since 2.1.0).
     *
     * @param int $orderId The order.
     *
     * @return array<int, array{provider: string, amount: string, currency: string, at: string, staff: bool, held: bool, resurfaced: bool, key: string, words: list<array{kind: string, id: string, amount: string, currency: string, at: string}>}> Keyed by refund row id ('staff': it waits for staff, {@see self::resurface()}, by its latest mark; 'held': no word says settled; 'resurfaced': the row came back for staff after a staff resolution; 'key': the request whose one refund it keeps outside the request's reference, {@see self::beyondDecision()}, or ''; 'at': its latest entry).
     *
     * @since  2.1.0
     */
    private static function unreconciledMarks(int $orderId): array
    {
        $db = self::db();
        $flags = [];
        $words = [];
        $last = [];

        foreach ($db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['event', 'entity_id', 'context', 'created']))
                ->from($db->quoteName('#__alfa_order_activity_log'))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->whereIn($db->quoteName('event'), [self::EVENT_REFUND_UNRECONCILED, self::EVENT_REFUND_WORD_ADDED], ParameterType::STRING)
                ->order($db->quoteName('id') . ' ASC'),
        )->loadObjectList() ?: [] as $entry) {
            $context = json_decode((string) $entry->context, true);
            $context = \is_array($context) ? $context : [];
            $rowId = (int) $entry->entity_id;
            $isMark = (string) $entry->event === self::EVENT_REFUND_UNRECONCILED;
            $word = [
                'kind' => $isMark
                    ? (!empty($context['held']) ? self::REPORT_STATUS_PENDING : self::REPORT_STATUS_SETTLED)
                    : (\in_array((string) ($context['kind'] ?? ''), [self::REPORT_STATUS_PENDING, self::STATUS_FAILED, self::STATUS_CANCELLED], true) ? (string) $context['kind'] : self::REPORT_STATUS_SETTLED),
                'id' => (string) ($context['provider_refund_id'] ?? ''),
                'amount' => (string) ($context['provider_amount'] ?? ''),
                'currency' => (string) ($context['provider_currency'] ?? ''),
                'at' => (string) $entry->created,
            ];
            // One fact once: the same kind and figure under the same id is the same word; with no id, the same kind and
            // figure the row already shows (under any id) is too.
            $known = array_filter(
                $words[$rowId] ?? [],
                static fn (array $kept): bool => $kept['kind'] === $word['kind'] && $kept['amount'] === $word['amount'] && ($word['id'] === '' || $kept['id'] === $word['id']),
            );

            if ($known === []) {
                $words[$rowId][] = $word;
            } elseif (!$isMark) {
                // An earlier word the provider sent again (keptWordAdded() writes it only when it is not the row's latest
                // word) is its latest word now: moved to the end with its new date, its provider id kept (main's N8-1:
                // arrival order, repeats included). A mark written again (a retry) moves nothing.
                $at = (int) array_key_last($known);
                $repeated = $words[$rowId][$at];
                unset($words[$rowId][$at]);
                $repeated['at'] = $word['at'];
                $repeated['id'] = $word['id'] !== '' ? $word['id'] : $repeated['id'];
                $words[$rowId] = array_values($words[$rowId]);
                $words[$rowId][] = $repeated;
            }

            $last[$rowId] = (string) $entry->created;

            if ($isMark) {
                $flags[$rowId] = [
                    'staff' => !empty($context['staff_decides']),
                    // The row came back for staff after a staff resolution (any of its marks says so).
                    'resurfaced' => ($flags[$rowId]['resurfaced'] ?? false) || !empty($context['resolved_report']),
                    // The request whose one refund the row keeps outside its request reference (beyondDecision()).
                    'key' => (string) ($context['request_key'] ?? '') !== '' ? (string) $context['request_key'] : ($flags[$rowId]['key'] ?? ''),
                ];
            }
        }

        $marks = [];

        foreach ($flags as $rowId => $flag) {
            $list = $words[$rowId] ?? [];
            $settled = array_values(array_filter($list, static fn (array $word): bool => $word['kind'] === self::REPORT_STATUS_SETTLED));
            // A failure the provider reported is shown, never stated: the report keeps the latest figure it stated.
            $figured = array_values(array_filter($list, static fn (array $word): bool => \in_array($word['kind'], [self::REPORT_STATUS_SETTLED, self::REPORT_STATUS_PENDING], true)));
            $stated = $settled !== [] ? $settled[\count($settled) - 1] : ($figured !== [] ? $figured[\count($figured) - 1] : ($list !== [] ? $list[\count($list) - 1] : ['id' => '', 'amount' => '', 'currency' => '']));
            $ids = array_values(array_filter(array_reverse(array_column($list, 'id')), static fn (string $id): bool => $id !== ''));
            $marks[$rowId] = [
                'provider' => $stated['id'] !== '' ? $stated['id'] : ($ids[0] ?? ''),
                'amount' => $stated['amount'],
                'currency' => $stated['currency'],
                'at' => $last[$rowId] ?? '',
                'staff' => $flag['staff'],
                // Held while no word says settled: a late hold never turns money moved back into a hold.
                'held' => $settled === [],
                'resurfaced' => $flag['resurfaced'],
                'key' => $flag['key'],
                'words' => array_reverse($list),
            ];
        }

        return $marks;
    }

    /**
     * The reserving reports of an answer of {@see self::unbookedProviderRefunds()},
     * each as "payment #P: refund R of A, reported T", joined by '; '.
     *
     * @param array $unbooked The answer.
     *
     * @return string Translated, plain text; '' when nothing is reserved.
     *
     * @since  2.1.0
     */
    private static function reservationList(array $unbooked): string
    {
        $items = [];

        foreach ($unbooked['reserved'] ?? [] as $paymentId => $reports) {
            foreach ($reports as $report) {
                $items[] = Text::sprintf(
                    'COM_ALFA_REFUND_RESERVATION_ITEM',
                    $paymentId,
                    self::reportReference(providerId: $report['id'], reportId: $report['reportId']),
                    $report['amount']->format(),
                    $report['reportedOn'],
                );
            }
        }

        foreach ($unbooked['rows'] ?? [] as $paymentId => $rows) {
            foreach ($rows as $row) {
                $items[] = self::reservingRowItem(paymentId: (int) $paymentId, row: $row);
            }
        }

        return implode('; ', $items);
    }

    /**
     * One refund row that reserves its amount, as a refusal or the order screen names
     * it: its payment (or the order), its row id, amount, status and since when.
     *
     * @param int $paymentId The payment it returns money from; 0 = the order.
     * @param array $row An entry of {@see self::reservingRows()}.
     *
     * @return string Translated, plain text.
     *
     * @since  2.1.0
     */
    private static function reservingRowItem(int $paymentId, array $row): string
    {
        $status = Text::_('COM_ALFA_STATUS_' . strtoupper($row['status']));

        return $paymentId > 0
            ? Text::sprintf('COM_ALFA_REFUND_RESERVATION_ROW_ITEM', $paymentId, $row['id'], $row['amount']->format(), $status, $row['added'])
            : Text::sprintf('COM_ALFA_REFUND_RESERVATION_ROW_ITEM_ORDER', $row['id'], $row['amount']->format(), $status, $row['added']);
    }

    /**
     * Why a refund start of this amount is refused: beyond what the order may
     * still refund once its pending provider refunds are reserved.
     *
     * @param Money $requested The start asked for.
     * @param int $orderId The order.
     * @param Money $left What is left once the reservations are kept apart.
     * @param Money $reserved What they reserve.
     * @param array $unbooked The answer of {@see self::unbookedProviderRefunds()} they come from.
     *
     * @return string Translated, plain text, naming each reservation.
     *
     * @since  2.1.0
     */
    private static function reservedRefusal(Money $requested, int $orderId, Money $left, Money $reserved, array $unbooked): string
    {
        return Text::sprintf(
            'COM_ALFA_REFUND_RESERVED_REFUSAL',
            $requested->format(),
            $orderId,
            $left->format(),
            $reserved->format(),
            self::reservationList(unbooked: $unbooked),
        );
    }

    /**
     * Decide a refund start again under the order's lock (create() with refundStart,
     * and a refund brought back into the books by update() or updateIfStatus()):
     * the refund block, the pool less what the pending provider refunds reserve,
     * and the refund's payment's remainder less what that payment's own pending
     * provider refunds reserve, from one read of the gateways' answers, so a report
     * that moves from pending to settled meanwhile is counted by one of them, never
     * by neither.
     *
     * @param int $orderId The order, locked by the caller.
     * @param Money $requested The refund being written.
     * @param Money $settled Everything returned with it ({@see self::returnedTotal()} with the candidate).
     * @param string $pool The ever-collected pool, as the SUM's decimal text.
     * @param int $paymentId The payment the refund returns money from; 0 = none (an unlinked refund).
     * @param int $excludeRow The refund row being written, when it is in the books already (its own
     *                        reservation is replaced by the candidate); 0 = a new row.
     *
     * @return array{outcome: string, reason: string}|null The refusal (OUTCOME_BLOCKED or OUTCOME_NOT_REFUNDABLE), or null.
     *
     * @since  2.1.0
     */
    private static function startRefusalLocked(int $orderId, Money $requested, Money $settled, string $pool, int $paymentId = 0, int $excludeRow = 0): ?array
    {
        $unbooked = self::reservationRead(orderId: $orderId, excludeRow: $excludeRow);
        $blocked = self::unbookedRefusal(unbooked: $unbooked);

        if ($blocked !== null) {
            return ['outcome' => self::OUTCOME_BLOCKED, 'reason' => $blocked];
        }

        $reserved = self::reservedTotal(unbooked: $unbooked, orderId: $orderId);

        if ($reserved->isPositive() && self::exceedsCap([$settled, $reserved], $pool, $orderId)) {
            $left = self::bookedRefundable(orderId: $orderId)->subtract($reserved)->nonNegative();

            return ['outcome' => self::OUTCOME_NOT_REFUNDABLE, 'reason' => self::reservedRefusal(requested: $requested, orderId: $orderId, left: $left, reserved: $reserved, unbooked: $unbooked)];
        }

        $paymentRefusal = self::paymentReservedRefusal(paymentId: $paymentId, requested: $requested, unbooked: $unbooked, orderId: $orderId);

        return $paymentRefusal === null ? null : ['outcome' => self::OUTCOME_NOT_REFUNDABLE, 'reason' => $paymentRefusal];
    }

    /**
     * Decide a write that brings a refund into the books that was not counted
     * before (a failed or cancelled refund brought back, a payment turned into a
     * refund) as the refund start it is, under the order's lock: exactly as
     * create() decides a new start ({@see self::startRefusalLocked()}), with the
     * row as the write leaves it. Asked by update() and updateIfStatus() after the
     * money caps, on the row read under the lock, so the payment form, the REST
     * settle verb, the builder and a direct call are all held to it.
     *
     * A refund in the books that is not settled (pending or authorized) already
     * reserves its amount: its settle is never a start (the caps alone
     * judge it, so its own reservation never refuses it), and lowering it
     * releases the difference. RAISING its amount is decided here as a start for
     * the increase: the row is judged at its new amount with its own reservation
     * left out. A provider's settle through the compare-and-set door
     * ($providerSettle) books money that moved and is judged by the caps alone,
     * whatever its figure. A settled refund's change is judged by the caps alone.
     *
     * @param int $id The row being written.
     * @param int $orderId Its order, locked by the caller.
     * @param object $stored The row as read under the lock.
     * @param array $data The write (amount already the column's decimal).
     * @param bool $providerSettle True from updateIfStatus(): a payment plugin's
     *                             compare-and-set (since 2.1.0).
     *
     * @return array{outcome: string, reason: string}|null The refusal, or null when the write may go on.
     *
     * @since  2.1.0
     */
    private static function revivalRefusalLocked(int $id, int $orderId, object $stored, array $data, bool $providerSettle = false): ?array
    {
        if (!self::refundEnters(stored: $stored, data: $data) && !self::reservationRaised(stored: $stored, data: $data, orderId: $orderId, providerSettle: $providerSettle)) {
            return null;
        }

        // A held figure the provider grew is its word, not a start (providerFact()).
        if (self::providerFactRow(['payment_type' => (string) ($data['payment_type'] ?? $stored->payment_type ?? ''), 'transaction_status' => (string) ($data['transaction_status'] ?? $stored->transaction_status ?? '')])) {
            return null;
        }

        $amount = self::money(\array_key_exists('amount', $data) ? ($data['amount'] ?? 0) : ($stored->amount ?? 0), $orderId)->abs();
        $linkId = (int) (\array_key_exists('id_refunded_payment', $data) ? $data['id_refunded_payment'] : ($stored->id_refunded_payment ?? 0));

        return self::startRefusalLocked(
            orderId: $orderId,
            requested: $amount,
            settled: self::returnedTotal(orderId: $orderId, excludeId: $id, candidateOriginalId: $linkId, candidateAmount: $amount),
            pool: self::sumRefundablePool($orderId, excludeId: $id),
            paymentId: $linkId,
            excludeRow: $id,
        );
    }

    /**
     * Whether a write raises the amount of a refund that is in the books but not
     * settled (pending or authorized) and stays a refund: the increase is a refund
     * start ({@see self::revivalRefusalLocked()}). Not when a payment plugin's
     * compare-and-set settles it: that books money the provider moved.
     *
     * @param object $stored The row as read under the lock.
     * @param array $data The write.
     * @param int $orderId Its order.
     * @param bool $providerSettle True from updateIfStatus().
     *
     * @since  2.1.0
     */
    private static function reservationRaised(object $stored, array $data, int $orderId, bool $providerSettle): bool
    {
        if ((string) ($stored->payment_type ?? '') !== self::TYPE_REFUND
            || !\in_array((string) ($stored->transaction_status ?? ''), self::REFUND_RESERVING_STATUSES, true)
            || !\array_key_exists('amount', $data)
            || (string) ($data['payment_type'] ?? self::TYPE_REFUND) !== self::TYPE_REFUND) {
            return false;
        }

        $to = (string) ($data['transaction_status'] ?? $stored->transaction_status);

        if (\in_array($to, [self::STATUS_FAILED, self::STATUS_CANCELLED], true)
            || ($providerSettle && \in_array($to, self::REFUND_SETTLED_STATUSES, true))) {
            return false;
        }

        return self::money($data['amount'] ?? 0, $orderId)->abs()->greaterThan(self::money($stored->amount ?? 0, $orderId)->abs());
    }

    /**
     * The reservations of one payment, from an answer of
     * {@see self::unbookedProviderRefunds()}, in the shape of that answer.
     *
     * @param array $unbooked The order-wide answer.
     * @param int $paymentId The payment; 0 = none.
     *
     * @return array{reserved: array<int, list<array>>, rows: array<int, list<array>>} Its reserving reports and its reserving refund rows.
     *
     * @since  2.1.0
     */
    private static function reservationsOf(array $unbooked, int $paymentId): array
    {
        return [
            'reserved' => $paymentId > 0 && isset($unbooked['reserved'][$paymentId]) ? [$paymentId => $unbooked['reserved'][$paymentId]] : [],
            'rows' => $paymentId > 0 && isset($unbooked['rows'][$paymentId]) ? [$paymentId => $unbooked['rows'][$paymentId]] : [],
        ];
    }

    /**
     * Why a refund start of this amount is refused on its payment: beyond what the
     * payment has left once its own pending provider refunds are reserved. The
     * provider holds that money for them on this payment, so a start that fits the
     * order's pool may still not fit the payment it returns money from.
     *
     * @param int $paymentId The payment the refund returns money from; 0 = none (nothing to ask).
     * @param Money $requested The start.
     * @param array $unbooked The answer of {@see self::unbookedProviderRefunds()} the decision is taken from.
     * @param int $orderId The order.
     *
     * @return string|null Translated, plain text, naming the payment's reservations; null when the start fits.
     *
     * @since  2.1.0
     */
    private static function paymentReservedRefusal(int $paymentId, Money $requested, array $unbooked, int $orderId): ?string
    {
        $own = self::reservationsOf(unbooked: $unbooked, paymentId: $paymentId);
        $reserved = self::reservedTotal(unbooked: $own, orderId: $orderId);

        if (!$reserved->isPositive()) {
            return null;
        }

        $payment = self::getRaw(id: $paymentId);
        $left = ($payment !== null ? self::bookedRemainder(original: $payment) : Money::zero(self::orderCurrency($orderId)))
            ->subtract($reserved)
            ->nonNegative();

        if (!$requested->greaterThan($left)) {
            return null;
        }

        return Text::sprintf(
            'COM_ALFA_REFUND_RESERVED_PAYMENT_REFUSAL',
            $requested->format(),
            $paymentId,
            $left->format(),
            $reserved->format(),
            self::reservationList(unbooked: $own),
        );
    }

    /**
     * The payments of an order that were set back from refunded to completed because
     * a refund of them became failed or cancelled, while they are still completed:
     * the order screen warns staff that what the refund set off earlier was not reversed.
     *
     * Read from the order's timeline, where {@see self::reevaluateAfterRefundEnded()}
     * records each such change: the latest per payment, in payment order. A payment
     * refunded again since (a later refund covers it) is not listed.
     *
     * @param int $orderId The order.
     *
     * @return list<array{payment: int, refund: int, status: string, at: string}> Per payment: the refund, its status and when (UTC).
     *
     * @since  2.1.0
     */
    public static function refundReevaluations(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $db = self::db();
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['entity_id', 'context', 'created']))
                ->from($db->quoteName('#__alfa_order_activity_log'))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->where($db->quoteName('event') . ' = ' . $db->quote(self::EVENT_REFUND_REEVALUATED))
                ->order($db->quoteName('id') . ' DESC'),
        )->loadObjectList() ?: [];
        $latest = [];

        foreach ($rows as $row) {
            $paymentId = (int) $row->entity_id;

            if ($paymentId <= 0 || isset($latest[$paymentId])) {
                continue;
            }

            $context = json_decode((string) $row->context, true);
            $latest[$paymentId] = [
                'payment' => $paymentId,
                'refund' => (int) ($context['id_refund'] ?? 0),
                'status' => (string) ($context['refund_status'] ?? ''),
                'at' => (string) $row->created,
            ];
        }

        ksort($latest);

        return array_values(array_filter(
            $latest,
            static fn (array $entry): bool => (string) (self::getRaw(id: $entry['payment'])->transaction_status ?? '') === self::STATUS_COMPLETED,
        ));
    }

    /**
     * The refunds the gateway of one payment reports and the books do not hold:
     * {@see self::unbookedProviderRefunds()} for a single payment row.
     *
     * Asked by the order screen for the payment's report actions. A row that is
     * not a payment, or does not exist, reports nothing. As there: 'refunds'
     * holds the reports that hold the block, 'reserved' those that reserve
     * their amount.
     *
     * @param int $paymentId The payment.
     *
     * @return array{refunds: list<array{reportId: string, id: ?string, amount: ?Money, providerAmount: string, providerCurrency: string, reportedOn: string, status: string}>, reserved: list<array{reportId: string, id: ?string, amount: Money, providerAmount: string, providerCurrency: string, reportedOn: string, status: string}>, unread: string|null}
     *
     * @since  2.1.0
     */
    public static function unbookedProviderRefundsOfPayment(int $paymentId): array
    {
        $payment = $paymentId > 0 ? self::getRaw(id: $paymentId) : null;

        if ($payment === null || (string) ($payment->payment_type ?? '') !== self::TYPE_PAYMENT) {
            return ['refunds' => [], 'reserved' => [], 'unread' => null];
        }

        $answer = self::askGateway(payment: $payment);

        return ['refunds' => $answer['refunds'], 'reserved' => $answer['reserved'], 'unread' => $answer['unread']];
    }

    /**
     * Ask one payment's gateway which refunds it reports that the books do not
     * hold, and read the answer entry by entry.
     *
     * The agreed entry ({@see PaymentsPlugin::unbookedProviderRefunds()}):
     * reportId (the identity of the reported refund, built from the provider's
     * facts), id (the provider's refund id, or null when the provider gave none),
     * amount (Money in the order's currency, or null when no exact amount can be
     * derived), providerAmount and providerCurrency (as the provider stated
     * them), reportedOn, and status (settled, or pending while the provider has
     * not completed the refund). A report already settled in the books is left
     * out, whatever the gateway says: one with an outcome kept for its reference
     * (recorded, reconciled, or a duplicate or invalid report), and one whose
     * provider id a refund row of this payment already carries, not failed or
     * cancelled (booked by any path). A malformed entry holds the block: it is
     * unread, with what is wrong. An answer that throws is unread as well: the
     * reason is a fixed, translated sentence naming the payment plugin, never
     * the exception's own text, which is written to the payments log
     * (com_alfa.payments) with the payment id. A reference listed twice is
     * read once, so a pending refund reserves its amount once however often it
     * is repeated. A report that reserves its amount
     * ({@see self::reservedAmount()}) is listed under 'reserved', with that
     * amount at the order's scale; every other report under 'refunds'. A
     * method with no gateway (the customer's credit), no recorded method, or an
     * element that is not an Alfa payment plugin answers nothing.
     *
     * @param object $payment The payment row (id, id_order, payment_method_type).
     *
     * @return array{refunds: list<array{reportId: string, id: ?string, amount: ?Money, providerAmount: string, providerCurrency: string, reportedOn: string, status: string}>, reserved: list<array{reportId: string, id: ?string, amount: Money, providerAmount: string, providerCurrency: string, reportedOn: string, status: string}>, unread: string|null, booked: array<string, array{entry: array, row: int}>}
     *
     * @since  2.1.0
     */
    private static function askGateway(object $payment): array
    {
        $none = ['refunds' => [], 'reserved' => [], 'unread' => null, 'booked' => []];
        $paymentId = (int) ($payment->id ?? 0);
        $element = (string) ($payment->payment_method_type ?? '');

        if ($paymentId <= 0 || $element === '' || \in_array($element, self::NON_GATEWAY_TYPES, true)) {
            return $none;
        }

        try {
            $plugin = Factory::getApplication()->bootPlugin($element, 'alfa-payments');

            if (!$plugin instanceof PaymentsPlugin) {
                return $none;
            }

            $answer = $plugin->unbookedProviderRefunds(paymentId: $paymentId);
            $currency = OrderCurrency::of(orderId: (int) ($payment->id_order ?? 0));
        } catch (Throwable $e) {
            // Staff see a fixed sentence naming the plugin; the exception's own text goes to the log only.
            try {
                self::ensureLogger();
                Log::add(
                    sprintf('Payment #%d: the %s payment plugin failed while reporting its provider refunds (%s): %s', $paymentId, $element, $e::class, $e->getMessage()),
                    Log::ERROR,
                    'com_alfa.payments',
                );
            } catch (Throwable) {
                // The refusal below holds whether or not the log can be written.
            }

            return ['refunds' => [], 'reserved' => [], 'unread' => Text::sprintf('COM_ALFA_REFUND_REPORTS_PLUGIN_FAILED', $element), 'booked' => []];
        }

        $settled = self::settledReports(element: $element, paymentId: $paymentId);
        $refunds = [];
        $reserved = [];
        $problems = [];
        $booked = [];
        $seen = [];

        foreach ($answer as $entry) {
            $reportId = \is_array($entry) && \is_string($entry['reportId'] ?? null) ? $entry['reportId'] : '';

            // Settled: never back, whatever the gateway repeats.
            if ($reportId !== '' && \in_array($reportId, $settled, true)) {
                continue;
            }

            $problem = self::reportProblem(entry: $entry, currency: $currency);

            if ($problem !== null) {
                $problems[] = $problem;

                continue;
            }

            if (isset($seen[$reportId])) {
                continue;
            }

            $seen[$reportId] = true;
            $report = [
                'reportId' => $reportId,
                'id' => $entry['id'],
                'amount' => $entry['amount'],
                'providerAmount' => $entry['providerAmount'],
                'providerCurrency' => $entry['providerCurrency'],
                'reportedOn' => $entry['reportedOn'],
                'status' => $entry['status'],
            ];
            $row = $report['id'] === null ? null : self::refundUnderReference(paymentId: $paymentId, reference: $report['id']);

            // A report with no exact amount in the order's currency is never absorbed by a reservation (a
            // pending or authorized row) under its id: what it stands for cannot be counted, so it keeps the
            // block. Under a settled row it is booked.
            if ($row !== null && $report['amount'] === null && \in_array((string) $row->transaction_status, self::REFUND_RESERVING_STATUSES, true)) {
                $row = null;
            }

            if ($row !== null) {
                $booked[$reportId] = ['entry' => $report, 'row' => (int) $row->id];

                continue;
            }

            $reserves = self::reservedAmount(report: $report, currency: $currency);

            if ($reserves !== null) {
                $reserved[] = ['amount' => $reserves] + $report;

                continue;
            }

            $refunds[] = $report;
        }

        // Staff are told once, the first time core sees each settled report the books do not hold.
        foreach ($refunds as $report) {
            if ($report['status'] === self::REPORT_STATUS_SETTLED) {
                self::surfaceUnbookedReport(payment: $payment, report: $report);
            }
        }

        // And the notice goes to the history once its report holds the block no more: booked under its provider id
        // or listed no more (a gateway stops listing a refund the books hold). Only from an answer read whole.
        if ($problems === []) {
            self::archiveSettledNotices(paymentId: $paymentId, orderId: (int) ($payment->id_order ?? 0), listed: $refunds, booked: $booked);
        }

        // The core's own report of each reservation of this payment its provider settled while the books
        // refused the booking: money moved that the books do not hold; it holds the block until the way out.
        foreach (self::unreconciledReports(payment: $payment, currency: $currency) as $report) {
            if (!\in_array($report['reportId'], $settled, true)) {
                $refunds[] = $report;
            }
        }

        return ['refunds' => $refunds, 'reserved' => $reserved, 'unread' => $problems === [] ? null : implode('; ', $problems), 'booked' => $booked];
    }

    /**
     * The dedup key of the notification of one settled report the books do not hold: per payment and report (the
     * report's reference as a SHA-256 prefix, so any reference fits the key).
     *
     * @param int $paymentId The payment.
     * @param string $reportId The report's reference.
     *
     * @since  2.1.0
     */
    private static function unbookedNoticeKey(int $paymentId, string $reportId): string
    {
        return 'payments.unbooked.' . $paymentId . '.' . substr(hash('sha256', $reportId), 0, 40);
    }

    /**
     * Retire the notices of settled reports of one payment that no longer hold the refund block ({@see
     * self::surfaceUnbookedReport()}): every open notice of the payment whose report is not among the settled
     * reports its gateway lists now as not in the books. A report booked since (under its provider id: listed as
     * booked, or a refund row of the payment carries that id) leaves its notice archived as history: O5, a provider
     * refund booked automatically leaves no unresolved state. A report that merely dropped out of the listing, neither
     * booked nor resolved, has its notice removed, so that if it is listed again it is raised again (with its
     * timeline entry). A resolved report's notice was archived by the way out. Best effort: a read is never refused
     * for it.
     *
     * @param int $paymentId The payment.
     * @param int $orderId Its order.
     * @param list<array> $listed The gateway's reports the books do not hold, as {@see self::askGateway()} lists them.
     * @param array<string, array{entry: array, row: int}> $booked The listed reports the books hold, by reportId.
     *
     * @since  2.1.0
     */
    private static function archiveSettledNotices(int $paymentId, int $orderId, array $listed, array $booked): void
    {
        try {
            $standing = [];

            foreach ($listed as $report) {
                if ($report['status'] === self::REPORT_STATUS_SETTLED) {
                    $standing[self::unbookedNoticeKey(paymentId: $paymentId, reportId: (string) $report['reportId'])] = true;
                }
            }

            $stale = array_values(array_filter(
                NotificationHelper::keys('payments.unbooked.' . $paymentId . '.'),
                static fn (string $key): bool => !isset($standing[$key]) && NotificationHelper::isOpen($key),
            ));

            if ($stale === []) {
                return;
            }

            $bookedKeys = [];

            foreach (array_keys($booked) as $reportId) {
                $bookedKeys[self::unbookedNoticeKey(paymentId: $paymentId, reportId: (string) $reportId)] = true;
            }

            // What each notice reported: its timeline entry names the report and the provider's refund id.
            $providerOf = [];
            $db = self::db();

            foreach ($db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('context'))
                    ->from($db->quoteName('#__alfa_order_activity_log'))
                    ->where($db->quoteName('id_order') . ' = ' . $orderId)
                    ->where($db->quoteName('event') . ' = ' . $db->quote(self::EVENT_REFUND_UNBOOKED_REPORTED))
                    ->where($db->quoteName('entity_id') . ' = ' . $paymentId),
            )->loadColumn() ?: [] as $context) {
                $facts = json_decode((string) $context, true);

                if (\is_array($facts) && (string) ($facts['report_id'] ?? '') !== '') {
                    $providerOf[self::unbookedNoticeKey(paymentId: $paymentId, reportId: (string) $facts['report_id'])] = (string) ($facts['provider_refund_id'] ?? '');
                }
            }

            foreach ($stale as $key) {
                $providerId = $providerOf[$key] ?? '';
                $isBooked = isset($bookedKeys[$key]) || ($providerId !== '' && self::refundUnderReference($paymentId, $providerId) !== null);

                NotificationHelper::clear($key, !$isBooked);
            }
        } catch (Throwable) {
            // The refund block itself does not depend on the notice.
        }
    }

    /**
     * Tell staff, once, that a gateway lists a refund its provider settled that the books do not hold (O5: refunds
     * on the order are blocked until staff resolve it): one order timeline entry (EVENT_REFUND_UNBOOKED_REPORTED)
     * and one danger notification with the way out, deduplicated per payment and report, so a report listed again
     * raises nothing new. Best effort: a read that lists the report is never refused for it. Gateways send no alert
     * of their own (their CRITICAL log only).
     *
     * @param object $payment The payment row (id, id_order).
     * @param array $report The report as {@see self::askGateway()} lists it.
     *
     * @since  2.1.0
     */
    private static function surfaceUnbookedReport(object $payment, array $report): void
    {
        $paymentId = (int) $payment->id;
        $orderId = (int) $payment->id_order;
        $key = self::unbookedNoticeKey(paymentId: $paymentId, reportId: (string) $report['reportId']);

        try {
            if (NotificationHelper::exists($key)) {
                return;
            }

            $reference = self::reportReference(providerId: $report['id'], reportId: (string) $report['reportId']);
            $summary = sprintf(
                'Payment #%d: a refund the provider made is not in the books (report %s, %s %s); refunds on this order are blocked until staff resolve it: Record this refund, reconcile it to an existing refund, or resolve it as a duplicate or invalid report.',
                $paymentId,
                $reference,
                (string) $report['providerAmount'],
                (string) $report['providerCurrency'],
            );
            OrderModel::logOrderActivity(
                $orderId,
                self::EVENT_REFUND_UNBOOKED_REPORTED,
                $summary,
                [
                    'id_payment' => $paymentId,
                    'report_id' => (string) $report['reportId'],
                    'provider_refund_id' => (string) ($report['id'] ?? ''),
                    'provider_amount' => (string) $report['providerAmount'],
                    'provider_currency' => (string) $report['providerCurrency'],
                    'amount_known' => $report['amount'] === null ? 0 : 1,
                ],
                $paymentId,
            );
            NotificationHelper::push(
                $key,
                'Payment #' . $paymentId . ': a refund the provider made is not in the books',
                [
                    'severity' => 'danger',
                    'group' => 'Payments',
                    'message' => $summary,
                    'url' => 'index.php?option=com_alfa&task=order.edit&id=' . $orderId,
                    'view' => ['action' => 'alfa.orders.view', 'asset' => 'com_alfa'],
                    'link' => ['action' => 'alfa.orders.edit', 'asset' => 'com_alfa'],
                ],
            );
        } catch (Throwable) {
            // The refund block itself does not depend on the notice.
        }
    }

    /**
     * The core's own reports of the reservations of one payment that its provider settled while the books
     * refused the booking ({@see self::EVENT_REFUND_UNRECONCILED}), still pending or authorized: settled
     * reports in the agreed shape, under REPORT_RESERVATION_PREFIX and the row id, with what the provider
     * stated (its refund id, or none; its figure in the order's currency, exact). A refund the provider only holds
     * (pending) is reported pending (so it is never recorded as settled): one kept for staff always, one held on a
     * payment that is no longer collected only while that payment is not collected again. Each report states the
     * provider's latest settled word and lists every word core kept on the row ('words', latest first:
     * {@see self::unreconciledMarks()}; since 2.1.0).
     *
     * @param object $payment The payment row (id, id_order, transaction_status).
     * @param Currency $currency The order's currency at its frozen scale.
     *
     * @return list<array{reportId: string, id: ?string, amount: ?Money, providerAmount: string, providerCurrency: string, reportedOn: string, status: string, words: list<array{kind: string, id: string, amount: string, currency: string, at: string}>}>
     *
     * @since  2.1.0
     */
    private static function unreconciledReports(object $payment, Currency $currency): array
    {
        $reports = [];
        $paymentId = (int) $payment->id;

        foreach (self::reservingRows(orderId: (int) $payment->id_order)[$paymentId] ?? [] as $row) {
            $mark = $row['unreconciled'];

            if ($mark === null
                || ($mark['held'] && !$mark['staff'] && \in_array((string) ($payment->transaction_status ?? ''), [self::STATUS_COMPLETED, self::STATUS_REFUNDED], true))) {
                continue;
            }

            try {
                $amount = self::frozenScaleMoney(value: Money::of($mark['amount'], $currency), currency: $currency);
            } catch (Throwable) {
                $amount = null;
            }

            $reports[] = [
                'reportId' => self::REPORT_RESERVATION_PREFIX . $row['id'],
                'id' => $mark['provider'] !== '' ? $mark['provider'] : null,
                'amount' => $amount,
                'providerAmount' => mb_substr($mark['amount'], 0, 64),
                'providerCurrency' => mb_substr($mark['currency'] !== '' ? $mark['currency'] : $currency->getCode(), 0, 16),
                'reportedOn' => mb_substr($mark['at'], 0, 16),
                'status' => $mark['held'] ? self::REPORT_STATUS_PENDING : self::REPORT_STATUS_SETTLED,
                // Every word the provider sent about this refund, latest first (since 2.1.0).
                'words' => $mark['words'],
            ];
        }

        return $reports;
    }

    /**
     * What is wrong with one entry of a gateway's answer, or null when it is the agreed shape.
     *
     * Besides the keys and their types, every fact must fit where the core keeps it:
     * the references and the provider's figures are valid UTF-8, and the figures are
     * no longer than their columns (providerAmount and reportedOn 64 characters,
     * providerCurrency 16). A fact that could not be written would make every
     * outcome of the report fail and keep the order blocked for good; as a fault it
     * holds the block and says what is wrong.
     *
     * @param mixed $entry The entry.
     * @param Currency $currency The order's currency.
     *
     * @return string|null "report <reference or ?>: <each fault>", plain text.
     *
     * @since  2.1.0
     */
    private static function reportProblem(mixed $entry, Currency $currency): ?string
    {
        if (!\is_array($entry)) {
            return 'report ?: not a list of facts';
        }

        $wrong = [];
        $reportId = \is_string($entry['reportId'] ?? null) && $entry['reportId'] !== '' ? $entry['reportId'] : '';

        if ($reportId === '') {
            $wrong[] = 'no reportId';
        } elseif (!mb_check_encoding($reportId, 'UTF-8')) {
            $wrong[] = 'reportId is not valid UTF-8';
        } elseif (str_starts_with($reportId, self::REPORT_RESERVATION_PREFIX)) {
            $wrong[] = 'a reportId starting reservation: names the core\'s own report of a reservation; a gateway never lists one';
        } elseif (str_starts_with($reportId, self::REPORT_REQUEST_PREFIX)) {
            // Rule 4 is retired (2.1.0): a refund request this site sends is a
            // reservation in the books, made by the core before the provider is asked.
            $wrong[] = 'a refund request is not a provider report: the core holds it as a reservation (reserveRefund()), so a gateway lists provider reports only';
        }

        if (!\array_key_exists('id', $entry)) {
            $wrong[] = 'no id';
        } elseif (!($entry['id'] === null || (\is_string($entry['id']) && $entry['id'] !== ''))) {
            $wrong[] = 'id is neither null nor a provider refund id';
        } elseif ($entry['id'] !== null && !mb_check_encoding($entry['id'], 'UTF-8')) {
            $wrong[] = 'id is not valid UTF-8';
        }

        if (!\array_key_exists('amount', $entry)) {
            $wrong[] = 'no amount';
        } elseif ($entry['amount'] !== null && !$entry['amount'] instanceof Money) {
            $wrong[] = 'amount is neither null nor Money';
        } elseif ($entry['amount'] instanceof Money && !$entry['amount']->getCurrency()->equals($currency)) {
            $wrong[] = sprintf('amount is in %s, not in the order\'s %s', $entry['amount']->getCurrency()->getCode(), $currency->getCode());
        }

        // The longest each figure may be: the width of its column in the outcome table.
        foreach (['providerAmount' => 64, 'providerCurrency' => 16, 'reportedOn' => 64] as $key => $longest) {
            if (!\is_string($entry[$key] ?? null)) {
                $wrong[] = 'no ' . $key;
            } elseif (!mb_check_encoding($entry[$key], 'UTF-8')) {
                $wrong[] = $key . ' is not valid UTF-8';
            } elseif (mb_strlen($entry[$key], 'UTF-8') > $longest) {
                $wrong[] = sprintf('%s is longer than %d characters', $key, $longest);
            }
        }

        if (!\array_key_exists('status', $entry)) {
            $wrong[] = 'no status';
        } elseif (!\in_array($entry['status'], [self::REPORT_STATUS_SETTLED, self::REPORT_STATUS_PENDING], true)) {
            $wrong[] = sprintf("status is neither '%s' nor '%s'", self::REPORT_STATUS_SETTLED, self::REPORT_STATUS_PENDING);
        }

        // A reference that is not valid UTF-8 is named with its bad bytes replaced, so the notice can print it.
        return $wrong === [] ? null : 'report ' . ($reportId !== '' ? mb_scrub($reportId, 'UTF-8') : '?') . ': ' . implode(', ', $wrong);
    }

    /**
     * The references of the reports of one payment whose outcome is kept.
     *
     * @param string $element The reporting gateway's element.
     * @param int $paymentId The payment.
     *
     * @return list<string>
     *
     * @since  2.1.0
     */
    private static function settledReports(string $element, int $paymentId): array
    {
        $db = self::db();

        return array_map('strval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('report_id'))
                ->from($db->quoteName(self::REPORT_OUTCOMES))
                ->where($db->quoteName('plugin_folder') . ' = ' . $db->quote('alfa-payments'))
                ->where($db->quoteName('plugin_element') . ' = ' . $db->quote($element))
                ->where($db->quoteName('id_payment') . ' = ' . $paymentId),
        )->loadColumn() ?: []);
    }

    /**
     * The kept outcome of one report, or null.
     *
     * @param string $element The reporting gateway's element.
     * @param int $paymentId The payment.
     * @param string $reportId The report's reference, compared byte for byte.
     *
     * @return object|null The outcome row.
     *
     * @since  2.1.0
     */
    private static function keptOutcome(string $element, int $paymentId, string $reportId): ?object
    {
        $db = self::db();
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName(self::REPORT_OUTCOMES))
                ->where($db->quoteName('plugin_folder') . ' = ' . $db->quote('alfa-payments'))
                ->where($db->quoteName('plugin_element') . ' = ' . $db->quote($element))
                ->where($db->quoteName('id_payment') . ' = ' . $paymentId)
                ->where($db->quoteName('report_id_hash') . ' = ' . $db->quote(hash('sha256', $reportId))),
        )->loadObjectList() ?: [];

        foreach ($rows as $row) {
            if ((string) $row->report_id === $reportId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether a kept report outcome links this refund row (recorded or reconciled).
     *
     * @param int $rowId The refund row.
     *
     * @since  2.1.0
     */
    private static function linkedToReport(int $rowId): bool
    {
        $db = self::db();

        return $rowId > 0 && (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName(self::REPORT_OUTCOMES))
                ->where($db->quoteName('id_refund_row') . ' = ' . $rowId),
        )->loadResult() > 0;
    }

    /**
     * How a report is named to the operator: the provider's refund id, or the
     * report's own reference when the provider gave none.
     *
     * @param ?string $providerId The provider's refund id.
     * @param string $reportId The report's reference.
     *
     * @since  2.1.0
     */
    private static function reportReference(?string $providerId, string $reportId): string
    {
        return $providerId ?? Text::sprintf('COM_ALFA_REPORT_NO_ID', $reportId);
    }

    /**
     * The refunds of an order a report may be reconciled to: settled refund rows
     * (refunded or completed) that no kept report outcome links yet, oldest first.
     *
     * @param int $orderId The order.
     *
     * @return list<object> id, amount (the stored decimal), transaction_id, id_refunded_payment, added.
     *
     * @since  2.1.0
     */
    public static function reconcilableRefunds(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $db = self::db();

        return $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['r.id', 'r.amount', 'r.transaction_id', 'r.id_refunded_payment', 'r.added']))
                ->from($db->quoteName(self::TABLE, 'r'))
                ->join('LEFT', $db->quoteName(self::REPORT_OUTCOMES, 'o') . ' ON ' . $db->quoteName('o.id_refund_row') . ' = ' . $db->quoteName('r.id'))
                ->where($db->quoteName('r.id_order') . ' = ' . $orderId)
                ->where($db->quoteName('r.payment_type') . ' = ' . $db->quote(self::TYPE_REFUND))
                ->where($db->quoteName('r.transaction_status') . ' IN (' . $db->quote(self::STATUS_REFUNDED) . ', ' . $db->quote(self::STATUS_COMPLETED) . ')')
                ->where($db->quoteName('o.id') . ' IS NULL')
                ->order($db->quoteName('r.id') . ' ASC'),
        )->loadObjectList() ?: [];
    }

    /**
     * One report of one payment as its gateway lists it now, unsettled, or why
     * there is none: the form of a report action shows the provider's facts from it.
     *
     * Read only.
     *
     * @param int $paymentId The payment.
     * @param string $reportId The report's reference.
     *
     * Answers the entry, or the reason there is none (already settled, not
     * reported, unreadable), translated, plain text; settled when its outcome is
     * already in the books.
     *
     * @return array{entry: array|null, reason: string, settled: bool}
     *
     * @since  2.1.0
     */
    public static function reportedRefund(int $paymentId, string $reportId): array
    {
        $payment = $paymentId > 0 ? self::getRaw(id: $paymentId) : null;
        $found = self::findReport(payment: $payment, paymentId: $paymentId, reportId: $reportId);

        return ['entry' => $found['entry'], 'reason' => $found['result']['reason'] ?? '', 'settled' => ($found['result']['outcome'] ?? '') === self::OUTCOME_DUPLICATE];
    }

    /**
     * Find one unsettled report of a payment, or the answer that says why not.
     *
     * @param object|null $payment The payment row.
     * @param int $paymentId Its id, as asked.
     * @param string $reportId The report's reference.
     *
     * @return array{entry: array|null, result: array|null} The entry, or the verdict to answer with.
     *
     * @since  2.1.0
     */
    private static function findReport(?object $payment, int $paymentId, string $reportId): array
    {
        if ($payment === null || (string) ($payment->payment_type ?? '') !== self::TYPE_PAYMENT) {
            return ['entry' => null, 'result' => self::refundResult(outcome: self::OUTCOME_REFUSED, reason: Text::sprintf('COM_ALFA_RECORD_REFUND_NOT_PAYMENT', $paymentId))];
        }

        if ($reportId === '') {
            return ['entry' => null, 'result' => self::refundResult(outcome: self::OUTCOME_REFUSED, reason: Text::_('COM_ALFA_REPORT_NONE'))];
        }

        $element = (string) ($payment->payment_method_type ?? '');
        $outcome = self::keptOutcome(element: $element, paymentId: $paymentId, reportId: $reportId);

        if ($outcome !== null) {
            $row = (int) ($outcome->id_refund_row ?? 0);
            $what = match ((string) $outcome->outcome) {
                self::REPORT_RECORDED => Text::sprintf('COM_ALFA_REPORT_OUTCOME_RECORDED', $row),
                self::REPORT_RECONCILED => Text::sprintf('COM_ALFA_REPORT_OUTCOME_RECONCILED', $row),
                default => Text::_('COM_ALFA_REPORT_OUTCOME_DISMISSED'),
            };
            $reference = self::reportReference(providerId: $outcome->provider_refund_id === null ? null : (string) $outcome->provider_refund_id, reportId: $reportId);

            return ['entry' => null, 'result' => self::refundResult(outcome: self::OUTCOME_DUPLICATE, refundId: $row, reason: Text::sprintf('COM_ALFA_REPORT_ALREADY', $reference, $paymentId, $what)) + ['reference' => $reference]];
        }

        $answer = self::askGateway(payment: $payment);

        if (isset($answer['booked'][$reportId])) {
            $booked = $answer['booked'][$reportId];
            $reference = self::reportReference(providerId: $booked['entry']['id'], reportId: $reportId);

            return ['entry' => null, 'result' => self::refundResult(outcome: self::OUTCOME_DUPLICATE, refundId: $booked['row'], reason: Text::sprintf('COM_ALFA_REPORT_ALREADY', $reference, $paymentId, Text::sprintf('COM_ALFA_REPORT_OUTCOME_BOOKED', $booked['row']))) + ['reference' => $reference]];
        }

        foreach (array_merge($answer['refunds'], $answer['reserved']) as $entry) {
            if ($entry['reportId'] === $reportId) {
                return ['entry' => $entry, 'result' => null];
            }
        }

        if ($answer['unread'] !== null) {
            return ['entry' => null, 'result' => self::refundResult(outcome: self::OUTCOME_REFUSED, reason: Text::sprintf('COM_ALFA_RECORD_REFUND_UNREAD', $paymentId, $answer['unread']))];
        }

        return ['entry' => null, 'result' => self::refundResult(outcome: self::OUTCOME_REFUSED, reason: Text::sprintf('COM_ALFA_REPORT_NOT_REPORTED', $reportId, $paymentId))];
    }

    /**
     * Settle one report of one payment exactly once: the shared frame of the four verbs.
     *
     * Under the order's lock, inside one transaction: the report is found again
     * (a kept outcome or a booking under its provider id answers OUTCOME_DUPLICATE,
     * "already"), $settle decides and writes, and the outcome it returns is kept
     * with the report's facts, the user and the time. Two requests for the same
     * report queue on the lock, so the second finds the first one's outcome.
     *
     * @param int $paymentId The payment.
     * @param string $reportId The report's reference.
     * @param Closure $settle fn(array $entry, int $orderId, Currency $currency): array{0: array, 1: array|null}
     *                        the verdict, and the outcome to keep (outcome, id_refund_row,
     *                        amount_typed, booked_amount, reason) or null to keep none.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float, reference: string}
     *
     * @since  2.1.0
     */
    private static function settleReport(int $paymentId, string $reportId, Closure $settle): array
    {
        $payment = $paymentId > 0 ? self::getRaw(id: $paymentId) : null;
        $orderId = (int) ($payment->id_order ?? 0);
        $db = self::db();
        $db->transactionStart(true);

        // The ledger's one transaction rule: a refusal inside the try closes its
        // level and returns at once; the commit comes after the try.
        try {
            if ($orderId > 0 && !self::lockOrders($db, $orderId)) {
                TransactionState::rollBackLevel($db);

                return self::refundResult(outcome: self::OUTCOME_RACED, reason: sprintf('order #%d could not be locked; nothing was written', $orderId)) + ['reference' => $reportId];
            }

            $found = self::findReport(payment: $payment, paymentId: $paymentId, reportId: $reportId);

            // Already settled, not reported, or unreadable: nothing was written.
            if ($found['entry'] === null) {
                TransactionState::rollBackLevel($db);

                return $found['result'] + ['reference' => $found['result']['reference'] ?? $reportId];
            }

            $entry = $found['entry'];
            $reference = self::reportReference(providerId: $entry['id'], reportId: $reportId);
            $currency = OrderCurrency::of(orderId: $orderId);
            [$result, $keep] = $settle($entry, $orderId, $currency);

            if ($keep !== null) {
                $identity = Factory::getApplication()->getIdentity();
                $outcome = (object) [
                    'plugin_folder' => 'alfa-payments',
                    'plugin_element' => (string) $payment->payment_method_type,
                    'id_payment' => $paymentId,
                    'id_order' => $orderId,
                    'report_id' => $reportId,
                    'outcome' => $keep['outcome'],
                    'id_refund_row' => $keep['id_refund_row'] ?? null,
                    'amount_typed' => (int) ($keep['amount_typed'] ?? 0),
                    'booked_amount' => $keep['booked_amount'] ?? null,
                    'booked_currency' => isset($keep['booked_amount']) ? $currency->getCode() : null,
                    'provider_refund_id' => $entry['id'],
                    'provider_amount' => $entry['providerAmount'],
                    'provider_currency' => $entry['providerCurrency'],
                    'reported_on' => $entry['reportedOn'],
                    'reason' => (string) $keep['reason'],
                    'id_user' => $identity === null ? 0 : (int) $identity->id,
                    'created' => Factory::getDate()->toSql(),
                ];
                $db->insertObject(self::REPORT_OUTCOMES, $outcome);

                // Resolved: its notification goes to the history.
                NotificationHelper::clear(self::unbookedNoticeKey(paymentId: $paymentId, reportId: $reportId));
            }
        } catch (Throwable $e) {
            TransactionState::rollBackLevel($db, $e);

            throw $e;
        }

        // What the verb decided stands, booked or refused: a refusal by refundPayment()
        // may still have written (money the provider moved is never rolled back).
        TransactionState::commitLevel($db);

        return $result + ['reference' => $reference];
    }

    /**
     * The decimal a refund row stores, as text.
     *
     * @param int $rowId The row.
     *
     * @since  2.1.0
     */
    private static function storedAmount(int $rowId): ?string
    {
        $row = $rowId > 0 ? self::getRaw($rowId) : null;

        return $row === null ? null : (string) $row->amount;
    }

    /**
     * The provider's earlier words on a core report that Record this refund books (its latest settled word): each
     * other word core kept on the row, latest first, as the audit names them; '' when the report carries one word
     * (since 2.1.0).
     *
     * @param array $entry The report ('words', 'id', 'providerAmount').
     *
     * @return string Translated, plain text, starting with a space; or ''.
     *
     * @since  2.1.0
     */
    private static function earlierWords(array $entry): string
    {
        $booked = false;
        $earlier = [];

        foreach ((array) ($entry['words'] ?? []) as $word) {
            if (!$booked && $word['kind'] === self::REPORT_STATUS_SETTLED && $word['amount'] === (string) $entry['providerAmount']
                && ($word['id'] === '' || $word['id'] === (string) ($entry['id'] ?? ''))) {
                $booked = true;

                continue;
            }

            $earlier[] = Text::sprintf(
                match ($word['kind']) {
                    self::REPORT_STATUS_PENDING => 'COM_ALFA_REFUND_WORD_HELD',
                    self::STATUS_FAILED, self::STATUS_CANCELLED => 'COM_ALFA_REFUND_WORD_FAILED',
                    default => 'COM_ALFA_REFUND_WORD_SETTLED',
                },
                $word['id'] !== '' ? $word['id'] : Text::_('COM_ALFA_REFUND_WORD_NO_ID'),
                $word['amount'],
                $word['currency'],
                $word['at'],
            );
        }

        return $earlier === [] ? '' : ' ' . Text::sprintf('COM_ALFA_RECORD_REFUND_EARLIER', implode('; ', $earlier));
    }

    /**
     * Record a refund the payment provider made outside Alfa, exactly as its
     * gateway reports it: "Record this refund", the way out of the refund block.
     *
     * The operator names the report and nothing else. The amount is the
     * gateway's own figure in the order's currency, read again now; the refund
     * is written by {@see self::refundPayment()} linked to this payment, with
     * gateway_moved (the money already left at the provider), under the
     * provider's refund id when there is one, and with no provider id when the
     * provider gave none (the report's reference keeps it once: never an
     * invented provider id). The provider is never called. A report the
     * provider has not settled yet is not recorded: it is never booked as a
     * settled refund. Until the provider settles it (then it is recorded here)
     * or reports that it failed or was cancelled (then the gateway lists it no
     * more), it reserves its amount in the order's refundable pool, or holds
     * the refund block when that amount is not exact in the order's currency
     * ({@see self::refundableNow()}). A report with no exact amount in the
     * order's currency is not recorded here: an authorised user records it with
     * {@see self::recordReportedRefundTyped()}, or resolves it. The outcome is
     * kept: a second click, a redelivered report or Reconcile finds it and
     * nothing is written again.
     *
     * Answers OUTCOME_OK with the refund row written; OUTCOME_DUPLICATE with the
     * refund row that already records it; OUTCOME_REFUSED when the gateway does
     * not report it, its answer cannot be read, the provider has not settled it,
     * or it has no exact amount; otherwise what refundPayment() answered. The reason is translated, plain
     * text; 'reference' names the report to the operator.
     *
     * @param int $paymentId The payment the provider refunded.
     * @param string $reportId The report's reference, exactly as the gateway lists it.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float, reference: string}
     *
     * @since  2.1.0
     */
    public static function recordReportedRefund(int $paymentId, string $reportId): array
    {
        return self::settleReport(paymentId: $paymentId, reportId: $reportId, settle: static function (array $entry, int $orderId, Currency $currency) use ($paymentId): array {
            $pending = self::pendingRefusal(entry: $entry, currency: $currency);

            if ($pending !== null) {
                return [$pending, null];
            }

            if ($entry['amount'] === null) {
                return [self::refundResult(
                    outcome: self::OUTCOME_REFUSED,
                    reason: Text::sprintf('COM_ALFA_RECORD_REFUND_AMOUNT_UNKNOWN', self::reportReference(providerId: $entry['id'], reportId: $entry['reportId']), $entry['providerAmount'], $entry['providerCurrency'], $currency->getCode()),
                ), null];
            }

            $reason = mb_substr(Text::sprintf('COM_ALFA_RECORD_REFUND_REASON', $entry['reportedOn']), 0, 255);
            $options = ['gateway_moved' => true, 'reason' => $reason];

            if ($entry['id'] !== null) {
                $options['transaction_id'] = $entry['id'];
            }

            // The core's own report of an unreconciled reservation: the reservation itself is the booking. Staff's
            // own decision: a provider refund they resolved before, or one that waits for them, is booked here.
            $reservation = self::reservationOfReport(reportId: $entry['reportId']);
            $result = self::staffDecides(static fn (): array => $reservation > 0
                ? self::settleRefund(reservationId: $reservation, amount: $entry['amount'], providerRefundId: $entry['id'], options: ['final' => true, 'reason' => $reason])
                : self::refundPayment(paymentId: $paymentId, amount: $entry['amount'], options: $options));

            if ($result['outcome'] !== self::OUTCOME_OK) {
                return [$result, null];
            }

            return [$result, [
                'outcome' => self::REPORT_RECORDED,
                'id_refund_row' => (int) $result['refund_id'],
                'booked_amount' => self::storedAmount(rowId: (int) $result['refund_id']),
                'reason' => $reason . self::earlierWords(entry: $entry),
            ]];
        });
    }

    /**
     * Record a reported refund for which no exact amount in the order's currency
     * can be derived (another currency, a figure the order's currency cannot
     * carry): an authorised user names the amount to book, and why.
     *
     * Allowed only for a report whose amount is null and that the provider has
     * settled; the provider's figure, currency and reference are kept unchanged
     * beside the booked one. Booked
     * through {@see self::refundPayment()} as every refund, with gateway_moved,
     * under the provider's refund id when there is one. The caller checks the
     * operator's right (Orders: Resolve provider refund reports).
     *
     * @param int $paymentId The payment the provider refunded.
     * @param string $reportId The report's reference.
     * @param Money $amount The amount to book, in the order's currency.
     * @param string $reason Why, as the operator states it; required, plain text of at most REPORT_REASON_MAX characters.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float, reference: string}
     *
     * @since  2.1.0
     */
    public static function recordReportedRefundTyped(int $paymentId, string $reportId, Money $amount, string $reason): array
    {
        $reason = trim($reason);
        $refusal = self::reasonRefusal(reason: $reason);

        if ($refusal !== null) {
            return self::refundResult(outcome: self::OUTCOME_REFUSED, reason: $refusal) + ['reference' => $reportId];
        }

        return self::settleReport(paymentId: $paymentId, reportId: $reportId, settle: static function (array $entry, int $orderId, Currency $currency) use ($paymentId, $amount, $reason): array {
            $pending = self::pendingRefusal(entry: $entry, currency: $currency);

            if ($pending !== null) {
                return [$pending, null];
            }

            if ($entry['amount'] !== null) {
                return [self::refundResult(outcome: self::OUTCOME_REFUSED, reason: Text::sprintf('COM_ALFA_RECORD_REFUND_AMOUNT_EXACT', self::reportReference(providerId: $entry['id'], reportId: $entry['reportId']))), null];
            }

            $options = [
                'gateway_moved' => true,
                'reason' => mb_substr(Text::sprintf('COM_ALFA_RECORD_REFUND_REASON_TYPED', $entry['providerAmount'], $entry['providerCurrency'], $reason), 0, 255),
            ];

            if ($entry['id'] !== null) {
                $options['transaction_id'] = $entry['id'];
            }

            $result = self::staffDecides(static fn (): array => self::refundPayment(paymentId: $paymentId, amount: $amount, options: $options));

            if ($result['outcome'] !== self::OUTCOME_OK) {
                return [$result, null];
            }

            return [$result, [
                'outcome' => self::REPORT_RECORDED,
                'id_refund_row' => (int) $result['refund_id'],
                'amount_typed' => 1,
                'booked_amount' => self::storedAmount(rowId: (int) $result['refund_id']),
                'reason' => $reason,
            ]];
        });
    }

    /**
     * Resolve a report as the refund already in this order's books: no refund
     * is booked; the report is linked to that refund row, with a reason.
     *
     * The row must be a settled refund (refunded or completed) of the same order
     * that no other report is linked to. The caller checks the operator's right.
     * Answers OUTCOME_RECORDED when the resolution is kept.
     *
     * @param int $paymentId The payment the report is about.
     * @param string $reportId The report's reference.
     * @param int $refundId The refund row the report corresponds to.
     * @param string $reason Why; required, plain text of at most REPORT_REASON_MAX characters.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float, reference: string}
     *
     * @since  2.1.0
     */
    public static function reconcileReportedRefund(int $paymentId, string $reportId, int $refundId, string $reason): array
    {
        $reason = trim($reason);
        $refusal = self::reasonRefusal(reason: $reason);

        if ($refusal !== null) {
            return self::refundResult(outcome: self::OUTCOME_REFUSED, reason: $refusal) + ['reference' => $reportId];
        }

        return self::settleReport(paymentId: $paymentId, reportId: $reportId, settle: static function (array $entry, int $orderId) use ($paymentId, $refundId, $reason): array {
            $row = $refundId > 0 ? self::getRaw(id: $refundId) : null;
            $candidate = $row !== null
                && (int) $row->id_order === $orderId
                && (string) $row->payment_type === self::TYPE_REFUND
                && \in_array((string) $row->transaction_status, [self::STATUS_REFUNDED, self::STATUS_COMPLETED], true)
                && !self::linkedToReport(rowId: $refundId);

            if (!$candidate) {
                return [self::refundResult(outcome: self::OUTCOME_REFUSED, reason: Text::sprintf('COM_ALFA_REPORT_REFUND_NOT_CANDIDATE', $refundId)), null];
            }

            $released = self::releaseReportedReservation(entry: $entry, orderId: $orderId, note: 'Reconciled to refund #' . $refundId . ': ' . $reason, paymentId: $paymentId);

            if ($released !== null) {
                return [$released, null];
            }

            return [self::refundResult(outcome: self::OUTCOME_RECORDED, refundId: $refundId, amount: (float) $row->amount), [
                'outcome' => self::REPORT_RECONCILED,
                'id_refund_row' => $refundId,
                'booked_amount' => (string) $row->amount,
                'reason' => $reason,
            ]];
        });
    }

    /**
     * Resolve a report as a duplicate or invalid report: it represents no
     * additional unbooked money. No refund is booked; the reason is kept. The
     * caller checks the operator's right. Answers OUTCOME_RECORDED when the
     * resolution is kept.
     *
     * @param int $paymentId The payment the report is about.
     * @param string $reportId The report's reference.
     * @param string $reason Why; required, plain text of at most REPORT_REASON_MAX characters.
     *
     * @return array{outcome: string, refund_id: int, reason: string, amount: float, reference: string}
     *
     * @since  2.1.0
     */
    public static function dismissReportedRefund(int $paymentId, string $reportId, string $reason): array
    {
        $reason = trim($reason);
        $refusal = self::reasonRefusal(reason: $reason);

        if ($refusal !== null) {
            return self::refundResult(outcome: self::OUTCOME_REFUSED, reason: $refusal) + ['reference' => $reportId];
        }

        return self::settleReport(paymentId: $paymentId, reportId: $reportId, settle: static function (array $entry, int $orderId) use ($paymentId, $reason): array {
            $released = self::releaseReportedReservation(entry: $entry, orderId: $orderId, note: 'Resolved as a duplicate or invalid report: ' . $reason, paymentId: $paymentId);

            if ($released !== null) {
                return [$released, null];
            }

            return [self::refundResult(outcome: self::OUTCOME_RECORDED), [
                'outcome' => self::REPORT_DISMISSED,
                'reason' => $reason,
            ]];
        });
    }

    /**
     * The reservation row the core's own report names (REPORT_RESERVATION_PREFIX and its id), or 0.
     *
     * @param string $reportId The report's reference.
     *
     * @since  2.1.0
     */
    private static function reservationOfReport(string $reportId): int
    {
        if (!str_starts_with($reportId, self::REPORT_RESERVATION_PREFIX)) {
            return 0;
        }

        $id = substr($reportId, \strlen(self::REPORT_RESERVATION_PREFIX));

        return ctype_digit($id) ? (int) $id : 0;
    }

    /**
     * When a report the core made for an unreconciled reservation is reconciled to an existing refund or
     * resolved as a duplicate or invalid report, the reservation goes with it in the same transaction
     * (cancelled; the resolution in its note): the money it stood for is accounted for by that resolution. The
     * same for a report the gateway listed under a provider id that a reservation of the payment carries (held
     * under it, listed because its amount is not exact in the order's currency): that reservation ends as the
     * resolution decided, never reserving beside it (since 2.1.0). A row the core marked keeps its own report.
     *
     * @param array $entry The report.
     * @param int $orderId Its order, locked by the caller.
     * @param string $note What resolved it.
     * @param int $paymentId The payment the report is about.
     *
     * @return array|null The refusal when the reservation could not be released (nothing is kept); null otherwise.
     *
     * @since  2.1.0
     */
    private static function releaseReportedReservation(array $entry, int $orderId, string $note, int $paymentId = 0): ?array
    {
        $id = self::reservationOfReport(reportId: $entry['reportId']);

        if ($id <= 0 && $paymentId > 0 && $entry['id'] !== null) {
            $carrier = self::refundUnderReference($paymentId, (string) $entry['id']);
            $id = $carrier !== null
                && \in_array((string) $carrier->transaction_status, self::REFUND_RESERVING_STATUSES, true)
                && !isset(self::unreconciledMarks(orderId: $orderId)[(int) $carrier->id])
                ? (int) $carrier->id
                : 0;
        }

        $row = $id > 0 ? self::getRaw(id: $id) : null;

        if ($row === null) {
            return null;
        }

        $verdict = self::updateIfStatus($id, ['id_order' => $orderId, 'transaction_status' => self::STATUS_CANCELLED, 'note' => mb_substr($note, 0, 255)], (string) $row->transaction_status);

        return $verdict === self::WRITE_OK ? null : self::refundResult(outcome: self::OUTCOME_FAILED, reason: sprintf('reservation #%d could not be released; nothing was kept', $id));
    }

    /**
     * THE STAFF GUARD: why a staff write may not make this change of a payment or refund row, or null. One guard at
     * the write door, asked under the order's lock by update() and updateIfStatus() for a write a staff entry point
     * marked ($staff; the builder's ->asStaff()): the payment form's save (OrderModel::saveOrderPayment(), which the
     * REST payments verb also writes through) and the REST settle verb (OrderopsController::settlePayment(): a refund
     * row through update(), a payment row through the compare-and-set). The payment form asks it first as well, to
     * tell the operator before anything is written. Its clauses, each for a gateway payment (its recorded
     * payment_method_type is a gateway's):
     *   S        its refund that is pending or authorized keeps its amount and its reference, and a refund request a
     *            gateway sent or a word kept for staff is not changed by hand ({@see self::staffRefundEditRefusal()});
     *   R4-6     staff never move its refund into a settled status, from any status, failed or cancelled included
     *            (the same function);
     *   rule 1   it keeps its method once its gateway collected it ({@see self::gatewayMethodRefusal()});
     *   R6-2     it keeps its provider transaction id once it has one ({@see self::providerIdRefusal()}).
     * A write no staff entry point marked (the core's own writes; a payment plugin's builder or compare-and-set,
     * inside a staff user's request or not: ->failed(), ->cancelled(), a capture, a settlement, its amount, payload or
     * bookkeeping) is never refused by it (since 2.1.0).
     *
     * @param object $stored The row as read (id, id_order, payment_type, transaction_status, amount, transaction_id,
     *                       id_refunded_payment, id_payment_method, payment_method_type).
     * @param array $data The write.
     *
     * @return string|null The refusal, translated; null when the write may go on.
     *
     * @since  2.1.0
     */
    public static function staffWriteRefusal(object $stored, array $data): ?string
    {
        return self::staffRefundEditRefusal(stored: $stored, data: $data)
            ?? self::gatewayMethodRefusal(stored: $stored, data: $data)
            ?? self::providerIdRefusal(stored: $stored, data: $data);
    }

    /**
     * Why staff may not write this change of a refund reservation by hand, or null. A reservation whose
     * provider settled it while the books refused the booking: the money moved, so it is not set failed or
     * cancelled and its amount is not changed; its report goes through the way out (Record this refund,
     * reconcile to an existing refund, duplicate or invalid report). A refund request a payment gateway sent
     * (still under its 'request:' key) is moved by its gateway only (settleRefund(), holdRefund()): staff never
     * settle it, re-reference it or change its amount; they may fail or cancel it (the orphan release). A refund
     * of a gateway payment that is pending or authorized (since 2.1.0: whoever wrote it, a
     * reservation, a row split off it or held beside it, one kept for staff, or one typed in the payment form)
     * keeps its amount and its reference while the provider may still complete it, and only the provider's word
     * settles it (its plugin, or Record this refund), also once staff set it failed or cancelled (a revival is not a
     * settle either): staff set it failed or cancelled and record a new refund
     * ({@see self::refundIdentityFixed()}). Clauses S and R4-6 of the staff guard ({@see self::staffWriteRefusal()}):
     * asked for the write of a staff entry point only, never for a payment plugin's write or the ledger's provider
     * verbs (settleRefund(), holdRefund(): the provider's figure and id win).
     *
     * @param object $stored The row as read (id, id_order, payment_type, transaction_status, amount).
     * @param array $data The write.
     *
     * @return string|null The refusal, translated; null when the write may go on.
     *
     * @since  2.1.0
     */
    private static function staffRefundEditRefusal(object $stored, array $data): ?string
    {
        $id = (int) ($stored->id ?? 0);
        $orderId = (int) ($stored->id_order ?? 0);

        if ($id <= 0 || $orderId <= 0 || (string) ($stored->payment_type ?? '') !== self::TYPE_REFUND) {
            return null;
        }

        // A failed or cancelled refund of a gateway payment brought back into the books by hand: only the provider's
        // word (or Record this refund on its report) settles a gateway payment's refund (since 2.1.0).
        if (\in_array((string) ($stored->transaction_status ?? ''), [self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
            return \in_array((string) ($data['transaction_status'] ?? ''), self::REFUND_SETTLED_STATUSES, true) && self::gatewayPaymentOf(refund: $stored)
                ? Text::sprintf('COM_ALFA_PAYMENT_REFUND_GATEWAY_SETTLES', $id)
                : null;
        }

        if (!\in_array((string) ($stored->transaction_status ?? ''), self::REFUND_RESERVING_STATUSES, true)) {
            return null;
        }

        $statusMoves = \array_key_exists('transaction_status', $data) && (string) $data['transaction_status'] !== (string) $stored->transaction_status;
        $reference = property_exists($stored, 'transaction_id')
            ? (string) ($stored->transaction_id ?? '')
            : (string) (self::getRaw(id: $id)->transaction_id ?? '');
        $referenceMoves = \array_key_exists('transaction_id', $data) && (string) ($data['transaction_id'] ?? '') !== $reference;

        try {
            $amountMoves = \array_key_exists('amount', $data)
                && self::money($data['amount'] ?? 0, $orderId)->getMinorUnits() !== self::money($stored->amount ?? 0, $orderId)->getMinorUnits();
        } catch (Throwable) {
            $amountMoves = true;
        }

        // A refund request a payment gateway sent (a reservation still under its key) is moved by its gateway
        // only: the payment form neither books money the books cannot match to the provider's own refund nor
        // re-references or re-amounts a request the provider may still be moving.
        if (str_starts_with($reference, self::REPORT_REQUEST_PREFIX)
            && ($amountMoves || $referenceMoves || ($statusMoves && \in_array((string) $data['transaction_status'], self::REFUND_SETTLED_STATUSES, true)))) {
            return Text::sprintf('COM_ALFA_PAYMENT_REFUND_REQUEST_FIXED', $id);
        }

        // A provider word kept for the way out. One the provider only HOLDS moved no money: staff may set it failed or
        // cancelled (its reservation is released); its amount stays the provider's.
        $mark = $statusMoves || $amountMoves ? (self::unreconciledMarks(orderId: $orderId)[$id] ?? null) : null;
        $ends = $statusMoves && \in_array((string) $data['transaction_status'], [self::STATUS_FAILED, self::STATUS_CANCELLED], true);

        if ($mark !== null && ($amountMoves || !($mark['held'] && $ends))) {
            return $mark['held']
                ? Text::sprintf('COM_ALFA_PAYMENT_REFUND_PENDING_FIXED', $id)
                : Text::sprintf('COM_ALFA_PAYMENT_REFUND_UNRECONCILED_FIXED', $id, self::REPORT_RESERVATION_PREFIX . $id);
        }

        // A refund of a gateway payment that is pending or authorized is settled by its provider's word only (its
        // payment plugin, or Record this refund on the provider's report): never by a status move here (since 2.1.0).
        $settles = $statusMoves && \in_array((string) $data['transaction_status'], self::REFUND_SETTLED_STATUSES, true);

        if (!($settles || $amountMoves || $referenceMoves) || !self::gatewayPaymentOf(refund: $stored)) {
            return null;
        }

        return $settles
            ? Text::sprintf('COM_ALFA_PAYMENT_REFUND_GATEWAY_SETTLES', $id)
            : Text::sprintf('COM_ALFA_PAYMENT_REFUND_PENDING_FIXED', $id);
    }

    /**
     * Whether staff may not change the amount or the reference of a refund row (the payment form shows both
     * read-only, {@see self::staffWriteRefusal()} refuses the change): it is pending or authorized, and it is
     * a refund of a gateway payment, or a refund request a gateway sent (its 'request:' key).
     *
     * @param int $refundId The refund row.
     *
     * @since  2.1.0
     */
    public static function refundIdentityFixed(int $refundId): bool
    {
        $row = $refundId > 0 ? self::getRaw(id: $refundId) : null;

        return $row !== null
            && (string) $row->payment_type === self::TYPE_REFUND
            && \in_array((string) $row->transaction_status, self::REFUND_RESERVING_STATUSES, true)
            && (str_starts_with((string) ($row->transaction_id ?? ''), self::REPORT_REQUEST_PREFIX) || self::gatewayPaymentOf(refund: $row));
    }

    /**
     * Whether a refund row returns money from a gateway payment: the payment it names was taken by a payment plugin
     * with a provider, as its payment_method_type (the one authority on which plugin took that money) says: an
     * element that is not empty (no plugin), not the customer's credit (NON_GATEWAY_TYPES) and not the bundled
     * Standard method (STANDARD_METHOD: no provider channel, the manual method).
     *
     * @param object $refund The refund row (id_refunded_payment).
     *
     * @since  2.1.0
     */
    private static function gatewayPaymentOf(object $refund): bool
    {
        $paymentId = (int) ($refund->id_refunded_payment ?? 0);
        $payment = $paymentId > 0 ? self::getRaw(id: $paymentId) : null;
        $element = (string) ($payment->payment_method_type ?? '');

        return $payment !== null
            && $element !== ''
            && $element !== self::STANDARD_METHOD
            && !\in_array($element, self::NON_GATEWAY_TYPES, true);
    }

    /**
     * Why a report the provider has not settled yet is not booked, or null when it is settled.
     *
     * A pending refund is the provider's claim, not money that went back: it is never
     * booked as a settled refund. It stays listed until the provider settles it or reports
     * that it failed or was cancelled; meanwhile it reserves its amount when that amount is
     * exact in the order's currency, and otherwise holds the refund block. The refusal says
     * which.
     *
     * @param array $entry The report as {@see self::askGateway()} lists it.
     * @param Currency $currency The order's currency.
     *
     * @return array|null The refusal, as refundResult() shapes it; null for a settled report.
     *
     * @since  2.1.0
     */
    private static function pendingRefusal(array $entry, Currency $currency): ?array
    {
        if ($entry['status'] !== self::REPORT_STATUS_PENDING) {
            return null;
        }

        return self::refundResult(
            outcome: self::OUTCOME_REFUSED,
            reason: Text::sprintf(
                self::reservedAmount(report: $entry, currency: $currency) === null ? 'COM_ALFA_RECORD_REFUND_PENDING_UNKNOWN' : 'COM_ALFA_RECORD_REFUND_PENDING',
                self::reportReference(providerId: $entry['id'], reportId: $entry['reportId']),
                $entry['reportedOn'],
            ),
        );
    }

    /**
     * Why the reason an operator gave to book or resolve a report is not taken, or null.
     *
     * Required, valid UTF-8 and at most REPORT_REASON_MAX characters: it is kept whole
     * with the report's outcome, so one the outcome table cannot store would fail the
     * booking or the resolution every time.
     *
     * @param string $reason The reason, trimmed.
     *
     * @return string|null The refusal, translated, plain text; null when it is taken.
     *
     * @since  2.1.0
     */
    private static function reasonRefusal(string $reason): ?string
    {
        if ($reason === '') {
            return Text::_('COM_ALFA_REPORT_REASON_MISSING');
        }

        if (!mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason, 'UTF-8') > self::REPORT_REASON_MAX) {
            return Text::sprintf('COM_ALFA_REPORT_REASON_TOO_LONG', self::REPORT_REASON_MAX);
        }

        return null;
    }

    /**
     * Why a refund written by staff may not enter the books now, or null.
     *
     * While the refund block holds ({@see self::refundStartRefusal()}), no write
     * by staff brings a refund into the books that was not counted before: a new
     * refund row, a payment turned into a refund, a failed or cancelled refund
     * brought back. A refund typed by hand is not linked to its payment's report,
     * so it would not end the report, and the same money could be booked twice:
     * each reported refund is recorded with Record this refund or resolved.
     * Asked by the order's payment form and the REST settle verb, to tell staff
     * before anything is written; the write itself is decided again under the
     * order's lock, whoever makes it: create() for a new row, update() and
     * updateIfStatus() for a refund brought back (since 2.1.0).
     *
     * @param object|null $stored The row as stored (payment_type, transaction_status); null for a new row.
     * @param array $data The write.
     * @param int $orderId The order.
     *
     * @return string|null The block's reason, translated; null when the write may proceed.
     *
     * @since  2.1.0
     */
    public static function staffRefundRefusal(?object $stored, array $data, int $orderId): ?string
    {
        if (!self::refundEnters(stored: $stored, data: $data)) {
            return null;
        }

        return self::refundStartRefusal(orderId: $orderId);
    }

    /**
     * Why a refund written by staff may not enter the books now because of what
     * the order's pending provider refunds reserve, or null.
     *
     * The same writes as {@see self::staffRefundRefusal()} (a refund entering the
     * books that was not counted before) are refund starts: one is refused when
     * its amount is beyond what the order may still refund once the pending
     * provider refunds are reserved ({@see self::refundableNow()}), or, for a
     * refund of a payment, beyond what that payment has left once its own pending
     * provider refunds are reserved ({@see self::refundableRemainder()}). Asked by
     * the order's payment form and the REST settle verb after the block; the write
     * is decided again under the order's lock by create() (a new row) or by
     * update() and updateIfStatus() (a refund brought back).
     *
     * @param object|null $stored The row as stored (payment_type, transaction_status, and amount when the write does not set one); null for a new row.
     * @param array $data The write.
     * @param int $orderId The order.
     *
     * @return string|null The refusal, translated, naming each reservation; null when the write may proceed.
     *
     * @since  2.1.0
     */
    public static function staffRefundReservedRefusal(?object $stored, array $data, int $orderId): ?string
    {
        if ($orderId <= 0) {
            return null;
        }

        // A refund entering the books, or a raise of one that reserves (pending or
        // authorized): decided at its new amount with its own reservation left out.
        try {
            $raised = $stored !== null && self::reservationRaised(stored: $stored, data: $data, orderId: $orderId, providerSettle: false);
        } catch (Throwable) {
            $raised = false;
        }

        if (!$raised && !self::refundEnters(stored: $stored, data: $data)) {
            return null;
        }

        $unbooked = self::reservationRead(orderId: $orderId, excludeRow: $raised ? (int) ($stored->id ?? 0) : 0);
        $reserved = self::reservedTotal(unbooked: $unbooked, orderId: $orderId);

        if (!$reserved->isPositive()) {
            return null;
        }

        // An amount that is not money of the order is refused by name where the row is written.
        try {
            $requested = self::money($data['amount'] ?? $stored->amount ?? 0, $orderId)->abs();
        } catch (Throwable) {
            return null;
        }

        $left = self::bookedRefundable(orderId: $orderId)->subtract($reserved)->nonNegative();

        if ($requested->greaterThan($left)) {
            return self::reservedRefusal(requested: $requested, orderId: $orderId, left: $left, reserved: $reserved, unbooked: $unbooked);
        }

        // And, for a refund of a payment, what that payment has left once its own
        // pending provider refunds are reserved.
        return self::paymentReservedRefusal(
            paymentId: (int) ($data['id_refunded_payment'] ?? $stored->id_refunded_payment ?? 0),
            requested: $requested,
            unbooked: $unbooked,
            orderId: $orderId,
        );
    }

    /**
     * Whether a write brings a refund into the books that was not counted before:
     * a new refund row, a payment turned into a refund, a failed or cancelled
     * refund brought back. Each is a refund start.
     *
     * @param object|null $stored The row as stored; null for a new row.
     * @param array $data The write.
     *
     * @since  2.1.0
     */
    private static function refundEnters(?object $stored, array $data): bool
    {
        $notCounted = [self::STATUS_FAILED, self::STATUS_CANCELLED];
        $countedBefore = $stored !== null
            && (string) ($stored->payment_type ?? '') === self::TYPE_REFUND
            && !\in_array((string) ($stored->transaction_status ?? ''), $notCounted, true);
        $type = (string) ($data['payment_type'] ?? $stored->payment_type ?? '');
        $status = (string) ($data['transaction_status'] ?? $stored->transaction_status ?? '');

        return !$countedBefore && $type === self::TYPE_REFUND && !\in_array($status, $notCounted, true);
    }

    /**
     * The refusal of the refund block, in the wording every payment plugin uses,
     * for what {@see self::unbookedProviderRefunds()} found.
     *
     * Each report that holds the block is listed as its provider id (or its
     * reference in brackets when the provider gave none), its exact amount (or
     * the provider's figure and currency as stated when there is none) and when
     * it was reported. A report that reserves its amount refuses nothing here.
     *
     * @param array{refunds: array<int, list<array{reportId: string, id: ?string, amount: ?Money, providerAmount: string, providerCurrency: string, reportedOn: string, status: string}>>, reserved: array<int, list<array>>, unread: array<int, string>} $unbooked What was found.
     *
     * @return string|null One sentence per payment, joined; null when there is nothing to refuse for.
     *
     * @since  2.1.0
     */
    private static function unbookedRefusal(array $unbooked): ?string
    {
        $reasons = [];

        foreach ($unbooked['refunds'] as $paymentId => $refunds) {
            $reasons[] = Text::sprintf(
                'COM_ALFA_REFUSE_UNBOOKED_PROVIDER_REFUND',
                $paymentId,
                implode('; ', array_map(
                    static fn (array $refund): string => ($refund['id'] ?? '[' . $refund['reportId'] . ']')
                        . ' (' . ($refund['amount']?->format() ?? $refund['providerAmount'] . ' ' . $refund['providerCurrency']) . ', ' . $refund['reportedOn'] . ')',
                    $refunds,
                )),
            );
        }

        foreach ($unbooked['unread'] as $paymentId => $why) {
            $reasons[] = Text::sprintf('COM_ALFA_REFUSE_UNBOOKED_PROVIDER_REFUND_UNREAD', $paymentId, $why);
        }

        return $reasons === [] ? null : implode('; ', $reasons);
    }

    /**
     * What an order has actually refunded: settled refund rows only.
     *
     * The read-side companion to {@see refundableNow}: that answers "how much
     * MAY still go back", this answers "how much HAS gone back". Anything
     * that records an intention to refund (a credit note's destination, an
     * RMA's decision) must report its status from this rather than from its
     * own stored flag: a flag can say "refunded" when no money moved.
     *
     * @param int $orderId Order PK.
     *
     * @return float Settled refund total in major units, never negative.
     *
     * @since  2.0.0
     */
    public static function settledRefundTotal(int $orderId): float
    {
        return self::money(self::sumSettledRefunds($orderId), $orderId)->nonNegative()->getAmount();
    }

    /**
     * What has actually been refunded AGAINST ONE CREDIT DOCUMENT.
     *
     * The order-wide {@see settledRefundTotal} answers a different question,
     * and using it to judge a single document is an accounting error: an
     * order carrying two credit notes of 50, only one of them refunded,
     * would report BOTH as settled. The refund row already holds the
     * attribution this needs: `id_document`, the credit document a refund
     * settles, so scope to that.
     *
     * A refund never stamped with a document counts towards no document,
     * which is the safe direction: it under-reports ("not paid yet") rather
     * than falsely reporting money as returned. A reversed refund counts only
     * for what still stands.
     *
     * @param int $documentId Credit document PK.
     *
     * @return float Settled refund total for that document, never negative.
     *
     * @since  2.0.0
     */
    public static function settledRefundForDocument(int $documentId): float
    {
        if ($documentId <= 0) {
            return 0.0;
        }

        // The database sums the stored amounts exactly; never below zero.
        return max(0.0, self::settledRefundsForDocuments([$documentId])[$documentId] ?? 0.0);
    }

    /**
     * The net settled refund of each credit document, in one query.
     *
     * Settled refund rows (refunded or completed) stamped with the document,
     * each net of its reversals. `id_credit_transaction IS NULL` is the
     * refund ledger's boundary: a row carrying one is a wallet spend and
     * belongs to the wallet's story (ReconciliationFacts).
     *
     * @param int[] $documentIds Credit document PKs.
     *
     * @return array<int, float> [documentId => net settled refund]; a document
     *                           with no refund row is absent.
     *
     * @since  2.0.0
     */
    public static function settledRefundsForDocuments(array $documentIds): array
    {
        return self::refundsByDocument(documentIds: $documentIds, inFlight: false, excludeId: 0);
    }

    /**
     * What refunds have taken from one credit document's own ceiling.
     *
     * Every refund row stamped with the document that is neither failed nor
     * cancelled, net of its reversals: a refund still pending holds its amount
     * as surely as a settled one, because once a provider confirms it the money
     * has left. The two ceilings of a note read it, the payments ledger's for a
     * refund and the customer credit ledger's for a pay-in, so a second refund
     * or a pay-in is refused before any money moves rather than when the first
     * refund settles. What a note has been PAID is
     * {@see self::settledRefundForDocument()}; this is what it can no longer give.
     *
     * @param int $documentId Credit document PK.
     * @param int $excludeId Refund row PK to leave out: the row being re-judged
     *                       (0 = none).
     *
     * @return float The net amount held, in major units, never negative.
     *
     * @since  2.0.0
     */
    public static function refundsHoldingDocument(int $documentId, int $excludeId = 0): float
    {
        if ($documentId <= 0) {
            return 0.0;
        }

        // The database sums the stored amounts exactly; never below zero.
        return max(0.0, self::refundsByDocument(documentIds: [$documentId], inFlight: true, excludeId: $excludeId)[$documentId] ?? 0.0);
    }

    /**
     * The net refund of each credit document, in one query: the one definition
     * behind {@see self::settledRefundsForDocuments()} and
     * {@see self::refundsHoldingDocument()}.
     *
     * Refund rows stamped with the document, each net of its reversals.
     * `id_credit_transaction IS NULL` is the refund ledger's boundary: a row
     * carrying one is a wallet spend and belongs to the wallet's story
     * (ReconciliationFacts).
     *
     * @param int[] $documentIds Credit document PKs.
     * @param bool $inFlight False: settled rows only (refunded or completed).
     *                       True: every row neither failed nor cancelled.
     * @param int $excludeId Refund row PK to leave out (0 = none).
     *
     * @return array<int, float> [documentId => net refund].
     *
     * @since  2.0.0
     */
    private static function refundsByDocument(array $documentIds, bool $inFlight, int $excludeId): array
    {
        $documentIds = array_values(array_unique(array_filter(array_map('intval', $documentIds), static fn (int $id): bool => $id > 0)));

        if ($documentIds === []) {
            return [];
        }

        $db = self::db();
        $query = $db->getQuery(true)
            ->select([$db->quoteName('r.id_document'), 'COALESCE(SUM(' . self::netAmountSql('r') . '), 0) AS ' . $db->quoteName('settled')])
            ->from($db->quoteName(self::TABLE, 'r'))
            ->where($db->quoteName('r.payment_type') . ' = ' . $db->quote(self::TYPE_REFUND))
            ->where($db->quoteName('r.id_credit_transaction') . ' IS NULL')
            ->whereIn($db->quoteName('r.id_document'), $documentIds)
            ->group($db->quoteName('r.id_document'));

        if ($inFlight) {
            $query->whereNotIn(
                $db->quoteName('r.transaction_status'),
                [self::STATUS_FAILED, self::STATUS_CANCELLED],
                ParameterType::STRING,
            );
        } else {
            $query->whereIn(
                $db->quoteName('r.transaction_status'),
                [self::STATUS_REFUNDED, self::STATUS_COMPLETED],
                ParameterType::STRING,
            );
        }

        if ($excludeId > 0) {
            $query->where($db->quoteName('r.' . self::PK) . ' != ' . $excludeId);
        }

        $rows = $db->setQuery($query)->loadObjectList() ?: [];

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->id_document] = (float) $row->settled;
        }

        return $out;
    }

    /**
     * Whether a refund that still stands settles this credit document.
     *
     * The one definition of Void & Replace's refund test: a refund row stamped
     * with the document, in any status but failed or cancelled, whose amount
     * is more than its recorded reversals. A refund the provider reversed in
     * full no longer holds the note.
     *
     * @param int $documentId Credit document PK.
     *
     *
     * @since  2.0.0
     */
    public static function documentHasLiveRefund(int $documentId): bool
    {
        if ($documentId <= 0) {
            return false;
        }

        $db = self::db();

        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName(self::TABLE, 'r'))
                ->where($db->quoteName('r.id_document') . ' = ' . $documentId)
                ->where($db->quoteName('r.payment_type') . ' = ' . $db->quote(self::TYPE_REFUND))
                ->where($db->quoteName('r.transaction_status') . ' NOT IN (' . $db->quote(self::STATUS_FAILED) . ', ' . $db->quote(self::STATUS_CANCELLED) . ')')
                ->where('(' . self::netAmountSql('r') . ') > 0'),
        )->loadResult() > 0;
    }

    /**
     * What is still refundable against one payment, for a new refund start.
     *
     * The row's gross minus the refunds already settled against it and the
     * chargebacks recorded against it, both net of their reversals, minus what
     * the payment's own pending provider refunds reserve (each counted once, as
     * in {@see self::refundableNow()}: the provider holds that money for them on
     * this payment), never negative. This is the maximum a refund UI may offer
     * and a gateway may send; the order-wide {@see self::refundableNow()} binds
     * as well. Money a provider already moved is booked against the books alone
     * (refundPayment() with gateway_moved), never against this figure.
     *
     * @param int $paymentId The original payment PK
     *
     * @return Money Remaining refundable amount, in the order's currency
     *
     * @since  2.0.0
     */
    public static function refundableRemainder(int $paymentId): Money
    {
        $original = self::getRaw($paymentId);

        // A row that does not exist belongs to no order.
        if (!$original) {
            return Money::zero(OrderCurrency::forNewOrder());
        }

        $orderId = (int) $original->id_order;
        // Its own reserving refund rows are read first, then the gateway, then the books.
        $reserved = (string) ($original->payment_type ?? '') === self::TYPE_PAYMENT
            ? self::reservedTotal(unbooked: [
                'rows' => array_intersect_key(self::reservingRows(orderId: $orderId), [$paymentId => true]),
                'reserved' => [$paymentId => self::askGateway(payment: $original)['reserved']],
            ], orderId: $orderId)
            : Money::zero(self::orderCurrency($orderId));

        return self::bookedRemainder(original: $original)->subtract($reserved)->nonNegative();
    }

    /**
     * What the books leave refundable against one payment: its gross minus the
     * refunds settled against it and the chargebacks recorded against it, both net
     * of their reversals, never negative. The figure the linked over-refund cap
     * enforces, and the one money a provider already moved is booked against; a
     * new start is held to it less the payment's own reservations
     * ({@see self::refundableRemainder()}).
     *
     * @param object $original The payment row (id, id_order, amount).
     *
     * @return Money In the order's currency and scale, never negative.
     *
     * @since  2.1.0
     */
    private static function bookedRemainder(object $original): Money
    {
        $paymentId = (int) $original->id;
        $orderId = (int) $original->id_order;

        return self::money($original->amount, $orderId)->abs()
            ->subtract(self::money(self::sumRefundsAgainst($paymentId), $orderId))
            ->subtract(self::money(self::sumChargebacksAgainst($paymentId), $orderId))
            ->nonNegative();
    }

    //  INTERNAL: Database Operations

    //  INTERNAL: Utilities

    /**
     * Freeze a payment method onto the order row when the order has none
     * yet (id + snapshot name). One guarded UPDATE: an order that already
     * carries a method name is never touched.
     *
     * @param int $orderId The order id.
     * @param int $methodId The payment method id.
     * @param string $methodName The resolved method-name snapshot.
     *
     *
     * @since  1.0.0
     */
    private static function adoptMethodOnOrderIfEmpty(int $orderId, int $methodId, string $methodName): void
    {
        // Outside the try: the catch below needs a connection to ask about the transaction.
        $db = self::db();

        try {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__alfa_orders'))
                ->set([
                    $db->quoteName('id_payment_method') . ' = ' . $methodId,
                    $db->quoteName('payment_method_name') . ' = ' . $db->quote($methodName),
                ])
                ->where([
                    $db->quoteName('id') . ' = ' . $orderId,
                    '(' . $db->quoteName('payment_method_name') . " = '' OR " . $db->quoteName('payment_method_name') . ' IS NULL)',
                ]);
            $db->setQuery(query: $query);
            $db->execute();
        } catch (Exception $e) {
            if (TransactionState::endedBy($db, $e)) {
                throw $e;
            }

            self::warn('adoptMethodOnOrderIfEmpty(): ' . $e->getMessage());
        }
    }
}
