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
 * ═══════════════════════════════════════════════════════════════
 *  REGISTERING ACTIONS (in plugin's onGetActions)
 * ═══════════════════════════════════════════════════════════════
 *
 *   $event->add('mark_shipped', 'Mark as Shipped')
 *       ->icon('truck')->css('btn-primary')
 *       ->confirm('Ship this order?');
 *
 * ═══════════════════════════════════════════════════════════════
 *  READING CONTEXT
 * ═══════════════════════════════════════════════════════════════
 *
 *   $shipment = $event->getShipment();
 *   $order    = $event->getOrder();
 *
 * Path: administrator/components/com_alfa/src/Event/Shipments/GetShipmentActionsEvent.php
 *
 * @since  3.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Shipments;

use Alfa\Component\Alfa\Administrator\Plugin\HasPluginActionsTrait;
use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

class GetShipmentActionsEvent extends AbstractImmutableEvent
{
    use HasPluginActionsTrait;

    /**
     * Get the shipment record (with decoded params and method info).
     *
     *
     * @since   3.0.0
     */
    public function getShipment(): object
    {
        return $this->arguments['shipment'];
    }

    /**
     * Get the order record.
     *
     *
     * @since   3.0.0
     */
    public function getOrder(): object
    {
        return $this->arguments['order'];
    }
}
