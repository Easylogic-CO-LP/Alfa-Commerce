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
use Alfa\Component\Alfa\Administrator\Helper\PostCommit;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryEffectOutbox;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\MaintenanceState;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * The job runner (2.2.0, design 2.4): every trigger calls tick() with its own wall-clock budget, and a tick runs the
 * singles recovery owes first, then due jobs in short chunks, and stops before the budget is spent.
 *
 * Orchestration only: the lease, the retry, the budget, the checkpoint, the progress and the priority. It knows no
 * pricing, stock, search or retention rule; a job's handler walks its units through its domain service.
 *
 * - A job is claimed by compare-and-set on its id (never SKIP LOCKED): the affected row count is the answer.
 * - A chunk commits the handler's domain writes, the heavy slot's renewal and the job's progress in one transaction,
 *   fenced by the claim: when either UPDATE finds the claim gone, the chunk is rolled back.
 * - One heavy job at a time: the heavy slot, taken by compare-and-set, renewed in every chunk, released on yield;
 *   taking it settles the running heavy rows nobody owns any more.
 * - A failed chunk is rolled back and the job retried after 30 s, doubling to an hour; failed after 8 in a row.
 * - Leases of 30 s, renewed at every chunk; a killed worker's job resumes after at most that.
 *
 * Nothing runs while the connection holds a transaction or one of Alfa's named locks, while Alfa is in maintenance,
 * or while an update has paused the runner (`runner.paused_until`).
 *
 * @since  2.2.0
 */
final class Runner
{
    /**
     * The lease of a claimed job and of the heavy slot, renewed at every chunk.
     *
     * @since  2.2.0
     */
    public const LEASE_SECONDS = 30;

    /**
     * What a chunk leaves of its lease for its own writes and the fence: a handler that waits never waits past
     * LEASE_SECONDS minus this.
     *
     * @since  2.2.0
     */
    private const CHUNK_LEASE_MARGIN_SECONDS = 5;

    /**
     * The checkpoint's key for the claims a job took without finishing a chunk: a worker that dies inside a chunk (a
     * PHP fatal: memory, a time limit) settles nothing, so its lease passes and the next tick claims the job again.
     * Counted at each claim, gone with the first chunk that finishes (its fence writes the handler's own checkpoint)
     * and with every failure the runner records.
     *
     * @since  2.2.0
     */
    private const UNFINISHED_CLAIMS_KEY = 'runner_unfinished_claims';

    /**
     * The claim that fails a job whose worker keeps stopping: the third without a finished chunk, so it stands for
     * staff (Retry, Mark resolved) and the jobs behind it run.
     *
     * @since  2.2.0
     */
    private const UNFINISHED_CLAIMS_LIMIT = 3;

    /**
     * Failures in a row after which a job is failed and waits for staff.
     *
     * @since  2.2.0
     */
    public const MAX_FAILURES = 8;

    /**
     * The first retry's delay; each further failure doubles it, up to BACKOFF_CAP_SECONDS.
     *
     * @since  2.2.0
     */
    public const BACKOFF_SECONDS = 30;

    /**
     * The longest retry delay.
     *
     * @since  2.2.0
     */
    public const BACKOFF_CAP_SECONDS = 3600;

    /**
     * The work a chunk aims at, in milliseconds.
     *
     * @since  2.2.0
     */
    public const CHUNK_TARGET_MS = 100.0;

    /**
     * The heavy slot's name in #__alfa_task_slots.
     *
     * @since  2.2.0
     */
    public const SLOT_HEAVY = 'heavy';

    /**
     * The #__alfa_meta row an update writes to pause every tick until it lies in the past.
     *
     * @since  2.2.0
     */
    public const META_PAUSED = 'runner.paused_until';

    /**
     * The #__alfa_meta row a tick that ran something writes: when, what triggered it and what it did.
     *
     * @since  2.2.0
     */
    public const META_LAST_TICK = 'runner.last_tick';

    /**
     * The triggers' gates: rows of #__alfa_task_slots taken by compare-and-set for their span (design 2.7, D1). The
     * storefront page that wins the page gate carries the kick; the guest endpoint and the API trigger work at most
     * once per span, whoever calls.
     *
     * @since  2.2.0
     */
    public const GATE_PAGE = 'gate.page';

    /** @since  2.2.0 */
    public const GATE_ENDPOINT = 'gate.endpoint';

    /** @since  2.2.0 */
    public const GATE_API = 'gate.api';

    /** The page gate's span in seconds (D1). @since  2.2.0 */
    public const PAGE_GATE_SECONDS = 30;

    /** The guest endpoint's gap and the API gate's span in seconds (D1). @since  2.2.0 */
    public const ENDPOINT_GAP_SECONDS = 5;

    /** The budget of a tick of the guest endpoint, the staff kick, the bell and the API after its response (D1). @since  2.2.0 */
    public const TICK_MS = 1000;

    /** The API tick's budget where the response cannot be finished first (D8). @since  2.2.0 */
    public const API_INLINE_MS = 250;

    /** The web cron's budget: under the common 30 s max_execution_time. @since  2.2.0 */
    public const WEBCRON_MS = 20000;

    /** The CLI command's span in seconds, or until nothing is due (D1). @since  2.2.0 */
    public const CLI_SECONDS = 25;

    /** The budget of a tick on one job asked for now: the progress page and the API's process route. @since  2.2.0 */
    public const PROCESS_MS = 2000;

    /** The staff kick's pause between two ticks while work remains (DESIGN-2.2.0.md 9.44, s120). @since  2.2.0 */
    public const STAFF_REPEAT_MS = 3000;

    /** How long one administrator page goes on ticking, in seconds; then only a page load ticks. @since  2.2.0 */
    public const STAFF_REPEAT_CAP_SECONDS = 1800;

    /**
     * Candidates read for one claim.
     *
     * @since  2.2.0
     */
    private const CANDIDATES = 5;

    /**
     * Attempts of an enqueue a deadlock on the unique active key ended.
     *
     * @since  2.2.0
     */
    private const WRITE_ATTEMPTS = 3;

    /**
     * The handlers made so far in this request, by name.
     *
     * @var array<string, JobHandler>
     *
     * @since  2.2.0
     */
    private static array $handlers = [];

    /**
     * One trigger's turn, bounded by its budget: the singles recovery owes, then due jobs chunk by chunk.
     *
     * The singles take the whole budget when no job is due and at most half of it when one is, so a job always
     * advances. With $onlyJob (Process now) only that job is worked; one running under another tick's live lease is
     * never taken ("elsewhere").
     *
     * @param string $trigger What runs it: bell, tools, runner, cli, processnow, or a trigger of the request.
     * @param int $budgetMs Its wall-clock budget in milliseconds; at least one chunk runs per claim.
     * @param int|null $onlyJob The one job to work, or null for any due job.
     *
     * @since  2.2.0
     */
    public static function tick(string $trigger, int $budgetMs, ?int $onlyJob = null): TickResult
    {
        $db = self::db();
        $start = hrtime(true);
        $refused = match (true) {
            TransactionState::isOpen($db) => 'transaction',
            PostCommit::inLock($db) => 'lock',
            MaintenanceState::active() => 'maintenance',
            self::paused() => 'paused',
            default => '',
        };

        if ($refused !== '') {
            return new TickResult(refused: $refused, stoppedBy: $refused, ms: self::since(start: $start));
        }

        // The work recovery runs is no visitor's and no member of staff's: the tick acts as the neutral system actor,
        // an empty user, and gives the request its own identity back afterwards (design 2.7, D8).
        $identity = self::actAsSystem();

        try {
            return self::tickAsSystem(db: $db, trigger: $trigger, budgetMs: max(1, $budgetMs), onlyJob: $onlyJob, start: $start);
        } finally {
            self::restoreIdentity(identity: $identity);
        }
    }

