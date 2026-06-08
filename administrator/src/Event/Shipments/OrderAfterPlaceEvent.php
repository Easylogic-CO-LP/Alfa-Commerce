<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Shipments;

class OrderAfterPlaceEvent extends ShipmentsEvent
{
    /**
     * Get the order/cart subject carried by the event.
     *
     * @return mixed The order or cart object
     *
     * @since  5.0.0
     */
    public function getOrder()
    {
        return $this->getSubject();
    }

    /**
     * Replace the order subject carried by the event.
     *
     * @param mixed $order The order object
     *
     * @return void
     *
     * @since  5.0.0
     */
    public function setOrder($order)
    {
        $this->setArgument('subject', $order);
    }
}
