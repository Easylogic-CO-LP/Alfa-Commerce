<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CommitmentRequest;
use Alfa\Component\Alfa\Administrator\Service\Inventory\DefaultReservationPolicy;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryContext;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryUnitException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\ReconciliationIntent;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Throwable;

/**
 * Order-side adapter of the inventory kernel: translates ORDER semantics
 * (placement, status stock semantics, line edits) into inventory
 * COMMANDS. Owns zero inventory logic: matching, guards, ledgers and
 * projections live in InventoryService; this class only builds requests.
 *
 * Reservations follow the order lifecycle; physical deduction/restore
 * follow fulfilment semantics (status stock_operation now, document
 * stock effects through the same commitment entry).
 *
 * @since  1.4.0
 */
class InventoryBridge
{
    /**
     * Reserve the order's lines at placement (policy-gated). Idempotent:
     * already-reserved lines are skipped by the service.
     *
     * @param int $orderId The order.
     * @param InventoryContext|null $context Audit context.
     *
     * @return array|null The reserve summary, or null when the policy
     *                    holds the commitment for a later moment.
     *
     * @since  1.4.0
     */
    public static function reserveOrder(int $orderId, ?InventoryContext $context = null): ?array
    {
        $order = self::orderRow($orderId);

        if ($order === null || !(new DefaultReservationPolicy())->shouldReserve($order)) {
            return null;
        }

        $lines = self::orderLines($orderId);

        if ($lines === []) {
            return null;
        }

        return (new InventoryService())->reserve(
            lines: $lines,
            sourceType: 'order',
            sourceId: $orderId,
            context: $context ?? self::context(),
        );
    }

