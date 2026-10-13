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
use Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailHelper;
use Joomla\CMS\String\PunycodeHelper;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;
use RuntimeException;
use Throwable;

/**
 * Void & Replace: the source is withdrawn and a new document issued in its place, as one act.
 *
 * The document reconciliation contract, section 4B and section 14.
 * This class PROPOSES and hands the commit to
 * DocumentIssuer::replace(), which owns the transaction; it computes no money,
 * exactly as IncrementalCorrection computes none.
 *
 * THE REPLACEMENT IS A NEW ISSUANCE. It takes the type's DEFAULT
 * series, draws a new number, and is dated when it is issued. The original's
 * series, sequence and date are frozen facts of the voided original and are
 * never copied. Provenance is `id_replaces` on the replacement, the inverse
 * derived; `id_corrects` is never used for it.
 *
 * ITS CONTENT IS THE CURRENT TARGET MINUS WHAT SURVIVES (section 4B amendment), never
 * a clone of the original. For a sales document that is what the order holds
 * now minus what the documents that survive the void already cover, computed
 * by releasing the source's claims and reading the ordinary pools, so it is the
 * same arithmetic an ordinary issue meets. For a credit note it is the negative
 * drift that remains once the note's own claim is released, against the same
 * corrected document: its credit axis, which is what id_corrects is for.
 *
 * THE BUYER DEFAULTS FROM THE SOURCE AND THE OPERATOR MAY EDIT IT.
 * The default is the source's frozen buyer block, all seven
 * columns, never the current, mutable order or customer: a tax id is never
 * derivable from the order (auto-issue says so), and re-deriving would
 * silently drop what an operator entered by hand. Before issue the operator
 * may change the name, VAT number, tax office, activity and email; the
 * country and address are carried unchanged. The edit is part of the draft,
 * so it shows in the preview, the fingerprint covers it, and the issuer
 * verifies that exactly the previewed block landed. The issuer's normal buyer
 * and document validation applies to it as to any draft, and an EDITED email
 * must be an email address: the one check the browser used to make, made
 * here, where it cannot block a frozen email nobody touched. The source is
 * only ever read.
 *
 * A CREDIT NOTE'S REPLACEMENT IS NOT THE NOTE'S BUYER, AND IS NOT EDITED:
 * a credit is made out to the buyer of the document it
 * corrects, as that document froze it, so the replacement of a credit note
 * takes the corrected invoice's buyer, all seven columns, and operator edits
 * are ignored. The issuer refuses any other buyer on a credit.
 *
 * @since  2.0.0
 */
final class VoidAndReplace
{
    /** @var string @since 2.0.0 */
    public const REASON_NO_REPLACEMENT_TARGET = 'no_replacement_target';

    /** @var string @since 2.0.0 */
    public const REASON_REFUND_SETTLED = 'refund_settled';

    /** @var string @since 2.0.0 */
    public const REASON_WALLET_SPENT = 'wallet_credit_spent';

    /** @var string @since 2.0.0 */
    public const REASON_ISSUER_REFUSED = 'issuer_refused';

    /**
     * Documents are issued by an external system (`documents_issuer`), so
     * com_alfa voids and issues nothing.
     *
     * @var string
     * @since 2.0.0
     */
    public const REASON_ISSUER_EXTERNAL = 'issuer_external';

    /**
     * The operator changed the buyer's email to something that is not an
     * email address.
     *
     * @var string
     * @since 2.0.0
     */
    public const REASON_INVALID_BUYER_EMAIL = 'invalid_buyer_email';

    /**
     * There is no buyer the new document can be made out to unchanged: the
     * buyer its source froze cannot be read back (DocumentIssuer::frozenBuyer()
     * refuses it), or a credit note names no document it corrects. Not
     * correctable by an edit (country and address are carried, never edited),
     * so a screen offers no buyer to edit. Also a correction's reason.
     *
     * @var string
     * @since 2.0.0
     */
    public const REASON_BUYER_UNAVAILABLE = 'buyer_unavailable';

    /**
     * The buyer keys an operator may edit on a replacement. Country and address
     * are carried from the source unchanged.
     *
     * @var string[]
     * @since 2.0.0
     */
    public const EDITABLE_BUYER = ['buyer_name', 'buyer_vat_number', 'buyer_tax_office', 'buyer_activity', 'buyer_email'];

