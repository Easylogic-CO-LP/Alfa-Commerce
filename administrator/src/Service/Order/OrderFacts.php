<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\OrderTotalHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\Factory;
use stdClass;

/**
 * The derivation of an order's live payment facts.
 *
 * Nothing is stored: the balance and the payment status are derived on read
 * from the current order total (OrderTotalHelper) and the payment ledger
 * (#__alfa_order_payments). The admin list, the API order, the API list and
 * the totals card each used to run their own ladder and disagreed: an order
 * overpaid and then partly refunded read 'partially_refunded' in the back
 * office and 'refunded' over the API. They all read this class now.
 *
 * Two entry points, one core:
 *   forOrder()  loads the total and the ledger rows for a single order;
 *   fromRows()  takes a total and rows a caller already has (the list view
 *               batches them, so unifying the derivation costs no queries).
 *
 * @since  1.10.0
 */
final class OrderFacts
{
    /**
     * Live payment facts for one order.
     *
     * $grandTotal exists for ONE caller shape: a hydrated order mid-placement,
     * whose shipment row does not exist yet, so recomputing the total from the
     * tables would be short by the carriage. Passing it keeps that caller on
     * this authority instead of letting it derive money of its own, which is
     * exactly how a second definition of "still to collect" came about. It
     * overrides only the total; the rows, the currency and the frozen scale are
     * still read here.
     *
     * @param int $orderId Order PK.
     * @param Money|float|null $grandTotal Caller's authoritative total (its
     *                                     Money, read on its digits), or null
     *                                     to recompute it from the tables.
     *
     * @return stdClass See {@see fromRows} for the shape.
     *
     * @throws OrderCurrencyException When the order names no currency: its
     *                                payment state cannot be derived, and no
     *                                other currency stands in for it.
     *
     * @since  1.10.0
     */
    public static function forOrder(int $orderId, Money|float|null $grandTotal = null): stdClass
    {
        return self::read(orderId: $orderId, grandTotal: $grandTotal)[0];
    }

    /**
     * What is still due on one order, as its Money: the signed balance of
     * {@see self::forOrder()}, never through a float.
     *
     * @param int $orderId Order PK.
     * @param Money|float|null $grandTotal As {@see self::forOrder()} takes it.
     *
     * @return Money Signed due at the order's frozen scale.
     *
     * @throws OrderCurrencyException When the order names no currency.
     *
     * @since  1.14.0
     */
    public static function balanceOf(int $orderId, Money|float|null $grandTotal = null): Money
    {
        return self::read(orderId: $orderId, grandTotal: $grandTotal)[1];
    }

    /**
     * The facts of one order and its signed balance as Money.
     *
     * @param int $orderId Order PK.
     * @param Money|float|null $grandTotal As {@see self::forOrder()} takes it.
     *
     * @return array{0: stdClass, 1: Money}
     *
     * @since  1.14.0
     */
    private static function read(int $orderId, Money|float|null $grandTotal): array
    {
        // No order yet: the figures of an order that is about to be created.
        if ($orderId <= 0) {
            return self::derive($grandTotal ?? 0.0, [], OrderCurrency::forNewOrder());
        }

        // THE ORDER'S OWN CURRENCY AT THE ORDER'S OWN SCALE, NOT TODAY'S.
        // Everything below (grand_total, paid_real, balance, is_paid,
        // payment_status) is re-derived from stored money through Money::of(),
        // so the scale it quantises at IS the order's payment state. Reading
        // the live currency row here is what let a decimal_place edit
        // re-settle a historical order. Resolved before the rows are read, so
        // an order that names no currency is refused without a second read.
        $currency = OrderCurrency::of(orderId: $orderId);

        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            $db->getQuery(true)
                ->select([
                    $db->quoteName('id'),
                    $db->quoteName('payment_type'),
                    $db->quoteName('transaction_status'),
                    $db->quoteName('amount'),
                    $db->quoteName('id_refunded_payment'),
                    $db->quoteName('id_reversed_payment'),
                ])
                ->from($db->quoteName('#__alfa_order_payments'))
                ->where($db->quoteName('id_order') . ' = ' . $orderId),
        );

        // Drain this before anything else touches the driver. It is a shared
        // singleton, and OrderTotalHelper runs its own setQuery(): as a
        // sibling argument to fromRows() it would replace this query before
        // it was ever read.
        $rows = (array) $db->loadObjectList();

