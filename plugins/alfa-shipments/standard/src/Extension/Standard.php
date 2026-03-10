<?php

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  STANDARD SHIPMENT PLUGIN — Reference Implementation
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * This file is the REFERENCE IMPLEMENTATION for all Alfa shipment plugins.
 * Copy this file as a starting point when building a new plugin (FedEx,
 * DHL, ACS Courier, Speedex, etc.). Every method, constant, and pattern
 * used here is explained in detail.
 *
 * ───────────────────────────────────────────────────────────────────────
 *  WHAT THIS PLUGIN DOES
 * ───────────────────────────────────────────────────────────────────────
 *
 * The Standard plugin handles offline shipments: manual fulfillment,
 * local delivery, in-store pickup. It includes a dimension-based shipping
 * cost calculator with zone/weight/zip matching. There is no external
 * carrier API — the admin manually marks shipments as shipped/delivered.
 *
 * ───────────────────────────────────────────────────────────────────────
 *  PLUGIN ANATOMY — Files & Structure
 * ───────────────────────────────────────────────────────────────────────
 *
 * plugins/alfa-shipments/standard/
 * ├── src/Extension/Standard.php        ← THIS FILE (main plugin class)
 * ├── params/
 * │   ├── logs.xml                      ← Plugin-specific log table schema
 * │   └── shipment.xml                  ← Shipping method config (cost zones)
 * ├── tmpl/                             ← Layout templates
 * │   ├── default_item_view.php         ← Product page (shipping info)
 * │   ├── default_cart_view.php         ← Cart page (method selector)
 * │   ├── action_view_details.php       ← Admin: shipment details modal
 * │   ├── action_tracking.php           ← Admin: tracking info modal
 * │   └── default_order_logs_view.php   ← Admin: plugin logs modal
 * ├── language/en-GB/
 * │   └── plg_alfashipments_standard.ini
 * └── services/provider.php             ← Joomla DI service provider
 *
 * ───────────────────────────────────────────────────────────────────────
 *  INHERITANCE CHAIN
 * ───────────────────────────────────────────────────────────────────────
 *
 * Standard extends ShipmentsPlugin extends Plugin extends CMSPlugin
 *
 *   CMSPlugin (Joomla)
 *     └─ Plugin (Alfa base)
 *          - Logging: log(), loadLogs(), deleteLog(), getLogsSchema()
 *          - Auto-creates plugin-specific log table from logs.xml
 *          - Utility: isValueInRange() for zip/dimension matching
 *          - Abstract hooks: onCartView(), onItemView()
 *
 *        └─ ShipmentsPlugin (Alfa shipments base)
 *              - Fluent builder: shipment(), shipmentUpdate()
 *              - Read/delete: getShipment(), deleteShipment()
 *              - Item assignment: assignShipmentItems()
 *              - Sets logIdentifierField = 'id_order_shipment'
 *              - Abstract: onCalculateShippingCost()
 *              - Empty action hooks: onGetActions(), onExecuteAction()
 *
 *           └─ Standard (THIS — concrete implementation)
 *                 - Implements all hooks
 *                 - Dimension-based cost calculator
 *                 - Admin actions: Mark Shipped, Mark Delivered, Cancel
 *                 - Tracking modal
 *                 - Structured plugin logs
 *
 * ───────────────────────────────────────────────────────────────────────
 *  SHIPMENT LIFECYCLE — State Machine
 * ───────────────────────────────────────────────────────────────────────
 *
 *   ┌──────────┐  Mark Shipped  ┌──────────┐  Mark Delivered  ┌───────────┐
 *   │ PENDING  │ ─────────────→ │ SHIPPED  │ ───────────────→ │ DELIVERED │
 *   └──────────┘                └──────────┘                  └───────────┘
 *        │
 *        │ Cancel
 *        ▼
 *   ┌───────────┐
 *   │ CANCELLED │
 *   └───────────┘
 *
 * Valid transitions for Standard (offline):
 *   pending   → shipped    (handleMarkShipped)  — sets 'shipped' timestamp
 *   shipped   → delivered  (handleMarkDelivered) — sets 'delivered' timestamp
 *   pending   → cancelled  (handleCancel)
 *
 * Builder auto-timestamps:
 *   ->shipped()   automatically sets the 'shipped' datetime column
 *   ->delivered() automatically sets BOTH 'shipped' + 'delivered' columns
 *                 (in case shipment goes straight to delivered)
 *
 * Carrier plugins (FedEx, DHL) may have additional transitions:
 *   pending → in_transit → out_for_delivery → delivered
 *   pending → exception → returned
 *   (Add statuses to OrderShipmentHelper::allStatuses() as needed)
 *
 * ───────────────────────────────────────────────────────────────────────
 *  SHIPPING COST CALCULATION — Dimension-Based Engine
 * ───────────────────────────────────────────────────────────────────────
 *
 * The Standard plugin calculates shipping by matching cart dimensions
 * against cost-per-place zones defined in the shipping method config:
 *
 *   1. Resolve delivery country (from cart user_info_delivery)
 *   2. Find matching zone in cost-per-place[] by country
 *   3. Aggregate product dimensions into single package:
 *      width:  summed (stacked side by side)
 *      height: max (tallest product)
 *      depth:  max (deepest product)
 *      weight: summed (all items × quantity)
 *   4. Sort zone's cost packages by price ascending
 *   5. First package where ALL dimensions fit = cheapest valid option
 *   6. Zip code must be in range (zip-start ≤ zip ≤ zip-end)
 *
 * Carrier plugins typically replace this with API calls:
 *   FedEx:    $fedex->getRates($origin, $destination, $packages)
 *   DHL:      $dhl->requestQuote($shipmentDetails)
 *
 * ───────────────────────────────────────────────────────────────────────
 *  FLUENT BUILDER API — Quick Reference
 * ───────────────────────────────────────────────────────────────────────
 *
 * CREATE (new shipment record):
 *   $id = $this->shipment($order)->pending()->withAllItems()->cost(12.50)->save();
 *   $id = $this->shipment($order)->pending()->withItems([101,102])->save();
 *   $id = $this->shipment($order)->shipped()->withAllItems()->cost(0)->save();
 *
 * UPDATE (modify existing):
 *   $this->shipmentUpdate($id)->shipped()->save();
 *   $this->shipmentUpdate($id)->delivered()->save();
 *   $this->shipmentUpdate($id)->cancelled()->save();
 *   $this->shipmentUpdate($id)->trackingNumber('TRACK123')->carrier('DHL')->save();
 *
 * READ (no builder needed):
 *   $shipment  = $this->getShipment($id);
 *   $shipments = $this->getShipmentsByOrder($orderId);
 *
 * DELETE / ITEMS (no builder needed):
 *   $this->deleteShipment($id, $orderId);
 *   $this->assignShipmentItems($shipmentId, $orderId, [101, 102]);
 *
 * ───────────────────────────────────────────────────────────────────────
 *  BUILDER SETTERS — Full List
 * ───────────────────────────────────────────────────────────────────────
 *
 * Status:   ->pending(), ->shipped(), ->delivered(), ->cancelled()
 *           (->shipped() auto-sets 'shipped' timestamp)
 *           (->delivered() auto-sets 'shipped' + 'delivered' timestamps)
 *
 * Items:    ->withAllItems(), ->withItems([101, 102])
 *
 * Cost:     ->cost($incl, $excl), ->cost($incl)  (excl defaults to same)
 *
 * Carrier:  ->trackingNumber($num), ->carrier($name)
 *
 * Other:    ->weight($kg), ->method($methodId), ->set($key, $value)
 *
 * Terminal: ->save() → int|false (shipment ID or false)
 * Debug:    ->toArray() → array (preview without saving)
 *
 * ───────────────────────────────────────────────────────────────────────
 *  LOGGING SYSTEM — Plugin-Specific Logs
 * ───────────────────────────────────────────────────────────────────────
 *
 * Log table: #__alfa_shipments_standard_logs
 *
 * Auto-created columns (by Plugin.php):
 *   id, id_order, id_order_shipment
 *
 * Plugin columns (from params/logs.xml):
 *   action, status, shipment_total, currency,
 *   tracking_number, carrier_name, note,
 *   created_on, created_by
 *
 * NOTE: Plugin logs are SEPARATE from the unified order_activity_log.
 * The activity log is written automatically by OrderShipmentHelper.
 * Plugin logs are for carrier-specific debugging data.
 *
 * ───────────────────────────────────────────────────────────────────────
 *  CREATING YOUR OWN SHIPMENT PLUGIN — Step by Step
 * ───────────────────────────────────────────────────────────────────────
 *
 * 1. Copy plugins/alfa-shipments/standard/ → plugins/alfa-shipments/fedex/
 * 2. Update namespace: Joomla\Plugin\AlfaShipments\FedEx\Extension
 * 3. Rename class: final class FedEx extends ShipmentsPlugin
 * 4. Update services/provider.php
 *
 * 5. Key differences for carrier plugins:
 *    - onCalculateShippingCost(): call carrier API for real-time rates
 *    - onOrderAfterPlace(): optionally create shipment label via API
 *    - handleMarkShipped(): call carrier pickup/manifest API
 *    - handleTrack(): fetch live tracking from carrier API
 *    - logs.xml: add carrier-specific columns (api_request_id, label_url)
 *    - shipment.xml: carrier credentials instead of cost zones
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Path: plugins/alfa-shipments/standard/src/Extension/Standard.php
 *
 * @since  3.0.0
 */

