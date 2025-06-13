<?php

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

class OrderBeforeSaveEvent extends PaymentsEvent
{
    public function getCart()
    {
        return $this->getSubject();
    }

}