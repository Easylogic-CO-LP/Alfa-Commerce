<?php

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

class OrderPlaceEvent extends PaymentsEvent
{
    public function getCart()
    {
        return $this->getSubject();
    }

    public function setCart($cart){
        $this->setArgument("subject", $cart);
    }

}
