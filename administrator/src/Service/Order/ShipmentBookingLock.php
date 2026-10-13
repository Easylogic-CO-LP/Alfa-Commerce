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
use Joomla\Database\DatabaseInterface;
use RuntimeException;

/**
 * One carrier booking at a time per shipment.
 *
 * A booking reads whether the shipment is already booked, asks the carrier and
 * records the parcel, and the carrier call can take seconds. A second booking
 * asked in that window, from a second tab or a second admin, would find the
 * shipment still unbooked and book a second parcel. A carrier runs the whole
 * booking inside run(); a second booking of the same shipment is refused while
 * the first holds the lock.
 *
 * A named database lock, taken without waiting ({@see NamedLock}).
 *
 * @since  2.0.0
 */
final class ShipmentBookingLock
{
    use NamedLock;

    /**
     * Runs the work while holding the shipment's booking lock.
     *
     * The lock is re-entrant for the connection that holds it, so a request
     * never refuses itself.
     *
     * @param int $shipmentId The shipment being booked.
     * @param callable $work Called with no arguments.
     *
     * @return bool False when another request holds the lock; the work did not run.
     *
     * @throws RuntimeException When the lock cannot be taken.
     *
     * @since  2.0.0
     */
    public static function run(int $shipmentId, callable $work): bool
    {
        if ($shipmentId <= 0) {
            throw new RuntimeException('A shipment booking lock needs a shipment id; got ' . $shipmentId . '.');
        }

        return self::runLocked(
            name: self::name(Factory::getContainer()->get('DatabaseDriver'), $shipmentId),
            label: 'booking lock of shipment #' . $shipmentId,
            logCategory: 'com_alfa.shipments',
            work: $work,
        );
    }

    /**
     * The lock's name for a shipment on this site.
     *
     * @param DatabaseInterface $db The site's database.
     * @param int $shipmentId The shipment.
     *
     * @return string 40 characters for a 10-digit shipment id; the server allows 64.
     *
     * @since  2.0.0
     */
    public static function name(DatabaseInterface $db, int $shipmentId): string
    {
        return self::lockName(db: $db, kind: 'booking', id: $shipmentId);
    }
}
