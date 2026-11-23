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
use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;

/**
 * Tidy the price index: the rows of a generation a newer one replaced, then that generation's context row, and
 * the periods that ended, a batch per chunk (PriceIndexSyncService::removeRetired() and removeEndedPeriods()). No
 * reader reads either: a retired generation is not live, an ended period does not hold the present.
 *
 * Light, low priority. Asked for when a generation goes live, and owed by the runner once a day (the span of its
 * policy, as it owes every scheduled job of every owner). Deleting twice deletes nothing more: a merge never asks a
 * rerun.
 *
 * @since  2.2.2
 */
final class PriceIndexTidyJob implements JobHandler
{
    /**
     * The handler's name, and its one scope.
     *
     * @since  2.2.2
     */
    public const NAME = 'price.index.tidy';

    /**
     * Owe a tidy: the job, or the one already waiting or running.
     *
     * @return int The job.
     *
     * @since  2.2.2
     */
    public static function enqueue(): int
    {
        return BackgroundWork::core()->job(self::NAME)->enqueue()->id;
    }

    public function policy(): JobPolicy
    {
        return new JobPolicy(label: 'COM_ALFA_TASKS_HANDLER_PRICE_INDEX_TIDY', weight: JobPolicy::LIGHT, priority: 5, initialUnits: 5000, rerunOnMerge: false, everyMinutes: 1440);
    }

    public function total(JobRun $run): int
    {
        return (new PriceIndexSyncService())->tidyCount();
    }

    public function chunk(JobRun $run, int $units): JobChunk
    {
        $service = new PriceIndexSyncService();
        $removed = $service->removeRetired(limit: $units);

        if ($removed < $units) {
            $removed += $service->removeEndedPeriods(limit: $units - $removed);
        }

        return new JobChunk(units: $removed, checkpoint: null, finished: $removed < $units);
    }
}
