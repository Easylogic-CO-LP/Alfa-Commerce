<?php

/**
 * Orders List Model: Batch-Loading Architecture
 *
 * Provides the admin orders grid with computed totals, payment/fulfillment
 * status, Money objects, and full child data for expandable detail panels.
 *
 * ARCHITECTURE:
 *   1. Main query: minimal, orders + 2 user JOINs (needed for search)
 *   2. Joomla paginates -> returns ~20 orders
 *   3. Load 3 lookup tables (statuses, payment methods, shipment methods)
 *   4. Batch-load children via 4 WHERE IN (...) queries (index seeks only)
 *   5. Attach names/colors from lookup tables (replaces 3 JOINs)
 *   6. Compute totals via OrderTotalHelper::computeFromArrays()
 *   7. Compute payment status + fulfillment status from batch data
 *   8. Attach raw child data to each order for expandable detail panels
 *
 * WHY LOOKUPS + BATCH (not JOINs + subqueries):
 *   Old approach:  3 JOINs + 8 correlated subqueries = complex SQL, 160+ executions
 *   New approach:  2 JOINs + 3 lookups + 4 batch queries = 9 simple queries total
 *   Lookup tables (statuses, methods) are 3-10 rows, loaded once, mapped in PHP.
 *   Child data (items, shipments, payments, discounts) batch-loaded via indexed IN().
 *
 * TOTALS - Single Source of Truth:
 *   Both OrderModel and OrdersModel call OrderTotalHelper::computeFromArrays().
 *   The formula (items + shipping - discounts) and the discount tax
 *   approximation are defined ONCE in the helper. No duplication.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderTotalHelper;
use Alfa\Component\Alfa\Administrator\Model\Traits\HasDateRangeFilterTrait;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderFacts;
use Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger;
use Alfa\Component\Alfa\Administrator\Service\Order\ReconciliationFacts;
use Alfa\Component\Alfa\Administrator\Service\Stats\DashboardStatsService;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Database\QueryInterface;

class OrdersModel extends AlfaListModel
{
    use HasDateRangeFilterTrait;

    /**
     * The admin-contract entity this list serves: every onAlfaAdmin* event
     * on this screen targets 'order'.
     *
     *
     * @since  2.0.0
     */
    protected string $alfaEntity = 'order';

    /**
     * The enriched page of the last getItems() run. The contract's list phases
     * re-ask getItems() while building row data; without this memo the five
     * batch loaders and the Money formatting would run twice per request.
     *
     *
     * @since  2.0.0
     */
    private ?array $alfaEnriched = null;

    /**
     * Constructor: registers sortable/filterable fields.
     *
     * Only real columns from #__alfa_orders and joined tables are sortable.
     * Computed fields (totals, fulfillment) are calculated after pagination
     * via batch-loading; they cannot be sorted at the SQL level.
     *
     * @param array $config Configuration array
     * @param MVCFactoryInterface|null $factory MVC factory
     * @since  1.0.0
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'reference', 'a.reference',
                'id_order_status', 'a.id_order_status',
                'id_payment_method', 'a.id_payment_method',
                'id_shipment_method', 'a.id_shipment_method',
                'state', 'a.state',
                'created', 'a.created',
                'modified', 'a.modified',
                'customer_name', 'a.customer_name',
                'customer_email', 'a.customer_email',
            ];
        }

        parent::__construct($config, $factory);
    }

    /**
     * Order ids whose CANONICAL facts match the fact filters.
     *
     * Why this is not a WHERE clause
     *
     * needs_reconciliation is derived in PHP from pools, documents and frozen
     * snapshots. Expressing it in SQL would mean writing the derivation a
     * second time, and the two would disagree the first time either moved:
     * the list calling an order broken while the order screen called it fine.
     * So the list asks the SAME class the screen asks.
     *
     * And why it is still bounded
     *
     * Not every order is a candidate. An order with no ISSUED document has
     * claimed nothing, so it can carry no over-claim, no negative remaining and
     * no semantic mismatch: the three things that set the verdict. An order
     * with no completed payment cannot be overpaid. Those two EXISTS tests are
     * provable supersets of the answer, not approximations of it: the facts
     * still decide, they only decide about fewer rows.
     *
     * The survivors are evaluated in CHUNKS, so the work is bounded by memory
     * rather than by the candidate count, and the query cost never follows the
     * PAGE size, which is the property the list contract actually needs.
     *
     * An order that names no currency has no facts at all. It is listed with
     * the orders that need reconciliation, read by one statement of its own.
     *
     * @param bool $needsReconciliation Keep only orders the facts call broken,
     *                                  and the orders that name no currency.
     * @param bool $overpaid Keep only orders holding more than they ask.
     *
     * @return int[] Matching order ids.
     *
     * @since  2.0.0
     */
    private function factFilteredIds(bool $needsReconciliation, bool $overpaid): array
    {
        $db = $this->getDatabase();
        $conditions = [];

        if ($needsReconciliation) {
            $conditions[] = 'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_documents', 'fd')
                . ' WHERE ' . $db->quoteName('fd.id_order') . ' = ' . $db->quoteName('fa.id')
                . ' AND ' . $db->quoteName('fd.status') . ' = ' . DocumentHelper::STATUS_ISSUED . ')';
        }

        if ($overpaid) {
            $conditions[] = 'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_order_payments', 'fp')
                . ' WHERE ' . $db->quoteName('fp.id_order') . ' = ' . $db->quoteName('fa.id')
                . ' AND ' . $db->quoteName('fp.payment_type') . ' = ' . $db->quote('payment')
                . ' AND ' . $db->quoteName('fp.transaction_status') . ' = ' . $db->quote('completed') . ')';
        }

        $candidates = array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('fa.id'))
                ->from($db->quoteName('#__alfa_orders', 'fa'))
                ->where('(' . implode(' OR ', $conditions) . ')'),
        )->loadColumn() ?: []);

        $matching = [];

        // An order that names no currency has no facts to be judged by, and
        // it is the one that needs repair before anything else can be done
        // with it: "needs reconciliation" lists it too. One statement, read
        // only while that filter is on.
        if ($needsReconciliation) {
            $matching = array_map('intval', $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('fa.id'))
                    ->from($db->quoteName('#__alfa_orders', 'fa'))
                    ->where('NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_currencies', 'fc')
                        . ' WHERE ' . $db->quoteName('fc.id') . ' = ' . $db->quoteName('fa.id_currency') . ')'),
            )->loadColumn() ?: []);
        }

        foreach (array_chunk($candidates, 500) as $chunk) {
            foreach (ReconciliationFacts::forOrders($chunk) as $orderId => $facts) {
                if ($needsReconciliation && $facts->needs_reconciliation) {
                    $matching[] = (int) $orderId;

                    continue;
                }

                if ($overpaid && $facts->overpaid) {
                    $matching[] = (int) $orderId;
                }
            }
        }

        return array_values(array_unique($matching));
    }

    /**
     * Populate state
     * @since  1.0.0
     */
    protected function populateState($ordering = 'a.id', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);

        // Order date window against a.created (UTC). Must run AFTER the parent:
        // the trait reads the registry the parent fills.
        $this->resolveDateRangeFilter();
    }

    /**
     * Three plain questions about an order, each its own filter, so a merchant can ask
     * "not paid", "paid but not shipped" or "untouched for two days" without a tile
     * having to say it first. The dashboard's tiles land on combinations of them with
     * the very predicates they counted with.
     *
     * Payment: `paid` (a completed payment), `unpaid` (none, and not cancelled),
     * `failed` (a failed or cancelled attempt in the window), `refunded` (a refund in
     * the window). For the last two the date window is about the PAYMENT, not the
     * order (an old order refunded this week belongs to this week), so it moves onto
     * the payment and leaves a.created alone.
     *
     * Every status and type here comes from {@see PaymentLedger}, never a literal: a
     * refund row carries STATUS_REFUNDED, not STATUS_COMPLETED, and this filter spent
     * its life asking for a pair the ledger has never written. The window sits on
     * `p.added` for the same reason: PaymentLedger stamps `processed_at` only for
     * STATUS_COMPLETED, so it is NULL on every refund and fails every comparison.
     * Fulfilment: `unshipped` (not in a completed or cancelled status), `shipped`.
     * Waiting: `placed_48` (placed longer ago than {@see DashboardStatsService::STALE_HOURS}),
     * `untouched_48` (no change for that long).
     *
     * @param QueryInterface $query The list query.
     * @param string $payment The payment filter, '' = any.
     * @param string $fulfilment The fulfilment filter, '' = any.
     * @param string $waiting The waiting filter, '' = any.
     * @param string $localStart Shop-local window start ('' = open).
     * @param string $localEnd Shop-local window end ('' = open).
     *
     *
     * @since  2.0.0
     */
    private function applyNeeds(QueryInterface $query, string $payment, string $fulfilment, string $waiting, string $localStart, string $localEnd): void
    {
        $db = $this->getDatabase();

        // "An order with a payment like this", optionally within the window on the
        // payment's own date column.
        $paymentLike = static function (array $where, ?string $dateColumn) use ($db, $localStart, $localEnd): string {
            $sub = $db->getQuery(true)
                ->select('1')
                ->from($db->quoteName('#__alfa_order_payments', 'p'))
                ->where($db->quoteName('p.id_order') . ' = ' . $db->quoteName('a.id'));

            foreach ($where as $clause) {
                $sub->where($clause);
            }

            if ($dateColumn !== null) {
                AlfaHelper::applyDateRange(query: $sub, column: $dateColumn, localStart: $localStart, localEnd: $localEnd);
            }

            return 'EXISTS (' . (string) $sub . ')';
        };

        $statusIn = static function (array $ids, bool $in) use ($query, $db): void {
            $ids = array_filter(array_map('intval', $ids));

            if ($ids !== []) {
                $query->where('COALESCE(' . $db->quoteName('a.id_order_status') . ', 0) ' . ($in ? 'IN' : 'NOT IN') . ' (' . implode(',', $ids) . ')');
            } elseif ($in) {
                $query->where('0 = 1');                 // "shipped" on a shop with no completed status: nothing
            }
        };

        $settled = [PaymentLedger::settledPaymentPredicate('p')];

        switch ($payment) {
            case 'paid':
                $query->where($paymentLike($settled, null));
                break;

            case 'unpaid':
                $query->where('NOT ' . $paymentLike($settled, null));
                $statusIn(OrderStatusHelper::getCancelledIds(), false);
                break;

            case 'failed':
                $query->where($paymentLike([PaymentLedger::failedAttemptPredicate('p')], 'p.added'));
                break;

            case 'refunded':
                // A refund the provider reversed in full no longer counts.
                $query->where($paymentLike([PaymentLedger::liveRefundPredicate('p')], 'p.added'));
                break;
        }

        switch ($fulfilment) {
            case 'unshipped':
                $statusIn(array_merge(OrderStatusHelper::getCompletedIds(), OrderStatusHelper::getCancelledIds()), false);
                break;

            case 'shipped':
                $statusIn(OrderStatusHelper::getCompletedIds(), true);
                break;
        }

        switch ($waiting) {
            case 'placed_48':
                $query->where($db->quoteName('a.created') . ' < ' . $db->quote(DashboardStatsService::staleBefore()));
                break;

            case 'untouched_48':
                $query->where($db->quoteName('a.modified') . ' < ' . $db->quote(DashboardStatsService::staleBefore()));
                break;
        }
    }

    /**
     * The product this grid is filtered to, named.
     *
     * filter.item_id is set only by the "Orders" link on a product and has no
     * field in the filter bar: a select listing every product would cost a query
     * per page load. That makes it INVISIBLE, and an invisible filter that
     * survives navigation reads as "my orders disappeared", so the grid says so
     * in words instead. One primary-key lookup, and only while the filter is on.
     *
     * @return string|null Product name, or null when no product filter is active.
     *
     * @since  2.0.0
     */
    public function getFilteredItemName(): ?string
    {
        $itemId = (int) $this->getState('filter.item_id');

        if ($itemId <= 0) {
            return null;
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('a.id'))
            ->from($db->quoteName('#__alfa_items', 'a'))
            ->where($db->quoteName('a.id') . ' = ' . $itemId);

        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_items',
            langPrimaryColumn: 'id_item',
            fields:            ['name'],
        );

        $row = $db->setQuery($query)->loadObject();

        return $row->name ?? null;
    }

    /**
     * The waiting filter that the current date window makes unsatisfiable, named.
     *
     * BOTH waiting options collide with a recent date window, because `created` and
     * `modified` are not independent: every creation path stamps them equal
     * ({@see \Alfa\Component\Alfa\Administrator\Service\Order\OrderCreator} and
     * {@see OrderModel::prepareTable()} both write `modified = created` at birth) and
     * `modified` only ever moves forward. So `modified >= created`, always, and a
     * window that forces `created` to be recent forces `modified` to be at least as
     * recent, which is exactly what the 48-hour cutoff forbids.
     *
     * "Today" is the plain case. Today 00:00 is at most 24 hours ago and the cutoff is
     * 48, so "placed today AND placed over 48 hours ago" is empty, and so is "placed
     * today AND untouched for 48 hours", since an order placed today cannot have sat
     * untouched for two days. Empty at every hour of every day, "Yesterday" likewise,
     * "This week" on Monday and Tuesday, "This month" on the 1st and 2nd.
     *
     * Left alone the grid answers that with a bare "no orders", which reads as a quiet
     * shop rather than a self-contradictory question: the same failure {@see
     * self::getFilteredItemName()} exists to prevent, so it gets the same answer: the
     * grid says so in words.
     *
     * The one exception is a `failed` / `refunded` payment filter, where the window
     * moves onto the payment row (see {@see self::getListQuery()}) and leaves
     * `a.created` unconstrained: an old order refunded this week is the point of that
     * branch, so any cutoff stays satisfiable there.
     *
     * The `modified >= created` invariant is what makes this provable. Imported rows
     * carrying a zero `modified` would break it; nothing in Alfa can create one.
     *
     * @return string|null The waiting option's label, or null when the filters can match.
     *
     * @since  2.0.0
     */
    public function getUnsatisfiableWaitingLabel(): ?string
    {
        $waiting = (string) $this->getState('filter.waiting', '');

        $label = match ($waiting) {
            'placed_48' => 'COM_ALFA_FILTER_WAITING_PLACED',
            'untouched_48' => 'COM_ALFA_FILTER_WAITING_UNTOUCHED',
            default => null,
        };

        if ($label === null) {
            return null;
        }

        if (\in_array((string) $this->getState('filter.payment', ''), ['failed', 'refunded'], true)) {
            return null;
        }

        // An open-ended window has no floor to outrun the cutoff.
        $localStart = (string) $this->getState('filter.date_start', '');

        if ($localStart === '') {
            return null;
        }

        try {
            // State holds SHOP-LOCAL bounds; the cutoff is UTC. Convert the same way
            // AlfaHelper::applyDateRange() does, then compare: both are 'Y-m-d H:i:s',
            // so string order is chronological order.
            $startUtc = Factory::getDate($localStart, AlfaHelper::shopTimezone())->toSql();
        } catch (Exception) {
            return null;                    // unparseable bound: the query drops it too
        }

        return $startUtc >= DashboardStatsService::staleBefore() ? Text::_($label) : null;
    }

    /**
     * Get store id.
     *
     * Serializes ALL filter.* state keys (sorted) instead of naming them one
     * by one: plugin-injected filters (onAlfaAdminListFilters + onAlfaAdminListQuery)
     * become part of the cache identity automatically.
     *
     * @since  1.0.0
     */
    protected function getStoreId($id = '')
    {
        $filters = [];

        // toArray(), not getProperties(): the API app injects a plain Registry
        // as model state (ApiController) and only toArray() exists on both.
        foreach ($this->getState()->toArray() as $key => $value) {
            if (str_starts_with((string) $key, 'filter.')) {
                $filters[$key] = $value;
            }
        }

        ksort($filters);

        $id .= ':' . serialize($filters);

        return parent::getStoreId($id);
    }

    /**
     * Build the main list query, intentionally minimal.
     *
     * Only orders table + 2 user JOINs (needed for customer name search).
     * Status/payment/shipment names and colors are attached in getItems()
     * from tiny lookup tables; no JOINs needed for those.
     *
     * All child data (items, shipments, payments, discounts) is batch-loaded
     * in getItems() after Joomla paginates.
     *
     * @since  1.0.0
     */
    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        // Order columns + user names
        // Only the users JOINs are needed: customer name/email are
        // used in the search WHERE clause. Everything else (status
        // names, method names, colors) comes from lookup tables in
        // getItems(); no point JOINing for 3-row reference tables.
        $query->select($this->getState('list.select', 'DISTINCT a.*'));

        // Last editor name (for checkout lock display)
        $query->select($db->quoteName('uc.name', 'editor'));

        // The customer is the order's own snapshot (a.customer_name /
        // a.customer_email / a.customer_is_guest, already in a.*): guests and
        // deleted accounts are listed and searchable like members. No join on
        // #__users: an alias of the same name would shadow the column.

        // FROM + JOINs
        $query->from($db->quoteName('#__alfa_orders', 'a'));

        // Last editor (for checked_out display)
        $query->join(
            'LEFT',
            $db->quoteName('#__users', 'uc'),
            $db->quoteName('uc.id') . ' = ' . $db->quoteName('a.checked_out'),
        );

        // Filters
        $orderStatusFilter = $this->getState('filter.id_order_status');
        if (!empty($orderStatusFilter)) {
            $query->where('a.id_order_status = ' . (int) $orderStatusFilter);
        }

        $paymentMethodFilter = $this->getState('filter.id_payment_method');
        if (!empty($paymentMethodFilter)) {
            $query->where('a.id_payment_method = ' . (int) $paymentMethodFilter);
        }

        $shipmentMethodFilter = $this->getState('filter.id_shipment_method');
        if (!empty($shipmentMethodFilter)) {
            $query->where('a.id_shipment_method = ' . (int) $shipmentMethodFilter);
        }

        // Product filter. Deliberately NOT declared in forms/filter_orders.xml:
        // a visible select would have to list every product in the catalogue, which
        // is a query per orders page load on a 100k-SKU shop. It is set only by the
        // "Orders" link on a product, and read straight off the state. The subquery
        // is a semi-join through idx_id_item on #__alfa_order_items.
        $itemFilter = (int) $this->getState('filter.item_id');

        if ($itemFilter > 0) {
            $query->where(
                $db->quoteName('a.id') . ' IN (SELECT ' . $db->quoteName('id_order')
                . ' FROM ' . $db->quoteName('#__alfa_order_items')
                . ' WHERE ' . $db->quoteName('id_item') . ' = ' . $itemFilter . ')',
            );
        }

        // Payment - Fulfilment - Waiting
        // Three plain filters; the dashboard's "Worth a look" tiles land on
        // combinations of them with the very predicates they counted with, so the
        // figure there and the total here agree.
        $payment = (string) $this->getState('filter.payment', '');
        $fulfilment = (string) $this->getState('filter.fulfilment', '');
        $waiting = (string) $this->getState('filter.waiting', '');
        $localStart = (string) $this->getState('filter.date_start', '');
        $localEnd = (string) $this->getState('filter.date_end', '');

        if ($payment !== '' || $fulfilment !== '' || $waiting !== '') {
            $this->applyNeeds($query, $payment, $fulfilment, $waiting, $localStart, $localEnd);
        }

        // Reconciliation - Overpaid
        // BOTH ARE DRIVEN BY THE CANONICAL FACT, never by a SQL re-derivation
        // of it. A second definition in the WHERE clause would drift from
        // ReconciliationFacts the first time either changed, and the list would
        // then disagree with the order screen about the same order.
        $reconciliation = (string) $this->getState('filter.reconciliation', '');
        $overpaidFilter = (string) $this->getState('filter.overpaid', '');

        if ($reconciliation !== '' || $overpaidFilter !== '') {
            $matching = $this->factFilteredIds(
                needsReconciliation: $reconciliation !== '',
                overpaid:            $overpaidFilter !== '',
            );

            $query->where(
                $matching === []
                    ? '0 = 1'
                    : $db->quoteName('a.id') . ' IN (' . implode(',', $matching) . ')',
            );
        }

        if (!\in_array($payment, ['failed', 'refunded'], true)) {
            // a.created is UTC; state holds SHOP-LOCAL bounds (what the calendars show).
            AlfaHelper::applyDateRange(
                query:      $query,
                column:     'a.created',
                localStart: $localStart,
                localEnd:   $localEnd,
            );
        }

        // Trash filter
        $show_trashed = $this->getState('filter.show_trashed');
        if (is_numeric($show_trashed) && $show_trashed == 1) {
            $query->where('a.state = -2');
        } else {
            $query->where('a.state != -2');
        }

        // Plugin filters now arrive through the admin contract: AlfaListModel's
        // _getListQuery() dispatches onAlfaAdminListQuery with this finished query.
        // The contract for handlers is unchanged: EXISTS subqueries only, and
        // nothing added while the plugin's filter is inactive.

        // Search
        $search = $this->getState('filter.search');
        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                $search = $db->quote('%' . $db->escape($search, true) . '%');
                // The snapshot first; the account as well, so an order placed
                // before the snapshot existed is still found by the name the
                // grid shows for it (OrderIdentity falls back the same way).
                $query->where('(' .
                    'a.customer_name LIKE ' . $search . ' OR ' .
                    'a.customer_email LIKE ' . $search . ' OR ' .
                    'a.reference LIKE ' . $search . ' OR ' .
                    'a.payment_method_name LIKE ' . $search . ' OR ' .
                    // Correlated on purpose: one primary-key lookup per scanned
                    // order, instead of an IN-subquery the optimiser may turn
                    // into a full scan of #__users per row.
                    'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__users', 'su')
                        . ' WHERE ' . $db->quoteName('su.id') . ' = ' . $db->quoteName('a.id_user')
                        . ' AND (' . $db->quoteName('su.name') . ' LIKE ' . $search
                        . ' OR ' . $db->quoteName('su.email') . ' LIKE ' . $search . '))' .
                    ')');
            }
        }

        // Ordering
        $orderCol = $this->state->get('list.ordering', 'a.id');
        $orderDirn = $this->state->get('list.direction', 'DESC');
        if ($orderCol && $orderDirn) {
            $query->order($db->quoteName($orderCol) . ' ' . (strtoupper((string) $orderDirn) === 'DESC' ? 'DESC' : 'ASC'));
        }

        return $query;
    }

    /**
     * Get paginated items with all computed data and child records.
     *
     * After Joomla paginates (typically ~20 orders), this method:
     *   1. Collects order IDs from the current page
     *   2. Batch-loads all children (4 queries via WHERE IN, index seeks)
     *   3. Computes totals via OrderTotalHelper::computeFromArrays()
     *   4. Computes payment status and fulfillment status
     *   5. Attaches raw child arrays for the detail panel
     *   6. Creates Money objects for formatted display
     *
     * Properties added to each order:
     *   Totals:      order_total, order_total_tax_excl, items_total, shipping_total, etc.
     *   Status:      payment_status, fulfillment_status, payment_percentage, etc.
     *   Children:    _items[], _shipments[], _payments[], _discounts[]
     *   Display:     order_total_money, formatted_date, order_age_days, etc.
     *
     * @return array Fully enriched order items
     * @since  1.0.0
     */
    public function getItems()
    {
        if ($this->alfaEnriched !== null) {
            return $this->alfaEnriched;
        }

        $items = parent::getItems();

        if ($items === false) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_ALFA_ERROR_SQL_LOADING_ORDERS'), 'error');
            return $this->alfaEnriched = [];
        }

        if (!is_array($items) || empty($items)) {
            return $this->alfaEnriched = [];
        }

        // Collect order IDs for batch queries
        $orderIds = array_map(function ($item) {
            return (int) $item->id;
        }, $items);

        // Batch-load all child data (4 simple indexed queries)
        // Each returns [ orderId => [row objects...] ] grouped by order.
        // Total: 4 queries with WHERE IN (...), index seeks only.
        // Compare: correlated subqueries = 8 x 20 = 160 queries.
        $itemsData = $this->batchLoadItems($orderIds);
        $shipmentLines = $this->batchLoadShipmentLines($orderIds);
        $shipmentsData = $this->batchLoadShipments($orderIds);
        $paymentsData = $this->batchLoadPayments($orderIds);
        $discountsData = $this->batchLoadDiscounts($orderIds);
        $contributionsData = $this->batchLoadContributions($orderIds);

        // Lookup tables (tiny: 3-10 rows each, cached per request)
        // AlfaHelper is the single source of truth for these lookups.
        // Static cache: first call queries DB, all subsequent calls
        // return cached result. No duplicate methods needed in models.
        $orderStatuses = AlfaHelper::getOrderStatuses();
        $paymentMethods = AlfaHelper::getPaymentMethods();
        $shipmentMethods = AlfaHelper::getShipmentMethods();

        // Process each order
        foreach ($items as $item) {
            $id = (int) $item->id;

            // Attach names + colors from lookup tables
            // Replaces the os/pm/sm JOINs in getListQuery().
            // Same data, no SQL complexity.
            $statusId = (int) ($item->id_order_status ?? 0);
            if (isset($orderStatuses[$statusId])) {
                $item->status_name = $orderStatuses[$statusId]->name;
                $item->status_color = $orderStatuses[$statusId]->color;
                $item->status_bg_color = $orderStatuses[$statusId]->bg_color;
            } else {
                $item->status_name = '';
                $item->status_color = '';
                $item->status_bg_color = '';
            }

            $pmId = (int) ($item->id_payment_method ?? 0);
            if (isset($paymentMethods[$pmId])) {
                $item->payment_method_name_live = $paymentMethods[$pmId]->name;
                $item->payment_color = $paymentMethods[$pmId]->color;
                $item->payment_bg_color = $paymentMethods[$pmId]->bg_color;
            } else {
                $item->payment_method_name_live = '';
                $item->payment_color = '';
                $item->payment_bg_color = '';
            }

            $smId = (int) ($item->id_shipment_method ?? 0);
            if (isset($shipmentMethods[$smId])) {
                $item->shipment_method_name_live = $shipmentMethods[$smId]->name;
                $item->shipment_color = $shipmentMethods[$smId]->color;
                $item->shipment_bg_color = $shipmentMethods[$smId]->bg_color;
            } else {
                $item->shipment_method_name_live = '';
                $item->shipment_color = '';
                $item->shipment_bg_color = '';
            }

            // Raw child arrays for this order
            $orderItems = $itemsData[$id] ?? [];
            $orderShipments = $shipmentsData[$id] ?? [];
            $orderPayments = $paymentsData[$id] ?? [];
            $orderDiscounts = $discountsData[$id] ?? [];
            $orderContributions = $contributionsData[$id] ?? [];

            // Store for the detail panel template
            $item->_items = $orderItems;
            $item->_shipments = $orderShipments;
            $item->_payments = $orderPayments;
            $item->_discounts = $orderDiscounts;
            $item->_contributions = $orderContributions;

            // Totals via single source of truth
            // OrderTotalHelper::computeFromArrays() contains THE formula:
            //   grand_total = items + shipping - discounts
            // Same method used by OrderModel::getItem() for the edit view.
            // Handles both Money objects and raw floats transparently.
            $totals = OrderTotalHelper::computeFromArrays(
                $orderItems,
                // One shipment population: cancelled and returned parcels are
                // listed in the detail panel but never count toward money.
                OrderTotalHelper::activeShipments($orderShipments),
                $orderDiscounts,
                $orderContributions,
            );

            $item->items_total = $totals->items_tax_incl;
            $item->items_total_excl = $totals->items_tax_excl;
            $item->shipping_total = $totals->shipping_tax_incl;
            $item->shipping_total_excl = $totals->shipping_tax_excl;
            $item->discount_total = $totals->discount_tax_incl;
            $item->discount_total_excl = $totals->discount_tax_excl;
            $item->contributions_total = $totals->contributions_tax_incl;
            $item->contributions_total_excl = $totals->contributions_tax_excl;
            $item->order_total = $totals->grand_total_tax_incl;
            $item->order_total_tax_excl = $totals->grand_total_tax_excl;

            // THIS order's currency
            // THE ORDER'S OWN, AT THE ORDER'S OWN SCALE, NOT TODAY'S: the one
            // answer OrderFacts::forOrder(), the order screen, the API item
            // route and the paid gate read ({@see OrderCurrency}). With a
            // currency of its own here this list derived balance, overpaid
            // and excess at the LIVE scale while they derived them at the
            // frozen one, so a decimal_place edit made the grid and the order
            // disagree about the same money. Nothing threw, because
            // Currency::equals() compares the ISO number and not the scale.
            //
            // Resolved once per row, for the facts and for every amount the
            // row shows. NULL when the order names no currency: the row stays
            // in the list and says so where its money would be. No other
            // currency is put in its place, and no payment state is derived
            // at a scale the order never named.
            $currency = OrderCurrency::findRow(
                orderId:    $id,
                currencyId: $item->id_currency ?? null,
                scale:      $item->currency_scale ?? null,
            );

            $item->_currency = $currency;
            $item->currency_notice = $currency === null ? OrderCurrency::notice() : '';

            $orderTotal = $totals->grand_total_tax_incl;

            // Live payment facts
            // One derivation, shared with the API, the edit view and the
            // totals card. The rows are already loaded here, so it costs no
            // extra query. The inline ladders this replaces disagreed across
            // surfaces.
            $facts = $currency === null ? null : OrderFacts::fromRows(
                $orderTotal,
                $orderPayments,
                $currency,
            );

            $item->payment_facts = $facts;
            $item->total_paid_real = $facts?->paid_real;
            $item->total_refunded_real = $facts?->refunded_real;
            $item->still_held = $facts?->still_held;
            // Signed: negative = overpaid.
            $item->balance = $facts?->balance;
            $item->overpaid = $facts?->overpaid;
            $item->excess = $facts?->excess;
            $item->payment_percentage = $facts?->payment_percentage;
            $item->payment_status = $facts?->payment_status;

            // Fulfillment status
            // Counted in UNITS, not lines. A line may be split across parcels
            // ("3 now, 2 Monday"), so counting lines would report a half-sent
            // line as either wholly sent or not sent at all, and on a
            // single-line order 'partial' would be unreachable entirely.
            $orderLines = $shipmentLines[$id] ?? [];
            $totalUnits = 0;
            $shippedUnits = 0;

            foreach ($orderItems as $oi) {
                $totalUnits += (int) ($oi->quantity ?? 0);

                // Per-item view for the detail panel: how much of THIS line
                // has gone, and under which status.
                $oi->shipped_quantity = 0;
                $oi->shipment_status = '';
                $oi->shipment_tracking = '';

                foreach ($orderLines as $line) {
                    if ((int) $line->id_order_item !== (int) $oi->id) {
                        continue;
                    }

                    if (in_array($line->shipment_status ?? '', ['shipped', 'delivered'], true)) {
                        $oi->shipped_quantity += (int) $line->quantity;
                    }

                    // First parcel carrying the line names its status; a
                    // cancelled parcel still shows, so the admin can see why
                    // nothing moved.
                    if ($oi->shipment_status === '') {
                        $oi->shipment_status = (string) ($line->shipment_status ?? '');
                        $oi->shipment_tracking = (string) ($line->shipment_tracking ?? '');
                    }
                }

                $shippedUnits += $oi->shipped_quantity;

                // A line split across parcels that are not all out yet.
                if ($oi->shipped_quantity > 0
                    && $oi->shipped_quantity < (int) ($oi->quantity ?? 0)) {
                    $oi->shipment_status = 'partial';
                }
            }

            // Kept as *_items for template compatibility; the numbers are units.
            $item->total_items = $totalUnits;
            $item->fulfilled_items = $shippedUnits;

            if ($totalUnits > 0) {
                $item->fulfillment_percentage = round(($shippedUnits / $totalUnits) * 100, 2);
            } else {
                $item->fulfillment_percentage = 0;
            }

            if ($totalUnits <= 0 || $shippedUnits <= 0) {
                $item->fulfillment_status = 'unfulfilled';
            } elseif ($shippedUnits >= $totalUnits) {
                $item->fulfillment_status = 'fulfilled';
            } else {
                $item->fulfillment_status = 'partial';
            }

            // Order age
            if (!empty($item->created)) {
                try {
                    $date = Factory::getDate($item->created);
                    $item->formatted_date = $date->format('Y-m-d H:i');
                    $item->order_age_days = floor((time() - $date->toUnix()) / 86400);
                } catch (Exception $e) {
                    $item->formatted_date = $item->created;
                    $item->order_age_days = 0;
                }
            }

            // What the row shows as money
            // Every amount of the row is written here as text, in the order's
            // own currency, and the templates print that text. A row whose
            // order names no currency gets the notice in each place instead:
            // never a bare number, which reads as money in the shop's.
            $notice = $item->currency_notice;

            // Each unit by the one unit rule, from the line's own quantity and
            // total in the same tax space; the agreed custom rate is a gross
            // rate and is passed beside the gross unit only.
            foreach ($item->_items as $oi) {
                $oi->unit_price_tax_incl_formatted = $currency !== null
                    ? $currency->formatUnit(
                        unit: $oi->unit_price_tax_incl ?? 0,
                        lineTotal: $oi->total_price_tax_incl ?? 0,
                        quantity: $oi->quantity ?? 0,
                        rate: $oi->custom_unit_price_tax_incl ?? null,
                    )
                    : $notice;
                $oi->unit_price_tax_excl_formatted = $currency !== null
                    ? $currency->formatUnit(
                        unit: $oi->unit_price_tax_excl ?? 0,
                        lineTotal: $oi->total_price_tax_excl ?? 0,
                        quantity: $oi->quantity ?? 0,
                    )
                    : $notice;

                $oi->total_price_tax_incl_formatted = $currency !== null ? $currency->format((float) ($oi->total_price_tax_incl ?? 0)) : $notice;
                $oi->total_price_tax_excl_formatted = $currency !== null ? $currency->format((float) ($oi->total_price_tax_excl ?? 0)) : $notice;

                if ((float) ($oi->discount_amount_tax_incl ?? 0) > 0) {
                    $oi->reduction_formatted = $currency !== null ? $currency->format((float) $oi->discount_amount_tax_incl) : $notice;
                }
            }

            foreach ($item->_shipments as $os) {
                $os->shipping_cost_formatted = $currency !== null ? $currency->format((float) ($os->shipping_cost_tax_incl ?? 0)) : $notice;
            }

            foreach ($item->_discounts as $cr) {
                $cr->value_formatted = $currency !== null ? $currency->format((float) ($cr->value_tax_excl ?? 0)) : $notice;
            }

            // A payment row arrives from the ledger as Money in its order's
            // currency. The rows of an order that names none arrive with the
            // stored amount, which is not money this list can format.
            foreach ($item->_payments as $op) {
                $op->amount_formatted = $op->amount instanceof Money ? $op->amount->abs()->format() : $notice;
            }

            // Money objects for main row display
            if ($currency !== null) {
                $item->order_total_money = Money::of($orderTotal, $currency);
                $item->order_total_tax_excl_money = Money::of($totals->grand_total_tax_excl, $currency);
                $item->total_paid_real_money = Money::of($facts->paid_real, $currency);
                $item->total_refunded_real_money = Money::of($facts->refunded_real, $currency);
                $item->items_total_money = Money::of($totals->items_tax_incl, $currency);
                $item->shipping_total_money = Money::of($totals->shipping_tax_incl, $currency);
                $item->contributions_total_money = Money::of($totals->contributions_tax_incl, $currency);
                $item->discount_total_money = Money::of($totals->discount_tax_incl, $currency);
            }
        }

        // Reconciliation facts, for the WHOLE PAGE in one call
        // ReconciliationFacts::forOrders(), never forOrder() per row: the
        // projection costs a fixed handful of statements for a page of any
        // length, and calling it per row would reintroduce exactly the N+1 the
        // batch loaders above exist to avoid.
        $reconciliation = ReconciliationFacts::forOrders(
            array_map(static fn (object $row): int => (int) $row->id, $items),
        );

        foreach ($items as $item) {
            $rf = $reconciliation[(int) $item->id] ?? null;

            $item->reconciliation_facts = $rf;
            $item->needs_reconciliation = (bool) ($rf->needs_reconciliation ?? false);
            $item->reconciliation_reasons = $rf->reconciliation_reasons ?? [];
            $item->credit_settlement_state = (string) ($rf->credit_settlement->state
                ?? ReconciliationFacts::STATE_NOT_APPLICABLE);

            // DERIVED AND REASON-BEARING, never a stored column. The grid needs
            // one dot to sort an operator's eye by, but a bare flag would be a
            // verdict without a subject, so it carries WHY, and the three
            // reasons stay nameable and separate.
            $attention = [];

            // An order that names no currency has no facts, so none of the
            // reasons below can name it. It carries one of its own, and the
            // mark an operator sorts by still finds it.
            if (($item->currency_notice ?? '') !== '') {
                $attention[] = 'no_currency';
            }

            if ($item->needs_reconciliation) {
                $attention[] = 'reconciliation';
            }

            if (!empty($item->overpaid)) {
                $attention[] = 'overpaid';
            }

            if (\in_array($item->credit_settlement_state, [
                ReconciliationFacts::STATE_NONE,
                ReconciliationFacts::STATE_PARTIAL,
            ], true)) {
                $attention[] = 'unsettled_credit';
            }

            $item->attention_reasons = $attention;
            $item->needs_attention = $attention !== [];
        }

        // Plugin row data and columns now arrive through the admin contract:
        // AlfaListModel runs onAlfaAdminListRowData (whole page's ids at once, the
        // same one-IN-query-per-plugin batch philosophy as the loaders above) and
        // onAlfaAdminListColumns after these rows are fully enriched.
        return $this->alfaEnriched = $items;
    }

    //  BATCH LOADERS: one indexed query per child table
    //
    //  Each returns [ orderId => [row objects...] ] via groupByOrder().
    //  Queries use WHERE id_order IN (...): index seeks, no table scans.
    //  Columns selected include everything needed for:
    //    - OrderTotalHelper::computeFromArrays() (totals)
    //    - Fulfillment status calculation
    //    - Detail panel display

    /**
     * Batch-load the order lines themselves: one row per line, no joins.
     *
     * Fulfilment comes from batchLoadShipmentLines() instead: a line may ride
     * in several parcels, so joining shipments here would multiply these rows.
     * quantity_refunded / quantity_returned are stamped from the document and
     * shipment ledgers (two grouped queries below), not read from the table.
     *
     * @param int[] $orderIds Order PKs from the current page
     *
     * @return array [ orderId => [item objects...] ]
     * @since  1.0.0
     */
    private function batchLoadItems(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('oi.id'),
                $db->quoteName('oi.id_order'),
                $db->quoteName('oi.id_item'),
                $db->quoteName('oi.name'),
                $db->quoteName('oi.sku'),
                $db->quoteName('oi.quantity'),
                $db->quoteName('oi.unit_price_tax_incl'),
                $db->quoteName('oi.unit_price_tax_excl'),
                $db->quoteName('oi.total_price_tax_incl'),
                $db->quoteName('oi.total_price_tax_excl'),
                $db->quoteName('oi.custom_unit_price_tax_incl'),
                $db->quoteName('oi.discount_percent'),
                $db->quoteName('oi.discount_amount_tax_incl'),
            ])
            ->from($db->quoteName('#__alfa_order_items', 'oi'))
            ->whereIn($db->quoteName('oi.id_order'), $orderIds);

        $db->setQuery($query);

        $rows = $db->loadObjectList();

        // Derived from the real ledgers (credit documents + returned parcels).
        // The four #__alfa_order_items columns quantity_refunded/quantity_returned
        // and refunded_amount_* are written only as zeros; superseded by this
        // derivation and removal candidates.
        $credited = $this->batchLoadCreditedUnits($orderIds);
        $returned = $this->batchLoadReturnedUnits($orderIds);

        foreach ($rows as $row) {
            $row->quantity_refunded = $credited[(int) $row->id] ?? 0.0;
            $row->quantity_returned = $returned[(int) $row->id] ?? 0.0;
        }

        return $this->groupByOrder($rows, 'id_order');
    }

    /**
     * Units already credited per order line: issued credit-pool documents
     * (DocumentHelper::creditTypes()) summed over their document lines.
     *
     * Keyed by LINE id, not order id: a separate grouped query merged in PHP,
     * for the same no-row-multiplication reason as batchLoadShipmentLines().
     *
     * @param int[] $orderIds Order PKs from the current page
     *
     * @return array<int, float> [ orderItemId => credited units ]
     * @since  2.0.0
     */
    private function batchLoadCreditedUnits(array $orderIds): array
    {
        $creditTypes = DocumentHelper::creditTypes();

        if (empty($orderIds) || $creditTypes === []) {
            return [];
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('di.id_order_item'),
                'SUM(' . $db->quoteName('di.quantity') . ') AS credited',
            ])
            ->from($db->quoteName('#__alfa_document_items', 'di'))
            ->join(
                'INNER',
                $db->quoteName('#__alfa_documents', 'd'),
                $db->quoteName('d.id') . ' = ' . $db->quoteName('di.id_document'),
            )
            ->whereIn($db->quoteName('d.id_order'), $orderIds)
            ->where([
                $db->quoteName('d.status') . ' = ' . DocumentHelper::STATUS_ISSUED,
                $db->quoteName('d.doc_type') . ' IN (' . implode(',', array_map([$db, 'quote'], $creditTypes)) . ')',
                $db->quoteName('di.id_order_item') . ' IS NOT NULL',
            ])
            ->group($db->quoteName('di.id_order_item'));

        $db->setQuery($query);

        $map = [];

        foreach ($db->loadObjectList() as $row) {
            $map[(int) $row->id_order_item] = (float) $row->credited;
        }

        return $map;
    }

    /**
     * Units physically back per order line: shipment lines whose parcel
     * sits in status 'returned' (ShipmentLedger::STATUS_RETURNED).
     *
     * @param int[] $orderIds Order PKs from the current page
     *
     * @return array<int, float> [ orderItemId => returned units ]
     * @since  2.0.0
     */
    private function batchLoadReturnedUnits(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('si.id_order_item'),
                'SUM(' . $db->quoteName('si.quantity') . ') AS returned',
            ])
            ->from($db->quoteName('#__alfa_order_shipment_items', 'si'))
            ->join(
                'INNER',
                $db->quoteName('#__alfa_order_shipments', 'os'),
                $db->quoteName('si.id_order_shipment') . ' = ' . $db->quoteName('os.id'),
            )
            ->whereIn($db->quoteName('si.id_order'), $orderIds)
            ->where($db->quoteName('os.status') . ' = ' . $db->quote('returned'))
            ->group($db->quoteName('si.id_order_item'));

        $db->setQuery($query);

        $map = [];

        foreach ($db->loadObjectList() as $row) {
            $map[(int) $row->id_order_item] = (float) $row->returned;
        }

        return $map;
    }

    /**
     * Batch-load the shipment LINES: which order line rides in which parcel,
     * how many units, and what that parcel's status is.
     *
     * A separate query rather than a join on batchLoadItems(): one line may
     * now appear in several parcels, so joining would multiply the item rows
     * and quietly double every total computed from them.
     *
     * @param int[] $orderIds Order PKs from the current page
     *
     * @return array [ orderId => [line objects...] ]
     * @since  2.0.0
     */
    private function batchLoadShipmentLines(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('si.id_order'),
                $db->quoteName('si.id_order_item'),
                $db->quoteName('si.id_order_shipment'),
                $db->quoteName('si.quantity'),
                $db->quoteName('os.status', 'shipment_status'),
                $db->quoteName('os.tracking_number', 'shipment_tracking'),
            ])
            ->from($db->quoteName('#__alfa_order_shipment_items', 'si'))
            ->join(
                'INNER',
                $db->quoteName('#__alfa_order_shipments', 'os'),
                $db->quoteName('si.id_order_shipment') . ' = ' . $db->quoteName('os.id'),
            )
            ->whereIn($db->quoteName('si.id_order'), $orderIds);

        $db->setQuery($query);

        return $this->groupByOrder($db->loadObjectList(), 'id_order');
    }

    /**
     * Batch-load order shipments with tracking details.
     *
     * @param int[] $orderIds Order PKs
     *
     * @return array [ orderId => [shipment objects...] ]
     * @since  1.0.0
     */
    private function batchLoadShipments(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id'),
                $db->quoteName('id_order'),
                $db->quoteName('shipment_method_name'),
                $db->quoteName('tracking_number'),
                $db->quoteName('carrier_name'),
                $db->quoteName('shipping_cost_tax_incl'),
                $db->quoteName('shipping_cost_tax_excl'),
                $db->quoteName('weight'),
                $db->quoteName('status'),
                $db->quoteName('added'),
                $db->quoteName('shipped'),
                $db->quoteName('delivered'),
            ])
            ->from($db->quoteName('#__alfa_order_shipments'))
            ->whereIn($db->quoteName('id_order'), $orderIds)
            ->order($db->quoteName('added') . ' ASC');

        $db->setQuery($query);

        return $this->groupByOrder($db->loadObjectList(), 'id_order');
    }

    /**
     * Batch-load ALL payment records (including refunds, pending, failed).
     *
     * All records are needed for the detail panel. Only completed payments
     * of type "payment" are counted toward total_paid_real (filtered in PHP).
     *
     * @param int[] $orderIds Order PKs
     *
     * @return array [ orderId => [payment objects...] ]
     * @since  1.0.0
     */
    private function batchLoadPayments(array $orderIds): array
    {
        // Through the ledger, not past it. This used to be its own SELECT with
        // its own column list: a second definition of a payment row, which is
        // how the list came to read raw decimals while every other reader had
        // moved to Money. Still one query for the whole page.
        return PaymentLedger::getByOrders($orderIds, oldestFirst: true);
    }

    /**
     * Batch-load frozen cart contributions (labeled order-level charges).
     *
     * @param int[] $orderIds Order PKs
     *
     * @return array [ orderId => [contribution objects...] ]
     * @since  2.0.0
     */
    private function batchLoadContributions(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id_order'),
                $db->quoteName('code'),
                $db->quoteName('source'),
                $db->quoteName('name'),
                $db->quoteName('amount_tax_excl'),
                $db->quoteName('tax_rate'),
                $db->quoteName('amount_tax_incl'),
                $db->quoteName('sort_order'),
            ])
            ->from($db->quoteName('#__alfa_order_contributions'))
            ->whereIn($db->quoteName('id_order'), $orderIds)
            ->order($db->quoteName('sort_order') . ' ASC');

        $db->setQuery($query);

        return $this->groupByOrder($db->loadObjectList(), 'id_order');
    }

    /**
     * Batch-load active cart rules (discounts).
     *
     * @param int[] $orderIds Order PKs
     *
     * @return array [ orderId => [cart rule objects...] ]
     * @since  1.0.0
     */
    private function batchLoadDiscounts(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id_order'),
                $db->quoteName('name'),
                $db->quoteName('value_tax_excl'),
                $db->quoteName('value_tax_incl'),
                $db->quoteName('discount_class'),
                $db->quoteName('free_shipping'),
            ])
            ->from($db->quoteName('#__alfa_order_cart_rule'))
            ->whereIn($db->quoteName('id_order'), $orderIds)
            ->where($db->quoteName('deleted') . ' = 0');

        $db->setQuery($query);

        return $this->groupByOrder($db->loadObjectList(), 'id_order');
    }

    //  Helpers

    /**
     * Group a flat result set by order ID.
     *
     * Converts a flat array of rows into a grouped structure:
     *   [ orderId => [row1, row2, ...], orderId2 => [...] ]
     *
     * @param array|null $rows Database result rows
     * @param string $key Column name containing the order ID
     *
     * @return array Grouped array
     * @since  1.0.0
     */
    private function groupByOrder(?array $rows, string $key): array
    {
        $grouped = [];

        if (empty($rows)) {
            return $grouped;
        }

        foreach ($rows as $row) {
            $orderId = (int) $row->$key;
            $grouped[$orderId][] = $row;
        }

        return $grouped;
    }

    //  LOOKUP METHODS: delegates to AlfaHelper (single source of truth)
    //
    //  Thin wrappers so the View can call $this->get('OrderStatuses').
    //  Data + caching lives in AlfaHelper.

    /**
     * Get all order statuses keyed by ID.
     * Delegates to AlfaHelper::getOrderStatuses() (cached).
     * @since  1.0.0
     */
    public function getOrderStatuses(): array
    {
        return AlfaHelper::getOrderStatuses();
    }

    /**
     * Get all active payment methods keyed by ID.
     * Delegates to AlfaHelper::getPaymentMethods() (cached).
     * @since  1.0.0
     */
    public function getPaymentMethods(): array
    {
        return AlfaHelper::getPaymentMethods();
    }

    /**
     * Get all active shipment methods keyed by ID.
     * Delegates to AlfaHelper::getShipmentMethods() (cached).
     * @since  1.0.0
     */
    public function getShipmentMethods(): array
    {
        return AlfaHelper::getShipmentMethods();
    }
}
