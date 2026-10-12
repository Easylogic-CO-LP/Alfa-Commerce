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
use Alfa\Component\Alfa\Administrator\Service\Order\OrderLineMoney;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoney;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoneyRefusal;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal;
use Alfa\Component\Alfa\Site\Service\Pricing\TaxChain;
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingPool;
use Joomla\CMS\Factory;
use LogicException;
use RuntimeException;

/**
 * OrderChargeLines: the order's NON-ITEM money as document draft lines.
 *
 * An order's amount paid is items + shipping + payment fees - cart-rule
 * discounts. Documents built from item lines alone total MORE than the
 * customer paid, silently; so every fiscal sales draft appends the order's
 * remaining charges from here, and both draft builders (auto-issue, manual
 * modal) share this one implementation.
 *
 * The three charge kinds map onto the line vocabulary the issuer already
 * accepts, each linked to its frozen source row through `id_source`:
 *
 *   LINE_SHIPPING    one per #__alfa_order_shipments row (gross carrier
 *                    charge at its frozen VAT rate; cancelled/returned
 *                    parcels excluded)
 *   LINE_FEE         one per #__alfa_order_contributions row
 *   LINE_ADJUSTMENT  one or more NEGATIVE lines per #__alfa_order_cart_rule
 *                    row (qty stays 1: the issuer forbids qty <= 0, prices
 *                    carry the sign)
 *
 * CONSUMABLE BALANCE (the partial-documents contract): each charge is a
 * balance claimed AT MOST once across all issued sales documents: €20 of
 * discount split €12 + €8 over two partials, never €20 twice. Consumption is
 * ledgered by summing issued lines per (line_type, id_source); voided
 * documents release their claims automatically. DocumentIssuer::guardCharges
 * re-checks the same sums inside the order lock, so concurrent issuance
 * cannot double-claim.
 *
 * DISCOUNT SIZING on a partial draft: a rule claims its remaining balance
 * x the draft's share of the remaining item value, measured on the frozen
 * line totals (OrderItemLines); the draft that takes the LAST remaining item
 * quantity claims the whole remaining balance instead (the same remainder
 * rule OrderItemLines::claim() applies to the item lines).
 *
 * VAT of an order-level discount is attributed PRO-RATA across the draft's
 * item lines' VAT rates (one negative line per rate; rounding drift absorbed
 * into the largest bucket). Shipping-class rules attach to the draft's
 * shipping lines instead and are clamped so claimed shipping minus claimed
 * benefit can never go negative. The frozen rows do not record WHICH lines a
 * product-class rule matched, so pro-rata over all draft items is the
 * closest attribution the frozen state supports.
 *
 * Charges apply to FISCAL documents. A SALES draft claims the ORDER's own
 * charges (above). A CREDIT draft claims the CORRECTED INVOICE's charge lines
 * instead: candidates come from the document being corrected, never from the
 * order's current charge state, because an order keeps changing after an
 * invoice freezes (a fee added later, a parcel cancelled) and a credit note
 * may only give back what that invoice actually charged. Delivery notes
 * carry no money by construction.
 *
 * CREDIT SIZING:
 *   shipping/fee  the amount the operator states for the invoice's own line,
 *                 checked by manualCreditPicks(); or, on a proposed correction,
 *                 the amount the drift names; either way capped at what the
 *                 invoice line has left;
 *   product/order discounts  never pickable: they follow the credited
 *                 goods RATE BY RATE: at each VAT rate a rule gives back
 *                 its share of what it has left at that rate in proportion
 *                 to the goods credited there, so a credit of goods at one
 *                 rate carries rows at that rate only; the draft that
 *                 credits the last goods at a rate takes the rule's exact
 *                 remainder there, and the one that credits the LAST
 *                 invoiced unit takes it at every rate;
 *   shipping-class discounts  on an operator's credit, each rule gives back
 *                 its invoiced amount x the shipping this credit gives back /
 *                 the invoice's shipping, rounded once and capped by what the
 *                 rule has left; the credit that empties the invoice's
 *                 shipping takes each rule's exact remainder (see
 *                 shippingDiscountAllocation()); on a proposal, only
 *                 the discount the order no longer applies (chargeDrift());
 *   VAT           every line carries the invoice's own rate, VAT category and
 *                 exemption code, and the amount claimed from that bucket.
 * Each invoice charge is creditable at most once across every credit note
 * correcting it; DocumentIssuer re-checks that per (line_type, id_source)
 * inside the order lock.
 *
 * @since  1.9.0
 */
final class OrderChargeLines
{
    /**
     * QUANTITY comparison tolerance. NOT for money, and never again.
     *
     * A quantity is legitimately fractional (half a metre of cable, 0.3 of a
     * kilo) and no scale makes it exact, so comparing one needs a tolerance.
     * MONEY IS THE OPPOSITE: at a stated currency scale it is an integer count
     * of minor units, and "is there anything left" is an exact question with an
     * exact answer.
     *
     * This constant used to answer both. Twenty monetary comparisons asked
     * whether a charge pool, a claim or a remainder was "close enough to zero"
     * at a millionth (a figure smaller than the smallest unit of every
     * currency Alfa supports) over values carried as six-decimal floats. A
     * pool could therefore be empty to the cent and still test as fundable, and
     * an allocation could be capped against a number the ledger never held.
     *
     * Money now goes through {@see self::isPositive()} and
     * {@see self::minorUnits()}, which quantise on the SOURCE RECORD'S FROZEN
     * SCALE and compare integers. Quantity keeps this.
     *
     * @var float
     * @since  1.9.0
     */
    private const QUANTITY_EPSILON = 0.000001;

    /**
     * Is this amount MORE THAN NOTHING, exactly, at the record's own scale?
     *
     * The one monetary question this file asks, and it has one exact answer.
     * Anything that quantises to zero minor units IS zero, not "nearly" zero,
     * and not zero-within-a-tolerance-nobody-chose.
     *
     * @param float $amount The amount, as the charge rows carry it.
     * @param Currency $currency The authoritative source record's currency, at its frozen scale.
     *
     *
     * @since  1.13.0
     */
    private static function isPositive(float $amount, Currency $currency): bool
    {
        return Money::of($amount, $currency)->isPositive();
    }

    /**
     * The same question about a magnitude, for a pool that may be signed.
     *
     * @param float $amount The amount.
     * @param Currency $currency The authoritative currency.
     *
     * @return bool
     *
     * @since  1.13.0
     */
    /**
     * An amount as the authoritative record's money, exactly.
     *
     * THE POOLS ARE NOW CARRIED AT THE SAME EXACTNESS THEY ARE DECIDED AT.
     * isPositive() and hasMagnitude() made every charge DECISION exact at the
     * frozen scale, but the figures they were asked about were still built by
     * `round(..., 6)`, a hard-coded six decimals that is a hundred times
     * coarser than a minor unit at scale 8 and far finer than one at scale 0.
     * That was tolerable while the numbers were only ever compared; it stops
     * being tolerable the moment a projection REPORTS them, because the
     * reported figure then carries residue the predicate was suppressing.
     *
     * @param float $amount The amount, as the charge rows carry it.
     * @param Currency $currency The authoritative source record's currency, at its frozen scale.
     *
     *
     * @since  1.13.0
     */
    private static function exact(float $amount, Currency $currency): float
    {
        return Money::of($amount, $currency)->getAmount();
    }

    /**
     * What a pool has left after what has been taken, never below zero.
     *
     * The clamp is the pool's, not a presentation choice: a charge cannot be
     * claimed more than once, so a negative remainder is not a debt, it is an
     * over-claim that the guards refuse separately.
     *
     * @param float $gross What the source charged.
     * @param float $used What documents have taken.
     * @param Currency $currency The authoritative source record's currency, at its frozen scale.
     *
     *
     * @since  1.13.0
     */
    private static function remainder(float $gross, float $used, Currency $currency): float
    {
        return Money::max(
            Money::of($gross, $currency)->subtract(Money::of($used, $currency)),
            Money::zero($currency),
        )->getAmount();
    }

    /**
     * What a charge has left, as its Money: the charge less what documents
     * already claimed of it, never below zero.
     *
     * @param Money $gross The charge, on its stored digits.
     * @param float $used What the active documents claimed of it.
     *
     *
     * @since  1.14.0
     */
    private static function remainderOf(Money $gross, float $used): Money
    {
        $currency = $gross->getCurrency();

        return Money::max($gross->subtract(Money::of($used, $currency)), Money::zero($currency));
    }

    private static function hasMagnitude(float $amount, Currency $currency): bool
    {
        return Money::of(abs($amount), $currency)->isPositive();
    }

    /**
     * Amounts added as the record's money: each read once at the currency's
     * scale, the sum formed on minor units.
     *
     * A float sum of amounts is not their sum: past fifteen significant
     * digits it reads back a minor unit away, and at seven and eight
     * decimals any rounding coarser than the scale cuts what it holds.
     *
     * @param array $amounts The amounts, as the charge rows carry them.
     * @param Currency $currency The authoritative source record's currency, at its frozen scale.
     *
     *
     * @since  1.14.0
     */
    private static function sumOf(array $amounts, Currency $currency): Money
    {
        $sum = Money::zero($currency);

        foreach ($amounts as $amount) {
            $sum = $sum->add(Money::of(\is_string($amount) || \is_int($amount) ? $amount : (float) $amount, $currency));
        }

        return $sum;
    }

    /**
     * One amount less another, as the record's money.
     *
     * @param float $amount The amount.
     * @param float $less What is taken off it.
     * @param Currency $currency The authoritative source record's currency, at its frozen scale.
     *
     * @return float At the currency's scale.
     *
     * @since  1.14.0
     */
    private static function difference(float $amount, float $less, Currency $currency): float
    {
        return Money::of($amount, $currency)->subtract(Money::of($less, $currency))->getAmount();
    }

