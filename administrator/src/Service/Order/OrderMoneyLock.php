<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\PostCommit;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * One capture or release at a time per order.
 *
 * A gateway's call can take seconds, and nothing is booked until the
 * provider answers. A second operation asked in that window, from a second
 * tab, a second admin or a resent form, would pass every check the first one
 * passed. For a capture or a release the gateway runs its checks, the
 * provider call and the booking inside run(); a second operation on the same
 * order is refused while the first holds the lock.
 *
 * NOT for a refund (since 2.1.0). A refund start is decided and reserved in
 * the books before the provider is asked (PaymentLedger::reserveRefund(),
 * under the order's short row lock), so every other start counts it at once,
 * and no lock is held across the provider's call. The core never takes this
 * lock; a gateway may still hold it around its own log write and booking,
 * never across a call to the provider.
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
     * never refuses itself. Work deferred while it is held, inside a
     * transaction or not, runs after its release ({@see PostCommit}), so no
     * e-mail or plugin keeps a second operation on the order refused as busy.
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

        // Work deferred while the lock is held (an e-mail, a plugin's reaction, a task) waits for its release.
        PostCommit::enterLock($db, name: $name);

        try {
            $work();
        } finally {
            $released = null;

            try {
                $released = $db->setQuery('SELECT RELEASE_LOCK(' . $name . ')')->loadResult();
            } catch (Throwable $e) {
                // The lock still ends with the connection, at the end of the request.
                Log::add('The money lock of order #' . $orderId . ' could not be released: ' . $e->getMessage(), Log::WARNING, 'com_alfa.payments');
            }

            if (!PostCommit::leaveLock($db, name: $name, stillHeld: self::stillHeld($db, $name))) {
                Log::add('The money lock of order #' . $orderId . ' is still held by this connection after its release (answer '
                    . var_export($released, true) . '): the work deferred inside it waits, and nothing deferred runs on this connection in this request.', Log::CRITICAL, 'com_alfa.payments');
            }
        }

        return true;
    }

    /**
     * Whether this connection still holds a named lock; false when it cannot be asked (the connection is gone, and its
     * locks with it).
     *
     * @param DatabaseInterface $db The connection.
     * @param string $quoted The lock's name, quoted.
     *
     * @since  2.1.4
     */
    public static function stillHeld(DatabaseInterface $db, string $quoted): bool
    {
        try {
            return (int) $db->setQuery('SELECT IS_USED_LOCK(' . $quoted . ') = CONNECTION_ID()')->loadResult() === 1;
        } catch (Throwable) {
            return false;
        }
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