    /**
     * Propose the replacement of one document, previewed, nothing mutated.
     *
     * `buyer` is the replacement's buyer block exactly as the header would
     * freeze it (the preview's own when there is a preview), and is there on a
     * refusal too, so a screen can show a refused buyer and let it be corrected.
     * For a credit note it is the corrected document's frozen buyer, and edits
     * are ignored (replacementBuyer()).
     *
     * @param int $sourceId The document to replace.
     * @param User|null $user Whose permissions to ask about; null = current identity.
     * @param array|null $buyer Operator edits over the source's buyer (EDITABLE_BUYER keys); null = none.
     *                          Ignored for a credit note.
     *
     * @return object  {available, reason, detail, source, surviving, draft, preview, fingerprint, buyer}
     *
     * @since   2.0.0
     */
    public static function propose(int $sourceId, ?User $user = null, ?array $buyer = null): object
    {
        $user ??= Factory::getApplication()->getIdentity();
        $source = DocumentHelper::getById($sourceId);

        if (!$source) {
            return self::refused(CorrectionStrategies::REASON_NOT_A_SOURCE, '', null, []);
        }

        // What the header would freeze, before the preview says it. A credit
        // note's replacement is made out to the buyer of the document it
        // corrects; a note that names none, or one whose stored buyer cannot
        // be read, has no buyer to offer and is refused below, after the
        // refusals that outrank it.
        $noBuyer = '';

        try {
            $frozenBuyer = DocumentIssuer::buyerBlock(self::replacementBuyer($source, $buyer));
        } catch (RuntimeException $e) {
            $frozenBuyer = DocumentIssuer::buyerBlock([]);
            $noBuyer = $e->getMessage();
        }

        // An external issuer owns the documents: com_alfa voids and issues none.
        if (!DocumentHelper::isIssuerEnabled()) {
            return self::refused(self::REASON_ISSUER_EXTERNAL, '', $source, $frozenBuyer);
        }

        // Validity is section 5's to decide, and its answer covers the permission,
        // the stock bar at the document level and the live correctives.
        $strategy = CorrectionStrategies::forDocument($sourceId, $user)[CorrectionStrategies::VOID_AND_REPLACE];

        if (!$strategy->available) {
            return self::refused($strategy->reason, $strategy->detail, $source, $frozenBuyer);
        }

        // Settlement, before anything is shown as possible: money that has
        // already left through a refund cannot be reversed by a document act.
        if (self::refundSettled($sourceId)) {
            return self::refused(self::REASON_REFUND_SETTLED, '', $source, $frozenBuyer);
        }

        $shortfall = self::walletShortfall($source);

        if ($shortfall !== '') {
            return self::refused(self::REASON_WALLET_SPENT, $shortfall, $source, $frozenBuyer);
        }

        if ($noBuyer !== '') {
            return self::refused(self::REASON_BUYER_UNAVAILABLE, $noBuyer, $source, $frozenBuyer);
        }

        $badEmail = self::invalidEmailEdit($source, $buyer);

        if ($badEmail !== '') {
            return self::refused(self::REASON_INVALID_BUYER_EMAIL, $badEmail, $source, $frozenBuyer);
        }

        try {
            $built = DocumentIssuer::previewReplacement(
                $sourceId,
                static fn (): ?array => self::buildDraft($source, $buyer),
            );
        } catch (Throwable $e) {
            // A refusal the issuer can put in the operator's words is shown in
            // them; its technical message stays the exception's.
            return self::refused(self::REASON_ISSUER_REFUSED, DocumentRefusedException::in($e)?->operatorText() ?? $e->getMessage(), $source, $frozenBuyer);
        }

        if ($built->draft === null) {
            return self::refused(self::REASON_NO_REPLACEMENT_TARGET, '', $source, $frozenBuyer);
        }

        // The same for a preview the issuer refused: its operator words when
        // the refusal carries them, else its own.
        if (!$built->preview->valid) {
            return self::refused(
                self::REASON_ISSUER_REFUSED,
                $built->preview->refusal_key !== ''
                    ? Text::sprintf($built->preview->refusal_key, ...$built->preview->refusal_values)
                    : $built->preview->reason,
                $source,
                $built->preview->buyer,
            );
        }

        // section 14 on the replacement's side. A source that carried no goods moved
        // nothing and may be replaced; a replacement that would carry goods on
        // a type that moves them is refused by the issuer after the fact, so
        // it is not offered here.
        if (self::draftMovesStock($built->draft)) {
            return self::refused(CorrectionStrategies::REASON_STOCK_MOVING, (string) $source->doc_type, $source, $built->preview->buyer);
        }

        return (object) [
            'available' => true,
            'reason' => '',
            'detail' => '',
            'source' => $source,
            'surviving' => self::survivingClaims($source),
            'draft' => $built->draft,
            'preview' => $built->preview,
            'fingerprint' => DocumentIssuer::draftFingerprint($built->draft, $built->preview),
            'buyer' => $built->preview->buyer,
        ];
    }

