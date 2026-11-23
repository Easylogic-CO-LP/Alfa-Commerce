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
use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRetention;

/**
 * Delete the finished tasks the retention rules let go, a batch by id per chunk, through TaskRetention.
 *
 * Light, the lowest priority. A merge never asks a rerun: deleting twice deletes nothing more. The runner owes it every
 * hour (the span of its policy, as it owes every scheduled job of every owner), and the Tools button asks for it.
 *
 * @since  2.2.0
 */
final class TasksPruneJob implements JobHandler
{
    /**
     * The handler's name, and its one scope.
     *
     * @since  2.2.0
     */
    public const NAME = 'tasks.prune';

    /**
     * Owe a prune: the job, or the one already waiting or running.
     *
     * @return int The job.
     *
     * @since  2.2.0
     */
    public static function enqueue(): int
    {
        return BackgroundWork::core()->job(self::NAME)->enqueue()->id;
    }

    public function policy(): JobPolicy
    {
        return new JobPolicy(label: 'COM_ALFA_TASKS_HANDLER_TASKS_PRUNE', weight: JobPolicy::LIGHT, priority: 0, initialUnits: 500, rerunOnMerge: false, everyMinutes: 60);
    }

    public function total(JobRun $run): int
    {
        return TaskRetention::eligibleCount();
    }

    public function chunk(JobRun $run, int $units): JobChunk
    {
        $deleted = TaskRetention::pruneChunk(limit: $units);

        return new JobChunk(units: $deleted, checkpoint: null, finished: $deleted < $units);
    }
}
