<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task\Job;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryEffectOutbox;
use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;

/**
 * Apply the stock movements orders and documents still owe (2.2.0): the stock effect outbox drained.
 *
 * InventoryEffectOutbox::drain() keeps its own transactions and claims, so a second run of an obligation is impossible
 * and the runner's chunk carries only the progress. A chunk that settles nothing (applies nothing and refuses nothing)
 * ends the run, as a movement that failed a moment ago is claimable again at once; refused movements wait for their
 * order line to be resolved. A merge while it runs asks one more run, for an obligation owed after the scan began.
 *
 * @since  2.2.0
 */
final class StockEffectsDrainJob implements JobHandler
{
    /**
     * The handler's name, and its one scope.
     *
     * @since  2.2.0
     */
    public const NAME = 'stock.effects.drain';

    /**
     * Owe a drain: the job, or the one already waiting or running.
     *
     * @since  2.2.0
     */
    public static function enqueue(): void
    {
        Runner::enqueue(handler: self::NAME, scopeKey: self::NAME, payload: []);
    }

    public function policy(): JobPolicy
    {
        return new JobPolicy(label: 'COM_ALFA_TASKS_HANDLER_STOCK_EFFECTS_DRAIN', weight: JobPolicy::LIGHT, priority: 100, initialUnits: 5, rerunOnMerge: true, ownTransactions: true);
    }

    public function total(JobRun $run): int
    {
        return InventoryEffectOutbox::outstandingCount();
    }

    public function chunk(JobRun $run, int $units): JobChunk
    {
        // The effects dispatch events the `alfa` group listens to; a listener that is not imported misses the movement.
        AlfaPluginHelper::get()->importGroup('alfa');

        $stats = InventoryEffectOutbox::drain(limit: $units);
        $finished = ($stats['applied'] + $stats['refused']) === 0 || InventoryEffectOutbox::outstandingCount() === 0;

        if ($finished) {
            // The warnings whose movement is no longer owed retire with the run.
            InventoryEffectOutbox::doSyncNotice(oweDrain: false);
        }

        return new JobChunk(units: (int) $stats['claimed'], checkpoint: null, finished: $finished);
    }
}
