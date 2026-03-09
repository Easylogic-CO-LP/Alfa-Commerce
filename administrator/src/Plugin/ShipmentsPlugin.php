<?php

/**
 * @package     Alfa.Component
 * @subpackage  Administrator.Plugin
 * @version     3.5.1
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2026 Easylogic CO LP
 * @license     GNU General Public License version 2 or later
 *
 * Base Shipment Plugin
 *
 * All shipment plugins extend this class.
 * Provides fluent builder wrappers for shipment operations
 * and empty action hooks for the admin UI.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  FLUENT BUILDER API (recommended for programmatic use):
 *
 *    CREATE:
 *      $id = $this->shipment($order)->pending()->withAllItems()->cost(12.50)->save();
 *      $id = $this->shipment($order)->delivered()->withAllItems()->cost(0)->save();
 *
 *    UPDATE:
 *      $this->shipmentUpdate($id)->shipped()->trackingNumber('TRACK123')->save();
 *      $this->shipmentUpdate($id)->delivered()->save();
 *      $this->shipmentUpdate($id)->cancelled()->save();
 *
 *  READ / DELETE / ITEMS (no builder needed):
 *    $shipment  = $this->getShipment($id);
 *    $shipments = $this->getShipmentsByOrder($orderId);
 *    $this->deleteShipment($id, $orderId);
 *    $this->assignShipmentItems($shipmentId, $orderId, [101, 102]);
 *
 *  LOGGING (inherited from Plugin.php):
 *    $logId = $this->log(['id_order' => $orderId, ...]);
 *    $logs  = $this->loadLogs($orderId, $shipmentId);
 *    $xml   = $this->getLogsSchema();
 *
 *  ADMIN ACTIONS (Fluent API on event):
 *    $event->add('mark_shipped', 'Mark as Shipped')
 *        ->icon('truck')->css('btn-primary')
 *        ->confirm('Ship this?')->priority(200);
 * ═══════════════════════════════════════════════════════════════════
 *
 * LOG IDENTIFIER: Sets logIdentifierField = 'id_order_shipment'
 *
 * Path: administrator/components/com_alfa/src/Plugin/ShipmentsPlugin.php
 *
 * @since  3.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

use Alfa\Component\Alfa\Administrator\Event\Shipments\CalculateShippingCostEvent;
use Alfa\Component\Alfa\Administrator\Helper\OrderShipmentHelper;
use RuntimeException;

defined('_JEXEC') or die;

abstract class ShipmentsPlugin extends Plugin
{
    /**
     * Events this plugin subscribes to.
     *
     * @since   3.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onItemView' => 'onItemView',
            'onCartView' => 'onCartView',
        ];
    }

    /**
     * Constructor.
     *
     * Sets logIdentifierField so $this->loadLogs($orderId, $shipmentId)
     * correctly filters by id_order_shipment in the plugin's log table.
     *
     * @param array $config Plugin configuration
     * @since   3.0.0
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->logIdentifierField = 'id_order_shipment';
    }

    // ==========================================================
    //  SHIPMENT FLUENT BUILDER WRAPPERS
    //
    //  These return an OrderShipmentHelper builder instance.
    //  The builder validates, auto-fills, and delegates to
    //  static CRUD methods internally.
    //
    //  For direct CRUD access (batch ops, admin form saves),
    //  use OrderShipmentHelper::create/update/delete() directly.
    // ==========================================================

    /**
     * Create a new shipment (returns fluent builder in CREATE mode).
     *
     * Auto-fills from order: id_order, id_shipment_method, weight.
     * Chain ->withAllItems() or ->withItems([...]) to assign items.
     *
     * Usage:
     *   $id = $this->shipment($order)->pending()->withAllItems()->cost(12.50)->save();
     *   $id = $this->shipment($order)->delivered()->withAllItems()->cost(0)->save();
     *   $id = $this->shipment($order)->pending()->withItems([101])->cost(8)->save();
     *
     * @param object $order Full order object (->id, ->items, ->id_shipment_method)
     *
     * @return OrderShipmentHelper Builder — chain setters, finish with ->save()
     *
     * @since   3.5.1
     */
    protected function shipment(object $order): OrderShipmentHelper
    {
        return OrderShipmentHelper::for($order);
    }

    /**
     * Update an existing shipment (returns fluent builder in UPDATE mode).
     *
     * Only fields you explicitly set are sent to the database.
     * Status shortcuts auto-set timestamps:
     *   ->shipped()   → sets 'shipped' timestamp
     *   ->delivered() → sets 'shipped' + 'delivered' timestamps
     *
     * Usage:
     *   $this->shipmentUpdate($id)->shipped()->trackingNumber('TRACK123')->save();
     *   $this->shipmentUpdate($id)->delivered()->save();
     *   $this->shipmentUpdate($id)->cancelled()->save();
     *
     * @param int $shipmentId Existing shipment row PK
     *
     * @return OrderShipmentHelper Builder — chain setters, finish with ->save()
     *
     * @throws RuntimeException If shipment not found in DB
     *
     * @since   3.5.1
     */
    protected function shipmentUpdate(int $shipmentId): OrderShipmentHelper
    {
        return OrderShipmentHelper::load($shipmentId);
    }

    // ==========================================================
    //  SHIPMENT READ / DELETE / ITEMS (no builder needed)
    // ==========================================================

    /**
     * Delete an order shipment record.
     *
     * Automatically clears item assignments before deletion.
     *
     * @param int $id Shipment row PK
     * @param int $orderId Order PK (for activity log)
     *
     * @return bool True on success
     *
     * @since   3.5.0
     */
    protected function deleteShipment(int $id, int $orderId): bool
    {
        return OrderShipmentHelper::delete($id, $orderId);
    }

    /**
     * Load a single shipment with method params attached.
     *
     * @param int $id Shipment row PK
     *
     * @return object|null Shipment record with ->params, or null
     *
     * @since   3.5.0
     */
    protected function getShipment(int $id): ?object
    {
        return OrderShipmentHelper::get($id);
    }

    /**
     * Load all shipments for an order (lightweight — no params).
     *
     * @param int $orderId Order PK
     *
     * @return array Array of shipment row objects
     *
     * @since   3.5.0
     */
    protected function getShipmentsByOrder(int $orderId): array
    {
        return OrderShipmentHelper::getByOrder($orderId);
    }

    /**
     * Assign order items to a shipment.
     *
     * Clears previous assignments for this shipment, then sets new ones.
     * Scoped to order to prevent cross-order item assignment.
     *
     * @param int $shipmentId Shipment PK
     * @param int $orderId Order PK (security scope)
     * @param array $itemIds Array of order_items.id values
     *
     * @return bool True on success
     *
     * @since   3.5.0
     */
    protected function assignShipmentItems(int $shipmentId, int $orderId, array $itemIds): bool
    {
        return OrderShipmentHelper::assignItems($shipmentId, $orderId, $itemIds);
    }

    // ==========================================================
    //  FRONTEND HOOKS (abstract — plugin MUST implement)
    // ==========================================================

    /**
     * Calculate shipping cost for the cart.
     *
     * @since   3.0.0
     */
    abstract public function onCalculateShippingCost(CalculateShippingCostEvent $event): void;

    // ==========================================================
    //  ADMIN ACTION HOOKS (empty — plugin overrides as needed)
    // ==========================================================

    /**
     * Register available action buttons for a shipment.
     *
     * Override this to add buttons using the fluent API:
     *
     *   $event->add('mark_shipped', 'Mark as Shipped')
     *       ->icon('truck')->css('btn-primary')
     *       ->confirm('Ship this?');
     *
     * Default: no actions.
     *
     * @param object $event GetShipmentActionsEvent
     * @since   3.0.0
     */
    public function onGetActions($event): void
    {
        // Empty — plugin overrides to register actions
    }

    /**
     * Handle an action button click for a shipment.
     *
     * Override and use match() to route actions:
     *
     *   match ($event->getAction()) {
     *       'mark_shipped' => $this->handleMarkShipped($event),
     *       'view_logs'    => $this->handleViewLogs($event),
     *       default        => $event->setError('Unknown action'),
     *   };
     *
     * Default: sets error "Unknown action".
     *
     * @param object $event ExecuteShipmentActionEvent
     * @since   3.0.0
     */
    public function onExecuteAction($event): void
    {
        $event->setError('Unknown action: ' . $event->getAction());
    }
}
