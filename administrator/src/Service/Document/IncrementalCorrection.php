<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderIdentity;
use Alfa\Component\Alfa\Administrator\Service\Order\ReconciliationFacts;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use RuntimeException;
use Throwable;

/**
 * Incremental correction: keep the source document, issue only the difference.
 *
 * The document reconciliation contract, section 4A: "Keep the valid
 * source document and issue only the difference... This is the normal/default
 * recommended strategy."
 *
 * WHAT THIS CLASS IS, AND WHAT IT IS NOT. It is a PROPOSER. It reads the facts,
 * decides which direction the drift runs, turns that into picks, and hands them
 * to the SAME assembler and the SAME preview the manual path uses. It computes
 * no money of its own, not one figure, because section 7 and section 19 forbid a proposal
 * calculator standing beside a manual one.
 *
 *   ReconciliationFacts  -> what the drift IS
 *   CorrectionStrategies -> whether correcting is ALLOWED
 *   DocumentDraft        -> how the draft is ASSEMBLED
 *   DocumentIssuer       -> what it WOULD BE (preview) and what it BECOMES (issue)
 *
 * THE TWO DIRECTIONS, and they take different documents:
 *
 *  - NEGATIVE DRIFT (section 16): the order is now below what active documents cover.
 *    A credit note against ONE named source document, for the difference, never
 *    for more than that source's own remaining credit pool. Both bounds apply:
 *    the drift says what is NEEDED, the pool says what is PERMITTED, and the
 *    smaller wins. Its type is the one credit type paired with the source's
 *    type (DocumentHelper::pairedCreditType()); none or several is refused.
 *
 *  - POSITIVE DRIFT (section 15): the order is now above what active documents cover.
 *    Another ORDINARY sales document of the existing target type, covering only
 *    the undocumented delta and never restating the documented part. An order
 *    with no sales document takes the one type the automatic-issue rules of
 *    the shop's statuses choose for its buyer; none, or two that disagree, is
 *    refused, and so is a type that is disabled. Per the
 *    design, core v1 invents no supplementary or debit
 *    type, and `id_corrects` is NOT overloaded for it: that column is the
 *    credit/correction-cap axis and means something else.
 *
 * DRIFT IS ITEMS AND CHARGES. Shipping and
 * fee pools drift on their own (a parcel re-priced, a fee lowered) with no
 * item quantity moving, and no item line is manufactured to carry that. The
 * charge drift is measured by OrderChargeLines::chargeDrift(), in money at the
 * order's frozen scale; this class only turns it into picks. Item and charge
 * drift in one direction share ONE draft. The negative direction is proposed
 * first, and the positive one once the negative is credited. A charge drift
 * that gives nothing back (a shipping cut the shipping discount absorbs
 * whole) is not a negative trigger, so it never stands in front of a
 * positive one; alone, it is refused as REASON_ZERO_TOTAL.
 *
 * THE BUYER follows the direction. A credit is made
 * out to the corrected document's frozen buyer and is not edited here; a
 * wrong buyer is a Void & Replace case. An additional sales document is made
 * out to the order's buyer as it stands at preview, resolved as a hand-issued
 * document's is, never an earlier document's. Either way it is in the draft,
 * so the preview shows it and the fingerprint binds it; a buyer the type's
 * requirements are not met by is named (REASON_BUYER_INSUFFICIENT).
 *
 * SEMANTIC DRIFT IS NOT CORRECTED HERE (section 17). A tax-rate or identity mismatch
 * is a DEFECT that no signed amount expresses; ReconciliationFacts says so in
 * its own words, that "the sum would be right and the document would still be
 * wrong". Manufacturing a money delta for it would be the "meaningless
 * arithmetic delta" the contract forbids, so the proposal comes back
 * unavailable with the mismatch named. A shipping or fee charge whose tax rate
 * changed after it was invoiced is one too: a credit at the old ratio would
 * clear the gross and leave the VAT wrong.
 *
 * @since  1.3.0
 */
final class IncrementalCorrection
{
    /** @var string @since 1.3.0 */
    public const DIRECTION_NEGATIVE = 'negative';

    /** @var string @since 1.3.0 */
    public const DIRECTION_POSITIVE = 'positive';

    /** @var string @since 1.3.0 */
    public const DIRECTION_NONE = 'none';

    /** @var string @since 1.3.0 */
    public const REASON_NO_DRIFT = 'no_drift';

    /** @var string @since 1.3.0 */
    public const REASON_SEMANTIC_MISMATCH = 'semantic_mismatch';

