<?php

namespace Alfa\Component\Alfa\Administrator\Event\Shipments;

class OrderPlaceEvent extends ShipmentsEvent
{
    public function getCart()
    {
        return $this->getSubject();
    }

    public function setCart($cart){
        $this->setArgument("subject", $cart);
    }
}
