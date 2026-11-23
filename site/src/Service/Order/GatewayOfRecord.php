<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Payments\OrderCompleteViewEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\OrderProcessViewEvent;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;
use InvalidArgumentException;
use Joomla\CMS\Log\Log;
use Throwable;

/**
 * The order pages ask one payment plugin about an order: its gateway of record, the plugin the order recorded
 * (payment_method_type), never the live method row, which staff may have edited. The process page asks it for its
 * payment page; the order completed page and the customer's order page ask it for its block about the order.
 *
 * @since  2.3.0
 */
final class GatewayOfRecord
{
    /** The gateway was asked. */
    public const ASKED = 'asked';

    /** The gateway of record is disabled, missing or cannot be loaded. */
    public const UNAVAILABLE = 'unavailable';

    /** The gateway's handler threw. */
    public const FAILED = 'failed';

    /**
     * Ask the order's gateway of record for its page or its block (targeted): its ambient subscriptions engaged, the
     * view event delivered to its handler, the layout owner defaulted to it. A gateway with no handler for the page is
     * asked nothing. Any Throwable leaving the handler is its failure: logged, and the caller renders no plugin block.
     *
     * @param string $paymentType The order's snapshot of the gateway's plugin element.
     * @param OrderProcessViewEvent|OrderCompleteViewEvent $event The view event.
     *
     * @return string ASKED, UNAVAILABLE or FAILED.
     *
     * @since  2.3.0
     */
    public static function ask(string $paymentType, OrderProcessViewEvent|OrderCompleteViewEvent $event): string
    {
        $plugins = AlfaPluginHelper::get();

        try {
            $id = PluginId::from('alfa-payments', $paymentType);
            $gateway = $plugins->bootSingle($id);
        } catch (PluginUnavailable|InvalidArgumentException $e) {
            Log::add('Order page: the gateway of record cannot be loaded (' . $e->getMessage() . '); the page shows the neutral line.', Log::WARNING, 'com_alfa.payments');

            return self::UNAVAILABLE;
        }

        $plugins->importSingle($id);

        if ($plugins->declares($gateway, $event->getName())) {
            try {
                $plugins->dispatchTo($gateway, $event);
            } catch (Throwable $e) {
                Log::add('Order page: ' . $id . ' failed in ' . $event->getName() . ' (' . $e::class . ': ' . $e->getMessage() . '); the page renders without it.', Log::ERROR, 'com_alfa.payments');

                return self::FAILED;
            }
        }

        if (empty($event->getLayoutPluginName())) {
            $event->setLayoutPluginName($paymentType);
        }

        if (empty($event->getLayoutPluginType())) {
            $event->setLayoutPluginType('alfa-payments');
        }

        return self::ASKED;
    }

    /**
     * The gateway's block about an order already read, as the order completed page and the customer's order page draw
     * it: the completion view event with the order's own row as its subject, asked of its gateway of record when the
     * order has one. An order with no payment plugin gets an event with no layout, which draws nothing.
     *
     * @param OrderConfirmation $order The order, as the page read it.
     *
     * @return array{event: OrderCompleteViewEvent, unavailable: bool} The event; unavailable when the gateway could not
     *                                                                 be asked (the page says so in neutral words and
     *                                                                 draws no block).
     *
     * @since  2.3.0
     */
    public static function completeView(OrderConfirmation $order): array
    {
        $event = new OrderCompleteViewEvent('onOrderCompleteView', [
            'subject' => $order->gatewaySubject(),
            'method' => $order->gatewayMethod(),
        ]);

        $unavailable = $order->gateway !== '' && self::ask(paymentType: $order->gateway, event: $event) !== self::ASKED;

        return ['event' => $event, 'unavailable' => $unavailable];
    }
}