    /** @var string @since 1.3.0 */
    public const REASON_NO_SOURCE = 'no_source_document';

    /** @var string @since 1.3.0 */
    public const REASON_POOL_EXHAUSTED = 'pool_exhausted';

    /** @var string @since 1.3.0 */
    public const REASON_NO_SERIES = 'no_series';

    /**
     * No enabled credit type is paired with the corrected document's type, so
     * no credit type is known to correct it and nothing is issued.
     *
     * @var string
     * @since 1.14.0
     */
    public const REASON_NO_CREDIT_TYPE = 'no_credit_type';

    /**
     * More than one enabled credit type is paired with the corrected
     * document's type; which one corrects it is not chosen for the shop.
     *
     * @var string
     * @since 1.14.0
     */
    public const REASON_CREDIT_TYPE_AMBIGUOUS = 'credit_type_ambiguous';

    /**
     * The only credit types paired with the corrected document's type are
     * disabled, so none is issued: the refusal names them.
     *
     * @var string
     * @since 1.14.0
     */
    public const REASON_CREDIT_TYPE_DISABLED = 'credit_type_disabled';

    /**
     * The order has no issued sales document, and the automatic-issue rules
     * of its status choose no sales type for it.
     *
     * @var string
     * @since 1.14.0
     */
    public const REASON_NO_SALES_TYPE = 'no_sales_type';

    /**
     * The order's issued sales documents are of more than one type, so the
     * type of an additional one is not known.
     *
     * @var string
     * @since 1.14.0
     */
    public const REASON_SALES_TYPE_AMBIGUOUS = 'sales_type_ambiguous';

    /**
     * The automatic-issue rules of two statuses choose different sales types
     * for the order's buyer, so the type of an additional document is not
     * known.
     *
     * @var string
     * @since 1.14.0
     */
    public const REASON_SALES_RULES_DISAGREE = 'sales_rules_disagree';

    /**
     * The sales type an additional document would be issued as is disabled.
     *
     * @var string
     * @since 1.14.0
     */
    public const REASON_SALES_TYPE_DISABLED = 'sales_type_disabled';

    /**
     * The charge drift nets to nothing: a document for it would total zero.
     *
     * A shipping cut on a free-shipping order is the case in point: the
     * discount the order no longer applies matches the shipping it no longer
     * charges, and the order's total has not moved. Issuing it would burn a
     * gapless number on a document that moves no money. A credit is judged by
     * CreditCalculator::zeroOutcome(): a 0,00 credit is issued only when every
     * unit it carries is quantity the order no longer holds. A sales draft
     * keeps the charge-only test.
     *
     * @var string
     * @since 1.14.0
     */
    public const REASON_ZERO_TOTAL = 'zero_total';

    /**
     * The correction confirmed is no longer the one previewed.
     *
     * The order changed between the preview and the confirmation (a parcel
     * re-priced, a fee edited, a line changed), so the correction derived now
     * is not what the operator reviewed. Nothing is issued; it is reviewed
     * again.
     *
     * @var string
     * @since 1.14.0
     */
    public const REASON_PREVIEW_CHANGED = 'preview_changed';

    /**
     * A discount drifted in a way no amount can correct on its own: goods were
     * already credited, so its share of the goods kept is stored nowhere, or
     * its tax semantic changed (OrderChargeLines::discountDrift()). Named, as
     * section 17 names a semantic mismatch, rather than corrected with money.
     *
     * @var string
     * @since 1.14.0
     */
    public const REASON_DISCOUNT_NOT_ADDITIVE = 'discount_not_additive';

    /**
     * The buyer the correction would be made out to lacks what its type requires.
     *
     * A type with requires_buyer_tax_id needs a buyer name and tax ID, and the
     * buyer this direction takes has neither made up nor borrowed: a credit
     * keeps the corrected document's frozen buyer (a wrong one is a Void &
     * Replace case), and an additional sales document takes the order's buyer,
     * which holds no tax ID; it is issued by hand, where one is entered.
     *
     * @var string
     * @since 1.14.0
     */
    public const REASON_BUYER_INSUFFICIENT = 'buyer_insufficient';

