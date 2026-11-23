<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Helper\PaymentToken;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;
use Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * Paying an existing order again: whether its buyer may go back to its payment step, and the address that leads there.
 *
 * A payment that failed, that the buyer cancelled at the gateway, or that the shop released leaves the order owed,
 * and nothing in the checkout led back to it. The address this class makes (task cart.pay, the order's id and its
 * payment token, PaymentToken: an HMAC of the order id keyed with the order's own secret, never the secret itself)
 * points the visitor's session at the order and opens the existing payment step; the order is never placed again, so no
 * second order, stock movement or order e-mail. Its gateway opens the attempt as on a first visit.
 *
 * The order may be paid again only while all of this holds, read on every use of the address and again before the
 * gateway is asked:
 * - the order is there and not in the trash;
 * - money is still owed (PaymentLedger::amountToCollect());
 * - its status is neither in the cancelled family nor in the completed one;
 * - no payment of it is held (authorized) at a provider;
 * - no refund of it is unsettled (PaymentLedger::orderDeleteRefusal());
 * - its payment method is published, and its gateway plugin is available and answers
 *   PaymentsPlugin::supportsPayAgain() true: its process view opens a new attempt for an existing order. A gateway that
 *   does not answer, or fails to, is refused; core's Standard method, paid outside the shop, keeps the default.
 *
 * @since  2.3.0
 */
final class PayAgain
{
    /** The order may be paid again. */
    public const OPEN = 'open';

    /** No such order for this address: not there, in the trash, or the token does not match. */
    public const NOT_AVAILABLE = 'not_available';

    /** Nothing is owed. */
    public const NOTHING_DUE = 'nothing_due';

    /** The order's status is in the cancelled or the completed family. */
    public const CLOSED = 'closed';

    /** A payment of the order is held at a provider. */
    public const HELD = 'held';

    /** A refund of the order is not settled. */
    public const REFUND = 'refund';

    /** The order's payment method or its gateway is not available, or the gateway does not open a new attempt. */
    public const GATEWAY = 'gateway';

    /**
     * The order's row when the address's token is the order's payment token; null otherwise (no such order, an order
     * in the trash, a token that does not match).
     *
     * @param int $orderId The order the address names.
     * @param string $token The token the address carries.
     *
     * @since  2.3.0
     */
    public static function orderFromLink(int $orderId, string $token): ?object
    {
        $order = self::row(orderId: $orderId);

        if ($order === null || !PaymentToken::verify($orderId, (string) ($order->secure_key ?? ''), $token)) {
            return null;
        }

        return $order;
    }

    /**
     * Whether the order may be paid again, and if not why: one of the constants above.
     *
     * @param int $orderId The order.
     *
     * @since  2.3.0
     */
    public static function verdict(int $orderId): string
    {
        $order = self::row(orderId: $orderId);

        return $order === null ? self::NOT_AVAILABLE : self::verdictFor(order: $order);
    }

    /**
     * {@see self::verdict()} for an order already read.
     *
     * @param object $order The order's row.
     *
     * @since  2.3.0
     */
    public static function verdictFor(object $order): string
    {
        $orderId = (int) ($order->id ?? 0);

        if ($orderId <= 0 || (int) ($order->state ?? 1) === -2) {
            return self::NOT_AVAILABLE;
        }

        $status = (int) ($order->id_order_status ?? 0);

        if (OrderStatusHelper::isCancelled($status) || OrderStatusHelper::isCompleted($status)) {
            return self::CLOSED;
        }

        if (PaymentLedger::amountToCollect($order) <= 0) {
            return self::NOTHING_DUE;
        }

        $db = self::db();
        $held = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_order_payments'))
                ->where($db->quoteName('id_order') . ' = :id')
                ->where($db->quoteName('payment_type') . ' IN (' . $db->quote('payment') . ', ' . $db->quote('authorization') . ')')
                ->where($db->quoteName('transaction_status') . ' = ' . $db->quote(PaymentLedger::STATUS_AUTHORIZED))
                ->bind(':id', $orderId, ParameterType::INTEGER),
        )->loadResult();

        if ($held > 0) {
            return self::HELD;
        }

        if (PaymentLedger::orderDeleteRefusal(orderId: $orderId) !== null) {
            return self::REFUND;
        }

        return self::gatewayAvailable(order: $order) ? self::OPEN : self::GATEWAY;
    }

    /**
     * The address that takes the order's buyer back to its payment step, absolute, for the confirmation page and the
     * order e-mail.
     *
     * @param object $order The order's row (its id and secure_key).
     *
     * @return string '' when the order carries no key.
     *
     * @since  2.3.0
     */
    public static function link(object $order): string
    {
        $orderId = (int) ($order->id ?? 0);
        $key = (string) ($order->secure_key ?? '');

        if ($orderId <= 0 || $key === '') {
            return '';
        }

        return Route::link(
            client:   'site',
            url:      'index.php?option=com_alfa&task=cart.pay&id_order=' . $orderId . '&token=' . PaymentToken::for($orderId, $key),
            xhtml:    false,
            absolute: true,
        );
    }

    /**
     * The sentence a visitor reads when the order may not be paid again.
     *
     * @param string $verdict One of the constants above, not OPEN.
     *
     * @since  2.3.0
     */
    public static function sentence(string $verdict): string
    {
        return Text::_(match ($verdict) {
            self::NOTHING_DUE => 'COM_ALFA_PAY_AGAIN_NOTHING_DUE',
            self::CLOSED => 'COM_ALFA_PAY_AGAIN_CLOSED',
            self::HELD => 'COM_ALFA_PAY_AGAIN_HELD',
            self::REFUND => 'COM_ALFA_PAY_AGAIN_REFUND',
            self::GATEWAY => 'COM_ALFA_PAY_AGAIN_GATEWAY',
            default => 'COM_ALFA_PAY_AGAIN_NOT_AVAILABLE',
        });
    }

    /**
     * Whether the order's payment method is published and its gateway, booted through the plugin helper (the one
     * availability authority), declares that its process view opens a new attempt (PaymentsPlugin::supportsPayAgain()).
     *
     * @param object $order The order's row.
     *
     * @since  2.3.0
     */
    private static function gatewayAvailable(object $order): bool
    {
        $methodId = (int) ($order->id_payment_method ?? 0);
        $element = (string) ($order->payment_method_type ?? '');

        if ($methodId <= 0 || $element === '') {
            return false;
        }

        $db = self::db();
        $published = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_payments'))
                ->where($db->quoteName('id') . ' = :id')
                ->where($db->quoteName('state') . ' = 1')
                ->bind(':id', $methodId, ParameterType::INTEGER),
        )->loadResult();

        if ($published === 0) {
            return false;
        }

        // The gateway's own answer, never assumed: one that is not available, does not answer or fails is refused.
        $plugins = AlfaPluginHelper::get();

        try {
            return (bool) $plugins->invoke($plugins->bootSingle(PluginId::from('alfa-payments', $element)), 'supportsPayAgain');
        } catch (PluginUnavailable|InvalidArgumentException) {
            return false;
        } catch (Throwable $e) {
            Log::add('Pay again: the payment plugin ' . $element . ' failed while answering whether it opens a new attempt: ' . ErrorText::of($e), Log::ERROR, 'com_alfa.payments');

            return false;
        }
    }

    /**
     * The order's row; null when there is no such order or it is in the trash.
     *
     * @param int $orderId The order.
     *
     * @since  2.3.0
     */
    private static function row(int $orderId): ?object
    {
        if ($orderId <= 0) {
            return null;
        }

        $db = self::db();
        $order = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = :id')
                ->where($db->quoteName('state') . ' != -2')
                ->bind(':id', $orderId, ParameterType::INTEGER),
        )->loadObject();

        return \is_object($order) ? $order : null;
    }

    /**
     * The database driver.
     *
     * @since  2.3.0
     */
    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
