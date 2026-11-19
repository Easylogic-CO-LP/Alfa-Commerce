<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/**
 * The order's payment and shipping method follow its payments and parcels (2.2.2): one rule, in one place.
 *
 * The order row keeps the method it is paid and sent with (id, name and plugin, written together): what the orders
 * list, the e-mails and the documents name, and the plugin an order asks when it is deleted. It is the checkout's
 * choice when the order is placed, and from then on:
 *
 *   payment   the method of the order's latest row of type payment that is not failed or cancelled; a wallet spend
 *             (a payment from the customer's credit) is left out, as it keeps its own label;
 *   shipment  the method of the order's latest parcel that is not cancelled or returned.
 *
 * With no such row the order keeps what it holds. Run by the payments ledger and the shipments ledger inside the
 * transaction of every write of a row (a new row, a change, a status change, a delete), so the order and its rows
 * commit together. Before, a payment filled the order's method only while the order had none, so a cancelled method
 * stayed on the order when another one paid, and no parcel ever wrote it.
 *
 * @since  2.2.2
 */
final class OrderMethods
{
    /**
     * The order's payment method, from its latest live payment.
     *
     * @param int $orderId The order.
     *
     * @since  2.2.2
     */
    public static function followPayments(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        $db = self::db();
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id_payment_method', 'payment_method', 'payment_method_type']))
                ->from($db->quoteName('#__alfa_order_payments'))
                ->where($db->quoteName('id_order') . ' = :order')
                ->where($db->quoteName('payment_type') . ' = ' . $db->quote(PaymentLedger::TYPE_PAYMENT))
                ->whereNotIn($db->quoteName('transaction_status'), [PaymentLedger::STATUS_FAILED, PaymentLedger::STATUS_CANCELLED], ParameterType::STRING)
                ->where('COALESCE(' . $db->quoteName('id_credit_transaction') . ', 0) = 0')
                ->where($db->quoteName('id_payment_method') . ' > 0')
                ->bind(':order', $orderId, ParameterType::INTEGER)
                ->order($db->quoteName('id') . ' DESC'),
            0,
            1,
        )->loadObject();

        if ($row === null) {
            return;
        }

        self::write(orderId: $orderId, columns: [
            'id_payment_method' => (int) $row->id_payment_method,
            'payment_method_name' => (string) $row->payment_method,
            'payment_method_type' => (string) $row->payment_method_type,
        ]);
    }

    /**
     * The order's shipping method, from its latest parcel that is not cancelled or returned.
     *
     * @param int $orderId The order.
     *
     * @since  2.2.2
     */
    public static function followShipments(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        $db = self::db();
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id_shipment_method', 'shipment_method_name', 'shipment_method_type']))
                ->from($db->quoteName('#__alfa_order_shipments'))
                ->where($db->quoteName('id_order') . ' = :order')
                ->whereNotIn($db->quoteName('status'), [ShipmentLedger::STATUS_CANCELLED, ShipmentLedger::STATUS_RETURNED], ParameterType::STRING)
                ->where($db->quoteName('id_shipment_method') . ' > 0')
                ->bind(':order', $orderId, ParameterType::INTEGER)
                ->order($db->quoteName('id') . ' DESC'),
            0,
            1,
        )->loadObject();

        if ($row === null) {
            return;
        }

        self::write(orderId: $orderId, columns: [
            'id_shipment_method' => (int) $row->id_shipment_method,
            'shipment_method_name' => (string) ($row->shipment_method_name ?? ''),
            'shipment_method_type' => (string) $row->shipment_method_type,
        ]);
    }

    /**
     * Write the method's three columns onto the order. A row whose columns already hold them is left as it is by the
     * database itself.
     *
     * @param int $orderId The order.
     * @param array<string, int|string> $columns The id, name and type, by column.
     *
     * @since  2.2.2
     */
    private static function write(int $orderId, array $columns): void
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__alfa_orders'))
            ->where($db->quoteName('id') . ' = :order')
            ->bind(':order', $orderId, ParameterType::INTEGER);

        foreach (array_keys($columns) as $column) {
            $query->set($db->quoteName($column) . ' = :' . $column)
                ->bind(':' . $column, $columns[$column], \is_int($columns[$column]) ? ParameterType::INTEGER : ParameterType::STRING);
        }

        $db->setQuery($query)->execute();
    }

    /**
     * The site's database.
     *
     * @since  2.2.2
     */
    private static function db(): DatabaseDriver
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }
}
