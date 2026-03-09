<?php

/**
 * @package     Alfa.Component
 * @subpackage  Administrator.Helper
 * @version     3.5.1
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2026 Easylogic CO LP
 * @license     GNU General Public License version 2 or later
 *
 * Order Shipment Helper — Unified CRUD Engine + Fluent Builder
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  TWO APIs, ONE CLASS — accessible from anywhere
 * ═══════════════════════════════════════════════════════════════════════
 *
 *  HIGH-LEVEL — Fluent Builder (recommended):
 *
 *    CREATE:  OrderShipmentHelper::for($order)->pending()->withAllItems()->cost(12.50)->save();
 *    UPDATE:  OrderShipmentHelper::load($id)->shipped()->trackingNumber('TRACK123')->save();
 *    PLUGIN:  $this->shipment($order)->pending()->withAllItems()->cost(5)->save();
 *    PLUGIN:  $this->shipmentUpdate($id)->shipped()->trackingNumber('TRACK123')->save();
 *    ADMIN:   OrderShipmentHelper::for($order)->pending()->withItems([101])->cost(8)->save();
 *    CLI:     OrderShipmentHelper::load($id)->delivered()->save();
 *
 *  LOW-LEVEL — Static CRUD (escape hatch, batch operations):
 *
 *    OrderShipmentHelper::create(['id_order' => 1, 'status' => 'pending', ...]);
 *    OrderShipmentHelper::update(5, ['status' => 'shipped', 'tracking_number' => 'T123']);
 *    OrderShipmentHelper::delete(5, 1);
 *    OrderShipmentHelper::get(5);
 *    OrderShipmentHelper::getByOrder(1);
 *    OrderShipmentHelper::assignItems(5, 1, [101, 102]);
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  AUTO-FILLED VALUES:
 * ═══════════════════════════════════════════════════════════════════════
 *
 *   By Builder (from $order):
 *     id_order, id_shipment_method, weight (SUM of items.weight × quantity)
 *
 *   By Status Shortcuts:
 *     ->shipped()   → auto-sets 'shipped' timestamp
 *     ->delivered() → auto-sets 'shipped' + 'delivered' timestamps
 *
 *   By static::create() (at DB save):
 *     added, id_employee, id_currency, shipment_method_name snapshot
 *
 * Path: administrator/components/com_alfa/src/Helper/OrderShipmentHelper.php
 *
 * @since  3.5.0
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use RuntimeException;

class OrderShipmentHelper
{
    // =====================================================================
    //  CONSTANTS — Shipment Status
    //
    //  The ONLY valid values for #__alfa_order_shipments.status.
    //  Use builder shortcut methods for IDE autocomplete.
    // =====================================================================

    /** @var string Created, not yet dispatched */
    public const STATUS_PENDING = 'pending';

    /** @var string Package dispatched to carrier */
    public const STATUS_SHIPPED = 'shipped';

    /** @var string Package received by customer */
    public const STATUS_DELIVERED = 'delivered';

    /** @var string Shipment cancelled before dispatch */
    public const STATUS_CANCELLED = 'cancelled';

    /** @var string Paused (stock issue, address verification, etc.) */
    public const STATUS_ON_HOLD = 'on_hold';

    /** @var string Package returned by customer or carrier */
    public const STATUS_RETURNED = 'returned';

    // =====================================================================
    //  CONSTANTS — Table Configuration
    // =====================================================================

    /** @var string Database table for shipments */
    private const TABLE = '#__alfa_order_shipments';

    /** @var string Database table for order items (FK: id_order_shipment) */
    private const ITEMS_TABLE = '#__alfa_order_items';

    /** @var string Primary key column */
    private const PK = 'id';

    /**
     * Nullable integer columns — form sends '', MySQL rejects for int.
     * Sanitized to null in saveToTable().
     */
    private const NULLABLE_INT_COLUMNS = ['id_order_invoice', 'id_employee'];

    /**
     * Nullable datetime columns — empty/zero → null in saveToTable().
     */
    private const NULLABLE_DATE_COLUMNS = ['shipped', 'delivered'];

    // =====================================================================
    //  BUILDER STATE (instance properties)
    // =====================================================================

    /** @var array Data accumulator */
    protected array $data = [];

    /** @var object|null Order context (null in update mode) */
    protected ?object $order = null;

    /** @var string 'create' or 'update' */
    protected string $mode = 'create';

    /** @var int|null Existing shipment PK (update mode) */
    protected ?int $shipmentId = null;

    /**
     * Item IDs to assign to this shipment.
     *   null  → no item operation (default)
     *   []    → explicitly empty (withNoItems)
     *   [1,2] → specific items
     */
    protected ?array $itemIds = null;

    // =====================================================================
    //
    //  FLUENT BUILDER API
    //
    // =====================================================================

    /**
     * CREATE mode — build a new shipment for an order.
     *
     * Auto-fills: id_order, id_shipment_method, weight (from items).
     *
     * Usage:
     *   OrderShipmentHelper::for($order)->pending()->withAllItems()->cost(12.50)->save();
     *   OrderShipmentHelper::for($order)->delivered()->withAllItems()->cost(0)->save();
     *
     * @param object $order Full order object (->id, ->items, ->id_shipment_method)
     *
     * @return static Builder in create mode
     *
     * @since   3.5.1
     */
    public static function for(object $order): static
    {
        $builder = new static();
        $builder->mode = 'create';
        $builder->order = $order;
        $builder->data = [
            'id_order' => (int) $order->id,
            'id_shipment_method' => (int) ($order->id_shipment_method ?? 0),
            'weight' => self::calculateWeight($order),
        ];

        return $builder;
    }

    /**
     * UPDATE mode — modify an existing shipment.
     *
     * Loads existing shipment from DB to validate existence and get id_order.
     *
     * Usage:
     *   OrderShipmentHelper::load($id)->shipped()->trackingNumber('TRACK123')->save();
     *   OrderShipmentHelper::load($id)->delivered()->save();
     *
     * @param int $shipmentId Existing shipment row PK
     *
     * @return static Builder in update mode
     *
     * @throws RuntimeException If shipment not found
     *
     * @since   3.5.1
     */
    public static function load(int $shipmentId): static
    {
        $existing = self::getRaw($shipmentId);

        if (!$existing) {
            throw new RuntimeException(
                "OrderShipmentHelper::load(): Shipment #{$shipmentId} not found",
            );
        }

        $builder = new static();
        $builder->mode = 'update';
        $builder->shipmentId = $shipmentId;
        $builder->data = [
            'id_order' => (int) ($existing->id_order ?? 0),
        ];

        return $builder;
    }

    // ─── Status Setters ──────────────────────────────────────────────

    /**
     * Set status to PENDING — created, not dispatched.
     */
    public function pending(): static
    {
        $this->data['status'] = self::STATUS_PENDING;
        return $this;
    }

    /**
     * Set status to SHIPPED — auto-sets 'shipped' timestamp.
     */
    public function shipped(): static
    {
        $this->data['status'] = self::STATUS_SHIPPED;

        if (empty($this->data['shipped'])) {
            $this->data['shipped'] = Factory::getDate('now', 'UTC')->toSql();
        }

        return $this;
    }

    /**
     * Set status to DELIVERED — auto-sets 'shipped' + 'delivered' timestamps.
     *
     * Use for: digital products (instant delivery), local pickup.
     */
    public function delivered(): static
    {
        $this->data['status'] = self::STATUS_DELIVERED;

        $now = Factory::getDate('now', 'UTC')->toSql();

        if (empty($this->data['shipped'])) {
            $this->data['shipped'] = $now;
        }
        if (empty($this->data['delivered'])) {
            $this->data['delivered'] = $now;
        }

        return $this;
    }

    /**
     * Set status to CANCELLED.
     */
    public function cancelled(): static
    {
        $this->data['status'] = self::STATUS_CANCELLED;
        return $this;
    }

    /**
     * Set status to ON_HOLD.
     * Use for: waiting for stock, address verification, customs hold.
     */
    public function onHold(): static
    {
        $this->data['status'] = self::STATUS_ON_HOLD;
        return $this;
    }

    /**
     * Set status to RETURNED.
     */
    public function returned(): static
    {
        $this->data['status'] = self::STATUS_RETURNED;
        return $this;
    }

    // ─── Item Assignment ─────────────────────────────────────────────

    /**
     * Assign ALL order items to this shipment.
     */
    public function withAllItems(): static
    {
        $this->itemIds = [];

        if ($this->order && !empty($this->order->items)) {
            foreach ($this->order->items as $item) {
                $id = (int) ($item->id ?? 0);
                if ($id > 0) {
                    $this->itemIds[] = $id;
                }
            }
        }

        return $this;
    }

    /**
     * Assign specific items by order_items.id (not product ID).
     *
     * @param array $itemIds Array of order_items row PKs
     */
    public function withItems(array $itemIds): static
    {
        $this->itemIds = array_map('intval', $itemIds);
        return $this;
    }

    /**
     * Create shipment with no items assigned (assign later from admin).
     */
    public function withNoItems(): static
    {
        $this->itemIds = [];
        return $this;
    }

    // ─── Data Setters ────────────────────────────────────────────────

    /**
     * Set shipping cost.
     *
     * @param float $taxIncl Cost including tax
     * @param float|null $taxExcl Cost excluding tax (defaults to taxIncl if null)
     */
    public function cost(float $taxIncl, ?float $taxExcl = null): static
    {
        $this->data['shipping_cost_tax_incl'] = $taxIncl;
        $this->data['shipping_cost_tax_excl'] = $taxExcl ?? $taxIncl;
        return $this;
    }

    /**
     * Set carrier tracking number.
     *
     * @param string $number Carrier tracking reference
     */
    public function trackingNumber(string $number): static
    {
        $this->data['tracking_number'] = $number;
        return $this;
    }

    /**
     * Set tracking URL for customer.
     *
     * @param string $url Full tracking URL
     */
    public function trackingUrl(string $url): static
    {
        $this->data['tracking_url'] = $url;
        return $this;
    }

    /**
     * Override the auto-calculated weight.
     *
     * @param float $weight Total weight in configured units
     */
    public function weight(float $weight): static
    {
        $this->data['weight'] = $weight;
        return $this;
    }

    /**
     * Override the shipment method (normally from order).
     *
     * @param int $methodId Shipment method PK
     */
    public function method(int $methodId): static
    {
        $this->data['id_shipment_method'] = $methodId;
        return $this;
    }

    /**
     * Escape hatch — set any column value directly.
     *
     * @param string $key Column name
     * @param mixed $value Value to set
     */
    public function set(string $key, mixed $value): static
    {
        $this->data[$key] = $value;
        return $this;
    }

    // ─── Terminal Operation ──────────────────────────────────────────

    /**
     * Validate and persist the shipment record.
     *
     * @return int|false Shipment ID on success, false on failure
     *
     * @throws RuntimeException If status not set (create mode)
     *
     * @since   3.5.1
     */
    public function save(): int|false
    {
        if ($this->mode === 'update') {
            return $this->doBuilderUpdate();
        }

        return $this->doBuilderCreate();
    }

    // ─── Introspection ───────────────────────────────────────────────

    /**
     * Get all valid shipment status values.
     *
     * @return array ['pending', 'shipped', 'delivered', 'cancelled', 'on_hold', 'returned']
     */
    public static function allStatuses(): array
    {
        return [
            self::STATUS_PENDING, self::STATUS_SHIPPED, self::STATUS_DELIVERED,
            self::STATUS_CANCELLED, self::STATUS_ON_HOLD, self::STATUS_RETURNED,
        ];
    }

    /**
     * Get current builder mode.
     *
     * @return string 'create' or 'update'
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Preview data without saving.
     */
    public function toArray(): array
    {
        $data = $this->data;

        if ($this->itemIds !== null) {
            $data['items'] = $this->itemIds;
        }

        return $data;
    }

    // ─── Builder Internal Methods ────────────────────────────────────

    /**
     * Execute CREATE flow.
     */
    protected function doBuilderCreate(): int|false
    {
        if (empty($this->data['status'])) {
            throw new RuntimeException(
                'OrderShipmentHelper: status is required. '
                . 'Call ->pending(), ->shipped(), ->delivered(), ->cancelled(), '
                . '->onHold(), or ->returned() before ->save(). '
                . 'Valid values: ' . implode(', ', self::allStatuses()),
            );
        }

        // Attach item assignments for create()
        if ($this->itemIds !== null) {
            $this->data['items'] = $this->itemIds;
        }

        return static::create($this->data);
    }

    /**
     * Execute UPDATE flow.
     */
    protected function doBuilderUpdate(): int|false
    {
        if (!$this->shipmentId) {
            throw new RuntimeException(
                'OrderShipmentHelper: No shipment ID for update. Use ::load($id) first.',
            );
        }

        // Attach items to data so update() handles assignment + logging
        if ($this->itemIds !== null) {
            $this->data['items'] = $this->itemIds;
        }

        $changes = array_diff_key($this->data, ['id_order' => true]);

        if (empty($changes)) {
            return $this->shipmentId; // No-op
        }

        $success = static::update($this->shipmentId, $this->data);

        return $success ? $this->shipmentId : false;
    }

    /**
     * Sum weight × quantity from order items.
     *
     * @param object $order Order with ->items
     */
    protected static function calculateWeight(object $order): float
    {
        $weight = 0.0;

        foreach ($order->items ?? [] as $item) {
            $weight += (float) ($item->weight ?? 0) * (int) ($item->quantity ?? 1);
        }

        return round($weight, 4);
    }

    // =====================================================================
    //
    //  STATIC CRUD ENGINE
    //
    // =====================================================================

    /**
     * Create a new order shipment record.
     *
     * If 'items' key is present, assigns those order items after creation.
     *
     * @param array $data Shipment data. 'id_order' is REQUIRED.
     *
     * @return int|false New shipment row ID, or false on failure
     *
     * @since   3.5.0
     */
    public static function create(array $data): int|false
    {
        $orderId = (int) ($data['id_order'] ?? 0);

        if ($orderId <= 0) {
            self::warn('create(): id_order is required');
            return false;
        }

        // Extract item assignments (not a DB column)
        $selectedItems = $data['items'] ?? [];
        unset($data['items']);

        // Ensure insert
        unset($data[self::PK]);

        // Convert Money → float
        $data['shipping_cost_tax_incl'] = self::moneyToFloat($data['shipping_cost_tax_incl'] ?? 0);
        $data['shipping_cost_tax_excl'] = self::moneyToFloat($data['shipping_cost_tax_excl'] ?? 0);

        // Snapshot method name (survives deletion)
        if (!empty($data['id_shipment_method']) && empty($data['shipment_method_name'])) {
            $data['shipment_method_name'] = self::resolveMethodName(
                'Shipment',
                (int) $data['id_shipment_method'],
            );
        }

        // Auto-fill defaults
        if (empty($data['added'])) {
            $data['added'] = Factory::getDate('now', 'UTC')->toSql();
        }
        if (empty($data['id_employee'])) {
            $data['id_employee'] = self::getCurrentUserId();
        }
        if (empty($data['id_currency']) && $orderId > 0) {
            $data['id_currency'] = self::getOrderCurrencyId($orderId);
        }

        $success = self::saveToTable($data);

        if (!$success) {
            return false;
        }

        $newId = (int) $data[self::PK];

        // Assign items
        if (!empty($selectedItems)) {
            self::assignItems($newId, $orderId, $selectedItems);
        }

        // Log activity
        $methodName = $data['shipment_method_name'] ?? '';
        $costIncl = number_format((float) $data['shipping_cost_tax_incl'], 2);
        $itemNames = self::getItemNames($newId);
        $logSuffix = $itemNames ? " [{$itemNames}]" : '';

        OrderModel::logOrderActivity(
            $orderId,
            'shipment.added',
            "Added shipment: {$methodName} — {$costIncl}{$logSuffix}",
            [
                'shipping_cost_tax_incl' => (float) $data['shipping_cost_tax_incl'],
                'shipping_cost_tax_excl' => (float) $data['shipping_cost_tax_excl'],
                'shipment_method_name' => $methodName,
                'tracking_number' => $data['tracking_number'] ?? '',
                'assigned_items' => array_map('intval', $selectedItems),
            ],
            $newId,
        );

        return $newId;
    }

    /**
     * Update an existing order shipment record.
     *
     * If 'items' key is present, item assignments are updated.
     *
     * @param int $id Shipment row PK
     * @param array $data Fields to update. 'id_order' is REQUIRED.
     *
     * @return bool True on success
     *
     * @since   3.5.0
     */
    public static function update(int $id, array $data): bool
    {
        if ($id <= 0) {
            self::warn('update(): invalid shipment ID');
            return false;
        }

        $orderId = (int) ($data['id_order'] ?? 0);

        if ($orderId <= 0) {
            self::warn('update(): id_order is required for activity logging');
            return false;
        }

        // Extract items before DB save
        $selectedItems = $data['items'] ?? null;
        unset($data['items']);

        $data[self::PK] = $id;

        $oldSnapshot = self::getRaw($id);
        $oldItemIds = self::getItemIds($id);

        // Convert Money
        if (array_key_exists('shipping_cost_tax_incl', $data)) {
            $data['shipping_cost_tax_incl'] = self::moneyToFloat($data['shipping_cost_tax_incl']);
        }
        if (array_key_exists('shipping_cost_tax_excl', $data)) {
            $data['shipping_cost_tax_excl'] = self::moneyToFloat($data['shipping_cost_tax_excl']);
        }

        // Snapshot method name if changed
        if (!empty($data['id_shipment_method']) && empty($data['shipment_method_name'])) {
            $data['shipment_method_name'] = self::resolveMethodName(
                'Shipment',
                (int) $data['id_shipment_method'],
            );
        }

        $success = self::saveToTable($data);

        if (!$success) {
            return false;
        }

        // Reassign items
        if ($selectedItems !== null) {
            self::assignItems($id, $orderId, $selectedItems);
        }

        // Log changes
        if ($oldSnapshot) {
            $trackFields = [
                'shipping_cost_tax_incl', 'shipping_cost_tax_excl',
                'id_shipment_method', 'shipment_method_name',
                'tracking_number', 'weight', 'status',
                'shipped', 'delivered',
            ];
            $changes = AlfaHelper::buildDiff($oldSnapshot, $data, $trackFields);

            if ($selectedItems !== null) {
                $newItemIds = array_map('intval', $selectedItems);
                sort($oldItemIds);
                sort($newItemIds);

                if ($oldItemIds !== $newItemIds) {
                    $changes['assigned_items'] = ['from' => $oldItemIds, 'to' => $newItemIds];
                }
            }

            if (!empty($changes)) {
                $changedKeys = implode(', ', array_keys($changes));
                OrderModel::logOrderActivity(
                    $orderId,
                    'shipment.edited',
                    "Edited shipment #{$id}: {$changedKeys}",
                    $changes,
                    $id,
                );
            }
        }

        return true;
    }

    /**
     * Delete an order shipment record.
     *
     * Clears item assignments (sets id_order_shipment = NULL) before
     * deleting to maintain referential integrity.
     *
     * @param int $id Shipment row PK
     * @param int $orderId Order PK (for activity log)
     *
     * @return bool True on success
     *
     * @since   3.5.0
     */
    public static function delete(int $id, int $orderId): bool
    {
        $shipment = self::getRaw($id);

        // Clear item assignments first (FK integrity)
        self::clearItemAssignments($id, $orderId);

        $success = self::deleteFromTable($id);

        if ($success && $shipment) {
            $methodName = $shipment->shipment_method_name ?? '';
            $costIncl = number_format((float) ($shipment->shipping_cost_tax_incl ?? 0), 2);

            OrderModel::logOrderActivity(
                $orderId,
                'shipment.deleted',
                "Deleted shipment: {$methodName} — {$costIncl}",
                [
                    'shipping_cost_tax_incl' => (float) ($shipment->shipping_cost_tax_incl ?? 0),
                    'shipment_method_name' => $methodName,
                    'tracking_number' => $shipment->tracking_number ?? '',
                ],
                $id,
            );
        }

        return $success;
    }

    // =====================================================================
    //  STATIC READ API
    // =====================================================================

    /**
     * Load a single shipment with method params.
     *
     * @param int $id Shipment row PK
     *
     * @return object|null Shipment with ->params, or null
     *
     * @since   3.5.0
     */
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
        $shipment = $db->loadObject();

        if (!$shipment) {
            return null;
        }

        try {
            $model = self::getRelatedModel('Shipment');
            $shipment->params = $model->getItem($shipment->id_shipment_method);
        } catch (Exception $e) {
            $shipment->params = null;
        }

        return $shipment;
    }

    /**
     * Load all shipments for an order (lightweight).
     *
     * @param int $orderId Order PK
     *
     * @return array Array of shipment row objects
     *
     * @since   3.5.0
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
            ->where('id_order = ' . (int) $orderId);

        $db->setQuery($query);

        return $db->loadObjectList() ?: [];
    }

    // =====================================================================
    //  STATIC ITEM ASSIGNMENT API
    // =====================================================================

    /**
     * Assign order items to a shipment.
     *
     * Clears previous assignments for this shipment, then sets new ones.
     * Scoped to order to prevent cross-order item assignment.
     *
     * @param int $shipmentId Shipment PK
     * @param int $orderId Order PK (security scope)
     * @param array $itemIds order_items.id values to assign
     *
     * @return bool True on success
     *
     * @since   3.5.0
     */
    public static function assignItems(int $shipmentId, int $orderId, array $itemIds): bool
    {
        $db = self::db();

        try {
            // Clear previous assignments for this shipment
            self::clearItemAssignments($shipmentId, $orderId);

            // Assign new items (scoped to order)
            if (!empty($itemIds)) {
                $query = $db->getQuery(true)
                    ->update($db->quoteName(self::ITEMS_TABLE))
                    ->set($db->quoteName('id_order_shipment') . ' = ' . (int) $shipmentId)
                    ->where($db->quoteName('id_order') . ' = ' . (int) $orderId)
                    ->whereIn('id', array_map('intval', $itemIds));

                $db->setQuery($query);
                $db->execute();
            }

            return true;
        } catch (Exception $e) {
            self::warn('assignItems() failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get item IDs currently assigned to a shipment.
     *
     * @param int $shipmentId Shipment PK
     *
     * @return array Array of order_items.id values
     *
     * @since   3.5.0
     */
    public static function getItemIds(int $shipmentId): array
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select('id')
            ->from(self::ITEMS_TABLE)
            ->where($db->quoteName('id_order_shipment') . ' = ' . (int) $shipmentId);

        $db->setQuery($query);

        return $db->loadColumn() ?: [];
    }

    /**
     * Get human-readable names of assigned items.
     *
     * Format: "T-Shirt ×2, Jeans ×1"
     *
     * @param int $shipmentId Shipment PK
     *
     * @return string Comma-separated names, or empty string
     *
     * @since   3.5.0
     */
    public static function getItemNames(int $shipmentId): string
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select(['name', 'quantity'])
            ->from(self::ITEMS_TABLE)
            ->where($db->quoteName('id_order_shipment') . ' = ' . (int) $shipmentId);

        $db->setQuery($query);
        $items = $db->loadObjectList();

        if (empty($items)) {
            return '';
        }

        $parts = [];

        foreach ($items as $item) {
            $parts[] = ($item->name ?? 'Item') . ' ×' . (int) ($item->quantity ?? 1);
        }

        return implode(', ', $parts);
    }

    // =====================================================================
    //  INTERNAL — Database Operations
    // =====================================================================

    /**
     * Load a raw shipment row (no enrichment).
     *
     * @param int $id Shipment PK
     *
     *
     * @since   3.5.0
     */
    private static function getRaw(int $id): ?object
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName(self::PK) . ' = ' . (int) $id);

        $db->setQuery($query);

        return $db->loadObject() ?: null;
    }

    /**
     * Save data to shipments table (insert or update).
     *
     * @param array &$data Data array (by reference — PK set on insert)
     *
     *
     * @since   3.5.0
     */
    private static function saveToTable(array &$data): bool
    {
        $db = self::db();

        $tableColumns = $db->getTableColumns(self::TABLE);
        $filtered = array_intersect_key($data, $tableColumns);

        if (empty($filtered)) {
            self::warn('saveToTable(): no valid columns after filtering');
            return false;
        }

        // Sanitize nullable columns — forms send '' which MySQL strict rejects
        foreach (self::NULLABLE_INT_COLUMNS as $col) {
            if (array_key_exists($col, $filtered) && $filtered[$col] === '') {
                $filtered[$col] = null;
            }
        }
        foreach (self::NULLABLE_DATE_COLUMNS as $col) {
            if (array_key_exists($col, $filtered) && empty($filtered[$col])) {
                $filtered[$col] = null;
            }
        }

        $isNew = empty($filtered[self::PK]) || (int) $filtered[self::PK] === 0;

        try {
            if ($isNew) {
                unset($filtered[self::PK]);
                $obj = (object) $filtered;
                $db->insertObject(self::TABLE, $obj, self::PK);
                $data[self::PK] = $obj->{self::PK} ?? $db->insertid();
            } else {
                $obj = (object) $filtered;
                $db->updateObject(self::TABLE, $obj, self::PK);
                $data[self::PK] = $filtered[self::PK];
            }
        } catch (Exception $e) {
            self::warn('saveToTable() failed: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Delete a single row from the shipments table.
     *
     * @param int $id Row PK
     *
     *
     * @since   3.5.0
     */
    private static function deleteFromTable(int $id): bool
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->delete($db->quoteName(self::TABLE))
            ->where($db->quoteName(self::PK) . ' = ' . (int) $id);

        try {
            $db->setQuery($query);
            $db->execute();
        } catch (Exception $e) {
            self::warn('deleteFromTable() failed: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Clear item assignments for a shipment (set id_order_shipment = NULL).
     *
     * Used before delete and before reassignment.
     *
     * @param int $shipmentId Shipment PK
     * @param int $orderId Order PK (scope)
     *
     *
     * @since   3.5.1
     */
    private static function clearItemAssignments(int $shipmentId, int $orderId): void
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::ITEMS_TABLE))
            ->set($db->quoteName('id_order_shipment') . ' = NULL')
            ->where($db->quoteName('id_order') . ' = ' . (int) $orderId)
            ->where($db->quoteName('id_order_shipment') . ' = ' . (int) $shipmentId);

        try {
            $db->setQuery($query);
            $db->execute();
        } catch (Exception $e) {
            self::warn('clearItemAssignments() failed: ' . $e->getMessage());
        }
    }

    // =====================================================================
    //  INTERNAL — Utilities
    // =====================================================================

    private static function db(): \Joomla\Database\DatabaseDriver
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }

    private static function moneyToFloat(mixed $value): float
    {
        if ($value instanceof Money) {
            return $value->getAmount();
        }
        return (float) ($value ?? 0);
    }

    private static function resolveMethodName(string $modelName, int $methodId): string
    {
        if ($methodId <= 0) {
            return '';
        }

        try {
            $model = self::getRelatedModel($modelName);
            $method = $model->getItem($methodId);
            return $method->name ?? '';
        } catch (Exception $e) {
            return '';
        }
    }

    private static function getRelatedModel(string $name)
    {
        return Factory::getApplication()
            ->bootComponent('com_alfa')
            ->getMVCFactory()
            ->createModel($name, 'Administrator', ['ignore_request' => true]);
    }

    private static function getOrderCurrencyId(int $orderId): int
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select('id_currency')
            ->from('#__alfa_orders')
            ->where('id = ' . (int) $orderId);
        $db->setQuery($query);

        return (int) ($db->loadResult() ?? 1);
    }

    private static function getCurrentUserId(): int
    {
        try {
            return Factory::getApplication()->getIdentity()->id ?? 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    private static function warn(string $message): void
    {
        Log::add('[OrderShipmentHelper] ' . $message, Log::WARNING, 'com_alfa.orders');

        try {
            Factory::getApplication()->enqueueMessage($message, 'error');
        } catch (Exception $e) {
            // CLI or early boot
        }
    }
}
