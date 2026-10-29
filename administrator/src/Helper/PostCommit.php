<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use RuntimeException;
use Throwable;
use WeakMap;

defined('_JEXEC') or die;

/**
 * Work that may only run once the transaction it belongs to has committed.
 *
 * Announcing a document number, draining stock into other code or telling a
 * listener about a write is final: no rollback reaches it. Done inside an open
 * transaction, it reports work that a later failure in the same transaction
 * can still undo, and a number it announced is then issued again.
 *
 * Such work is deferred here. With no transaction open it runs at once. Inside
 * one it waits on the connection's queue, owned by the level it was deferred
 * in: TransactionState::commitLevel() runs the queue when the outermost
 * transaction commits, and TransactionState::rollBackLevel() discards what the
 * level it rolls back owned. A savepoint that is released hands its work to
 * the level around it.
 *
 * Every level in which work may be deferred is therefore closed through
 * TransactionState::commitLevel() or rollBackLevel(): a plain commit or
 * rollback of the driver leaves the work waiting.
 *
 * Alfa's named locks (an order's money lock, a shipment's booking lock) open a
 * lock scope on the connection while they are held: work deferred inside one
 * waits for its release, even with no transaction open, so nothing deferred
 * runs while another request is refused as busy on that lock. Work deferred
 * with no transaction open inside a lock scope is already committed, so no
 * rollback discards it.
 *
 * The queue also holds the connection's notice journal (2.1.4): each notification
 * write raised inside a transaction, recorded by its single ({@see Tasks::notice()}),
 * owned by its level like the work. Once the outermost transaction has committed the
 * journal is written first, before any other work, even while a lock scope or a hold
 * is open (s71): a row write holds no lock past its own statement. What the writes owe
 * outside the database (onAlfaNotificationPushed) is work like any other and waits
 * for the release.
 *
 * @since  2.0.0
 */
final class PostCommit
{
    /**
     * The waiting work of each connection, oldest first.
     *
     * @var WeakMap<DatabaseDriver, list<array{level: int, label: string, work: callable, journal?: array<string, mixed>}>>|null
     *
     * @since  2.0.0
     */
    private static ?WeakMap $queues = null;

    /**
     * The named locks each connection holds through Alfa's lock helpers: the scopes open, and how many of them each
     * lock's name holds.
     *
     * @var WeakMap<DatabaseDriver, array{open: int, names: array<string, int>}>|null
     *
     * @since  2.1.4
     */
    private static ?WeakMap $locks = null;

    /**
     * Run work once the connection's transaction has committed.
     *
     * With no transaction and no lock scope open it runs at once; with only a
     * lock scope open it runs when the last lock is released. A failure of the
     * work is logged under its label and never reaches the caller: when it
     * runs, the work it follows is already committed.
     *
     * @param DatabaseDriver $db The connection the work's transaction runs on.
     * @param string $label What the work does, as the log names it.
     * @param callable $work fn(): void.
     *
     *
     * @since  2.0.0
     */
    public static function defer(DatabaseDriver $db, string $label, callable $work): void
    {
        self::queue(db: $db, level: TransactionState::level($db), label: $label, work: $work);
    }

    /**
     * Run work once the connection's outermost transaction has committed.
     *
     * As defer(), except that inside a transaction the work belongs to the
     * outermost level from the start: a savepoint rolled back does not take it,
     * only the outermost rollback does. For work that reads what committed
     * when it runs, such as a batch of tasks.
     *
     * @param DatabaseDriver $db The connection the work's transaction runs on.
     * @param string $label What the work does, as the log names it.
     * @param callable $work fn(): void.
     *
     * @since  2.1.4
     */
    public static function deferToOutermost(DatabaseDriver $db, string $label, callable $work): void
    {
        self::queue(db: $db, level: min(1, TransactionState::level($db)), label: $label, work: $work);
    }

    /**
     * Record a notification write raised inside the connection's transaction: the notice journal, owned by the current
     * level as deferred work is. Written once the outermost transaction has committed, before any other work, even
     * inside a lock scope or a hold.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $label What the entry is, as the log names it.
     * @param array{task: int, batch: string, op: array<string, mixed>} $entry The single, its batch, the write.
     *
     * @since  2.1.4
     */
    public static function journal(DatabaseDriver $db, string $label, array $entry): void
    {
        $queues = self::queues();
        $items = $queues[$db] ?? [];
        $items[] = ['level' => max(0, TransactionState::level($db)), 'label' => $label, 'work' => static function (): void {
        }, 'journal' => $entry];
        $queues[$db] = $items;
    }

