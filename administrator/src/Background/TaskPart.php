<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Background;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Task\TaskFailure;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRun;

/**
 * A named part of a running task (one recipient, one call to another system), recorded in the task's history as soon
 * as its outcome is known and never repeated once done: a retry and staff's Run again skip it.
 *
 *     if (!$task->part('submit')->isDone()) {
 *         ... the call ...
 *         $task->part('submit')->done();
 *     }
 *
 * @api WRITE: the record of one part of a task, written at once.
 *
 * @since  2.3.0
 */
final class TaskPart
{
    /**
     * @param TaskRun $run The running task.
     * @param string $name The part's name.
     *
     * @internal Made by RunTaskEvent::part() only.
     *
     * @since  2.3.0
     */
    public function __construct(private readonly TaskRun $run, private readonly string $name)
    {
    }

    /**
     * Whether the part was done in an earlier attempt, or by the task this one runs again: skip it.
     *
     * @since  2.3.0
     */
    public function isDone(): bool
    {
        return $this->run->isDone(unit: $this->name);
    }

    /**
     * The part took effect: recorded now, never attempted again.
     *
     * @since  2.3.0
     */
    public function done(): void
    {
        $this->run->unit(unit: $this->name, outcome: TaskRun::DONE);
    }

    /**
     * The part did not take effect: recorded now, attempted again on the next try.
     *
     * @param string $why What happened, for staff.
     *
     * @since  2.3.0
     */
    public function failed(string $why): void
    {
        $this->run->unit(unit: $this->name, outcome: TaskRun::FAILED, error: $why);
    }

    /**
     * Nobody knows whether the part took effect (a call with no answer): recorded now, and the run ends here; the task
     * is held for staff and never run again by itself.
     *
     * @param string $why What happened, for staff.
     *
     * @since  2.3.0
     */
    public function unknown(string $why): never
    {
        $this->run->unit(unit: $this->name, outcome: TaskRun::UNKNOWN, error: $why);

        throw TaskFailure::unknown($why);
    }
}
