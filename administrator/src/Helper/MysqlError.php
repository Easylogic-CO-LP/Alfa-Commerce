<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\Exception\PrepareStatementFailureException;
use mysqli_sql_exception;
use PDOException;
use Throwable;

defined('_JEXEC') or die;

/**
 * The MySQL error number of a failed statement, the same on both of Joomla's
 * MySQL drivers.
 *
 * The two drivers report a failure differently, and a code compared directly
 * means something else on each:
 *
 *   MySQLi hands the server's number as the exception code (1062 for a
 *   duplicate key, 1146 for a missing table, 1205 for a lock wait that timed
 *   out), and the server's own text as the message.
 *
 *   MySQL (PDO) hands the SQLSTATE as the code, cast to an integer: every
 *   integrity violation is 23000, a missing table 42, a lock wait 0. The
 *   number is in the message, which the driver writes as the statement's
 *   error triple "SQLSTATE, number, text".
 *
 * The drivers' own exceptions carry it as well: PDO's in its error triple,
 * mysqli's as the code. So a decision taken on one number reads it here, and
 * only a statement failure of one of the two drivers has one: anything else
 * answers 0.
 *
 * @since  2.0.0
 */
final class MysqlError
{
    /**
     * The server's error number of a failed statement.
     *
     * @api READ ONLY: the MySQL error number of a failed statement (1062 a duplicate key, 1205 a lock wait, 1213 a
     *      deadlock), 0 when the failure is not a statement's; read it instead of parsing a message.
     *
     * @param Throwable $error The failure.
     *
     * @return int The MySQL error number, or 0 when the failure is not a statement's.
     *
     * @since  2.0.0
     */
    public static function number(Throwable $error): int
    {
        if ($error instanceof PDOException) {
            return (int) ($error->errorInfo[1] ?? 0);
        }

        if (!$error instanceof ExecutionFailureException
            && !$error instanceof PrepareStatementFailureException
            && !$error instanceof mysqli_sql_exception) {
            return 0;
        }

        // The PDO driver's statement failure: "SQLSTATE, number, text".
        if (preg_match('/^[0-9A-Z]{5}, (\d*), /', $error->getMessage(), $match) === 1) {
            return (int) $match[1];
        }

        return (int) $error->getCode();
    }

    /**
     * Whether a failure is another write holding the same rows at that moment: a deadlock (1213), which the server ended
     * by rolling the whole transaction back, or a lock wait that ran out (1205), on the failure or any it wraps. The
     * same work sent again a moment later finds the rows free.
     *
     * @api READ ONLY: true for 1213 and 1205 anywhere in the failure's chain, on both of Joomla's MySQL drivers.
     *
     * @param Throwable $error The failure.
     *
     * @since  2.3.0
     */
    public static function busy(Throwable $error): bool
    {
        for ($failure = $error; $failure !== null; $failure = $failure->getPrevious()) {
            if (\in_array(self::number(error: $failure), [1205, 1213], true)) {
                return true;
            }
        }

        return false;
    }
}
