<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

/**
 * Runs the tasks of one kind ({@see Tasks}): the domain service a single belongs to.
 *
 * A handler is called with no transaction and no named lock open. It ends normally when its work is done, or throws
 * a {@see TaskFailure} that says what is known about the failure; any other Throwable is a known failure. A handler
 * whose work has parts (the recipients of an e-mail, the listeners of an event) records each part's outcome through
 * {@see TaskRun::unit()} as it comes, and attempts only the parts {@see TaskRun::shouldAttempt()} allows, so a part
 * recorded as done is never done again.
 *
 * @since  2.1.4
 */
interface TaskHandler
{
    /**
     * How its tasks are run, retried and shown to staff.
     *
     * @since  2.1.4
     */
    public function policy(): TaskPolicy;

    /**
     * Run one task.
     *
     * @param TaskRun $run The task, its payload and its history.
     *
     * @throws TaskFailure When the work did not complete, with what is known about it.
     *
     * @since  2.1.4
     */
    public function run(TaskRun $run): void;
}
