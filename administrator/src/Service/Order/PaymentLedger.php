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
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Model\OrderModel;
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
     * @since  1.9.0
     */
    public const UNRECORDED_PAYMENT_STATUSES = [
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
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
     * @since  1.14.0
     */
    public const TYPE_REVERSAL = 'reversal';

    /**
     * @var string Money the customer's bank took back from a collected gateway
     *             payment. Written only by {@see self::recordChargeback()}.
     *
     * @since  1.14.0
     */
    public const TYPE_CHARGEBACK = 'chargeback';

    /**
     * @var string[] The types only the ledger's own verbs write. A row of one of
     *               them is history: it is never edited or deleted.
     *
     * @since  1.14.0
     */
    public const FACT_TYPES = [self::TYPE_REVERSAL, self::TYPE_CHARGEBACK];

    /**
     * @var string The payment_method_type of money that moved through the
     *             customer's wallet: a wallet spend, and a refund of one.
     *
     * @since  1.14.0
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
     * @since  1.10.0
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
     * @since  1.10.0
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
     * @since  1.10.0
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
     * @since  1.10.0
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
     * @since  1.10.0
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
     * @since  1.10.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @var string recordReversal() / recordChargeback(): the fact is written.
     *
     * @since  1.14.0
     */
    public const OUTCOME_RECORDED = 'recorded';

    /**
     * @var string recordReversal() / recordChargeback(): nothing written, because
     *             the request is not a fact this ledger can take. A retry cannot help.
     *
     * @since  1.14.0
     */
    public const OUTCOME_REFUSED = 'refused';

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
     * @since  1.9.0
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
     * @since  1.9.0
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
     * returning spent wallet money is money truth, not presentation.
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
     * @since  1.9.0
     */
    public function bookkeeping(): static
    {
        $this->bookkeeping = true;
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
     * @since  1.9.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
            );
        }

        return static::update($this->paymentId, $this->data, $this->bookkeeping, $payloadMerge)
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
     *
     * @return int|false New payment row ID, or false on failure
     *
     * @throws OrderCurrencyException When the order names no currency: nothing is written.
     *
     * @since  1.0.0
     */
    public static function create(array $data, Money|float $grandTotalHint = 0.0): int|false
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
        // ledger's spend, and every third-party gateway that follows the
        // documented PaymentsPlugin::refundableNow() preflight. Locking in
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

            $newId = self::createLocked($data, $orderId, $grandTotalHint);

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
     *
     * @return int|false New payment row ID, or false if a guard refused
     *
     * @since  1.9.0
     */
    private static function createLocked(array $data, int $orderId, Money|float $grandTotalHint, bool $ledgerFact = false): int|false
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
            // decimal(24,8) accumulation artefact.
            if (self::exceedsCap([$alreadyRefunded, $thisRefund], $gross, $orderId)) {
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
     * must read as 'payment.refunded', never as an incoming 'payment.added'.
     * Non-fatal by contract: the row is committed, so an entry that cannot be
     * written is logged and never turns a recorded payment into a throw.
     *
     * @param int $paymentId Committed payment row PK.
     * @param int $orderId Order PK.
     *
     *
     * @since  1.10.0
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
     * @since  1.14.0
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

        OrderModel::logOrderActivity(
            $orderId,
            $isRefundRow ? 'payment.refunded' : 'payment.added',
            $isRefundRow
                ? "Refunded: {$methodName} - {$amount}"
                : "Added payment: {$methodName} - {$amount}",
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
     *
     * @return bool True on success
     *
     * @throws OrderCurrencyException When the order names no currency: nothing is written.
     *
     * @since  1.0.0
     */
    public static function update(int $id, array $data, bool $bookkeeping = false, ?Closure $payloadMerge = null): bool
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

                $data['payment_method_type'] = self::resolveMethodType('Payment', $movedTo);
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
        }

        return $success;
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
     * @since  1.9.0
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
     *
     * @return int WRITE_OK (1) this caller made the change, or the row still
     *             holds $expectedStatus and the write changed nothing;
     *             WRITE_RACED (0) row no longer in $expectedStatus, or the
     *             precondition refused, nothing written;
     *             WRITE_FAILED (-1) rejected or DB error
     *
     * @throws OrderCurrencyException When the order names no currency: nothing is written.
     *
     * @since  1.9.0
     */
    public static function updateIfStatus(
        int $id,
        array $data,
        string $expectedStatus,
        bool $bookkeeping = false,
        ?Closure $payloadMerge = null,
        ?Closure $precondition = null,
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
            $affected = self::updateIfStatusLocked($db, $id, $data, $expectedStatus, $oldSnapshot, $unreconciled);

            // The driver counts CHANGED rows, so a row that still holds
            // $expectedStatus but already carries these values also answers 0.
            // That is not a lost race: the write matched and had nothing to do.
            $unchanged = $affected === 0 && self::stillHoldsStatus($db, $id, $expectedStatus);

            // Only the caller that actually flipped the row reconciles it,
            // and it does so before the commit; see update().
            $walletEntries = [];

            if ($affected >= 1) {
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
     *
     * @return int Affected rows, or -1 when a guard refused the write
     *
     * @since  1.9.0
     */
    private static function updateIfStatusLocked(
        object $db,
        int $id,
        array &$data,
        string $expectedStatus,
        object $oldSnapshot,
        ?array &$unreconciled = null,
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

        // Same money caps as update(); rejected = nothing written
        if (!self::recheckCapsOnUpdate($id, $orderId, $oldSnapshot, $data)) {
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

            $data['payment_method_type'] = self::resolveMethodType('Payment', $movedTo);
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     *      reserves nothing and is exempt.
     *
     * @param object $stored The row as read under the order lock.
     * @param array $data The write.
     * @param int $orderId The order the write names.
     *
     * @return string|null Why the write is refused, or null.
     *
     * @since  1.14.0
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
     * How many completed fact rows of one type name a row.
     *
     * @param string $column id_reversed_payment (reversals) or id_refunded_payment (chargebacks).
     * @param int $rowId The named row.
     * @param string $type TYPE_REVERSAL or TYPE_CHARGEBACK.
     *
     *
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
     */
    private static function withoutReferenceHash(array &$data): void
    {
        unset($data['transaction_id_hash']);
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
     * @since  1.9.0
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
     * Refund a payment: the whole two-step-plus-compensation dance as ONE
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
     *     because partial refunds may precede. A zero or negative Money is
     *     refused: a monetary zero never means "no amount was supplied".
     *  3. FULL vs PARTIAL. Covering the remainder is FULL: the original is
     *     flipped to 'refunded' (a real, merchant-visible transition: the
     *     status_on_refunded map SHOULD fire here). Anything less is
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
     *  5. COMPENSATION. If the audit row is refused, a full refund's flip is
     *     rolled back so the ledger is left where it started, unless the
     *     gateway already moved the money (see 'gateway_moved'), where the
     *     truth is that the payment IS refunded and only the books are
     *     behind. Then there is no rollback, only a CRITICAL reconcile log.
     *  6. ONE PROVIDER REFUND, ONE ROW. A non-empty 'transaction_id' already
     *     on a refund row of this payment is the provider retrying that
     *     refund: OUTCOME_DUPLICATE, nothing written. Asked before any flip,
     *     again under the order lock a full refund's flip takes (a concurrent
     *     retry never flips the original), and decided for good inside
     *     create()'s order lock, so two concurrent retries cannot both book
     *     it. The complete reference is compared byte for byte (the column
     *     stores it whole); an empty reference is never de-duplicated, the
     *     same reference on another payment is another refund, and a failed
     *     or cancelled refund row reserves nothing.
     *
     * PREFLIGHT ONLY: this does not call any gateway. Move the money
     * first, then record it here; refundableNow() is the check to run
     * BEFORE the gateway call.
     *
     * Options:
     *   'reason'           string  Human-readable reason (refund_reason)
     *   'transaction_id'   string  Gateway REFUND reference: the provider's id
     *                              for this refund, never the original
     *                              payment's (see 6.)
     *   'gateway_response' string  Raw gateway JSON, for reconciliation
     *   'document'         int     Credit document this refund settles
     *   'gateway_moved'    bool    The money ALREADY left at the gateway:
     *                              skip the compensating rollback and log
     *                              CRITICAL instead. Set it whenever the
     *                              gateway API succeeded before this call.
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
     * @since  1.9.0
     */
    public static function refundPayment(int $paymentId, ?Money $amount = null, array $options = []): array
    {
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

        // A refund returns money in the order's currency: none is booked on
        // an order that names no currency.
        try {
            OrderCurrency::of(orderId: $orderId);
        } catch (OrderCurrencyException $e) {
            return self::refundResult(self::OUTCOME_NOT_REFUNDABLE, reason: $e->getMessage());
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

        if ($booked !== null) {
            return self::refundResult(
                self::OUTCOME_DUPLICATE,
                reason: self::duplicateReason($paymentId, $reference, $booked),
            );
        }

        // Same figure the linked over-refund cap enforces, so nothing this
        // verb accepts can be rejected at write time over refunds that
        // already happened. Guard epsilon: dust below it is exhausted.
        $remainder = self::refundableRemainder($paymentId);

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

        // ONLY null means "the remainder"; a zero was refused above, because a
        // monetary zero must never stand for "no amount was supplied".
        // Everything below is Money in the ORDER'S scale, so the rounding
        // happens once, correctly, instead of at a hard-coded six decimals.
        $requested = $amount ?? $remainder;

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
        // paid out. Checking here makes the refusal a sentence rather than a
        // silent false from create(); the cap itself is still the backstop.
        $pool = self::refundableNow($orderId);

        if ($requested->greaterThan($pool)) {
            return self::refundResult(
                self::OUTCOME_NOT_REFUNDABLE,
                reason: sprintf(
                    'requested %s, order #%d has %s refundable; store credit already returned counts as paid out',
                    $requested->format(),
                    $orderId,
                    $pool->format(),
                ),
            );
        }

        // "Full" means the refunds cover the payment, asked as a Money
        // question: the old (remainder - amount) <= 0.0001 called a 1-yen
        // shortfall on a JPY order full, and a 3-decimal OMR order's genuine
        // remainder dust not-full. Refunds alone decide it (refundTypeFor()).
        $isFull = self::refundTypeFor(paymentId: $paymentId, amount: $requested) === self::REFUND_FULL;
        $gatewayMoved = (bool) ($options['gateway_moved'] ?? false);

        // The row is handed the Money itself, so its column is written the
        // exact decimal of it; the log lines quote this figure. Never
        // reassigned over the $amount parameter: every decision above this
        // line was taken on Money.
        $writeAmount = $requested->getAmount();

        // STEP 1 (FULL ONLY): flip the original
        // Deliberately NOT a bookkeeping write: a full refund IS a phase the
        // order reached, so status_on_refunded must fire. Compare-and-set so
        // two admins clicking Refund cannot both pass.
        if ($isFull) {
            // The reference is asked again once the flip holds the order: a
            // concurrent booking of it committed while this call queued is seen
            // only there, and a retry must not flip the original at all.
            $verdict = self::updateIfStatus(
                $paymentId,
                [
                    'id_order' => $orderId,
                    'transaction_status' => self::STATUS_REFUNDED,
                ],
                self::STATUS_COMPLETED,
                precondition: $reference === ''
                    ? null
                    : static fn (): bool => self::refundUnderReference($paymentId, $reference) === null,
            );

            // Lost to a concurrent booking of this very refund: the refund is
            // in the books, which is the duplicate, not a race to report.
            $bookedMeanwhile = $verdict === self::WRITE_RACED
                ? self::refundUnderReference($paymentId, $reference)
                : null;

            if ($bookedMeanwhile !== null) {
                return self::refundResult(
                    self::OUTCOME_DUPLICATE,
                    reason: self::duplicateReason($paymentId, $reference, $bookedMeanwhile),
                );
            }

            if ($verdict === self::WRITE_RACED) {
                return self::refundResult(
                    self::OUTCOME_RACED,
                    reason: "payment #{$paymentId} left 'completed' mid-refund; nothing written",
                );
            }

            if ($verdict !== self::WRITE_OK) {
                return self::refundResult(
                    self::OUTCOME_FAILED,
                    reason: "payment #{$paymentId} could not be marked refunded",
                );
            }
        }

        // STEP 2: the linked audit row
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

        // A THROW HERE IS THE SAME EVENT AS A FALSE. Step 1 has already flipped
        // the original to 'refunded' and fired status_on_refunded; if this
        // write dies rather than declining, the payment is marked refunded
        // with no refund row behind it: the status says the money went back
        // and the ledger says nothing came back. The compensation below is
        // written for exactly that state, so the exception is turned into the
        // outcome it already handles instead of escaping past it.
        $createFailure = null;

        try {
            $refundId = static::create($data);
        } catch (Throwable $e) {
            // A failure that ended the caller's transaction is the caller's to
            // hear: the compensation below would run outside it.
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
        }

        if ($refundId !== false) {
            return self::refundResult(self::OUTCOME_OK, (int) $refundId, amount: $writeAmount);
        }

        // COMPENSATION: the audit row was refused, or threw
        // Both arrive here. A refusal names its own reason through warn();
        // an exception's message is carried along, because "the write threw"
        // and "the write declined" need different fixes and the log line is
        // the only place that distinction survives.
        $why = $createFailure === null ? 'rejected' : 'threw: ' . $createFailure->getMessage();

        // A PROVIDER RETRY THAT REACHED THE LOCK SECOND. The early check ran
        // before the lock, so a concurrent booking of the same reference is
        // seen only by create(), which refused this row. Nothing is missing
        // from the books (the row that records this refund exists), so it is
        // no reconcile case: a full refund's flip is put back like any other
        // refused row, and the answer is the duplicate.
        $duplicateOf = $createFailure === null ? self::refundUnderReference($paymentId, $reference) : null;

        // The gateway already paid the customer: the payment really IS
        // refunded and only the books are behind, so rolling the original
        // back would make the ledger lie. Be loud instead.
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

        // Nothing moved at the gateway (or what moved is already booked), so
        // put the original back. As VISIBLE as the flip it undoes:
        // status_on_completed has to restore the order the refunded
        // automation just moved.
        if ($isFull) {
            $rollback = self::updateIfStatus(
                $paymentId,
                [
                    'id_order' => $orderId,
                    'transaction_status' => self::STATUS_COMPLETED,
                ],
                self::STATUS_REFUNDED,
            );

            // The rollback can be refused too: the same order row is locked,
            // and the flip already returned the wallet money. Discarding this
            // verdict was how a payment could be left 'refunded', the customer
            // credited, and no refund row to account for either, while the
            // caller was told nothing had happened.
            if ($rollback !== self::WRITE_OK) {
                self::ensureLogger();
                Log::add(
                    sprintf(
                        'refundPayment(): payment #%d is stranded: the audit row was %s and the '
                        . 'rollback of its refunded flip also failed. The money was returned; no refund '
                        . 'row accounts for it. Reconcile this order by hand.',
                        $paymentId,
                        $why,
                    ),
                    Log::CRITICAL,
                    'com_alfa.payments',
                );

                NotificationHelper::push(
                    'payments.refund.unreconciled.' . $paymentId,
                    'Payment #' . $paymentId . ': refund left unreconciled',
                    [
                        'severity' => 'danger',
                        'group' => 'Payments',
                        'message' => 'The money was returned but no refund row records it, and the rollback failed. This order needs manual reconciliation.',
                        'url' => 'index.php?option=com_alfa&task=order.edit&id=' . $orderId,
                        'view' => ['action' => 'alfa.orders.view', 'asset' => 'com_alfa'],
                        'link' => ['action' => 'alfa.orders.edit', 'asset' => 'com_alfa'],
                    ],
                );

                return self::refundResult(
                    self::OUTCOME_UNRECONCILED,
                    reason: sprintf(
                        'payment #%d was flipped to refunded, the audit row was rejected, and the rollback failed',
                        $paymentId,
                    ),
                );
            }
        }

        if ($duplicateOf !== null) {
            return self::refundResult(
                self::OUTCOME_DUPLICATE,
                reason: self::duplicateReason($paymentId, $reference, $duplicateOf, flipUndone: $isFull),
            );
        }

        return self::refundResult(
            self::OUTCOME_FAILED,
            reason: sprintf('the refund row for payment #%d was rejected by the ledger', $paymentId),
        );
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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

                if ($paymentId > 0) {
                    $p = self::getRaw($paymentId);

                    if ($p !== null
                        && (string) $p->payment_type === self::TYPE_PAYMENT
                        && (string) $p->transaction_status === self::STATUS_REFUNDED
                        && self::money(self::sumRefundsAgainst($paymentId), $orderId)->lessThan(self::money($p->amount, $orderId)->abs())
                    ) {
                        $db->setQuery(
                            $db->getQuery(true)
                                ->update($db->quoteName(self::TABLE))
                                ->set($db->quoteName('transaction_status') . ' = ' . $db->quote(self::STATUS_COMPLETED))
                                ->where($db->quoteName(self::PK) . ' = ' . $paymentId)
                                ->where($db->quoteName('transaction_status') . ' = ' . $db->quote(self::STATUS_REFUNDED)),
                        )->execute();

                        $reopened = (int) $db->getAffectedRows() === 1;
                    }
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @param bool $flipUndone This call had already marked the payment
     *                         refunded and put it back to completed: two
     *                         status changes were written and logged, so
     *                         "nothing written" would not be true.
     *
     *
     * @since  1.14.0
     */
    private static function duplicateReason(int $paymentId, string $reference, object $booked, bool $flipUndone = false): string
    {
        return sprintf(
            'refund %s is already recorded on payment #%d as refund row #%d: a repeat of the same refund, %s',
            htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $paymentId,
            (int) $booked->id,
            $flipUndone
                ? 'no refund row written; the payment was marked refunded and then put back to completed'
                : 'nothing written',
        );
    }

    /**
     * Shape one refundPayment() verdict.
     *
     *
     * @return array{outcome:string,refund_id:int,reason:string,amount:float}
     *
     * @since  1.9.0
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
     * @since  1.9.1
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.9.0
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
     * @since  1.14.0
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
     * @since  1.3.0
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
     * @since  1.9.0
     */
    /**
     * Net customer-credit ("wallet") amount settling this order's credit
     * notes. Void claw-backs are negative entries with the same source, so
     * the SUM self-corrects; clamped at zero against stray data.
     *
     *
     * @return float
     *
     * @since  1.9.0
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
     * @since  1.9.0
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
     * @since  1.9.0
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
     * @since  1.9.0
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
     * @since  1.9.0
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
     * @since  1.14.0
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
     * @since  1.9.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.9.0
     */
    private static float $updateGrandTotalHint = 0.0;

    /**
     * Park (or clear with 0.0) the grand-total hint the next update-path cap
     * re-check should measure against. Scope it tightly: set, save, reset.
     *
     * @param float $total In-memory grand total, 0.0 to clear.
     *
     *
     * @since  1.9.0
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
     * @since  1.9.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.9.0
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
     * @since  1.14.0
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
     * @since  1.9.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.9.0
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
     * @since  1.9.0
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
     * @since  1.9.1
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
     * @since  1.10.0
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
     * @since  1.10.0
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
     * @since  1.9.1
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
     * @since  1.9.0
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
     * @since  1.9.0
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
     * Counts 'payment' rows completed OR refunded: the standard plugin's
     * full-refund flow flips the original to 'refunded' BEFORE writing the
     * linked refund row, so a completed-only pool would read 0 at that
     * moment and reject the legitimate refund. Deliberately NOT
     * sumCollectedPayments()/paidTotal(), which stay completed-only to
     * mirror OrdersModel's total_paid_real.
     *
     * @param int $orderId Order PK
     * @param int $excludeId Row PK to leave out (0 = none)
     *
     * @return string Ever-collected total in major units, as the SUM's decimal text
     *
     * @since  1.9.0
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
     * @since  1.9.0
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
     * @since  1.14.0
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
     * @since  1.9.0
     */
    private static function orderGrandTotal(int $orderId, ?object $order = null): string
    {
        return OrderTotalHelper::getExactBreakdown(orderId: $orderId, order: $order)->grand_total_tax_incl;
    }

    /**
     * Delete an order payment record.
     *
     * Refused for a collected 'payment' row (completed/refunded) with
     * settled refunds recorded against it: deleting the original would
     * leave those refunds above the pool for good and their
     * id_refunded_payment dangling. Anything else deletes as before.
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

        if ($payment
            && (self::countFactsNaming(column: 'id_reversed_payment', rowId: $id, type: self::TYPE_REVERSAL) > 0
                || self::countFactsNaming(column: 'id_refunded_payment', rowId: $id, type: self::TYPE_CHARGEBACK) > 0)) {
            self::warn(sprintf(
                'delete(): row #%d is named by a recorded reversal or chargeback; it is history and cannot be deleted',
                $id,
            ));

            return false;
        }

        if (
            $payment
            && ($payment->payment_type ?? '') === self::TYPE_PAYMENT
            && in_array(
                (string) ($payment->transaction_status ?? ''),
                [self::STATUS_COMPLETED, self::STATUS_REFUNDED],
                true,
            )
            && self::sumRefundsAgainst($id) > 0.0
        ) {
            self::warn(sprintf(
                'delete(): payment #%d has settled refunds recorded against it; remove those first',
                $id,
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
        // neither figure.
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
     * @since  1.9.1
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
     * @since  1.9.1
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
     * @since  1.9.1
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
     * @since  1.9.0
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
     * @since  1.9.0
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
     * @since  1.14.0
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
     * @since  1.9.0
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
     * @since  1.9.0
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
     * @since  1.10.0
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
     * @since  1.10.0
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
     * @since  1.9.0
     */
    /**
     * How much may still be refunded on this order: the PREFLIGHT every
     * gateway must run BEFORE moving money.
     *
     * Ever-collected pool minus refunds already settled minus store credit
     * already handed back for this order's credit notes. Contract for
     * gateway authors:
     *
     *   - Call this (or PaymentsPlugin::refundableNow) BEFORE the gateway
     *     refund API. The ledger's own guard runs at write time: by then
     *     your gateway has already moved the money and the row is rejected,
     *     leaving a refund that exists at the bank but not in the books.
     *   - Gate your Refund action on it too, so the button is not offered
     *     for money that is no longer refundable.
     *
     * @param int $orderId Order PK; 0 = no order.
     *
     * @return Money Refundable amount, never negative; zero, in the currency
     *               a new order takes, when there is no order.
     *
     * @since  1.9.0
     */
    public static function refundableNow(int $orderId): Money
    {
        // No order: nothing can be refunded, in the currency a new order takes.
        if ($orderId <= 0) {
            return Money::zero(OrderCurrency::forNewOrder());
        }

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
     * @since  1.9.0
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
     * @since  1.9.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * What is still refundable against one payment.
     *
     * The row's gross minus the refunds already settled against it and the
     * chargebacks recorded against it, both net of their reversals, never
     * negative. This is the maximum a refund UI may offer: the create-path cap
     * refuses anything above it.
     *
     * @param int $paymentId The original payment PK
     *
     * @return float Remaining refundable amount in major units
     *
     * @since  1.9.0
     */
    public static function refundableRemainder(int $paymentId): Money
    {
        $original = self::getRaw($paymentId);

        // A row that does not exist belongs to no order.
        if (!$original) {
            return Money::zero(OrderCurrency::forNewOrder());
        }

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
        try {
            $db = self::db();
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
