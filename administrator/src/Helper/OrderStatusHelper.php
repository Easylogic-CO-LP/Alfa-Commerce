<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;

/**
 * OrderStatusHelper
 *
 * Semantic lookups over `#__alfa_orders_statuses`. Statuses are user-defined
 * (no seeded IDs, no constants) so any code that needs "the brand-new-order
 * status" or "the cancellation family" must ask this helper rather than
 * hardcoding an ID. Admin marks which rows own each role via the three
 * flags in the orderstatus form; OrderstatusModel enforces the invariants:
 *
 *   • is_initial   — singleton: exactly one row across the whole table.
 *   • is_cancelled — multi-row family: any number of rows (Returned,
 *                    Refunded, Cancelled all carry it).
 *   • is_completed — multi-row family: any number of rows (Delivered,
 *                    Picked up, Paid all carry it).
 *
 * Hence getInitialId() returns a single id while getCancelledIds() /
 * getCompletedIds() return arrays — callers that need "is this status
 * in the cancelled family?" should use isCancelled($id) instead of
 * walking the ids list.
 *
 * Results are memoised for the lifetime of the request. Callers that need
 * fresh data after writing should call self::clearCache().
 *
 * @since   1.0.1
 */
class OrderStatusHelper
{
    /**
     * Default ORDER BY column for getAll() and the lookups built on it.
     */
    private const DEFAULT_ORDER_COLUMN = 'ordering';

    /**
     * Request-scoped cache of every status row keyed by id, indexed by
     * the ORDER BY column used to retrieve them. Callers passing
     * different order columns each get their own cached slice so the
     * iteration order matches what they asked for.
     *
     * Shape: ['ordering' => [id => row, …], 'name' => [id => row, …]]
     *
     * @var array<string, array<int, object>>
     */
    protected static array $statusesCache = [];

    /**
     * Load every status row keyed by id, ordered by the requested column.
     *
     * Memoised per `$orderBy` value for the lifetime of the request.
     * Includes unpublished rows on purpose — an order may still
     * reference a status that's been unpublished, and admin-side
     * activity logging needs the historical name without falling
     * through to a blank cell.
     *
     * @param string|null $orderBy Status column to ORDER BY. Defaults
     *                             to `ordering` when null / empty. The
     *                             column name is wrapped in quoteName()
     *                             so admins can't inject SQL via the
     *                             argument.
     *
     * @return array<int, object> Rows keyed by PK, iteration order
     *                            matching the requested column (with
     *                            `id ASC` as the deterministic
     *                            tiebreaker).
     *
     * @since   1.0.1
     */
    public static function getAll(?string $orderBy = null): array
    {
        $orderColumn = ($orderBy !== null && $orderBy !== '') ? $orderBy : self::DEFAULT_ORDER_COLUMN;

        if (isset(self::$statusesCache[$orderColumn])) {
            return self::$statusesCache[$orderColumn];
        }

        try {
            $db    = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_orders_statuses'))
                ->order($db->quoteName($orderColumn) . ' ASC')
                ->order($db->quoteName('id') . ' ASC');

            $db->setQuery(query: $query);
            $rows = $db->loadObjectList('id') ?: [];
        } catch (Exception $e) {
            Log::add(
                entry:    'OrderStatusHelper::getAll failed: ' . $e->getMessage(),
                priority: Log::ERROR,
                category: 'com_alfa.orderstatus',
            );
            $rows = [];
        }

        self::$statusesCache[$orderColumn] = $rows;

        return $rows;
    }

    /**
     * Reset the request-scoped cache for every order column.
     *
     * Call after writing to `#__alfa_orders_statuses` so the next
     * lookup re-queries the DB.
     *
     * @return void
     *
     * @since   1.0.1
     */
    public static function clearCache(): void
    {
        self::$statusesCache = [];
    }

    /**
     * Resolve the ID of the row marked is_initial = 1.
     *
     * Falls back to the lowest-id published row, then to null. The
     * fallback exists so a freshly installed system without a
     * nominated initial status still produces a sensible default for
     * new-order creation; in steady state the cross-row enforcer in
     * OrderstatusModel guarantees exactly one is_initial holder.
     *
     * @return int|null The status PK or null if the table is empty.
     *
     * @since   1.0.1
     */
    public static function getInitialId(): ?int
    {
        return self::resolveRoleId(flag: 'is_initial', allowFallback: true);
    }

