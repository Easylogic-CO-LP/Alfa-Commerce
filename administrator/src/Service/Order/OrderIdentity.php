<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

use Alfa\Component\Alfa\Administrator\Helper\RoleRegistry;
use Joomla\CMS\Factory;
use Throwable;

defined('_JEXEC') or die;

/**
 * Who the customer of an order is: one answer for every consumer.
 *
 * The order row carries a SNAPSHOT (`customer_name`, `customer_email`,
 * `customer_is_guest`) written once at creation. Members are snapshotted
 * from `#__users`, guests from the delivery details they typed. Lists,
 * documents and history read the snapshot; the NOTIFICATION address of a
 * member follows the live account while it exists (an email typo fixed in
 * the profile the day after ordering must still receive the shipping mail).
 *
 * @since  2.0.0
 */
final class OrderIdentity
{
    public const KIND_ACCOUNT = 'account';
    public const KIND_GUEST = 'guest';
    public const KIND_DELETED = 'deleted';

    /**
     * The snapshot for a member, from the account.
     *
     * @param int $userId The Joomla user id.
     *
     * @return array{customer_name: string, customer_email: string, customer_is_guest: int}
     *
     * @since  2.0.0
     */
    public static function snapshotFromUser(int $userId): array
    {
        $account = self::account($userId);

        return [
            'customer_name' => (string) ($account->name ?? ''),
            'customer_email' => (string) ($account->email ?? ''),
            'customer_is_guest' => 0,
        ];
    }

    /**
     * The snapshot for a guest, from the details they typed (a submitted
     * field bucket or a `#__alfa_user_info` record, both keyed by column).
     *
     * @param array|object|null $details The delivery details.
     *
     * @return array{customer_name: string, customer_email: string, customer_is_guest: int}
     *
     * @since  2.0.0
     */
    public static function snapshotFromDetails(array|object|null $details): array
    {
        $values = RoleRegistry::valuesFromRow(\is_array($details) ? (object) $details : $details);

        return [
            'customer_name' => $values[RoleRegistry::NAME] ?? '',
            'customer_email' => $values[RoleRegistry::EMAIL] ?? '',
            'customer_is_guest' => 1,
        ];
    }

    /**
     * The customer of an order as the snapshot records it.
     *
     * Orders created before the snapshot existed carry an empty one; a member
     * order among them still resolves through the account, so nothing that
     * worked before reads as a nameless guest.
     *
     * @param object $order The `#__alfa_orders` row (id_user, customer_* columns).
     *
     * @return array{name: string, email: string, is_guest: bool, kind: string}
     *
     * @since  2.0.0
     */
    public static function forOrder(object $order): array
    {
        $userId = (int) ($order->id_user ?? 0);
        $isGuest = (int) ($order->customer_is_guest ?? 0) === 1;
        $name = trim((string) ($order->customer_name ?? ''));
        $email = trim((string) ($order->customer_email ?? ''));

        if ($name === '' && $email === '' && $userId > 0) {
            $account = self::account($userId);
            $name = (string) ($account->name ?? '');
            $email = (string) ($account->email ?? '');
        }

        if ($isGuest) {
            $kind = self::KIND_GUEST;
        } elseif ($userId > 0) {
            $kind = self::KIND_ACCOUNT;
        } else {
            // Not a guest and no account left: the account was deleted after
            // the order (Alfasync clears id_user on deletion).
            $kind = self::KIND_DELETED;
        }

        return ['name' => $name, 'email' => $email, 'is_guest' => $isGuest, 'kind' => $kind];
    }

    /**
     * Where the customer's notifications go.
     *
     * A member's LIVE account address while the account exists (a corrected
     * typo must reach them), else the snapshot (guests, deleted accounts).
     *
     * @param object $order The `#__alfa_orders` row.
     *
     * @return string The address, '' when none is known.
     *
     * @since  2.0.0
     */
    public static function notificationEmail(object $order): string
    {
        $userId = (int) ($order->id_user ?? 0);

        if ($userId > 0) {
            $live = trim((string) (self::account($userId)->email ?? ''));

            if ($live !== '') {
                return $live;
            }
        }

        return self::forOrder($order)['email'];
    }

    /**
     * The buyer a sales document is made out to.
     *
     * When the order invoices to a record of its own, the invoicee is that
     * record's name/email (the customer asked for different details on the
     * document); otherwise the customer who placed the order.
     *
     * @param object $order The `#__alfa_orders` row.
     *
     * @return array{buyer_name: string, buyer_email: string}
     *
     * @since  2.0.0
     */
    public static function buyer(object $order): array
    {
        $customer = self::forOrder($order);
        $buyer = ['buyer_name' => $customer['name'], 'buyer_email' => $customer['email']];

        $invoiceId = (int) ($order->id_address_invoice ?? 0);
        $deliveryId = (int) ($order->id_address_delivery ?? 0);

        if ($invoiceId > 0 && $invoiceId !== $deliveryId) {
            $values = RoleRegistry::valuesFromRow(self::record($invoiceId));
            $userId = (int) ($order->id_user ?? 0);

            if (($values[RoleRegistry::NAME] ?? '') !== '') {
                $buyer['buyer_name'] = $values[RoleRegistry::NAME];
            }

            // A member's email belongs to the account, wherever else a copy of
            // it may sit: a record written before that rule existed must not
            // outrank the address the customer maintains in their profile.
            if (
                ($values[RoleRegistry::EMAIL] ?? '') !== ''
                && !RoleRegistry::accountOwned(RoleRegistry::EMAIL, $userId)
            ) {
                $buyer['buyer_email'] = $values[RoleRegistry::EMAIL];
            }
        }

        return $buyer;
    }

    /**
     * Load an order row by id (the columns this service reads).
     *
     * @param int $orderId The order id.
     *
     *
     * @since  2.0.0
     */
    public static function loadOrder(int $orderId): ?object
    {
        if ($orderId <= 0) {
            return null;
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('id'),
                    $db->quoteName('id_user'),
                    $db->quoteName('customer_name'),
                    $db->quoteName('customer_email'),
                    $db->quoteName('customer_is_guest'),
                    $db->quoteName('id_address_delivery'),
                    $db->quoteName('id_address_invoice'),
                ])
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = ' . $orderId);

            return $db->setQuery($query)->loadObject() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * A `#__users` row (id, name, email, username), or null.
     *
     * @param int $userId The user id.
     *
     *
     * @since  2.0.0
     */
    private static function account(int $userId): ?object
    {
        static $cache = [];

        if ($userId <= 0) {
            return null;
        }

        if (array_key_exists($userId, $cache)) {
            return $cache[$userId];
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select([$db->quoteName('id'), $db->quoteName('name'), $db->quoteName('email'), $db->quoteName('username')])
                ->from($db->quoteName('#__users'))
                ->where($db->quoteName('id') . ' = ' . $userId);

            return $cache[$userId] = ($db->setQuery($query)->loadObject() ?: null);
        } catch (Throwable $e) {
            return $cache[$userId] = null;
        }
    }

    /**
     * A `#__alfa_user_info` record by id, or null.
     *
     * @param int $id The record id.
     *
     *
     * @since  2.0.0
     */
    private static function record(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_user_info'))
                ->where($db->quoteName('id') . ' = ' . $id);

            return $db->setQuery($query)->loadObject() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
