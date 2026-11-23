<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\CheckoutSurface;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern\HasSurfaces;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\SurfaceBuilder;
use Alfa\Component\Alfa\Site\Service\Order\Order;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The order completed page's hook (onOrderCompleteView), asked of the order's payment
 * plugin: it draws its block on the page (bank details for a transfer, for example)
 * through orderComplete(), or redirects.
 *
 * @since  1.0.0
 */
class OrderCompleteViewEvent extends PaymentsLayoutEvent
{
    use HasSurfaces;

    /**
     * The payment's block on this page. Fluent:
     * orderComplete()->setLayout('default_order_completed')->setData([...]).
     *
     * @since  2.3.0
     */
    public function orderComplete(): SurfaceBuilder
    {
        return $this->surfaceFor(CheckoutSurface::OrderComplete->value);
    }

    /**
     * Get the order/cart subject carried by the event.
     *
     * @return mixed The order or cart object
     *
     * @since  1.0.0
     */
    public function getOrder(): Order
    {
        return Order::of($this->getSubject());
    }
}
