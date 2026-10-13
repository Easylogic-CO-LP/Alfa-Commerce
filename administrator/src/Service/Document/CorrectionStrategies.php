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
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

/**
 * Which reconciliation strategies are valid for a document, and why the others are not.
 *
 * THE CONTRACT THIS IMPLEMENTS is the document reconciliation
 * contract, section 5: "Do not let the operator choose an invalid strategy. Alfa
 * determines whether the source document is voidable; whether active dependent
 * corrective documents exist; whether the fiscal/document provider permits
 * void/replacement; whether the requested quantities and amounts remain inside
 * authoritative source pools; whether the currency matches; whether required
 * permissions exist; whether stock/logistics consequences require separate
 * actions. The operator chooses only among strategies that are currently
 * valid."
 *
 * SO THIS CLASS DECIDES NOTHING ABOUT MONEY. It answers one question (
 * may this strategy be offered, and if not, what does the operator read), and
 * every monetary answer inside it comes from DocumentIssuer::preview(), which
 * is the issuer's own calculator (section 6, section 19). A strategy that looks affordable
 * here is affordable because the issuer said so, not because this class
 * re-derived it.
 *
 * DERIVED, NEVER PERSISTED, exactly as ReconciliationFacts is. There is no
 * strategy column, no pending-correction row and no stored verdict: section 1 says
 * drift is derived and a pending correction is never persisted merely because
 * drift exists. A request only memoises its own read of a replacement chain
 * (clearCache()).
 *
 * THE THREE STRATEGIES, and they are the contract's own:
 *
 *  - INCREMENTAL (section 4A): keep the valid source document and issue only the
 *    difference. The normal, recommended strategy. For NEGATIVE drift that is
 *    a credit note against the source (section 16); for POSITIVE drift it is another
 *    ordinary sales document covering only the undocumented delta (section 15, and
 *    the rule that core v1 invents no supplementary or
 *    debit type, and does not overload id_corrects, which is reserved for the
 *    credit/correction-cap axis).
 *
 *  - VOID_AND_REPLACE (section 4B): void the source and issue a replacement. NOT the
 *    default reaction to an order edit. It carries its own explicit ACL
 *    capability, `alfa.documents.replace`, and is never implied by the pair
 *    that authorises ordinary manual issuance.
 *
 *  - VOID (section 13): withdraw the document from active coverage, keeping it
 *    numbered, immutable and auditable. The correction of last resort.
 *
 * @since  2.0.0
 */
final class CorrectionStrategies
{
    /**
     * Keep the source document, issue only the difference.
     *
     * @var string
     * @since  2.0.0
     */
    public const INCREMENTAL = 'incremental';

    /**
     * Void the source and issue a replacement, as one coordinated workflow.
     *
     * @var string
     * @since  2.0.0
     */
    public const VOID_AND_REPLACE = 'void_and_replace';

    /**
     * Withdraw the document from active coverage, keeping its history.
     *
     * @var string
     * @since  2.0.0
     */
    public const VOID = 'void';

    /**
     * The capability Void & Replace answers to, and nothing else does.
     *
     * @var string
     * @since  2.0.0
     */
    public const CAPABILITY_REPLACE = 'replace';

    // Reasons. Each names ONE condition, so a refusal is never a shrug.

    /** @var string @since 2.0.0 */
    public const REASON_ALREADY_VOIDED = 'already_voided';

    /** @var string @since 2.0.0 */
    public const REASON_ACTIVE_CORRECTIVES = 'active_correctives';

    /** @var string @since 2.0.0 */
    public const REASON_NO_PERMISSION = 'no_permission';

    /** @var string @since 2.0.0 */
    public const REASON_NOTHING_TO_CORRECT = 'nothing_to_correct';

    /** @var string @since 2.0.0 */
    public const REASON_STOCK_MOVING = 'stock_moving';

    /** @var string @since 2.0.0 */
    public const REASON_SURVIVING_CLAIMS = 'surviving_claims';

    /** @var string @since 2.0.0 */
    public const REASON_NOT_A_SOURCE = 'not_a_source';

    /**
     * The document's replacement chain carries links the workflow never
     * writes (a cycle, a fork, a dangling link...); DocumentIssuer refuses to
     * extend it, so the strategy is not offered.
     *
     * @var string
     * @since 2.0.0
     */
    public const REASON_CORRUPT_CHAIN = 'corrupt_chain';