    /**
     * Whether work deferred under this label still waits on the connection.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $label The label it was deferred under.
     *
     * @since  2.1.4
     */
    public static function waiting(DatabaseDriver $db, string $label): bool
    {
        foreach (self::queues()[$db] ?? [] as $item) {
            if ($item['label'] === $label) {
                return true;
            }
        }

        return false;
    }

    /**
     * Open a lock scope: the connection now holds one of Alfa's named locks.
     *
     * Called right after the lock was taken. Until the matching leaveLock(), deferred work waits even with no
     * transaction open. A lock taken again by the same connection through the helpers (named locks are re-entrant)
     * opens a scope inside the first.
     *
     * @param DatabaseDriver $db The connection that took the lock.
     * @param string $name The lock's name.
     *
     * @since  2.1.4
     */
    public static function enterLock(DatabaseDriver $db, string $name): void
    {
        $locks = self::locks();
        $scope = $locks[$db] ?? ['open' => 0, 'names' => []];
        $scope['open']++;
        $scope['names'][$name] = ($scope['names'][$name] ?? 0) + 1;
        $locks[$db] = $scope;
    }

    /**
     * Close a lock scope once its helper released the lock, and run the waiting work when no lock scope and no
     * transaction is left open.
     *
     * When the connection still holds the lock although no scope of Alfa's holds it any more (its release failed, or
     * other code took it again), the scope is kept open: nothing deferred runs on the connection in this request, and
     * the singles it wrote reach recovery once the lock is gone.
     *
     * @param DatabaseDriver $db The connection that released the lock.
     * @param string $name The lock's name.
     * @param bool $stillHeld Whether the connection still holds it after the release.
     *
     * @return bool False when the scope was kept open because the lock is still held.
     *
     * @since  2.1.4
     */
    public static function leaveLock(DatabaseDriver $db, string $name, bool $stillHeld = false): bool
    {
        $locks = self::locks();
        $scope = $locks[$db] ?? ['open' => 0, 'names' => []];
        $scope['names'][$name] = ($scope['names'][$name] ?? 0) - 1;

        if ($stillHeld && $scope['names'][$name] <= 0) {
            $locks[$db] = $scope;

            return false;
        }

        if ($scope['names'][$name] <= 0) {
            unset($scope['names'][$name]);
        }

        $scope['open']--;

        if ($scope['open'] > 0) {
            $locks[$db] = $scope;

            return true;
        }

        unset($locks[$db]);
        self::flush($db);

        return true;
    }

    /**
     * Hold the connection's waiting work, as a lock scope without a lock, until resume() with the same name: for a
     * caller that has its own work to finish after its commit before what that commit owes runs (an order's
     * placement: its wallet and gateway hooks first, then its confirmation e-mail).
     *
     * @param DatabaseDriver $db The connection.
     * @param string $name What holds it.
     *
     * @since  2.1.4
     */
    public static function hold(DatabaseDriver $db, string $name): void
    {
        self::enterLock(db: $db, name: $name);
    }

    /**
     * End a hold(), and run the waiting work when no lock scope and no transaction is left open.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $name What held it.
     *
     * @since  2.1.4
     */
    public static function resume(DatabaseDriver $db, string $name): void
    {
        self::leaveLock(db: $db, name: $name);
    }

    /**
     * Whether the connection holds one of Alfa's named locks.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.1.4
     */
    public static function inLock(DatabaseDriver $db): bool
    {
        return (self::locks()[$db]['open'] ?? 0) > 0;
    }

    /**
     * Run the work at once when nothing is open on the connection, else queue
     * it at the level given (0 while only a lock scope is open).
     *
     * @param DatabaseDriver $db The connection.
     * @param int $level The level that owns the work.
     * @param string $label What the work does.
     * @param callable $work The work.
     *
     * @since  2.1.4
     */
    private static function queue(DatabaseDriver $db, int $level, string $label, callable $work): void
    {
        if ($level < 1 && !self::inLock($db)) {
            self::run(db: $db, label: $label, work: $work);

            return;
        }

        $queues = self::queues();
        $items = $queues[$db] ?? [];
        $items[] = ['level' => max(0, $level), 'label' => $label, 'work' => $work];
        $queues[$db] = $items;
    }