namespace Joomla\Plugin\AlfaShipments\Standard\Extension;

use Alfa\Component\Alfa\Administrator\Helper\OrderShipmentHelper;
use Alfa\Component\Alfa\Administrator\Plugin\ShipmentsPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;

defined('_JEXEC') or die;

final class Standard extends ShipmentsPlugin
{
    // =========================================================================
    //
    //  FRONTEND HOOKS — Customer-Facing Pages
    //
    //  These render HTML on the storefront. Each hook receives an event
    //  with the relevant context and the shipment method record.
    //  You MUST call setLayout() — even for an empty layout.
    //
    // =========================================================================

    /**
     * Product page — show shipping info (e.g. "Free shipping", "Ships in 2 days").
     *
     * For Standard: shows basic shipping text.
     * For FedEx: could show estimated delivery date from API.
     * For DHL: could show "Express delivery available" badge.
     *
     * @param object $event ItemViewEvent
     *                      $event->getItem()   → product object
     *                      $event->getMethod() → shipment method record
     * @since   3.0.0
     */
    public function onItemView($event): void
    {
        $event->setLayout('default_item_view');
        $event->setLayoutData([
            'method' => $event->getMethod(),
            'item' => $event->getItem(),
        ]);
    }

    /**
     * Cart page — show shipping method selector or cost estimate.
     *
     * For Standard: shows method name and calculated cost.
     * For FedEx: could show real-time rates for multiple service levels.
     *
     * @param object $event CartViewEvent
     *                      $event->getCart()    → cart object
     *                      $event->getMethod() → shipment method record
     * @since   3.0.0
     */
    public function onCartView($event): void
    {
        $event->setLayout('default_cart_view');
        $event->setLayoutData([
            'method' => $event->getMethod(),
            'item' => $event->getCart(),
        ]);
    }