    /**
     * A credit note on the order carries a shipping, fee or discount amount
     * that nothing still active on the invoice it corrects accounts for, so
     * what each charge has left is not known and no credit on the order can be
     * sized. A refused Replace is never told so: it names only the notes that
     * block that Replace (REASON_CREDIT_STILL_ACCOUNTS,
     * REASON_REPLACEMENT_UNAVAILABLE). The detail names the notes.
     *
     * @var string
     * @since 2.0.0
     */
    public const REASON_CREDIT_UNAVAILABLE = 'credit_unavailable';

    /**
     * A credit note cannot be replaced while it still accounts for other
     * notes: each takes back part of a discount it gave, and with it replaced
     * nothing would account for that part. No note other than this one is
     * unaccounted for as the order stands. The detail names the notes.
     *
     * @var string
     * @since 2.0.0
     */
    public const REASON_CREDIT_STILL_ACCOUNTS = 'credit_still_accounts';

    /**
     * A credit note cannot be replaced: with it voided, nothing still active
     * on the invoice each named note corrects would account for a shipping,
     * fee or discount amount that note carries. Only this replacement is
     * refused, and voiding every named note lifts the refusal. The detail
     * names the notes.
     *
     * @var string
     * @since 2.0.0
     */
    public const REASON_REPLACEMENT_UNAVAILABLE = 'replacement_unavailable';

    /**
     * The document's order names no currency. Nothing is issued against such
     * an order and nothing of it is voided, so no strategy is offered until
     * the order is repaired. Its sentence is the one every refused command
     * on such an order shows.
     *
     * @var string
     * @since 2.0.0
     */
    public const REASON_ORDER_CURRENCY_MISSING = 'order_currency_missing';

    /**
     * The anomalies of the replacement chains read in this request, by document id.
     *
     * @var array<int, string>
     * @since 2.0.0
     */
    private static array $chainAnomalies = [];

    /**
     * Whether each order asked about in this request names no currency, by order id.
     *
     * @var array<int, bool>
     * @since 2.0.0
     */
    private static array $ordersWithoutCurrency = [];

