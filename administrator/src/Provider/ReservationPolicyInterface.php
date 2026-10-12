<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Provider;

\defined('_JEXEC') or die;

/**
 * Decides WHEN an order becomes an inventory commitment. The core policy
 * reserves at order placement; packages may hold the reservation until a
 * later business moment (payment confirmation, approval workflows) by
 * shipping their own policy.
 *
 * Part of the Alfa provider family: capabilities are declared in code (
 * implemented interfaces and subscribed events), never in manifest
 * metadata.
 *
 * @since  1.4.0
 */
interface ReservationPolicyInterface
{
    /**
     * Whether stock should be reserved for the order at this moment.
     *
     * @param object $order The order row as known at the trigger point
     *                      (id, status, totals; shape of the order table).
     *
     *
     * @since  1.4.0
     */
    public function shouldReserve(object $order): bool;
}