    /**
     * Calculate shipping cost for the current cart and method.
     *
     * This is the CORE hook for shipping cost calculation.
     * Called during cart totals calculation for each active method.
     *
     * For Standard: dimension-based matching against cost zones.
     * For FedEx: call FedEx Rate API with package dimensions.
     * For DHL: call DHL Express Rating with shipment details.
     *
     * Available on $event:
     *   $event->getCart()                  → cart object (items, user address)
     *   $event->getMethod()               → shipment method record (with params)
     *   $event->setShippingCost($cost)     → set tax-inclusive cost
     *   $event->setShippingCostTaxExcl($cost) → set tax-exclusive cost
     *
     * @param object $event CalculateShippingCostEvent
     * @since   3.0.0
     */
    public function onCalculateShippingCost($event): void
    {
        $cart = $event->getCart();
        $method = $event->getMethod();

        $shippingCost = self::calculateShippingCost($cart, $method);

        $event->setShippingCost($shippingCost);
        $event->setShippingCostTaxExcl($shippingCost); // Standard: no tax on shipping
    }

    // =========================================================================
    //
    //  ORDER PLACEMENT HOOK
    //
    //  Called by OrderPlaceHelper::triggerAfterPlaceEvents() AFTER the
    //  order, items, and payment are committed to the database.
    //
    //  This is where you create the INITIAL shipment record.
    //
    // =========================================================================

