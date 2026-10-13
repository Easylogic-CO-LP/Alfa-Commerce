<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\MysqlError;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Document\StockBridge;
use Alfa\Component\Alfa\Administrator\Service\Order\InventoryBridge;
use Alfa\Component\Alfa\Administrator\Service\Order\StockPolicy;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use RuntimeException;
use Throwable;

/**
 * DURABLE STOCK-EFFECT OBLIGATIONS.
 *
 * THE CONTRACT. A stock effect that a committed business fact requires is
 * itself a durable fact. It is recorded in the SAME transaction as the fact
 * that requires it, and it is not discharged until the movement exists.
 * Nothing outside that transaction is ever the only record of the obligation.
 *
 * WHY THIS EXISTS. A document commits, and only then is its stock effect
 * applied. A crash in that window (the process killed, the worker OOMed, the
 * request aborted) would lose the movement: best-effort catch-and-retry inside
 * the dying process cannot recover it, because the process is what is gone.
 * The inventory consistency audit (console command and admin page) reports
 * movements missing from the ledger; this outbox keeps them from going missing.
 *
 * HOW THE PROPERTY IS OBTAINED.
 *
 *   enqueue()  runs INSIDE the producer's transaction. If the business fact
 *              rolls back, so does the obligation: no phantom effect.
 *   drain()    claims and applies. Claiming is a CONDITIONAL UPDATE whose
 *              affected-row count is the answer: exactly one worker can move a
 *              row out of pending, so two workers racing the same obligation
 *              cannot both apply it. No locks are held across the effect.
 *   applied    is terminal. A settled obligation is never claimable again.
 *   refused    waits for the operator. The inventory service refused the
 *              effect because an order line has no stock identity left to
 *              move against (its combination or its product is gone and no
 *              resolution is recorded); a retry cannot change that, so the row
 *              is never claimed again until its producer re-states it or the
 *              operator resolves the line ({@see self::rearmRefused()}). A line
 *              whose identity only stopped being a current unit while the
 *              command waited stays retryable ({@see self::classify()}).
 *
 * A fulfilment refused because other claims reserve the stock it needs stays
 * claimable, and the drain that meets it announces it in the operator's words.
 *
 * Until it is applied, an obligation (refused included) counts as an active
 * claim on the units its order's lines name: the inventory service refuses to
 * retire such a unit.
 *
 * A worker that dies mid-flight leaves its row 'claimed'. That is not a leak:
 * a claim older than the stale window is reclaimable, because the only thing a
 * claim asserts is "someone was working on this recently".
 *
 * IDEMPOTENCE HAS TWO LAYERS, deliberately. UNIQUE(source_type, source_id)
 * stops a replayed producer enqueueing twice; the claim stops two workers
 * applying once. Underneath both, the effect itself is still capped by the
 * ledger, so even a pathological double-apply cannot exceed what was deducted.
 *
 * @since  2.0.0
 */
