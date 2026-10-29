<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use LogicException;

/**
 * notice: a notification write (a push or a clear) raised inside a transaction, written after its commit.
 *
 * Its payload is the write. The tasks service writes notices itself: a batch's first and together, each key's with the
 * older notices of the key still owed, in one transaction that deletes their singles first ({@see Tasks::applyJournal()}),
 * and a notice whose row was written after it was raised is skipped, so writing it twice changes nothing
 * ({@see NotificationHelper::applyJournal()}). A failure is retried with backoff and only logged: a notice is advisory.
 *
 * @since  2.1.4
 */
final class NoticeTask implements TaskHandler
{
    /**
     * The handler's name.
     *
     * @since  2.1.4
     */
    public const NAME = 'notice';

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    public function policy(): TaskPolicy
    {
        return new TaskPolicy(label: 'COM_ALFA_TASKS_HANDLER_NOTICE', idempotent: true, retries: true, attention: '');
    }

    /**
     * Never runs: the tasks service writes a notice with the other notices of its key, not through its handler.
     *
     * {@inheritdoc}
     *
     * @throws LogicException Always.
     *
     * @since  2.1.4
     */
    public function run(TaskRun $run): void
    {
        throw new LogicException('Notice #' . $run->id() . ' is written by the tasks service with the other notices of its key.');
    }
}
