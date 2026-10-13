<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

/**
 * What an ENABLED location is: the ONE definition, for every consumer.
 *
 * `#__alfa_inventory_locations.state` existed from the first inventory release and
 * the admin honoured it, but nothing in the kernel ever read it: a disabled shelf
 * stayed salable, kept receiving allocations, kept being restocked. Disabling is
 * also the ONLY retirement route a merchant has ({@see \Alfa\Component\Alfa\Administrator\Model\LocationModel::canDelete()}
 * refuses to delete any location carrying stock, reservations or ledger history),
 * so "disabled" had to mean something and meant nothing.
 *
 * ## The contract this class owns
 *
 * For a DISABLED location:
 *
 *     no new sale            it does not count toward what the shop can sell
 *     no allocation          no reservation is ever placed there
 *     no receiving/restock   a positive delta is refused
 *     transfers OUT allowed  the escape route off a retired shelf
 *     transfers IN forbidden
 *     existing reservations  still fulfillable: goods already promised ship
 *     always visible         admin and reports keep showing it and its stock
 *
 * The last two are why this is a class and not four inlined `state = 1` tests.
 * The contract is asymmetric: it gates what ENTERS a location and what the shop
 * may PROMISE from it, never what LEAVES one, and an ad-hoc check written at the
 * fifth call site is exactly where that asymmetry gets lost, trapping the goods on
 * the shelf the merchant was trying to retire.
 *
 * ## Why the predicate is a subquery and nothing is cached
 *
 * Consumers ask across a request that may itself flip a location's state (the
 * locations admin saves, then a listing renders). A cached id list is stale in
 * precisely that request. The table is tiny (a shop has single-digit locations),
 * so the subquery costs nothing measurable, and it is always current.
 *
 * @since  2.0.0
 */
final class LocationPolicy
{
    /**
     * SQL predicate restricting a location-id column to ENABLED locations.
     *
     * Written as an `IN (SELECT ...)` rather than a join so it drops into an
     * existing aggregate or `Query` object without disturbing its shape.
     *
     * @param DatabaseInterface $db The connection (for identifier quoting).
     * @param string $column Fully qualified location-id column.
     *
     * @return string A boolean SQL fragment.
     *
     * @since  2.0.0
     */
    public static function enabledPredicate(DatabaseInterface $db, string $column): string
    {
        return $column . ' IN (SELECT ' . $db->quoteName('id')
            . ' FROM ' . $db->quoteName('#__alfa_inventory_locations')
            . ' WHERE ' . $db->quoteName('state') . ' = 1)';
    }

    /**
     * Every enabled location id, default first, then the merchant's ordering.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    public static function enabledIds(DatabaseInterface $db): array
    {
        $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_inventory_locations'))
                ->where($db->quoteName('state') . ' = 1')
                ->order($db->quoteName('is_default') . ' DESC, ' . $db->quoteName('ordering') . ', ' . $db->quoteName('id')),
        );

        return array_map('intval', $db->loadColumn() ?: []);
    }

    /**
     * Whether a location may receive new stock, allocations and reservations.
     *
     * FAIL-CLOSED, and deliberately so on both halves: a non-positive id is not a
     * location at all (the contract names the phantom-location seam explicitly), and an id
     * matching no row is not a location either. Both answer false rather than
     * letting the caller invent a shelf by writing to it.
     *
     * A command that acts on the answer inside its transaction reads it with a
     * shared lock, so the location cannot change state until that command commits;
     * a read outside any stock transaction passes false.
     *
     * @param DatabaseInterface $db The connection.
     * @param int $locationId The location.
     * @param bool $lockInShareMode Read the row with a shared lock (current read).
     *
     *
     * @since  2.0.0
     */
    public static function isEnabled(DatabaseInterface $db, int $locationId, bool $lockInShareMode): bool
    {
        if ($locationId <= 0) {
            return false;
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName('state'))
            ->from($db->quoteName('#__alfa_inventory_locations'))
            ->where($db->quoteName('id') . ' = ' . $locationId);

        $db->setQuery($lockInShareMode ? (string) $query . ' LOCK IN SHARE MODE' : $query);

        $state = $db->loadResult();

        return $state !== null && (int) $state === 1;
    }
}
