<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Projection;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Catalog\CollectCatalogProjectionsEvent;
use Alfa\Component\Alfa\Administrator\Event\Catalog\PricesChangedEvent;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Alfa\Component\Alfa\Administrator\Service\Search\ItemSearchProjection;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\CatalogueRebuildJob;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Throwable;

/**
 * Single write-path entry point that fans an item change out to every projection.
 *
 * `ItemModel` calls only `$manager->syncItem($id)` / `syncItems($ids)` /
 * `deleteForItem($id)` / `deleteForItems($ids)` (the plural forms are the batch
 * entry points and the ones a multi-row admin action must use), and it never
 * names price, item-field, or search indexes. As new projections appear
 * (stock, recommendations, analytics) they are registered here, not threaded
 * through the model.
 *
 * The manager holds no projection logic of its own; it dispatches. Each
 * projection is invoked inside its own try/catch so one failing index never
 * blocks the others, and a sync failure never blocks the admin save (mirrors
 * the original non-fatal price-sync behaviour).
 *
 * Every rebuild and removal runs under the items' locks (2.2.0, IndexRebuildLock): asked with a wait, never skipped,
 * never bypassed. An item whose lock is not granted, or whose rebuild fails, is marked owed
 * (`#__alfa_items.projection_dirty_at`) and the catalogue.rebuild job is enqueued, so no change is left unprojected
 * without a record that its rebuild is owed.
 *
 * Each call is one write of the price index as onAlfaPricesChanged announces it (2.2.0): the caller's cause (a product
 * by default; a category's or a manufacturer's change, or the catalogue job's rebuild, say so), its chunks merged into
 * one event after the commit ({@see PriceIndexSyncService::announcing()}).
 *
 * @since  2.0.0
 */
class ProjectionManager
{
    /**
     * Item ids one marking statement covers.
     *
     * @since  2.2.0
     */
    private const MARK_RANGE = 1000;

    /**
     * Items one locked rebuild or removal takes at a time: their locks, their marks, one call to each projection.
     *
     * @since  2.2.0
     */
    private const CHUNK_SIZE = 100;

    /** @var ProjectionInterface[] */
    private array $projections;

    /**
     * @param ProjectionInterface[]|null $projections Injected for testing; defaults
     *                                                to the standard catalog set.
     *
     * @since  2.0.0
     */
    public function __construct(?array $projections = null)
    {
        $this->projections = $projections ?? array_merge(
            [
                new PriceProjection(),
                new ItemFieldValueProjection(),
                new ItemSearchProjection(),
            ],
            self::collectExtensionProjections(),
        );
    }

    /**
     * Projections contributed by installed extensions.
     *
     * The seam that keeps a third-party projection on the SAME write path as the
     * built-in ones. Without it, a search or analytics package can only hook the
     * models directly and re-derive "what changed" for itself, and two packages
     * would derive it differently. Registered here, they are invoked with the same
     * ids, on the same triggers, inside the same per-projection try/catch.
     *
     * A failure to collect is logged and ignored: the catalogue's own projections
     * must keep working when a third-party package is broken.
     *
     * @return ProjectionInterface[]
     *
     * @since  2.0.0
     */
    private static function collectExtensionProjections(): array
    {
        $projections = [];

        try {
            // Mode A, isolated (2.2.0): a contributor that fails is logged and every later one still registers.
            $plugins = AlfaPluginHelper::get();
            $plugins->importGroup('alfa-search');
            $plugins->importGroup('alfa');

            $event = new CollectCatalogProjectionsEvent('onCollectCatalogProjections', ['projections' => []]);
            $plugins->dispatchIsolated($event);

            foreach ($event->getProjections() as $entry) {
                if (($entry['projection'] ?? null) instanceof ProjectionInterface) {
                    $projections[] = $entry['projection'];
                }
            }
        } catch (Throwable $e) {
            Log::add(
                '[ProjectionManager] collecting extension projections failed; NO extension projections'
                . ' were registered for this request: ' . $e->getMessage(),
                Log::ERROR,
                'com_alfa',
            );
        }

        return $projections;
    }

