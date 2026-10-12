<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * A named database lock, taken without waiting, held while a piece of work runs.
 *
 * The lock belongs to the database connection, so a request that dies frees
 * it, and the connection that holds it may take it again. Named locks are
 * server-wide, so the name carries this site's database and table prefix,
 * hashed to stay within the 64 characters MySQL and MariaDB allow.
 *
 * It fails closed: a lock the database gives no answer for is an exception,
 * and the work does not run.
 *
 * @since  1.14.0
 */
trait NamedLock
{
    /**
     * Runs the work while holding the named lock.
     *
     * @param string $name The lock's full name, from lockName().
     * @param string $label What the lock guards, for the log and the exception.
     * @param string $logCategory Where a failed release is logged.
     * @param callable $work Called with no arguments.
     *
     * @return bool False when another connection holds the lock; the work did not run.
     *
     * @throws RuntimeException When the lock cannot be taken.
     *
     * @since  1.14.0
     */
    private static function runLocked(string $name, string $label, string $logCategory, callable $work): bool
    {
        // The connection that takes the lock is the one that releases it.
        $db = Factory::getContainer()->get('DatabaseDriver');
        $quoted = $db->quote($name);
        $got = $db->setQuery('SELECT GET_LOCK(' . $quoted . ', 0)')->loadResult();

        if ($got === null) {
            throw new RuntimeException('The ' . $label . ' could not be taken: the database returned no answer.');
        }

        if ((int) $got !== 1) {
            return false;
        }

        try {
            $work();
        } finally {
            try {
                $db->setQuery('SELECT RELEASE_LOCK(' . $quoted . ')')->loadResult();
            } catch (Throwable $e) {
                // The lock still ends with the connection, at the end of the request.
                Log::add('The ' . $label . ' could not be released: ' . $e->getMessage(), Log::WARNING, $logCategory);
            }
        }

        return true;
    }

    /**
     * A lock name scoped to this site.
     *
     * @param DatabaseInterface $db The site's database.
     * @param string $kind What is locked, e.g. 'booking'.
     * @param int $id The record it is locked for.
     *
     * @return string alfa.<kind>.<site>.<id>
     *
     * @since  1.14.0
     */
    private static function lockName(DatabaseInterface $db, string $kind, int $id): string
    {
        $site = (string) Factory::getApplication()->get('db') . '.' . $db->getPrefix();

        return 'alfa.' . $kind . '.' . substr(hash('sha256', $site), 0, 16) . '.' . $id;
    }
}
