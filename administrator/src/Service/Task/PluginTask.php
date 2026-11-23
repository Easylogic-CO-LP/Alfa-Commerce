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
use Alfa\Component\Alfa\Administrator\Event\Background\RunTaskEvent;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;

/**
 * An extension's task, as the engine runs it: the plugin that declared it booted for its work (enabled, whatever its
 * Access), its onAlfaRunTask called with the task as nobody, and what it says mapped onto the engine's failure kinds.
 * Its policy comes from the declaration: a repeatable task is idempotent; every failure the plugin names is retried on
 * the tasks' ladder; staff may run it again for the parts not done.
 *
 * @since  2.3.0
 */
final class PluginTask implements TaskHandler
{
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
     * As declared: its label, repeatable, urgent.
     *
     * @since  2.3.0
     */
    public function policy(): TaskPolicy
    {
        return new TaskPolicy(
            label: (string) $this->declaration['label'],
            version: 1,
            idempotent: (int) $this->declaration['repeatable'] === 1,
            retries: true,
            needsApplication: true,
            staffAction: TaskPolicy::ACTION_RERUN,
            attention: (int) $this->declaration['urgent'] === 1 ? 'danger' : 'warning',
            cancellable: true,
        );
    }

    /**
     * Run the task through its plugin.
     *
     * @param TaskRun $run The task.
     *
     * @throws OwnerUnavailable When the plugin is disabled or no longer installed.
     * @throws TaskFailure How the run failed, as the plugin said it or as the engine reads it.
     *
     * @since  2.3.0
     */
    public function run(TaskRun $run): void
    {
        $helper = AlfaPluginHelper::get();
        $loaded = PluginJob::boot(helper: $helper, owner: $this->owner);

        if (!$helper->declares($loaded, RunTaskEvent::NAME)) {
            throw TaskFailure::failed('The plugin ' . $this->owner . ' has no public ' . RunTaskEvent::NAME . '() method.');
        }

        try {
            SystemActor::around(static fn () => $helper->dispatchTo($loaded, new RunTaskEvent(run: $run)));
        } catch (WorkFailure $failure) {
            throw $failure->kind === WorkFailure::FAILED
                ? TaskFailure::failed($failure->getMessage(), $failure)
                : TaskFailure::retry($failure->getMessage(), $failure, $failure->after);
        } catch (PluginUnavailable $e) {
            throw TaskFailure::retry($e->getMessage(), $e);
        }

        // A part recorded unknown and then passed over: its effect is still unknown, so the task is held.
        if ($run->hasUnknownUnit()) {
            throw TaskFailure::unknown('A part of the task has an unknown outcome.');
        }
    }
}