    /**
     * The tick's work, as the system actor.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $trigger What runs it.
     * @param int $budgetMs Its budget.
     * @param int|null $onlyJob The one job to work, or null.
     * @param int|float $start When the tick began (hrtime).
     *
     * @since  2.2.0
     */
    private static function tickAsSystem(DatabaseDriver $db, string $trigger, int $budgetMs, ?int $onlyJob, int|float $start): TickResult
    {
        if ($onlyJob === null) {
            self::owe(db: $db);
        }

        $tally = ['jobs' => 0, 'chunks' => 0, 'units' => 0, 'failed' => 0, 'lost' => 0, 'deadlocks' => 0];
        $retried = Tasks::retriedDeadlocks();
        $jobDue = self::jobDue(db: $db, onlyJob: $onlyJob);
        $singles = Tasks::sweepSingles(budgetMs: $jobDue ? max(1, intdiv($budgetMs, 2)) : $budgetMs, trigger: $trigger);
        $stoppedBy = 'nothing';

        if ($jobDue) {
            $stoppedBy = self::runJobs(db: $db, trigger: $trigger, budgetMs: $budgetMs, start: $start, onlyJob: $onlyJob, owner: bin2hex(random_bytes(16)), tally: $tally);
        } elseif ($onlyJob !== null && self::runsElsewhere(db: $db, id: $onlyJob)) {
            $stoppedBy = 'elsewhere';
        }

        $result = new TickResult(
            singles: $singles,
            jobs: $tally['jobs'],
            chunks: $tally['chunks'],
            units: $tally['units'],
            failed: $tally['failed'],
            lost: $tally['lost'],
            deadlocks: $tally['deadlocks'] + Tasks::retriedDeadlocks() - $retried,
            stoppedBy: $stoppedBy,
            ms: self::since(start: $start),
        );

        if (($singles['claimed'] ?? 0) > 0 || $tally['jobs'] > 0) {
            self::recordTick(db: $db, trigger: $trigger, result: $result);
        }

        RunnerHealth::sync();

        return $result;
    }

    /**
     * Whether a trigger has work to run now, in one statement (design 2.7): a pending job due, a running job whose lease
     * passed, a single recovery would take, a waiting price set whose job is gone, a stock movement owed with no
     * drain job to apply it, or an item's rebuild owed past the grace with no catalogue job. A heavy job running under a live lease is not due, so pages stop kicking by themselves.
     * A failure answers false, logged: a page never fails for it.
     *
     * @since  2.2.0
     */
    public static function due(): bool
    {
        return self::anyRow(
            probes: static fn (DatabaseDriver $db): array => self::dueProbes(db: $db),
            failure: 'the due read failed; no trigger kicks',
        );
    }

