<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CommitmentRequest;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryContext;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryReservedFloorException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryService;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use RuntimeException;
use Throwable;

/**
 * The document->inventory SEMANTIC RESOLVER. Documents never call
 * inventory: the type registry states WHAT issuing a type means
 * (stock_effect in none | deduct | restore) and this helper translates
 * that semantic into a CommitmentRequest / restore command through the
 * kernel's single fulfilment entry. Never `if ($docType == '...')`.
 *
 * Two moments in the issue flow:
 *   validateForIssue()  PRE-transaction: a strict-policy violation, a
 *                       line whose stock identity is gone with no recorded
 *                       resolution, or a deduct that would take stock other
 *                       claims reserve, vetoes the issue before a number burns.
 *   applyForDocument()  POST-commit: the persisted document (real id =
 *                       the idempotency source) drives the stock effect.
 *
 * WHAT MOVES IS WHAT THE DOCUMENT FROZE. Product identity, variant
 * identity and the movement bound come from the document line's own
 * id_item / id_combination / movement_bound, never from the order line:
 * the effect is applied after the issue committed and released the lock,
 * so the order may already carry a different quantity or variant, and
 * re-reading it would let an ordinary edit reinterpret goods that have
 * already moved. id_order_item stays the ledger identity and provenance;
 * it names the line, it does not authorise the movement.
 *
 * WHAT THIS DELIBERATELY DOES NOT OWN: "have these units already come
 * back?". That guard lived here and was one-directional: it stopped a
 * document re-shelving what a return had shelved, but nothing stopped the
 * reverse, so the same two units gained +4.00 when the document went first.
 * The question belongs to the one seam BOTH doors pass through, so
 * InventoryService::restore() now answers it for either caller. Translating
 * a document type into a kernel command is all this class does.
 *
 * @since  2.0.0
 */
class StockBridge
{
    /**
     * How the inventory service opens a log sentence of its own. A refusal that
     * lists several problems says it once, before the first.
     *
     * @since  2.0.0
     */
    private const SERVICE_PREFIX = 'Inventory: ';

    /**
     * How the inventory service closes the log sentence of a command it refused.
     * As a clause of a list, the problem ends before it.
     *
     * @since  2.0.0
     */
    private const SERVICE_CLOSING = '. Nothing was changed.';

    /**
     * Pre-issue dry run: would the document's stock effect apply cleanly?
     * Throws on the first problem so the issue vetoes BEFORE its
     * transaction opens: no number burned, nothing written.
     *
     * A deduct is checked against the kernel's fulfilment rules. A restore
     * cannot over-apply (it is capped by the ledger), but the kernel refuses
     * it whole when an order line's stock identity is gone with no recorded
     * resolution while units are still out on it, so that is checked before
     * issue too. An identity kept only for order history (an archived
     * combination, the product itself once it has combinations) restores.
     *
     * A deduct that would take stock other claims reserve is refused as the
     * inventory service's own typed refusal, before a number is drawn: when
     * that is all that stands in the way, the service's refusal itself is
     * thrown, so the caller can show the operator which stock is reserved.
     * Beside a problem of another kind it is one clause of the refusal that
     * lists them all ({@see self::clause()}).
     *
     * @param string $docType The document type alias.
     * @param int $orderId The order being documented.
     * @param array $lines The draft lines, carrying the identity the
     *                     issue is about to freeze (id_order_item,
     *                     id_item, id_combination, quantity,
     *                     movement_bound).
     * @param bool $isCombined Whether the document also serves as a
     *                         delivery note.
     *
     *
     * @throws InventoryReservedFloorException When the only thing a deduct would be
     *                                         rejected for is stock other claims reserve.
     * @throws RuntimeException When the effect would be rejected for
     *                          anything else.
     *
     * @since  2.0.0
     */
    public static function validateForIssue(string $docType, int $orderId, array $lines, bool $isCombined = false): void
    {
        // The DOCUMENT's effect, not its type's: a combined document carries the
        // delivery note's deduct, and without asking for it here the dry run
        // waves through an issue whose deduct is impossible, burning a gapless
        // number before the post-commit effect discovers it.
        $effect = DocumentHelper::stockEffectForDocument(docType: $docType, isCombined: $isCombined);

        if ($effect !== DocumentHelper::STOCK_EFFECT_DEDUCT && $effect !== DocumentHelper::STOCK_EFFECT_RESTORE) {
            // 'none' has no stock meaning at all.
            return;
        }

        $requestLines = self::requestLines(lines: $lines);

        if ($requestLines === []) {
            return;
        }

        $service = new InventoryService();

        if ($effect === DocumentHelper::STOCK_EFFECT_RESTORE) {
            $problems = $service->validateRestore(sourceType: 'document', lines: $requestLines);
        } else {
            // Source id 0 for a deduct: the document does not exist yet; the dry
            // run only evaluates quantities, the real command anchors on the real id.
            $judged = $service->commitmentProblems(
                request: new CommitmentRequest(sourceType: 'document', sourceId: 0, lines: $requestLines),
            );

            // When stock other claims reserve is all that stands in the way, the
            // service's own refusal is thrown, as its command would throw it.
            $reserved = array_values(array_filter(
                $judged,
                static fn (array $problem): bool => ($problem['code'] ?? '') === 'reserved_floor'
                    && ($problem['refusal'] ?? null) instanceof InventoryReservedFloorException,
            ));

            if ($reserved !== [] && \count($reserved) === \count($judged)) {
                throw $reserved[0]['refusal'];
            }

            // Listed with other problems, each text is one clause of the refusal.
            $problems = array_map(
                callback: static fn (array $problem): string => self::clause(text: (string) ($problem['text'] ?? '')),
                array: $judged,
            );
        }

        if ($problems !== []) {
            throw new RuntimeException(self::SERVICE_PREFIX . implode('; ', $problems));
        }
    }

