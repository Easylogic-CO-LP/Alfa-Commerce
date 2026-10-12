<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Projection;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * Maintains `#__alfa_item_field_value_index`, the flat (item, field, value)
 * projection that powers catalog filtering and facets.
 *
 * The index is a denormalised read model: the catalog query answers "does this
 * item carry value V of field F?" with one indexed EXISTS instead of unioning
 * three association tables and joining the value pool on every request.
 *
 * Rebuilds are **delete-first inside a transaction**, so the swap is one atomic
 * replace: a failed rebuild leaves the item's previous rows untouched, is logged
 * rather than raised, and the next save runs it again. Writes are idempotent and
 * guarded by {@see IndexRebuildLock} so overlapping saves cannot interleave.
 * That transaction is a savepoint when a caller already holds one: a second
 * START TRANSACTION would commit the caller's work on the spot.
 *
 * @since  1.3.0
 */
class ItemFieldValueProjection implements ProjectionInterface
{
    /** Items per transaction during a bulk rebuild; caps lock/transaction span. */
    private const CHUNK_SIZE = 100;

    /** The projection table. */
    private const TABLE = '#__alfa_item_field_value_index';

    /** @var DatabaseInterface */
    private $db;

    private ItemFieldValueCollector $collector;

    private IndexRebuildLock $lock;

    /**
     * @param DatabaseInterface|null $db Injected for testing; defaults to the container driver.
     * @param ItemFieldValueCollector|null $collector Injected for testing; defaults to a new collector.
     * @param IndexRebuildLock|null $lock Injected for testing; defaults to a new lock.
     *
     * @since  1.3.0
     */
    public function __construct(
        ?DatabaseInterface $db = null,
        ?ItemFieldValueCollector $collector = null,
        ?IndexRebuildLock $lock = null,
    ) {
        $this->db = $db ?? Factory::getContainer()->get('DatabaseDriver');
        $this->collector = $collector ?? new ItemFieldValueCollector($this->db);
        $this->lock = $lock ?? new IndexRebuildLock($this->db);
    }

    /**
     * @inheritDoc
     * @since  1.3.0
     */
    public function syncItem(int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }

        // Another connection is already rebuilding this item; its full
        // delete+insert will reflect the current source of truth, so skip.
        if (!$this->lock->acquire($itemId)) {
            return;
        }

        $nested = TransactionState::isOpen(db: $this->db);

