<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

/**
 * The open order lines that still rely on a stock identity: the one definition the
 * inventory kernel, the inventory audit and the catalogue guards share.
 *
 * A line relies on its acting identity, whether or not it holds a reservation,
 * when all of these hold:
 *   - its order is open: not trashed, and its status is neither a cancellation
 *     nor a completion ({@see self::openOrderSql()});
 *   - it was placed as a stock claim ({@see self::placedTrackedSql()});
 *   - its outstanding quantity is above the quantity tolerance: the ordered
 *     quantity less what has been delivered from that identity for it.
 * Delivered ({@see self::deliveredTermSql()}) is what the line's deductions took
 * out, less what restores that undo a delivery put back (a cancellation, a lowered
 * quantity, a status moved back from a deducting one). A restore from a physical
 * return (a return process or a credit document) is returned quantity
 * ({@see self::returnedTermSql()}): it settles the delivery it brought back, is
 * never owed again because the order is still open, and is ordinary physical stock
 * of the identity it came back to. The order's status decides only whether the
 * line can rely at all; it is never the authority for a returned quantity. Sending
 * the customer another unit is a new obligation, made only by an explicit claim:
 * an active reservation of the line, or the deducting document or status that
 * ships it under the physical floor.
 * The acting identity is the (item, combination) the line was sold as, or the
 * identity its effective recorded resolution names when the line still names that
 * resolution's original or resolved identity
 * ({@see InventoryService::effectiveResolutionSql()}).
 *
 * Read-only. A caller that must hold the answer until it writes reads it after it
 * has locked the identity's stock records: a fulfilment of such a line locks the
 * same records first.
 *
 * @since  2.0.0
 */
final class OpenLineClaims
{
    /**
     * @param DatabaseInterface $db The database driver.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly DatabaseInterface $db,
    ) {
    }

    /**
     * The open order lines that still rely on each identity of the named items, in
     * one query: per item, per acting combination (0 = the item itself), the lines
     * by order and line. Each line reports what was ordered, what was delivered
     * from the acting identity, what came back by a physical return, and the
     * outstanding quantity (ordered less delivered) that makes it rely.
     *
     * @param int[] $itemIds The items.
     *
     * @return array<int, array<int, list<array{order_id: int, line_id: int, ordered: float, delivered: float, returned: float, outstanding: float}>>>
     *                                                                                                                                                 item id => acting combination id => lines.
     *
     * @since  2.0.0
     */
    public function forItems(array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        sort($ids);

        $db = $this->db;
        $frozenCombination = 'COALESCE(oi.' . $db->quoteName('id_combination') . ', 0)';
        $resolved = 'r.' . $db->quoteName('id') . ' IS NOT NULL AND ('
            . '(oi.' . $db->quoteName('id_item') . ' = r.' . $db->quoteName('original_item_id')
            . ' AND ' . $frozenCombination . ' = r.' . $db->quoteName('original_combination_id') . ')'
            . ' OR (oi.' . $db->quoteName('id_item') . ' = r.' . $db->quoteName('resolved_item_id')
            . ' AND ' . $frozenCombination . ' = r.' . $db->quoteName('resolved_combination_id') . '))';
        $actingItem = '(CASE WHEN ' . $resolved . ' THEN r.' . $db->quoteName('resolved_item_id') . ' ELSE oi.' . $db->quoteName('id_item') . ' END)';
        $actingCombination = '(CASE WHEN ' . $resolved . ' THEN r.' . $db->quoteName('resolved_combination_id') . ' ELSE ' . $frozenCombination . ' END)';

        // The line's own deduct and restore movements on its acting identity.
        $lineMovements = ' FROM ' . $db->quoteName(name: '#__alfa_inventory_movements', as: 'ocm')
            . ' WHERE ocm.' . $db->quoteName(name: 'source_line_id') . ' = oi.' . $db->quoteName(name: 'id')
            . ' AND ocm.' . $db->quoteName(name: 'item_id') . ' = ' . $actingItem
            . ' AND ocm.' . $db->quoteName(name: 'combination_id') . ' = ' . $actingCombination
            . ' AND ocm.' . $db->quoteName(name: 'movement_type') . ' IN ('
            . $db->quote(text: InventoryService::MOVE_DEDUCT) . ', ' . $db->quote(text: InventoryService::MOVE_RESTORE) . ')';

        $db->setQuery(
            'SELECT oi.' . $db->quoteName('id') . ' AS ' . $db->quoteName('line_id')
            . ', oi.' . $db->quoteName('id_order') . ' AS ' . $db->quoteName('order_id')
            . ', oi.' . $db->quoteName('quantity') . ' AS ' . $db->quoteName('ordered')
            . ', ' . $actingItem . ' AS ' . $db->quoteName('acting_item')
            . ', ' . $actingCombination . ' AS ' . $db->quoteName('acting_combination')
            . ', COALESCE((SELECT SUM(' . self::deliveredTermSql(db: $db, movementAlias: 'ocm') . ')'
            . $lineMovements . '), 0) AS ' . $db->quoteName(name: 'delivered')
            . ', COALESCE((SELECT SUM(' . self::returnedTermSql(db: $db, movementAlias: 'ocm') . ')'
            . $lineMovements . '), 0) AS ' . $db->quoteName(name: 'returned')
            . ' FROM ' . $db->quoteName('#__alfa_order_items', 'oi')
            . ' INNER JOIN ' . $db->quoteName('#__alfa_orders', 'o') . ' ON o.' . $db->quoteName('id') . ' = oi.' . $db->quoteName('id_order')
            . ' LEFT JOIN ' . $db->quoteName('#__alfa_orders_statuses', 'st') . ' ON st.' . $db->quoteName('id') . ' = o.' . $db->quoteName('id_order_status')
            . ' LEFT JOIN ' . $db->quoteName('#__alfa_inventory_line_resolutions', 'r')
            . ' ON r.' . $db->quoteName('order_item_id') . ' = oi.' . $db->quoteName('id')
            . ' AND ' . InventoryService::effectiveResolutionSql(db: $db, alias: 'r')
            . ' WHERE oi.' . $db->quoteName('id_item') . ' IN (' . implode(',', $ids) . ')'
            . ' AND ' . self::openOrderSql(db: $db, orderAlias: 'o', statusAlias: 'st')
            . ' AND ' . self::placedTrackedSql(db: $db, lineAlias: 'oi') . ' = 1'
            . ' ORDER BY oi.' . $db->quoteName('id_order') . ', oi.' . $db->quoteName('id'),
        );

        $claims = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $ordered = (float) $row->ordered;
            $delivered = (float) $row->delivered;
            $outstanding = $ordered - $delivered;

            if ($outstanding <= InventoryAvailabilityService::QUANTITY_TOLERANCE) {
                continue;
            }

            $claims[(int) $row->acting_item][(int) $row->acting_combination][] = [
                'order_id' => (int) $row->order_id,
                'line_id' => (int) $row->line_id,
                'ordered' => $ordered,
                'delivered' => $delivered,
                'returned' => (float) $row->returned,
                'outstanding' => $outstanding,
            ];
        }

