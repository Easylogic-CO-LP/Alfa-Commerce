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
use DomainException;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * What staff do with a failed or held single, each act audited in its history.
 *
 * Nothing failed or held is ever run again automatically: staff decide. Resend (an e-mail) and Run again (a plugin's
 * reaction) write a new single that names the original (resend_of, rerun_of) and carries every part recorded done in
 * the chain; it runs at once, its handler attempts only the parts not recorded as done, and the original becomes
 * resolved in the same transaction. Mark resolved
 * closes a task with no action, with the reason given. A refusal is a DomainException whose code is one of the REFUSED_
 * constants and whose message is its language key.
 *
 * @since  2.1.4
 */
final class TaskStaff
{
    /**
     * Refusal: the task is no longer failed or held (someone handled it, or it is gone).
     *
     * @since  2.1.4
     */
    public const REFUSED_GONE = 1;

    /**
     * Refusal: its handler offers no such action.
     *
     * @since  2.1.4
     */
    public const REFUSED_NOT_OFFERED = 2;

    /**
     * Refusal: Mark resolved was asked with no reason.
     *
     * @since  2.1.4
     */
    public const REFUSED_NO_REASON = 3;

    /**
     * The longest reason kept.
     *
     * @since  2.1.4
     */
    public const REASON_MAX = 500;

    /**
     * The most tasks the Tools card lists.
     *
     * @since  2.1.4
     */
    public const LIST_LIMIT = 100;

