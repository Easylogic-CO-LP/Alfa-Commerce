<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Get Shipment Actions Event
 *
 * Triggered when the admin order page needs to render action buttons
 * for a shipment. Each shipment plugin's onGetActions() receives this
 * event and registers its buttons via the fluent API.
 *
 *  REGISTERING ACTIONS (in plugin's onGetActions)
 *
 *   $event->add('mark_shipped', 'Mark as Shipped')
 *       ->icon('truck')->css('btn-primary')
 *       ->confirm('Ship this order?');
 *
 *  Reading context
 *
 *   $shipment = $event->getShipment();
 *   $order    = $event->getOrder();
 *
 * Path: administrator/components/com_alfa/src/Event/Shipments/GetShipmentActionsEvent.php
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Shipments;

use Alfa\Component\Alfa\Administrator\Plugin\Traits\HasPluginActionsTrait;
use Alfa\Component\Alfa\Site\Service\Order\Order;
use Alfa\Component\Alfa\Site\Service\Order\Shipment;
use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

class GetShipmentActionsEvent extends AbstractImmutableEvent
{
    use HasPluginActionsTrait;

    /**
     * Get the shipment record (with decoded params and method info).
     *
     *
     * @since  1.0.0
     */
    public function getShipment(): object
    {
        return $this->arguments['shipment'];
    }

    /**
     * The shipment this event is about, as the canonical reader.
     *
     *     $shipment = $event->getOrderShipment();
     *     $locker = $shipment->pluginData()->getString(key: 'locker_id', default: '');
     *
     * THE EXACT ONE, not "a shipment of this order". An order can be split
     * across parcels, so a handler that went to the order and took the first
     * shipment would work until the day a shop split one, and then quietly
     * label the wrong parcel. This event knows which shipment it concerns and
     * hands that one over.
     *
     * {@see self::getShipment()} remains the raw record, which is what carries
     * the method's own settings (`->params`); this is the same row read through
     * the reader, so nothing is decoded twice.
     *
     *
     * @since  2.0.0
     */
    public function getOrderShipment(): Shipment
    {
        return Shipment::of($this->arguments['shipment']);
    }

    /**
     * Get the order record.
     *
     *
     * @since  1.0.0
     */
    public function getOrder(): Order
    {
        return Order::of($this->arguments['order']);
    }
}
