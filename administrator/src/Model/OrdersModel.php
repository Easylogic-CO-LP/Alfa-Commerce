<?php

/**
 * Orders List Model — Batch-Loading Architecture
 *
 * Provides the admin orders grid with computed totals, payment/fulfillment
 * status, Money objects, and full child data for expandable detail panels.
 *
 * ARCHITECTURE:
 *   1. Main query: minimal — orders + 2 user JOINs (needed for search)
 *   2. Joomla paginates → returns ~20 orders
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
 *   Lookup tables (statuses, methods) are 3-10 rows — loaded once, mapped in PHP.
 *   Child data (items, shipments, payments, discounts) batch-loaded via indexed IN().
 *
 * TOTALS — Single Source of Truth:
 *   Both OrderModel and OrdersModel call OrderTotalHelper::computeFromArrays().
 *   The formula (items + shipping - discounts) and the discount tax
 *   approximation are defined ONCE in the helper. No duplication.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  3.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderTotalHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;

class OrdersModel extends ListModel
{
    /**
     * Constructor — registers sortable/filterable fields.
     *
     * Only real columns from #__alfa_orders and joined tables are sortable.
     * Computed fields (totals, fulfillment) are calculated after pagination
     * via batch-loading — they cannot be sorted at the SQL level.
     *
     * @param array $config Configuration array
     * @param MVCFactoryInterface|null $factory MVC factory
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
                'customer_name', 'cu.name',
            ];
        }

        parent::__construct($config, $factory);
    }

    /**
     * Populate state
     */
    protected function populateState($ordering = 'a.id', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);
    }

    /**
     * Get store id
     */
    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.id_order_status');
        $id .= ':' . $this->getState('filter.id_payment_method');
        $id .= ':' . $this->getState('filter.id_shipment_method');
        $id .= ':' . $this->getState('filter.show_trashed');

        return parent::getStoreId($id);
    }

    /**
     * Build the main list query — intentionally minimal.
     *
     * Only orders table + 2 user JOINs (needed for customer name search).
     * Status/payment/shipment names and colors are attached in getItems()
     * from tiny lookup tables — no JOINs needed for those.
     *
     * All child data (items, shipments, payments, discounts) is batch-loaded
     * in getItems() after Joomla paginates.
     *
     * @return \Joomla\Database\DatabaseQuery
     */
    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        // ── Order columns + user names ──────────────────────────
        // Only the users JOINs are needed — customer name/email are
        // used in the search WHERE clause. Everything else (status
        // names, method names, colors) comes from lookup tables in
        // getItems() — no point JOINing for 3-row reference tables.
        $query->select($this->getState('list.select', 'DISTINCT a.*'));

        // Last editor name (for checkout lock display)
        $query->select($db->quoteName('uc.name', 'editor'));

        // Customer name + email (needed for search WHERE clause)
        $query->select([
            $db->quoteName('cu.name', 'customer_name'),
            $db->quoteName('cu.email', 'customer_email'),
        ]);

        // ── FROM + JOINs (only users — needed for search) ───────
        $query->from($db->quoteName('#__alfa_orders', 'a'));

        // Last editor (for checked_out display)
        $query->join(
            'LEFT',
            $db->quoteName('#__users', 'uc'),
            $db->quoteName('uc.id') . ' = ' . $db->quoteName('a.checked_out'),
        );

        // Customer (needed for: search by name/email, display in list)
        $query->join(
            'LEFT',
            $db->quoteName('#__users', 'cu'),
            $db->quoteName('cu.id') . ' = ' . $db->quoteName('a.id_user'),
        );

        // ── Filters ─────────────────────────────────────────────
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

        // Trash filter
        $show_trashed = $this->getState('filter.show_trashed');
        if (is_numeric($show_trashed) && $show_trashed == 1) {
            $query->where('a.state = -2');
        } else {
            $query->where('a.state != -2');
        }

        // ── Search ──────────────────────────────────────────────
        $search = $this->getState('filter.search');
        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                $search = $db->quote('%' . $db->escape($search, true) . '%');
                $query->where('(' .
                    'cu.name LIKE ' . $search . ' OR ' .
                    'cu.email LIKE ' . $search . ' OR ' .
                    'a.reference LIKE ' . $search . ' OR ' .
                    'a.payment_method_name LIKE ' . $search .
                    ')');
            }
        }

        // ── Ordering ────────────────────────────────────────────
        $orderCol = $this->state->get('list.ordering', 'a.id');
        $orderDirn = $this->state->get('list.direction', 'DESC');
        if ($orderCol && $orderDirn) {
            $query->order($db->escape($orderCol . ' ' . $orderDirn));
        }

        return $query;
    }

    /**
     * Get paginated items with all computed data and child records.
     *
     * After Joomla paginates (typically ~20 orders), this method:
     *   1. Collects order IDs from the current page
     *   2. Batch-loads all children (4 queries via WHERE IN — index seeks)
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
     */
    public function getItems()
    {
        $items = parent::getItems();

        if ($items === false) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_ALFA_ERROR_SQL_LOADING_ORDERS'), 'error');
            return [];
        }

        if (!is_array($items) || empty($items)) {
            return [];
        }

        // ── Collect order IDs for batch queries ─────────────────
        $orderIds = array_map(function ($item) {
            return (int) $item->id;
        }, $items);

        // ── Batch-load all child data (4 simple indexed queries) ─
        // Each returns [ orderId => [row objects...] ] grouped by order.
        // Total: 4 queries with WHERE IN (...) — index seeks only.
        // Compare: correlated subqueries = 8 × 20 = 160 queries.
        $itemsData = $this->batchLoadItems($orderIds);
        $shipmentsData = $this->batchLoadShipments($orderIds);
        $paymentsData = $this->batchLoadPayments($orderIds);
        $discountsData = $this->batchLoadDiscounts($orderIds);

        // ── Lookup tables (tiny — 3-10 rows each, cached per request) ─
        // AlfaHelper is the single source of truth for these lookups.
        // Static cache: first call queries DB, all subsequent calls
        // return cached result. No duplicate methods needed in models.
        $orderStatuses = AlfaHelper::getOrderStatuses();
        $paymentMethods = AlfaHelper::getPaymentMethods();
        $shipmentMethods = AlfaHelper::getShipmentMethods();

        // ── Process each order ──────────────────────────────────
        foreach ($items as $item) {
            $id = (int) $item->id;

            // ── Attach names + colors from lookup tables ────
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

            // Store for the detail panel template
            $item->_items = $orderItems;
            $item->_shipments = $orderShipments;
            $item->_payments = $orderPayments;
            $item->_discounts = $orderDiscounts;

            // ── Totals via single source of truth ───────────
            // OrderTotalHelper::computeFromArrays() contains THE formula:
            //   grand_total = items + shipping - discounts
            // Same method used by OrderModel::getItem() for the edit view.
            // Handles both Money objects and raw floats transparently.
            $totals = OrderTotalHelper::computeFromArrays(
                $orderItems,
                $orderShipments,
                $orderDiscounts,
            );

            $item->items_total = $totals->items_tax_incl;
            $item->items_total_excl = $totals->items_tax_excl;
            $item->shipping_total = $totals->shipping_tax_incl;
            $item->shipping_total_excl = $totals->shipping_tax_excl;
            $item->discount_total = $totals->discount_tax_incl;
            $item->discount_total_excl = $totals->discount_tax_excl;
            $item->order_total = $totals->grand_total_tax_incl;
            $item->order_total_tax_excl = $totals->grand_total_tax_excl;

            // ── Total paid (completed payment-type only) ────
            // Only completed payments of type "payment" count.
            // Refunds, pending, failed are stored for the detail panel
            // but don't affect the paid total.
            $totalPaidReal = 0.0;

            foreach ($orderPayments as $op) {
                if (($op->transaction_status ?? '') === 'completed'
                    && ($op->payment_type ?? '') === 'payment') {
                    $totalPaidReal += (float) $op->amount;
                }
            }

            $item->total_paid_real = $totalPaidReal;

            // ── Payment status ──────────────────────────────
            $orderTotal = $totals->grand_total_tax_incl;

            if ($orderTotal > 0) {
                $item->payment_percentage = round(($totalPaidReal / $orderTotal) * 100, 2);
            } else {
                $item->payment_percentage = 0;
            }

            if ($totalPaidReal <= 0) {
                $item->payment_status = 'unpaid';
            } elseif ($totalPaidReal >= $orderTotal) {
                $item->payment_status = 'paid';
            } else {
                $item->payment_status = 'partial';
            }

            // ── Fulfillment status ──────────────────────────
            // Count total items vs items assigned to shipped/delivered shipments.
            $totalItemCount = count($orderItems);
            $fulfilledItems = 0;

            foreach ($orderItems as $oi) {
                if (!empty($oi->shipment_status)
                    && in_array($oi->shipment_status, ['shipped', 'delivered'], true)) {
                    $fulfilledItems++;
                }
            }

            $item->total_items = $totalItemCount;
            $item->fulfilled_items = $fulfilledItems;

            if ($totalItemCount > 0) {
                $item->fulfillment_percentage = round(($fulfilledItems / $totalItemCount) * 100, 2);
            } else {
                $item->fulfillment_percentage = 0;
            }

            if ($totalItemCount <= 0) {
                $item->fulfillment_status = 'unfulfilled';
            } elseif ($fulfilledItems >= $totalItemCount) {
                $item->fulfillment_status = 'fulfilled';
            } elseif ($fulfilledItems > 0) {
                $item->fulfillment_status = 'partial';
            } else {
                $item->fulfillment_status = 'unfulfilled';
            }

            // ── Order age ───────────────────────────────────
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

            // ── Per-order currency ─────────────────────────
            // Currency::loadById() is cached per-request.
            // 20 orders with the same currency = 1 DB query.
            $currency = null;
            $currencyId = (int) ($item->id_currency ?? 0);

            if ($currencyId > 0) {
                try {
                    $currency = Currency::loadById($currencyId);
                } catch (Exception $e) {
                    // Currency not found — fallback chain
                    try {
                        $currency = Currency::loadByCode('EUR');
                    } catch (Exception $e2) {
                        try {
                            $currency = Currency::loadByCode('USD');
                        } catch (Exception $e3) {
                            $currency = null;
                        }
                    }
                }
            }

            $item->_currency = $currency;

            // ── Money objects for main row display ──────────
            if ($currency !== null) {
                try {
                    $item->order_total_money = Money::of($orderTotal, $currency);
                    $item->order_total_tax_excl_money = Money::of($totals->grand_total_tax_excl, $currency);
                    $item->total_paid_real_money = Money::of($totalPaidReal, $currency);
                    $item->items_total_money = Money::of($totals->items_tax_incl, $currency);
                    $item->shipping_total_money = Money::of($totals->shipping_tax_incl, $currency);
                    $item->discount_total_money = Money::of($totals->discount_tax_incl, $currency);
                } catch (Exception $e) {
                    // Money creation failed
                }

                // ── Format child data for the detail panel ──
                // Template just echoes — no formatting logic there.
                foreach ($item->_items as $oi) {
                    $oi->unit_price_tax_incl_formatted = $currency->format((float) ($oi->unit_price_tax_incl ?? 0));
                    $oi->unit_price_tax_excl_formatted = $currency->format((float) ($oi->unit_price_tax_excl ?? 0));
                    $oi->total_price_tax_incl_formatted = $currency->format((float) ($oi->total_price_tax_incl ?? 0));
                    $oi->total_price_tax_excl_formatted = $currency->format((float) ($oi->total_price_tax_excl ?? 0));
                    if ((float) ($oi->reduction_amount_tax_incl ?? 0) > 0) {
                        $oi->reduction_formatted = $currency->format((float) $oi->reduction_amount_tax_incl);
                    }
                }

                foreach ($item->_shipments as $os) {
                    $os->shipping_cost_formatted = $currency->format((float) ($os->shipping_cost_tax_incl ?? 0));
                }

                foreach ($item->_payments as $op) {
                    $op->amount_formatted = $currency->format(abs((float) ($op->amount ?? 0)));
                }

                foreach ($item->_discounts as $cr) {
                    $cr->value_formatted = $currency->format((float) ($cr->value_tax_excl ?? 0));
                }
            }
        }

        return $items;
    }

    // =====================================================================
    //  BATCH LOADERS — one indexed query per child table
    //
    //  Each returns [ orderId => [row objects...] ] via groupByOrder().
    //  Queries use WHERE id_order IN (...) — index seeks, no table scans.
    //  Columns selected include everything needed for:
    //    - OrderTotalHelper::computeFromArrays() (totals)
    //    - Fulfillment status calculation
    //    - Detail panel display
    // =====================================================================

    /**
     * Batch-load order items with shipment fulfillment status.
     *
     * LEFT JOINs to shipments to get per-item fulfillment status
     * (shipped/delivered) without a separate query.
     *
     * @param int[] $orderIds Order PKs from the current page
     *
     * @return array [ orderId => [item objects...] ]
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
                $db->quoteName('oi.reference'),
                $db->quoteName('oi.quantity'),
                $db->quoteName('oi.quantity_refunded'),
                $db->quoteName('oi.quantity_return'),
                $db->quoteName('oi.unit_price_tax_incl'),
                $db->quoteName('oi.unit_price_tax_excl'),
                $db->quoteName('oi.total_price_tax_incl'),
                $db->quoteName('oi.total_price_tax_excl'),
                $db->quoteName('oi.reduction_percent'),
                $db->quoteName('oi.reduction_amount_tax_incl'),
                $db->quoteName('oi.id_order_shipment'),
                // Shipment status for per-item fulfillment
                $db->quoteName('os.status', 'shipment_status'),
                $db->quoteName('os.tracking_number', 'shipment_tracking'),
            ])
            ->from($db->quoteName('#__alfa_order_items', 'oi'))
            ->join(
                'LEFT',
                $db->quoteName('#__alfa_order_shipments', 'os'),
                $db->quoteName('oi.id_order_shipment') . ' = ' . $db->quoteName('os.id'),
            )
            ->whereIn($db->quoteName('oi.id_order'), $orderIds);

        $db->setQuery($query);

        return $this->groupByOrder($db->loadObjectList(), 'id_order');
    }

    /**
     * Batch-load order shipments with tracking details.
     *
     * @param int[] $orderIds Order PKs
     *
     * @return array [ orderId => [shipment objects...] ]
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
     */
    private function batchLoadPayments(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id'),
                $db->quoteName('id_order'),
                $db->quoteName('payment_method'),
                $db->quoteName('amount'),
                $db->quoteName('payment_type'),
                $db->quoteName('transaction_status'),
                $db->quoteName('transaction_id'),
                $db->quoteName('id_refunded_payment'),
                $db->quoteName('refund_type'),
                $db->quoteName('refund_reason'),
                $db->quoteName('added'),
                $db->quoteName('processed_at'),
            ])
            ->from($db->quoteName('#__alfa_order_payments'))
            ->whereIn($db->quoteName('id_order'), $orderIds)
            ->order($db->quoteName('added') . ' ASC');

        $db->setQuery($query);

        return $this->groupByOrder($db->loadObjectList(), 'id_order');
    }

    /**
     * Batch-load active cart rules (discounts).
     *
     * @param int[] $orderIds Order PKs
     *
     * @return array [ orderId => [cart rule objects...] ]
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
                $db->quoteName('free_shipping'),
            ])
            ->from($db->quoteName('#__alfa_order_cart_rule'))
            ->whereIn($db->quoteName('id_order'), $orderIds)
            ->where($db->quoteName('deleted') . ' = 0');

        $db->setQuery($query);

        return $this->groupByOrder($db->loadObjectList(), 'id_order');
    }

    // =====================================================================
    //  HELPERS
    // =====================================================================

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

    // =====================================================================
    //  LOOKUP METHODS — delegates to AlfaHelper (single source of truth)
    //
    //  Thin wrappers so the View can call $this->get('OrderStatuses').
    //  Data + caching lives in AlfaHelper.
    // =====================================================================

    /**
     * Get all order statuses keyed by ID.
     * Delegates to AlfaHelper::getOrderStatuses() (cached).
     */
    public function getOrderStatuses(): array
    {
        return AlfaHelper::getOrderStatuses();
    }

    /**
     * Get all active payment methods keyed by ID.
     * Delegates to AlfaHelper::getPaymentMethods() (cached).
     */
    public function getPaymentMethods(): array
    {
        return AlfaHelper::getPaymentMethods();
    }

    /**
     * Get all active shipment methods keyed by ID.
     * Delegates to AlfaHelper::getShipmentMethods() (cached).
     */
    public function getShipmentMethods(): array
    {
        return AlfaHelper::getShipmentMethods();
    }
}
