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
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\PostCommit;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\MaintenanceState;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderDocuments;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderEntitlements;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderPlacementEmail;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderStatusEmail;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderStatusEvent;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderStock;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * The tasks of Alfa Commerce (#__alfa_tasks): what a change owes after its commit (a single) and, from 2.2.0, long
 * work with steps (a job). This class writes and runs singles; nothing in 2.1.4 writes or claims a job.
 *
 * A SINGLE is written inside the business change's own transaction ({@see self::single()}), so a rollback removes it
 * with the change and a commit keeps it: the row is the proof that the change committed and the record of what it
 * owes. Its normal path is its own request: the singles of one transaction (a batch) run in id order right after the
 * outermost commit and the release of Alfa's named locks ({@see PostCommit}); its notices (the notice journal,
 * {@see self::notice()}) are written at that commit itself, even while a named lock is held (s71). A request that ends
 * first leaves them here, and recovery runs them later ({@see self::sweepSingles()}: the notification bell's background
 * refresh and Tools > Tasks). Recovery is never the normal path, and a single is never scheduled.
 *
 * Each task is claimed by one worker at a time, through a conditional UPDATE whose affected row count is the answer,
 * and settled only by the claim that ran it. A known failure is retried with backoff when the handler allows it. An
 * outcome nobody can know (handed over with no answer, or a run that stopped while it held the task) is held for
 * staff and never run again automatically, unless the handler's work is idempotent. Staff act on failed and held
 * tasks through {@see TaskStaff}; the attention notice says when there are any ({@see TaskAttention}).
 *
 * @since  2.1.4
 */
final class Tasks
{
    /**
     * The table.
     *
     * @since  2.1.4
     */
    public const TABLE = '#__alfa_tasks';

    /**
     * One thing a change owes after its commit, born in that transaction.
     *
     * @since  2.1.4
     */
    public const TYPE_SINGLE = 'single';

    /**
     * Long work with steps and a checkpoint (2.2.0).
     *
     * @since  2.1.4
     */
    public const TYPE_JOB = 'job';

    /** @since  2.1.4 */
    public const STATE_PENDING = 'pending';

    /** @since  2.1.4 */
    public const STATE_RUNNING = 'running';

    /** @since  2.1.4 */
    public const STATE_DONE = 'done';

    /** @since  2.1.4 */
    public const STATE_FAILED = 'failed';

    /** @since  2.1.4 */
    public const STATE_HELD = 'held';

    /** @since  2.1.4 */
    public const STATE_RESOLVED = 'resolved';

    /**
     * How long a claim lasts, renewed by every history write: longer than PHPMailer's 300 seconds per command.
     *
     * @since  2.1.4
     */
    public const LEASE_SECONDS = 900;

    /**
     * How old a single that never ran must be before recovery takes it, so its own request runs it first.
     *
     * @since  2.1.4
     */
    public const GRACE_SECONDS = 120;

    /**
     * The wait before each automatic retry of a known failure: 1 min, 5 min, 30 min, 2 h, 6 h, 24 h.
     *
     * @since  2.1.4
     */
    public const BACKOFF_SECONDS = [60, 300, 1800, 7200, 21600, 86400];

    /**
     * The bell's recovery budget, wall clock.
     *
     * @since  2.1.4
     */
    public const RECOVER_BUDGET_MS = 1500;

    /**
     * How long finished tasks are kept.
     *
     * @since  2.1.4
     */
    public const KEEP_DAYS = 30;

    /**
     * Minutes between two recoveries from the bell.
     *
     * @since  2.1.4
     */
    private const RECOVER_MINUTES = 2;

    /**
     * Minutes between two prunes from the bell.
     *
     * @since  2.1.4
     */
    private const PRUNE_MINUTES = 60;

    /**
     * Rows deleted per statement.
     *
     * @since  2.1.4
     */
    private const PRUNE_BATCH = 500;

    /**
     * Most delete statements per prune.
     *
     * @since  2.1.4
     */
    private const PRUNE_ROUNDS = 10;

    /**
     * Most resends or runs again followed back to the first task.
     *
     * @since  2.1.4
     */
    private const CHAIN_LIMIT = 20;

    /**
     * How many times a write on the tasks table is tried when the server undid it for a deadlock or a lock wait.
     *
     * @since  2.1.4
     */
    private const WRITE_ATTEMPTS = 3;

    /**
     * How many times a write of notices takes over the older notices of its keys that are still owed, before it leaves
     * them to recovery (contention, never counted as a failure).
     *
     * @since  2.1.4
     */
    private const TAKEOVER_ROUNDS = 5;

    /**
     * How long, in seconds, a run waits for the lock of a notification key another run is writing.
     *
     * @since  2.1.4
     */
    private const NOTICE_LOCK_SECONDS = 10;

    /**
     * The hold under which a run writes notices: what its writes defer (their announcements) runs once the keys' locks
     * are released ({@see self::applyNotices()}).
     *
     * @since  2.1.4
     */
    private const NOTICE_HOLD = 'alfa.tasks.notices';

    /**
     * The flags every JSON column of the table is written with.
     *
     * @since  2.1.4
     */
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * The handlers made so far in this request, by name.
     *
     * @var array<string, TaskHandler>
     *
     * @since  2.1.4
     */
    private static array $handlers = [];

    /**
     * The batch of each connection's open transaction.
     *
     * @var WeakMap<DatabaseDriver, string>|null
     *
     * @since  2.1.4
     */
    private static ?WeakMap $batches = null;

    /**
     * Owe one thing after the commit of the change being made.
     *
     * Inside a transaction the row joins the batch of the outermost transaction, which runs once that transaction
     * has committed and Alfa's named locks are released; a rollback removes it. With no transaction open it is
     * written and run at once, through the same path (after the release of a named lock when one is held).
     *
     * @param string $handler The handler's name.
     * @param string $subjectType What it is about, e.g. order; '' for nothing.
     * @param int $subjectId The id of what it is about, 0 for nothing.
     * @param array<string, mixed> $payload What the handler needs.
     *
     * @return int The task's id.
     *
     * @throws InvalidArgumentException When no handler has that name.
     *
     * @since  2.1.4
     */
    public static function single(string $handler, string $subjectType = '', int $subjectId = 0, array $payload = []): int
    {
        return self::write(handler: $handler, subjectType: $subjectType, subjectId: $subjectId, payload: $payload, trigger: 'request', userId: self::userId());
    }

    /**
     * Write a single for a given trigger and user: {@see self::single()} for the request, {@see TaskStaff} for a
     * resend or a run again.
     *
     * @param string $handler The handler's name.
     * @param string $subjectType What it is about.
     * @param int $subjectId The id of what it is about.
     * @param array<string, mixed> $payload What the handler needs.
     * @param string $trigger What runs it first: request or staff.
     * @param int $userId Who asked for it.
     * @param list<array<string, mixed>> $history What it starts with: the done units a resend carries ({@see self::doneUnits()}).
     *
     * @return int The task's id.
     *
     * @throws InvalidArgumentException When no handler has that name.
     *
     * @since  2.1.4
     */
    public static function write(string $handler, string $subjectType, int $subjectId, array $payload, string $trigger, int $userId, array $history = []): int
    {
        $implementation = self::handler(name: $handler);

        if ($implementation === null) {
            throw new InvalidArgumentException('No task handler is named ' . $handler . '.');
        }

        $db = self::db();
        [$token, $waiting] = self::batch(db: $db);
        $id = self::insert(db: $db, implementation: $implementation, handler: $handler, subjectType: $subjectType, subjectId: $subjectId, payload: $payload, token: $token, userId: $userId, history: $history);

        if ($waiting) {
            // The batch's run already waits: the outermost commit runs it, or, with nothing open, it runs now.
            PostCommit::flush($db);
        } else {
            PostCommit::deferToOutermost(
                db: $db,
                label: self::batchLabel(token: $token),
                work: static function () use ($db, $token, $trigger): void {
                    self::runBatch(db: $db, batch: $token, trigger: $trigger);
                },
            );
        }

        return $id;
    }

    /**
     * Record a notification write raised inside the connection's transaction: a notice single in the transaction's
     * batch, and its entry in the notice journal ({@see PostCommit::journal()}). No run waits for it: the journal is
     * written once the outermost transaction has committed, before any other work, even inside a lock scope or a hold
     * (s71), and recovery writes what a request left. Until it is written every reader counts it
     * ({@see NotificationHelper::exists()}). A failure to write the single reaches the caller, as a failure to write the
     * notification did.
     *
     * @param DatabaseDriver $db The connection, with a transaction open.
     * @param array<string, mixed> $op The write: op (push or clear), key, and its arguments.
     *
     * @return int The single's id.
     *
     * @since  2.1.4
     */
    public static function notice(DatabaseDriver $db, array $op): int
    {
        $implementation = self::handler(name: NoticeTask::NAME);
        [$token] = self::batch(db: $db);
        $id = self::insert(db: $db, implementation: $implementation, handler: NoticeTask::NAME, subjectType: '', subjectId: 0, payload: $op, token: $token, userId: self::userId());
        PostCommit::journal(db: $db, label: self::noticeLabel(token: $token), entry: ['task' => $id, 'batch' => $token, 'op' => $op]);

        return $id;
    }

