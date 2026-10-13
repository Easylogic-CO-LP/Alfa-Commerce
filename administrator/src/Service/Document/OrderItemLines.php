<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderLineMoney;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\Factory;
use RuntimeException;

/**
 * OrderItemLines: the order's ITEM money as document draft lines.
 *
 * THE RULE. The order line's whole-line total is the monetary authority for
 * the line, and what a document states of it is money derived from stored
 * money: the GROSS is allocated, and the net is that gross divided out at
 * the line's stored tax rate ({@see OrderLineMoney::netAtStoredRate()}). The
 * unit price is a display value only; it never rebuilds a line total.
 *
 *   the last of the quantity  the exact remaining gross and net: the line's
 *                             totals minus what active documents already
 *                             stated for it (the final partial IS this draft)
 *   a part of the quantity    CUMULATIVE: what the line should have stated up
 *                             to and including this part, its gross x
 *                             (quantity already documented + this quantity) /
 *                             its quantity, rounded once, less the gross
 *                             already stated; the net is the split of that
 *                             cumulative gross, never above the line's own
 *                             net nor below what the rest of the line needs
 *                             (its net less its gross not yet stated), less
 *                             the net already stated
 *   more than is left         the plain share, gross x quantity / the line's
 *                             quantity, and its split, so the guard that owns
 *                             the line refuses it rather than a clamp hiding it
 *
 * So the active documents of one line sum EXACTLY to its totals once it is
 * fully issued, on both axes: 3.33 + 3.34 + 3.33 for a 10.00 line of three,
 * never 9.99. The two axes are never allocated on their own, so no part
 * states a VAT below zero, and a part that could only be stated with a
 * negative net or VAT (the documents already issued no longer sit on the
 * line's cumulative split) is refused by name, saying whether the rest of
 * the line can still be issued in one document. Voided documents consume
 * neither quantity nor money. A credit takes the corrected
 * document's line as its pool under the same rule, at that line's own tax
 * rate, so a full credit reverses exactly what that line stated.
 *
 * Every item-line draft builder goes through here (auto-issue, the order
 * screen's issue and credit modal, and every credit an extension drafts
 * through DocumentDraft), and so does OrderChargeLines::itemShare(), which
 * divides by the same balance.
 *
 * @since  2.0.0
 */
final class OrderItemLines
{
    /**
     * Tolerance of the final-partial and over-claim tests: a QUANTITY
     * tolerance, the figure the issuer's quantity guard uses. Every money
     * comparison in this class is an exact Money comparison in minor units;
     * there is no money epsilon.
     *
     * @var float
     * @since  2.0.0
     */
    private const QUANTITY_EPSILON = 0.000001;

    /**
     * The order's currency, at the scale the order froze.
     *
     * The one answer every order service gives ({@see OrderCurrency}): the
     * currency the order names, never another one in its place.
     *
     * @param int $orderId The order id.
     *
     *
     * @throws OrderCurrencyException When the order names no currency.
     *
     * @since  2.0.0
     */
    public static function orderCurrency(int $orderId): Currency
    {
        return OrderCurrency::of(orderId: $orderId);
    }

    /**
     * Per order line: the quantity still issuable for a document type and the
     * frozen money still claimable against it.
     *
     * @param int $orderId The order id.
     * @param string $docType Target document type alias.
     *
     * @return array<int, object> Keyed by order line id: one {@see self::balance()} each, whose `row` is the
     *                            #__alfa_order_items row.
     *
     * @since  2.0.0
     */
    public static function remaining(int $orderId, string $docType): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_order_items'))
            ->where($db->quoteName('id_order') . ' = :orderId')
            ->bind(':orderId', $orderId);
        $db->setQuery(query: $query);
        $rows = $db->loadObjectList('id') ?: [];

        if ($rows === []) {
            return [];
        }

        $quantities = DocumentHelper::remainingQuantities(orderId: $orderId, docType: $docType);

        // The money pool is the one the quantity pool is paired with, so both
        // axes answer the same question (DocumentHelper::remainingQuantities):
        //
        //   sales        frozen total - every sales-pool statement
        //   credit       what sales documents stated - what credits took
        //   independent  frozen total - this type's own statements
        //   none         the frozen total, uncounted
        //
        // Fixed at the sales statements, a delivery note would show the whole
        // order issuable and nothing left to state; measured against the frozen
        // total, a credit could claim money that was never invoiced.
        //
        // The credit pool is read only for what it has left
        // (OrderChargeLines::itemShare()); no part is ever claimed from it: a
        // credit is drafted from the document it corrects (buildCreditLines()),
        // and buildSalesLines() refuses a credit type.
        $targets = null;