    /**
     * Run the connection's waiting work, oldest first.
     *
     * Nothing runs while the connection holds a transaction or a lock scope:
     * the outermost commit, or the release of the last lock, runs it. Work
     * whose transaction committed while a lock scope is open is committed
     * work from then on, and no later rollback on the connection discards it.
     * The queue is taken before the first item runs, so an item that defers
     * work of its own inside its own transaction has it run after that
     * transaction's commit, and nothing runs twice.
     *
     * The notice journal is written first ({@see Tasks::applyJournal()}), at
     * the outermost commit even while a lock scope is open (s71), so other
     * requests and the work after it read the notifications its transactions
     * raised. Inside a lock scope the work its writes defer (their
     * announcements) waits with the work the commit released, after the work
     * of earlier commits, in the order it runs without a lock scope.
     *
     * @param DatabaseDriver $db The connection.
     *
     *
     * @since  2.0.0
     */
    public static function flush(DatabaseDriver $db): void
    {
        if (TransactionState::isOpen($db)) {
            return;
        }

        $queues = self::queues();
        $locked = self::inLock($db);
        $journal = [];
        $earlier = [];
        $released = [];

        foreach ($queues[$db] ?? [] as $item) {
            if (isset($item['journal'])) {
                $journal[] = $item['journal'];
            } elseif ($locked && $item['level'] < 1) {
                $earlier[] = $item;
            } else {
                $released[] = ['level' => 0] + $item;
            }
        }

        unset($queues[$db]);

        if ($journal !== []) {
            self::run(db: $db, label: 'the notice journal', work: static function () use ($db, $journal): void {
                Tasks::applyJournal(db: $db, entries: $journal);
            });
        }

        if ($locked) {
            $items = [...$earlier, ...($queues[$db] ?? []), ...$released];

            if ($items === []) {
                unset($queues[$db]);
            } else {
                $queues[$db] = $items;
            }

            return;
        }

        foreach ($released as $item) {
            self::run(db: $db, label: $item['label'], work: $item['work']);
        }
    }

    /**
     * Discard the waiting work of the levels that were rolled back.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $fromDepth The outermost level rolled back; 1 = every level.
     *
     *
     * @since  2.0.0
     */
    public static function drop(DatabaseDriver $db, int $fromDepth = 1): void
    {
        $queues = self::queues();
        $items = array_values(array_filter(
            $queues[$db] ?? [],
            static fn (array $item): bool => $item['level'] < $fromDepth,
        ));

        if ($items === []) {
            unset($queues[$db]);

            return;
        }

        $queues[$db] = $items;
    }

    /**
     * Hand the work of a released savepoint to the level around it.
     *
     * Called by TransactionState::commitLevel(). The released work commits or
     * rolls back with the enclosing level from now on, so a later savepoint at
     * the same depth that is rolled back does not take it.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $level The level that was released (2 or deeper).
     *
     *
     * @since  2.0.0
     */
    public static function release(DatabaseDriver $db, int $level): void
    {
        $queues = self::queues();

        if (!isset($queues[$db])) {
            return;
        }

        $queues[$db] = array_map(
            static fn (array $item): array => $item['level'] >= $level
                ? ['level' => $level - 1] + $item
                : $item,
            $queues[$db],
        );
    }

    /**
     * Run one item, so that its failure stays its own.
     *
     * A transaction the item leaves open is rolled back: the work after it and
     * the caller must not run inside it.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $label What the work does.
     * @param callable $work The work.
     *
     *
     * @since  2.0.0
     */
    private static function run(DatabaseDriver $db, string $label, callable $work): void
    {
        $failure = null;

        try {
            $work();
        } catch (Throwable $e) {
            $failure = $e;
            self::report(entry: "Post-commit work '{$label}' failed: " . $e::class . ': ' . $e->getMessage()
                . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
        }

        $left = TransactionState::level($db);

        if ($left < 1) {
            return;
        }

        $failure ??= new RuntimeException("Post-commit work '{$label}' left a transaction open.");

        for ($guard = $left; $guard > 0; $guard--) {
            if (TransactionState::rollBackLevel(db: $db, failure: $failure)) {
                break;
            }
        }

        self::report(entry: "Post-commit work '{$label}' left a transaction open; it was rolled back.");
    }

    /**
     * Write an ERROR line that can never change the outcome it reports.
     *
     * @param string $entry The line.
     *
     *
     * @since  2.0.0
     */
    private static function report(string $entry): void
    {
        try {
            AlfaLog::register();
            Log::add(entry: $entry, priority: Log::ERROR, category: 'com_alfa');
        } catch (Throwable) {
            // An unwritable log changes nothing that already happened.
        }
    }

    /**
     * The queues, made on first use.
     *
     *
     * @since  2.0.0
     */
    private static function queues(): WeakMap
    {
        return self::$queues ??= new WeakMap();
    }

    /**
     * The lock scopes, made on first use.
     *
     *
     * @since  2.1.4
     */
    private static function locks(): WeakMap
    {
        return self::$locks ??= new WeakMap();
    }
}
