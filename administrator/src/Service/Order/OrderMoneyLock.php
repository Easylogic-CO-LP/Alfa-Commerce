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
 * One money operation at a time per order: refund, capture or release.
 *
 * A gateway's call can take seconds, and nothing is booked until the
 * provider answers. A second operation asked in that window, from a second
 * tab, a second admin or a resent form, would pass every check the first one
 * passed. The gateway runs its checks, the provider call and the booking
 * inside run(); a second operation on the same order is refused while the
 * first holds the lock.
 *
 * A named database lock, taken without waiting. It belongs to the database
 * connection, so a request that dies frees it. Named locks are server-wide,
 * so the name carries this site's database and table prefix, hashed to stay
 * within the 64 characters MySQL and MariaDB allow.
 *
 * It fails closed: a lock that cannot be taken is an exception, and the
 * operation is not sent.
 *
 * @since  2.0.0
 */
final class OrderMoneyLock
{
    /**
     * Runs the work while holding the order's money lock.
     *
     * The lock is re-entrant for the connection that holds it, so a request
     * never refuses itself.
     *
     * @param int $orderId The order the operation is on.
     * @param callable $work Called with no arguments.
     *
     * @return bool False when another request holds the lock; the work did not run.
     *
     * @throws RuntimeException When the lock cannot be taken.
     *
     * @since  2.0.0
     */
    public static function run(int $orderId, callable $work): bool
    {
        if ($orderId <= 0) {
            throw new RuntimeException('An order money lock needs an order id; got ' . $orderId . '.');
        }

        // The connection that takes the lock is the one that releases it.
        $db = Factory::getContainer()->get('DatabaseDriver');
        $name = $db->quote(self::name($db, $orderId));
        $got = $db->setQuery('SELECT GET_LOCK(' . $name . ', 0)')->loadResult();

        if ($got === null) {
            throw new RuntimeException('The money lock of order #' . $orderId . ' could not be taken: the database returned no answer.');
        }

        if ((int) $got !== 1) {
            return false;
        }

        try {
            $work();
        } finally {
            try {
                $db->setQuery('SELECT RELEASE_LOCK(' . $name . ')')->loadResult();
            } catch (Throwable $e) {
                // The lock still ends with the connection, at the end of the request.
                Log::add('The money lock of order #' . $orderId . ' could not be released: ' . $e->getMessage(), Log::WARNING, 'com_alfa.payments');
            }
        }

        return true;
    }

    /**
     * The lock's name for an order on this site.
     *
     * @param DatabaseInterface $db The site's database.
     * @param int $orderId The order.
     *
     * @return string At most 43 characters.
     *
     * @since  2.0.0
     */
    public static function name(DatabaseInterface $db, int $orderId): string
    {
        $site = (string) Factory::getApplication()->get('db') . '.' . $db->getPrefix();

        return 'alfa.money.' . substr(hash('sha256', $site), 0, 16) . '.' . $orderId;
    }
}
