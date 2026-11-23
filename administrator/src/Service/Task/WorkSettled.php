<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Background\WorkStatus;
use Alfa\Component\Alfa\Administrator\Event\Background\WorkSettledEvent;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Joomla\CMS\Factory;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * Telling a plugin how its task or job ended (onAlfaWorkSettled). Every path that ends an extension's work (done,
 * failed, held, resolved by staff or by a later run, cancelled) owes, in the same transaction, a core task work.settled
 * that hands the plugin the row as it ended: it is retried on the tasks' ladder and recovered after a crash like any
 * task, so the plugin is told once the end is final, even if the server stops in between. A plugin without the method
 * is not told; it reads its work with find().
 *
 * @since  2.3.0
 */
final class WorkSettled implements TaskHandler
{
    /**
     * The task's name.
     *
     * @since  2.3.0
     */
    public const NAME = 'work.settled';

    /**
     * Owe the plugin that owns a task or job the news of its end, in the transaction open on the connection (the one
     * that settled it). Nothing for core's own work, nor for a plugin that has no onAlfaWorkSettled.
     *
     * @param string $owner Whose work it is.
     * @param int $id The task or job that ended.
     * @param string $reason Staff's reason for Mark resolved or Cancel; '' otherwise.
     *
     * @since  2.3.0
     */
    public static function owe(string $owner, int $id, string $reason = ''): void
    {
        if ($owner === DueRead::CORE_OWNER || !WorkDeclarations::tellsSettled(owner: $owner)) {
            return;
        }

        Tasks::write(handler: self::NAME, subjectType: '', subjectId: 0, payload: ['owner' => $owner, 'task' => $id, 'reason' => $reason], trigger: 'request', userId: 0);
    }

    /**
     * Retried on the tasks' ladder, and run again when interrupted: the plugin's method must be safe to repeat.
     *
     * @since  2.3.0
     */
    public function policy(): TaskPolicy
    {
        return new TaskPolicy(
            label: 'COM_ALFA_TASKS_HANDLER_WORK_SETTLED',
            idempotent: true,
            retries: true,
            needsApplication: true,
            staffAction: TaskPolicy::ACTION_RERUN,
            attention: 'warning',
        );
    }

    /**
     * Hand the plugin the row as it ended. A row pruned meanwhile, or a plugin that no longer has the method, tells
     * nothing; a plugin that cannot be booted, or whose method throws, is tried again later.
     *
     * @param TaskRun $run The task.
     *
     * @throws TaskFailure When the plugin cannot be told now.
     *
     * @since  2.3.0
     */
    public function run(TaskRun $run): void
    {
        $payload = $run->payload();
        $owner = (string) ($payload['owner'] ?? '');
        $id = (int) ($payload['task'] ?? 0);
        $db = Factory::getContainer()->get('DatabaseDriver');
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'type', 'handler', 'state', 'done_units', 'total_units', 'history', 'result', 'last_error', 'finished_at']))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('id') . ' = :id')
                ->where($db->quoteName('owner') . ' = :owner')
                ->bind(':id', $id, ParameterType::INTEGER)
                ->bind(':owner', $owner),
        )->loadAssoc();

        if (!\is_array($row)) {
            return;
        }

        $helper = AlfaPluginHelper::get();

        try {
            $loaded = PluginJob::boot(helper: $helper, owner: PluginId::of($owner));
        } catch (OwnerUnavailable $e) {
            throw TaskFailure::retry($e->getMessage(), $e);
        }

        if (!$helper->declares($loaded, WorkSettledEvent::NAME)) {
            return;
        }

        $event = new WorkSettledEvent(status: WorkStatus::fromRow(row: $row), reason: (string) ($payload['reason'] ?? ''));

        try {
            SystemActor::around(static fn () => $helper->dispatchTo($loaded, $event));
        } catch (Throwable $e) {
            throw TaskFailure::retry('The plugin ' . $owner . ' failed when told how #' . $id . ' ended: ' . $e->getMessage(), $e);
        }
    }
}
