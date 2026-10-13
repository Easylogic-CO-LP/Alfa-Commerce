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
}