    /**
     * Every strategy for one issued document, valid or not, each with its reason.
     *
     * The shape is deliberately total: a caller gets ALL three keys every time,
     * so a screen renders the same rows and greys out what it may not offer,
     * rather than silently dropping a strategy an operator was looking for.
     *
     * @param int $documentId The source document.
     * @param User|null $user Whose permissions to ask about; null = current identity.
     *
     * @return array<string, object> Keyed by strategy: {available: bool, reason: string, detail: string}.
     *
     * @since   2.0.0
     */
    public static function forDocument(int $documentId, ?User $user = null): array
    {
        $user ??= Factory::getApplication()->getIdentity();

        $document = DocumentHelper::getById($documentId);

        if (!$document) {
            return [
                self::INCREMENTAL => self::refused(self::REASON_NOT_A_SOURCE, ''),
                self::VOID_AND_REPLACE => self::refused(self::REASON_NOT_A_SOURCE, ''),
                self::VOID => self::refused(self::REASON_NOT_A_SOURCE, ''),
            ];
        }

        $docType = (string) $document->doc_type;
        $isVoided = (int) $document->status === DocumentHelper::STATUS_VOIDED;

        // A document that already has non-voided corrective documents naming it
        // cannot be voided: the issuer refuses, and a strategy screen must say
        // so before the operator commits rather than after.
        $activeCorrectives = self::activeCorrectiveCount($documentId);

        // An order that names no currency is issued nothing and voided
        // nothing: the issuer refuses each of the three, so none is offered.
        // Asked once, for all three.
        $noCurrency = !$isVoided && self::orderNamesNoCurrency((int) $document->id_order);

        // Void
        $void = match (true) {
            $isVoided => self::refused(self::REASON_ALREADY_VOIDED, ''),
            $noCurrency => self::refused(self::REASON_ORDER_CURRENCY_MISSING, ''),
            $activeCorrectives > 0 => self::refused(
                self::REASON_ACTIVE_CORRECTIVES,
                (string) $activeCorrectives,
            ),
            !AlfaHelper::can(section: 'documents', action: 'edit.state', user: $user)
                                    => self::refused(self::REASON_NO_PERMISSION, 'documents.edit.state'),
            default => self::available(),
        };

        // Void and replace
        // Its own capability, never the manual-issuance pair.
        // And section 14's amendment: a stock-moving document must not be offered
        // ordinary void-and-replace while issuing its replacement would execute
        // the same physical movement a second time. Until a movement-preserving
        // mechanism exists the strategy is UNAVAILABLE with the reason shown;
        // it is NOT solved by making void restore stock, nor by suppressing
        // stock effects based on where the call came from. A document that
        // carries no item line moved nothing, so it is not barred.
        //
        // A chain with anomalies is refused by the issuer, so it is not offered
        // either. Its depth never matters. The arms
        // are asked in order, so the chain is read only once nothing cheaper
        // has refused, and at most once per document in a request.
        $replace = match (true) {
            $isVoided => self::refused(self::REASON_ALREADY_VOIDED, ''),
            $noCurrency => self::refused(self::REASON_ORDER_CURRENCY_MISSING, ''),
            $activeCorrectives > 0 => self::refused(
                self::REASON_ACTIVE_CORRECTIVES,
                (string) $activeCorrectives,
            ),
            !AlfaHelper::can(section: 'documents', action: self::CAPABILITY_REPLACE, user: $user)
                                   => self::refused(self::REASON_NO_PERMISSION, 'documents.replace'),
            self::chainAnomalies($documentId) !== ''
                                   => self::refused(self::REASON_CORRUPT_CHAIN, self::chainAnomalies($documentId)),
            DocumentHelper::movesStock($document)
                                   => self::refused(self::REASON_STOCK_MOVING, $docType),
            // Replacing a credit note issues a credit, unavailable while another
            // note of the order carries a charge amount nothing still active on
            // its invoice accounts for. It is asked with this note released,
            // as its replacement is built: the note itself is not counted, so
            // it stays replaceable, and a grant it carries no longer records
            // another note's reversal. The reason is told in the shape it has.
            \in_array($docType, DocumentHelper::creditTypes(), true) && ($others = OrderChargeLines::unrecordedCreditNotes(
                orderId:   (int) $document->id_order,
                releasing: $documentId,
            )) !== []
                                   => self::refusedReplacement(note: $document, notes: $others),
            default => self::available(),
        };

        // Incremental
        // Correcting a document means issuing ANOTHER document against the same
        // order, so it asks the pair that authorises ordinary manual issuance,
        // which must NOT also carry Void & Replace.
        $mayIssue = AlfaHelper::can(section: 'orders', action: 'edit', user: $user)
            && AlfaHelper::can(section: 'documents', action: 'create', user: $user);

        $incremental = match (true) {
            $isVoided => self::refused(self::REASON_ALREADY_VOIDED, ''),
            $noCurrency => self::refused(self::REASON_ORDER_CURRENCY_MISSING, ''),
            // A credit note is not itself a source to correct incrementally:
            // the correction axis runs from a SALES document.
            !\in_array($docType, DocumentHelper::salesTypes(), true)
                      => self::refused(self::REASON_NOT_A_SOURCE, $docType),
            !$mayIssue => self::refused(self::REASON_NO_PERMISSION, 'orders.edit + documents.create'),
            default => self::available(),
        };

        return [
            self::INCREMENTAL => $incremental,
            self::VOID_AND_REPLACE => $replace,
            self::VOID => $void,
        ];
    }

    /**
     * Why the replacement of a credit note is unavailable, told in the shape it has.
     *
     * The notes given are those that refuse the replacement: nothing still
     * active accounts for an amount each carries once the note is released,
     * as its replacement is built. When no note other than this one is
     * unaccounted for while it stands, the note still accounts for them all:
     * each takes back part of a discount it gave. Otherwise the replacement
     * is refused naming the notes, with the note being replaced voided. Either
     * way only this replacement is refused, never the order's credits, since
     * the notes named block this replacement and no other credit. The
     * strategy screen, where the note is issued, and the replacement's
     * builder, where it is released, both ask here, and the answer does not
     * depend on the note's status, so the two always tell the same shape.
     *
     * @param object $note The credit note to be replaced (id, id_order, number_formatted).
     * @param array $notes The notes that refuse its replacement, as unrecordedCreditNotes() lists them; not empty.
     *
     * @return object {reason: string, detail: string, key: string, values: array, message: string}: the
     *                strategy reason and its detail, the operator sentence's key and values, and the
     *                technical message.
     *
     * @since   2.0.0
     */
    public static function replacementUnavailable(object $note, array $notes): object
    {
        $orderId = (int) $note->id_order;
        $numbers = implode(', ', array_column($notes, 'number_formatted'));

        if (OrderChargeLines::unrecordedCreditNotes(orderId: $orderId, standing: (int) $note->id) === []) {
            return (object) [
                'reason' => self::REASON_CREDIT_STILL_ACCOUNTS,
                'detail' => $numbers,
                'key' => 'COM_ALFA_CREDIT_UNAVAILABLE_STILL_ACCOUNTS',
                'values' => [(string) $note->number_formatted, $numbers],
                'message' => "credit note {$note->number_formatted} cannot be replaced: it still accounts for credit note {$numbers}, which takes back part of a discount it gave.",
            ];
        }

        return (object) [
            'reason' => self::REASON_REPLACEMENT_UNAVAILABLE,
            'detail' => $numbers,
            'key' => 'COM_ALFA_CREDIT_UNAVAILABLE_REPLACEMENT',
            'values' => [(string) $note->number_formatted, $numbers],
            'message' => "credit note {$note->number_formatted} cannot be replaced: with it voided, credit note {$numbers} carries a charge line that nothing still active on its invoice accounts for.",
        ];
    }