    /**
     * Propose the incremental correction for an order, previewed but not issued.
     *
     * An available proposal carries the fingerprint of its preview
     * (DocumentIssuer::draftFingerprint()); the confirmation posts it back to
     * issue(). A refused one carries ''.
     *
     * @param int $orderId The order to reconcile.
     * @param int $sourceDocumentId The document a NEGATIVE correction credits; ignored when positive.
     * @param User|null $user Whose permissions to ask about; null = current identity.
     *
     * @return object  {available, reason, detail, direction, draft, preview, fingerprint}
     *
     * @since   1.3.0
     */
    public static function propose(int $orderId, int $sourceDocumentId = 0, ?User $user = null): object
    {
        $user ??= Factory::getApplication()->getIdentity();
        $facts = ReconciliationFacts::forOrder($orderId);

        // section 17: a semantic mismatch is a defect that no signed amount expresses.
        // Reported before anything else, because issuing money against it would
        // clear the arithmetic and leave the document just as wrong.
        if (!empty($facts->mismatches)) {
            return self::refused(
                self::REASON_SEMANTIC_MISMATCH,
                implode(',', array_unique(array_map(
                    static fn (object $m): string => (string) $m->kind,
                    $facts->mismatches,
                ))),
                self::DIRECTION_NONE,
            );
        }

        $drift = self::lineDrift($orderId);
        // Charges drift on their own (a parcel re-priced, a fee lowered) with
        // no item quantity moving. Measured
        // by the charge-pool authority, in money, never here.
        $charges = OrderChargeLines::chargeDrift(orderId: $orderId);
        // A charge drift that gives nothing back is no reason to credit: it
        // would only stand in front of a positive drift, from every entrance.
        // A discount the order came to give after its documents is money
        // owed back just as a lowered charge is.
        $chargesOwed = (self::drifted($charges->negative) && !$charges->offset) || $charges->negative['grants'] !== [];
        $chargesDue = self::drifted($charges->positive) || $charges->positive['reversals'] !== [];

        // A discount drift no amount can correct on its own is named: alone,
        // it is the answer; beside another drift, it rides the proposal.
        if ($charges->blocked !== [] && $drift['negative'] === [] && $drift['positive'] === [] && !$chargesOwed && !$chargesDue) {
            $refusal = self::refused(self::REASON_DISCOUNT_NOT_ADDITIVE, self::blockedDetail($charges->blocked), self::DIRECTION_NONE);
            $refusal->blocked_discounts = $charges->blocked;

            return $refusal;
        }

        // NEGATIVE: credit the difference against one named source
        if ($drift['negative'] !== [] || $chargesOwed) {
            if ($sourceDocumentId <= 0) {
                return self::refused(self::REASON_NO_SOURCE, '', self::DIRECTION_NEGATIVE);
            }

            $strategy = CorrectionStrategies::forDocument($sourceDocumentId, $user)[CorrectionStrategies::INCREMENTAL];

            if (!$strategy->available) {
                return self::refused($strategy->reason, $strategy->detail, self::DIRECTION_NEGATIVE);
            }

            // An order whose credits cannot be sized is unavailable for every
            // credit, before anything is calculated (charge
            // scope). The reason names the notes.
            $unrecorded = OrderChargeLines::unrecordedCreditNotes(orderId: $orderId);

            if ($unrecorded !== []) {
                return self::refused(
                    CorrectionStrategies::REASON_CREDIT_UNAVAILABLE,
                    implode(', ', array_column($unrecorded, 'number_formatted')),
                    self::DIRECTION_NEGATIVE,
                );
            }

            $docType = self::creditTypeFor($sourceDocumentId);

            if ($docType === '') {
                return self::pairingRefusal(orderId: $orderId, sourceDocumentId: $sourceDocumentId);
            }

            // A credit is issued in the language of the document it corrects
            // and the language is resolved before its lines.
            try {
                $language = DocumentHelper::creditLanguageTag(correctsId: $sourceDocumentId);
            } catch (Throwable $e) {
                return self::refused('issuer_refused', $e->getMessage(), self::DIRECTION_NEGATIVE);
            }

            // A credit is made out to whoever the document it corrects was, as
            // that document froze them; never edited on this path.
            // A frozen buyer the reader cannot carry (an
            // address that is not a JSON object) is refused by name, never
            // dropped and never an error page.
            try {
                $buyer = VoidAndReplace::buyerOf(DocumentHelper::getById($sourceDocumentId));
            } catch (RuntimeException $e) {
                return self::refused(VoidAndReplace::REASON_BUYER_UNAVAILABLE, $e->getMessage(), self::DIRECTION_NEGATIVE);
            }
            // What the drift NEEDS, bounded by what the named document still has on that line.
            $pool = OrderItemLines::remainingForCredit(correctsId: $sourceDocumentId);
            $picks = [];

            foreach ($drift['negative'] as $lineId => $needed) {
                $allowed = min($needed, (float) ($pool[$lineId]->remaining_qty ?? 0.0));

                if ($allowed > 0) {
                    $picks[$lineId] = ['selected' => 1, 'quantity' => $allowed];
                }
            }

            $credit = self::creditCharges($orderId, $sourceDocumentId, $charges);

            // Refused only when this source can give back NEITHER goods nor a
            // charge. A charge-only credit is a credit: no item line is
            // manufactured to carry it. The drift it could not take is still
            // named, with its amount.
            if ($picks === [] && !self::drifted($credit->picks['amounts']) && $credit->picks['amounts']['grants'] === []) {
                $refusal = self::refused(self::REASON_POOL_EXHAUSTED, '', self::DIRECTION_NEGATIVE);
                $refusal->unabsorbed_charges = $credit->unabsorbed;

                return $refusal;
            }

            $proposal = self::proposal(
                direction:   self::DIRECTION_NEGATIVE,
                orderId:     $orderId,
                docType:     $docType,
                picks:       $picks,
                chargePicks: $credit->picks,
                correctsId:  $sourceDocumentId,
                language:    $language,
                buyer:       $buyer,
            );
            // Drift this document cannot give back (charged on another one, or
            // beyond what this one has left) is named, never guessed onto it.
            $proposal->unabsorbed_charges = $credit->unabsorbed;
            $proposal->blocked_discounts = $charges->blocked;

            return $proposal;
        }

        // POSITIVE: another ordinary sales document for the delta
        if ($drift['positive'] !== [] || $chargesDue) {
            $salesType = self::positiveTypeFor(orderId: $orderId);

            if ($salesType->refusal !== null) {
                return $salesType->refusal;
            }

            $docType = $salesType->type;

            $mayIssue = AlfaHelper::can(section: 'orders', action: 'edit', user: $user)
                && AlfaHelper::can(section: 'documents', action: 'create', user: $user);

            if (!$mayIssue) {
                return self::refused(
                    CorrectionStrategies::REASON_NO_PERMISSION,
                    'orders.edit + documents.create',
                    self::DIRECTION_POSITIVE,
                );
            }

            // An EMPTY pick is a charge-only draft, not "every item": the sales
            // builder reads only null as everything.
            $picks = [];

            foreach ($drift['positive'] as $lineId => $delta) {
                $picks[$lineId] = ['selected' => 1, 'quantity' => $delta];
            }

            // An additional sales document keeps the normal policy's language.
            $language = DocumentHelper::defaultLanguageTag();

            $proposal = self::proposal(
                direction:   self::DIRECTION_POSITIVE,
                orderId:     $orderId,
                docType:     $docType,
                picks:       $picks,
                chargePicks: self::salesCharges($orderId, $charges),
                // NOT id_corrects: that column is the credit/correction-cap
                // axis. A supplementary sales document is an ordinary issuance
                // against the order, and overloading it would put a
                // non-credit into the cumulative credit cap's arithmetic.
                correctsId:  0,
                language:    $language,
                buyer:       self::orderBuyer($orderId),
            );
            $proposal->blocked_discounts = $charges->blocked;

            return $proposal;
        }

        // Only a charge drift that nets to nothing is left: named, never issued.
        if ($charges->offset) {
            return self::refused(
                self::REASON_ZERO_TOTAL,
                implode(', ', $charges->labels['shipping']),
                self::DIRECTION_NEGATIVE,
            );
        }

        return self::refused(self::REASON_NO_DRIFT, '', self::DIRECTION_NONE);
    }

