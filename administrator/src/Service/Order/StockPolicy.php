<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Throwable;

/**
 * Order-status stock semantics.
 *
 * Founded on the inventory kernel: this helper interprets what a status
 * MEANS for stock (stock_operation) and calls the order-side inventory
 * adapter; it never touches a stock column. All quantity changes run as
 * InventoryService commands (movements, reservations, projections).
 *
 * STATUS SEMANTICS (stock_operation on #__alfa_orders_statuses):
 *   0 = items should be OUT of stock  -> fulfil (convert reservations)
 *   1 = items should be IN stock      -> restore what's out, release holds
 *
 * @since  1.0.0
 */
class StockPolicy
{
    /**
     * The claim an order holds on stock. Physical and reserved are separate
     * axes: a claim is one of these three, never a combination.
     *
     * @since  2.0.0
     */
    public const CLAIM_NONE = 'none';

    /** The goods are physically deducted for this order. */
    public const CLAIM_PHYSICAL = 'physical';

    /** The goods stay on the shelf; the order holds a reservation. */
    public const CLAIM_RESERVATION = 'reservation';

    /**
     * Get the default order status for new orders.
     *
     * Delegates to OrderStatusHelper::getInitialId(), the single
     * source of truth for "which row plays the brand-new-order role".
     * The helper's fallback chain (is_initial = 1 -> first published
     * row by ordering) covers the freshly-installed case where no role
     * has been nominated yet.
     *
     * The ROLE is a flag on `#__alfa_orders_statuses`; the NAME is not:
     * it lives only in the per-language tables. The row is therefore
     * taken from AlfaHelper::getOrderStatuses(), which joins them, so
     * callers that name the status in a message or a log get the
     * translated name rather than a missing property.
     *
     * Returns an emergency stub only when the statuses table is empty,
     * which should never happen in production but is logged at
     * CRITICAL so it's visible if it does.
     *
     * @return object Status row with at least: id, name, stock_operation,
     *                state. Additional columns are passed through from
     *                the underlying row.
     *
     * @since  1.0.0
     */
    public static function getDefaultOrderStatus(): object
    {
        $initialId = OrderStatusHelper::getInitialId();
        $status = $initialId !== null ? (AlfaHelper::getOrderStatuses()[$initialId] ?? null) : null;

        if ($status !== null) {
            return $status;
        }

        Log::add(
            entry:    'No order statuses found in database; returning emergency stub.',
            priority: Log::CRITICAL,
            category: 'com_alfa.orders',
        );

        return (object) [
            'id' => 0,
            'name' => 'Unknown',
            'stock_operation' => 0,
            'state' => 1,
        ];
    }

    /**
     * Check whether a given status requires stock to be OUT (deducted).
     *
     * stock_operation column on #__alfa_orders_statuses:
     *   0 = items should be OUT of stock (Confirmed, Shipped, Completed)
     *   1 = items should be IN stock     (Cancelled)
     *
     * @param int $statusId Order status ID
     * @return bool True if stock should be deducted (stock_operation = 0)
     * @since  1.0.0
     */
    public static function shouldDeductStock(int $statusId): bool
    {
        $statuses = AlfaHelper::getOrderStatuses();

        if (!isset($statuses[$statusId])) {
            Log::add(
                "shouldDeductStock(): Unknown status ID {$statusId}, defaulting to deduct",
                Log::WARNING,
                'com_alfa.orders',
            );
            return true;
        }

        // stock_operation = 0 means "remove from stock" -> should deduct
        return (int) $statuses[$statusId]->stock_operation === 0;
    }

    /**
     * What claim on stock an order in this status may hold.
     *
     * THE SINGLE AUTHORITY. Placement, status transitions and line syncs all
     * used to answer this question for themselves, from `stock_operation`
     * alone, and disagreed: a cancelled order kept (or a later save
     * re-created) a reservation, while an order moved to a legitimate
     * non-deducting status lost the hold it should have kept.
     *
     * The two axes are independent and this is where that is stated:
     *   `stock_operation` is the PHYSICAL axis: deduct the goods or not.
     *   Cancellation is the CLAIM axis: a cancelled order claims nothing at
     *   all, in either form, whatever its stock_operation happens to say.
     *
     * @param int $statusId Order status ID
     *
     * @return string One of the CLAIM_* constants.
     *
     * @since  2.0.0
     */
    public static function claimFor(int $statusId): string
    {
        $statuses = AlfaHelper::getOrderStatuses();
        $status = $statuses[$statusId] ?? null;

        if ($status === null) {
            // Same fail-safe as shouldDeductStock(): an unknown status is
            // treated as a live, deducting order rather than silently
            // releasing goods.
            Log::add(
                "claimFor(): Unknown status ID {$statusId}, defaulting to a physical claim",
                Log::WARNING,
                'com_alfa.orders',
            );

            return self::CLAIM_PHYSICAL;
        }

        if ((int) $status->is_cancelled === 1) {
            return self::CLAIM_NONE;
        }

        return (int) $status->stock_operation === 0 ? self::CLAIM_PHYSICAL : self::CLAIM_RESERVATION;
    }