        try {
            $references = $this->collector->collect($itemId);

            $this->db->transactionStart(true);

            try {
                $this->deleteRows([$itemId]);
                $this->insertRows($itemId, $references);
                $this->db->transactionCommit(true);
            } catch (Throwable $e) {
                TransactionState::rollBackLevel(db: $this->db, failure: $e);

                throw $e;
            }
        } catch (Throwable $e) {
            // A failure that ended the transaction this sync was called inside
            // is that caller's to hear: logged and swallowed here, the caller
            // would commit work the server has already discarded.
            if ($nested && (!TransactionState::isOpen(db: $this->db) || TransactionState::endedBy(db: $this->db, failure: $e))) {
                throw $e;
            }

            $this->logWarning('syncItem(' . $itemId . ') failed: ' . $e->getMessage());
        } finally {
            $this->lock->release($itemId);
        }
    }

    /**
     * @inheritDoc
     * @since  1.3.0
     */
    public function syncItems(array $itemIds): void
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));

        if (empty($itemIds)) {
            return;
        }

        // One transaction per chunk: bounded lock/transaction span, and the
        // delete-first invariant still holds within each chunk.
        $nested = TransactionState::isOpen(db: $this->db);

        foreach (array_chunk($itemIds, self::CHUNK_SIZE) as $chunk) {
            try {
                $this->db->transactionStart(true);

                try {
                    $this->deleteRows($chunk);

                    foreach ($chunk as $itemId) {
                        $this->insertRows((int) $itemId, $this->collector->collect((int) $itemId));
                    }

                    $this->db->transactionCommit(true);
                } catch (Throwable $e) {
                    TransactionState::rollBackLevel(db: $this->db, failure: $e);

                    throw $e;
                }
            } catch (Throwable $e) {
                // As in syncItem(): the caller hears a failure that ended its transaction.
                if ($nested && (!TransactionState::isOpen(db: $this->db) || TransactionState::endedBy(db: $this->db, failure: $e))) {
                    throw $e;
                }

                $this->logWarning('syncItems() chunk failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * @inheritDoc
     * @since  1.3.0
     */
    public function deleteForItem(int $itemId): void
    {
        $this->deleteForItems(itemIds: [$itemId]);
    }

    /**
     * Remove the index rows for a whole batch of items.
     *
     * The set-shaped delete {@see ProjectionManager} prefers: one
     * `DELETE ... WHERE item_id IN (...)` per chunk instead of one statement per
     * item. No {@see IndexRebuildLock} or transaction here: a pure delete is
     * idempotent and has no insert phase that a concurrent rebuild could race.
     *
     * @param int[] $itemIds The item primary keys.
     *
     * @since  1.5.0
     */
    public function deleteForItems(array $itemIds): void
    {
        $itemIds = array_values(
            array_unique(
                array_filter(
                    array_map('intval', $itemIds),
                    static fn (int $itemId): bool => $itemId > 0,
                ),
            ),
        );

        // An empty list would render as `IN ()`, which is a MySQL syntax error.
        if ($itemIds === []) {
            return;
        }

        // The chunk only bounds the IN-list; it is not a correctness boundary,
        // so a failed chunk must not stop the remaining ones from draining.
        foreach (array_chunk($itemIds, self::CHUNK_SIZE) as $chunk) {
            try {
                $this->deleteRows($chunk);
            } catch (Throwable $e) {
                $this->logWarning('deleteForItems() chunk failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Rebuild the entire index from scratch (post-install / bulk import).
     *
     * @since  1.3.0
     */
    public function syncAll(): void
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__alfa_items'))
            ->where($this->db->quoteName('state') . ' = 1');

        $itemIds = array_map('intval', $this->db->setQuery($query)->loadColumn() ?: []);

        $this->syncItems($itemIds);
    }

    /**
     * Delete every index row for the given items.
     *
     * @param int[] $itemIds The item primary keys.
     *
     * @since  1.3.0
     */
    private function deleteRows(array $itemIds): void
    {
        $query = $this->db->getQuery(true)
            ->delete($this->db->quoteName(self::TABLE))
            ->whereIn($this->db->quoteName('item_id'), array_map('intval', $itemIds), ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    /**
     * Insert the collected references for one item as a single multi-row INSERT.
     *
     * Operands are integers (cast in {@see ItemFieldValueReference}), so direct
     * interpolation is injection-safe and avoids per-row binding overhead.
     *
     * @param int $itemId The item primary key.
     * @param ItemFieldValueReference[] $references The (field, value) pairs to store.
     *
     * @since  1.3.0
     */
    private function insertRows(int $itemId, array $references): void
    {
        if (empty($references)) {
            return;
        }

        $rows = [];

        foreach ($references as $reference) {
            $rows[] = '(' . $itemId . ', ' . $reference->fieldId . ', ' . $reference->valueId . ')';
        }

        $sql = 'INSERT INTO ' . $this->db->quoteName(self::TABLE)
            . ' (' . $this->db->quoteName('item_id')
            . ', ' . $this->db->quoteName('field_id')
            . ', ' . $this->db->quoteName('value_id') . ') VALUES '
            . implode(', ', $rows);

        $this->db->setQuery($sql)->execute();
    }

    /**
     * Log a non-fatal warning to the com_alfa channel.
     *
     * @param string $message The message to log.
     *
     * @since  1.3.0
     */
    private function logWarning(string $message): void
    {
        Log::add('[ItemFieldValueProjection] ' . $message, Log::WARNING, 'com_alfa');
    }

    /**
     * "This item carries a value of that field" as a boolean SQL expression.
     *
     * The projection is the ONE place that knows how an item reaches a field
     * value (through a combination, an option or a spec), so the read side of
     * that question belongs next to the write side. The storefront facets and
     * the admin grid both call this, which is what keeps a filtered admin list
     * and a filtered catalogue page from ever disagreeing.
     *
     * With no value ids the expression asks the weaker question: "uses this
     * field at all", which is what a "where used" link needs.
     *
     * @param string $itemAlias Alias of the items table in the outer query.
     * @param int $fieldId The field to constrain.
     * @param int[] $valueIds Accepted value ids (OR within the field); empty = any.
     *
     * @return string A boolean expression for QueryInterface::where(), '1 = 1' when unconstrained.
     *
     * @since   1.3.0
     */
    public static function existsSql(string $itemAlias, int $fieldId, array $valueIds = []): string
    {
        if ($fieldId <= 0) {
            return '1 = 1';
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $valueIds = array_values(array_filter(array_map('intval', $valueIds)));

        $sql = 'EXISTS (SELECT 1 FROM ' . $db->quoteName(self::TABLE, 'ix')
            . ' WHERE ix.item_id = ' . $db->quoteName($itemAlias) . '.id'
            . ' AND ix.field_id = ' . $fieldId;

        if ($valueIds) {
            $sql .= ' AND ix.value_id IN (' . implode(',', $valueIds) . ')';
        }

        return $sql . ')';
    }

    /**
     * Which fields the catalogue actually uses.
     *
     * Driven from the SMALL field table with one EXISTS probe per row, not from the
     * LARGE projection with a GROUP BY: each probe is a single index seek that stops
     * at the first match, so the cost tracks the number of fields, not the number of
     * indexed rows. Measured on a 100k-product catalogue (6M projection rows,
     * MariaDB 10.6): 0.3 ms this way against 3.6 s scanning the projection.
     *
     * There is deliberately no count sibling to this method. A per-field
     * COUNT(DISTINCT item_id) cannot avoid reading every row it counts (4.4 s on
     * that same catalogue), so a usage count has to come from a maintained tally,
     * never from an on-demand scan.
     *
     * @return int[] Field ids that appear in the projection at least once.
     *
     * @since   1.9.0
     */
    public static function usedFieldIds(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('f.id'))
            ->from($db->quoteName('#__alfa_item_fields', 'f'))
            ->where(
                'EXISTS (SELECT 1 FROM ' . $db->quoteName(self::TABLE, 'ix')
                . ' WHERE ' . $db->quoteName('ix.field_id') . ' = ' . $db->quoteName('f.id') . ')',
            );

        return array_map('intval', $db->setQuery($query)->loadColumn() ?: []);
    }
}
