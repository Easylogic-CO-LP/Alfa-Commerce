<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Documents\DocumentBeforeIssueEvent;
use Alfa\Component\Alfa\Administrator\Event\Documents\DocumentIssuedEvent;
use Alfa\Component\Alfa\Administrator\Event\Documents\DocumentVoidedEvent;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationText;
use Alfa\Component\Alfa\Administrator\Helper\PostCommit;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryContext;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryEffectOutbox;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryService;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\ParameterType;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * DocumentIssuer: THE single write path for sales documents.
 *
 * Every document (auto-issue on status flags, manual admin issuance, future
 * API/fulfilment flows) is created and voided HERE, inside one transaction
 * envelope:
 *
 *   order row FOR UPDATE  -> serializes all document ops per order
 *   -> validate draft + over-issue/credit guards (inside the lock)
 *   -> series row FOR UPDATE -> year scoping + date monotonicity (shop tz)
 *   -> draw gapless number (assigned at issue ONLY, frozen formatted string)
 *   -> insert header + lines + per-rate VAT summary
 *   -> advance series (next_number, last_issued_at)
 *   -> order activity log
 *   -> COMMIT -> onDocumentIssued (post-commit, non-blocking)
 *
 * Invariants owned here:
 *   - a failed transaction never burns a number (draw is last-moment, in-tx)
 *   - issued documents are IMMUTABLE: void() flips status only; there is
 *     NO unvoid and NO edit/delete path anywhere in the component
 *   - the sum of partial documents never exceeds the order (sales share one pool,
 *     credits draw on issued sales, delivery notes count independently)
 *   - rounding: every document is internally consistent from its own
 *     frozen lines; the FINAL partial for a line takes the amount remainder
 *     (OrderItemLines::claim(), the draft builders' rule; this helper
 *     stores what it is given, frozen)
 *
 * @since  2.0.0
 */
final class DocumentIssuer
{
    /**
     * The draft key that carries the answer of onDocumentBeforeIssue.
     *
     * issue() and void() each end with work that must only happen once their
     * write is durable: announcing it (onDocumentIssued, onDocumentVoided; the
     * void event's own docblock names tax-authority cancellation as its
     * consumer), draining the document's stock obligation into third-party
     * inventory code, and clearing auto-issue alerts. That work is deferred to
     * the COMMIT of the outermost transaction (PostCommit), so a number a
     * caller's rollback takes back is never announced and never drained.
     *
     * The question before a number, onDocumentBeforeIssue, is asked before any
     * transaction opens: a subscriber that changes the schema or commits there
     * would otherwise commit the caller's work and every write after it one
     * statement at a time, and a document refused later would keep its
     * number. A caller that issues inside its own transaction asks it first
     * (beforeIssue()) and hands the answered draft to issue(), which knows it
     * by this key: a seal of the answered draft.
     *
     * @since  2.0.0
     */
    public const ASKED = 'before_issue_asked';

    /**
     * The key the seals of answered drafts are made with, one per process.
     *
     *
     * @since  2.0.0
     */
    private static ?string $askKey = null;

    /**
     * TRUE while envelope() owns the transaction.
     *
     * A nested call is refused in words that name what it was nested in.
     *
     *
     * @since  2.0.0
     */
    private static bool $enveloped = false;

    /**
     * TRUE while replace() owns the transaction.
     *
     *
     * @since  2.0.0
     */
    private static bool $replaceOpen = false;

    /**
     * The source document replace() is superseding, while it runs; 0 otherwise.
     *
     * `id_replaces` is canonical provenance and the schema says who writes it:
     * "ONLY the explicit Void & Replace workflow". issue() refuses a draft that
     * carries it unless it names exactly this id, so neither a manual path nor
     * an onDocumentBeforeIssue subscriber can invent a replacement relationship
     * (section 19, N5: a manual void followed by ordinary issuance is NOT a
     * replacement, however similar the resulting numbers).
     *
     *
     * @since  2.0.0
     */
    private static int $replacing = 0;

    /**
     * Quantity precision for the over-issue guards.
     *
     * QUANTITY, NOT MONEY, and named so nobody converts it by sweep. A line
     * quantity is legitimately fractional (weight, length, hours) and has no
     * currency scale to be exact at, so it carries a declared precision
     * instead. The monetary guards beside it ask Money at a frozen scale and
     * take no tolerance at all.
     *
     * @since  2.0.0
     */
    private const QUANTITY_EPSILON = 0.000001;

