<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryEffectOutbox;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryIntegrityReader;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use UnexpectedValueException;

/**
 * The inventory audit page's report: the whole catalogue read by the integrity reader.
 *
 * Read only. The report is taken inside one consistent, read-only snapshot, so every
 * figure it compares was read at the same moment while the shop keeps selling, and the
 * snapshot is rolled back whatever happens. It never repairs anything; the repairs are
 * separate, explicit actions of InventoryauditController. It also reads back the stock unit an
 * order-line resolution recorded, for the operator's confirmation, tells whether a stock
 * identity is still offered for sale, to word a refused write-off, words the open order
 * lines that keep held stock from being written off, and lists the stock movements an order
 * still owes once it has been served first.
 *
 * @since  2.0.0
 */
class InventoryauditModel extends BaseDatabaseModel
{
    /**
     * Audit every item.
     *
     * Opens its own transaction on the model's connection and reads through that connection, so
     * it must not run inside a caller's transaction: starting one would commit the caller's work.
     *
     * @return array{findings: array<string, array>, classifications: array<string, array>, scanned: array{units: int, rows: int}}
     *                                                                                                                             The integrity reader's report, inconsistencies and classifications grouped by category.
     *
     * @since  2.0.0
     */
    public function getReport(): array
    {
        $db = $this->getDatabase();

        // The next transaction read-only, opened by the driver: MySQL cannot prepare START
        // TRANSACTION, and the MySQLi driver prepares every statement it is handed. Under
        // REPEATABLE READ the first read fixes the snapshot every later read sees.
        $db->setQuery(query: 'SET TRANSACTION READ ONLY')->execute();
        $db->transactionStart();

        try {
            return (new InventoryIntegrityReader(db: $db))->audit(itemIds: null);
        } finally {
            TransactionState::rollBackLevel(db: $db);
        }
    }

    /**
     * The stock unit a recorded order-line resolution binds its line to, as recorded.
     *
     * @param int $resolutionId The resolution.
     *
     * @return array{item_id: int, combination_id: int} Combination 0 is the product itself.
     *
     * @throws UnexpectedValueException When no such resolution is recorded.
     *
     * @since  2.0.0
     */
    public function getResolvedUnit(int $resolutionId): array
    {
        $db = $this->getDatabase();
        $row = $db->setQuery(
            query: 'SELECT ' . $db->quoteName(name: 'resolved_item_id') . ', ' . $db->quoteName(name: 'resolved_combination_id')
            . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_line_resolutions')
            . ' WHERE ' . $db->quoteName(name: 'id') . ' = ' . $resolutionId,
        )->loadAssoc();

        if ($row === null) {
            throw new UnexpectedValueException(sprintf('Inventory: order-line resolution #%d is not recorded.', $resolutionId));
        }

        return [
            'item_id' => (int) $row['resolved_item_id'],
            'combination_id' => (int) $row['resolved_combination_id'],
        ];
    }

    /**
     * Whether a stock identity is still offered for sale: a combination of the product that is
     * not archived, or a product without such combinations, of a product that is not archived
     * itself. Its stock changes through the stock editor, never through the audited write-off,
     * which the inventory service refuses for it; this read only chooses the words of that
     * refusal. A plain read: the inventory service decides under its own locks.
     *
     * @param int $itemId The product.
     * @param int $combinationId The combination, 0 for the product itself.
     *
     * @return bool False as well when the product or the combination does not exist.
     *
     * @since  2.0.0
     */
    public function isCurrentUnit(int $itemId, int $combinationId): bool
    {
        $db = $this->getDatabase();
        $state = $db->setQuery(
            query: 'SELECT ' . $db->quoteName(name: 'state') . ' FROM ' . $db->quoteName(name: '#__alfa_items')
            . ' WHERE ' . $db->quoteName(name: 'id') . ' = ' . $itemId,
        )->loadResult();

        // Every stock identity of an archived product is kept for order history only.
        if ($state === null || !CombinationState::isCurrent(state: $state)) {
            return false;
        }

        if ($combinationId > 0) {
            $combinationState = $db->setQuery(
                query: 'SELECT ' . $db->quoteName(name: 'state') . ' FROM ' . $db->quoteName(name: '#__alfa_item_combinations')
                . ' WHERE ' . $db->quoteName(name: 'id') . ' = ' . $combinationId
                . ' AND ' . $db->quoteName(name: 'item_id') . ' = ' . $itemId,
            )->loadResult();

            return $combinationState !== null && CombinationState::isCurrent(state: $combinationState);
        }

        // The product itself is sold only while it has no current combination.
        return (int) $db->setQuery(
            query: 'SELECT COUNT(*) FROM ' . $db->quoteName(name: '#__alfa_item_combinations')
            . ' WHERE ' . $db->quoteName(name: 'item_id') . ' = ' . $itemId
            . ' AND ' . CombinationState::currentSql(column: $db->quoteName(name: 'state')),
        )->loadResult() === 0;
    }

    /**
     * The stock movements of an order that are still owed and that a retry may apply: its
     * own and its documents', queued or failed, never one refused for the operator (those
     * wait for a resolution). Read once an order has been served first, so they are tried at
     * once. A plain read: the queue's drain claims each one before it applies it.
     *
     * @param int $orderId The order.
     *
     * @return list<array{source_type: string, source_id: int}> In the order they were queued.
     *
     * @since  2.0.0
     */
    public function owedMovements(int $orderId): array
    {
        $db = $this->getDatabase();

        return array_map(
            callback: static fn (object $row): array => ['source_type' => (string) $row->source_type, 'source_id' => (int) $row->source_id],
            array: $db->setQuery(
                query: 'SELECT ' . $db->quoteName(name: 'source_type') . ', ' . $db->quoteName(name: 'source_id')
                . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_effect_outbox')
                . ' WHERE ' . $db->quoteName(name: 'order_id') . ' = ' . $orderId
                . ' AND ' . $db->quoteName(name: 'status') . ' IN (' . $db->quote(text: InventoryEffectOutbox::STATUS_PENDING) . ', ' . $db->quote(text: InventoryEffectOutbox::STATUS_FAILED) . ')'
                . ' ORDER BY ' . $db->quoteName(name: 'id'),
            )->loadObjectList() ?: [],
        );
    }

    /**
     * Open order lines that still rely on a stock identity, in the operator's words: each
     * line's order, line and the quantity it still has to deduct, comma separated.
     *
     * @param array $lines Entries {order_id, line_id, ordered, outstanding}, as the inventory
     *                     service lists them when it refuses a write-off and as the audit
     *                     reports them on held stock.
     *
     *
     * @since  2.0.0
     */
    public static function openLinesText(array $lines): string
    {
        return implode(', ', array_map(
            static fn (array $line): string => Text::sprintf(
                'COM_ALFA_INVENTORY_AUDIT_OPEN_LINE_ITEM',
                (int) $line['order_id'],
                (int) $line['line_id'],
                InventoryAvailabilityService::formatQuantity(value: (float) $line['outstanding']),
            ),
            $lines,
        ));
    }
}
