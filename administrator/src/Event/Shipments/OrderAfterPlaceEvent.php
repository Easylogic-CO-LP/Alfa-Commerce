<?php

namespace Alfa\Component\Alfa\Administrator\Event\Shipments;

class OrderAfterPlaceEvent extends ShipmentsEvent
{
    public function getOrder()
    {
        return $this->getSubject();
    }

    public function setOrder($order)
    {
        $this->setArgument("subject", $order);
    }
}
