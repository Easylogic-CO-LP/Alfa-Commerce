<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

// No direct access.
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * The derivation of an order's fulfilment: how many of its units have gone out, and what that makes the order.
 *
 * Nothing is stored: it is read from the order's lines and the lines of its parcels. The orders list and the REST
 * order both read this class, so the list and a single order cannot answer the same order two ways.
 *
 * Counted in UNITS, not lines. A line may be split across parcels ("3 now, 2 Monday"), so counting lines would
 * report a half-sent line as either wholly sent or not sent at all, and on a single-line order "partial" would be
 * unreachable entirely. A unit has gone out when the parcel carrying it is shipped or delivered.
 *
 * Two entry points, one core:
 *   forOrder()  loads the lines of one order and its parcels' lines;
 *   fromRows()  takes rows a caller already has (the list batches them for a page of orders).
 *
 * @since  2.2.1
 */
final class OrderFulfillment
{
    /**
     * No unit has gone out (an order without units included).
     *
     * @since  2.2.1
     */
    public const UNFULFILLED = 'unfulfilled';

    /**
     * Some units have gone out, not all.
     *
     * @since  2.2.1
     */
    public const PARTIAL = 'partial';

    /**
     * Every unit has gone out.
     *
     * @since  2.2.1
     */
    public const FULFILLED = 'fulfilled';

    /**
     * The parcel states in which a parcel's units have gone out.
     *
     * @since  2.2.1
     */
    private const SENT = ['shipped', 'delivered'];

    /**
     * Each entry of $lines is one order line, by its id: the units of it that have gone out, the status of the first
     * parcel that carries it ("partial" for a line split across parcels that are not all out) and that parcel's
     * tracking number.
     *
     * @param int $totalUnits The units the order's lines hold.
     * @param int $shippedUnits The units of those lines that have gone out.
     * @param float|int $percentage The share that has gone out, 0 to 100, at two places.
     * @param string $status One of the constants above.
     * @param array<int, array{shipped_quantity: int, shipment_status: string, shipment_tracking: string}> $lines Per order line.
     *
     * @since  2.2.1
     */
    private function __construct(
        public readonly int $totalUnits,
        public readonly int $shippedUnits,
        public readonly float|int $percentage,
        public readonly string $status,
        public readonly array $lines,
    ) {
    }

    /**
     * The fulfilment of one order, read from its lines and its parcels' lines.
     *
     * @param int $orderId The order.
     *
     * @since  2.2.1
     */
    public static function forOrder(int $orderId): self
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $lines = $db->setQuery(
            $db->getQuery(true)
                ->select([$db->quoteName('id'), $db->quoteName('quantity')])
                ->from($db->quoteName('#__alfa_order_items'))
                ->where($db->quoteName('id_order') . ' = :order')
                ->bind(':order', $orderId, ParameterType::INTEGER),
        )->loadObjectList() ?: [];

        return self::fromRows(orderLines: $lines, parcelLines: self::parcelLines(orderIds: [$orderId])[$orderId] ?? []);
    }

    /**
     * The fulfilment of one order from rows the caller already holds.
     *
     * @param object[] $orderLines The order's lines: id, quantity.
     * @param object[] $parcelLines The lines of the order's parcels, as {@see self::parcelLines()} reads them:
     *                              id_order_item, quantity, shipment_status, shipment_tracking.
     *
     * @since  2.2.1
     */
    public static function fromRows(array $orderLines, array $parcelLines): self
    {
        $totalUnits = 0;
        $shippedUnits = 0;
        $perLine = [];

        foreach ($orderLines as $orderLine) {
            $lineId = (int) $orderLine->id;
            $quantity = (int) ($orderLine->quantity ?? 0);
            $totalUnits += $quantity;

            $shipped = 0;
            $status = '';
            $tracking = '';

            foreach ($parcelLines as $parcelLine) {
                if ((int) $parcelLine->id_order_item !== $lineId) {
                    continue;
                }

                if (\in_array($parcelLine->shipment_status ?? '', self::SENT, true)) {
                    $shipped += (int) $parcelLine->quantity;
                }

                // The first parcel carrying the line names its status; a cancelled parcel still shows, so staff can
                // see why nothing moved.
                if ($status === '') {
                    $status = (string) ($parcelLine->shipment_status ?? '');
                    $tracking = (string) ($parcelLine->shipment_tracking ?? '');
                }
            }

            $shippedUnits += $shipped;

            // A line split across parcels that are not all out yet.
            if ($shipped > 0 && $shipped < $quantity) {
                $status = self::PARTIAL;
            }

            $perLine[$lineId] = ['shipped_quantity' => $shipped, 'shipment_status' => $status, 'shipment_tracking' => $tracking];
        }

        return new self(
            totalUnits:   $totalUnits,
            shippedUnits: $shippedUnits,
            percentage:   $totalUnits > 0 ? round(($shippedUnits / $totalUnits) * 100, 2) : 0,
            status:       match (true) {
                $totalUnits <= 0 || $shippedUnits <= 0 => self::UNFULFILLED,
                $shippedUnits >= $totalUnits => self::FULFILLED,
                default => self::PARTIAL,
            },
            lines:        $perLine,
        );
    }

    /**
     * The lines of the parcels of the given orders, with each parcel's status and tracking number, grouped by order.
     *
     * One grouped query on the parcel lines, joined to their parcels: a line may ride in several parcels, so joining
     * parcels onto the order lines would multiply those rows.
     *
     * @param int[] $orderIds The orders.
     *
     * @return array<int, object[]> [orderId => [parcel line, ...]]
     *
     * @since  2.2.1
     */
    public static function parcelLines(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select([
                    $db->quoteName('si.id_order'),
                    $db->quoteName('si.id_order_item'),
                    $db->quoteName('si.id_order_shipment'),
                    $db->quoteName('si.quantity'),
                    $db->quoteName('os.status', 'shipment_status'),
                    $db->quoteName('os.tracking_number', 'shipment_tracking'),
                ])
                ->from($db->quoteName('#__alfa_order_shipment_items', 'si'))
                ->join(
                    'INNER',
                    $db->quoteName('#__alfa_order_shipments', 'os'),
                    $db->quoteName('si.id_order_shipment') . ' = ' . $db->quoteName('os.id'),
                )
                ->whereIn($db->quoteName('si.id_order'), array_map('intval', $orderIds)),
        )->loadObjectList() ?: [];

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row->id_order][] = $row;
        }

        return $grouped;
    }
}