    /**
     * Create the initial shipment record when an order is placed.
     *
     * WHEN: Called once per order, right after checkout completes.
     * WHO:  Triggered by the frontend OrderPlaceHelper.
     * WHAT: Creates ONE shipment with ALL items assigned.
     *
     * The fluent builder auto-fills:
     *   - id_order, id_shipment_method → from $order
     *   - weight → SUM(items.weight × quantity) auto-calculated
     *   - items → assigned via ->withAllItems()
     *   - added, id_employee, id_currency → by the CRUD engine
     *   - method name snapshot → survives method deletion
     *
     * Shipping cost comes from OrderPlaceHelper which already overrides
     * total_shipping_tax_incl/excl with cart values before this fires.
     *
     * For carrier plugins that auto-generate labels:
     *   $label = $fedex->createShipment($packageDetails);
     *   $id = $this->shipment($order)
     *       ->pending()
     *       ->withAllItems()
     *       ->cost($shippingCostIncl, $shippingCostExcl)
     *       ->trackingNumber($label->trackingNumber)
     *       ->carrier('FedEx')
     *       ->save();
     *
     * @param object $event OrderAfterPlaceEvent
     *                      $event->getOrder() → full order object
     * @since   3.5.1
     */
    public function onOrderAfterPlace($event): void
    {
        $order = $event->getOrder();

        if (!$order || empty($order->id)) {
            return;
        }

        // ── Extract shipping cost from order totals ──────────
        // OrderPlaceHelper already overrides these from CartHelper
        // before firing this event. No recalculation needed.
        $shippingCostIncl = 0.0;
        $shippingCostExcl = 0.0;

        if (isset($order->total_shipping_tax_incl) && is_object($order->total_shipping_tax_incl)) {
            $shippingCostIncl = $order->total_shipping_tax_incl->getAmount();
        }

        if (isset($order->total_shipping_tax_excl) && is_object($order->total_shipping_tax_excl)) {
            $shippingCostExcl = $order->total_shipping_tax_excl->getAmount();
        }

        // ── Create pending shipment via fluent builder ────────
        // ->pending() sets status = 'pending'
        // ->withAllItems() collects all order item IDs and assigns them
        // ->cost() sets shipping_cost_tax_incl + shipping_cost_tax_excl
        // ->save() auto-fills: weight, added, id_employee, id_currency, method name
        $shipmentId = $this->shipment($order)
            ->pending()
            ->withAllItems()
            ->cost($shippingCostIncl, $shippingCostExcl)
            ->save();

        if (!$shipmentId) {
            Log::add(
                'Standard shipment: Failed to create initial shipment for order #' . $order->id,
                Log::ERROR,
                'com_alfa.shipments',
            );
        }
    }

    // =========================================================================
    //
    //  ADMIN ACTION HOOKS — Button Registration + Execution
    //
    //  Two hooks control the admin action buttons next to each shipment
    //  in the order edit view:
    //
    //  1. onGetActions()    — register buttons based on current status
    //  2. onExecuteAction() — handle button clicks
    //
    // =========================================================================

