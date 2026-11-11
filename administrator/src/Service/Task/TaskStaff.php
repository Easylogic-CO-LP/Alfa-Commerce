<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MysqlError;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Media\ThumbnailRecreation;
use DomainException;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * What staff do with a failed or held single, or a failed job, each act audited in its history.
 *
 * Nothing failed or held is ever run again automatically: staff decide. Resend (an e-mail) and Run again (a plugin's
 * reaction) write a new single that names the original (resend_of, rerun_of) and carries every part recorded done in
 * the chain; it runs at once, its handler attempts only the parts not recorded as done, and the original becomes
 * resolved in the same transaction. Mark resolved
 * closes a task with no action, with the reason given. Retry puts a failed job back in the queue with its failures
 * reset; a job is never cancelled. A refusal is a DomainException whose code is one of the REFUSED_
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
     * Refusal: another job of the same work is already waiting or running.
     *
     * @since  2.2.0
     */
    public const REFUSED_ACTIVE = 4;

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
     * How recently a waiting job must have advanced to read as running: the staff kick and the progress page drive a job
     * in steps a few seconds apart, and between two of them its row waits (2.2.0, entry 27).
     *
     * @since  2.2.0
     */
    public const DRIVEN_SECONDS = 30;

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
     * What the Tasks tab's jobs card draws (layouts/tasks/jobs.php), the one source of the Tools page and of the card's
     * refresh on a progress page (TasksController::jobsCard(), UX-84): the jobs, the names of the customer groups their
     * price sets cover and of the search engines their rebuilds cover, the thumbnails mode a thumbnails job without a
     * report yet will walk in (each read once, and only when such a job is listed: the layout reads no database), how
     * many jobs failed (the attention card says so when no single needs attention, UX-02), and the job a progress page
     * follows (its row says where it is followed, entry 20).
     *
     * @param int $followedJob The job the page follows, 0 for none.
     *
     * @return array{rows: list<array<string, mixed>>, groups: array<int, string>, engines: array<string, string>, thumbnailsMode: string|null, followedJob: int, failedJobs: int, unreadable: bool}
     *
     * @since  2.2.0
     */
    public static function jobsCard(int $followedJob): array
    {
        try {
            $rows = self::jobRows();
        } catch (Throwable $e) {
            Tasks::log(entry: 'Tools: the jobs could not be read: ' . $e::class . ': ' . $e->getMessage(), priority: Log::ERROR);

            return ['rows' => [], 'groups' => [], 'engines' => [], 'thumbnailsMode' => null, 'followedJob' => $followedJob, 'failedJobs' => 0, 'unreadable' => true];
        }

        $thumbnailsMode = null;
        $engines = [];
        $search = Job\SearchRebuildJob::NAME . ':';

        foreach ($rows as $row) {
            $scope = (string) $row['scope_key'];

            if ($thumbnailsMode === null && $scope === Job\ThumbnailsRecreateJob::NAME && ($row['report'] ?? null) === null) {
                $thumbnailsMode = ThumbnailRecreation::mode();
            }

            if (str_starts_with($scope, $search) && !isset($engines[substr($scope, \strlen($search))])) {
                $engines[substr($scope, \strlen($search))] = Job\SearchRebuildJob::engineName(key: substr($scope, \strlen($search)));
            }
        }

        $ids = array_values(array_unique(array_merge([], ...array_column($rows, 'groups'))));
        $groups = [];

        if ($ids !== []) {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $groups = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'title']))
                    ->from($db->quoteName('#__usergroups'))
                    ->whereIn($db->quoteName('id'), $ids),
            )->loadAssocList('id', 'title') ?: [];
        }

        return [
            'rows' => $rows, 'groups' => $groups, 'engines' => $engines, 'thumbnailsMode' => $thumbnailsMode, 'followedJob' => $followedJob,
            'failedJobs' => \count(array_filter($rows, static fn (array $row): bool => $row['state'] === Tasks::STATE_FAILED)), 'unreadable' => false,
        ];
    }

    /**
     * The jobs, for Tools > Tasks: running and waiting first, then failed, then the finished ones newest first.
     *
     * @param int $limit The most rows.
     *
     * @return list<array<string, mixed>> Each row's columns, plus label (a language key, '' for a handler this version
     *                                    does not have), groups (the customer groups a price set's job prepares,
     *                                    an empty list for any other job) and driven (true for a job running, or
     *                                    waiting between two steps it took within self::DRIVEN_SECONDS: one being
     *                                    driven, displayed as running).
     *
     * @since  2.2.0
     */
    public static function jobRows(int $limit = self::LIST_LIMIT): array
    {
        $db = self::db();
        $order = 'CASE ' . $db->quoteName('state') . ' WHEN ' . $db->quote(Tasks::STATE_RUNNING) . ' THEN 0 WHEN ' . $db->quote(Tasks::STATE_PENDING)
            . ' THEN 1 WHEN ' . $db->quote(Tasks::STATE_FAILED) . ' THEN 2 ELSE 3 END';
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'handler', 'state', 'scope_key', 'done_units', 'total_units', 'failures', 'last_error', 'run_after', 'created_at', 'started_at', 'finished_at']))
                // Driven: running, or started, due and advanced within DRIVEN_SECONDS (a step's end writes updated_at;
                // a failed step sets its retry in the future, so a job waiting out its retry is not driven).
                ->select('(' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING) . ' OR (' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_PENDING)
                    . ' AND ' . $db->quoteName('started_at') . ' IS NOT NULL AND ' . $db->quoteName('run_after') . ' <= UTC_TIMESTAMP(3)'
                    . ' AND ' . $db->quoteName('updated_at') . ' >= UTC_TIMESTAMP(3) - INTERVAL ' . self::DRIVEN_SECONDS . ' SECOND)) AS ' . $db->quoteName('driven'))
                // The thumbnails job's walk keeps its mode and its report in its checkpoint (2.2.0, part C21).
                ->select('CASE WHEN ' . $db->quoteName('handler') . ' = ' . $db->quote(Job\ThumbnailsRecreateJob::NAME) . ' THEN ' . $db->quoteName('checkpoint') . ' END AS ' . $db->quoteName('checkpoint'))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB))
                ->order($order . ' ASC, ' . $db->quoteName('id') . ' DESC'),
            0,
            max(1, $limit),
        )->loadAssocList() ?: [];
        $prefix = Job\PriceSetPrepareJob::NAME . ':';
        $digests = [];

        foreach ($rows as $row) {
            if (str_starts_with((string) $row['scope_key'], $prefix)) {
                $digests[] = substr((string) $row['scope_key'], \strlen($prefix));
            }
        }

        $lists = $digests === [] ? [] : ($db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['digest', 'group_list']))
                ->from($db->quoteName('#__alfa_price_group_sets'))
                ->whereIn($db->quoteName('digest'), array_values(array_unique($digests)), ParameterType::STRING),
        )->loadAssocList('digest', 'group_list') ?: []);

        foreach ($rows as &$row) {
            $row['label'] = Runner::handler(name: (string) $row['handler'])?->policy()->label ?? '';
            $row['driven'] = (bool) $row['driven'];
            $list = str_starts_with((string) $row['scope_key'], $prefix) ? (string) ($lists[substr((string) $row['scope_key'], \strlen($prefix))] ?? '') : '';
            $row['groups'] = $list === '' ? [] : array_map('intval', explode(',', $list));
            $row['report'] = $row['handler'] === Job\ThumbnailsRecreateJob::NAME ? ThumbnailRecreation::reportOf(checkpoint: $row['checkpoint']) : null;
            unset($row['checkpoint']);
        }

        unset($row);

        return $rows;
    }

    /**
     * A job's progress in words for its progress card: "640 of 1500 (43%)", or nothing when its total is 0, where the
     * card says the state alone, as the jobs list does (UX-04, NEW-6).
     *
     * @param int $done The units done.
     * @param int $total The units in all.
     * @param int $percent Its percent.
     *
     * @since  2.2.0
     */
    public static function unitsText(int $done, int $total, int $percent): string
    {
        return $total > 0 ? Text::sprintf('COM_ALFA_TASKS_PROGRESS_UNITS', $done, $total, $percent) : '';
    }

    /**
     * One job as the progress page shows it, or null when there is no job of that id.
     *
     * @param int $id The job.
     *
     * @return array{id: int, label: string, heavy: bool, state: string, done: int, total: int, percent: int, error: string,
     *               started_at: ?string, finished_at: ?string, report: array<string, mixed>|null}|null
     *
     * @since  2.2.0
     */
    public static function job(int $id): ?array
    {
        $row = TaskDocument::row(id: $id);

        if ($row === null || $row['type'] !== Tasks::TYPE_JOB) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'label' => Runner::handler(name: (string) $row['handler'])?->policy()->label ?? '',
            'heavy' => Runner::handler(name: (string) $row['handler'])?->policy()->weight === JobPolicy::HEAVY,
            'state' => (string) $row['state'],
            'done' => (int) $row['done_units'],
            'total' => (int) $row['total_units'],
            'percent' => TaskDocument::percent(row: $row),
            'error' => (string) $row['last_error'],
            'started_at' => $row['started_at'],
            'finished_at' => $row['finished_at'],
            'report' => (string) $row['scope_key'] === Job\ThumbnailsRecreateJob::NAME ? self::thumbnailsReport(id: (int) $row['id']) : null,
        ];
    }

    /**
     * A thumbnails job's report so far (made, kept, missing, not made), read from its own checkpoint, or null before its
     * first step (UX-84: the progress card prints it).
     *
     * @param int $id The job.
     *
     * @return array<string, mixed>|null
     *
     * @since  2.2.0
     */
    private static function thumbnailsReport(int $id): ?array
    {
        $db = self::db();

        return ThumbnailRecreation::reportOf(checkpoint: $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('checkpoint'))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER),
        )->loadResult());
    }

    /**
     * Process now on a job waiting out its backoff: due now, as staff asked (2.2.0). A job that is not waiting, or is
     * due already, is left as it is.
     *
     * @param int $id The job.
     *
     * @since  2.2.0
     */
    public static function dueNow(int $id): void
    {
        $db = self::db();

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName(Tasks::TABLE))
                    ->set($db->quoteName('run_after') . ' = UTC_TIMESTAMP(3)')
                    ->where($db->quoteName('id') . ' = :id')
                    ->where($db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB))
                    ->where($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_PENDING))
                    ->where($db->quoteName('run_after') . ' > UTC_TIMESTAMP(3)')
                    ->bind(':id', $id, ParameterType::INTEGER),
            )->execute();
        } catch (Throwable $e) {
            Tasks::log(entry: 'Process now: job #' . $id . ' could not be made due now: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
        }
    }

    /**
     * Put a failed job back in the queue: waiting, due now, its failures reset. The next tick works it from its
     * checkpoint.
     *
     * @param int $id The job.
     * @param int $userId Who asks.
     *
     * @throws DomainException When it is no longer a failed job, or another job of the same work is active (the
     *                         message is a language key).
     *
     * @since  2.2.0
     */
    public static function retry(int $id, int $userId): void
    {
        $db = self::db();
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'type', 'handler', 'state', 'history']))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER),
        )->loadAssoc();

        if (!\is_array($row) || $row['type'] !== Tasks::TYPE_JOB || $row['state'] !== Tasks::STATE_FAILED) {
            throw new DomainException('COM_ALFA_TASKS_ACTION_GONE', self::REFUSED_GONE);
        }

        $history = Tasks::encodeHistory([...Tasks::decodeHistory($row['history']), Tasks::entry(trigger: 'staff', userId: $userId, outcome: 'retried')]);
        $pending = Tasks::STATE_PENDING;
        $trigger = 'staff';

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName(Tasks::TABLE))
                    ->set($db->quoteName('state') . ' = :state')
                    ->set($db->quoteName('failures') . ' = 0')
                    ->set($db->quoteName('run_after') . ' = UTC_TIMESTAMP(3)')
                    ->set($db->quoteName('lease_owner') . ' = NULL')
                    ->set($db->quoteName('lease_until') . ' = NULL')
                    ->set($db->quoteName('finished_at') . ' = NULL')
                    ->set($db->quoteName('history') . ' = :history')
                    ->set($db->quoteName('last_trigger') . ' = :trigger')
                    ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
                    ->where($db->quoteName('id') . ' = :id')
                    ->where($db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB))
                    ->where($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_FAILED))
                    ->bind(':state', $pending)
                    ->bind(':history', $history)
                    ->bind(':trigger', $trigger)
                    ->bind(':id', $id, ParameterType::INTEGER),
            )->execute();
        } catch (Throwable $e) {
            // Waiting again would make it the second active job of its scope: the one already active covers it.
            if (MysqlError::number(error: $e) === 1062) {
                throw new DomainException('COM_ALFA_TASKS_JOB_ACTIVE', self::REFUSED_ACTIVE);
            }

            throw $e;
        }

        if ($db->getAffectedRows() !== 1) {
            throw new DomainException('COM_ALFA_TASKS_ACTION_GONE', self::REFUSED_GONE);
        }

        Tasks::log(entry: 'Job #' . $id . ' (' . $row['handler'] . ') was put back in the queue by user ' . $userId . '.', priority: Log::INFO);
        TaskAttention::sync();
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
     * Close a failed or held single, or a failed job, with no action.
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
     * A task staff may act on: a failed or held single, or a failed job.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $id The task.
     *
     * @return array<string, mixed>
     *
     * @throws DomainException When it is neither a failed or held single nor a failed job.
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

        if (!\is_array($row) || !self::open(type: (string) $row['type'], state: (string) $row['state'])) {
            throw new DomainException('COM_ALFA_TASKS_ACTION_GONE', self::REFUSED_GONE);
        }

        return $row;
    }

    /**
     * Resolve a failed or held single, or a failed job, with its history: the affected row count says whether this
     * act did it.
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
                ->where('((' . $db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_SINGLE) . ' AND ' . $db->quoteName('state') . ' IN ('
                    . $db->quote(Tasks::STATE_FAILED) . ', ' . $db->quote(Tasks::STATE_HELD) . ')) OR (' . $db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB)
                    . ' AND ' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_FAILED) . '))')
                ->bind(':state', $state)
                ->bind(':history', $json)
                ->bind(':trigger', $trigger)
                ->bind(':id', $id, ParameterType::INTEGER),
        )->execute();

        return $db->getAffectedRows() === 1;
    }

    /**
     * Whether staff may act on a task of this type in this state: a failed or held single, or a failed job.
     *
     * @param string $type Its type.
     * @param string $state Its state.
     *
     * @since  2.2.0
     */
    private static function open(string $type, string $state): bool
    {
        return $type === Tasks::TYPE_SINGLE ? \in_array($state, [Tasks::STATE_FAILED, Tasks::STATE_HELD], true) : $type === Tasks::TYPE_JOB && $state === Tasks::STATE_FAILED;
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
