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
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use RuntimeException;
use Throwable;

/**
 * The one canonical credit calculation.
 *
 * Every entrance that issues a credit (the order screen, a proposed
 * correction, Void & Replace and the RMA) asks here, so a proposal calculator
 * and a manual calculator never stand side by side. It writes nothing, opens
 * no transaction and takes no lock, so it is valid outside a transaction and
 * inside a caller's lock alike.
 *
 * THE ONE ZERO RULE. A credit draft that totals 0,00 at the corrected
 * document's scale is refused, except when every unit it carries is one the
 * corrected invoice lists and the order no longer holds: that is a quantity
 * correction, issued as a 0,00 note carrying its quantity. Every other zero is
 * "no refundable amount", or "the current selections produce 0,00" when the
 * selection leaves out money the corrected document still has to credit: a
 * charge with money left after its discount, or, when no goods are picked,
 * goods with money left after theirs.
 *
 * @since  1.14.0
 */
final class CreditCalculator
{
    /**
     * Quantity precision, the issuer's: a quantity is not money and has no
     * currency scale to be exact at.
     *
     * @since  1.14.0
     */
    private const QUANTITY_EPSILON = 0.000001;

    /**
     * Calculate a credit: what it would issue, what it comes to, and why.
     *
     * The preview is a non-persisting run of the issuer itself, so a previewed
     * credit and the issued one are the same calculation. In order: the shape
     * of the input, and a corrected document the issuer accepts (issued, a
     * sales document, of this order); the order's availability, before
     * anything is calculated; the corrected document's language; the
     * operator's charge picks (a ticked charge of 0 stops here, as an input
     * error); the lines; the charge decision record; the draft; the issuer's
     * preview; the one zero rule; the charges left out; and, for an issuable
     * credit only, the fingerprint that ties an issue to this state. A refusal
     * is an answer, never an exception.
     *
     * @param CreditInput $input What to calculate.
     *
     *
     * @since   1.14.0
     */
    public static function calculate(CreditInput $input): CreditResult
    {
        $correctsId = $input->correctsId;
        $corrected = $correctsId > 0 ? DocumentHelper::getById($correctsId) : null;

        // 1. The shape, and the corrected document itself: an issued sales
        // document of this order, by the issuer's own rule (its owner is
        // never inferred). With nothing picked no preview runs, so this is
        // the only place such a document is refused rather than measured.
        $shape = match (true) {
            !\in_array($input->docType, DocumentHelper::creditTypes(), true)
                => "'{$input->docType}' is not a credit document type.",
            $correctsId <= 0 => 'a credit must name the document it corrects.',
            $corrected === null => "document #{$correctsId} does not exist.",
            default => '',
        };

        if ($shape !== '') {
            return self::answer(outcome: CreditOutcome::Invalid, detail: $shape, corrected: $corrected);
        }

        try {
            DocumentIssuer::guardCorrectable(target: $corrected, orderId: $input->orderId);
        } catch (Throwable $e) {
            return self::refused(error: $e, corrected: $corrected);
        }

        // 2. Availability, before anything is calculated.
        try {
            $unrecorded = OrderChargeLines::unrecordedCreditNotes(orderId: $input->orderId);
        } catch (Throwable $e) {
            return self::refused(error: $e, corrected: $corrected);
        }

        if ($unrecorded !== []) {
            $numbers = implode(', ', array_column($unrecorded, 'number_formatted'));

            return self::answer(
                outcome:   CreditOutcome::Unavailable,
                detail:    "credits on order #{$input->orderId} are unavailable: credit note {$numbers} carries a charge line that nothing still active on its invoice accounts for.",
                corrected: $corrected,
                key:       'COM_ALFA_CREDIT_UNAVAILABLE_UNRECORDED',
                values:    [$numbers],
            );
        }

        // 3.-7. The language, the charge picks, the lines, the record and the draft.
        $language = '';

        try {
            $language = DocumentHelper::creditLanguageTag(correctsId: $correctsId);
            $chargePicks = OrderChargeLines::manualCreditPicks(correctsId: $correctsId, posted: $input->postedCharges);
            $lines = DocumentDraft::lines(
                orderId:     $input->orderId,
                docType:     $input->docType,
                picks:       $input->itemPicks,
                chargePicks: $chargePicks,
                correctsId:  $correctsId,
                language:    $language,
            );
            $record = self::decisionRecord(
                correctsId: $correctsId,
                selected:   ['shipping' => $chargePicks['shipping'], 'fees' => $chargePicks['fees']],
                lines:      $lines,
            );
            $draft = DocumentIssuer::frozenBuyer($corrected) + [
                'doc_type' => $input->docType,
                'id_order' => $input->orderId,
                'id_series' => $input->seriesId,
                'id_corrects' => $correctsId,
                'language' => $language,
                'print_note' => $input->printNote,
                'created_by' => $input->actorId,
                'lines' => $lines,
                'credit_charges' => $record,
            ];
        } catch (Throwable $e) {
            return self::refused(error: $e, corrected: $corrected, language: $language);
        }

        $currency = DocumentMoney::currencyOf($corrected);
        $exclusions = self::exclusions(record: $record, currency: $currency);

        try {
            // 8. An empty draft is never previewed: the issuer needs a line.
            // It can only be nothing refundable or a zero selection.
            if ($lines === []) {
                $outcome = self::zeroOutcome(orderId: $input->orderId, correctsId: $correctsId, lines: [], totalIncl: 0.0, record: $record);

                return self::judged(outcome: $outcome, corrected: $corrected, language: $language, draft: $draft, preview: null, record: $record, exclusions: $exclusions);
            }

            // 9. The issuer's own preview: every guard it runs at issue.
            $preview = DocumentIssuer::preview($draft);

            if (!$preview->valid) {
                return self::answer(
                    outcome:    CreditOutcome::Invalid,
                    detail:     (string) $preview->reason,
                    corrected:  $corrected,
                    key:        (string) $preview->refusal_key,
                    values:     (array) $preview->refusal_values,
                    language:   $language,
                    draft:      $draft,
                    preview:    $preview,
                    record:     $record,
                    exclusions: $exclusions,
                );
            }

            // 10. The one zero rule, on the total the issuer computed.
            $outcome = self::zeroOutcome(
                orderId:    $input->orderId,
                correctsId: $correctsId,
                lines:      $lines,
                totalIncl:  (float) $preview->totals->incl,
                record:     $record,
            );

            // 12. What an issue must still find: the lines, the money and every
            // charge's availability, so a charge left out that another credit
            // consumed in between changes it too.
            $fingerprint = $outcome->issuable()
                ? hash('sha256', json_encode(
                    [DocumentIssuer::draftFingerprint($draft, $preview), $record, $outcome->value],
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ))
                : '';
        } catch (Throwable $e) {
            return self::refused(error: $e, corrected: $corrected, language: $language);
        }

        return self::judged(
            outcome:     $outcome,
            corrected:   $corrected,
            language:    $language,
            draft:       $draft,
            preview:     $preview,
            record:      $record,
            exclusions:  $exclusions,
            fingerprint: $fingerprint,
        );
    }