    /**
     * Handle stock on an order status transition.
     *
     * Reconciles to the TARGET STATUS'S CLAIM (see claimFor()), not to a
     * change in stock_operation. Keying on stock_operation alone conflated
     * two different destinations ("hold a reservation" and "hold nothing"),
     * so a move between two non-deducting statuses (awaiting payment ->
     * cancelled) was read as "no change" and left the cancelled order still
     * holding stock.
     *
     * Destinations:
     *   -> CLAIM_NONE         restore what is out AND release every hold
     *   -> CLAIM_RESERVATION  return the physical deduction, keep the hold
     *   -> CLAIM_PHYSICAL     fulfil: convert the hold into a deduction
     *
     * Idempotent by the kernel's per-line ledger, so a re-fired transition
     * is a no-op.
     *
     * @param int $orderId Order PK
     * @param int $oldStatusId Previous order status ID
     * @param int $newStatusId New order status ID
     * @return array ['action' => 'restored'|'reserved'|'deducted'|'none', 'count' => int]
     * @since  1.0.0
     */
    public static function handleStatusTransition(int $orderId, int $oldStatusId, int $newStatusId): array
    {
        $statuses = AlfaHelper::getOrderStatuses();

        $oldClaim = self::claimFor($oldStatusId);
        $newClaim = self::claimFor($newStatusId);

        // Same claim on both sides -> nothing to reconcile.
        if ($oldClaim === $newClaim) {
            return ['action' => 'none', 'count' => 0];
        }

        $oldStatusName = $statuses[$oldStatusId]->name ?? "#{$oldStatusId}";
        $newStatusName = $statuses[$newStatusId]->name ?? "#{$newStatusId}";

        $applied = self::applyClaim(orderId: $orderId, claim: $newClaim);
        $where = [
            'old_status' => ['id' => $oldStatusId, 'name' => $oldStatusName, 'claim' => $oldClaim],
            'new_status' => ['id' => $newStatusId, 'name' => $newStatusName, 'claim' => $newClaim],
        ];

        if ($applied['action'] === 'restored') {
            OrderModel::logOrderActivity(
                $orderId,
                'stock.restored',
                "Stock restored: \"{$oldStatusName}\" -> \"{$newStatusName}\" ({$applied['detail']['restored_quantity']} units returned, {$applied['detail']['released_quantity']} released)",
                $where + $applied['detail'],
            );
        } elseif ($applied['action'] === 'reserved') {
            OrderModel::logOrderActivity(
                $orderId,
                'stock.reserved',
                "Stock returned and held: \"{$oldStatusName}\" -> \"{$newStatusName}\"",
                $where,
            );
        } else {
            OrderModel::logOrderActivity(
                $orderId,
                'stock.deducted',
                "Stock deducted: \"{$oldStatusName}\" -> \"{$newStatusName}\" ({$applied['detail']['deducted_quantity']} units, {$applied['count']} lines)",
                $where + $applied['detail'],
            );
        }

        return ['action' => $applied['action'], 'count' => $applied['count']];
    }

