<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Stats;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * The readings core's own dashboard cards draw (the takings hero and the alert strip)
 * and the window contract every other reading is measured against.
 *
 * The Analytics plugin's projections (rhythm, status split, payment split, top
 * customers) live in the plugin; they take one of these as their window and ask it
 * for {@see self::window()} and {@see self::currencyId()}, so a report and the
 * dashboard can never disagree about what "the last 30 days" means.
 *
 * ONE SERVICE, ONE WINDOW. Each method answers one business question over the same
 * period, so two cards on the same screen can never disagree about what "the last 30
 * days" means. The period is handed in once and never re-derived.
 *
 * THREE RULES IT KEEPS LITERALLY, because a dashboard that lies is worse than one that
 * is empty:
 *
 *  1. **Money is per currency, and the figures are the STORE's own.** Adding 100 EUR to
 *     100 USD is not 200 of anything. Every money query filters to the default currency
 *     and {@see self::money()} says how many others also traded, so a
 *     multi-currency shop is told what it is not being shown instead of being handed a
 *     number that means nothing. This is the same rule
 *     {@see \Alfa\Component\Alfa\Administrator\Service\Card\CoreCardProvider::takings()}
 *     already applies to the takings tile. A PAYMENT IS MONEY IN ITS ORDER'S CURRENCY:
 *     every query that values one joins its order ({@see PaymentLedger::orderJoin()})
 *     and reads the currency the order names, as the ledger and the order screen do,
 *     never the stamp on the payment row. A payment is then counted where its order
 *     is, and the money of an order that names no currency is in no figure.
 *
 *  2. **Days are the merchant's days.** A shop in Athens closes at midnight in Athens,
 *     not in UTC. Rows are stored UTC, so the window starts at the site's own midnight
 *     converted to UTC and each row's bucket is its distance from THAT instant, not
 *     `DATE(created)`, which would file the first three hours of every Greek evening
 *     under the previous day. (One caveat, stated rather than hidden: a window spanning
 *     a DST change uses the offset in force now, so one boundary in the year can be an
 *     hour out. Correcting that costs a per-row timezone conversion the index cannot
 *     use, which is not worth an hour once a year on a dashboard.)
 *
 *  3. **Series are DENSE.** Every day in the window appears, zeros included. A chart
 *     that skips empty days silently bends its x-axis, and a quiet week becomes
 *     invisible. Gaps are filled here, never in the presentation layer.
 *
 * Nothing here throws. A figure that cannot be produced comes back empty and the card
 * that asked for it draws what it can: the dashboard is a report, and a report must
 * never be the reason an admin cannot work.
 *
 * @since  1.10.0
 */
final class DashboardStatsService
{
    /**
     * @var int Seconds in a day, for the bucket arithmetic.
     * @since  1.10.0
     */
    private const DAY = 86400;

    /**
     * @var int How long an order may sit before "sitting" is the finding; shared with
     *          the orders list, whose "Needs attention" filter counts the same way.
     * @since  1.10.0
     */
    public const STALE_HOURS = 48;

    /**
     * @var int How many products every caller asks for: ONE number, so the card and
     *          the best-seller-running-low signal share a single query.
     * @since  1.10.0
     */
    public const TOP_LIMIT = 8;

    /**
     * @var int Same, for cart rules.
     * @since  1.10.0
     */
    public const PROMO_LIMIT = 8;

    /**
     * @var int|null The store currency's row id, resolved once. -1 = none configured.
     * @since  1.10.0
     */
    private ?int $currencyId = null;

    /**
     * @var string|null Window start, as a UTC SQL datetime.
     * @since  1.10.0
     */
    private ?string $start = null;

    /**
     * @var string|null Window end (exclusive), as a UTC SQL datetime.
     * @since  1.10.0
     */
    private ?string $end = null;

    /**
     * Answers already paid for, keyed by question.
     *
     * The attention signals are CROSS-REFERENCES: "a best seller running low" is the
     * best-sellers list joined to stock, and "a promotion that bleeds" is the promotions
     * list read again. Without this, one dashboard load ran the best-sellers aggregate
     * twice, the promotions aggregate twice and the whole money pass three times,
     * every duplicate a full scan over the window. The service is request-scoped and
     * the window is immutable, so an answer given once is the answer.
     *
     * @var array<string, mixed>
     * @since  1.10.0
     */
    private array $memo = [];

    /**
     * @var DateTimeImmutable Shop-local midnight the window opens on.
     * @since  1.10.0
     */
    private readonly DateTimeImmutable $localStart;

    /**
     * @var DateTimeImmutable Shop-local instant the window closes on: EXCLUSIVE, so a
     *                        window ending "on the 31st" runs to the last second of the 31st and never
     *                        swallows the first second of the 1st.
     * @since  1.10.0
     */
    private readonly DateTimeImmutable $localEnd;

    /**
     * @var int Whole days in the window: the number of buckets a series has.
     * @since  1.10.0
     */
    private readonly int $days;

    /**
     * A window of the last N days, ending now: what the dashboard asks for.
     *
     * Any window at all is accepted through the two date arguments; the dashboard's
     * "last N days" and a report's "previous month" are the same object, and every
     * question below answers for whichever it was given. Both dates are SHOP-LOCAL,
     * which is what the calendars post and what the presets resolve to
     * ({@see \Alfa\Component\Alfa\Administrator\Helper\AlfaHelper::buildDateRange()}).
     *
     * @param int $days How far back, when no dates are given.
     * @param DateTimeImmutable|null $localStart Shop-local start (inclusive).
     * @param DateTimeImmutable|null $localEnd Shop-local end (inclusive, to the second).
     *
     * @since  1.10.0
     */
    public function __construct(int $days = 30, ?DateTimeImmutable $localStart = null, ?DateTimeImmutable $localEnd = null)
    {
        $zone = self::zone();

        if ($localStart !== null && $localEnd !== null) {
            $start = $localStart->setTimezone($zone);
            // The picker posts "23:59:59"; one second on makes the bound exclusive without
            // the arithmetic ever having to know what the picker's last second was.
            $end = $localEnd->setTimezone($zone)->modify('+1 second');

            if ($end <= $start) {
                $end = $start->modify('+1 day');           // a backwards range is one day, not none
            }
        } else {
            $days = max(1, $days);
            $start = (new DateTimeImmutable('today midnight', $zone))->modify('-' . ($days - 1) . ' days');
            $end = (new DateTimeImmutable('today midnight', $zone))->modify('+1 day');
        }

        $this->localStart = $start;
        $this->localEnd = $end;
        $this->days = max(1, (int) ceil(($end->getTimestamp() - $start->getTimestamp()) / self::DAY));
    }