    /**
     * Carry out the replacement the operator previewed.
     *
     * The capability is asked HERE, at the service boundary, and again inside
     * DocumentIssuer::replace(), which is public and must not be a way round
     * it. The draft is rebuilt the one way it is ever built and refused unless
     * it matches the fingerprint of what was previewed.
     *
     * The buyer edit is part of the draft, so an edit other than the one
     * previewed, or a previewed edit dropped at commit, fails the fingerprint.
     * An external issuer, and an edited email that is not an email address,
     * are refused here as propose() refuses them. A credit note takes no edit.
     *
     * A caller that has a rule of its own for the replacement's money hands a
     * check, which the act asks once the source is voided and before the
     * replacement is numbered (DocumentIssuer::replace()).
     *
     * @param int $sourceId The document to replace.
     * @param string $reason Why (audit; required).
     * @param string $fingerprint The fingerprint of the confirmed preview.
     * @param User|null $user Who is acting; null = current identity.
     * @param array|null $buyer The operator's buyer edits, as previewed; null = none. Ignored for a credit note.
     * @param callable|null $beforeNumbering fn(object $source, object $preview): void - the caller's own check, handed to the act; it refuses by throwing.
     *
     * @return object The replacement document.
     *
     * @throws RuntimeException With the one condition that refused it.
     *
     * @since   2.0.0
     */
    public static function execute(int $sourceId, string $reason, string $fingerprint, ?User $user = null, ?array $buyer = null, ?callable $beforeNumbering = null): object
    {
        $user ??= Factory::getApplication()->getIdentity();

        if (!DocumentHelper::isIssuerEnabled()) {
            throw new RuntimeException(
                'Void & Replace is unavailable: documents are issued by an external system (documents_issuer), so nothing is voided or issued here.',
            );
        }

        if (!AlfaHelper::can(section: 'documents', action: CorrectionStrategies::CAPABILITY_REPLACE, user: $user)) {
            throw new RuntimeException(
                'Void & Replace requires the Void & Replace Sales Documents permission; the rights to issue or to void do not include it.',
            );
        }

        $source = DocumentHelper::getById($sourceId);

        if (!$source) {
            throw new RuntimeException("Document #{$sourceId} does not exist.");
        }

        $badEmail = self::invalidEmailEdit($source, $buyer);

        if ($badEmail !== '') {
            throw new RuntimeException($badEmail);
        }

        return DocumentIssuer::replace(
            sourceId:            $sourceId,
            reason:              $reason,
            draftBuilder:        static fn (): ?array => self::buildDraft($source, $buyer),
            expectedFingerprint: $fingerprint,
            actorId:             (int) $user->id,
            beforeNumbering:     $beforeNumbering,
        );
    }

