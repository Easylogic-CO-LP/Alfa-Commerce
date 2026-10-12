<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

use Alfa\Component\Alfa\Site\Service\Order\Order;

class OrderAfterPlaceEvent extends PaymentsEvent
{
    /**
     * The persisted order, as placement loaded it after the commit.
     *
     * @return object
     *
     * @since  1.0.0
     */
    public function getOrder(): Order
    {
        return Order::of($this->getSubject());
    }
}
