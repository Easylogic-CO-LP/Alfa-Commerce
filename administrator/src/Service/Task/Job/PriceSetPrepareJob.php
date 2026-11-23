<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task\Job;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Background\BackgroundWork;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Alfa\Component\Alfa\Administrator\Service\Task\DueRead;
use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskError;

/**
 * Prepare the list prices of one pricing context of the price index (a currency class, a place class, a set of customer
 * groups and, for a customer named by their own prices, that customer).
 *
 * One job per context, its scope the context's id. Each chunk writes the context's rows for the next published
 * products from the context's own checkpoint (prepared_through), through PriceIndexSyncService::prepareContextChunk();
 * the last marks the context complete and asks its switch ({@see PriceIndexSyncService::switchReady()}): a context new
 * to the data is read from then on, a next generation once every next generation of its change is complete. A context dropped while its job waits
 * or runs ends the job done. Heavy: one preparer at a time. A merge never asks a rerun: live saves keep a waiting
 * context current up to its checkpoint.
 *
 * It keeps its own transactions: each chunk takes its products' item locks before it reads the context, writes in its
 * own transaction and releases after the commit.
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
    public const NAME = DueRead::PRICE_SET_PREPARE;

    /**
     * The scope of a context's job.
     *
     * @param int $contextId The context (#__alfa_price_contexts.id).
     *
     * @since  2.2.2
     */
    public static function scopeFor(int $contextId): string
    {
        return self::NAME . ':' . $contextId;
    }

    /**
     * Owe a context's preparation: its job, or the one it already has.
     *
     * @param int $contextId The context.
     *
     * @since  2.2.2
     */
    public static function enqueueFor(int $contextId): void
    {
        BackgroundWork::core()->job(self::NAME)->scope((string) $contextId)->with(['context' => $contextId])->enqueue();
    }

    public function policy(): JobPolicy
    {
        return new JobPolicy(label: 'COM_ALFA_TASKS_HANDLER_PRICE_SET_PREPARE', weight: JobPolicy::HEAVY, priority: 50, initialUnits: 230, rerunOnMerge: false, ownTransactions: true);
    }

    public function total(JobRun $run): int
    {
        $service = new PriceIndexSyncService();
        // The contexts the data defines, brought in line first.
        $service->alignContexts();

        return $service->publishedItemCount();
    }

    public function chunk(JobRun $run, int $units): JobChunk
    {
        $service = new PriceIndexSyncService();
        $contextId = (int) ($run->payload['context'] ?? 0);

        // Complete already (a run after a switch that failed): the switch is asked again.
        if (!$service->contextWaits(contextId: $contextId)) {
            $service->switchReady();

            return new JobChunk(units: 0, checkpoint: null, finished: true);
        }

        $result = $service->prepareContextChunk(contextId: $contextId, limit: $units);

        return match ($result['state']) {
            'failed' => throw new TaskError('COM_ALFA_TASKS_ERROR_PRICE_SET_STEP', [$contextId], $result['failure'] ?? null),
            'chunk' => new JobChunk(units: $result['products'], checkpoint: ['through' => $result['through']], finished: false),
            default => new JobChunk(units: $result['products'], checkpoint: null, finished: true),
        };
    }
}