    /**
     * Insert one single.
     *
     * @param DatabaseDriver $db The connection.
     * @param TaskHandler $implementation Its handler.
     * @param string $handler The handler's name.
     * @param string $subjectType What it is about.
     * @param int $subjectId The id of what it is about.
     * @param array<string, mixed> $payload What the handler needs.
     * @param string $token Its batch.
     * @param int $userId Who asked for it.
     * @param list<array<string, mixed>> $history What it starts with.
     *
     * @return int The task's id.
     *
     * @since  2.1.4
     */
    private static function insert(DatabaseDriver $db, TaskHandler $implementation, string $handler, string $subjectType, int $subjectId, array $payload, string $token, int $userId, array $history = []): int
    {
        $json = json_encode(['version' => $implementation->policy()->version] + $payload, self::JSON);
        $type = self::TYPE_SINGLE;
        $origin = self::origin();
        $started = $history === [] ? null : self::encodeHistory($history);
        $query = $db->getQuery(true)
            ->insert($db->quoteName(self::TABLE))
            ->columns($db->quoteName(['type', 'handler', 'batch', 'subject_type', 'subject_id', 'payload', 'history', 'run_after', 'origin', 'created_by', 'created_at', 'updated_at']))
            ->values(':type, :handler, :batch, :subjectType, :subjectId, :payload, ' . ($started === null ? 'NULL' : ':history') . ', UTC_TIMESTAMP(3), :origin, :createdBy, UTC_TIMESTAMP(6), UTC_TIMESTAMP(3)')
            ->bind(':type', $type)
            ->bind(':handler', $handler)
            ->bind(':batch', $token)
            ->bind(':subjectType', $subjectType)
            ->bind(':subjectId', $subjectId, ParameterType::INTEGER)
            ->bind(':payload', $json)
            ->bind(':origin', $origin)
            ->bind(':createdBy', $userId, ParameterType::INTEGER);

        if ($started !== null) {
            $query->bind(':history', $started);
        }

        $db->setQuery($query)->execute();

        return (int) $db->insertid();
    }

    /**
     * Write the notice journal of transactions that committed: what PostCommit runs first after the outermost commit,
     * even while a lock scope or a hold is open (s71). The singles still pending, due and held by no run are claimed one
     * by one through their primary key and written together with the older notices of their keys
     * ({@see self::applyNotices()}); one a rollback removed, or another run holds, is left to it.
     *
     * @param DatabaseDriver $db The connection.
     * @param list<array{task: int, batch: string, op: array<string, mixed>}> $entries The journal.
     *
     * @since  2.1.4
     */
    public static function applyJournal(DatabaseDriver $db, array $entries): void
    {
        $ids = array_values(array_unique(array_map(static fn (array $entry): int => (int) $entry['task'], $entries)));

        if ($ids === [] || TransactionState::isOpen($db)) {
            return;
        }

        $lease = bin2hex(random_bytes(16));
        $trigger = 'request';
        $claimed = 0;

        foreach ($ids as $id) {
            $claimed += self::claimNotice(db: $db, id: $id, lease: $lease, trigger: $trigger);
        }

        if ($claimed > 0) {
            $counts = self::counts();
            self::applyNotices(db: $db, lease: $lease, trigger: $trigger, userId: self::userId(), counts: $counts);
        }
    }

    /**
     * The LIKE pattern that finds the notice singles of a notification key by their payload, or of every key under a
     * prefix: the key as {@see self::insert()} encodes it. Another field's text can match it, so the reader takes the
     * key back from the payload.
     *
     * @param string $key The key, or the prefix.
     * @param bool $prefix Whether every key under it.
     *
     * @since  2.1.4
     */
    public static function noticePattern(string $key, bool $prefix = false): string
    {
        $json = json_encode($key, self::JSON);

        return '%' . addcslashes('"key":' . ($prefix ? substr($json, 0, -1) : $json), '%_\\') . '%';
    }

