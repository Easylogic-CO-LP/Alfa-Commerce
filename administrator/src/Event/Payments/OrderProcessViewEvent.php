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
 * The order process page's hook (onOrderProcessView), asked of the order's payment
 * plugin after placement: it sends the buyer to the provider (setRedirectUrl) or
 * draws the page through orderProcess(). A plugin that does neither sends the buyer
 * on to the order completed page.
 *
 * @since  1.0.0
 */
class OrderProcessViewEvent extends PaymentsLayoutEvent
{
    use HasSurfaces;

    /**
     * The payment's block on this page. Fluent:
     * orderProcess()->setLayout('default_order_process')->setData([...]).
     *
     * @since  2.3.0
     */
    public function orderProcess(): SurfaceBuilder
    {
        return $this->surfaceFor(CheckoutSurface::OrderProcess->value);
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
