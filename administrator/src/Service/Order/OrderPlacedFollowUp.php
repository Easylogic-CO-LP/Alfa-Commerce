<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Background\BackgroundWork;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderEntitlements;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderPlacementEmail;

/**
 * What a new order owes for the status it opens in: the download access of that status and its e-mail, to
 * the customer when the status tells the customer and to the status's administrator recipients, as a status change's
 * e-mail goes. One place for the checkout's placement and for an order created with its lines over the REST API.
 *
 * Written as singles of the transaction that writes the order, so they run once it has committed and a request
 * that dies leaves them to recovery; each handler decides again when it runs (a status that tells nobody sends
 * nothing).
 *
 * @since  2.2.2
 */
final class OrderPlacedFollowUp
{
    /**
     * Owe a new order's download access and status e-mail, inside the transaction that writes the order.
     *
     * @param int $orderId The new order.
     * @param int $statusId The status it opens in.
     * @param bool $customer Whether the customer is told; false for a creation made silent for the customer, whose
     *                       e-mail goes to the status's administrator recipients alone, as a silent status change's.
     *
     * @since  2.2.2
     */
    public static function owe(int $orderId, int $statusId, bool $customer = true): void
    {
        BackgroundWork::core()->task(OrderEntitlements::NAME)->about('order', $orderId)->with(['status' => $statusId])->enqueue();
        BackgroundWork::core()->task(OrderPlacementEmail::NAME)->about('order', $orderId)->with($customer ? ['status' => $statusId] : ['status' => $statusId, 'customer' => false])->enqueue();
    }
}