    /**
     * The replacement draft: the current target minus what survives the void.
     *
     * Called only with the source's claims RELEASED (inside
     * DocumentIssuer::previewReplacement() or replace()), so the pools it reads
     * are the pools the replacement will actually meet. There is something to
     * replace when items OR non-item charges (shipping, fees) remain to be
     * covered; null when neither does, when a sales replacement's charges alone
     * remain and net to exactly zero at the order's frozen scale, and when a
     * credit replacement comes to 0,00 without being a quantity correction
     * (CreditCalculator::zeroOutcome()).
     *
     * A credit replacement is a credit, so it is refused, never answered null,
     * while another credit note of the order carries a charge line that nothing
     * still active on its invoice accounts for. replace() builds here under the
     * order lock, so that refusal is asked again when the act is executed. It
     * is told as CorrectionStrategies::replacementUnavailable() tells it: the
     * note still accounts for those notes, or this replacement is refused
     * naming them.
     *
     * @param object $source The source document row.
     * @param array|null $buyer Operator edits over the source's buyer; null = none. Ignored for a credit note.
     *
     *
     * @throws DocumentRefusedException When the replacement is unavailable, naming the notes that make it so.
     * @throws RuntimeException When a credit note names no corrected document to take the buyer from.
     *
     * @since   2.0.0
     */
    public static function buildDraft(object $source, ?array $buyer = null): ?array
    {
        $orderId = (int) $source->id_order;
        $docType = (string) $source->doc_type;
        $isCredit = \in_array($docType, DocumentHelper::creditTypes(), true);
        $correctsId = $isCredit ? (int) $source->id_corrects : 0;

        // Replacing a credit note issues a credit, so it is refused while
        // another note of the order carries a charge line nothing still active
        // accounts for. The note being replaced is already released here, so
        // replacing that note itself stays possible. The refusal is told in the
        // shape it has, as the strategy screen tells it.
        if ($isCredit) {
            $unrecorded = OrderChargeLines::unrecordedCreditNotes(orderId: $orderId);

            if ($unrecorded !== []) {
                $why = CorrectionStrategies::replacementUnavailable(note: $source, notes: $unrecorded);

                throw new DocumentRefusedException(
                    message: $why->message,
                    key:     $why->key,
                    values:  $why->values,
                );
            }
        }

        // Then the buyer: a credit note that names no document it corrects is
        // refused by that name before anything is read from the document.
        $replacementBuyer = self::replacementBuyer($source, $buyer);
        // A NEW issuance: a sales replacement takes the normal policy's
        // language, a credit replacement the language of the document it
        // corrects; the type's default series,
        // never the source's series.
        $language = $isCredit ? DocumentHelper::creditLanguageTag(correctsId: $correctsId) : DocumentHelper::defaultLanguageTag();
        $seriesId = (int) DocumentHelper::defaultSeriesId($docType);

        if ($seriesId <= 0) {
            throw new RuntimeException("no default numbering series exists for '{$docType}', so a replacement has nowhere to be numbered.");
        }

        $picks = [];

        if ($isCredit) {
            // What still needs crediting once this note's own claim is
            // released, bounded by what the document it corrects still has on
            // that line.
            $pool = OrderItemLines::remainingForCredit(correctsId: $correctsId);

            foreach (IncrementalCorrection::lineDrift($orderId)['negative'] as $lineId => $needed) {
                $allowed = min($needed, (float) ($pool[$lineId]->remaining_qty ?? 0.0));

                if ($allowed > 0) {
                    $picks[$lineId] = ['selected' => 1, 'quantity' => $allowed];
                }
            }
        } else {
            // What the order holds now minus what the surviving documents
            // already cover: exactly the sales pool with the source released.
            $pool = DocumentHelper::remainingQuantities(orderId: $orderId, docType: $docType);

            foreach ($pool as $lineId => $row) {
                if ((float) $row['remaining'] > 0) {
                    $picks[$lineId] = ['selected' => 1, 'quantity' => (float) $row['remaining']];
                }
            }
        }

        $chargePicks = IncrementalCorrection::chargePicks($orderId, $docType, $correctsId);

        // A charge alone is a target too: a replacement may cover only the
        // shipping or a fee. Discount rules never are on their own.
        if ($picks === [] && ($chargePicks['shipping'] ?? []) === [] && ($chargePicks['fees'] ?? []) === []) {
            return null;
        }

        $lines = DocumentDraft::lines(
            orderId:     $orderId,
            docType:     $docType,
            picks:       $picks,
            chargePicks: $chargePicks,
            correctsId:  $correctsId,
            language:    $language,
        );

        // A zero-total replacement is no target either, exactly as the
        // correction proposer rules: a shipping cut the free-shipping discount
        // absorbs whole gives back a shipping line and its discount, and a
        // replacement of them would burn a number. A credit is judged by the
        // one zero rule: 0,00 is issued only as a quantity
        // correction. A sales replacement keeps the charge-only test.
        $totalIncl = array_sum(array_map(static fn (array $line): float => (float) $line['total_tax_incl'], $lines));

        if ($isCredit) {
            if (CreditCalculator::zeroOutcome(
                orderId:    $orderId,
                correctsId: $correctsId,
                lines:      $lines,
                totalIncl:  $totalIncl,
                record:     [],
            ) === CreditOutcome::NothingRefundable) {
                return null;
            }
        } elseif ($picks === [] && OrderChargeLines::isZeroAtOrderScale(orderId: $orderId, amount: $totalIncl)) {
            return null;
        }

        $draft = [
            'doc_type' => $docType,
            'id_order' => $orderId,
            'id_series' => $seriesId,
            'language' => $language,
            'lines' => $lines,
        ] + $replacementBuyer;

        if ($correctsId > 0) {
            // The replacement CREDIT's own credit axis: which document it
            // corrects. Its provenance is id_replaces, added by the issuer.
            $draft['id_corrects'] = $correctsId;
        }

        return $draft;
    }