    /**
     * Rebuild every projection for a single item (save / re-publish), under the item's lock.
     *
     * The lock is asked with its wait (IndexRebuildLock); the item's mark is read after the grant, every projection
     * rebuilds it, and the mark is cleared by compare-and-set on the value read. A lock not granted, or a projection
     * that fails, leaves the item marked and the catalogue job enqueued; nothing reaches the caller but a failure that
     * ended the transaction it called in.
     *
     * @param int $itemId The item primary key.
     * @param string $cause Why, as onAlfaPricesChanged says it (2.2.0; a PricesChangedEvent::CAUSE_ constant).
     * @param int|null $causeId What caused it; null for the item itself.
     *
     * @since  2.0.0
     */
    public function syncItem(int $itemId, string $cause = PricesChangedEvent::CAUSE_ITEM, ?int $causeId = null): void
    {
        if ($itemId > 0) {
            self::announcing(cause: $cause, causeId: $causeId, itemIds: [$itemId], work: fn () => $this->rebuildLocked(itemIds: [$itemId], operation: 'syncItem(' . $itemId . ')'));
        }
    }

    /**
     * Run one call as one write the price index announces (2.2.0): for a product's cause with no id given, the product
     * when the call names one, else 0.
     *
     * @param string $cause The cause.
     * @param int|null $causeId Its id, or null.
     * @param int[] $itemIds The call's items.
     * @param callable $work fn(): mixed, the call's work.
     *
     * @return mixed What the work answers.
     *
     * @since  2.2.0
     */
    private static function announcing(string $cause, ?int $causeId, array $itemIds, callable $work): mixed
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));

        return PriceIndexSyncService::announcing(cause: $cause, causeId: $causeId ?? (\count($itemIds) === 1 ? $itemIds[0] : 0), work: $work);
    }

    /**
     * Rebuild every projection for a batch of items (bulk fan-out), a chunk at a time, each chunk under its items'
     * locks taken in ascending id order. An item whose lock is not granted is left out of its chunk, marked and
     * enqueued; the others go on.
     *
     * @param int[] $itemIds The item primary keys.
     * @param string $cause Why, as onAlfaPricesChanged says it (2.2.0; a PricesChangedEvent::CAUSE_ constant).
     * @param int|null $causeId What caused it; null for the item when one is named, else 0.
     *
     * @since  2.0.0
     */
    public function syncItems(array $itemIds, string $cause = PricesChangedEvent::CAUSE_ITEM, ?int $causeId = null): void
    {
        self::announcing(cause: $cause, causeId: $causeId, itemIds: $itemIds, work: fn () => $this->syncItemsAnnounced(itemIds: $itemIds));
    }

    /**
     * The work of syncItems(), inside its announcement.
     *
     * @param int[] $itemIds The item primary keys.
     *
     * @since  2.2.0
     */
    private function syncItemsAnnounced(array $itemIds): void
    {
        $itemIds = self::ascending(itemIds: $itemIds);

        // No item: the projections are still asked, as before 2.2.0 (the price index aligns its sets of groups).
        if ($itemIds === []) {
            foreach ($this->projections as $projection) {
                try {
                    $projection->syncItems([]);
                } catch (Throwable $e) {
                    $this->logFailure($projection, 'syncItems(0 items)', $e);
                }
            }

            return;
        }

        foreach (array_chunk($itemIds, self::CHUNK_SIZE) as $chunk) {
            $this->rebuildLocked(itemIds: $chunk, operation: 'syncItems(' . \count($chunk) . ' items)');
        }
    }

    /**
     * Rebuild several items in every projection, item by item, and answer which failed and which were busy (2.2.0, the
     * catalogue.rebuild job's path): the job clears an item's mark only when its rebuild committed.
     *
     * The job keeps its own transactions (design 4.2 item 9): the items' locks are taken in ascending order, and each
     * item is rebuilt in a transaction begun after its grant and committed before its release, so it reads the item as
     * committed when the lock was granted. The asks wait no longer than the deadline the job's chunk has (the tick's
     * budget, never past its lease); an item whose lock was not granted by then is answered as busy and keeps its
     * mark: the writer holding it rebuilds it, or the job's next tick does. An item whose lock failed (the database
     * cannot lock for this connection) is rebuilt without it: the job holds the heavy slot, and every request meeting
     * the same failure marks and enqueues instead, so the job is its only writer. A failure rolls that item back
     * alone, is logged, and the item is answered as failed; one that ended a transaction the caller holds is rethrown.
     *
     * @param int[] $itemIds The item primary keys.
     * @param float|null $until The deadline of the asks, as microtime(true); null for the full wait each.
     * @param string $cause Why, as onAlfaPricesChanged says it (a PricesChangedEvent::CAUSE_ constant; the catalogue
     *                      job's rebuild by default).
     * @param int $causeId What caused it; 0 for a rebuild.
     *
     * @return array{failed: int[], busy: int[]} The items whose rebuild failed, and those whose lock was not granted.
     *
     * @throws Throwable A failure that ended the caller's transaction.
     *
     * @since  2.2.0
     */
    public function syncItemsOrFail(array $itemIds, ?float $until = null, string $cause = PricesChangedEvent::CAUSE_REBUILD, int $causeId = 0): array
    {
        return self::announcing(cause: $cause, causeId: $causeId, itemIds: $itemIds, work: fn (): array => $this->syncItemsOrFailAnnounced(itemIds: $itemIds, until: $until));
    }

    /**
     * The work of syncItemsOrFail(), inside its announcement.
     *
     * @param int[] $itemIds The item primary keys.
     * @param float|null $until The deadline of the asks.
     *
     * @return array{failed: int[], busy: int[]} The items whose rebuild failed, and those whose lock was not granted.
     *
     * @since  2.2.0
     */
    private function syncItemsOrFailAnnounced(array $itemIds, ?float $until): array
    {
        $db = self::db();
        $lock = new IndexRebuildLock($db);
        $status = $lock->acquire(itemIds: $itemIds, until: $until);
        $open = array_keys($status, IndexRebuildLock::GRANTED, true);
        $failed = [];
        $busy = [];

        try {
            foreach ($status as $itemId => $state) {
                if ($state === IndexRebuildLock::TIMEOUT) {
                    $busy[] = $itemId;

                    continue;
                }

                $rebuilt = $state === IndexRebuildLock::GRANTED
                    ? $this->rebuildAlone(db: $db, itemId: $itemId)
                    : IndexRebuildLock::unlocked($db, fn (): bool => $this->rebuildAlone(db: $db, itemId: $itemId));

                if (!$rebuilt) {
                    $failed[] = $itemId;
                }

                if ($state === IndexRebuildLock::GRANTED) {
                    $lock->release(itemIds: [$itemId]);
                    $open = array_values(array_diff($open, [$itemId]));
                }
            }
        } finally {
            $lock->release(itemIds: $open);
        }

        return ['failed' => $failed, 'busy' => $busy];
    }

    /**
     * Rebuild one item in every projection, in a transaction level of its own, begun now (after its lock's grant).
     *
     * @param DatabaseDriver $db The connection.
     * @param int $itemId The item.
     *
     * @return bool Whether the rebuild committed.
     *
     * @throws Throwable A failure that ended a transaction the caller holds.
     *
     * @since  2.2.0
     */
    private function rebuildAlone(DatabaseDriver $db, int $itemId): bool
    {
        $nested = TransactionState::isOpen(db: $db);
        $current = null;
        $db->transactionStart(true);

        try {
            foreach ($this->projections as $projection) {
                $current = $projection;
                self::rebuild(projection: $projection, itemIds: [$itemId]);
            }

            TransactionState::commitLevel($db);

            return true;
        } catch (Throwable $e) {
            if (TransactionState::rollBackLevel(db: $db, failure: $e) && $nested) {
                throw $e;
            }

            if ($current !== null) {
                $this->logFailure($current, 'syncItemsOrFail(item ' . $itemId . ')', $e);
            }

            return false;
        }
    }

    /**
     * Rebuild items in every projection under their locks: the locks asked (ascending, with the wait), the marks read
     * after the grant, every projection asked, the marks cleared by compare-and-set when every projection rebuilt
     * them; an item not granted, or a chunk a projection failed, marked and enqueued.
     *
     * @param int[] $itemIds The items, at most one chunk.
     * @param string $operation What the log names.
     *
     * @throws Throwable A failure that ended the transaction the caller holds.
     *
     * @since  2.2.0
     */
    private function rebuildLocked(array $itemIds, string $operation): void
    {
        $db = self::db();
        $lock = new IndexRebuildLock($db);
        $status = $lock->acquire(itemIds: $itemIds);
        $granted = array_keys($status, IndexRebuildLock::GRANTED, true);
        self::owe(itemIds: array_keys(array_diff_key($status, array_flip($granted))));

        if ($granted === []) {
            return;
        }

        $nested = TransactionState::isOpen(db: $db);

        try {
            // Read after the grant: a mark written later is a value this rebuild did not read, and it stays.
            $read = self::marksOf(itemIds: $granted);
            $failed = false;

            foreach ($this->projections as $projection) {
                try {
                    self::rebuild(projection: $projection, itemIds: $granted);
                } catch (Throwable $e) {
                    // A failure that ended the transaction this rebuild was called inside is that caller's to hear:
                    // logged and swallowed here, the caller would commit work the server has already discarded.
                    if ($nested && (!TransactionState::isOpen(db: $db) || TransactionState::endedBy(db: $db, failure: $e))) {
                        throw $e;
                    }

                    $this->logFailure($projection, $operation, $e);
                    $failed = true;
                }
            }

            if ($failed) {
                self::owe(itemIds: $granted);
            } else {
                self::clearOwn(read: $read);
            }
        } finally {
            $lock->release(itemIds: $granted);
        }
    }

    /**
     * Ask one projection to rebuild items, through its failing variant when it has one, so a failure reaches the
     * manager.
     *
     * @param ProjectionInterface $projection The projection.
     * @param int[] $itemIds The items.
     *
     * @throws Throwable What the projection met.
     *
     * @since  2.2.0
     */
    private static function rebuild(ProjectionInterface $projection, array $itemIds): void
    {
        if (method_exists($projection, 'syncItemsOrFail')) {
            $projection->syncItemsOrFail($itemIds);
        } elseif (\count($itemIds) === 1) {
            $projection->syncItem((int) reset($itemIds));
        } else {
            $projection->syncItems($itemIds);
        }
    }

    /**
     * Remove a batch of items from every projection (unpublish / trash / delete).
     *
     * The batch entry point: an admin action operating on a selection calls this
     * once with the whole set instead of looping {@see self::deleteForItem()},
     * so each projection can collapse the removal into a single statement.
     *
     * A removal writes the items' rows too, so it holds their locks (2.2.0): a chunk at a time, in ascending id order,
     * with the wait. An item whose lock is not granted, or whose removal a projection failed, is marked and enqueued:
     * the catalogue job brings its rows in line with what it is then. The marks of the items removed are cleared by
     * compare-and-set on the value read after the grant, as a rebuild clears them.
     *
     * Projections that expose the optional `deleteForItems(array $itemIds)` get
     * the whole chunk in one call; the rest are driven one id at a time so an
     * implementer written against the older single-item contract keeps working.
     *
     * @param int[] $itemIds The item primary keys.
     * @param string $cause Why, as onAlfaPricesChanged says it (2.2.0; a PricesChangedEvent::CAUSE_ constant).
     * @param int|null $causeId What caused it; null for the item when one is named, else 0.
     *
     * @since  2.0.0
     */
    public function deleteForItems(array $itemIds, string $cause = PricesChangedEvent::CAUSE_ITEM, ?int $causeId = null): void
    {
        self::announcing(cause: $cause, causeId: $causeId, itemIds: $itemIds, work: fn () => $this->deleteForItemsAnnounced(itemIds: $itemIds));
    }

    /**
     * The work of deleteForItems(), inside its announcement.
     *
     * @param int[] $itemIds The item primary keys.
     *
     * @since  2.2.0
     */
    private function deleteForItemsAnnounced(array $itemIds): void
    {
        $itemIds = self::ascending(itemIds: $itemIds);

        // Nothing selected: never spin the projection list, and never let an
        // empty set reach a projection that would build `IN ()`.
        if ($itemIds === []) {
            return;
        }

        $lock = new IndexRebuildLock(self::db());

        foreach (array_chunk($itemIds, self::CHUNK_SIZE) as $chunk) {
            $status = $lock->acquire(itemIds: $chunk);
            $granted = array_keys($status, IndexRebuildLock::GRANTED, true);
            self::owe(itemIds: array_keys(array_diff_key($status, array_flip($granted))));

            if ($granted === []) {
                continue;
            }

            try {
                // Read after the grant, as a rebuild reads them: a change that marked the items before its write (an
                // unpublish) is cleared once its removal is done, and a mark written later stays.
                $read = self::marksOf(itemIds: $granted);
                $failed = false;

                foreach ($this->projections as $projection) {
                    if (!self::acceptsItemIdBatch(projection: $projection)) {
                        $this->deleteOneByOne(projection: $projection, itemIds: $granted);

                        continue;
                    }

                    try {
                        $projection->deleteForItems(itemIds: $granted);
                    } catch (Throwable $e) {
                        $this->logFailure($projection, 'deleteForItems(' . count($granted) . ' items)', $e);
                        $failed = true;
                    }
                }

                if ($failed) {
                    self::owe(itemIds: $granted);
                } else {
                    self::clearOwn(read: $read);
                }
            } finally {
                $lock->release(itemIds: $granted);
            }
        }
    }

    /**
     * Whether a projection can take the whole selection in one call.
     *
     * The optional `deleteForItems()` is invoked as a named argument, so the
     * parameter name is part of the contract: an implementer that spells it
     * differently would throw `Unknown named parameter`, and a caught throw here
     * means the projection silently keeps rows for deleted items. Checking the
     * signature up front sends such a projection down the one-by-one path
     * instead: slower, but its rows still go away.
     *
     * @param ProjectionInterface $projection The projection to probe.
     *
     * @return bool True when `deleteForItems(array $itemIds)` is callable as declared.
     *
     * @since  2.0.0
     */
    private static function acceptsItemIdBatch(ProjectionInterface $projection): bool
    {
        if (!is_callable([$projection, 'deleteForItems'])) {
            return false;
        }

        try {
            // Magic __call() exposes no signature to name an argument against.
            $parameters = (new ReflectionMethod($projection, 'deleteForItems'))->getParameters();
        } catch (ReflectionException) {
            return false;
        }

        return isset($parameters[0]) && $parameters[0]->getName() === 'itemIds';
    }

    /**
     * Drive a projection that only implements the single-item delete.
     *
     * Each id keeps its own try/catch so one unremovable item never costs the
     * rest of the selection its removal.
     *
     * @param ProjectionInterface $projection The projection to drive.
     * @param int[] $itemIds The item primary keys.
     *
     * @since  2.0.0
     */
    private function deleteOneByOne(ProjectionInterface $projection, array $itemIds): void
    {
        foreach ($itemIds as $itemId) {
            try {
                $projection->deleteForItem(itemId: $itemId);
            } catch (Throwable $e) {
                $this->logFailure($projection, 'deleteForItem(' . $itemId . ')', $e);
            }
        }
    }

    /**
     * Remove one item from every projection (unpublish / trash / delete).
     *
     * Convenience for callers holding a single id; the set-shaped
     * {@see self::deleteForItems()} is the primitive.
     *
     * @param int $itemId The item primary key.
     * @param string $cause Why, as onAlfaPricesChanged says it (2.2.0; a PricesChangedEvent::CAUSE_ constant).
     * @param int|null $causeId What caused it; null for the item itself.
     *
     * @since  2.0.0
     */
    public function deleteForItem(int $itemId, string $cause = PricesChangedEvent::CAUSE_ITEM, ?int $causeId = null): void
    {
        $this->deleteForItems(itemIds: [$itemId], cause: $cause, causeId: $causeId);
    }

    /**
     * Reduce a raw id list to unique, positive integers.
     *
     * `array_filter()` alone keeps negatives, and a negative id can throw out of
     * a projection's own lookups and abort the rest of the batch.
     *
     * @param int[]|string[] $itemIds The raw id list.
     *
     * @return int[] The normalized ids, re-indexed from zero.
     *
     * @since  2.0.0
     */
    private static function positiveIds(array $itemIds): array
    {
        return array_values(
            array_unique(
                array_filter(
                    array_map('intval', $itemIds),
                    static fn (int $itemId): bool => $itemId > 0,
                ),
            ),
        );
    }

    /**
     * Unique positive item ids, ascending: the order every set of item locks is taken in.
     *
     * @param array<int|string> $itemIds The raw id list.
     *
     * @return int[]
     *
     * @since  2.2.0
     */
    private static function ascending(array $itemIds): array
    {
        $itemIds = self::positiveIds(itemIds: $itemIds);
        sort($itemIds);

        return $itemIds;
    }

    /**
     * The site's connection, the one that holds the item locks.
     *
     * @since  2.2.0
     */
    private static function db(): DatabaseDriver
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }

    /**
     * Log a non-fatal projection failure to the com_alfa channel.
     *
     * @param ProjectionInterface $projection The projection that threw.
     * @param string $operation Human-readable operation label.
     * @param Throwable $e The caught exception.
     *
     * @since  2.0.0
     */
    private function logFailure(ProjectionInterface $projection, string $operation, Throwable $e): void
    {
        $short = (new ReflectionClass($projection))->getShortName();

        Log::add(
            '[ProjectionManager] ' . $short . '::' . $operation . ' failed: ' . $e->getMessage(),
            Log::WARNING,
            'com_alfa',
        );
    }

    //  THE CATALOGUE JOB'S DIRTY SCOPE (2.2.0)
    //
    //  #__alfa_items.projection_dirty_at marks an item whose projections are owed a rebuild (NULL = current). An item
    //  save writes it before its row and clears it with its rebuild; a lock not granted or a rebuild that failed writes
    //  it and enqueues the job; Tools marks every item. A rebuild clears a mark by compare-and-set on the value it read
    //  after its grant, so a mark written meanwhile survives. The catalogue.rebuild job walks the marked items by
    //  ascending id; a tick's due read turns a mark older than the grace into the job (Runner).

    /**
     * Mark items owed a rebuild, now by the database clock (one statement per 1,000 ids).
     *
     * @param int[] $itemIds The items.
     *
     * @since  2.2.0
     */
    public static function mark(array $itemIds): void
    {
        $db = self::db();

        foreach (array_chunk(self::ascending(itemIds: $itemIds), self::MARK_RANGE) as $range) {
            $db->setQuery('UPDATE ' . $db->quoteName('#__alfa_items') . ' SET ' . $db->quoteName('projection_dirty_at') . ' = UTC_TIMESTAMP(6) WHERE '
                . $db->quoteName('id') . ' IN (' . implode(',', $range) . ')')->execute();
        }
    }

    /**
     * Before an item change committed outside the item save (2.2.0, the lock rule of the save): the item's lock asked
     * with its wait, then the item marked by the statement the save uses, so the change is never committed unmarked.
     * Answer to give rebuildChanged() once the change is made, in a finally.
     *
     * @param int $itemId The item about to change.
     *
     * @return array{item: int, lock: IndexRebuildLock, held: int[]}
     *
     * @since  2.2.0
     */
    public static function markChange(int $itemId): array
    {
        $lock = new IndexRebuildLock(self::db());
        $held = array_keys($lock->acquire(itemIds: [$itemId]), IndexRebuildLock::GRANTED, true);

        try {
            self::mark(itemIds: [$itemId]);
        } catch (Throwable $e) {
            $lock->release(itemIds: $held);

            throw $e;
        }

        return ['item' => $itemId, 'lock' => $lock, 'held' => $held];
    }

    /**
     * After the change markChange() announced: the item rebuilt under its lock (its mark cleared), or, when the lock
     * was not granted within its wait, the rebuild owed to the catalogue job (the change stands); the lock released.
     *
     * @param array{item: int, lock: IndexRebuildLock, held: int[]} $change What markChange() answered.
     *
     * @since  2.2.0
     */
    public static function rebuildChanged(array $change): void
    {
        try {
            if ($change['held'] !== []) {
                (new self())->syncItem($change['item']);
            } else {
                self::owe(itemIds: [$change['item']]);
            }
        } finally {
            $change['lock']->release(itemIds: $change['held']);
        }
    }

    /**
     * The database clock as a mark: what a new item's insert carries.
     *
     * @since  2.2.0
     */
    public static function markNow(): string
    {
        return (string) self::db()->setQuery('SELECT UTC_TIMESTAMP(6)')->loadResult();
    }

    /**
     * Owe items a rebuild: mark them and enqueue the catalogue job (the one waiting or running takes it, with its
     * rerun). A failure to record it is logged; it never reaches the caller, whose own work has committed.
     *
     * @param int[] $itemIds The items.
     *
     * @since  2.2.0
     */
    public static function owe(array $itemIds): void
    {
        $itemIds = self::ascending(itemIds: $itemIds);

        if ($itemIds === []) {
            return;
        }

        try {
            self::mark(itemIds: $itemIds);
            CatalogueRebuildJob::enqueue();
        } catch (Throwable $e) {
            Log::add(
                '[ProjectionManager] items ' . implode(', ', $itemIds) . ' are owed a rebuild that could not be recorded: ' . $e->getMessage(),
                Log::ERROR,
                IndexRebuildLock::LOG_CATEGORY,
            );
        }
    }

    /**
     * Mark every item owed a rebuild, in ranges of 1,000 ids (one statement per range, never one row per item).
     *
     * @return int The items marked.
     *
     * @since  2.2.0
     */
    public static function markAllForRebuild(): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $items = $db->quoteName('#__alfa_items');
        [$low, $high] = array_map('intval', (array) $db->setQuery('SELECT COALESCE(MIN(id), 0), COALESCE(MAX(id), 0) FROM ' . $items)->loadRow());
        $marked = 0;

        for ($from = $low; $high > 0 && $from <= $high; $from += self::MARK_RANGE) {
            $db->setQuery('UPDATE ' . $items . ' SET ' . $db->quoteName('projection_dirty_at') . ' = UTC_TIMESTAMP(6) WHERE ' . $db->quoteName('id')
                . ' BETWEEN ' . $from . ' AND ' . ($from + self::MARK_RANGE - 1))->execute();
            $marked += (int) $db->getAffectedRows();
        }

        return $marked;
    }

    /**
     * The marked items after an id, ascending, each with the mark as read.
     *
     * @param int $after The last item already rebuilt in this pass.
     * @param int $limit At most this many.
     *
     * @return array<int, string> Item id => its projection_dirty_at.
     *
     * @since  2.2.0
     */
    public static function markedAfter(int $after, int $limit): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $rows = $db->setQuery(
            'SELECT ' . $db->quoteName('id') . ', ' . $db->quoteName('projection_dirty_at') . ' FROM ' . $db->quoteName('#__alfa_items')
            . ' WHERE ' . $db->quoteName('projection_dirty_at') . ' IS NOT NULL AND ' . $db->quoteName('id') . ' > ' . $after
            . ' ORDER BY ' . $db->quoteName('id') . ' ASC',
            0,
            max(1, $limit),
        )->loadAssocList() ?: [];

        return array_combine(array_map('intval', array_column($rows, 'id')), array_map('strval', array_column($rows, 'projection_dirty_at'))) ?: [];
    }

    /**
     * How many items are marked.
     *
     * @since  2.2.0
     */
    public static function markedCount(): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        return (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_items') . ' WHERE ' . $db->quoteName('projection_dirty_at') . ' IS NOT NULL')->loadResult();
    }

    /**
     * The marks of items, as read now (the items marked only).
     *
     * @param int[] $itemIds The items.
     *
     * @return array<int, string> Item id => its projection_dirty_at.
     *
     * @since  2.2.0
     */
    private static function marksOf(array $itemIds): array
    {
        $db = self::db();

        try {
            return array_map('strval', $db->setQuery(
                'SELECT ' . $db->quoteName('id') . ', ' . $db->quoteName('projection_dirty_at') . ' FROM ' . $db->quoteName('#__alfa_items')
                . ' WHERE ' . $db->quoteName('projection_dirty_at') . ' IS NOT NULL AND ' . $db->quoteName('id') . ' IN (' . implode(',', self::ascending(itemIds: $itemIds)) . ')',
            )->loadAssocList('id', 'projection_dirty_at') ?: []);
        } catch (Throwable $e) {
            Log::add('[ProjectionManager] the marks of items ' . implode(', ', $itemIds) . ' could not be read; they stay: ' . $e->getMessage(), Log::WARNING, IndexRebuildLock::LOG_CATEGORY);

            return [];
        }
    }

    /**
     * clearMarks() for a rebuild in a request: a failure is logged, the marks stay and the job rebuilds them again.
     *
     * @param array<int, string> $read Item id => the mark as read.
     *
     * @since  2.2.0
     */
    private static function clearOwn(array $read): void
    {
        try {
            self::clearMarks(read: $read);
        } catch (Throwable $e) {
            Log::add('[ProjectionManager] rebuilt items kept their marks: ' . $e->getMessage(), Log::WARNING, IndexRebuildLock::LOG_CATEGORY);
        }
    }

    /**
     * The items that still hold the mark read before their rebuild (one statement): what a rebuild that failed still
     * owes. An item whose mark another writer cleared or wrote again meanwhile is not among them.
     *
     * @param array<int, string> $read Item id => the mark as read.
     *
     * @return int[] The items still marked with the value read, ascending.
     *
     * @since  2.2.0
     */
    public static function stillMarked(array $read): array
    {
        if ($read === []) {
            return [];
        }

        $now = self::marksOf(itemIds: array_keys($read));

        return array_values(array_filter(
            self::ascending(itemIds: array_keys($read)),
            static fn (int $itemId): bool => isset($now[$itemId]) && $now[$itemId] === (string) $read[$itemId],
        ));
    }

    /**
     * Clear the marks of rebuilt items, each only while it still holds the value read before its rebuild.
     *
     * @param array<int, string> $read Item id => the mark as read.
     *
     * @return int The marks cleared.
     *
     * @since  2.2.0
     */
    public static function clearMarks(array $read): int
    {
        if ($read === []) {
            return 0;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $column = $db->quoteName('projection_dirty_at');
        $each = [];

        foreach ($read as $id => $at) {
            $each[] = '(' . $db->quoteName('id') . ' = ' . (int) $id . ' AND ' . $column . ' = ' . $db->quote((string) $at) . ')';
        }

        $db->setQuery('UPDATE ' . $db->quoteName('#__alfa_items') . ' SET ' . $column . ' = NULL WHERE ' . implode(' OR ', $each))->execute();

        return (int) $db->getAffectedRows();
    }
}
