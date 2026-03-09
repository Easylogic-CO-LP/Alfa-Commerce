<?php

/**
 * @package     Alfa.Component
 * @subpackage  Administrator.Helper
 * @version     3.5.1
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2026 Easylogic CO LP
 * @license     GNU General Public License version 2 or later
 *
 * Centralized Stock Management Helper
 *
 * Single source of truth for ALL stock operations in the system.
 * Both frontend (OrderPlaceHelper) and admin (OrderModel, OrderHelper)
 * delegate to this helper. No other class should touch #__alfa_items.stock directly.
 *
 * STOCK OPERATIONS:
 *   deductOrderStock($orderId)       — Bulk: subtract ALL order item quantities
 *   restoreOrderStock($orderId)      — Bulk: add back ALL order item quantities
 *   adjustProductStock($id, $diff)   — Single: adjust one product by diff
 *   handleStockAggregated(...)       — Diff: compare old vs new item sets
 *
 * STOCK RULES:
 *   - Only products with manage_stock = 1 are adjusted
 *   - Stock is allowed to go negative (backorder)
 *   - Negative stock triggers a warning, never blocks the operation
 *   - quantity_in_stock on order_items is a SNAPSHOT, not an operation flag
 *
 * ORDER STATUS HELPERS:
 *   getDefaultOrderStatus()          — Returns the is_default=1 status
 *   shouldDeductStock($statusId)     — Checks stock_operation on a status
 *   handleStatusTransition(...)      — Detects 0↔1 transitions, adjusts stock
 *
 * Path: administrator/components/com_alfa/src/Helper/OrderStockHelper.php
 *
 * @since  3.5.1
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;

class OrderStockHelper
{
    // =========================================================================
    //  ORDER STATUS HELPERS
    // =========================================================================

    /**
     * Get the default order status for new orders.
     *
     * Reads from #__alfa_orders_statuses WHERE is_default = 1.
     * Falls back to the status with the lowest ordering value
     * if no default is explicitly set (backward compatibility).
     *
     * @return object Status row with: id, name, stock_operation, is_default, etc.
     * @since   3.5.1
     */
    public static function getDefaultOrderStatus(): object
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        // Primary: explicit default
        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__alfa_orders_statuses')
            ->where('is_default = 1')
            ->where('state = 1')
            ->setLimit(1);
        $db->setQuery($query);
        $status = $db->loadObject();

        if ($status) {
            return $status;
        }

        // Fallback: first by ordering (backward compat before migration)
        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__alfa_orders_statuses')
            ->where('state = 1')
            ->order('ordering ASC')
            ->setLimit(1);
        $db->setQuery($query);
        $status = $db->loadObject();

        if ($status) {
            Log::add(
                'No default order status set (is_default=1). Using first by ordering: '
                . $status->name . ' (id=' . $status->id . ')',
                Log::WARNING,
                'com_alfa.orders',
            );
            return $status;
        }

        // Emergency fallback: return a stub (should never happen in production)
        Log::add('No order statuses found in database!', Log::CRITICAL, 'com_alfa.orders');

        return (object) [
            'id' => 1,
            'name' => 'Unknown',
            'stock_operation' => 0,
            'is_default' => 0,
            'state' => 1,
        ];
    }

    /**
     * Check whether a given status requires stock to be OUT (deducted).
     *
     * stock_operation column on #__alfa_orders_statuses:
     *   0 = items should be OUT of stock (Confirmed, Shipped, Completed)
     *   1 = items should be IN stock     (Cancelled)
     *
     * @param int $statusId Order status ID
     * @return bool True if stock should be deducted (stock_operation = 0)
     * @since   3.5.1
     */
    public static function shouldDeductStock(int $statusId): bool
    {
        $statuses = AlfaHelper::getOrderStatuses();

        if (!isset($statuses[$statusId])) {
            Log::add(
                "shouldDeductStock(): Unknown status ID {$statusId}, defaulting to deduct",
                Log::WARNING,
                'com_alfa.orders',
            );
            return true;
        }

        // stock_operation = 0 means "remove from stock" → should deduct
        return (int) $statuses[$statusId]->stock_operation === 0;
    }

    /**
     * Handle stock adjustment on order status transition.
     *
     * Compares stock_operation between old and new status.
     * Only adjusts when the value actually changes (0→1 or 1→0).
     *
     * Transitions:
     *   0 → 1  (Confirmed → Cancelled)  = RESTORE stock
     *   1 → 0  (Cancelled → Confirmed)  = DEDUCT stock
     *   0 → 0  (Confirmed → Shipped)    = no change
     *   1 → 1  (hypothetical)           = no change
     *
     * @param int $orderId Order PK
     * @param int $oldStatusId Previous order status ID
     * @param int $newStatusId New order status ID
     * @return array ['action' => 'restored'|'deducted'|'none', 'count' => int]
     * @since   3.5.1
     */
    public static function handleStatusTransition(int $orderId, int $oldStatusId, int $newStatusId): array
    {
        $statuses = AlfaHelper::getOrderStatuses();

        $oldStockOp = (int) ($statuses[$oldStatusId]->stock_operation ?? 0);
        $newStockOp = (int) ($statuses[$newStatusId]->stock_operation ?? 0);

        // No change in stock operation → nothing to do
        if ($oldStockOp === $newStockOp) {
            return ['action' => 'none', 'count' => 0];
        }

        $oldStatusName = $statuses[$oldStatusId]->name ?? "#{$oldStatusId}";
        $newStatusName = $statuses[$newStatusId]->name ?? "#{$newStatusId}";

        if ($newStockOp === 1) {
            // Transitioning TO "keep in stock" → RESTORE items
            $count = self::restoreOrderStock($orderId);

            OrderModel::logOrderActivity(
                $orderId,
                'stock.restored',
                "Stock restored: \"{$oldStatusName}\" → \"{$newStatusName}\" ({$count} products returned)",
                [
                    'old_status' => ['id' => $oldStatusId, 'name' => $oldStatusName, 'stock_operation' => $oldStockOp],
                    'new_status' => ['id' => $newStatusId, 'name' => $newStatusName, 'stock_operation' => $newStockOp],
                    'products_restored' => $count,
                ],
            );

            return ['action' => 'restored', 'count' => $count];
        }

        // Transitioning TO "remove from stock" → DEDUCT items
        $count = self::deductOrderStock($orderId);

        OrderModel::logOrderActivity(
            $orderId,
            'stock.deducted',
            "Stock deducted: \"{$oldStatusName}\" → \"{$newStatusName}\" ({$count} products deducted)",
            [
                'old_status' => ['id' => $oldStatusId, 'name' => $oldStatusName, 'stock_operation' => $oldStockOp],
                'new_status' => ['id' => $newStatusId, 'name' => $newStatusName, 'stock_operation' => $newStockOp],
                'products_deducted' => $count,
            ],
        );

        return ['action' => 'deducted', 'count' => $count];
    }

    // =========================================================================
    //  BULK STOCK OPERATIONS (entire order)
    // =========================================================================

    /**
     * Deduct ALL order items from product stock.
     *
     * Aggregates by product ID (supports duplicate lines) and only
     * adjusts products with manage_stock = 1.
     *
     * Used when:
     *   - Order is placed (frontend) with a deducting status
     *   - Order status changes from non-deducting to deducting
     *
     * @param int $orderId Order PK
     * @return int Number of distinct products adjusted
     * @since   3.5.1
     */
    public static function deductOrderStock(int $orderId): int
    {
        $aggregated = self::aggregateOrderItems($orderId);
        $count = 0;

        foreach ($aggregated as $productId => $totalQty) {
            if (self::adjustProductStock($productId, -$totalQty)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Restore ALL order items to product stock.
     *
     * Aggregates by product ID and only adjusts products with manage_stock = 1.
     *
     * Used when:
     *   - Order status changes to a non-deducting status (e.g. Cancelled)
     *
     * @param int $orderId Order PK
     * @return int Number of distinct products adjusted
     * @since   3.5.1
     */
    public static function restoreOrderStock(int $orderId): int
    {
        $aggregated = self::aggregateOrderItems($orderId);
        $count = 0;

        foreach ($aggregated as $productId => $totalQty) {
            if (self::adjustProductStock($productId, +$totalQty)) {
                $count++;
            }
        }

        return $count;
    }

    // =========================================================================
    //  SINGLE PRODUCT STOCK ADJUSTMENT
    // =========================================================================

    /**
     * Adjust stock for a single product by the given difference.
     *
     * Respects manage_stock: products with manage_stock = 0 are skipped.
     * Stock is allowed to go negative (backorder) — never blocks.
     *
     * This is the ONLY method that writes to #__alfa_items.stock.
     * All other methods in the system must go through this.
     *
     * @param int $productId Product PK (#__alfa_items.id)
     * @param int $diff Stock change: negative = deduct, positive = restore
     * @return bool True if stock was adjusted, false if skipped (manage_stock=0 or error)
     * @since   3.5.1
     */
    public static function adjustProductStock(int $productId, int $diff): bool
    {
        if ($diff === 0 || $productId <= 0) {
            return false;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        try {
            // Check manage_stock flag — skip products that don't track stock
            $query = $db->getQuery(true)
                ->select(['manage_stock', 'stock', 'name'])
                ->from('#__alfa_items')
                ->where('id = ' . intval($productId));
            $db->setQuery($query);
            $product = $db->loadObject();

            if (!$product) {
                Log::add(
                    "adjustProductStock(): Product #{$productId} not found",
                    Log::WARNING,
                    'com_alfa.orders',
                );
                return false;
            }

            if (!(int) $product->manage_stock) {
                // Product doesn't track stock — skip silently
                return false;
            }

            // Apply the adjustment
            $operator = ($diff > 0) ? '+' : '-';
            $absDiff = abs($diff);

            $query = $db->getQuery(true)
                ->update('#__alfa_items')
                ->set('stock = stock ' . $operator . ' ' . intval($absDiff))
                ->where('id = ' . intval($productId));
            $db->setQuery($query);
            $db->execute();

            // Warn about negative stock (informational only)
            // Cast to float — stock column can be null in DB
            $currentStock = (float) ($product->stock ?? 0);
            $newStock = $currentStock + $diff;
            if ($newStock < 0) {
                Log::add(
                    sprintf(
                        'Stock warning: "%s" (id=%d) is now %s (was %s, adjusted by %s%d)',
                        $product->name ?? "Product #{$productId}",
                        $productId,
                        number_format($newStock, 0),
                        number_format($currentStock, 0),
                        ($diff > 0 ? '+' : ''),
                        $diff,
                    ),
                    Log::WARNING,
                    'com_alfa.orders',
                );
            }

            return true;
        } catch (Exception $e) {
            Log::add(
                "adjustProductStock() failed for product #{$productId}: " . $e->getMessage(),
                Log::ERROR,
                'com_alfa.orders',
            );
            return false;
        }
    }

    // =========================================================================
    //  DIFF-BASED STOCK (for item edits — old qty vs new qty)
    // =========================================================================

    /**
     * Handle stock via aggregation when order items change.
     *
     * Compares old items vs new items, aggregates by product ID,
     * and applies the DIFFERENCE. Supports duplicate product lines.
     *
     * Positive diff (more ordered)  → deduct from stock
     * Negative diff (less ordered)  → restore to stock
     *
     * Replaces the old OrderHelper::handleStockAggregated() which
     * did NOT respect manage_stock.
     *
     * @param array $newItems New item set (objects with id_item, quantity)
     * @param array $oldItemsByPK Old items indexed by row PK
     * @since   3.5.1
     */
    public static function handleItemStockDiff(array $newItems, array $oldItemsByPK): void
    {
        // Aggregate old quantities per product
        $oldQtyByProduct = [];
        foreach ($oldItemsByPK as $oldItem) {
            $pid = (int) ($oldItem->id_item ?? 0);
            if ($pid > 0) {
                $oldQtyByProduct[$pid] = ($oldQtyByProduct[$pid] ?? 0) + (int) ($oldItem->quantity ?? 0);
            }
        }

        // Aggregate new quantities per product
        $newQtyByProduct = [];
        foreach ($newItems as $newItem) {
            $pid = (int) (is_object($newItem) ? ($newItem->id_item ?? 0) : ($newItem['id_item'] ?? 0));
            $qty = (int) (is_object($newItem) ? ($newItem->quantity ?? 0) : ($newItem['quantity'] ?? 0));
            if ($pid > 0) {
                $newQtyByProduct[$pid] = ($newQtyByProduct[$pid] ?? 0) + $qty;
            }
        }

        // All product IDs involved
        $allProductIds = array_unique(array_merge(
            array_keys($oldQtyByProduct),
            array_keys($newQtyByProduct),
        ));

        // Apply diff per product: negative diff = deduct, positive diff = restore
        foreach ($allProductIds as $productId) {
            $oldQty = $oldQtyByProduct[$productId] ?? 0;
            $newQty = $newQtyByProduct[$productId] ?? 0;
            $diff = $oldQty - $newQty; // More ordered = negative diff = deduct

            if ($diff !== 0) {
                // adjustProductStock: positive = restore, negative = deduct
                // $diff here: oldQty - newQty
                //   oldQty=5, newQty=8 → diff = -3 → deduct 3 more
                //   oldQty=8, newQty=5 → diff = +3 → restore 3
                self::adjustProductStock($productId, $diff);
            }
        }
    }

    // =========================================================================
    //  INTERNAL HELPERS
    // =========================================================================

    /**
     * Load and aggregate order item quantities by product ID.
     *
     * @param int $orderId Order PK
     * @return array [product_id => total_quantity, ...]
     * @since   3.5.1
     */
    protected static function aggregateOrderItems(int $orderId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select(['id_item', 'quantity'])
            ->from('#__alfa_order_items')
            ->where('id_order = ' . intval($orderId));
        $db->setQuery($query);
        $items = $db->loadObjectList();

        if (empty($items)) {
            return [];
        }

        $aggregated = [];
        foreach ($items as $item) {
            $pid = (int) $item->id_item;
            $qty = (int) $item->quantity;
            if ($pid > 0 && $qty > 0) {
                $aggregated[$pid] = ($aggregated[$pid] ?? 0) + $qty;
            }
        }

        return $aggregated;
    }
}