    /**
     * The documents that survive the void and keep their claims, in the source's coverage family.
     *
     * @param object $source The source document row.
     *
     * @return array<int, object> {id, number_formatted, doc_type, total_tax_incl}
     *
     * @since   2.0.0
     */
    public static function survivingClaims(object $source): array
    {
        $isCredit = \in_array((string) $source->doc_type, DocumentHelper::creditTypes(), true);
        $family = $isCredit ? DocumentHelper::creditTypes() : DocumentHelper::salesTypes();
        $out = [];

        foreach (DocumentHelper::getForOrder((int) $source->id_order) as $document) {
            if ((int) $document->id === (int) $source->id
                || (int) $document->status !== DocumentHelper::STATUS_ISSUED
                || !\in_array((string) $document->doc_type, $family, true)) {
                continue;
            }

            // A surviving credit only competes with this one when it draws on
            // the same corrected document.
            if ($isCredit && (int) $document->id_corrects !== (int) $source->id_corrects) {
                continue;
            }

            $out[] = (object) [
                'id' => (int) $document->id,
                'number_formatted' => (string) $document->number_formatted,
                'doc_type' => (string) $document->doc_type,
                'total_tax_incl' => (float) $document->total_tax_incl,
            ];
        }

        return $out;
    }

    /**
     * Is this note settled, or being settled, by a refund?
     *
     * @param int $documentId The document.
     *
     *
     * @since   2.0.0
     */
    private static function refundSettled(int $documentId): bool
    {
        // The ledger's one definition: a refund that still stands, net of the
        // reversals a provider reported.
        return \Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger::documentHasLiveRefund(documentId: $documentId);
    }

    /**
     * The wallet can no longer give back what this note put in, or '' when it can.
     *
     * The void claws the note's wallet credit back through the wallet ledger
     * and refuses when the balance is short. Asked by the screen before the
     * operator commits, and by DocumentIssuer::replace() under the order
     * lock before it voids anything; the ledger's own guard, under the
     * account lock, remains the authority.
     *
     * @param object $source The source document row.
     *
     * @return string What the note credited and what the account still holds, in the
     *                order's money, as staff read it under the refusal, or ''.
     *
     * @since   2.0.0
     */
    public static function walletShortfall(object $source): string
    {
        if (!\in_array((string) $source->doc_type, DocumentHelper::creditTypes(), true)) {
            return '';
        }

        $net = CustomerCreditLedger::creditNoteNetMoney((int) $source->id);

        if (!$net->isPositive()) {
            return '';
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $creditNote = CustomerCreditLedger::SOURCE_CREDIT_NOTE;
        $sourceId = (int) $source->id;

        $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id_user'))
                ->from($db->quoteName('#__alfa_customer_credit_transactions'))
                ->where($db->quoteName('source_type') . ' = :type')
                ->where($db->quoteName('source_id') . ' = :id')
                ->order($db->quoteName('id') . ' ASC')
                ->bind(':type', $creditNote)
                ->bind(':id', $sourceId, ParameterType::INTEGER),
            0,
            1,
        );
        $userId = (int) $db->loadResult();

        // Both in the wallet's money, compared on minor units: the account
        // holds what the note credited, or it is short by at least one unit.
        $balance = CustomerCreditLedger::balance($userId);

        if (!Money::of($balance, $net->getCurrency())->lessThan($net)) {
            return '';
        }

        // Both figures in the order's currency at the scale it froze, as every
        // other amount staff read about the order.
        $currency = OrderCurrency::of(orderId: (int) $source->id_order);

        return sprintf(
            '%s credited, %s left in the customer\'s credit account',
            Money::of($net->getAmount(), $currency)->format(),
            Money::of($balance, $currency)->format(),
        );
    }

    /**
     * The replacement's buyer: the source's frozen block, with the operator's edits over it.
     *
     * The WHOLE block, all seven keys, empty values included: dropping an
     * empty one would only have the header write it back as empty, and an
     * explicit key says what the replacement carries. Edits overlay the
     * EDITABLE_BUYER keys only, tested for presence rather than value, so a
     * field the operator cleared becomes empty instead of reverting to the
     * source. Only the immutable source row is read, never the order.
     *
     * Public because the replacement modal's controller must know what the
     * modal displayed, to tell an untouched field from an edit. The frozen
     * block is read through the one reader, DocumentIssuer::frozenBuyer().
     *
     * @param object $source The source document row.
     * @param array|null $edits Operator edits; null = none.
     *
     * @return array buyer_* draft keys.
     *
     * @since   2.0.0
     */
    public static function buyerOf(object $source, ?array $edits = null): array
    {
        $buyer = DocumentIssuer::frozenBuyer($source);

        foreach (self::EDITABLE_BUYER as $key) {
            if ($edits !== null && \array_key_exists($key, $edits)) {
                $buyer[$key] = (string) $edits[$key];
            }
        }

        return $buyer;
    }