    /**
     * One problem of the pre-issue dry run as a clause of the refusal that lists
     * them.
     *
     * The inventory service words most problems as clauses. Stock other claims
     * reserve is worded as a log sentence of its own: it opens with the service's
     * prefix and closes by saying that nothing was changed. The list says the
     * prefix once, before its first clause, and a refused issue changes nothing
     * whatever the list holds, so both are left out of the clause.
     *
     * @param string $text The problem as the inventory service words it.
     *
     * @return string The problem without the service's prefix and closing sentence.
     *
     * @since  2.0.0
     */
    private static function clause(string $text): string
    {
        $clause = trim(string: $text);

        if (str_starts_with(haystack: $clause, needle: self::SERVICE_PREFIX)) {
            $clause = substr(string: $clause, offset: \strlen(string: self::SERVICE_PREFIX));
        }

        if (str_ends_with(haystack: $clause, needle: self::SERVICE_CLOSING)) {
            $clause = substr(string: $clause, offset: 0, length: -\strlen(string: self::SERVICE_CLOSING));
        }

        return $clause;
    }

    /**
     * Post-commit application of the issued document's stock effect,
     * through the kernel's single fulfilment entry. Idempotent by the
     * document source identity: reissues/retries no-op.
     *
     * @param int $documentId The persisted document id.
     * @param string $docType The document type alias.
     * @param int $orderId The documented order.
     *
     * @return array|null The command summary, or null when the type has
     *                    no stock effect / no anchorable lines.
     *
     * @since  2.0.0
     */
    public static function applyForDocument(int $documentId, string $docType, int $orderId): ?array
    {
        // NOT short-circuited on the TYPE's effect any more: a combined document
        // of a type that has none still carries the delivery note's, and that is
        // only knowable from the document row read under the fence below.
        $db = Factory::getContainer()->get('DatabaseDriver');

        // The effect belongs to an issued document
        //
        // This runs after the issue committed and released the order lock, so
        // a void can complete in between. DocumentIssuer::void() is the ONE
        // withdrawal mechanism and it refuses a document that is not ISSUED,
        // so it runs exactly once, and a claim written after that point can
        // never be withdrawn by anything. Measured: a credit note voided in
        // this window kept a restore of its own for ever, unlogged.
        //
        // The status is therefore re-read FOR UPDATE behind the SAME order
        // lock void() takes, and held until the effect is written. Whichever
        // side wins the race the outcome is sound: the effect lands and void
        // reverses it, or void lands and the effect stands down. Nothing in
        // void() is widened and no new serialization point is introduced.
        //
        // The guard lives HERE rather than in the caller because this is the
        // method that writes: a fence at one call site protects one call site.
        //
        // Savepoint-aware: an issuing caller may still hold an open
        // transaction, and a bare transactionStart() would issue a second
        // START TRANSACTION, which MySQL treats as an implicit COMMIT of it.
        $db->transactionStart(true);

        try {
            $db->setQuery(
                'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__alfa_orders')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $orderId . ' FOR UPDATE',
            );
            $db->loadResult();

            $db->setQuery(
                'SELECT ' . $db->quoteName('status') . ', ' . $db->quoteName('is_combined')
                . ' FROM ' . $db->quoteName('#__alfa_documents')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $documentId . ' FOR UPDATE',
            );
            $row = $db->loadObject();
            $status = $row->status ?? null;

            if ($status === null || (int) $status !== DocumentHelper::STATUS_ISSUED) {
                Log::add(
                    entry:    "Document #{$documentId}: stock effect stood down; the document was no longer"
                              . ' issued when the effect was applied (status '
                              . ($status === null ? 'missing' : (int) $status) . ').',
                    priority: Log::WARNING,
                    category: 'com_alfa.inventory',
                );

                TransactionState::commitLevel(db: $db);

                return null;
            }

            // The DOCUMENT's effect, not its type's alone: a combined document
            // also carries the delivery note's, which is why the flag is read
            // here rather than resolved from $docType.
            $effect = DocumentHelper::stockEffectForDocument($docType, (int) ($row->is_combined ?? 0) === 1);

            if ($effect === DocumentHelper::STOCK_EFFECT_NONE) {
                TransactionState::commitLevel(db: $db);

                return null;
            }

            $result = self::applyFenced(db: $db, documentId: $documentId, effect: $effect);

            TransactionState::commitLevel(db: $db);

            return $result;
        } catch (Throwable $e) {
            // After a deadlock the whole transaction is gone, on the server and
            // in the driver's count; the caller hears the failure itself.
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }
    }

    /**
     * The effect itself, written while the caller holds the order lock and has
     * confirmed the document is still issued.
     *
     * @param object $db Database driver (inside the fenced transaction).
     * @param int $documentId The persisted document id.
     * @param string $effect The type's resolved stock effect.
     *
     * @return array|null The command summary, or null when no line anchors.
     *
     * @since  2.0.0
     */
    private static function applyFenced(object $db, int $documentId, string $effect): ?array
    {
        // Every inventory fact the movement needs comes from this row: the
        // document froze it at issue, and by now the order may say otherwise.
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id_order_item', 'id_item', 'id_combination', 'quantity', 'movement_bound']))
            ->from($db->quoteName('#__alfa_document_items'))
            ->where($db->quoteName('id_document') . ' = ' . (int) $documentId)
            ->where($db->quoteName('line_type') . ' = ' . $db->quote('item'))
            ->where($db->quoteName('id_order_item') . ' IS NOT NULL');
        $docLines = $db->setQuery($query)->loadObjectList() ?: [];

        $lines = array_map(
            static fn (object $row): array => [
                'id_order_item' => (int) $row->id_order_item,
                'id_item' => $row->id_item !== null ? (int) $row->id_item : null,
                'id_combination' => $row->id_combination !== null ? (int) $row->id_combination : null,
                'quantity' => (float) $row->quantity,
                'movement_bound' => $row->movement_bound !== null ? (float) $row->movement_bound : null,
            ],
            $docLines,
        );

        $requestLines = self::requestLines(lines: $lines);

        if ($requestLines === []) {
            return null;
        }

        $service = new InventoryService();
        $context = new InventoryContext(note: 'Document stock effect');

        if ($effect === DocumentHelper::STOCK_EFFECT_RESTORE) {
            return $service->restore(
                sourceType: 'document',
                sourceId: $documentId,
                lines: $requestLines,
                context: $context,
            );
        }

        return $service->commitReservation(
            request: new CommitmentRequest(sourceType: 'document', sourceId: $documentId, lines: $requestLines),
            context: $context,
        );
    }

    /**
     * Translate FROZEN document lines into kernel request lines. This is
     * the one place the column names meet the kernel's keys: id_item ->
     * item_id, id_combination -> combination_id, movement_bound -> the
     * ordered bound the movement caps against; the document's own
     * quantity is the INCREMENT. line_id stays the order line: the
     * per-line ledger identity, provenance rather than authority.
     *
     * A line that froze no identity or no bound carries no movement
     * authority (a free-text line, or any line of a stock_effect: none
     * document), and NULL is not a zero, so it is skipped rather than
     * moved by nothing.
     *
     * @param array $lines Frozen line arrays: id_order_item, id_item,
     *                     id_combination, quantity, movement_bound.
     *
     * @return array The CommitmentRequest / restore line arrays.
     *
     * @since  2.0.0
     */
    private static function requestLines(array $lines): array
    {
        $requestLines = [];

        foreach ($lines as $line) {
            $line = (array) $line;
            $anchor = (int) ($line['id_order_item'] ?? 0);
            $itemId = (int) ($line['id_item'] ?? 0);
            $quantity = (float) ($line['quantity'] ?? 0);
            $bound = $line['movement_bound'] ?? null;

            // A missing identity or bound is not a zero: the line was never
            // authorised to move stock, so it is skipped outright; capping
            // it at nothing would turn a forgotten freeze into a silent no-op.
            if ($anchor <= 0 || $itemId <= 0 || $quantity <= 0 || $bound === null) {
                continue;
            }

            $combination = $line['id_combination'] ?? null;

            $requestLines[] = [
                'item_id' => $itemId,
                'combination_id' => $combination !== null ? (int) $combination : null,
                'quantity' => $quantity,
                'ordered' => (float) $bound,
                'line_id' => $anchor,
                'mode' => 'increment',
            ];
        }

        return $requestLines;
    }
}
