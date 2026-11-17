<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use LogicException;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use WeakMap;

defined('_JEXEC') or die;

/**
 * Whether a transaction is open on a connection, and how a level is closed
 * after a failure inside it.
 *
 * Asked before any CREATE or ALTER: MySQL commits the open transaction before
 * DDL, so schema work run inside a caller's transaction commits the caller's
 * work half done and leaves the driver counting a transaction the server no
 * longer has. Every Alfa transaction is opened through Joomla's driver, which
 * keeps the depth of the transactions and savepoints it opened.
 *
 * A deadlock ends the server's whole transaction, every savepoint of it
 * included. Rolling back to a savepoint then fails ("SAVEPOINT does not
 * exist"), and that failure must neither replace the deadlock nor leave the
 * driver counting levels the server no longer has: a caller that is answered
 * with a refusal instead of the deadlock commits, and its earlier work is
 * already gone.
 *
 * A level is committed through commitLevel() and closed after a failure
 * through rollBackLevel(): they run or discard the work PostCommit holds for
 * it, so nothing deferred runs before the outermost commit or after a
 * rollback.
 *
 * @since  2.0.0
 */
final class TransactionState
{
    /**
     * The connection as the driver whose transaction levels this class keeps (2.2.2): the narrowing of a connection to
     * a DatabaseDriver for TransactionState and PostCommit that the cart, the price index, the item field projection
     * and the inventory service use, each once, in its constructor, closing the levels it opens through commitLevel()
     * or rollBackLevel() on what this returns. A few older places still narrow on their own. Every connection
     * Joomla's container hands out is a driver.
     *
     * @api ANSWERS: the same connection as the driver whose levels this class keeps; a connection that is not one is
     *      refused with a LogicException.
     *
     * @param DatabaseInterface $db The connection.
     *
     * @return DatabaseDriver The same connection.
     *
     * @throws LogicException When the connection is not a database driver.
     *
     * @since  2.2.2
     */
    public static function driver(DatabaseInterface $db): DatabaseDriver
    {
        if (!$db instanceof DatabaseDriver) {
            throw new LogicException(
                'Alfa Commerce closes its transaction levels through TransactionState, which keeps the levels of a database '
                . 'driver; ' . get_debug_type($db) . ' is not one.',
            );
        }

        return $db;
    }

    /**
     * How many times each connection rolled a level back.
     *
     * @var WeakMap<DatabaseDriver, int>|null
     *
     * @since  2.1.4
     */
    private static ?WeakMap $rollbacks = null;

    /**
     * How many times the connection rolled a level back through {@see self::rollBackLevel()}: a request memo of what
     * the database holds stays valid only while this is unchanged, since a rollback undoes what it remembered.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.1.4
     */
    public static function rollbacks(DatabaseDriver $db): int
    {
        return (self::$rollbacks ??= new WeakMap())[$db] ?? 0;
    }

    /**
     * Whether the connection holds an open transaction or savepoint.
     *
     * @api READ ONLY: whether the driver counts an open transaction or savepoint; inside core's own transaction the
     *      open level is core's.
     *
     * @param DatabaseDriver $db The connection.
     *
     *
     * @since  2.0.0
     */
    public static function isOpen(DatabaseDriver $db): bool
    {
        return self::level($db) > 0;
    }

    /**
     * The number of transaction levels the driver counts on the connection.
     *
     * 1 is the outermost transaction, each savepoint inside it one more; 0 (or
     * less, on the PDO driver after a level was closed twice) is none.
     *
     * @param DatabaseDriver $db The connection.
     *
     *
     * @since  2.0.0
     */
    public static function level(DatabaseDriver $db): int
    {
        return (int) self::depth()->getValue($db);
    }

