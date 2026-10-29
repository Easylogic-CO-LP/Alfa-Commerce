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
 * How the tasks of one handler are run, retried and shown to staff.
 *
 * @since  2.1.4
 */
final class TaskPolicy
{
    /**
     * Staff may resend the parts not recorded as sent (an e-mail).
     *
     * @since  2.1.4
     */
    public const ACTION_RESEND = 'resend';

    /**
     * Staff may run again the parts not recorded as completed (a plugin event).
     *
     * @since  2.1.4
     */
    public const ACTION_RERUN = 'rerun';

    /**
     * @param string $label Language key that names the task for staff.
     * @param int $version The handler's version, stored with every payload it is given.
     * @param bool $idempotent Running it twice duplicates nothing: a run that was interrupted is run again.
     *                         Otherwise an interrupted run is held for staff, never run again automatically.
     * @param bool $retries A known failure is retried with backoff; otherwise it fails at once.
     * @param bool $needsApplication It cannot run in a process with no application (a command-line script).
     * @param string $staffAction What staff may do with a failed or held task besides Mark resolved:
     *                            self::ACTION_RESEND, self::ACTION_RERUN or ''.
     * @param string $attention The attention notice's severity while one is failed or held: danger or warning;
     *                          '' when a failure is only logged.
     * @param string $sequence The subject's sequence the tasks join, '' for none: a task is not claimed while an
     *                         earlier task of the same sequence and subject is pending or running (an order's
     *                         e-mails go in the order they were written); a failed or held one does not hold it up.
     *
     * @since  2.1.4
     */
    public function __construct(
        public readonly string $label,
        public readonly int $version = 1,
        public readonly bool $idempotent = false,
        public readonly bool $retries = false,
        public readonly bool $needsApplication = false,
        public readonly string $staffAction = '',
        public readonly string $attention = 'warning',
        public readonly string $sequence = '',
    ) {
    }
}
