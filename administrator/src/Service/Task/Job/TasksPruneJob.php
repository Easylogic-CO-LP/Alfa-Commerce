<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task\Job;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRetention;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Joomla\Database\DatabaseDriver;

/**
 * Delete the finished tasks the retention rules let go (2.2.0), a batch by id per chunk, through TaskRetention.
 *
 * Light, the lowest priority. A merge never asks a rerun: deleting twice deletes nothing more. Owed by any tick when
 * none finished in the last hour; when none finished for a day, it goes ahead of the other maintenance (owe()).
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
     * Its claim order when no prune finished for a day: ahead of catalogue.rebuild (30) and search.rebuild (10),
     * behind the shop's own work (stock.effects.drain 100, price.set.prepare 50).
     *
     * @since  2.2.0
     */
    public const BACKSTOP_PRIORITY = 40;

    /**
     * Minutes after a finished prune before a tick owes the next.
     *
     * @since  2.2.0
     */
    public const EVERY_MINUTES = 60;

    /**
     * Hours with no finished prune after which the daily backstop puts it ahead.
     *
     * @since  2.2.0
     */
    public const BACKSTOP_HOURS = 24;

    /**
     * Owe a prune: the job, or the one already waiting or running.
     *
     * @return int The job.
     *
     * @since  2.2.0
     */
    public static function enqueue(): int
    {
        return Runner::enqueue(handler: self::NAME, scopeKey: self::NAME, payload: [])->id;
    }

    /**
     * What a tick owes of the prune: the job when none is active and none finished in the last hour; when none finished
     * for a day, or the active one has waited a day, the job at the backstop priority (the active one raised). One
     * read: the active job's priority and age, and the last finished prune.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.2.0
     */
    public static function owe(DatabaseDriver $db): void
    {
        $table = $db->quoteName(Tasks::TABLE);
        $name = $db->quote(self::NAME);
        $last = '(SELECT MAX(' . $db->quoteName('finished_at') . ') FROM ' . $table . ' WHERE ' . $db->quoteName('handler') . ' = ' . $name
            . ' AND ' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_DONE) . ')';
        $active = ' FROM ' . $table . ' WHERE ' . $db->quoteName('active_key') . ' = ' . $name;
        $row = $db->setQuery(
            'SELECT (SELECT ' . $db->quoteName('priority') . $active . ') AS ' . $db->quoteName('priority')
            . ', COALESCE(' . $last . ' < UTC_TIMESTAMP(3) - INTERVAL ' . self::EVERY_MINUTES . ' MINUTE, 1) AS ' . $db->quoteName('due')
            . ', (COALESCE(' . $last . ' < UTC_TIMESTAMP(3) - INTERVAL ' . self::BACKSTOP_HOURS . ' HOUR, 0)'
            . ' OR COALESCE((SELECT ' . $db->quoteName('created_at') . $active . ') < UTC_TIMESTAMP(3) - INTERVAL ' . self::BACKSTOP_HOURS . ' HOUR, 0)) AS ' . $db->quoteName('overdue'),
        )->loadAssoc() ?: [];
        $overdue = (int) ($row['overdue'] ?? 0) === 1;

        if (($row['priority'] ?? null) === null) {
            if ((int) ($row['due'] ?? 0) === 1) {
                Runner::enqueue(handler: self::NAME, scopeKey: self::NAME, payload: [], priority: $overdue ? self::BACKSTOP_PRIORITY : null);
            }

            return;
        }

        if ($overdue && (int) $row['priority'] < self::BACKSTOP_PRIORITY) {
            Runner::enqueue(handler: self::NAME, scopeKey: self::NAME, payload: [], priority: self::BACKSTOP_PRIORITY);
        }
    }

    public function policy(): JobPolicy
    {
        return new JobPolicy(label: 'COM_ALFA_TASKS_HANDLER_TASKS_PRUNE', weight: JobPolicy::LIGHT, priority: 0, initialUnits: 500, rerunOnMerge: false);
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
