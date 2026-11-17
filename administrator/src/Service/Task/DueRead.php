<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseDriver;
use Throwable;

/**
 * What the triggers' due read names (2.2.1): the task table's types and states and the grace a single waits, the three
 * job handlers the read looks for, the states of a stock movement, and the SQL of the singles' two probes and of an
 * abandoned stock claim.
 *
 * Every storefront page and every API request asks Runner::due() whether work is due. The read used to take these
 * names from Tasks, InventoryEffectOutbox and three job classes, so each request loaded those five large classes for a
 * handful of constants. They take their constants from here now (one definition: Tasks::STATE_PENDING is
 * DueRead::STATE_PENDING), and the five load only when a job runs.
 *
 * @since  2.2.1
 */
final class DueRead
{
    /** @since 2.2.1 */
    public const TABLE = '#__alfa_tasks';

    /** @since 2.2.1 */
    public const TYPE_SINGLE = 'single';

    /** @since 2.2.1 */
    public const TYPE_JOB = 'job';

    /** @since 2.2.1 */
    public const STATE_PENDING = 'pending';

    /** @since 2.2.1 */
    public const STATE_RUNNING = 'running';

    /** @since 2.2.1 */
    public const STATE_FAILED = 'failed';

    /**
     * Seconds a single that never ran waits for its own request before recovery takes it.
     *
     * @since 2.2.1
     */
    public const GRACE_SECONDS = 120;

    /** @since 2.2.1 */
    public const CATALOGUE_REBUILD = 'catalogue.rebuild';

    /** @since 2.2.1 */
    public const PRICE_SET_PREPARE = 'price.set.prepare';

    /** @since 2.2.1 */
    public const STOCK_EFFECTS_DRAIN = 'stock.effects.drain';

    /** @since 2.2.1 */
    public const MOVEMENT_PENDING = 'pending';

    /** @since 2.2.1 */
    public const MOVEMENT_CLAIMED = 'claimed';

    /**
     * Seconds after which a stock movement's claim counts as abandoned, its worker taken to have stopped.
     *
     * @since 2.2.1
     */
    public const STALE_CLAIM_SECONDS = 300;

    /**
     * The two single probes of the due read as SQL with no bound value, each selecting 1: a pending single due for
     * recovery and unreserved, a running single whose lease passed. A process with no application leaves out the
     * singles whose handler needs one; only then are the handlers read (Tasks).
     *
     * @param DatabaseDriver $db The connection.
     *
     * @return list<string>
     *
     * @since  2.2.1
     */
    public static function singleProbes(DatabaseDriver $db): array
    {
        $single = $db->quoteName('type') . ' = ' . $db->quote(self::TYPE_SINGLE);
        $pending = 'SELECT 1 FROM ' . $db->quoteName(self::TABLE) . ' WHERE ' . $single . ' AND ' . $db->quoteName('state') . ' = ' . $db->quote(self::STATE_PENDING)
            . ' AND ' . self::singleDue(db: $db) . ' AND ' . self::unreserved(db: $db);

        // Only a process with no application (a bare worker) reads the handlers, and so loads Tasks; every page, API
        // request and console run has an application and leaves out no handler.
        if (!self::hasApplication()) {
            $needing = Tasks::handlersNeedingApplication();

            if ($needing !== []) {
                $pending .= ' AND ' . $db->quoteName('handler') . ' NOT IN (' . implode(', ', array_map([$db, 'quote'], $needing)) . ')';
            }
        }

        return [
            $pending,
            'SELECT 1 FROM ' . $db->quoteName(self::TABLE) . ' WHERE ' . $single . ' AND ' . $db->quoteName('state') . ' = ' . $db->quote(self::STATE_RUNNING)
                . ' AND ' . $db->quoteName('lease_until') . ' < UTC_TIMESTAMP(3)',
        ];
    }

    /**
     * When a pending single is due for recovery: a retry once its time has come; one that never ran once it is older
     * than the grace, so its own request runs it first.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.2.1
     */
    public static function singleDue(DatabaseDriver $db): string
    {
        return $db->quoteName('run_after') . ' <= UTC_TIMESTAMP(3) AND (' . $db->quoteName('failures') . ' > 0 OR '
            . $db->quoteName('run_after') . ' <= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL ' . self::GRACE_SECONDS . ' SECOND))';
    }

    /**
     * A task no worker holds: no lease owner, or a lease that has passed.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.2.1
     */
    public static function unreserved(DatabaseDriver $db): string
    {
        return '(' . $db->quoteName('lease_owner') . ' IS NULL OR ' . $db->quoteName('lease_until') . ' < UTC_TIMESTAMP(3))';
    }

    /**
     * The one definition of an abandoned stock claim: claimed longer ago than the stale window, so its worker is taken
     * to have stopped. The drain settles it as a failed try; the task runner owes a drain while one stands.
     *
     * @param object $db Database driver.
     * @param int $staleSeconds Age after which a claim counts as abandoned.
     *
     * @return string SQL condition.
     *
     * @since  2.2.1
     */
    public static function abandonedClaimWhere(object $db, int $staleSeconds = self::STALE_CLAIM_SECONDS): string
    {
        return '(' . $db->quoteName('status') . ' = ' . $db->quote(self::MOVEMENT_CLAIMED)
            . ' AND ' . $db->quoteName('claimed_at') . ' <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . max(0, $staleSeconds) . ' SECOND))';
    }

    /**
     * Whether the process has an application: every web, API and console request has one; a bare worker has none.
     *
     * @since  2.2.1
     */
    public static function hasApplication(): bool
    {
        try {
            Factory::getApplication();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
