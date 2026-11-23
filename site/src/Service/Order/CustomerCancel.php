<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationText;
use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Order\EmailService;
use Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger;
use Alfa\Component\Alfa\Administrator\Service\Order\Transition;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseDriver;
use Throwable;

/**
 * A customer cancelling their own order from their account.
 *
 * Two settings of the shop decide it, and neither is ever inferred: a status allows it (its "Customers may cancel" flag,
 * customer_can_cancel), and Options name the status a cancelled order moves to ("Customer cancel status",
 * customer_cancel_status: a published status of the cancelled family). Until both are set no order may be cancelled by
 * its customer, which is how every shop stands after the update; a new shop has its Pending status flagged and its
 * Cancelled status named.
 *
 * An order may be cancelled while all of this holds, read again under the order's row lock before anything is written:
 * - it is the customer's own order (its account, logged in), not in the trash;
 * - the shop has named a status to move it to, published and in the cancelled family;
 * - the order's status allows its customer to cancel it, and is not that status;
 * - no payment of it is open at a payment provider (a gateway's payment or authorisation still pending or held): the
 *   money could still arrive after the order is cancelled, so the customer is asked to wait until it settles or the
 *   attempt expires.
 *
 * The move is the order's ordinary status change (Transition) to the shop's status, made only while the order is still
 * in the status the customer saw (expectStatus()), attributed to the customer, with the note "Cancelled by the
 * customer" in its history. The status decides everything else, as for any change into it: its stock rule, the
 * customer's e-mail when it sends one, the staff e-mail to its recipients. Nothing is refunded by it. Staff are told
 * by a notification as well, so a cancelled order never waits unseen.
 *
 * @since  2.3.0
 */
final class CustomerCancel
{
    /** The order may be cancelled by its customer. */
    public const OPEN = 'open';

    /** The order was cancelled. */
    public const CANCELLED = 'cancelled';

    /** No such order of this customer: not there, in the trash, another's, or no customer logged in. */
    public const NOT_AVAILABLE = 'not_available';

    /** The shop has not set the customer cancel up: no status named in Options, or one that cannot take it. */
    public const OFF = 'off';

    /** The order's status does not allow its customer to cancel it. */
    public const NOT_ALLOWED = 'not_allowed';

    /** A payment of the order is still open at a payment provider. */
    public const HELD = 'held';

    /** The order is no longer in the status the customer saw: someone changed it meanwhile. */
    public const SUPERSEDED = 'superseded';

    /** The order is being changed by someone else right now; asking again may succeed. */
    public const BUSY = 'busy';

    /** The status change was refused (the target status's own requirements). */
    public const REFUSED = 'refused';

    /** The Options key naming the status a customer's cancel moves the order to. */
    public const OPTION = 'customer_cancel_status';

    /** The note the order's history carries for the change. */
    private const NOTE = 'Cancelled by the customer';

    /**
     * The status the shop names for a customer's cancel, when it can take one: published and in the cancelled family.
     *
     * @return int|null Null while the shop has named none, or names a status that cannot take it.
     *
     * @since  2.3.0
     */
    public static function target(): ?int
    {
        $statusId = (int) ComponentHelper::getParams('com_alfa')->get(self::OPTION, 0);
        $status = OrderStatusHelper::getById(statusId: $statusId);

        return $status !== null && (int) ($status->state ?? 0) === 1 && (int) ($status->is_cancelled ?? 0) === 1
            ? $statusId
            : null;
    }

    /**
     * Whether a status allows customers to cancel their orders in it.
     *
     * @param int $statusId The status.
     *
     * @since  2.3.0
     */
    public static function allowedIn(int $statusId): bool
    {
        return (int) (OrderStatusHelper::getById(statusId: $statusId)->customer_can_cancel ?? 0) === 1;
    }

    /**
     * Whether a customer may cancel an order now, and if not why: one of the constants above (never CANCELLED).
     *
     * @param int $orderId The order.
     * @param int $customerId The customer logged in; 0 for none.
     *
     * @since  2.3.0
     */
    public static function verdict(int $orderId, int $customerId): string
    {
        return self::verdictFor(order: self::row(orderId: $orderId), customerId: $customerId);
    }

