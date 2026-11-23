<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Background\WorkFailure;
use Alfa\Component\Alfa\Administrator\Event\Background\RunJobEvent;
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\LoadedPlugin;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;

/**
 * An extension's job, as the runner runs it: each step is the plugin's onAlfaRunJob, called on the plugin that
 * declared it (booted for its work: enabled, whatever its Access), and must end with next() or finished(). Its policy
 * comes from the declaration: light or heavy, one fixed priority below core's correctness and freshness work and above
 * the prune, the runner's step sizing from one unit, a request while it runs making it run once more from the start; a
 * job that calls other systems runs its steps outside the runner's transaction and waits the tasks' ladder between
 * failed tries.
 *
 * @since  2.3.0
 */
final class PluginJob implements JobHandler
{
    /**
     * The claim order of every extension job: behind the stock drain (100), the price sets (50), the catalogue (30)
     * and search (10) rebuilds, beside the price index tidy, ahead of the prune.
     *
     * @since  2.3.0
     */
    public const PRIORITY = 5;

    /**
     * @param PluginId $owner The plugin that declared it.
     * @param array<string, mixed> $declaration Its declaration row.
     *
     * @since  2.3.0
     */
    public function __construct(private readonly PluginId $owner, private readonly array $declaration)
    {
    }

    /**
     * Boot the owner of some work for it; a plugin disabled or gone marks its declarations disabled and is
     * OwnerUnavailable, any other refusal a failure tried again.
     *
     * @param AlfaPluginHelper $helper The plugin helper.
     * @param PluginId $owner The plugin.
     *
     * @throws OwnerUnavailable When the plugin is disabled or no longer installed.
     * @throws TaskFailure When it cannot be booted for another reason.
     *
     * @since  2.3.0
     */
    public static function boot(AlfaPluginHelper $helper, PluginId $owner): LoadedPlugin
    {
        try {
            return $helper->bootForWork($owner);
        } catch (PluginUnavailable $e) {
            if (\in_array($e->reason, [PluginUnavailable::DISABLED, PluginUnavailable::MISSING], true)) {
                throw new OwnerUnavailable($e->getMessage(), 0, $e);
            }

            throw TaskFailure::retry(ErrorText::of($e), $e);
        }
    }

    /**
     * As declared: its label, weight, span and whether it calls other systems.
     *
     * @since  2.3.0
     */
    public function policy(): JobPolicy
    {
        $callsOut = (int) $this->declaration['calls_out'] === 1;

        return new JobPolicy(
            label: (string) $this->declaration['label'],
            weight: (int) $this->declaration['heavy'] === 1 ? JobPolicy::HEAVY : JobPolicy::LIGHT,
            priority: self::PRIORITY,
            initialUnits: 1,
            rerunOnMerge: true,
            version: 1,
            ownTransactions: $callsOut,
            everyMinutes: (int) $this->declaration['every_minutes'],
            callsOut: $callsOut,
            cancellable: true,
        );
    }

    /**
     * The units in all as the plugin last reported them (total() on a step); 0 while not counted.
     *
     * @param JobRun $run The job.
     *
     * @since  2.3.0
     */
    public function total(JobRun $run): int
    {
        return $run->totalUnits;
    }

    /**
     * One step: the plugin's onAlfaRunJob with at most $units units.
     *
     * @param JobRun $run The job.
     * @param int $units At most this many units.
     *
     * @throws OwnerUnavailable When the plugin is disabled or no longer installed.
     * @throws TaskFailure How the step failed: as the plugin said it, or failed when it ended with neither next() nor
     *                     finished(), or with both.
     *
     * @since  2.3.0
     */
    public function chunk(JobRun $run, int $units): JobChunk
    {
        $helper = AlfaPluginHelper::get();
        $loaded = self::boot(helper: $helper, owner: $this->owner);

        if (!$helper->declares($loaded, RunJobEvent::NAME)) {
            throw TaskFailure::failed('The plugin ' . $this->owner . ' has no public ' . RunJobEvent::NAME . '() method.');
        }

        $event = new RunJobEvent(run: $run, units: $units);

        try {
            SystemActor::around(static fn () => $helper->dispatchTo($loaded, $event));
        } catch (WorkFailure $failure) {
            throw $failure->kind === WorkFailure::FAILED
                ? TaskFailure::failed(ErrorText::of($failure), $failure)
                : TaskFailure::retry(ErrorText::of($failure), $failure, $failure->after);
        }

        $outcome = $event->outcome();

        if ($outcome['ending'] === '' || $outcome['ending'] === 'both') {
            throw TaskFailure::failed($outcome['ending'] === ''
                ? 'The step of ' . $run->handler . ' ended with neither next() nor finished().'
                : 'The step of ' . $run->handler . ' ended with both next() and finished().');
        }

        $finished = $outcome['ending'] === 'finished';

        return new JobChunk(
            units: $outcome['walked'],
            checkpoint: $finished ? null : $outcome['checkpoint'],
            finished: $finished,
            total: $outcome['total'],
            result: $finished ? $outcome['result'] : null,
        );
    }
}