    /**
     * A window between two shop-local instants, as the date-range filter holds them.
     *
     * @param string $localStart 'Y-m-d H:i:s' in shop time, '' for "the last 30 days".
     * @param string $localEnd 'Y-m-d H:i:s' in shop time, '' for "now".
     *
     *
     * @since  1.10.0
     */
    public static function between(string $localStart, string $localEnd): self
    {
        $zone = self::zone();

        try {
            $start = $localStart !== '' ? new DateTimeImmutable($localStart, $zone) : null;
            $end = $localEnd !== '' ? new DateTimeImmutable($localEnd, $zone) : new DateTimeImmutable('now', $zone);
        } catch (Throwable) {
            return new self();                              // an unparseable bound is no bound
        }

        return $start === null ? new self() : new self(30, $start, $end);
    }

    /**
     * The shop's timezone: every window is anchored on it, never on the viewer's.
     *
     *
     * @since  1.10.0
     */
    private static function zone(): DateTimeZone
    {
        try {
            return new DateTimeZone(Factory::getApplication()->get('offset', 'UTC'));
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * The window's shop-local bounds, for a caller that wants to say what it covered.
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable} End is INCLUSIVE here
     *                                                                 (the last second), because that is how a human reads a range.
     *
     * @since  1.10.0
     */
    public function bounds(): array
    {
        return ['start' => $this->localStart, 'end' => $this->localEnd->modify('-1 second')];
    }

    /**
     * The hero: what the shop took, what it gave back, and how many orders carried it.
     *
     * Revenue is SETTLED money (completed payment rows), not order totals, because an
     * order total is a promise and a payment is a fact. Refunds are their own series
     * rather than being netted off: a week that took 10.000 and refunded 4.000 is not
     * the same week as one that took 6.000, and only one of the two needs looking into.
     *
     * A chargeback is money the customer's bank took back: it leaves revenue and is
     * its own series (chargebacks, chargedBack), never a refund. A reversal gives back
     * what its refund or chargeback took.
     *
     * @return array{
     *     labels: string[], revenue: float[], refunds: float[], chargebacks: float[],
     *     orders: int[], total: float, refunded: float, chargedBack: float,
     *     orderCount: int, paidOrders: int, revenueDelta: float, ordersDelta: float,
     *     others: int, noCurrency: int
     * }
     *
     * @since  1.10.0
     */
    public function money(): array
    {
        if (isset($this->memo['money'])) {
            return $this->memo['money'];
        }

        $out = [
            'labels' => $this->labels(),
            'revenue' => array_fill(0, $this->days, 0.0),
            'refunds' => array_fill(0, $this->days, 0.0),
            'chargebacks' => array_fill(0, $this->days, 0.0),
            'orders' => array_fill(0, $this->days, 0),
            'total' => 0.0,
            'refunded' => 0.0,
            'chargedBack' => 0.0,
            'orderCount' => 0,
            'paidOrders' => 0,
            'revenueDelta' => 0.0,
            'ordersDelta' => 0.0,
            'others' => 0,
            'noCurrency' => 0,
        ];

        $currency = $this->currencyId();

        if ($currency < 0) {
            return $this->memo['money'] = $out;         // no default currency: no money to report
        }

        try {
            $db = $this->db();

            // Money, bucketed by day and split by direction. `payment_type` carries the
            // direction (the amount column is always positive), so one pass answers both
            // series without a second query or a sign convention to remember.
            $rows = $db->setQuery(
                $db->getQuery(true)
                    ->select($this->bucketExpr(PaymentLedger::moneyMoment('p')) . ' AS ' . $db->quoteName('bucket'))
                    ->select($db->quoteName('p.payment_type'))
                    ->select($db->quoteName('t.payment_type', 'target_type'))
                    // Whether a reversal's target is a refund this figure counts.
                    ->select('(CASE WHEN ' . PaymentLedger::refundPredicate(alias: 't') . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('target_counted'))
                    // The currency the row's order names. The store's rows are the
                    // figures; a payment settled in another currency is only counted,
                    // as a currency that also traded. An order that names no currency
                    // row is not joined, so its money is in neither.
                    ->select($db->quoteName('o.id_currency', 'order_currency'))
                    ->select('SUM(' . $db->quoteName('p.amount') . ') AS ' . $db->quoteName('total'))
                    ->select('COUNT(DISTINCT ' . $db->quoteName('p.id_order') . ') AS ' . $db->quoteName('orders'))
                    ->from($db->quoteName('#__alfa_order_payments', 'p') . PaymentLedger::reversalTargetJoin('p', 't') . PaymentLedger::orderJoin('p', 'o', 'c'))
                    ->where(PaymentLedger::moneyMovedPredicate('p'))
                    ->where($this->windowExpr(PaymentLedger::moneyMoment('p')))
                    ->group([$db->quoteName('bucket'), $db->quoteName('p.payment_type'), $db->quoteName('t.payment_type'), $db->quoteName('target_counted'), $db->quoteName('o.id_currency')]),
            )->loadObjectList() ?: [];

            $paid = [];
            $others = [];

            foreach ($rows as $row) {
                $bucket = (int) $row->bucket;

                if ($bucket < 0 || $bucket >= $this->days) {
                    continue;                           // clock skew: a row outside its own window
                }

                // Money in another currency is never added to the store's.
                if ((int) $row->order_currency !== $currency) {
                    if ($row->payment_type === PaymentLedger::TYPE_PAYMENT) {
                        $others[(int) $row->order_currency] = true;
                    }

                    continue;
                }

                if ($row->payment_type === PaymentLedger::TYPE_REFUND) {
                    $out['refunds'][$bucket] += (float) $row->total;
                    $out['refunded'] += (float) $row->total;

                    continue;
                }

                // A refund the provider reversed did not give the money back. Only a
                // refund this figure counted is netted: one stored 'completed' was
                // never in it, so its reversal takes nothing out.
                if ($row->payment_type === PaymentLedger::TYPE_REVERSAL && $row->target_type === PaymentLedger::TYPE_REFUND) {
                    if ((int) $row->target_counted === 1) {
                        $out['refunds'][$bucket] -= (float) $row->total;
                        $out['refunded'] -= (float) $row->total;
                    }

                    continue;
                }

                // Taken back by the bank: out of takings, into its own series.
                if ($row->payment_type === PaymentLedger::TYPE_CHARGEBACK) {
                    $out['revenue'][$bucket] -= (float) $row->total;
                    $out['total'] -= (float) $row->total;
                    $out['chargebacks'][$bucket] += (float) $row->total;
                    $out['chargedBack'] += (float) $row->total;

                    continue;
                }

                // A won dispute: the money is back in takings.
                if ($row->payment_type === PaymentLedger::TYPE_REVERSAL && $row->target_type === PaymentLedger::TYPE_CHARGEBACK) {
                    $out['revenue'][$bucket] += (float) $row->total;
                    $out['total'] += (float) $row->total;
                    $out['chargebacks'][$bucket] -= (float) $row->total;
                    $out['chargedBack'] -= (float) $row->total;

                    continue;
                }

                if ($row->payment_type === PaymentLedger::TYPE_PAYMENT) {
                    $out['revenue'][$bucket] += (float) $row->total;
                    $out['total'] += (float) $row->total;
                    $paid[$bucket] = ($paid[$bucket] ?? 0) + (int) $row->orders;
                }
            }

            $out['paidOrders'] = array_sum($paid);

            // Orders placed, from the orders table: a different fact from money taken,
            // and the one a merchant counts when they say "how many orders today".
            $placed = $db->setQuery(
                $db->getQuery(true)
                    ->select($this->bucket('o.created') . ' AS ' . $db->quoteName('bucket'))
                    ->select('COUNT(*) AS ' . $db->quoteName('n'))
                    ->from($db->quoteName('#__alfa_orders', 'o'))
                    ->where($db->quoteName('o.state') . ' <> -2')
                    ->where($this->window('o.created'))
                    ->group($db->quoteName('bucket')),
            )->loadObjectList() ?: [];

            foreach ($placed as $row) {
                $bucket = (int) $row->bucket;

                if ($bucket >= 0 && $bucket < $this->days) {
                    $out['orders'][$bucket] = (int) $row->n;
                    $out['orderCount'] += (int) $row->n;
                }
            }

            $previous = $this->previousPeriod($currency);
            $out['revenueDelta'] = $this->delta($out['total'], $previous['revenue']);
            $out['ordersDelta'] = $this->delta((float) $out['orderCount'], (float) $previous['orders']);
            $out['others'] = \count($others);
            $out['noCurrency'] = $this->ordersWithoutCurrency();
        } catch (Throwable) {
            // Reported as far as it got; a card draws what it has.
        }

        return $this->memo['money'] = $out;
    }

    /**
     * What sold: units and money, per product, over the window.
     *
     * THE NAME IS THE PRODUCT'S CATALOGUE NAME, in the admin's language: every card
     * links a row to that product's edit screen, so the label must name the record the
     * link opens. The name a line froze is a sale's name, not the product's: two
     * products that last sold under one line name read as one, and a row read "Terrain
     * item 2" while it opened "Nordic Repair Shampoo 8". Only a product the catalogue
     * no longer holds, or holds without a usable name, is named from its lines, since
     * nothing else knows it. Cancelled
     * orders are excluded: a cancelled sale is not a sale.
     *
     * @param int $limit How many rows.
     *
     * @return array<int, array{id: int, name: string, quantity: int, revenue: float}>
     *
     * @since  1.10.0
     */
    public function topProducts(int $limit = 8): array
    {
        if (isset($this->memo['top' . $limit])) {
            return $this->memo['top' . $limit];
        }

        $currency = $this->currencyId();

        if ($currency < 0) {
            return [];
        }

        try {
            $db = $this->db();

            // THE AGGREGATE CARRIES NUMBERS ONLY, AND THE NAMES ARE FETCHED AFTER.
            //
            // `name` is a longtext holding a `{lang: text}` map. Pulling it through the
            // GROUP BY (even as MAX()) makes the temporary table this query already
            // needs a BLOB one, which MySQL spills to disk instead of keeping in memory.
            // At demo scale (30k lines) that is survivable; over a real catalogue's
            // order history it is the difference between a dashboard and a timeout. Two
            // statements, the second of which reads exactly `$limit` rows, cost less
            // than one that drags a text column through a filesort.
            $query = $db->getQuery(true)
                ->select($db->quoteName('oi.id_item'))
                ->select('SUM(' . $db->quoteName('oi.quantity') . ') AS ' . $db->quoteName('quantity'))
                ->select('SUM(' . $db->quoteName('oi.total_price_tax_incl') . ') AS ' . $db->quoteName('revenue'))
                ->from($db->quoteName('#__alfa_order_items', 'oi'))
                ->join('INNER', $db->quoteName('#__alfa_orders', 'o'), 'o.id = oi.id_order')
                ->where($db->quoteName('o.state') . ' <> -2')
                ->where($db->quoteName('o.id_currency') . ' = :currency')
                ->where($this->window('o.created'))
                ->group($db->quoteName('oi.id_item'))
                ->order($db->quoteName('revenue') . ' DESC')
                ->setLimit($limit)
                ->bind(':currency', $currency, ParameterType::INTEGER);

            $this->excludeCancelled($query, 'o');

            $rows = $db->setQuery($query)->loadObjectList() ?: [];

            if ($rows === []) {
                return $this->memo['top' . $limit] = [];
            }

            $ids = array_map(static fn (object $r): int => (int) $r->id_item, $rows);
            $catalogue = $this->catalogueNames($ids);
            $frozen = $this->frozenNames(array_values(array_diff($ids, array_keys($catalogue))));
            $out = [];

            foreach ($rows as $row) {
                $out[] = [
                    'id' => (int) $row->id_item,
                    'name' => (string) ($catalogue[(int) $row->id_item] ?? $frozen[(int) $row->id_item] ?? ''),
                    'quantity' => (int) $row->quantity,
                    'revenue' => (float) $row->revenue,
                ];
            }

            return $this->memo['top' . $limit] = $out;
        } catch (Throwable) {
            return $this->memo['top' . $limit] = [];
        }
    }

    /**
     * The catalogue name of each of these products, in the admin's language, keyed by
     * the products the catalogue still holds AND can name.
     *
     * The name the product's edit screen shows, resolved the way the items list
     * resolves it: the current language, then the default one. A product that is gone
     * is absent from the answer, which is how the caller knows to name it from its
     * lines; so is one held with no usable name in either language, because a blank
     * label is drawn as an unlinked dash and its lines are then the only name it has.
     * Reads exactly one row per product, by primary key.
     *
     * @param int[] $ids Product ids.
     *
     * @return array<int, string> Product id => catalogue name.
     *
     * @since  1.14.0
     */
    private function catalogueNames(array $ids): array
    {
        $ids = array_filter(array_map('intval', $ids));

        if ($ids === []) {
            return [];
        }

        $records = MultilingualHelper::getRecordsByIds(
            db: $this->db(),
            ids: $ids,
            table: '#__alfa_items',
            langTableBase: '#__alfa_items',
            langPrimaryColumn: 'id_item',
        );

        return array_filter(
            array_map(static fn (array $record): string => (string) ($record['name'] ?? ''), $records),
            static fn (string $name): bool => trim($name) !== '',
        );
    }

    /**
     * The name each of these products was SOLD under, one row per product.
     *
     * The snapshot the line froze: the only name a product the catalogue no longer
     * holds still has, so it still answers for what it sold. Read after the aggregate
     * so the text column never touches a GROUP BY.
     *
     * @param int[] $ids Product ids.
     *
     * @return array<int, string> Product id => resolved name.
     *
     * @since  1.10.0
     */
    private function frozenNames(array $ids): array
    {
        $ids = array_filter(array_map('intval', $ids));

        if ($ids === []) {
            return [];
        }

        $db = $this->db();
        $out = [];

        // The most RECENT line wins: the name the product last sold under, which is the
        // one a merchant will recognise.
        //
        // The inner aggregate reads ID COLUMNS ONLY, over `idx_id_item`, and returns one
        // row per product; the outer join then fetches exactly that many names by
        // primary key. Selecting the names directly and de-duplicating in PHP would have
        // dragged every line these products ever appeared on across the wire: the same
        // mistake as putting the text column in the GROUP BY, one step further along.
        $rows = $db->setQuery(
            'SELECT oi.' . $db->quoteName('id_item') . ', oi.' . $db->quoteName('name')
            . ' FROM ' . $db->quoteName('#__alfa_order_items', 'oi')
            . ' INNER JOIN (SELECT ' . $db->quoteName('id_item') . ', MAX(' . $db->quoteName('id') . ') AS '
            . $db->quoteName('last_id')
            . ' FROM ' . $db->quoteName('#__alfa_order_items')
            . ' WHERE ' . $db->quoteName('id_item') . ' IN (' . implode(',', $ids) . ')'
            . ' GROUP BY ' . $db->quoteName('id_item') . ') AS ' . $db->quoteName('m')
            . ' ON m.last_id = oi.' . $db->quoteName('id'),
        )->loadObjectList() ?: [];

        foreach ($rows as $row) {
            // Frozen as a {lang:text} JSON map, exactly as the order screen reads it.
            $out[(int) $row->id_item] = (string) MultilingualHelper::resolveLangMap((string) $row->name);
        }

        return $out;
    }

    /**
     * What the promotions cost and what they carried.
     *
     * The question nobody can answer without this: a rule that gave away 4.000 on orders
     * worth 5.000 is not the same rule as one that gave away 400 on orders worth 30.000,
     * and both look like "a discount" everywhere else in the admin. Shipping rules are
     * included but marked by their class: a free-shipping rule reduces shipping, it is
     * not a discount, and averaging the two would misprice both.
     *
     * @param int $limit How many rules.
     *
     * @return array<int, array{id: int, name: string, code: string, class: string,
     *                          orders: int, discount: float, revenue: float}>
     *
     * @since  1.10.0
     */
    public function promoImpact(int $limit = 8): array
    {
        if (isset($this->memo['promo' . $limit])) {
            return $this->memo['promo' . $limit];
        }

        $currency = $this->currencyId();

        if ($currency < 0) {
            return [];
        }

        try {
            $db = $this->db();
            $query = $db->getQuery(true)
                ->select($db->quoteName('r.id_cart_rule', 'id'))
                ->select('MAX(' . $db->quoteName('r.name') . ') AS ' . $db->quoteName('name'))
                ->select('MAX(COALESCE(' . $db->quoteName('r.code') . ', ' . $db->quote('') . ')) AS ' . $db->quoteName('code'))
                ->select('MAX(' . $db->quoteName('r.discount_class') . ') AS ' . $db->quoteName('discount_class'))
                ->select('COUNT(DISTINCT ' . $db->quoteName('r.id_order') . ') AS ' . $db->quoteName('orders'))
                ->select('SUM(' . $db->quoteName('r.value_tax_incl') . ') AS ' . $db->quoteName('discount'))
                ->from($db->quoteName('#__alfa_order_cart_rule', 'r'))
                ->join('INNER', $db->quoteName('#__alfa_orders', 'o'), 'o.id = r.id_order')
                ->where($db->quoteName('r.deleted') . ' = 0')
                ->where($db->quoteName('o.state') . ' <> -2')
                ->where($db->quoteName('o.id_currency') . ' = :currency')
                ->where($this->window('o.created'))
                ->group($db->quoteName('r.id_cart_rule'))
                ->order($db->quoteName('discount') . ' DESC')
                ->setLimit($limit)
                ->bind(':currency', $currency, ParameterType::INTEGER);

            $this->excludeCancelled($query, 'o');

            $rows = $db->setQuery($query)->loadObjectList() ?: [];

            if ($rows === []) {
                return [];
            }

            // What those orders were worth, so the giveaway can be read against the take.
            // A second pass keyed by rule, because summing the lines in the query above
            // would multiply every order total by the number of rules on it.
            $revenue = $this->revenueByRule(array_map(static fn (object $r): int => (int) $r->id, $rows), $currency);
            $out = [];

            foreach ($rows as $row) {
                $out[] = [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'code' => (string) $row->code,
                    'class' => (string) $row->discount_class,
                    'orders' => (int) $row->orders,
                    'discount' => (float) $row->discount,
                    'revenue' => (float) ($revenue[(int) $row->id] ?? 0.0),
                ];
            }

            return $this->memo['promo' . $limit] = $out;
        } catch (Throwable) {
            return $this->memo['promo' . $limit] = [];
        }
    }

    /**
     * WHAT A MERCHANT SHOULD DO SOMETHING ABOUT, ranked by the money behind it.
     *
     * Two counts were not enough. A dashboard that says "10 payments failed" tells you
     * a number; one that says "7 of your 10 failures were on the same gateway" tells
     * you where to look, and those are different products. Every finding here either
     * names the money at risk or names the thing responsible, and several of them are
     * JOINS no other card on this screen makes, which is the whole reason they belong
     * to one method rather than being scattered across seven cards.
     *
     * NOT A DUPLICATE OF THE NOTIFICATION BELL. Every `NotificationHelper::push()`
     * producer in the codebase is event-driven and per-incident: a refund that could
     * not be reconciled, a stock movement that failed, a subscription that lapsed.
     * Nothing produces a STANDING CONDITION: "money that has been sitting unshipped
     * since Tuesday" is not an event, it is a state, and a state has to be looked for.
     * The bell is the incident log; this is the current reading.
     *
     * Every finding is independent: one that cannot be produced is simply absent, and
     * a shop with nothing wrong gets an empty array and no card at all.
     *
     * @param int $staleHours How long something may sit before it counts as sitting.
     *
     * @return array<int, array{key: string, count: int, money: float, extra: array}>
     *                                                                                Ranked, money first.
     *
     * @since  1.10.0
     */
    public function attention(int $staleHours = self::STALE_HOURS): array
    {
        $findings = [];

        foreach ([
            fn (): ?array => $this->unfulfilled($staleHours),
            fn (): ?array => $this->unpaid($staleHours),
            fn (): ?array => $this->failedPayments(),
            fn (): ?array => $this->bestSellerLow(),
            fn (): ?array => $this->outOfStock(),
            fn (): ?array => $this->promoBleed(),
            fn (): ?array => $this->refundsUp(),
        ] as $signal) {
            try {
                $finding = $signal();
            } catch (Throwable) {
                continue;                               // one bad signal is not a broken card
            }

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        // MONEY ALREADY HELD OUTRANKS MONEY NEVER HAD, and only then does the size of
        // the figure decide.
        //
        // Sorting on the euro figure alone put "3.330 orders were never paid, 13 M"
        // above "68 orders are paid and unshipped, 2.500": arithmetically right and
        // exactly backwards as advice. The first is a conversion problem; the second is
        // customers who have paid and received nothing, which is the one that becomes a
        // chargeback and a review. A tier says which KIND of trouble it is; the money
        // then ranks within the kind.
        $tier = static fn (array $f): int => match ($f['key']) {
            'unfulfilled', 'promo' => 2,   // the shop has the money, or gave it away
            'unpaid', 'gateway', 'failed', 'refunds' => 1,   // money in play
            default => 0,   // no money attached yet
        };

        usort($findings, static fn (array $a, array $b): int
            => [$tier($b), $b['money'], $b['count']] <=> [$tier($a), $a['money'], $a['count']]);

        return $findings;
    }

    /**
     * PAID AND NOT SHIPPED: the one worth waking someone for.
     *
     * The shop has the money and the customer has nothing. Not merely "old": `modified`
     * moves on every status change, so an order somebody is working on never appears
     * here however long it has been open.
     *
     * @param int $hours How long it may sit.
     *
     *
     * @since  1.10.0
     */
    private function unfulfilled(int $hours): ?array
    {
        $currency = $this->currencyId();

        if ($currency < 0) {
            return null;
        }

        $db = $this->db();
        $cutoff = $db->quote($this->hoursAgo($hours));
        $skip = $this->statusList(array_merge(
            OrderStatusHelper::getCompletedIds(),
            OrderStatusHelper::getCancelledIds(),
        ));

        // Grouped per order first: an order with three payment rows is ONE unshipped
        // order carrying the sum of them, not three.
        // EVERY SUCH ORDER IS COUNTED, WHATEVER IT WAS PAID IN: the count is what the
        // orders list will show when the tile is followed, and a list is not per
        // currency. Only the money is, and a payment is money in its ORDER's
        // currency: it is summed for the orders in the shop's currency, the orders
        // in another are counted, and so are the orders that name none, so the
        // sentence can say both. An order that names no currency is never valued
        // in the shop's and is not an order in another one.
        $row = $db->setQuery(
            'SELECT COUNT(*) AS n,'
            . ' COALESCE(SUM(CASE WHEN t.cur = ' . (int) $currency . ' THEN t.paid ELSE 0 END), 0) AS total,'
            . ' COALESCE(SUM(CASE WHEN t.known = 1 AND t.cur <> ' . (int) $currency . ' THEN 1 ELSE 0 END), 0) AS others,'
            . ' COALESCE(SUM(CASE WHEN t.known = 0 THEN 1 ELSE 0 END), 0) AS nocurrency FROM ('
            . ' SELECT o.id, o.id_currency AS cur, SUM(p.amount) AS paid,'
            . ' (SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_currencies', 'c') . ' WHERE c.id = o.id_currency) AS known'
            . ' FROM ' . $db->quoteName('#__alfa_orders', 'o')
            . ' INNER JOIN ' . $db->quoteName('#__alfa_order_payments', 'p') . ' ON p.id_order = o.id'
            . ' WHERE ' . PaymentLedger::settledPaymentPredicate('p')
            . ' AND o.state <> -2'
            . ' AND o.modified < ' . $cutoff
            . $skip
            . ' GROUP BY o.id, o.id_currency) AS t',
        )->loadObject();

        $count = (int) ($row->n ?? 0);

        return $count > 0
            ? ['key' => 'unfulfilled', 'count' => $count, 'money' => (float) $row->total, 'extra' => ['hours' => $hours, 'others' => (int) $row->others, 'noCurrency' => (int) $row->nocurrency]]
            : null;
    }

    /**
     * PLACED AND NEVER PAID: the funnel's one actionable gap, as money.
     *
     * The order total comes from its own lines, because an order carries no total
     * column: what it is worth is what was put in it.
     *
     * @param int $hours How long it may sit before it counts as abandoned.
     *
     *
     * @since  1.10.0
     */
    private function unpaid(int $hours): ?array
    {
        $currency = $this->currencyId();

        if ($currency < 0) {
            return null;
        }

        $db = $this->db();
        $skip = $this->statusList(OrderStatusHelper::getCancelledIds());

        // Counted in every currency, valued in the shop's; see unfulfilled().
        // An order that names no currency is counted too, and said to have
        // none: it is not an order in another currency, and it is never
        // valued in the shop's.
        $row = $db->setQuery(
            'SELECT COUNT(*) AS n,'
            . ' COALESCE(SUM(CASE WHEN t.cur = ' . (int) $currency . ' THEN t.value ELSE 0 END), 0) AS total,'
            . ' COALESCE(SUM(CASE WHEN t.known = 1 AND t.cur <> ' . (int) $currency . ' THEN 1 ELSE 0 END), 0) AS others,'
            . ' COALESCE(SUM(CASE WHEN t.known = 0 THEN 1 ELSE 0 END), 0) AS nocurrency FROM ('
            . ' SELECT o.id, o.id_currency AS cur, COALESCE(SUM(oi.total_price_tax_incl), 0) AS value,'
            . ' (SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_currencies', 'c') . ' WHERE c.id = o.id_currency) AS known'
            . ' FROM ' . $db->quoteName('#__alfa_orders', 'o')
            // LEFT: an unpaid order with no lines is still an unpaid order the list shows.
            . ' LEFT JOIN ' . $db->quoteName('#__alfa_order_items', 'oi') . ' ON oi.id_order = o.id'
            . ' WHERE o.state <> -2'
            . ' AND ' . $this->window('o.created')
            . ' AND o.created < ' . $db->quote($this->hoursAgo($hours))
            . $skip
            // A cancelled order is not an unpaid one, and neither is a paid one.
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_order_payments', 'p')
            . ' WHERE p.id_order = o.id AND ' . PaymentLedger::settledPaymentPredicate('p') . ')'
            . ' GROUP BY o.id, o.id_currency) AS t',
        )->loadObject();

        $count = (int) ($row->n ?? 0);

        return $count > 0
            ? ['key' => 'unpaid', 'count' => $count, 'money' => (float) $row->total, 'extra' => ['hours' => $hours, 'others' => (int) $row->others, 'noCurrency' => (int) $row->nocurrency]]
            : null;
    }

