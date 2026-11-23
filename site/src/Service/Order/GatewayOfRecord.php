<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\CheckoutSurface;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\Surfaces;
use Alfa\Component\Alfa\Administrator\Event\Payments\OrderCompleteViewEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\OrderProcessViewEvent;
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
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
     * view event delivered to its handler. A gateway with no handler for the page is asked nothing. Any Throwable leaving the handler is its failure: logged, and the caller renders no plugin block.
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
            Log::add('Order page: the gateway of record cannot be loaded (' . ErrorText::of($e) . '); the page shows the neutral line.', Log::WARNING, 'com_alfa.payments');

            return self::UNAVAILABLE;
        }

        $plugins->importSingle($id);

        if ($plugins->declares($gateway, $event->getName())) {
            try {
                $plugins->dispatchTo($gateway, $event);
            } catch (Throwable $e) {
                Log::add('Order page: ' . $id . ' failed in ' . $event->getName() . ' (' . $e::class . ': ' . ErrorText::of($e) . '); the page renders without it.', Log::ERROR, 'com_alfa.payments');

                return self::FAILED;
            }
        }

        return self::ASKED;
    }

    /**
     * What the gateway drew on its page through the event's builder (orderProcess() on the process page,
     * orderComplete() on the order pages): one surface descriptor, its owner the gateway's plugin unless it named
     * another; null when it drew nothing there.
     *
     * @param string $paymentType The order's snapshot of the gateway's plugin element.
     * @param OrderProcessViewEvent|OrderCompleteViewEvent $event The view event, asked.
     *
     * @return array|null The descriptor (layout, layoutData, layoutPluginType, layoutPluginName), or null.
     *
     * @since  2.3.0
     */
    public static function block(string $paymentType, OrderProcessViewEvent|OrderCompleteViewEvent $event): ?array
    {
        $slot = $event instanceof OrderProcessViewEvent ? CheckoutSurface::OrderProcess : CheckoutSurface::OrderComplete;

        return Surfaces::collect($event, 'alfa-payments', $paymentType)[$slot->value] ?? null;
    }

    /**
     * The gateway's block about an order already read, as the order completed page and the customer's order page draw
     * it: the completion view event with the order's own row as its subject, asked of its gateway of record when the
     * order has one, and what it drew. An order with no payment plugin has no block.
     *
     * @param OrderConfirmation $order The order, as the page read it.
     *
     * @return array{event: OrderCompleteViewEvent, unavailable: bool, block: array|null} The event (for its redirect);
     *                                                                 unavailable when the gateway could not be asked
     *                                                                 (the page says so in neutral words and draws no
     *                                                                 block); the block it drew, or null.
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
        $block = $order->gateway !== '' && !$unavailable ? self::block(paymentType: $order->gateway, event: $event) : null;

        return ['event' => $event, 'unavailable' => $unavailable, 'block' => $block];
    }
}
