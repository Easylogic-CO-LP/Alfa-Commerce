<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Shipments;

use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\PlacementEventContext;

class OrderBeforePlaceEvent extends ShipmentsEvent
{
    /**
     * The cart being converted, as a plugin may see it: its id and its lines,
     * as values. There is no way from here to the cart itself, and no way to
     * hand a different one back; placement's state is placement's.
     *
     *
     * @since  2.0.0
     */
    public function getContext(): PlacementEventContext
    {
        return $this->getSubject();
    }
}