final class InventoryEffectOutbox
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';

    /**
     * Refused by the inventory service in a way a retry cannot change: never
     * claimable, re-opened only when the producer re-states the obligation.
     *
     * @since  2.0.0
     */
    public const STATUS_REFUSED = 'refused';

    /** A claim older than this is assumed to belong to a process that died. */
    public const STALE_CLAIM_SECONDS = 300;

    /**
     * The notice raised for owed movements nothing has announced: a request that was
     * cut off cannot warn about itself.
     *
     * @since  2.0.0
     */
    public const NOTICE_STRANDED = 'inventory.effects.stranded';

    /**
     * Key prefixes of the per-order and per-document notices. The producers raise
     * them, and a drain raises one for every refusal it meets; this class, which
     * knows what is still owed, retires them.
     *
     * @since  2.0.0
     */
    public const ORDER_NOTICE_PREFIX = 'orders.transition.stockfailed.';
    public const DOCUMENT_NOTICE_PREFIX = 'documents.stockfailed.';

    /**
     * Minutes between checks from the notification bell, like the integrity check.
     *
     * @since  2.0.0
     */
    private const NOTICE_CHECK_MINUTES = 10;

    /**
     * Record an obligation. MUST be called inside the producer's transaction.
     *
     * $restate is a CONTRACT, not a convenience. It says the producer's effect
     * is ABSOLUTE: a function of the entity's current state, so applying it
     * again can only re-assert the same answer. Only such an effect may have a
     * settled obligation brought back to life when the state moves again. A
     * refused one is re-opened too: the state it was refused against has moved,
     * so the re-stated effect is a new question for the inventory service.
     *
     * A DELTA-shaped effect must never set it. A document's stock effect is
     * the standing example: the kernel's guarantee there is a ledger CAP, not
     * a no-op, so reviving a settled document obligation would restore a
     * second time. Documents therefore enqueue once, keyed by their own id,
     * and are never re-stated.
     *
     * @param string $sourceType The producing domain ('document', 'order_claim').
     * @param int $sourceId The committed row that owes the effect.
     * @param int $orderId The order the effect resolves against.
     * @param string $payload What the producer needs in order to apply it,
     *                        and, for a re-statable producer, the version
     *                        token a worker's settle is checked against.
     * @param bool $restate Whether a settled obligation may be revived.
     *
     *
     * @since  2.0.0
     */
    public static function enqueue(
        string $sourceType,
        int $sourceId,
        int $orderId,
        string $payload = '',
        bool $restate = false,
    ): void {
        if ($sourceType === '' || $sourceId <= 0) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $now = Factory::getDate()->toSql();

        // INSERT IGNORE against UNIQUE(source_type, source_id): a producer that
        // is replayed re-states the same obligation rather than adding a second.
        $db->setQuery(
            'INSERT IGNORE INTO ' . $db->quoteName('#__alfa_inventory_effect_outbox')
            . ' (' . $db->quoteName('source_type') . ', ' . $db->quoteName('source_id') . ', '
            . $db->quoteName('order_id') . ', ' . $db->quoteName('payload') . ', '
            . $db->quoteName('status') . ', ' . $db->quoteName('created') . ', ' . $db->quoteName('modified') . ')'
            . ' VALUES (' . $db->quote($sourceType) . ', ' . $sourceId . ', ' . $orderId . ', '
            . $db->quote($payload) . ', ' . $db->quote(self::STATUS_PENDING) . ', '
            . $db->quote($now) . ', ' . $db->quote($now) . ')',
        );
        $db->execute();

        if (!$restate) {
            return;
        }

        $where = ' WHERE ' . $db->quoteName('source_type') . ' = ' . $db->quote($sourceType)
            . ' AND ' . $db->quoteName('source_id') . ' = ' . $sourceId;

        // The version token moves first, and it moves whatever the status is.
        // A worker already in flight settles with the token it read, so a
        // re-statement under its feet makes that settle miss, which is the
        // point: its answer was computed before this fact existed, and the
        // obligation stays outstanding until someone answers again.
        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__alfa_inventory_effect_outbox')
            . ' SET ' . $db->quoteName('payload') . ' = ' . $db->quote($payload)
            . ', ' . $db->quoteName('order_id') . ' = ' . $orderId
            . ', ' . $db->quoteName('modified') . ' = ' . $db->quote($now)
            . $where,
        );
        $db->execute();

        // Then, and only for a settled or refused row, the obligation is opened
        // again. A row still pending/claimed/failed is left exactly as it is: it
        // is already outstanding, and resetting its claim would hand the same
        // work to a second worker.
        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__alfa_inventory_effect_outbox')
            . ' SET ' . $db->quoteName('status') . ' = ' . $db->quote(self::STATUS_PENDING)
            . ', ' . $db->quoteName('claim_token') . ' = NULL'
            . ', ' . $db->quoteName('claimed_at') . ' = NULL'
            . ', ' . $db->quoteName('attempts') . ' = 0'
            . $where . ' AND ' . $db->quoteName('status') . ' IN (' . $db->quote(self::STATUS_APPLIED)
            . ', ' . $db->quote(self::STATUS_REFUSED) . ')',
        );
        $db->execute();
    }

    /**
     * Queue again every refused obligation of an order. MUST be called inside
     * the transaction that records the resolution of one of its order lines.
     *
     * A refused obligation waits for the operator because an order line had no
     * stock identity to move against. Once a resolution of a line of that order
     * is recorded, the same effect is a new question for the inventory service,
     * so the order's refused obligations (its stock claim and its documents
     * alike) are opened for the next drain, committed with the resolution or
     * rolled back with it. Rows in any other status are left as they are.
     *
     * @param int $orderId The order whose line was resolved.
     *
     * @return list<array{source_type: string, source_id: int}> The obligations queued again.
     *
     * @since  2.0.0
     */
    public static function rearmRefused(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // Locked first, so exactly the rows read are the rows re-opened. The
        // status leads the condition: the status index bounds the lock to
        // refused rows, which no drain claims.
        $rows = (array) $db->setQuery(
            'SELECT ' . $db->quoteName('id') . ', ' . $db->quoteName('source_type') . ', ' . $db->quoteName('source_id')
            . ' FROM ' . $db->quoteName('#__alfa_inventory_effect_outbox')
            . ' WHERE ' . $db->quoteName('status') . ' = ' . $db->quote(self::STATUS_REFUSED)
            . ' AND ' . $db->quoteName('order_id') . ' = ' . $orderId
            . ' ORDER BY ' . $db->quoteName('id') . ' ASC FOR UPDATE',
        )->loadObjectList();

        if ($rows === []) {
            return [];
        }

        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__alfa_inventory_effect_outbox')
            . ' SET ' . $db->quoteName('status') . ' = ' . $db->quote(self::STATUS_PENDING)
            . ', ' . $db->quoteName('claim_token') . ' = NULL'
            . ', ' . $db->quoteName('claimed_at') . ' = NULL'
            . ', ' . $db->quoteName('last_error') . ' = ' . $db->quote('')
            . ', ' . $db->quoteName('modified') . ' = UTC_TIMESTAMP()'
            . ' WHERE ' . $db->quoteName('id') . ' IN (' . implode(',', array_map(static fn (object $row): int => (int) $row->id, $rows)) . ')'
            . ' AND ' . $db->quoteName('status') . ' = ' . $db->quote(self::STATUS_REFUSED),
        );
        $db->execute();

        return array_map(
            static fn (object $row): array => ['source_type' => (string) $row->source_type, 'source_id' => (int) $row->source_id],
            $rows,
        );
    }

    /**
     * Claim and apply outstanding obligations.
     *
     * An effect that throws is settled 'failed' (claimable again) or, when a
     * retry cannot apply it, 'refused' (see {@see self::classify()}). 'failed'
     * in the summary counts every claimed obligation not applied in this pass,
     * refused ones included; 'refused' counts those settled terminal,
     * 'refusals' carries their messages by source id, and 'refusalReasons' the
     * same refusals as the operator reads them (one translated sentence per
     * unresolved order line). Every refusal also raises its order's or its
     * document's notice, so it is announced whichever drain met it.
     *
     * A fulfilment refused because other claims reserve the stock it needs is
     * settled 'failed' like every retryable failure. 'reservedFloor' carries
     * that refusal by source id as the operator reads it, and the order's or
     * the document's notice is raised with it, pointing at the action that
     * applies the movement once that stock is free.
     *
     * @param int $limit How many to take in one pass.
     * @param int|null $staleSeconds Reclaim window for claims whose worker died.
     * @param int $onlySourceId Restrict to one obligation (the producer's
     *                          own immediate attempt); 0 = any. Source
     *                          ids are only unique WITHIN a type, so this
     *                          is meaningless without $onlySourceType.
     * @param string $onlySourceType Restrict to one producing domain.
     *
     * @return array{claimed: int, applied: int, failed: int, refused: int, results: array, errors: array, refusals: array, refusalReasons: array, reservedFloor: array}
     *
     * @since  2.0.0
     */
    public static function drain(
        int $limit = 25,
        ?int $staleSeconds = null,
        int $onlySourceId = 0,
        string $onlySourceType = '',
    ): array {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $staleSeconds ??= self::STALE_CLAIM_SECONDS;
        $summary = [
            'claimed' => 0,
            'applied' => 0,
            'failed' => 0,
            'refused' => 0,
            'results' => [],
            'errors' => [],
            'refusals' => [],
            'refusalReasons' => [],
            'reservedFloor' => [],
        ];

        // Run inside a caller's transaction, a failure that ends it is the caller's
        // to hear (see the catch below).
        $nested = TransactionState::isOpen(db: $db);

        foreach (self::candidates($db, $limit, $staleSeconds, $onlySourceId, $onlySourceType) as $row) {
            $token = bin2hex(random_bytes(16));

            if (!self::claim($db, (int) $row->id, $token, $staleSeconds)) {
                // Another worker took it between the read and the claim. That is
                // the mechanism working, not a failure.
                continue;
            }

            $summary['claimed']++;

            try {
                // The producer's own result travels back with the summary, so
                // an immediate caller can report what happened without asking
                // the domain a second question.
                $summary['results'][(int) $row->source_id] = self::apply($row);
                self::settle($db, (int) $row->id, $token, self::STATUS_APPLIED, '', (string) $row->payload);
                $summary['applied']++;

                // A document's warning is retired where its movement lands. An
                // order's is retired by StockPolicy::reconcileOrderClaim() itself.
                if ((string) $row->source_type === 'document') {
                    self::retireNotice(self::documentNoticeKey((int) $row->source_id));
                }
            } catch (Throwable $e) {
                // Inside a caller's transaction that the failure ended (or may have
                // ended: a lock wait), settling it and going on would write outside
                // the transaction the caller believes it holds. The caller hears it.
                if ($nested && (!TransactionState::isOpen(db: $db) || self::endsTransaction(failure: $e))) {
                    throw $e;
                }

                $status = self::classify(failure: $e);

                self::settle(
                    db:      $db,
                    id:      (int) $row->id,
                    token:   $token,
                    status:  $status,
                    error:   $e->getMessage(),
                    payload: (string) $row->payload,
                );
                $summary['failed']++;
                $summary['errors'][(int) $row->source_id] = $e->getMessage();

                if ($status === self::STATUS_REFUSED) {
                    $reason = self::refusalReason(failure: $e);

                    $summary['refused']++;
                    $summary['refusals'][(int) $row->source_id] = $e->getMessage();
                    $summary['refusalReasons'][(int) $row->source_id] = $reason;

                    self::announceRefusal(
                        row:             $row,
                        reason:          $reason,
                        orderMessage:    'COM_ALFA_NOTIFY_STOCK_ORDER_REFUSED_MSG',
                        documentMessage: 'COM_ALFA_NOTIFY_STOCK_DOCUMENT_REFUSED_MSG',
                        terminal:        true,
                    );
                } elseif ($e instanceof InventoryReservedFloorException) {
                    // Still owed: the movement applies once the other claims let
                    // go of that stock, so the operator is told which stock it is.
                    $sentence = $e->operatorMessage();

                    $summary['reservedFloor'][(int) $row->source_id] = $sentence;

                    self::announceRefusal(
                        row:             $row,
                        reason:          $sentence,
                        orderMessage:    'COM_ALFA_NOTIFY_STOCK_ORDER_RESERVED_MSG',
                        documentMessage: 'COM_ALFA_NOTIFY_STOCK_DOCUMENT_RESERVED_MSG',
                        terminal:        false,
                    );
                }

                Log::add(
                    entry:    'Inventory effect for ' . $row->source_type . ' #' . $row->source_id
                              . ($status === self::STATUS_REFUSED
                                  ? ' refused: operator resolution required: '
                                  : ' failed and remains retryable: ')
                              . $e->getMessage(),
                    priority: Log::ERROR,
                    category: 'com_alfa.inventory',
                );
            }
        }

        return $summary;
    }

    /**
     * Obligations that are outstanding: never settled, or claimed by a worker
     * that has not been heard from within the stale window.
     *
     * @param object $db Database driver.
     * @param int $limit Batch size.
     * @param int $staleSeconds Reclaim window.
     * @param int $onlySourceId Restrict to one obligation, or 0.
     * @param string $onlySourceType Restrict to one producing domain, or ''.
     *
     *
     * @since  2.0.0
     */
    private static function candidates(
        object $db,
        int $limit,
        int $staleSeconds,
        int $onlySourceId,
        string $onlySourceType = '',
    ): array {
        $where = self::claimableWhere($db, $staleSeconds);

        if ($onlySourceId > 0) {
            $where .= ' AND ' . $db->quoteName('source_id') . ' = ' . $onlySourceId;
        }

        if ($onlySourceType !== '') {
            $where .= ' AND ' . $db->quoteName('source_type') . ' = ' . $db->quote($onlySourceType);
        }

        $db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__alfa_inventory_effect_outbox')
            . ' WHERE ' . $where
            // Movements not yet tried go first: a failed one is claimable again at
            // once, and a batch of those at the lowest ids must not starve the rest.
            // Among the failed ones the least recently tried goes first (a claim
            // and its verdict both move `modified`), so the ones that keep failing
            // take their turn behind one whose cause has since been cleared.
            . ' ORDER BY (' . $db->quoteName('status') . ' = ' . $db->quote(self::STATUS_FAILED) . ') ASC, '
            . $db->quoteName(name: 'modified') . ' ASC, '
            . $db->quoteName('id') . ' ASC LIMIT ' . max(1, $limit),
        );

        return (array) $db->loadObjectList();
    }

    /**
     * Take ownership. The affected-row count IS the answer: two workers racing
     * one obligation cannot both get 1, so neither locks nor luck is involved.
     *
     * @param object $db Database driver.
     * @param int $id The obligation.
     * @param string $token This worker's claim token.
     * @param int $staleSeconds Reclaim window.
     *
     * @return bool True when this worker owns the row.
     *
     * @since  2.0.0
     */
    private static function claim(object $db, int $id, string $token, int $staleSeconds): bool
    {
        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__alfa_inventory_effect_outbox')
            . ' SET ' . $db->quoteName('status') . ' = ' . $db->quote(self::STATUS_CLAIMED)
            . ', ' . $db->quoteName('claim_token') . ' = ' . $db->quote($token)
            . ', ' . $db->quoteName('claimed_at') . ' = UTC_TIMESTAMP()'
            . ', ' . $db->quoteName('attempts') . ' = ' . $db->quoteName('attempts') . ' + 1'
            . ', ' . $db->quoteName('modified') . ' = UTC_TIMESTAMP()'
            . ' WHERE ' . $db->quoteName('id') . ' = ' . $id
            . ' AND (' . $db->quoteName('status') . ' IN (' . $db->quote(self::STATUS_PENDING) . ', '
            . $db->quote(self::STATUS_FAILED) . ')'
            . ' OR (' . $db->quoteName('status') . ' = ' . $db->quote(self::STATUS_CLAIMED)
            . ' AND ' . $db->quoteName('claimed_at') . ' <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL '
            . max(0, $staleSeconds) . ' SECOND)))',
        );
        $db->execute();

        return $db->getAffectedRows() === 1;
    }

    /**
     * Settle an obligation this worker owns. The claim token is part of the
     * condition, so a worker that lost its claim to the stale sweep cannot
     * overwrite the verdict of the worker that took over.
     *
     * @param object $db Database driver.
     * @param int $id The obligation.
     * @param string $token This worker's claim token.
     * @param string $status The verdict.
     * @param string $error Why, when the verdict is failure.
     * @param string $payload The version token this worker actually answered
     *                        for. A re-statable producer moves it when the
     *                        state moves, so a verdict computed before that
     *                        must not be recorded.
     *
     *
     * @since  2.0.0
     */
    private static function settle(
        object $db,
        int $id,
        string $token,
        string $status,
        string $error,
        string $payload = '',
    ): void {
        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__alfa_inventory_effect_outbox')
            . ' SET ' . $db->quoteName('status') . ' = ' . $db->quote($status)
            . ', ' . $db->quoteName('last_error') . ' = ' . $db->quote(mb_substr($error, 0, 500))
            . ', ' . $db->quoteName('modified') . ' = UTC_TIMESTAMP()'
            . ' WHERE ' . $db->quoteName('id') . ' = ' . $id
            . ' AND ' . $db->quoteName('claim_token') . ' = ' . $db->quote($token)
            . ' AND ' . $db->quoteName('payload') . ' = ' . $db->quote($payload),
        );
        $db->execute();
    }

    /**
     * The status a failed effect settles in.
     *
     * An order line whose stock identity is gone with no recorded resolution
     * makes the inventory service refuse the whole command, and a retry meets
     * the same refusal: the obligation becomes refused and waits for the
     * operator. A refusal whose lines are all not_current stays retryable: the
     * identity stopped being a current unit while the command waited for its
     * lock (a combination archived, a product given combinations), and the
     * retry reads it as order history and no longer reserves on it. Every other
     * failure stays retryable too: a unit whose tracking changed while the
     * command waited for its lock (the retry reads the new tracking), a unit
     * whose stock figures need the audited repair first, and a fulfilment that
     * would take stock other claims reserve (the retry applies it once those
     * claims are lowered or cancelled, or stock is received).
     *
     * @param Throwable $failure What the effect threw.
     *
     * @return string {@see self::STATUS_REFUSED} or {@see self::STATUS_FAILED}.
     *
     * @since  2.0.0
     */
    private static function classify(Throwable $failure): string
    {
        if ($failure instanceof InventoryLineUnresolvedException) {
            $notCurrent = array_filter(
                $failure->lines,
                static fn (array $line): bool => InventoryLineUnresolvedException::lineReason(line: $line)
                    === InventoryLineUnresolvedException::REASON_NOT_CURRENT,
            );

            return $failure->lines !== [] && \count($notCurrent) === \count($failure->lines)
                ? self::STATUS_FAILED
                : self::STATUS_REFUSED;
        }

        return $failure instanceof InventoryUnitException && $failure->identityLost()
            ? self::STATUS_REFUSED
            : self::STATUS_FAILED;
    }

    /**
     * A refusal as the operator reads it: one translated sentence per order line
     * the inventory service refused, by the line's reason. A line whose
     * combination is gone, a line whose product is gone, and a line on an
     * identity that can no longer be sold or reserved each read differently. A
     * refusal that names no order line keeps the service's own message.
     *
     * The administrator strings are loaded here because a drain runs from any
     * request that moves an order, a storefront payment callback included.
     *
     * @param Throwable $failure The refusal.
     *
     *
     * @since  2.0.0
     */
    private static function refusalReason(Throwable $failure): string
    {
        if (!$failure instanceof InventoryLineUnresolvedException || $failure->lines === []) {
            return $failure->getMessage();
        }

        try {
            Factory::getApplication()->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);
        } catch (Throwable $e) {
            Log::add(
                entry:    'Stock-effect refusal: the administrator strings could not be loaded: ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.inventory',
            );
        }

        $sentences = [];

        foreach ($failure->lines as $line) {
            $lineId = (int) ($line['line_id'] ?? 0);
            $itemId = (int) ($line['item_id'] ?? 0);
            $combinationId = (int) ($line['combination_id'] ?? 0);

            if (InventoryLineUnresolvedException::lineReason(line: $line) === InventoryLineUnresolvedException::REASON_NOT_CURRENT) {
                $sentences[] = Text::sprintf(
                    'COM_ALFA_INVENTORY_LINE_NOT_SELLABLE',
                    $lineId,
                    $combinationId > 0
                        ? Text::sprintf('COM_ALFA_INVENTORY_LINE_UNIT_COMBINATION', $combinationId, $itemId)
                        : Text::sprintf('COM_ALFA_INVENTORY_LINE_UNIT_ITEM', $itemId),
                );

                continue;
            }

            $sentences[] = $combinationId > 0
                ? Text::sprintf('COM_ALFA_INVENTORY_LINE_UNRESOLVED', $lineId, $combinationId)
                : Text::sprintf('COM_ALFA_INVENTORY_LINE_UNRESOLVED_PARENT', $lineId, $itemId);
        }

        return implode(' ', $sentences);
    }

    /**
     * Raise the notice of an obligation the inventory service refused: its
     * order's for a status change, its document's for a document.
     *
     * The drain that meets a refusal is not always the producer's own attempt: a
     * request cut off before its effect ran is answered later by the Tools action,
     * whose only other surface is its one-time message. The notice stays open
     * until the obligation is applied ({@see self::retireSettledNotices()}).
     *
     * A terminal refusal (an order line with no stock identity to move against)
     * sends the operator to the Inventory audit page. A refusal a retry can end
     * (the stock is reserved for other claims) sends the operator to the Tools
     * action that applies the movement.
     *
     * @param object $row The outbox row.
     * @param string $reason The refusal as the operator reads it, raw: the
     *                       notification screens escape it where they render it.
     * @param string $orderMessage Language key of an order's notice text; it takes one
     *                             value, the reason.
     * @param string $documentMessage Language key of a document's notice text; it takes
     *                                one value, the reason.
     * @param bool $terminal Whether a retry cannot apply the obligation.
     *
     *
     * @since  2.0.0
     */
    private static function announceRefusal(
        object $row,
        string $reason,
        string $orderMessage,
        string $documentMessage,
        bool $terminal,
    ): void {
        try {
            Factory::getApplication()->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);

            if ((string) $row->source_type === 'document') {
                $documentId = (int) $row->source_id;
                $db = Factory::getContainer()->get('DatabaseDriver');
                $number = (string) $db->setQuery(
                    'SELECT ' . $db->quoteName('number_formatted') . ' FROM ' . $db->quoteName('#__alfa_documents')
                    . ' WHERE ' . $db->quoteName('id') . ' = ' . $documentId,
                )->loadResult();

                NotificationHelper::push(
                    dedupKey: self::documentNoticeKey(documentId: $documentId),
                    title:    Text::sprintf('COM_ALFA_NOTIFY_STOCK_DOCUMENT_TITLE', $number !== '' ? $number : '#' . $documentId),
                    options:  self::noticeOptions(
                        message: Text::sprintf($documentMessage, $reason),
                        refused: $terminal,
                    ),
                );
            } elseif ((string) $row->source_type === 'order_claim') {
                $orderId = (int) $row->order_id;

                NotificationHelper::push(
                    dedupKey: self::orderNoticeKey(orderId: $orderId),
                    title:    Text::sprintf('COM_ALFA_NOTIFY_STOCK_ORDER_TITLE', $orderId),
                    options:  self::noticeOptions(
                        message: Text::sprintf($orderMessage, $reason),
                        refused: $terminal,
                    ),
                );
            }
        } catch (Throwable $e) {
            // Advisory: a notice that cannot be raised never changes the verdict,
            // unless its failure ended (or may have ended) the caller's transaction.
            if (self::endsTransaction(failure: $e)) {
                throw $e;
            }

            Log::add(
                entry:    'Stock-effect refusal notice for ' . $row->source_type . ' #' . $row->source_id
                          . ' could not be raised: ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.inventory',
            );
        }
    }

    /**
     * Translate an obligation into the effect it stands for.
     *
     * A document's effect is followed, in the same drain, by its order's own
     * deduction on the lines the document names converging on what the
     * document's movement now covers ({@see InventoryBridge::convergeDocumentLines()}):
     * a line whose variant changed while the effect was owed is then out once,
     * under the document, on the identity it froze. The two are separate
     * transactions. When the convergence fails, the obligation stays
     * outstanding with its failure, and the next drain applies the document's
     * effect again, which finds its movement in place and moves nothing, and
     * converges again. It converges whenever the obligation names an order,
     * whatever the effect did this time: a document voided before that retry
     * stands its effect down, and the movement that landed before still has the
     * order's own deduction to converge on. The convergence only gives back, so
     * where no movement of the document ever landed it finds nothing to do.
     *
     * @param object $row The outbox row.
     *
     * @return array|null Whatever the producing domain reports, for a caller
     *                    that is applying its own obligation inline.
     *
     * @throws Throwable Whatever the effect throws; the caller records it and
     *                   leaves the obligation retryable, or refused when a
     *                   retry cannot apply it.
     *
     * @since  2.0.0
     */
    private static function apply(object $row): ?array
    {
        switch ((string) $row->source_type) {
            case 'document':
                // StockBridge is already fenced on the document's status and
                // capped by the ledger, so a re-application after a crash
                // cannot over-apply.
                $applied = StockBridge::applyForDocument(
                    documentId: (int) $row->source_id,
                    docType:    (string) $row->payload,
                    orderId:    (int) $row->order_id,
                );

                // Whatever the effect did this time: a convergence that failed
                // after the effect landed is retried here even when the document
                // has since been voided and its effect stands down. It only gives
                // back, so it finds nothing to do where nothing landed.
                if ((int) $row->order_id > 0) {
                    InventoryBridge::convergeDocumentLines(
                        orderId: (int) $row->order_id,
                        lineIds: self::documentOrderLines(documentId: (int) $row->source_id),
                    );
                }

                return $applied;

            case 'order_claim':
                // Absolute by construction: it reads the order's status as it
                // is NOW, so a queued obligation can never apply a stale
                // effect, and a miss converges regardless of how many
                // claim-equal transitions happened in between.
                return self::applyOrderClaim(orderId: (int) $row->source_id);
        }

        throw new RuntimeException('Unknown stock-effect source type: ' . $row->source_type);
    }

    /**
     * Whether a failure inside an open transaction ended it, or may have: a
     * deadlock always does, and a lock wait that timed out does when the server
     * rolls the whole transaction back on a timeout (innodb_rollback_on_timeout).
     * With no transaction open a failed statement ended nothing but itself.
     *
     * @param Throwable $failure What failed.
     *
     *
     * @since  2.0.0
     */
    private static function endsTransaction(Throwable $failure): bool
    {
        return TransactionState::isOpen(db: Factory::getContainer()->get(resourceName: 'DatabaseDriver'))
            && \in_array(needle: MysqlError::number(error: $failure), haystack: [1205, 1213], strict: true);
    }

    /**
     * The order lines a document names with a stock identity: its item lines
     * anchored on an order line that froze a product. A document's lines do not
     * change once it is issued, so a plain read is the answer.
     *
     * @param int $documentId The document.
     *
     * @return int[] The order line ids.
     *
     * @since  2.0.0
     */
    private static function documentOrderLines(int $documentId): array
    {
        $db = Factory::getContainer()->get(resourceName: 'DatabaseDriver');

        return array_map(
            callback: 'intval',
            array: $db->setQuery(
                query: 'SELECT DISTINCT ' . $db->quoteName(name: 'id_order_item') . ' FROM ' . $db->quoteName(name: '#__alfa_document_items')
                . ' WHERE ' . $db->quoteName(name: 'id_document') . ' = ' . $documentId
                . ' AND ' . $db->quoteName(name: 'line_type') . ' = ' . $db->quote(text: DocumentHelper::LINE_ITEM)
                . ' AND ' . $db->quoteName(name: 'id_order_item') . ' IS NOT NULL'
                . ' AND ' . $db->quoteName(name: 'id_item') . ' IS NOT NULL'
                . ' ORDER BY ' . $db->quoteName(name: 'id_order_item'),
            )->loadColumn() ?: [],
        );
    }

    /**
     * Reconcile an order's stock to what its status claims, after judging once
     * more, under locks, that the status may hold the order.
     *
     * A status that holds a reservation takes no order with a line that would wait
     * for its stock without one: a line placed as a stock claim whose unit does not
     * track stock now ({@see InventoryBridge::unheldClaimRefusal()}). The status
     * change judges that before it saves the status. This is the same judgement
     * made where the hold is taken, for an obligation whose order reached such a
     * status some other way: the order's row is locked, the stock records of its
     * units are locked and their tracking read under them, and the reconciliation
     * runs in the same transaction, so the tracking it was judged on cannot change
     * before the reservation is written. A hit leaves nothing reserved and nothing
     * moved, and the obligation stays owed: the operator switches stock tracking
     * back on for the unit, or gives the order another status, and the next drain
     * applies it.
     *
     * @param int $orderId The order whose claim is applied.
     *
     * @return array What the reconciliation reports.
     *
     * @throws RuntimeException When a line would wait for stock with no reservation;
     *                          its message is the operator's sentence.
     * @throws Throwable Whatever the reconciliation throws.
     *
     * @since  2.0.0
     */
    private static function applyOrderClaim(int $orderId): array
    {
        $db = Factory::getContainer()->get(resourceName: 'DatabaseDriver');

        $db->transactionStart(asSavepoint: true);

        try {
            // The order's row first, as its reconciliation and every fulfilment
            // of its lines take it; stock records only after it.
            $statusId = (int) $db->setQuery(
                query: 'SELECT ' . $db->quoteName(name: 'id_order_status') . ' FROM ' . $db->quoteName(name: '#__alfa_orders')
                . ' WHERE ' . $db->quoteName(name: 'id') . ' = ' . $orderId . ' FOR UPDATE',
            )->loadResult();

            if ($statusId > 0) {
                $unheld = InventoryBridge::unheldClaimRefusal(
                    orderId: $orderId,
                    claim:   StockPolicy::claimFor(statusId: $statusId),
                    lock:    true,
                );

                if ($unheld !== null) {
                    throw new RuntimeException(message: $unheld);
                }
            }

            $applied = StockPolicy::reconcileOrderClaim(orderId: $orderId);

            TransactionState::commitLevel(db: $db);
        } catch (Throwable $e) {
            // After a deadlock the whole transaction is gone, on the server and
            // in the driver's count, so the next obligation of the drain is
            // applied in a real transaction.
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        return $applied;
    }

    /**
     * @param int $orderId The order.
     *
     * @return string The dedup key of that order's failed-stock notice.
     *
     * @since  2.0.0
     */
    public static function orderNoticeKey(int $orderId): string
    {
        return self::ORDER_NOTICE_PREFIX . $orderId;
    }

    /**
     * @param int $documentId The document.
     *
     * @return string The dedup key of that document's failed-stock notice.
     *
     * @since  2.0.0
     */
    public static function documentNoticeKey(int $documentId): string
    {
        return self::DOCUMENT_NOTICE_PREFIX . $documentId;
    }

    /**
     * The options every stock-movement notice shares.
     *
     * Only a user who may open Tools sees it or its link: the one thing the notice asks
     * for is the Tools action that applies the movement. The link stays relative: a
     * notice can be raised from a storefront request (a payment callback moving an
     * order), where a routed URL would point at the site instead of the administrator.
     *
     * A REFUSED movement is not fixed by that action: its order line has to be resolved
     * on the Inventory audit page first, so its notice links there instead, and only for
     * the users that page admits (Super Users). Who sees the notice is unchanged.
     *
     * A FAILURE IS NEW EVERY TIME, so the notice re-opens (`constant`). push() on an
     * archived row only updates it and leaves it dismissed; without this, an order that
     * failed once, was fixed, and failed again would stay silent the second time.
     *
     * @param string $message The translated message.
     * @param bool $refused Whether the movement was refused and waits for an order
     *                      line to be resolved.
     *
     * @return array Options for NotificationHelper::push().
     *
     * @since  2.0.0
     */
    public static function noticeOptions(string $message, bool $refused = false): array
    {
        return [
            'group' => Text::_('COM_ALFA_NOTIFY_GROUP_INVENTORY'),
            'severity' => 'warning',
            'message' => $message,
            'constant' => true,
            'url' => $refused
                ? 'index.php?option=com_alfa&view=inventoryaudit'
                : 'index.php?option=com_alfa&view=tools#dbsync',
            'view' => ['action' => 'alfa.tools', 'asset' => 'com_alfa'],
            'link' => $refused
                ? ['action' => 'core.admin', 'asset' => 'com_alfa']
                : ['action' => 'alfa.tools', 'asset' => 'com_alfa'],
        ];
    }

    /**
     * How many obligations a drain would pick up right now.
     *
     * @param int|null $staleSeconds Age after which a claim counts as abandoned.
     *
     *
     * @since  2.0.0
     */
    public static function outstandingCount(?int $staleSeconds = null): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_inventory_effect_outbox')
            . ' WHERE ' . self::claimableWhere($db, $staleSeconds ?? self::STALE_CLAIM_SECONDS),
        );

        return (int) $db->loadResult();
    }

    /**
     * How many obligations wait for the operator: refused because an order line
     * has no stock identity to move against, so no drain applies them until the
     * line is resolved.
     *
     *
     * @since  2.0.0
     */
    public static function refusedCount(): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_inventory_effect_outbox')
            . ' WHERE ' . $db->quoteName('status') . ' = ' . $db->quote(self::STATUS_REFUSED),
        );

        return (int) $db->loadResult();
    }

    /**
     * Obligations nobody is working on any more: a failed one, or a pending or claimed
     * one older than the stale window. A fresh pending row is not here: it belongs to
     * a request that is still running and about to apply it.
     *
     * @param int $limit Most rows to return.
     * @param int|null $staleSeconds Age after which a row counts as abandoned.
     *
     * @return object[]
     *
     * @since  2.0.0
     */
    public static function strandedRows(int $limit = 200, ?int $staleSeconds = null): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $cutoff = 'DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . max(0, $staleSeconds ?? self::STALE_CLAIM_SECONDS) . ' SECOND)';

        $db->setQuery(
            'SELECT ' . $db->quoteName('id') . ', ' . $db->quoteName('source_type') . ', '
            . $db->quoteName('source_id') . ', ' . $db->quoteName('order_id')
            . ' FROM ' . $db->quoteName('#__alfa_inventory_effect_outbox')
            . ' WHERE ' . $db->quoteName('status') . ' = ' . $db->quote(self::STATUS_FAILED)
            . ' OR (' . $db->quoteName('status') . ' = ' . $db->quote(self::STATUS_PENDING)
            . ' AND ' . $db->quoteName('modified') . ' <= ' . $cutoff . ')'
            . ' OR (' . $db->quoteName('status') . ' = ' . $db->quote(self::STATUS_CLAIMED)
            . ' AND ' . $db->quoteName('claimed_at') . ' <= ' . $cutoff . ')'
            . ' ORDER BY ' . $db->quoteName('id') . ' ASC LIMIT ' . max(1, $limit),
        );

        return (array) $db->loadObjectList();
    }

    /**
     * Keep the notification centre honest about owed stock movements, cheaply.
     *
     * Called from the bell's async fetch, never from a page render, and throttled the
     * way the integrity check is. This is what replaces a scheduled task: a request
     * that was cut off cannot raise its own warning, so the next administrator who
     * opens a screen raises it for it.
     *
     *
     * @since  2.0.0
     */
    public static function syncNotice(): void
    {
        try {
            $cache = Factory::getContainer()
                ->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', [
                    'defaultgroup' => 'com_alfa.stockeffects',
                    'lifetime' => self::NOTICE_CHECK_MINUTES,
                    'caching' => true,
                ]);

            $cache->get([self::class, 'doSyncNotice'], [], 'sync');
        } catch (Throwable) {
            // Cache unavailable: just do it; correct, only less throttled.
            self::doSyncNotice();
        }
    }

    /**
     * The uncached check behind {@see self::syncNotice()}.
     *
     * Warnings whose movement is no longer owed are retired first. Then one notice
     * states how many owed movements nothing is currently warning about; a movement
     * whose order or document already shows its own notice is not counted twice.
     *
     * @return bool Always true, for the cache callback.
     *
     * @since  2.0.0
     */
    public static function doSyncNotice(): bool
    {
        try {
            self::retireSettledNotices();

            $unannounced = 0;

            foreach (self::strandedRows() as $row) {
                $key = (string) $row->source_type === 'document'
                    ? self::documentNoticeKey((int) $row->source_id)
                    : self::orderNoticeKey((int) $row->order_id);

                if (!NotificationHelper::isOpen(dedupKey: $key)) {
                    $unannounced++;
                }
            }

            if ($unannounced === 0) {
                self::retireNotice(self::NOTICE_STRANDED);

                return true;
            }

            Factory::getApplication()->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);

            // Re-open it only when it is not already standing. A standing notice just gets
            // its count updated: re-alerting on every check would mark it unread again every
            // few minutes for as long as the problem lasts.
            NotificationHelper::push(
                self::NOTICE_STRANDED,
                Text::_('COM_ALFA_NOTIFY_STOCK_STRANDED_TITLE'),
                ['dismissible' => false, 'constant' => !NotificationHelper::isOpen(dedupKey: self::NOTICE_STRANDED)]
                    + self::noticeOptions(Text::sprintf('COM_ALFA_NOTIFY_STOCK_STRANDED_MSG', $unannounced)),
            );
        } catch (Throwable $e) {
            Log::add('Stock-effect notice check failed: ' . $e->getMessage(), Log::WARNING, 'com_alfa.inventory');
        }

        return true;
    }

    /**
     * Retire the failed-stock warnings whose movement is no longer owed.
     *
     * The producer that raised an alert is what must retire it, and "owed" is this
     * class's fact: a warning left from a movement that was since applied, or one
     * with no movement recorded at all, would otherwise stand for ever, asking the
     * merchant to fix nothing.
     *
     * @return int How many warnings were retired.
     *
     * @since  2.0.0
     */
    public static function retireSettledNotices(): int
    {
        $retired = 0;

        foreach ([self::ORDER_NOTICE_PREFIX => 'order', self::DOCUMENT_NOTICE_PREFIX => 'document'] as $prefix => $kind) {
            foreach (NotificationHelper::keys(prefix: $prefix) as $key) {
                $key = (string) $key;
                $id = (int) substr($key, \strlen($prefix));

                if ($id <= 0 || !NotificationHelper::isOpen(dedupKey: $key) || self::owes($kind, $id)) {
                    continue;
                }

                NotificationHelper::clear(dedupKey: $key);
                $retired++;
            }
        }

        return $retired;
    }

    /**
     * @param string $kind 'order' or 'document'.
     * @param int $id The order or document.
     *
     * @return bool Whether any movement for it is still outstanding.
     *
     * @since  2.0.0
     */
    private static function owes(string $kind, int $id): bool
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $which = $kind === 'document'
            ? $db->quoteName('source_type') . ' = ' . $db->quote('document') . ' AND ' . $db->quoteName('source_id') . ' = ' . $id
            : $db->quoteName('order_id') . ' = ' . $id;

        $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_inventory_effect_outbox')
            . ' WHERE ' . $which
            . ' AND ' . $db->quoteName('status') . ' <> ' . $db->quote(self::STATUS_APPLIED),
        );

        return (int) $db->loadResult() > 0;
    }

    /**
     * Archive a standing notice; nothing when there is none.
     *
     * @param string $key The dedup key.
     *
     *
     * @since  2.0.0
     */
    private static function retireNotice(string $key): void
    {
        try {
            if (NotificationHelper::isOpen(dedupKey: $key)) {
                NotificationHelper::clear(dedupKey: $key);
            }
        } catch (Throwable $e) {
            // Advisory only: a failed retraction never fails a stock movement, unless
            // it ended (or may have ended) the caller's transaction.
            if (self::endsTransaction(failure: $e)) {
                throw $e;
            }
        }
    }

    /**
     * The one definition of "claimable": pending or failed, or claimed longer ago than
     * the stale window. {@see self::claim()} repeats it inside its conditional UPDATE on
     * purpose: that copy is the concurrency guard and must stay one statement.
     *
     * @param object $db Database driver.
     * @param int $staleSeconds Age after which a claim counts as abandoned.
     *
     * @return string SQL condition.
     *
     * @since  2.0.0
     */
    private static function claimableWhere(object $db, int $staleSeconds): string
    {
        return '(' . $db->quoteName('status') . ' IN (' . $db->quote(self::STATUS_PENDING) . ', '
            . $db->quote(self::STATUS_FAILED) . ')'
            . ' OR (' . $db->quoteName('status') . ' = ' . $db->quote(self::STATUS_CLAIMED)
            . ' AND ' . $db->quoteName('claimed_at') . ' <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL '
            . max(0, $staleSeconds) . ' SECOND)))';
    }
}