    /**
     * The order's charges with their unclaimed balances.
     *
     * @param int $orderId The order id.
     *
     * @return object{shipping: array, fees: array, rules: array} Each entry
     *                                                            carries the frozen source row's amounts and what remains
     *                                                            claimable (remaining_excl / remaining_incl).
     *
     * @since  1.9.0
     */
    public static function remaining(int $orderId): object
    {
        // The order's own frozen scale (the authoritative source record's)
        // for every monetary question below.
        $currency = OrderItemLines::orderCurrency(orderId: $orderId);
        $db = Factory::getContainer()->get('DatabaseDriver');
        $consumed = self::consumedBySource(db: $db, orderId: $orderId);
        // A credit note's discount GRANT is discount the order's documents
        // already give: a later sales document must not claim it again.
        $ledger = self::discountLedger(db: $db, orderId: $orderId, currency: $currency);

        $shipping = [];
        $fees = [];
        $rules = [];

        // Shipping: gross carrier charge per live parcel
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'shipment_method_name', 'shipping_cost_tax_incl', 'shipping_cost_tax_excl', 'shipping_tax_rate']))
            ->from($db->quoteName('#__alfa_order_shipments'))
            ->where($db->quoteName('id_order') . ' = :orderId')
            ->where($db->quoteName('status') . " NOT IN ('cancelled', 'returned')")
            ->bind(':orderId', $orderId);
        $db->setQuery(query: $query);

        foreach ($db->loadObjectList() ?: [] as $row) {
            // The parcel's two costs on their stored digits, and what it has
            // left as the exact decimal texts a document line is handed.
            $incl = Money::of((string) $row->shipping_cost_tax_incl, $currency);
            $excl = Money::of((string) $row->shipping_cost_tax_excl, $currency);
            $used = $consumed[DocumentHelper::LINE_SHIPPING][(int) $row->id] ?? ['incl' => 0.0, 'excl' => 0.0];

            $shipping[] = (object) [
                'id' => (int) $row->id,
                'name' => (string) ($row->shipment_method_name ?: 'Shipping'),
                // The rate the charge was taxed at, frozen with it: a rate read
                // back from the rounded amounts is not one (8.06 of 10.00 reads
                // as 24.069%).
                'tax_rate' => (float) $row->shipping_tax_rate,
                'amount_incl' => ExactAmount::ofMoney($incl)->text(),
                'amount_excl' => ExactAmount::ofMoney($excl)->text(),
                'remaining_incl' => ExactAmount::ofMoney(self::remainderOf(gross: $incl, used: $used['incl']))->text(),
                'remaining_excl' => ExactAmount::ofMoney(self::remainderOf(gross: $excl, used: $used['excl']))->text(),
            ];
        }

        // Payment/cart fees: frozen contributions
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'name', 'tax_rate', 'amount_tax_incl', 'amount_tax_excl']))
            ->from($db->quoteName('#__alfa_order_contributions'))
            ->where($db->quoteName('id_order') . ' = :orderId')
            ->order($db->quoteName('sort_order') . ' ASC')
            ->bind(':orderId', $orderId);
        $db->setQuery(query: $query);

        foreach ($db->loadObjectList() ?: [] as $row) {
            $incl = (float) $row->amount_tax_incl;
            $excl = (float) $row->amount_tax_excl;
            $used = $consumed[DocumentHelper::LINE_FEE][(int) $row->id] ?? ['incl' => 0.0, 'excl' => 0.0];

            $fees[] = (object) [
                'id' => (int) $row->id,
                'name' => MultilingualHelper::resolveLangMap((string) $row->name, DocumentHelper::defaultLanguageTag()),
                'tax_rate' => (float) $row->tax_rate,
                'amount_incl' => $incl,
                'amount_excl' => $excl,
                'remaining_incl' => self::remainder($incl, $used['incl'], $currency),
                'remaining_excl' => self::remainder($excl, $used['excl'], $currency),
            ];
        }

        // Cart rules: the frozen discount rows
        // In row order, as OrderTotalHelper::getBreakdown() reads them: stacked
        // shipping-class rules are clamped one after another, and the split
        // between them follows that order (chargeDrift()).
        $query = $db->getQuery(true)
            ->select($db->quoteName([
                'id_order_cart_rule', 'name', 'code', 'discount_class',
                'value_tax_excl', 'value_tax_incl', 'free_shipping',
            ]))
            ->from($db->quoteName('#__alfa_order_cart_rule'))
            ->where($db->quoteName('id_order') . ' = :orderId')
            ->where($db->quoteName('deleted') . ' = 0')
            ->order($db->quoteName('id_order_cart_rule') . ' ASC')
            ->bind(':orderId', $orderId);
        $db->setQuery(query: $query);

        foreach ($db->loadObjectList() ?: [] as $row) {
            $incl = (float) $row->value_tax_incl;
            $excl = (float) $row->value_tax_excl;

            // Rows frozen before 1.9.0 carry no inclusive figure: the excl
            // value is the only honest amount left (same fallback as
            // OrderTotalHelper::computeFromArrays).
            if (!Money::of($incl, $currency)->isPositive()) {
                $incl = $excl;
            }

            // Consumed adjustment lines are NEGATIVE: claims accrue as |sum|;
            // a credit note's grant of the same discount is claimed too.
            $used = $consumed[DocumentHelper::LINE_ADJUSTMENT][(int) $row->id_order_cart_rule] ?? ['incl' => 0.0, 'excl' => 0.0];
            $granted = $ledger[(int) $row->id_order_cart_rule] ?? null;

            $rules[] = (object) [
                'id' => (int) $row->id_order_cart_rule,
                // The email convention the merchant chose: the coupon CODE
                // the customer typed, the frozen name for automatic rules.
                'label' => (string) (($row->code ?? '') !== '' && $row->code !== null ? $row->code : $row->name),
                'discount_class' => (string) $row->discount_class,
                'amount_incl' => $incl,
                'amount_excl' => $excl,
                'remaining_incl' => self::remainder($incl, Money::of(abs($used['incl']), $currency)->add($granted->granted_incl ?? Money::zero($currency))->getAmount(), $currency),
                'remaining_excl' => self::remainder($excl, Money::of(abs($used['excl']), $currency)->add($granted->granted_excl ?? Money::zero($currency))->getAmount(), $currency),
            ];
        }

        return (object) ['shipping' => $shipping, 'fees' => $fees, 'rules' => $rules];
    }

    /**
     * The corrected invoices' own charge lines with their uncredited balances.
     *
     * The credit-side twin of remaining(): the frozen side is the INVOICE, not
     * the order, and the consumed side is what ISSUED credit notes correcting
     * that invoice already took per (line_type, id_source). A voided note
     * drops out, so a void releases its claim. The consumed population is the
     * one DocumentIssuer's invoice-total cap counts (id_corrects and ISSUED,
     * no type filter), so the two ceilings never disagree about what has
     * been credited.
     *
     * Each candidate carries its VAT BUCKETS, keyed by vatBucketKey(): one per
     * distinct (tax_rate, vat_category, vat_exemption_code) the invoice froze
     * for that source. The invoice may have split one cart rule across several
     * rates, and its VAT summary is grouped on rate and category with the
     * exemption reason alongside, so a credit that claims inside the buckets
     * and copies all three columns keeps every VAT figure the invoice's own.
     *
     * Amounts are SIGNED as the invoice signed them (adjustments negative), and
     * a balance is clamped toward zero, never past it. Every figure is at the
     * corrected document's own frozen currency.
     *
     * An invoice charge line always names its source: DocumentIssuer refuses a
     * sales charge line without one, so no
     * charge is missing from this list.
     *
     * A credited charge line that names no source, or names one this invoice
     * never charged (a discount grant excepted), is placed on no source:
     * nothing records which pool it consumed. The order is then
     * unavailable for credit (unrecordedCreditNotes()).
     *
     * @param int[] $correctsIds Corrected (issued sales) document ids.
     *
     * @return array<int, object{shipping: object[], fees: object[], rules: object[], item_basis: object}>
     *                                                                                                     Keyed by document id, one entry per requested id that names a
     *                                                                                                     document. A candidate is {id, label, discount_class, amount_incl,
     *                                                                                                     amount_excl, remaining_incl, remaining_excl, buckets}; the item
     *                                                                                                     basis is creditItemBases()' entry for the document.
     *
     * @since  1.10.0
     */
    public static function correctedChargesFor(array $correctsIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $correctsIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // Each corrected document's own frozen currency: the whole
        // of what its header froze, and the order it was issued for.
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'id_order', 'id_currency', 'currency_code', 'currency_scale']))
            ->from($db->quoteName('#__alfa_documents'))
            ->whereIn($db->quoteName('id'), $ids);
        $db->setQuery(query: $query);
        $currencies = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $currencies[(int) $row->id] = DocumentMoney::currencyOf($row);
        }

        $ids = array_values(array_filter($ids, static fn (int $id): bool => isset($currencies[$id])));

        if ($ids === []) {
            return [];
        }

        $groupBy = [
            $db->quoteName('i.line_type'),
            $db->quoteName('i.id_source'),
            $db->quoteName('i.tax_rate'),
            $db->quoteName('i.vat_category'),
            $db->quoteName('i.vat_exemption_code'),
        ];

        // What each invoice charged, per source and VAT identity
        // The rule row is not filtered on `deleted`: it is soft-deleted and only
        // ever removed with its order, so it outlives every document of it.
        $query = $db->getQuery(true)
            ->select(array_merge([$db->quoteName('i.id_document')], $groupBy, [
                'MIN(' . $db->quoteName('i.name') . ') AS ' . $db->quoteName('label'),
                'MAX(' . $db->quoteName('r.discount_class') . ') AS ' . $db->quoteName('discount_class'),
                'SUM(' . $db->quoteName('i.total_tax_incl') . ') AS ' . $db->quoteName('incl'),
                'SUM(' . $db->quoteName('i.total_tax_excl') . ') AS ' . $db->quoteName('excl'),
            ]))
            ->from($db->quoteName('#__alfa_document_items', 'i'))
            ->join(
                'LEFT',
                $db->quoteName('#__alfa_order_cart_rule', 'r') . ' ON r.id_order_cart_rule = i.id_source AND i.line_type = '
                . $db->quote(DocumentHelper::LINE_ADJUSTMENT),
            )
            ->whereIn($db->quoteName('i.id_document'), $ids)
            ->where($db->quoteName('i.id_source') . ' IS NOT NULL')
            ->where($db->quoteName('i.line_type') . ' <> ' . $db->quote(DocumentHelper::LINE_ITEM))
            ->group(array_merge([$db->quoteName('i.id_document')], $groupBy))
            ->order('MIN(' . $db->quoteName('i.id') . ') ASC');
        $db->setQuery(query: $query);

        $sources = array_fill_keys($ids, []);

        // A shipping or fee charge's remaining, also as exact money: what its
        // lines charged less what the issued credits took from it, read from
        // the stored figures (the buckets below carry floats, which hold no
        // more than fifteen digits). An operator's stated amount is judged on it.
        $exact = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $type = (string) $row->line_type;
            $sourceId = (int) $row->id_source;

            if ($type === DocumentHelper::LINE_SHIPPING || $type === DocumentHelper::LINE_FEE) {
                $currency = $currencies[(int) $row->id_document];
                $exactKey = (int) $row->id_document . '|' . $type . '|' . $sourceId;
                $exact[$exactKey] = ($exact[$exactKey] ?? Money::zero($currency))->add(Money::of((string) $row->incl, $currency));
            }

            $entry = $sources[(int) $row->id_document][$type][$sourceId] ??= (object) [
                'id' => $sourceId,
                // 'label', the spelling remaining() and the order screen use.
                'label' => (string) $row->label,
                // A class that cannot be read is merchandise-following: that
                // can only credit LESS, never refund a discount the customer kept.
                'discount_class' => $type === DocumentHelper::LINE_ADJUSTMENT
                    ? ((string) ($row->discount_class ?? '') ?: 'product')
                    : '',
                'amount_incl' => 0.0,
                'amount_excl' => 0.0,
                'remaining_incl' => 0.0,
                'remaining_excl' => 0.0,
                'buckets' => [],
            ];

            $key = self::vatBucketKey(rate: (float) $row->tax_rate, category: $row->vat_category, code: $row->vat_exemption_code);
            $entry->buckets[$key] = [
                'rate' => (float) $row->tax_rate,
                'vat_category' => $row->vat_category === null ? null : (int) $row->vat_category,
                'vat_exemption_code' => $row->vat_exemption_code === null ? null : (string) $row->vat_exemption_code,
                'incl' => (float) $row->incl,
                'excl' => (float) $row->excl,
                'remaining_incl' => (float) $row->incl,
                'remaining_excl' => (float) $row->excl,
            ];
        }

        // What each source charged, fixed before anything is taken off it.
        foreach ($sources as $documentId => $byType) {
            foreach ($byType as $byId) {
                foreach ($byId as $entry) {
                    $entry->amount_incl = self::sumOf(array_column($entry->buckets, 'incl'), $currencies[$documentId])->getAmount();
                    $entry->amount_excl = self::sumOf(array_column($entry->buckets, 'excl'), $currencies[$documentId])->getAmount();
                }
            }
        }

        // What issued credit notes correcting them already took
        // No id_source filter: every credited charge line is read, and one
        // whose pool nothing records is placed on no source (below).
        // A discount GRANT (a positive adjustment line on a credit note) is
        // grouped apart from a reversal: one adds discount, the other takes it
        // back, and a sum would net them.
        $query = $db->getQuery(true)
            ->select(array_merge([$db->quoteName('d.id_corrects')], $groupBy, [
                '(' . $db->quoteName('i.total_tax_incl') . ' > 0) AS ' . $db->quoteName('positive'),
                'MIN(' . $db->quoteName('i.name') . ') AS ' . $db->quoteName('label'),
                'MAX(' . $db->quoteName('r.discount_class') . ') AS ' . $db->quoteName('discount_class'),
                'SUM(' . $db->quoteName('i.total_tax_incl') . ') AS ' . $db->quoteName('incl'),
                'SUM(' . $db->quoteName('i.total_tax_excl') . ') AS ' . $db->quoteName('excl'),
            ]))
            ->from($db->quoteName('#__alfa_document_items', 'i'))
            ->join('INNER', $db->quoteName('#__alfa_documents', 'd') . ' ON d.id = i.id_document')
            ->join(
                'LEFT',
                $db->quoteName('#__alfa_order_cart_rule', 'r') . ' ON r.id_order_cart_rule = i.id_source AND i.line_type = '
                . $db->quote(DocumentHelper::LINE_ADJUSTMENT),
            )
            ->whereIn($db->quoteName('d.id_corrects'), $ids)
            ->where($db->quoteName('d.status') . ' = ' . DocumentHelper::STATUS_ISSUED)
            ->where($db->quoteName('i.line_type') . ' <> ' . $db->quote(DocumentHelper::LINE_ITEM))
            ->group(array_merge([$db->quoteName('d.id_corrects')], $groupBy, [$db->quoteName('positive')]))
            // Grants first: what a reversal may take back includes them, and
            // taking before adding would clamp at a figure that is not the one.
            ->order($db->quoteName('positive') . ' DESC');
        $db->setQuery(query: $query);

        foreach ($db->loadObjectList() ?: [] as $row) {
            $documentId = (int) $row->id_corrects;
            $type = (string) $row->line_type;
            $key = self::vatBucketKey(rate: (float) $row->tax_rate, category: $row->vat_category, code: $row->vat_exemption_code);

            // A GRANT is discount this document now gives on top of what it
            // charged; the rule may be one it never carried. It widens what a
            // later credit of the goods takes back with them, so it is added
            // to the source's discount, never taken off it.
            if ($type === DocumentHelper::LINE_ADJUSTMENT && $row->id_source !== null && (int) $row->positive === 1) {
                $grant = $sources[$documentId][$type][(int) $row->id_source] ??= (object) [
                    'id' => (int) $row->id_source,
                    'label' => (string) $row->label,
                    'discount_class' => (string) ($row->discount_class ?? '') ?: 'product',
                    'amount_incl' => 0.0,
                    'amount_excl' => 0.0,
                    'remaining_incl' => 0.0,
                    'remaining_excl' => 0.0,
                    'buckets' => [],
                ];
                $grant->buckets[$key] ??= [
                    'rate' => (float) $row->tax_rate,
                    'vat_category' => $row->vat_category === null ? null : (int) $row->vat_category,
                    'vat_exemption_code' => $row->vat_exemption_code === null ? null : (string) $row->vat_exemption_code,
                    'incl' => 0.0,
                    'excl' => 0.0,
                    'remaining_incl' => 0.0,
                    'remaining_excl' => 0.0,
                ];

                foreach (['incl', 'excl'] as $axis) {
                    $grant->buckets[$key][$axis] = self::difference($grant->buckets[$key][$axis], (float) $row->{$axis}, $currencies[$documentId]);
                    $grant->buckets[$key]['remaining_' . $axis] = self::difference($grant->buckets[$key]['remaining_' . $axis], (float) $row->{$axis}, $currencies[$documentId]);
                }

                $grant->amount_incl = self::sumOf(array_column($grant->buckets, 'incl'), $currencies[$documentId])->getAmount();
                $grant->amount_excl = self::sumOf(array_column($grant->buckets, 'excl'), $currencies[$documentId])->getAmount();

                continue;
            }

            $entry = $row->id_source === null ? null : ($sources[$documentId][$type][(int) $row->id_source] ?? null);
            $exactKey = $documentId . '|' . $type . '|' . (int) $row->id_source;

            if ($entry !== null && isset($exact[$exactKey])) {
                $exact[$exactKey] = $exact[$exactKey]->subtract(Money::of(ltrim((string) $row->incl, '-'), $currencies[$documentId]));
            }

            if ($entry !== null && isset($entry->buckets[$key])) {
                // A line past its bucket's remaining is still that source's
                // money: the rest comes off its other buckets, so what the
                // source has left is exact even when one bucket was overdrawn.
                [$overIncl, $overExcl] = self::take(entry: $entry, key: $key, incl: abs((float) $row->incl), excl: abs((float) $row->excl), currency: $currencies[$documentId]);
                self::drain(targets: self::bucketsLargestFirst([$entry]), incl: $overIncl, excl: $overExcl, currency: $currencies[$documentId]);

                continue;
            }

            if ($entry !== null) {
                // Its own source in a VAT identity the invoice never froze for
                // it: still that source's money, taken from its largest bucket.
                self::drain(targets: self::bucketsLargestFirst([$entry]), incl: abs((float) $row->incl), excl: abs((float) $row->excl), currency: $currencies[$documentId]);

                continue;
            }

            // Nothing records which pool this line consumed. The order
            // is unavailable for credit, unrecordedCreditNotes(), so no
            // entrance calculates on these figures.
            continue;
        }

        $bases = self::creditItemBases(db: $db, correctsIds: $ids, currencies: $currencies);
        $result = [];

        foreach ($ids as $documentId) {
            foreach ($sources[$documentId] as $type => $byId) {
                foreach ($byId as $entry) {
                    $entry->remaining_incl = self::sumOf(array_column($entry->buckets, 'remaining_incl'), $currencies[$documentId])->getAmount();
                    $entry->remaining_excl = self::sumOf(array_column($entry->buckets, 'remaining_excl'), $currencies[$documentId])->getAmount();

                    if (isset($exact[$documentId . '|' . $type . '|' . $entry->id])) {
                        $entry->remaining_incl_exact = ExactAmount::ofMoney($exact[$documentId . '|' . $type . '|' . $entry->id]->nonNegative())->text();
                    }
                }
            }

            $result[$documentId] = (object) [
                'shipping' => array_values($sources[$documentId][DocumentHelper::LINE_SHIPPING] ?? []),
                'fees' => array_values($sources[$documentId][DocumentHelper::LINE_FEE] ?? []),
                'rules' => array_values($sources[$documentId][DocumentHelper::LINE_ADJUSTMENT] ?? []),
                'item_basis' => $bases[$documentId],
            ];
        }

        return $result;
    }

    /**
     * One corrected invoice's charge candidates (see correctedChargesFor()).
     *
     * @param int $correctsId The corrected (issued sales) document id.
     *
     * @return object{shipping: object[], fees: object[], rules: object[], item_basis: object}
     *
     * @since  1.10.0
     */
    public static function correctedCharges(int $correctsId): object
    {
        return self::correctedChargesFor(correctsIds: [$correctsId])[$correctsId] ?? (object) [
            'shipping' => [],
            'fees' => [],
            'rules' => [],
            'item_basis' => (object) ['remaining_incl' => 0.0, 'remaining_qty' => 0.0, 'rates' => []],
        ];
    }

    /**
     * Whether an order's credits are unavailable, and which credit notes make
     * them so: one fact per order, read before anything is calculated.
     *
     * An ISSUED credit note of the order, correcting any of its invoices, makes
     * it unavailable when it carries a non-item line that names no source, or
     * names one its corrected invoice never charged. Two lines are recorded
     * all the same, exactly as correctedChargesFor() attributes them: a
     * discount grant (a positive adjustment), and a negative adjustment that
     * reverses a rule an issued credit of the same invoice granted. A voided
     * note never counts.
     *
     * A credit note's replacement is built with that note released, so the
     * screen that offers the replacement asks with it released too, and both
     * answer for the same state: the note is not counted, and a discount it
     * grants records no other note's reversal.
     *
     * Read with a note standing, the discounts that note grants record other
     * notes' reversals whatever its status, and the note itself is not
     * listed: the notes named are those nothing accounts for even while it
     * stands. The answer is the same while the note is issued and while it is
     * released in its replacement's savepoint, so the refusal of that
     * replacement tells one shape wherever it is asked.
     *
     * @param int $orderId The order id.
     * @param int $releasing The credit note read as released, as a replacement
     *                       of it is built; 0 reads the order as it stands.
     * @param int $standing The credit note read as standing whatever its
     *                      status, and not listed; 0 for none. Never the
     *                      note read as released.
     *
     * @return list<object{id: int, number_formatted: string}> Ordered by id; [] = not affected.
     *
     * @throws RuntimeException When such a note is issued without a number.
     *
     * @since  1.14.0
     */
    public static function unrecordedCreditNotes(int $orderId, int $releasing = 0, int $standing = 0): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $issued = (int) DocumentHelper::STATUS_ISSUED;
        $adjustment = $db->quote(DocumentHelper::LINE_ADJUSTMENT);

        // The corrected invoice charged this source.
        $charged = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName('#__alfa_document_items', 's'))
            ->where([
                $db->quoteName('s.id_document') . ' = ' . $db->quoteName('d.id_corrects'),
                $db->quoteName('s.line_type') . ' = ' . $db->quoteName('i.line_type'),
                $db->quoteName('s.id_source') . ' = ' . $db->quoteName('i.id_source'),
            ]);

        // An issued credit of the same invoice granted this rule; a note read
        // as standing grants whatever its status.
        $grantIssued = $db->quoteName('d2.status') . ' = ' . $issued;

        if ($standing > 0) {
            $grantIssued = '(' . $grantIssued . ' OR ' . $db->quoteName('d2.id') . ' = ' . $standing . ')';
        }

        $granted = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName('#__alfa_document_items', 'g'))
            ->join('INNER', $db->quoteName('#__alfa_documents', 'd2') . ' ON ' . $db->quoteName('d2.id') . ' = ' . $db->quoteName('g.id_document'))
            ->where([
                $db->quoteName('d2.id_corrects') . ' = ' . $db->quoteName('d.id_corrects'),
                $grantIssued,
                $db->quoteName('g.line_type') . ' = ' . $adjustment,
                $db->quoteName('g.total_tax_incl') . ' > 0',
                $db->quoteName('g.id_source') . ' = ' . $db->quoteName('i.id_source'),
            ]);

        if ($releasing > 0) {
            $granted->where($db->quoteName('d2.id') . ' <> ' . $releasing);
        }

        $isGrant = '(' . $db->quoteName('i.line_type') . ' = ' . $adjustment . ' AND ' . $db->quoteName('i.total_tax_incl') . ' > 0)';

        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('d.id') . ', ' . $db->quoteName('d.number_formatted'))
            ->from($db->quoteName('#__alfa_documents', 'd'))
            ->join('INNER', $db->quoteName('#__alfa_document_items', 'i') . ' ON ' . $db->quoteName('i.id_document') . ' = ' . $db->quoteName('d.id'))
            ->where([
                $db->quoteName('d.id_order') . ' = :orderId',
                $db->quoteName('d.status') . ' = ' . $issued,
                $db->quoteName('d.id_corrects') . ' IS NOT NULL',
                $db->quoteName('i.line_type') . ' <> ' . $db->quote(DocumentHelper::LINE_ITEM),
                '(' . $db->quoteName('i.id_source') . ' IS NULL'
                . ' OR (NOT ' . $isGrant
                . ' AND NOT EXISTS (' . $charged . ')'
                . ' AND NOT (' . $db->quoteName('i.line_type') . ' = ' . $adjustment . ' AND EXISTS (' . $granted . '))))',
            ])
            ->order($db->quoteName('d.id') . ' ASC')
            ->bind(':orderId', $orderId);

        if ($releasing > 0) {
            $query->where($db->quoteName('d.id') . ' <> ' . $releasing);
        }

        if ($standing > 0) {
            $query->where($db->quoteName('d.id') . ' <> ' . $standing);
        }

        $db->setQuery(query: $query);
        $notes = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $number = (string) ($row->number_formatted ?? '');

            // The reason names the note by its frozen number; none is invented.
            if ($number === '') {
                throw new RuntimeException("credit note #{$row->id} is issued without a number, so the reason cannot name it");
            }

            $notes[] = (object) ['id' => (int) $row->id, 'number_formatted' => $number];
        }

        return $notes;
    }

    /**
     * What the shipping-class discount does to each shipping source of one
     * corrected document, for an operator's credit: its proportional claim
     * per rule, each parcel's share of a shared
     * discount and what crediting a parcel
     * would give back after its discount.
     * The same allocator sizes creditLines().
     *
     * It checks no availability: its caller asks unrecordedCreditNotes() first.
     *
     * @param int $correctsId The corrected (issued sales) document id.
     * @param array<int, float> $creditedShipping Shipment id => gross this draft credits.
     *
     * @return object{money_left: array<int, float>, shares: array<int, array<int, float>>, claims: array<int, float>}
     *                                                                                                                 Every figure exact at the corrected document's scale:
     *                                                                                                                 money_left per shipping source with something left (after this
     *                                                                                                                 draft for a source it leaves out); shares per
     *                                                                                                                 parcel still holding shipping after this draft, per rule (0
     *                                                                                                                 included); claims per shipping-class rule.
     *
     * @throws RuntimeException When the document does not exist, states more shipping discount than shipping,
     *                          or a credited amount names no parcel of it or exceeds what the parcel has left.
     *
     * @since  1.14.0
     */
    public static function shippingDiscountDetail(int $correctsId, array $creditedShipping): object
    {
        $corrected = DocumentHelper::getById($correctsId)
            ?? throw new RuntimeException("id_corrects #{$correctsId} is not a document.");
        $currency = DocumentMoney::currencyOf($corrected);
        $credited = [];

        foreach ($creditedShipping as $shipmentId => $gross) {
            $credited[$shipmentId] = Money::of((float) $gross, $currency)->getMinorUnits();
        }

        $allocation = self::shippingDiscountAllocation(
            charges:  self::correctedCharges(correctsId: $correctsId),
            credited: $credited,
            currency: $currency,
        );
        $amount = static fn (int $minor): float => Money::ofMinor($minor, $currency)->getAmount();
        $amounts = static fn (array $byRule): array => array_map($amount, $byRule);

        return (object) [
            'money_left' => array_map($amount, $allocation->moneyLeft),
            'shares' => array_map($amounts, $allocation->shares),
            'claims' => array_map($amount, $allocation->claims),
        ];
    }

    /**
     * What crediting every goods unit one corrected document still has would
     * give back, after the merchandise-class discounts that follow the goods.
     *
     * The goods' value not yet credited by an issued credit note, less what
     * each merchandise-class rule has left: the walk of a credit that takes
     * the last goods, with no shipping and no fees. Goods a discount covers in
     * full give back nothing. It checks no availability and writes nothing.
     *
     * @param int $correctsId The corrected (issued sales) document id.
     *
     * @return float At the corrected document's currency and scale; never below 0.
     *
     * @throws RuntimeException When the document does not exist.
     *
     * @since  1.14.0
     */
    public static function goodsMoneyLeft(int $correctsId): float
    {
        $corrected = DocumentHelper::getById($correctsId)
            ?? throw new RuntimeException("id_corrects #{$correctsId} is not a document.");
        $currency = DocumentMoney::currencyOf($corrected);
        $charges = self::correctedCharges(correctsId: $correctsId);
        $merchandise = self::merchandiseDiscounts(
            charges:   $charges,
            valueIncl: $charges->item_basis->remaining_incl,
            credited:  [],
            final:     true,
            currency:  $currency,
        );

        return Money::of($merchandise->left, $currency)->nonNegative()->getAmount();
    }

    /**
     * The identity of one VAT bucket: rate, category and exemption reason.
     *
     * A rate alone is not a VAT identity: the issuer groups the VAT summary on
     * rate and category and carries the exemption code alongside, so two lines
     * at one rate can belong to different summary rows.
     *
     * @param float $rate VAT rate.
     * @param int|string|null $category Tax-authority VAT category.
     * @param string|null $code Exemption reason code.
     *
     *
     * @since  1.10.0
     */
    public static function vatBucketKey(float $rate, int|string|null $category, ?string $code): string
    {
        return number_format($rate, 3, '.', '') . '|' . ($category === null ? '' : (int) $category) . '|' . ($code ?? '');
    }

    /**
     * Build the charge draft lines accompanying a set of picked item lines.
     *
     * @param int $orderId The order id.
     * @param string $docType Target document type; non-fiscal types,
     *                        and types outside the sales and credit
     *                        pools, get no charge lines.
     * @param array $itemLines The draft's item lines (share basis).
     * @param array|null $picks Modal selection: ['shipping' => [id...],
     *                          'fees' => [id...], 'rules' => [id...]].
     *                          Null = everything with a remaining
     *                          balance (the auto-issue behaviour). A
     *                          credit reads only shipping and fees, and
     *                          an optional 'amounts' => ['shipping' =>
     *                          [id => incl], 'fees' => [id => incl],
     *                          'rules' => [id => incl]] that claims less
     *                          than a picked line's whole remaining and
     *                          sizes the shipping discount riding it.
     * @param bool $finalForItems True when, after this draft, no item
     *                            quantity remains in the SALES pool;
     *                            discounts then claim their WHOLE
     *                            remaining balance (remainder rule)
     *                            instead of the value share. A credit
     *                            measures its own finality.
     * @param int $correctsId The corrected document of a credit draft;
     *                        a credit type without one gets no lines.
     *
     * @return array Draft lines ready for DocumentIssuer::issue().
     *
     * @since  1.9.0
     */
    public static function buildLines(
        int $orderId,
        string $docType,
        array $itemLines,
        ?array $picks = null,
        bool $finalForItems = true,
        int $correctsId = 0,
    ): array {
        if (!DocumentHelper::isFiscal($docType)) {
            return [];
        }

        // THE AUTHORITATIVE SCALE, resolved once for every monetary question
        // below. It is the ORDER's frozen scale (the source record's own),
        // never today's currency row, and never a second authority of this
        // file's making.
        $currency = OrderItemLines::orderCurrency(orderId: $orderId);

        if (\in_array($docType, DocumentHelper::creditTypes(), true)) {
            // A credit draws on the document it corrects, and its finality and
            // value share are measured against THAT invoice, not the order.
            return $correctsId > 0
                ? self::creditLines(orderId: $orderId, correctsId: $correctsId, itemLines: $itemLines, picks: $picks)
                : [];
        }

        if (DocumentHelper::poolOf($docType) !== DocumentHelper::POOL_SALES) {
            return [];
        }

        $charges = self::remaining(orderId: $orderId);
        $lines = [];

        $picked = static function (string $kind, int $id) use ($picks): bool {
            if ($picks === null) {
                return true;
            }

            return \in_array($id, array_map('intval', (array) ($picks[$kind] ?? [])), true);
        };

        // Shipping lines (gross)
        $shippingClaimIncl = Money::zero($currency);
        $shippingClaimExcl = Money::zero($currency);

        foreach ($charges->shipping as $parcel) {
            if (!self::isPositive($parcel->remaining_incl, $currency) || !$picked('shipping', $parcel->id)) {
                continue;
            }

            $lines[] = [
                'line_type' => DocumentHelper::LINE_SHIPPING,
                'id_order_item' => null,
                'id_source' => $parcel->id,
                'name' => $parcel->name,
                'quantity' => 1,
                'unit_price_tax_excl' => $parcel->remaining_excl,
                'unit_price_tax_incl' => $parcel->remaining_incl,
                'tax_rate' => $parcel->tax_rate,
                'total_tax_excl' => $parcel->remaining_excl,
                'total_tax_incl' => $parcel->remaining_incl,
                // The parcel's figures are the exact texts of its remainder,
                // and so is their difference.
                'tax_amount' => ExactAmount::ofMoney(Money::of($parcel->remaining_incl, $currency)->subtract(Money::of($parcel->remaining_excl, $currency)))->text(),
            ];

            $shippingClaimIncl = $shippingClaimIncl->add(Money::of($parcel->remaining_incl, $currency));
            $shippingClaimExcl = $shippingClaimExcl->add(Money::of($parcel->remaining_excl, $currency));
        }

        // Fee lines
        foreach ($charges->fees as $fee) {
            if (!self::isPositive($fee->remaining_incl, $currency) || !$picked('fees', $fee->id)) {
                continue;
            }

            $lines[] = [
                'line_type' => DocumentHelper::LINE_FEE,
                'id_order_item' => null,
                'id_source' => $fee->id,
                'name' => $fee->name,
                'quantity' => 1,
                'unit_price_tax_excl' => $fee->remaining_excl,
                'unit_price_tax_incl' => $fee->remaining_incl,
                'tax_rate' => $fee->tax_rate,
                'total_tax_excl' => $fee->remaining_excl,
                'total_tax_incl' => $fee->remaining_incl,
                'tax_amount' => self::difference($fee->remaining_incl, $fee->remaining_excl, $currency),
            ];
        }

        // Discount adjustment lines
        $share = self::itemShare(orderId: $orderId, docType: $docType, itemLines: $itemLines);

        $itemsIncl = self::sumOf(array_map(static fn (array $line): float => (float) ($line['total_tax_incl'] ?? 0), $itemLines), $currency);
        $feesIncl = self::sumOf(array_map(
            static fn (array $line): float => (float) $line['total_tax_incl'],
            array_filter($lines, static fn (array $line): bool => ($line['line_type'] ?? '') === DocumentHelper::LINE_FEE),
        ), $currency);

        // Shipping-class rules FIRST, and never independently pickable: the
        // benefit belongs to the shipping charge it reduces, so it rides
        // whatever shipping this draft claims. Making it uncheckable killed
        // a real failure mode: shipping claimed on one partial with the
        // benefit "saved for later" left the balance forever unclaimable
        // (later drafts have no shipping to clamp against) and the issued
        // documents permanently over-billed the order.
        $shippingAvailIncl = $shippingClaimIncl;
        $shippingAvailExcl = $shippingClaimExcl;

        // Rate carriers for the benefit split: the CLAIMED shipping lines.
        $shippingAsRateLines = array_values(array_filter(
            $lines,
            static fn (array $l): bool => ($l['line_type'] ?? '') === DocumentHelper::LINE_SHIPPING,
        ));
        // What the rules before took at each of those rates, gross and net.
        $shippingTaken = [];

        foreach ($charges->rules as $rule) {
            if ($rule->discount_class !== 'shipping' || !self::isPositive($rule->remaining_incl, $currency)) {
                continue;
            }

            // Running clamp: two stacked shipping rules share the SAME
            // claimed shipping; without decrementing, each would clamp
            // against the full amount and the shipping side would go
            // negative on the document.
            $claimIncl = Money::min(Money::of($rule->remaining_incl, $currency), $shippingAvailIncl);

            if (!$claimIncl->isPositive()) {
                continue;
            }

            // Rate and net come from the shipping being reduced: the gross
            // pro-rata across the claimed parcels' rates, and each part's net
            // by the claimed shipping's own net over its gross at that rate,
            // after what the rules before took (ShippingPool), as the cart
            // took it. A full invoice states the rule rows the order froze.
            foreach (self::shippingDiscountParts(rateLines: $shippingAsRateLines, claimIncl: $claimIncl->getAmount(), rule: $rule, taken: $shippingTaken, currency: $currency) as $bucket) {
                $lines[] = self::adjustmentLine(
                    sourceId: $rule->id,
                    name:     $rule->label,
                    incl:     -$bucket['incl'],
                    excl:     -$bucket['excl'],
                    rate:     $bucket['rate'],
                    currency: $currency,
                );
                $shippingAvailExcl = $shippingAvailExcl->subtract(Money::of($bucket['excl'], $currency));
            }

            $shippingAvailIncl = $shippingAvailIncl->subtract($claimIncl);
        }

        // Order/product rules: value share of THIS draft, whole remainder on
        // the final item draft; ALWAYS clamped to the draft's remaining
        // positive value. Without the clamp a remainder bigger than the
        // draft (discount unchecked earlier, then a small final draft)
        // issued an immutable NEGATIVE sales invoice.
        $draftValueIncl = $itemsIncl->add($shippingAvailIncl)->add($feesIncl);
        // What the rules before took at each of the goods' rates, gross and net.
        $taken = [];

        foreach ($charges->rules as $rule) {
            if ($rule->discount_class === 'shipping'
                || !self::isPositive($rule->remaining_incl, $currency)
                || !$picked('rules', $rule->id)) {
                continue;
            }

            $claimIncl = $finalForItems
                ? Money::of($rule->remaining_incl, $currency)
                : Money::min(Money::of($rule->remaining_incl, $currency), Money::of($rule->remaining_incl * $share, $currency));

            $claimIncl = Money::min($claimIncl, $draftValueIncl->nonNegative());

            if (!$claimIncl->isPositive()) {
                continue;
            }

            foreach (self::discountParts(rateLines: $itemLines, claimIncl: $claimIncl->getAmount(), rule: $rule, taken: $taken, currency: $currency) as $bucket) {
                $lines[] = self::adjustmentLine(
                    sourceId: $rule->id,
                    name:     $rule->label,
                    incl:     -$bucket['incl'],
                    excl:     -$bucket['excl'],
                    rate:     $bucket['rate'],
                    currency: $currency,
                );
            }

            $draftValueIncl = $draftValueIncl->subtract($claimIncl);
        }

        // Discount REVERSALS: discount documents gave that the order no longer does
        // Only a proposal names one (picks['amounts']['reversals']), sized by
        // discountHeadroom(); the issuer caps it again under the lock. It
        // follows the rule's own documented buckets, so the VAT it charges
        // back is the VAT the discount took off.
        $reversals = (array) ($picks['amounts']['reversals'] ?? []);

        if ($reversals !== []) {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $salesDocuments = self::issuedSalesDocuments(db: $db, orderId: $orderId);
            $headroom = self::discountHeadroom(orderId: $orderId);

            foreach ($reversals as $ruleId => $amount) {
                $buckets = self::ruleBuckets(db: $db, documentIds: $salesDocuments, ruleId: (int) $ruleId);

                foreach (self::spread(buckets: $buckets, incl: (float) $amount, ownRatio: true, currency: $currency) as $part) {
                    $lines[] = self::discountLine(ruleId: (int) $ruleId, label: (string) ($headroom[(int) $ruleId]->label ?? ''), part: $part, currency: $currency);
                }
            }
        }

        return $lines;
    }

    /**
     * The order's issued sales documents.
     *
     * @param object $db Database driver.
     * @param int $orderId The order id.
     *
     * @return int[]
     *
     * @since  1.14.0
     */
    private static function issuedSalesDocuments(object $db, int $orderId): array
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__alfa_documents'))
            ->where([
                $db->quoteName('id_order') . ' = :orderId',
                $db->quoteName('status') . ' = ' . DocumentHelper::STATUS_ISSUED,
                $db->quoteName('doc_type') . ' IN (' . implode(',', array_map([$db, 'quote'], DocumentHelper::salesTypes())) . ')',
            ])
            ->bind(':orderId', $orderId);
        $db->setQuery(query: $query);

        return array_map('intval', $db->loadColumn() ?: []);
    }

    /**
     * A credit draft's charge lines, claimed from the corrected invoice.
     *
     * @param int $orderId The order id (a grant's discount headroom).
     * @param int $correctsId The corrected document; its currency sizes every claim.
     * @param array $itemLines The draft's item lines (share and finality basis).
     * @param array|null $picks Shipping and fee picks, with an optional
     *                          'amounts' => [kind => [id => incl]] claim
     *                          per picked source, and per shipping-class
     *                          rule under 'rules' (a proposal only);
     *                          null = all of them whole.
     *
     * @return array Draft lines, signed as the invoice signed each source.
     *
     * @throws RuntimeException When the corrected document does not exist.
     *
     * @since  1.10.0
     */
    private static function creditLines(int $orderId, int $correctsId, array $itemLines, ?array $picks): array
    {
        $charges = self::correctedCharges(correctsId: $correctsId);
        $corrected = DocumentHelper::getById($correctsId)
            ?? throw new RuntimeException("id_corrects #{$correctsId} is not a document.");
        $currency = DocumentMoney::currencyOf($corrected);
        $lines = [];

        // Shipping and fees: the invoice line's remaining, or the amount picked
        // The amount an operator states is checked by manualCreditPicks()
        // before it gets here; a proposal's is the drift it names.
        $claims = self::chargeClaims(charges: $charges, picks: $picks, currency: $currency);

        foreach ([
            [DocumentHelper::LINE_SHIPPING, 'shipping', $charges->shipping],
            [DocumentHelper::LINE_FEE, 'fees', $charges->fees],
        ] as [$type, $kind, $entries]) {
            foreach ($entries as $entry) {
                if (!isset($claims[$kind][$entry->id])) {
                    continue;
                }

                $claim = Money::ofMinor($claims[$kind][$entry->id], $currency);

                foreach (self::allocateClaim(entry: $entry, claimIncl: $claim->getAmount(), currency: $currency, shipping: $type === DocumentHelper::LINE_SHIPPING) as $part) {
                    $lines[] = self::creditLine(type: $type, entry: $entry, part: $part, currency: $currency);
                }
            }
        }

        // What this credit gives back, not what the lines had left: the
        // shipping-class discount follows it and the merchandise discounts
        // below are capped by it.
        $shippingIncl = Money::ofMinor(array_sum($claims['shipping']), $currency);
        $feesIncl = Money::ofMinor(array_sum($claims['fees']), $currency);

        // Shipping-class discounts
        // A pick that names the rules' amounts is a proposed correction.
        $sized = isset($picks['amounts']['rules']);

        if ($sized) {
            // A proposal gives back only what the order no longer applies of
            // the discount (chargeDrift()), because the shipping the order
            // still carries may still be discounted in full. A rule it names no
            // amount for gives back nothing. Clamped to the credited shipping
            // with a running total, so two stacked rules share it.
            // Every figure of this walk is already the corrected document's
            // money (the credited shipping, each rule's remainder, the amount a
            // proposal names), so the running available is kept in its minor
            // units: what one rule takes is exactly what the next one no longer
            // finds, and the notes close on the invoice's frozen figures.
            $shippingLeft = $shippingIncl;

            foreach ($charges->rules as $rule) {
                if ($rule->discount_class !== 'shipping' || !self::hasMagnitude($rule->remaining_incl, $currency)) {
                    continue;
                }

                $claim = Money::min(Money::of(abs($rule->remaining_incl), $currency), $shippingLeft->nonNegative());
                $claim = Money::min($claim, Money::of(abs((float) ($picks['amounts']['rules'][$rule->id] ?? 0.0)), $currency));

                if (!$claim->isPositive()) {
                    continue;
                }

                foreach (self::allocateClaim(entry: $rule, claimIncl: $claim->getAmount(), currency: $currency, shipping: true) as $part) {
                    $lines[] = self::creditLine(type: DocumentHelper::LINE_ADJUSTMENT, entry: $rule, part: $part, currency: $currency);
                }

                $shippingLeft = $shippingLeft->subtract($claim);
            }
        } else {
            // An operator's credit gives back each shipping-class rule in
            // proportion to the shipping it credits (see
            // shippingDiscountAllocation()).
            $allocation = self::shippingDiscountAllocation(charges: $charges, credited: $claims['shipping'], currency: $currency);
            $discountGiven = 0;

            foreach ($charges->rules as $rule) {
                $claim = $rule->discount_class === 'shipping' ? ($allocation->claims[(int) $rule->id] ?? 0) : 0;

                if ($claim === 0) {
                    continue;
                }

                foreach (self::allocateClaim(entry: $rule, claimIncl: Money::ofMinor($claim, $currency)->getAmount(), currency: $currency, shipping: true) as $part) {
                    $lines[] = self::creditLine(type: DocumentHelper::LINE_ADJUSTMENT, entry: $rule, part: $part, currency: $currency);
                }

                $discountGiven += $claim;
            }

            $shippingLeft = Money::ofMinor(array_sum($claims['shipping']) - $discountGiven, $currency);
        }

        // Merchandise discounts: follow the credited goods, rate by rate
        // What the draft credits at each VAT rate: the goods' gross, and their
        // quantity for goods given free.
        $byRate = [];

        foreach ($itemLines as $line) {
            $rate = self::rateKey((float) ($line['tax_rate'] ?? 0));
            $byRate[$rate]['incl'][] = $line['total_tax_incl'] ?? 0;
            $byRate[$rate]['qty'] = ($byRate[$rate]['qty'] ?? 0.0) + (float) ($line['quantity'] ?? 0);
        }

        $credited = [];
        $draftIncl = Money::zero($currency);
        $draftQty = 0.0;

        foreach ($byRate as $rate => $goods) {
            $credited[$rate] = (object) ['incl' => self::sumOf($goods['incl'], $currency), 'qty' => $goods['qty']];
            $draftIncl = $draftIncl->add($credited[$rate]->incl);
            $draftQty += $goods['qty'];
        }

        // FINAL against the corrected invoice: nothing of its goods is left to
        // credit after this draft, so the remainder lands here or nowhere.
        $final = ($charges->item_basis->remaining_qty - $draftQty) <= self::QUANTITY_EPSILON;

        // Never more than this credit gives back: a credit note may not total
        // negative any more than a sales document may.
        $draftValueIncl = $draftIncl->add($shippingLeft)->add($feesIncl);
        $merchandise = self::merchandiseDiscounts(
            charges:   $charges,
            valueIncl: $draftValueIncl->getAmount(),
            credited:  $credited,
            final:     $final,
            currency:  $currency,
        );

        // Each rate's part is claimed from the rule's buckets at that rate only.
        foreach ($merchandise->claims as [$rule, , $parts]) {
            foreach ($parts as $rate => $minor) {
                $atRate = self::atRate(rule: $rule, rate: (string) $rate, currency: $currency);

                foreach (self::allocateClaim(entry: $atRate, claimIncl: Money::ofMinor($minor, $currency)->getAmount(), currency: $currency) as $part) {
                    $lines[] = self::creditLine(type: DocumentHelper::LINE_ADJUSTMENT, entry: $rule, part: $part, currency: $currency);
                }
            }
        }

        // Discount GRANTS: discount the order came to give after this document
        // Only a proposal names one (picks['amounts']['grants']), sized by
        // discountHeadroom(); the issuer caps it again under the lock. It
        // follows this document's own lines for the rule when it carries
        // them, and the document's goods when it never carried the rule,
        // split as the sales builder would have split it.
        $grants = (array) ($picks['amounts']['grants'] ?? []);

        if ($grants !== []) {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $headroom = self::discountHeadroom(orderId: $orderId);

            foreach ($grants as $ruleId => $amount) {
                $own = self::ruleBuckets(db: $db, documentIds: [$correctsId], ruleId: (int) $ruleId);
                $buckets = $own !== [] ? $own : self::goodsBuckets(db: $db, documentId: $correctsId);

                foreach (self::spread(buckets: $buckets, incl: (float) $amount, ownRatio: $own !== [], currency: $currency) as $part) {
                    $lines[] = self::discountLine(ruleId: (int) $ruleId, label: (string) ($headroom[(int) $ruleId]->label ?? ''), part: $part, currency: $currency);
                }
            }
        }

        return $lines;
    }

    /**
     * The merchandise-class discounts a credit gives back with its goods, rule
     * by rule and rate by rate: the one walk both a credit's lines and the
     * goods' money left are measured by.
     *
     * The discounts follow the goods AT THEIR VAT RATE. At each rate a rule's
     * buckets carry, the rule gives back its share of what it has left there
     * in proportion to the goods the credit takes at that rate over the goods
     * the document has left there (by quantity where those goods are free);
     * all of it when the credit takes the last goods at that rate; nothing
     * when the credit takes no goods there. The credit that takes the last
     * goods the document has takes every rule's whole remainder. So a credit
     * of goods at one rate gives back discount at that rate only, and the
     * credits of one invoice close every rate exactly.
     *
     * The rule's give-back is the sum of those exact shares, rounded once, and
     * its rates share it by the one allocation, cumulatively in the order of
     * the rule's buckets: each rate's part is its exact share rounded down or
     * up, never more than the rule has left there. It is never more than the
     * credit still gives back after the rules before it, so a credit note
     * never totals negative; a capped give-back is shared over the rates by
     * the one allocation over their parts. The running figure is the corrected
     * document's money, kept in its minor units.
     *
     * @param object $charges One correctedCharges() entry.
     * @param float $valueIncl What the credit gives back before these discounts, gross.
     * @param array<string, object{incl: Money, qty: float}> $credited The goods the credit takes, keyed by rateKey().
     * @param bool $final Whether the credit takes the last goods the document has left.
     * @param Currency $currency The corrected document's currency.
     *
     * @return object{claims: list<array{0: object, 1: float, 2: array<string, int>}>, left: float} Each rule
     *                                                                                              that gives back money, in rule order: the rule, its gross claim (the sum of its parts, stated for a
     *                                                                                              reader of the walk; the credit's lines are built from the parts) and its parts in minor units by
     *                                                                                              rate; and what the credit gives back after them.
     *
     * @since  1.14.0
     */
    private static function merchandiseDiscounts(object $charges, float $valueIncl, array $credited, bool $final, Currency $currency): object
    {
        $claims = [];
        $value = Money::of($valueIncl, $currency);

        foreach ($charges->rules as $rule) {
            if ($rule->discount_class === 'shipping' || !self::hasMagnitude($rule->remaining_incl, $currency)) {
                continue;
            }

            $terms = [];

            foreach (self::leftByRate(rule: $rule, currency: $currency) as $rate => $left) {
                $terms[$rate] = $final
                    ? [$left, 1, 1]
                    : self::rateTerm(
                        left:     $left,
                        credited: $credited[$rate] ?? null,
                        basis:    $charges->item_basis->rates[$rate] ?? null,
                        currency: $currency,
                    );
            }

            $parts = self::ratesShare(terms: $terms, currency: $currency);
            $claim = Money::ofMinor(array_sum($parts), $currency);
            $cap = $value->nonNegative();

            if ($claim->greaterThan($cap)) {
                $parts = array_combine(array_keys($parts), self::allocateWithin(amount: $cap->getMinorUnits(), caps: $parts, currency: $currency));
                $claim = $cap;
            }

            if (!$claim->isPositive()) {
                continue;
            }

            $claims[] = [$rule, $claim->getAmount(), array_filter($parts, static fn (int $part): bool => $part > 0)];
            $value = $value->subtract($claim);
        }

        return (object) ['claims' => $claims, 'left' => $value->getAmount()];
    }

    /**
     * One VAT rate's key: the rate at the three decimals a document stores, as
     * the sales builder groups a discount's split by rate.
     *
     * @param float $rate VAT rate.
     *
     *
     * @since  1.14.0
     */
    private static function rateKey(float $rate): string
    {
        return number_format($rate, 3, '.', '');
    }

    /**
     * What a rule has left at each VAT rate its buckets carry, in minor units,
     * in the order of its buckets.
     *
     * @param object $rule One correctedCharges() candidate.
     * @param Currency $currency The corrected document's currency.
     *
     * @return array<string, int> Magnitudes keyed by rateKey(); rates with nothing left are left out.
     *
     * @since  1.14.0
     */
    private static function leftByRate(object $rule, Currency $currency): array
    {
        $left = [];

        foreach ($rule->buckets as $bucket) {
            $minor = Money::of(abs((float) $bucket['remaining_incl']), $currency)->getMinorUnits();

            if ($minor > 0) {
                $rate = self::rateKey((float) $bucket['rate']);
                $left[$rate] = ($left[$rate] ?? 0) + $minor;
            }
        }

        return $left;
    }

    /**
     * One rate's exact share of what a rule has left there, as the term
     * [left, part, whole] read left x part / whole.
     *
     * Nothing when the credit takes no goods at the rate; all of it when it
     * takes the last goods there; else the goods' gross credited over the
     * gross the document has left at the rate, or their quantities where
     * those goods are free (to the six decimals a quantity is kept at).
     *
     * @param int $left What the rule has left at the rate, minor units.
     * @param object|null $credited The goods the credit takes at the rate ({incl: Money, qty: float}), or null.
     * @param object|null $basis The goods the document has left at the rate ({remaining_incl, remaining_qty}), or null.
     * @param Currency $currency The corrected document's currency.
     *
     * @return array{0: int, 1: int, 2: int}
     *
     * @since  1.14.0
     */
    private static function rateTerm(int $left, ?object $credited, ?object $basis, Currency $currency): array
    {
        // Goods the document does not carry at this rate are none of its goods.
        if ($credited === null || $basis === null) {
            return [0, 0, 1];
        }

        if ($basis->remaining_qty - $credited->qty <= self::QUANTITY_EPSILON) {
            return [$left, 1, 1];
        }

        if (self::isPositive($basis->remaining_incl, $currency)) {
            $whole = Money::of($basis->remaining_incl, $currency)->getMinorUnits();

            return [$left, min($credited->incl->getMinorUnits(), $whole), $whole];
        }

        return [$left, (int) round($credited->qty * 1000000), (int) round($basis->remaining_qty * 1000000)];
    }

    /**
     * Share a rule's give-back over its rates: the sum of the rates' exact
     * shares rounded once, each rate's part the cumulative rounding of the
     * shares so far less the part before it.
     *
     * @param array<string, array{0: int, 1: int, 2: int}> $terms Each rate's term from rateTerm(), in bucket order.
     * @param Currency $currency The corrected document's currency.
     *
     * @return array<string, int> Each rate's part, minor units, summing to the give-back.
     *
     * @since  1.14.0
     */
    private static function ratesShare(array $terms, Currency $currency): array
    {
        $unit = Money::ofMinor(1, $currency);
        $prefix = [];
        $before = 0;
        $parts = [];

        foreach ($terms as $rate => $term) {
            $prefix[] = $term;
            $through = $unit->timesRatioSum(terms: $prefix, divisor: 1)->getMinorUnits();
            $parts[$rate] = $through - $before;
            $before = $through;
        }

        return $parts;
    }

    /**
     * A rule seen at one VAT rate only: its buckets at that rate and what they
     * have left, signed as the invoice signed the rule, so a part claimed at
     * that rate is allocated over them alone and the whole of what they have
     * left takes them verbatim.
     *
     * @param object $rule One correctedCharges() candidate.
     * @param string $rate A rateKey().
     * @param Currency $currency The corrected document's currency.
     *
     * @return object The candidate with those buckets and that remaining.
     *
     * @since  1.14.0
     */
    private static function atRate(object $rule, string $rate, Currency $currency): object
    {
        $view = clone $rule;
        $view->buckets = array_filter(
            $rule->buckets,
            static fn (array $bucket): bool => self::rateKey((float) $bucket['rate']) === $rate,
        );
        $view->remaining_incl = self::sumOf(array_map(static fn (array $bucket): float => (float) $bucket['remaining_incl'], $view->buckets), $currency)->getAmount();
        $view->remaining_excl = self::sumOf(array_map(static fn (array $bucket): float => (float) $bucket['remaining_excl'], $view->buckets), $currency)->getAmount();

        return $view;
    }

    /**
     * What a credit claims of each shipping and fee source of the corrected
     * document, in minor units: the one place a charge is sized.
     *
     * A source is claimed when it has something left and, when picks are
     * given, it is picked. The claim is its whole remaining, or the amount the
     * pick names capped at that remaining: the proposal's single cap. An
     * operator's amount never reaches the cap, because manualCreditPicks()
     * refuses one above what is left.
     *
     * @param object $charges One correctedCharges() entry.
     * @param array|null $picks As creditLines() takes them; null = all of them whole.
     * @param Currency $currency The corrected document's currency.
     *
     * @return array{shipping: array<int, int>, fees: array<int, int>} Source id => minor units, in candidate order.
     *
     * @since  1.14.0
     */
    private static function chargeClaims(object $charges, ?array $picks, Currency $currency): array
    {
        $claims = ['shipping' => [], 'fees' => []];

        foreach (['shipping' => $charges->shipping, 'fees' => $charges->fees] as $kind => $entries) {
            // Only shipping and fees are the operator's to exclude. A rules pick
            // is never read: a discount follows what is credited, it is not chosen.
            $pickedIds = $picks === null ? null : array_map('intval', (array) ($picks[$kind] ?? []));

            foreach ($entries as $entry) {
                if (!self::hasMagnitude($entry->remaining_incl, $currency)
                    || ($pickedIds !== null && !\in_array((int) $entry->id, $pickedIds, true))) {
                    continue;
                }

                $claim = Money::of(abs($entry->remaining_incl), $currency);
                $asked = $picks['amounts'][$kind][$entry->id] ?? null;

                if ($asked !== null) {
                    $claim = Money::min($claim, Money::of(abs((float) $asked), $currency));
                }

                $claims[$kind][(int) $entry->id] = $claim->getMinorUnits();
            }
        }

        return $claims;
    }

    /**
     * round-half-up(pool x part / whole), exactly, on minor units.
     *
     * @param int $pool The pool shared out (0 <= pool <= whole).
     * @param int $part The part of the whole this share follows (0 <= part <= whole).
     * @param int $whole The whole (0 < whole < 2^62).
     *
     *
     * @throws RuntimeException When an argument is outside those bounds.
     *
     * @since  1.14.0
     */
    private static function proportionalMinor(int $pool, int $part, int $whole): int
    {
        [$quotient, $remainder] = self::longDivision(pool: $pool, part: $part, whole: $whole);

        return $remainder >= $whole - $remainder ? $quotient + 1 : $quotient;
    }

    /**
     * floor(pool x part / whole) and what it leaves, exactly, on minor units.
     *
     * @param int $pool The pool shared out (0 <= pool <= whole).
     * @param int $part The part of the whole this share follows (0 <= part <= whole).
     * @param int $whole The whole (0 < whole < 2^62).
     *
     * @return array{0: int, 1: int} The quotient, and the remainder (0 <= remainder < whole).
     *
     * @throws RuntimeException When an argument is outside those bounds.
     *
     * @since  1.14.0
     */
    private static function longDivision(int $pool, int $part, int $whole): array
    {
        if ($whole <= 0 || $whole >= (1 << 62) || $pool < 0 || $pool > $whole || $part < 0 || $part > $whole) {
            throw new RuntimeException("a proportional share needs 0 <= {$pool}, {$part} <= {$whole} < 2^62 minor units.");
        }

        // Long division one bit of $part at a time: no intermediate exceeds
        // twice $whole, so pool x part never overflows at scale 8 (exact
        // arithmetic, scales 0-8).
        $quotient = 0;
        $remainder = 0;

        for ($bit = 61; $bit >= 0; $bit--) {
            $quotient <<= 1;
            $remainder <<= 1;

            if ($remainder >= $whole) {
                $remainder -= $whole;
                $quotient++;
            }

            if ((($part >> $bit) & 1) === 1) {
                $remainder += $pool;

                if ($remainder >= $whole) {
                    $remainder -= $whole;
                    $quotient++;
                }
            }
        }

        return [$quotient, $remainder];
    }

    /**
     * THE SHIPPING-CLASS DISCOUNT OF AN OPERATOR'S CREDIT, in minor units, on
     * one corrected document.
     *
     * Each rule gives back by the one allocation rule, cumulatively over the
     * shipping credited so far: its target is its invoiced amount times the
     * shipping credited once this credit is issued over the invoice's whole
     * original shipping, rounded once, half away from zero, and the credit
     * gives back that target less what the rule has already given back, never
     * below nothing. The target never passes the rule's amount, so a credit
     * never takes more than the rule has left, and the credit that empties
     * the invoice's shipping takes each rule's exact remainder. A 1.00 rule
     * over three 1.00 parcels credited one by one gives back 0.33, 0.34 and
     * 0.33, as its split over the parcels does; rounding each credit on its
     * own gave 0.33, 0.33 and 0.34. The divisor is the invoice's whole
     * shipping, because no column ties a discount to a parcel.
     *
     * Beside the claims it states, as calculated detail, by the same rule:
     * each rule's share on every parcel still holding shipping after this
     * credit, as the credits of those parcels, whole and one after the other
     * in id order, would give it back; and what crediting all a parcel has
     * left, alone, would give back after its discount. A parcel this credit
     * leaves out is measured after this credit, as a later credit of it finds
     * it.
     *
     * @param object $charges One correctedCharges() entry.
     * @param array<int, int> $credited Shipment id => minor units this credit gives back.
     * @param Currency $currency The corrected document's currency.
     *
     * @return object{claims: array<int, int>, shares: array<int, array<int, int>>, moneyLeft: array<int, int>}
     *                                                                                                          Rules keyed by id_order_cart_rule, parcels by shipment id.
     *
     * @throws RuntimeException When the document states more shipping discount than shipping, or a credited
     *                          amount names no parcel of it, is negative, or exceeds what the parcel has left.
     *
     * @since  1.14.0
     */
    private static function shippingDiscountAllocation(object $charges, array $credited, Currency $currency): object
    {
        $minor = static fn (float $amount): int => Money::of(abs($amount), $currency)->getMinorUnits();

        // The parcels in id order: "the last parcel" is the last by id.
        $parcels = $charges->shipping;
        usort($parcels, static fn (object $a, object $b): int => (int) $a->id <=> (int) $b->id);

        // The pools: the invoice's whole shipping, and each shipping-class
        // rule's invoiced amount and what it has left.
        $whole = 0;
        $pools = [];
        $rest = [];

        foreach ($parcels as $parcel) {
            $whole += $minor($parcel->amount_incl);
        }

        foreach ($charges->rules as $rule) {
            if ($rule->discount_class === 'shipping') {
                $pools[(int) $rule->id] = $minor($rule->amount_incl);
                $rest[(int) $rule->id] = $minor($rule->remaining_incl);
            }
        }

        // The sales builder never writes this; it keeps every share inside
        // proportionalMinor()'s bounds.
        if (array_sum($pools) > $whole) {
            throw new RuntimeException('the corrected document states more shipping discount than shipping; a credit cannot give back more discount than shipping.');
        }

        // Per parcel: what it has left, and what this credit gives back of it.
        $before = [];
        $credit = [];

        foreach ($parcels as $parcel) {
            $before[(int) $parcel->id] = $minor($parcel->remaining_incl);
            $credit[(int) $parcel->id] = 0;
        }

        foreach ($credited as $id => $amount) {
            if (!isset($before[(int) $id])) {
                throw new RuntimeException("shipping #{$id} is not on the corrected document; a credit gives back what that document charged.");
            }

            if ($amount < 0) {
                throw new RuntimeException("shipping #{$id}: a credit cannot give back a negative amount");
            }

            if ($amount > $before[(int) $id]) {
                throw new RuntimeException("shipping #{$id}: this credit gives back more than the invoice has left");
            }

            $credit[(int) $id] = (int) $amount;
        }

        $left = array_sum($before);
        $given = array_sum($credit);

        // What a credit gives back of one rule: the rule's target on the
        // shipping credited once the credit is issued, less what the rule has
        // given back already. No shipping credited gives back no discount; the
        // credit that empties the shipping takes the rule's rest.
        $claimOf = static function (int $pool, int $ruleLeft, int $shippingLeft, int $creditNow) use ($whole): int {
            if ($creditNow === 0) {
                return 0;
            }

            if ($creditNow === $shippingLeft) {
                return $ruleLeft;
            }

            $target = self::proportionalMinor(pool: $pool, part: $whole - $shippingLeft + $creditNow, whole: $whole);

            return max(0, $target - ($pool - $ruleLeft));
        };

        // Each rule on its own pool, so stacking order does not matter.
        $claims = [];

        foreach (array_keys($pools) as $id) {
            $claims[$id] = $claimOf($pools[$id], $rest[$id], $left, $given);
        }

        // Each parcel still holding shipping after this credit states its
        // share of every rule: what its credit would give back, the holders
        // credited whole one after the other in id order.
        $restAfter = [];
        $holders = [];

        foreach ($claims as $id => $claim) {
            $restAfter[$id] = $rest[$id] - $claim;
        }

        foreach ($before as $id => $amount) {
            if ($amount - $credit[$id] > 0) {
                $holders[$id] = $amount - $credit[$id];
            }
        }

        $shares = array_fill_keys(array_keys($holders), []);

        foreach (array_keys($pools) as $id) {
            $ruleLeft = $restAfter[$id];
            $shippingLeft = $left - $given;

            foreach ($holders as $parcelId => $after) {
                $share = $claimOf($pools[$id], $ruleLeft, $shippingLeft, $after);
                $shares[$parcelId][$id] = $share;
                $ruleLeft -= $share;
                $shippingLeft -= $after;
            }
        }

        // Money left: what crediting all a parcel has left, alone, gives back
        // after its discount; 0 is a fully netted parcel. A parcel this credit
        // gives back is measured from the state before it. A parcel it leaves
        // out is measured from the state after it, since that is what a later
        // credit of it finds: this credit may already have taken the rule.
        $moneyLeft = [];

        foreach (array_keys($before) as $id) {
            if ($before[$id] <= 0) {
                continue;
            }

            $leftOut = $credit[$id] === 0;
            $discount = 0;

            foreach (array_keys($pools) as $ruleId) {
                $discount += $leftOut
                    ? $claimOf($pools[$ruleId], $restAfter[$ruleId], $left - $given, $before[$id])
                    : $claimOf($pools[$ruleId], $rest[$ruleId], $left, $before[$id]);
            }

            $moneyLeft[$id] = $before[$id] - $discount;
        }

        return (object) [
            'claims' => $claims,
            'shares' => $shares,
            'moneyLeft' => $moneyLeft,
        ];
    }

    /**
     * Split one source's claim across its VAT buckets.
     *
     * The whole remaining balance takes every bucket's remaining figures
     * verbatim, so a full credit reproduces the invoice's own. A partial claim
     * allocates its GROSS in minor units over what each bucket has left, by
     * the one allocation (allocateWithin()): no bucket is given more than it
     * has left or less than nothing, and the parts sum to the claim. Each
     * part's net follows the frozen bucket CUMULATIVELY, as every part
     * document of frozen money does: the net up to and including this part
     * is the split of the gross credited from it so far, never above the
     * bucket's own net nor below what its remaining gross still needs; the
     * part takes that less the net earlier credits took. A shipping bucket
     * (a shipping line, or a shipping-class discount) splits by its own net
     * over its gross (ShippingPool), as the order and the invoice split it;
     * any other bucket at its stored rate. So the credits of one bucket close on its net exactly,
     * none states a VAT of the other sign or a net above its gross, and a
     * bucket given nothing prints no line. Where the credits already issued
     * leave no such part (a voided note between them, or notes stated under
     * another rule), the part is refused by name, as an item line's is: the
     * rest is credited in one document, or those notes are corrected first.
     * The claim's net and VAT are the sums of its parts.
     *
     * @param object $entry One correctedCharges() candidate.
     * @param float $claimIncl The gross amount claimed, a magnitude on the currency.
     * @param Currency $currency The corrected document's currency.
     * @param bool $shipping True for a shipping line or a shipping-class discount.
     *
     * @return array<int, array{bucket: array, incl: float, excl: float}> Signed as the invoice signed the source.
     *
     * @throws RuntimeException When a part cannot be stated at its bucket's tax rate.
     *
     * @since  1.10.0
     */
    private static function allocateClaim(object $entry, float $claimIncl, Currency $currency, bool $shipping = false): array
    {
        $live = array_values(array_filter(
            $entry->buckets,
            static fn (array $bucket): bool => self::hasMagnitude($bucket['remaining_incl'], $currency),
        ));

        if ($live === []) {
            return [];
        }

        $parts = [];

        // THE FINAL-ALLOCATION TEST, AND IT IS NOW EXACT. It used to add a
        // millionth before comparing, so a claim a whole minor unit SHORT of
        // the pool could still be treated as taking all of it, and the
        // remainder it did not cover was absorbed anyway. Compared in minor
        // units there is no gap to hide in: >= means >=.
        if (Money::of($claimIncl, $currency)->getMinorUnits()
            >= Money::of(abs($entry->remaining_incl), $currency)->getMinorUnits()) {
            foreach ($live as $bucket) {
                $parts[] = ['bucket' => $bucket, 'incl' => $bucket['remaining_incl'], 'excl' => $bucket['remaining_excl']];
            }

            return $parts;
        }

        $sign = $entry->amount_incl < 0 ? -1.0 : 1.0;
        $minor = static fn (float $amount): int => Money::of(abs($amount), $currency)->getMinorUnits();
        $grossCaps = array_map(static fn (array $bucket): int => $minor($bucket['remaining_incl']), $live);
        $gross = self::allocateWithin(amount: $minor($claimIncl), caps: $grossCaps, currency: $currency);

        foreach ($live as $i => $bucket) {
            // A bucket the allocation left nothing to prints no 0.00 line.
            if ($gross[$i] === 0) {
                continue;
            }

            // The bucket as frozen (P gross, N net), and what earlier credits
            // took of it: T is the gross credited from it once this part is.
            $pool = Money::of(abs((float) $bucket['incl']), $currency);
            $poolNet = Money::of(abs((float) $bucket['excl']), $currency);
            $takenNet = $poolNet->subtract(Money::of(abs((float) $bucket['remaining_excl']), $currency));
            $target = $pool->subtract(Money::of(abs((float) $bucket['remaining_incl']), $currency))
                ->add(Money::ofMinor($gross[$i], $currency));
            $net = ($shipping
                ? (new ShippingPool(gross: $pool, net: $poolNet))->netTaken($target)
                : Money::max(
                    Money::min(OrderLineMoney::netAtStoredRate(gross: $target, taxRate: (float) $bucket['rate']), $poolNet),
                    $poolNet->subtract($pool->subtract($target)),
                ))->subtract($takenNet);

            if ($net->isNegative() || $net->greaterThan(Money::ofMinor($gross[$i], $currency))) {
                self::refuseImpossiblePart(entry: $entry, bucket: $bucket, currency: $currency);
            }

            $parts[] = [
                'bucket' => $bucket,
                'incl' => $sign * Money::ofMinor($gross[$i], $currency)->getAmount(),
                'excl' => $sign * $net->getAmount(),
            ];
        }

        return $parts;
    }

    /**
     * Refuse a part of a charge that cannot be stated at its bucket's tax rate:
     * a net below nothing or above its gross.
     *
     * The rest of the bucket in one credit states its remainders, which can be
     * issued while their net is not above their gross.
     *
     * @param object $entry The correctedCharges() candidate.
     * @param array $bucket The bucket the part falls on.
     * @param Currency $currency The corrected document's currency.
     *
     *
     * @throws RuntimeException Always.
     *
     * @since  1.14.0
     */
    private static function refuseImpossiblePart(object $entry, array $bucket, Currency $currency): never
    {
        $restGross = Money::of(abs((float) $bucket['remaining_incl']), $currency);
        $restNet = Money::of(abs((float) $bucket['remaining_excl']), $currency);

        if (!$restNet->greaterThan($restGross)) {
            throw new RuntimeException(sprintf(
                'charge "%s": this part cannot be stated after the credit notes already issued for it, which are correct as they are; credit the rest of it (%s) in one document.',
                $entry->label,
                Money::of(abs((float) $entry->remaining_incl), $currency)->format(),
            ));
        }

        throw new RuntimeException(sprintf(
            'charge "%s": the credit notes already issued for it leave no part of it that can be stated at its tax rate; void or correct them first.',
            $entry->label,
        ));
    }

    /**
     * Split an amount in minor units across buckets by what each has left,
     * never giving a bucket more than its cap or less than nothing.
     *
     * The amount is bounded by the caps' sum, and Money's one allocation
     * ({@see Money::allocateByRatios()}) shares it cumulatively over the caps:
     * no part is then below zero or above its cap (each cumulative target is
     * within half a minor unit of its exact value), and the parts sum to the
     * amount. 38.99 over 13.00, 13.00, 13.00 and 0.01 is 12.99, 13.00, 12.99
     * and 0.01.
     *
     * @param int $amount What is claimed, in minor units (clamped to the caps' sum).
     * @param int[] $caps Each bucket's remaining, in minor units, in bucket order.
     * @param Currency $currency The currency the units are minor units of.
     *
     * @return int[] One part per cap, in the same order, summing to the amount.
     *
     * @since  1.10.0
     */
    private static function allocateWithin(int $amount, array $caps, Currency $currency): array
    {
        $caps = array_values($caps);
        $amount = max(0, min($amount, array_sum($caps)));

        if ($amount === 0) {
            return array_fill(0, \count($caps), 0);
        }

        return array_map(
            static fn (Money $part): int => $part->getMinorUnits(),
            Money::ofMinor($amount, $currency)->allocateByRatios($caps),
        );
    }

    /**
     * One credit charge draft line, carrying the invoice source and all three
     * of its bucket's VAT columns.
     *
     * @param string $type LINE_SHIPPING, LINE_FEE or LINE_ADJUSTMENT.
     * @param object $entry The correctedCharges() candidate.
     * @param array $part One allocateClaim() part.
     *
     *
     * @since  1.10.0
     */
    private static function creditLine(string $type, object $entry, array $part, Currency $currency): array
    {
        return [
            'line_type' => $type,
            'id_order_item' => null,
            'id_source' => $entry->id,
            'name' => $entry->label,
            'quantity' => 1,
            'unit_price_tax_excl' => $part['excl'],
            'unit_price_tax_incl' => $part['incl'],
            'tax_rate' => $part['bucket']['rate'],
            'vat_category' => $part['bucket']['vat_category'],
            'vat_exemption_code' => $part['bucket']['vat_exemption_code'],
            'total_tax_excl' => $part['excl'],
            'total_tax_incl' => $part['incl'],
            'tax_amount' => self::difference($part['incl'], $part['excl'], $currency),
        ];
    }

    /**
     * Take an already credited amount off one bucket, toward zero and never
     * past it.
     *
     * In the corrected document's minor units, so a remainder is exact at
     * every currency scale.
     *
     * @param object $entry The candidate.
     * @param string $key The bucket key.
     * @param float $incl Gross magnitude credited.
     * @param float $excl Net magnitude credited.
     * @param Currency $currency The corrected document's currency.
     *
     * @return array{0: float, 1: float} What could not be taken (gross, net).
     *
     * @since  1.10.0
     */
    private static function take(object $entry, string $key, float $incl, float $excl, Currency $currency): array
    {
        $left = [];

        foreach (['incl' => $incl, 'excl' => $excl] as $axis => $amount) {
            $credited = Money::of($amount, $currency);
            $remaining = Money::of($entry->buckets[$key]['remaining_' . $axis], $currency);
            $taken = Money::min($credited, $remaining->abs());
            $rest = $remaining->abs()->subtract($taken);
            $entry->buckets[$key]['remaining_' . $axis] = ($remaining->isNegative() ? $rest->negate() : $rest)->getAmount();
            $left[] = $credited->subtract($taken)->getAmount();
        }

        return $left;
    }

    /**
     * Take an already credited amount off a list of buckets, in the order
     * given, until it is gone.
     *
     * @param array<int, array{0: object, 1: string}> $targets [candidate, bucket key] pairs.
     * @param float $incl Gross magnitude to take.
     * @param float $excl Net magnitude to take.
     * @param Currency $currency The corrected document's currency.
     *
     * @return array{0: float, 1: float} What no bucket could absorb (gross, net).
     *
     * @since  1.10.0
     */
    private static function drain(array $targets, float $incl, float $excl, Currency $currency): array
    {
        foreach ($targets as [$entry, $key]) {
            // Exact: nothing left to drain means nothing left AT THE
            // RECORD'S SCALE, not nothing left to within a millionth.
            if (!self::isPositive($incl, $currency) && !self::isPositive($excl, $currency)) {
                break;
            }

            [$incl, $excl] = self::take(entry: $entry, key: $key, incl: $incl, excl: $excl, currency: $currency);
        }

        return [$incl, $excl];
    }

    /**
     * Every bucket of the given candidates, candidate by candidate in the
     * order given, each candidate's largest remaining bucket first.
     *
     * @param object[] $entries Candidates.
     *
     * @return array<int, array{0: object, 1: string}> [candidate, bucket key] pairs.
     *
     * @since  1.10.0
     */
    private static function bucketsLargestFirst(array $entries): array
    {
        $targets = [];

        foreach ($entries as $entry) {
            $keys = array_keys($entry->buckets);
            usort($keys, static fn (string $a, string $b): int => abs($entry->buckets[$b]['remaining_incl']) <=> abs($entry->buckets[$a]['remaining_incl']));

            foreach ($keys as $key) {
                $targets[] = [$entry, $key];
            }
        }

        return $targets;
    }

    /**
     * Per corrected document: its item value and quantity not yet credited by
     * an ISSUED credit note (a voided note releases its basis too), in all and
     * at each VAT rate its goods carry: the merchandise discounts of a credit
     * follow the goods rate by rate.
     *
     * @param object $db Database driver.
     * @param int[] $correctsIds Corrected document ids (positive).
     * @param array<int, Currency> $currencies Each document's own currency, by id.
     *
     * @return array<int, object{remaining_incl: float, remaining_qty: float,
     *                           rates: array<string, object{remaining_incl: float, remaining_qty: float}>}>
     *         One per id; `rates` keyed by rateKey(), one per rate the document's goods carry.
     *
     * @since  1.10.0
     */
    private static function creditItemBases(object $db, array $correctsIds, array $currencies): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $correctsIds), static fn (int $id): bool => $id > 0)));
        $invoiced = [];
        $credited = [];

        if ($ids !== []) {
            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('id_document'),
                    $db->quoteName('tax_rate'),
                    'SUM(' . $db->quoteName('total_tax_incl') . ') AS ' . $db->quoteName('incl'),
                    'SUM(' . $db->quoteName('quantity') . ') AS ' . $db->quoteName('qty'),
                ])
                ->from($db->quoteName('#__alfa_document_items'))
                ->whereIn($db->quoteName('id_document'), $ids)
                ->where($db->quoteName('line_type') . ' = ' . $db->quote(DocumentHelper::LINE_ITEM))
                ->where($db->quoteName('id_order_item') . ' IS NOT NULL')
                ->group($db->quoteName(['id_document', 'tax_rate']));
            $db->setQuery(query: $query);

            foreach ($db->loadObjectList() ?: [] as $row) {
                $invoiced[(int) $row->id_document][self::rateKey((float) $row->tax_rate)][] = $row;
            }

            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('d.id_corrects'),
                    $db->quoteName('i.tax_rate'),
                    'SUM(' . $db->quoteName('i.total_tax_incl') . ') AS ' . $db->quoteName('incl'),
                    'SUM(' . $db->quoteName('i.quantity') . ') AS ' . $db->quoteName('qty'),
                ])
                ->from($db->quoteName('#__alfa_document_items', 'i'))
                ->join('INNER', $db->quoteName('#__alfa_documents', 'd') . ' ON d.id = i.id_document')
                ->whereIn($db->quoteName('d.id_corrects'), $ids)
                ->where($db->quoteName('d.status') . ' = ' . DocumentHelper::STATUS_ISSUED)
                ->where($db->quoteName('i.line_type') . ' = ' . $db->quote(DocumentHelper::LINE_ITEM))
                ->where($db->quoteName('i.id_order_item') . ' IS NOT NULL')
                ->group($db->quoteName(['d.id_corrects', 'i.tax_rate']));
            $db->setQuery(query: $query);

            foreach ($db->loadObjectList() ?: [] as $row) {
                $credited[(int) $row->id_corrects][self::rateKey((float) $row->tax_rate)][] = $row;
            }
        }

        // Money exactly, at the corrected document's frozen scale; QUANTITY
        // stays one: a fractional quantity is a real thing (weight, length,
        // hours) and has no currency scale to be exact at. Six decimals is
        // this pool's declared quantity precision, not a monetary tolerance.
        $sum = static fn (array $rows, Currency $currency): array => [
            self::sumOf(array_map(static fn (object $row): string => (string) $row->incl, $rows), $currency),
            array_sum(array_map(static fn (object $row): float => (float) $row->qty, $rows)),
        ];
        $bases = [];

        foreach ($ids as $id) {
            $currency = $currencies[$id];
            $all = [[], []];
            $rates = [];

            foreach ($invoiced[$id] ?? [] as $rate => $rows) {
                [$invoicedIncl, $invoicedQty] = $sum($rows, $currency);
                [$creditedIncl, $creditedQty] = $sum($credited[$id][$rate] ?? [], $currency);
                $rates[$rate] = (object) [
                    'remaining_incl' => Money::max($invoicedIncl->subtract($creditedIncl), Money::zero($currency))->getAmount(),
                    'remaining_qty' => round(max(0.0, $invoicedQty - $creditedQty), 6),
                ];
            }

            foreach ([$invoiced[$id] ?? [], $credited[$id] ?? []] as $side => $byRate) {
                foreach ($byRate as $rows) {
                    array_push($all[$side], ...$rows);
                }
            }

            [$invoicedIncl, $invoicedQty] = $sum($all[0], $currency);
            [$creditedIncl, $creditedQty] = $sum($all[1], $currency);
            $bases[$id] = (object) [
                'remaining_incl' => Money::max($invoicedIncl->subtract($creditedIncl), Money::zero($currency))->getAmount(),
                'remaining_qty' => round(max(0.0, $invoicedQty - $creditedQty), 6),
                'rates' => $rates,
            ];
        }

        return $bases;
    }

    /**
     * This draft's share of the order's REMAINING item value (0..1).
     *
     * @param int $orderId The order id.
     * @param string $docType The target document type (pool semantics).
     * @param array $itemLines The draft's item lines.
     *
     *
     * @since  1.9.0
     */
    private static function itemShare(int $orderId, string $docType, array $itemLines): float
    {
        $currency = OrderItemLines::orderCurrency(orderId: $orderId);
        $draftIncl = self::sumOf(array_map(static fn (array $line): float => (float) ($line['total_tax_incl'] ?? 0), $itemLines), $currency);

        if (!$draftIncl->isPositive()) {
            return 0.0;
        }

        // The remaining item value on the base the draft's own lines were built
        // on: each line's frozen balance, never unit price x quantity, so both
        // sides of the ratio agree. A line with no quantity left is never
        // drafted again, so what it still holds is not value to share against.
        $remainingIncl = Money::zero($currency);

        foreach (OrderItemLines::remaining(orderId: $orderId, docType: $docType) as $balance) {
            if ($balance->remaining_qty > self::QUANTITY_EPSILON) {
                $remainingIncl = $remainingIncl->add(Money::of($balance->remaining_incl->getAmount(), $currency));
            }
        }

        if (!$remainingIncl->isPositive()) {
            return 1.0;
        }

        return min(1.0, $draftIncl->getAmount() / $remainingIncl->getAmount());
    }

    /**
     * One order or product rule's discount on a sales draft, over the rates of
     * the goods it reduces, after the rules before it.
     *
     * The split is splitAcrossRates()', told what the rules before took at
     * each rate. Each part that fits within what its rate's lines still carry
     * keeps its net within their net too: never above it, and never below what
     * leaves their rest a net within its gross. So the part that takes the rest
     * of a rate takes the rest of its net, and rules that together take all of
     * a rate's lines leave its row at nothing, never a minor unit below it. A
     * part beyond what its rate carries (a discount reaching the shipping or
     * the fees) keeps the net of its own split.
     *
     * @param array $rateLines The draft lines the discount reduces.
     * @param float $claimIncl The gross claimed.
     * @param object $rule The charge entry.
     * @param array<string, array{0: int, 1: int}> $taken What the rules before took at each rate, gross and
     *                                                    net in minor units, keyed by rateKey(); this rule's parts
     *                                                    are added to it.
     * @param Currency $currency The order's currency.
     *
     * @return array<int, array{rate: float, incl: float, excl: float}>
     *
     * @since  1.14.0
     */
    private static function discountParts(array $rateLines, float $claimIncl, object $rule, array &$taken, Currency $currency): array
    {
        $carried = [];

        foreach ($rateLines as $line) {
            $rate = self::rateKey((float) ($line['tax_rate'] ?? 0));
            $carried[$rate] = [
                ($carried[$rate][0] ?? 0) + Money::of(\is_string($line['total_tax_incl'] ?? null) ? $line['total_tax_incl'] : (float) ($line['total_tax_incl'] ?? 0), $currency)->getMinorUnits(),
                ($carried[$rate][1] ?? 0) + Money::of(\is_string($line['total_tax_excl'] ?? null) ? $line['total_tax_excl'] : (float) ($line['total_tax_excl'] ?? 0), $currency)->getMinorUnits(),
            ];
        }

        $parts = self::splitAcrossRates(
            itemLines: $rateLines,
            claimIncl: $claimIncl,
            rule:      $rule,
            currency:  $currency,
            taken:     array_map(static fn (array $took): int => $took[0], $taken),
        );

        foreach ($parts as $i => $part) {
            $rate = self::rateKey((float) $part['rate']);
            $gross = Money::of($part['incl'], $currency)->getMinorUnits();
            $net = Money::of($part['excl'], $currency)->getMinorUnits();

            if (isset($carried[$rate]) && $gross <= $carried[$rate][0] - ($taken[$rate][0] ?? 0)) {
                $grossLeft = $carried[$rate][0] - ($taken[$rate][0] ?? 0);
                $netLeft = $carried[$rate][1] - ($taken[$rate][1] ?? 0);
                $net = max(min($net, $netLeft), $netLeft - ($grossLeft - $gross));
                $parts[$i]['excl'] = Money::ofMinor($net, $currency)->getAmount();
            }

            $taken[$rate] = [($taken[$rate][0] ?? 0) + $gross, ($taken[$rate][1] ?? 0) + $net];
        }

        return $parts;
    }

    /**
     * One shipping-class rule's discount on a sales draft, over the claimed
     * shipping's rates, after the shipping rules before it.
     *
     * The gross is split as splitAcrossRates() splits it, told what the rules
     * before took at each rate. Each part's net is the claimed shipping's own
     * net over its gross at that rate, cumulatively (ShippingPool): the rule
     * the cart took its net by, so a full invoice states the nets the order
     * froze, and rules that together take a rate's shipping take exactly its
     * net.
     *
     * @param array $rateLines The draft's shipping lines.
     * @param float $claimIncl The gross claimed.
     * @param object $rule The charge entry.
     * @param array<string, array{0: int, 1: int}> $taken What the rules before took at each rate, gross and
     *                                                    net in minor units, keyed by rateKey(); this rule's parts
     *                                                    are added to it.
     * @param Currency $currency The order's currency.
     *
     * @return array<int, array{rate: float, incl: float, excl: float}>
     *
     * @throws LogicException When a part falls on a rate no claimed shipping carries.
     *
     * @since  1.14.0
     */
    private static function shippingDiscountParts(array $rateLines, float $claimIncl, object $rule, array &$taken, Currency $currency): array
    {
        $carried = [];

        foreach ($rateLines as $line) {
            $rate = self::rateKey((float) ($line['tax_rate'] ?? 0));
            $carried[$rate] = [
                ($carried[$rate][0] ?? Money::zero($currency))->add(Money::of(\is_string($line['total_tax_incl'] ?? null) ? $line['total_tax_incl'] : (float) ($line['total_tax_incl'] ?? 0), $currency)),
                ($carried[$rate][1] ?? Money::zero($currency))->add(Money::of(\is_string($line['total_tax_excl'] ?? null) ? $line['total_tax_excl'] : (float) ($line['total_tax_excl'] ?? 0), $currency)),
            ];
        }

        $parts = self::splitAcrossRates(
            itemLines: $rateLines,
            claimIncl: $claimIncl,
            rule:      $rule,
            currency:  $currency,
            taken:     array_map(static fn (array $took): int => $took[0], $taken),
        );

        foreach ($parts as $i => $part) {
            $rate = self::rateKey((float) $part['rate']);
            [$gross, $net] = $carried[$rate] ?? throw new LogicException('A shipping discount falls on the rate ' . $rate . ', which no claimed shipping carries.');
            $before = Money::ofMinor($taken[$rate][0] ?? 0, $currency);
            $partGross = Money::of($part['incl'], $currency);
            $partNet = (new ShippingPool(gross: $gross, net: $net))->netOf(part: $partGross, before: $before);
            $parts[$i]['excl'] = $partNet->getAmount();
            $taken[$rate] = [$before->add($partGross)->getMinorUnits(), ($taken[$rate][1] ?? 0) + $partNet->getMinorUnits()];
        }

        return $parts;
    }

    /**
     * Split a claimed discount across the draft items' VAT rates, pro-rata
     * by tax-inclusive value, in the order's minor units: Money's one
     * allocation ({@see Money::allocateByRatios()}) over each rate's gross,
     * so every part is money at the scale and the parts sum to the claim
     * EXACTLY, line by line as the document stores them.
     *
     * A rate is never discounted past what its lines still carry after the
     * rules before this one, while the claim fits within what they carry:
     * the claim is then split over that instead. Two rules that together
     * take all of the goods would otherwise each round half up at one rate
     * and leave that rate's row a minor unit below nothing.
     *
     * @param array $itemLines The draft's item lines.
     * @param float $claimIncl The tax-inclusive amount being claimed.
     * @param object $rule The charge entry (frozen excl/incl ratio:
     *                     the fallback when the draft has no items).
     * @param array<string, int> $taken What the rules before this one took
     *                                  at each rate, in minor units, keyed by
     *                                  rateKey(); none for the first rule.
     *
     * @return array<int, array{rate: float, incl: float, excl: float}>
     *
     * @since  1.9.0
     */
    private static function splitAcrossRates(array $itemLines, float $claimIncl, object $rule, Currency $currency, array $taken = []): array
    {
        $claim = Money::of($claimIncl, $currency);
        $byRate = [];
        $totalIncl = Money::zero($currency);

        foreach ($itemLines as $line) {
            $rate = number_format((float) ($line['tax_rate'] ?? 0), 3, '.', '');
            $incl = Money::of((float) ($line['total_tax_incl'] ?? 0), $currency);
            $byRate[$rate] = isset($byRate[$rate]) ? $byRate[$rate]->add($incl) : $incl;
            $totalIncl = $totalIncl->add($incl);
        }

        if ($byRate === [] || !$totalIncl->isPositive()) {
            // Charge-only draft: the frozen row's own tax profile is the
            // only attribution the data supports: its rate, and the net as the
            // exact ratio of its two figures, rounded once.
            $taxed = $rule->amount_incl > 0;
            $excl = $taxed
                ? $claim->timesRatio(numerator: (float) $rule->amount_excl, denominator: (float) $rule->amount_incl)
                : $claim;

            return [[
                'rate' => $taxed ? self::rateFrom(incl: (float) $rule->amount_incl, excl: (float) $rule->amount_excl, currency: $currency) : 0.0,
                'incl' => $claim->getAmount(),
                'excl' => $excl->getAmount(),
            ]];
        }

        $parts = $claim->allocateByRatios(array_map(static fn (Money $gross): int => $gross->getMinorUnits(), array_values($byRate)));
        $room = [];
        $over = false;

        foreach (array_keys($byRate) as $position => $rate) {
            $room[] = max(0, $byRate[$rate]->getMinorUnits() - (int) ($taken[(string) $rate] ?? 0));
            $over = $over || $parts[$position]->getMinorUnits() > $room[$position];
        }

        if ($over && $claim->getMinorUnits() <= array_sum($room)) {
            $parts = $claim->allocateByRatios($room);
        }

        $result = [];

        foreach (array_keys($byRate) as $position => $rate) {
            $incl = $parts[$position];

            if (!$incl->isPositive()) {
                continue;
            }

            $rateF = (float) $rate;

            $result[] = [
                'rate' => $rateF,
                'incl' => $incl->getAmount(),
                'excl' => self::exact($incl->getAmount() / (1 + $rateF / 100), $currency),
            ];
        }

        return $result;
    }

    /**
     * One negative adjustment draft line (qty 1; sign lives in the prices).
     *
     * @param int $sourceId The frozen #__alfa_order_cart_rule PK.
     * @param string $name Printed label (coupon code or rule name).
     * @param float $incl NEGATIVE tax-inclusive total.
     * @param float $excl NEGATIVE tax-exclusive total.
     * @param float $rate VAT rate of the bucket being reduced.
     *
     *
     * @since  1.9.0
     */
    private static function adjustmentLine(int $sourceId, string $name, float $incl, float $excl, float $rate, Currency $currency): array
    {
        return [
            'line_type' => DocumentHelper::LINE_ADJUSTMENT,
            'id_order_item' => null,
            'id_source' => $sourceId,
            'name' => $name,
            'quantity' => 1,
            'unit_price_tax_excl' => $excl,
            'unit_price_tax_incl' => $incl,
            'tax_rate' => $rate,
            'total_tax_excl' => $excl,
            'total_tax_incl' => $incl,
            'tax_amount' => self::difference($incl, $excl, $currency),
        ];
    }

    /**
     * Sum of issued claims per (line_type, id_source) for an order: the
     * consumed side of every charge balance. Voided documents drop out.
     *
     * The sales pool reads the sales types, and so does every caller that
     * names none. chargeDrift() asks the SAME query for the credit types, so
     * "what was given back" is summed exactly the way "what was claimed" is.
     *
     * @param object $db Database driver.
     * @param int $orderId The order id.
     * @param string[]|null $docTypes The document types to sum; null = the sales types.
     *
     * @return array<string, array<int, array{incl: float, excl: float, name: string}>>
     *
     * @since  1.9.0
     */
    public static function consumedBySource(object $db, int $orderId, ?array $docTypes = null): array
    {
        $docTypes ??= DocumentHelper::salesTypes();

        // A shop with no type of the kind asked for has consumed nothing of it.
        if ($docTypes === []) {
            return [];
        }

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('i.line_type'),
                $db->quoteName('i.id_source'),
                'MIN(' . $db->quoteName('i.name') . ') AS name',
                'SUM(' . $db->quoteName('i.total_tax_incl') . ') AS incl',
                'SUM(' . $db->quoteName('i.total_tax_excl') . ') AS excl',
            ])
            ->from($db->quoteName('#__alfa_document_items', 'i'))
            ->join('INNER', $db->quoteName('#__alfa_documents', 'd') . ' ON d.id = i.id_document')
            ->where([
                $db->quoteName('d.id_order') . ' = :orderId',
                $db->quoteName('d.status') . ' = ' . DocumentHelper::STATUS_ISSUED,
                $db->quoteName('d.doc_type') . ' IN (' . implode(',', array_map([$db, 'quote'], $docTypes)) . ')',
                $db->quoteName('i.id_source') . ' IS NOT NULL',
            ])
            ->group([$db->quoteName('i.line_type'), $db->quoteName('i.id_source')])
            ->bind(':orderId', $orderId);
        $db->setQuery(query: $query);

        $map = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            // Adjustments are negative by design (abs at the consumer);
            // shipping/fee claims are positive by design: clamp so a stray
            // negative line can never inflate a balance past its frozen cap.
            $map[(string) $row->line_type][(int) $row->id_source] = [
                'incl' => (float) $row->incl,
                'excl' => (float) $row->excl,
                'name' => (string) $row->name,
            ];
            if ($row->line_type !== DocumentHelper::LINE_ADJUSTMENT) {
                $map[(string) $row->line_type][(int) $row->id_source] = [
                    'incl' => max(0.0, (float) $row->incl),
                    'excl' => max(0.0, (float) $row->excl),
                    'name' => (string) $row->name,
                ];
            }
        }

        return $map;
    }

    /**
     * Per shipping and fee source: how far the order's CURRENT charge has
     * drifted from what its active documents cover, in each direction.
     *
     * NEGATIVE is what sales documents claimed, net of what credit notes gave
     * back, above what the order carries now: what a credit must give back.
     * POSITIVE is the sales pool's own remainder (remaining()): the current
     * charge above what sales documents claimed, which another sales document
     * may take. A credited amount never refills the sales pool, so a charge
     * lowered, credited and raised again is not re-invoiced, the rule the item
     * pool follows in IncrementalCorrection::lineDrift().
     *
     * A source the order no longer carries (a parcel deleted, cancelled or
     * returned) reads as zero now, so everything it still documents is drift.
     *
     * Discount rules are not drift triggers: they follow the goods and the
     * shipping they reduce, and the builders size them. What a
     * shipping-class rule contributes here is its SIZE on a credit: the order
     * takes it off at most the shipping it still carries, rule by rule in the
     * order OrderTotalHelper applies them, and never re-freezes the rule row.
     * So a credit of reduced shipping gives back only the discount the order
     * no longer applies (`negative.rules`): none while the shipping left still
     * covers the whole discount, never the whole discount by default.
     *
     * `offset` is true when the negative drift gives back nothing: every
     * shipping reduction is matched in full by the discount the order no
     * longer applies, and no fee drifted; a free-shipping order whose shipping
     * cost was lowered. The order's total has not moved, so a proposal must not
     * treat it as a credit to issue.
     *
     * @param int $orderId The order id.
     *
     * @return object{negative: array{shipping: array<int, float>, fees: array<int, float>, rules: array<int, float>},
     *                positive: array{shipping: array<int, float>, fees: array<int, float>},
     *                labels: array{shipping: array<int, string>, fees: array<int, string>},
     *                offset: bool}
     *         Gross amounts at the order's frozen scale, non-zero entries only.
     *         `labels` names every negative source as its documents printed it.
     *
     * @since  1.14.0
     */
    public static function chargeDrift(int $orderId): object
    {
        $currency = OrderItemLines::orderCurrency(orderId: $orderId);
        $db = Factory::getContainer()->get('DatabaseDriver');
        $sold = self::consumedBySource(db: $db, orderId: $orderId);
        $credited = self::consumedBySource(db: $db, orderId: $orderId, docTypes: DocumentHelper::creditTypes());
        $pools = self::remaining(orderId: $orderId);

        $drift = (object) [
            'negative' => ['shipping' => [], 'fees' => [], 'rules' => []],
            'positive' => ['shipping' => [], 'fees' => []],
            'labels' => ['shipping' => [], 'fees' => []],
            'offset' => false,
        ];

        foreach ([
            'shipping' => [DocumentHelper::LINE_SHIPPING, $pools->shipping],
            'fees' => [DocumentHelper::LINE_FEE, $pools->fees],
        ] as $kind => [$type, $rows]) {
            $current = [];

            foreach ($rows as $row) {
                $current[$row->id] = Money::of($row->amount_incl, $currency);

                if (Money::of($row->remaining_incl, $currency)->isPositive()) {
                    $drift->positive[$kind][$row->id] = $row->remaining_incl;
                }
            }

            foreach ($sold[$type] ?? [] as $sourceId => $claimed) {
                $over = Money::of($claimed['incl'], $currency)
                    ->subtract(Money::of($credited[$type][$sourceId]['incl'] ?? 0.0, $currency))
                    ->subtract($current[$sourceId] ?? Money::zero($currency));

                if ($over->isPositive()) {
                    $drift->negative[$kind][$sourceId] = $over->getAmount();
                    $drift->labels[$kind][$sourceId] = $claimed['name'];
                }
            }
        }

        // Shipping-class discounts the order no longer applies
        // The order's own arithmetic (OrderTotalHelper::computeFromArrays): each
        // rule takes off what the active shipping still has, never below zero.
        $shippingLeft = Money::zero($currency);

        foreach ($pools->shipping as $parcel) {
            $shippingLeft = $shippingLeft->add(Money::of($parcel->amount_incl, $currency));
        }

        foreach ($pools->rules as $rule) {
            if ($rule->discount_class !== 'shipping') {
                continue;
            }

            $after = Money::max($shippingLeft->subtract(Money::of($rule->amount_incl, $currency)), Money::zero($currency));
            $applied = $shippingLeft->subtract($after);
            $shippingLeft = $after;

            // Adjustment lines are negative on both sides; the claims are magnitudes.
            $over = Money::of(abs($sold[DocumentHelper::LINE_ADJUSTMENT][$rule->id]['incl'] ?? 0.0), $currency)
                ->subtract(Money::of(abs($credited[DocumentHelper::LINE_ADJUSTMENT][$rule->id]['incl'] ?? 0.0), $currency))
                ->subtract($applied);

            if ($over->isPositive()) {
                $drift->negative['rules'][$rule->id] = $over->getAmount();
            }
        }

        // Does the negative drift give anything back at all?
        // A credit's discount rides the shipping it credits and is capped by
        // it, so the discount side counts at most the shipping side.
        $shippingBack = Money::zero($currency);
        $discountBack = Money::zero($currency);

        foreach ($drift->negative['shipping'] as $incl) {
            $shippingBack = $shippingBack->add(Money::of($incl, $currency));
        }

        foreach ($drift->negative['rules'] as $incl) {
            $discountBack = $discountBack->add(Money::of($incl, $currency));
        }

        $drift->offset = $drift->negative['shipping'] !== []
            && $drift->negative['fees'] === []
            && $shippingBack->subtract(Money::min($discountBack, $shippingBack))->isZero();

        self::discountDrift(db: $db, orderId: $orderId, drift: $drift, currency: $currency);

        return $drift;
    }

    /**
     * The discount half of chargeDrift(): per non-shipping cart rule, what a
     * credit must GRANT and what another sales document must REVERSE.
     *
     * A coupon added or raised after invoicing is money the documents
     * over-charge; one cut or removed, money they under-charge.
     * Sized by discountHeadroom(), from the rule's STORED value
     * and the documents' stored discount lines, never from a percentage.
     *
     * Three conditions decide whether a proposal may act, and each refusal is
     * named in `blocked` rather than dropped:
     *   - a GRANT waits until every order line is fully invoiced: before that
     *     the discount still due lands on the invoice of the remaining goods,
     *     which is the sales builder's own rule, not drift;
     *   - once goods have been credited, the discount's share of the goods
     *     kept is stored nowhere, so a change is not additive (goods_credited);
     *   - a rule whose frozen net/gross no longer matches the documented one,
     *     or the goods' for a rule never documented, changed its tax semantic
     *     and is section 17's, not money's (tax_changed).
     *
     * @param object $db Database driver.
     * @param int $orderId The order id.
     * @param object $drift chargeDrift()'s result, extended in place.
     * @param Currency $currency The order's frozen currency.
     *
     *
     * @since  1.14.0
     */
    private static function discountDrift(object $db, int $orderId, object $drift, Currency $currency): void
    {
        $drift->negative['grants'] = [];
        $drift->positive['reversals'] = [];
        $drift->labels['discounts'] = [];
        $drift->blocked = [];

        $headroom = self::discountHeadroom(orderId: $orderId);

        if ($headroom === []) {
            return;
        }

        $goodsDocumented = array_filter(
            DocumentHelper::remainingQuantities(orderId: $orderId, docType: DocumentHelper::TYPE_INVOICE),
            static fn (array $line): bool => $line['remaining'] > 0.0,
        ) === [];
        $goodsCredited = array_filter(
            DocumentHelper::consumedQuantities(orderId: $orderId, docTypes: DocumentHelper::creditTypes()),
            static fn (float $quantity): bool => $quantity > 0.0,
        ) !== [];

        foreach ($headroom as $ruleId => $rule) {
            $grant = $goodsDocumented ? Money::of($rule->grantable_incl, $currency) : Money::zero($currency);
            $reverse = Money::of($rule->reversible_incl, $currency);

            if (!$grant->isPositive() && !$reverse->isPositive()) {
                continue;
            }

            $drift->labels['discounts'][$ruleId] = $rule->label;

            $cause = match (true) {
                $goodsCredited => 'goods_credited',
                Money::of($rule->current_incl, $currency)->isPositive()
                    && !self::discountTaxHolds(db: $db, orderId: $orderId, rule: $rule, currency: $currency) => 'tax_changed',
                default => '',
            };

            if ($cause !== '') {
                $drift->blocked[] = (object) ['id' => $ruleId, 'label' => $rule->label, 'reason' => $cause];

                continue;
            }

            if ($grant->isPositive()) {
                $drift->negative['grants'][$ruleId] = $grant->getAmount();
            }

            if ($reverse->isPositive()) {
                $drift->positive['reversals'][$ruleId] = $reverse->getAmount();
            }
        }
    }

    /**
     * Does a cart rule's frozen net/gross still match the tax semantic its
     * money is documented at?
     *
     * A rule sales documents carry is asked against those lines' own net/gross:
     * a coupon cut or raised at the same tax is the same semantic. A rule no
     * document carries is asked against the invoiced goods' net/gross, the
     * ratio the cart engine froze it at (its net is its gross times the goods'
     * net over their gross). Either way the test is the parcel test of the
     * reconciliation facts: one side times the ratio lands on the other at the
     * order's frozen scale, from either direction.
     *
     * @param object $db Database driver.
     * @param int $orderId The order id.
     * @param object $rule One discountHeadroom() entry.
     * @param Currency $currency The order's frozen currency.
     *
     *
     * @since  1.14.0
     */
    private static function discountTaxHolds(object $db, int $orderId, object $rule, Currency $currency): bool
    {
        $salesIncl = abs($rule->ledger?->sales_incl->getAmount() ?? 0.0);

        if (Money::of($salesIncl, $currency)->isPositive()) {
            $ratio = abs($rule->ledger->sales_excl->getAmount()) / $salesIncl;
        } else {
            $query = $db->getQuery(true)
                ->select([
                    'SUM(' . $db->quoteName('i.total_tax_incl') . ') AS ' . $db->quoteName('incl'),
                    'SUM(' . $db->quoteName('i.total_tax_excl') . ') AS ' . $db->quoteName('excl'),
                ])
                ->from($db->quoteName('#__alfa_document_items', 'i'))
                ->join('INNER', $db->quoteName('#__alfa_documents', 'd') . ' ON d.id = i.id_document')
                ->where([
                    $db->quoteName('d.id_order') . ' = :orderId',
                    $db->quoteName('d.status') . ' = ' . DocumentHelper::STATUS_ISSUED,
                    $db->quoteName('d.doc_type') . ' IN (' . implode(',', array_map([$db, 'quote'], DocumentHelper::salesTypes())) . ')',
                    $db->quoteName('i.line_type') . ' = ' . $db->quote(DocumentHelper::LINE_ITEM),
                ])
                ->bind(':orderId', $orderId);
            $goods = $db->setQuery(query: $query)->loadObject();

            if (!Money::of((float) ($goods->incl ?? 0), $currency)->isPositive()) {
                return false;
            }

            $ratio = (float) $goods->excl / (float) $goods->incl;
        }

        $incl = Money::of($rule->current_incl, $currency);
        $excl = Money::of($rule->current_excl, $currency);

        return Money::of($incl->getAmount() * $ratio, $currency)->equals($excl)
            || ($ratio > 0.0 && Money::of($excl->getAmount() / $ratio, $currency)->equals($incl));
    }

    /**
     * What one corrected document can give back of the order's negative charge
     * drift, and what it cannot.
     *
     * A credit corrects ONE document and may only give back what that document
     * charged and still has left. The drift is order-wide, so part of it can sit
     * on another sales document, or exceed what this one has left after earlier
     * notes. That part is neither guessed onto another source nor dropped: it is
     * returned as unabsorbed, so the proposal can name it.
     *
     * The amount handed to the builder is the drift itself. creditLines() caps
     * it at the source's remaining, so the cap lives in one place; the
     * unabsorbed figure is what that cap leaves out. The shipping-class
     * discount the order no longer applies goes with it as `rules`: it sizes
     * the discount the credited shipping gives back, and creditLines() caps it
     * at that shipping and at what this document's rule has left.
     *
     * @param int $orderId The order id; its frozen scale decides every comparison.
     * @param object $drift chargeDrift() for the order.
     * @param object $source correctedChargesFor() for the corrected document.
     *
     * @return object{amounts: array{shipping: array<int, float>, fees: array<int, float>, rules: array<int, float>},
     *                unabsorbed: list<object{kind: string, id: int, label: string, incl: float, scale: int}>}
     *         `scale` is the order's frozen scale, the one `incl` is stated at.
     *
     * @since  1.14.0
     */
    public static function creditableDrift(int $orderId, object $drift, object $source): object
    {
        $currency = OrderItemLines::orderCurrency(orderId: $orderId);
        $result = (object) [
            'amounts' => ['shipping' => [], 'fees' => [], 'rules' => $drift->negative['rules']],
            'unabsorbed' => [],
        ];

        foreach (['shipping', 'fees'] as $kind) {
            $left = [];

            foreach ($source->{$kind} as $entry) {
                $left[(int) $entry->id] = Money::of(abs($entry->remaining_incl), $currency);
            }

            foreach ($drift->negative[$kind] as $sourceId => $incl) {
                $needed = Money::of($incl, $currency);
                $available = $left[$sourceId] ?? Money::zero($currency);

                if ($available->isPositive()) {
                    $result->amounts[$kind][$sourceId] = $incl;
                }

                $beyond = Money::max($needed->subtract($available), Money::zero($currency));

                if ($beyond->isPositive()) {
                    $result->unabsorbed[] = (object) [
                        'kind' => $kind,
                        'id' => $sourceId,
                        'label' => $drift->labels[$kind][$sourceId],
                        'incl' => $beyond->getAmount(),
                        'scale' => $currency->getDecimalPlaces(),
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * What documents did to each non-shipping cart rule of an order, split by
     * what each claim did to the discount.
     *
     * A DISCOUNT MOVES THREE WAYS, and one signed sum would net them into a
     * figure no rule can be written against:
     *   sales     what sales documents charged against the rule, SIGNED: a
     *             discount line is negative, a later reversal of it positive;
     *   granted   what credit notes GAVE on top of it: a positive adjustment
     *             line, issued when the order came to give more discount;
     *   reversed  what credit notes TOOK BACK with the goods they credited:
     *             a negative adjustment line that follows the merchandise.
     * Shipping-class rules belong to the shipping pool (chargeDrift()).
     *
     * Each figure is the order's money, added in its minor units.
     *
     * @param object $db Database driver.
     * @param int $orderId The order id.
     * @param Currency $currency The order's currency, at its frozen scale.
     *
     * @return array<int, object{sales_incl: Money, sales_excl: Money, granted_incl: Money, granted_excl: Money,
     *                           reversed_incl: Money, reversed_excl: Money, label: string, discount_class: string}>
     *         Keyed by #__alfa_order_cart_rule id; reversed and granted are magnitudes.
     *
     * @since  1.14.0
     */
    public static function discountLedger(object $db, int $orderId, Currency $currency): array
    {
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('i.id_source'),
                $db->quoteName('d.doc_type'),
                '(' . $db->quoteName('i.total_tax_incl') . ' > 0) AS ' . $db->quoteName('positive'),
                'MIN(' . $db->quoteName('i.name') . ') AS ' . $db->quoteName('label'),
                'MAX(' . $db->quoteName('r.discount_class') . ') AS ' . $db->quoteName('discount_class'),
                'SUM(' . $db->quoteName('i.total_tax_incl') . ') AS ' . $db->quoteName('incl'),
                'SUM(' . $db->quoteName('i.total_tax_excl') . ') AS ' . $db->quoteName('excl'),
            ])
            ->from($db->quoteName('#__alfa_document_items', 'i'))
            ->join('INNER', $db->quoteName('#__alfa_documents', 'd') . ' ON d.id = i.id_document')
            ->join('LEFT', $db->quoteName('#__alfa_order_cart_rule', 'r') . ' ON r.id_order_cart_rule = i.id_source')
            ->where([
                $db->quoteName('d.id_order') . ' = :orderId',
                $db->quoteName('d.status') . ' = ' . DocumentHelper::STATUS_ISSUED,
                $db->quoteName('i.line_type') . ' = ' . $db->quote(DocumentHelper::LINE_ADJUSTMENT),
                $db->quoteName('i.id_source') . ' IS NOT NULL',
            ])
            ->group([$db->quoteName('i.id_source'), $db->quoteName('d.doc_type'), $db->quoteName('positive')])
            ->bind(':orderId', $orderId);
        $db->setQuery(query: $query);

        $ledger = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            if ((string) ($row->discount_class ?? '') === 'shipping') {
                continue;
            }

            $pool = DocumentHelper::poolOf((string) $row->doc_type);

            if ($pool !== DocumentHelper::POOL_SALES && $pool !== DocumentHelper::POOL_CREDIT) {
                continue;
            }

            $entry = $ledger[(int) $row->id_source] ??= (object) [
                'sales_incl' => Money::zero($currency),
                'sales_excl' => Money::zero($currency),
                'granted_incl' => Money::zero($currency),
                'granted_excl' => Money::zero($currency),
                'reversed_incl' => Money::zero($currency),
                'reversed_excl' => Money::zero($currency),
                'label' => (string) $row->label,
                'discount_class' => (string) ($row->discount_class ?? '') ?: 'order',
            ];

            $incl = Money::of((string) $row->incl, $currency);
            $excl = Money::of((string) $row->excl, $currency);

            if ($pool === DocumentHelper::POOL_SALES) {
                $entry->sales_incl = $entry->sales_incl->add($incl);
                $entry->sales_excl = $entry->sales_excl->add($excl);
            } elseif ((int) $row->positive === 1) {
                $entry->granted_incl = $entry->granted_incl->add($incl);
                $entry->granted_excl = $entry->granted_excl->add($excl);
            } else {
                $entry->reversed_incl = $entry->reversed_incl->add($incl->abs());
                $entry->reversed_excl = $entry->reversed_excl->add($excl->abs());
            }
        }

        return $ledger;
    }

    /**
     * How far each non-shipping cart rule's documented discount sits from the
     * discount the order gives now: what may still be GRANTED, and what must
     * be REVERSED.
     *
     * In gross discount magnitudes at the order's frozen scale, for every rule
     * the order carries (C, its frozen value: stored, never recomputed from a
     * percentage) and every rule a document names (C = 0 once the order no
     * longer carries it):
     *
     *   given       sales discount, net of sales reversals, plus credit grants
     *   documented  max(given - taken back, min(given, C))
     *   grantable   C - given, when positive
     *   reversible  documented - C, when positive
     *
     * "Taken back" is what credit notes reversed with the goods they credited.
     * It is the reconciliation facts' documented(), in discount space: a
     * reversal counts only as far as it offsets an over-grant, because
     * returned goods take their share of the discount with them and that is
     * no drift.
     *
     * These are the MONEY CEILINGS the issuer enforces on every grant and
     * reversal: no document may give more discount than the order gives, nor
     * take back more than documents gave beyond it. Whether a proposal may act
     * on them (the goods fully documented, none credited, the tax unchanged)
     * is chargeDrift()'s question.
     *
     * @param int $orderId The order id.
     *
     * @return array<int, object{current_incl: float, current_excl: float, grantable_incl: float,
     *                           reversible_incl: float, documented_incl: float, label: string,
     *                           ledger: object|null}> Keyed by rule id.
     *
     * @since  1.14.0
     */
    public static function discountHeadroom(int $orderId): array
    {
        $currency = OrderItemLines::orderCurrency(orderId: $orderId);
        $db = Factory::getContainer()->get('DatabaseDriver');
        $ledger = self::discountLedger(db: $db, orderId: $orderId, currency: $currency);

        $query = $db->getQuery(true)
            ->select($db->quoteName(['id_order_cart_rule', 'name', 'code', 'value_tax_excl', 'value_tax_incl']))
            ->from($db->quoteName('#__alfa_order_cart_rule'))
            ->where($db->quoteName('id_order') . ' = :orderId')
            ->where($db->quoteName('deleted') . ' = 0')
            ->where($db->quoteName('discount_class') . ' <> ' . $db->quote('shipping'))
            ->order($db->quoteName('id_order_cart_rule') . ' ASC')
            ->bind(':orderId', $orderId);
        $db->setQuery(query: $query);
        $current = $db->loadObjectList('id_order_cart_rule') ?: [];

        $ids = array_unique(array_merge(array_map('intval', array_keys($current)), array_keys($ledger)));
        sort($ids);
        $headroom = [];

        foreach ($ids as $ruleId) {
            $rule = $current[$ruleId] ?? null;
            $entry = $ledger[$ruleId] ?? null;
            $incl = $rule === null ? 0.0 : abs((float) $rule->value_tax_incl);
            $excl = $rule === null ? 0.0 : abs((float) $rule->value_tax_excl);

            // Rows frozen before 1.9.0 carry no inclusive figure (remaining()).
            if ($rule !== null && !Money::of($incl, $currency)->isPositive()) {
                $incl = $excl;
            }

            $held = Money::of($incl, $currency);
            $given = ($entry?->sales_incl ?? Money::zero($currency))->negate()->add($entry?->granted_incl ?? Money::zero($currency));
            $takenBack = $entry?->reversed_incl ?? Money::zero($currency);
            $documented = Money::max($given->subtract($takenBack), Money::min($given, $held));

            $headroom[$ruleId] = (object) [
                'current_incl' => $held->getAmount(),
                'current_excl' => Money::of($excl, $currency)->getAmount(),
                'documented_incl' => $documented->getAmount(),
                'grantable_incl' => Money::max($held->subtract($given), Money::zero($currency))->getAmount(),
                'reversible_incl' => Money::max($documented->subtract($held), Money::zero($currency))->getAmount(),
                'label' => $rule !== null
                    ? (string) (($rule->code ?? '') !== '' ? $rule->code : $rule->name)
                    : (string) ($entry->label ?? ''),
                'ledger' => $entry,
            ];
        }

        return $headroom;
    }

    /**
     * The VAT buckets a discount's documented money sits in: the rule's own
     * adjustment lines on the given documents, grouped as the documents froze
     * them. The stored allocation a grant or reversal follows.
     *
     * @param object $db Database driver.
     * @param int[] $documentIds The documents to read.
     * @param int $ruleId The #__alfa_order_cart_rule id.
     *
     * @return list<array{rate: float, vat_category: ?int, vat_exemption_code: ?string, incl: float, excl: float}>
     *                                                                                                             Magnitudes, largest first.
     *
     * @since  1.14.0
     */
    private static function ruleBuckets(object $db, array $documentIds, int $ruleId): array
    {
        return self::bucketsOf(db: $db, documentIds: $documentIds, where: [
            $db->quoteName('i.line_type') . ' = ' . $db->quote(DocumentHelper::LINE_ADJUSTMENT),
            $db->quoteName('i.id_source') . ' = ' . $ruleId,
        ]);
    }

    /**
     * The VAT buckets of the goods a document carries: the allocation a
     * discount it never carried follows, exactly as the sales builder splits
     * a discount across the goods it reduces.
     *
     * @param object $db Database driver.
     * @param int $documentId The document.
     *
     * @return list<array{rate: float, vat_category: ?int, vat_exemption_code: ?string, incl: float, excl: float}>
     *
     * @since  1.14.0
     */
    private static function goodsBuckets(object $db, int $documentId): array
    {
        return self::bucketsOf(db: $db, documentIds: [$documentId], where: [
            $db->quoteName('i.line_type') . ' = ' . $db->quote(DocumentHelper::LINE_ITEM),
        ]);
    }

    /**
     * Document lines grouped by their VAT identity, as magnitudes, largest first.
     *
     * @param object $db Database driver.
     * @param int[] $documentIds The documents to read.
     * @param string[] $where The line filter.
     *
     * @return list<array{rate: float, vat_category: ?int, vat_exemption_code: ?string, incl: float, excl: float}>
     *
     * @since  1.14.0
     */
    private static function bucketsOf(object $db, array $documentIds, array $where): array
    {
        $documentIds = array_values(array_filter(array_map('intval', $documentIds)));

        if ($documentIds === []) {
            return [];
        }

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('i.tax_rate'),
                $db->quoteName('i.vat_category'),
                $db->quoteName('i.vat_exemption_code'),
                'SUM(' . $db->quoteName('i.total_tax_incl') . ') AS ' . $db->quoteName('incl'),
                'SUM(' . $db->quoteName('i.total_tax_excl') . ') AS ' . $db->quoteName('excl'),
            ])
            ->from($db->quoteName('#__alfa_document_items', 'i'))
            ->whereIn($db->quoteName('i.id_document'), $documentIds)
            ->where($where)
            ->group($db->quoteName(['i.tax_rate', 'i.vat_category', 'i.vat_exemption_code']));
        $db->setQuery(query: $query);

        $buckets = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $buckets[] = [
                'rate' => (float) $row->tax_rate,
                'vat_category' => $row->vat_category === null ? null : (int) $row->vat_category,
                'vat_exemption_code' => $row->vat_exemption_code === null ? null : (string) $row->vat_exemption_code,
                'incl' => abs((float) $row->incl),
                'excl' => abs((float) $row->excl),
            ];
        }

        usort($buckets, static fn (array $a, array $b): int => $b['incl'] <=> $a['incl']);

        return $buckets;
    }

    /**
     * Spread a gross amount across VAT buckets by their weight, in minor units,
     * so the parts sum to the amount EXACTLY.
     *
     * By the one allocation ({@see Money::allocateByRatios()}) over the buckets
     * in their order: every part within one minor unit of its exact share, none
     * below zero, the product of an amount and a weight formed without leaving
     * integers at any size. Each part's net follows its own bucket's frozen
     * net/gross ratio when the bucket is the discount's own money, and the
     * bucket's rate when it is the goods' (a discount the document never
     * carried, split as the sales builder splits one).
     *
     * @param array $buckets From ruleBuckets() or goodsBuckets(), largest first.
     * @param float $incl The gross magnitude to spread.
     * @param bool $ownRatio True for a rule's own buckets, false for goods buckets.
     * @param Currency $currency The order's frozen currency.
     *
     * @return list<array{bucket: array, incl: float, excl: float}> Magnitudes; empty parts omitted.
     *
     * @since  1.14.0
     */
    private static function spread(array $buckets, float $incl, bool $ownRatio, Currency $currency): array
    {
        $buckets = array_values($buckets);
        $amount = Money::of($incl, $currency);
        $weights = array_map(static fn (array $b): int => Money::of($b['incl'], $currency)->getMinorUnits(), $buckets);

        if (!$amount->isPositive() || array_sum($weights) <= 0) {
            return [];
        }

        $parts = [];

        foreach ($amount->allocateByRatios($weights) as $i => $gross) {
            if ($gross->isZero()) {
                continue;
            }

            $bucket = $buckets[$i];
            $net = $ownRatio
                ? ($bucket['incl'] > 0 ? $gross->timesRatio(numerator: (float) $bucket['excl'], denominator: (float) $bucket['incl']) : $gross)
                : TaxChain::netOf(gross: $gross, rates: [(float) $bucket['rate']]);

            $parts[] = ['bucket' => $bucket, 'incl' => $gross->getAmount(), 'excl' => $net->getAmount()];
        }

        return $parts;
    }

    /**
     * One discount line (a grant on a credit note, a reversal on a sales
     * document), POSITIVE on both: a grant gives the customer money back, a
     * reversal charges back discount the order no longer gives. It carries
     * its bucket's VAT identity, as every charge line does.
     *
     * @param int $ruleId The #__alfa_order_cart_rule id.
     * @param string $label Printed label.
     * @param array $part One spread() part.
     * @param Currency $currency The order's frozen currency.
     *
     *
     * @since  1.14.0
     */
    private static function discountLine(int $ruleId, string $label, array $part, Currency $currency): array
    {
        return [
            'line_type' => DocumentHelper::LINE_ADJUSTMENT,
            'id_order_item' => null,
            'id_source' => $ruleId,
            'name' => $label,
            'quantity' => 1,
            'unit_price_tax_excl' => $part['excl'],
            'unit_price_tax_incl' => $part['incl'],
            'tax_rate' => $part['bucket']['rate'],
            'vat_category' => $part['bucket']['vat_category'],
            'vat_exemption_code' => $part['bucket']['vat_exemption_code'],
            'total_tax_excl' => $part['excl'],
            'total_tax_incl' => $part['incl'],
            'tax_amount' => self::difference($part['incl'], $part['excl'], $currency),
        ];
    }

    /**
     * The charge picks an operator's credit form posts, made explicit and checked.
     *
     * The order-screen credit may give back PART of a shipping or fee charge
     * line: each charge is credited only when ticked
     * (nothing is ticked by default), and every ticked charge states
     * its amount: the form pre-fills what is left, but what is credited is
     * always the figure posted, never a full credit implied by silence. The
     * amount is read exactly ({@see ExactMoney}) at the CORRECTED document's
     * frozen scale, the authority of what it charged:
     *   - a ticked charge without an amount is refused;
     *   - a malformed amount, one with more decimal places than the scale
     *     (never rounded), one larger than an amount holds, zero or less is
     *     refused;
     *   - more than the document has left for that charge after every earlier
     *     credit is refused, never silently cut down;
     *   - one minor unit is an amount.
     * Nothing else a POST could name is kept: no discount pick (a discount
     * follows the goods and the shipping), and no amount of any other kind.
     * The result feeds the same builder and the same issuer guards a proposed
     * correction does, so the arithmetic is one.
     *
     * @param int $correctsId The corrected document.
     * @param array $posted The form's `charges` array.
     *
     * @return array{shipping: int[], fees: int[], rules: int[], amounts: array{shipping: array<int, float>, fees: array<int, float>}}
     *
     * @throws RuntimeException Naming the charge and what is wrong with its amount.
     *
     * @since  1.14.0
     */
    public static function manualCreditPicks(int $correctsId, array $posted): array
    {
        $document = DocumentHelper::getById($correctsId);
        $currency = DocumentMoney::currencyOf($document);
        $charges = self::correctedCharges(correctsId: $correctsId);
        $picks = ['shipping' => [], 'fees' => [], 'rules' => [], 'amounts' => ['shipping' => [], 'fees' => []]];

        foreach (['shipping' => $charges->shipping, 'fees' => $charges->fees] as $kind => $entries) {
            $byId = array_column($entries, null, 'id');

            foreach (array_unique(array_map('intval', (array) ($posted[$kind] ?? []))) as $id) {
                $entry = $byId[$id] ?? null;

                if ($entry === null) {
                    throw new RuntimeException("charge #{$id} is not on the corrected document; a credit gives back what that document charged.");
                }

                $label = (string) $entry->label;
                $raw = trim((string) ($posted['amounts'][$kind][$id] ?? ''));

                if ($raw === '') {
                    throw new RuntimeException("enter the amount of {$label} to credit; a charge is credited by the amount stated, never whole by default.");
                }

                try {
                    $amount = ExactMoney::of(value: $raw, currency: $currency, commaDecimal: true);
                } catch (ExactMoneyRefusal $e) {
                    throw new RuntimeException(match ($e->reason()) {
                        ExactMoney::FINER => sprintf("%s: '%s' has more decimal places than %s allows (%d); an amount is never rounded.", $label, $raw, $currency->getCode(), $currency->getDecimalPlaces()),
                        ExactMoney::GROUPED => sprintf("%s: '%s' has more decimal places than %s allows (%d); enter the amount without a thousands separator.", $label, $raw, $currency->getCode(), $currency->getDecimalPlaces()),
                        ExactMoney::LARGER => sprintf("%s: '%s' is larger than an amount of %s holds.", $label, $raw, $currency->getCode()),
                        default => "{$label}: '{$raw}' is not an amount in this document's currency.",
                    }, 0, $e);
                }

                // What is left, exactly (correctedChargesFor()).
                $left = Money::of($entry->remaining_incl_exact, $currency);

                if (!$amount->isPositive()) {
                    throw new RuntimeException("{$label}: the amount to credit must be more than zero.");
                }

                if ($amount->greaterThan($left)) {
                    // Both figures on their digits, as the modal prints them.
                    throw new RuntimeException(sprintf(
                        '%s: %s is more than the %s the document has left for it after earlier credits.',
                        $label,
                        self::shownFigure($amount),
                        self::shownFigure($left),
                    ));
                }

                $picks[$kind][] = $id;
                $picks['amounts'][$kind][$id] = $amount->getAmount();
            }
        }

        return $picks;
    }

    /**
     * A money figure as the documents modal prints one (6,20; 81.474.912,58707308),
     * on its digits: DocumentMoney::formatter() prints through a float.
     *
     * @param Money $money The figure.
     *
     *
     * @since  1.14.0
     */
    private static function shownFigure(Money $money): string
    {
        [$whole, $fraction] = array_pad(explode('.', PublishedDecimal::money($money)), 2, '');
        $sign = str_starts_with($whole, '-') ? '-' : '';
        $grouped = $sign . strrev(implode('.', str_split(strrev(ltrim($whole, '-')), 3)));

        return $fraction === '' ? $grouped : $grouped . ',' . $fraction;
    }

    /**
     * Is an amount exactly nothing at the order's frozen scale?
     *
     * A previewed document total is a sum of floats, and whether it is zero is
     * a question about money with one exact answer. The correction proposer
     * computes no money of its own, so it asks here, at the scale every charge
     * pool of the order is already decided at.
     *
     * @param int $orderId The order id.
     * @param float $amount The amount, as a preview totals it.
     *
     *
     * @since  1.14.0
     */
    public static function isZeroAtOrderScale(int $orderId, float $amount): bool
    {
        return Money::of($amount, OrderItemLines::orderCurrency(orderId: $orderId))->isZero();
    }

    /**
     * Derive a VAT percentage from an incl/excl pair (0 when untaxed).
     *
     * @param float $incl Tax-inclusive amount.
     * @param float $excl Tax-exclusive amount.
     *
     * @return float Rate rounded to 3 decimals (e.g. 24.0).
     *
     * @since  1.9.0
     */
    private static function rateFrom(float $incl, float $excl, Currency $currency): float
    {
        if (!self::isPositive($excl, $currency) || $incl <= $excl) {
            return 0.0;
        }

        return round(($incl / $excl - 1) * 100, 3);
    }
}