    /**
     * Whether work remains for an open administrator page to keep asking for (DESIGN-2.2.0.md 9.44, amending 2.7), in
     * one statement: what due() reads, and a job running under a live lease, which another worker holds now. The page
     * keeps asking while such a job runs; the claim rules decide who works it. A failure answers false, logged: the
     * page stops and the next page load asks again.
     *
     * @since  2.2.0
     */
    public static function remains(): bool
    {
        return self::anyRow(
            probes: static fn (DatabaseDriver $db): array => [...self::dueProbes(db: $db), 'SELECT 1 FROM ' . $db->quoteName(Tasks::TABLE)
                . ' WHERE ' . $db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB) . ' AND ' . $db->quoteName('state') . ' = '
                . $db->quote(Tasks::STATE_RUNNING) . ' AND ' . $db->quoteName('lease_until') . ' >= UTC_TIMESTAMP(3)'],
            failure: 'the remaining-work read failed; the page stops asking',
        );
    }

    /**
     * Whether any of the probes reads a row, in one statement; a failure answers false, logged.
     *
     * @param callable(DatabaseDriver): list<string> $probes The probes, each a SELECT of one column.
     * @param string $failure What the log says when the read fails.
     *
     * @since  2.2.0
     */
    private static function anyRow(callable $probes, string $failure): bool
    {
        $db = self::db();

        try {
            $union = array_map(static fn (string $probe): string => '(' . $probe . ' LIMIT 1)', $probes($db));

            return (bool) $db->setQuery(implode(' UNION ALL ', $union) . ' LIMIT 1')->loadResult();
        } catch (Throwable $e) {
            Tasks::log(entry: 'Runner: ' . $failure . ': ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);

            return false;
        }
    }

    /**
     * Take a trigger's gate for its span by compare-and-set: true for the one caller that changed the row.
     *
     * @param string $gate The gate: GATE_PAGE, GATE_ENDPOINT or GATE_API.
     * @param int $seconds Its span.
     *
     * @since  2.2.0
     */
    public static function openGate(string $gate, int $seconds): bool
    {
        $db = self::db();

        try {
            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__alfa_task_slots') . ' SET ' . $db->quoteName('lease_until') . ' = UTC_TIMESTAMP(3) + INTERVAL ' . max(1, $seconds) . ' SECOND'
                . ' WHERE ' . $db->quoteName('name') . ' = ' . $db->quote($gate)
                . ' AND (' . $db->quoteName('lease_until') . ' IS NULL OR ' . $db->quoteName('lease_until') . ' < UTC_TIMESTAMP(3))',
            )->execute();

            return $db->getAffectedRows() === 1;
        } catch (Throwable $e) {
            Tasks::log(entry: 'Runner: the gate ' . $gate . ' could not be taken: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);

            return false;
        }
    }

    /**
     * The probes of due(), each a SELECT of one column that reads at most one row.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @return list<string>
     *
     * @since  2.2.0
     */
    private static function dueProbes(DatabaseDriver $db): array
    {
        $tasks = $db->quoteName(Tasks::TABLE);
        $job = $db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB);
        $probes = [
            'SELECT 1 FROM ' . $tasks . ' WHERE ' . $job . ' AND ' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_PENDING)
                . ' AND ' . $db->quoteName('run_after') . ' <= UTC_TIMESTAMP(3)',
            'SELECT 1 FROM ' . $tasks . ' WHERE ' . $job . ' AND ' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING)
                . ' AND ' . $db->quoteName('lease_until') . ' < UTC_TIMESTAMP(3)',
        ];

        foreach (Tasks::dueProbes(db: $db) as $single) {
            $probes[] = $single;
        }

        $probes[] = 'SELECT 1 FROM ' . $db->quoteName('#__alfa_price_group_sets', 's') . ' WHERE ' . self::waitingSetWithoutJob(db: $db);
        $probes[] = 'SELECT 1 FROM ' . $db->quoteName('#__alfa_inventory_effect_outbox') . ' WHERE ' . self::owedMovementWithoutDrain(db: $db);
        $probes[] = 'SELECT 1 FROM ' . $db->quoteName('#__alfa_items') . ' WHERE ' . self::markWithoutCatalogueJob(db: $db);

        return $probes;
    }

    /**
     * An item's rebuild mark older than the singles' grace that no catalogue job has been asked for since it was
     * written, while no catalogue job is pending or running: a save that died after its commit and before its
     * rebuild (2.2.0, Q-L). A failed job is not active here: it stands for staff, and the mark still gets its job, whose
     * success closes the failed one. A mark a job has already walked and kept (a rebuild that failed again) waits for
     * the next request of the job, so an item that always fails never keeps the pages kicking.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.2.0
     */
    private static function markWithoutCatalogueJob(DatabaseDriver $db): string
    {
        $tasks = $db->quoteName(Tasks::TABLE);
        $handler = $db->quote(Job\CatalogueRebuildJob::NAME);

        return $db->quoteName('projection_dirty_at') . ' <= UTC_TIMESTAMP(6) - INTERVAL ' . Tasks::GRACE_SECONDS . ' SECOND'
            . ' AND ' . $db->quoteName('projection_dirty_at') . ' > COALESCE((SELECT MAX(' . $db->quoteName('created_at') . ') FROM ' . $tasks
            . ' WHERE ' . $db->quoteName('handler') . ' = ' . $handler . "), '1000-01-01 00:00:00')"
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $tasks . ' WHERE ' . $db->quoteName('active_key') . ' = ' . $handler . ')';
    }

    /**
     * A waiting price set with no job of its scope, pending, running or failed: a set whose job a hand deletion or a
     * test's cleanup removed. A failed job stands for staff (the attention notice) and is not replaced.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.2.0
     */
    private static function waitingSetWithoutJob(DatabaseDriver $db): string
    {
        return $db->quoteName('s.prepared_at') . ' IS NULL AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName(Tasks::TABLE, 't')
            . ' WHERE ' . $db->quoteName('t.handler') . ' = ' . $db->quote(Job\PriceSetPrepareJob::NAME)
            . ' AND ' . $db->quoteName('t.state') . ' IN (' . implode(', ', array_map([$db, 'quote'], [Tasks::STATE_PENDING, Tasks::STATE_RUNNING, Tasks::STATE_FAILED])) . ')'
            . ' AND ' . $db->quoteName('t.scope_key') . ' = CONCAT(' . $db->quote(Job\PriceSetPrepareJob::NAME . ':') . ', CONVERT(' . $db->quoteName('s.digest') . ' USING utf8mb4)) COLLATE utf8mb4_unicode_ci)';
    }

    /**
     * A stock movement owed, pending or under a claim gone stale (which the drain settles as a failed try, 2.2.1), with
     * no drain job to apply it. A movement that failed waits for the bell's throttled check
     * (InventoryEffectOutbox::syncNotice()), so a movement that always fails never keeps the pages kicking.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.2.0
     */
    private static function owedMovementWithoutDrain(DatabaseDriver $db): string
    {
        return '(' . $db->quoteName('status') . ' = ' . $db->quote(InventoryEffectOutbox::STATUS_PENDING)
            . ' OR ' . InventoryEffectOutbox::abandonedClaimWhere($db) . ')'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName(Tasks::TABLE, 't')
            . ' WHERE ' . $db->quoteName('t.handler') . ' = ' . $db->quote(Job\StockEffectsDrainJob::NAME)
            . ' AND ' . $db->quoteName('t.state') . ' IN (' . $db->quote(Tasks::STATE_PENDING) . ', ' . $db->quote(Tasks::STATE_RUNNING) . '))';
    }

    /**
     * The work a tick owes before it looks for due jobs: a job for each waiting price set with no job of its scope
     * (one whose job failed is not among them), the drain while movements are owed, the catalogue job for a rebuild
     * mark older than the grace that no job was asked for, and the prune of finished tasks once an hour
     * (TasksPruneJob::owe()). Each is logged and left on a failure: the tick goes on.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.2.0
     */
    private static function owe(DatabaseDriver $db): void
    {
        try {
            // Each waiting set whose job is gone gets its job; a set whose job failed is left to staff, whatever
            // happens to the others (the Tools button and the update queue every waiting set, their own planning).
            foreach ($db->setQuery('SELECT ' . $db->quoteName('s.digest') . ' FROM ' . $db->quoteName('#__alfa_price_group_sets', 's') . ' WHERE ' . self::waitingSetWithoutJob(db: $db))->loadColumn() ?: [] as $digest) {
                Job\PriceSetPrepareJob::enqueueFor(digest: (string) $digest);
            }
        } catch (Throwable $e) {
            Tasks::log(entry: 'Runner: the waiting price sets could not be queued: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
        }

        try {
            if ($db->setQuery('SELECT 1 FROM ' . $db->quoteName('#__alfa_inventory_effect_outbox') . ' WHERE ' . self::owedMovementWithoutDrain(db: $db), 0, 1)->loadResult()) {
                Job\StockEffectsDrainJob::enqueue();
            }
        } catch (Throwable $e) {
            Tasks::log(entry: 'Runner: the stock movements owed could not be queued: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
        }

        try {
            if ($db->setQuery('SELECT 1 FROM ' . $db->quoteName('#__alfa_items') . ' WHERE ' . self::markWithoutCatalogueJob(db: $db), 0, 1)->loadResult()) {
                Job\CatalogueRebuildJob::enqueue();
            }
        } catch (Throwable $e) {
            Tasks::log(entry: 'Runner: the rebuilds owed could not be queued: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
        }

        try {
            Job\TasksPruneJob::owe(db: $db);
        } catch (Throwable $e) {
            Tasks::log(entry: 'Runner: the prune of finished tasks could not be queued: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
        }
    }

    /**
     * Make the application's identity the neutral system actor for the tick: an empty user. Answers the identity to
     * give back, or null where there is no application.
     *
     * @since  2.2.0
     */
    private static function actAsSystem(): ?User
    {
        try {
            $app = Factory::getApplication();
        } catch (Throwable) {
            return null;
        }

        $identity = $app->getIdentity() ?? new User();
        $app->loadIdentity(new User());

        return $identity;
    }

    /**
     * Give the request its own identity back after the tick.
     *
     * @param User|null $identity What actAsSystem() answered.
     *
     * @since  2.2.0
     */
    private static function restoreIdentity(?User $identity): void
    {
        if ($identity !== null) {
            Factory::getApplication()->loadIdentity($identity);
        }
    }

    /**
     * Owe a job: a new one for the scope, or the scope's active one joined (one statement on the unique active key).
     *
     * A job joined once it has started (running, or pending with progress after a yield, a failure or a settle) is
     * marked to run once more from the start when its handler asks for it; one that never ran merges as it is. Its
     * payload is never rewritten. Inside a business transaction the job is born with the change that owes it, at its
     * commit. The scope key is the handler's: its name, or its name, a colon and what it covers
     * (price.set.prepare:<digest>), so a handler never joins another handler's job.
     *
     * @param string $handler The handler's name.
     * @param string $scopeKey What it covers: one active job per scope.
     * @param array<string, mixed> $payload What the handler needs.
     * @param int|null $priority Its claim order when it must go ahead of its handler's own (the daily backstop of
     *                           tasks.prune); null for the handler's. A join keeps the higher of the two.
     *
     * @throws InvalidArgumentException When no job handler has that name, or the scope key is not the handler's.
     *
     * @since  2.2.0
     */
    public static function enqueue(string $handler, string $scopeKey, array $payload, ?int $priority = null): EnqueueResult
    {
        $implementation = self::handler(name: $handler);

        if ($implementation === null) {
            throw new InvalidArgumentException('No job handler is named ' . $handler . '.');
        }

        if ($scopeKey !== $handler && !str_starts_with($scopeKey, $handler . ':')) {
            throw new InvalidArgumentException('The scope key of a ' . $handler . ' job is ' . $handler . ', or starts with ' . $handler . ': (given: ' . $scopeKey . ').');
        }

        $policy = $implementation->policy();
        $db = self::db();
        $json = json_encode(['version' => $policy->version] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $weight = $policy->weight === JobPolicy::HEAVY ? JobPolicy::HEAVY : JobPolicy::LIGHT;
        $priority ??= $policy->priority;
        $rerun = $policy->rerunOnMerge ? 1 : 0;
        $origin = Tasks::origin();
        $userId = Tasks::userId();
        $maxFailures = self::MAX_FAILURES;

        for ($attempt = 1; ; $attempt++) {
            try {
                $query = $db->getQuery(true)->setQuery(
                    'INSERT INTO ' . $db->quoteName(Tasks::TABLE)
                    . ' (' . implode(', ', $db->quoteName(['type', 'handler', 'scope_key', 'weight', 'priority', 'payload', 'run_after', 'max_failures', 'origin', 'created_by', 'created_at', 'updated_at'])) . ')'
                    . ' VALUES (' . $db->quote(Tasks::TYPE_JOB) . ', :handler, :scope, :weight, :priority, :payload, UTC_TIMESTAMP(3), :maxFailures, :origin, :user, UTC_TIMESTAMP(6), UTC_TIMESTAMP(3))'
                    . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('id') . ' = LAST_INSERT_ID(' . $db->quoteName('id') . '), '
                    . $db->quoteName('rerun') . ' = IF(:rerunOnMerge = 1 AND (' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING) . ' OR ' . $db->quoteName('done_units') . ' > 0), 1, ' . $db->quoteName('rerun') . '), '
                    . $db->quoteName('priority') . ' = GREATEST(' . $db->quoteName('priority') . ', :mergePriority), '
                    . $db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)',
                )
                    ->bind(':handler', $handler)
                    ->bind(':scope', $scopeKey)
                    ->bind(':weight', $weight)
                    ->bind(':priority', $priority, ParameterType::INTEGER)
                    ->bind(':mergePriority', $priority, ParameterType::INTEGER)
                    ->bind(':payload', $json)
                    ->bind(':maxFailures', $maxFailures, ParameterType::INTEGER)
                    ->bind(':origin', $origin)
                    ->bind(':user', $userId, ParameterType::INTEGER)
                    ->bind(':rerunOnMerge', $rerun, ParameterType::INTEGER);
                $db->setQuery($query)->execute();
                $result = new EnqueueResult(id: (int) $db->insertid(), merged: $db->getAffectedRows() !== 1);
                Tasks::noteForRequest(id: $result->id, merged: $result->merged);

                return $result;
            } catch (Throwable $e) {
                if ($attempt < self::WRITE_ATTEMPTS && MysqlError::number(error: $e) === 1213 && !TransactionState::isOpen($db)) {
                    Tasks::retriedDeadlock(statement: 'the enqueue of a ' . $handler . ' job', attempt: $attempt);
                    usleep(random_int(5000, 30000) * $attempt);

                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * A job handler by name, made once per request; null when this version has none of that name. The registry is
     * closed in 2.2.0: core's handlers only.
     *
     * @param string $name The handler's name.
     *
     * @since  2.2.0
     */
    public static function handler(string $name): ?JobHandler
    {
        if (isset(self::$handlers[$name])) {
            return self::$handlers[$name];
        }

        $class = self::coreHandlers()[$name] ?? null;

        return $class === null ? null : self::$handlers[$name] = new $class();
    }

    /**
     * Whether an update has paused every tick: `runner.paused_until` lies ahead by the database clock. A pause that
     * cannot be read counts as one.
     *
     * @since  2.2.0
     */
    public static function paused(): bool
    {
        $db = self::db();

        try {
            return (bool) $db->setQuery(
                'SELECT 1 FROM ' . $db->quoteName('#__alfa_meta') . ' WHERE ' . $db->quoteName('name') . ' = ' . $db->quote(self::META_PAUSED)
                . ' AND ' . $db->quoteName('value') . " > DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s')",
            )->loadResult();
        } catch (Throwable $e) {
            Tasks::log(entry: 'Runner: the pause could not be read; the tick does nothing: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);

            return true;
        }
    }

    /**
     * Core's job handlers by name (2.2.0, design 2.5; the jobs themselves are written in part C4).
     *
     * @return array<string, class-string<JobHandler>>
     *
     * @since  2.2.0
     */
    private static function coreHandlers(): array
    {
        return [
            Job\PriceSetPrepareJob::NAME => Job\PriceSetPrepareJob::class,
            Job\StockEffectsDrainJob::NAME => Job\StockEffectsDrainJob::class,
            Job\SearchRebuildJob::NAME => Job\SearchRebuildJob::class,
            Job\CatalogueRebuildJob::NAME => Job\CatalogueRebuildJob::class,
            Job\TasksPruneJob::NAME => Job\TasksPruneJob::class,
            // The gallery's thumbnails, recreated from Tools > Media (2.2.0, part C21).
            Job\ThumbnailsRecreateJob::NAME => Job\ThumbnailsRecreateJob::class,
        ];
    }

    /**
     * The newest job of a scope, whatever its state: what a page that started it reads to show its progress.
     *
     * @param string $scopeKey The scope.
     *
     * @return array{id: int, state: string, done: int, total: int, error: string}|null Null when the scope never had a job.
     *
     * @since  2.2.0
     */
    public static function latestJob(string $scopeKey): ?array
    {
        $db = self::db();
        $type = Tasks::TYPE_JOB;
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'state', 'done_units', 'total_units', 'last_error']))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('type') . ' = :type')
                ->where($db->quoteName('scope_key') . ' = :scope')
                ->order($db->quoteName('id') . ' DESC')
                ->bind(':type', $type)
                ->bind(':scope', $scopeKey),
            0,
            1,
        )->loadAssoc();

        return \is_array($row) ? [
            'id' => (int) $row['id'], 'state' => (string) $row['state'], 'done' => (int) $row['done_units'],
            'total' => (int) $row['total_units'], 'error' => (string) ($row['last_error'] ?? ''),
        ] : null;
    }

    /**
     * Claim and work due jobs until the budget is spent or none is left that this tick may take.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $trigger What runs it.
     * @param int $budgetMs The tick's budget.
     * @param int $start hrtime() when the tick began.
     * @param int|null $onlyJob The one job to work, or null.
     * @param string $owner This tick's claim.
     * @param array<string, int> $tally What the tick did.
     *
     * @return string Why it stopped: budget, nothing or elsewhere.
     *
     * @since  2.2.0
     */
    private static function runJobs(DatabaseDriver $db, string $trigger, int $budgetMs, int $start, ?int $onlyJob, string $owner, array &$tally): string
    {
        $slot = false;
        $stopped = 'nothing';

        try {
            // One claim at least, however small the budget: every tick that finds a job due advances it.
            do {
                $claimed = null;

                foreach (self::candidates(db: $db, onlyJob: $onlyJob) as $candidate) {
                    if ($candidate['weight'] === JobPolicy::HEAVY && !$slot) {
                        if (!self::attempt(tally: $tally, statement: static fn (): bool => self::takeSlot(db: $db, owner: $owner))) {
                            continue;
                        }

                        $slot = true;
                        self::attempt(tally: $tally, statement: static fn (): bool => self::settleStale(db: $db, owner: $owner) >= 0);
                    }

                    if (self::attempt(tally: $tally, statement: static fn (): bool => self::claim(db: $db, id: (int) $candidate['id'], owner: $owner, trigger: $trigger))) {
                        $claimed = $candidate;

                        break;
                    }
                }

                if ($claimed === null) {
                    $stopped = $onlyJob !== null && self::runsElsewhere(db: $db, id: $onlyJob) ? 'elsewhere' : 'nothing';

                    break;
                }

                $tally['jobs']++;
                $outcome = self::work(db: $db, id: (int) $claimed['id'], owner: $owner, trigger: $trigger, budgetMs: $budgetMs, start: $start, tally: $tally);

                if ($slot) {
                    self::releaseSlot(db: $db, owner: $owner);
                    $slot = false;
                }

                $stopped = 'budget';

                if ($onlyJob !== null || $outcome === 'yielded') {
                    break;
                }
            } while (self::since(start: $start) < $budgetMs);
        } finally {
            if ($slot) {
                self::releaseSlot(db: $db, owner: $owner);
            }
        }

        return $stopped;
    }

    /**
     * Work one claimed job: chunks while the next one fits the budget (at least one), then finish or yield.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $id The job.
     * @param string $owner The claim.
     * @param string $trigger What runs it.
     * @param int $budgetMs The tick's budget.
     * @param int $start hrtime() when the tick began.
     * @param array<string, int> $tally What the tick did.
     *
     * @return string finished, yielded, failed or lost.
     *
     * @since  2.2.0
     */
    private static function work(DatabaseDriver $db, int $id, string $owner, string $trigger, int $budgetMs, int $start, array &$tally): string
    {
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('id') . ' = :id')
                ->where($db->quoteName('lease_owner') . ' = :owner')
                ->bind(':id', $id, ParameterType::INTEGER)
                ->bind(':owner', $owner),
        )->loadAssoc();

        if (!\is_array($row)) {
            $tally['lost']++;

            return 'lost';
        }

        $handler = self::handler(name: (string) $row['handler']);

        if ($handler === null) {
            self::fail(db: $db, id: $id, owner: $owner, error: Tasks::errorKey('COM_ALFA_TASKS_ERROR_NO_HANDLER', (string) $row['handler']), final: true);
            $tally['failed']++;

            return 'failed';
        }

        $policy = $handler->policy();
        $heavy = (string) $row['weight'] === JobPolicy::HEAVY;
        $payload = json_decode((string) $row['payload'], true);
        $checkpoint = json_decode((string) ($row['checkpoint'] ?? ''), true);
        $checkpoint = \is_array($checkpoint) ? $checkpoint : null;
        $unfinished = (int) ($checkpoint[self::UNFINISHED_CLAIMS_KEY] ?? 0);
        $checkpoint = $checkpoint === null ? null : (array_diff_key($checkpoint, [self::UNFINISHED_CLAIMS_KEY => true]) ?: null);

        if ($unfinished >= self::UNFINISHED_CLAIMS_LIMIT - 1) {
            self::fail(db: $db, id: $id, owner: $owner, error: Tasks::errorKey('COM_ALFA_TASKS_ERROR_NO_PROGRESS', self::UNFINISHED_CLAIMS_LIMIT), final: true);
            $tally['failed']++;

            return 'failed';
        }

        if (!self::countClaim(db: $db, id: $id, owner: $owner, checkpoint: ($checkpoint ?? []) + [self::UNFINISHED_CLAIMS_KEY => $unfinished + 1])) {
            $tally['lost']++;

            return 'lost';
        }

        $run = new JobRun(
            id: $id,
            handler: (string) $row['handler'],
            scopeKey: (string) ($row['scope_key'] ?? ''),
            payload: \is_array($payload) ? $payload : [],
            checkpoint: $checkpoint,
            doneUnits: (int) $row['done_units'],
            totalUnits: (int) $row['total_units'],
            trigger: $trigger,
        );

        try {
            $total = max(0, $handler->total($run));
        } catch (Throwable $e) {
            self::fail(db: $db, id: $id, owner: $owner, error: Tasks::failureLine($e, $id));
            $tally['failed']++;

            return 'failed';
        }

        $run = $run->at(checkpoint: $run->checkpoint, doneUnits: $run->doneUnits, totalUnits: $total);
        $units = max(1, $policy->initialUnits);
        $unitMs = null;

        while (true) {
            // At least one chunk per claim; then none the rest of the budget cannot hold.
            if ($unitMs !== null && (self::since(start: $start) >= $budgetMs || self::since(start: $start) + $units * $unitMs > $budgetMs)) {
                self::yieldJob(db: $db, id: $id, owner: $owner);

                return 'yielded';
            }

            $began = hrtime(true);
            // The moment the chunk must be done by: what the tick's budget has left, and never past the lease the claim
            // or the last chunk's fence set (a handler that waits for a lock waits no longer).
            $left = min($budgetMs - self::since(start: $start), (self::LEASE_SECONDS - self::CHUNK_LEASE_MARGIN_SECONDS) * 1000);
            $chunk = self::chunk(db: $db, handler: $handler, run: $run->until(until: microtime(true) + max(0, $left) / 1000), units: $units, owner: $owner, heavy: $heavy, tally: $tally);

            if (!$chunk instanceof JobChunk) {
                return $chunk;
            }

            $tally['chunks']++;
            $tally['units'] += $chunk->units;

            if ($chunk->finished) {
                self::closeFailedOfScope(db: $db, run: $run, trigger: $trigger);

                return 'finished';
            }

            // The cost per unit, weighted toward the last chunk; the next chunk aims at the target, at most doubling.
            $perUnit = self::since(start: $began) / max(1, $chunk->units);
            $unitMs = $unitMs === null ? $perUnit : 0.3 * $unitMs + 0.7 * $perUnit;
            $units = max(1, min(2 * $units, (int) floor(self::CHUNK_TARGET_MS / max(0.001, $unitMs))));
            $run = $run->at(checkpoint: $chunk->checkpoint, doneUnits: $run->doneUnits + $chunk->units, totalUnits: $total);
        }
    }

    /**
     * One chunk: the handler's writes, the heavy slot's renewal and the fenced progress (or finish) in one transaction.
     *
     * @param DatabaseDriver $db The connection.
     * @param JobHandler $handler The handler.
     * @param JobRun $run The job as the chunk starts.
     * @param int $units At most this many units.
     * @param string $owner The claim.
     * @param bool $heavy Whether the job holds the heavy slot.
     * @param array<string, int> $tally What the tick did.
     *
     * @return JobChunk|string What the chunk did, or failed or lost.
     *
     * @since  2.2.0
     */
    private static function chunk(DatabaseDriver $db, JobHandler $handler, JobRun $run, int $units, string $owner, bool $heavy, array &$tally): JobChunk|string
    {
        // A handler whose domain service keeps its own transactions works before the chunk transaction opens.
        $done = null;

        if ($handler->policy()->ownTransactions) {
            try {
                $done = $handler->chunk($run, $units);
            } catch (Throwable $e) {
                $tally['deadlocks'] += MysqlError::number(error: $e) === 1213 ? 1 : 0;
                self::fail(db: $db, id: $run->id, owner: $owner, error: Tasks::failureLine($e, $run->id));
                $tally['failed']++;

                return 'failed';
            }
        }

        $db->transactionStart(true);
        $level = TransactionState::level($db);

        try {
            $chunk = $done ?? $handler->chunk($run, $units);

            // A handler that swallowed a failure which ended the chunk's transaction (a deadlock) would let the slot's
            // renewal and the fence run in autocommit and commit its progress alone: the chunk failed.
            if (TransactionState::level($db) !== $level) {
                throw new TaskError('COM_ALFA_TASKS_ERROR_STEP_TRANSACTION');
            }

            $kept = (!$heavy || self::renewSlot(db: $db, owner: $owner)) && self::fence(db: $db, run: $run, chunk: $chunk, owner: $owner);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);
            $tally['deadlocks'] += MysqlError::number(error: $e) === 1213 ? 1 : 0;
            self::fail(db: $db, id: $run->id, owner: $owner, error: Tasks::failureLine($e, $run->id));
            $tally['failed']++;

            return 'failed';
        }

        if (!$kept) {
            // The claim is another tick's now: nothing of this chunk is kept.
            TransactionState::rollBackLevel($db);
            $tally['lost']++;
            Tasks::log(entry: 'Runner: job #' . $run->id . ' (' . $run->handler . '): its lease passed to another tick; the chunk was rolled back.', priority: Log::WARNING);

            return 'lost';
        }

        try {
            TransactionState::commitLevel($db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);
            $tally['deadlocks'] += MysqlError::number(error: $e) === 1213 ? 1 : 0;
            self::fail(db: $db, id: $run->id, owner: $owner, error: Tasks::failureLine($e, $run->id));
            $tally['failed']++;

            return 'failed';
        }

        return $chunk;
    }

    /**
     * The fenced update of a chunk: progress, or the finish (done, or pending again when a merge asked for one more run,
     * from the start), only while the claim is this tick's.
     *
     * @param DatabaseDriver $db The connection, in the chunk's transaction.
     * @param JobRun $run The job as the chunk started.
     * @param JobChunk $chunk What the chunk did.
     * @param string $owner The claim.
     *
     * @since  2.2.0
     */
    private static function fence(DatabaseDriver $db, JobRun $run, JobChunk $chunk, string $owner): bool
    {
        $id = $run->id;
        $done = max(0, $chunk->units);
        $total = max($run->totalUnits, $run->doneUnits + $done);
        $checkpoint = $chunk->checkpoint === null ? null : json_encode($chunk->checkpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $query = $db->getQuery(true)->update($db->quoteName(Tasks::TABLE));

        if ($chunk->finished) {
            // rerun is read before it is cleared: assignments run left to right on both release databases.
            $query
                ->set($db->quoteName('done_units') . ' = IF(' . $db->quoteName('rerun') . ' = 1, 0, ' . $db->quoteName('done_units') . ' + :units)')
                ->set($db->quoteName('total_units') . ' = :total')
                ->set($db->quoteName('failures') . ' = 0')
                ->set($db->quoteName('state') . ' = IF(' . $db->quoteName('rerun') . ' = 1, ' . $db->quote(Tasks::STATE_PENDING) . ', ' . $db->quote(Tasks::STATE_DONE) . ')')
                ->set($db->quoteName('finished_at') . ' = IF(' . $db->quoteName('rerun') . ' = 1, NULL, UTC_TIMESTAMP(3))')
                ->set($db->quoteName('checkpoint') . ' = IF(' . $db->quoteName('rerun') . ' = 1, NULL, ' . ($checkpoint === null ? 'NULL' : ':checkpoint') . ')')
                ->set($db->quoteName('run_after') . ' = IF(' . $db->quoteName('rerun') . ' = 1, UTC_TIMESTAMP(3), ' . $db->quoteName('run_after') . ')')
                ->set($db->quoteName('rerun') . ' = 0')
                ->set($db->quoteName('lease_owner') . ' = NULL')
                ->set($db->quoteName('lease_until') . ' = NULL');
        } else {
            $query
                ->set($db->quoteName('checkpoint') . ' = ' . ($checkpoint === null ? 'NULL' : ':checkpoint'))
                ->set($db->quoteName('done_units') . ' = ' . $db->quoteName('done_units') . ' + :units')
                ->set($db->quoteName('total_units') . ' = :total')
                ->set($db->quoteName('failures') . ' = 0')
                ->set($db->quoteName('lease_until') . ' = UTC_TIMESTAMP(3) + INTERVAL ' . self::LEASE_SECONDS . ' SECOND');
        }

        $query
            ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING))
            ->where($db->quoteName('lease_owner') . ' = :owner')
            ->bind(':units', $done, ParameterType::INTEGER)
            ->bind(':total', $total, ParameterType::INTEGER)
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':owner', $owner);

        if ($checkpoint !== null) {
            $query->bind(':checkpoint', $checkpoint);
        }

        if (Tasks::changed(db: $db, query: $query) === 1) {
            return true;
        }

        // A row matched but left as it was (the same millisecond) changes nothing: asked under its lock.
        return !$chunk->finished && self::stillOwned(db: $db, sql: 'SELECT 1 FROM ' . $db->quoteName(Tasks::TABLE) . ' WHERE ' . $db->quoteName('id') . ' = ' . $id
            . ' AND ' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING) . ' AND ' . $db->quoteName('lease_owner') . ' = ' . $db->quote($owner) . ' FOR UPDATE');
    }

    /**
     * Claim one job by compare-and-set on its id: pending and due, or running under a lease that passed.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $id The job.
     * @param string $owner The claim.
     * @param string $trigger What claims it.
     *
     * @since  2.2.0
     */
    private static function claim(DatabaseDriver $db, int $id, string $owner, string $trigger): bool
    {
        $query = $db->getQuery(true)
            ->update($db->quoteName(Tasks::TABLE))
            ->set($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING))
            ->set($db->quoteName('lease_owner') . ' = :owner')
            ->set($db->quoteName('lease_until') . ' = UTC_TIMESTAMP(3) + INTERVAL ' . self::LEASE_SECONDS . ' SECOND')
            ->set($db->quoteName('claims') . ' = ' . $db->quoteName('claims') . ' + 1')
            ->set($db->quoteName('started_at') . ' = COALESCE(' . $db->quoteName('started_at') . ', UTC_TIMESTAMP(3))')
            ->set($db->quoteName('last_trigger') . ' = :trigger')
            ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB))
            ->where('((' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_PENDING) . ' AND ' . $db->quoteName('run_after') . ' <= UTC_TIMESTAMP(3))'
                . ' OR (' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING) . ' AND ' . $db->quoteName('lease_until') . ' < UTC_TIMESTAMP(3)))')
            ->bind(':owner', $owner)
            ->bind(':trigger', $trigger)
            ->bind(':id', $id, ParameterType::INTEGER);

        return Tasks::changed(db: $db, query: $query) === 1;
    }

    /**
     * Take the heavy slot by compare-and-set: free, or its lease passed.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $owner The claim.
     *
     * @since  2.2.0
     */
    private static function takeSlot(DatabaseDriver $db, string $owner): bool
    {
        $name = self::SLOT_HEAVY;

        return Tasks::changed(
            db: $db,
            query: $db->getQuery(true)
                ->update($db->quoteName('#__alfa_task_slots'))
                ->set($db->quoteName('owner') . ' = :owner')
                ->set($db->quoteName('lease_until') . ' = UTC_TIMESTAMP(3) + INTERVAL ' . self::LEASE_SECONDS . ' SECOND')
                ->where($db->quoteName('name') . ' = :name')
                ->where('(' . $db->quoteName('owner') . ' IS NULL OR ' . $db->quoteName('owner') . ' = :same OR ' . $db->quoteName('lease_until') . ' < UTC_TIMESTAMP(3))')
                ->bind(':owner', $owner)
                ->bind(':same', $owner)
                ->bind(':name', $name),
        ) === 1;
    }

    /**
     * Owning the heavy slot proves nobody else works a heavy job: the running heavy rows of other claims go back to
     * pending, their checkpoint kept.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $owner The claim that took the slot.
     *
     * @return int How many it settled.
     *
     * @since  2.2.0
     */
    private static function settleStale(DatabaseDriver $db, string $owner): int
    {
        $weight = JobPolicy::HEAVY;

        return Tasks::changed(
            db: $db,
            query: $db->getQuery(true)
                ->update($db->quoteName(Tasks::TABLE))
                ->set($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_PENDING))
                ->set($db->quoteName('lease_owner') . ' = NULL')
                ->set($db->quoteName('lease_until') . ' = NULL')
                ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
                ->where($db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB))
                ->where($db->quoteName('weight') . ' = :weight')
                ->where($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING))
                ->where('NOT (' . $db->quoteName('lease_owner') . ' <=> :owner)')
                ->bind(':weight', $weight)
                ->bind(':owner', $owner),
        );
    }

    /**
     * Renew the heavy slot inside a chunk's transaction (slot first, then the job: one lock order).
     *
     * @param DatabaseDriver $db The connection.
     * @param string $owner The claim.
     *
     * @since  2.2.0
     */
    private static function renewSlot(DatabaseDriver $db, string $owner): bool
    {
        $name = self::SLOT_HEAVY;
        $renewed = Tasks::changed(
            db: $db,
            query: $db->getQuery(true)
                ->update($db->quoteName('#__alfa_task_slots'))
                ->set($db->quoteName('lease_until') . ' = UTC_TIMESTAMP(3) + INTERVAL ' . self::LEASE_SECONDS . ' SECOND')
                ->where($db->quoteName('name') . ' = :name')
                ->where($db->quoteName('owner') . ' = :owner')
                ->bind(':name', $name)
                ->bind(':owner', $owner),
        );

        return $renewed === 1 || self::stillOwned(db: $db, sql: 'SELECT 1 FROM ' . $db->quoteName('#__alfa_task_slots') . ' WHERE ' . $db->quoteName('name') . ' = '
            . $db->quote(self::SLOT_HEAVY) . ' AND ' . $db->quoteName('owner') . ' = ' . $db->quote($owner) . ' FOR UPDATE');
    }

    /**
     * Release the heavy slot. A release that fails is logged; the slot's lease then lapses.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $owner The claim.
     *
     * @since  2.2.0
     */
    private static function releaseSlot(DatabaseDriver $db, string $owner): void
    {
        $name = self::SLOT_HEAVY;

        try {
            Tasks::changed(
                db: $db,
                query: $db->getQuery(true)
                    ->update($db->quoteName('#__alfa_task_slots'))
                    ->set($db->quoteName('owner') . ' = NULL')
                    ->set($db->quoteName('lease_until') . ' = NULL')
                    ->where($db->quoteName('name') . ' = :name')
                    ->where($db->quoteName('owner') . ' = :owner')
                    ->bind(':name', $name)
                    ->bind(':owner', $owner),
            );
        } catch (Throwable $e) {
            Tasks::log(entry: 'Runner: the heavy slot stays held until its lease lapses (' . self::LEASE_SECONDS . ' s), its release failed: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
        }
    }

    /**
     * Give a job back when the budget is spent: pending again, its checkpoint kept, its lease cleared.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $id The job.
     * @param string $owner The claim.
     *
     * @since  2.2.0
     */
    private static function yieldJob(DatabaseDriver $db, int $id, string $owner): void
    {
        try {
            Tasks::changed(
                db: $db,
                query: $db->getQuery(true)
                    ->update($db->quoteName(Tasks::TABLE))
                    ->set($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_PENDING))
                    ->set($db->quoteName('lease_owner') . ' = NULL')
                    ->set($db->quoteName('lease_until') . ' = NULL')
                    ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
                    ->where($db->quoteName('id') . ' = :id')
                    ->where($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING))
                    ->where($db->quoteName('lease_owner') . ' = :owner')
                    ->bind(':id', $id, ParameterType::INTEGER)
                    ->bind(':owner', $owner),
            );
        } catch (Throwable $e) {
            Tasks::log(entry: 'Runner: job #' . $id . ' stays claimed until its lease lapses (' . self::LEASE_SECONDS . ' s), its yield failed: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
        }
    }

    /**
     * Count a claim in the job's checkpoint before its first chunk: the claims taken without finishing a chunk, which
     * the first finished chunk's fence clears.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $id The job.
     * @param string $owner The claim.
     * @param array<string, mixed> $checkpoint The checkpoint with the count.
     *
     * @return bool Whether the claim is still this tick's.
     *
     * @since  2.2.0
     */
    private static function countClaim(DatabaseDriver $db, int $id, string $owner, array $checkpoint): bool
    {
        $text = json_encode($checkpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return Tasks::changed(
            db: $db,
            query: $db->getQuery(true)
                ->update($db->quoteName(Tasks::TABLE))
                ->set($db->quoteName('checkpoint') . ' = :checkpoint')
                ->where($db->quoteName('id') . ' = :id')
                ->where($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING))
                ->where($db->quoteName('lease_owner') . ' = :owner')
                ->bind(':checkpoint', $text)
                ->bind(':id', $id, ParameterType::INTEGER)
                ->bind(':owner', $owner),
        ) === 1;
    }

    /**
     * Record a failed chunk (already rolled back): one more failure, retried after 30 s doubling to an hour, failed
     * for staff once failures reach max_failures (or at once when $final), the lease cleared.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $id The job.
     * @param string $owner The claim.
     * @param string $error What failed.
     * @param bool $final Whether no retry can help (no handler).
     *
     * @since  2.2.0
     */
    private static function fail(DatabaseDriver $db, int $id, string $owner, string $error, bool $final = false): void
    {
        $error = mb_substr($error !== '' ? $error : 'unknown failure', 0, 1000);
        $gone = $final ? '1' : $db->quoteName('failures') . ' >= ' . $db->quoteName('max_failures');

        try {
            // failures first: the later assignments read the new count (left to right on both release databases).
            Tasks::changed(
                db: $db,
                query: $db->getQuery(true)
                    ->update($db->quoteName(Tasks::TABLE))
                    ->set($db->quoteName('failures') . ' = ' . $db->quoteName('failures') . ' + 1')
                    ->set($db->quoteName('state') . ' = IF(' . $gone . ', ' . $db->quote(Tasks::STATE_FAILED) . ', ' . $db->quote(Tasks::STATE_PENDING) . ')')
                    ->set($db->quoteName('finished_at') . ' = IF(' . $gone . ', UTC_TIMESTAMP(3), NULL)')
                    ->set($db->quoteName('run_after') . ' = IF(' . $gone . ', ' . $db->quoteName('run_after') . ', UTC_TIMESTAMP(3) + INTERVAL LEAST('
                        . self::BACKOFF_CAP_SECONDS . ', ' . self::BACKOFF_SECONDS . ' * POW(2, ' . $db->quoteName('failures') . ' - 1)) SECOND)')
                    ->set($db->quoteName('last_error') . ' = :error')
                    // A failure recorded is a claim that settled: the count of the claims that did not goes with it.
                    ->set($db->quoteName('checkpoint') . ' = IF(JSON_VALID(' . $db->quoteName('checkpoint') . '), JSON_REMOVE(' . $db->quoteName('checkpoint')
                        . ', ' . $db->quote('$.' . self::UNFINISHED_CLAIMS_KEY) . '), ' . $db->quoteName('checkpoint') . ')')
                    ->set($db->quoteName('lease_owner') . ' = NULL')
                    ->set($db->quoteName('lease_until') . ' = NULL')
                    ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
                    ->where($db->quoteName('id') . ' = :id')
                    ->where($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING))
                    ->where($db->quoteName('lease_owner') . ' = :owner')
                    ->bind(':error', $error)
                    ->bind(':id', $id, ParameterType::INTEGER)
                    ->bind(':owner', $owner),
            );
        } catch (Throwable $e) {
            Tasks::log(entry: 'Runner: the failure of job #' . $id . ' could not be recorded; its lease lapses (' . self::LEASE_SECONDS . ' s): ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
        }

        Tasks::log(entry: 'Runner: job #' . $id . ' failed' . ($final ? ' for good' : ' and is retried with backoff') . ': ' . $error, priority: $final ? Log::ERROR : Log::WARNING);
    }

    /**
     * A later success closes the scope's failed job: after a done finish, a failed job of the same handler and
     * scope is resolved, with a history entry naming the job that closed it.
     *
     * @param DatabaseDriver $db The connection.
     * @param JobRun $run The job that finished.
     * @param string $trigger What ran it.
     *
     * @since  2.2.0
     */
    private static function closeFailedOfScope(DatabaseDriver $db, JobRun $run, string $trigger): void
    {
        try {
            $id = $run->id;
            $state = (string) $db->setQuery('SELECT ' . $db->quoteName('state') . ' FROM ' . $db->quoteName(Tasks::TABLE) . ' WHERE ' . $db->quoteName('id') . ' = ' . $id)->loadResult();

            if ($state !== Tasks::STATE_DONE || $run->scopeKey === '') {
                return;
            }

            $handler = $run->handler;
            $scope = $run->scopeKey;
            $failed = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'history']))
                    ->from($db->quoteName(Tasks::TABLE))
                    ->where($db->quoteName('handler') . ' = :handler')
                    ->where($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_FAILED))
                    ->where($db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB))
                    ->where($db->quoteName('scope_key') . ' = :scope')
                    ->bind(':handler', $handler)
                    ->bind(':scope', $scope),
            )->loadAssocList() ?: [];

            foreach ($failed as $row) {
                $history = Tasks::encodeHistory([...Tasks::decodeHistory($row['history']), Tasks::entry(trigger: $trigger, userId: Tasks::userId(), outcome: 'closed', more: ['job' => $id])]);
                $failedId = (int) $row['id'];
                Tasks::changed(
                    db: $db,
                    query: $db->getQuery(true)
                        ->update($db->quoteName(Tasks::TABLE))
                        ->set($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RESOLVED))
                        ->set($db->quoteName('history') . ' = :history')
                        ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
                        // Resolved now: the retention rules count its days from this act, as from staff's.
                        ->set($db->quoteName('finished_at') . ' = UTC_TIMESTAMP(3)')
                        ->where($db->quoteName('id') . ' = :id')
                        ->where($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_FAILED))
                        ->bind(':history', $history)
                        ->bind(':id', $failedId, ParameterType::INTEGER),
                );
            }
        } catch (Throwable $e) {
            Tasks::log(entry: 'Runner: the failed jobs of scope ' . $run->scopeKey . ' could not be closed by job #' . $run->id . ': ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
        }
    }

    /**
     * Up to CANDIDATES jobs a claim may take, read without locks: pending and due by priority, run_after and id
     * (idx_due), and running ones whose lease passed (idx_lease).
     *
     * @param DatabaseDriver $db The connection.
     * @param int|null $onlyJob The one job, or null.
     *
     * @return list<array{id: int, weight: string, priority: int}>
     *
     * @since  2.2.0
     */
    private static function candidates(DatabaseDriver $db, ?int $onlyJob): array
    {
        $rows = [];

        foreach ([
            '(' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_PENDING) . ' AND ' . $db->quoteName('run_after') . ' <= UTC_TIMESTAMP(3))',
            '(' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING) . ' AND ' . $db->quoteName('lease_until') . ' < UTC_TIMESTAMP(3))',
        ] as $due) {
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'weight', 'priority', 'run_after']))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB))
                ->where($due)
                ->order($db->quoteName('priority') . ' DESC, ' . $db->quoteName('run_after') . ' ASC, ' . $db->quoteName('id') . ' ASC');

            if ($onlyJob !== null) {
                $query->where($db->quoteName('id') . ' = ' . (int) $onlyJob);
            }

            foreach ($db->setQuery($query, 0, self::CANDIDATES)->loadAssocList() ?: [] as $row) {
                $rows[(int) $row['id']] = ['id' => (int) $row['id'], 'weight' => (string) $row['weight'], 'priority' => (int) $row['priority'], 'run_after' => (string) $row['run_after']];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$b['priority'], $a['run_after'], $a['id']] <=> [$a['priority'], $b['run_after'], $b['id']]);

        return \array_slice(array_map(static fn (array $row): array => ['id' => $row['id'], 'weight' => $row['weight'], 'priority' => $row['priority']], $rows), 0, self::CANDIDATES);
    }

    /**
     * Whether a job is due (or the asked one is): one read for each kind.
     *
     * @param DatabaseDriver $db The connection.
     * @param int|null $onlyJob The one job, or null.
     *
     * @since  2.2.0
     */
    private static function jobDue(DatabaseDriver $db, ?int $onlyJob): bool
    {
        return self::candidates(db: $db, onlyJob: $onlyJob) !== [];
    }

    /**
     * Whether a job runs under another tick's live lease.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $id The job.
     *
     * @since  2.2.0
     */
    private static function runsElsewhere(DatabaseDriver $db, int $id): bool
    {
        return (bool) $db->setQuery(
            'SELECT 1 FROM ' . $db->quoteName(Tasks::TABLE) . ' WHERE ' . $db->quoteName('id') . ' = ' . $id . ' AND ' . $db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB)
            . ' AND ' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING) . ' AND ' . $db->quoteName('lease_until') . ' >= UTC_TIMESTAMP(3)',
        )->loadResult();
    }

    /**
     * A fence's second look when its UPDATE changed nothing: whether the row is still this claim's, read under its lock.
     *
     * @param DatabaseDriver $db The connection, in the chunk's transaction.
     * @param string $sql The locking read.
     *
     * @since  2.2.0
     */
    private static function stillOwned(DatabaseDriver $db, string $sql): bool
    {
        return (bool) $db->setQuery($sql)->loadResult();
    }

    /**
     * Run one claim statement of the tick; a failure counts as not taken (a deadlock is counted) and is logged.
     *
     * @param array<string, int> $tally What the tick did.
     * @param callable $statement fn(): bool.
     *
     * @since  2.2.0
     */
    private static function attempt(array &$tally, callable $statement): bool
    {
        try {
            return $statement();
        } catch (Throwable $e) {
            $tally['deadlocks'] += MysqlError::number(error: $e) === 1213 ? 1 : 0;
            Tasks::log(entry: 'Runner: a claim statement failed and is not taken: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);

            return false;
        }
    }

    /**
     * Write runner.last_tick: when, what triggered it and what it did. A failure is logged, never the tick's.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $trigger What ran the tick.
     * @param TickResult $result What it did.
     *
     * @since  2.2.0
     */
    private static function recordTick(DatabaseDriver $db, string $trigger, TickResult $result): void
    {
        try {
            $json = json_encode([
                'at' => Factory::getDate()->toSql(),
                'trigger' => $trigger,
                'singles' => (int) ($result->singles['claimed'] ?? 0),
                'chunks' => $result->chunks,
                'jobs' => $result->jobs,
                'ms' => (int) round($result->ms),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $again = $json;
            $name = self::META_LAST_TICK;
            $query = $db->getQuery(true)->setQuery(
                'INSERT INTO ' . $db->quoteName('#__alfa_meta') . ' (' . $db->quoteName('name') . ', ' . $db->quoteName('value') . ') VALUES (:name, :value)'
                . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('value') . ' = :again',
            )
                ->bind(':name', $name)
                ->bind(':value', $json)
                ->bind(':again', $again);
            $db->setQuery($query)->execute();
        } catch (Throwable $e) {
            Tasks::log(entry: 'Runner: runner.last_tick could not be written: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
        }
    }

    /**
     * Milliseconds since an hrtime().
     *
     * @param int $start The hrtime().
     *
     * @since  2.2.0
     */
    private static function since(int $start): float
    {
        return (hrtime(true) - $start) / 1e6;
    }

    /**
     * The site's connection.
     *
     * @since  2.2.0
     */
    private static function db(): DatabaseDriver
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }
}