        return $claims;
    }

    /**
     * The per-movement SQL term whose SUM over an order line's movements on an
     * identity is the quantity delivered from it for that line: a deduction counts
     * what it took out, and a restore that undoes a delivery (any source but a
     * physical return: a cancellation, a lowered quantity, a status moved back)
     * takes it back. A restore from a physical return is returned quantity instead
     * ({@see self::returnedTermSql()}); every other movement counts 0.
     *
     * @param DatabaseInterface $db The database driver.
     * @param string $movementAlias The alias of #__alfa_inventory_movements.
     *
     * @return string A parenthesised numeric expression.
     *
     * @since  2.0.0
     */
    public static function deliveredTermSql(DatabaseInterface $db, string $movementAlias): string
    {
        $type = $movementAlias . '.' . $db->quoteName(name: 'movement_type');
        $change = $movementAlias . '.' . $db->quoteName(name: 'qty_change');

        return '(CASE WHEN ' . $type . ' = ' . $db->quote(text: InventoryService::MOVE_DEDUCT) . ' THEN -' . $change
            . ' WHEN ' . $type . ' = ' . $db->quote(text: InventoryService::MOVE_RESTORE)
            . ' AND ' . $movementAlias . '.' . $db->quoteName(name: 'source_type') . ' NOT IN (' . self::physicalReturnSources(db: $db) . ')'
            . ' THEN -' . $change . ' ELSE 0 END)';
    }

    /**
     * The per-movement SQL term whose SUM over an order line's movements on an
     * identity is the quantity that physically came back to it for that line: the
     * restores of a return process or a credit document, net of their own reversals
     * (negative restores of the same source) and of a return taking over a
     * document's restore (both legs, net zero). Every other movement counts 0.
     *
     * @param DatabaseInterface $db The database driver.
     * @param string $movementAlias The alias of #__alfa_inventory_movements.
     *
     * @return string A parenthesised numeric expression.
     *
     * @since  2.0.0
     */
    public static function returnedTermSql(DatabaseInterface $db, string $movementAlias): string
    {
        return '(CASE WHEN ' . $movementAlias . '.' . $db->quoteName(name: 'movement_type') . ' = ' . $db->quote(text: InventoryService::MOVE_RESTORE)
            . ' AND ' . $movementAlias . '.' . $db->quoteName(name: 'source_type') . ' IN (' . self::physicalReturnSources(db: $db) . ')'
            . ' THEN ' . $movementAlias . '.' . $db->quoteName(name: 'qty_change') . ' ELSE 0 END)';
    }

    /**
     * The quoted list of the source domains whose restores are physical returns.
     *
     * @param DatabaseInterface $db The database driver.
     *
     * @return string A comma-separated list of quoted literals.
     *
     * @since  2.0.0
     */
    private static function physicalReturnSources(DatabaseInterface $db): string
    {
        return $db->quote(text: InventoryService::SOURCE_RETURN) . ', ' . $db->quote(text: InventoryService::SOURCE_DOCUMENT);
    }

    /**
     * The SQL condition "this order is open": not trashed (a missing state reads as
     * published), and a status that is neither a cancellation nor a completion (an
     * order without a status row is open).
     *
     * @param DatabaseInterface $db The database driver.
     * @param string $orderAlias The alias of #__alfa_orders.
     * @param string $statusAlias The alias of #__alfa_orders_statuses, LEFT JOINed on the order's status.
     *
     * @return string A parenthesised boolean expression.
     *
     * @since  2.0.0
     */
    public static function openOrderSql(DatabaseInterface $db, string $orderAlias, string $statusAlias): string
    {
        return '(COALESCE(' . $orderAlias . '.' . $db->quoteName('state') . ', 1) <> -2'
            . ' AND COALESCE(' . $statusAlias . '.' . $db->quoteName('is_cancelled') . ', 0) = 0'
            . ' AND COALESCE(' . $statusAlias . '.' . $db->quoteName('is_completed') . ', 0) = 0)';
    }

    /**
     * The SQL value "this order line was placed as a stock claim", 1 or 0.
     *
     * Decided by the line's placement record when that record names the identity the
     * line was sold as: its tracked flag. A line without such a record (one placed
     * before these records existed) is judged from its own stock history alone: it
     * was a claim when a reservation of the order was ever made for it (in any
     * state), or when an order or a document deducted it, on any identity. The
     * current tracking of any identity is never read.
     *
     * @param DatabaseInterface $db The database driver.
     * @param string $lineAlias The alias of #__alfa_order_items.
     *
     * @return string A parenthesised integer expression (1 or 0).
     *
     * @since  2.0.0
     */
    public static function placedTrackedSql(DatabaseInterface $db, string $lineAlias): string
    {
        $line = $lineAlias . '.' . $db->quoteName('id');

        return '(COALESCE('
            . '(SELECT ptp.' . $db->quoteName('tracked')
            . ' FROM ' . $db->quoteName('#__alfa_inventory_line_provenance', 'ptp')
            . ' WHERE ptp.' . $db->quoteName('order_item_id') . ' = ' . $line
            . ' AND ptp.' . $db->quoteName('item_id') . ' = ' . $lineAlias . '.' . $db->quoteName('id_item')
            . ' AND ptp.' . $db->quoteName('combination_id') . ' = COALESCE(' . $lineAlias . '.' . $db->quoteName('id_combination') . ', 0)),'
            . ' CASE WHEN EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_inventory_reservations', 'ptr')
            . ' WHERE ptr.' . $db->quoteName('source_line_id') . ' = ' . $line
            . ' AND ptr.' . $db->quoteName('source_type') . ' = ' . $db->quote('order') . ')'
            . ' OR EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_inventory_movements', 'ptm')
            . ' WHERE ptm.' . $db->quoteName('source_line_id') . ' = ' . $line
            . ' AND ptm.' . $db->quoteName('movement_type') . ' = ' . $db->quote(InventoryService::MOVE_DEDUCT)
            . ' AND ptm.' . $db->quoteName('source_type') . ' IN (' . $db->quote('order') . ', ' . $db->quote(InventoryService::SOURCE_DOCUMENT) . '))'
            . ' THEN 1 ELSE 0 END))';
    }
}
