<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

class OrderPlaceEvent extends PaymentsEvent
{
    public function getCart()
    {
        return $this->getSubject();
    }

    public function setCart($cart)
    {
        $this->setArgument('subject', $cart);
    }
}
