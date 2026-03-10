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
    public function getOrder()
    {
        return $this->getSubject();
    }

    public function setOrder($order)
    {
        $this->setArgument('subject', $order);
    }
}