    /**
     * The shipping and fee charges a credit of one document with nothing
     * picked would leave out: what the document still holds.
     *
     * The same list calculate() answers for a credit that picks nothing, and
     * like it, none for a document the issuer would not correct or an order
     * whose credits are unavailable. Which credit type would be issued does
     * not change it, so none is asked: the list stands for a document whose
     * type no single credit type corrects as well.
     *
     * @param int $orderId The order.
     * @param int $correctsId The document.
     *
     * @return array CreditResult::exclusions entries.
     *
     * @since   1.14.0
     */
    public static function chargesLeftOut(int $orderId, int $correctsId): array
    {
        $corrected = $correctsId > 0 ? DocumentHelper::getById($correctsId) : null;

        if ($corrected === null) {
            return [];
        }

        try {
            DocumentIssuer::guardCorrectable(target: $corrected, orderId: $orderId);

            if (OrderChargeLines::unrecordedCreditNotes(orderId: $orderId) !== []) {
                return [];
            }

            // Nothing picked: the credit carries no line.
            $record = self::decisionRecord(correctsId: $correctsId, selected: ['shipping' => [], 'fees' => []], lines: []);
        } catch (Throwable) {
            // A document the issuer refuses to correct has no list, as in calculate().
            return [];
        }

        return self::exclusions(record: $record, currency: DocumentMoney::currencyOf($corrected));
    }