    /**
     * Register action buttons based on the current shipment status.
     *
     * Called when the shipments list is rendered in the order edit view.
     *
     * Status logic:
     *   PENDING:   Mark Shipped + Cancel
     *   SHIPPED:   Mark Delivered + Track (if tracking number)
     *   DELIVERED: (none — lifecycle complete)
     *   CANCELLED: (none — lifecycle complete)
     *
     * @param object $event GetShipmentActionsEvent
     *                      $event->getShipment() → shipment record
     *                      $event->getOrder()    → order object
     *                      $event->add(name, label) → register button
     * @since   3.0.0
     */
    public function onGetActions($event): void
    {
        $shipment = $event->getShipment();
        $status = $shipment->status ?? 'pending';

        // ── ALWAYS AVAILABLE ─────────────────────────────────

        // View Details — opens a modal with shipment information
        $event->add('view_details', Text::_('COM_ALFA_VIEW_DETAILS'))
            ->icon('eye')->css('btn-outline-secondary')
            ->modal('action_view_details', Text::_('COM_ALFA_SHIPMENT_DETAILS') . ' #' . $shipment->id)
            ->priority(10);

        // View Logs — opens a modal with this shipment's plugin log history
        $event->add('view_logs', Text::_('COM_ALFA_VIEW_LOGS'))
            ->icon('list')->css('btn-outline-info')
            ->modal('default_order_logs_view', Text::_('COM_ALFA_SHIPMENT_LOGS') . ' #' . $shipment->id, 'lg')
            ->priority(5);

        // ── PENDING STATE ────────────────────────────────────

        if (in_array($status, [OrderShipmentHelper::STATUS_PENDING, ''])) {
            // Mark Shipped — transitions to shipped, auto-sets timestamp
            $event->add('mark_shipped', Text::_('PLG_ALFASHIPMENTS_STANDARD_MARK_SHIPPED'))
                ->icon('truck')->css('btn-success')
                ->confirm(Text::_('PLG_ALFASHIPMENTS_STANDARD_CONFIRM_MARK_SHIPPED'))
                ->priority(200);

            // Cancel — transitions to cancelled
            $event->add('cancel', Text::_('COM_ALFA_CANCEL'))
                ->icon('cancel')->css('btn-outline-danger')
                ->confirm(Text::_('PLG_ALFASHIPMENTS_STANDARD_CONFIRM_CANCEL'))
                ->priority(50);
        }

        // ── SHIPPED STATE ────────────────────────────────────

        if ($status === OrderShipmentHelper::STATUS_SHIPPED) {
            // Mark Delivered — transitions to delivered, auto-sets timestamp
            $event->add('mark_delivered', Text::_('PLG_ALFASHIPMENTS_STANDARD_MARK_DELIVERED'))
                ->icon('checkmark-circle')->css('btn-success')
                ->confirm(Text::_('PLG_ALFASHIPMENTS_STANDARD_CONFIRM_MARK_DELIVERED'))
                ->priority(200);

            // Track — only if tracking number exists
            $trackingNumber = $shipment->tracking_number ?? '';
            if (!empty($trackingNumber)) {
                $event->add('track', Text::_('PLG_ALFASHIPMENTS_STANDARD_TRACK'))
                    ->icon('location')->css('btn-outline-primary')
                    ->modal('action_tracking', Text::_('PLG_ALFASHIPMENTS_STANDARD_TRACKING') . ' #' . $shipment->id)
                    ->priority(100);
            }
        }

        // ── TERMINAL STATES (delivered, cancelled) ───────────
        // No actions — lifecycle is complete.
        // Carrier plugins might add "Return Shipment" for delivered.
    }

    /**
     * Handle an admin action button click.
     *
     * Routes the action name to the appropriate handler method.
     *
     * @param object $event ExecuteShipmentActionEvent
     *                      $event->getAction()   → action name string
     *                      $event->getShipment() → shipment record
     *                      $event->getOrder()    → order object
     * @since   3.0.0
     */
    public function onExecuteAction($event): void
    {
        match ($event->getAction()) {
            'mark_shipped' => $this->handleMarkShipped($event),
            'mark_delivered' => $this->handleMarkDelivered($event),
            'cancel' => $this->handleCancel($event),
            'view_details' => $this->handleViewDetails($event),
            'view_logs' => $this->handleViewLogs($event),
            'track' => $this->handleTrack($event),
            default => $event->setError(Text::sprintf('PLG_ALFASHIPMENTS_STANDARD_ERROR_UNKNOWN_ACTION', $event->getAction())),
        };
    }

    // =========================================================================
    //
    //  ACTION HANDLERS — Business Logic
    //
    //  Each handler follows the same pattern:
    //    1. Get shipment + order from event
    //    2. Use fluent builder to modify the shipment record
    //    3. Write a structured log entry with ALL schema columns
    //    4. Set success/error message on the event
    //    5. Request page refresh if data changed
    //
    //  Log schema columns (9 plugin-specific + 3 auto):
    //    Auto:    id, id_order, id_order_shipment
    //    Plugin:  action, status, shipment_total, currency,
    //             tracking_number, carrier_name, note,
    //             created_on, created_by
    //
    // =========================================================================