    /**
     * Cancel an order for its customer, as the order page asks it: only while the order is in the status the page
     * showed, everything asked again under the order's row lock.
     *
     * @param int $orderId The order.
     * @param int $customerId The customer logged in.
     * @param int $shownStatusId The status the order was in on the page the customer pressed Cancel order on.
     *
     * @return string CANCELLED, or why not: one of the other constants above.
     *
     * @throws Throwable A failure the status change does not answer itself (rethrown once the transaction is closed).
     *
     * @since  2.3.0
     */
    public static function cancel(int $orderId, int $customerId, int $shownStatusId): string
    {
        if ($orderId <= 0 || $customerId <= 0) {
            return self::NOT_AVAILABLE;
        }

        $db = self::db();
        $db->transactionStart(true);

        try {
            $order = self::row(orderId: $orderId, lock: true);
            $verdict = self::verdictFor(order: $order, customerId: $customerId);

            if ($verdict === self::OPEN && (int) $order->id_order_status !== $shownStatusId) {
                $verdict = self::SUPERSEDED;
            }

            if ($verdict !== self::OPEN) {
                TransactionState::rollBackLevel(db: $db);

                return $verdict;
            }

            $target = (int) self::target();
            $transition = Transition::for($orderId)
                ->to($target)
                ->expectStatus($shownStatusId)
                ->byUser($customerId)
                ->note(self::NOTE);
            $outcome = $transition->outcome(moved: $transition->save());

            if ($outcome !== Transition::OUTCOME_MOVED) {
                TransactionState::rollBackLevel(db: $db);

                return match ($outcome) {
                    Transition::OUTCOME_BUSY => self::BUSY,
                    Transition::OUTCOME_SUPERSEDED => self::SUPERSEDED,
                    default => self::REFUSED,
                };
            }

            // Staff learn of it at once: written with the change, so a rollback takes it too.
            $number = EmailService::orderNumber(order: $order);
            NotificationHelper::push(
                dedupKey: 'order.customer_cancel.' . $orderId,
                title: NotificationText::of('COM_ALFA_NOTIFY_CUSTOMER_CANCEL_TITLE', $number),
                options: [
                    'group' => NotificationHelper::GROUP_ORDERS,
                    'severity' => 'warning',
                    'message' => NotificationText::of('COM_ALFA_NOTIFY_CUSTOMER_CANCEL_MSG', $number),
                    'url' => 'index.php?option=com_alfa&task=order.edit&id=' . $orderId,
                    'view' => ['action' => 'alfa.orders.view', 'asset' => 'com_alfa'],
                    'link' => ['action' => 'alfa.orders.edit', 'asset' => 'com_alfa'],
                ],
            );

            TransactionState::commitLevel(db: $db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        return self::CANCELLED;
    }

    /**
     * The sentence a customer reads for a cancel's outcome or a refusal.
     *
     * @param string $verdict One of the constants above.
     *
     * @since  2.3.0
     */
    public static function sentence(string $verdict): string
    {
        return Text::_(match ($verdict) {
            self::CANCELLED => 'COM_ALFA_ORDER_CANCEL_DONE',
            self::OFF, self::NOT_ALLOWED => 'COM_ALFA_ORDER_CANCEL_NOT_ALLOWED',
            self::HELD => 'COM_ALFA_ORDER_CANCEL_HELD',
            self::SUPERSEDED => 'COM_ALFA_ORDER_CANCEL_SUPERSEDED',
            self::BUSY => 'COM_ALFA_ORDER_CANCEL_BUSY',
            self::REFUSED => 'COM_ALFA_ORDER_CANCEL_REFUSED',
            default => 'COM_ALFA_ORDER_NOT_AVAILABLE',
        });
    }

    /**
     * {@see self::verdict()} for an order already read.
     *
     * @param object|null $order The order's row (id, id_user, id_order_status, state); null when there is none.
     * @param int $customerId The customer logged in; 0 for none.
     *
     * @since  2.3.0
     */
    private static function verdictFor(?object $order, int $customerId): string
    {
        if ($order === null || $customerId <= 0 || (int) $order->id_user !== $customerId || (int) $order->state === -2) {
            return self::NOT_AVAILABLE;
        }

        $target = self::target();

        if ($target === null) {
            return self::OFF;
        }

        $statusId = (int) $order->id_order_status;

        if ($statusId === $target || !self::allowedIn(statusId: $statusId)) {
            return self::NOT_ALLOWED;
        }

        return PaymentLedger::gatewayAttemptOpen(orderId: (int) $order->id) ? self::HELD : self::OPEN;
    }

    /**
     * The order's row, under its row lock when asked.
     *
     * @param int $orderId The order.
     * @param bool $lock Whether to lock the row (inside the caller's transaction).
     *
     * @since  2.3.0
     */
    private static function row(int $orderId, bool $lock = false): ?object
    {
        if ($orderId <= 0) {
            return null;
        }

        $db = self::db();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'id_user', 'id_order_status', 'state']))
            ->from($db->quoteName('#__alfa_orders'))
            ->where($db->quoteName('id') . ' = ' . $orderId);
        $order = $db->setQuery($lock ? $query . ' FOR UPDATE' : $query)->loadObject();

        return \is_object($order) ? $order : null;
    }

    /**
     * The database driver whose transaction levels Alfa keeps.
     *
     * @since  2.3.0
     */
    private static function db(): DatabaseDriver
    {
        return TransactionState::driver(Factory::getContainer()->get('DatabaseDriver'));
    }
}
