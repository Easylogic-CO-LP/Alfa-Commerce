<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Projection;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Per-item advisory lock guarding projection rebuilds against interleaving.
 *
 * Two overlapping saves of the same item (two admin tabs, an API call racing a
 * UI save) could otherwise delete-then-insert each other's rows out of order.
 * A MySQL named lock (`GET_LOCK`) serialises them across connections/processes:
 * the second rebuild simply skips, and the first one's result stands
 * (last-write-wins is deterministic because each rebuild is a full delete +
 * insert from the same source of truth).
 *
 * Acquisition is non-blocking (timeout 0): a skipped rebuild is harmless
 * because the winning rebuild already wrote the current state.
 *
 * @since  1.3.0
 */
class IndexRebuildLock
{
    /** Lock-name prefix; scopes the named lock to this subsystem. */
    private const PREFIX = 'alfa_reindex_';

    /** @var DatabaseInterface */
    private $db;

    /** @var array<int, bool> Locks currently held by this instance, by item id. */
    private array $held = [];

    /**
     * @param DatabaseInterface|null $db Injected for testing; defaults to the container driver.
     *
     * @since  1.3.0
     */
    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db ?? Factory::getContainer()->get('DatabaseDriver');
    }

    /**
     * Try to acquire the rebuild lock for an item without blocking.
     *
     * @param int $itemId The item primary key.
     *
     * @return bool True if the lock is now held (caller may proceed); false if
     *              another connection holds it (caller should skip).
     * @since  1.3.0
     */
    public function acquire(int $itemId): bool
    {
        if ($itemId <= 0) {
            return false;
        }

        try {
            $name = $this->db->quote(self::PREFIX . $itemId);
            $got = (int) $this->db->setQuery('SELECT GET_LOCK(' . $name . ', 0)')->loadResult();
        } catch (Throwable $e) {
            // If locking is unavailable, don't block the rebuild; fall through.
            return true;
        }

        if ($got === 1) {
            $this->held[$itemId] = true;

            return true;
        }

        return false;
    }

    /**
     * Release a previously acquired lock. No-op if not held by this instance.
     *
     * @param int $itemId The item primary key.
     *
     * @since  1.3.0
     */
    public function release(int $itemId): void
    {
        if (empty($this->held[$itemId])) {
            return;
        }

        try {
            $name = $this->db->quote(self::PREFIX . $itemId);
            $this->db->setQuery('SELECT RELEASE_LOCK(' . $name . ')')->execute();
        } catch (Throwable $e) {
            // Best-effort release; the lock is also freed when the session ends.
        }

        unset($this->held[$itemId]);
    }
}
