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
 * How a task ended, or what staff did with it, as its handler hears it ({@see TaskSettleListener}).
 *
 * @since  2.1.4
 */
final class TaskSettlement
{
    /**
     * A run ended; the state says how (done, pending for a retry, failed or held).
     *
     * @since  2.1.4
     */
    public const RUN = 'run';

    /**
     * Recovery found a run that stopped before its outcome was recorded and held it.
     *
     * @since  2.1.4
     */
    public const INTERRUPTED = 'interrupted';

    /**
     * Staff resent it: a new task sends what was not recorded as sent.
     *
     * @since  2.1.4
     */
    public const RESENT = 'resent';

    /**
     * Staff ran it again: a new task runs what was not recorded as completed.
     *
     * @since  2.1.4
     */
    public const RUN_AGAIN = 'run_again';

    /**
     * Staff marked it resolved, with a reason.
     *
     * @since  2.1.4
     */
    public const RESOLVED = 'resolved';

    /**
     * @param int $taskId The task.
     * @param string $handler Its handler's name.
     * @param string $subjectType What it is about, e.g. order.
     * @param int $subjectId The id of what it is about.
     * @param array<string, mixed> $payload What its handler was given.
     * @param string $event What happened: one of the constants.
     * @param string $state The state the task is in now.
     * @param string $error The last error, '' for none.
     * @param int $userId Who acted (staff), 0 for nobody.
     * @param int $newTaskId The task a resend or a run again made, 0 for none.
     * @param string $reason Why staff resolved it.
     * @param array<string, list<string>> $units The units by their last outcome (done, failed, unknown).
     *
     * @since  2.1.4
     */
    public function __construct(
        public readonly int $taskId,
        public readonly string $handler,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly array $payload,
        public readonly string $event,
        public readonly string $state,
        public readonly string $error = '',
        public readonly int $userId = 0,
        public readonly int $newTaskId = 0,
        public readonly string $reason = '',
        public readonly array $units = [],
    ) {
    }
}