    /**
     * Mark shipment as shipped.
     *
     * Transition: pending → shipped
     *
     * What happens:
     *   1. Updates status to 'shipped' in #__alfa_order_shipments
     *   2. Builder auto-sets 'shipped' datetime column to now
     *   3. Items in this shipment now count as "fulfilled" in the
     *      orders list view (fulfillment = shipped OR delivered)
     *   4. Writes plugin log + automatic activity log (via helper)
     *
     * For carrier plugins, this might also:
     *   - Call carrier API to create manifest/pickup request
     *   - Generate shipping label PDF
     *   - Send tracking email to customer
     *
     * @param object $event ExecuteShipmentActionEvent
     * @since   4.0.0
     */
    private function handleMarkShipped($event): void
    {
        $shipment = $event->getShipment();
        $order = $event->getOrder();
        $cost = $this->getShipmentCost($shipment);
        $now = Factory::getDate('now', 'UTC')->toSql();

        // ->shipped() sets status = 'shipped' AND auto-sets 'shipped' timestamp
        $result = $this->shipmentUpdate((int) $shipment->id)
            ->shipped()
            ->save();

        if ($result === false) {
            $event->setError(Text::sprintf('PLG_ALFASHIPMENTS_STANDARD_ERROR_MARK_SHIPPED', $shipment->id));
            return;
        }

        // Write to the PLUGIN-SPECIFIC log table
        $this->log([
            'id_order' => (int) $order->id,
            'id_order_shipment' => (int) $shipment->id,
            'action' => 'mark_shipped',
            'status' => OrderShipmentHelper::STATUS_SHIPPED,
            'shipment_total' => $cost,
            'currency' => $order->id_currency ?? '',
            'tracking_number' => $shipment->tracking_number ?? null,
            'carrier_name' => $shipment->carrier_name ?? null,
            'note' => Text::_('PLG_ALFASHIPMENTS_STANDARD_LOG_MARKED_SHIPPED'),
            'created_on' => $now,
            'created_by' => (int) Factory::getApplication()->getIdentity()->id,
        ]);

        $event->setMessage(Text::sprintf('PLG_ALFASHIPMENTS_STANDARD_MSG_MARKED_SHIPPED', $shipment->id));
        $event->setRefresh(true);
    }

    /**
     * Mark shipment as delivered.
     *
     * Transition: shipped → delivered
     *
     * What happens:
     *   1. Updates status to 'delivered' in #__alfa_order_shipments
     *   2. Builder auto-sets BOTH 'shipped' + 'delivered' timestamps
     *      (shipped is set defensively — in case it was missed)
     *   3. Items remain "fulfilled" in the orders list
     *   4. Writes plugin log + automatic activity log
     *
     * For carrier plugins:
     *   - This might be triggered by a webhook instead of admin click
     *   - FedEx: EVENT.DELIVERED webhook → auto-call this handler
     *
     * @param object $event ExecuteShipmentActionEvent
     * @since   4.0.0
     */
    private function handleMarkDelivered($event): void
    {
        $shipment = $event->getShipment();
        $order = $event->getOrder();
        $cost = $this->getShipmentCost($shipment);
        $now = Factory::getDate('now', 'UTC')->toSql();

        // ->delivered() sets status + both shipped/delivered timestamps
        $result = $this->shipmentUpdate((int) $shipment->id)
            ->delivered()
            ->save();

        if ($result === false) {
            $event->setError(Text::sprintf('PLG_ALFASHIPMENTS_STANDARD_ERROR_MARK_DELIVERED', $shipment->id));
            return;
        }

        $this->log([
            'id_order' => (int) $order->id,
            'id_order_shipment' => (int) $shipment->id,
            'action' => 'mark_delivered',
            'status' => OrderShipmentHelper::STATUS_DELIVERED,
            'shipment_total' => $cost,
            'currency' => $order->id_currency ?? '',
            'tracking_number' => $shipment->tracking_number ?? null,
            'carrier_name' => $shipment->carrier_name ?? null,
            'note' => Text::_('PLG_ALFASHIPMENTS_STANDARD_LOG_MARKED_DELIVERED'),
            'created_on' => $now,
            'created_by' => (int) Factory::getApplication()->getIdentity()->id,
        ]);

        $event->setMessage(Text::sprintf('PLG_ALFASHIPMENTS_STANDARD_MSG_MARKED_DELIVERED', $shipment->id));
        $event->setRefresh(true);
    }