    /**
     * Issue the correction the operator previewed, and nothing else.
     *
     * section 6: what is committed is the exact result that was shown. The preview
     * and the confirmation are two requests, and the order may change between
     * them. So the proposal is derived again here, the one way it is ever
     * derived, and issued only when its fingerprint is the one the confirmed
     * preview carried; otherwise nothing is issued and the refusal is
     * REASON_PREVIEW_CHANGED. Void & Replace binds its confirmation the same
     * way (DocumentIssuer::replace()).
     *
     * @param int $orderId The order to reconcile.
     * @param int $sourceId The document a negative correction credits; 0 when none is named.
     * @param string $fingerprint The fingerprint of the preview the operator confirmed.
     * @param User|null $user Whose permissions to ask about; null = current identity.
     *
     * @return object  {issued: ?object, proposal: object} - issued is the
     *                                                     issued document row, null when nothing was issued, and
     *                                                     proposal is the one issued or the refusal.
     *
     * @throws RuntimeException When the issuer refuses the draft.
     *
     * @since   1.14.0
     */
    public static function issue(int $orderId, int $sourceId, string $fingerprint, ?User $user = null): object
    {
        $proposal = self::propose($orderId, $sourceId, $user);

        if (!$proposal->available) {
            return (object) ['issued' => null, 'proposal' => $proposal];
        }

        if (!hash_equals($proposal->fingerprint, $fingerprint)) {
            return (object) [
                'issued' => null,
                'proposal' => self::refused(self::REASON_PREVIEW_CHANGED, '', $proposal->direction),
            ];
        }

        return (object) ['issued' => DocumentIssuer::issue($proposal->draft), 'proposal' => $proposal];
    }

