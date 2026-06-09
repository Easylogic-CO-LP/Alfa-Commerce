<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

class OrderBeforeSaveEvent extends PaymentsEvent
{
    /**
     * Get the cart subject carried by the event.
     *
     * @return mixed The cart object
     *
     * @since  1.0.0
     */
    public function getCart()
    {
        return $this->getSubject();
    }
}
