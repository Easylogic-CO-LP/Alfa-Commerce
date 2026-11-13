<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * @package    Alfa Commerce
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Service;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Catalog\PriceChange;
use Alfa\Component\Alfa\Administrator\Event\Catalog\PricesChangedEvent;
use Alfa\Component\Alfa\Administrator\Helper\PostCommit;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Service\Projection\IndexRebuildLock;
use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\CatalogueRebuildJob;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\PriceSetPrepareJob;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Site\Service\Place\PlaceTree;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceDataLoader;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * PriceIndexSyncService: Builds and maintains the Architecture-B price index.
 *
 * Column naming
 * All stored columns use the same names as the com_alfa component config fields:
 *
 *   base_price                - raw unit price, no discounts, no tax
 *   discount_amount           - money saved; 0 means no discount applies
 *   base_price_with_discounts - after discounts, before tax (B2B net/ex-VAT)
 *   tax_amount                - tax applied to the discounted price
 *   base_price_with_tax       - base + tax, NO discounts ("was" price)
 *   final_price           *   - what visitor pays  (primary filter column)
 *   discount_percent          - % saving  (filter: save >= X%)
 *
 * NOTE: has_discount was removed. discount_amount > 0 is identical and avoids
 * the risk of two columns drifting out of sync.
 *
 * Places (2.2.1)
 * A slot is kept for every place a discount, a tax or a price row names, and
 * for no place (0). A slot's place is priced as a buyer there is: its rules
 * are those of the place and of every place above it, its price rows its own.
 * A buyer reads the slot of their own place when it has one; else that of the
 * nearest place above them that a discount or a tax names, whose rules are the
 * buyer's too; else the slot of no place (slotPlaces()). A place named only by
 * price rows is passed over above a buyer: a price row applies to its own place only.
 * Since a slot is priced with the chain it had when it was written, a place
 * that takes another parent owes the slots below it a rebuild
 * (oweChainRebuild()); a new place, or a place moved with no slot at or below
 * it, needs none: the reader follows the tree as it is.
 *
 * Slots
 * A row is prepared for one currency, one place and one SET of pricing groups
 * (group_set_id, a row of #__alfa_price_group_sets): the groups a price row, a
 * discount or a tax names that its customers hold. A set is kept as its list
 * (ids ascending, comma-separated, '' for none) and found by the SHA-1 of that
 * list, by the writer and the reader alike (groupSetKey()). A slot is priced
 * with exactly that set, so it holds what those customers are charged,
 * whichever of their groups carry the pricing. The sets are the ones customers
 * really hold: each account's, the guest's and that of a newly registered
 * customer. A visitor reads the slot of their own set.
 *
 * Preparation
 * A set customers hold for the first time is REQUESTED by the request that
 * brought it (an account, a group or a pricing rule saved), which also gives
 * it its price.set.prepare job, and PREPARED by that job (2.2.0;
 * prepareSetChunk()): a chunk of products at a time, each chunk one
 * transaction together with the set's checkpoint, one preparer at a time (the
 * job runner's heavy slot). The set is marked prepared after its last chunk; a set without
 * that mark is never read, whatever rows it has, so a preparation that failed
 * or was cut off is invisible until a later run finishes it. A set nobody
 * holds any more is dropped by the request that left it so.
 *
 * Every write of an item's rows, a preparation's chunk included, holds the
 * item's lock (2.2.0, IndexRebuildLock): the item and the sets' progress are
 * read after the grant and the rows commit before the release.
 *
 * Public API
 *   syncItem($itemId)           - re-index one item after save/re-publish
 *   syncItems($itemIds)         - re-index a specific list of items
 *   syncByDiscount($discountId) - re-index items affected by a discount change
 *   syncByTax($taxId)           - re-index items affected by a tax change
 *   syncGroupSets()             - request the sets customers hold, drop the ones nobody holds
 *   syncAll()                   - rewrite every published product of the prepared sets
 *   deleteForItem($itemId)      - remove all index rows for one item
 *   prepareSetChunk($setId, $n) - one chunk of a waiting set's preparation (the price.set.prepare job)
 *   alignSets()                 - the sets customers hold brought in line (the job, at each claim)
 *   queueWaitingSets()          - a job for every waiting set (the update to 2.2.0)
 *   preparationState()          - the sets waiting, each with its job (Tools)
 *   accountGroupSet($userId)    - the set of pricing groups one account holds
 *   holdsGroupSet($groupSet)    - whether the index knows a set, prepared or waiting
 *   pricingGroups()             - the groups a price row, a discount or a tax names (the read side)
 *   slotKey($context)           - the set of one visitor's slots (the read side)
 *   slotSetJoin($context, ...)    - the join that reads only a prepared set (the read side)
 *   slotPlaces($context)        - the places whose slots one visitor reads, nearest first (the read side)
 *   slotRanking($alias, $places) - how one visitor's matching slots are ranked (the read side)
 *   oweChainRebuild($placeIds)  - the catalogue's rebuild owed when places take another parent
 *
 * Every sync entry point first brings the sets in line with the sets customers
 * hold (syncGroupSets()), so a pricing row that names a group for the first
 * time, or the last one that named it, changes them where it must. It then
 * writes its products' rows of every prepared set, and of a waiting set only
 * the products that set's preparation has already passed: no request prepares
 * a set.
 *
 * SYNC TRIGGER MAP (complete; see model hooks for implementation)
 *   ItemModel::save()            -> syncItem()       (save with new prices)
 *   ItemModel::publish(state=1)  -> syncItem()       (re-publish: re-add to index)
 *   ItemModel::publish(state!=1)  -> deleteForItem()  (unpublish/trash: hide from filter)
 *   ItemModel::delete()          -> deleteForItem()  (FK CASCADE also handles this)
 *
 *   DiscountModel::save()        -> syncItems()      (old scope + new scope, one pass)
 *   DiscountModel::publish()     -> syncByDiscount() (enable/disable affects prices)
 *   DiscountModel::delete()      -> syncItems()      (scope collected BEFORE delete)
 *
 *   TaxModel::save()             -> syncItems()      (old scope + new scope, one pass)
 *   TaxModel::publish()          -> syncByTax()      (enable/disable affects prices)
 *   TaxModel::delete()           -> syncItems()      (scope collected BEFORE delete)
 *
 *   CategoryModel::delete()      -> syncItems()      (the products that lost a category)
 *   ManufacturerModel::delete()  -> syncItems()      (the products that lost a manufacturer, via ProjectionManager)
 *   PlaceModel::delete()         -> the places' own rows are deleted; no rule widens (RuleScopeGuard)
 *   PlaceModel::save() (a new parent), or a delete that leaves places
 *   without their parent          -> oweChainRebuild() (2.2.1)
 *
 *   A user group saved under another parent, or removed; an account saved
 *   into another set of groups, or removed; the users component's options
 *   saved (the sync plugin)       -> syncGroupSets()
 *
 * All sync failures are non-fatal: logged as warnings, admin save never blocked.
 *
 * THE PRICE-CHANGE EVENT (2.2.0, onAlfaPricesChanged, {@see PricesChangedEvent}). This writer is the only place that
 * writes the index, so it is the one that announces it. Each public write runs as one announcement
 * ({@see self::announcing()}) carrying its cause (the caller's: a product, a discount, a tax, a category, a
 * manufacturer, a set's preparation, or a rebuild by default); a write made inside another one joins it. While
 * something listens, each chunk reads its rows before rewriting them and keeps the rows it writes, and the rows that
 * differ join the announcement once the chunk's level has committed; the announcement goes out once, after the
 * outermost commit that holds its chunks (PostCommit), so a rollback takes its changes with it. With no listener
 * nothing is read.
 *
 * @since  1.0.0
 */
class PriceIndexSyncService
{
    // Constants

    /** Chunk size for bulk item syncs: balances memory vs DB round-trips. */
    private const CHUNK_SIZE = 50;

    /** Items per removal statement: the projection manager's chunk, so a bulk removal runs the statements it ran before 2.2.0. */
    private const REMOVE_CHUNK_SIZE = 100;

    /**
     * The bound on one onAlfaPricesChanged (2.2.0, A-C18-4): once a write outside a caller's transaction has collected
     * this many changed rows (about 1.5 MB), what it holds goes out as its own event and the collection starts again.
     * An event so carries about this many rows, at most one chunk's more; a larger write is announced by several,
     * each exact for its rows.
     *
     * @since  2.2.0
     */
    public const ANNOUNCE_LIMIT = 5000;

    /** Sentinel valid_from for the segment that is live right now. */
    private const EPOCH = '1000-01-01 00:00:00';

    /** Upper bound on time segments per item (runaway-window safety). */
    private const MAX_SEGMENTS = 8;

    /** The sets of pricing groups the index is keyed by. */
    private const SETS_TABLE = '#__alfa_price_group_sets';

    /** Whether this process's connection may concatenate a list of any length (the accounts' sets). */
    private static bool $longLists = false;

    /** What the last calculation that failed in writeChunk() raised. */
    private string $lastFailure = '';

    /** What stopped the last chunk of a preparation that was rolled back (2.2.0: the job raises it as its cause). */
    private ?Throwable $chunkFailure = null;

    /**
     * The write being announced (2.2.0): its cause, whether anything listens, and what its committed chunks changed.
     * Opened by the outermost announcing call of the process; the calls inside it join it.
     *
     * @var stdClass|null {cause: string, causeId: int, listening: bool, items: array<int, true>, sets: array<int, true>, changes: PriceChange[]}
     * @since  2.2.0
     */
    private static ?stdClass $announcement = null;

    /**
     * Whether an onAlfaPricesChanged is being delivered now, and the announcements raised meanwhile (a listener that
     * writes prices): sent in their order once the running delivery ends (2.2.0, A-C18-7).
     *
     * @since  2.2.0
     */
    private static bool $delivering = false;

    /**
     * @var stdClass[] The announcements waiting for the running delivery to end.
     * @since  2.2.0
     */
    private static array $deliveries = [];

    /**
     * What the last chunk written changed, and the sets it wrote: held until the chunk's level commits.
     *
     * @var array{changes: PriceChange[], sets: int[]}
     * @since  2.2.0
     */
    private array $chunkChanges = ['changes' => [], 'sets' => []];

    /**
     * The groups a price row, a discount or a tax names, as read for this process.
     *
     * @var int[]|null
     * @since  2.0.0
     */
    private static ?array $pricingGroups = null;

    // Dependencies

    /** @var \Joomla\Database\DatabaseInterface */
    private $db;

    /** @var PriceCalculator */
    private $calculator;

    // Constructor

    /**
     * @param PriceCalculator|null $calculator Injected for testing; defaults to new instance
     * @since  1.0.0
     */
    public function __construct(?PriceCalculator $calculator = null)
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
        $this->calculator = $calculator ?? new PriceCalculator();
    }

    // Public API

    /**
     * Re-index a single item.
     *
     * Called from ItemModel::save() and ItemModel::publish() when an item is
     * re-published (state = 1). All associated data must already be committed.
     *
     * @param int $itemId The item.
     * @param string $cause Why, as onAlfaPricesChanged says it (2.2.0; a PricesChangedEvent::CAUSE_ constant).
     * @param int $causeId What caused it; 0 for a rebuild.
     *
     * @since  1.0.0
     */
    public function syncItem(int $itemId, string $cause = PricesChangedEvent::CAUSE_REBUILD, int $causeId = 0): void
    {
        if ($itemId <= 0) {
            return;
        }

        self::announcing(cause: $cause, causeId: $causeId, work: function () use ($itemId): void {
            try {
                [, $groupSets] = $this->alignGroupSets();
                $this->writeIndexRows([$itemId], $this->getActiveContextCombinations(groupSets: $groupSets));
            } catch (Throwable $e) {
                $this->logWarning("syncItem({$itemId}) failed: " . $e->getMessage());
                ProjectionManager::owe(itemIds: [$itemId]);
            }
        });
    }

    /**
     * Re-index a specific list of items.
     *
     * Used by the discount and tax delete hooks: the calling model collects
     * the affected item ids BEFORE performing the delete (while the scope
     * tables still exist), then calls this method AFTER the delete so prices
     * are computed without the removed discount/tax.
     *
     * @param int[] $itemIds
     * @param string $cause Why, as onAlfaPricesChanged says it (2.2.0; a PricesChangedEvent::CAUSE_ constant).
     * @param int $causeId What caused it; 0 for a rebuild.
     *
     * @return int Number of index rows written
     * @since  1.0.0
     */
    public function syncItems(array $itemIds, string $cause = PricesChangedEvent::CAUSE_REBUILD, int $causeId = 0): int
    {
        return self::announcing(cause: $cause, causeId: $causeId, work: function () use ($itemIds): int {
            // Not strict: a chunk whose calculation failed is rolled back, logged and skipped, and the other chunks
            // are written (the rule saves and the publish path rebuild every product they can).
            try {
                return $this->rebuildItems(itemIds: $itemIds, strict: false);
            } catch (Throwable $e) {
                $this->logWarning('syncItems() failed: ' . $e->getMessage());
                ProjectionManager::owe(itemIds: $itemIds);

                return 0;
            }
        });
    }

    /**
     * Re-index a list of items and let a failure reach the caller (2.2.0): the manager clears an item's mark only when
     * its rebuild committed, so it must hear a rebuild that failed; syncItems() owes one to the catalogue job instead.
     * The manager's rebuilds call it (PriceProjection::syncItemsOrFail()), holding the items' locks.
     *
     * @param int[] $itemIds The items.
     * @param string $cause Why, as onAlfaPricesChanged says it (a PricesChangedEvent::CAUSE_ constant).
     * @param int $causeId What caused it; 0 for a rebuild.
     *
     * @return int Number of index rows written.
     *
     * @throws Throwable Whatever the rebuild met; a failure that ended the caller's transaction among them, and a
     *                   calculation that failed (its chunk rolled back, its rows as they were).
     *
     * @since  2.2.0
     */
    public function syncItemsOrFail(array $itemIds, string $cause = PricesChangedEvent::CAUSE_REBUILD, int $causeId = 0): int
    {
        return self::announcing(cause: $cause, causeId: $causeId, work: fn (): int => $this->rebuildItems(itemIds: $itemIds, strict: true));
    }

    /**
     * The re-index of syncItems() and syncItemsOrFail(): the groups' sets aligned, then the items written.
     *
     * @param int[] $itemIds The items.
     * @param bool $strict A chunk whose calculation failed throws (syncItemsOrFail()); false skips that chunk, logged.
     *
     * @return int Number of index rows written.
     *
     * @since  2.2.0
     */
    private function rebuildItems(array $itemIds, bool $strict): int
    {
        $itemIds = array_filter(array_map('intval', $itemIds));

        // Even with no item left in scope: the rule may have named a group
        // for the first time, or been the last to name one.
        [, $groupSets] = $this->alignGroupSets();

        if (empty($itemIds)) {
            return 0;
        }

        return $this->writeIndexRows(
            array_values($itemIds),
            $this->getActiveContextCombinations(groupSets: $groupSets),
            strict: $strict,
        );
    }

    /**
     * Re-index all items that a discount could affect.
     *
     * If the discount has category_id = 0 (applies globally) a full syncAll()
     * is triggered. Otherwise only items in its specific categories are synced.
     *
     * Called from DiscountModel::publish(). DiscountModel::save() re-indexes the
     * union of the old and new scope in one pass instead
     * ({@see self::getItemIdsForDiscount()}).
     * NOT used for delete; see syncItems() and the delete hook comments.
     *
     * @return int Number of index rows written
     * @since  1.0.0
     */
    public function syncByDiscount(int $discountId): int
    {
        if ($discountId <= 0) {
            return 0;
        }

        return self::announcing(cause: PricesChangedEvent::CAUSE_DISCOUNT, causeId: $discountId, work: fn (): int => $this->syncDiscountScope(discountId: $discountId));
    }

    /**
     * The work of syncByDiscount(), inside its announcement.
     *
     * @param int $discountId The discount.
     *
     * @return int Number of index rows written.
     *
     * @since  2.2.0
     */
    private function syncDiscountScope(int $discountId): int
    {
        try {
            if ($this->discountAppliesGlobally($discountId)) {
                return $this->syncAll();
            }

            [, $groupSets] = $this->alignGroupSets();

            $categoryIds = $this->getDiscountCategoryIds($discountId);
            if (empty($categoryIds)) {
                return 0;
            }

            $itemIds = $this->getItemIdsInCategories($categoryIds);
            if (empty($itemIds)) {
                return 0;
            }

            return $this->writeIndexRows($itemIds, $this->getActiveContextCombinations(groupSets: $groupSets));
        } catch (Throwable $e) {
            $this->logWarning("syncByDiscount({$discountId}) failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Re-index all items that a tax change could affect.
     *
     * If the tax has category_id = 0 (applies globally) a full syncAll() is
     * triggered. Otherwise only items in its specific categories are synced.
     *
     * Called from TaxModel::publish(). TaxModel::save() re-indexes the union of
     * the old and new scope in one pass instead ({@see self::getItemIdsForTax()}).
     * NOT used for delete; see syncItems() and the delete hook comments.
     *
     * @return int Number of index rows written
     * @since  1.0.0
     */
    public function syncByTax(int $taxId): int
    {
        if ($taxId <= 0) {
            return 0;
        }

        return self::announcing(cause: PricesChangedEvent::CAUSE_TAX, causeId: $taxId, work: fn (): int => $this->syncTaxScope(taxId: $taxId));
    }

    /**
     * The work of syncByTax(), inside its announcement.
     *
     * @param int $taxId The tax.
     *
     * @return int Number of index rows written.
     *
     * @since  2.2.0
     */
    private function syncTaxScope(int $taxId): int
    {
        try {
            if ($this->taxAppliesGlobally($taxId)) {
                return $this->syncAll();
            }

            [, $groupSets] = $this->alignGroupSets();

            $categoryIds = $this->getTaxCategoryIds($taxId);
            if (empty($categoryIds)) {
                return 0;
            }

            $itemIds = $this->getItemIdsInCategories($categoryIds);
            if (empty($itemIds)) {
                return 0;
            }

            return $this->writeIndexRows($itemIds, $this->getActiveContextCombinations(groupSets: $groupSets));
        } catch (Throwable $e) {
            $this->logWarning("syncByTax({$taxId}) failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Request every set of pricing groups customers hold that the index does not
     * know yet, and drop every set nobody holds any more, with its slots.
     *
     * A requested set is only recorded, with its price.set.prepare job, which
     * prepares it (2.2.0), so the request that brought it writes no row of it
     * and returns at once. Nothing else is rewritten, so a change that leaves
     * every customer in a set the index already knows costs nothing more: a new
     * group, a renamed group, an account saved into the set it had.
     *
     * Called by the sync plugin when the customers' sets may have changed
     * outside a pricing row: a group saved under another parent or removed, an
     * account saved into other groups or removed, the users component's guest
     * or new-user group changed. Every other sync entry point runs it first.
     *
     * @return int Number of sets requested; 0 when every set was already known.
     *
     * @since  2.0.0
     */
    public function syncGroupSets(): int
    {
        try {
            return $this->alignGroupSets()[0];
        } catch (Throwable $e) {
            $this->logWarning(message: 'syncGroupSets() failed: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Rewrite every published product's rows of every prepared set, after the
     * sets are brought in line with those customers hold.
     *
     * Safe to run at any time: INSERT ... ON DUPLICATE KEY UPDATE is idempotent.
     * It prepares no set: a set still waiting is written only up to the product
     * its preparation has passed, and the rest of it is its job's (so after an
     * installation, which prepares nothing, the jobs build the index).
     * The sets nobody holds are dropped.
     *
     * Known limitation: a catalogue-wide rule save (a discount or tax for every
     * category) rewrites every prepared set inside its own request, so with many
     * combinations of pricing groups it may be slow or exceed the request's time
     * limit. Planned after the stable release: a versioned, atomic pricing
     * publish (POST-V1-FOLLOWUPS PV1-05).
     *
     * @param string $cause Why, as onAlfaPricesChanged says it (2.2.0; inside a discount's or a tax's write, theirs).
     * @param int $causeId What caused it; 0 for a rebuild.
     *
     * @return int Total number of index rows written
     * @since  1.0.0
     */
    public function syncAll(string $cause = PricesChangedEvent::CAUSE_REBUILD, int $causeId = 0): int
    {
        return self::announcing(cause: $cause, causeId: $causeId, work: function (): int {
            try {
                [, $groupSets] = $this->alignGroupSets();

                return $this->writeIndexRows(
                    $this->getAllPublishedItemIds(),
                    $this->getActiveContextCombinations(groupSets: $groupSets),
                );
            } catch (Throwable $e) {
                $this->logWarning('syncAll() failed: ' . $e->getMessage());

                return 0;
            }
        });
    }

    /**
     * Remove all index rows for a specific item.
     *
     * Call when an item is unpublished, trashed, or soft-deleted so it
     * disappears from the filter immediately without waiting for a full rebuild.
     * Hard deletes are also handled automatically by the FK ON DELETE CASCADE.
     *
     * @param int $itemId The item.
     * @param string $cause Why, as onAlfaPricesChanged says it (2.2.0; a PricesChangedEvent::CAUSE_ constant).
     * @param int $causeId What caused it; 0 for a rebuild.
     *
     * @since  1.0.0
     */
    public function deleteForItem(int $itemId, string $cause = PricesChangedEvent::CAUSE_REBUILD, int $causeId = 0): void
    {
        if ($itemId > 0) {
            $this->deleteForItems(itemIds: [$itemId], cause: $cause, causeId: $causeId);
        }
    }

    /**
     * Remove every index row of some items (unpublished, trashed or removed), a chunk at a time (2.2.0: the price
     * projection's removal, so the index has one writer and its removed rows are announced). Outside a transaction a
     * chunk that fails is logged and the others still go; inside one (a delete's, A-C18-14) the failure is thrown to
     * the caller, whose transaction then rolls back whole.
     *
     * @param int[] $itemIds The items.
     * @param string $cause Why, as onAlfaPricesChanged says it (a PricesChangedEvent::CAUSE_ constant).
     * @param int $causeId What caused it; 0 for a rebuild.
     *
     * @since  2.2.0
     */
    public function deleteForItems(array $itemIds, string $cause = PricesChangedEvent::CAUSE_REBUILD, int $causeId = 0): void
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $itemId): bool => $itemId > 0)));

        if ($itemIds === []) {
            return;
        }

        self::announcing(cause: $cause, causeId: $causeId, work: function () use ($itemIds): void {
            foreach (array_chunk($itemIds, self::REMOVE_CHUNK_SIZE) as $chunk) {
                try {
                    $before = self::detecting() ? $this->readIndexRows(itemIds: $chunk, groupSetIds: null) : null;
                    $query = $this->db->getQuery(true)
                        ->delete($this->db->quoteName('#__alfa_items_price_index'))
                        ->whereIn($this->db->quoteName('item_id'), $chunk);

                    $this->db->setQuery($query)->execute();

                    if ($before !== null) {
                        $this->chunkChanges = ['changes' => self::rowChanges(before: $before, after: []), 'sets' => array_values(array_unique(array_map(static fn (array $row): int => $row['set'], $before)))];
                        $this->keepChunkChanges();
                    }
                } catch (Throwable $e) {
                    // Inside a caller's transaction the failure is the caller's (2.2.0, A-C18-14): a lock wait timeout
                    // or a deadlock swallowed here would let a delete go on, and its cascade take rows no event named.
                    if ($this->db instanceof DatabaseDriver && TransactionState::isOpen(db: $this->db)) {
                        throw $e;
                    }

                    $this->logWarning('deleteForItems() failed for a chunk of ' . \count($chunk) . ' item(s): ' . $e->getMessage());
                }
            }
        });
    }

    /**
     * Prepare one chunk of a waiting set of pricing groups: the work of the price.set.prepare job (2.2.0), one chunk
     * per call, run by the job runner before its chunk transaction (the job keeps its own transactions).
     *
     * From the set's checkpoint (prepared_through) the next published products, at most $limit, are written for the
     * set and the checkpoint moves past them in one transaction, the set's row held for it (prepareChunk()); when no
     * product is left, or fewer than asked were left, the set is marked prepared and from then on it is read. A product
     * whose pricing throws is left out of the set and recorded on it, as before.
     *
     * The chunk's item locks are taken first (ascending, with the wait), the set and the products are read after the
     * grant, and the locks are released after the commit: a save that waits for a product then reads the set's
     * checkpoint past it and writes the set's rows itself. A product whose lock another writer kept for the whole wait
     * is passed over and owed to the catalogue job, which writes its rows once the checkpoint has passed it. A product
     * whose lock failed (the database cannot lock for this connection) is written without it: the job holds the heavy
     * slot, and every request meeting the same failure enqueues instead.
     *
     * The state answered: chunk (more to come), prepared (the set is now read), gone (the set no longer waits: dropped,
     * or already prepared) or failed (the chunk was rolled back; the set still waits; failure is what stopped it).
     *
     * @param int $setId The set.
     * @param int $limit At most this many products.
     *
     * @return array{state: string, written: int, products: int, through: int, failure?: Throwable|null} The chunk's outcome.
     *
     * @since  2.2.0
     */
    public function prepareSetChunk(int $setId, int $limit): array
    {
        return self::announcing(cause: PricesChangedEvent::CAUSE_SET, causeId: $setId, work: fn (): array => $this->prepareSetChunkAnnounced(setId: $setId, limit: $limit));
    }

    /**
     * The work of prepareSetChunk(), inside its announcement (cause set).
     *
     * @param int $setId The set.
     * @param int $limit At most this many products.
     *
     * @return array{state: string, written: int, products: int, through: int, failure?: Throwable|null} The chunk's outcome.
     *
     * @since  2.2.0
     */
    private function prepareSetChunkAnnounced(int $setId, int $limit): array
    {
        $waiting = ' WHERE ' . $this->db->quoteName('id') . ' = ' . $setId . ' AND ' . $this->db->quoteName('prepared_at') . ' IS NULL';
        $through = $this->db->setQuery('SELECT ' . $this->db->quoteName('prepared_through') . ' FROM ' . $this->db->quoteName(self::SETS_TABLE) . $waiting)->loadResult();

        if ($through === null) {
            return ['state' => 'gone', 'written' => 0, 'products' => 0, 'through' => 0];
        }

        $through = (int) $through;
        $limit = max(1, $limit);
        $chunk = $this->publishedItemIdsAfter(after: $through, limit: $limit);

        if ($chunk === []) {
            return ['state' => $this->markPrepared(setId: $setId) === 1 ? 'prepared' : 'gone', 'written' => 0, 'products' => 0, 'through' => $through];
        }

        $this->chunkFailure = null;
        $lock = new IndexRebuildLock($this->db);
        $status = $lock->acquire(itemIds: $chunk);
        $granted = array_keys($status, IndexRebuildLock::GRANTED, true);
        $busy = array_keys($status, IndexRebuildLock::TIMEOUT, true);

        try {
            $set = $this->db->setQuery('SELECT ' . $this->db->quoteName('group_list') . ' FROM ' . $this->db->quoteName(self::SETS_TABLE) . $waiting)->loadObject();

            if ($set === null) {
                return ['state' => 'gone', 'written' => 0, 'products' => 0, 'through' => $through];
            }

            $combinations = $this->getActiveContextCombinations(groupSets: [
                $setId => ['groups' => self::groupsOfList(list: (string) $set->group_list), 'through' => null, 'leftOut' => []],
            ]);
            $leftOut = [];
            $written = $this->prepareChunk(
                setId:        $setId,
                chunk:        array_values(array_diff($chunk, $busy)),
                through:      (int) max($chunk),
                combinations: $combinations,
                globalEdges:  $this->getGlobalBoundaries(),
                leftOut:      $leftOut,
            );
        } finally {
            $lock->release(itemIds: $granted);
        }

        if ($written !== null) {
            ProjectionManager::owe(itemIds: $busy);
        }

        if ($written === null) {
            // Not committed: the chunk failed, or the set was dropped meanwhile.
            return ['state' => $this->groupSetWaits(setId: $setId) ? 'failed' : 'gone', 'written' => 0, 'products' => 0, 'through' => $through, 'failure' => $this->chunkFailure];
        }

        if (\count($chunk) < $limit) {
            $this->markPrepared(setId: $setId);

            return ['state' => 'prepared', 'written' => $written, 'products' => \count($chunk), 'through' => (int) max($chunk)];
        }

        return ['state' => 'chunk', 'written' => $written, 'products' => \count($chunk), 'through' => (int) max($chunk)];
    }

    /**
     * How many products are published: what one set's preparation walks.
     *
     * @since  2.2.0
     */
    public function publishedItemCount(): int
    {
        return (int) $this->db->setQuery(
            'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__alfa_items') . ' WHERE ' . $this->db->quoteName('state') . ' = 1',
        )->loadResult();
    }

    /**
     * The id of the set of pricing groups a digest names, while it waits.
     *
     * @param string $digest The set's digest.
     *
     * @return int|null Null when no set of that digest waits.
     *
     * @since  2.2.0
     */
    public function waitingSetId(string $digest): ?int
    {
        $id = $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('id') . ' FROM ' . $this->db->quoteName(self::SETS_TABLE)
            . ' WHERE ' . $this->db->quoteName('digest') . ' = ' . $this->db->quote($digest) . ' AND ' . $this->db->quoteName('prepared_at') . ' IS NULL',
        )->loadResult();

        return $id === null ? null : (int) $id;
    }

    /**
     * Bring the sets of pricing groups in line with those customers hold, as every sync entry point does: the
     * price.set.prepare job asks it at each claim, so memberships written where no event reaches the shop are
     * followed too (the scheduled task's first step before 2.2.0). New sets get their jobs; sets nobody holds go.
     *
     * @since  2.2.0
     */
    public function alignSets(): void
    {
        $this->alignGroupSets();
    }

    /**
     * Give every waiting set its price.set.prepare job (the update to 2.2.0: the sets the scheduled task left
     * waiting become the jobs' work; each resumes from its checkpoint).
     *
     * @return int The waiting sets, each with its job now.
     *
     * @since  2.2.0
     */
    public function queueWaitingSets(): int
    {
        $digests = $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('digest') . ' FROM ' . $this->db->quoteName(self::SETS_TABLE)
            . ' WHERE ' . $this->db->quoteName('prepared_at') . ' IS NULL ORDER BY ' . $this->db->quoteName('requested_at') . ', ' . $this->db->quoteName('id'),
        )->loadColumn() ?: [];

        foreach ($digests as $digest) {
            PriceSetPrepareJob::enqueueFor(digest: (string) $digest);
        }

        return \count($digests);
    }

    /**
     * What the staff are shown of the preparation: each waiting set with its groups, when it was requested, how many of
     * the published products it holds and its job; and the products that could not be priced for a set when it was
     * prepared.
     *
     * @return array{waiting: list<array{groups: int[], requested_at: string, done: int, products: int, job: array{id: int, state: string, done: int, total: int, error: string}|null}>, leftOut: int[]}
     *
     * @since  2.0.0
     */
    public static function preparationState(): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $products = (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_items') . ' WHERE ' . $db->quoteName('state') . ' = 1',
        )->loadResult();
        $waiting = [];

        foreach ($db->setQuery(
            'SELECT ' . implode(', ', $db->quoteName(['digest', 'group_list', 'requested_at', 'prepared_through'])) . ' FROM ' . $db->quoteName(self::SETS_TABLE)
            . ' WHERE ' . $db->quoteName('prepared_at') . ' IS NULL ORDER BY ' . $db->quoteName('requested_at') . ', ' . $db->quoteName('id'),
        )->loadObjectList() as $set) {
            $through = (int) $set->prepared_through;
            $waiting[] = [
                'groups' => self::groupsOfList(list: (string) $set->group_list),
                'requested_at' => (string) $set->requested_at,
                'done' => $through === 0 ? 0 : (int) $db->setQuery(
                    'SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_items') . ' WHERE ' . $db->quoteName('state') . ' = 1 AND '
                    . $db->quoteName('id') . ' <= ' . $through,
                )->loadResult(),
                'products' => $products,
                'job' => Runner::latestJob(scopeKey: PriceSetPrepareJob::scopeFor(digest: (string) $set->digest)),
            ];
        }

        $leftOut = [];

        foreach ($db->setQuery(
            'SELECT ' . $db->quoteName('left_out') . ' FROM ' . $db->quoteName(self::SETS_TABLE)
            . ' WHERE ' . $db->quoteName('left_out') . ' IS NOT NULL AND ' . $db->quoteName('left_out') . ' <> ' . $db->quote(''),
        )->loadColumn() as $list) {
            $leftOut = array_merge($leftOut, self::groupsOfList(list: (string) $list));
        }

        $leftOut = array_values(array_unique($leftOut));
        sort($leftOut);

        return ['waiting' => $waiting, 'leftOut' => $leftOut];
    }

    // Scope helpers: called by model delete hooks BEFORE the actual delete

    /**
     * Return all item ids affected by a discount.
     *
     * Call this BEFORE deleting the discount so the scope tables still exist.
     * If the discount applies globally (category_id = 0) all published items
     * are returned.
     *
     * @return int[]
     * @since  1.0.0
     */
    public function getItemIdsForDiscount(int $discountId): array
    {
        if ($discountId <= 0) {
            return [];
        }

        if ($this->discountAppliesGlobally($discountId)) {
            return $this->getAllPublishedItemIds();
        }

        $categoryIds = $this->getDiscountCategoryIds($discountId);
        return $this->getItemIdsInCategories($categoryIds);
    }

    /**
     * Return all item ids affected by a tax.
     *
     * Call this BEFORE deleting the tax so the scope tables still exist.
     * If the tax applies globally (category_id = 0) all published items
     * are returned.
     *
     * @return int[]
     * @since  1.0.0
     */
    public function getItemIdsForTax(int $taxId): array
    {
        if ($taxId <= 0) {
            return [];
        }

        if ($this->taxAppliesGlobally($taxId)) {
            return $this->getAllPublishedItemIds();
        }

        $categoryIds = $this->getTaxCategoryIds($taxId);
        return $this->getItemIdsInCategories($categoryIds);
    }

    // Read side: which slots one visitor reads, and how they are ranked

    /**
     * The groups a price row, a discount or a tax names: the groups that can
     * change a price. A customer's other groups change none.
     *
     * Read once per process; every sync entry point reads them again.
     *
     * @return int[] Ascending.
     *
     * @since  2.0.0
     */
    public static function pricingGroups(): array
    {
        if (self::$pricingGroups !== null) {
            return self::$pricingGroups;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $named = static fn (string $table, string $published = ''): string => 'SELECT ' . $db->quoteName('usergroup_id')
            . ' FROM ' . $db->quoteName($table) . ' WHERE ' . $db->quoteName('usergroup_id') . ' > 0' . $published;

        $groups = array_map('intval', $db->setQuery(
            $named('#__alfa_discount_usergroups')
            . ' UNION ' . $named('#__alfa_tax_usergroups')
            . ' UNION ' . $named('#__alfa_items_prices', ' AND ' . $db->quoteName('state') . ' = 1'),
        )->loadColumn());
        sort($groups);

        return self::$pricingGroups = $groups;
    }

    /**
     * The set of pricing groups whose slots one visitor reads: the groups of
     * the context that a price row, a discount or a tax names.
     *
     * A slot of that set was priced with those groups, which are the only ones
     * of the visitor's that change a price, so it holds what the visitor is
     * charged for their own currency and place.
     *
     * @param PriceContext $context The visitor's pricing context.
     *
     * @return string The key the set is found by (groupSetKey()).
     *
     * @since  2.0.0
     */
    public static function slotKey(PriceContext $context): string
    {
        return self::groupSetKey(groupIds: array_intersect($context->getUserGroups(), self::pricingGroups()));
    }

    /**
     * The join that keeps a reader to the slots of one visitor's set, and only
     * once that set is prepared.
     *
     * A set still waiting for its preparation is not read at all, whatever rows
     * the index holds of it: the visitor gets no figure from the index rather
     * than part of one, or another set's. The set's row is found by its unique
     * key, so the database reads it once and takes its id as a constant.
     *
     * @param PriceContext $context The visitor's pricing context.
     * @param string $alias Alias of the index table in the reader's statement.
     *
     * @return string An INNER JOIN clause, to follow the index table in the reader's FROM.
     *
     * @since  2.0.0
     */
    public static function slotSetJoin(PriceContext $context, string $alias): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $sets = $alias . '_set';

        return 'INNER JOIN ' . $db->quoteName(self::SETS_TABLE, $sets)
            . " ON {$sets}.id = {$alias}.group_set_id AND {$sets}.digest = " . $db->quote(self::slotKey(context: $context))
            . " AND {$sets}.prepared_at IS NOT NULL";
    }

    /**
     * The places whose slots one visitor reads, nearest first (2.2.1): the
     * visitor's own place, then each place above it that a discount or a tax
     * names. A buyer below such a place has its rules (PriceDataLoader matches
     * the chain), so its slot holds the buyer's price when the buyer's own
     * place has none. A place above the buyer that only price rows name is
     * passed over: its rows apply to it only, so its slot is not
     * the buyer's price. The slot of no place (0) is read after these.
     *
     * Read from the tree as it is now, so a new place under a ruled one reads
     * that one's slot with no rebuild.
     *
     * @param PriceContext $context The visitor's pricing context.
     *
     * @return int[] Empty for an unknown place.
     *
     * @since  2.2.1
     */
    public static function slotPlaces(PriceContext $context): array
    {
        $chain = PlaceTree::placesFor(placeId: (int) ($context->getLocationId() ?? 0));

        if (\count($chain) < 2) {
            return $chain;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $above = \array_slice($chain, 1);
        $named = static fn (string $table): string => 'SELECT ' . $db->quoteName('place_id') . ' FROM ' . $db->quoteName($table)
            . ' WHERE ' . $db->quoteName('place_id') . ' IN (' . implode(',', $above) . ')';
        $ruled = array_map('intval', $db->setQuery($named('#__alfa_discount_places') . ' UNION ' . $named('#__alfa_tax_places'))->loadColumn() ?: []);

        return array_merge([$chain[0]], array_values(array_intersect($above, $ruled)));
    }

    /**
     * The order in which one visitor's matching slots are preferred, as an
     * ORDER BY list over the index.
     *
     * A visitor matches the slots of their own set of pricing groups in their
     * own currency and in any, at the places slotPlaces() names and at no
     * place. The nearest place decides (2.2.1: the order slotPlaces() gives,
     * no place last), then the visitor's own currency before any; the lower
     * price decides what is left.
     *
     * Every reader of the index ranks by this expression and by no other, so
     * the list, its price filter and its facets cannot disagree about a price.
     *
     * @param string $alias Alias of the index table in the reader's statement.
     * @param int[] $places The visitor's places, nearest first (slotPlaces()).
     *
     * @return string An ORDER BY list, without the keyword.
     *
     * @since  2.0.0
     */
    public static function slotRanking(string $alias, array $places): string
    {
        $near = '';

        foreach (array_values(array_map('intval', $places)) as $position => $place) {
            $near .= " WHEN {$place} THEN {$position}";
        }

        $nearness = $near === '' ? '0' : "CASE {$alias}.place_id{$near} ELSE " . \count($places) . ' END';

        return "{$nearness} ASC, ({$alias}.currency_id > 0) DESC, {$alias}.final_price ASC";
    }

    /**
     * The catalogue's rebuild owed when places take another parent, or lose it
     * to a delete (2.2.1): every slot of a place at or below them was priced
     * with the chain it had. Owed only when such a slot exists: a place no
     * discount, tax or price row names, with none below it, keeps no slot, and
     * the reader follows the tree as it is.
     *
     * @param int[] $placeIds The places that took another parent, or the children of the places deleted.
     *
     * @return bool Whether the rebuild was owed (every product marked and the catalogue job enqueued).
     *
     * @since  2.2.1
     */
    public function oweChainRebuild(array $placeIds): bool
    {
        $placeIds = array_values(array_filter(array_map('intval', $placeIds), static fn (int $place): bool => $place > 0));

        if ($placeIds === []) {
            return false;
        }

        // The tree as it is now.
        PlaceTree::forget();
        $below = array_filter(
            $this->getDistinctPlaceIds(),
            static fn (int $slot): bool => $slot > 0 && array_intersect(PlaceTree::placesFor(placeId: $slot), $placeIds) !== [],
        );

        if ($below === []) {
            return false;
        }

        ProjectionManager::markAllForRebuild();
        CatalogueRebuildJob::enqueue();

        return true;
    }

    /**
     * The set of pricing groups one account holds, as the index keys it.
     *
     * @param int $userId The account.
     *
     * @return string The set's key (groupSetKey()).
     *
     * @since  2.0.0
     */
    public function accountGroupSet(int $userId): string
    {
        $lists = $this->accountGroupLists(userId: $userId);

        return self::groupSetKey(groupIds: self::groupsOfList(list: $lists[0] ?? ''));
    }

    /**
     * Whether the index knows one set of pricing groups: prepared, or requested
     * and waiting for its preparation.
     *
     * @param string $groupSet The set's key (groupSetKey()).
     *
     *
     * @since  2.0.0
     */
    public function holdsGroupSet(string $groupSet): bool
    {
        $query = $this->db->getQuery(true)
            ->select('1')
            ->from($this->db->quoteName(self::SETS_TABLE))
            ->where($this->db->quoteName('digest') . ' = ' . $this->db->quote($groupSet));

        return (bool) $this->db->setQuery($query)->loadResult();
    }

    // Core indexing

    /**
     * Write index rows for a list of items x context combinations.
     *
     * For each (item x currency x place x set of pricing groups) slot:
     *   1. Build a PriceContext priced with exactly that set
     *   2. Batch-compute prices with PriceCalculator at quantity = 1
     *   3. Derive all column values from the PriceResult
     *   4. Upsert via INSERT ... ON DUPLICATE KEY UPDATE
     *
     * Items where base_price = 0 (no price row matched) are skipped. A set still
     * waiting for its preparation is written only for the items it has passed.
     * An item that is not published, as read under its lock, has its rows of the
     * sets written deleted and none written (2.2.1): a catalogue rebuild
     * marks every item, published or not.
     *
     * Each chunk is written under its items' locks (2.2.0, IndexRebuildLock); an item not granted, and a chunk that
     * failed, are owed to the catalogue job (ProjectionManager::owe()).
     *
     * @param int[] $itemIds
     * @param array $combinations From getActiveContextCombinations()
     * @param bool $strict A chunk whose calculation failed, or whose locks were not granted, throws once rolled back
     *                     (2.2.0, the manager's rebuilds through syncItemsOrFail()); false owes that chunk to the
     *                     catalogue job and moves on to the next, the failure logged.
     * @return int Total rows written
     *
     * @throws RuntimeException When strict and a calculation failed or a lock was not granted.
     *
     * @since  1.0.0
     */
    private function writeIndexRows(array $itemIds, array $combinations, bool $strict = false): int
    {
        if (empty($itemIds) || empty($combinations)) {
            return 0;
        }

        $written = 0;
        $globalEdges = $this->getGlobalBoundaries();
        $lock = new IndexRebuildLock($this->db);
        /** @var DatabaseDriver $db */
        $db = $this->db;
        $nested = TransactionState::isOpen(db: $db);
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $itemId): bool => $itemId > 0)));
        sort($itemIds);

        foreach (array_chunk($itemIds, self::CHUNK_SIZE) as $chunk) {
            // The chunk's item locks first, ascending, with the wait (2.2.0): the items, and how far each waiting set
            // has come, are read after the grant, and the rows commit before the release. An item not granted is left
            // out and owed to the catalogue job; a strict caller (which holds the locks already) hears it.
            $status = $lock->acquire(itemIds: $chunk);
            $granted = array_keys($status, IndexRebuildLock::GRANTED, true);
            $refused = array_values(array_diff($chunk, $granted));

            if ($refused !== [] && $strict) {
                $lock->release(itemIds: $granted);

                throw new RuntimeException('Price index: the rebuild locks of item(s) ' . implode(', ', $refused) . ' were not granted; their rows were kept as they were.');
            }

            ProjectionManager::owe(itemIds: $refused);

            if ($granted === []) {
                continue;
            }

            try {
                $chunkWritten = $this->writeLockedChunk(chunk: $granted, combinations: $combinations, globalEdges: $globalEdges);
            } catch (Throwable $e) {
                // A strict caller, or one whose transaction the failure ended, hears it; otherwise the chunk is owed
                // and the others go on.
                if ($strict || ($nested && (!TransactionState::isOpen(db: $db) || TransactionState::endedBy(db: $db, failure: $e)))) {
                    throw $e;
                }

                $this->logWarning('A chunk of the price index failed and is left to the catalogue rebuild: ' . $e->getMessage());
                $chunkWritten = null;
            } finally {
                $lock->release(itemIds: $granted);
            }

            if ($chunkWritten !== null) {
                $written += $chunkWritten;

                continue;
            }

            // A calculation was skipped after the delete; the chunk's prior (complete) rows were restored. Other
            // chunks are unaffected; the failure was already logged. A strict caller hears that these items were not
            // rebuilt; otherwise they are owed to the catalogue job.
            if ($strict) {
                throw new RuntimeException('Price index: a calculation failed for item(s) ' . implode(', ', $granted) . '; their rows were kept as they were.');
            }

            ProjectionManager::owe(itemIds: $granted);
        }

        return $written;
    }

    /**
     * One chunk of writeIndexRows(), its items' locks held: the waiting sets' progress read again, then the chunk's
     * rows swapped in one transaction level.
     *
     * Segments are rebuilt wholesale (their keys and count change with the windows; an update could never know which
     * old rows became obsolete). The transaction makes the swap atomic, so a concurrent catalog query never sees the
     * gap.
     *
     * @param int[] $chunk The items, their locks held.
     * @param array $combinations From getActiveContextCombinations().
     * @param array $globalEdges From getGlobalBoundaries().
     *
     * @return int|null Rows written; null when a calculation was skipped and the chunk's rows were restored.
     *
     * @throws Throwable A failure of the chunk, its level rolled back.
     *
     * @since  2.2.0
     */
    private function writeLockedChunk(array $chunk, array $combinations, array $globalEdges): ?int
    {
        $combinations = $this->withSetsAsNow(combinations: $combinations);

        if ($combinations === []) {
            return 0;
        }

        $this->db->transactionStart(true);

        try {
            $chunkWritten = $this->writeChunk(chunk: $chunk, combinations: $combinations, globalEdges: $globalEdges);

            if ($chunkWritten !== null) {
                $this->db->transactionCommit(true);
                // Committed: what the chunk changed joins the announcement (2.2.0).
                $this->keepChunkChanges();

                return $chunkWritten;
            }
        } catch (Throwable $e) {
            $this->dropChunkChanges();
            TransactionState::rollBackLevel(db: $this->db, failure: $e);

            throw $e;
        }

        $this->dropChunkChanges();
        $this->db->transactionRollback(true);

        return null;
    }

    /**
     * The combinations with each set's progress as the database holds it now: read under a chunk's item locks, so a
     * set whose preparation passed the chunk's items meanwhile is written for them, a set prepared meanwhile for every
     * item, and a set dropped meanwhile for none (2.2.0). The preparer holds its chunk's item locks until its commit,
     * so a preparation that covers an item is either committed here or waits for this chunk.
     *
     * @param array $combinations From getActiveContextCombinations().
     *
     * @return array The combinations still to write, their through and leftOut as now.
     *
     * @since  2.2.0
     */
    private function withSetsAsNow(array $combinations): array
    {
        $setIds = array_values(array_unique(array_map(static fn (array $combo): int => (int) $combo['group_set_id'], $combinations)));
        $now = $setIds === [] ? [] : ($this->db->setQuery(
            'SELECT ' . implode(', ', $this->db->quoteName(['id', 'prepared_at', 'prepared_through', 'left_out'])) . ' FROM ' . $this->db->quoteName(self::SETS_TABLE)
            . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . implode(',', $setIds) . ')',
        )->loadObjectList('id') ?: []);
        $out = [];

        foreach ($combinations as $combo) {
            $set = $now[(int) $combo['group_set_id']] ?? null;

            if ($set === null) {
                continue;
            }

            $combo['through'] = $set->prepared_at === null ? (int) $set->prepared_through : null;
            $combo['leftOut'] = self::groupsOfList(list: (string) $set->left_out);
            $out[] = $combo;
        }

        return $out;
    }

    /**
     * Rewrite one chunk of products, inside the caller's transaction: delete the
     * rows of the sets written here, price every slot, upsert it.
     *
     * A prepared set is written for every product of the chunk; a set still
     * waiting for its preparation only for the products its preparation has
     * passed (combination 'through'), so its other rows stay its preparer's.
     *
     * @param int[] $chunk The products.
     * @param array $combinations From getActiveContextCombinations().
     * @param string[] $globalEdges From getGlobalBoundaries().
     *
     * @return int|null Rows written; null when a calculation failed (logged): the caller rolls the chunk back.
     *
     * @since  2.0.0
     */
    private function writeChunk(array $chunk, array $combinations, array $globalEdges): ?int
    {
        // TEMPORAL index: one row per TIME SEGMENT, cut at every upcoming
        // publish-window edge (the item's price rows + all discount/tax
        // windows). Each segment is computed by the real engine evaluated AT
        // that moment, so a promo price exists in the index BEFORE its window
        // opens and the catalog query simply picks the row matching NOW():
        // no scheduler needed, segments activate and expire by themselves.
        $itemEdges = $this->getItemBoundaries($chunk);

        // Priced from what the database holds now: rows an earlier pricing in
        // this process loaded may since have changed, and so may the place tree
        // (2.2.1: a long run, such as the command-line task runner's, reads it
        // again for every chunk).
        PriceDataLoader::forgetLoaded();
        PlaceTree::forget();

        // Only a published product has rows (2.2.1): the rows of one that is not are deleted below with the
        // chunk's, as the unpublish path deletes them, and nothing is priced for it.
        $published = $this->publishedOf(itemIds: $chunk);

        // The products each set is written for in this chunk.
        $itemsOf = [];

        foreach ($combinations as $combo) {
            $setId = (int) $combo['group_set_id'];
            $through = $combo['through'];
            $itemsOf[$setId] ??= $through === null
                ? $chunk
                : array_values(array_filter($chunk, static fn (int $itemId): bool => $itemId <= $through));
        }

        // One DELETE per distinct list of products: the rows of exactly the sets
        // rewritten below, and of no other.
        $setsByItems = [];

        foreach ($itemsOf as $setId => $items) {
            if ($items !== []) {
                $setsByItems[implode(',', $items)][] = $setId;
            }
        }

        // While something listens (2.2.0): the rows about to be rewritten, read once before the delete, and the rows
        // written kept below, so the chunk can say which rows changed.
        $detect = self::detecting();
        $before = [];
        $after = [];

        if ($detect) {
            $setsWritten = array_keys(array_filter($itemsOf, static fn (array $items): bool => $items !== []));
            $before = $setsWritten === [] ? [] : array_filter(
                $this->readIndexRows(itemIds: $chunk, groupSetIds: $setsWritten),
                static fn (array $row): bool => \in_array($row['item'], $itemsOf[$row['set']] ?? [], true),
            );
        }

        foreach ($setsByItems as $items => $setIds) {
            $this->deleteIndexRows(itemIds: array_map('intval', explode(',', (string) $items)), groupSetIds: $setIds);
        }

        // Group items by segment start so each (start x context) slot
        // is ONE batched calculator call. valid_until = the item's
        // next edge.
        $startGroups = [];

        foreach ($published as $itemId) {
            $edges = array_values(array_unique(array_merge($globalEdges, $itemEdges[$itemId] ?? [])));
            sort($edges);
            $edges = array_slice($edges, 0, self::MAX_SEGMENTS - 1);
            $starts = array_merge([null], $edges);

            foreach ($starts as $i => $start) {
                $key = $start ?? 'live';
                $startGroups[$key]['start'] = $start;
                $startGroups[$key]['items'][] = $itemId;
                $startGroups[$key]['until'][$itemId] = $starts[$i + 1] ?? null;
            }
        }

        $written = 0;

        foreach ($combinations as $combo) {
            $currencyId = (int) $combo['currency_id'];
            $placeId = (int) $combo['place_id'];
            $groupSetId = (int) $combo['group_set_id'];
            $setItems = $itemsOf[$groupSetId];

            if ($setItems === []) {
                continue;
            }

            foreach ($startGroups as $group) {
                $items = array_values(array_intersect($group['items'], $setItems));

                if ($items === []) {
                    continue;
                }

                // Future segments evaluate one second INSIDE the segment so
                // windows ending exactly on the edge are already excluded.
                $reference = $group['start'] !== null
                    ? Factory::getDate($group['start'])->modify('+1 second')->toSql()
                    : null;

                // Anonymous, priced with exactly the slot's pricing groups behind
                // the "any group" sentinel: what a customer holding that set is charged.
                $context = PriceContext::forIndex(currencyId: $currencyId, placeId: $placeId, usergroupId: 0)
                    ->withUserGroups(groups: array_merge([0], $combo['groups']))
                    ->withReferenceDate($reference);

                try {
                    $results = $this->calculator->calculate($items, 1, $context);
                } catch (Throwable $e) {
                    $this->lastFailure = $e->getMessage();

                    // The delete above already wiped this chunk's prior rows, so
                    // committing now would leave its items with partial / missing
                    // index rows: they would silently drop out of the catalog
                    // filter/sort (one poisoned item can strip the whole chunk).
                    // The caller rolls the chunk back instead: its COMPLETE prior
                    // rows (stale but consistent, which the reader can always
                    // serve safely) or, for a set being prepared, its checkpoint.
                    $this->logWarning(
                        "Calculation failed for currency={$currencyId} place={$placeId} "
                        . "set={$groupSetId} at=" . ($group['start'] ?? 'now')
                        . '; rolling this chunk back: ' . $e->getMessage(),
                    );

                    return null;
                }

                foreach ($items as $itemId) {
                    $result = $results[$itemId] ?? null;
                    if ($result === null) {
                        continue;
                    }

                    // Map PriceResult -> index columns
                    // Column names match config fields exactly.

                    $basePrice = round($result->getBaseTotal()->getAmount(), 4);

                    // Skip segments with no matching price row: the item is
                    // simply absent from the catalog for that period.
                    if ($basePrice <= 0) {
                        continue;
                    }

                    $discountAmount = round($result->getSavingsTotal()->getAmount(), 4);
                    $basePriceWithDiscounts = round($result->getSubtotal()->getAmount(), 4);
                    $taxAmount = round($result->getTaxTotal()->getAmount(), 4);
                    $finalPrice = round($result->getTotal()->getAmount(), 4);

                    // base_price_with_tax is the UNDISCOUNTED gross: the struck
                    // "was" price. PriceResult owns that derivation (tax on the full
                    // base at the effective rate); $taxTotal is the tax on what the
                    // customer pays and is the wrong figure for it, before tax and
                    // after tax alike.
                    $basePriceWithTax = round($result->getBaseTotalWithTax()->getAmount(), 4);

                    // discount_percent: use PriceResult's authoritative calculation
                    // which correctly handles both before-tax and after-tax discounts
                    // via DiscountSummary. This matches what the frontend price layout displays.
                    $discountPercent = $result->getSavingsPercent();

                    $this->upsertRow(
                        $itemId,
                        $currencyId,
                        $placeId,
                        $groupSetId,
                        $basePrice,
                        $discountAmount,
                        $basePriceWithDiscounts,
                        $taxAmount,
                        $basePriceWithTax,
                        $finalPrice,
                        $discountPercent,
                        $group['start'] ?? self::EPOCH,
                        $group['until'][$itemId] ?? null,
                    );

                    if ($detect) {
                        $row = [
                            'item' => $itemId, 'combination' => 0, 'currency' => $currencyId, 'place' => $placeId, 'set' => $groupSetId,
                            'from' => $group['start'] ?? self::EPOCH, 'until' => $group['until'][$itemId] ?? null,
                            // The column's text, decimal(24,8): the four-decimal value printed fixed-point and
                            // padded to eight, never through a float's eighth decimal.
                            'final' => sprintf('%.4F', $finalPrice) . '0000',
                        ];
                        $after[self::rowKey(row: $row)] = $row;
                    }

                    $written++;
                }
            }
        }

        // A product its preparation left out of a set, priced here for that set, is
        // left out no more.
        $leftOutOf = [];

        foreach ($combinations as $combo) {
            $leftOutOf[(int) $combo['group_set_id']] ??= $combo['leftOut'] ?? [];
        }

        foreach ($leftOutOf as $setId => $leftOut) {
            $priced = array_intersect($leftOut, $itemsOf[$setId]);

            if ($priced !== []) {
                $this->db->setQuery(
                    'UPDATE ' . $this->db->quoteName(self::SETS_TABLE) . ' SET ' . $this->db->quoteName('left_out') . ' = '
                    . $this->db->quote(implode(',', array_diff($leftOut, $priced))) . ' WHERE ' . $this->db->quoteName('id') . ' = ' . (int) $setId,
                )->execute();
            }
        }

        $this->chunkChanges = $detect
            ? ['changes' => self::rowChanges(before: $before, after: $after), 'sets' => array_keys(array_filter($itemsOf, static fn (array $items): bool => $items !== []))]
            : ['changes' => [], 'sets' => []];

        return $written;
    }

    // The price-change event (2.2.0)

    /**
     * Run one write of the index as one announcement of onAlfaPricesChanged: whether anything listens is asked once
     * (the alfa group imported first), the committed chunks' changes join it, and it goes out once, after the outermost
     * commit that holds them (PostCommit::defer(): at once with no transaction open, dropped by a rollback). A write
     * made inside another one joins it and keeps its cause. A write that changes more than ANNOUNCE_LIMIT rows outside
     * a caller's transaction is announced by several events as its chunks commit, each exact for its rows, the same
     * cause and cause id, no row twice; inside a caller's transaction its rows are held until the outermost commit.
     *
     * @param string $cause Why the rows are written (a PricesChangedEvent::CAUSE_ constant).
     * @param int $causeId What caused it; 0 for a rebuild.
     * @param callable $work fn(): mixed, the write.
     *
     * @return mixed What the write answers.
     *
     * @since  2.2.0
     */
    public static function announcing(string $cause, int $causeId, callable $work): mixed
    {
        if (self::$announcement !== null) {
            return $work();
        }

        $announcement = (object) ['cause' => $cause, 'causeId' => max(0, $causeId), 'listening' => self::listening(), 'items' => [], 'sets' => [], 'changes' => []];
        self::$announcement = $announcement;

        try {
            return $work();
        } finally {
            self::$announcement = null;

            if ($announcement->listening) {
                $db = Factory::getContainer()->get('DatabaseDriver');
                $deliver = static function () use ($announcement): void {
                    self::deliver(announcement: $announcement);
                };

                $db instanceof DatabaseDriver ? PostCommit::defer(db: $db, label: PricesChangedEvent::NAME, work: $deliver) : $deliver();
            }
        }
    }

    /**
     * Whether anything listens to onAlfaPricesChanged on the shared dispatcher, the alfa group imported first.
     *
     * @since  2.2.0
     */
    private static function listening(): bool
    {
        try {
            $plugins = AlfaPluginHelper::get();
            $plugins->importGroup('alfa');

            return $plugins->hasListeners(PricesChangedEvent::NAME);
        } catch (Throwable $e) {
            Log::add('[PriceIndexSyncService] Whether anything listens to ' . PricesChangedEvent::NAME . ' could not be asked; nothing is announced: ' . $e->getMessage(), Log::WARNING, 'com_alfa');

            return false;
        }
    }

    /**
     * Whether the write running now detects its changes: an announcement is open and something listens.
     *
     * @since  2.2.0
     */
    private static function detecting(): bool
    {
        return self::$announcement?->listening === true;
    }

    /**
     * The last chunk committed: what it changed joins the announcement once every level around it has committed
     * (PostCommit::defer(), dropped with a level that rolls back).
     *
     * @since  2.2.0
     */
    private function keepChunkChanges(): void
    {
        $kept = $this->chunkChanges;
        $this->dropChunkChanges();
        $announcement = self::$announcement;

        if ($announcement === null || !$announcement->listening || ($kept['changes'] === [] && $kept['sets'] === [])) {
            return;
        }

        $db = $this->db;
        $join = static function () use ($announcement, $kept, $db): void {
            foreach ($kept['changes'] as $change) {
                $announcement->changes[] = $change;
                $announcement->items[$change->itemId] = true;
            }

            foreach ($kept['sets'] as $setId) {
                $announcement->sets[$setId] = true;
            }

            // THE BOUND (A-C18-4). Past the limit, with no transaction open around the write, what is collected is
            // committed: it goes out as its own event and the collection starts again, so a listener never holds the
            // whole of a catalogue-wide write in memory. Inside a caller's transaction the rows wait for its commit.
            if (\count($announcement->changes) >= self::ANNOUNCE_LIMIT && !($db instanceof DatabaseDriver && TransactionState::isOpen($db))) {
                $part = clone $announcement;
                $announcement->changes = [];
                $announcement->items = [];
                $announcement->sets = [];
                self::deliver(announcement: $part);
            }
        };

        $this->db instanceof DatabaseDriver ? PostCommit::defer(db: $this->db, label: PricesChangedEvent::NAME, work: $join) : $join();
    }

    /**
     * Forget what the last chunk changed: it was rolled back, or never written.
     *
     * @since  2.2.0
     */
    private function dropChunkChanges(): void
    {
        $this->chunkChanges = ['changes' => [], 'sets' => []];
    }

    /**
     * Deliver an announcement that holds changes: onAlfaPricesChanged on the shared dispatcher, an after phase (mode A,
     * isolated: a listener's failure is logged and the next one runs). One raised while another is being delivered
     * waits for it (A-C18-7).
     *
     * @param stdClass $announcement The announcement.
     *
     * @since  2.2.0
     */
    private static function deliver(stdClass $announcement): void
    {
        if ($announcement->changes === []) {
            return;
        }

        // A listener that writes prices raises its own announcement while this one is delivered: it waits, and goes
        // out when this delivery ends, so every listener hears the events in the order their writes committed.
        if (self::$delivering) {
            self::$deliveries[] = $announcement;

            return;
        }

        self::$delivering = true;

        try {
            self::dispatch(announcement: $announcement);

            while (self::$deliveries !== []) {
                self::dispatch(announcement: array_shift(self::$deliveries));
            }
        } finally {
            self::$delivering = false;
            self::$deliveries = [];
        }
    }

    /**
     * Send one announcement: onAlfaPricesChanged on the shared dispatcher, an after phase (mode A, isolated).
     *
     * @param stdClass $announcement The announcement, holding changes.
     *
     * @since  2.2.0
     */
    private static function dispatch(stdClass $announcement): void
    {
        $itemIds = array_keys($announcement->items);
        $setIds = array_keys($announcement->sets);
        sort($itemIds);
        sort($setIds);

        try {
            $plugins = AlfaPluginHelper::get();
            $plugins->importGroup('alfa');
            $plugins->dispatchIsolated(new PricesChangedEvent(PricesChangedEvent::NAME, [
                'itemIds' => $itemIds,
                'cause' => $announcement->cause,
                'causeId' => $announcement->causeId,
                'groupSetIds' => $setIds,
                'changes' => $announcement->changes,
            ]));
        } catch (Throwable $e) {
            Log::add('[PriceIndexSyncService] ' . PricesChangedEvent::NAME . ' was not delivered: ' . $e->getMessage(), Log::WARNING, 'com_alfa');
        }
    }

    /**
     * The index rows of some items, as stored: of the given sets of pricing groups, or of every set.
     *
     * @param int[] $itemIds The items.
     * @param int[]|null $groupSetIds The sets; null for every set.
     *
     * @return array<string, array{item: int, combination: int, currency: int, place: int, set: int, from: string, until: string|null, final: string}> By row key.
     *
     * @since  2.2.0
     */
    private function readIndexRows(array $itemIds, ?array $groupSetIds): array
    {
        $db = $this->db;
        // The ids as integers in the statement's text, not bound: the locking clause is appended to the text, which
        // carries no bound value.
        $query = $db->getQuery(true)
            ->select($db->quoteName(['item_id', 'combination_id', 'currency_id', 'place_id', 'group_set_id', 'valid_from', 'valid_until', 'final_price']))
            ->from($db->quoteName('#__alfa_items_price_index'))
            ->where($db->quoteName('item_id') . ' IN (' . implode(',', array_map('intval', $itemIds)) . ')');

        if ($groupSetIds !== null) {
            $query->where($db->quoteName('group_set_id') . ' IN (' . implode(',', array_map('intval', $groupSetIds)) . ')');
        }

        $rows = [];

        // A LOCKING READ (A-C18-8): the rows the delete takes next, as they stand, also inside a caller's transaction
        // whose snapshot is older than the last commit.
        foreach ($db->setQuery((string) $query . ' FOR UPDATE')->loadObjectList() ?: [] as $stored) {
            $row = [
                'item' => (int) $stored->item_id, 'combination' => (int) $stored->combination_id, 'currency' => (int) $stored->currency_id,
                'place' => (int) $stored->place_id, 'set' => (int) $stored->group_set_id, 'from' => (string) $stored->valid_from,
                'until' => $stored->valid_until === null ? null : (string) $stored->valid_until, 'final' => (string) $stored->final_price,
            ];
            $rows[self::rowKey(row: $row)] = $row;
        }

        return $rows;
    }

    /**
     * A row's identity: the index's primary key.
     *
     * @param array{item: int, combination: int, currency: int, place: int, set: int, from: string} $row The row.
     *
     * @since  2.2.0
     */
    private static function rowKey(array $row): string
    {
        return $row['item'] . '|' . $row['combination'] . '|' . $row['currency'] . '|' . $row['place'] . '|' . $row['set'] . '|' . $row['from'];
    }

    /**
     * The rows that changed between two readings of the same rows, by key: a final price or a window end that differs,
     * a row new, a row gone.
     *
     * @param array<string, array> $before The rows before, by key.
     * @param array<string, array> $after The rows after, by key.
     *
     * @return PriceChange[]
     *
     * @since  2.2.0
     */
    private static function rowChanges(array $before, array $after): array
    {
        $changes = [];

        foreach ($after + $before as $key => $row) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if ($old !== null && $new !== null && $old['final'] === $new['final'] && $old['until'] === $new['until']) {
                continue;
            }

            $changes[] = new PriceChange(
                itemId:        $row['item'],
                combinationId: $row['combination'],
                currencyId:    $row['currency'],
                placeId:       $row['place'],
                groupSetId:    $row['set'],
                validFrom:     $row['from'],
                validUntil:    ($new ?? $old)['until'],
                oldFinalPrice: $old['final'] ?? null,
                newFinalPrice: $new['final'] ?? null,
            );
        }

        return $changes;
    }

    /**
     * Drop the index rows of the given items in some sets of pricing groups
     * (segments are rebuilt wholesale).
     *
     * @param int[] $itemIds
     * @param int[] $groupSetIds The sets whose rows of those items go.
     *
     * @since  2.0.0
     */
    private function deleteIndexRows(array $itemIds, array $groupSetIds): void
    {
        $query = $this->db->getQuery(true)
            ->delete($this->db->quoteName('#__alfa_items_price_index'))
            ->whereIn($this->db->quoteName('item_id'), array_map('intval', $itemIds))
            ->where($this->db->quoteName('group_set_id') . ' IN (' . implode(',', array_map('intval', $groupSetIds)) . ')');

        $this->db->setQuery($query)->execute();
    }

    /**
     * Upsert one row into #__alfa_items_price_index.
     *
     * INSERT ... ON DUPLICATE KEY UPDATE is atomic and idempotent. updated_at is
     * the database's clock at the moment the row is written.
     * @since  1.0.0
     */
    private function upsertRow(
        int $itemId,
        int $currencyId,
        int $placeId,
        int $groupSetId,
        float $basePrice,
        float $discountAmount,
        float $basePriceWithDiscounts,
        float $taxAmount,
        float $basePriceWithTax,
        float $finalPrice,
        float $discountPercent,
        string $validFrom = self::EPOCH,
        ?string $validUntil = null,
        int $combinationId = 0,
    ): void {
        $db = $this->db;
        $table = $db->quoteName('#__alfa_items_price_index');
        $validFromSql = $db->quote($validFrom);
        $validUntilSql = $validUntil !== null ? $db->quote($validUntil) : 'NULL';

        $sql = "INSERT INTO {$table}
                    (item_id, combination_id, currency_id, place_id, group_set_id,
                     base_price, discount_amount, base_price_with_discounts,
                     tax_amount, base_price_with_tax, final_price,
                     discount_percent, valid_from, valid_until, updated_at)
                VALUES
                    ({$itemId}, {$combinationId}, {$currencyId}, {$placeId}, {$groupSetId},
                     {$basePrice}, {$discountAmount}, {$basePriceWithDiscounts},
                     {$taxAmount}, {$basePriceWithTax}, {$finalPrice},
                     {$discountPercent}, {$validFromSql}, {$validUntilSql}, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE
                    base_price                = VALUES(base_price),
                    discount_amount           = VALUES(discount_amount),
                    base_price_with_discounts = VALUES(base_price_with_discounts),
                    tax_amount                = VALUES(tax_amount),
                    base_price_with_tax       = VALUES(base_price_with_tax),
                    final_price               = VALUES(final_price),
                    discount_percent          = VALUES(discount_percent),
                    valid_until               = VALUES(valid_until),
                    updated_at                = VALUES(updated_at)";

        $db->setQuery($sql)->execute();
    }

    // Temporal boundaries (valid_from / valid_until segments)

    /**
     * Every FUTURE publish-window edge of each item's own price rows
     * (item-level rows only; combination rows have their own lifecycle).
     *
     * @param int[] $itemIds The chunk being indexed.
     *
     * @return array item_id => [datetime, ...]
     *
     * @since  2.0.0
     */
    private function getItemBoundaries(array $itemIds): array
    {
        $db = $this->db;
        $list = implode(',', array_map('intval', $itemIds));
        $now = $db->quote(Factory::getDate()->toSql());
        $prices = $db->quoteName('#__alfa_items_prices');

        $sql = "SELECT DISTINCT item_id, publish_up AS boundary FROM {$prices}
                 WHERE state = 1 AND combination_id IS NULL AND item_id IN ({$list})
                   AND publish_up IS NOT NULL AND publish_up > {$now}
                UNION
                SELECT DISTINCT item_id, publish_down FROM {$prices}
                 WHERE state = 1 AND combination_id IS NULL AND item_id IN ({$list})
                   AND publish_down IS NOT NULL AND publish_down > {$now}";

        $boundaries = [];

        foreach ($db->setQuery($sql)->loadObjectList() ?: [] as $rowBoundary) {
            $boundaries[(int) $rowBoundary->item_id][] = (string) $rowBoundary->boundary;
        }

        return $boundaries;
    }

    /**
     * Every FUTURE publish-window edge across ALL discounts and taxes
     * (nearest first, capped).
     *
     * Deliberately not item-scoped: resolving each rule's category/
     * manufacturer/place/group scope per item would replicate the loader's
     * join matrix. An extra segment with identical values for unaffected
     * items is cheap; a missed boundary would mean wrong prices.
     *
     * @return string[] Sorted datetime strings.
     *
     * @since  2.0.0
     */
    private function getGlobalBoundaries(): array
    {
        $db = $this->db;
        $now = $db->quote(Factory::getDate()->toSql());
        $union = [];

        foreach (['#__alfa_discounts', '#__alfa_taxes'] as $tableName) {
            foreach (['publish_up', 'publish_down'] as $column) {
                $union[] = "SELECT {$column} AS boundary FROM {$db->quoteName($tableName)}
                             WHERE state = 1 AND {$column} IS NOT NULL AND {$column} > {$now}";
            }
        }

        $sql = 'SELECT DISTINCT boundary FROM (' . implode(' UNION ALL ', $union) . ') AS boundaries'
            . ' ORDER BY boundary ASC LIMIT ' . (self::MAX_SEGMENTS - 2);

        return array_map('strval', $db->setQuery($sql)->loadColumn() ?: []);
    }

    // Combination discovery

    /**
     * Build all (currency x place x set of pricing groups) combinations worth indexing.
     *
     * Only combinations that can produce different prices are included:
     *   Currencies - distinct currency_id from items_prices + sentinel 0
     *   Group sets - the sets of pricing groups customers hold (groupSetsInUse())
     *   Places     - distinct values from discount_places, tax_places,
     *                and country_id from items_prices + sentinel 0
     *
     * Sentinel 0 = "any / default" for currency and place, always included.
     *
     * @param array<int, array{groups: int[], through: int|null, leftOut?: int[]}> $groupSets The sets to write, by id:
     *                                                                                        their groups; for a set still waiting for its preparation the last product it has passed (null for a
     *                                                                                        prepared set); the products its preparation left out.
     *
     * @return array Array of ['currency_id'=>N, 'place_id'=>N, 'group_set_id'=>N, 'groups'=>int[], 'through'=>?int, 'leftOut'=>int[]]
     * @since  1.0.0
     */
    private function getActiveContextCombinations(array $groupSets): array
    {
        if ($groupSets === []) {
            return [];
        }

        $currencies = $this->getDistinctCurrencyIds();
        $places = $this->getDistinctPlaceIds();

        $combinations = [];
        foreach ($currencies as $cid) {
            foreach ($places as $pid) {
                foreach ($groupSets as $groupSetId => $groupSet) {
                    $combinations[] = [
                        'currency_id' => $cid,
                        'place_id' => $pid,
                        'group_set_id' => (int) $groupSetId,
                        'groups' => $groupSet['groups'],
                        'through' => $groupSet['through'],
                        'leftOut' => $groupSet['leftOut'] ?? [],
                    ];
                }
            }
        }

        return $combinations;
    }

    /**
     * Distinct currency DB ids in items_prices, plus sentinel 0.
     *
     * @return int[]
     * @since  1.0.0
     */
    private function getDistinctCurrencyIds(): array
    {
        $query = $this->db->getQuery(true)
            ->select('DISTINCT ' . $this->db->quoteName('currency_id'))
            ->from($this->db->quoteName('#__alfa_items_prices'))
            ->where($this->db->quoteName('state') . ' = 1');

        $this->db->setQuery($query);
        return array_unique(array_merge([0], array_map('intval', $this->db->loadColumn())));
    }

    // Sets of pricing groups

    /**
     * A set of groups as it is kept: the ids ascending, comma-separated.
     *
     * @param int[] $groupIds The groups; the "any group" sentinel is no group.
     *
     * @return string '' for no group.
     *
     * @since  2.0.0
     */
    private static function groupSetList(array $groupIds): string
    {
        $groupIds = array_values(array_unique(array_filter(
            array_map('intval', $groupIds),
            static fn (int $groupId): bool => $groupId > 0,
        )));
        sort($groupIds);

        return implode(',', $groupIds);
    }

    /**
     * The key a set of groups is found by, by the writer and the reader alike:
     * the SHA-1 of its list, so every membership has a key of one length.
     *
     * @param int[] $groupIds The groups; the "any group" sentinel is no group.
     *
     * @return string 40 hex digits.
     *
     * @since  2.0.0
     */
    private static function groupSetKey(array $groupIds): string
    {
        return sha1(self::groupSetList(groupIds: $groupIds));
    }

    /**
     * The groups of a list the accounts statement answers.
     *
     * @param string $list Ids, comma-separated; '' for none.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    private static function groupsOfList(string $list): array
    {
        return $list === '' ? [] : array_map('intval', explode(',', $list));
    }

    /**
     * The sets of pricing groups customers really hold: the set of every
     * account, of a visitor with no account and of a newly registered customer.
     * No other combination of the groups is ever requested.
     *
     * @return array<string, int[]> Key (groupSetKey()) => its groups.
     *
     * @since  2.0.0
     */
    private function groupSetsInUse(): array
    {
        $pricing = self::pricingGroups();

        // No pricing group: every customer holds the same, empty, set.
        if ($pricing === []) {
            return [self::groupSetKey(groupIds: []) => []];
        }

        $groupSets = [];

        foreach ($this->accountGroupLists() as $list) {
            $groups = self::groupsOfList(list: $list);
            $groupSets[self::groupSetKey(groupIds: $groups)] = $groups;
        }

        // The visitor with no account holds the guest group's line, and a newly
        // registered customer the line of the group new accounts are placed in.
        // Joomla answers the root for a group it cannot find.
        $users = ComponentHelper::getParams('com_users');
        $starting = [(int) $users->get('guest_usergroup', 1), (int) $users->get('new_usertype', 2)];
        $lineages = PriceContext::groupLineages(groupIds: $starting);

        foreach ($starting as $groupId) {
            $groups = array_values(array_intersect($lineages[$groupId] ?? [1], $pricing));
            sort($groups);
            $groupSets[self::groupSetKey(groupIds: $groups)] = $groups;
        }

        ksort($groupSets, SORT_STRING);

        return $groupSets;
    }

    /**
     * The sets of pricing groups accounts hold: every account's, or one's.
     *
     * Read as Joomla reads the groups of an account (Access::getGroupsByUser()):
     * each group the account is placed in with every group whose range in the
     * tree's nested set encloses it; an account placed in no group holds the
     * root. Then reduced to the pricing groups.
     *
     * @param int|null $userId One account; null for every account.
     *
     * @return string[] Distinct sets, each as its list of ids (ascending, comma-separated).
     *
     * @since  2.0.0
     */
    private function accountGroupLists(?int $userId = null): array
    {
        $pricing = self::pricingGroups();

        if ($pricing === []) {
            return [''];
        }

        $db = $this->db;

        // An account may hold any number of pricing groups: no list is cut short.
        if (!self::$longLists) {
            $db->setQuery('SET SESSION group_concat_max_len = 1048576')->execute();
            self::$longLists = true;
        }
        // The pricing groups are picked inside the aggregate, not in the join's condition: MySQL 8.0 reads a join that
        // the condition narrows to one listed id as a constant table, and its GROUP_CONCAT then loses that id or adds an
        // empty one. Every other group of the line gives NULL, which GROUP_CONCAT leaves out.
        $pricingLine = 'CASE WHEN ' . $db->quoteName('line.id') . ' IN (' . implode(',', $pricing) . ')'
            . ' THEN ' . $db->quoteName('line.id') . ' END';
        $sql = 'SELECT DISTINCT COALESCE(' . $db->quoteName('held.group_set') . ', ' . $db->quote('') . ') FROM ('
            . 'SELECT GROUP_CONCAT(DISTINCT ' . $pricingLine . ' ORDER BY ' . $pricingLine
            . ' SEPARATOR ' . $db->quote(',') . ') AS ' . $db->quoteName('group_set')
            . ' FROM ' . $db->quoteName('#__users', 'u')
            . ' LEFT JOIN ' . $db->quoteName('#__user_usergroup_map', 'm')
            . ' ON ' . $db->quoteName('m.user_id') . ' = ' . $db->quoteName('u.id')
            . ' LEFT JOIN ' . $db->quoteName('#__usergroups', 'g')
            . ' ON ' . $db->quoteName('g.id') . ' = COALESCE(' . $db->quoteName('m.group_id') . ', 1)'
            . ' LEFT JOIN ' . $db->quoteName('#__usergroups', 'line')
            . ' ON ' . $db->quoteName('line.lft') . ' <= ' . $db->quoteName('g.lft')
            . ' AND ' . $db->quoteName('line.rgt') . ' >= ' . $db->quoteName('g.rgt')
            . ($userId !== null ? ' WHERE ' . $db->quoteName('u.id') . ' = ' . (int) $userId : '')
            . ' GROUP BY ' . $db->quoteName('u.id') . ') AS ' . $db->quoteName('held');

        return array_map('strval', $db->setQuery($sql)->loadColumn());
    }

    /**
     * The sets of pricing groups the index knows, by id: every set customers
     * held when it was last brought in line, prepared or waiting.
     *
     * @return array<int, object> Each with id, group_list, digest, prepared_at, prepared_through.
     *
     * @since  2.0.0
     */
    private function knownGroupSets(): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['id', 'group_list', 'digest', 'prepared_at', 'prepared_through', 'left_out']))
            ->from($this->db->quoteName(self::SETS_TABLE));

        return $this->db->setQuery($query)->loadObjectList('id');
    }

    /**
     * The sets of pricing groups the index holds rows of, by id.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    private function indexedGroupSetIds(): array
    {
        $query = $this->db->getQuery(true)
            ->select('DISTINCT ' . $this->db->quoteName('group_set_id'))
            ->from($this->db->quoteName('#__alfa_items_price_index'));

        return array_map('intval', $this->db->setQuery($query)->loadColumn());
    }

    /**
     * Bring the sets the index knows in line with the sets customers hold:
     * request each set nobody held before, drop each set nobody holds any more
     * with its rows. The groups that can change a price are read again first.
     *
     * Nothing is prepared here: a requested set has passed no product yet, so a
     * sync that follows writes no row of it.
     *
     * @return array{0: int, 1: array<int, array{groups: int[], through: int|null, leftOut: int[]}>} Sets requested; and
     *                                                                                               every set the index now knows, by id, with its groups, while it waits the last product its preparation
     *                                                                                               passed, and the products its preparation left out.
     *
     * @since  2.0.0
     */
    private function alignGroupSets(): array
    {
        self::$pricingGroups = null;
        $held = $this->groupSetsInUse();
        // The index first, then the sets: a set id the index holds and the table
        // no longer does was dropped, not created meanwhile.
        $indexed = $this->indexedGroupSetIds();
        $known = $this->knownGroupSets();
        $unheld = [];
        $knownKeys = [];

        foreach ($known as $setId => $set) {
            $knownKeys[(string) $set->digest] = true;

            if (!isset($held[(string) $set->digest])) {
                $unheld[] = (int) $setId;
            }
        }

        // The sets nobody holds go now, and so do rows that name no set at all
        // (a set dropped while a sync was writing it).
        $this->dropGroupSets(setIds: array_merge($unheld, array_diff($indexed, array_map('intval', array_keys($known)))));

        $requested = $this->requestGroupSets(groupSets: array_diff_key($held, $knownKeys));

        if ($requested > 0 || $unheld !== []) {
            $known = $this->knownGroupSets();
        }

        $groupSets = [];

        foreach ($known as $setId => $set) {
            if (isset($held[(string) $set->digest])) {
                $groupSets[(int) $setId] = [
                    'groups' => self::groupsOfList(list: (string) $set->group_list),
                    'through' => $set->prepared_at === null ? (int) $set->prepared_through : null,
                    'leftOut' => self::groupsOfList(list: (string) $set->left_out),
                ];
            }
        }

        return [$requested, $groupSets];
    }

    /**
     * Record sets of pricing groups as requested: known to the index, waiting
     * for their price.set.prepare job (2.2.0), which each gets here.
     *
     * @param array<string, int[]> $groupSets Key => groups; none, no statement.
     *
     * @return int The sets recorded (a set another request recorded meanwhile is not counted).
     *
     * @since  2.0.0
     */
    private function requestGroupSets(array $groupSets): int
    {
        if ($groupSets === []) {
            return 0;
        }

        // The database's clock at the moment of writing: Joomla's "now" is fixed for a whole process.
        $rows = [];

        foreach ($groupSets as $key => $groups) {
            $rows[] = '(' . $this->db->quote(self::groupSetList(groupIds: $groups)) . ', ' . $this->db->quote((string) $key) . ', UTC_TIMESTAMP())';
        }

        $this->db->setQuery(
            'INSERT IGNORE INTO ' . $this->db->quoteName(self::SETS_TABLE)
            . ' (' . implode(', ', $this->db->quoteName(['group_list', 'digest', 'requested_at'])) . ') VALUES ' . implode(', ', $rows),
        )->execute();
        $recorded = (int) $this->db->getAffectedRows();

        // Requesting a set enqueues its preparation, in the same request and transaction as the set's row (2.2.0).
        foreach (array_keys($groupSets) as $digest) {
            PriceSetPrepareJob::enqueueFor(digest: (string) $digest);
        }

        return $recorded;
    }

    /**
     * Remove some sets of pricing groups, and every row of theirs.
     *
     * The set's row goes first, in the same transaction as its rows: a preparer
     * writing a chunk of the set holds that row, so the two never cross.
     *
     * @param int[] $setIds The sets; none, no statement.
     *
     *
     * @since  2.0.0
     */
    private function dropGroupSets(array $setIds): void
    {
        $setIds = array_values(array_unique(array_map('intval', $setIds)));

        if ($setIds === []) {
            return;
        }

        $list = implode(',', $setIds);
        $this->db->transactionStart(true);

        try {
            $this->db->setQuery(
                'DELETE FROM ' . $this->db->quoteName(self::SETS_TABLE) . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . $list . ')',
            )->execute();
            $this->db->setQuery(
                'DELETE FROM ' . $this->db->quoteName('#__alfa_items_price_index') . ' WHERE ' . $this->db->quoteName('group_set_id') . ' IN (' . $list . ')',
            )->execute();
            $this->db->transactionCommit(true);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $this->db, failure: $e);

            throw $e;
        }
    }

    /**
     * Whether one set of pricing groups is still known and waiting.
     *
     * @param int $setId The set.
     *
     *
     * @since  2.0.0
     */
    private function groupSetWaits(int $setId): bool
    {
        $query = $this->db->getQuery(true)
            ->select('1')
            ->from($this->db->quoteName(self::SETS_TABLE))
            ->where($this->db->quoteName('id') . ' = ' . $setId)
            ->where($this->db->quoteName('prepared_at') . ' IS NULL');

        return (bool) $this->db->setQuery($query)->loadResult();
    }

    /**
     * Write one chunk of a set's preparation, and move its checkpoint past it,
     * in one transaction.
     *
     * The set's row is held for the transaction: a request dropping the set
     * waits for the chunk, and a set dropped before it is not written at all.
     * A product whose pricing throws takes its whole batch with it, so then the
     * chunk is priced again a product at a time, each product on its own: one
     * that throws again is left out of the set (no row: no figure for the set's
     * customers), logged with its error and recorded on the set, and the chunk
     * commits without it.
     *
     * @param int $setId The set being prepared.
     * @param int[] $chunk The products to write, ascending (none when every product of the chunk is passed over).
     * @param int $through The checkpoint the chunk moves the set to: its last product.
     * @param array $combinations From getActiveContextCombinations(), for this set alone.
     * @param string[] $globalEdges From getGlobalBoundaries().
     * @param array $leftOut The run's products left out; this chunk's are added.
     *
     * @return int|null Rows written; null when nothing was committed (the chunk failed otherwise, or the set is gone).
     *
     * @since  2.0.0
     */
    private function prepareChunk(int $setId, array $chunk, int $through, array $combinations, array $globalEdges, array &$leftOut): ?int
    {
        $sets = $this->db->quoteName(self::SETS_TABLE);
        $holdSet = fn (): bool => $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('id') . ' FROM ' . $sets . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $setId
            . ' AND ' . $this->db->quoteName('prepared_at') . ' IS NULL FOR UPDATE',
        )->loadResult() !== null;
        $nested = TransactionState::isOpen(db: $this->db);

        $this->db->transactionStart(true);

        try {
            $this->lastFailure = '';
            $this->dropChunkChanges();
            $written = $holdSet() ? ($chunk === [] ? 0 : $this->writeChunk(chunk: $chunk, combinations: $combinations, globalEdges: $globalEdges)) : null;
            $left = [];
            // What this chunk changes, announced once its level commits (2.2.0).
            $kept = $written === null ? ['changes' => [], 'sets' => []] : $this->chunkChanges;

            if ($written === null && $this->lastFailure !== '') {
                $this->lastFailure = '';
                $this->db->transactionRollback(true);
                $this->db->transactionStart(true);
                $written = $holdSet() ? 0 : null;

                foreach ($written === null ? [] : $chunk as $itemId) {
                    $this->db->transactionStart(true);

                    try {
                        $one = $this->writeChunk(chunk: [$itemId], combinations: $combinations, globalEdges: $globalEdges);
                    } catch (Throwable $e) {
                        // Back to the chunk's own level, which the catch below closes.
                        TransactionState::rollBackLevel(db: $this->db, failure: $e);

                        throw $e;
                    }

                    if ($one === null) {
                        $this->dropChunkChanges();
                        $this->db->transactionRollback(true);
                        $left[] = ['set' => $setId, 'product' => $itemId, 'message' => $this->lastFailure];
                        $this->logWarning(message: "Product {$itemId} could not be priced for set {$setId} and is left out of it: " . $this->lastFailure);
                        $this->lastFailure = '';

                        continue;
                    }

                    $this->db->transactionCommit(true);
                    $written += $one;
                    $kept = ['changes' => array_merge($kept['changes'], $this->chunkChanges['changes']), 'sets' => array_values(array_unique(array_merge($kept['sets'], $this->chunkChanges['sets'])))];
                }
            }

            if ($written === null) {
                $this->dropChunkChanges();
                $this->db->transactionRollback(true);

                return null;
            }

            $this->db->setQuery(
                'UPDATE ' . $sets . ' SET ' . $this->db->quoteName('prepared_through') . ' = ' . $through
                . ($left === [] ? '' : ', ' . $this->db->quoteName('left_out') . ' = CONCAT_WS(' . $this->db->quote(',') . ', NULLIF('
                    . $this->db->quoteName('left_out') . ', ' . $this->db->quote('') . '), ' . $this->db->quote(implode(',', array_column($left, 'product'))) . ')')
                . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $setId,
            )->execute();
            $this->db->transactionCommit(true);
            $this->chunkChanges = $kept;
            $this->keepChunkChanges();
            array_push($leftOut, ...$left);

            return $written;
        } catch (Throwable $e) {
            $this->dropChunkChanges();
            // A failure that ended the transaction this preparation was
            // called inside is that caller's to hear, never a chunk left for
            // later: the caller would commit work the server has discarded.
            if (TransactionState::rollBackLevel(db: $this->db, failure: $e) && $nested) {
                throw $e;
            }

            $this->chunkFailure = $e;
            $this->logWarning(message: "Preparing set {$setId} stopped at a chunk, which was rolled back: " . $e->getMessage());

            return null;
        }
    }

    /**
     * Mark a set of pricing groups prepared: from now on it is read.
     *
     * @param int $setId The set, all of whose chunks are written.
     *
     * @return int 1 when the set was marked; 0 when it was gone or already marked.
     *
     * @since  2.0.0
     */
    private function markPrepared(int $setId): int
    {
        $this->db->setQuery(
            'UPDATE ' . $this->db->quoteName(self::SETS_TABLE)
            . ' SET ' . $this->db->quoteName('prepared_at') . ' = UTC_TIMESTAMP()'
            . ', ' . $this->db->quoteName('requested_at') . ' = NULL, ' . $this->db->quoteName('prepared_through') . ' = 0'
            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $setId . ' AND ' . $this->db->quoteName('prepared_at') . ' IS NULL',
        )->execute();

        return (int) $this->db->getAffectedRows();
    }

    /**
     * The next chunk of published products after a checkpoint.
     *
     * @param int $after The last product already written.
     * @param int $limit At most this many.
     *
     * @return int[] Ascending; at most $limit.
     *
     * @since  2.0.0
     */
    private function publishedItemIdsAfter(int $after, int $limit = self::CHUNK_SIZE): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__alfa_items'))
            ->where($this->db->quoteName('state') . ' = 1')
            ->where($this->db->quoteName('id') . ' > ' . $after)
            ->order($this->db->quoteName('id') . ' ASC')
            ->setLimit(max(1, $limit));

        return array_map('intval', $this->db->setQuery($query)->loadColumn());
    }

    /**
     * Distinct place ids from all pricing assignment tables, plus sentinel 0.
     *
     * discount_places / tax_places use column 'place_id'.
     * items_prices uses 'country_id' (same semantic: a #__alfa_places id).
     *
     * @return int[]
     * @since  1.0.0
     */
    private function getDistinctPlaceIds(): array
    {
        $db = $this->db;
        $sql = "SELECT DISTINCT place_id FROM {$db->quoteName('#__alfa_discount_places')}
                UNION
                SELECT DISTINCT place_id FROM {$db->quoteName('#__alfa_tax_places')}
                UNION
                SELECT DISTINCT country_id AS place_id
                FROM {$db->quoteName('#__alfa_items_prices')}
                WHERE " . $db->quoteName('state') . ' = 1 AND country_id > 0';

        $db->setQuery($sql);
        return array_unique(array_merge([0], array_map('intval', $db->loadColumn())));
    }

    // Item discovery

    /**
     * The published items among some, read now (2.2.1): the index writer prices these only.
     *
     * @param int[] $itemIds The items.
     *
     * @return int[] In the order given.
     *
     * @since  2.2.1
     */
    private function publishedOf(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__alfa_items'))
            ->whereIn($this->db->quoteName('id'), array_map('intval', $itemIds))
            ->where($this->db->quoteName('state') . ' = 1');
        $published = array_map('intval', $this->db->setQuery($query)->loadColumn() ?: []);

        return array_values(array_intersect(array_map('intval', $itemIds), $published));
    }

    /**
     * All published item ids.
     *
     * @return int[]
     * @since  1.0.0
     */
    private function getAllPublishedItemIds(): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__alfa_items'))
            ->where($this->db->quoteName('state') . ' = 1');

        $this->db->setQuery($query);
        return array_map('intval', $this->db->loadColumn());
    }

    /**
     * Item ids belonging to any of the given category ids.
     *
     * @param int[] $categoryIds
     * @return int[]
     * @since  1.0.0
     */
    private function getItemIdsInCategories(array $categoryIds): array
    {
        if (empty($categoryIds)) {
            return [];
        }

        $query = $this->db->getQuery(true)
            ->select('DISTINCT ' . $this->db->quoteName('item_id'))
            ->from($this->db->quoteName('#__alfa_items_categories'))
            ->whereIn($this->db->quoteName('category_id'), $categoryIds);

        $this->db->setQuery($query);
        return array_map('intval', $this->db->loadColumn());
    }

    // Discount scope helpers

    /**
     * True if the discount has category_id = 0 (applies to all items).
     * @since  1.0.0
     */
    private function discountAppliesGlobally(int $discountId): bool
    {
        // Mirror the loader (PriceDataLoader): a discount applies to ALL items
        // when it carries the category_id = 0 sentinel OR has no category-scope
        // rows at all (a rule written without any: a category delete is refused
        // before it would leave one so). The loader's
        // "dc.discount_id IS NULL OR dc.category_id = 0" branch fires for every
        // item in both cases, so the index must too.
        $query = $this->db->getQuery(true)
            ->select([
                'SUM(CASE WHEN ' . $this->db->quoteName('category_id') . ' = 0 THEN 1 ELSE 0 END) AS sentinel_count',
                'COUNT(*) AS total_count',
            ])
            ->from($this->db->quoteName('#__alfa_discount_categories'))
            ->where($this->db->quoteName('discount_id') . ' = ' . (int) $discountId);

        $row = $this->db->setQuery($query)->loadObject();

        return $row !== null && ((int) $row->sentinel_count > 0 || (int) $row->total_count === 0);
    }

    /**
     * Category ids assigned to a discount (excluding sentinel 0).
     *
     * @return int[]
     * @since  1.0.0
     */
    private function getDiscountCategoryIds(int $discountId): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('category_id'))
            ->from($this->db->quoteName('#__alfa_discount_categories'))
            ->where($this->db->quoteName('discount_id') . ' = ' . (int) $discountId)
            ->where($this->db->quoteName('category_id') . ' > 0');

        return array_map('intval', $this->db->setQuery($query)->loadColumn());
    }

    // Tax scope helpers

    /**
     * True if the tax has category_id = 0 (applies to all items).
     * @since  1.0.0
     */
    private function taxAppliesGlobally(int $taxId): bool
    {
        // Mirror the loader (see discountAppliesGlobally): a tax applies to ALL
        // items when it has the category_id = 0 sentinel OR no category-scope
        // rows at all (a rule written without any: a category delete is refused
        // before it would leave one so).
        $query = $this->db->getQuery(true)
            ->select([
                'SUM(CASE WHEN ' . $this->db->quoteName('category_id') . ' = 0 THEN 1 ELSE 0 END) AS sentinel_count',
                'COUNT(*) AS total_count',
            ])
            ->from($this->db->quoteName('#__alfa_tax_categories'))
            ->where($this->db->quoteName('tax_id') . ' = ' . (int) $taxId);

        $row = $this->db->setQuery($query)->loadObject();

        return $row !== null && ((int) $row->sentinel_count > 0 || (int) $row->total_count === 0);
    }

    /**
     * Category ids assigned to a tax (excluding sentinel 0).
     *
     * @return int[]
     * @since  1.0.0
     */
    private function getTaxCategoryIds(int $taxId): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('category_id'))
            ->from($this->db->quoteName('#__alfa_tax_categories'))
            ->where($this->db->quoteName('tax_id') . ' = ' . (int) $taxId)
            ->where($this->db->quoteName('category_id') . ' > 0');

        return array_map('intval', $this->db->setQuery($query)->loadColumn());
    }

    // Logging

    /**
     * Log a non-fatal warning to the Joomla 'com_alfa' log channel.
     * @since  1.0.0
     */
    private function logWarning(string $message): void
    {
        Log::add('[PriceIndexSyncService] ' . $message, Log::WARNING, 'com_alfa');
    }
}
