<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task\Job;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskError;

/**
 * Prepare the list prices of one set of customer groups (2.2.0; it replaces the scheduled task of plg_task_alfapricesets).
 *
 * One job per set, its scope the set's digest. Each chunk writes the set's rows for the next published products from
 * the set's own checkpoint (prepared_through, mirrored in the job's), through PriceIndexSyncService::prepareSetChunk();
 * the last marks the set prepared. A set dropped while its job waits or runs ends the job done. Heavy: one preparer at
 * a time. A merge never asks a rerun: live item saves keep a waiting set current up to its checkpoint.
 *
 * It keeps its own transactions: each chunk takes its products' item locks before it reads the set, writes in its own
 * transaction and releases after the commit, so a save waiting for a product reads the checkpoint past it. The
 * runner's chunk transaction carries the heavy slot's renewal and the fenced progress only; a claim lost meanwhile
 * loses progress only, the set's checkpoint being its own.
 *
 * @since  2.2.0
 */
final class PriceSetPrepareJob implements JobHandler
{
    /**
     * The handler's name.
     *
     * @since  2.2.0
     */
    public const NAME = 'price.set.prepare';

    /**
     * The scope of a set's job.
     *
     * @param string $digest The set's digest.
     *
     * @since  2.2.0
     */
    public static function scopeFor(string $digest): string
    {
        return self::NAME . ':' . $digest;
    }

    /**
     * Owe a set's preparation: its job, or the one it already has.
     *
     * @param string $digest The set's digest.
     *
     * @since  2.2.0
     */
    public static function enqueueFor(string $digest): void
    {
        Runner::enqueue(handler: self::NAME, scopeKey: self::scopeFor(digest: $digest), payload: ['digest' => $digest]);
    }

    public function policy(): JobPolicy
    {
        return new JobPolicy(label: 'COM_ALFA_TASKS_HANDLER_PRICE_SET_PREPARE', weight: JobPolicy::HEAVY, priority: 50, initialUnits: 230, rerunOnMerge: false, ownTransactions: true);
    }

    public function total(JobRun $run): int
    {
        $service = new PriceIndexSyncService();
        // The sets customers hold, brought in line first, as the scheduled task did at each run.
        $service->alignSets();

        return $service->publishedItemCount();
    }

    public function chunk(JobRun $run, int $units): JobChunk
    {
        $service = new PriceIndexSyncService();
        $setId = $service->waitingSetId(digest: (string) ($run->payload['digest'] ?? ''));

        if ($setId === null) {
            return new JobChunk(units: 0, checkpoint: null, finished: true);
        }

        $result = $service->prepareSetChunk(setId: $setId, limit: $units);

        return match ($result['state']) {
            // What stopped the chunk is the failure's cause: the job's stored line then follows it (Tasks::failureLine()).
            'failed' => throw new TaskError('COM_ALFA_TASKS_ERROR_PRICE_SET_STEP', [$setId], $result['failure'] ?? null),
            'chunk' => new JobChunk(units: $result['products'], checkpoint: ['through' => $result['through']], finished: false),
            default => new JobChunk(units: $result['products'], checkpoint: null, finished: true),
        };
    }
}