    /**
     * The charge decision record of one credit: each eligible shipping and fee
     * source of the corrected document, selected or excluded, with its figures.
     *
     * Eligible is every shipping and fee source with money left before this
     * credit, in the corrected document's order, shipping first. Discounts are
     * never a choice: the shipping-class rules are stated beside, as calculated
     * detail, and an excluded parcel states its share of each
     * one. Every figure is a gross decimal
     * string at the corrected document's scale, built from minor units, and
     * `money_left` is what crediting all a source has left would give back
     * after its discount, never more than the corrected document can still
     * credit: before this credit for a source it gives back, and after it for
     * a source it leaves out, since that is what a later credit of it finds.
     * `bounded_by_invoice` says whether that document limit is what bounds
     * the figure, below what the source itself would give back.
     *
     * @param int $correctsId The corrected (issued sales) document.
     * @param array $selected ['shipping' => int[], 'fees' => int[]]: the sources this credit gives back.
     * @param array $lines The credit's draft lines.
     *
     * @return array JSON-safe: {id_corrects, corrected, currency, scale, entries[], shipping_discounts[]}.
     *
     * @throws RuntimeException When the document does not exist, or the lines and the selection disagree.
     *
     * @since   1.14.0
     */
    public static function decisionRecord(int $correctsId, array $selected, array $lines): array
    {
        $corrected = DocumentHelper::getById($correctsId)
            ?? throw new RuntimeException("document #{$correctsId} does not exist, so no charge record can be made of it.");
        $currency = DocumentMoney::currencyOf($corrected);
        $scale = $currency->getDecimalPlaces();
        $number = (string) $corrected->number_formatted;
        $charges = OrderChargeLines::correctedCharges(correctsId: $correctsId);
        $minor = static fn (float $amount): int => Money::of(abs($amount), $currency)->getMinorUnits();

        // What this credit's lines give back, per source and line type.
        $given = [];

        foreach ($lines as $line) {
            $type = (string) ($line['line_type'] ?? DocumentHelper::LINE_ITEM);

            if ($type === DocumentHelper::LINE_ITEM) {
                continue;
            }

            $sourceId = (int) ($line['id_source'] ?? 0);
            $given[$type][$sourceId] = ($given[$type][$sourceId] ?? 0) + $minor((float) ($line['total_tax_incl'] ?? 0.0));
        }

        $eligible = [];
        $chosen = [];

        foreach ([
            'shipping' => [DocumentHelper::LINE_SHIPPING, $charges->shipping],
            'fees' => [DocumentHelper::LINE_FEE, $charges->fees],
        ] as $kind => [$type, $candidates]) {
            $chosen[$kind] = array_map('intval', (array) ($selected[$kind] ?? []));

            foreach ($candidates as $candidate) {
                if (Money::of(abs($candidate->remaining_incl), $currency)->isPositive()) {
                    $eligible[$kind][(int) $candidate->id] = $candidate;
                }
            }

            foreach (array_keys($given[$type] ?? []) as $sourceId) {
                if (!isset($eligible[$kind][$sourceId]) || !\in_array($sourceId, $chosen[$kind], true)) {
                    throw new RuntimeException("the credit's {$type} line names #{$sourceId}, which is not a selected {$type} of {$number} with money left to credit.");
                }
            }

            foreach ($chosen[$kind] as $sourceId) {
                if (!isset($eligible[$kind][$sourceId])) {
                    throw new RuntimeException("{$type} #{$sourceId} is selected, but {$number} has nothing of it left to credit.");
                }

                if (($given[$type][$sourceId] ?? 0) <= 0) {
                    throw new RuntimeException("{$type} #{$sourceId} is selected, but this credit gives back nothing of it.");
                }
            }
        }

        $creditedShipping = [];

        foreach ($chosen['shipping'] as $sourceId) {
            $creditedShipping[$sourceId] = Money::ofMinor($given[DocumentHelper::LINE_SHIPPING][$sourceId], $currency)->getAmount();
        }

        $detail = OrderChargeLines::shippingDiscountDetail(correctsId: $correctsId, creditedShipping: $creditedShipping);
        $ruleLabels = [];

        foreach ($charges->rules as $rule) {
            $ruleLabels[(int) $rule->id] = (string) $rule->label;
        }

        $decimal = static fn (int $amount): string => self::decimal(minor: $amount, scale: $scale);
        $entries = [];
        // An order discount can spill over a small final invoice's goods into
        // its charges, so a charge can hold more than its document can still
        // credit: that is all crediting it can give back. A charge this credit
        // leaves out can give back only what the document has left after it.
        $documentLeft = DocumentIssuer::creditLeft(target: $corrected);
        $leftBefore = max(0, $documentLeft->getMinorUnits());
        $leftAfter = max(0, $documentLeft->subtract(Money::of(DocumentIssuer::linesTotal(lines: $lines), $currency))->getMinorUnits());

        foreach ([
            'shipping' => DocumentHelper::LINE_SHIPPING,
            'fees' => DocumentHelper::LINE_FEE,
        ] as $kind => $type) {
            foreach ($eligible[$kind] ?? [] as $sourceId => $candidate) {
                $isSelected = \in_array($sourceId, $chosen[$kind], true);
                $original = $minor((float) $candidate->amount_incl);
                $before = $minor((float) $candidate->remaining_incl);
                $now = $isSelected ? $given[$type][$sourceId] : 0;
                $moneyLeft = $before;
                $shares = [];

                if ($type === DocumentHelper::LINE_SHIPPING) {
                    $moneyLeft = Money::of(
                        (float) ($detail->money_left[$sourceId] ?? throw new RuntimeException("shipping #{$sourceId} of {$number} states no money left.")),
                        $currency,
                    )->getMinorUnits();

                    // An excluded parcel still holds its shipping, so it states a share of every rule.
                    $parcelShares = $isSelected ? [] : ($detail->shares[$sourceId] ?? throw new RuntimeException("shipping #{$sourceId} of {$number} states no discount share."));

                    foreach ($parcelShares as $ruleId => $share) {
                        $shareMinor = Money::of((float) $share, $currency)->getMinorUnits();

                        if ($shareMinor > 0) {
                            $shares[] = ['id_source' => (int) $ruleId, 'label' => (string) ($ruleLabels[(int) $ruleId] ?? ''), 'share' => $decimal($shareMinor)];
                        }
                    }
                }

                // Whether what the document can still credit is what limits
                // the source, below what crediting it would give back itself:
                // then a row names the document's limit, not the source's.
                $bound = $isSelected ? $leftBefore : $leftAfter;
                $boundedByInvoice = $bound < $moneyLeft;
                $moneyLeft = min($moneyLeft, $bound);

                $entries[] = [
                    'line_type' => $type,
                    'id_source' => $sourceId,
                    'label' => (string) $candidate->label,
                    'decision' => $isSelected ? 'selected' : 'excluded',
                    'original' => $decimal($original),
                    'credited_before' => $decimal($original - $before),
                    'remaining_before' => $decimal($before),
                    'credited_now' => $decimal($now),
                    'remaining_after' => $decimal($before - $now),
                    'money_left' => $decimal($moneyLeft),
                    'bounded_by_invoice' => $boundedByInvoice,
                    'discount_shares' => $shares,
                ];
            }
        }

        // The shipping-class rules with something left: calculated detail, never a decision.
        $shippingDiscounts = [];

        foreach ($charges->rules as $rule) {
            $before = $minor((float) $rule->remaining_incl);

            if ($rule->discount_class !== 'shipping' || $before <= 0) {
                continue;
            }

            $now = $given[DocumentHelper::LINE_ADJUSTMENT][(int) $rule->id] ?? 0;
            $shippingDiscounts[] = [
                'id_source' => (int) $rule->id,
                'label' => (string) $rule->label,
                'remaining_before' => $decimal($before),
                'credited_now' => $decimal($now),
                'remaining_after' => $decimal($before - $now),
            ];
        }

        return [
            'id_corrects' => $correctsId,
            'corrected' => $number,
            'currency' => $currency->getCode(),
            'scale' => $scale,
            'entries' => $entries,
            'shipping_discounts' => $shippingDiscounts,
        ];
    }

