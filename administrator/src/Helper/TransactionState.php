<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Joomla\Database\DatabaseDriver;
use ReflectionProperty;
use RuntimeException;
use Throwable;

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
     * Whether the connection holds an open transaction or savepoint.
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