    /**
     * Does a per-kind charge map name any source?
     *
     * @param array $byKind ['shipping' => [id => amount], 'fees' => [id => amount]].
     *
     *
     * @since   1.14.0
     */
    private static function drifted(array $byKind): bool
    {
        return $byKind['shipping'] !== [] || $byKind['fees'] !== [];
    }

    /**
     * Per-line drift, in QUANTITY, split by direction.
     *
     * Read from the same authority the issuer's own guards use. The sales
     * pool's `remaining` is clamped at zero, so it answers the positive
     * direction only; the negative one is what active sales documents cover
     * MINUS what the order now carries.
     *
     * @param int $orderId The order.
     *
     * @return array{positive: array<int, float>, negative: array<int, float>}
     *
     * @since   1.3.0
     */
    public static function lineDrift(int $orderId): array
    {
        $undocumented = DocumentHelper::remainingQuantities(
            orderId: $orderId,
            docType: DocumentHelper::TYPE_INVOICE,
        );
        $sold = DocumentHelper::consumedQuantities(
            orderId:  $orderId,
            docTypes: DocumentHelper::salesTypes(),
        );
        $credited = DocumentHelper::consumedQuantities(
            orderId:  $orderId,
            docTypes: DocumentHelper::creditTypes(),
        );

        $positive = [];
        $negative = [];

        foreach ($undocumented as $lineId => $row) {
            $ordered = (float) $row['ordered'];

            // POSITIVE is bounded by what a new sales document may actually
            // claim: the sales pool, which is ordered minus what sales
            // documents already took. Crediting does NOT refill it: a credited
            // unit is not re-invoiceable, and proposing it would build a draft
            // the issuer's own guard would refuse.
            if ((float) $row['remaining'] > 0) {
                $positive[$lineId] = (float) $row['remaining'];

                continue;
            }

            // NEGATIVE is the NET documented position, not the gross one.
            // Credits already issued have given part of it back, and counting
            // only what sales documents cover would propose the same credit
            // over and over, each edit re-crediting what the last correction
            // already returned.
            $netDocumented = (float) ($sold[$lineId] ?? 0.0) - (float) ($credited[$lineId] ?? 0.0);

            if ($netDocumented > $ordered) {
                $negative[$lineId] = $netDocumented - $ordered;
            }
        }

        return ['positive' => $positive, 'negative' => $negative];
    }

    /**
     * The credit type a correction of one document is issued as.
     *
     * The one enabled credit type paired with the corrected document's type
     * (DocumentHelper::pairedCreditType()). No alias is matched and no type
     * is taken for coming first: none, or several, is no answer.
     *
     * @param int $sourceDocumentId The document being corrected.
     *
     * @return string The type alias, or '' when no single credit type corrects it.
     *
     * @since   1.3.0
     */
    private static function creditTypeFor(int $sourceDocumentId): string
    {
        $source = DocumentHelper::getById($sourceDocumentId);

        return DocumentHelper::pairedCreditType(salesType: (string) ($source->doc_type ?? ''))->alias;
    }

