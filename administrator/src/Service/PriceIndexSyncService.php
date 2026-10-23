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

use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceDataLoader;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use RuntimeException;
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
 * brought it (an account, a group or a pricing rule saved) and PREPARED later,
 * by the scheduled task (prepareGroupSets()): a chunk of products at a time,
 * each chunk one transaction together with the set's checkpoint, one preparer
 * at a time. The set is marked prepared after its last chunk; a set without
 * that mark is never read, whatever rows it has, so a preparation that failed
 * or was cut off is invisible until a later run finishes it. A set nobody
 * holds any more is dropped by the request that left it so.
 *
 * Public API
 *   syncItem($itemId)           - re-index one item after save/re-publish
 *   syncItems($itemIds)         - re-index a specific list of items
 *   syncByDiscount($discountId) - re-index items affected by a discount change
 *   syncByTax($taxId)           - re-index items affected by a tax change
 *   syncGroupSets()             - request the sets customers hold, drop the ones nobody holds
 *   syncAll()                   - rewrite every published product of the prepared sets
 *   deleteForItem($itemId)      - remove all index rows for one item
 *   prepareGroupSets()          - prepare the requested sets (the scheduled task)
 *   ensurePreparationTask()     - create that task unless one exists (the installer)
 *   preparationState()          - the sets waiting and the task's last run (Tools)
 *   accountGroupSet($userId)    - the set of pricing groups one account holds
 *   holdsGroupSet($groupSet)    - whether the index knows a set, prepared or waiting
 *   pricingGroups()             - the groups a price row, a discount or a tax names (the read side)
 *   slotKey($context)           - the set of one visitor's slots (the read side)
 *   slotSetJoin($context, ...)    - the join that reads only a prepared set (the read side)
 *   slotRanking($alias)         - how one visitor's matching slots are ranked (the read side)
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
 *
 *   A user group saved under another parent, or removed; an account saved
 *   into another set of groups, or removed; the users component's options
 *   saved (the sync plugin)       -> syncGroupSets()
 *
 * All sync failures are non-fatal: logged as warnings, admin save never blocked.
 *
 * @since  1.0.0
 */
class PriceIndexSyncService
{
    // Constants

    /** Chunk size for bulk item syncs: balances memory vs DB round-trips. */
    private const CHUNK_SIZE = 50;

    /** Sentinel valid_from for the segment that is live right now. */
    private const EPOCH = '1000-01-01 00:00:00';

    /** Upper bound on time segments per item (runaway-window safety). */
    private const MAX_SEGMENTS = 8;

    /** The sets of pricing groups the index is keyed by. */
    private const SETS_TABLE = '#__alfa_price_group_sets';

    /**
     * The scheduler routine that prepares requested sets (plg_task_alfapricesets).
     *
     * @since  2.0.0
     */
    public const PREPARE_TASK = 'alfapricesets.prepare';

    /** How often the task the installer creates runs, in minutes. */
    private const PREPARE_EVERY_MINUTES = 5;

    /** How long one run of that task works, in seconds. */
    private const PREPARE_SECONDS = 20;

    /** Whether this process's connection may concatenate a list of any length (the accounts' sets). */
    private static bool $longLists = false;

    /** What the last calculation that failed in writeChunk() raised. */
    private string $lastFailure = '';

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
     * @since  1.0.0
     */
    public function syncItem(int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }

        try {
            [, $groupSets] = $this->alignGroupSets();
            $this->writeIndexRows([$itemId], $this->getActiveContextCombinations(groupSets: $groupSets));
        } catch (Throwable $e) {
            $this->logWarning("syncItem({$itemId}) failed: " . $e->getMessage());
        }
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
     * @return int Number of index rows written
     * @since  1.0.0
     */
    public function syncItems(array $itemIds): int
    {
        $itemIds = array_filter(array_map('intval', $itemIds));

        try {
            // Even with no item left in scope: the rule may have named a group
            // for the first time, or been the last to name one.
            [, $groupSets] = $this->alignGroupSets();

            if (empty($itemIds)) {
                return 0;
            }

            return $this->writeIndexRows(
                array_values($itemIds),
                $this->getActiveContextCombinations(groupSets: $groupSets),
            );
        } catch (Throwable $e) {
            $this->logWarning('syncItems() failed: ' . $e->getMessage());
            return 0;
        }
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
     * A requested set is only recorded: the scheduled task prepares it
     * (prepareGroupSets()), so the request that brought it writes no row of it
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
     * its preparation has passed, and the rest of it is the scheduled task's (so
     * after an installation, which prepares nothing, the task builds the index).
     * The sets nobody holds are dropped.
     *
     * Known limitation: a catalogue-wide rule save (a discount or tax for every
     * category) rewrites every prepared set inside its own request, so with many
     * combinations of pricing groups it may be slow or exceed the request's time
     * limit. Planned after the stable release: a versioned, atomic pricing
     * publish (POST-V1-FOLLOWUPS PV1-05).
     *
     * @return int Total number of index rows written
     * @since  1.0.0
     */
    public function syncAll(): int
    {
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
    }

