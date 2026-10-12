<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

/**
 * The order in which the active reservations of one stock record are served, and
 * what the record's stock on hand covers of each: the one definition the inventory
 * kernel, the inventory audit and the audit page share.
 *
 * A stock record (item, combination, location) can carry more reserved than it
 * holds where the shop allows backorders. Its active reservations are then served
 * in one order: the higher priority first, then the older, then the lower id.
 * Every reservation is created at priority 0, so by default the first reserved is
 * the first served; only the audited override of an administrator raises a
 * priority ({@see InventoryService::prioritiseReservations()}).
 *
 * A reservation's backing is what the record's on-hand covers of it once every
 * reservation ahead of it is covered: on-hand less the remainders ahead of it,
 * never below 0 and never above its own remainder. What it is not covered for
 * waits for stock. A shipment never takes stock that backs a reservation ahead
 * of its own ({@see InventoryService::commitReservation()}).
 *
 * @since  1.14.0
 */
final class ReservationQueue
{
    /**
     * The ORDER BY terms of the order of claims, for a query over reservation rows.
     *
     * @param DatabaseInterface $db The connection (for identifier quoting).
     * @param string $alias The reservations table's alias, or '' for none.
     *
     * @return string Three terms, comma separated.
     *
     * @since  1.14.0
     */
    public static function orderSql(DatabaseInterface $db, string $alias): string
    {
        $column = static fn (string $name): string => ($alias !== '' ? $alias . '.' : '') . $db->quoteName(name: $name);

        return $column(name: 'priority') . ' DESC, ' . $column(name: 'created') . ' ASC, ' . $column(name: 'id') . ' ASC';
    }

    /**
     * Compare two reservation rows in the order of claims: the one served first
     * sorts first.
     *
     * @param object $one A reservation row: priority, created, id.
     * @param object $other Another one.
     *
     * @return int Below 0 when $one is served first.
     *
     * @since  1.14.0
     */
    public static function compare(object $one, object $other): int
    {
        return [-(int) $one->priority, (string) $one->created, (int) $one->id]
            <=> [-(int) $other->priority, (string) $other->created, (int) $other->id];
    }

    /**
     * Sort reservation rows of one stock record into the order of claims.
     *
     * @param object[] $rows Reservation rows: priority, created, id.
     *
     * @return object[] The same rows, the one served first first.
     *
     * @since  1.14.0
     */
    public static function sorted(array $rows): array
    {
        usort(array: $rows, callback: [self::class, 'compare']);

        return $rows;
    }

    /**
     * What a record's on-hand covers of each of its reservations, in the order of
     * claims.
     *
     * @param array<int, array{id: int, remainder: float}> $queue The record's active reservations, in the
     *                                                            order of claims, each with its remainder.
     * @param float $onHand The record's quantity on hand.
     *
     * @return array<int, array{backed: float, waiting: float}> By reservation id: the part the on-hand
     *                                                          covers and the part that waits for stock.
     *
     * @since  1.14.0
     */
    public static function backing(array $queue, float $onHand): array
    {
        $result = [];
        $ahead = 0.0;

        foreach ($queue as $claim) {
            $remainder = max(0.0, (float) $claim['remainder']);
            $backed = min($remainder, max(0.0, $onHand - $ahead));

            $result[(int) $claim['id']] = ['backed' => $backed, 'waiting' => $remainder - $backed];
            $ahead += $remainder;
        }

        return $result;
    }
}