    /**
     * The buyer the replacement of this source is made out to.
     *
     * A sales document's: its own frozen block with the operator's edits over
     * it (buyerOf()). A credit note's: the frozen buyer of the document it
     * CORRECTS, all seven columns, edits ignored: a credit is made out to the
     * buyer of what it corrects, never the note's own buyer and never an edit
     * made here; the issuer refuses any other.
     *
     * @param object $source The source document row.
     * @param array|null $edits Operator edits; null = none. Ignored for a credit note.
     *
     * @return array buyer_* draft keys.
     *
     * @throws RuntimeException When a credit note names no corrected document that exists.
     *
     * @since   2.0.0
     */
    public static function replacementBuyer(object $source, ?array $edits = null): array
    {
        if (!self::isCredit($source)) {
            return self::buyerOf($source, $edits);
        }

        $correctsId = (int) ($source->id_corrects ?? 0);
        $corrected = $correctsId > 0 ? DocumentHelper::getById($correctsId) : null;

        if ($corrected === null) {
            throw new RuntimeException(sprintf(
                'credit note %s names no document it corrects, so there is no buyer to make its replacement out to.',
                (string) ($source->number_formatted ?? ''),
            ));
        }

        return DocumentIssuer::frozenBuyer($corrected);
    }

    /**
     * Is this source a credit document?
     *
     * @param object $source The source document row.
     *
     *
     * @since   2.0.0
     */
    public static function isCredit(object $source): bool
    {
        return \in_array((string) ($source->doc_type ?? ''), DocumentHelper::creditTypes(), true);
    }

    /**
     * Why an EDITED buyer email is not an email address, or '' when it is, or when it was not edited.
     *
     * Only an edit is asked: an email carried unchanged from the source is a
     * frozen fact and is never re-validated, whatever a browser would make of
     * it. An emptied email is no address to check. The domain is asked in its
     * ASCII form, so an internationalised domain typed in Unicode is accepted
     * as the email field shows it. A credit note takes no edit, so there is
     * nothing to ask.
     *
     * @param object $source The source document row.
     * @param array|null $edits Operator edits; null = none.
     *
     *
     * @since   2.0.0
     */
    private static function invalidEmailEdit(object $source, ?array $edits): string
    {
        if (self::isCredit($source)) {
            return '';
        }

        $email = self::buyerOf($source, $edits)['buyer_email'];

        if ($email === (string) ($source->buyer_email ?? '') || $email === '') {
            return '';
        }

        return MailHelper::isEmailAddress(PunycodeHelper::emailToPunycode($email))
            ? ''
            : "buyer_email '{$email}' is not an email address.";
    }

    /**
     * Would issuing this replacement draft move physical stock?
     *
     * The draft-side twin of DocumentHelper::movesStock(): the type's effect,
     * and an item line for it to move.
     *
     * @param array $draft The replacement draft.
     *
     *
     * @since   2.0.0
     */
    private static function draftMovesStock(array $draft): bool
    {
        if (DocumentHelper::stockEffectForDocument((string) $draft['doc_type'], !empty($draft['is_combined']))
            === DocumentHelper::STOCK_EFFECT_NONE) {
            return false;
        }

        foreach ($draft['lines'] as $line) {
            if (($line['line_type'] ?? DocumentHelper::LINE_ITEM) === DocumentHelper::LINE_ITEM) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $reason The one condition.
     * @param string $detail What it is about.
     * @param object|null $source The source row, when there is one.
     * @param array $buyer The replacement's buyer block as the header would freeze it.
     *
     *
     * @since   2.0.0
     */
    private static function refused(string $reason, string $detail, ?object $source, array $buyer): object
    {
        return (object) [
            'available' => false,
            'reason' => $reason,
            'detail' => $detail,
            'source' => $source,
            'surviving' => [],
            'draft' => null,
            'preview' => null,
            'fingerprint' => '',
            'buyer' => $buyer,
        ];
    }
}