    /**
     * Fulfil the order's lines: reservations convert to physical
     * deductions through the single commitment entry. Idempotent by the
     * per-line ledger: statuses and documents can both fire without
     * double-deducting.
     *
     * The order deducts only its OWN share of each line: the line quantity less
     * what other sources (a delivery note, a return) currently have out for that
     * line. Units a document already shipped have left the shelf once and are not
     * the order's to take again, so a line's physical net never exceeds its
     * ordered quantity, whichever of the two moved first. A net restore by another
     * source (a credit note) is no reason to take more: only units currently out
     * reduce the order's share. Units the order itself put back are not out any
     * more: a reinstated order whose cancellation gave a delivery note's units back
     * deducts the whole line on the variant the line sells now, or the commit is
     * refused for that variant's stock ({@see self::ownShare()}). This is the
     * target the line reconciliation converges on in a deducting status
     * ({@see self::syncOrderLines()}).
     *
     * What other sources have out, and what the order itself has out per unit of
     * a line they have something out on, are read under the order's row lock, the
     * anchor every deducting trigger of its lines takes, and as the latest
     * committed state, so a document's deduction and this commit cannot both read
     * the line as uncovered. The unit a line sells now is read, the same way, only
     * for a line whose order put back stock other sources have out for it.
     *
     * Once the commit has applied, a line keeps no reservation beyond what it
     * still owes the order on the variant it sells now
     * ({@see self::releaseBeyondShares()}): a hold the commit did not convert,
     * because a document already shipped the line, or because it stands on a
     * variant the line no longer sells, is released. A refused commit throws
     * before that and keeps its reservations as the claim it still owes.
     *
     * @param int $orderId The order.
     * @param InventoryContext|null $context Audit context.
     *
     * @return array The commitment summary.
     *
     * @since  1.4.0
     */
    public static function commitOrder(int $orderId, ?InventoryContext $context = null): array
    {
        $service = new InventoryService();
        $db = Factory::getContainer()->get(resourceName: 'DatabaseDriver');

        $db->transactionStart(asSavepoint: true);

        try {
            $db->setQuery(
                query: 'SELECT ' . $db->quoteName(name: 'id') . ' FROM ' . $db->quoteName(name: '#__alfa_orders')
                . ' WHERE ' . $db->quoteName(name: 'id') . ' = ' . $orderId . ' FOR UPDATE',
            )->loadResult();

            $orderLines = self::orderLines(orderId: $orderId);

            // What every other source has out on each line, unit by unit, as the
            // latest committed state.
            $foreign = $service->foreignUnitNets(
                sourceType: 'order',
                sourceId:   $orderId,
                lineIds:    array_column(array: $orderLines, column_key: 'line_id'),
                current:    true,
            );

            // What the order itself has out per unit, for the lines another source has
            // something out on, as the latest committed state.
            $own = $foreign === []
                ? []
                : $service->lineUnitNets(sourceType: 'order', sourceId: $orderId, lineIds: array_keys(array: $foreign), current: true);

            // The unit a line sells now, read only for a line whose order put back stock
            // other sources have out for it: there alone does it change the share.
            $givingBack = array_values(array: array_filter(
                array: $orderLines,
                callback: static fn (array $line): bool => self::givenBack(
                    othersOn: $foreign[$line['line_id']] ?? [],
                    ownUnits: $own[$line['line_id']] ?? [],
                    sells:    null,
                ) < -InventoryAvailabilityService::QUANTITY_TOLERANCE,
            ));
            $identities = $givingBack === [] ? [] : $service->lineIdentities(lines: $givingBack, current: true);
            $lines = [];
            $shares = [];

            foreach ($orderLines as $line) {
                $acting = $identities[$line['line_id']] ?? null;
                $ownTarget = self::ownShare(
                    quantity:  $line['quantity'],
                    othersOn:  $foreign[$line['line_id']] ?? [],
                    givenBack: self::givenBack(
                        othersOn: $foreign[$line['line_id']] ?? [],
                        ownUnits: $own[$line['line_id']] ?? [],
                        sells:    $acting === null ? null : $acting['item_id'] . ':' . $acting['combination_id'],
                    ),
                );

                $line['quantity'] = $ownTarget;
                $line['ordered'] = $ownTarget;
                $line['mode'] = 'target';
                $lines[] = $line;
                $shares[$line['line_id']] = $ownTarget;
            }

            $result = $service->commitReservation(
                request: new CommitmentRequest(sourceType: 'order', sourceId: $orderId, lines: $lines),
                context: $context ?? self::context(),
            );

            // Only once the claim applied: what a line still holds beyond its share
            // is a claim nothing converts any more.
            self::releaseBeyondShares(service: $service, orderId: $orderId, lines: $orderLines, shares: $shares, context: $context ?? self::context());

            TransactionState::commitLevel(db: $db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        return $result;
    }

    /**
     * Give back what the order's own deduction has out beyond its share of the
     * lines a document names, once that document's stock effect is applied.
     *
     * A document moves the goods it names on the identity it froze when it was
     * issued; the order deducts a line on the identity the line sells now. When a
     * line moved to another variant while a document's stock effect was still
     * owed, both deduct, each on its own variant. Once the document's movement
     * exists, the order's share of each line the document names is the share the
     * status commit takes ({@see self::ownShare()}): the line quantity less what
     * other sources have out for the line, the stock of theirs the order put back
     * not counted as out, clamped at zero. What the order has out above that share
     * (what it put back of other sources' stock already counted in the share) comes
     * back, first from a variant the line no longer sells, then from the one it
     * sells now. The document's own rows are never touched, so the line's stock
     * stays out under the document, on the identity it froze.
     *
     * Only the lines named, only while the order's status deducts its stock, and
     * only the order's own deduction: an order whose status holds a reservation or
     * nothing, and a line the order has no more out on than its share, are left as
     * they are. Nothing is deducted here: a share the order has not taken yet is
     * its stock claim's to take.
     *
     * The order's row is locked first, as every reconciliation of its lines takes
     * it, and what each source has out is read under it as the latest committed
     * state. The give-back is one restore of the inventory service, which takes
     * the stock records of every unit it acts on in ascending order. A failure
     * rolls back everything this call wrote and is thrown to the caller.
     *
     * @param int $orderId The document's order.
     * @param int[] $lineIds The order lines the document names.
     * @param InventoryContext|null $context Audit context.
     *
     * @return array{restored_quantity: float, lines: int} What came back, and on how many lines.
     *
     * @since  1.14.0
     */
    public static function convergeDocumentLines(int $orderId, array $lineIds, ?InventoryContext $context = null): array
    {
        $named = array_values(array: array_unique(array: array_filter(array: array_map(callback: 'intval', array: $lineIds))));
        $converged = ['restored_quantity' => 0.0, 'lines' => 0];

        if ($orderId <= 0 || $named === []) {
            return $converged;
        }

        $db = Factory::getContainer()->get(resourceName: 'DatabaseDriver');

        $db->transactionStart(asSavepoint: true);

        try {
            $statusId = (int) $db->setQuery(
                query: 'SELECT ' . $db->quoteName(name: 'id_order_status') . ' FROM ' . $db->quoteName(name: '#__alfa_orders')
                . ' WHERE ' . $db->quoteName(name: 'id') . ' = ' . $orderId . ' FOR UPDATE',
            )->loadResult();

            $lines = $statusId > 0 && StockPolicy::claimFor(statusId: $statusId) === StockPolicy::CLAIM_PHYSICAL
                ? array_values(array: array_filter(
                    array: self::orderLines(orderId: $orderId),
                    callback: static fn (array $line): bool => \in_array(needle: $line['line_id'], haystack: $named, strict: true),
                ))
                : [];

            if ($lines !== []) {
                $service = new InventoryService();
                $lineIds = array_column(array: $lines, column_key: 'line_id');

                // What every other source has out on each line, and what the order
                // itself has out, unit by unit, as the latest committed state.
                $foreign = $service->foreignUnitNets(sourceType: 'order', sourceId: $orderId, lineIds: $lineIds, current: true);
                $own = $service->lineUnitNets(sourceType: 'order', sourceId: $orderId, lineIds: $lineIds, current: true);
                $identities = $service->lineIdentities(lines: $lines, current: true);
                $restoreLines = [];

                foreach ($lines as $line) {
                    $units = $own[$line['line_id']] ?? [];
                    $sells = $identities[$line['line_id']]['item_id'] . ':' . $identities[$line['line_id']]['combination_id'];

                    // The share the status commit takes. What the order put back of
                    // other sources' stock is counted in it, so it is not counted
                    // again against what the order has out.
                    $givenBack = self::givenBack(othersOn: $foreign[$line['line_id']] ?? [], ownUnits: $units, sells: $sells);
                    $share = self::ownShare(quantity: $line['quantity'], othersOn: $foreign[$line['line_id']] ?? [], givenBack: $givenBack);
                    $excess = array_sum(array: array_column(array: $units, column_key: 'deducted_net')) - $givenBack - $share;

                    if ($excess <= InventoryAvailabilityService::QUANTITY_TOLERANCE) {
                        continue;
                    }

                    // A variant the line no longer sells gives back first, the one it
                    // sells now last.
                    uasort(
                        array: $units,
                        callback: static fn (array $one, array $other): int => [$one['item_id'] . ':' . $one['combination_id'] === $sells, $one['item_id'], $one['combination_id']]
                            <=> [$other['item_id'] . ':' . $other['combination_id'] === $sells, $other['item_id'], $other['combination_id']],
                    );

                    foreach ($units as $unit) {
                        $give = min($excess, max(0.0, $unit['deducted_net']));

                        if ($give <= InventoryAvailabilityService::QUANTITY_TOLERANCE) {
                            continue;
                        }

                        $restoreLines[] = [
                            'item_id' => $unit['item_id'],
                            'combination_id' => $unit['combination_id'] ?: null,
                            'line_id' => $line['line_id'],
                            'quantity' => $give,
                        ];
                        $excess -= $give;
                    }
                }

                if ($restoreLines !== []) {
                    $restored = $service->restore(
                        sourceType: 'order',
                        sourceId:   $orderId,
                        lines:      $restoreLines,
                        context:    $context ?? self::context(),
                    );

                    $converged = [
                        'restored_quantity' => (float) $restored['restored_quantity'],
                        'lines' => \count(value: array_unique(array: array_column(array: $restoreLines, column_key: 'line_id'))),
                    ];
                }
            }

            TransactionState::commitLevel(db: $db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        return $converged;
    }

    /**
     * Return the order to "in stock" semantics: restore everything the
     * ledger says is out, release every remaining reservation. Both
     * halves are idempotent. The order's row is taken first and what each
     * line has out is read as the latest committed state.
     *
     * @param int $orderId The order.
     * @param InventoryContext|null $context Audit context.
     *
     * @return array{restored_quantity: float, released_quantity: float}
     *
     * @since  1.4.0
     */
    public static function restoreOrder(int $orderId, ?InventoryContext $context = null): array
    {
        $service = new InventoryService();
        $context ??= self::context();
        $restoredQty = 0.0;

        // The order's row first, the anchor every stock command of its lines takes
        // (a caller that holds it already takes nothing new), then what each line has
        // out as the latest committed state: chosen from a snapshot, a line whose
        // deduction committed after it was never given back.
        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__alfa_orders')
            . ' WHERE ' . $db->quoteName('id') . ' = ' . $orderId . ' FOR UPDATE',
        )->loadResult();

        $restoreLines = [];
        // The order's OWN line ids, so a deduction another domain wrote
        // against them (a delivery note's stock effect on an unreserved
        // line) is visible here. Voiding that note deliberately does not
        // un-ship the parcel; THIS is the path that brings the units back.
        $ownLines = array_column(self::orderLines($orderId), 'line_id');

        foreach ($service->sourceLineState(
            sourceType: 'order',
            sourceId:   $orderId,
            intent:     ReconciliationIntent::LifecycleRestore,
            lineIds:    $ownLines,
            current:    true,
        ) as $lineId => $state) {
            if ($state['deducted_net'] > 0) {
                $restoreLines[] = [
                    'item_id' => $state['item_id'],
                    'combination_id' => $state['combination_id'],
                    'line_id' => $lineId,
                    // quantity omitted = everything still out.
                ];
            }
        }

        if ($restoreLines !== []) {
            $restored = $service->restore(sourceType: 'order', sourceId: $orderId, lines: $restoreLines, context: $context);
            $restoredQty = (float) $restored['restored_quantity'];
        }

        $released = $service->release(sourceType: 'order', sourceId: $orderId, context: $context);

        return [
            'restored_quantity' => $restoredQty,
            'released_quantity' => (float) $released['released_quantity'],
        ];
    }

    /**
     * Reconcile inventory with the order's CURRENT lines: the single
     * entry for every line edit (quantity change, added line, deleted
     * line, full-set save). Declarative and idempotent: it drives the
     * kernel state to match the lines, whatever path the edit took.
     *
     * Deducting status: per-line ledger converges to the line quantity
     * (commit tops up, restore returns excess; vanished lines return
     * fully), and a hold a line still has beyond what it owes the order on
     * the variant it sells now lets go ({@see self::releaseBeyondShares()}).
     * Non-deducting status: per-line active reservations converge to the line
     * quantity (additive reserve / partial release; vanished lines release
     * fully, anything out returns). A status that claims nothing (cancelled):
     * the order is restored ({@see self::restoreOrder()}). The status is read
     * under the order's row lock, as the latest committed state, before any of
     * this is decided, so a reconciliation that waited for a status change
     * converges on the status that change saved.
     *
     * Every line converges on its ACTING stock identity, as the inventory
     * service reports it: the identity it was sold as, or the one an operator
     * resolution recorded for it. A line whose identity is kept only for order
     * history (an archived combination, the product itself once it has
     * combinations) is fulfilled and restored there but never reserved: a
     * reservation is a claim on sellable stock, which that identity no longer
     * is, so its target is 0 and an existing hold on it lets go. The same holds
     * for a line that was not a stock claim when it was placed, and for one
     * whose unit no longer tracks stock: only a line the service reports as
     * reservable is reserved. A line whose identity is gone is handed to the
     * service unchanged, which refuses it.
     *
     * @param int $orderId The order.
     * @param InventoryContext|null $context Audit context.
     *
     *
     * @since  1.4.0
     */
    public static function syncOrderLines(int $orderId, ?InventoryContext $context = null): void
    {
        $service = new InventoryService();
        $context ??= self::context();

        // ONE ORDER, ONE RECONCILER AT A TIME.
        //
        // This whole method is a converge-to-target: it reads what the line
        // currently holds and writes the difference, with reserve(additive:
        // true): an unconditional ADD, not an idempotent set. Read outside a
        // lock, two concurrent order saves both saw remainder 0 and both added
        // the full line quantity, doubling the hold on a product that had only
        // been saved twice.
        //
        // The order row is the same anchor commitReservation() takes, so a
        // save and a deduct trigger cannot interleave either.
        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->transactionStart(true);

        try {
            // THE STATUS DECIDES THE WHOLE RECONCILIATION, SO IT IS READ UNDER
            // THE LOCK THAT OWNS IT. Read first and locked later (a plain read,
            // answered from the caller's snapshot), an edit that queued behind
            // the order's cancellation still saw the status it left and deducted
            // the whole line again on the cancelled order. The locking read is
            // the latest committed state, as a status change and the stock claim
            // reconciliation read it under the same row lock.
            $order = $db->setQuery(
                'SELECT ' . $db->quoteName('id') . ', ' . $db->quoteName('id_order_status') . ' FROM ' . $db->quoteName('#__alfa_orders')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $orderId . ' FOR UPDATE',
            )->loadObject();

            if ($order === null) {
                TransactionState::commitLevel(db: $db);

                return;
            }

            // A CANCELLED ORDER RECONCILES TO NOTHING. This reconciliation used
            // to read only "does the status deduct?", so on a cancelled order it
            // took the reservation branch and converged the hold back UP to the
            // line quantities, quietly re-creating the claim a cancellation had
            // just released. Whatever is out comes back and every hold goes.
            if (StockPolicy::claimFor((int) $order->id_order_status) === StockPolicy::CLAIM_NONE) {
                self::restoreOrder($orderId, $context);
                TransactionState::commitLevel(db: $db);

                return;
            }

            $deductMode = StockPolicy::shouldDeductStock((int) $order->id_order_status);

            $lines = self::orderLines($orderId);
            $currentIds = array_column($lines, 'line_id');

            // What each line acts on and whether that identity is still a unit
            // that can be reserved, read once after the order lock. A recorded
            // resolution of a line is written under this same lock, so none is
            // recorded while the lines converge. The resolutions are a current
            // read: one committed after this transaction's snapshot and before
            // the lock is seen here, as the service's own commands see it.
            // Whether the identity is still a current unit is read as usual;
            // the service checks it again under lock before it writes.
            $identities = $service->lineIdentities(lines: $lines, current: true);

            // Pass the order's own lines: a deduction written by another domain
            // against one of them is the order's to reconcile, reserved or not.
            // current: true; the lock keeps other reconcilers out, but only a
            // current read makes their committed work visible to this one.
            // ORDINARY EDIT INTENT: this reconciliation compensates the
            // order's OWN claim and nothing else. A document that moved goods
            // did so as its own business act, and the order-edit rule is explicit that an
            // edit "must not absorb a stock movement written by an issued
            // document and put those units back merely because quantity was
            // retyped". The write side already agrees (the 'target' branch of
            // commitReservation() converges on the order's own net, not the
            // line's), so this makes the read side say the same thing.
            $state = $service->sourceLineState(
                sourceType: 'order',
                sourceId:   $orderId,
                intent:     ReconciliationIntent::OrderEdit,
                lineIds:    $currentIds,
                current:    true,
            );

            // A line's claim belongs to the unit the line sells
            //
            // A line can change unit: the admin re-configures a placed line
            // to a different combination, which is a supported action. The
            // ledger keys on (item, combination), so that leaves the old unit
            // holding this line's deduction while the unit actually sold holds
            // nothing.
            //
            // The convergence below CANNOT see it, and would make it worse:
            // sourceLineState() sums deducted_net across every unit, so the
            // line reads as fully claimed, takes the top-up branch, and
            // lineNet(), which IS unit-scoped, reports 0 for the new unit.
            // Result: the full quantity deducted a second time, on the new
            // unit, with the old one still out. Measured: unit A -3, unit B -3
            // for a 3-unit line.
            //
            // So the stale unit is made whole FIRST, and the line then
            // converges normally on the unit it actually sells. Doing it here
            // rather than at the one write path means every path that can move
            // a line's unit is healed by the next reconcile, not just the one
            // that was noticed.
            // Per-unit foreign nets, for the reservation target below. Read
            // here so the whole convergence works from one consistent snapshot
            // taken under the same lock.
            $foreignUnits = $service->foreignUnitNets(
                sourceType: 'order',
                sourceId:   $orderId,
                lineIds:    $currentIds,
                current:    true,
            );

            $unitNets = $service->lineUnitNets(
                sourceType: 'order',
                sourceId: $orderId,
                lineIds: $currentIds,
                current: true,
            );

            foreach ($lines as $line) {
                // The unit the line acts on, not the one it names: a line bound
                // by a recorded resolution moves stock on the bound unit, and
                // that stock is its own, never stale.
                $acting = $identities[$line['line_id']];
                $sells = $acting['item_id'] . ':' . $acting['combination_id'];

                foreach ($unitNets[$line['line_id']] ?? [] as $key => $held) {
                    if ($key === $sells) {
                        continue;
                    }

                    // What is OUT on the stale unit comes back.
                    if ($held['deducted_net'] > 0.000001) {
                        $service->restore(
                            sourceType: 'order',
                            sourceId: $orderId,
                            lines: [[
                                'item_id' => $held['item_id'],
                                'combination_id' => $held['combination_id'] ?: null,
                                'line_id' => $line['line_id'],
                                'quantity' => $held['deducted_net'],
                            ]],
                            context: $context,
                        );

                        // The per-line state was read before this restore, and
                        // it summed the unit just given back. Leaving it would
                        // make the convergence below believe the line is still
                        // claimed, and, when the line now orders fewer, decide
                        // it is OVER-claimed and try to restore the difference
                        // from a unit that holds nothing.
                        if (isset($state[$line['line_id']]['deducted_net'])) {
                            $state[$line['line_id']]['deducted_net'] =
                                max(0.0, $state[$line['line_id']]['deducted_net'] - $held['deducted_net']);
                        }
                    }

                    // And what is still HELD on it lets go. Targeted at the
                    // unit: a line mid-move holds on both, and an untargeted
                    // release would give back whichever row was locked first.
                    if ($held['reserved_remainder'] > 0.000001) {
                        $service->releaseLine(
                            sourceType: 'order',
                            sourceId: $orderId,
                            lineId: $line['line_id'],
                            quantity: $held['reserved_remainder'],
                            context: $context,
                            onlyItemId: $held['item_id'],
                            onlyCombinationId: $held['combination_id'],
                        );

                        if (isset($state[$line['line_id']]['reserved_remainder'])) {
                            $state[$line['line_id']]['reserved_remainder'] =
                                max(0.0, $state[$line['line_id']]['reserved_remainder'] - $held['reserved_remainder']);
                        }
                    }
                }
            }

            // In a deducting status, the order's own share of each line, for the
            // holds released once every line has converged.
            $shares = [];

            foreach ($lines as $line) {
                $lineState = $state[$line['line_id']]
                    ?? ['reserved_remainder' => 0.0, 'deducted_net' => 0.0, 'foreign_deducted_net' => 0.0];

                // WHAT THIS ORDER STILL HAS TO COVER ITSELF.
                //
                // Units another source already has out are physically gone, so the
                // order's own claim is the remainder, not the whole line. Reading
                // it here is the difference between "do not compensate a
                // document's movement" (the intent above) and "do not deduct the
                // same unit twice", which are separate rules and both hold.
                //
                // Measured while building item 2: with the edit intent in place
                // but this target left at the full line quantity, lowering a
                // delivery-noted line from 2 to 1 made the order DEDUCT a second
                // unit: the same defect as before with its sign reversed.
                // CLAMPED AT ZERO, and the clamp is load-bearing. The net is
                // COALESCE(-SUM(qty_change)) over DEDUCT and RESTORE, so a
                // document that put units BACK (a credit note carries
                // stock_effect 'restore') makes it NEGATIVE, and an unclamped
                // subtraction would raise the order's target ABOVE its own line
                // and deduct the restored unit straight back off the shelf.
                // Measured: line 2, order out 2, a credit note restores 1 ->
                // target 3, own net 2, effective 1. That is a document-origin
                // movement reversed by an ordinary edit, which is the one thing
                // this item exists to prevent.
                //
                // Only units another source currently has OUT reduce what the
                // order still has to cover. A net restore by someone else creates
                // no obligation for the order to take more.
                //
                // Units the order itself put back are not out any more: on a variant
                // the line no longer sells, what the order put back of another
                // source's stock (a cancellation gives back what a delivery note
                // shipped) does not count as out, and what the order has out is
                // measured without it. This is the status commit's own share
                // (ownShare()), so the reconciliation and the commit converge on one
                // target.
                $acting = $identities[$line['line_id']];
                $sellsKey = $acting['item_id'] . ':' . $acting['combination_id'];
                $givenBack = self::givenBack(othersOn: $foreignUnits[$line['line_id']] ?? [], ownUnits: $unitNets[$line['line_id']] ?? [], sells: $sellsKey);
                $ownTarget = self::ownShare(quantity: $line['quantity'], othersOn: $foreignUnits[$line['line_id']] ?? [], givenBack: $givenBack);
                $ownOut = $lineState['deducted_net'] - $givenBack;

                if ($deductMode) {
                    if ($ownOut > $ownTarget) {
                        $service->restore(
                            sourceType: 'order',
                            sourceId: $orderId,
                            lines: [[
                                'item_id' => $line['item_id'],
                                'combination_id' => $line['combination_id'],
                                'line_id' => $line['line_id'],
                                'quantity' => $ownOut - $ownTarget,
                            ]],
                            context: $context,
                        );
                    } else {
                        // Absolute target; the ledger caps the top-up.
                        $service->commitReservation(
                            request: new CommitmentRequest(sourceType: 'order', sourceId: $orderId, lines: [[
                                'item_id' => $line['item_id'],
                                'combination_id' => $line['combination_id'],
                                'line_id' => $line['line_id'],
                                'quantity' => $ownTarget,
                                'ordered' => $ownTarget,
                                'mode' => 'target',
                            ]]),
                            context: $context,
                        );
                    }

                    $shares[$line['line_id']] = $ownTarget;

                    continue;
                }

                // Reservation mode: the ORDER'S OWN deduction comes back first,
                // then the active remainder converges to the line quantity.
                //
                // The quantity is STATED. Omitting it makes restore() give back
                // the line's whole net across every source (InventoryService::
                // lineLedger() carries no source filter), so once the guard above
                // became own-only this branch would have handed a delivery note's
                // units back under the order's identity: the reversal the order-edit rule
                // forbids, reached by an ordinary save in a reserving status.
                if ($lineState['deducted_net'] > 0) {
                    $service->restore(
                        sourceType: 'order',
                        sourceId: $orderId,
                        lines: [[
                            'item_id' => $line['item_id'],
                            'combination_id' => $line['combination_id'],
                            'line_id' => $line['line_id'],
                            'quantity' => $lineState['deducted_net'],
                        ]],
                        context: $context,
                    );
                }

                // THE RESERVATION OPERAND IS IDENTITY-SCOPED, and only this one is.
                //
                // sourceLineState()'s foreign_deducted_net sums every unit a line
                // has touched, which is right for "what else is out on this line"
                // and wrong for sizing a claim: after a variant change a document's
                // deduction on the OLD unit would satisfy the claim for the new
                // one. foreignUnitNets() answers per unit, so this is the foreign
                // net for exactly the identity the line sells today.
                //
                // The DEDUCT branch above deliberately keeps the per-line figure:
                // that is the shipped behaviour, and changing it would move
                // physical stock in a case no rule covers yet. Only what the
                // order itself put back of another source's stock stops counting
                // there.
                //
                // Here too: what the order put back on this unit of another source's
                // stock (a cancellation gives back what a delivery note shipped) is on
                // the shelf again, so the reservation covers it again, as the status
                // commit deducts it again (stillOutOn()).
                $foreignOnUnit = self::stillOutOn(othersOn: $foreignUnits[$line['line_id']] ?? [], ownUnits: $unitNets[$line['line_id']] ?? [], unit: $sellsKey);

                // ONLY A RESERVABLE LINE IS RESERVED. The service calls a line
                // reservable when its identity is a current unit that tracks stock
                // now and the line was a stock claim when it was placed. Any other
                // line is fulfilled and restored as it was placed, but a hold on it
                // would be a claim the sale never made, or one on stock that is no
                // longer for sale (an identity kept only for order history). Its
                // target is 0: nothing is reserved, and a hold it still carries lets
                // go through the negative delta below. A line whose identity is gone
                // is handed to the service unchanged, which refuses it.
                $reserveTarget = ($acting['reservable'] || $acting['role'] === InventoryService::ROLE_UNRESOLVED)
                    ? max(0.0, $line['quantity'] - $foreignOnUnit)
                    : 0.0;

                // A RESERVATION COVERS ONLY WHAT IS NOT ALREADY PHYSICALLY OUT.
                //
                // Measured before this patch: a 2-unit line whose delivery note had
                // already deducted 2 moved to a reserving status and gained a
                // 2-unit hold on top: 4 units of shelf committed to a 2-unit line,
                // the availability axis wrong by exactly what the document moved.
                // The deduct branch above learned this in item 2; this branch is
                // the same rule on the other axis, against the same target.
                //
                // The delta stays SIGNED: negative is how an existing hold that now
                // exceeds the target is released, and clamping it would strand it.
                $delta = $reserveTarget - $lineState['reserved_remainder'];

                if ($delta > 0.000001) {
                    $service->reserve(
                        lines: [[
                            'item_id' => $line['item_id'],
                            'combination_id' => $line['combination_id'],
                            'line_id' => $line['line_id'],
                            'quantity' => $delta,
                        ]],
                        sourceType: 'order',
                        sourceId: $orderId,
                        context: $context,
                        additive: true,
                    );
                } elseif ($delta < -0.000001) {
                    $service->releaseLine(
                        sourceType: 'order',
                        sourceId: $orderId,
                        lineId: $line['line_id'],
                        quantity: -$delta,
                        context: $context,
                    );
                }
            }

            // A deducting status converts a line's holds as far as its share reaches;
            // what a line still holds beyond what it owes lets go, as after a status
            // commit.
            if ($deductMode) {
                self::releaseBeyondShares(service: $service, orderId: $orderId, lines: $lines, shares: $shares, context: $context, identities: $identities);
            }

            // Vanished lines: everything returns, every hold lets go.
            foreach ($state as $lineId => $lineState) {
                if (\in_array($lineId, $currentIds, true)) {
                    continue;
                }

                // Bounded to the order's own net, for the reason the reservation
                // branch above states: a vanished line gives back what the ORDER
                // had out, never what a document still has out on it.
                if ($lineState['deducted_net'] > 0) {
                    $service->restore(
                        sourceType: 'order',
                        sourceId: $orderId,
                        lines: [[
                            'item_id' => $lineState['item_id'],
                            'combination_id' => $lineState['combination_id'],
                            'line_id' => $lineId,
                            'quantity' => $lineState['deducted_net'],
                        ]],
                        context: $context,
                    );
                }

                if ($lineState['reserved_remainder'] > 0) {
                    $service->releaseLine(sourceType: 'order', sourceId: $orderId, lineId: $lineId, context: $context);
                }
            }

            TransactionState::commitLevel(db: $db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }
    }

    /**
     * Release what each line still holds beyond what it owes the order, once the
     * order's stock claim has applied in a status that deducts.
     *
     * In such a status a line's reservations stand only for what the order still
     * has to deduct itself on the variant the line sells now (the stock identity it
     * acts on: the one it was sold as, or the one a recorded resolution binds it
     * to), the unit the status commit deducts it on: its share of the line (the
     * figure the status commit takes, {@see self::ownShare()}) less what the order
     * already has out for the line on that unit. Only a reservation on that unit, up
     * to that figure, is a claim the commit converts. What the order itself has out
     * on another unit does not change it; what it put back there of another source's
     * stock is in the share: stock a cancellation gave back on the variant the line
     * sold before is a deduction the line owes again on the one it sells now.
     *
     * Every reservation of the line on any other unit is released whole, first;
     * what it holds on its own unit beyond that figure is released from the back of
     * the order of claims ({@see InventoryService::releaseLine()}). A line that owes
     * nothing more on its unit, or holds nothing there, lets go of every
     * reservation in one release.
     *
     * Called after the claim applied, inside the caller's transaction and under
     * the order's row lock: a refused claim has thrown before and keeps its
     * reservations as the claim it still owes. What each line has out and holds is
     * read as the latest committed state. The unit a line sells now is read only
     * for a line that still holds something, unless the caller already read it
     * under the same lock: its recorded resolution as the latest committed row,
     * which is written only under the order's row lock.
     *
     * The release is an inventory command of its own after the fulfilment: the
     * stock records of a unit only the release acts on are taken after the rows the
     * fulfilment already holds. A command that holds those records while it waits
     * for one of those rows, or for the order's reservation rows, waits for this
     * one in turn; the database then ends one of the two, which fails whole and
     * leaves nothing.
     *
     * @param InventoryService $service The inventory service.
     * @param int $orderId The order.
     * @param array $lines The order's lines whose share is given (line_id,
     *                     item_id, combination_id).
     * @param array<int, float> $shares The order's own share of each line, by line id.
     * @param InventoryContext $context Audit context.
     * @param array<int, array>|null $identities The unit each line sells now (item_id,
     *                                           combination_id), by line id, as the caller read it
     *                                           under the order's row lock; null to read it here.
     *
     * @return float The quantity released.
     *
     * @since  1.14.0
     */
    private static function releaseBeyondShares(
        InventoryService $service,
        int $orderId,
        array $lines,
        array $shares,
        InventoryContext $context,
        ?array $identities = null,
    ): float {
        if ($shares === []) {
            return 0.0;
        }

        $held = $service->lineUnitNets(sourceType: 'order', sourceId: $orderId, lineIds: array_keys(array: $shares), current: true);

        // Only a line that still holds something is judged.
        $holding = array_values(array: array_filter(
            array: $lines,
            callback: static fn (array $line): bool => isset($shares[$line['line_id']])
                && array_sum(array: array_column(array: $held[$line['line_id']] ?? [], column_key: 'reserved_remainder'))
                    > InventoryAvailabilityService::QUANTITY_TOLERANCE,
        ));

        if ($holding === []) {
            return 0.0;
        }

        $identities ??= $service->lineIdentities(lines: $holding, current: true);
        $released = 0.0;

        foreach ($holding as $line) {
            $lineId = (int) $line['line_id'];
            $units = $held[$lineId];
            $acting = $identities[$lineId];
            $sells = $acting['item_id'] . ':' . $acting['combination_id'];
            $onUnit = $units[$sells] ?? ['deducted_net' => 0.0, 'reserved_remainder' => 0.0];

            // What the order still has to deduct on the unit the line sells now, and
            // as much of it as the line holds there: the only claim the commit converts.
            $owes = max(0.0, $shares[$lineId] - $onUnit['deducted_net']);
            $keep = min($owes, $onUnit['reserved_remainder']);

            if ($keep <= InventoryAvailabilityService::QUANTITY_TOLERANCE) {
                $released += (float) $service->releaseLine(
                    sourceType: 'order',
                    sourceId:   $orderId,
                    lineId:     $lineId,
                    context:    $context,
                )['released_quantity'];

                continue;
            }

            // A hold on any other unit is a claim nothing converts: released whole, first.
            foreach ($units as $key => $unit) {
                if ($key === $sells || $unit['reserved_remainder'] <= InventoryAvailabilityService::QUANTITY_TOLERANCE) {
                    continue;
                }

                $released += (float) $service->releaseLine(
                    sourceType:        'order',
                    sourceId:          $orderId,
                    lineId:            $lineId,
                    context:           $context,
                    onlyItemId:        $unit['item_id'],
                    onlyCombinationId: $unit['combination_id'],
                )['released_quantity'];
            }

            $beyond = $onUnit['reserved_remainder'] - $keep;

            if ($beyond > InventoryAvailabilityService::QUANTITY_TOLERANCE) {
                $released += (float) $service->releaseLine(
                    sourceType:        'order',
                    sourceId:          $orderId,
                    lineId:            $lineId,
                    quantity:          $beyond,
                    context:           $context,
                    onlyItemId:        $acting['item_id'],
                    onlyCombinationId: $acting['combination_id'],
                )['released_quantity'];
            }
        }

        return $released;
    }

    /**
     * The order's own share of one line: what the order itself still has to cover of
     * it, the target the status commit deducts the line up to on the stock identity
     * the line sells now ({@see self::commitOrder()}), and the one the line
     * reconciliation and the convergence after a document's stock effect take.
     *
     * The line quantity less what other sources (a delivery note, a return) have out
     * for the line, on every unit: stock a document already shipped has left the
     * shelf once and is not the order's to take again. Stock of theirs the order
     * itself put back is not out any more and counts as given back
     * ({@see self::givenBack()}). What other sources have out, so reduced, is clamped
     * at zero, since a credit note's restore makes it negative and must not raise the
     * share above the line, and so is the share.
     *
     * @param float $quantity The line quantity.
     * @param array<string, float> $othersOn What other sources have out for the line, by unit
     *                                       ("item:combination").
     * @param float $givenBack What the order put back of that stock ({@see self::givenBack()}),
     *                         zero or negative.
     *
     * @return float The share, zero or more.
     *
     * @since  1.14.0
     */
    private static function ownShare(float $quantity, array $othersOn, float $givenBack): float
    {
        return max(0.0, $quantity - max(0.0, array_sum(array: $othersOn) + $givenBack));
    }

    /**
     * What the order itself put back for one line of the stock other sources have out
     * for it: on each unit another source has out for the line, the order's own net
     * there where it is negative, summed. A cancellation gives back everything out for
     * the order's lines, a delivery note's units included, so those units are on the
     * shelf again and are not the line's any more.
     *
     * Only the order's own net counts: what a physical return brought back is already
     * in what other sources have out, and it is never given back by the order. The
     * unit the line sells now is left out: the status commit measures what the order
     * already has out there itself, what it put back included
     * ({@see InventoryService::commitReservation()}), so counting it here would count
     * it twice. Null leaves no unit out, for a caller that only asks whether the order
     * put anything back.
     *
     * @param array<string, float> $othersOn What other sources have out for the line, by unit ("item:combination").
     * @param array<string, array> $ownUnits What the order has out and holds for the line, by unit, as
     *                                       {@see InventoryService::lineUnitNets()} reports it.
     * @param string|null $sells The unit the line sells now ("item:combination"), or null.
     *
     * @return float Zero or negative.
     *
     * @since  1.14.0
     */
    private static function givenBack(array $othersOn, array $ownUnits, ?string $sells): float
    {
        $givenBack = 0.0;

        foreach (array_keys(array: $othersOn) as $unit) {
            if ((string) $unit !== $sells) {
                $givenBack += min(0.0, (float) ($ownUnits[$unit]['deducted_net'] ?? 0.0));
            }
        }

        return $givenBack;
    }

    /**
     * What other sources still have out for one line on one unit: what they have out
     * there, less what the order itself put back of it there (its own net there,
     * where negative, {@see self::givenBack()}), clamped at zero. What a reservation
     * of the line on that unit does not have to cover: a reinstated line whose
     * cancellation gave a delivery note's units back holds its whole quantity again,
     * as the status commit deducts it again. A physical return is already in what
     * other sources have out.
     *
     * @param array<string, float> $othersOn What other sources have out for the line, by unit ("item:combination").
     * @param array<string, array> $ownUnits What the order has out and holds for the line, by unit, as
     *                                       {@see InventoryService::lineUnitNets()} reports it.
     * @param string $unit The unit ("item:combination").
     *
     * @return float Zero or more.
     *
     * @since  1.14.0
     */
    private static function stillOutOn(array $othersOn, array $ownUnits, string $unit): float
    {
        return max(0.0, (float) ($othersOn[$unit] ?? 0.0) + self::givenBack(
            othersOn: array_intersect_key($othersOn, [$unit => true]),
            ownUnits: $ownUnits,
            sells:    null,
        ));
    }

    /**
     * Record how the order's new lines are placed: for each, whether the stock
     * identity it is sold as tracks stock at this moment. The inventory service
     * keeps that with the line, and it decides later whether the line's
     * fulfilment deducts stock, whatever the identity's tracking says by then. A
     * line re-pointed to another identity is placed again. A record is removed
     * with its order line by the database, whoever deletes the line. Called
     * inside the placement's own transaction and before any claim, so a failure
     * fails the placement.
     *
     * Only lines of the named order are recorded: a line of another order is
     * refused, and nothing is written.
     *
     * @param int $orderId The order being placed or edited.
     * @param int[] $lineIds Its lines being placed.
     * @param InventoryContext|null $context Audit context (the acting user).
     *
     * @return int The number of lines recorded.
     *
     * @throws InvalidArgumentException When a line is not a line of the order.
     *
     * @since  1.14.0
     */
    public static function recordPlacement(int $orderId, array $lineIds, ?InventoryContext $context = null): int
    {
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));

        if ($lineIds === []) {
            return 0;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $own = array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_order_items'))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->whereIn($db->quoteName('id'), $lineIds),
        )->loadColumn() ?: []);
        $foreign = array_values(array_diff($lineIds, $own));

        if ($foreign !== []) {
            throw new InvalidArgumentException(
                'InventoryBridge: order #' . $orderId . ' has no line ' . implode(', ', $foreign) . '; no placement was recorded.',
            );
        }

        return (new InventoryService())->recordPlacement(lineIds: $lineIds, context: $context ?? self::context());
    }

    /**
     * Why the order-line editor may not save a new claim of a line on a stock
     * identity, or null when it may: asked before it creates a line, raises a
     * line's quantity or moves a line onto another identity, and asked again
     * inside that save's transaction just before it commits, so a change the
     * save's own transaction made to the identity is judged too.
     *
     * The line is judged on the identity it acts on, as the inventory service
     * reports it for that line naming that identity: the identity itself, or the
     * one an operator resolution recorded for the line. Only a current unit takes a
     * new claim. An identity kept only for order history (an archived combination,
     * the product itself once it has current combinations, any identity of an
     * archived product) is refused by name, and an identity that no longer exists
     * is refused as gone. The name is the identity the line acts on, which a
     * resolution may have moved away from the one the line names. A line not yet
     * created has no resolution. The line's recorded resolution is read as the
     * latest committed row under a shared lock. The identity's catalogue rows are
     * read as the service reads them, from the caller's transaction, before the
     * save's transaction opens; in the check made just before that transaction
     * commits they are read as the latest committed rows under shared locks held
     * until it ends, so a product or combination archived or removed by another
     * transaction in the meantime is judged as it stands.
     *
     * A line that keeps its placement (a larger quantity on the identity it was
     * placed on) keeps the stock tracking it was placed with, and so would the
     * added quantity: it is refused when that tracking is not the tracking of the
     * identity it acts on now ({@see self::placementRefusal()}).
     *
     * The text is raw: the caller escapes it where it renders HTML.
     *
     * @param int $lineId The order line, 0 for a line not yet created.
     * @param int $itemId The product the line names.
     * @param int|null $combinationId The combination the line names; null or 0 for the product itself.
     * @param bool $keepsPlacement Whether the line stays on the identity it was placed on (no new
     *                             placement is recorded for it): a larger quantity of an existing line.
     * @param bool $beforeCommit True only for the check made as the last statement of the save's
     *                           transaction, where the identity's catalogue rows are read as the latest
     *                           committed rows under shared locks held until it commits; false before
     *                           the transaction, where a lock would be upgraded by the stock commands
     *                           that follow.
     *
     * @return string|null The operator's refusal, or null when the claim may be saved.
     *
     * @since  1.14.0
     */
    public static function claimRefusal(int $lineId, int $itemId, ?int $combinationId, bool $keepsPlacement, bool $beforeCommit): ?string
    {
        $combinationId = (int) ($combinationId ?? 0);
        $service = new InventoryService();

        $role = $service->lineRole(
            lineId: $lineId,
            itemId: $itemId,
            combinationId: $combinationId,
            current: true,
            lockIdentity: $beforeCommit,
        );

        if ($role === InventoryService::ROLE_CURRENT) {
            return $keepsPlacement && $lineId > 0
                ? self::placementRefusal(service: $service, lineId: $lineId, itemId: $itemId, combinationId: $combinationId)
                : null;
        }

        if ($role !== InventoryService::ROLE_HISTORICAL) {
            return Text::_('COM_ALFA_ORDER_ITEM_REFUSED_IDENTITY_GONE');
        }

        // Named as the identity the line acts on: a resolution recorded for an
        // existing line can point it away from the one it names.
        $acting = $lineId > 0
            ? ($service->lineIdentities(
                lines: [['line_id' => $lineId, 'item_id' => $itemId, 'combination_id' => $combinationId]],
                current: true,
            )[$lineId] ?? null)
            : null;

        return Text::sprintf(
            'COM_ALFA_ORDER_ITEM_REFUSED_NOT_CURRENT',
            InventoryUnitException::labelFor(
                itemId: (int) ($acting['item_id'] ?? $itemId),
                combinationId: (int) ($acting['combination_id'] ?? $combinationId),
            ),
        );
    }

    /**
     * Why a larger quantity of an existing line on the identity it was placed on
     * may not be saved, or null when it may.
     *
     * Whether a line is a stock claim is decided once, by how it was placed, and a
     * larger quantity is written with no new placement, so the added quantity would
     * be claimed, or not, as the line was. It is refused when that is not how the
     * identity the line acts on tracks stock now: added to a line placed untracked,
     * a tracked unit's stock would leave with no reservation or deduction; added to
     * a line placed as a claim, an untracked unit would be reserved and deducted.
     * The extra quantity saved as a new line is placed under the current tracking.
     *
     * @param InventoryService $service The inventory service.
     * @param int $lineId The existing order line.
     * @param int $itemId The product the line names.
     * @param int $combinationId The combination the line names, 0 for the product itself.
     *
     * @return string|null The operator's refusal, naming the identity the line acts on, or null.
     *
     * @since  1.14.0
     */
    private static function placementRefusal(InventoryService $service, int $lineId, int $itemId, int $combinationId): ?string
    {
        $line = $service->lineIdentities(
            lines: [['line_id' => $lineId, 'item_id' => $itemId, 'combination_id' => $combinationId]],
            current: true,
        )[$lineId];

        // The service's own verdict of the current unit's tracking: an empty dry run
        // reports not_tracked exactly when the unit does not track stock.
        $problems = $service->validateTargets(
            itemId: $line['item_id'],
            combinationId: $line['combination_id'],
            targets: [],
            assumeTracked: false,
        );
        $tracksNow = ($problems[0]['code'] ?? '') !== 'not_tracked';

        if ($tracksNow === $line['placed_tracked']) {
            return null;
        }

        return Text::sprintf(
            'COM_ALFA_ORDER_ITEM_REFUSED_PLACED_TRACKING',
            InventoryUnitException::labelFor(itemId: $line['item_id'], combinationId: $line['combination_id']),
        );
    }

    /**
     * Why an order may not enter, or come back into, a state in which its stock
     * waits on the shelf, or null when it may.
     *
     * In a status that holds a reservation every line placed as a stock claim waits
     * for its stock under a reservation. A line whose stock identity is a current
     * unit that does not track stock now cannot be given one (untracked stock holds
     * no reservations), yet its fulfilment would still deduct stock, as it was
     * placed: the line would follow two inventory policies at once. The order is
     * therefore refused that state while it has such a line with quantity a
     * reservation would have to cover: the line quantity less what other sources
     * (a delivery note) still have out for it on that identity, what the order
     * itself put back of it there not counted ({@see self::stillOutOn()}). A line
     * shipped in full needs no reservation and is not counted.
     *
     * Only a claim that holds a reservation is judged. A status that deducts is
     * never refused here (the line deducts as it was placed), nor is one that
     * claims nothing (a cancellation or a return holds nothing). The operator
     * switches stock tracking back on for the unit with the quantity on hand, or
     * gives the order a status that deducts its stock or cancels it.
     *
     * With $lock the answer holds until the caller's transaction ends: the stock
     * records of every current unit the order's lines were placed on as stock
     * claims are locked, and each unit's tracking is read under those locks. A
     * tracking switch takes the same locks first, so it has either committed and
     * is read here, or it waits for the caller and then meets the order's lines.
     * The caller runs inside a transaction and holds the order's row lock, taken
     * before this is asked: the order row always comes before stock records.
     * Without $lock nothing is locked: the check a screen makes before a save.
     *
     * The text is raw: the caller escapes it where it renders HTML.
     *
     * @param int $orderId The order.
     * @param string $claim The claim the state the order would enter holds on stock
     *                      (one of the StockPolicy::CLAIM_* constants).
     * @param bool $lock Judge under the stock-record locks of the order's units
     *                   (inside a transaction, after the order's row lock), or
     *                   read plainly.
     *
     * @return string|null One sentence per such line, naming the line, its unit and
     *                     the quantity a reservation would have to cover, or null
     *                     when the order may enter the state.
     *
     * @since  1.14.0
     */
    public static function unheldClaimRefusal(int $orderId, string $claim, bool $lock): ?string
    {
        if ($claim !== StockPolicy::CLAIM_RESERVATION) {
            return null;
        }

        $lines = self::orderLines(orderId: $orderId);

        if ($lines === []) {
            return null;
        }

        $service = new InventoryService();
        $identities = $service->lineIdentities(lines: $lines, current: $lock);

        // The lines a tracking switch can leave without a reservation: placed as
        // stock claims, on an identity that is a current unit.
        $placed = array_values(array: array_filter(
            array: $lines,
            callback: static fn (array $line): bool => ($identities[$line['line_id']]['placed_tracked'] ?? false)
                && ($identities[$line['line_id']]['role'] ?? '') === InventoryService::ROLE_CURRENT,
        ));

        if ($placed === []) {
            return null;
        }

        // Under lock, each unit's tracking as it stands now; otherwise what the
        // plain read of the line's identity says.
        $tracking = $lock
            ? $service->lockedUnitTracking(units: array_map(
                callback: static fn (array $line): array => [
                    'item' => $identities[$line['line_id']]['item_id'],
                    'combination' => $identities[$line['line_id']]['combination_id'],
                ],
                array: $placed,
            ))
            : null;

        $unheld = array_values(array: array_filter(
            array: $placed,
            callback: static function (array $line) use ($identities, $tracking): bool {
                $identity = $identities[$line['line_id']];

                return $tracking !== null
                    ? ($tracking[$identity['item_id'] . ':' . $identity['combination_id']] ?? null) === false
                    : !$identity['reservable'];
            },
        ));

        if ($unheld === []) {
            return null;
        }

        // What other sources still have out for each line on the unit it acts on,
        // less what the order itself put back of it there: a reservation would
        // cover only the rest.
        $foreign = $service->foreignUnitNets(
            sourceType: 'order',
            sourceId:   $orderId,
            lineIds:    array_column(array: $unheld, column_key: 'line_id'),
            current:    $lock,
        );
        $own = $foreign === []
            ? []
            : $service->lineUnitNets(sourceType: 'order', sourceId: $orderId, lineIds: array_keys(array: $foreign), current: $lock);
        $sentences = [];

        foreach ($unheld as $line) {
            $identity = $identities[$line['line_id']];
            $unitKey = $identity['item_id'] . ':' . $identity['combination_id'];
            $toCover = $line['quantity'] - self::stillOutOn(othersOn: $foreign[$line['line_id']] ?? [], ownUnits: $own[$line['line_id']] ?? [], unit: $unitKey);

            if ($toCover <= InventoryAvailabilityService::QUANTITY_TOLERANCE) {
                continue;
            }

            if ($sentences === []) {
                self::loadAdministratorStrings();
            }

            $sentences[] = Text::sprintf(
                'COM_ALFA_ORDER_CLAIM_UNHELD',
                $line['line_id'],
                InventoryUnitException::labelFor(itemId: $identity['item_id'], combinationId: $identity['combination_id']),
                InventoryAvailabilityService::formatQuantity(value: $toCover),
            );
        }

        return $sentences === [] ? null : implode(separator: ' ', array: $sentences);
    }

    /**
     * Why a trashed order may not be restored from the trash, or null when it may.
     *
     * Restoring is no status change: the order comes back in the status it was
     * trashed in. When that status holds a reservation, the restored order is asked
     * what a move into that status is asked ({@see self::unheldClaimRefusal()}): a
     * line placed as a stock claim whose unit stopped tracking stock while the
     * order was in the trash would come back waiting for stock with no reservation.
     * An order that is not in the trash, and one whose status deducts or claims
     * nothing, is never refused.
     *
     * The text is raw: the caller escapes it where it renders HTML.
     *
     * @param int $orderId The order being restored.
     *
     * @return string|null The operator's refusal, or null when the order may be restored.
     *
     * @since  1.14.0
     */
    public static function untrashRefusal(int $orderId): ?string
    {
        $order = self::orderRow(orderId: $orderId);

        if ($order === null || (int) ($order->state ?? 1) !== -2) {
            return null;
        }

        return self::unheldClaimRefusal(
            orderId: $orderId,
            claim:   StockPolicy::claimFor(statusId: (int) $order->id_order_status),
            lock:    false,
        );
    }

    /**
     * Load the component's administrator strings into the application language when
     * they are not there yet: a status change runs from any request that moves an
     * order, a storefront payment callback included. A failure is logged; the
     * refusal then shows its key.
     *
     *
     * @since  1.14.0
     */
    private static function loadAdministratorStrings(): void
    {
        try {
            $language = Factory::getApplication()->getLanguage();

            if (!$language->hasKey(string: 'COM_ALFA_ORDER_CLAIM_UNHELD')) {
                $language->load(extension: 'com_alfa', basePath: JPATH_ADMINISTRATOR);
            }
        } catch (Throwable $e) {
            Log::add(
                entry:    'InventoryBridge: the administrator strings could not be loaded: ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.inventory',
            );
        }
    }

    /**
     * The order's lines as inventory request lines.
     *
     * @param int $orderId The order.
     *
     * @return array<int, array{item_id: int, combination_id: int|null, quantity: float, line_id: int}>
     *
     * @since  1.4.0
     */
    public static function orderLines(int $orderId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'id_item', 'id_combination', 'quantity']))
            ->from($db->quoteName('#__alfa_order_items'))
            ->where($db->quoteName('id_order') . ' = ' . (int) $orderId);

        $lines = [];

        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            if ((float) $row->quantity <= 0) {
                continue;
            }

            $lines[] = [
                'item_id' => (int) $row->id_item,
                'combination_id' => $row->id_combination !== null ? (int) $row->id_combination : null,
                'quantity' => (float) $row->quantity,
                'line_id' => (int) $row->id,
            ];
        }

        return $lines;
    }

    /**
     * @param int $orderId The order.
     *
     *
     * @since  1.4.0
     */
    private static function orderRow(int $orderId): ?object
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_orders'))
            ->where($db->quoteName('id') . ' = ' . (int) $orderId);

        return $db->setQuery($query)->loadObject() ?: null;
    }

    /**
     * Ambient audit context: acting user id.
     *
     *
     * @since  1.4.0
     */
    private static function context(): InventoryContext
    {
        return InventoryContext::ambient();
    }
}