    /**
     * Return every status id whose is_cancelled flag is set to 1.
     *
     * The cancellation role is multi-row by design — "Returned",
     * "Refunded", and "Cancelled" can all be in the family. Callers
     * that want to test a single id should prefer isCancelled() over
     * walking this list.
     *
     * @return int[] Status PKs in the cancelled family. Empty array
     *               when no row has been nominated yet.
     *
     * @since   1.0.1
     */
    public static function getCancelledIds(): array
    {
        return self::resolveRoleIds(flag: 'is_cancelled');
    }

    /**
     * Return every status id whose is_completed flag is set to 1.
     *
     * The completed role is multi-row by design — "Delivered",
     * "Picked up", and "Paid" can all be in the family.
     *
     * @return int[] Status PKs in the completed family. Empty array
     *               when no row has been nominated yet.
     *
     * @since   1.0.1
     */
    public static function getCompletedIds(): array
    {
        return self::resolveRoleIds(flag: 'is_completed');
    }

    /**
     * Test whether a given status id is the initial status.
     *
     * @param int|null $statusId The status id to test.
     *
     * @return bool True only when $statusId matches the row marked is_initial.
     *
     * @since   1.0.1
     */
    public static function isInitial(?int $statusId): bool
    {
        return self::hasFlag(statusId: $statusId, flag: 'is_initial');
    }

    /**
     * Test whether a given status id is the cancellation status.
     *
     * @param int|null $statusId The status id to test.
     *
     * @return bool True only when $statusId matches the row marked is_cancelled.
     *
     * @since   1.0.1
     */
    public static function isCancelled(?int $statusId): bool
    {
        return self::hasFlag(statusId: $statusId, flag: 'is_cancelled');
    }

    /**
     * Test whether a given status id is the completion status.
     *
     * @param int|null $statusId The status id to test.
     *
     * @return bool True only when $statusId matches the row marked is_completed.
     *
     * @since   1.0.1
     */
    public static function isCompleted(?int $statusId): bool
    {
        return self::hasFlag(statusId: $statusId, flag: 'is_completed');
    }

    /**
     * Get a single status row by id, or null if missing.
     *
     * @param int|null $statusId The status PK.
     *
     * @return object|null Status row or null when unknown.
     *
     * @since   1.0.1
     */
    public static function getById(?int $statusId): ?object
    {
        if (!$statusId) {
            return null;
        }

        return self::getAll()[$statusId] ?? null;
    }

    /**
     * Resolve the id of the row that owns a given singleton role flag.
     *
     * Used only by getInitialId() now that is_cancelled / is_completed
     * are multi-row families (which go through resolveRoleIds() instead).
     *
     * @param string $flag          The singleton role flag column.
     * @param bool   $allowFallback When true and no row owns the role,
     *                              fall back to the first published row
     *                              by `ordering`. Used by is_initial so
     *                              new orders always get a status even
     *                              before nomination.
     *
     * @return int|null Status PK or null if nothing matches.
     *
     * @since   1.0.1
     */
    protected static function resolveRoleId(string $flag, bool $allowFallback): ?int
    {
        foreach (self::getAll() as $row) {
            if ((int) ($row->{$flag} ?? 0) === 1) {
                return (int) $row->id;
            }
        }

        if (!$allowFallback) {
            return null;
        }

        // Fallback: first published row in admin-configured ordering.
        foreach (self::getAll() as $row) {
            if ((int) ($row->state ?? 0) === 1) {
                return (int) $row->id;
            }
        }

        return null;
    }

    /**
     * Resolve every row id whose multi-row role flag is set to 1.
     *
     * Ordering follows the cached order (set in getAll(): ordering ASC),
     * so callers that walk the array see the admin-controlled drag order.
     *
     * @param string $flag One of: is_cancelled, is_completed.
     *
     * @return int[] Row PKs in the family. Empty when no row holds the
     *               flag.
     *
     * @since   1.0.1
     */
    protected static function resolveRoleIds(string $flag): array
    {
        $ids = [];

        foreach (self::getAll() as $row) {
            if ((int) ($row->{$flag} ?? 0) === 1) {
                $ids[] = (int) $row->id;
            }
        }

        return $ids;
    }

    /**
     * Shared body of the is{Role} checks.
     *
     * @param int|null $statusId The status id under test.
     * @param string   $flag     Role flag column name.
     *
     * @return bool True when the row identified by $statusId has $flag = 1.
     *
     * @since   1.0.1
     */
    protected static function hasFlag(?int $statusId, string $flag): bool
    {
        if (!$statusId) {
            return false;
        }

        $row = self::getById(statusId: $statusId);

        return $row !== null && (int) ($row->{$flag} ?? 0) === 1;
    }
}
