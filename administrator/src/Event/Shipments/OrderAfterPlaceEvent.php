<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Shipments;

use Alfa\Component\Alfa\Site\Service\Order\Order;

/**
 * onOrderAfterPlace for the order's carrier: the order is committed, and Alfa has recorded its parcel (pending, the
 * shipping charge the customer accepted and every item) on the carrier's method. The carrier completes that parcel with
 * what is its own (a locker, a pickup point, its data) through ShipmentsPlugin::shipmentUpdate($event->getShipmentId())
 * and records no parcel of its own.
 *
 * @since  1.0.0
 */
class OrderAfterPlaceEvent extends ShipmentsEvent
{
    /**
     * The persisted order, as placement loaded it after the commit.
     *
     * @return object
     *
     * @since  1.0.0
     */
    public function getOrder(): Order
    {
        return Order::of($this->getSubject());
    }

    /**
     * The order's parcel Alfa recorded before this event, for the carrier to complete.
     *
     * @since  2.3.0
     */
    public function getShipmentId(): int
    {
        return (int) $this->getArgument('shipment', 0);
    }
}
