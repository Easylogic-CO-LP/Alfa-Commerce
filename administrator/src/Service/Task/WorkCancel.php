<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * Cancel a task or job that has not finished, through its owner's BackgroundWork::cancel() or staff's Cancel in
 * Tools > Tasks.
 *
 * A waiting task or job is cancelled at once, its scope free. A running job is marked (cancel_requested) and stays
 * running, so its scope stays taken: the runner runs no further step, the step in progress keeps nothing (or, for a
 * job that calls other systems, moves no bookmark), and the runner then settles it cancelled. A running task is
 * refused: it is one go, and stopping it half way would leave its effect unknown. Core's own work is cancelled only
 * when its policy says it may be: the shop's correctness work never is.
 *
 * @since  2.3.0
 */
final class WorkCancel
{
    /**
     * Cancel one task or job.
     *
     * @param int $id The task or job.
     * @param string|null $owner The owner asking, whose rows alone it may cancel; null for staff, who may cancel any
     *                           extension's work and core's cancellable work.
     * @param string $trigger What asks: request or staff.
     * @param int $userId Who asks.
     * @param string $reason Why, for the history and the plugin; '' when none is given.
     *
     * @return bool True when it was cancelled, or its cancel is asked of its running step.
     *
     * @since  2.3.0
     */
    public static function cancel(int $id, ?string $owner, string $trigger, int $userId, string $reason): bool
    {
        if ($id <= 0) {
            return false;
        }

        $db = TransactionState::driver(Factory::getContainer()->get('DatabaseDriver'));
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'type', 'owner', 'handler', 'state', 'history', 'cancel_requested']))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER),
        )->loadAssoc();

        if (!\is_array($row) || ($owner !== null && (string) $row['owner'] !== $owner) || !self::cancellable(row: $row)) {
            return false;
        }

        $more = $reason === '' ? [] : ['reason' => $reason];

        if ($row['state'] === Tasks::STATE_PENDING) {
            return self::cancelWaiting(db: $db, row: $row, history: [...Tasks::decodeHistory($row['history']), Tasks::entry(trigger: $trigger, userId: $userId, outcome: Tasks::STATE_CANCELLED, more: $more)], reason: $reason);
        }

        if ($row['state'] === Tasks::STATE_RUNNING && $row['type'] === Tasks::TYPE_JOB) {
            if ((int) $row['cancel_requested'] === 1) {
                return true;
            }

            $history = Tasks::encodeHistory([...Tasks::decodeHistory($row['history']), Tasks::entry(trigger: $trigger, userId: $userId, outcome: 'cancel_requested', more: $more)]);
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName(Tasks::TABLE))
                    ->set($db->quoteName('cancel_requested') . ' = 1')
                    ->set($db->quoteName('history') . ' = :history')
                    ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
                    ->where($db->quoteName('id') . ' = :id')
                    ->where($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING))
                    ->bind(':history', $history)
                    ->bind(':id', $id, ParameterType::INTEGER),
            )->execute();

            if ($db->getAffectedRows() === 1) {
                Tasks::log(entry: 'Background work: job #' . $id . ' (' . $row['handler'] . ' of ' . $row['owner'] . ') is cancelled once its step has ended.', priority: Log::INFO);

                return true;
            }

            // It ended or went back to waiting meanwhile: asked again on its new state.
            return self::cancel(id: $id, owner: $owner, trigger: $trigger, userId: $userId, reason: $reason);
        }

        return false;
    }

    /**
     * Whether a row's work may be cancelled at all: an extension's always; core's when its policy says so.
     *
     * @param array<string, mixed> $row The row: type, owner, handler.
     *
     * @since  2.3.0
     */
    public static function cancellable(array $row): bool
    {
        if ((string) $row['owner'] !== DueRead::CORE_OWNER) {
            return true;
        }

        $policy = $row['type'] === Tasks::TYPE_JOB ? Runner::handler(name: (string) $row['handler'])?->policy() : Tasks::handler(name: (string) $row['handler'])?->policy();

        return $policy !== null && $policy->cancellable;
    }

    /**
     * Cancel a waiting row at once, with its history entry: one no run holds (a single reserved for the run of its
     * batch is that run's).
     *
     * @param DatabaseDriver $db The connection.
     * @param array<string, mixed> $row The row.
     * @param list<array<string, mixed>> $history Its history with the cancel.
     * @param string $reason Why.
     *
     * @since  2.3.0
     */
    private static function cancelWaiting(DatabaseDriver $db, array $row, array $history, string $reason): bool
    {
        $id = (int) $row['id'];
        $json = Tasks::encodeHistory($history);
        $state = Tasks::STATE_CANCELLED;
        $db->transactionStart(true);

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName(Tasks::TABLE))
                    ->set($db->quoteName('state') . ' = :state')
                    ->set($db->quoteName('history') . ' = :history')
                    ->set($db->quoteName('lease_owner') . ' = NULL')
                    ->set($db->quoteName('lease_until') . ' = NULL')
                    ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
                    ->set($db->quoteName('finished_at') . ' = UTC_TIMESTAMP(3)')
                    ->where($db->quoteName('id') . ' = :id')
                    ->where($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_PENDING))
                    ->where(DueRead::unreserved(db: $db))
                    ->bind(':state', $state)
                    ->bind(':history', $json)
                    ->bind(':id', $id, ParameterType::INTEGER),
            )->execute();
            $cancelled = $db->getAffectedRows() === 1;

            if ($cancelled) {
                WorkSettled::owe(owner: (string) $row['owner'], id: $id, reason: $reason);
            }

            TransactionState::commitLevel($db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        if ($cancelled) {
            Tasks::log(entry: 'Background work: #' . $id . ' (' . $row['handler'] . ' of ' . $row['owner'] . ') was cancelled while it waited.', priority: Log::INFO);
        }

        return $cancelled;
    }
}