        switch (DocumentHelper::poolOf($docType)) {
            case DocumentHelper::POOL_CREDIT:
                $targets = DocumentHelper::consumedAmounts(orderId: $orderId, docTypes: DocumentHelper::salesTypes());
                $taken = DocumentHelper::consumedAmounts(orderId: $orderId, docTypes: DocumentHelper::creditTypes());
                break;

            case DocumentHelper::POOL_INDEPENDENT:
                $taken = DocumentHelper::consumedAmounts(orderId: $orderId, docTypes: [$docType]);
                break;

            case DocumentHelper::POOL_NONE:
                $taken = [];
                break;

            default: // POOL_SALES, and unknown aliases, as the quantity pool does
                $taken = DocumentHelper::consumedAmounts(orderId: $orderId, docTypes: DocumentHelper::salesTypes());
                break;
        }

        $currency = self::orderCurrency(orderId: $orderId);
        $balances = [];

        foreach ($rows as $lineId => $row) {
            $lineId = (int) $lineId;

            $balances[$lineId] = self::balance(
                row:          $row,
                lineId:       $lineId,
                poolQuantity: (float) $row->quantity,
                quantity:     (float) ($quantities[$lineId]['remaining'] ?? 0.0),
                targetIncl:   $targets === null ? (float) $row->total_price_tax_incl : (float) ($targets[$lineId]['incl'] ?? 0.0),
                targetExcl:   $targets === null ? (float) $row->total_price_tax_excl : (float) ($targets[$lineId]['excl'] ?? 0.0),
                takenIncl:    (float) ($taken[$lineId]['incl'] ?? 0.0),
                takenExcl:    (float) ($taken[$lineId]['excl'] ?? 0.0),
                currency:     $currency,
            );
        }