    /**
     * Commit the level a method opened with transactionStart(true).
     *
     * A savepoint is released, and the work deferred inside it waits for the
     * level around it. The outermost level is committed, and the work deferred
     * inside the transaction then runs (PostCommit::flush()). A COMMIT that
     * fails is closed here, through rollBackLevel() with its failure, which
     * discards that work, and then reaches the caller: no caller is left
     * counting a level the server no longer holds. A caller's own
     * rollBackLevel() then finds no level open, and only drops.
     *
     * @param DatabaseDriver $db The connection.
     *
     *
     * @throws RuntimeException When no level is open: the work it was to commit is already gone.
     *
     * @since  2.0.0
     */
    public static function commitLevel(DatabaseDriver $db): void
    {
        $level = self::level($db);

        if ($level < 1) {
            throw new RuntimeException('No transaction is open to commit: the work it held was already rolled back.');
        }

        if ($level > 1) {
            $db->transactionCommit(true);
            PostCommit::release(db: $db, level: $level);

            return;
        }

        try {
            $db->transactionCommit(true);

            if (self::isOpen($db)) {
                throw new RuntimeException('The transaction was not committed: the driver still counts it open.');
            }
        } catch (Throwable $e) {
            self::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        PostCommit::flush($db);
    }

    /**
     * Close the level a method opened with transactionStart(true), after a
     * failure inside it.
     *
     * Rolls back to the level's savepoint, or the whole transaction when the
     * level is the outermost one. When the server has already ended the whole
     * transaction (a deadlock), or the savepoint cannot be rolled back to, no
     * level of the transaction is left to keep: it is rolled back whole, on
     * the server as well as in the driver's count, which counts no
     * transaction afterwards. A level that an inner
     * call already closed this way is not closed again. The work PostCommit
     * holds for every level rolled back is discarded.
     *
     * The caller rethrows its original failure. A method that answers a
     * refusal instead does so only while its caller's transaction is still
     * open: when this answers true and the method was called inside a
     * transaction, that transaction has ended, and the caller must hear the
     * failure itself.
     *
     * A level that refuses, with nothing failed, is closed the same way
     * without a failure.
     *
     * @api ANSWERS: closes only the caller's own level (the whole transaction when the server ended it or the savepoint
     *      is gone) and answers true when no transaction is left; called inside core's transaction, true means core's
     *      work is gone too, and the caller rethrows its failure, never answering a refusal.
     *
     * @param DatabaseDriver $db The connection.
     * @param Throwable|null $failure What failed inside the level; null for a refusal.
     *
     * @return bool True when no transaction is left on the connection.
     *
     * @since  2.0.0
     */
    public static function rollBackLevel(DatabaseDriver $db, ?Throwable $failure = null): bool
    {
        $level = self::level($db);

        if ($level < 1) {
            PostCommit::drop(db: $db);

            return true;
        }

        $rollbacks = self::$rollbacks ??= new WeakMap();
        $rollbacks[$db] = ($rollbacks[$db] ?? 0) + 1;

        if ($failure === null || MysqlError::number($failure) !== 1213) {
            try {
                $db->transactionRollback(true);
                PostCommit::drop(db: $db, fromDepth: $level);

                return !self::isOpen($db);
            } catch (Throwable) {
                // The savepoint went with the transaction.
            }
        }

        try {
            $db->transactionRollback();
        } catch (Throwable) {
            // The driver found no transaction to roll back and sent nothing: the
            // PDO driver asks the server, which reports none after a deadlock.
            // Yet the server starts the next transaction implicitly, and keeps
            // every write after it uncommitted, until a ROLLBACK reaches it.
            try {
                $db->setQuery('ROLLBACK')->execute();
            } catch (Throwable) {
                // The connection is gone, and its transaction with it.
            }
        }

        // MySQLi counts no transaction after a full rollback; the PDO driver
        // lowers its count by one, or not at all when the server had none
        // left. The server holds none now either way.
        self::depth()->setValue($db, 0);
        PostCommit::drop(db: $db);

        return true;
    }

    /**
     * Whether a failure ended the transaction the connection holds.
     *
     * A method that answers a failed statement with a refusal (false, a
     * status) asks this first: a deadlock has ended the whole transaction it
     * ran in, and a refusal would let the transaction's owner commit work the
     * server has already discarded. Outside a transaction a deadlock undid
     * only its own statement, and the refusal stands.
     *
     * @param DatabaseDriver $db The connection.
     * @param Throwable $failure What failed.
     *
     * @return bool True when the failure must be rethrown.
     *
     * @since  2.0.0
     */
    public static function endedBy(DatabaseDriver $db, Throwable $failure): bool
    {
        return MysqlError::number($failure) === 1213 && self::isOpen($db);
    }

    /**
     * The driver's count of the transactions and savepoints it opened.
     *
     *
     * @since  2.0.0
     */
    private static function depth(): ReflectionProperty
    {
        // Read against Joomla 6.1.3 (joomla/database 4.0.0): the driver offers no
        // reader for the depth it keeps in the protected $transactionDepth.
        return new ReflectionProperty(DatabaseDriver::class, 'transactionDepth');
    }
}