    /**
     * The one zero rule: what a credit draft of this total comes to.
     *
     * A zero that is no quantity correction is a zero selection when the
     * operator's selection leaves out money the corrected document still has
     * to credit: a shipping or fee source that would give back money after
     * its discount, or, when the draft carries no goods, the goods that would
     * give back money after their discounts. Free goods the order still holds
     * stay nothing refundable. Callers without an operator selection (a
     * proposed correction, Void & Replace) pass an empty record, so they can
     * only get Issuable, QuantityCorrection or NothingRefundable.
     *
     * @param int $orderId The order the credit is issued on.
     * @param int $correctsId The corrected document.
     * @param array $lines The draft lines.
     * @param float $totalIncl The draft's total, tax included, as the issuer's preview totals it.
     * @param array $record The charge decision record, or [] without an operator selection; only a record opens case 2.
     *
     *
     * @throws RuntimeException When the corrected document does not exist.
     *
     * @since   1.14.0
     */
    public static function zeroOutcome(int $orderId, int $correctsId, array $lines, float $totalIncl, array $record): CreditOutcome
    {
        $corrected = DocumentHelper::getById($correctsId);

        if ($corrected === null) {
            throw new RuntimeException("document #{$correctsId} does not exist, so a credit of it cannot be judged.");
        }

        // The corrected document's own money, never the order's.
        $currency = DocumentMoney::currencyOf($corrected);

        if (!Money::of($totalIncl, $currency)->isZero()) {
            return CreditOutcome::Issuable;
        }

        if (self::quantityCorrection(orderId: $orderId, correctsId: $correctsId, lines: $lines)) {
            return CreditOutcome::QuantityCorrection;
        }

        // A shipping or fee source left with money after its attached discount.
        if (self::chargeHoldsMoney(record: $record, currency: $currency)) {
            return CreditOutcome::SelectionZero;
        }

        // No goods are picked while the corrected document still has goods
        // money to credit: the selection, not the document, gives back nothing.
        if ($record !== [] && !self::carriesItems(lines: $lines)
            && Money::of(OrderChargeLines::goodsMoneyLeft(correctsId: $correctsId), $currency)->isPositive()) {
            return CreditOutcome::SelectionZero;
        }

        return CreditOutcome::NothingRefundable;
    }

