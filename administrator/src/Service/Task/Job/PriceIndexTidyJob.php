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
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Joomla\Database\DatabaseDriver;

/**
 * Tidy the price index: the rows of a generation a newer one replaced, then that generation's context row, and
 * the periods that ended, a batch per chunk (PriceIndexSyncService::removeRetired() and removeEndedPeriods()). No
 * reader reads either: a retired generation is not live, an ended period does not hold the present.
 *
 * Light, low priority. Enqueued after a switch has been announced, and owed by any tick once a day when none finished
 * in the last day (owe()). Each chunk first switches live the complete generations that wait for nothing
 * (PriceIndexSyncService::switchReady(), a switch the preparation could not make). Deleting twice deletes nothing more:
 * a merge never asks a rerun. It keeps its own transactions: the switch is one, each removal a statement.
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
     * Hours after a finished tidy before a tick owes the next: the nightly pass.
     *
     * @since  2.2.2
     */
    public const EVERY_HOURS = 24;

    /**
     * Owe a tidy: the job, or the one already waiting or running.
     *
     * @return int The job.
     *
     * @since  2.2.2
     */
    public static function enqueue(): int
    {
        return Runner::enqueue(handler: self::NAME, scopeKey: self::NAME, payload: [])->id;
    }

    /**
     * What a tick owes of the tidy: the job when none is active and none finished in the last day. One read.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.2.2
     */
    public static function owe(DatabaseDriver $db): void
    {
        $table = $db->quoteName(Tasks::TABLE);
        $name = $db->quote(self::NAME);
        $due = (int) $db->setQuery(
            'SELECT NOT EXISTS (SELECT 1 FROM ' . $table . ' WHERE ' . $db->quoteName('active_key') . ' = ' . $name . ')'
            . ' AND COALESCE((SELECT MAX(' . $db->quoteName('finished_at') . ') FROM ' . $table . ' WHERE ' . $db->quoteName('handler') . ' = ' . $name
            . ' AND ' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_DONE) . ') < UTC_TIMESTAMP(3) - INTERVAL ' . self::EVERY_HOURS . ' HOUR, 1)',
        )->loadResult();

        if ($due === 1) {
            self::enqueue();
        }
    }

    public function policy(): JobPolicy
    {
        return new JobPolicy(label: 'COM_ALFA_TASKS_HANDLER_PRICE_INDEX_TIDY', weight: JobPolicy::LIGHT, priority: 5, initialUnits: 5000, rerunOnMerge: false, ownTransactions: true);
    }

    public function total(JobRun $run): int
    {
        return (new PriceIndexSyncService())->tidyCount();
    }

    public function chunk(JobRun $run, int $units): JobChunk
    {
        $service = new PriceIndexSyncService();
        $service->switchReady();
        $removed = $service->removeRetired(limit: $units);

        if ($removed < $units) {
            $removed += $service->removeEndedPeriods(limit: $units - $removed);
        }

        return new JobChunk(units: $removed, checkpoint: null, finished: $removed < $units);
    }
}