    /**
     * Cancel a pending shipment.
     *
     * Transition: pending → cancelled
     *
     * What happens:
     *   1. Updates status to 'cancelled'
     *   2. Items in this shipment become "unfulfilled" again
     *   3. Admin can re-assign items to a new shipment
     *
     * For carrier plugins:
     *   - Call carrier API to void the shipment/label
     *   - FedEx: $fedex->cancelShipment($trackingNumber)
     *
     * @param object $event ExecuteShipmentActionEvent
     * @since   4.0.0
     */
    private function handleCancel($event): void
    {
        $shipment = $event->getShipment();
        $order = $event->getOrder();
        $cost = $this->getShipmentCost($shipment);
        $now = Factory::getDate('now', 'UTC')->toSql();

        $result = $this->shipmentUpdate((int) $shipment->id)
            ->cancelled()
            ->save();

        if ($result === false) {
            $event->setError(Text::sprintf('PLG_ALFASHIPMENTS_STANDARD_ERROR_CANCEL', $shipment->id));
            return;
        }

        $this->log([
            'id_order' => (int) $order->id,
            'id_order_shipment' => (int) $shipment->id,
            'action' => 'cancel',
            'status' => OrderShipmentHelper::STATUS_CANCELLED,
            'shipment_total' => $cost,
            'currency' => $order->id_currency ?? '',
            'tracking_number' => $shipment->tracking_number ?? null,
            'carrier_name' => $shipment->carrier_name ?? null,
            'note' => Text::_('PLG_ALFASHIPMENTS_STANDARD_LOG_CANCELLED'),
            'created_on' => $now,
            'created_by' => (int) Factory::getApplication()->getIdentity()->id,
        ]);

        $event->setMessage(Text::sprintf('PLG_ALFASHIPMENTS_STANDARD_MSG_CANCELLED', $shipment->id));
        $event->setRefresh(true);
    }

    // =========================================================================
    //
    //  MODAL ACTION HANDLERS
    //
    // =========================================================================

    /**
     * Show shipment details in a modal.
     *
     * @param object $event ExecuteShipmentActionEvent
     * @since   3.0.0
     */
    private function handleViewDetails($event): void
    {
        $shipment = $event->getShipment();

        $event->setLayout('action_view_details');
        $event->setLayoutData([
            'shipment' => $shipment,
            'order' => $event->getOrder(),
        ]);
        $event->setModalTitle(Text::_('COM_ALFA_SHIPMENT_DETAILS') . ' #' . $shipment->id);
    }

    /**
     * Show plugin-specific logs in a modal.
     *
     * loadLogs() automatically filters by id_order_shipment because
     * ShipmentsPlugin sets logIdentifierField = 'id_order_shipment'.
     *
     * @param object $event ExecuteShipmentActionEvent
     * @since   3.5.0
     */
    private function handleViewLogs($event): void
    {
        $shipment = $event->getShipment();
        $order = $event->getOrder();

        $logData = $this->loadLogs((int) $order->id, (int) $shipment->id);

        $event->setLayout('default_order_logs_view');
        $event->setLayoutData([
            'logData' => $logData ?? [],
            'xml' => $this->getLogsSchema(),
        ]);
        $event->setModalTitle(Text::_('COM_ALFA_SHIPMENT_LOGS') . ' #' . $shipment->id);
    }

    /**
     * Show tracking info in a modal.
     *
     * For Standard: just displays the tracking number.
     * For carrier plugins: could fetch live tracking from API:
     *   $tracking = $fedex->track($shipment->tracking_number);
     *   $event->setLayoutData(['events' => $tracking->events, ...]);
     *
     * @param object $event ExecuteShipmentActionEvent
     * @since   3.0.0
     */
    private function handleTrack($event): void
    {
        $shipment = $event->getShipment();

        $event->setLayout('action_tracking');
        $event->setLayoutData([
            'shipment' => $shipment,
            'order' => $event->getOrder(),
            'tracking_number' => $shipment->tracking_number ?? '',
            'tracking_url' => $shipment->tracking_url ?? '',
        ]);
        $event->setModalTitle(Text::_('PLG_ALFASHIPMENTS_STANDARD_TRACKING') . ' #' . $shipment->id);
    }

    // =========================================================================
    //
    //  SHIPPING COST CALCULATION — Dimension-Based Engine
    //
    //  Products are aggregated by dimensions, then matched against
    //  cost zones sorted by price. Cheapest valid match wins.
    //
    //  Carrier plugins typically REPLACE this entire section with
    //  real-time API rate calls.
    //
    // =========================================================================