    /**
     * The units a 0,00 quantity correction against one document may carry, per order line.
     *
     * Per line the document states: the line's negative quantity drift after
     * earlier credits, bounded by what the document still has to credit on it.
     * Only lines above the quantity precision are listed, so `[]` means no
     * quantity correction is possible on that document. The Corrects picker
     * asks this too, so the picker and the zero rule never disagree.
     *
     * @param int $orderId The order.
     * @param int $correctsId The corrected document.
     *
     * @return array<int, float> Units keyed by order line id.
     *
     * @since   1.14.0
     */
    public static function quantityCorrectionAllowance(int $orderId, int $correctsId): array
    {
        $drift = IncrementalCorrection::lineDrift(orderId: $orderId)['negative'];
        $left = OrderItemLines::remainingForCredit(correctsId: $correctsId);
        $allowance = [];

        // The document's own lines only: a line it does not state is never listed.
        foreach ($left as $lineId => $balance) {
            $units = min((float) ($drift[$lineId] ?? 0.0), (float) $balance->remaining_qty);

            if ($units > self::QUANTITY_EPSILON) {
                $allowance[(int) $lineId] = $units;
            }
        }

        return $allowance;
    }

    /**
     * Is a 0,00 draft a quantity correction: every unit it carries one the order no longer holds?
     *
     * @param int $orderId The order.
     * @param int $correctsId The corrected document.
     * @param array $lines The draft lines.
     *
     *
     * @since   1.14.0
     */
    private static function quantityCorrection(int $orderId, int $correctsId, array $lines): bool
    {
        $claimed = [];

        foreach ($lines as $line) {
            if ((string) ($line['line_type'] ?? DocumentHelper::LINE_ITEM) !== DocumentHelper::LINE_ITEM) {
                continue;
            }

            $lineId = (int) ($line['id_order_item'] ?? 0);

            // A value-only line is not a unit the invoice lists.
            if ($lineId <= 0) {
                return false;
            }

            $claimed[$lineId] = ($claimed[$lineId] ?? 0.0) + (float) ($line['quantity'] ?? 1);
        }

        if ($claimed === []) {
            return false;
        }

        $allowance = self::quantityCorrectionAllowance(orderId: $orderId, correctsId: $correctsId);

        foreach ($claimed as $lineId => $quantity) {
            if ($quantity - ($allowance[$lineId] ?? 0.0) > self::QUANTITY_EPSILON) {
                return false;
            }
        }

        return true;
    }