    /**
     * Issue a document from a plain-data draft.
     *
     * Draft keys:
     *   doc_type       string REQUIRED - DocumentHelper::types()
     *   id_order       int    REQUIRED
     *   id_series      int    0/absent = default series for the type
     *   is_combined    bool   Fiscal doc that also serves as delivery note.
     *   id_corrects    int    Corrected document id, REQUIRED on credit types
     *                         (it is what caps the credited amount).
     *   id_replaces    int    Superseded document id, supplied ONLY by the
     *                         explicit Void & Replace workflow; absent/0 = NULL
     *                         on every other path.
     *   id_shipment    int    Shipment reference (delivery notes; 0 = none).
     *   language       string Document language tag. A credit is stamped with its
     *                         corrected document's language: a credit draft
     *                         naming another is refused, one naming none is
     *                         stamped with it. Other types default to
     *                         DocumentHelper::defaultLanguageTag().
     *   buyer_*        string Buyer snapshot (name, vat_number, tax_office,
     *                         activity, country, email); buyer_address array|null.
     *   movement_*     string movement_purpose, carrier_name, vehicle_number;
     *                         movement_start datetime string; ship_from/ship_to array|null.
     *   payment_method_name string - authority_payment_type int|null
     *   note, print_note    string
     *   created_by     int    Default: current identity.
     *   credit_charges array  The CreditCalculator decision record (manual
     *                         credits only); rebuilt and compared under the
     *                         lock, then written as the
     *                         `document.credit_charges` trail event.
     *   credit_destination string Where a credit's money is sent, frozen on
     *                         it with its number:
     *                         CustomerCreditLedger::CREDIT_NOTE_DESTINATION
     *                         for the customer's credit account, absent for
     *                         any other destination. Refused on every other
     *                         type and for any other value, in the
     *                         operator's sentence for a document that was
     *                         changed while it was issued.
     *   lines          array  REQUIRED non-empty. Each line:
     *     line_type ('item'|'shipping'|'fee'|'adjustment', default item),
     *     id_order_item (int|null; null = free-text/value-only line),
     *     id_item / id_combination / movement_bound (the frozen inventory
     *     identity and the movement ceiling as at issue; absent/0 = NULL,
     *     meaning the line carries no inventory identity or authority),
     *     id_source (int: the order charge a shipping, fee or adjustment
     *     line bills or reverses; REQUIRED on every such line of a fiscal
     *     sales document and of a credit),
     *     sku, name (REQUIRED, resolved single-language), description,
     *     quantity (default 1), unit,
     *     unit_price_tax_excl / unit_price_tax_incl (REQUIRED for fiscal),
     *     original_unit_price_tax_excl, discount_tax_excl,
     *     custom_unit_price_tax_incl (the agreed custom rate the line was
     *     frozen with; absent = NULL, and NULL on a document without money),
     *     tax_rate, vat_category, vat_exemption_code,
     *     total_tax_excl / tax_amount / total_tax_incl (absent = unit x qty,
     *     a fallback for plugin-amended drafts: the core builders always pass
     *     them, from the frozen order line or the corrected document line),
     *     sort_order.
     *
     * Totals + the per-rate VAT summary are computed from the lines.
     *
     * @param array $draft The document draft (see above). Inside an
     *                     open transaction, a draft answered by
     *                     beforeIssue() before that transaction began.
     * @param bool $manageTransaction Own the transaction (default true);
     *                                false when the caller already holds one.
     * @param bool $applyStockEffect Apply the type's stock effect (default
     *                               true). Pass FALSE only when the caller
     *                               has ALREADY moved these goods through
     *                               the inventory kernel itself; see below.
     *
     * @return object The issued `#__alfa_documents` row (fresh read).
     *
     * @throws RuntimeException On guard/validation failure or write errors
     *                          (after rollback when this call owns the tx).
     *
     * @since  2.0.0
     */
    public static function issue(
        array $draft,
        bool $manageTransaction = true,
        bool $applyStockEffect = true,
    ): object {
        // The stock-effect lines below (skipped / failed) are written to
        // com_alfa.inventory, and an unregistered category is dropped in
        // silence. Registered here for the same reason the money services do
        // it: documents are issued from requests that never boot com_alfa:
        // auto-issue on a status change, an RMA workflow, a cron job. AlfaLog
        // owns the map; this only makes sure it has been applied.
        AlfaLog::register();

        // BEFORE event: subscribers may amend the draft or veto by
        // throwing. Asked before any transaction opens, so a veto never
        // burns a number and a subscriber never acts inside a caller's
        // transaction; amended drafts still pass every guard, so a
        // subscriber that adds a charge line with no source to a sales
        // document is refused like any caller.
        $draft = self::answeredDraft(draft: $draft);

        $docType = (string) ($draft['doc_type'] ?? '');
        $orderId = (int) ($draft['id_order'] ?? 0);
        $lines = $draft['lines'] ?? [];

        if (!\in_array($docType, DocumentHelper::types(), true)) {
            throw new RuntimeException("DocumentIssuer: unknown doc_type '{$docType}'.");
        }

        if ($orderId <= 0) {
            throw new RuntimeException('DocumentIssuer: a draft needs id_order.');
        }

        if (!\is_array($lines) || $lines === []) {
            throw new RuntimeException('DocumentIssuer: a document needs at least one line.');
        }

        // Provenance is not a draft field anyone may set
        // Checked AFTER the before-event on purpose: a subscriber may amend
        // the draft, and one that added id_replaces must be refused as surely
        // as a caller that passed it.
        $replaces = (int) ($draft['id_replaces'] ?? 0);

        if ($replaces > 0 && $replaces !== self::$replacing) {
            throw new RuntimeException(
                'DocumentIssuer: id_replaces is written only by the explicit Void & Replace workflow; '
                . "a document cannot be issued as a replacement of #{$replaces} from anywhere else.",
            );
        }

        // Who moves the goods
        // The type registry says what ISSUING a type means for stock, and by
        // default this is the only mover. But a document is not always the
        // first party to touch the goods: an RMA inspects them, decides
        // restock or scrap, and moves stock through the same inventory
        // kernel at that moment; it has to, because scrap must net to zero
        // whether or not a credit note ever follows.
        //
        // Both moving is a DOUBLE COUNT, and the per-line ledger does not
        // catch it: restore() nets deducted-minus-restored for the LINE, so
        // it only refuses once the line's whole outstanding balance is
        // consumed. Return 1 unit of a 5-unit line and both the RMA and the
        // note find 4 still out and each give a unit back, measured at
        // stock +2.00 for one physical unit returned (and +1.00 for a
        // SCRAP, which must move nothing at all).
        //
        // So the mover stands down explicitly rather than by luck. This is
        // NOT a plugin redefining stock semantics; restore still means
        // exactly what it meant; it is the one caller that already did the
        // work saying so.
        if ($applyStockEffect) {
            // Stock-effect dry run (semantic resolver): a strict-policy
            // violation vetoes HERE, before the transaction: no number burns.
            StockBridge::validateForIssue(
                docType: $docType,
                orderId: $orderId,
                lines: $lines,
                isCombined: !empty($draft['is_combined']),
            );
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        if ($manageTransaction) {
            $db->transactionStart(true);
        }

        try {
            // 1. Order lock: serializes every document op for this order
            $order = self::lockOrder(db: $db, orderId: $orderId);

            // The order's own currency, read under the lock and before a
            // number is drawn. An order that names none is issued nothing,
            // whatever the type: a credit's header names its order's too.
            $orderCurrency = self::documentCurrency(order: $order);

            // 2. Draft validation + over-issue guards (inside the lock)
            self::validateDraft(docType: $docType, draft: $draft, lines: $lines);
            self::guardQuantities(db: $db, docType: $docType, orderId: $orderId, lines: $lines, draft: $draft);
            self::guardReplacement(db: $db, docType: $docType, orderId: $orderId, draft: $draft);

            // 3. Series lock + year scoping + monotonicity
            $seriesId = (int) ($draft['id_series'] ?? 0);

            if ($seriesId <= 0) {
                $seriesId = (int) DocumentHelper::defaultSeriesId($docType);
            }

            if ($seriesId <= 0) {
                throw new RuntimeException("DocumentIssuer: no series given and no default series exists for '{$docType}'.");
            }

            $series = self::lockSeries(db: $db, seriesId: $seriesId, docType: $docType);

            $nowUtc = Factory::getDate('now', 'UTC');
            $localDate = Factory::getDate('now', DocumentHelper::shopTimezone());
            $localYear = (int) $localDate->format('Y', true);

            if ((int) $series->year_scoped === 1 && (int) $series->current_year !== $localYear) {
                // ADOPTING A YEAR IS NOT ROLLING ONE OVER.
                //
                // A series that has never issued has no year to have ended:
                // current_year is NULL, and (int) NULL is 0, which never
                // equals a real year, so this branch used to fire on a FRESH
                // series exactly as on a genuine rollover, and overwrote
                // next_number with 1. That silently discarded the operator's
                // migration seed: the field the admin form lets you edit ONLY
                // while the series has no issued documents (SeriesModel), for
                // exactly one purpose: continuing a shop's legacy numbering.
                // A migration seeded to resume at 5000 restarted at 1.
                //
                // last_issued_at is the honest discriminator. It is stamped by
                // the advance write below, so it is non-NULL precisely when
                // this counter has been drawn before, and a year that has
                // ended is a year that issued something.
                if ($series->last_issued_at !== null) {
                    // New legal year (shop tz): the counter restarts.
                    $series->next_number = 1;
                }

                $series->current_year = $localYear;
            }

            if ($series->last_issued_at !== null && $nowUtc->toSql() < $series->last_issued_at) {
                // Tripwire only: dates are server-assigned so this cannot
                // happen unless the clock moved backwards. Hard abort.
                throw new RuntimeException("DocumentIssuer: clock skew; now precedes series last_issued_at ({$series->last_issued_at}).");
            }

            // 4. Draw the gapless number (frozen format)
            $number = (int) $series->next_number;
            $numberFormatted = DocumentHelper::formatNumber(series: $series, number: $number, localYear: $localYear);

            // 5. Persist header + lines + VAT summary
            // A credit is computed and frozen in the money of the document it
            // corrects; every other type in the order's.
            $docCurrency = \in_array($docType, DocumentHelper::creditTypes(), true) && (int) ($draft['id_corrects'] ?? 0) > 0
                ? DocumentMoney::currencyOf(self::load(db: $db, documentId: (int) $draft['id_corrects']))
                : $orderCurrency;

            $normalizedLines = self::normalizeLines(
                docType:    $docType,
                lines:      $lines,
                currency:   $docCurrency,
                orderId:    $orderId,
                correctsId: (int) ($draft['id_corrects'] ?? 0),
            );
            $totals = self::sumTotals(lines: $normalizedLines);

            $documentId = self::insertHeader(
                db:              $db,
                docType:         $docType,
                draft:           $draft,
                order:           $order,
                series:          $series,
                number:          $number,
                numberFormatted: $numberFormatted,
                issueDateUtc:    $nowUtc->toSql(),
                issueDateLocal:  $localDate->format('Y-m-d', true),
                totals:          $totals,
            );

            foreach ($normalizedLines as $line) {
                $line->id_document = $documentId;
                $db->insertObject('#__alfa_document_items', $line);
            }

            foreach (self::buildTaxSummary(lines: $normalizedLines) as $taxRow) {
                $taxRow->id_document = $documentId;
                $db->insertObject('#__alfa_document_taxes', $taxRow);
            }

            // 6. Advance the series under the same lock
            $advance = new stdClass();
            $advance->id = (int) $series->id;
            $advance->next_number = $number + 1;
            $advance->current_year = $series->current_year;
            $advance->last_issued_at = $nowUtc->toSql();
            $db->updateObject('#__alfa_document_series', $advance, 'id');

            // 7. Order activity trail
            self::logAction(
                db:       $db,
                orderId:  $orderId,
                statusId: (int) $order->id_order_status,
                event:    'document.issued',
                summary:  ucfirst(str_replace('_', ' ', $docType)) . " {$numberFormatted} issued",
                context:  ['id_document' => $documentId, 'doc_type' => $docType, 'number' => $numberFormatted],
                actorId:  (int) ($draft['created_by'] ?? self::currentUserId()),
            );

            // 7b. The credit's shipping and fee choices
            // In the same transaction as the note: a record that cannot be
            // written, or does not reconcile with the lines just stored, rolls
            // the note and its number back. Refused rather than truncated.
            // Nothing is written when no charge was there to decide on.
            if (\in_array($docType, DocumentHelper::creditTypes(), true) && ($draft['credit_charges']['entries'] ?? []) !== []) {
                self::verifyCreditCharges(
                    record:     $draft['credit_charges'],
                    correctsId: (int) $draft['id_corrects'],
                    number:     $numberFormatted,
                );

                self::logAction(
                    db:             $db,
                    orderId:        $orderId,
                    statusId:       (int) $order->id_order_status,
                    event:          'document.credit_charges',
                    summary:        ucfirst(str_replace('_', ' ', $docType)) . " {$numberFormatted}: shipping and fee choices recorded (corrects {$draft['credit_charges']['corrected']})",
                    context:        ['id_document' => $documentId, 'number' => $numberFormatted] + $draft['credit_charges'],
                    actorId:        (int) ($draft['created_by'] ?? self::currentUserId()),
                    refuseOversize: true,
                );
            }

            // THE OBLIGATION IS COMMITTED WITH THE FACT THAT REQUIRES IT.
            //
            // The stock effect used to exist only as the next statement after
            // this commit, so a crash in that window lost the movement for
            // good. Recording it here makes it survive the process: if the
            // document rolls back the obligation rolls back with it, and if the
            // document lands the obligation lands too and is drained until the
            // movement exists.
            if ($applyStockEffect && DocumentHelper::stockEffectForDocument($docType, !empty($draft['is_combined'])) !== DocumentHelper::STOCK_EFFECT_NONE) {
                InventoryEffectOutbox::enqueue(
                    sourceType: 'document',
                    sourceId:   $documentId,
                    orderId:    $orderId,
                    payload:    $docType,
                );
            }

            if ($manageTransaction) {
                TransactionState::commitLevel($db);
            }
        } catch (Throwable $e) {
            if ($manageTransaction) {
                TransactionState::rollBackLevel(db: $db, failure: $e);
            }

            throw new RuntimeException('DocumentIssuer: issue failed: ' . $e->getMessage(), 0, $e);
        }

        $document = self::load(db: $db, documentId: $documentId);

        // EVERYTHING BELOW NEEDS THE DOCUMENT TO BE DURABLE. With our own
        // transaction at the top level it already is; inside a caller's
        // transaction it is not yet, and PostCommit holds this until the
        // outermost COMMIT (and drops it on a rollback) so nothing is
        // announced or drained for a document that may never be.
        PostCommit::defer(db: $db, label: "DocumentIssuer: after issuing document #{$documentId}", work: static function () use ($db, $documentId, $orderId, $applyStockEffect, $numberFormatted): void {
            $document = self::load(db: $db, documentId: $documentId);

            // The document now exists, so the auto-issue alerts waiting on it are
            // answered (those of its type: AutoIssuePolicy::clearFor()). This is
            // the ONE write path: auto-issue on a status change and a manual issue
            // from the order both land here, so retracting here also covers a
            // merchant who fixed it by hand. Without it the warning stood for ever
            // on an order documented minutes later.
            AutoIssuePolicy::clearFor(orderId: $orderId, docType: (string) ($document->doc_type ?? ''));

            // Stock effect of the issued type: through the kernel's single
            // fulfilment entry, anchored on the REAL document id (idempotent:
            // retries/reissues no-op).
            //
            // WHY THIS IS NOT INSIDE THE TRANSACTION. A fiscal document that has
            // taken a number is authoritative the moment it commits: it has been
            // reported and handed to a customer, and an inventory problem must not
            // be able to un-issue it. But the effect it requires travels through
            // `alfa-inventory` plugin events into third-party code, which can fail
            // or be slow for reasons that have nothing to do with the document.
            //
            // Those two facts together are exactly why the obligation is written
            // durably above rather than the effect being made atomic here: the
            // fiscal fact commits alone, and what it owes outlives the process
            // that owed it.
            if ($applyStockEffect) {
                // Drain THIS document's own obligation now, so the happy path is
                // unchanged and the effect is applied in the same request. A
                // failure here is no longer a loss: the obligation stays in the
                // outbox, and the notification centre sends the merchant to
                // Tools -> Database Sync to finish it.
                //
                // drain() does NOT throw on a failed movement: it records it and
                // reports it in the summary, so the summary is what is checked. The
                // catch is for the outbox itself being unreachable.
                try {
                    $drained = InventoryEffectOutbox::drain(
                        limit:          1,
                        onlySourceId:   $documentId,
                        onlySourceType: 'document',
                    );

                    // A refusal, or a movement waiting on stock other claims reserve, already raised
                    // this document's notice; only another retryable failure points at Tools.
                    if (($drained['failed'] ?? 0) > 0
                        && !isset($drained['refusals'][$documentId])
                        && !isset($drained['reservedFloor'][$documentId])
                    ) {
                        self::reportStockDeferred(
                            documentId: $documentId,
                            number:     (string) $numberFormatted,
                            reason:     (string) ($drained['errors'][$documentId] ?? 'unknown'),
                            notice:     $drained['failureNotices'][$documentId] ?? null,
                        );
                    }
                } catch (Throwable $e) {
                    self::reportStockDeferred(
                        documentId: $documentId,
                        number:     (string) $numberFormatted,
                        reason:     $e->getMessage(),
                    );
                }
            } else {
                // Auditable: "why did this credit note move no stock?" must be
                // answerable from the log alone, months later.
                self::logQuietly(
                    entry:    "Document #{$documentId}: stock effect skipped; the issuing caller already moved these goods.",
                    priority: Log::INFO,
                    category: 'com_alfa.inventory',
                );
            }

            // Post-commit, non-blocking by contract: a listener failure can
            // never affect the already-committed document.
            self::dispatch(name: 'onDocumentIssued', event: new DocumentIssuedEvent('onDocumentIssued', [
                'document' => $document,
                'lines' => self::loadLines(db: $db, documentId: $documentId),
                'orderId' => $orderId,
            ]));
        });

        return $document;
    }

    /**
     * Preview a draft: what this document WOULD be, without issuing it.
     *
     * The document reconciliation contract, section 6: "Preview must
     * execute the same canonical domain calculation used by final issuance,
     * without persisting or consuming pools", and section 19: there must never be a
     * proposal calculator and a manual calculator as separate financial models.
     *
     * THIS METHOD LIVES ON THE ISSUER FOR THAT REASON. It does not re-implement
     * anything: it runs the SAME validateDraft(), the SAME over-issue and charge
     * guards, the SAME currency resolution, the SAME normalizeLines() and the
     * SAME sumTotals() that issue() runs, and then stops. A preview that agreed
     * with a second implementation instead of with the issuer would be worth
     * nothing.
     *
     * WHAT IT DELIBERATELY DOES NOT DO, and why:
     *
     *  - NO TRANSACTION AND NO LOCK. issue() takes the order lock to serialize
     *    document operations; a read-only answer must not hold a write lock
     *    while an operator reads it. The consequence is honest and stated here:
     *    a preview is a snapshot, and issue() re-runs every guard under the
     *    lock, so a preview can never be the thing that authorises a write.
     *  - NO SERIES LOCK AND NO NUMBER. A previewed document has no number
     *    because drawing one burns it; `number_formatted` is null on purpose
     *    rather than a guess the operator might read as real.
     *  - NO STOCK VALIDATION. validateForIssue() is a dry run already, but it
     *    belongs to issuing; section 12 keeps fiscal reconciliation and physical stock
     *    separate, and a preview that reported a stock veto as a money problem
     *    would blur exactly that line.
     *  - NO EVENTS. A subscriber may amend a draft or veto on the real issue;
     *    firing that on a preview would let a plugin act on something that has
     *    not happened.
     *
     * A REFUSAL IS AN ANSWER, NOT AN ERROR. Every guard states its own reason in
     * its own words, so an invalid draft comes back with `valid = false` and the
     * issuer's verbatim message, which is what section 5 needs to offer the operator
     * only strategies that are currently valid, with the reason when they are
     * not. THIS METHOD THROWS NOTHING for anything the issuer would refuse,
     * including the three checks issue() makes before its transaction opens
     * (unknown type, missing id_order, no lines) and a missing order.
     *
     * @param array $draft The same draft shape issue() takes.
     *
     * @return object {valid: bool, reason: string, lines: stdClass[],
     *                totals: object, tax_summary: stdClass[],
     *                currency_code: string, currency_scale: int,
     *                number_formatted: null, buyer: array (buyerBlock(), since 2.0.0),
     *                refusal_key: string, refusal_values: array (the refusal's
     *                operator words, '' / [] when valid or when it carries none; since 2.0.0)}
     *
     * @since  2.0.0
     */
    public static function preview(array $draft): object
    {
        $docType = (string) ($draft['doc_type'] ?? '');
        $orderId = (int) ($draft['id_order'] ?? 0);
        $lines = $draft['lines'] ?? [];

        $answer = static fn (
            bool $valid,
            string $reason,
            ?object $currency = null,
            array $normalized = [],
            ?object $totals = null,
            string $refusalKey = '',
            array $refusalValues = [],
        ): object => (object) [
            'valid' => $valid,
            'reason' => $reason,
            'lines' => $normalized,
            'totals' => $totals ?? (object) ['excl' => 0.0, 'tax' => 0.0, 'incl' => 0.0],
            'tax_summary' => $normalized === [] ? [] : self::buildTaxSummary(lines: $normalized),
            'currency_code' => $currency?->getCode() ?? '',
            'currency_scale' => $currency?->getDecimalPlaces(),
            'number_formatted' => null,
            // As the header will freeze it, shown on a refusal too, so a
            // buyer the issuer refuses can be read and corrected.
            'buyer' => self::buyerBlock($draft),
            'refusal_key' => $refusalKey,
            'refusal_values' => $refusalValues,
        ];

        // EVERY refusal the issuer would make comes back as an ANSWER, in the
        // issuer's own words, including the three checks issue() makes before
        // it opens its transaction. section 5 needs a reason for each strategy it
        // cannot offer; a thrown exception is not a reason an operator reads.
        if (!\in_array($docType, DocumentHelper::types(), true)) {
            return $answer(false, "DocumentIssuer: unknown doc_type '{$docType}'.");
        }

        if ($orderId <= 0) {
            return $answer(false, 'DocumentIssuer: a draft needs id_order.');
        }

        if (!\is_array($lines) || $lines === []) {
            return $answer(false, 'DocumentIssuer: a document needs at least one line.');
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // Read, never FOR UPDATE: see the lock note above.
        $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = :orderId')
                ->bind(':orderId', $orderId, ParameterType::INTEGER),
        );
        $order = $db->loadObject();

        if (!$order) {
            // lockOrder() says the same thing at the same point in issue().
            return $answer(false, "DocumentIssuer: order #{$orderId} not found.");
        }

        // An order that names no currency previews nothing, in the words
        // issue() refuses it with.
        try {
            $currency = self::documentCurrency(order: $order);
        } catch (DocumentRefusedException $e) {
            return $answer(false, $e->getMessage(), null, refusalKey: $e->key(), refusalValues: $e->values());
        }

        try {
            // A credit previews in its corrected document's money, as issue()
            // freezes it. A refusal raised before that is known keeps
            // the order's.
            if (\in_array($docType, DocumentHelper::creditTypes(), true) && (int) ($draft['id_corrects'] ?? 0) > 0) {
                $currency = DocumentMoney::currencyOf(self::load(db: $db, documentId: (int) $draft['id_corrects']));
            }

            // The same two calls issue() makes, in the same order. The charge
            // guards are NOT invoked separately: guardQuantities() chains into
            // guardCharges(), which chains into guardCreditCharges(). Calling
            // them again here would double every charge query and, worse, would
            // be a second call shape that could drift from the issuer's.
            self::validateDraft(docType: $docType, draft: $draft, lines: $lines);
            self::guardQuantities(db: $db, docType: $docType, orderId: $orderId, lines: $lines, draft: $draft);
            self::guardReplacement(db: $db, docType: $docType, orderId: $orderId, draft: $draft);

            $normalized = self::normalizeLines(
                docType:    $docType,
                lines:      $lines,
                currency:   $currency,
                orderId:    $orderId,
                correctsId: (int) ($draft['id_corrects'] ?? 0),
            );
        } catch (Throwable $e) {
            // The issuer's own words, unchanged, and the operator's words
            // where the refusal carries them.
            $refusal = DocumentRefusedException::in($e);

            return $answer(false, $e->getMessage(), $currency, refusalKey: $refusal?->key() ?? '', refusalValues: $refusal?->values() ?? []);
        }

        return $answer(true, '', $currency, $normalized, self::sumTotals(lines: $normalized));
    }

    /**
     * Void an issued document. The number is kept (gapless invariant), the
     * content is untouched: only status + void audit change. There is no
     * reverse transition.
     *
     * @param int $documentId The document to void.
     * @param string $reason Mandatory human reason (audit).
     * @param int|null $actorId Acting admin; null = current identity.
     * @param bool $manageTransaction Own the transaction (default true).
     *
     * @return object The voided document row (fresh read).
     *
     * @throws RuntimeException When the document is not issued, is referenced
     *                          by non-voided correctives, or the write fails;
     *                          and when its order names no currency, in which
     *                          case the chain carries a DocumentRefusedException
     *                          with the operator's sentence.
     *
     * @since  2.0.0
     */
    public static function void(int $documentId, string $reason, ?int $actorId = null, bool $manageTransaction = true): object
    {
        if (trim($reason) === '') {
            throw new RuntimeException('DocumentIssuer: a void requires a reason.');
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $actorId ??= self::currentUserId();

        // Order before document, and this read before the transaction
        // The lock order was the reverse (document, then order), which is the
        // exact opposite of CustomerCreditLedger::applyCreditNote(): an ABBA
        // pair that deadlocks the moment a void races a wallet credit. ORDER ->
        // DOCUMENT -> ACCOUNT is the one hierarchy every money path follows, so
        // this is the path that bends.
        //
        // But finding the order to lock costs a read, and that read MUST NOT
        // happen inside the transaction. Under REPEATABLE READ the first
        // CONSISTENT (non-locking) read is what creates the transaction's
        // snapshot; a locking read does not. Open with a plain SELECT and the
        // snapshot is pinned BEFORE the order lock is acquired, so every plain
        // read afterwards (the corrective-reference COUNT, creditNoteNet(),
        // the walletUser lookup) is served from a pre-lock view. A racer that
        // committed while we queued on the lock is then invisible:
        // creditNoteNet() returns 0.00, no clawback is written, and a voided
        // note leaves its wallet money behind permanently.
        //
        // Reading id_order out here is safe on its own terms: a document never
        // changes the order it belongs to. The authoritative document read is
        // the LOCKING one below, and that is what the status guard judges.
        $orderId = (int) self::load(db: $db, documentId: $documentId)->id_order;

        // The wallet counter-entry's COMMITTED line, when this void writes one.
        // The ledger's own line, written inside this transaction, says only
        // "staged"; this one is written by the post-commit tail below, so it
        // exists only for a reversal that became durable.
        $reversalLine = '';

        if ($manageTransaction) {
            $db->transactionStart(true);
        }

        try {
            // The FIRST statement in the envelope is a locking read, so no
            // snapshot exists until after the serialization point.
            $order = self::lockOrder(db: $db, orderId: $orderId);

            // A void is a command on the order's money: it withdraws what the
            // document claimed and can take wallet money back. An order that
            // names no currency is voided nothing. Read under the lock, as
            // issue() reads it, before anything else is read or written.
            self::documentCurrency(order: $order);

            $document = self::load(db: $db, documentId: $documentId, forUpdate: true);

            if ((int) $document->status !== DocumentHelper::STATUS_ISSUED) {
                throw new RuntimeException("document #{$documentId} is not issued; only issued documents can be voided.");
            }

            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_documents'))
                ->where([
                    $db->quoteName('id_corrects') . ' = :docId',
                    $db->quoteName('status') . ' != ' . DocumentHelper::STATUS_VOIDED,
                ])
                ->bind(':docId', $documentId);
            $db->setQuery(query: $query);

            if ((int) $db->loadResult() > 0) {
                throw new RuntimeException("document #{$documentId} has non-voided corrective documents; void those first.");
            }

            // A wallet-credited credit note must take its money back when
            // voided; otherwise void-and-reissue mints unlimited credit
            // (the pool refills, every guard passes, +amount per cycle).
            // Counter-entry inside THIS transaction; if the customer already
            // spent the credit, the insufficient-balance guard blocks the
            // void until an operator reclaims it manually.
            if (\in_array((string) $document->doc_type, DocumentHelper::creditTypes(), true)) {
                // THE AMOUNT IS READ FROM THE WALLET LEDGER, NOT FROM THE
                // DOCUMENT. What has to come back is what the wallet actually
                // received for this note: the net of its own credit_note
                // transactions, at the WALLET's scale, which is a different
                // authority from the document's own frozen scale. Taking the
                // document's total instead would claw back a figure the wallet
                // may never have been given.
                //
                // Asked ONCE and reused: the predicate below and the amount
                // handed to the ledger are the SAME Money, or a note could pass
                // the "is there anything to take back" test and then have a
                // different sum taken from it.
                $walletNetMoney = CustomerCreditLedger::creditNoteNetMoney((int) $documentId);
                $walletNet = $walletNetMoney->getAmount();

                if ($walletNetMoney->isPositive()) {
                    $db->setQuery(
                        'SELECT ' . $db->quoteName('id_user')
                        . ' FROM ' . $db->quoteName('#__alfa_customer_credit_transactions')
                        . ' WHERE ' . $db->quoteName('source_type') . ' = ' . $db->quote('credit_note')
                        . ' AND ' . $db->quoteName('source_id') . ' = ' . (int) $documentId
                        . ' ORDER BY ' . $db->quoteName('id') . ' ASC',
                    );
                    $walletUser = (int) $db->loadResult();

                    try {
                        $reversal = CustomerCreditLedger::reverseCreditNote(
                            documentId:      (int) $documentId,
                            numberFormatted: (string) $document->number_formatted,
                            userId:          $walletUser,
                            amount:          $walletNet,
                            actorId:         $actorId,
                        );
                    } catch (Throwable $e) {
                        throw new RuntimeException(
                            "document #{$documentId} credited {$walletNet} to customer #{$walletUser}'s wallet and it cannot be "
                            . 'taken back (' . $e->getMessage() . '); reclaim the credit manually, then void.',
                        );
                    }

                    // The ledger's figures, in the wallet's own money.
                    $reversalLine = sprintf(
                        'Customer credit debit committed: user %d, %s (credit_note #%d), balance %s; the reversal of voided credit note %s.',
                        $walletUser,
                        Money::of((float) $reversal->amount, $walletNetMoney->getCurrency())->format(false),
                        $documentId,
                        Money::of((float) $reversal->balance_after, $walletNetMoney->getCurrency())->format(false),
                        (string) $document->number_formatted,
                    );
                }
            }

            // A void withdraws every claim the document made, and no
            //    Fact it merely recorded
            // Stated as "a voided document has zero active stock effect" this
            // read as a rule about all stock effects. It is not, and the
            // difference is which way the document faces:
            //
            //   RESTORE-effect (a credit note) CLAIMS the goods came back.
            //   Nothing else asserts that claim, so only the void can
            //   withdraw it. Leave it standing and void+reissue shelves the
            //   same units twice: issue 3 (+3), void (nothing), reissue 3
            //   (+2 more, clamped by what is still out) = +5 for three units.
            //   Hence the reversal below.
            //
            //   DEDUCT-effect (a delivery note) RECORDS that goods went out.
            //   The parcel left the building; correcting the paperwork does
            //   not bring it back, and the outstanding balance was never the
            //   document's to hold: sourceLineState() matches movements by
            //   LINE precisely so a document-triggered fulfilment belongs to
            //   the order. The sanctioned reversal is the order lifecycle
            //   (restoreOrder/syncOrderLines, driven by the status policy),
            //   which is why reverseRestores() is the only thing called here
            //   and deduct rows are deliberately left alone.
            //
            // The money clawback above is the same principle: withdraw the
            // claim, never rewrite history.
            //
            // INSIDE this transaction, and therefore inside the FOR UPDATE on
            // the document row plus the status guard above: two concurrent
            // voids serialise, the loser sees VOIDED and is refused, so the
            // reversal cannot be applied twice. reverseRestores() is also
            // idempotent by arithmetic (it reverses the NET of this source's
            // restore rows), so a repeat finds nothing left even if a future
            // caller bypasses that guard.
            //
            // A failure here ABORTS the void: leaving a document voided while
            // its units stayed on the shelf is the state this exists to
            // prevent. If the goods have already been re-sold the physical
            // guard refuses, and the operator sees why.
            try {
                // Journalled to void()'s actor: the explicit $actorId (the API
                // passes one), else the current identity. Never
                // InventoryContext::ambient(), which would drop an explicit actor.
                (new InventoryService())->reverseRestores(
                    sourceType: 'document',
                    sourceId:   $documentId,
                    context:    new InventoryContext(userId: $actorId, note: 'Void of document #' . $documentId),
                );
            } catch (Throwable $e) {
                throw new RuntimeException(
                    "document #{$documentId} put stock back when it was issued and that cannot be undone ("
                    . $e->getMessage() . '): the units may already be sold; correct stock manually, then void.',
                );
            }

            $void = new stdClass();
            $void->id = $documentId;
            $void->status = DocumentHelper::STATUS_VOIDED;
            $void->voided_at = Factory::getDate('now', 'UTC')->toSql();
            $void->voided_by = $actorId;
            $void->void_reason = mb_substr(trim($reason), 0, 500);
            $void->modified = $void->voided_at;
            $void->modified_by = $actorId;
            $db->updateObject('#__alfa_documents', $void, 'id');

            self::logAction(
                db:       $db,
                orderId:  (int) $document->id_order,
                statusId: 0,
                event:    'document.voided',
                summary:  ucfirst(str_replace('_', ' ', (string) $document->doc_type))
                          . " {$document->number_formatted} voided: " . mb_substr(trim($reason), 0, 120),
                context:  ['id_document' => $documentId, 'reason' => trim($reason)],
                actorId:  $actorId,
            );

            if ($manageTransaction) {
                TransactionState::commitLevel($db);
            }
        } catch (Throwable $e) {
            if ($manageTransaction) {
                TransactionState::rollBackLevel(db: $db, failure: $e);
            }

            throw new RuntimeException('DocumentIssuer: void failed: ' . $e->getMessage(), 0, $e);
        }

        $document = self::load(db: $db, documentId: $documentId);

        // Announced only once the void is DURABLE: after the outermost COMMIT.
        // A void a failing replacement or a caller's rollback takes back must
        // never have been told to a listener: its docblock names a
        // tax-authority cancellation transmission as the intended consumer.
        PostCommit::defer(db: $db, label: "DocumentIssuer: after voiding document #{$documentId}", work: static function () use ($db, $documentId, $reason, $actorId, $reversalLine): void {
            if ($reversalLine !== '') {
                AlfaLog::register();
                self::logQuietly(entry: $reversalLine, priority: Log::INFO, category: 'com_alfa.credit');
            }

            self::dispatch(name: 'onDocumentVoided', event: new DocumentVoidedEvent('onDocumentVoided', [
                'document' => self::load(db: $db, documentId: $documentId),
                'reason' => trim($reason),
                'actorId' => $actorId,
            ]));
        });

        return $document;
    }

    /**
     * Run a caller's issuing work in ONE transaction, and its tails after the COMMIT.
     *
     * A caller that writes its own rows beside a document (a returns workflow
     * linking a credit note to its return, for example) holds the transaction
     * and calls issue() or void() with manageTransaction: false. The caller's
     * own transaction already holds their post-commit tails (onDocumentIssued /
     * onDocumentVoided, the stock drain, the auto-issue alert withdrawal, the
     * "stock effect skipped" line) until its outermost COMMIT (PostCommit). The
     * envelope adds the one level the work runs in, and refuses nesting by
     * name.
     *
     * This does for a caller's work what replace() does for its own:
     *  1. opens the transaction; every tail issue() and void() defer waits
     *     for its COMMIT (PostCommit);
     *  2. runs the work, then COMMITs;
     *  3. the tails run once each, in the order they were deferred, after the
     *     outermost COMMIT. A tail that throws is logged at ERROR and cannot
     *     undo the committed work; the tails after it still run;
     *  4. on any failure in the work or at COMMIT, drops every deferred tail,
     *     rolls back, and rethrows the work's own throwable unchanged.
     *
     * The work issues from drafts asked before this was called
     * (beforeIssue()): no document hook runs inside the transaction.
     *
     * It writes no file line about the work: only the caller knows what the
     * work was, so the caller writes its "committed" line after this returns
     * and its "rolled back" line after catching the failure.
     *
     * NOT NESTABLE, either way: not inside another envelope, not inside
     * replace(), and replace() refuses to run inside one. Inside a caller's
     * own transaction its COMMIT is a savepoint release, and the tails wait for
     * the caller's outermost COMMIT.
     *
     * The work's first statement should be a locking read, in the caller's
     * own lock order: under REPEATABLE READ the first consistent read inside
     * the transaction fixes its snapshot.
     *
     * @param callable $work fn(): mixed, the caller's issuing work.
     *
     * @return mixed What the work returned.
     *
     * @throws RuntimeException When nested.
     * @throws Throwable The work's own failure, after the rollback.
     *
     * @since   2.0.0
     */
    public static function envelope(callable $work): mixed
    {
        if (self::$enveloped) {
            throw new RuntimeException('DocumentIssuer: an issuing envelope cannot run inside another issuing envelope.');
        }

        if (self::$replaceOpen) {
            throw new RuntimeException('DocumentIssuer: an issuing envelope cannot run inside Void & Replace.');
        }

        AlfaLog::register();

        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->transactionStart(true);
        self::$enveloped = true;

        try {
            $result = $work();

            // Let go before the COMMIT: the tails it runs may issue again.
            self::$enveloped = false;
            TransactionState::commitLevel($db);
        } catch (Throwable $e) {
            // Nothing deferred is sent: what it would announce never happened.
            self::$enveloped = false;
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        return $result;
    }

    /**
     * Void a document and issue its replacement as ONE coordinated act.
     *
     * The document reconciliation contract, section 14: "Do not
     * implement Void & Replace as two unrelated UI button calls that can leave
     * the system halfway through accidentally. Treat it as one explicit
     * coordinated domain workflow." The only observable results are therefore
     * two: the source still ACTIVE with no replacement, or the source VOIDED,
     * the replacement ISSUED and the replacement naming the source in
     * `id_replaces`. Every step below runs inside one transaction, and nothing
     * leaves it (no event, no stock drain, no alert) until it commits.
     *
     * WHY IT LIVES ON THE ISSUER. It needs three things only the issuer can do:
     * take the order lock as the envelope's FIRST statement, hold its own
     * void()/issue() post-commit tails until the real commit, and be the one
     * place `id_replaces` is allowed to be written.
     *
     * THE STEPS, in order:
     *  1. the permission, at this boundary: `documents.replace`, never the
     *     issuing pair;
     *  2. the ORDER lock, then the source FOR UPDATE: ORDER -> DOCUMENT, the
     *     hierarchy every money path follows;
     *  3. every precondition RE-CHECKED under that lock (the screen's answer was
     *     a snapshot): issued, not stock-moving at the DOCUMENT level, no live
     *     corrective, not settled by a refund, its wallet credit still there to
     *     take back;
     *  4. the replacement draft, built against the post-void view WITHOUT
     *     voiding (withSourceReleased), and refused unless it matches the
     *     preview the operator confirmed: nothing is recomputed after
     *     confirmation by a second path. The builder's own refusals, UNAVAILABLE
     *     of a credit replacement included, are asked again here under the lock.
     *     It is built the same way once BEFORE the transaction, and that draft
     *     is the one onDocumentBeforeIssue is asked about, before any
     *     transaction opens; the draft built under the lock must equal it;
     *  5. the void, then the caller's own check, if it handed one; 6. the
     *     issue of the answered draft; 7. a verification of what actually
     *     landed; 8. one audit row for the relationship; 9. COMMIT; 10. the
     *     committed line, then the tails, in the order they happened.
     *
     * A CREDIT NOTE'S REPLACEMENT GOES WHERE ITS SOURCE WENT. A replacement
     * restates the credit its source gave, so it is numbered with the
     * destination the source was frozen with, read from the source row locked
     * here, whatever the draft builder names. One sent to the customer's
     * credit account therefore holds its amount from the moment it is numbered,
     * as the source did until this act voided it; paying it in is the caller's
     * step, after the COMMIT. A source with no destination gives its
     * replacement none.
     *
     * THE CALLER'S LAST WORD BEFORE A NUMBER IS DRAWN. What the money of a
     * replacement needs is not this class's to judge. A caller that has such a
     * rule hands a check, and it is asked once the source is voided and before
     * the replacement is numbered, inside the transaction and under the order
     * lock, so it meets the order as the replacement will: without what the
     * source held. A check that refuses throws, and the whole act rolls back:
     * the source stays issued and no number is used.
     *
     * NOT NESTABLE, and not inside envelope(). Not inside a caller's
     * transaction either: onDocumentBeforeIssue is asked before any
     * transaction opens, so a call with one open is refused and nothing is
     * changed.
     *
     * REFUSALS. Every refusal it states is a DocumentRefusedException: staff
     * read its operator sentence, naming the document, and the exception's
     * message stays the technical one the logs record. A preview refused in
     * operator words keeps them.
     *
     * FILE LOGS. The audit rows are database rows in
     * the envelope and stay the authority. File lines are not transactional,
     * so none written before COMMIT may state a success: the "committed" line
     * is written after it, and a failure writes a "rolled back" line after the
     * rollback. Every file line on those paths is written quietly: a log that
     * cannot be written never masks the failure, nor reports a committed act
     * as failed.
     *
     * @param int $sourceId The document being replaced.
     * @param string $reason The void reason (audit, required).
     * @param callable $draftBuilder fn(): ?array - the ONE way the replacement draft is built.
     * @param string $expectedFingerprint draftFingerprint() of the preview the operator confirmed.
     * @param int|null $actorId Acting admin; null = current identity.
     * @param callable|null $beforeNumbering fn(object $source, object $preview): void - the caller's own check, asked once the source is voided and before the replacement is numbered; it refuses by throwing.
     *
     * @return object The replacement document row, re-read after commit.
     *
     * @since   2.0.0
     */
    public static function replace(
        int $sourceId,
        string $reason,
        callable $draftBuilder,
        string $expectedFingerprint,
        ?int $actorId = null,
        ?callable $beforeNumbering = null,
    ): object {
        AlfaLog::register();

        // Every refusal below carries the operator's sentence beside its
        // technical message, which the logs and the rolled-back line keep.
        if (trim($reason) === '') {
            throw new DocumentRefusedException(message: 'DocumentIssuer: a void requires a reason.', key: 'COM_ALFA_DOCUMENTS_VOID_NEEDS_REASON');
        }

        if (self::$enveloped) {
            throw new DocumentRefusedException(
                message: 'DocumentIssuer: Void & Replace cannot run inside an issuing envelope.',
                key:     'COM_ALFA_REPLACEMENT_REFUSED_NESTED',
            );
        }

        if (self::$replaceOpen) {
            throw new DocumentRefusedException(
                message: 'DocumentIssuer: Void & Replace cannot run inside another Void & Replace.',
                key:     'COM_ALFA_REPLACEMENT_REFUSED_NESTED',
            );
        }

        $actorId ??= self::currentUserId();
        $actor = Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($actorId);

        // 1. The service boundary
        // A composite destructive financial act answers to its own capability.
        // Holding Orders: Edit together with Sales Documents: Create is what
        // authorises ordinary issuance, and it deliberately does NOT reach here.
        if (!AlfaHelper::can(section: 'documents', action: 'replace', user: $actor)) {
            throw new DocumentRefusedException(
                message: 'DocumentIssuer: Void & Replace requires the Void & Replace Sales Documents permission '
                    . '(documents.replace); the rights to issue or to void do not include it.',
                key:     'COM_ALFA_REPLACEMENT_REFUSED_PERMISSION',
            );
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // Read OUTSIDE the envelope: under REPEATABLE READ the first
        // consistent read inside a transaction fixes its snapshot, and it must
        // not be fixed before the order lock is held.
        $unlocked = self::load(db: $db, documentId: $sourceId);
        $orderId = (int) $unlocked->id_order;

        $source = null;
        $replacement = null;
        $opened = false;

        try {
            // The act is this one's from here: a builder or a subscriber that
            // starts an envelope or another Void & Replace is refused by name.
            self::$replaceOpen = true;

            // 4a. The replacement, asked about BEFORE the transaction
            // Built as it will be built under the lock, and judged the same
            // way. onDocumentBeforeIssue is asked about it now, with no
            // transaction open, so a subscriber cannot commit the void below
            // or a number this act later refuses.
            self::guardReplaceable(db: $db, source: $unlocked);
            $outside = self::previewReplacement(sourceId: $sourceId, draftBuilder: $draftBuilder);
            self::judgeReplacement(built: $outside, source: $unlocked, expectedFingerprint: $expectedFingerprint);
            $base = ['credit_destination' => $unlocked->credit_destination] + $outside->draft + ['created_by' => $actorId];
            $answered = self::beforeIssue(draft: $base);

            $db->transactionStart(true);
            $opened = true;

            // The first work deferred inside the act: once it is durable, the
            // committed line is written before the tails it holds.
            PostCommit::defer(db: $db, label: 'DocumentIssuer: the Void & Replace committed line', work: static function () use (&$source, &$replacement, $sourceId): void {
                self::logQuietly(
                    entry:    'DocumentIssuer: Void & Replace committed: ' . self::describe($source, $sourceId)
                              . ' voided and replaced by ' . self::describe($replacement, (int) ($replacement->id ?? 0)) . '.',
                    priority: Log::INFO,
                    category: 'com_alfa.documents',
                );
            });

            // 2. ORDER first, as the envelope's first statement
            self::lockOrder(db: $db, orderId: $orderId);
            $source = self::load(db: $db, documentId: $sourceId, forUpdate: true);

            // 3. Every precondition, again, under the lock
            self::guardReplaceable(db: $db, source: $source);

            // 4. The draft, built again under the lock, before anything is mutated
            $built = self::withSourceReleased(db: $db, sourceId: $sourceId, draftBuilder: $draftBuilder);
            self::judgeReplacement(built: $built, source: $source, expectedFingerprint: $expectedFingerprint);

            // The draft onDocumentBeforeIssue was asked about must still be the
            // one the locked state gives.
            if ((['credit_destination' => $source->credit_destination] + $built->draft + ['created_by' => $actorId]) !== $base) {
                throw new DocumentRefusedException(
                    message: 'the replacement no longer matches the preview that was confirmed: the order or its '
                        . 'documents changed since. Review the replacement again before issuing it.',
                    key:     'COM_ALFA_REPLACEMENT_REFUSED_PREVIEW_CHANGED',
                    values:  [(string) $source->number_formatted],
                );
            }

            // 5. The void: its announcement is HELD, not sent
            self::void(documentId: $sourceId, reason: $reason, actorId: $actorId, manageTransaction: false);

            // The caller's own check, with the source voided and no number
            // drawn: a refusal here still undoes everything.
            if ($beforeNumbering !== null) {
                $beforeNumbering($source, $built->preview);
            }

            // 6. The replacement: the answered draft, carrying provenance
            // And the source's frozen destination, first so that it stands
            // whatever the builder named: a replacement restates the same
            // credit, so its money goes where the source's went.
            self::$replacing = $sourceId;

            try {
                $replacement = self::issue(draft: $answered, manageTransaction: false);
            } finally {
                self::$replacing = 0;
            }

            // 7. What actually landed, before it is allowed to stay
            // An onDocumentBeforeIssue subscriber may amend a draft; one that
            // dropped the provenance, changed the type, made the replacement
            // move stock, changed its money, rewrote its buyer or changed
            // where its credit is sent is refused here, in the transaction,
            // where refusing still undoes everything.
            self::verifyReplacement(
                db:          $db,
                source:      $source,
                replacement: self::load(db: $db, documentId: (int) $replacement->id),
                preview:     $built->preview,
                buyer:       self::buyerBlock($built->draft),
            );

            // 8. The relationship, audited on its own (section 20)
            self::logAction(
                db:       $db,
                orderId:  $orderId,
                statusId: 0,
                event:    'document.replaced',
                summary:  ucfirst(str_replace('_', ' ', (string) $source->doc_type))
                          . " {$source->number_formatted} replaced by {$replacement->number_formatted}: "
                          . mb_substr(trim($reason), 0, 120),
                context:  [
                    'id_document' => (int) $replacement->id,
                    'replaces' => $sourceId,
                    'strategy' => 'void_and_replace',
                    'reason' => trim($reason),
                    'previewed' => $expectedFingerprint,
                ],
                actorId:  $actorId,
            );

            // 9. Durable; then the committed line and the tails
            // Let go before the COMMIT: the tails it runs may act again.
            self::$replaceOpen = false;
            TransactionState::commitLevel($db);
        } catch (Throwable $e) {
            // Nothing deferred is sent: what it would announce never happened.
            self::$replaceOpen = false;
            self::$replacing = 0;

            if ($opened) {
                TransactionState::rollBackLevel(db: $db, failure: $e);
            }

            // Written AFTER the rollback, so it states what is now true. A
            // file line written inside the envelope may only ever have said
            // "staged"; this is the line that tells a reader it went nowhere.
            self::logQuietly(
                entry:    'DocumentIssuer: Void & Replace of ' . self::describe($source ?? $unlocked, $sourceId)
                          . ' rolled back; nothing was voided or issued: ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.documents',
            );

            throw new RuntimeException('DocumentIssuer: void and replace failed: ' . $e->getMessage(), 0, $e);
        }

        return self::load(db: $db, documentId: (int) $replacement->id);
    }

    /**
     * What the replacement WOULD be, previewed against the post-void view without voiding.
     *
     * Every pool in the component counts ISSUED documents only, so "as if the
     * source were voided" is, for all arithmetic, one row's status. This takes
     * the order lock (ORDER -> DOCUMENT, the hierarchy every money path
     * follows), flips that status inside a savepoint, builds the draft with the
     * real builders and previews it with the real guards, and rolls back.
     * Nothing it touched survives, and because it calls neither void() nor
     * issue(), no event, wallet entry, stock movement or number is produced.
     *
     * @param int $sourceId The document that would be replaced.
     * @param callable $draftBuilder fn(): ?array - the same builder replace() uses.
     *
     * @return object  {draft: ?array, preview: ?object}
     *
     * @since   2.0.0
     */
    public static function previewReplacement(int $sourceId, callable $draftBuilder): object
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $orderId = (int) self::load(db: $db, documentId: $sourceId)->id_order;

        $db->transactionStart(true);

        try {
            self::lockOrder(db: $db, orderId: $orderId);

            return self::withSourceReleased(db: $db, sourceId: $sourceId, draftBuilder: $draftBuilder);
        } finally {
            // Always: a preview persists nothing and consumes nothing.
            self::rollbackQuietly($db);
        }
    }

    /**
     * Build and preview the replacement draft with the source's claims released, then undo that.
     *
     * The savepoint is always rolled back. If that rollback had to escalate to
     * a full ROLLBACK, the caller's transaction (and its order lock) is gone,
     * and replace() must not go on to void and issue in autocommit: that is
     * refused here, unless an exception is already on its way out.
     *
     * @param object $db The driver, inside an open transaction.
     * @param int $sourceId The document whose claims are released.
     * @param callable $draftBuilder fn(): ?array.
     *
     * @return object  {draft: ?array, preview: ?object}
     *
     * @throws DocumentRefusedException When the source is not issued, or the enclosing transaction was lost.
     *
     * @since   2.0.0
     */
    private static function withSourceReleased(object $db, int $sourceId, callable $draftBuilder): object
    {
        $voided = DocumentHelper::STATUS_VOIDED;
        $issued = DocumentHelper::STATUS_ISSUED;

        $db->transactionStart(true);

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__alfa_documents'))
                    ->set($db->quoteName('status') . ' = :voided')
                    ->where($db->quoteName('id') . ' = :id')
                    ->where($db->quoteName('status') . ' = :issued')
                    ->bind(':voided', $voided, ParameterType::INTEGER)
                    ->bind(':id', $sourceId, ParameterType::INTEGER)
                    ->bind(':issued', $issued, ParameterType::INTEGER),
            )->execute();

            if ((int) $db->getAffectedRows() !== 1) {
                throw new DocumentRefusedException(
                    message: "document #{$sourceId} is not issued; only an issued document can be replaced.",
                    key:     'COM_ALFA_REPLACEMENT_REFUSED_NOT_ISSUED',
                    values:  [(string) self::load(db: $db, documentId: $sourceId)->number_formatted],
                );
            }

            $draft = $draftBuilder();

            if ($draft !== null) {
                $draft['id_replaces'] = $sourceId;
            }

            $built = (object) ['draft' => $draft, 'preview' => $draft === null ? null : self::preview($draft)];
        } finally {
            // Back to exactly what it was; the savepoint is released either way.
            $escalated = self::rollbackQuietly($db);
        }

        // Reached only when nothing is propagating.
        if ($escalated) {
            throw new DocumentRefusedException(
                message: "the transaction holding document #{$sourceId} ended while its replacement was being built: "
                    . 'nothing was voided or issued; review the replacement again.',
                key:     'COM_ALFA_REPLACEMENT_REFUSED_TRANSACTION_LOST',
                values:  [(string) self::load(db: $db, documentId: $sourceId)->number_formatted],
            );
        }

        return $built;
    }

    /**
     * The preconditions of a replacement, asked of the source under the order lock.
     *
     * @param object $db The driver.
     * @param object $source The source row, locked FOR UPDATE.
     *
     *
     * @throws DocumentRefusedException Naming the one condition that refuses it, in the operator's words too.
     *
     * @since   2.0.0
     */
    private static function guardReplaceable(object $db, object $source): void
    {
        $sourceId = (int) $source->id;
        $number = (string) $source->number_formatted;

        if ((int) $source->status !== DocumentHelper::STATUS_ISSUED) {
            throw new DocumentRefusedException(
                message: "document #{$sourceId} is not issued; only an issued document can be replaced.",
                key:     'COM_ALFA_REPLACEMENT_REFUSED_NOT_ISSUED',
                values:  [$number],
            );
        }

        // A CORRUPT CHAIN IS NOT EXTENDED. This workflow only ever writes a
        // chain that runs forward inside one order with one successor per
        // link; a cycle, self-loop, fork or dangling link came from outside
        // it, and replacing any member would build on a history no reader can
        // trust. The chain has no depth limit; only its integrity is asked.
        $anomalies = DocumentHelper::replacementChain($sourceId)->anomalies;

        if ($anomalies !== []) {
            throw new DocumentRefusedException(
                message: "document #{$sourceId} belongs to a corrupt replacement chain ("
                    . implode(', ', array_map(static fn (array $anomaly): string => "{$anomaly['kind']} at #{$anomaly['at']}", $anomalies))
                    . '); it cannot be replaced until the chain is repaired.',
                key:     'COM_ALFA_REPLACEMENT_REFUSED_CORRUPT_CHAIN',
                values:  [$number],
            );
        }

        // section 14 amendment, at the DOCUMENT level: a combined invoice moved the
        // goods even though its type moves none. Issuing its replacement
        // would execute that physical movement again. A document with no item
        // line moved nothing, so there is nothing to move twice.
        if (DocumentHelper::movesStock($source)) {
            throw new DocumentRefusedException(
                message: "document #{$sourceId} moved stock, and issuing its replacement would move it again; "
                    . 'Void & Replace is unavailable for it until a movement-preserving replacement exists.',
                key:     'COM_ALFA_REPLACEMENT_REFUSED_STOCK_MOVING',
                values:  [$number],
            );
        }

        // Named, so staff know which documents to void first.
        $db->setQuery(
            'SELECT ' . $db->quoteName('number_formatted') . ' FROM ' . $db->quoteName('#__alfa_documents')
            . ' WHERE ' . $db->quoteName('id_corrects') . ' = ' . $sourceId
            . ' AND ' . $db->quoteName('status') . ' <> ' . DocumentHelper::STATUS_VOIDED
            . ' ORDER BY ' . $db->quoteName('id'),
        );
        $correctives = $db->loadColumn() ?: [];

        if ($correctives !== []) {
            throw new DocumentRefusedException(
                message: "document #{$sourceId} has non-voided corrective documents; void those first.",
                key:     'COM_ALFA_REPLACEMENT_REFUSED_CORRECTIVES',
                values:  [$number, implode(', ', $correctives)],
            );
        }

        // A REFUND already paid money out against this note, or is on its way
        // to. Replacing the note does not un-send it, and a refund left pointing
        // at a voided note would let the replacement be settled again, paying
        // the customer twice. Refused rather than compensated. A refund the
        // provider reversed in full (PaymentLedger::recordReversal()) no longer
        // holds the note; the ledger owns that test.
        if (\Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger::documentHasLiveRefund(documentId: $sourceId)) {
            throw new DocumentRefusedException(
                message: "document #{$sourceId} is settled by a refund that has paid money out; a refund cannot be "
                    . 'reversed by replacing the note it settled.',
                key:     'COM_ALFA_REPLACEMENT_REFUSED_REFUND_SETTLED',
                values:  [$number],
            );
        }

        // The void takes back the wallet credit the note gave; credit the
        // customer has already spent cannot be, so nothing is voided.
        $shortfall = VoidAndReplace::walletShortfall(source: $source);

        if ($shortfall !== '') {
            throw new DocumentRefusedException(
                message: "document #{$sourceId} credited the customer's wallet, which no longer holds it ({$shortfall}); the void could not take it back.",
                key:     'COM_ALFA_REPLACEMENT_REFUSED_WALLET_SPENT',
                values:  [$number],
            );
        }
    }

    /**
     * Refuse a replacement that did not land as the workflow built it.
     *
     * The buyer is compared column by column: onDocumentBeforeIssue runs after
     * the fingerprint check and may amend the draft, and the previewed buyer is
     * what the operator confirmed.
     *
     * The frozen destination is compared with the source's: a replacement that
     * landed with another one, or with none, would hold the wrong amount on
     * its order, or hold nothing while nobody pays it in.
     *
     * @param object $db The driver.
     * @param object $source The source row as it was locked.
     * @param object $replacement The replacement row as it now stands.
     * @param object $preview The preview the draft was confirmed against.
     * @param array $buyer buyerBlock() of the confirmed draft.
     *
     *
     * @since   2.0.0
     */
    private static function verifyReplacement(object $db, object $source, object $replacement, object $preview, array $buyer): void
    {
        $sourceNow = self::load(db: $db, documentId: (int) $source->id);
        // Both totals in the source document's money, at the scale it froze.
        $currency = DocumentMoney::currencyOf($sourceNow);
        $minor = static fn ($value): int => Money::of((float) $value, $currency)->getMinorUnits();

        $problems = [];

        if ((int) $sourceNow->status !== DocumentHelper::STATUS_VOIDED) {
            $problems[] = 'the source is not voided';
        }

        if ((int) $replacement->id_replaces !== (int) $source->id) {
            $problems[] = 'the replacement does not name the source in id_replaces';
        }

        if ((int) $replacement->id === (int) $source->id) {
            $problems[] = 'the replacement is the source';
        }

        if ((string) $replacement->doc_type !== (string) $source->doc_type) {
            $problems[] = 'the replacement is a different document type';
        }

        if (DocumentHelper::movesStock($replacement)) {
            $problems[] = 'the replacement would move stock';
        }

        if ($minor($replacement->total_tax_incl) !== $minor($preview->totals->incl)) {
            $problems[] = 'the replacement total differs from the preview';
        }

        if ($replacement->credit_destination !== $source->credit_destination) {
            $problems[] = 'the replacement is not sent where its source was sent';
        }

        foreach ($buyer as $column => $previewed) {
            $landed = $replacement->{$column} ?? null;

            if (($landed === null ? null : (string) $landed) !== $previewed) {
                $problems[] = "the replacement {$column} differs from the preview";
            }
        }

        if ($problems !== []) {
            throw new DocumentRefusedException(
                message: 'the replacement did not land as previewed: ' . implode('; ', $problems) . '.',
                key:     'COM_ALFA_REPLACEMENT_REFUSED_NOT_AS_PREVIEWED',
                values:  [(string) $source->number_formatted],
            );
        }
    }

    /**
     * A draft that names a document in `id_replaces` must be a real replacement of it.
     *
     * The data half of the provenance rule: issue() and preview() both call
     * it, so a preview refuses exactly what issuing would. (The other half,
     * that only replace() may carry the key at all, is issue()'s alone.)
     *
     * @param object $db The driver.
     * @param string $docType The type being issued.
     * @param int $orderId The order.
     * @param array $draft The draft.
     *
     *
     * @since   2.0.0
     */
    private static function guardReplacement(object $db, string $docType, int $orderId, array $draft): void
    {
        $replaces = (int) ($draft['id_replaces'] ?? 0);

        if ($replaces <= 0) {
            return;
        }

        $db->setQuery(
            'SELECT ' . implode(', ', $db->quoteName(['id_order', 'doc_type', 'status']))
            . ' FROM ' . $db->quoteName('#__alfa_documents')
            . ' WHERE ' . $db->quoteName('id') . ' = ' . $replaces,
        );
        $replaced = $db->loadObject();

        if (!$replaced) {
            throw new RuntimeException("DocumentIssuer: id_replaces names document #{$replaces}, which does not exist.");
        }

        if ((int) $replaced->id_order !== $orderId) {
            throw new RuntimeException("DocumentIssuer: document #{$replaces} belongs to another order and cannot be replaced from this one.");
        }

        if ((string) $replaced->doc_type !== $docType) {
            throw new RuntimeException(
                'DocumentIssuer: a replacement is the same type as the document it replaces '
                . "({$replaced->doc_type}, not {$docType}).",
            );
        }

        // Never while the source still stands: the replacement is issued only
        // once the document it replaces has been withdrawn from coverage.
        if ((int) $replaced->status !== DocumentHelper::STATUS_VOIDED) {
            throw new RuntimeException(
                "DocumentIssuer: document #{$replaces} is still active; a replacement is issued only once the "
                . 'document it replaces has been voided.',
            );
        }

        // ONE SUCCESSOR, EVER, stated as the rule really is. A replacement
        // withdrawn by a plain void still replaced this document; a second
        // one would fork the chain. What may be replaced next is the chain's
        // live document; a chain whose last replacement was withdrawn has
        // none, and the order goes on with an ordinary issue.
        $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_documents')
            . ' WHERE ' . $db->quoteName('id_replaces') . ' = ' . $replaces,
        );

        if ((int) $db->loadResult() > 0) {
            throw new RuntimeException(
                "DocumentIssuer: document #{$replaces} already has a replacement; a document is replaced at most "
                . "once. Replace the chain's live document instead or, if its replacement was withdrawn, issue a "
                . 'new document normally.',
            );
        }
    }