        // ?? and not a truthiness test: 0.0 is a real total (a fully
        // discounted order), and treating it as "not supplied" would send the
        // caller back to the tables for a figure it already holds. The exact
        // sum as Money: a float of it loses an 8-place order's last units.
        $grandTotal ??= Money::of(OrderTotalHelper::getExactBreakdown(orderId: $orderId)->grand_total_tax_incl, $currency);

        return self::derive($grandTotal, $rows, $currency);
    }

    /**
     * The core derivation: pure, so the list view can feed it rows it already
     * loaded and get byte-identical answers to forOrder().
     *
     * Returned facts:
     *   currency_scale     the order currency's configured monetary scale;
     *                      Alfa admits {@see Currency::SCALE_MIN}..{@see
     *                      Currency::SCALE_MAX}, and every fact below is
     *                      derived at THIS scale, never at OrderTotalHelper's six
     *   grand_total        current order total (OrderTotalHelper), NORMALIZED
     *                      to that scale
     *   paid_real          money ever collected (completed 'payment' rows)
     *   refunded_real      money given back (settled 'refund' rows, each net
     *                      of its reversals)
     *   returned_real      the part of refunded_real that paid_real still
     *                      counts: refunds of payments still 'completed', and
     *                      unlinked (goodwill) ones. A full refund flips its
     *                      payment out of paid_real, so its refund is not in
     *                      here
     *   charged_back       money the customer's bank took back (completed
     *                      'chargeback' rows, each net of its reversals).
     *                      Not a refund: an orthogonal fact, never negative,
     *                      0.0 when there is none
     *   still_held         what the shop is actually holding right now:
     *                      paid_real - returned_real - charged_back
     *   balance            SIGNED due: grand_total - still_held.
     *                      > 0 collectable - < 0 overpaid - never clamped
     *   overpaid           balance < 0
     *   excess             how much MORE is held than the order now asks for:
     *                      -balance when overpaid, otherwise exactly 0. Never
     *                      negative, never null.
     *   is_paid            THE payment fact every gate reads: balance <= 0
     *                      at that scale
     *   payment_status     unpaid | partial | paid | partially_refunded | refunded
     *   payment_percentage paid_real as a percentage of the normalized total
     *   money              the money figures above as Money, at that scale:
     *                      grand_total, paid_real, refunded_real,
     *                      returned_real, charged_back, still_held, balance,
     *                      excess
     *
     * @param Money|float $grandTotal Current canonical order total (tax incl);
     *                                a Money is read on its digits.
     * @param array $rows #__alfa_order_payments rows for the order.
     * @param Currency $currency The order's currency at its frozen scale
     *                           ({@see OrderCurrency}). There are no facts
     *                           without one.
     *
     *
     * @since  1.10.0
     */
    public static function fromRows(Money|float $grandTotal, array $rows, Currency $currency): stdClass
    {
        return self::derive($grandTotal, $rows, $currency)[0];
    }

    /**
     * The derivation itself: the facts, and the signed balance as Money.
     *
     * @param Money|float $grandTotal As {@see self::fromRows()} takes it.
     * @param array $rows #__alfa_order_payments rows for the order.
     * @param Currency $currency The order's currency at its frozen scale.
     *
     * @return array{0: stdClass, 1: Money}
     *
     * @since  1.14.0
     */
    private static function derive(Money|float $grandTotal, array $rows, Currency $currency): array
    {
        $paid = [];
        $refunded = [];

        // Which collected payments are still counted in paid_real. A FULL
        // refund flips its original to 'refunded' (dropping it from the paid
        // pool), while a PARTIAL refund leaves the original 'completed'. Only
        // refunds whose original is STILL counted may reduce what is held;
        // otherwise a half-refunded two-payment order reads as fully refunded.
        $stillCounted = [];

        foreach ($rows as $row) {
            $type = (string) ($row->payment_type ?? '');
            $status = (string) ($row->transaction_status ?? '');
            $amount = self::amount($row->amount ?? 0, $currency);

            if ($type === PaymentLedger::TYPE_PAYMENT && $status === PaymentLedger::STATUS_COMPLETED) {
                $paid[] = $amount;
                $stillCounted[(int) ($row->id ?? 0)] = true;
            }
        }

        // What each refund or chargeback has had reversed:
        // a reversal names its target, and the target counts
        // only for what still stands.
        $reversedBy = [];

        foreach ($rows as $row) {
            if ((string) ($row->payment_type ?? '') === PaymentLedger::TYPE_REVERSAL
                && (string) ($row->transaction_status ?? '') === PaymentLedger::STATUS_COMPLETED) {
                $targetId = (int) ($row->id_reversed_payment ?? 0);
                $amount = self::amount($row->amount ?? 0, $currency);

                $reversedBy[$targetId] = isset($reversedBy[$targetId]) ? $reversedBy[$targetId]->add($amount) : $amount;
            }
        }

        $net = static fn (object $row): Money => (isset($reversedBy[(int) ($row->id ?? 0)])
            ? self::amount($row->amount ?? 0, $currency)->subtract($reversedBy[(int) ($row->id ?? 0)])
            : self::amount($row->amount ?? 0, $currency))->nonNegative();

        $returnedFromHeld = [];
        $chargedBack = [];

        foreach ($rows as $row) {
            // A chargeback counts whatever its payment's status: the bank took
            // the money back from what the shop held.
            if ((string) ($row->payment_type ?? '') === PaymentLedger::TYPE_CHARGEBACK
                && (string) ($row->transaction_status ?? '') === PaymentLedger::STATUS_COMPLETED) {
                $chargedBack[] = $net($row);

                continue;
            }

            if ((string) ($row->payment_type ?? '') !== PaymentLedger::TYPE_REFUND) {
                continue;
            }

            if (!\in_array((string) ($row->transaction_status ?? ''), [PaymentLedger::STATUS_REFUNDED, PaymentLedger::STATUS_COMPLETED], true)) {
                continue;
            }

            $amount = $net($row);
            $refunded[] = $amount;

            // An unlinked (goodwill) refund has no original to have been
            // dropped, so it always reduces what is held.
            $originalId = (int) ($row->id_refunded_payment ?? 0);

            if ($originalId === 0 || isset($stillCounted[$originalId])) {
                $returnedFromHeld[] = $amount;
            }
        }

        // Summed as Money so the figures round once, in the order's own scale,
        // instead of accumulating float dust across rows.
        $paidMoney = $paid ? Money::sum(...$paid) : Money::zero($currency);
        $refundedMoney = $refunded ? Money::sum(...$refunded) : Money::zero($currency);
        $returnedMoney = $returnedFromHeld ? Money::sum(...$returnedFromHeld) : Money::zero($currency);
        $chargedBackMoney = $chargedBack ? Money::sum(...$chargedBack) : Money::zero($currency);

        // THE normalisation, done once. OrderTotalHelper answers in six
        // decimals because that is what its arithmetic carries; the order's
        // currency is the only scale the obligation exists at, so every fact
        // below is derived from this figure and never from the raw one.
        $totalMoney = Money::of($grandTotal instanceof Money ? ExactAmount::ofMoney($grandTotal)->text() : $grandTotal, $currency);

        // Refunds only first: the status ladder asks it, so a chargeback never
        // reads as 'refunded'. Then what the bank took back.
        $heldBeforeChargebacks = $paidMoney->subtract($returnedMoney);
        $heldMoney = $heldBeforeChargebacks->subtract($chargedBackMoney);

        // Signed on purpose: an order whose total was reduced after payment
        // must read negative. Clamping here hid overpayment everywhere.
        $balanceMoney = $totalMoney->subtract($heldMoney);

        $facts = new stdClass();
        $facts->currency_scale = $currency->getDecimalPlaces();
        $facts->grand_total = $totalMoney->getAmount();
        $facts->paid_real = $paidMoney->getAmount();
        $facts->refunded_real = $refundedMoney->getAmount();
        // Named so a surface that shows Paid can net exactly what Paid still
        // counts; netting refunded_real there counts a full refund twice.
        $facts->returned_real = $returnedMoney->getAmount();
        $facts->charged_back = $chargedBackMoney->getAmount();
        $facts->still_held = $heldMoney->getAmount();
        $facts->balance = $balanceMoney->getAmount();
        $facts->overpaid = $balanceMoney->isNegative();

        // THE EXCESS, NAMED ONCE. Every consumer that wanted this figure had to
        // negate the balance itself, and the only surface that ever did lived in
        // a template (edit_totals). A derived fact that each reader re-derives is
        // a fact with as many definitions as readers, which is the thing this
        // class exists to prevent.
        //
        // Zero when not overpaid, so it is a Money amount at the order's frozen
        // scale in every case and never a null a consumer has to branch on. It
        // is NOT an independent truth: excess and balance are the same
        // subtraction read from opposite ends, and both come from Money's
        // integer minor units, so they cannot disagree.
        $facts->excess = ($balanceMoney->isNegative()
            ? $balanceMoney->abs()
            : Money::zero($currency))->getAmount();

        // THE payment fact every gate reads. Money compares integer minor
        // units, so "settled" is exact at every supported scale and no
        // consumer needs a tolerance of its own.
        $facts->is_paid = !$balanceMoney->isPositive();
        $facts->payment_percentage = $totalMoney->isPositive()
            ? round(($paidMoney->getMinorUnits() / $totalMoney->getMinorUnits()) * 100, 2)
            : 0.0;
        $facts->payment_status = self::status(
            paid:                  $paidMoney,
            refunded:              $refundedMoney,
            heldBeforeChargebacks: $heldBeforeChargebacks,
            balance:               $balanceMoney,
        );

        // The same facts as the Money they were derived from, for a reader that
        // publishes them: a float above holds fifteen significant digits, and an
        // order at eight places can state more.
        $facts->money = (object) [
            'grand_total' => $totalMoney,
            'paid_real' => $paidMoney,
            'refunded_real' => $refundedMoney,
            'returned_real' => $returnedMoney,
            'charged_back' => $chargedBackMoney,
            'still_held' => $heldMoney,
            'balance' => $balanceMoney,
            'excess' => $balanceMoney->isNegative() ? $balanceMoney->abs() : Money::zero($currency),
        ];

        return [$facts, $balanceMoney];
    }

    /**
     * The payment-status ladder.
     *
     * Refund states are tested FIRST: an order refunded in full was also paid
     * in full, so the paid ladder alone would report it as "paid, 100%": the
     * money-visible wrong answer.
     *
     * Every rung is a Money question, asked at the order currency's own scale
     * ({@see Currency::SCALE_MIN}..{@see Currency::SCALE_MAX}). The rung that
     * used to compare what was paid against the RAW six-decimal total is the
     * reason an order could read 'partial' with a 0.00 balance: a EUR total of
     * 117.374 is an obligation of 117.37, and paying 117.37 settles it. A fixed
     * tolerance cannot state that rule: 0.00001 is a hundredth of a minor unit
     * at two decimals and a thousand of them at eight.
     *
     * A chargeback is not a refund: the first rung reads what is held before
     * chargebacks, so a charged-back order never reads 'refunded'. A charged-back
     * order with no refunds reads 'partial', with is_paid false.
     *
     * @param Money $paid Money ever collected.
     * @param Money $refunded Money given back, net of reversals.
     * @param Money $heldBeforeChargebacks Money held, before chargebacks.
     * @param Money $balance Signed due at the order's scale.
     *
     *
     * @since  1.10.0
     */
    private static function status(Money $paid, Money $refunded, Money $heldBeforeChargebacks, Money $balance): string
    {
        if ($refunded->isPositive() && !$heldBeforeChargebacks->isPositive()) {
            return 'refunded';
        }

        if ($refunded->isPositive()) {
            return 'partially_refunded';
        }

        if (!$paid->isPositive()) {
            return 'unpaid';
        }

        // Overpayment is a separate FACT, not a status value: an overpaid
        // order is still 'paid' to every list filter and badge map, and the
        // excess is surfaced by ->overpaid / ->balance.
        return $balance->isPositive() ? 'partial' : 'paid';
    }

    /**
     * A ledger row's amount as Money at the order's scale.
     *
     * abs() mirrors the ledger's own SUM(ABS(amount)) guards: refunds are
     * stored positive and only rendered with a minus, and a hand-inserted
     * negative row must not make the derivation disagree with the guards.
     *
     * @param mixed $raw Amount as stored (decimal string, read on its
     *                   digits; a float or a Money).
     * @param Currency $currency Order currency.
     *
     *
     * @since  1.10.0
     */
    private static function amount($raw, Currency $currency): Money
    {
        if ($raw instanceof Money) {
            return $raw->abs();
        }

        return Money::of(\is_string($raw) ? $raw : (float) $raw, $currency)->abs();
    }
}