    /**
     * Claim one notice single for a run, through its primary key alone (an index hint, which MariaDB and MySQL accept on
     * an UPDATE): read through idx_due, a claim locked the pending notices of other requests claiming theirs at the
     * same moment, and the two deadlocked (REVIEW-C3 MINOR-3).
     *
     * @param DatabaseDriver $db The connection.
     * @param int $id The single.
     * @param string $lease The run's claim.
     * @param string $trigger What runs it.
     * @param string|null $from null: a pending, due single no live run holds; '': a single not written yet whoever
     *                          holds it (a notice of a key this run writes, under the key's lock), never one that waits
     *                          out its backoff; else the reservation it is under.
     *
     * @return int 1 when the run holds it now.
     *
     * @since  2.1.4
     */
    private static function claimNotice(DatabaseDriver $db, int $id, string $lease, string $trigger, ?string $from = null): int
    {
        $handler = NoticeTask::NAME;
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::TABLE) . ' FORCE INDEX (PRIMARY)')
            ->set($db->quoteName('state') . ' = ' . $db->quote(self::STATE_RUNNING))
            ->set($db->quoteName('lease_owner') . ' = :lease')
            ->set($db->quoteName('lease_until') . ' = ' . self::leaseEnd())
            ->set($db->quoteName('claims') . ' = ' . $db->quoteName('claims') . ' + 1')
            ->set($db->quoteName('last_trigger') . ' = :trigger')
            ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('type') . ' = ' . $db->quote(self::TYPE_SINGLE))
            ->where($db->quoteName('handler') . ' = :handler')
            ->bind(':lease', $lease)
            ->bind(':trigger', $trigger)
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':handler', $handler);

        if ($from === null) {
            $query->where($db->quoteName('state') . ' = ' . $db->quote(self::STATE_PENDING))
                ->where($db->quoteName('run_after') . ' <= UTC_TIMESTAMP(3)')
                ->where(self::unreserved(db: $db));
        } elseif ($from === '') {
            $query->where('(' . $db->quoteName('state') . ' = ' . $db->quote(self::STATE_RUNNING) . ' OR (' . $db->quoteName('state') . ' = '
                . $db->quote(self::STATE_PENDING) . ' AND ' . $db->quoteName('run_after') . ' <= UTC_TIMESTAMP(3)))');
        } else {
            $query->where($db->quoteName('state') . ' = ' . $db->quote(self::STATE_PENDING))
                ->where($db->quoteName('lease_owner') . ' = :from')
                ->bind(':from', $from);
        }

        return self::changed(db: $db, query: $query);
    }

    /**
     * Write the notices a run claimed, in id order across their keys, in one transaction that deletes their singles
     * first: a notice is written once or not at all, and one whose row was written after it was raised is skipped
     * ({@see NotificationHelper::applyJournal()}).
     *
     * One run writes a key at a time (REVIEW-C3 fix round, MAJOR-2): the run takes the named lock of each of its keys,
     * in key order, and under them takes over every notice of those keys still owed, from any request (REVIEW-C3
     * MAJOR-1), and writes them all in the order raised. A run that waits for a key's lock finds its notices written
     * by the run that held it, and has nothing left to write: losing them to that run is not a failure. A notice that
     * waits out its backoff is never taken over early: the run's notices of its key wait with it, written together when
     * it is due. A deadlock or a lock wait on a row is tried again; only a write that fails otherwise counts a failure,
     * with backoff, failed after the last. A lock not had within {@see self::NOTICE_LOCK_SECONDS}, or older notices still
     * appearing after {@see self::TAKEOVER_ROUNDS} rounds, leaves the notices to recovery with no failure counted. A
     * notice's failure is only logged.
     *
     * No plugin code runs under a key's lock: the run holds the connection's waiting work ({@see PostCommit::hold()})
     * from before it takes the locks until it has released them, so what its writes defer (onAlfaNotificationPushed)
     * runs after the release, and a slow listener never delays another run writing the same key. Inside a lock scope
     * of the caller it waits for that scope's release, as before.
     *
     * @param DatabaseDriver $db The connection, with nothing open.
     * @param string $lease The run's claim.
     * @param string $trigger What runs it.
     * @param int $userId Who.
     * @param array<string, int> $counts The tally.
     *
     * @since  2.1.4
     */
    private static function applyNotices(DatabaseDriver $db, string $lease, string $trigger, int $userId, array &$counts): void
    {
        $rows = self::leasedNotices(db: $db, lease: $lease);

        if ($rows === []) {
            return;
        }

        $keys = array_values(array_unique(array_map(static fn (array $row): string => self::noticeKey(payload: $row['payload']), $rows)));
        sort($keys);
        PostCommit::hold(db: $db, name: self::NOTICE_HOLD);

        try {
            $locks = self::lockNoticeKeys(db: $db, keys: $keys);

            if ($locks === null) {
                self::releaseNotices(db: $db, rows: $rows, lease: $lease);
                self::log(entry: 'Tasks: the notices #' . implode(', #', array_column($rows, 'id')) . ' wait for another run writing the same keys; recovery writes them.', priority: Log::INFO);

                return;
            }

            try {
                self::writeNotices(db: $db, keys: $keys, lease: $lease, trigger: $trigger, userId: $userId, counts: $counts);
            } finally {
                foreach ($locks as $name) {
                    try {
                        $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($name) . ')')->loadResult();
                    } catch (Throwable) {
                        // A lock the connection lost goes with it.
                    }
                }
            }
        } finally {
            // The keys' locks are released: what the writes deferred runs now (or at the caller's own release).
            PostCommit::resume(db: $db, name: self::NOTICE_HOLD);
        }
    }

    /**
     * Write the notices of keys whose locks this run holds: {@see self::applyNotices()}.
     *
     * @param DatabaseDriver $db The connection, with nothing open.
     * @param list<string> $keys The keys, their locks held.
     * @param string $lease The run's claim.
     * @param string $trigger What runs it.
     * @param int $userId Who.
     * @param array<string, int> $counts The tally.
     *
     * @since  2.1.4
     */
    private static function writeNotices(DatabaseDriver $db, array $keys, string $lease, string $trigger, int $userId, array &$counts): void
    {
        $attempt = 1;

        for ($round = 1; ; $round++) {
            // Every notice of the keys still owed is this run's to write, but one that waits out its backoff: the
            // run's notices of its key wait with it.
            $waits = self::takeOverNotices(db: $db, keys: $keys, lease: $lease, trigger: $trigger);
            $rows = self::leasedNotices(db: $db, lease: $lease);
            $later = array_values(array_filter($rows, static fn (array $row): bool => isset($waits[self::noticeKey(payload: $row['payload'])])));

            foreach ($later as $row) {
                self::releaseNotices(db: $db, rows: [$row], lease: $lease, runAfter: $waits[self::noticeKey(payload: $row['payload'])]);
            }

            $rows = array_values(array_filter($rows, static fn (array $row): bool => !isset($waits[self::noticeKey(payload: $row['payload'])])));

            if ($rows === []) {
                return;
            }

            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            $ops = array_map(static fn (array $row): array => [
                'task' => (int) $row['id'],
                'raised' => (string) $row['created_at'],
                'op' => (array) json_decode((string) $row['payload'], true),
            ], $rows);
            $older = [];
            $db->transactionStart(true);

            try {
                // Their singles first, through the primary key alone (the multi-table form takes an index hint on
                // MariaDB and MySQL): on a small table MariaDB plans this DELETE as a full scan, which would lock every
                // single, other requests' uncommitted ones included, until the commit.
                $gone = self::changed(
                    db: $db,
                    query: $db->getQuery(true)
                        ->setQuery(
                            'DELETE ' . $db->quoteName('t') . ' FROM ' . $db->quoteName(self::TABLE) . ' AS ' . $db->quoteName('t') . ' FORCE INDEX (PRIMARY)'
                            . ' WHERE ' . $db->quoteName('t.id') . ' IN (' . implode(', ', $ids) . ')'
                            . ' AND ' . $db->quoteName('t.lease_owner') . ' = :lease'
                            . ' AND ' . $db->quoteName('t.state') . ' = ' . $db->quote(self::STATE_RUNNING),
                        )
                        ->bind(':lease', $lease),
                );

                if ($gone === \count($ids)) {
                    $older = NotificationHelper::applyJournal(ops: $ops);
                }

                if ($gone !== \count($ids) || $older !== []) {
                    // A notice committed meanwhile by a slower request: the next round takes it over too.
                    TransactionState::rollBackLevel($db);

                    if ($round >= self::TAKEOVER_ROUNDS) {
                        self::releaseNotices(db: $db, rows: $rows, lease: $lease);
                        self::log(entry: 'Tasks: the notices #' . implode(', #', $ids) . ' met older notices of their keys still being raised; recovery writes them.', priority: Log::INFO);

                        return;
                    }

                    continue;
                }

                TransactionState::commitLevel($db);
                $counts['done'] += \count($rows);

                return;
            } catch (Throwable $e) {
                if (TransactionState::isOpen($db)) {
                    TransactionState::rollBackLevel(db: $db, failure: $e);
                }

                if ($attempt < self::WRITE_ATTEMPTS && \in_array(needle: MysqlError::number(error: $e), haystack: [1205, 1213], strict: true)) {
                    $attempt++;
                    usleep(random_int(5000, 30000) * $attempt);

                    continue;
                }

                $error = $e->getMessage() !== '' ? $e->getMessage() : $e::class;

                foreach ($rows as $row) {
                    $failures = (int) $row['failures'];
                    $state = $failures >= (int) $row['max_failures'] ? self::STATE_FAILED : self::STATE_PENDING;
                    $delay = $state === self::STATE_PENDING ? self::BACKOFF_SECONDS[min($failures, \count(self::BACKOFF_SECONDS) - 1)] : 0;
                    $history = [...self::decodeHistory($row['history']), self::entry(
                        trigger: $trigger,
                        userId: $userId,
                        outcome: $state === self::STATE_PENDING ? 'retry' : $state,
                        error: $error,
                        more: $delay > 0 ? ['retry_in' => $delay] : [],
                    )];
                    self::settled(db: $db, row: $row, lease: $lease, state: $state, history: $history, error: $error, failures: $failures + 1, counts: $counts, delay: $delay);
                }

                self::log(entry: 'Tasks: the notices #' . implode(', #', $ids) . ' could not be written and are retried later: ' . $e::class . ': ' . $error, priority: Log::WARNING);

                return;
            }
        }
    }

    /**
     * The notice singles a run holds, running under its claim, in id order.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $lease The run's claim.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.1.4
     */
    private static function leasedNotices(DatabaseDriver $db, string $lease): array
    {
        $handler = NoticeTask::NAME;

        return $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'handler', 'payload', 'history', 'failures', 'max_failures', 'created_at', 'subject_type', 'subject_id']))
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('lease_owner') . ' = :lease')
                ->where($db->quoteName('handler') . ' = :handler')
                ->where($db->quoteName('state') . ' = ' . $db->quote(self::STATE_RUNNING))
                ->order($db->quoteName('id') . ' ASC')
                ->bind(':lease', $lease)
                ->bind(':handler', $handler),
        )->loadAssocList() ?: [];
    }

    /**
     * Take over every notice of keys still owed that another run holds or nobody does (this run holds the keys' locks),
     * each by its primary key; one that waits out its backoff is left to wait.
     *
     * @param DatabaseDriver $db The connection.
     * @param list<string> $keys The keys.
     * @param string $lease The run's claim.
     * @param string $trigger What runs it.
     *
     * @return array<string, string> The keys with a notice waiting out its backoff, each with when it is due.
     *
     * @since  2.1.4
     */
    private static function takeOverNotices(DatabaseDriver $db, array $keys, string $lease, string $trigger): array
    {
        $handler = NoticeTask::NAME;
        $owed = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'state', 'payload']))
                ->select('(' . $db->quoteName('run_after') . ' > UTC_TIMESTAMP(3)) AS ' . $db->quoteName('waits'))
                ->select($db->quoteName('run_after'))
                ->from($db->quoteName(self::TABLE) . ' FORCE INDEX (' . $db->quoteName('idx_handler_state') . ')')
                ->where($db->quoteName('handler') . ' = :handler')
                ->where($db->quoteName('state') . ' IN (' . $db->quote(self::STATE_PENDING) . ', ' . $db->quote(self::STATE_RUNNING) . ')')
                ->where('(' . $db->quoteName('lease_owner') . ' IS NULL OR ' . $db->quoteName('lease_owner') . ' <> :lease)')
                ->where('(' . implode(' OR ', array_map(static fn (string $key): string => $db->quoteName('payload') . ' LIKE ' . $db->quote(self::noticePattern(key: $key)), $keys)) . ')')
                ->order($db->quoteName('id') . ' ASC')
                ->bind(':handler', $handler)
                ->bind(':lease', $lease),
        )->loadAssocList() ?: [];
        $waits = [];

        foreach ($owed as $single) {
            $key = self::noticeKey(payload: $single['payload']);

            if (!\in_array($key, $keys, true)) {
                continue;
            }

            if ($single['state'] === self::STATE_PENDING && (int) $single['waits'] === 1) {
                $waits[$key] = max($waits[$key] ?? '', (string) $single['run_after']);

                continue;
            }

            self::claimNotice(db: $db, id: (int) $single['id'], lease: $lease, trigger: $trigger, from: '');
        }

        return $waits;
    }

    /**
     * Put notice singles back pending, no failure counted (another run writes their keys, or one of their keys waits out
     * its backoff): written by the next write of their keys, or by recovery.
     *
     * @param DatabaseDriver $db The connection.
     * @param list<array<string, mixed>> $rows The singles.
     * @param string $lease The run's claim on them.
     * @param string|null $runAfter Not before then; null keeps when they were due.
     *
     * @since  2.1.4
     */
    private static function releaseNotices(DatabaseDriver $db, array $rows, string $lease, ?string $runAfter = null): void
    {
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $query = $db->getQuery(true)
                ->update($db->quoteName(self::TABLE) . ' FORCE INDEX (PRIMARY)')
                ->set($db->quoteName('state') . ' = ' . $db->quote(self::STATE_PENDING))
                ->set($db->quoteName('lease_owner') . ' = NULL')
                ->set($db->quoteName('lease_until') . ' = NULL')
                ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
                ->where($db->quoteName('id') . ' = :id')
                ->where($db->quoteName('lease_owner') . ' = :lease')
                ->where($db->quoteName('state') . ' = ' . $db->quote(self::STATE_RUNNING))
                ->bind(':id', $id, ParameterType::INTEGER)
                ->bind(':lease', $lease);

            if ($runAfter !== null) {
                $query->set($db->quoteName('run_after') . ' = GREATEST(' . $db->quoteName('run_after') . ', :runAfter)')
                    ->bind(':runAfter', $runAfter);
            }

            self::changed(db: $db, query: $query);
        }
    }

    /**
     * Take the named lock of each notification key, in the order given (sorted, so two runs never wait on each other):
     * the locks held, or null when one was not had in time (every one taken is released then).
     *
     * @param DatabaseDriver $db The connection.
     * @param list<string> $keys The keys, sorted.
     *
     * @return list<string>|null
     *
     * @since  2.1.4
     */
    private static function lockNoticeKeys(DatabaseDriver $db, array $keys): ?array
    {
        try {
            $site = (string) Factory::getContainer()->get('config')->get('db');
        } catch (Throwable) {
            $site = '';
        }

        $held = [];

        foreach ($keys as $key) {
            $name = 'alfa.notice.' . substr(hash('sha256', $site . '|' . $db->getPrefix() . '|' . $key), 0, 40);

            try {
                $got = (int) $db->setQuery('SELECT GET_LOCK(' . $db->quote($name) . ', ' . self::NOTICE_LOCK_SECONDS . ')')->loadResult();
            } catch (Throwable) {
                $got = 0;
            }

            if ($got !== 1) {
                foreach ($held as $taken) {
                    try {
                        $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($taken) . ')')->loadResult();
                    } catch (Throwable) {
                        // A lock the connection lost goes with it.
                    }
                }

                return null;
            }

            $held[] = $name;
        }

        return $held;
    }

    /**
     * The notification key a notice single's payload names.
     *
     * @param mixed $payload The stored JSON.
     *
     * @since  2.1.4
     */
    private static function noticeKey(mixed $payload): string
    {
        $op = json_decode((string) $payload, true);

        return \is_array($op) ? (string) ($op['key'] ?? '') : '';
    }

    /**
     * Run the singles of one batch, in id order: what PostCommit runs after the outermost commit.
     *
     * The batch's pending singles are reserved first (this run's claim on them, their state unchanged), so recovery
     * leaves them to this run however long the earlier ones take; then each is claimed just before it runs, only under
     * that reservation. A request that dies leaves the ones it never started pending, their reservation lapses with its
     * claim, and recovery gives them their first run in id order. Nothing runs while a transaction or a named lock is
     * open on the connection.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $batch The batch.
     * @param string $trigger What runs it.
     *
     * @return array<string, int> What happened, by outcome.
     *
     * @since  2.1.4
     */
    public static function runBatch(DatabaseDriver $db, string $batch, string $trigger = 'request'): array
    {
        $counts = self::counts();

        if (TransactionState::isOpen($db) || PostCommit::inLock($db)) {
            self::log(entry: 'Tasks: batch ' . $batch . ' was asked to run inside a transaction or a named lock; it is left to recovery.', priority: Log::WARNING);

            return $counts;
        }

        $lease = bin2hex(random_bytes(16));

        if (self::reserve(db: $db, batch: $batch, lease: $lease, due: $db->quoteName('run_after') . ' <= UTC_TIMESTAMP(3)') > 0) {
            self::runReserved(db: $db, batch: $batch, lease: $lease, trigger: $trigger, userId: self::userId(), counts: $counts);
        }

        self::afterRun(counts: $counts);

        return $counts;
    }

    /**
     * Recovery: run the singles their request did not finish, oldest first, one at a time, within a wall-clock budget.
     *
     * Takes a single that never ran once it is older than the grace, a retry whose time has come, and a running one
     * whose claim passed: run again when its handler is idempotent, else held for staff. Never inside a transaction
     * or a named lock, and never in maintenance.
     *
     * @param int $budgetMs The budget in milliseconds; at least one task is taken when one is due.
     * @param string $trigger What runs it: bell, tools, runner or cli.
     *
     * @return array<string, int> What happened, by outcome.
     *
     * @since  2.1.4
     */
    public static function sweepSingles(int $budgetMs, string $trigger = 'bell'): array
    {
        $db = self::db();
        $counts = self::counts();

        if ($budgetMs < 1 || TransactionState::isOpen($db) || PostCommit::inLock($db) || MaintenanceState::active()) {
            return $counts;
        }

        $deadline = hrtime(true) + $budgetMs * 1000000;
        $userId = self::userId();

        while (hrtime(true) < $deadline) {
            $candidates = self::candidates(db: $db, limit: 10);
            $progress = false;

            foreach ($candidates as $candidate) {
                if ($progress && hrtime(true) >= $deadline) {
                    break 2;
                }

                if ($candidate['state'] === self::STATE_RUNNING) {
                    $progress = self::recoverInterrupted(db: $db, candidate: $candidate, trigger: $trigger, userId: $userId, counts: $counts, deadline: $deadline) || $progress;

                    continue;
                }

                // A stranded single: its batch's due singles are reserved together and run in id order, by this sweep only.
                $lease = bin2hex(random_bytes(16));
                $batch = (string) $candidate['batch'];

                if (self::reserve(db: $db, batch: $batch, lease: $lease, due: self::dueCondition(db: $db)) > 0) {
                    $progress = self::runReserved(db: $db, batch: $batch, lease: $lease, trigger: $trigger, userId: $userId, counts: $counts, deadline: $deadline) || $progress;
                }
            }

            if (!$progress) {
                break;
            }
        }

        self::afterRun(counts: $counts);

        return $counts;
    }

    /**
     * Whether recovery has a single to take now: one indexed read for each kind.
     *
     * @since  2.1.4
     */
    public static function due(): bool
    {
        $db = self::db();

        foreach (self::dueQueries(db: $db, select: '1') as $query) {
            if ($db->setQuery($query, 0, 1)->loadResult()) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many singles recovery would take now.
     *
     * @since  2.1.4
     */
    public static function dueCount(): int
    {
        $db = self::db();
        $count = 0;

        foreach (self::dueQueries(db: $db, select: 'COUNT(*)') as $query) {
            $count += (int) $db->setQuery($query)->loadResult();
        }

        return $count;
    }

    /**
     * How many singles wait for a retry whose time has not come.
     *
     * @since  2.1.4
     */
    public static function waitingCount(): int
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('type') . ' = ' . $db->quote(self::TYPE_SINGLE))
            ->where($db->quoteName('state') . ' = ' . $db->quote(self::STATE_PENDING))
            ->where($db->quoteName('run_after') . ' > UTC_TIMESTAMP(3)');

        return (int) $db->setQuery($query)->loadResult();
    }

    /**
     * The bell's recovery, at most every few minutes: what the bell's background refresh calls.
     *
     * @since  2.1.4
     */
    public static function recoverThrottled(): void
    {
        try {
            $cache = Factory::getContainer()
                ->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', [
                    'defaultgroup' => 'com_alfa.tasks',
                    'lifetime' => self::RECOVER_MINUTES,
                    'caching' => true,
                ]);

            $cache->get([self::class, 'recover'], [], 'recover');
        } catch (Throwable) {
            // Cache unavailable: just do it; correct, only less throttled.
            self::recover();
        }
    }

    /**
     * The uncached recovery behind {@see self::recoverThrottled()}: one due check, a short sweep when something is
     * due, then the attention notice brought up to date. A failure is logged and never reaches the page.
     *
     * @return bool Always true, for the cache callback.
     *
     * @since  2.1.4
     */
    public static function recover(): bool
    {
        try {
            if (MaintenanceState::active()) {
                return true;
            }

            if (self::due()) {
                self::sweepSingles(budgetMs: self::RECOVER_BUDGET_MS, trigger: 'bell');
            }

            TaskAttention::sync();
        } catch (Throwable $e) {
            self::log(entry: 'Tasks: the recovery from the bell failed: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
        }

        return true;
    }

    /**
     * Delete the tasks finished more than {@see self::KEEP_DAYS} days ago (done or resolved), by id, a batch at a
     * time. Failed and held tasks stay until staff act.
     *
     * @return int How many were deleted.
     *
     * @since  2.1.4
     */
    public static function prune(): int
    {
        $db = self::db();
        $deleted = 0;

        for ($round = 0; $round < self::PRUNE_ROUNDS; $round++) {
            $ids = array_map('intval', $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('id'))
                    ->from($db->quoteName(self::TABLE))
                    ->where($db->quoteName('finished_at') . ' < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL ' . self::KEEP_DAYS . ' DAY)')
                    ->whereIn($db->quoteName('state'), [self::STATE_DONE, self::STATE_RESOLVED], ParameterType::STRING)
                    ->order($db->quoteName('finished_at') . ' ASC'),
                0,
                self::PRUNE_BATCH,
            )->loadColumn() ?: []);

            if ($ids === []) {
                break;
            }

            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName(self::TABLE))
                    ->whereIn($db->quoteName('id'), $ids),
            )->execute();
            $deleted += \count($ids);

            if (\count($ids) < self::PRUNE_BATCH) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * {@see self::prune()} at most once an hour, from the bell's background refresh.
     *
     * @since  2.1.4
     */
    public static function pruneThrottled(): void
    {
        try {
            $cache = Factory::getContainer()
                ->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', [
                    'defaultgroup' => 'com_alfa.tasks',
                    'lifetime' => self::PRUNE_MINUTES,
                    'caching' => true,
                ]);

            $cache->get([self::class, 'pruneOnce'], [], 'prune');
        } catch (Throwable) {
            self::pruneOnce();
        }
    }

    /**
     * {@see self::prune()}, its failure logged: the cache callback of {@see self::pruneThrottled()}.
     *
     * @return bool Always true, for the cache callback.
     *
     * @since  2.1.4
     */
    public static function pruneOnce(): bool
    {
        try {
            if (!MaintenanceState::active()) {
                self::prune();
            }
        } catch (Throwable $e) {
            self::log(entry: 'Tasks: pruning finished tasks failed: ' . $e->getMessage(), priority: Log::WARNING);
        }

        return true;
    }

    /**
     * The handler of a name, or null when there is none.
     *
     * @param string $name The handler's name.
     *
     * @since  2.1.4
     */
    public static function handler(string $name): ?TaskHandler
    {
        if (isset(self::$handlers[$name])) {
            return self::$handlers[$name];
        }

        $class = self::coreHandlers()[$name] ?? null;

        if ($class === null) {
            return null;
        }

        return self::$handlers[$name] = new $class();
    }

    /**
     * Core's handlers, by name: the work core's own changes owe after their commit.
     *
     * @return array<string, class-string<TaskHandler>>
     *
     * @since  2.1.4
     */
    private static function coreHandlers(): array
    {
        return [
            NoticeTask::NAME => NoticeTask::class,
            OrderStock::NAME => OrderStock::class,
            OrderEntitlements::NAME => OrderEntitlements::class,
            OrderDocuments::NAME => OrderDocuments::class,
            OrderStatusEmail::NAME => OrderStatusEmail::class,
            OrderPlacementEmail::NAME => OrderPlacementEmail::class,
            OrderStatusEvent::NAME => OrderStatusEvent::class,
        ];
    }

    /**
     * One history entry.
     *
     * @param string $trigger What ran it or acted on it.
     * @param int $userId Who, 0 for nobody in particular.
     * @param string $outcome What happened.
     * @param string|null $unit The unit it is about, null for the task as a whole.
     * @param string $error What failed.
     * @param array<string, mixed> $more Further facts (the task resent, the reason).
     *
     * @return array<string, mixed>
     *
     * @since  2.1.4
     */
    public static function entry(string $trigger, int $userId, string $outcome, ?string $unit = null, string $error = '', array $more = []): array
    {
        $entry = [
            'at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
            'trigger' => $trigger,
            'user' => $userId,
            'outcome' => $outcome,
        ];

        if ($unit !== null) {
            $entry['unit'] = $unit;
        }

        if ($error !== '') {
            $entry['error'] = mb_substr($error, 0, 500);
        }

        return $entry + $more;
    }

    /**
     * A history as stored.
     *
     * @param list<array<string, mixed>> $history The entries.
     *
     * @since  2.1.4
     */
    public static function encodeHistory(array $history): string
    {
        return json_encode(array_values($history), self::JSON);
    }

    /**
     * A history as read.
     *
     * @param mixed $stored The column's value.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.1.4
     */
    public static function decodeHistory(mixed $stored): array
    {
        $history = json_decode((string) $stored, true);

        return \is_array($history) ? array_values(array_filter($history, 'is_array')) : [];
    }

    /**
     * The units recorded done by a single and by every task it resends or runs again, back to the first: what a new
     * resend or run again must not repeat, carried into its own history so no prune of an earlier task can lose it.
     *
     * @param array<string, mixed> $row The single (its id, payload and history).
     *
     * @return list<string> The units, each once.
     *
     * @since  2.1.4
     */
    public static function doneUnits(array $row): array
    {
        $done = [];

        foreach ([...self::decodeHistory($row['history'] ?? null), ...self::earlier(db: self::db(), row: $row)] as $entry) {
            if (isset($entry['unit']) && ($entry['outcome'] ?? '') === TaskRun::DONE) {
                $done[(string) $entry['unit']] = true;
            }
        }

        return array_keys($done);
    }

    /**
     * The SQL that ends a claim taken now.
     *
     * @since  2.1.4
     */
    public static function leaseEnd(): string
    {
        return 'DATE_ADD(UTC_TIMESTAMP(3), INTERVAL ' . self::LEASE_SECONDS . ' SECOND)';
    }

    /**
     * Run one write on the tasks table and answer how many rows it changed. Outside a transaction the server undid
     * that statement only when it chose it for a deadlock or its lock wait timed out, so it is run again, up to
     * {@see self::WRITE_ATTEMPTS} times in all; inside one the failure reaches the caller, since the whole transaction
     * may be gone.
     *
     * @param DatabaseDriver $db The connection.
     * @param QueryInterface $query The write.
     *
     * @return int The affected rows.
     *
     * @since  2.1.4
     */
    public static function changed(DatabaseDriver $db, QueryInterface $query): int
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $db->setQuery($query)->execute();

                return $db->getAffectedRows();
            } catch (Throwable $e) {
                if ($attempt >= self::WRITE_ATTEMPTS || TransactionState::isOpen($db)
                    || !\in_array(needle: MysqlError::number(error: $e), haystack: [1205, 1213], strict: true)) {
                    throw $e;
                }

                usleep(random_int(5000, 30000) * $attempt);
            }
        }
    }

    /**
     * The id of the user this request runs for, 0 when none.
     *
     * @since  2.1.4
     */
    public static function userId(): int
    {
        try {
            return (int) (Factory::getApplication()->getIdentity()->id ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Write one line of the tasks log; a log that cannot be written changes nothing.
     *
     * @param string $entry The line.
     * @param int $priority The Log priority.
     *
     * @since  2.1.4
     */
    public static function log(string $entry, int $priority): void
    {
        try {
            AlfaLog::register();
            Log::add(entry: $entry, priority: $priority, category: 'com_alfa.tasks');
        } catch (Throwable) {
            // An unwritable log changes nothing that already happened.
        }
    }

    /**
     * The batch of the connection's open transaction: the one whose run still waits, or a new one.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @return array{0: string, 1: bool} The token, and whether its run already waits.
     *
     * @since  2.1.4
     */
    private static function batch(DatabaseDriver $db): array
    {
        $batches = self::$batches ??= new WeakMap();
        $token = $batches[$db] ?? null;

        if ($token !== null) {
            $run = PostCommit::waiting(db: $db, label: self::batchLabel(token: $token));

            // Its run, or a notice of it (a transaction whose first single was a notice), still waits for the commit.
            if ($run || PostCommit::waiting(db: $db, label: self::noticeLabel(token: $token))) {
                return [$token, $run];
            }
        }

        $token = bin2hex(random_bytes(16));
        $batches[$db] = $token;

        return [$token, false];
    }

    /**
     * The label a batch's run waits under.
     *
     * @param string $token The batch.
     *
     * @since  2.1.4
     */
    private static function batchLabel(string $token): string
    {
        return 'Tasks: run batch ' . $token;
    }

    /**
     * The label a batch's notices wait under in the notice journal.
     *
     * @param string $token The batch.
     *
     * @since  2.1.4
     */
    private static function noticeLabel(string $token): string
    {
        return 'Tasks: notice of batch ' . $token;
    }

    /**
     * Reserve a batch's due pending singles for one run: its claim on them, their state unchanged. A single another
     * run reserved and whose reservation is still live is left to that run.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $batch The batch.
     * @param string $lease The run's claim.
     * @param string $due The SQL condition that makes a single due.
     *
     * @return int How many this run reserved.
     *
     * @since  2.1.4
     */
    private static function reserve(DatabaseDriver $db, string $batch, string $lease, string $due): int
    {
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::TABLE))
            ->set($db->quoteName('lease_owner') . ' = :lease')
            ->set($db->quoteName('lease_until') . ' = ' . self::leaseEnd())
            ->where($db->quoteName('batch') . ' = :batch')
            ->where($db->quoteName('type') . ' = ' . $db->quote(self::TYPE_SINGLE))
            ->where($db->quoteName('state') . ' = ' . $db->quote(self::STATE_PENDING))
            ->where($due)
            ->where(self::unreserved(db: $db))
            ->bind(':lease', $lease)
            ->bind(':batch', $batch);
        self::onlyRunnable(db: $db, query: $query);

        return self::changed(db: $db, query: $query);
    }

    /**
     * Run a batch's singles this run reserved, in id order: each claimed by id under the reservation just before it
     * runs, the reservation of the rest renewed at each claim; what is left at the end (a budget that ran out) is
     * released for the next run.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $batch The batch.
     * @param string $lease The run's claim.
     * @param string $trigger What runs it.
     * @param int $userId Who.
     * @param array<string, int> $counts The tally.
     * @param int|null $deadline hrtime() past which no further single is started once one ran; null for none.
     *
     * @return bool Whether one ran.
     *
     * @since  2.1.4
     */
    private static function runReserved(DatabaseDriver $db, string $batch, string $lease, string $trigger, int $userId, array &$counts, ?int $deadline = null): bool
    {
        $ran = false;

        try {
            $reserved = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'handler', 'subject_type', 'subject_id']))
                    ->from($db->quoteName(self::TABLE))
                    ->where($db->quoteName('batch') . ' = :batch')
                    ->where($db->quoteName('lease_owner') . ' = :lease')
                    ->where($db->quoteName('type') . ' = ' . $db->quote(self::TYPE_SINGLE))
                    ->where($db->quoteName('state') . ' = ' . $db->quote(self::STATE_PENDING))
                    ->order($db->quoteName('id') . ' ASC')
                    ->bind(':batch', $batch)
                    ->bind(':lease', $lease),
            )->loadAssocList() ?: [];

            if (\in_array(NoticeTask::NAME, array_column($reserved, 'handler'), true)) {
                // The batch's notices are written first and together: the work after them reads what its transaction
                // raised.
                $claimed = 0;

                foreach ($reserved as $single) {
                    if ($single['handler'] === NoticeTask::NAME) {
                        $claimed += self::claimNotice(db: $db, id: (int) $single['id'], lease: $lease, trigger: $trigger, from: $lease);
                    }
                }

                if ($claimed > 0) {
                    $ran = true;
                    $counts['claimed'] += $claimed;
                    self::applyNotices(db: $db, lease: $lease, trigger: $trigger, userId: $userId, counts: $counts);
                }

                $reserved = array_values(array_filter($reserved, static fn (array $single): bool => $single['handler'] !== NoticeTask::NAME));
            }

            foreach ($reserved as $single) {
                if ($ran && $deadline !== null && hrtime(true) >= $deadline) {
                    break;
                }

                // An order's e-mail waits while an earlier one of its order is pending or running: it stays pending,
                // and recovery runs it after that one.
                if (self::waitsInSequence(db: $db, single: $single)) {
                    continue;
                }

                $row = self::claimReserved(db: $db, id: (int) $single['id'], lease: $lease, trigger: $trigger);

                if ($row === null) {
                    continue;
                }

                $ran = true;
                $counts['claimed']++;
                self::renewReservation(db: $db, batch: $batch, lease: $lease);
                self::runClaimed(db: $db, row: $row, lease: $lease, trigger: $trigger, userId: $userId, counts: $counts);
            }
        } finally {
            // Whatever ended the run, what it did not start is released for the next run at once.
            self::release(db: $db, batch: $batch, lease: $lease);
        }

        return $ran;
    }

    /**
     * Release what a run still reserves of its batch. A release that fails is logged and never replaces the run's
     * own answer or failure: the reservation then lapses with its claim.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $batch The batch.
     * @param string $lease The run's claim.
     *
     * @since  2.1.4
     */
    private static function release(DatabaseDriver $db, string $batch, string $lease): void
    {
        try {
            self::changed(
                db: $db,
                query: $db->getQuery(true)
                    ->update($db->quoteName(self::TABLE))
                    ->set($db->quoteName('lease_owner') . ' = NULL')
                    ->set($db->quoteName('lease_until') . ' = NULL')
                    ->where($db->quoteName('batch') . ' = :batch')
                    ->where($db->quoteName('lease_owner') . ' = :lease')
                    ->where($db->quoteName('state') . ' = ' . $db->quote(self::STATE_PENDING))
                    ->bind(':batch', $batch)
                    ->bind(':lease', $lease),
            );
        } catch (Throwable $e) {
            self::log(entry: 'Tasks: batch ' . $batch . ' stays reserved until its claim lapses (' . self::LEASE_SECONDS . ' s), its release failed: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
        }
    }

    /**
     * Claim one reserved single by id: only under this run's reservation, and only while it is pending. The
     * affected row count is the answer.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $id The task.
     * @param string $lease The run's claim.
     * @param string $trigger What claims it.
     *
     * @return array<string, mixed>|null The claimed row, or null when it is not this run's to claim.
     *
     * @since  2.1.4
     */
    private static function claimReserved(DatabaseDriver $db, int $id, string $lease, string $trigger): ?array
    {
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::TABLE))
            ->set($db->quoteName('state') . ' = ' . $db->quote(self::STATE_RUNNING))
            ->set($db->quoteName('lease_until') . ' = ' . self::leaseEnd())
            ->set($db->quoteName('claims') . ' = ' . $db->quoteName('claims') . ' + 1')
            ->set($db->quoteName('last_trigger') . ' = :trigger')
            ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('type') . ' = ' . $db->quote(self::TYPE_SINGLE))
            ->where($db->quoteName('state') . ' = ' . $db->quote(self::STATE_PENDING))
            ->where($db->quoteName('lease_owner') . ' = :lease')
            ->bind(':trigger', $trigger)
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':lease', $lease);

        if (self::changed(db: $db, query: $query) !== 1) {
            return null;
        }

        return self::owned(db: $db, id: $id, lease: $lease);
    }

    /**
     * Whether a single waits for an earlier one of its sequence ({@see TaskPolicy::$sequence}): one of a handler in
     * the same sequence, about the same subject, with a lower id, still pending or running.
     *
     * @param DatabaseDriver $db The connection.
     * @param array<string, mixed> $single Its id, handler, subject_type and subject_id.
     *
     * @since  2.1.4
     */
    private static function waitsInSequence(DatabaseDriver $db, array $single): bool
    {
        $sequence = self::handler(name: (string) $single['handler'])?->policy()->sequence ?? '';

        if ($sequence === '' || (string) $single['subject_type'] === '') {
            return false;
        }

        $handlers = [];

        foreach (array_unique([...array_keys(self::coreHandlers()), ...array_keys(self::$handlers)]) as $name) {
            if ((self::handler(name: $name)?->policy()->sequence ?? '') === $sequence) {
                $handlers[] = $name;
            }
        }

        $type = (string) $single['subject_type'];
        $subject = (int) $single['subject_id'];
        $id = (int) $single['id'];

        return (bool) $db->setQuery(
            $db->getQuery(true)
                ->select('1')
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('subject_type') . ' = :type')
                ->where($db->quoteName('subject_id') . ' = :subject')
                ->where($db->quoteName('id') . ' < :id')
                ->whereIn($db->quoteName('handler'), $handlers, ParameterType::STRING)
                ->whereIn($db->quoteName('state'), [self::STATE_PENDING, self::STATE_RUNNING], ParameterType::STRING)
                ->bind(':type', $type)
                ->bind(':subject', $subject, ParameterType::INTEGER)
                ->bind(':id', $id, ParameterType::INTEGER),
            0,
            1,
        )->loadResult();
    }

    /**
     * Tell a task's handler how the task ended or what staff did with it, when it listens
     * ({@see TaskSettleListener}). What the handler does never changes the task: a failure is logged; inside a
     * transaction one that ended the transaction reaches the caller, whose act is then undone with it.
     *
     * @param array<string, mixed> $row The task (id, handler, subject_type, subject_id, payload).
     * @param string $event What happened: a TaskSettlement constant.
     * @param string $state The state the task is in now.
     * @param list<array<string, mixed>> $history Its history.
     * @param string $error The last error.
     * @param int $userId Who acted, 0 for nobody.
     * @param int $newTaskId The task a resend or a run again made.
     * @param string $reason Why staff resolved it.
     *
     * @since  2.1.4
     */
    public static function notifySettled(array $row, string $event, string $state, array $history, string $error = '', int $userId = 0, int $newTaskId = 0, string $reason = ''): void
    {
        $handler = self::handler(name: (string) $row['handler']);

        if (!$handler instanceof TaskSettleListener) {
            return;
        }

        $payload = json_decode((string) ($row['payload'] ?? ''), true);

        try {
            $handler->settled(new TaskSettlement(
                taskId: (int) $row['id'],
                handler: (string) $row['handler'],
                subjectType: (string) ($row['subject_type'] ?? ''),
                subjectId: (int) ($row['subject_id'] ?? 0),
                payload: \is_array($payload) ? $payload : [],
                event: $event,
                state: $state,
                error: $error,
                userId: $userId,
                newTaskId: $newTaskId,
                reason: $reason,
                units: self::unitOutcomes(history: $history),
            ));
        } catch (Throwable $e) {
            if (TransactionState::endedBy(db: self::db(), failure: $e)) {
                throw $e;
            }

            self::log(entry: 'Task #' . (int) $row['id'] . ' (' . $row['handler'] . '): its handler could not record how it ended (' . $event . ', ' . $state . '): ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
        }
    }

    /**
     * The units of a history by their last outcome.
     *
     * @param list<array<string, mixed>> $history The history.
     *
     * @return array<string, list<string>> Outcome => units (done, failed, unknown).
     *
     * @since  2.1.4
     */
    public static function unitOutcomes(array $history): array
    {
        $last = [];

        foreach ($history as $entry) {
            if (isset($entry['unit']) && \in_array($entry['outcome'] ?? '', [TaskRun::DONE, TaskRun::FAILED, TaskRun::UNKNOWN], true)) {
                $last[(string) $entry['unit']] = (string) $entry['outcome'];
            }
        }

        $units = [];

        foreach ($last as $unit => $outcome) {
            $units[$outcome][] = (string) $unit;
        }

        return $units;
    }

    /**
     * Renew a run's reservation of its batch's singles not started yet: at each claim, and at each unit a handler
     * records ({@see TaskRun::unit()}), so a long run keeps the rest of its batch.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $batch The batch.
     * @param string $lease The run's claim.
     *
     * @since  2.1.4
     */
    public static function renewReservation(DatabaseDriver $db, string $batch, string $lease): void
    {
        if ($batch === '') {
            return;
        }

        try {
            self::changed(
                db: $db,
                query: $db->getQuery(true)
                    ->update($db->quoteName(self::TABLE))
                    ->set($db->quoteName('lease_until') . ' = ' . self::leaseEnd())
                    ->where($db->quoteName('batch') . ' = :batch')
                    ->where($db->quoteName('lease_owner') . ' = :lease')
                    ->where($db->quoteName('state') . ' = ' . $db->quote(self::STATE_PENDING))
                    ->bind(':batch', $batch)
                    ->bind(':lease', $lease),
            );
        } catch (Throwable $e) {
            // The work it renews for is unaffected: the reservation only keeps its last end, and lapses then.
            self::log(entry: 'Tasks: the reservation of batch ' . $batch . ' could not be renewed: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
        }
    }

    /**
     * A pending single no run holds: never reserved, or its reservation lapsed (the run that made it stopped).
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.1.4
     */
    private static function unreserved(DatabaseDriver $db): string
    {
        return '(' . $db->quoteName('lease_owner') . ' IS NULL OR ' . $db->quoteName('lease_until') . ' < UTC_TIMESTAMP(3))';
    }

    /**
     * The row a claim owns.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $id The task.
     * @param string $lease The claim.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the row is not there under that claim.
     *
     * @since  2.1.4
     */
    private static function owned(DatabaseDriver $db, int $id, string $lease): array
    {
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('id') . ' = :id')
                ->where($db->quoteName('lease_owner') . ' = :lease')
                ->bind(':id', $id, ParameterType::INTEGER)
                ->bind(':lease', $lease),
        )->loadAssoc();

        if (!\is_array($row)) {
            throw new RuntimeException('Task #' . $id . ' was claimed but cannot be read back.');
        }

        return $row;
    }

    /**
     * Run a claimed single and settle it with what happened.
     *
     * @param DatabaseDriver $db The connection.
     * @param array<string, mixed> $row The task as claimed.
     * @param string $lease The claim.
     * @param string $trigger What runs it.
     * @param int $userId Who.
     * @param array<string, int> $counts The tally, by outcome.
     *
     * @since  2.1.4
     */
    private static function runClaimed(DatabaseDriver $db, array $row, string $lease, string $trigger, int $userId, array &$counts): void
    {
        $id = (int) $row['id'];

        if ((string) $row['handler'] === NoticeTask::NAME) {
            // A notice is written with the older notices of its key, in the transaction that deletes their singles.
            self::applyNotices(db: $db, lease: $lease, trigger: $trigger, userId: $userId, counts: $counts);

            return;
        }

        $handler = self::handler(name: (string) $row['handler']);

        if ($handler === null) {
            $error = 'No task handler is named ' . $row['handler'] . ' in this version.';
            $history = [...self::decodeHistory($row['history']), self::entry(trigger: $trigger, userId: $userId, outcome: self::STATE_FAILED, error: $error)];
            self::settle(db: $db, id: $id, lease: $lease, state: self::STATE_FAILED, history: $history, error: $error, failures: (int) $row['failures']);
            $counts['failed']++;
            self::log(entry: 'Task #' . $id . ': ' . $error, priority: Log::ERROR);

            return;
        }

        $policy = $handler->policy();
        $run = new TaskRun(
            db: $db,
            row: $row,
            lease: $lease,
            trigger: $trigger,
            userId: $userId,
            history: self::decodeHistory($row['history']),
            earlier: self::earlier(db: $db, row: $row),
        );
        $failure = null;

        try {
            $handler->run($run);
        } catch (Throwable $e) {
            $failure = $e;
        }

        if (TransactionState::level($db) > 0) {
            // Nothing is recorded inside a transaction the handler left open: it is rolled back first.
            $left = new RuntimeException('The task left a transaction open; it was rolled back.');

            for ($guard = TransactionState::level($db); $guard > 0; $guard--) {
                if (TransactionState::rollBackLevel(db: $db, failure: $failure ?? $left)) {
                    break;
                }
            }

            $failure ??= $left;
        }

        if ($failure instanceof TaskFailure && $failure->kind === TaskFailure::LOST) {
            $counts['lost']++;
            self::log(entry: 'Task #' . $id . ': another worker took it over; this run records nothing more.', priority: Log::WARNING);

            return;
        }

        $history = $run->history();
        $failures = (int) $row['failures'];

        if ($failure === null) {
            $history[] = self::entry(trigger: $trigger, userId: $userId, outcome: self::STATE_DONE);
            self::settled(db: $db, row: $row, lease: $lease, state: self::STATE_DONE, history: $history, error: '', failures: 0, counts: $counts);

            return;
        }

        $error = $failure->getMessage() !== '' ? $failure->getMessage() : $failure::class;
        // A failure the handler did not classify: safe to retry only when its work is idempotent; for any other the
        // work may have taken effect before it threw, so the outcome is unknown (s55).
        $kind = $failure instanceof TaskFailure ? $failure->kind : ($policy->idempotent ? TaskFailure::RETRY : TaskFailure::UNKNOWN);

        if ($kind === TaskFailure::UNKNOWN || ($kind === TaskFailure::RETRY && $run->hasUnknownUnit())) {
            $state = self::STATE_HELD;
        } elseif ($kind === TaskFailure::FAILED || !$policy->retries || $failures >= (int) $row['max_failures']) {
            $state = self::STATE_FAILED;
        } else {
            $state = self::STATE_PENDING;
        }

        $delay = $state === self::STATE_PENDING ? self::BACKOFF_SECONDS[min($failures, \count(self::BACKOFF_SECONDS) - 1)] : 0;
        $history[] = self::entry(
            trigger: $trigger,
            userId: $userId,
            outcome: $state === self::STATE_PENDING ? 'retry' : $state,
            error: $error,
            more: $delay > 0 ? ['retry_in' => $delay] : [],
        );
        self::settled(db: $db, row: $row, lease: $lease, state: $state, history: $history, error: $error, failures: $failures + 1, counts: $counts, delay: $delay);
        self::log(
            entry: 'Task #' . $id . ' (' . $row['handler'] . ') ' . match ($state) {
                self::STATE_PENDING => 'failed and is retried in ' . $delay . ' s',
                self::STATE_HELD => 'is held: its outcome is unknown, staff decide',
                default => 'failed and is not retried automatically',
            } . ': ' . $failure::class . ': ' . $error,
            priority: $state === self::STATE_PENDING ? Log::WARNING : Log::ERROR,
        );
    }

    /**
     * Settle a run and count its outcome, then tell its handler; a run whose claim was taken over counts as lost.
     *
     * @param DatabaseDriver $db The connection.
     * @param array<string, mixed> $row The task.
     * @param string $lease The claim.
     * @param string $state The state it ends in.
     * @param list<array<string, mixed>> $history Its history.
     * @param string $error The last error.
     * @param int $failures Its consecutive known failures.
     * @param array<string, int> $counts The tally.
     * @param int $delay Seconds before a retry.
     *
     * @since  2.1.4
     */
    private static function settled(DatabaseDriver $db, array $row, string $lease, string $state, array $history, string $error, int $failures, array &$counts, int $delay = 0): void
    {
        $id = (int) $row['id'];

        if (!self::settle(db: $db, id: $id, lease: $lease, state: $state, history: $history, error: $error, failures: $failures, delay: $delay)) {
            $counts['lost']++;
            self::log(entry: 'Task #' . $id . ': its claim was taken over before its outcome (' . $state . ') could be recorded.', priority: Log::WARNING);

            return;
        }

        $counts[match ($state) {
            self::STATE_DONE => 'done',
            self::STATE_PENDING => 'retried',
            self::STATE_HELD => 'held',
            default => 'failed',
        }]++;
        self::notifySettled(row: $row, event: TaskSettlement::RUN, state: $state, history: $history, error: $error);
    }

    /**
     * Record how a run ended, fenced by its claim.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $id The task.
     * @param string $lease The claim.
     * @param string $state The state it ends in.
     * @param list<array<string, mixed>> $history Its history.
     * @param string $error The last error.
     * @param int $failures Its consecutive known failures.
     * @param int $delay Seconds before a retry, for pending.
     *
     * @return bool False when the claim was no longer this run's.
     *
     * @since  2.1.4
     */
    private static function settle(DatabaseDriver $db, int $id, string $lease, string $state, array $history, string $error, int $failures, int $delay = 0): bool
    {
        $json = self::encodeHistory($history);
        $error = mb_substr($error, 0, 1000);
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::TABLE))
            ->set($db->quoteName('state') . ' = :state')
            ->set($db->quoteName('history') . ' = :history')
            ->set($db->quoteName('last_error') . ' = :error')
            ->set($db->quoteName('failures') . ' = :failures')
            ->set($db->quoteName('lease_owner') . ' = NULL')
            ->set($db->quoteName('lease_until') . ' = NULL')
            ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('lease_owner') . ' = :lease')
            ->where($db->quoteName('state') . ' = ' . $db->quote(self::STATE_RUNNING))
            ->bind(':state', $state)
            ->bind(':history', $json)
            ->bind(':error', $error)
            ->bind(':failures', $failures, ParameterType::INTEGER)
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':lease', $lease);

        if ($state === self::STATE_PENDING) {
            $query->set($db->quoteName('run_after') . ' = DATE_ADD(UTC_TIMESTAMP(3), INTERVAL ' . max(0, $delay) . ' SECOND)');
        } else {
            $query->set($db->quoteName('finished_at') . ' = UTC_TIMESTAMP(3)');
        }

        return self::changed(db: $db, query: $query) === 1;
    }

    /**
     * A running single whose claim passed: its run stopped before its outcome was recorded. An idempotent handler
     * runs it again, the rest of its batch after it; any other is held for staff.
     *
     * @param DatabaseDriver $db The connection.
     * @param array<string, mixed> $candidate The task as read.
     * @param string $trigger What recovers it.
     * @param int $userId Who.
     * @param array<string, int> $counts The tally.
     * @param int|null $deadline hrtime() past which the rest of its batch is left for the next run.
     *
     * @return bool Whether this worker took it.
     *
     * @since  2.1.4
     */
    private static function recoverInterrupted(DatabaseDriver $db, array $candidate, string $trigger, int $userId, array &$counts, ?int $deadline = null): bool
    {
        if ((string) $candidate['handler'] === NoticeTask::NAME) {
            return self::recoverNotices(db: $db, candidate: $candidate, trigger: $trigger, userId: $userId, counts: $counts, deadline: $deadline);
        }

        $id = (int) $candidate['id'];
        $old = (string) $candidate['lease_owner'];
        $policy = self::handler(name: (string) $candidate['handler'])?->policy();
        $again = $policy !== null && $policy->idempotent;

        if ($again && $policy->needsApplication && !self::hasApplication()) {
            return false;
        }

        $error = $again
            ? 'The run stopped before its outcome was recorded; its work is safe to repeat, so it runs again.'
            : 'The run stopped before its outcome was recorded, so whether it took effect is unknown; it is held for staff.';
        $history = self::encodeHistory([...self::decodeHistory($candidate['history']), self::entry(trigger: $trigger, userId: $userId, outcome: 'interrupted', error: $error)]);
        $lease = bin2hex(random_bytes(16));
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::TABLE))
            ->set($db->quoteName('history') . ' = :history')
            ->set($db->quoteName('last_trigger') . ' = :trigger')
            ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('type') . ' = ' . $db->quote(self::TYPE_SINGLE))
            ->where($db->quoteName('state') . ' = ' . $db->quote(self::STATE_RUNNING))
            ->where($db->quoteName('lease_owner') . ' = :old')
            ->where($db->quoteName('lease_until') . ' < UTC_TIMESTAMP(3)')
            ->bind(':history', $history)
            ->bind(':trigger', $trigger)
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':old', $old);

        if ($again) {
            $query->set($db->quoteName('lease_owner') . ' = :lease')
                ->set($db->quoteName('lease_until') . ' = ' . self::leaseEnd())
                ->set($db->quoteName('claims') . ' = ' . $db->quoteName('claims') . ' + 1')
                ->bind(':lease', $lease);
        } else {
            $state = self::STATE_HELD;
            $last = mb_substr($error, 0, 1000);
            $query->set($db->quoteName('state') . ' = :state')
                ->set($db->quoteName('last_error') . ' = :error')
                ->set($db->quoteName('lease_owner') . ' = NULL')
                ->set($db->quoteName('lease_until') . ' = NULL')
                ->set($db->quoteName('finished_at') . ' = UTC_TIMESTAMP(3)')
                ->bind(':state', $state)
                ->bind(':error', $last);
        }

        if (self::changed(db: $db, query: $query) !== 1) {
            return false;
        }

        if (!$again) {
            $counts['held']++;
            self::log(entry: 'Task #' . $id . ' (' . $candidate['handler'] . ') is held: ' . $error, priority: Log::ERROR);
            self::notifySettled(row: $candidate, event: TaskSettlement::INTERRUPTED, state: self::STATE_HELD, history: self::decodeHistory($history), error: $error);

            return true;
        }

        // The rest of its batch waits for it: reserved under the same claim, run after it in id order.
        $batch = (string) $candidate['batch'];
        self::reserve(db: $db, batch: $batch, lease: $lease, due: self::dueCondition(db: $db));
        $counts['claimed']++;

        try {
            self::runClaimed(db: $db, row: self::owned(db: $db, id: $id, lease: $lease), lease: $lease, trigger: $trigger, userId: $userId, counts: $counts);
        } catch (Throwable $e) {
            self::release(db: $db, batch: $batch, lease: $lease);

            throw $e;
        }

        self::runReserved(db: $db, batch: $batch, lease: $lease, trigger: $trigger, userId: $userId, counts: $counts, deadline: $deadline);

        return true;
    }

    /**
     * Notices whose run stopped before they were written. Writing them is one transaction that deletes them, so none of
     * their batch was written: they are put back pending, the batch's together, and written by the batch's next run,
     * here, so each key's notices are still written together.
     *
     * @param DatabaseDriver $db The connection.
     * @param array<string, mixed> $candidate One of them, as read.
     * @param string $trigger What recovers them.
     * @param int $userId Who.
     * @param array<string, int> $counts The tally.
     * @param int|null $deadline hrtime() past which the rest of the batch is left for the next run.
     *
     * @return bool Whether this worker took them.
     *
     * @since  2.1.4
     */
    private static function recoverNotices(DatabaseDriver $db, array $candidate, string $trigger, int $userId, array &$counts, ?int $deadline = null): bool
    {
        $batch = (string) $candidate['batch'];
        $handler = NoticeTask::NAME;
        $back = self::changed(
            db: $db,
            query: $db->getQuery(true)
                ->update($db->quoteName(self::TABLE))
                ->set($db->quoteName('state') . ' = ' . $db->quote(self::STATE_PENDING))
                ->set($db->quoteName('lease_owner') . ' = NULL')
                ->set($db->quoteName('lease_until') . ' = NULL')
                ->set($db->quoteName('last_trigger') . ' = :trigger')
                ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
                ->where($db->quoteName('batch') . ' = :batch')
                ->where($db->quoteName('type') . ' = ' . $db->quote(self::TYPE_SINGLE))
                ->where($db->quoteName('handler') . ' = :handler')
                ->where($db->quoteName('state') . ' = ' . $db->quote(self::STATE_RUNNING))
                ->where($db->quoteName('lease_until') . ' < UTC_TIMESTAMP(3)')
                ->bind(':trigger', $trigger)
                ->bind(':batch', $batch)
                ->bind(':handler', $handler),
        );

        if ($back < 1) {
            return false;
        }

        $lease = bin2hex(random_bytes(16));

        if (self::reserve(db: $db, batch: $batch, lease: $lease, due: self::dueCondition(db: $db)) > 0) {
            self::runReserved(db: $db, batch: $batch, lease: $lease, trigger: $trigger, userId: $userId, counts: $counts, deadline: $deadline);
        }

        return true;
    }

    /**
     * Singles recovery may take: due pending ones and running ones whose claim passed, oldest first.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $limit Most of each kind.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.1.4
     */
    private static function candidates(DatabaseDriver $db, int $limit): array
    {
        $rows = [];

        foreach (self::dueQueries(db: $db, select: implode(', ', $db->quoteName(['id', 'handler', 'state', 'batch', 'lease_owner', 'history', 'subject_type', 'subject_id', 'payload']))) as $kind => $query) {
            $query->order($db->quoteName($kind === 'pending' ? 'run_after' : 'lease_until') . ' ASC, ' . $db->quoteName('id') . ' ASC');

            foreach ($db->setQuery($query, 0, $limit)->loadAssocList() ?: [] as $row) {
                $rows[(int) $row['id']] = $row;
            }
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * The two reads of what recovery may take: due pending singles no live run holds, and running ones whose claim
     * passed.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $select The select list.
     *
     * @return array{pending: \Joomla\Database\QueryInterface, running: \Joomla\Database\QueryInterface}
     *
     * @since  2.1.4
     */
    private static function dueQueries(DatabaseDriver $db, string $select): array
    {
        $pending = $db->getQuery(true)
            ->select($select)
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('type') . ' = ' . $db->quote(self::TYPE_SINGLE))
            ->where($db->quoteName('state') . ' = ' . $db->quote(self::STATE_PENDING))
            ->where(self::dueCondition(db: $db))
            ->where(self::unreserved(db: $db));
        self::onlyRunnable(db: $db, query: $pending);

        $running = $db->getQuery(true)
            ->select($select)
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('type') . ' = ' . $db->quote(self::TYPE_SINGLE))
            ->where($db->quoteName('state') . ' = ' . $db->quote(self::STATE_RUNNING))
            ->where($db->quoteName('lease_until') . ' < UTC_TIMESTAMP(3)');

        return ['pending' => $pending, 'running' => $running];
    }

    /**
     * When a pending single is due for recovery: a retry once its time has come; one that never ran once it is older
     * than the grace, so its own request runs it first.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.1.4
     */
    private static function dueCondition(DatabaseDriver $db): string
    {
        return $db->quoteName('run_after') . ' <= UTC_TIMESTAMP(3) AND (' . $db->quoteName('failures') . ' > 0 OR '
            . $db->quoteName('run_after') . ' <= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL ' . self::GRACE_SECONDS . ' SECOND))';
    }

    /**
     * Leave out, in a process with no application, the singles whose handler needs one: they stay pending, unclaimed.
     *
     * @param DatabaseDriver $db The connection.
     * @param \Joomla\Database\QueryInterface $query The read.
     *
     * @since  2.1.4
     */
    private static function onlyRunnable(DatabaseDriver $db, $query): void
    {
        if (self::hasApplication()) {
            return;
        }

        $needing = [];

        foreach (array_unique([...array_keys(self::coreHandlers()), ...array_keys(self::$handlers)]) as $name) {
            if (self::handler(name: $name)?->policy()->needsApplication) {
                $needing[] = $name;
            }
        }

        if ($needing !== []) {
            $query->whereNotIn($db->quoteName('handler'), $needing, ParameterType::STRING);
        }
    }

    /**
     * The history of the tasks a single resends or runs again, back to the first.
     *
     * @param DatabaseDriver $db The connection.
     * @param array<string, mixed> $row The single.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.1.4
     */
    private static function earlier(DatabaseDriver $db, array $row): array
    {
        $entries = [];
        $payload = json_decode((string) $row['payload'], true);
        $seen = [(int) $row['id'] => true];

        for ($step = 0; $step < self::CHAIN_LIMIT && \is_array($payload); $step++) {
            $of = (int) ($payload['resend_of'] ?? $payload['rerun_of'] ?? 0);

            if ($of < 1 || isset($seen[$of])) {
                break;
            }

            $seen[$of] = true;
            $earlier = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['payload', 'history']))
                    ->from($db->quoteName(self::TABLE))
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':id', $of, ParameterType::INTEGER),
            )->loadAssoc();

            if (!\is_array($earlier)) {
                break;
            }

            $entries = [...$entries, ...self::decodeHistory($earlier['history'])];
            $payload = json_decode((string) $earlier['payload'], true);
        }

        return $entries;
    }

    /**
     * After a run: the attention notice raised at once when a task ended failed or held.
     *
     * @param array<string, int> $counts The tally.
     *
     * @since  2.1.4
     */
    private static function afterRun(array $counts): void
    {
        if ($counts['failed'] + $counts['held'] > 0) {
            TaskAttention::sync();
        }
    }

    /**
     * An empty tally.
     *
     * @return array<string, int>
     *
     * @since  2.1.4
     */
    private static function counts(): array
    {
        return ['claimed' => 0, 'done' => 0, 'retried' => 0, 'failed' => 0, 'held' => 0, 'lost' => 0];
    }

    /**
     * Where the request comes from.
     *
     * @since  2.1.4
     */
    private static function origin(): string
    {
        try {
            $app = Factory::getApplication();
        } catch (Throwable) {
            return 'cli';
        }

        foreach (['administrator' => 'admin', 'site' => 'site', 'api' => 'api'] as $client => $origin) {
            if (method_exists($app, 'isClient') && $app->isClient($client)) {
                return $origin;
            }
        }

        return 'cli';
    }

    /**
     * Whether an application runs this process (the web, the Joomla console).
     *
     * @since  2.1.4
     */
    private static function hasApplication(): bool
    {
        try {
            Factory::getApplication();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The site's connection: the one every business transaction runs on.
     *
     * @since  2.1.4
     */
    private static function db(): DatabaseDriver
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }
}