    /**
     * The refusal of a correction whose document no single credit type corrects.
     *
     * Decided before anything is drafted, so nothing is numbered. It names the
     * order, the document and its type, and, when several credit types are
     * paired with it, each of them; when the only types paired with it are
     * disabled, those.
     *
     * @param int $orderId The order.
     * @param int $sourceDocumentId The document being corrected.
     *
     * @return object The refusal.
     *
     * @since   1.14.0
     */
    private static function pairingRefusal(int $orderId, int $sourceDocumentId): object
    {
        $source = DocumentHelper::getById($sourceDocumentId);
        $sourceType = (string) ($source->doc_type ?? '');
        $pairing = DocumentHelper::pairedCreditType(salesType: $sourceType);
        $paired = $pairing->paired;
        $labels = static fn (array $aliases): string => implode(
            ', ',
            array_map(static fn (string $alias): string => DocumentHelper::typeLabel($alias), $aliases),
        );
        $document = Text::sprintf(
            'COM_ALFA_CORRECTION_DETAIL_DOCUMENT',
            $orderId,
            (string) ($source->number_formatted ?? ''),
            DocumentHelper::typeLabel($sourceType),
        );

        if ($paired !== []) {
            return self::refused(
                self::REASON_CREDIT_TYPE_AMBIGUOUS,
                Text::sprintf('COM_ALFA_CORRECTION_DETAIL_PAIRED', $document, $labels($paired)),
                self::DIRECTION_NEGATIVE,
            );
        }

        // Set to be corrected, by a type the merchant disabled: said so.
        if ($pairing->disabled !== []) {
            return self::refused(
                self::REASON_CREDIT_TYPE_DISABLED,
                Text::sprintf('COM_ALFA_CORRECTION_DETAIL_DISABLED', $document, $labels($pairing->disabled)),
                self::DIRECTION_NEGATIVE,
            );
        }

        return self::refused(self::REASON_NO_CREDIT_TYPE, $document, self::DIRECTION_NEGATIVE);
    }

    /**
     * The sales type a positive delta is issued as.
     *
     * The type of the order's issued sales documents, so the additional
     * document matches what was issued before it; documents of more than one
     * type are refused, never one of them picked. An order with none takes
     * the one type the automatic-issue rules of the shop's statuses choose
     * for its buyer, and is refused when they choose none or disagree. A type
     * that is disabled is refused by name: it is not issued.
     *
     * @param int $orderId The order.
     *
     * @return object  {type: string, refusal: ?object} - the sales type, or '' and the refusal.
     *
     * @since   1.3.0
     */
    private static function positiveTypeFor(int $orderId): object
    {
        $issued = [];

        foreach (DocumentHelper::getForOrder($orderId) as $document) {
            if ((int) $document->status === DocumentHelper::STATUS_ISSUED
                && \in_array((string) $document->doc_type, DocumentHelper::salesTypes(), true)) {
                $issued[(string) $document->doc_type][] = (string) $document->number_formatted;
            }
        }

        $refusal = static fn (string $reason, string $detail): object => (object) [
            'type' => '',
            'refusal' => self::refused($reason, $detail, self::DIRECTION_POSITIVE),
        ];
        $labels = static fn (array $aliases): string => implode(
            ', ',
            array_map(static fn (string $alias): string => DocumentHelper::typeLabel($alias), $aliases),
        );

        if (\count($issued) > 1) {
            $named = [];

            foreach ($issued as $type => $numbers) {
                $named[] = implode(', ', $numbers) . ' (' . DocumentHelper::typeLabel($type) . ')';
            }

            return $refusal(
                self::REASON_SALES_TYPE_AMBIGUOUS,
                Text::sprintf('COM_ALFA_CORRECTION_DETAIL_ORDER_DOCUMENTS', $orderId, implode('; ', $named)),
            );
        }

        if ($issued !== []) {
            $type = (string) array_key_first($issued);
        } else {
            $ruled = AutoIssuePolicy::salesTypesFor(orderId: $orderId);

            if ($ruled === []) {
                return $refusal(self::REASON_NO_SALES_TYPE, Text::sprintf('COM_ALFA_CORRECTION_DETAIL_ORDER', $orderId));
            }

            if (\count($ruled) > 1) {
                return $refusal(
                    self::REASON_SALES_RULES_DISAGREE,
                    Text::sprintf('COM_ALFA_CORRECTION_DETAIL_ORDER_DOCUMENTS', $orderId, $labels($ruled)),
                );
            }

            $type = $ruled[0];
        }

        if (!\in_array($type, DocumentHelper::types(), true)) {
            return $refusal(
                self::REASON_SALES_TYPE_DISABLED,
                Text::sprintf('COM_ALFA_CORRECTION_DETAIL_ORDER_DOCUMENTS', $orderId, $labels([$type])),
            );
        }

        return (object) ['type' => $type, 'refusal' => null];
    }