    /**
     * The Replace strategy refused for a credit note whose replacement is unavailable.
     *
     * @param object $note The credit note.
     * @param array $notes The notes that refuse its replacement.
     *
     * @return object  {available: false, reason: string, detail: string}
     *
     * @since   2.0.0
     */
    private static function refusedReplacement(object $note, array $notes): object
    {
        $why = self::replacementUnavailable(note: $note, notes: $notes);

        return self::refused($why->reason, $why->detail);
    }

    /**
     * Whether the document's order exists and names no currency.
     *
     * An order that has no row is not this reason's to tell: the issuer
     * refuses it for what it is. Read once per order in a request, because
     * the order screen asks every document of one order and an order's
     * currency never changes; see clearCache(). The issuer never reads the
     * memo: it reads the order's currency under the order lock.
     *
     * @param int $orderId The document's order.
     *
     *
     * @since   2.0.0
     */
    private static function orderNamesNoCurrency(int $orderId): bool
    {
        if (isset(self::$ordersWithoutCurrency[$orderId])) {
            return self::$ordersWithoutCurrency[$orderId];
        }

        try {
            OrderCurrency::of(orderId: $orderId);
            $none = false;
        } catch (OrderCurrencyException $e) {
            $none = $e->orderExists();
        }

        return self::$ordersWithoutCurrency[$orderId] = $none;
    }

    /**
     * How many non-voided documents name this one as the document they correct.
     *
     * The same question DocumentIssuer::void() asks before it refuses, asked
     * here so a screen can say it first.
     *
     * @param int $documentId The source document.
     *
     *
     * @since   2.0.0
     */
    private static function activeCorrectiveCount(int $documentId): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $voided = DocumentHelper::STATUS_VOIDED;

        $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_documents'))
                ->where($db->quoteName('id_corrects') . ' = :documentId')
                ->where($db->quoteName('status') . ' <> :voided')
                ->bind(':documentId', $documentId, ParameterType::INTEGER)
                ->bind(':voided', $voided, ParameterType::INTEGER),
        );

        return (int) $db->loadResult();
    }

    /**
     * Forget the replacement chains this request has read, and which of its
     * orders name no currency.
     *
     * A chain's anomalies are memoised per document for the request: the order
     * screen asks every document, and one modal asks the same source twice. The
     * Void & Replace workflow never writes an anomaly: it extends a chain only
     * forward, under the order lock, one successor per link, so nothing it
     * does can make a memoised answer wrong. A caller that rewrites
     * `id_replaces` itself, a repair, calls this after the write. The issuer
     * never reads the memo: it re-reads the chain under the lock.
     *
     *
     * @since   2.0.0
     */
    public static function clearCache(): void
    {
        self::$chainAnomalies = [];
        self::$ordersWithoutCurrency = [];
    }

    /**
     * The anomalies of the document's replacement chain, named, or '' when it is sound.
     *
     * Read once per document in a request; see clearCache().
     *
     * @param int $documentId The source document.
     *
     * @return string "cycle at #12, fork at #9", or ''.
     *
     * @since   2.0.0
     */
    private static function chainAnomalies(int $documentId): string
    {
        return self::$chainAnomalies[$documentId] ??= implode(', ', array_map(
            static fn (array $anomaly): string => "{$anomaly['kind']} at #{$anomaly['at']}",
            DocumentHelper::replacementChain($documentId)->anomalies,
        ));
    }

    /**
     * @return object {available: true, reason: '', detail: ''}
     *
     * @since  2.0.0
     */
    private static function available(): object
    {
        return (object) ['available' => true, 'reason' => '', 'detail' => ''];
    }

    /**
     * @param string $reason One of the REASON_* constants.
     * @param string $detail The concrete thing the reason is about, or empty.
     *
     * @return object {available: false, reason: string, detail: string}
     *
     * @since   2.0.0
     */
    private static function refused(string $reason, string $detail): object
    {
        return (object) ['available' => false, 'reason' => $reason, 'detail' => $detail];
    }
}