    /**
     * Lock and return the order row (FOR UPDATE).
     *
     * @param object $db Database driver.
     * @param int $orderId The order id.
     *
     * @return object The order row.
     *
     * @since  2.0.0
     */
    private static function lockOrder(object $db, int $orderId): object
    {
        $db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__alfa_orders')
            . ' WHERE ' . $db->quoteName('id') . ' = ' . $orderId . ' FOR UPDATE',
        );
        $order = $db->loadObject();

        if ($order === null) {
            throw new RuntimeException("order #{$orderId} does not exist.");
        }

        return $order;
    }

    /**
     * Lock and return the series row (FOR UPDATE), validating type + state.
     *
     * @param object $db Database driver.
     * @param int $seriesId The series id.
     * @param string $docType The document type being issued.
     *
     * @return object The series row.
     *
     * @since  2.0.0
     */
    private static function lockSeries(object $db, int $seriesId, string $docType): object
    {
        $db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__alfa_document_series')
            . ' WHERE ' . $db->quoteName('id') . ' = ' . $seriesId . ' FOR UPDATE',
        );
        $series = $db->loadObject();

        if ($series === null || (int) $series->state !== 1) {
            throw new RuntimeException("series #{$seriesId} does not exist or is unpublished.");
        }

        if ((string) $series->doc_type !== $docType) {
            throw new RuntimeException("series #{$seriesId} serves '{$series->doc_type}', not '{$docType}'.");
        }

        return $series;
    }

    /**
     * Static draft validation (buyer/movement blocks, line shape, buyer column widths).
     *
     * @param string $docType The document type.
     * @param array $draft The draft.
     * @param array $lines The draft lines.
     *
     * @since  2.0.0
     */
    private static function validateDraft(string $docType, array $draft, array $lines): void
    {
        if (DocumentHelper::requiresBuyerVat($docType)) {
            if (trim((string) ($draft['buyer_vat_number'] ?? '')) === '' || trim((string) ($draft['buyer_name'] ?? '')) === '') {
                self::refuseIncompleteCorrectedBuyer(docType: $docType, correctsId: (int) ($draft['id_corrects'] ?? 0));

                throw new RuntimeException("'{$docType}' requires buyer_name and, per configuration, buyer_vat_number (tax ID).");
            }
        }

        // THE COLUMN WIDTHS ARE VALIDATION, so preview() and issue() agree. An
        // over-long value used to preview as valid and then fail at insert
        // with the driver's raw "Data too long". Measured on the block the
        // header will store, in characters, as the utf8mb4 columns count.
        // Widths only: VAT format and check digits belong to jurisdiction
        // packages, not to core.
        $buyer = self::buyerBlock($draft);

        foreach (['buyer_name' => 255, 'buyer_vat_number' => 20, 'buyer_tax_office' => 100, 'buyer_activity' => 255, 'buyer_email' => 255] as $key => $width) {
            $length = mb_strlen($buyer[$key]);

            if ($length > $width) {
                throw new RuntimeException("{$key} is {$length} characters long; a document holds at most {$width}.");
            }
        }

        $countryLength = mb_strlen($buyer['buyer_country']);

        if ($countryLength !== 0 && $countryLength !== 2) {
            throw new RuntimeException("buyer_country is {$countryLength} characters long; a document holds a two-letter country code or none.");
        }

        $needsMovement = DocumentHelper::requiresMovement($docType) || !empty($draft['is_combined']);

        if ($needsMovement && trim((string) ($draft['movement_purpose'] ?? '')) === '') {
            throw new RuntimeException("'{$docType}'" . (!empty($draft['is_combined']) ? ' (combined)' : '') . ' requires movement_purpose.');
        }

        foreach ($lines as $i => $line) {
            if (trim((string) ($line['name'] ?? '')) === '') {
                throw new RuntimeException("line {$i} needs a resolved name.");
            }

            if ((float) ($line['quantity'] ?? 1) <= 0) {
                throw new RuntimeException("line {$i} needs a positive quantity.");
            }

            if (DocumentHelper::isFiscal($docType) && !isset($line['unit_price_tax_excl'], $line['unit_price_tax_incl'])) {
                throw new RuntimeException("line {$i} needs explicit unit prices (documents freeze, never price).");
            }
        }
    }

    /**
     * Refuse, by name, a credit whose type needs a buyer its corrected document never froze.
     *
     * A credit is made out to the buyer its corrected document froze and takes
     * no other (guardQuantities()), so "requires buyer_vat_number" would ask
     * for something no screen may supply. When the frozen
     * buyer itself lacks what the type requires, the refusal names that
     * document and what it lacks. Which credit type may correct such a
     * document is a policy decision; nothing here relaxes the rule.
     * Returns without refusing when this is not that case; the caller then
     * refuses in its own words.
     *
     * @param string $docType The document type, one that requires a tax id.
     * @param int $correctsId The draft's id_corrects, or 0.
     *
     *
     * @throws RuntimeException When the corrected document froze an incomplete buyer.
     *
     * @since   2.0.0
     */
    private static function refuseIncompleteCorrectedBuyer(string $docType, int $correctsId): void
    {
        if ($correctsId <= 0 || !\in_array($docType, DocumentHelper::creditTypes(), true)) {
            return;
        }

        $corrected = DocumentHelper::getById($correctsId);

        if ($corrected === null) {
            return;
        }

        $frozen = self::frozenBuyer($corrected);
        $missing = array_values(array_filter(
            ['buyer_name', 'buyer_vat_number'],
            static fn (string $key): bool => trim($frozen[$key]) === '',
        ));

        if ($missing === []) {
            return;
        }

        $number = (string) ($corrected->number_formatted ?? '');

        throw new RuntimeException(sprintf(
            "'%s' requires the buyer's name and tax ID, and a credit is made out to the buyer %s (#%d) froze, which has no %s; a credit cannot complete it. A wrong buyer is corrected by Void & Replace of %s.",
            $docType,
            $number,
            $correctsId,
            implode(', ', $missing),
            $number,
        ));
    }

    /**
     * Over-issue / over-credit guards: computed INSIDE the order lock so a
     * concurrent issuance cannot slip past the sums.
     *
     * Also the corrected-document rules of a credit (since 2.0.0): its buyer
     * and its language are the ones the corrected document froze, it credits
     * no more of a line than that document has left, and only a credit may
     * name a corrected document at all.
     *
     * @param object $db Database driver.
     * @param string $docType The document type.
     * @param int $orderId The order id.
     * @param array $lines The draft lines.
     * @param array $draft The full draft (id_corrects for value credits).
     *
     * @since  2.0.0
     */
    private static function guardQuantities(object $db, string $docType, int $orderId, array $lines, array $draft): void
    {
        $remaining = DocumentHelper::remainingQuantities(orderId: $orderId, docType: $docType);

        // THE DRAFT CONSUMES CUMULATIVELY. remainingQuantities() counts only
        // PERSISTED documents (the draft being validated is not one), so
        // measuring every line against that same untouched snapshot let a
        // single draft spend the pool repeatedly: two lines of 3 against a
        // 5-unit order line each passed on their own and 6 units were issued
        // against 5 sold. Sum the draft's own claims per order line and test
        // the RUNNING TOTAL, exactly as guardCharges() below already does for
        // charge lines.
        $claimed = [];
        // The line as the operator counts it: its row in the issue modal, from
        // 1. The modal lists EVERY line of the order by id (DocumentLinesField),
        // ticked or not, fully documented or not; the draft holds only the
        // ticked rows, so a place in the draft named the wrong row.
        $rows = array_keys($remaining);
        sort($rows, SORT_NUMERIC);
        $rowOf = array_flip($rows);

        foreach ($lines as $i => $line) {
            $orderItemId = (int) ($line['id_order_item'] ?? 0);

            if ($orderItemId <= 0) {
                continue; // Free-text / value-only line; amount guard below.
            }

            if (!isset($remaining[$orderItemId])) {
                throw new RuntimeException("line {$i}: order line #{$orderItemId} does not belong to order #{$orderId}.");
            }

            $qty = (float) ($line['quantity'] ?? 1);
            $claimed[$orderItemId] = ($claimed[$orderItemId] ?? 0.0) + $qty;

            // QUANTITY, not money: a fractional quantity is a real thing
            // (weight, length, hours) and has no currency scale to be exact at.
            if ($claimed[$orderItemId] - $remaining[$orderItemId]['remaining'] > self::QUANTITY_EPSILON) {
                // Name the cumulative total only when it differs from this
                // line, so a single over-quantity line still reads as before.
                $context = $claimed[$orderItemId] - $qty > self::QUANTITY_EPSILON
                    ? " (this draft claims {$claimed[$orderItemId]} in total for that line)"
                    : '';

                // The message keeps the technical detail logs read; the
                // operator is told the line by its row and product (C-4).
                throw new DocumentRefusedException(
                    message: "line {$i}: quantity {$qty}{$context} exceeds the remaining {$remaining[$orderItemId]['remaining']} for order line #{$orderItemId}.",
                    key:     $context === '' ? 'COM_ALFA_DOCUMENTS_REFUSED_LINE_QUANTITY' : 'COM_ALFA_DOCUMENTS_REFUSED_LINE_QUANTITY_TOTAL',
                    values:  [
                        $rowOf[$orderItemId] + 1,
                        (string) ($line['name'] ?? ''),
                        $context === '' ? $qty : $claimed[$orderItemId],
                        $remaining[$orderItemId]['remaining'],
                    ],
                );
            }
        }

        // Charge lines (shipping / fee / adjustment linked via id_source):
        // each frozen order charge is a consumable balance claimed AT MOST
        // once across all issued sales documents. Same lock, same pattern as
        // the quantity pool: a concurrent issuance cannot double-claim.
        self::guardCharges(db: $db, docType: $docType, orderId: $orderId, lines: $lines);

        // A credit document must name what it corrects
        // This whole block used to be gated on `$correctsId > 0`, which read
        // as "validate the target when one was given". But the block is not
        // only the target check; it is also the CUMULATIVE CREDIT CAP. So a
        // credit note with no id_corrects had no target AND NO CEILING:
        // measured, a 620.00 credit note issued against a 62.00 invoice,
        // restoring stock and creditable to the customer's wallet.
        //
        // Required HERE rather than in the screens because every issuance
        // path funnels through this guard: the order screen, the RMA
        // workflow, auto-issue, and any plugin that amends a draft through
        // onDocumentBeforeIssue. The order screen blocks an empty pick before
        // it posts, but that is an early convenience; a required form field
        // binds one door, this binds the corridor.
        //
        // Placement matters as much as the rule: this runs inside the order
        // lock but BEFORE the series is locked and a gapless number is
        // allocated, so a refusal costs nothing: no document, no number, no
        // stock movement, no wallet entry.
        $correctsId = (int) ($draft['id_corrects'] ?? 0);

        if (\in_array($docType, DocumentHelper::creditTypes(), true)) {
            if ($correctsId <= 0) {
                throw new RuntimeException(
                    'a credit document must name the document it corrects (id_corrects); '
                    . 'without one nothing caps what may be credited.',
                );
            }

            // Missing rows throw out of load(): a ghost id never reaches the
            // checks below.
            $target = self::load(db: $db, documentId: $correctsId);

            // An issued sales document of this order: the one statement of
            // the rule, which CreditCalculator::calculate() asks as well.
            self::guardCorrectable(target: $target, orderId: $orderId);

            // A credit is made out to the buyer of what it corrects
            // The buyer comes from the frozen corrected
            // document, never the order's or the customer's as they stand now,
            // and never someone else. All seven columns, compared through the
            // one normaliser so an address is compared as it is stored.
            // REFUSED, NEVER REWRITTEN: preview(), draftFingerprint() and
            // verifyReplacement() read the caller's draft, and what was
            // previewed is what issue persists. Here, before the series lock,
            // a refusal draws no number. issue() judges the draft its
            // onDocumentBeforeIssue subscribers amended; preview() dispatches
            // no event (see its docblock) and judges the draft as built, so a
            // subscriber that changes a credit's buyer passes a preview and is
            // refused here at issue: the rule fails closed.
            $frozenBuyer = self::buyerBlock(self::frozenBuyer($target));
            $draftBuyer = self::buyerBlock($draft);
            $differing = array_keys(array_filter(
                $frozenBuyer,
                static fn ($value, string $column): bool => $draftBuyer[$column] !== $value,
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($differing !== []) {
                throw new RuntimeException(sprintf(
                    'the buyer of this credit differs from the buyer %s (#%d) froze in %s; a credit is made out to the buyer of the document it corrects, unchanged.',
                    (string) $target->number_formatted,
                    $correctsId,
                    implode(', ', $differing),
                ));
            }

            // A credit is issued in the language of what it corrects
            // The corrected
            // document's stored language is taken verbatim. A draft naming
            // another is refused, never rewritten, as the buyer is; one
            // naming none is stamped with it (insertHeader()).
            if (isset($draft['language']) && (string) $draft['language'] !== (string) $target->language) {
                $draftLanguage = (string) $draft['language'];
                $targetLanguage = (string) $target->language;

                throw new DocumentRefusedException(
                    message: "the credit's language '{$draftLanguage}' differs from '{$targetLanguage}' of {$target->number_formatted} (#{$correctsId}); a credit is issued in the language of the document it corrects.",
                    key:     'COM_ALFA_DOCUMENTS_REFUSED_CREDIT_LANGUAGE',
                    values:  [$draftLanguage, (string) $target->number_formatted, $targetLanguage],
                );
            }

            // The invoice-level cap below is one number for the whole
            // document: a note that gave back all of the shipping while items
            // were still outstanding leaves room under it, so a second note
            // could claim the same parcel again. Per (line_type, id_source),
            // in the corrected document's money.
            self::guardCreditCharges(
                db:         $db,
                orderId:    $orderId,
                correctsId: $correctsId,
                lines:      $lines,
                currency:   DocumentMoney::currencyOf($target),
            );

            // No more of a line than this document has left to credit
            // For every
            // line the corrected document states, the draft's running
            // quantity may not exceed what that document has left after its
            // ISSUED credits. The order-wide pool above admits a line split
            // over several invoices in full against any one of them; the
            // total cap below would admit it while the invoice holds money
            // elsewhere. A line the document does not state is not judged
            // here.
            $left = OrderItemLines::remainingForCredit(correctsId: $correctsId);
            $invoiceClaimed = [];

            foreach ($lines as $i => $line) {
                if ((string) ($line['line_type'] ?? DocumentHelper::LINE_ITEM) !== DocumentHelper::LINE_ITEM) {
                    continue;
                }

                $orderItemId = (int) ($line['id_order_item'] ?? 0);

                if ($orderItemId <= 0 || !isset($left[$orderItemId])) {
                    continue;
                }

                $invoiceClaimed[$orderItemId] = ($invoiceClaimed[$orderItemId] ?? 0.0) + (float) ($line['quantity'] ?? 1);

                if ($invoiceClaimed[$orderItemId] - $left[$orderItemId]->remaining_qty > self::QUANTITY_EPSILON) {
                    throw new DocumentRefusedException(
                        message: "line {$i}: this credit claims {$invoiceClaimed[$orderItemId]} of order line #{$orderItemId}, but {$target->number_formatted} (#{$correctsId}) has {$left[$orderItemId]->remaining_qty} left to credit for it.",
                        key:     'COM_ALFA_DOCUMENTS_REFUSED_LINE_QUANTITY_INVOICE',
                        values:  [
                            $rowOf[$orderItemId] + 1,
                            (string) ($line['name'] ?? ''),
                            $invoiceClaimed[$orderItemId],
                            (string) $target->number_formatted,
                            $left[$orderItemId]->remaining_qty,
                        ],
                    );
                }
            }

            $newTotal = self::linesTotal(lines: $lines);

            // EXACT, at the corrected document's own frozen scale. The
            // tolerance this replaces was a flat 0.000001 in "the document's
            // currency", a figure finer than the smallest unit of every
            // currency Alfa supports, so a cumulative credit could exceed an
            // invoice by a real minor unit at scale 8 and still be admitted,
            // while at scale 0 it forgave nothing it needed to. The cap is the
            // invoice's own total, asked in the invoice's own money.
            $capCurrency = DocumentMoney::currencyOf($target);
            $creditLeft = self::creditLeft(target: $target);

            if (Money::of($newTotal, $capCurrency)->greaterThan($creditLeft)) {
                $alreadyCredited = DocumentHelper::creditedTotal(documentId: $correctsId);
                $money = DocumentMoney::formatter($target);

                // An invoice with nothing left is told so: no amount can be
                // lowered into a credit of it.
                throw new DocumentRefusedException(
                    message: "cumulative credits ({$alreadyCredited} + {$newTotal}) would exceed the corrected document's total ({$target->total_tax_incl}).",
                    key:     $creditLeft->isPositive() ? 'COM_ALFA_DOCUMENTS_REFUSED_CREDIT_TOTAL' : 'COM_ALFA_DOCUMENTS_REFUSED_CREDIT_TOTAL_NONE',
                    values:  [$money($newTotal), (string) $target->number_formatted, $money(max(0.0, $creditLeft->getAmount()))],
                );
            }

            // The charge record still describes this document
            // A calculated credit carries its decision record: every eligible
            // shipping and fee source with what it had left. Rebuilt here,
            // under the order lock and before a number is drawn, from the
            // record's own selection and the lines as they stand, and compared
            // byte for byte ("Re-read authoritative source state and
            // repeat the canonical validations/calculation before
            // persistence"). A subscriber that amended the lines fails closed.
            if (isset($draft['credit_charges'])) {
                $changed = static fn (?Throwable $cause = null): DocumentRefusedException => new DocumentRefusedException(
                    message:  "the credit's charge record no longer matches {$target->number_formatted} (#{$correctsId}); what it has left to credit moved since it was calculated.",
                    key:      'COM_ALFA_CREDIT_PREVIEW_CHANGED',
                    previous: $cause,
                );
                $recordSelected = ['shipping' => [], 'fees' => []];

                foreach (\is_array($draft['credit_charges']) ? (array) ($draft['credit_charges']['entries'] ?? []) : [] as $entry) {
                    if (\is_array($entry) && ($entry['decision'] ?? '') === 'selected') {
                        $recordSelected[($entry['line_type'] ?? '') === DocumentHelper::LINE_FEE ? 'fees' : 'shipping'][] = (int) ($entry['id_source'] ?? 0);
                    }
                }

                // A rebuild that cannot be made is the same change, told the same way.
                try {
                    $rebuilt = CreditCalculator::decisionRecord(correctsId: $correctsId, selected: $recordSelected, lines: $lines);
                } catch (Throwable $e) {
                    throw $changed($e);
                }

                if ($rebuilt !== $draft['credit_charges']) {
                    throw $changed();
                }
            }
        } elseif (isset($draft['credit_charges'])) {
            // Only a credit makes a charge choice to record.
            throw new RuntimeException("credit_charges is set on '{$docType}', which is not a credit document.");
        } elseif ($correctsId !== 0) {
            // The reverse of the rule above: naming a corrected document IS
            // being a credit. Stored on any other type, id_corrects would be
            // summed as a credit against its target and lend it its identity.
            throw new RuntimeException(
                "id_corrects #{$correctsId} is set on '{$docType}', which is not a credit document; only a credit corrects another document.",
            );
        }
    }

    /**
     * What a credit may correct: an ISSUED SALES document of the credit's own order.
     *
     * The one statement of the rule. guardQuantities() asks it under the
     * order lock at preview and at issue; CreditCalculator::calculate() asks
     * it before anything is measured, so a credit that picks nothing is
     * refused in the same words instead of measured against a document that
     * cannot be credited.
     *
     * @param object $target The corrected document row.
     * @param int $orderId The order the credit is issued on.
     *
     *
     * @throws RuntimeException Naming the rule the document breaks.
     *
     * @since   2.0.0
     */
    public static function guardCorrectable(object $target, int $orderId): void
    {
        $correctsId = (int) $target->id;

        if ((int) $target->status !== DocumentHelper::STATUS_ISSUED) {
            throw new RuntimeException("id_corrects #{$correctsId} is not an issued document.");
        }

        if ((int) $target->id_order !== $orderId) {
            throw new RuntimeException("id_corrects #{$correctsId} belongs to a different order.");
        }

        // And it must be something that CHARGED money. A delivery note is
        // issued, belongs to the order and passes both checks above, but
        // its lines carry no money; crediting against one is meaningless
        // and, on the RMA path, wedges the return permanently. Refused on
        // its own terms rather than incidentally by the zero-total cap.
        if (!\in_array((string) $target->doc_type, DocumentHelper::salesTypes(), true)) {
            throw new RuntimeException(
                "id_corrects #{$correctsId} is not a sales document; a credit corrects what was charged.",
            );
        }
    }

    /**
     * What a sales document can still credit: its total less its issued credits, in its own money.
     *
     * The invoice-total cap: guardQuantities() refuses a credit whose lines
     * total more (linesTotal()), and CreditCalculator::decisionRecord() bounds
     * what a charge has left to give back by it. "Issued credits" is
     * DocumentHelper::creditedTotal(), read on the shared connection, so
     * inside the order lock it is the sum that lock holds. Below zero only for
     * credits the cap never judged.
     *
     * @param object $target The sales document row (total_tax_incl and its frozen currency).
     *
     *
     * @since   2.0.0
     */
    public static function creditLeft(object $target): Money
    {
        $currency = DocumentMoney::currencyOf($target);

        return Money::of((float) $target->total_tax_incl, $currency)
            ->subtract(Money::of(DocumentHelper::creditedTotal(documentId: (int) $target->id), $currency));
    }

    /**
     * What a credit's lines give back in total, tax included, as the invoice-total cap reads them.
     *
     * Each line's total, or its quantity times its unit price when it states
     * none. The cap and CreditCalculator::decisionRecord() both read it, so
     * what a charge left out can still give back and what the cap admits
     * never disagree.
     *
     * @param array $lines The draft's lines.
     *
     *
     * @since   2.0.0
     */
    public static function linesTotal(array $lines): float
    {
        // The exact sum of the stated totals: added on their digits, never in floats.
        $total = \Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount::of('0');

        foreach ($lines as $index => $line) {
            $total = $total->plus(\Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount::ofNumber(self::statedTotal(line: $line, axis: 'incl', index: (int) $index)));
        }

        return (float) $total->text();
    }

    /**
     * One of the two totals a draft line states, as a number.
     *
     * A document line states its own whole-line totals; the issuer stores
     * them and never multiplies a unit price into one. Every core builder
     * states both, so a line that states neither is refused by name rather
     * than priced from its unit.
     *
     * The total is handed as the draft states it, so a column's text keeps
     * every digit; a reader takes it as Money or ExactAmount reads a number.
     *
     * @param array $line The draft line.
     * @param string $axis 'incl' or 'excl'.
     * @param int $index The line's position in the draft, from 0; the refusal names it from 1.
     *
     *
     * @throws RuntimeException When the line states no such total.
     *
     * @since   2.0.0
     */
    private static function statedTotal(array $line, string $axis, int $index): string|int|float
    {
        if (!isset($line['total_tax_' . $axis]) || !is_numeric($line['total_tax_' . $axis])) {
            throw new RuntimeException(
                'line ' . ($index + 1) . ' (' . (string) ($line['name'] ?? '') . ') states no total; a document line states its own two totals, and a unit price is never multiplied into one.',
            );
        }

        return $line['total_tax_' . $axis];
    }

    /**
     * Over-claim guard for charge lines, INSIDE the order lock.
     *
     * For every draft line carrying id_source, the already-issued claims for
     * that (line_type, id_source) plus this draft's claim must not exceed the
     * frozen source amount: a parcel's carrier charge, a contribution's
     * amount, or a cart rule's discount value (adjustments compare by
     * absolute value; their lines are negative).
     *
     * On a fiscal sales document every shipping, fee and adjustment line must
     * name its order charge (id_source); one that names none is refused by row
     * before a number is drawn.
     *
     * @param object $db Database driver.
     * @param string $docType The document type.
     * @param int $orderId The order id.
     * @param array $lines The draft lines.
     *
     * @since  2.0.0
     */
    private static function guardCharges(object $db, string $docType, int $orderId, array $lines): void
    {
        if (!DocumentHelper::isFiscal($docType) || DocumentHelper::poolOf($docType) !== DocumentHelper::POOL_SALES) {
            return;
        }

        $claims = [];
        $reversals = [];
        // Every claim of a sales document is the order's money, added in its minor units.
        $currency = OrderItemLines::orderCurrency(orderId: $orderId);

        foreach ($lines as $i => $line) {
            $sourceId = (int) ($line['id_source'] ?? 0);
            $type = (string) ($line['line_type'] ?? DocumentHelper::LINE_ITEM);

            if ($type === DocumentHelper::LINE_ITEM) {
                continue;
            }

            // A charge nobody can measure is not billed: the sales twin of the
            // credit refusal in guardCreditCharges().
            if ($sourceId <= 0) {
                throw new DocumentRefusedException(
                    message: "line {$i}: a {$type} line on a sales document must name the order charge it bills (id_source).",
                    key:     'COM_ALFA_DOCUMENTS_REFUSED_CHARGE_NO_SOURCE',
                    values:  [$i + 1, (string) ($line['name'] ?? '')],
                );
            }

            // A POSITIVE adjustment on a sales document reverses discount the
            // order no longer gives: capped by what documents gave beyond it,
            // not by what is left to claim.
            if ($type === DocumentHelper::LINE_ADJUSTMENT && (float) ($line['total_tax_incl'] ?? 0) > 0) {
                $reversals[$sourceId] = Money::of($reversals[$sourceId] ?? 0.0, $currency)
                    ->add(Money::of((float) $line['total_tax_incl'], $currency))
                    ->getAmount();

                continue;
            }

            $claims[$type][$sourceId] = Money::of($claims[$type][$sourceId] ?? 0.0, $currency)
                ->add(Money::of(abs((float) ($line['total_tax_incl'] ?? 0)), $currency))
                ->getAmount();
        }

        // A SALES document can never total negative; that money is a
        // credit note's job. Belt-and-braces under the lock: the builders
        // clamp claims to the draft's value, but a forged or plugin-amended
        // draft must hit the same wall.
        $draftIncl = Money::zero($currency);

        foreach ($lines as $index => $line) {
            $draftIncl = $draftIncl->add(Money::of(self::statedTotal(line: $line, axis: 'incl', index: (int) $index), $currency));
        }

        if ($draftIncl->isNegative()) {
            throw new RuntimeException(
                "draft totals {$draftIncl->getAmount()}: a sales document cannot be negative; issue a credit document instead.",
            );
        }

        if ($reversals !== []) {
            $headroom = OrderChargeLines::discountHeadroom(orderId: $orderId);
            $reversalCurrency = OrderItemLines::orderCurrency(orderId: $orderId);

            foreach ($reversals as $ruleId => $amount) {
                $rule = $headroom[$ruleId] ?? null;

                if ($rule === null) {
                    throw new RuntimeException(
                        "discount reversal (adjustment #{$ruleId}) names no discount of order #{$orderId}.",
                    );
                }

                if (Money::of($amount, $reversalCurrency)->greaterThan(Money::of($rule->reversible_incl, $reversalCurrency))) {
                    throw new RuntimeException(
                        "discount reversal (adjustment #{$ruleId}): {$amount} exceeds the {$rule->reversible_incl} its documents "
                        . 'gave beyond the discount the order gives; a sales document charges back only discount the order no longer gives.',
                    );
                }
            }
        }

        if ($claims === []) {
            return;
        }

        $charges = OrderChargeLines::remaining(orderId: $orderId);

        $frozen = [
            DocumentHelper::LINE_SHIPPING => array_column($charges->shipping, null, 'id'),
            DocumentHelper::LINE_FEE => array_column($charges->fees, null, 'id'),
            DocumentHelper::LINE_ADJUSTMENT => array_column($charges->rules, null, 'id'),
        ];

        foreach ($claims as $type => $bySource) {
            foreach ($bySource as $sourceId => $claim) {
                $source = $frozen[$type][$sourceId] ?? null;

                if ($source === null) {
                    throw new RuntimeException(
                        "charge line ({$type} #{$sourceId}) does not belong to order #{$orderId}.",
                    );
                }

                $chargeCurrency = OrderItemLines::orderCurrency(orderId: $orderId);

                if (Money::of($claim, $chargeCurrency)
                        ->greaterThan(Money::of((float) $source->remaining_incl, $chargeCurrency))) {
                    throw new RuntimeException(
                        "charge line ({$type} #{$sourceId}): claim {$claim} exceeds the remaining "
                        . "{$source->remaining_incl}; each order charge is claimable exactly once "
                        . 'across all partial documents.',
                    );
                }

                // Shipping/fee charges freeze an exact excl figure too;
                // guard that axis, or a bent draft could shift VAT base
                // between documents while the incl side balances.
                if ($type !== DocumentHelper::LINE_ADJUSTMENT) {
                    $claimExcl = Money::zero($chargeCurrency);

                    foreach ($lines as $line) {
                        if ((int) ($line['id_source'] ?? 0) === $sourceId
                            && (string) ($line['line_type'] ?? '') === $type) {
                            $claimExcl = $claimExcl->add(Money::of(abs((float) ($line['total_tax_excl'] ?? 0)), $chargeCurrency));
                        }
                    }

                    if ($claimExcl->greaterThan(Money::of((float) $source->remaining_excl, $chargeCurrency))) {
                        throw new RuntimeException(
                            "charge line ({$type} #{$sourceId}): net claim {$claimExcl->getAmount()} exceeds the "
                            . "remaining net {$source->remaining_excl}.",
                        );
                    }
                }
            }
        }
    }

    /**
     * Cumulative per-source credit cap, INSIDE the order lock.
     *
     * A credit corrects a DOCUMENT, so its charge candidates are that
     * document's own charge lines: for every (line_type, id_source) the draft
     * claims, what issued credit notes correcting the same document already
     * took plus this draft's claim may not exceed what that document charged
     * for it, and may not point the other way. The invoice-level total cap is
     * a different question and cannot answer this one.
     *
     * Every credit charge line must carry id_source: it is the identity the
     * cap is computed on, so a line without one is unmeasurable. Core, the
     * returns workflow and any extension amending a draft through
     * onDocumentBeforeIssue preserve it.
     *
     * Each line is measured as the issuer will store it: its totals on the
     * corrected document's currency, as normalizeLines() puts them for a
     * credit, so a claim written as sub-minor-unit lines is counted
     * at what it becomes.
     *
     * @param object $db Database driver (the order lock is held).
     * @param int $orderId The order.
     * @param int $correctsId The corrected document, already proven an
     *                        issued sales document of this order.
     * @param array $lines The draft lines.
     * @param \Alfa\Component\Alfa\Site\Service\Pricing\Currency $currency The corrected document's currency, at its frozen scale.
     *
     *
     * @throws RuntimeException When a charge line names no source, names one
     *                          the corrected document does not carry, points
     *                          the other way, or exceeds what is left of it.
     *
     * @since  2.0.0
     */
    private static function guardCreditCharges(
        object $db,
        int $orderId,
        int $correctsId,
        array $lines,
        \Alfa\Component\Alfa\Site\Service\Pricing\Currency $currency,
    ): void {
        $claims = [];
        $grants = [];

        foreach ($lines as $i => $line) {
            $type = (string) ($line['line_type'] ?? DocumentHelper::LINE_ITEM);

            if ($type === DocumentHelper::LINE_ITEM) {
                continue;
            }

            // A POSITIVE adjustment on a credit GRANTS discount the order came
            // to give after the document: capped by what the order gives beyond
            // its documents, whatever this document itself carried.
            if ($type === DocumentHelper::LINE_ADJUSTMENT && (float) ($line['total_tax_incl'] ?? 0) > 0) {
                $sourceId = (int) ($line['id_source'] ?? 0);
                $grants[$sourceId] = Money::of($grants[$sourceId] ?? 0.0, $currency)
                    ->add(Money::of((float) $line['total_tax_incl'], $currency))
                    ->getAmount();

                continue;
            }

            $sourceId = (int) ($line['id_source'] ?? 0);

            if ($sourceId <= 0) {
                throw new RuntimeException(
                    "line {$i}: a credit charge line must name the invoice charge it reverses (id_source).",
                );
            }

            // The figures normalizeLines() will store: the line's own totals,
            // rounded on the corrected document's currency and summed on it,
            // so a claim keeps every digit of its scale.
            foreach (['incl', 'excl'] as $axis) {
                $stored = \Alfa\Component\Alfa\Site\Service\Pricing\Money::roundToCurrency(
                    self::statedTotal(line: $line, axis: $axis, index: (int) $i),
                    $currency,
                );
                $claims[$type][$sourceId][$axis] = Money::of($claims[$type][$sourceId][$axis] ?? 0.0, $currency)
                    ->add(Money::of($stored, $currency))
                    ->getAmount();
            }
        }

        if ($grants !== []) {
            $headroom = OrderChargeLines::discountHeadroom(orderId: $orderId);

            foreach ($grants as $ruleId => $amount) {
                $rule = $headroom[$ruleId] ?? null;

                if ($rule === null || !Money::of($rule->current_incl, $currency)->isPositive()) {
                    throw new RuntimeException(
                        "discount grant (adjustment #{$ruleId}) names no discount order #{$orderId} gives.",
                    );
                }

                if (Money::of($amount, $currency)->greaterThan(Money::of($rule->grantable_incl, $currency))) {
                    throw new RuntimeException(
                        "discount grant (adjustment #{$ruleId}): {$amount} exceeds the {$rule->grantable_incl} the order gives "
                        . 'beyond what its documents already give; a credit grants only discount no document gives yet.',
                    );
                }
            }
        }

        if ($claims === []) {
            return;
        }

        // Read on the shared connection, which holds the order lock.
        $charges = OrderChargeLines::correctedCharges(correctsId: $correctsId);

        $frozen = [
            DocumentHelper::LINE_SHIPPING => array_column($charges->shipping, null, 'id'),
            DocumentHelper::LINE_FEE => array_column($charges->fees, null, 'id'),
            DocumentHelper::LINE_ADJUSTMENT => array_column($charges->rules, null, 'id'),
        ];

        foreach ($claims as $type => $bySource) {
            foreach ($bySource as $sourceId => $claim) {
                $source = $frozen[$type][$sourceId] ?? null;

                if ($source === null) {
                    throw new RuntimeException(
                        "charge line ({$type} #{$sourceId}) is not on the corrected document #{$correctsId}; "
                        . 'a credit gives back what THAT document charged.',
                    );
                }

                // Direction before size: a claim that points the other way
                // satisfies any ceiling while inverting the money.
                if (($claim['incl'] <=> 0.0) !== ((float) $source->amount_incl <=> 0.0)) {
                    throw new RuntimeException(
                        "charge line ({$type} #{$sourceId}): {$claim['incl']} reverses a charge of {$source->amount_incl}; "
                        . 'a credit line must have the same sign as the charge it corrects.',
                    );
                }

                if (Money::of(abs($claim['incl']), $currency)
                        ->greaterThan(Money::of(abs((float) $source->remaining_incl), $currency))) {
                    throw new RuntimeException(
                        "charge line ({$type} #{$sourceId}): claim {$claim['incl']} exceeds the {$source->remaining_incl} "
                        . "document #{$correctsId} has left for it; each invoice charge is creditable at most once "
                        . 'across all credit notes correcting it.',
                    );
                }

                // The net axis too, for every kind: a credit copies the
                // invoice's own rows, so the net per source is exact, and a
                // bent draft could otherwise move VAT base between notes.
                if (Money::of(abs($claim['excl']), $currency)
                        ->greaterThan(Money::of(abs((float) $source->remaining_excl), $currency))) {
                    throw new RuntimeException(
                        "charge line ({$type} #{$sourceId}): net claim {$claim['excl']} exceeds the remaining net "
                        . "{$source->remaining_excl} of document #{$correctsId}.",
                    );
                }
            }
        }
    }

    /**
     * The inventory identity an item line freezes, keyed by order line.
     *
     * A CREDIT takes it ONLY from the document it corrects: a line that
     * document froze without identity carries none, and the order line is
     * never read.
     * Everything else takes it from the order line, which at issue time
     * IS the freeze; nothing re-reads it afterwards.
     *
     * @param int $orderId The documented order.
     * @param int $correctsId The corrected document, 0 when there is none.
     *
     * @return array<int, object> Keyed by id_order_item.
     *
     * @since  2.0.0
     */
    private static function frozenIdentity(int $orderId, int $correctsId): array
    {
        if ($orderId <= 0 && $correctsId <= 0) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $identity = [];

        // A credit (the only type that names a corrected document) never
        // reads the order line.
        if ($orderId > 0 && $correctsId <= 0) {
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'id_item', 'id_combination', 'quantity']))
                ->from($db->quoteName('#__alfa_order_items'))
                ->where($db->quoteName('id_order') . ' = :orderId')
                ->bind(':orderId', $orderId);
            $db->setQuery(query: $query);

            foreach ($db->loadObjectList() ?: [] as $row) {
                $identity[(int) $row->id] = (object) [
                    'id_item' => (int) $row->id_item,
                    'id_combination' => $row->id_combination !== null ? (int) $row->id_combination : null,
                    'movement_bound' => (float) $row->quantity,
                ];
            }
        }

        if ($correctsId > 0) {
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id_order_item', 'id_item', 'id_combination', 'movement_bound']))
                ->from($db->quoteName('#__alfa_document_items'))
                ->where($db->quoteName('id_document') . ' = :correctsId')
                ->where($db->quoteName('line_type') . ' = ' . $db->quote(DocumentHelper::LINE_ITEM))
                ->where($db->quoteName('id_order_item') . ' IS NOT NULL')
                ->bind(':correctsId', $correctsId);
            $db->setQuery(query: $query);

            // The corrected document is the credit's only source: it is what
            // this credit is reversing, and its line may name goods the order
            // no longer does.
            foreach ($db->loadObjectList() ?: [] as $row) {
                if ($row->id_item === null) {
                    continue;
                }

                $identity[(int) $row->id_order_item] = (object) [
                    'id_item' => (int) $row->id_item,
                    'id_combination' => $row->id_combination !== null ? (int) $row->id_combination : null,
                    'movement_bound' => $row->movement_bound !== null ? (float) $row->movement_bound : null,
                ];
            }
        }

        return $identity;
    }

    /**
     * Normalize draft lines into insert-ready row objects with frozen totals
     * and the frozen inventory identity the line moves.
     *
     * @param string $docType The document type.
     * @param array $lines The draft lines.
     * @param \Alfa\Component\Alfa\Site\Service\Pricing\Currency $currency The document currency: the order's for a sales
     *                                                                     document, the corrected document's for a credit.
     *
     * @return stdClass[]
     *
     * @since  2.0.0
     */
    private static function normalizeLines(
        string $docType,
        array $lines,
        \Alfa\Component\Alfa\Site\Service\Pricing\Currency $currency,
        int $orderId = 0,
        int $correctsId = 0,
    ): array {
        // THE FREEZE RESOLVES HERE, at the one writer, not in each draft
        // builder. There are four builders; one that forgets the inventory
        // identity would issue a stock-moving document whose effect silently
        // moves nothing. Resolving it here makes forgetting impossible.
        $identity = self::frozenIdentity(orderId: $orderId, correctsId: $correctsId);

        $rows = [];
        $sort = 0;

        // Every figure is handed to its column as its exact decimal text: a
        // unit, a rate and a printed discount held at the column's eighth
        // place, a total as the document currency's Money.
        $unit = static fn (string|int|float $value): string => StoredDecimal::of(ExactAmount::ofNumber($value)->roundedAt(StoredDecimal::SCALE));
        $zero = StoredDecimal::of(ExactAmount::of('0'));

        foreach ($lines as $index => $line) {
            $qty = (float) ($line['quantity'] ?? 1);
            $unitExcl = $unit($line['unit_price_tax_excl'] ?? 0);
            $unitIncl = $unit($line['unit_price_tax_incl'] ?? 0);

            $row = new stdClass();
            $row->id_document = 0; // set after header insert
            $row->line_type = \in_array($line['line_type'] ?? '', [
                DocumentHelper::LINE_ITEM, DocumentHelper::LINE_SHIPPING,
                DocumentHelper::LINE_FEE, DocumentHelper::LINE_ADJUSTMENT,
            ], true) ? $line['line_type'] : DocumentHelper::LINE_ITEM;
            $row->id_order_item = ((int) ($line['id_order_item'] ?? 0)) ?: null;

            // Frozen inventory identity and movement ceiling
            // id_order_item above is provenance, not authority: the order line
            // stays editable after issue, so what this line moved is stated
            // ONCE here and never re-read from that row.
            //
            // NULL = the line carries no inventory identity or movement
            // authority (a free-text line, or a draft that states none). A zero
            // would read as "authorised to move nothing" and hide a forgotten
            // write, hence the same "0 means null" discipline as id_order_item.
            //
            // WHETHER these facts are ever consumed is the DOCUMENT's
            // stock_effect to decide, never the line's: a combined sales
            // document moves stock where the same type uncombined does not.
            // What the draft states wins: a builder that already read the
            // corrected document knows the answer better than a lookup does.
            // Only an item line anchored on an order line can carry identity.
            $frozen = $row->line_type === DocumentHelper::LINE_ITEM && $row->id_order_item !== null
                ? ($identity[$row->id_order_item] ?? null)
                : null;
            $row->id_item = ((int) ($line['id_item'] ?? $frozen->id_item ?? 0)) ?: null;
            $row->id_combination = ((int) ($line['id_combination'] ?? $frozen->id_combination ?? 0)) ?: null;
            $row->movement_bound = ((float) ($line['movement_bound'] ?? $frozen->movement_bound ?? 0)) ?: null;

            $row->id_source = ((int) ($line['id_source'] ?? 0)) ?: null;
            $row->sku = (string) ($line['sku'] ?? '');
            $row->name = mb_substr((string) $line['name'], 0, 500);
            $row->description = ($line['description'] ?? null) !== null ? (string) $line['description'] : null;
            $row->quantity = $qty;
            $row->unit = (string) ($line['unit'] ?? '');
            $row->unit_price_tax_excl = $unitExcl;
            $row->unit_price_tax_incl = $unitIncl;
            $row->original_unit_price_tax_excl = isset($line['original_unit_price_tax_excl'])
                ? $unit($line['original_unit_price_tax_excl']) : null;
            // The agreed custom rate, as the draft states it: frozen here and
            // never re-read from the order line. NULL when the draft states none.
            $row->custom_unit_price_tax_incl = isset($line['custom_unit_price_tax_incl'])
                ? $unit($line['custom_unit_price_tax_incl']) : null;
            $row->discount_tax_excl = $unit($line['discount_tax_excl'] ?? 0);
            $row->tax_rate = (float) ($line['tax_rate'] ?? 0);
            $row->vat_category = isset($line['vat_category']) ? (int) $line['vat_category'] : null;
            $row->vat_exemption_code = ($line['vat_exemption_code'] ?? null) !== null
                ? (string) $line['vat_exemption_code'] : null;

            // Delivery notes carry no money: zero the amounts regardless of input.
            if (!DocumentHelper::isFiscal($docType)) {
                $row->unit_price_tax_excl = $zero;
                $row->unit_price_tax_incl = $zero;
                $row->custom_unit_price_tax_incl = null;
                $row->discount_tax_excl = $zero;
                $row->total_tax_excl = $zero;
                $row->tax_amount = $zero;
                $row->total_tax_incl = $zero;
            } else {
                // PER-LINE (ROW-BASE) ROUNDING:
                // "Each line's tax is rounded to the currency scale, and the
                // document/rate totals are sums of those rounded line taxes."
                //
                // The line's two totals are put on the currency FIRST, through
                // the one rounding primitive, and the tax is then their
                // DIFFERENCE. Two figures already on the minor unit differ by a
                // whole number of minor units, so the tax is exact by
                // construction and incl = excl + tax can never drift, which is
                // what lets sumTotals() and buildTaxSummary() simply add these
                // up and still reconcile with the header.
                //
                // Before this, whatever arithmetic produced was frozen verbatim:
                // measured on the reference data, 1,352 of 26,659 rows in
                // #__alfa_document_taxes (the table install.sql calls the
                // "rounding truth" that "rendering and VAT reports read ... never
                // re-derive") held amounts that do not exist in the currency.
                //
                // Unit prices are deliberately NOT rounded here: a unit may
                // legitimately be finer than the minor unit (a per-gram price),
                // and it is the LINE that is the money the document owes.
                // Totals from the core builders (OrderItemLines) are already on
                // the currency, so this rounding changes nothing for them and
                // remains the guard for a plugin-amended draft.
                $row->total_tax_excl = StoredDecimal::of(Money::of(self::statedTotal(line: $line, axis: 'excl', index: (int) $index), $currency));
                $row->total_tax_incl = StoredDecimal::of(Money::of(self::statedTotal(line: $line, axis: 'incl', index: (int) $index), $currency));
                $row->tax_amount = StoredDecimal::of(Money::of($row->total_tax_incl, $currency)->subtract(Money::of($row->total_tax_excl, $currency)));

                // A LINE THAT CANNOT BE A FISCAL LINE IS REFUSED HERE, so
                // preview() and issue() refuse it alike. Its VAT never has the
                // opposite sign of its gross, and on a line that is not
                // negative the net never exceeds the gross. No builder should
                // produce one; one that does is stopped before it is frozen.
                $gross = Money::of($row->total_tax_incl, $currency);
                $net = Money::of($row->total_tax_excl, $currency);
                $vat = $gross->subtract($net);

                if (($gross->isPositive() && $vat->isNegative()) || ($gross->isNegative() && $vat->isPositive())) {
                    throw new RuntimeException(
                        "line {$index} ({$row->name}) states VAT {$vat->getAmount()} against a gross of {$gross->getAmount()}; "
                        . "a line's VAT never has the opposite sign of its gross.",
                    );
                }

                if (!$gross->isNegative() && $net->greaterThan($gross)) {
                    throw new RuntimeException(
                        "line {$index} ({$row->name}) states a net of {$net->getAmount()} above its gross of {$gross->getAmount()}; "
                        . 'on a line that is not negative the net never exceeds the gross.',
                    );
                }
            }

            $row->authority_classification = null;
            $row->sort_order = (int) ($line['sort_order'] ?? $sort);
            $sort++;

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Ask onDocumentBeforeIssue about a draft, before any transaction opens.
     *
     * Subscribers may amend the draft or veto it by throwing. Asked with no
     * transaction open, so a subscriber that changes the schema or commits can
     * commit no caller's work and no number this issue later refuses. One that
     * leaves a transaction open refuses the issue: what it left is rolled back.
     *
     * A caller that issues inside its own transaction asks this before that
     * transaction begins and hands the answer to issue(); a draft changed after
     * the answer is refused there. issue() asks it itself when no transaction
     * is open.
     *
     * @param array $draft The document draft issue() would take.
     *
     * @return array The answered draft, sealed (DocumentIssuer::ASKED).
     *
     * @throws RuntimeException When a transaction is open, or a subscriber left one changed.
     * @throws Throwable A subscriber's veto.
     *
     * @since  2.0.0
     */
    public static function beforeIssue(array $draft): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        if (TransactionState::isOpen($db)) {
            throw new RuntimeException(
                'DocumentIssuer: onDocumentBeforeIssue is asked before any transaction opens, and a transaction is open: '
                . 'ask it before the transaction begins.',
            );
        }

        unset($draft[self::ASKED]);

        PluginHelper::importPlugin('alfa');
        $event = new DocumentBeforeIssueEvent('onDocumentBeforeIssue', ['draft' => $draft]);
        $veto = null;

        try {
            Factory::getApplication()->getDispatcher()->dispatch($event->getName(), $event);
        } catch (Throwable $e) {
            $veto = $e;
        }

        $left = TransactionState::level($db);

        if ($left !== 0) {
            $changed = new RuntimeException(
                'DocumentIssuer: an onDocumentBeforeIssue subscriber left a database transaction changed; nothing was issued.',
                0,
                $veto,
            );

            for ($guard = $left; $guard > 0; $guard--) {
                if (TransactionState::rollBackLevel(db: $db, failure: $changed)) {
                    break;
                }
            }

            throw $changed;
        }

        if ($veto !== null) {
            throw $veto;
        }

        $answered = $event->getDraft();
        unset($answered[self::ASKED]);
        $answered[self::ASKED] = self::seal(draft: $answered);

        return $answered;
    }

    /**
     * The draft issue() works on: answered by onDocumentBeforeIssue, unsealed.
     *
     * @param array $draft The draft handed to issue().
     *
     *
     * @throws RuntimeException A draft changed after its answer, or one never asked inside an open transaction.
     *
     * @since  2.0.0
     */
    private static function answeredDraft(array $draft): array
    {
        if (\array_key_exists(self::ASKED, $draft)) {
            $seal = $draft[self::ASKED];
            unset($draft[self::ASKED]);

            if (!\is_string($seal) || !hash_equals(self::seal(draft: $draft), $seal)) {
                throw new RuntimeException(
                    'DocumentIssuer: the draft was changed after onDocumentBeforeIssue answered it; nothing was issued.',
                );
            }

            return $draft;
        }

        if (TransactionState::isOpen(Factory::getContainer()->get('DatabaseDriver'))) {
            throw new RuntimeException(
                'DocumentIssuer: inside an open transaction a document is issued only from a draft onDocumentBeforeIssue '
                . 'answered before that transaction began (DocumentIssuer::beforeIssue()); nothing was issued.',
            );
        }

        $answered = self::beforeIssue(draft: $draft);
        unset($answered[self::ASKED]);

        return $answered;
    }

    /**
     * The seal of an answered draft.
     *
     * @param array $draft The answered draft, without its seal.
     *
     *
     * @since  2.0.0
     */
    private static function seal(array $draft): string
    {
        self::$askKey ??= random_bytes(32);

        return hash_hmac('sha256', serialize($draft), self::$askKey);
    }

    /**
     * The refusals of a replacement draft, the same before the transaction and under its lock.
     *
     * @param object $built {draft: ?array, preview: ?object} from withSourceReleased().
     * @param object $source The source row.
     * @param string $expectedFingerprint draftFingerprint() of the preview the operator confirmed.
     *
     *
     * @throws DocumentRefusedException Naming why, in the operator's words too.
     *
     * @since  2.0.0
     */
    private static function judgeReplacement(object $built, object $source, string $expectedFingerprint): void
    {
        $sourceId = (int) $source->id;

        if ($built->draft === null) {
            throw new DocumentRefusedException(
                message: "document #{$sourceId} has nothing left to replace: what it covered is now covered by the "
                    . 'documents that survive it, or the order no longer holds it. Void it instead.',
                key:     'COM_ALFA_REPLACEMENT_REFUSED_NOTHING_LEFT',
                values:  [(string) $source->number_formatted],
            );
        }

        // The preview's own refusal keeps its operator words; one without
        // them is told as the replacement's.
        if (!$built->preview->valid) {
            throw (string) $built->preview->refusal_key !== ''
                ? new DocumentRefusedException(
                    message: (string) $built->preview->reason,
                    key:     (string) $built->preview->refusal_key,
                    values:  (array) $built->preview->refusal_values,
                )
                : new DocumentRefusedException(
                    message: (string) $built->preview->reason,
                    key:     'COM_ALFA_REPLACEMENT_REFUSED_PREVIEW',
                    values:  [(string) $source->number_formatted],
                );
        }

        if (!hash_equals($expectedFingerprint, self::draftFingerprint($built->draft, $built->preview))) {
            throw new DocumentRefusedException(
                message: 'the replacement no longer matches the preview that was confirmed: the order or its '
                    . 'documents changed since. Review the replacement again before issuing it.',
                key:     'COM_ALFA_REPLACEMENT_REFUSED_PREVIEW_CHANGED',
                values:  [(string) $source->number_formatted],
            );
        }
    }

    /**
     * Undo a level that keeps nothing, without letting the cleanup replace the
     * failure it may follow.
     *
     * A preview releases the source's claims inside a level of its own and
     * always undoes it: the level's savepoint, or the whole transaction when
     * the level is the outermost one. A level that is no longer open is not
     * rolled back again, so nothing is closed twice on either driver.
     *
     * A deadlock has ALREADY ended the transaction on the server, and a
     * savepoint lost with it makes `ROLLBACK TO SAVEPOINT` an error in its own
     * right. The level is then closed as every failed level is, through
     * TransactionState::rollBackLevel(): the whole transaction is ended on the
     * server and in the driver's count alike, so every later statement in the
     * request runs in a real transaction again.
     *
     * It REPORTS that escalation. A full ROLLBACK ends every level at once, so
     * a caller that only meant to leave a savepoint no longer holds the
     * transaction it opened, and must not carry on as if it did.
     *
     * @param object $db The driver.
     *
     * @return bool True when no transaction is left on the connection.
     *
     * @since   2.0.0
     */
    private static function rollbackQuietly(object $db): bool
    {
        if (!TransactionState::isOpen($db)) {
            return true;
        }

        try {
            $db->transactionRollback(true);
        } catch (Throwable $savepointGone) {
            self::logQuietly(
                entry:    'DocumentIssuer: a savepoint rollback was refused (' . $savepointGone->getMessage()
                          . '): the server most likely rolled the transaction back already; ending it in full.',
                priority: Log::WARNING,
                category: 'com_alfa.documents',
            );

            return TransactionState::rollBackLevel(db: $db, failure: $savepointGone);
        }

        return !TransactionState::isOpen($db);
    }

    /**
     * Write a file log line that can never change the outcome it reports.
     *
     * FormattedtextLogger throws when its file cannot be written, and Log does
     * not catch it. On a catch path that would mask the failure being handled;
     * after a COMMIT it would report a durable act as failed. The database
     * audit rows are the authority; a file line is an aid, so it is dropped
     * rather than allowed to decide anything.
     *
     * @param string $entry The line.
     * @param int $priority A Log:: priority.
     * @param string $category The category.
     *
     *
     * @since   2.0.0
     */
    private static function logQuietly(string $entry, int $priority, string $category): void
    {
        try {
            Log::add(entry: $entry, priority: $priority, category: $category);
        } catch (Throwable) {
            // Unwritable log: the outcome stands as it is.
        }
    }

    /**
     * A document named for a log line: its number and its id, or the id alone.
     *
     * @param object|null $document The row, when it was read.
     * @param int $id Its id.
     *
     *
     * @since   2.0.0
     */
    private static function describe(?object $document, int $id): string
    {
        $number = (string) ($document->number_formatted ?? '');

        return $number !== '' ? "document {$number} (#{$id})" : "document #{$id}";
    }

    /**
     * The identity of a previewed draft: what the operator saw, in minor units.
     *
     * Void & Replace previews first and commits later, in two requests. The
     * contract forbids recomputing the replacement after confirmation by a
     * second path, and it forbids issuing something other than what was shown.
     * replace() therefore rebuilds the draft the one way it is ever built,
     * fingerprints it, and refuses unless it matches the fingerprint of the
     * preview the operator confirmed. A change to the order in between is then
     * a refusal to review again, never a silent difference.
     *
     * The ARITHMETIC, the identity fields that decide it, and the BUYER block
     * as the header will freeze it are hashed. The buyer is editable on a
     * replacement, so a buyer other than the one
     * previewed must fail here exactly as a changed figure does. Money is
     * taken at eight decimals so a float's printed form cannot make two equal
     * figures differ.
     *
     * Each line's saving is hashed with its totals: a recalculation can move a
     * saving to its final remainder while the line's net, tax and totals stay
     * equal, and a note must never print a saving its preview did not show.
     *
     * @param array $draft The draft that would be issued.
     * @param object $preview Its preview.
     *
     * @return string A hex digest.
     *
     * @since   2.0.0
     */
    public static function draftFingerprint(array $draft, object $preview): string
    {
        $money = static fn ($value): string => number_format((float) $value, 8, '.', '');

        $lines = array_map(
            static fn (object $line): array => [
                (string) ($line->line_type ?? ''),
                (int) ($line->id_source ?? 0),
                (int) ($line->id_order_item ?? 0),
                number_format((float) ($line->quantity ?? 0), 6, '.', ''),
                $money($line->total_tax_excl ?? 0),
                $money($line->tax_amount ?? 0),
                $money($line->total_tax_incl ?? 0),
                number_format((float) ($line->tax_rate ?? 0), 3, '.', ''),
                $money($line->discount_tax_excl ?? 0),
            ],
            $preview->lines ?? [],
        );

        return hash('sha256', (string) json_encode([
            'doc_type' => (string) ($draft['doc_type'] ?? ''),
            'id_order' => (int) ($draft['id_order'] ?? 0),
            'id_series' => (int) ($draft['id_series'] ?? 0),
            'id_corrects' => (int) ($draft['id_corrects'] ?? 0),
            'id_replaces' => (int) ($draft['id_replaces'] ?? 0),
            'lines' => $lines,
            'totals' => [
                $money($preview->totals->excl ?? 0),
                $money($preview->totals->tax ?? 0),
                $money($preview->totals->incl ?? 0),
            ],
            'buyer' => self::buyerBlock($draft),
        ]));
    }

    /**
     * The currency a document's money is computed and frozen in: the order's.
     *
     * A credit does not use it. A credit is computed and frozen in the money of
     * the document it corrects, DocumentMoney::currencyOf() of that document.
     *
     * The order's own, through the one reader of it ({@see OrderCurrency}).
     * Resolved by id and state-agnostically, because a historical order may
     * point at a currency the merchant has since unpublished.
     *
     * AT THE ORDER'S FROZEN SCALE, not today's. The document freezes the same
     * scale, so the money it computes now and the money it renders later are in
     * one scale for the life of the record. No fallback when the order has
     * none: a pre-v1 order's scale is unknown, and inventing one here would put
     * today's configuration into a fiscal record.
     *
     * An order that names no currency is issued nothing. No other currency is
     * put in its place: a fiscal document in a currency its order never named
     * cannot be taken back.
     *
     * Shared by issue() and preview() so a previewed figure and the figure that
     * is finally frozen can never be computed in different money.
     *
     * @param object $order The locked (or read) order row.
     *
     * @return \Alfa\Component\Alfa\Site\Service\Pricing\Currency
     *
     * @throws DocumentRefusedException When the order names no currency.
     *
     * @since  2.0.0
     */
    private static function documentCurrency(object $order): object
    {
        try {
            return OrderCurrency::ofRow(
                orderId:    (int) $order->id,
                currencyId: $order->id_currency,
                scale:      $order->currency_scale,
            );
        } catch (OrderCurrencyException $e) {
            throw new DocumentRefusedException(
                message:  'DocumentIssuer: order #' . (int) $order->id . ' names no currency (' . $e->detail() . ').',
                key:      OrderCurrency::REFUSAL_KEY,
                previous: $e,
            );
        }
    }

    /**
     * Header totals from normalized lines.
     *
     * @param stdClass[] $lines Normalized line rows.
     *
     * @return object {excl, tax, incl}
     *
     * @since  2.0.0
     */
    private static function sumTotals(array $lines): object
    {
        // The exact sums of the lines' figures, each already money at the
        // scale, as the texts the header's columns are handed.
        $exact = static fn (array $figures): string => StoredDecimal::of(array_reduce(
            $figures,
            static fn (ExactAmount $sum, $figure): ExactAmount => $sum->plus(ExactAmount::ofNumber($figure)),
            ExactAmount::of('0'),
        ));

        return (object) [
            'excl' => $exact(array_column($lines, 'total_tax_excl')),
            'tax' => $exact(array_column($lines, 'tax_amount')),
            'incl' => $exact(array_column($lines, 'total_tax_incl')),
        ];
    }

    /**
     * Group normalized lines into per-rate tax summary rows.
     *
     * @param stdClass[] $lines Normalized line rows.
     *
     * @return stdClass[]
     *
     * @since  2.0.0
     */
    private static function buildTaxSummary(array $lines): array
    {
        $groups = [];

        foreach ($lines as $line) {
            if ((float) $line->total_tax_incl === 0.0 && (float) $line->total_tax_excl === 0.0) {
                continue;
            }

            $key = number_format((float) $line->tax_rate, 3, '.', '') . '|' . ($line->vat_category ?? '');

            if (!isset($groups[$key])) {
                $row = new stdClass();
                $row->id_document = 0;
                $row->tax_rate = (float) $line->tax_rate;
                $row->vat_category = $line->vat_category;
                $row->exemption_code = $line->vat_exemption_code;
                $row->base_tax_excl = StoredDecimal::of(ExactAmount::of('0'));
                $row->tax_amount = StoredDecimal::of(ExactAmount::of('0'));
                $groups[$key] = $row;
            }

            // Added on the digits of figures already at the scale, never in
            // floats, and kept as the texts the rate's row is handed.
            $groups[$key]->base_tax_excl = StoredDecimal::of(ExactAmount::ofNumber($groups[$key]->base_tax_excl)->plus(ExactAmount::ofNumber($line->total_tax_excl)));
            $groups[$key]->tax_amount = StoredDecimal::of(ExactAmount::ofNumber($groups[$key]->tax_amount)->plus(ExactAmount::ofNumber($line->tax_amount)));
        }

        return array_values($groups);
    }

    /**
     * The buyer block exactly as a document header freezes it.
     *
     * The seven column values insertHeader() writes, in column order: the five
     * texts and the country as strings, the address JSON-encoded or null. It is
     * the ONE normaliser of the buyer, so the preview shows it, the fingerprint
     * hashes it and verifyReplacement() compares against it in the same form
     * the row stores; "the exact previewed values are what issue persists"
     * holds by construction, not by agreement.
     *
     * @param array $draft The draft (its buyer_* keys; anything else is ignored).
     *
     * @return array{buyer_name: string, buyer_vat_number: string, buyer_tax_office: string,
     *        buyer_activity: string, buyer_country: string, buyer_email: string,
     *        buyer_address: string|null}
     *
     * @since   2.0.0
     */
    public static function buyerBlock(array $draft): array
    {
        return [
            'buyer_name' => (string) ($draft['buyer_name'] ?? ''),
            'buyer_vat_number' => (string) ($draft['buyer_vat_number'] ?? ''),
            'buyer_tax_office' => (string) ($draft['buyer_tax_office'] ?? ''),
            'buyer_activity' => (string) ($draft['buyer_activity'] ?? ''),
            'buyer_country' => (string) ($draft['buyer_country'] ?? ''),
            'buyer_email' => (string) ($draft['buyer_email'] ?? ''),
            'buyer_address' => isset($draft['buyer_address']) && \is_array($draft['buyer_address'])
                ? json_encode($draft['buyer_address'], JSON_UNESCAPED_UNICODE) : null,
        ];
    }

    /**
     * The buyer a document froze, as the seven buyer_* draft keys.
     *
     * The ONE reader of a document's frozen buyer. A credit is made out to the
     * buyer of the document it corrects, as that document froze it,
     * so every path that drafts one (the order screen,
     * Void & Replace, a proposed correction, the returns workflow) reads it
     * here, and guardQuantities() compares against it. Only the immutable
     * document row is read, never the order or the customer.
     *
     * The address is decoded back into the array the header encoded, so
     * buyerBlock() gives back the stored bytes. A stored address that is not a
     * JSON object is refused rather than dropped: carrying it as "no address"
     * would silently change a frozen buyer.
     *
     * @param object $document The `#__alfa_documents` row.
     *
     * @return array{buyer_name: string, buyer_vat_number: string, buyer_tax_office: string,
     *        buyer_activity: string, buyer_country: string, buyer_email: string,
     *        buyer_address: array|null}
     *
     * @throws RuntimeException When the stored address is not a JSON object.
     *
     * @since   2.0.0
     */
    public static function frozenBuyer(object $document): array
    {
        $stored = $document->buyer_address ?? null;
        $address = null;

        if ($stored !== null && $stored !== '') {
            $address = json_decode((string) $stored, true);

            if (!\is_array($address)) {
                throw new RuntimeException(sprintf(
                    'document %s (#%d) holds a buyer address that is not a JSON object, so its buyer cannot be carried.',
                    (string) ($document->number_formatted ?? ''),
                    (int) ($document->id ?? 0),
                ));
            }
        }

        return [
            'buyer_name' => (string) ($document->buyer_name ?? ''),
            'buyer_vat_number' => (string) ($document->buyer_vat_number ?? ''),
            'buyer_tax_office' => (string) ($document->buyer_tax_office ?? ''),
            'buyer_activity' => (string) ($document->buyer_activity ?? ''),
            'buyer_country' => (string) ($document->buyer_country ?? ''),
            'buyer_email' => (string) ($document->buyer_email ?? ''),
            'buyer_address' => $address,
        ];
    }

    /**
     * Insert the document header with every frozen snapshot block.
     *
     * `status` and `id_replaces` are written explicitly: neither column has a
     * database default, so nothing about a document's lifecycle or its
     * provenance is ever decided by the absence of a write.
     *
     * A credit's destination is frozen here when it is the customer's credit
     * account, the one destination a note holds headroom for; every other
     * header carries none.
     *
     * @since  2.0.0
     */
    private static function insertHeader(
        object $db,
        string $docType,
        array $draft,
        object $order,
        object $series,
        int $number,
        string $numberFormatted,
        string $issueDateUtc,
        string $issueDateLocal,
        object $totals,
    ): int {
        $actorId = (int) ($draft['created_by'] ?? self::currentUserId());

        $doc = new stdClass();
        $doc->doc_type = $docType;
        // Stated, never implied: the column carries no default, so the
        // lifecycle state of every document is written by the path that
        // creates it. Issuance has exactly one outcome: an issued document.
        $doc->status = DocumentHelper::STATUS_ISSUED;
        $doc->is_combined = (int) !empty($draft['is_combined']);
        $doc->id_series = (int) $series->id;
        $doc->number = $number;
        // Numbering year: part of uq_series_number so a year-scoped counter can
        // restart at 1 each shop-tz year without colliding with prior years.
        // Derived from the SAME local date the year-reset above used; 0 keeps
        // non-scoped series globally unique exactly as before.
        $doc->number_year = ((int) $series->year_scoped === 1) ? (int) substr($issueDateLocal, 0, 4) : 0;
        $doc->number_formatted = $numberFormatted;
        $doc->issue_date = $issueDateUtc;
        $doc->issue_date_local = $issueDateLocal;
        $doc->id_order = (int) $order->id;
        $doc->id_shipment = ((int) ($draft['id_shipment'] ?? 0)) ?: null;
        $doc->id_corrects = ((int) ($draft['id_corrects'] ?? 0)) ?: null;

        // Replacement provenance, and ONLY from an explicit replacement intent
        // in the draft: the Void & Replace workflow is the single path allowed
        // to set it. A manual void followed by ordinary issuance leaves it
        // NULL even when the resulting monetary state is identical; invented
        // provenance is worse than none. The inverse, `replaced_by`, is DERIVED
        // (WHERE id_replaces = :id) and never persisted.
        $doc->id_replaces = ((int) ($draft['id_replaces'] ?? 0)) ?: null;

        // Where the credit's money is sent, frozen with its number. The
        // customer's credit account is paid after the note is committed, and
        // until then the note holds its amount against what the order can
        // still give back: the choice is part of the note and is never
        // rewritten. No other destination is frozen, and only a credit has one.
        // A screen names one only where it can be frozen, so any other reaches
        // this point through a draft that was amended on its way here: staff
        // read the sentence of a document changed while it was issued, and the
        // message stays the technical one.
        $destination = $draft['credit_destination'] ?? null;

        if ($destination !== null) {
            if (!\in_array($docType, DocumentHelper::creditTypes(), true)) {
                throw new DocumentRefusedException(
                    message: "credit_destination is set on '{$docType}', which is not a credit document; only a credit sends money back.",
                    key:     'COM_ALFA_CREDIT_DESTINATION_NOT_KEPT',
                );
            }

            if ($destination !== CustomerCreditLedger::CREDIT_NOTE_DESTINATION) {
                throw new DocumentRefusedException(
                    message: 'credit_destination ' . json_encode($destination) . ' is not a destination a credit note is frozen with.',
                    key:     'COM_ALFA_CREDIT_DESTINATION_NOT_KEPT',
                );
            }
        }

        $doc->credit_destination = $destination;

        // A credit is stamped with the language of the document it corrects
        // (guardQuantities() has refused a draft
        // naming another). Every other type takes the draft's, or the site's.
        $isCredit = \in_array($docType, DocumentHelper::creditTypes(), true);
        $doc->language = $isCredit
            ? DocumentHelper::creditLanguageTag(correctsId: (int) $draft['id_corrects'])
            : (string) ($draft['language'] ?? DocumentHelper::defaultLanguageTag());
        $doc->id_currency = (int) $order->id_currency;
        $doc->currency_code = (string) ($draft['currency_code'] ?? self::currencyCode(db: $db, currencyId: (int) $order->id_currency));
        $doc->conversion_rate = (float) $order->conversion_rate;

        // THE SCALE THIS DOCUMENT'S MONEY MEANS, FROZEN AT ISSUE beside the
        // currency facts this table already freezes.
        //
        // An issued document is a fiscal record: what it printed is what it
        // printed. Its totals, its correction and credit caps, its rounding
        // and every later document computed from it must all speak the scale
        // it was issued under, never the current #__alfa_currencies row,
        // which is configuration for NEW money.
        //
        // Inherited from the ORDER, not re-read from the currency table, so a
        // document and the order it documents can never disagree about the
        // scale their shared money is in. A CREDIT inherits it from the
        // document it corrects, whose money it gives back. NULL stays
        // NULL: a pre-v1 record cannot lend a scale it never recorded, and 0
        // is a real zero-decimal scale that must stay distinguishable from its
        // absence.
        $scaleSource = $isCredit ? self::load(db: $db, documentId: (int) $draft['id_corrects']) : $order;
        $doc->currency_scale = $scaleSource->currency_scale === null ? null : (int) $scaleSource->currency_scale;

        // Buyer snapshot: draft wins, enterable at issue (spec 1g). The one
        // normaliser, so what is previewed and fingerprinted is what lands.
        foreach (self::buyerBlock($draft) as $column => $value) {
            $doc->{$column} = $value;
        }

        // Seller snapshot: frozen from the SERIES' issuing identity at
        // THIS moment (the series row is locked by this transaction).
        $doc->seller_name = (string) ($series->seller_name ?? '');
        $doc->seller_vat_number = (string) ($series->seller_vat_number ?? '');
        $doc->seller_tax_office = (string) ($series->seller_tax_office ?? '');
        $doc->seller_activity = (string) ($series->seller_activity ?? '');
        $doc->seller_address = (string) ($series->seller_address ?? '') !== ''
            ? json_encode(['text' => (string) $series->seller_address], JSON_UNESCAPED_UNICODE) : null;
        $doc->seller_extra = (string) ($series->seller_extra ?? '') !== ''
            ? json_encode(['text' => (string) $series->seller_extra], JSON_UNESCAPED_UNICODE) : null;

        $doc->payment_method_name = (string) ($draft['payment_method_name'] ?? $order->payment_method_name ?? '');
        $doc->authority_payment_type = isset($draft['authority_payment_type']) ? (int) $draft['authority_payment_type'] : null;

        $doc->movement_purpose = ($draft['movement_purpose'] ?? null) !== null ? (string) $draft['movement_purpose'] : null;
        $doc->carrier_name = ($draft['carrier_name'] ?? null) !== null ? (string) $draft['carrier_name'] : null;
        $doc->vehicle_number = ($draft['vehicle_number'] ?? null) !== null ? (string) $draft['vehicle_number'] : null;
        $doc->movement_start = ($draft['movement_start'] ?? null) !== null ? (string) $draft['movement_start'] : null;
        $doc->ship_from = isset($draft['ship_from']) && \is_array($draft['ship_from'])
            ? json_encode($draft['ship_from'], JSON_UNESCAPED_UNICODE) : null;
        $doc->ship_to = isset($draft['ship_to']) && \is_array($draft['ship_to'])
            ? json_encode($draft['ship_to'], JSON_UNESCAPED_UNICODE) : null;

        $doc->total_tax_excl = $totals->excl;
        $doc->total_tax = $totals->tax;
        $doc->total_tax_incl = $totals->incl;

        $doc->authority_state = 0;
        $doc->note = ($draft['note'] ?? null) !== null ? (string) $draft['note'] : null;
        $doc->print_note = ($draft['print_note'] ?? null) !== null ? (string) $draft['print_note'] : null;

        $doc->created = $issueDateUtc;
        $doc->created_by = $actorId;
        $doc->modified = $issueDateUtc;
        $doc->modified_by = $actorId;

        $db->insertObject('#__alfa_documents', $doc, 'id');

        return (int) $doc->id;
    }

    /**
     * Order-trail entry, strict: it throws, so the document rolls back with its record.
     *
     * The actor is the one the operation names, never the identity that
     * happens to be loaded.
     *
     * @param object $db Database driver.
     * @param int $orderId The order id.
     * @param int $statusId The order's status at the time.
     * @param string $event Dot-notation event.
     * @param string $summary Human-readable summary.
     * @param array $context Structured data.
     * @param int $actorId The acting user; 0 = System.
     * @param bool $refuseOversize Refuse a record too large to store whole (since 2.0.0).
     *
     * @since  2.0.0
     */
    private static function logAction(
        object $db,
        int $orderId,
        int $statusId,
        string $event,
        string $summary,
        array $context,
        int $actorId,
        bool $refuseOversize = false,
    ): void {
        // Through the shared writer: bounded fields and one severity policy.
        // Strict, because issuance is inside its transaction: a fiscal
        // document and its record belong together or not at all.
        OrderModel::logOrderActivityStrict(
            orderId:        $orderId,
            event:          $event,
            summary:        $summary,
            context:        $context,
            entityId:       (int) ($context['id_document'] ?? 0) ?: null,
            actorId:        $actorId,
            refuseOversize: $refuseOversize,
        );
    }

    /**
     * Prove a credit's charge record against the lines just stored.
     *
     * Re-read on the issuing connection, which sees the note's own lines: what
     * every recorded shipping and fee source, and every shipping-class rule,
     * has left must be exactly the record's `remaining_after`.
     *
     * @param array $record The decision record.
     * @param int $correctsId The corrected document.
     * @param string $number The credit's number.
     *
     * @throws RuntimeException On the first figure that does not reconcile.
     *
     * @since  2.0.0
     */
    private static function verifyCreditCharges(array $record, int $correctsId, string $number): void
    {
        $currency = DocumentMoney::currencyOf(DocumentHelper::getById($correctsId));
        $charges = OrderChargeLines::correctedCharges(correctsId: $correctsId);
        $left = [];

        foreach ([
            DocumentHelper::LINE_SHIPPING => $charges->shipping,
            DocumentHelper::LINE_FEE => $charges->fees,
            DocumentHelper::LINE_ADJUSTMENT => $charges->rules,
        ] as $type => $candidates) {
            foreach ($candidates as $candidate) {
                $left[$type][(int) $candidate->id] = Money::of(abs((float) $candidate->remaining_incl), $currency)->getMinorUnits();
            }
        }

        $figures = [];

        foreach ($record['entries'] ?? [] as $entry) {
            $figures[] = [(string) $entry['line_type'], (int) $entry['id_source'], $entry];
        }

        foreach ($record['shipping_discounts'] ?? [] as $rule) {
            $figures[] = [DocumentHelper::LINE_ADJUSTMENT, (int) $rule['id_source'], $rule];
        }

        // A record figure read exactly: its digits, never through a float.
        $recorded = static fn (string $amount): int => (str_starts_with($amount, '-') ? -1 : 1) * (int) str_replace(['-', '.'], '', $amount);

        foreach ($figures as [$type, $id, $figure]) {
            $reread = $left[$type][$id] ?? 0;

            if ($reread !== $recorded((string) $figure['remaining_after'])) {
                $shown = Money::ofMinor($reread, $currency)->getAmount();

                throw new RuntimeException(
                    "the charge record of {$number} does not reconcile for {$type} #{$id}: {$figure['remaining_before']} - {$figure['credited_now']} != {$shown}.",
                );
            }
        }
    }

    /**
     * Load a document header (optionally FOR UPDATE inside a transaction).
     *
     * @since  2.0.0
     */
    private static function load(object $db, int $documentId, bool $forUpdate = false): object
    {
        $db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__alfa_documents')
            . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $documentId
            . ($forUpdate ? ' FOR UPDATE' : ''),
        );
        $document = $db->loadObject();

        if ($document === null) {
            throw new RuntimeException("document #{$documentId} does not exist.");
        }

        return $document;
    }

    /**
     * Load a document's lines in sort order.
     *
     * @since  2.0.0
     */
    private static function loadLines(object $db, int $documentId): array
    {
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_document_items'))
            ->where($db->quoteName('id_document') . ' = :docId')
            ->order($db->quoteName('sort_order') . ' ASC, ' . $db->quoteName('id') . ' ASC')
            ->bind(':docId', $documentId);
        $db->setQuery(query: $query);

        return $db->loadObjectList() ?: [];
    }

    /**
     * ISO currency code for a currency PK ('' when unknown).
     *
     * @since  2.0.0
     */
    private static function currencyCode(object $db, int $currencyId): string
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('code'))
            ->from($db->quoteName('#__alfa_currencies'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $currencyId);
        $db->setQuery(query: $query);

        return (string) $db->loadResult();
    }

    /**
     * Import the alfa feature-plugin group and dispatch a document event,
     * log-and-continue on listener failure (non-blocking contract).
     *
     * @since  2.0.0
     */
    private static function dispatch(string $name, object $event): void
    {
        try {
            PluginHelper::importPlugin('alfa');
            Factory::getApplication()->getDispatcher()->dispatch($name, $event);
        } catch (Throwable $e) {
            self::logQuietly(
                entry:    "{$name} listener failed: " . $e->getMessage() . "\n" . $e->getTraceAsString(),
                priority: Log::ERROR,
                category: 'com_alfa.documents',
            );
        }
    }

    /**
     * Current identity id (0 in CLI/unauthenticated contexts).
     *
     * @since  2.0.0
     */
    private static function currentUserId(): int
    {
        try {
            return (int) (Factory::getApplication()->getIdentity()?->id ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Current identity display name ('System' outside a session).
     *
     * @since  2.0.0
     */
    private static function currentUserName(): string
    {
        try {
            return (string) (Factory::getApplication()->getIdentity()?->name ?: 'System');
        } catch (Throwable) {
            return 'System';
        }
    }

    /**
     * Tell the merchant a document's stock movement did not happen.
     *
     * The document stands (a numbered fiscal document is never undone by a stock
     * problem) and the movement it owes is still recorded, so the notice points at
     * the Tools action that applies it and clears itself once it lands.
     *
     * @param int $documentId The issued document.
     * @param string $number Its formatted number, for the title.
     * @param string $reason Why the movement failed.
     * @param mixed $notice The same reason as the notification's parameter, when it has its own words (language keys
     *                      and their values); null for the reason as written.
     *
     *
     * @since  2.0.0
     */
    private static function reportStockDeferred(int $documentId, string $number, string $reason, mixed $notice = null): void
    {
        self::logQuietly(
            entry:    "Document #{$documentId}: stock effect deferred: {$reason}"
                      . ' (the obligation remains in the outbox; Tools -> Database Sync applies it).',
            priority: Log::ERROR,
            category: 'com_alfa.inventory',
        );

        try {
            NotificationHelper::push(
                InventoryEffectOutbox::documentNoticeKey($documentId),
                NotificationText::of('COM_ALFA_NOTIFY_STOCK_DOCUMENT_TITLE', $number !== '' ? $number : '#' . $documentId),
                InventoryEffectOutbox::noticeOptions(NotificationText::of('COM_ALFA_NOTIFY_STOCK_DOCUMENT_MSG', $notice ?? $reason)),
            );
        } catch (Throwable) {
            // Advisory only: a failed notification never un-issues a document.
        }
    }
}
