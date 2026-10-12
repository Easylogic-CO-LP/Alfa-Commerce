<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Provider\ReservationPolicyInterface;

/**
 * The shipped reservation policy: an order commits inventory the moment
 * it exists. Packages holding the commitment for a later business moment
 * (payment confirmation, approval) replace this via the policy seam.
 *
 * @since  1.4.0
 */
final class DefaultReservationPolicy implements ReservationPolicyInterface
{
    /**
     * @param object $order The order row at the trigger point.
     *
     * @return bool Always true: reserve at placement.
     *
     * @since  1.4.0
     */
    public function shouldReserve(object $order): bool
    {
        return true;
    }
}