    /**
     * FAILURES, AND WHETHER THEY HAVE A CULPRIT.
     *
     * Ten failures spread over three gateways is customers mistyping cards. Ten on ONE
     * gateway is that gateway, and the merchant should be told which: that difference
     * is the whole value of this signal over a bare count.
     *
     * Failed attempts are dated by `added`: a payment that failed was never processed,
     * so `processed_at` is null and dating them by it would report zero failures for
     * ever.
     *
     *
     * @since  1.10.0
     */
    private function failedPayments(): ?array
    {
        $currency = $this->currencyId();

        if ($currency < 0) {
            return null;
        }

        $db = $this->db();

        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('p.payment_method_type', 'type'))
                ->select('MAX(' . $db->quoteName('p.payment_method') . ') AS ' . $db->quoteName('name'))
                ->select('MAX(' . $db->quoteName('p.id_payment_method') . ') AS ' . $db->quoteName('method_id'))
                ->select('COUNT(*) AS ' . $db->quoteName('n'))
                ->select('SUM(' . $db->quoteName('p.amount') . ') AS ' . $db->quoteName('total'))
                ->from($db->quoteName('#__alfa_order_payments', 'p') . PaymentLedger::orderJoin('p', 'o'))
                ->where(PaymentLedger::failedAttemptPredicate('p'))
                ->where($db->quoteName('o.id_currency') . ' = :currency')
                ->where($this->window('p.added'))
                ->group($db->quoteName('p.payment_method_type'))
                ->order('COUNT(*) DESC')
                ->bind(':currency', $currency, ParameterType::INTEGER),
        )->loadObjectList() ?: [];

        if ($rows === []) {
            return null;
        }

        $total = 0;
        $money = 0.0;

        foreach ($rows as $row) {
            $total += (int) $row->n;
            $money += (float) $row->total;
        }

        $worst = $rows[0];
        $share = $total > 0 ? (int) round(100 * (int) $worst->n / $total) : 0;

        // Concentration only means something when there was somewhere else to go and
        // enough attempts for a share to be a share rather than an accident.
        $concentrated = \count($rows) > 1 && $total >= 3 && $share >= 60;

        return [
            'key' => $concentrated ? 'gateway' : 'failed',
            'count' => $concentrated ? (int) $worst->n : $total,
            'money' => $money,
            'extra' => [
                'gateway' => (string) ($worst->name ?: $worst->type),
                'method_id' => (int) $worst->method_id,
                'share' => $share,
                'total' => $total,
            ],
        ];
    }

    /**
     * A PRODUCT THAT EARNS, ABOUT TO STOP.
     *
     * The join nothing else on the screen makes: the best-sellers list against the
     * stock those same products have left. "Low stock: 22 units" is a housekeeping
     * number; "your best seller has 3 left" is a purchase order.
     *
     *
     * @since  1.10.0
     */
    private function bestSellerLow(): ?array
    {
        // The same list the best-sellers card asked for, so this costs nothing.
        $top = $this->topProducts(self::TOP_LIMIT);

        if ($top === []) {
            return null;
        }

        $db = $this->db();
        $ids = array_filter(array_map(static fn (array $r): int => $r['id'], $top));
        $global = (float) ComponentHelper::getParams('com_alfa')->get('stock_low', 0);

        if ($ids === []) {
            return null;
        }

        $threshold = 'COALESCE(NULLIF(i.' . $db->quoteName('stock_low') . ', 0), ' . $global . ')';

        $rows = $db->setQuery(
            'SELECT i.' . $db->quoteName('id') . ', i.' . $db->quoteName('available_quantity') . ' AS qty'
            . ' FROM ' . $db->quoteName('#__alfa_items', 'i')
            . ' WHERE i.' . $db->quoteName('state') . ' = 1'
            . ' AND i.' . $db->quoteName('manage_stock') . ' = 1'
            . ' AND ' . $threshold . ' > 0'
            . ' AND i.' . $db->quoteName('available_quantity') . ' <= ' . $threshold
            . ' AND i.' . $db->quoteName('id') . ' IN (' . implode(',', $ids) . ')',
        )->loadObjectList('id') ?: [];

        if ($rows === []) {
            return null;
        }

        // The best-selling one of them, because the list arrives revenue-first.
        foreach ($top as $product) {
            if (isset($rows[$product['id']])) {
                return [
                    'key' => 'bestseller',
                    'count' => (int) $rows[$product['id']]->qty,
                    // Ranked by what it EARNED, not by what is left: the money at risk
                    // is the revenue that stops when the shelf empties.
                    'money' => $product['revenue'],
                    'extra' => ['name' => $product['name'], 'id' => $product['id'], 'more' => \count($rows) - 1],
                ];
            }
        }

        return null;
    }

    /**
     * PUBLISHED AND UNBUYABLE: advertising what cannot be sold.
     *
     * Counted the same way the low-stock tile counts: a simple product answers for
     * itself, a product with combinations lets its units answer.
     *
     *
     * @since  1.10.0
     */
    private function outOfStock(): ?array
    {
        $db = $this->db();

        // PRODUCTS, not units: the figure has to be the total of the list it opens.
        // A product without variants is out when it is tracked and at zero; a product
        // with variants is out when ANY published, tracked variant is at zero, because
        // that is what needs restocking. Tracking is judged per unit, the variant's own
        // flag falling back to the item's, and a quantity never counted (NULL) is zero,
        // exactly as the storefront judges it. Archived variants are not variants here:
        // a product whose variants are all archived is judged as a product without any.
        $units = $db->quoteName('#__alfa_item_combinations', 'c');
        $owned = 'c.' . $db->quoteName('item_id') . ' = i.' . $db->quoteName('id');
        $tracked = 'COALESCE(c.' . $db->quoteName('manage_stock') . ', i.' . $db->quoteName('manage_stock') . ', 0) = 1';

        $total = (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_items', 'i')
            . ' WHERE i.' . $db->quoteName('state') . ' = 1'
            . ' AND ((i.' . $db->quoteName('manage_stock') . ' = 1'
            . ' AND COALESCE(i.' . $db->quoteName('available_quantity') . ', 0) <= 0'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $units . ' WHERE ' . $owned
            . ' AND ' . CombinationState::currentSql(column: 'c.' . $db->quoteName('state')) . '))'
            . ' OR EXISTS (SELECT 1 FROM ' . $units . ' WHERE ' . $owned
            . ' AND c.' . $db->quoteName('state') . ' = 1 AND ' . $tracked
            . ' AND COALESCE(c.' . $db->quoteName('available_quantity') . ', 0) <= 0))',
        )->loadResult();

        return $total > 0
            ? ['key' => 'outofstock', 'count' => $total, 'money' => 0.0, 'extra' => []]
            : null;
    }

    /**
     * A PROMOTION THAT COSTS MORE THAN IT CARRIES.
     *
     * Shipping rules are excluded on purpose: a free-shipping rule reduces shipping,
     * it is not a discount, and measuring it against order revenue would condemn every
     * one of them. Product and order rules are the ones that can actually bleed.
     *
     *
     * @since  1.10.0
     */
    private function promoBleed(): ?array
    {
        foreach ($this->promoImpact(self::PROMO_LIMIT) as $rule) {
            if ($rule['class'] === 'shipping' || $rule['discount'] <= 0.0) {
                continue;
            }

            // Given away at least as much as it brought in. Not a ratio to be tuned:
            // this is the point at which the rule stopped being a promotion.
            if ($rule['discount'] >= $rule['revenue']) {
                return [
                    'key' => 'promo',
                    'count' => $rule['orders'],
                    'money' => $rule['discount'],
                    'extra' => [
                        'name' => $rule['name'],
                        'id' => $rule['id'],
                        'revenue' => $rule['revenue'],
                    ],
                ];
            }
        }

        return null;
    }

    /**
     * REFUNDS RISING: something changed, and the merchant would want to know.
     *
     * A refund figure alone means nothing without last month's beside it, which is why
     * this signal is a comparison rather than a total.
     *
     *
     * @since  1.10.0
     */
    private function refundsUp(): ?array
    {
        $currency = $this->currencyId();

        if ($currency < 0) {
            return null;
        }

        $now = $this->money()['refunded'];
        $previous = $this->previousPeriod($currency)['refunds'];

        if ($now <= 0.0) {
            return null;
        }

        $delta = $this->delta($now, $previous);

        // A quarter up is noise on a small shop; half again is a change.
        return $delta >= 50.0
            ? ['key' => 'refunds', 'count' => (int) round($delta), 'money' => $now, 'extra' => ['previous' => $previous]]
            : null;
    }

    /**
     * An instant this many hours before now, as stored UTC.
     *
     * @param int $hours How far back.
     *
     *
     * @since  1.10.0
     */
    private function hoursAgo(int $hours): string
    {
        return self::staleBefore($hours);
    }

    /**
     * The UTC moment before which an untouched order counts as sitting.
     *
     * @param int $hours How long it may sit.
     *
     * @return string SQL datetime.
     *
     * @since  1.10.0
     */
    public static function staleBefore(int $hours = self::STALE_HOURS): string
    {
        return (new Date('now'))->modify('-' . max(1, $hours) . ' hours')->toSql();
    }

    /**
     * A ready-made `AND ... NOT IN (...)` for a list of status ids, or '' when empty.
     *
     * Ids come from OrderStatusHelper, never from a request, so they are cast and
     * inlined rather than bound: these clauses are glued into hand-built SQL where a
     * placeholder would have to be threaded through by hand.
     *
     * @param int[] $ids Statuses to exclude.
     *
     *
     * @since  1.10.0
     */
    private function statusList(array $ids): string
    {
        $ids = array_filter(array_map('intval', $ids));

        return $ids === []
            ? ''
            : ' AND COALESCE(o.' . $this->db()->quoteName('id_order_status') . ', 0) NOT IN (' . implode(',', $ids) . ')';
    }

    /**
     * How many days every question here covers.
     *
     *
     * @since  1.10.0
     */
    public function days(): int
    {
        return $this->days;
    }

    /**
     * The store's currency, or null when none is configured yet.
     *
     *
     * @since  1.10.0
     */
    public function currency(): ?Currency
    {
        try {
            return Currency::getDefault();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * One label per day in the window, oldest first: the x-axis, dense by construction.
     *
     * @return string[]
     *
     * @since  1.10.0
     */
    public function labels(): array
    {
        $labels = [];
        $day = $this->windowStartLocal();

        for ($i = 0; $i < $this->days; $i++) {
            $labels[] = $day->format('d/m');
            $day = $day->modify('+1 day');
        }

        return $labels;
    }

    /**
     * @since  1.10.0
     */
    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    /**
     * The store currency's row id, or -1 when none is configured. Public for the same
     * reason {@see self::window()} is: a collaborator filters money the way core does.
     *
     *
     * @since  1.10.0
     */
    public function currencyId(): int
    {
        if ($this->currencyId === null) {
            try {
                $this->currencyId = Currency::getDefault()->getId();
            } catch (Throwable) {
                $this->currencyId = -1;
            }
        }

        return $this->currencyId;
    }

    /**
     * The site's current offset from UTC, in seconds.
     *
     *
     * @since  1.10.0
     */
    private function offsetSeconds(): int
    {
        try {
            $zone = Factory::getApplication()->get('offset', 'UTC');

            return (new DateTime('now', new DateTimeZone($zone)))->getOffset();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Midnight, in the merchant's timezone, `days - 1` days ago, as a local Date.
     *
     *
     * @since  1.10.0
     */
    private function windowStartLocal(): DateTimeImmutable
    {
        return $this->localStart;
    }

    /**
     * That same instant, as the UTC datetime the rows are stored in.
     *
     *
     * @since  1.10.0
     */
    private function start(): string
    {
        return $this->start ??= $this->localStart
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * The window's EXCLUSIVE end, as the UTC datetime the rows are stored in.
     *
     *
     * @since  1.10.0
     */
    private function end(): string
    {
        return $this->end ??= $this->localEnd
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * The SQL that keeps a row inside the window: BOTH bounds, always.
     *
     * The first cut only had a lower bound, because "the last 30 days" has no upper one
     * worth writing. A report on "previous month" does, and a query that forgot it would
     * quietly include this month too.
     *
     * PUBLIC ON PURPOSE: the Analytics plugin's own projections ask this window for
     * their predicate instead of re-deriving the bounds: one place decides what "in
     * the window" means, and a collaborator cannot get one bound right and one wrong.
     *
     * @param string $column Column, alias.path form.
     *
     *
     * @since  1.10.0
     */
    public function window(string $column): string
    {
        return $this->windowExpr($this->db()->quoteName($column));
    }

    /**
     * The same predicate over an SQL expression rather than a bare column.
     *
     * {@see self::window()} is public surface that collaborators call with a column
     * name, so it cannot start accepting expressions. The money query needs one:
     * `COALESCE(processed_at, added)`, because a refund row has no `processed_at`.
     *
     * @param string $expression Already-quoted SQL naming a datetime.
     *
     *
     * @since  1.10.0
     */
    public function windowExpr(string $expression): string
    {
        $db = $this->db();

        return $expression . ' >= ' . $db->quote($this->start())
            . ' AND ' . $expression . ' < ' . $db->quote($this->end());
    }

    /**
     * The SQL that puts a row in its day.
     *
     * Distance from the window's first instant, in whole days, NOT `DATE(col)`, which
     * buckets by the UTC calendar and would file part of every local evening under the
     * wrong day for any shop that is not on UTC.
     *
     * @param string $column Column expression, already an alias.path form.
     *
     *
     * @since  1.10.0
     */
    private function bucket(string $column): string
    {
        return $this->bucketExpr($this->db()->quoteName($column));
    }

    /**
     * The same bucketing over an SQL expression. See {@see self::windowExpr()}.
     *
     * @param string $expression Already-quoted SQL naming a datetime.
     *
     *
     * @since  1.10.0
     */
    private function bucketExpr(string $expression): string
    {
        $db = $this->db();

        // The instant is QUOTED, not bound: it is a value this class computed, and a
        // named placeholder appearing both here and in the WHERE of the same statement
        // is only portable when PDO emulates prepares. The analytics repository quotes
        // its window for the same reason.
        return 'FLOOR(TIMESTAMPDIFF(SECOND, ' . $db->quote($this->start())
            . ', ' . $expression . ') / ' . self::DAY . ')';
    }

    /**
     * The same window, one window earlier: what "up 12%" is up against.
     *
     * @param int $currency Store currency row id.
     *
     * @return array{revenue: float, orders: int, refunds: float}
     *
     * @since  1.10.0
     */
    private function previousPeriod(int $currency): array
    {
        if (isset($this->memo['previous'])) {
            return $this->memo['previous'];
        }

        $out = ['revenue' => 0.0, 'orders' => 0, 'refunds' => 0.0];

        try {
            $db = $this->db();
            $end = $this->start();
            // The same LENGTH of time immediately before: for "previous month" that is
            // the month before it, whatever its day count; "-N days" would have compared
            // a 31-day March with 31 days of February-and-March.
            $span = $this->localEnd->getTimestamp() - $this->localStart->getTimestamp();
            $begin = $this->localStart
                ->sub(new DateInterval('PT' . max(1, $span) . 'S'))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');

            // Takings: settled payments, less chargebacks, plus won disputes.
            $out['revenue'] = (float) $db->setQuery(
                $db->getQuery(true)
                    ->select('COALESCE(SUM(' . PaymentLedger::takingsAmount('p') . '), 0)')
                    ->from($db->quoteName('#__alfa_order_payments', 'p') . PaymentLedger::reversalTargetJoin('p', 't') . PaymentLedger::orderJoin('p', 'o'))
                    ->where(PaymentLedger::takingsPredicate('p', 't'))
                    ->where($db->quoteName('o.id_currency') . ' = :currency')
                    ->where($db->quoteName('p.processed_at') . ' >= ' . $db->quote($begin))
                    ->where($db->quoteName('p.processed_at') . ' < ' . $db->quote($end))
                    ->bind(':currency', $currency, ParameterType::INTEGER),
            )->loadResult();

            $out['orders'] = (int) $db->setQuery(
                $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__alfa_orders'))
                    ->where($db->quoteName('state') . ' <> -2')
                    ->where($db->quoteName('created') . ' >= ' . $db->quote($begin))
                    ->where($db->quoteName('created') . ' < ' . $db->quote($end)),
            )->loadResult();

            // Refunds net of the ones the provider reversed.
            $out['refunds'] = (float) $db->setQuery(
                $db->getQuery(true)
                    ->select('COALESCE(SUM(' . PaymentLedger::refundFlowAmount('p') . '), 0)')
                    ->from($db->quoteName('#__alfa_order_payments', 'p') . PaymentLedger::reversalTargetJoin('p', 't') . PaymentLedger::orderJoin('p', 'o'))
                    ->where(PaymentLedger::refundFlowPredicate('p', 't'))
                    ->where($db->quoteName('o.id_currency') . ' = :currency2')
                    ->where(PaymentLedger::moneyMoment('p') . ' >= ' . $db->quote($begin))
                    ->where(PaymentLedger::moneyMoment('p') . ' < ' . $db->quote($end))
                    ->bind(':currency2', $currency, ParameterType::INTEGER),
            )->loadResult();
        } catch (Throwable) {
            // No comparison: the card simply shows no delta.
        }

        return $this->memo['previous'] = $out;
    }

    /**
     * Percentage movement, with the one case arithmetic cannot express.
     *
     * Growth from zero is not "infinite percent" and is not 0% either: it is "new", and
     * the honest way to say that on a badge is 100%, once, rather than a symbol nobody
     * can read at 11px.
     *
     * @param float $now This window.
     * @param float $previous The one before it.
     *
     *
     * @since  1.10.0
     */
    private function delta(float $now, float $previous): float
    {
        if ($previous <= 0.0) {
            return $now > 0.0 ? 100.0 : 0.0;
        }

        return round(100 * ($now - $previous) / $previous, 1);
    }

    /**
     * How many orders placed in the window name no currency: what no figure
     * here can value.
     *
     * An order's money is counted under the currency it names. An order that
     * names none, or one that has no row, belongs to no currency's figures.
     * It is counted and said, and never added to the store's.
     *
     *
     * @since  1.14.0
     */
    private function ordersWithoutCurrency(): int
    {
        try {
            $db = $this->db();

            return (int) $db->setQuery(
                'SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_orders', 'o')
                . ' WHERE o.state <> -2'
                . ' AND ' . $this->window('o.created')
                . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_currencies', 'c') . ' WHERE c.id = o.id_currency)',
            )->loadResult();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * What the orders carrying each of these rules were worth.
     *
     * @param int[] $ruleIds Rules to answer for.
     * @param int $currency Store currency row id.
     *
     * @return array<int, float> Rule id => revenue.
     *
     * @since  1.10.0
     */
    private function revenueByRule(array $ruleIds, int $currency): array
    {
        if ($ruleIds === []) {
            return [];
        }

        try {
            $db = $this->db();

            // ONE ROW PER (rule, order) FIRST, then the lines of those orders.
            //
            // Joining the rule rows straight to the item lines would multiply an order's
            // total by however many rule rows it carries: an order split across two
            // invoices holds the same rule twice, and its revenue would be counted twice
            // for that rule. DISTINCT collapses that before a single line is summed, and
            // the derived table stays inside the window because it is built from the
            // constrained rule set rather than from every order that ever existed.
            $pairs = '(SELECT DISTINCT ' . $db->quoteName('r.id_cart_rule') . ', ' . $db->quoteName('r.id_order')
                . ' FROM ' . $db->quoteName('#__alfa_order_cart_rule', 'r')
                . ' INNER JOIN ' . $db->quoteName('#__alfa_orders', 'o') . ' ON o.id = r.id_order'
                . ' WHERE ' . $db->quoteName('r.deleted') . ' = 0'
                . ' AND ' . $db->quoteName('o.state') . ' <> -2'
                . ' AND ' . $db->quoteName('o.id_currency') . ' = ' . (int) $currency
                . ' AND ' . $this->window('o.created')
                . ' AND ' . $db->quoteName('r.id_cart_rule') . ' IN (' . implode(',', array_map('intval', $ruleIds)) . ')'
                . ') AS ' . $db->quoteName('x');

            $rows = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('x.id_cart_rule', 'id'))
                    ->select('COALESCE(SUM(' . $db->quoteName('oi.total_price_tax_incl') . '), 0) AS ' . $db->quoteName('revenue'))
                    ->from($pairs)
                    ->join('INNER', $db->quoteName('#__alfa_order_items', 'oi'), 'oi.id_order = x.id_order')
                    ->group($db->quoteName('x.id_cart_rule')),
            )->loadObjectList() ?: [];

            $out = [];

            foreach ($rows as $row) {
                $out[(int) $row->id] = (float) $row->revenue;
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Drop cancelled orders from a query: a cancelled sale is not a sale.
     *
     * @param \Joomla\Database\QueryInterface $query The query.
     * @param string $alias The orders table's alias in it.
     *
     *
     * @since  1.10.0
     */
    private function excludeCancelled($query, string $alias): void
    {
        $ids = OrderStatusHelper::getCancelledIds();

        if ($ids !== []) {
            $query->whereNotIn($this->db()->quoteName($alias . '.id_order_status'), $ids);
        }
    }

    /**
     * Drop finished orders from a query: nobody needs chasing about those.
     *
     * @param \Joomla\Database\QueryInterface $query The query.
     * @param string $alias The orders table's alias in it.
     *
     *
     * @since  1.10.0
     */
    private function excludeCompleted($query, string $alias): void
    {
        $ids = OrderStatusHelper::getCompletedIds();

        if ($ids !== []) {
            $query->whereNotIn($this->db()->quoteName($alias . '.id_order_status'), $ids);
        }
    }
}
