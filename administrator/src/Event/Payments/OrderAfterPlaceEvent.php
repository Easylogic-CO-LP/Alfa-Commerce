<?php

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

class OrderAfterPlaceEvent extends PaymentsEvent
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