    /**
     * Assemble the draft through the shared assembler and preview it through the issuer.
     *
     * @param string $direction Which way the drift runs.
     * @param int $orderId The order.
     * @param string $docType The type to issue.
     * @param array $picks The per-line picks.
     * @param array $chargePicks The charge picks, in the assembler's shape.
     * @param int $correctsId The corrected document, or 0.
     * @param string $language The language frozen names are taken in.
     * @param array $buyer The buyer_* block the document is made out to.
     *
     *
     * @since   1.3.0
     */
    private static function proposal(
        string $direction,
        int $orderId,
        string $docType,
        array $picks,
        array $chargePicks,
        int $correctsId,
        string $language,
        array $buyer,
    ): object {
        $seriesId = (int) DocumentHelper::defaultSeriesId($docType);

        // Named by the label the operator knows; the alias rides beside it.
        if ($seriesId <= 0) {
            $refusal = self::refused(self::REASON_NO_SERIES, DocumentHelper::typeLabel($docType), $direction);
            $refusal->doc_type = $docType;

            return $refusal;
        }

        $missing = self::missingBuyer($docType, $buyer);

        if ($missing !== []) {
            return self::refused(self::REASON_BUYER_INSUFFICIENT, implode(', ', $missing), $direction);
        }

        // The buyer is part of the draft, so the preview shows it and the
        // fingerprint binds it: the buyer previewed is the buyer issued.
        $draft = $buyer + [
            'doc_type' => $docType,
            'id_order' => $orderId,
            'id_series' => $seriesId,
            'language' => $language,
            'lines' => DocumentDraft::lines(
                orderId:     $orderId,
                docType:     $docType,
                picks:       $picks,
                chargePicks: $chargePicks,
                correctsId:  $correctsId,
                language:    $language,
            ),
        ];

        if ($correctsId > 0) {
            $draft['id_corrects'] = $correctsId;
        }

        $preview = DocumentIssuer::preview($draft);

        // One zero rule for a credit: a 0,00 credit is issued only
        // when every unit it carries is quantity the order no longer holds. A
        // sales draft keeps its own test: a charge-only draft that moves no
        // money is not a correction, and would burn a gapless number.
        if ($correctsId > 0) {
            if ($preview->valid
                && CreditCalculator::zeroOutcome(
                    orderId:    $orderId,
                    correctsId: $correctsId,
                    lines:      $draft['lines'],
                    totalIncl:  (float) $preview->totals->incl,
                    record:     [],
                ) === CreditOutcome::NothingRefundable) {
                return self::refused(self::REASON_ZERO_TOTAL, '', $direction);
            }
        } elseif ($preview->valid
            && $picks === []
            && OrderChargeLines::isZeroAtOrderScale(orderId: $orderId, amount: (float) $preview->totals->incl)) {
            return self::refused(self::REASON_ZERO_TOTAL, '', $direction);
        }

        return (object) [
            'available' => $preview->valid,
            'reason' => $preview->valid ? '' : 'issuer_refused',
            'detail' => $preview->reason,
            'direction' => $direction,
            'draft' => $draft,
            'preview' => $preview,
            'fingerprint' => $preview->valid ? DocumentIssuer::draftFingerprint($draft, $preview) : '',
        ];
    }

    /**
     * The charge picks a proposed draft carries, in the assembler's shape.
     *
     * A SALES draft claims every shipping and fee source the order's sales pool
     * still holds (exactly the positive charge drift) and lists the rules
     * whole, because the builder sizes each discount by what the draft claims
     * and skips one with nothing left. A CREDIT draft claims the negative
     * charge drift the corrected document can give back, each source with its
     * amount (see creditCharges()).
     *
     * @param int $orderId The order.
     * @param string $docType The type being drafted.
     * @param int $correctsId The corrected document, or 0.
     *
     * @return array{shipping: int[], fees: int[], rules: int[], amounts?: array}
     *
     * @since   1.3.0
     */
    public static function chargePicks(int $orderId, string $docType, int $correctsId): array
    {
        $charges = OrderChargeLines::chargeDrift(orderId: $orderId);

        return $correctsId > 0
            ? self::creditCharges($orderId, $correctsId, $charges)->picks
            : self::salesCharges($orderId, $charges);
    }