    /**
     * Does a draft carry goods: any item line, a value-only one included?
     *
     * @param array $lines The draft lines.
     *
     *
     * @since   1.14.0
     */
    private static function carriesItems(array $lines): bool
    {
        foreach ($lines as $line) {
            if ((string) ($line['line_type'] ?? DocumentHelper::LINE_ITEM) === DocumentHelper::LINE_ITEM) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does one shipping or fee source of a record still hold money: would
     * crediting all it has left give back more than 0 after its discount?
     *
     * @param array $entry One entry of a decisionRecord().
     * @param Currency $currency The corrected document's currency.
     *
     *
     * @since   1.14.0
     */
    private static function holdsMoney(array $entry, Currency $currency): bool
    {
        return Money::of((float) $entry['money_left'], $currency)->isPositive();
    }

    /**
     * Does any shipping or fee source of a record still hold money? It decides
     * the charge leg of the zero rule and which sentence a zero selection
     * reads, so the two can never disagree.
     *
     * @param array $record A decisionRecord(), or [] without an operator selection.
     * @param Currency $currency The corrected document's currency.
     *
     *
     * @since   1.14.0
     */
    private static function chargeHoldsMoney(array $record, Currency $currency): bool
    {
        foreach ($record['entries'] ?? [] as $entry) {
            if (self::holdsMoney(entry: $entry, currency: $currency)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The entries of a record the one list of charges left out names: excluded
     * by the operator, with money left after their discount. A fully netted
     * parcel stays in the record and is left off this list.
     *
     * @param array $record A decisionRecord().
     * @param Currency $currency The corrected document's currency.
     *
     *
     * @since   1.14.0
     */
    private static function exclusions(array $record, Currency $currency): array
    {
        return array_values(array_filter(
            $record['entries'] ?? [],
            static fn (array $entry): bool => $entry['decision'] === 'excluded'
                && self::holdsMoney(entry: $entry, currency: $currency),
        ));
    }

    /**
     * A calculated outcome with its operator sentence.
     *
     * @param CreditOutcome $outcome What the credit comes to.
     * @param object $corrected The corrected document.
     * @param string $language The credit's language.
     * @param array $draft The draft.
     * @param object|null $preview The issuer's preview; null for an empty draft.
     * @param array $record The decision record.
     * @param array $exclusions The charges left out.
     * @param string $fingerprint The fingerprint of an issuable credit.
     *
     *
     * @since   1.14.0
     */
    private static function judged(
        CreditOutcome $outcome,
        object $corrected,
        string $language,
        array $draft,
        ?object $preview,
        array $record,
        array $exclusions,
        string $fingerprint = '',
    ): CreditResult {
        $number = (string) $corrected->number_formatted;
        $zero = (DocumentMoney::formatter($corrected))(0.0);
        $currency = DocumentMoney::currencyOf($corrected);

        // A zero selection names what still holds money: a charge first, else the goods.
        [$key, $values, $detail] = match ($outcome) {
            CreditOutcome::NothingRefundable => ['COM_ALFA_CREDIT_ZERO_NOTHING_REFUNDABLE', [], "there is no refundable amount on {$number} for this credit."],
            CreditOutcome::SelectionZero => self::chargeHoldsMoney(record: $record, currency: $currency)
                ? ['COM_ALFA_CREDIT_ZERO_SELECTION', [$zero], "the selections credit {$zero} of {$number} while a charge on it still has money left."]
                : ['COM_ALFA_CREDIT_ZERO_SELECTION_GOODS', [$zero, $number], "the selections credit {$zero} of {$number} while its goods still have money left."],
            CreditOutcome::QuantityCorrection => ['COM_ALFA_CREDIT_QUANTITY_CORRECTION', [$number, $zero], "a {$zero} credit that corrects the quantity {$number} states for goods the order no longer holds."],
            default => ['', [], ''],
        };

        return self::answer(
            outcome:     $outcome,
            detail:      $detail,
            corrected:   $corrected,
            key:         $key,
            values:      $values,
            language:    $language,
            draft:       $draft,
            preview:     $preview,
            record:      $record,
            exclusions:  $exclusions,
            fingerprint: $outcome->issuable() ? $fingerprint : '',
        );
    }

    /**
     * A refusal raised on the way: Invalid, in the refusal's own words.
     *
     * @param Throwable $error What was thrown.
     * @param object|null $corrected The corrected document.
     * @param string $language The credit's language, when it was resolved.
     *
     *
     * @since   1.14.0
     */
    private static function refused(Throwable $error, ?object $corrected, string $language = ''): CreditResult
    {
        $refusal = DocumentRefusedException::in($error);

        return self::answer(
            outcome:   CreditOutcome::Invalid,
            detail:    $error->getMessage(),
            corrected: $corrected,
            key:       $refusal?->key() ?? '',
            values:    $refusal?->values() ?? [],
            language:  $language,
        );
    }

    /**
     * One result, with every member not given empty.
     *
     * @param CreditOutcome $outcome What the credit comes to.
     * @param string $detail The technical text.
     * @param object|null $corrected The corrected document.
     * @param string $key The operator sentence's language key.
     * @param array $values Its values.
     * @param string $language The credit's language.
     * @param array $draft The draft.
     * @param object|null $preview The issuer's preview.
     * @param array $record The decision record.
     * @param array $exclusions The charges left out.
     * @param string $fingerprint The fingerprint.
     *
     *
     * @since   1.14.0
     */
    private static function answer(
        CreditOutcome $outcome,
        string $detail,
        ?object $corrected,
        string $key = '',
        array $values = [],
        string $language = '',
        array $draft = [],
        ?object $preview = null,
        array $record = [],
        array $exclusions = [],
        string $fingerprint = '',
    ): CreditResult {
        return new CreditResult(
            outcome:      $outcome,
            reasonKey:    $key,
            reasonValues: $values,
            detail:       $detail,
            corrected:    $corrected,
            language:     $language,
            draft:        $draft,
            preview:      $preview,
            record:       $record,
            exclusions:   $exclusions,
            fingerprint:  $fingerprint,
        );
    }

    /**
     * A money figure of the record: minor units written at the scale, never through float formatting.
     *
     * @param int $minor The amount in minor units.
     * @param int $scale The currency's decimal places.
     *
     * @return string Exactly $scale decimals ('12.40' at 2, '12' at 0).
     *
     * @since   1.14.0
     */
    private static function decimal(int $minor, int $scale): string
    {
        $sign = $minor < 0 ? '-' : '';
        $digits = str_pad((string) abs($minor), $scale + 1, '0', STR_PAD_LEFT);

        return $scale === 0 ? $sign . $digits : $sign . substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
    }
}
