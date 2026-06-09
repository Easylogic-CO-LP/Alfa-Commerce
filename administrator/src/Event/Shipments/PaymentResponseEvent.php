<?php

/**
 * Alfa Commerce
 *
 * @copyright  (C) 2023 Open Source Matters, Inc. <https://www.joomla.org>
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Shipments;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Gateway/carrier-return event (onPaymentResponse) for shipments, dispatched by the
 * site PaymentController which has no view — so it is redirect-only (no layout). To
 * show a result page, redirect to a view layout (e.g. the order-process page).
 *
 * @since  5.0.0
 */
class PaymentResponseEvent extends ShipmentsRedirectEvent
{
    /**
     * Get the order/cart subject carried by the event.
     *
     * @return mixed The order or cart object
     *
     * @since  5.0.0
     */
    public function getOrder()
    {
        return $this->getSubject();
    }
}