    /**
     * Remove all index rows for a specific item.
     *
     * Call when an item is unpublished, trashed, or soft-deleted so it
     * disappears from the filter immediately without waiting for a full rebuild.
     * Hard deletes are also handled automatically by the FK ON DELETE CASCADE.
     * @since  1.0.0
     */
    public function deleteForItem(int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }

        try {
            $query = $this->db->getQuery(true)
                ->delete($this->db->quoteName('#__alfa_items_price_index'))
                ->where($this->db->quoteName('item_id') . ' = ' . (int) $itemId);

            $this->db->setQuery($query)->execute();
        } catch (Throwable $e) {
            $this->logWarning("deleteForItem({$itemId}) failed: " . $e->getMessage());
        }
    }

    /**
     * Prepare the sets of pricing groups that wait for it: the work of the
     * scheduled task.
     *
     * One preparer at a time: a named lock of the database server, asked for
     * with a wait; a run that does not get it writes nothing and says so. The
     * sets customers hold are brought in line first, so memberships written
     * where no event reaches the shop are followed too. Then each waiting set,
     * oldest request first, is written a chunk of published products at a
     * time from its checkpoint on; a chunk and the checkpoint commit together.
     * After the last chunk the set is marked prepared, and from then on it is
     * read. A chunk that fails is rolled back, the set waits for the next run,
     * and this run goes on with the next set. The run stops at the first chunk
     * that ends after the time it was given.
     *
     * @param float $seconds How long the run may work; it always writes one chunk when a set waits.
     * @param int $wait How long to wait, in seconds, for a preparer already at work.
     *
     * A product whose pricing throws does not stop a set: its chunk is priced
     * again a product at a time, and that product is left out of the set (no
     * row, so no figure for its customers), logged and recorded on the set. It
     * is not tried again by the task; saving the product prices it again.
     *
     * @return array{prepared: int, written: int, waiting: int, busy: bool, failed: int[], leftOut: list<array{set: int, product: int, message: string}>}
     *                                                                                                                                                    Sets marked prepared, index rows written, sets still waiting, whether another preparer held the lock, the
     *                                                                                                                                                    sets whose chunk failed otherwise, and the products left out of a set in this run.
     *
     * @since  2.0.0
     */
    public function prepareGroupSets(float $seconds = 20.0, int $wait = 10): array
    {
        $run = ['prepared' => 0, 'written' => 0, 'waiting' => 0, 'busy' => false, 'failed' => [], 'leftOut' => []];
        $deadline = microtime(true) + max(0.0, $seconds);
        $lock = $this->preparerLock();

        if ((int) $this->db->setQuery('SELECT GET_LOCK(' . $lock . ', ' . max(0, $wait) . ')')->loadResult() !== 1) {
            $run['busy'] = true;

            return $run;
        }

        try {
            $this->alignGroupSets();

            foreach ($this->waitingGroupSets() as $set) {
                $setId = (int) $set->id;
                $through = (int) $set->prepared_through;
                $combinations = $this->getActiveContextCombinations(groupSets: [
                    $setId => ['groups' => self::groupsOfList(list: (string) $set->group_list), 'through' => null, 'leftOut' => []],
                ]);
                $globalEdges = $this->getGlobalBoundaries();

                while (true) {
                    $chunk = $this->publishedItemIdsAfter(after: $through);

                    if ($chunk === []) {
                        $run['prepared'] += $this->markPrepared(setId: $setId);

                        break;
                    }

                    $written = $this->prepareChunk(setId: $setId, chunk: $chunk, combinations: $combinations, globalEdges: $globalEdges, leftOut: $run['leftOut']);

                    if ($written === null) {
                        // Not committed: the chunk failed, or the set was dropped meanwhile.
                        if ($this->groupSetWaits(setId: $setId)) {
                            $run['failed'][] = $setId;
                        }

                        break;
                    }

                    $run['written'] += $written;
                    $through = max($chunk);

                    if (\count($chunk) < self::CHUNK_SIZE) {
                        $run['prepared'] += $this->markPrepared(setId: $setId);

                        break;
                    }

                    if (microtime(true) >= $deadline) {
                        break 2;
                    }
                }

                if (microtime(true) >= $deadline) {
                    break;
                }
            }

            $run['waiting'] = \count($this->waitingGroupSets());
        } finally {
            $this->db->setQuery('SELECT RELEASE_LOCK(' . $lock . ')')->execute();
        }

        return $run;
    }

    /**
     * The scheduled task that prepares requested sets, as the component's
     * installer creates it: enabled, every five minutes from now, under the
     * component's own title for it.
     *
     * @return array The data com_scheduler's Task model saves.
     *
     * @since  2.0.0
     */
    public static function preparationTaskData(): array
    {
        // Reloaded: an update's request may have read the previous version's file before this one was copied.
        Factory::getApplication()->getLanguage()->load(extension: 'com_alfa', basePath: JPATH_ADMINISTRATOR, reload: true);

        return [
            'id' => 0,
            'title' => Text::_('COM_ALFA_PRICE_SETS_TASK_TITLE'),
            'type' => self::PREPARE_TASK,
            'state' => 1,
            'execution_rules' => [
                'rule-type' => 'interval-minutes',
                'interval-minutes' => self::PREPARE_EVERY_MINUTES,
                'exec-day' => gmdate('d'),
                'exec-time' => gmdate('H:i'),
            ],
            'params' => ['seconds' => self::PREPARE_SECONDS],
        ];
    }

    /**
     * Make sure a scheduled task prepares requested sets: create it, enabled,
     * unless a task of its routine exists in any state (a task the shop
     * disabled stays as the shop left it). Joomla's lazy scheduler then runs it
     * with no cron entry; a cron entry of cli/joomla.php scheduler:run is
     * optional. Called by the component's installer on install and on update.
     *
     * Created through com_scheduler's Task model, so the schedule's arithmetic
     * and the task's permissions are the scheduler's own.
     *
     * @return int|null The id of the task created; null when one existed.
     *
     * @throws RuntimeException When the task could not be saved.
     *
     * @since  2.0.0
     */
    public static function ensurePreparationTask(): ?int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $existing = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__scheduler_tasks'))
                ->where($db->quoteName('type') . ' = ' . $db->quote(self::PREPARE_TASK)),
            0,
            1,
        )->loadResult();

        if ($existing !== null) {
            return null;
        }

        $model = Factory::getApplication()->bootComponent('com_scheduler')
            ->getMVCFactory()->createModel('Task', 'Administrator', ['ignore_request' => true]);

        if (!$model->save(self::preparationTaskData())) {
            throw new RuntimeException(implode(' ', array_map(
                static fn ($error): string => $error instanceof Throwable ? $error->getMessage() : (string) $error,
                $model->getErrors(),
            )) ?: 'the scheduled task was not saved');
        }

        return (int) $model->getState('task.id');
    }

    /**
     * What the staff are shown of the preparation: the sets that wait, and the
     * scheduled task that prepares them.
     *
     * @return array{waiting: list<array{groups: int[], requested_at: string, done: int, products: int}>, leftOut: int[], lastRun: ?array{at: string, exit: int}, scheduledTasks: int}
     *                                                                                                                                                                                 Each waiting set with its groups, when it was requested and how many of the published products it holds;
     *                                                                                                                                                                                 the products that could not be priced for a set when it was prepared; the last run of the task, if any;
     *                                                                                                                                                                                 how many enabled tasks of it run on a schedule (not only by hand).
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
            'SELECT ' . implode(', ', $db->quoteName(['group_list', 'requested_at', 'prepared_through'])) . ' FROM ' . $db->quoteName(self::SETS_TABLE)
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
            ];
        }

        $tasks = $db->setQuery(
            'SELECT ' . implode(', ', $db->quoteName(['state', 'execution_rules', 'last_execution', 'last_exit_code'])) . ' FROM ' . $db->quoteName('#__scheduler_tasks')
            . ' WHERE ' . $db->quoteName('type') . ' = ' . $db->quote(self::PREPARE_TASK)
            . ' ORDER BY ' . $db->quoteName('last_execution') . ' DESC',
        )->loadObjectList();
        $lastRun = null;

        foreach ($tasks as $task) {
            if ($task->last_execution !== null) {
                $lastRun = ['at' => (string) $task->last_execution, 'exit' => (int) $task->last_exit_code];

                break;
            }
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

        return [
            'waiting' => $waiting,
            'leftOut' => $leftOut,
            'lastRun' => $lastRun,
            'scheduledTasks' => \count(array_filter(
                $tasks,
                static fn (object $task): bool => (int) $task->state === 1
                    && (json_decode((string) $task->execution_rules, true)['rule-type'] ?? 'manual') !== 'manual',
            )),
        ];
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
     * The order in which one visitor's matching slots are preferred, as an
     * ORDER BY list over the index.
     *
     * A visitor matches the slots of their own set of pricing groups in their
     * own currency and in any, at their own place and at any. The most specific
     * currency and place decide; the lower price decides between a slot of
     * their currency at any place and one of any currency at their place.
     *
     * Every reader of the index ranks by this expression and by no other, so
     * the list, its price filter and its facets cannot disagree about a price.
     *
     * @param string $alias Alias of the index table in the reader's statement.
     *
     * @return string An ORDER BY list, without the keyword.
     *
     * @since  2.0.0
     */
    public static function slotRanking(string $alias): string
    {
        return "(({$alias}.currency_id > 0) + ({$alias}.place_id > 0)) DESC, {$alias}.final_price ASC";
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
     *
     * @param int[] $itemIds
     * @param array $combinations From getActiveContextCombinations()
     * @return int Total rows written
     * @since  1.0.0
     */
    private function writeIndexRows(array $itemIds, array $combinations): int
    {
        if (empty($itemIds) || empty($combinations)) {
            return 0;
        }

        $written = 0;
        $globalEdges = $this->getGlobalBoundaries();

        foreach (array_chunk($itemIds, self::CHUNK_SIZE) as $chunk) {
            // Segments are rebuilt wholesale (their keys and count change
            // with the windows; an update could never know which old rows
            // became obsolete). The transaction makes the swap atomic, so a
            // concurrent catalog query never sees the gap.
            $this->db->transactionStart(true);

            try {
                $chunkWritten = $this->writeChunk(chunk: $chunk, combinations: $combinations, globalEdges: $globalEdges);

                if ($chunkWritten === null) {
                    // A calculation was skipped after the delete; restore this
                    // chunk's prior (complete) rows and move on. Other chunks are
                    // unaffected; the failure was already logged.
                    $this->db->transactionRollback(true);

                    continue;
                }

                $this->db->transactionCommit(true);
                $written += $chunkWritten;
            } catch (Throwable $e) {
                TransactionState::rollBackLevel(db: $this->db, failure: $e);
                throw $e;
            }
        }

        return $written;
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
        // this process loaded may since have changed.
        PriceDataLoader::forgetLoaded();

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

        foreach ($setsByItems as $items => $setIds) {
            $this->deleteIndexRows(itemIds: array_map('intval', explode(',', (string) $items)), groupSetIds: $setIds);
        }

        // Group items by segment start so each (start x context) slot
        // is ONE batched calculator call. valid_until = the item's
        // next edge.
        $startGroups = [];

        foreach ($chunk as $itemId) {
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

        return $written;
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
        $sql = 'SELECT DISTINCT COALESCE(' . $db->quoteName('held.group_set') . ', ' . $db->quote('') . ') FROM ('
            . 'SELECT GROUP_CONCAT(DISTINCT ' . $db->quoteName('line.id') . ' ORDER BY ' . $db->quoteName('line.id')
            . ' SEPARATOR ' . $db->quote(',') . ') AS ' . $db->quoteName('group_set')
            . ' FROM ' . $db->quoteName('#__users', 'u')
            . ' LEFT JOIN ' . $db->quoteName('#__user_usergroup_map', 'm')
            . ' ON ' . $db->quoteName('m.user_id') . ' = ' . $db->quoteName('u.id')
            . ' LEFT JOIN ' . $db->quoteName('#__usergroups', 'g')
            . ' ON ' . $db->quoteName('g.id') . ' = COALESCE(' . $db->quoteName('m.group_id') . ', 1)'
            . ' LEFT JOIN ' . $db->quoteName('#__usergroups', 'line')
            . ' ON ' . $db->quoteName('line.lft') . ' <= ' . $db->quoteName('g.lft')
            . ' AND ' . $db->quoteName('line.rgt') . ' >= ' . $db->quoteName('g.rgt')
            . ' AND ' . $db->quoteName('line.id') . ' IN (' . implode(',', $pricing) . ')'
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
     * for the scheduled task to prepare them.
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

        return (int) $this->db->getAffectedRows();
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
     * The sets of pricing groups waiting for their preparation, oldest request first.
     *
     * @return object[] Each with id, group_list, prepared_through.
     *
     * @since  2.0.0
     */
    private function waitingGroupSets(): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['id', 'group_list', 'prepared_through']))
            ->from($this->db->quoteName(self::SETS_TABLE))
            ->where($this->db->quoteName('prepared_at') . ' IS NULL')
            ->order($this->db->quoteName('requested_at') . ' ASC, ' . $this->db->quoteName('id') . ' ASC');

        return $this->db->setQuery($query)->loadObjectList();
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
     * @param int[] $chunk The products, ascending.
     * @param array $combinations From getActiveContextCombinations(), for this set alone.
     * @param string[] $globalEdges From getGlobalBoundaries().
     * @param array $leftOut The run's products left out; this chunk's are added.
     *
     * @return int|null Rows written; null when nothing was committed (the chunk failed otherwise, or the set is gone).
     *
     * @since  2.0.0
     */
    private function prepareChunk(int $setId, array $chunk, array $combinations, array $globalEdges, array &$leftOut): ?int
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
            $written = $holdSet() ? $this->writeChunk(chunk: $chunk, combinations: $combinations, globalEdges: $globalEdges) : null;
            $left = [];

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
                        $this->db->transactionRollback(true);
                        $left[] = ['set' => $setId, 'product' => $itemId, 'message' => $this->lastFailure];
                        $this->logWarning(message: "Product {$itemId} could not be priced for set {$setId} and is left out of it: " . $this->lastFailure);
                        $this->lastFailure = '';

                        continue;
                    }

                    $this->db->transactionCommit(true);
                    $written += $one;
                }
            }

            if ($written === null) {
                $this->db->transactionRollback(true);

                return null;
            }

            $this->db->setQuery(
                'UPDATE ' . $sets . ' SET ' . $this->db->quoteName('prepared_through') . ' = ' . (int) max($chunk)
                . ($left === [] ? '' : ', ' . $this->db->quoteName('left_out') . ' = CONCAT_WS(' . $this->db->quote(',') . ', NULLIF('
                    . $this->db->quoteName('left_out') . ', ' . $this->db->quote('') . '), ' . $this->db->quote(implode(',', array_column($left, 'product'))) . ')')
                . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $setId,
            )->execute();
            $this->db->transactionCommit(true);
            array_push($leftOut, ...$left);

            return $written;
        } catch (Throwable $e) {
            // A failure that ended the transaction this preparation was
            // called inside is that caller's to hear, never a chunk left for
            // later: the caller would commit work the server has discarded.
            if (TransactionState::rollBackLevel(db: $this->db, failure: $e) && $nested) {
                throw $e;
            }

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
     *
     * @return int[] Ascending; at most one chunk.
     *
     * @since  2.0.0
     */
    private function publishedItemIdsAfter(int $after): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__alfa_items'))
            ->where($this->db->quoteName('state') . ' = 1')
            ->where($this->db->quoteName('id') . ' > ' . $after)
            ->order($this->db->quoteName('id') . ' ASC')
            ->setLimit(self::CHUNK_SIZE);

        return array_map('intval', $this->db->setQuery($query)->loadColumn());
    }

    /**
     * The name of the lock one preparer holds, as an SQL expression. The
     * database server's locks are shared by every database it serves, so the
     * name carries this shop's database and table prefix.
     *
     *
     * @since  2.0.0
     */
    private function preparerLock(): string
    {
        return "CONCAT('alfa.price_group_sets.', LEFT(SHA1(CONCAT(DATABASE(), " . $this->db->quote($this->db->getPrefix()) . ')), 20))';
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
