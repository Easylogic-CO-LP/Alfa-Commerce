<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

defined('_JEXEC') or die;

/**
 * The render slots a payment or shipping plugin may target in the checkout: under its
 * method in the cart's method selectors (onCartView), and the two order pages a payment
 * plugin draws after placement (onOrderProcessView, onOrderCompleteView). Each event
 * exposes only its own slot, so a plugin cannot aim at another page's.
 *
 * @since  2.3.0
 */
enum CheckoutSurface: string
{
    /** Under a payment method in the cart's payment methods, below core's drawing of it. */
    case PaymentMethods = 'payment-methods';

    /** Under a shipment method in the cart's shipment methods, below core's drawing of it. */
    case ShipmentMethods = 'shipment-methods';

    /** The order process page: what the buyer sees between placement and payment. */
    case OrderProcess = 'order-process';

    /** The payment's block on the order completed page, such as bank details for a transfer. */
    case OrderComplete = 'order-complete';
}