    /**
     * RECONCILE AN ORDER'S STOCK TO WHATEVER ITS STATUS CLAIMS RIGHT NOW.
     *
     * The transition path above is a convenience, not the authority: it knows
     * where the order came from, so it can skip the work when the claim did
     * not change. This one takes no history at all. It reads the order's
     * CURRENT status and makes the shelf agree with it.
     *
     * That difference is the whole point. A missed effect used to be
     * unrecoverable because the reconciler was only ever invoked with a
     * from/to pair, and on the shipped status set almost every pair is
     * claim-equal, so the miss was skipped for ever. Asking "what does this
     * order claim now?" cannot be skipped, and cannot apply a stale effect
     * either: a transition that lands while this is queued simply changes the
     * answer this call will read.
     *
     * @param int $orderId Order PK.
     *
     * @return array{action: string, count: int, status: int, claim: string}
     *
     * @since  2.0.0
     */
    public static function reconcileOrderClaim(int $orderId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        // READ THE STATUS UNDER THE LOCK THAT OWNS IT.
        //
        // Reading it first and locking later is a check-then-act: a transition
        // committing in that gap would leave this call applying the claim of a
        // status the order has already left, and (because it would then report
        // success) the newer claim would never be reconciled at all.
        //
        // Transition::save() decides under the same FOR UPDATE on the order
        // row, so taking it here makes the two serialise: whichever goes first,
        // the other sees the world it left. Savepoint-aware, because a producer
        // draining its own obligation is already inside a transaction.
        $db->transactionStart(true);

        try {
            $db->setQuery(
                'SELECT ' . $db->quoteName('id_order_status') . ' FROM ' . $db->quoteName('#__alfa_orders')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $orderId . ' FOR UPDATE',
            );
            $statusId = (int) $db->loadResult();

            if ($statusId <= 0) {
                // The order is gone. Nothing to reconcile, and nothing to warn
                // about: a deleted order's lines were released by its own delete.
                TransactionState::commitLevel($db);

                return ['action' => 'none', 'count' => 0, 'status' => 0, 'claim' => self::CLAIM_NONE];
            }

            $claim = self::claimFor($statusId);
            $applied = self::applyClaim(orderId: $orderId, claim: $claim);
            TransactionState::commitLevel($db);
        } catch (Throwable $e) {
            // A deadlock has ended the whole transaction: its savepoint is
            // gone, and a plain rollback would replace the deadlock with its
            // own failure and leave a level counted.
            TransactionState::rollBackLevel($db, $e);

            throw $e;
        }

        $statuses = AlfaHelper::getOrderStatuses();
        $statusName = $statuses[$statusId]->name ?? "#{$statusId}";

        OrderModel::logOrderActivity(
            $orderId,
            'stock.reconciled',
            "Stock reconciled to \"{$statusName}\" ({$applied['action']})",
            [
                'status' => ['id' => $statusId, 'name' => $statusName, 'claim' => $claim],
            ] + $applied['detail'],
        );

        // THE ALERT IS RETRACTED BY THE RECOVERY, NOT BY A HUMAN. An operator
        // told "the order and the shelf disagree" must be told when they no
        // longer do, or the panel accumulates warnings about problems that
        // fixed themselves.
        try {
            $key = 'orders.transition.stockfailed.' . $orderId;

            if (NotificationHelper::exists(dedupKey: $key)) {
                NotificationHelper::clear(dedupKey: $key);
            }
        } catch (Throwable $clearError) {
            // Advisory only: a failed retraction never fails the reconcile, unless it ended a caller's transaction.
            if (TransactionState::endedBy(db: Factory::getContainer()->get('DatabaseDriver'), failure: $clearError)) {
                throw $clearError;
            }
        }

        return ['action' => $applied['action'], 'count' => $applied['count'],
            'status' => $statusId, 'claim' => $claim];
    }

    /**
     * The destination action for a claim: the one place the mapping lives.
     *
     * Deliberately takes no from/to pair: every branch is a function of where
     * the order should end up, which is why the same three verbs serve both a
     * transition and a from-scratch reconciliation.
     *
     * @param int $orderId Order PK.
     * @param string $claim The claim the order's status asserts.
     *
     * @return array{action: string, count: int, detail: array}
     *
     * @since  2.0.0
     */
    private static function applyClaim(int $orderId, string $claim): array
    {
        if ($claim === self::CLAIM_NONE) {
            // Cancelled: the order claims nothing in either form. This is the
            // one destination where restoreOrder()'s composite is exactly
            // right: return what is out AND let every hold go.
            $result = InventoryBridge::restoreOrder($orderId);

            return [
                'action' => 'restored',
                'count' => (int) ceil((float) $result['restored_quantity']),
                'detail' => [
                    'restored_quantity' => $result['restored_quantity'],
                    'released_quantity' => $result['released_quantity'],
                ],
            ];
        }

        if ($claim === self::CLAIM_RESERVATION) {
            // Still a live order, just not a deducting one: the goods go back
            // on the shelf but the order keeps its place in the queue.
            // syncOrderLines() is that reconciliation already (it restores
            // what is out and converges the hold to the line quantities), so
            // no new verb is needed and restoreOrder() is deliberately NOT
            // used here: it would let the hold go.
            InventoryBridge::syncOrderLines($orderId);

            return ['action' => 'reserved', 'count' => 0, 'detail' => []];
        }

        // "Remove from stock" -> fulfil through the kernel.
        $result = InventoryBridge::commitOrder($orderId);

        return [
            'action' => 'deducted',
            'count' => (int) $result['lines'],
            'detail' => ['deducted_quantity' => $result['deducted_quantity'], 'lines' => (int) $result['lines']],
        ];
    }

    /**
     * Fulfil ALL order lines (convert reservations to deductions).
     *
     * Used when:
     *   - Order is placed with a deducting status
     *   - Order status changes from non-deducting to deducting
     *
     * Idempotent by the kernel's per-line ledger.
     *
     * @param int $orderId Order PK
     * @return int Number of lines fulfilled
     * @since  1.0.0
     */
    public static function deductOrderStock(int $orderId): int
    {
        return (int) InventoryBridge::commitOrder($orderId)['lines'];
    }

    /**
     * Return ALL order quantities to stock and release remaining holds.
     *
     * Used when:
     *   - Order status changes to a non-deducting status (e.g. Cancelled)
     *
     * Idempotent by the kernel's per-line ledger.
     *
     * @param int $orderId Order PK
     * @return int Restored units (rounded up for reporting)
     * @since  1.0.0
     */
    public static function restoreOrderStock(int $orderId): int
    {
        return (int) ceil((float) InventoryBridge::restoreOrder($orderId)['restored_quantity']);
    }
}