    /**
     * The failed and held singles, oldest first, with what staff may do with each.
     *
     * @param int $limit The most rows.
     *
     * @return list<array<string, mixed>> Each row's columns, plus label (a language key, '' for a handler this
     *                                    version does not have), action (resend, rerun or '') and units (unit =>
     *                                    its last recorded outcome).
     *
     * @since  2.1.4
     */
    public static function attentionRows(int $limit = self::LIST_LIMIT): array
    {
        $db = self::db();
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'handler', 'state', 'subject_type', 'subject_id', 'failures', 'claims', 'last_error', 'last_trigger', 'created_at', 'finished_at', 'history']))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_SINGLE))
                ->whereIn($db->quoteName('state'), [Tasks::STATE_FAILED, Tasks::STATE_HELD], ParameterType::STRING)
                ->order($db->quoteName('id') . ' ASC'),
            0,
            max(1, $limit),
        )->loadAssocList() ?: [];

        foreach ($rows as &$row) {
            $policy = Tasks::handler(name: (string) $row['handler'])?->policy();
            $row['label'] = $policy->label ?? '';
            $row['action'] = $policy->staffAction ?? '';
            $row['history'] = Tasks::decodeHistory($row['history']);
            $row['units'] = [];

            foreach ($row['history'] as $entry) {
                if (isset($entry['unit'])) {
                    $row['units'][(string) $entry['unit']] = (string) ($entry['outcome'] ?? '');
                }
            }
        }

        unset($row);

        return $rows;
    }

    /**
     * How many singles are failed or held.
     *
     * @since  2.1.4
     */
    public static function attentionTotal(): int
    {
        $db = self::db();

        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_SINGLE))
                ->whereIn($db->quoteName('state'), [Tasks::STATE_FAILED, Tasks::STATE_HELD], ParameterType::STRING),
        )->loadResult();
    }

    /**
     * Resend a failed or held e-mail to the recipients not recorded as sent.
     *
     * @param int $id The task.
     * @param int $userId Who asks.
     *
     * @return int The new task.
     *
     * @throws DomainException When the task cannot be resent (the message is a language key).
     *
     * @since  2.1.4
     */
    public static function resend(int $id, int $userId): int
    {
        return self::again(id: $id, userId: $userId, action: TaskPolicy::ACTION_RESEND);
    }

    /**
     * Run a failed or held task again for the parts not recorded as completed.
     *
     * @param int $id The task.
     * @param int $userId Who asks.
     *
     * @return int The new task.
     *
     * @throws DomainException When the task cannot be run again (the message is a language key).
     *
     * @since  2.1.4
     */
    public static function runAgain(int $id, int $userId): int
    {
        return self::again(id: $id, userId: $userId, action: TaskPolicy::ACTION_RERUN);
    }

    /**
     * Close a failed or held task with no action.
     *
     * @param int $id The task.
     * @param int $userId Who closes it.
     * @param string $reason Why nothing more is needed.
     *
     * @throws DomainException When no reason is given or the task is no longer failed or held (the message is a
     *                         language key).
     *
     * @since  2.1.4
     */
    public static function resolve(int $id, int $userId, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, self::REASON_MAX);

        if ($reason === '') {
            throw new DomainException('COM_ALFA_TASKS_REASON_REQUIRED', self::REFUSED_NO_REASON);
        }

        $db = self::db();
        $row = self::actionable(db: $db, id: $id);
        $history = [...Tasks::decodeHistory($row['history']), Tasks::entry(trigger: 'staff', userId: $userId, outcome: Tasks::STATE_RESOLVED, more: ['reason' => $reason])];

        if (!self::close(db: $db, id: $id, history: $history)) {
            throw new DomainException('COM_ALFA_TASKS_ACTION_GONE', self::REFUSED_GONE);
        }

        // Its handler records it where staff look for it (the order's timeline): who, when and why.
        Tasks::notifySettled(row: $row, event: TaskSettlement::RESOLVED, state: Tasks::STATE_RESOLVED, history: $history, userId: $userId, reason: $reason);
        Tasks::log(entry: 'Task #' . $id . ' (' . $row['handler'] . ') was marked resolved by user ' . $userId . ': ' . $reason, priority: Log::INFO);
        TaskAttention::sync();
    }

    /**
     * Resend or run again: a new single that names the original, written in the transaction that resolves the
     * original, and run once it has committed.
     *
     * @param int $id The task.
     * @param int $userId Who asks.
     * @param string $action TaskPolicy::ACTION_RESEND or TaskPolicy::ACTION_RERUN.
     *
     * @return int The new task.
     *
     * @throws DomainException When the task does not allow it or is no longer failed or held.
     *
     * @since  2.1.4
     */
    private static function again(int $id, int $userId, string $action): int
    {
        $db = self::db();
        $row = self::actionable(db: $db, id: $id);
        $policy = Tasks::handler(name: (string) $row['handler'])?->policy();

        if ($policy === null || $policy->staffAction !== $action) {
            throw new DomainException('COM_ALFA_TASKS_ACTION_NOT_OFFERED', self::REFUSED_NOT_OFFERED);
        }

        $payload = json_decode((string) $row['payload'], true);
        $payload = \is_array($payload) ? $payload : [];
        unset($payload['version'], $payload['resend_of'], $payload['rerun_of']);
        $payload[$action === TaskPolicy::ACTION_RESEND ? 'resend_of' : 'rerun_of'] = $id;
        $outcome = $action === TaskPolicy::ACTION_RESEND ? 'resent' : 'run again';

        // Every unit recorded done anywhere in the chain travels with the new task, so it is never repeated, whatever
        // is pruned of the tasks before it.
        $carried = array_map(
            static fn (string $unit): array => Tasks::entry(trigger: 'staff', userId: $userId, outcome: TaskRun::DONE, unit: $unit, more: ['carried_from' => $id]),
            Tasks::doneUnits(row: $row),
        );

        $db->transactionStart(true);

        try {
            $newId = Tasks::write(
                handler: (string) $row['handler'],
                subjectType: (string) $row['subject_type'],
                subjectId: (int) $row['subject_id'],
                payload: $payload,
                trigger: 'staff',
                userId: $userId,
                history: $carried,
            );
            $history = [...Tasks::decodeHistory($row['history']), Tasks::entry(trigger: 'staff', userId: $userId, outcome: $outcome, more: ['task' => $newId])];

            if (!self::close(db: $db, id: $id, history: $history)) {
                TransactionState::rollBackLevel(db: $db);

                throw new DomainException('COM_ALFA_TASKS_ACTION_GONE', self::REFUSED_GONE);
            }

            // Recorded with the act, in its transaction (the order's timeline: who resent or ran it again).
            Tasks::notifySettled(
                row: $row,
                event: $action === TaskPolicy::ACTION_RESEND ? TaskSettlement::RESENT : TaskSettlement::RUN_AGAIN,
                state: Tasks::STATE_RESOLVED,
                history: $history,
                userId: $userId,
                newTaskId: $newId,
            );

            // The new task runs after this commit.
            TransactionState::commitLevel($db);
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        Tasks::log(entry: 'Task #' . $id . ' (' . $row['handler'] . ') was ' . $outcome . ' by user ' . $userId . ' as task #' . $newId . '.', priority: Log::INFO);
        TaskAttention::sync();

        return $newId;
    }

    /**
     * A single staff may act on.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $id The task.
     *
     * @return array<string, mixed>
     *
     * @throws DomainException When it is not a failed or held single.
     *
     * @since  2.1.4
     */
    private static function actionable(DatabaseDriver $db, int $id): array
    {
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'type', 'handler', 'state', 'subject_type', 'subject_id', 'payload', 'history']))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER),
        )->loadAssoc();

        if (!\is_array($row) || $row['type'] !== Tasks::TYPE_SINGLE || !\in_array($row['state'], [Tasks::STATE_FAILED, Tasks::STATE_HELD], true)) {
            throw new DomainException('COM_ALFA_TASKS_ACTION_GONE', self::REFUSED_GONE);
        }

        return $row;
    }

    /**
     * Resolve a failed or held single, with its history: the affected row count says whether this act did it.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $id The task.
     * @param list<array<string, mixed>> $history Its history with this act.
     *
     * @since  2.1.4
     */
    private static function close(DatabaseDriver $db, int $id, array $history): bool
    {
        $json = Tasks::encodeHistory($history);
        $state = Tasks::STATE_RESOLVED;
        $trigger = 'staff';
        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName(Tasks::TABLE))
                ->set($db->quoteName('state') . ' = :state')
                ->set($db->quoteName('history') . ' = :history')
                ->set($db->quoteName('last_trigger') . ' = :trigger')
                ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
                ->set($db->quoteName('finished_at') . ' = UTC_TIMESTAMP(3)')
                ->where($db->quoteName('id') . ' = :id')
                ->where($db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_SINGLE))
                ->whereIn($db->quoteName('state'), [Tasks::STATE_FAILED, Tasks::STATE_HELD], ParameterType::STRING)
                ->bind(':state', $state)
                ->bind(':history', $json)
                ->bind(':trigger', $trigger)
                ->bind(':id', $id, ParameterType::INTEGER),
        )->execute();

        return $db->getAffectedRows() === 1;
    }

    /**
     * The site's connection.
     *
     * @since  2.1.4
     */
    private static function db(): DatabaseDriver
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }
}