    /**
     * Calculate shipping cost for the current cart and method.
     *
     * Resolves the delivery country, finds the matching cost-per-place
     * zone, then delegates to findBestShippingMethod() for dimension matching.
     *
     * @param object $cart Cart object (getData() returns cart data)
     * @param object $method Shipping method record with params (cost zones)
     * @return float|int Shipping cost (0 if no match)
     * @since   3.0.0
     */
    public function calculateShippingCost($cart, $method)
    {
        $cartData = $cart->getData();
        $shipmentPackages = $method->params;
        $countrySelected = 84; // Greece (default — TODO: from cart address)

        // Get zip code from delivery address (default: 000000)
        $zipCode = '000000';
        if (isset($cartData->user_info_delivery->zip_code) && !empty($cartData->user_info_delivery->zip_code)) {
            $zipCode = $cartData->user_info_delivery->zip_code;
        }

        // Find the cost zone matching the delivery country
        $calculationData = null;
        foreach ($shipmentPackages['cost-per-place'] as $entry) {
            if (isset($entry['places'])) {
                foreach ($entry['places'] as $place) {
                    if ($place == $countrySelected) {
                        $calculationData = $entry;
                    }
                }
            } else {
                // Fallback zone (no country restriction — catch-all)
                $calculationData = $entry;
            }
        }

        if (empty($calculationData)) {
            return 0;
        }

        return self::findBestShippingMethod($cartData->items, $calculationData['costs'], $zipCode);
    }

    /**
     * Find the cheapest shipping package that fits the cart dimensions.
     *
     * Algorithm:
     *   1. Aggregate all product dimensions into one package
     *   2. Sort available methods by cost ascending
     *   3. First method where ALL dimensions fit AND zip is in range = result
     *
     * @param array $products Cart items (with ->width, ->height, etc.)
     * @param array $shippingMethods Cost packages with dimension limits
     * @param string|int $zipCode Delivery zip (-1 to skip check)
     * @return float|int Shipping cost (0 if nothing fits)
     * @since   3.0.0
     */
    public function findBestShippingMethod($products, $shippingMethods, $zipCode = -1)
    {
        if (empty($products) || empty($shippingMethods)) {
            return 0;
        }

        $packageDimensions = self::getTotalDimensions($products);

        // Sort by cost ascending — first valid match is cheapest
        usort($shippingMethods, fn ($a, $b) => $a['cost'] <=> $b['cost']);

        foreach ($shippingMethods as $method) {
            if ($packageDimensions['width'] <= $method['width-max']
                && $packageDimensions['height'] <= $method['height-max']
                && $packageDimensions['depth'] <= $method['depth-max']
                && $packageDimensions['weight'] <= $method['weight-max']
                && $this->isValueInRange($zipCode, $method['zip-start'], $method['zip-end'])) {
                return $method['cost'];
            }
        }

        return 0;
    }

    /**
     * Aggregate product dimensions into a single package.
     *
     * Width:  summed (products stacked side by side)
     * Height: max (tallest product determines box height)
     * Depth:  max (deepest product determines box depth)
     * Weight: summed (all items × quantity)
     *
     * @param array $products Cart items with dimension properties
     * @return array ['width' => float, 'height' => float, 'depth' => float, 'weight' => float]
     * @since   3.0.0
     */
    public function getTotalDimensions($products)
    {
        $totalWidth = 0;
        $maxHeight = 0;
        $maxDepth = 0;
        $totalWeight = 0;

        foreach ($products as $product) {
            $totalWidth += $product->width * $product->quantity;
            $maxHeight = max($maxHeight, $product->height);
            $maxDepth = max($maxDepth, $product->depth);
            $totalWeight += $product->weight * $product->quantity;
        }

        return [
            'width' => $totalWidth,
            'height' => $maxHeight,
            'depth' => $maxDepth,
            'weight' => $totalWeight,
        ];
    }

    // =========================================================================
    //
    //  UTILITY METHODS
    //
    // =========================================================================

    /**
     * Extract the shipment cost as a float.
     *
     * Safely handles Money objects, raw floats, and strings.
     *
     * @param object $shipment Shipment record
     * @return float Shipping cost (tax incl) as a plain float
     * @since   3.5.0
     */
    private function getShipmentCost(object $shipment): float
    {
        $cost = $shipment->shipping_cost_tax_incl ?? 0;

        if (is_object($cost) && method_exists($cost, 'getAmount')) {
            return (float) $cost->getAmount();
        }

        return (float) $cost;
    }
}