        return $balances;
    }

    /**
     * Per order line of ONE corrected document: the quantity and the money
     * that issued credit notes against THAT document have not yet taken.
     *
     * This is the axis DocumentIssuer's cumulative credit cap measures, the
     * corrected document's own lines, and deliberately not the order-wide
     * credit pool, which spans every sales document of the order.
     *
     * Beside the quantity and the two totals, each line carries its saving
     * pool: the whole-line saving, net of tax, the document printed for it,
     * what issued credits already gave back of it and what they have not.
     *
     * @param int $correctsId The corrected (issued sales) document id.
     *
     * @return array<int, object> Keyed by order line id, every line the document states (0 left included):
     *                            one {@see self::balance()} each, with stated_qty (float) and stated_saving_excl,
     *                            taken_saving_excl and remaining_saving_excl (Money) beside it. `row` is the corrected
     *                            document's first row for the line; every Money is at the corrected document's own currency.
     *
     * @since  2.0.0
     */
    public static function remainingForCredit(int $correctsId): array
    {
        if ($correctsId <= 0) {
            return [];
        }

        $document = DocumentHelper::getById($correctsId);

        if ($document === null) {
            return [];
        }

        // The corrected document's own frozen currency.
        $currency = DocumentMoney::currencyOf($document);
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_document_items'))
            ->where([
                $db->quoteName('id_document') . ' = :correctsId',
                $db->quoteName('line_type') . ' = ' . $db->quote(DocumentHelper::LINE_ITEM),
                $db->quoteName('id_order_item') . ' IS NOT NULL',
            ])
            ->order($db->quoteName('id') . ' ASC')
            ->bind(':correctsId', $correctsId);
        $db->setQuery(query: $query);

        // One document may split a line across rows; fold them.
        $stated = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $lineId = (int) $row->id_order_item;
            $stated[$lineId] ??= ['row' => $row, 'qty' => 0.0, 'incl' => 0.0, 'excl' => 0.0, 'saving' => 0.0];
            $stated[$lineId]['qty'] += (float) $row->quantity;
            $stated[$lineId]['incl'] += (float) $row->total_tax_incl;
            $stated[$lineId]['excl'] += (float) $row->total_tax_excl;
            $stated[$lineId]['saving'] += (float) $row->discount_tax_excl;
        }

        if ($stated === []) {
            return [];
        }

        // ISSUED credits only: a voided note gives its quantity and its money
        // back, exactly as the issuer's cap counts them.
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('i.id_order_item'),
                'SUM(' . $db->quoteName('i.quantity') . ') AS qty',
                'SUM(' . $db->quoteName('i.total_tax_incl') . ') AS incl',
                'SUM(' . $db->quoteName('i.total_tax_excl') . ') AS excl',
                'SUM(' . $db->quoteName('i.discount_tax_excl') . ') AS saving',
            ])
            ->from($db->quoteName('#__alfa_document_items', 'i'))
            ->join('INNER', $db->quoteName('#__alfa_documents', 'd') . ' ON d.id = i.id_document')
            ->where([
                $db->quoteName('d.id_corrects') . ' = :correctsId',
                $db->quoteName('d.status') . ' = ' . DocumentHelper::STATUS_ISSUED,
                $db->quoteName('i.line_type') . ' = ' . $db->quote(DocumentHelper::LINE_ITEM),
                $db->quoteName('i.id_order_item') . ' IS NOT NULL',
            ])
            ->group($db->quoteName('i.id_order_item'))
            ->bind(':correctsId', $correctsId);
        $db->setQuery(query: $query);

        $taken = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $taken[(int) $row->id_order_item] = [
                'qty' => (float) $row->qty,
                'incl' => (float) $row->incl,
                'excl' => (float) $row->excl,
                'saving' => (float) $row->saving,
            ];
        }

        $balances = [];

        foreach ($stated as $lineId => $line) {
            $used = $taken[$lineId] ?? ['qty' => 0.0, 'incl' => 0.0, 'excl' => 0.0, 'saving' => 0.0];

            $balance = self::balance(
                row:          $line['row'],
                lineId:       (int) $lineId,
                poolQuantity: $line['qty'],
                quantity:     max(0.0, $line['qty'] - $used['qty']),
                targetIncl:   $line['incl'],
                targetExcl:   $line['excl'],
                takenIncl:    $used['incl'],
                takenExcl:    $used['excl'],
                currency:     $currency,
            );

            // The saving pool, floored as the two totals are.
            $balance->stated_qty = $line['qty'];
            $balance->stated_saving_excl = Money::of($line['saving'], $currency);
            $balance->taken_saving_excl = Money::of($used['saving'], $currency);
            $balance->remaining_saving_excl = Money::max(
                $balance->stated_saving_excl->subtract($balance->taken_saving_excl),
                Money::zero($currency),
            );
            $balances[$lineId] = $balance;
        }

        return $balances;
    }

    /**
     * THE RULE: the two line totals a draft of this quantity states.
     *
     * The gross is allocated from the pool and the net is its split at the
     * pool row's stored tax rate: the order line's for a sales document, the
     * corrected document line's for a credit. No unit price is read.
     *
     * @param float $quantity The quantity being drafted.
     * @param object $balance One entry of remaining() or remainingForCredit().
     * @param Currency $currency The balance's currency.
     *
     * @return array{total_tax_excl: string, total_tax_incl: string} Both on the currency's minor unit, as
     *                                                               their exact decimal texts.
     *
     * @throws RuntimeException When the documents already issued for the line leave this part a net or a VAT
     *                          below zero; the message names the line and the way out.
     *
     * @since  2.0.0
     */
    public static function claim(float $quantity, object $balance, Currency $currency): array
    {
        $taxRate = (float) $balance->row->tax_rate;

        // A pool with no quantity has no money to state.
        if ($balance->pool_qty <= 0.0) {
            return self::stated(gross: Money::zero($currency), net: Money::zero($currency));
        }

        // More than the line has left is not a final partial but an over-claim.
        // Stated at its plain share of the pool, with that share's split, the
        // guard that owns the line refuses it: the issuer's quantity guard on
        // a sales document, and on a credit its per-invoice quantity step.
        // Stated at the remainder, it would pass as a document for units
        // nobody pays for.
        if (self::isOverClaim(quantity: $quantity, balance: $balance)) {
            $share = $balance->pool_incl->timesRatio(numerator: $quantity, denominator: $balance->pool_qty);

            return self::stated(gross: $share, net: OrderLineMoney::netAtStoredRate(gross: $share, taxRate: $taxRate));
        }

        if (self::isFinalPartial(quantity: $quantity, balance: $balance)) {
            // The final partial IS the draft that takes the last of the
            // quantity: it takes the exact remainder of the pool, on both
            // axes, so the parts of a line close on its totals.
            $gross = $balance->remaining_incl;
            $net = $balance->remaining_excl;
        } else {
            // CUMULATIVE. What the pool should have stated up to and including
            // this part, rounded once, less what active documents already
            // stated. The net is the split of that cumulative gross, never
            // above the pool's own net, less the net already stated: neither
            // axis is allocated on its own, so the VAT of a part cannot fall
            // below zero by rounding. It is never below what the rest of the
            // line needs either, the pool's net less the gross not yet stated,
            // so a line whose own net is above the split of its gross still
            // closes. On a line whose net IS that split the bound never
            // decides: the split moves by no more than the gross does.
            $target = $balance->pool_incl->timesRatio(
                numerator:   $balance->pool_qty - $balance->remaining_qty + $quantity,
                denominator: $balance->pool_qty,
            );
            $gross = $target->subtract($balance->taken_incl);
            $net = Money::max(
                Money::min(OrderLineMoney::netAtStoredRate(gross: $target, taxRate: $taxRate), $balance->pool_excl),
                $balance->pool_excl->subtract($balance->pool_incl->subtract($target)),
            )->subtract($balance->taken_excl);
        }

        self::refuseImpossiblePart(lineId: $balance->line_id, gross: $gross, net: $net, balance: $balance);

        return self::stated(gross: $gross, net: $net);
    }

    /**
     * The two totals of a part, in the shape claim() answers with: each the
     * exact decimal text of its Money, so the document writes its digits and
     * never a float's.
     *
     * @param Money $gross The part's total, tax included.
     * @param Money $net The part's total, tax excluded.
     *
     * @return array{total_tax_excl: string, total_tax_incl: string}
     *
     * @since  2.0.0
     */
    private static function stated(Money $gross, Money $net): array
    {
        return ['total_tax_excl' => ExactAmount::ofMoney($net)->text(), 'total_tax_incl' => ExactAmount::ofMoney($gross)->text()];
    }

    /**
     * Refuse a part no document can state: one whose net is below zero or
     * above its gross. A gross below zero is always one of the two.
     *
     * It is reached only when the documents already issued for the line no
     * longer sit on its cumulative split: an earlier part was voided, so the
     * active documents are no longer the cumulative parts; the line was
     * edited down or re-priced under another tax after a part was issued; or
     * an earlier part was stated under another rule. Nothing is clamped to
     * hide it. When the rest of the line can still be stated in one document,
     * the refusal says so: the documents already issued stay as they are.
     * Otherwise (a refused last part is that rest, so never) they are voided
     * or corrected first.
     *
     * @param int $lineId The order line.
     * @param Money $gross The part's total, tax included.
     * @param Money $net The part's total, tax excluded.
     * @param object $balance One entry of remaining() or remainingForCredit(): the line's remainders.
     *
     *
     * @throws RuntimeException When the part cannot be stated.
     *
     * @since  2.0.0
     */
    private static function refuseImpossiblePart(int $lineId, Money $gross, Money $net, object $balance): void
    {
        if (!$net->isNegative() && !$net->greaterThan($gross)) {
            return;
        }

        // The rest of the line in one document states the remainders, which
        // are never below zero: it can be issued when its net is not above
        // its gross. A refused last part IS that rest, so it never can.
        if (!$balance->remaining_excl->greaterThan($balance->remaining_incl)) {
            throw new RuntimeException(sprintf(
                'order line #%d: this part cannot be stated after the documents already issued for the line, which are correct as they are; issue the rest of the line (%s) in one document.',
                $lineId,
                rtrim(rtrim(number_format($balance->remaining_qty, 6, '.', ''), '0'), '.'),
            ));
        }

        throw new RuntimeException("order line #{$lineId}: the documents already issued for the line leave no part of it that can be stated at its tax rate; void or correct them first.");
    }

    /**
     * Whether a drafted quantity is more than the line has left.
     *
     * @param float $quantity The quantity being drafted.
     * @param object $balance One entry of remaining() or remainingForCredit().
     *
     *
     * @since  2.0.0
     */
    private static function isOverClaim(float $quantity, object $balance): bool
    {
        return $quantity - self::QUANTITY_EPSILON > $balance->remaining_qty;
    }

    /**
     * Whether a drafted quantity takes the last of the line: the final
     * partial, which takes the exact remainder. The one place this test is
     * written.
     *
     * @param float $quantity The quantity being drafted.
     * @param object $balance One entry of remaining() or remainingForCredit().
     *
     *
     * @since  2.0.0
     */
    private static function isFinalPartial(float $quantity, object $balance): bool
    {
        return $quantity + self::QUANTITY_EPSILON >= $balance->remaining_qty;
    }

    /**
     * The whole-line saving, net of tax, a credit of this quantity gives back.
     *
     * Allocated as {@see self::claim()} allocates the totals beside it, so a
     * credit line's total and its saving still add up to what the corrected
     * line showed for those units: CUMULATIVE, the corrected line's stated
     * saving x (quantity already credited + this quantity) / its stated
     * quantity, rounded once, less the saving already given back; never more
     * than the line has left of it, never negative. The final partial takes
     * the exact remainder. It is never rebuilt from unit prices.
     *
     * @param float $quantity The quantity being credited.
     * @param object $balance One entry of remainingForCredit().
     *
     * @return Money At the corrected document's currency.
     *
     * @since  2.0.0
     */
    private static function claimSaving(float $quantity, object $balance): Money
    {
        $currency = $balance->remaining_saving_excl->getCurrency();

        // Never issued: the per-invoice quantity step refuses it.
        if (self::isOverClaim(quantity: $quantity, balance: $balance)) {
            return Money::zero($currency);
        }

        if (self::isFinalPartial(quantity: $quantity, balance: $balance)) {
            return $balance->remaining_saving_excl;          // the exact remainder
        }

        // 0 < quantity < remaining_qty <= stated_qty: the division is safe,
        // and stated_qty - remaining_qty is the quantity already credited.
        $target = $balance->stated_saving_excl->timesRatio(
            numerator:   $balance->stated_qty - $balance->remaining_qty + $quantity,
            denominator: $balance->stated_qty,
        );

        return Money::min(
            Money::max($target->subtract($balance->taken_saving_excl), Money::zero($currency)),
            $balance->remaining_saving_excl,
        );
    }

    /**
     * Draft item lines for a document that is not a credit against a
     * corrected document: every sales type, and the logistics types.
     *
     * Each line freezes the order line's inventory identity and its ordered
     * quantity as the movement ceiling, so a later variant or quantity edit
     * cannot rewrite what the issued document moved.
     *
     * @param int $orderId The order id.
     * @param string $docType Target document type alias.
     * @param array|null $picks The order screen's picks, [lineId => ['selected' => 1,
     *                          'quantity' => n]], in posted order. Null = every
     *                          line's whole remainder (auto-issue).
     * @param string $language Document language tag for the line names.
     *
     * @return array Draft lines for DocumentIssuer::issue() (possibly empty).
     *
     * @throws RuntimeException When the type is a credit type: a credit is drafted from the document it corrects
     *                          ({@see self::buildCreditLines()}).
     *
     * @since  2.0.0
     */
    public static function buildSalesLines(int $orderId, string $docType, ?array $picks, string $language): array
    {
        if (\in_array($docType, DocumentHelper::creditTypes(), true)) {
            throw new RuntimeException("{$docType} is a credit document type: a credit is drafted from the document it corrects, never from the order's lines.");
        }

        $balances = self::remaining(orderId: $orderId, docType: $docType);

        $picks ??= array_map(
            static fn (object $balance): array => ['selected' => 1, 'quantity' => $balance->remaining_qty],
            $balances,
        );

        $lines = [];

        foreach ($picks as $lineId => $pick) {
            $quantity = (float) ($pick['quantity'] ?? 0);
            $balance = $balances[(int) $lineId] ?? null;

            if (empty($pick['selected']) || $quantity <= 0 || $balance === null) {
                continue;
            }

            $row = $balance->row;
            $currency = $balance->remaining_incl->getCurrency();

            $lines[] = self::draftLine(
                orderItemId:      (int) $row->id,
                sku:              (string) ($row->sku ?? ''),
                name:             MultilingualHelper::resolveLangMap($row->name ?? '', $language),
                unit:             '',
                description:      null,
                quantity:         $quantity,
                unitExcl:         ExactAmount::ofNumber($row->unit_price_tax_excl ?? 0),
                unitIncl:         ExactAmount::ofNumber($row->unit_price_tax_incl ?? 0),
                originalUnitExcl: ExactAmount::ofNumber($row->original_product_price ?? 0),
                discountExcl:     self::printedDiscount($row, $quantity, $currency),
                taxRate:          (float) $row->tax_rate,
                vatCategory:      null,
                vatExemptionCode: null,
                // Frozen AS AT ISSUE from the order line: the identity it
                // moves, and its ordered quantity as the movement ceiling:
                // the same figures the stock path read live until now.
                idItem:           (int) $row->id_item,
                idCombination:    $row->id_combination !== null ? (int) $row->id_combination : null,
                movementBound:    (float) $row->quantity,
                claimed:          self::claim(quantity: $quantity, balance: $balance, currency: $currency),
                currency:         $currency,
                // The agreed custom rate, frozen as at issue from the order
                // line, which stays editable afterwards.
                customRate:       $row->custom_unit_price_tax_incl !== null ? (string) $row->custom_unit_price_tax_incl : null,
            );
        }

        return $lines;
    }

    /**
     * Draft item lines for a CREDIT document against a corrected document.
     *
     * A CREDIT CORRECTS WHAT WAS CHARGED. The corrected document froze its own
     * prices and totals when it was issued, and an order line stays editable
     * afterwards (saveOrderItem carries no issued-document guard). Drafting a
     * credit from today's ORDER line therefore credits the wrong figure:
     * measured, an invoice that charged 12.40 produced a 6.20 credit note after
     * the line was re-priced. So the corrected document's own line is the
     * source of truth: its two totals are the pool a partial takes its
     * cumulative share of, at that line's own tax rate, the final partial
     * takes its exact remaining total, and a full credit reverses exactly
     * what it stated.
     *
     * A picked line the corrected document does not carry is refused by name:
     * a credit gives back only what that document charged.
     *
     * Every fact of the line is the corrected line's: name, SKU, unit and
     * description as printed, the VAT identity, the prices, and the inventory
     * identity and movement ceiling, so a credit gives back what THAT
     * document moved and not what the order line says today. A NULL on the
     * frozen row means "this line moved nothing" and stays NULL. The saving
     * comes from the line's saving pool.
     *
     * @param int $correctsId The corrected document id.
     * @param array $picks The picks, [lineId => ['selected' => 1, 'quantity' => n]], in posted order.
     *
     * @return array Draft lines for DocumentIssuer::issue() (possibly empty).
     *
     * @throws RuntimeException When a picked line is not on the corrected document.
     *
     * @since  2.0.0
     */
    public static function buildCreditLines(int $correctsId, array $picks): array
    {
        $corrected = self::remainingForCredit(correctsId: $correctsId);
        $lines = [];

        foreach ($picks as $lineId => $pick) {
            $quantity = (float) ($pick['quantity'] ?? 0);

            if (empty($pick['selected']) || $quantity <= 0) {
                continue;
            }

            $balance = $corrected[(int) $lineId] ?? null;

            if ($balance === null) {
                throw new RuntimeException("order line #{$lineId} is not on the corrected document; a credit gives back what that document charged.");
            }

            $frozen = $balance->row;
            $currency = $balance->remaining_incl->getCurrency();
            $claimed = self::claim(quantity: $quantity, balance: $balance, currency: $currency);
            $discount = self::claimSaving(quantity: $quantity, balance: $balance);

            $lines[] = self::draftLine(
                orderItemId:      (int) $lineId,
                sku:              (string) $frozen->sku,
                name:             (string) $frozen->name,
                unit:             (string) $frozen->unit,
                description:      $frozen->description !== null ? (string) $frozen->description : null,
                quantity:         $quantity,
                unitExcl:         ExactAmount::ofNumber($frozen->unit_price_tax_excl ?? 0),
                unitIncl:         ExactAmount::ofNumber($frozen->unit_price_tax_incl ?? 0),
                originalUnitExcl: $frozen->original_unit_price_tax_excl !== null ? ExactAmount::ofNumber($frozen->original_unit_price_tax_excl) : null,
                discountExcl:     $discount,
                taxRate:          (float) $frozen->tax_rate,
                vatCategory:      $frozen->vat_category !== null ? (int) $frozen->vat_category : null,
                vatExemptionCode: $frozen->vat_exemption_code !== null ? (string) $frozen->vat_exemption_code : null,
                idItem:           $frozen->id_item !== null ? (int) $frozen->id_item : null,
                idCombination:    $frozen->id_combination !== null ? (int) $frozen->id_combination : null,
                movementBound:    $frozen->movement_bound !== null ? (float) $frozen->movement_bound : null,
                claimed:          $claimed,
                currency:         $currency,
                // The corrected line's own agreed rate, never the order line's.
                customRate:       $frozen->custom_unit_price_tax_incl !== null ? (string) $frozen->custom_unit_price_tax_incl : null,
            );
        }

        return $lines;
    }

    /**
     * One line's balance: its pool, what active documents already stated of
     * it and what is left, per tax axis, on the currency.
     *
     * The pool is what the line may be documented for and the quantity that
     * money belongs to: the order line's totals and quantity for a sales
     * document, what sales documents issued for the order-wide credit pool,
     * the corrected line's stated totals and quantity for a credit.
     *
     * @param object $row The line the stored tax rate and the printed facts are read from.
     * @param int $lineId The order line the balance belongs to.
     * @param float $poolQuantity The quantity the pool's money belongs to.
     * @param float $quantity Quantity still issuable.
     * @param float $targetIncl What the line may be documented for, tax included.
     * @param float $targetExcl What the line may be documented for, tax excluded.
     * @param float $takenIncl What active documents already stated, tax included.
     * @param float $takenExcl What active documents already stated, tax excluded.
     * @param Currency $currency The pool's currency.
     *
     * @return object{row: object, line_id: int, pool_qty: float, pool_incl: Money, pool_excl: Money,
     *                taken_incl: Money, taken_excl: Money, remaining_qty: float, remaining_incl: Money,
     *                remaining_excl: Money}
     *
     * @since  2.0.0
     */
    private static function balance(
        object $row,
        int $lineId,
        float $poolQuantity,
        float $quantity,
        float $targetIncl,
        float $targetExcl,
        float $takenIncl,
        float $takenExcl,
        Currency $currency,
    ): object {
        $zero = Money::zero($currency);

        // Money::of() puts every figure on the minor unit ONCE, here, which is
        // what makes "sums exactly" exact.
        $poolIncl = Money::of($targetIncl, $currency);
        $poolExcl = Money::of($targetExcl, $currency);
        $statedIncl = Money::of($takenIncl, $currency);
        $statedExcl = Money::of($takenExcl, $currency);

        // What is left is never negative: a line edited DOWN after a document
        // was issued can be worth less than what is already documented, and
        // the contract is "never exceeding the line's total". On a credit
        // this floor is the only guard; the issuer's negative-draft refusal
        // covers sales documents only.
        return (object) [
            'row' => $row,
            'line_id' => $lineId,
            'pool_qty' => $poolQuantity,
            'pool_incl' => $poolIncl,
            'pool_excl' => $poolExcl,
            'taken_incl' => $statedIncl,
            'taken_excl' => $statedExcl,
            'remaining_qty' => $quantity,
            'remaining_incl' => Money::max($poolIncl->subtract($statedIncl), $zero),
            'remaining_excl' => Money::max($poolExcl->subtract($statedExcl), $zero),
        ];
    }

    /**
     * THE WHOLE-LINE SAVING A DOCUMENT PRINTS, for the quantity it drafts.
     *
     * Read off the order line's authoritative whole-line columns (the
     * undiscounted original and what is actually charged) and never rebuilt
     * from a per-unit difference.
     *
     * A PARTIAL draft prints its share by quantity: the line's saving times the
     * drafted quantity over the line's quantity, rounded once.
     *
     * NULL AUTHORITY PRINTS ZERO, NOT A GUESS. A line written before Item 3
     * carries no authoritative whole-line saving, and its legacy columns held
     * two different money spaces depending on which writer last touched them.
     * Reconstructing one would be fabricating financial truth; the approved
     * pre-v1 reset regenerates the corpus through the canonical writers.
     *
     * @param object $row The order line.
     * @param float $quantity The quantity this document drafts.
     * @param Currency $currency The order currency.
     *
     *
     * @since  2.0.0
     */
    private static function printedDiscount(object $row, float $quantity, Currency $currency): Money
    {
        if (!OrderLineMoney::hasAuthority($row)) {
            return Money::zero($currency);
        }

        $saving = OrderLineMoney::storedSavingExact($row, withTax: false);
        $lineQuantity = (float) ($row->quantity ?? 0);

        if ($saving === null || !$saving->isPositive() || $lineQuantity <= 0.0) {
            return Money::zero($currency);
        }

        // Quantised ONCE, on the currency, after the allocation, not per unit
        // and then multiplied, which is the drift this replaces. The share is
        // the exact ratio of the two quantities: a quotient formed in floats
        // first is no longer that share, and a tie would round by its error.
        return Money::of($saving->text(), $currency)
            ->timesRatio(numerator: $quantity, denominator: $lineQuantity);
    }

    /**
     * One item draft line in the shape DocumentIssuer::issue() takes.
     *
     * @param int $orderItemId The order line (provenance).
     * @param string $sku SKU as printed.
     * @param string $name Resolved single-language name.
     * @param string $unit Unit label as printed; '' = none.
     * @param string|null $description Description as printed; null = none.
     * @param float $quantity Drafted quantity.
     * @param ExactAmount $unitExcl Unit price, tax excluded (display), on its digits.
     * @param ExactAmount $unitIncl Unit price, tax included (display), on its digits.
     * @param ExactAmount|null $originalUnitExcl Pre-discount unit price, tax excluded.
     * @param Money $discountExcl The line's whole-line saving, net of tax.
     * @param float $taxRate VAT rate.
     * @param int|null $vatCategory Tax-authority VAT category; null = none.
     * @param string|null $vatExemptionCode Exemption reason code; null = none.
     * @param int|null $idItem Inventory identity to freeze; null/0 = none.
     * @param int|null $idCombination Variant identity to freeze; null/0 = none.
     * @param float|null $movementBound Movement ceiling to freeze; null = no authority.
     * @param array $claimed claim()'s two totals.
     * @param Currency $currency The balance's currency.
     * @param string|null $customRate The agreed custom unit rate, tax incl, as stored; null = none.
     *
     * @return array Every money and unit figure as its exact decimal text.
     *
     * @since  2.0.0
     */
    private static function draftLine(
        int $orderItemId,
        string $sku,
        string $name,
        string $unit,
        ?string $description,
        float $quantity,
        ExactAmount $unitExcl,
        ExactAmount $unitIncl,
        ?ExactAmount $originalUnitExcl,
        Money $discountExcl,
        float $taxRate,
        ?int $vatCategory,
        ?string $vatExemptionCode,
        ?int $idItem,
        ?int $idCombination,
        ?float $movementBound,
        array $claimed,
        Currency $currency,
        ?string $customRate,
    ): array {
        return [
            'line_type' => DocumentHelper::LINE_ITEM,
            'id_order_item' => $orderItemId,
            // Provenance above, AUTHORITY here: what this line moves is stated
            // now and never re-read from the order line, which stays editable.
            'id_item' => $idItem,
            'id_combination' => $idCombination,
            'movement_bound' => $movementBound,
            'sku' => $sku,
            'name' => $name,
            'unit' => $unit,
            'description' => $description,
            'vat_category' => $vatCategory,
            'vat_exemption_code' => $vatExemptionCode,
            'quantity' => $quantity,
            'unit_price_tax_excl' => $unitExcl->text(),
            'unit_price_tax_incl' => $unitIncl->text(),
            // The explicit custom rate the line was agreed at, frozen as a fact
            // of the document and never re-read from the order line.
            'custom_unit_price_tax_incl' => $customRate,
            // One predicate for sales and credit lines alike: a
            // was-price only beside a positive whole-line saving.
            'original_unit_price_tax_excl' => $discountExcl->isPositive() ? $originalUnitExcl?->text() : null,
            // THE PRINTED DISCOUNT CONSUMES THE AUTHORITATIVE WHOLE-LINE
            // FIGURES.
            //
            // RETIRED HERE, and quoted so it cannot return unnoticed:
            //
            //   ($original - $unitExcl) * $quantity
            //
            // Two defects in that one expression. It rebuilt a WHOLE-LINE
            // amount from a PER-UNIT difference multiplied by quantity, so the
            // per-unit rounding was multiplied instead of absorbed once:
            // measured over realistic lines, 78.6% of them printed a figure
            // that was not the line's actual saving, worst four cents out on a
            // ten-unit line. And `$original > $unitExcl` INFERRED that a
            // discount had happened by comparing two prices, the same
            // inference the custom-price contract forbids for the override state.
            //
            // Each builder now hands in a whole-line saving: a sales line the
            // order line's stored original minus final, pro-rated to the
            // drafted quantity (printedDiscount()); a credit line its share of
            // the corrected line's saving pool (claimSaving()). A row with NO
            // Item 3 authority prints NOTHING rather than a reconstruction:
            // an incomplete document is honest, a fabricated saving is not.
            'discount_tax_excl' => ExactAmount::ofMoney($discountExcl)->text(),
            'tax_rate' => $taxRate,
            'total_tax_excl' => $claimed['total_tax_excl'],
            'total_tax_incl' => $claimed['total_tax_incl'],
            // Informational: the issuer stores the difference of the two totals.
            'tax_amount' => ExactAmount::ofMoney(
                Money::of($claimed['total_tax_incl'], $currency)->subtract(Money::of($claimed['total_tax_excl'], $currency)),
            )->text(),
        ];
    }
}
