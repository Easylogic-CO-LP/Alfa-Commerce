<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
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
 * @since  1.14.0
 */
final class PostCommit
{
    /**
     * The waiting work of each connection, oldest first.
     *
     * @var WeakMap<DatabaseDriver, list<array{level: int, label: string, work: callable}>>|null
     *
     * @since  1.14.0
     */
    private static ?WeakMap $queues = null;

    /**
     * Run work once the connection's transaction has committed.
     *
     * A failure of the work is logged under its label and never reaches the
     * caller: when it runs, the work it follows is already committed.
     *
     * @param DatabaseDriver $db The connection the work's transaction runs on.
     * @param string $label What the work does, as the log names it.
     * @param callable $work fn(): void.
     *
     *
     * @since  1.14.0
     */
    public static function defer(DatabaseDriver $db, string $label, callable $work): void
    {
        $level = TransactionState::level($db);

        if ($level < 1) {
            self::run(db: $db, label: $label, work: $work);

            return;
        }

        $queues = self::queues();
        $items = $queues[$db] ?? [];
        $items[] = ['level' => $level, 'label' => $label, 'work' => $work];
        $queues[$db] = $items;
    }

    /**
     * Run the connection's waiting work, oldest first.
     *
     * Nothing runs while the connection holds a transaction: the outermost
     * commit runs it. The queue is taken before the first item runs, so an
     * item that defers work of its own inside its own transaction has it run
     * after that transaction's commit, and nothing runs twice.
     *
     * @param DatabaseDriver $db The connection.
     *
     *
     * @since  1.14.0
     */
    public static function flush(DatabaseDriver $db): void
    {
        if (TransactionState::isOpen($db)) {
            return;
        }

        $queues = self::queues();
        $items = $queues[$db] ?? [];
        unset($queues[$db]);

        foreach ($items as $item) {
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
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
     * @since  1.14.0
     */
    private static function queues(): WeakMap
    {
        return self::$queues ??= new WeakMap();
    }
}
