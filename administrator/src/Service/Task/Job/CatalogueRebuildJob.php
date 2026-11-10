<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task\Job;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager;
use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use RuntimeException;

/**
 * Rebuild every projection of the items marked owed (2.2.0): the price index, the field values, the search entry and
 * every projection an extension registers, as an item's save rebuilds them. The job is the per-item repair of a
 * rebuild a request skipped or lost; the search index's full rebuild stays the search.rebuild job's, by generation.
 *
 * The dirty scope is #__alfa_items.projection_dirty_at: a pass walks the marked items by ascending id from the job's
 * checkpoint, rebuilds every projection of a chunk's items through ProjectionManager::syncItemsOrFail() and clears
 * each mark by compare-and-set on the value it read, so a mark written meanwhile survives. The job is done when a pass
 * finds no mark left after its checkpoint; a merge while it runs (a mark behind the checkpoint) asks one more pass from
 * the start. Heavy.
 *
 * It keeps its own transactions: the marks are read in autocommit, the chunk's item locks taken in ascending order, and
 * each item is rebuilt in a transaction begun after its grant and committed before its release, so it reads the item
 * as committed at the grant. Inside the runner's chunk transaction its first read would fix the snapshot before the
 * locks, and a change committed and rebuilt while the job waited would be overwritten with the older rows. The
 * runner's chunk transaction carries the heavy slot's renewal and the fenced progress only; a claim lost meanwhile
 * loses progress only, since a rebuild is idempotent and a mark is cleared only on its value.
 *
 * @since  2.2.0
 */
final class CatalogueRebuildJob implements JobHandler
{
    /**
     * The handler's name, and its one scope.
     *
     * @since  2.2.0
     */
    public const NAME = 'catalogue.rebuild';

    /**
     * Owe a pass over the marked items: the job, or the one already waiting or running.
     *
     * @return int The job.
     *
     * @since  2.2.0
     */
    public static function enqueue(): int
    {
        return Runner::enqueue(handler: self::NAME, scopeKey: self::NAME, payload: [])->id;
    }

    public function policy(): JobPolicy
    {
        return new JobPolicy(label: 'COM_ALFA_TASKS_HANDLER_CATALOGUE_REBUILD', weight: JobPolicy::HEAVY, priority: 30, initialUnits: 5, rerunOnMerge: true, ownTransactions: true);
    }

    public function total(JobRun $run): int
    {
        return ProjectionManager::markedCount();
    }

    public function chunk(JobRun $run, int $units): JobChunk
    {
        $marked = ProjectionManager::markedAfter(after: (int) ($run->checkpoint['after'] ?? 0), limit: $units);

        if ($marked === []) {
            return new JobChunk(units: 0, checkpoint: null, finished: true);
        }

        // Each item is rebuilt in every projection in its own transaction: one that fails (a deadlock among them) is
        // rolled back alone and keeps its mark. The lock asks wait no longer than the chunk's deadline (the tick's
        // budget, never past the lease): an item another writer keeps past it is busy, keeps its mark and is left to
        // the next tick, the checkpoint stopping before it. The marks of the items whose rebuild committed are
        // cleared, each by compare-and-set on the value read.
        $answer = (new ProjectionManager())->syncItemsOrFail(itemIds: array_keys($marked), until: $run->until);
        $busy = $answer['busy'];
        $notRebuilt = array_merge($answer['failed'], $busy);
        ProjectionManager::clearMarks(read: array_diff_key($marked, array_flip($notRebuilt)));

        // A failure counts only for an item still marked with the value this chunk read: one whose mark another
        // writer cleared or wrote again meanwhile was rebuilt by it, or is owed again on its own mark. A failure beside
        // a committed rebuild asks the job's own rerun, which walks the marks left. A chunk that rebuilt nothing fails:
        // the runner retries the job with its backoff and, after its failures, keeps it failed for staff, so a mark is
        // never left with neither a job nor a staff item, and an item that always fails never keeps the job turning.
        $failed = ProjectionManager::stillMarked(read: array_intersect_key($marked, array_flip($answer['failed'])));
        $rebuilt = \count($marked) - \count($notRebuilt);

        if ($failed !== [] && $rebuilt === 0 && $busy === []) {
            throw new RuntimeException('No item of the chunk could be rebuilt (items ' . implode(', ', $failed) . '); the job is retried.');
        }

        if ($failed !== []) {
            self::enqueue();
        }

        if ($busy !== []) {
            // The busy items are walked again from the first of them, by this pass's next chunk or the next tick.
            return new JobChunk(units: $rebuilt, checkpoint: ['after' => min($busy) - 1], finished: false);
        }

        return new JobChunk(units: \count($marked), checkpoint: ['after' => max(array_keys($marked))], finished: \count($marked) < $units);
    }
}