    /**
     * A credit's charge picks against one corrected document, and the drift it cannot absorb.
     *
     * A CREDIT CLAIMS THE CHARGE DRIFT, NOT THE CHARGE HEADROOM. Claiming every
     * corrected charge with headroom left once proposed, for an order edited
     * from four units to three, a credit for the unit AND the invoice's whole
     * shipping: shipping the order still carried, unchanged. Each source is
     * now picked with the amount it drifted by: all of it when the order no
     * longer carries the charge, the reduction when it carries less. The
     * builder caps that at what the document has left. The shipping discount
     * rides it only as far as the order no longer applies it (`rules`).
     *
     * @param int $orderId The order.
     * @param int $correctsId The corrected document.
     * @param object $charges OrderChargeLines::chargeDrift() for the order.
     *
     * @return object{picks: array{shipping: int[], fees: int[], rules: int[], amounts: array}, unabsorbed: object[]}
     *                                                                                                                `amounts` is [shipping => [id => incl], fees => [id => incl], rules => [id => incl]].
     *
     * @since   1.14.0
     */
    private static function creditCharges(int $orderId, int $correctsId, object $charges): object
    {
        $credit = OrderChargeLines::creditableDrift(
            orderId: $orderId,
            drift:   $charges,
            source:  OrderChargeLines::correctedChargesFor(correctsIds: [$correctsId])[$correctsId],
        );

        // Discounts on a credit follow the goods and the shipping; the builder
        // attaches them itself and they are not the proposal's to pick.
        // A discount grant names no source line on this document: the builder
        // spreads it over the document's own lines for the rule, or its goods.
        return (object) [
            'picks' => [
                'shipping' => array_keys($credit->amounts['shipping']),
                'fees' => array_keys($credit->amounts['fees']),
                'rules' => [],
                'amounts' => $credit->amounts + ['grants' => $charges->negative['grants']],
            ],
            'unabsorbed' => $credit->unabsorbed,
        ];
    }

    /**
     * A sales draft's charge picks: the positive charge drift, and every rule.
     *
     * @param int $orderId The order.
     * @param object $charges OrderChargeLines::chargeDrift() for the order.
     *
     * @return array{shipping: int[], fees: int[], rules: int[]}
     *
     * @since   1.14.0
     */
    private static function salesCharges(int $orderId, object $charges): array
    {
        return [
            'shipping' => array_keys($charges->positive['shipping']),
            'fees' => array_keys($charges->positive['fees']),
            'rules' => array_map(
                static fn (object $rule): int => (int) $rule->id,
                OrderChargeLines::remaining(orderId: $orderId)->rules,
            ),
            // Discount documents gave that the order no longer gives, charged back.
            'amounts' => ['reversals' => $charges->positive['reversals']],
        ];
    }

    /**
     * The blocked discounts, named with their cause, for a refusal's detail.
     *
     * @param object[] $blocked OrderChargeLines::chargeDrift()->blocked.
     *
     * @return string "SAVE10 (goods_credited), WELCOME (tax_changed)"
     *
     * @since   1.14.0
     */
    private static function blockedDetail(array $blocked): string
    {
        return implode(', ', array_map(
            static fn (object $discount): string => "{$discount->label} ({$discount->reason})",
            $blocked,
        ));
    }

    /**
     * The buyer an additional sales document is made out to: the order's, now.
     *
     * Resolved the one way a hand-issued document's buyer is: OrderIdentity::
     * buyer(), which the Issue document form starts from: the invoicee when the
     * order invoices to details of its own, else the customer. Never an earlier
     * document's buyer; that one was frozen for that document. The order holds
     * no tax ID, so none is carried.
     *
     * @param int $orderId The order.
     *
     * @return array buyer_* draft keys; empty when the order cannot be read.
     *
     * @since   1.14.0
     */
    private static function orderBuyer(int $orderId): array
    {
        $order = OrderIdentity::loadOrder($orderId);

        return $order === null ? [] : OrderIdentity::buyer($order);
    }

    /**
     * What a buyer lacks that the type requires: the issuer's own rule.
     *
     * @param string $docType The type to issue.
     * @param array $buyer The buyer_* block.
     *
     * @return string[] The missing buyer_* keys; empty when nothing is missing.
     *
     * @since   1.14.0
     */
    private static function missingBuyer(string $docType, array $buyer): array
    {
        if (!DocumentHelper::requiresBuyerVat($docType)) {
            return [];
        }

        return array_values(array_filter(
            ['buyer_name', 'buyer_vat_number'],
            static fn (string $key): bool => trim((string) ($buyer[$key] ?? '')) === '',
        ));
    }

    /**
     * @param string $reason One of the REASON_* constants, or a strategy's own.
     * @param string $detail The concrete thing it is about.
     * @param string $direction Which way the drift ran, if known.
     *
     *
     * @since   1.3.0
     */
    private static function refused(string $reason, string $detail, string $direction): object
    {
        return (object) [
            'available' => false,
            'reason' => $reason,
            'detail' => $detail,
            'direction' => $direction,
            'draft' => null,
            'preview' => null,
            'fingerprint' => '',
        ];
    }
}
