<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderTotalHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\Factory;
use stdClass;
use Throwable;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * What an order's documents have claimed against it, and what is wrong with it.
 *
 * Why this is three axes and not one verdict
 *
 * An order can be in trouble in three unrelated ways, and collapsing them into
 * a single badge is how an operator ends up chasing the wrong thing:
 *
 *   needs_reconciliation  a document says something the order cannot support:
 *                         it claimed more than existed, or it froze a fact the
 *                         order has since contradicted. This is a DEFECT.
 *   credit_settlement     a credit note was issued and the customer has not yet
 *                         had the money. This is WORK, not a defect.
 *   overpaid / excess     the shop holds more than the order now asks for. Also
 *                         work, also not a defect, and already owned by
 *                         {@see OrderFacts}; carried here so a projection has
 *                         one place to read, never re-derived.
 *
 * None of the three implies either of the others and nothing here lets one set
 * another. A later needs_attention may aggregate them for a screen, but it must
 * stay derived and must carry its reasons.
 *
 * Coverage is not a problem
 *
 * The single most common way to get this wrong is to treat "not fully
 * documented" as an alarm. An order with no invoice yet is NONE. An order
 * half-invoiced is PARTIAL. Both are ordinary and healthy, and neither sets
 * needs_reconciliation. Only OVER (a claim beyond what existed) is a defect,
 * because it means a document states money or quantity the order never had.
 *
 * And "coverage" has four meanings, so it is never published unqualified
 *
 * A document type declares which pool it draws on, and the answer to "how much
 * is covered" is different for each:
 *
 *   sales        draws on what was ORDERED, and reads its documents net of the
 *                credits that corrected an over-claim on the same line or
 *                charge, so a correct credit note reconciles the order on its
 *                own. Shipping is read as the order charges it, net of its
 *                shipping-class discounts.
 *   credit       draws on what sales documents actually ISSUED; you cannot
 *                credit what was never invoiced.
 *   independent  draws on what was ordered, but counts only its OWN alias, so
 *                a delivery note and a proforma do not consume each other.
 *   none         draws on nothing and is uncounted: NOT_APPLICABLE, which is
 *                a real answer and not a missing one.
 *
 * Publishing one merged `coverage` would mean four different things depending
 * on a document type the caller cannot see, so every figure below is named.
 *
 * Derived, never stored
 *
 * There is no reconciliation column anywhere and this class must never acquire
 * one. A stored verdict is a second truth that drifts from the ledger the
 * moment anything writes a document without updating it, and the whole point of
 * a reconciliation fact is that it cannot be stale.
 *
 * Every figure is money at the order's frozen scale
 *
 * Comparisons are integer minor units through {@see Money}. No epsilon decides
 * anything here, and the pools this reads were made exact for that reason.
 *
 * @since  2.0.0
 */
final class ReconciliationFacts
{
    /** Nothing has been claimed against this pool. Healthy. */
    public const STATE_NONE = 'NONE';

    /** Some of the pool has been claimed. Healthy. */
    public const STATE_PARTIAL = 'PARTIAL';

    /** The pool is exactly exhausted. Healthy. */
    public const STATE_FULL = 'FULL';

    /** More was claimed than existed. A DEFECT: the only state that alarms. */
    public const STATE_OVER = 'OVER';

    /** This pool does not apply: the type draws on nothing. Not a gap. */
    public const STATE_NOT_APPLICABLE = 'NOT_APPLICABLE';

    /** The four money pools an order carries, in the order a human reads them. */
    public const POOLS = ['items', 'shipping', 'fees', 'discounts'];

    /**
     * Reasons a reconciliation verdict can be true. Each is a DEFECT.
     *
     * Named rather than boolean so a surface can say WHICH condition fired
     * without re-deriving it, and so a new condition cannot be added silently.
     */
    public const REASON_OVER_CLAIMED = 'pool_over_claimed';
    public const REASON_NEGATIVE_REMAINING = 'negative_remaining';
    public const REASON_SEMANTIC_MISMATCH = 'semantic_mismatch';

    /**
     * A member of a sales pool (one order line, parcel, fee or rule) claims
     * more than it holds while the pool as a whole does not. A document claims
     * a line, not a pool: another member's unused capacity never pays for it.
     */
    public const REASON_MEMBER_OVER_CLAIMED = 'member_over_claimed';

    /** Mismatch kinds, against ACTIVE ISSUED documents only. */
    public const MISMATCH_TAX_RATE = 'tax_rate';
    public const MISMATCH_ITEM_IDENTITY = 'item_identity';
    public const MISMATCH_COMBINATION_IDENTITY = 'combination_identity';

    /**
     * The state of one pool, from two integer minor-unit figures.
     *
     * Exact by construction: both sides arrive as minor units, so there is no
     * comparison here that a tolerance could soften.
     *
     * @param int $capacity What the pool ever held.
     * @param int $consumed What documents have taken from it.
     *
     * @return string One of the STATE_* constants.
     *
     * @since  2.0.0
     */
    private static function state(int $capacity, int $consumed): string
    {
        if ($consumed > $capacity) {
            return self::STATE_OVER;
        }

        if ($consumed === 0) {
            return self::STATE_NONE;
        }

        return $consumed === $capacity ? self::STATE_FULL : self::STATE_PARTIAL;
    }

    /**
     * One pool's four authoritative figures.
     *
     * `remaining` is SIGNED on purpose: an over-claim is reported as the
     * negative it is rather than floored, because flooring it here would hide
     * the very condition this class exists to raise. The pools themselves clamp
     * for their own purposes (a charge cannot be claimed twice); this is the
     * reconciliation view, and it must be able to say "minus two".
     *
     * @param int $capacity Minor units the pool ever held.
     * @param int $consumed Minor units the semantic reads as documented
     *                      (sales: see documented()).
     * @param Currency $currency The order's frozen currency.
     *
     *
     * @since  2.0.0
     */
    private static function pool(int $capacity, int $consumed, Currency $currency): stdClass
    {
        $entry = new stdClass();
        $entry->capacity = Money::ofMinor($capacity, $currency)->getAmount();
        $entry->consumed = Money::ofMinor($consumed, $currency)->getAmount();
        $entry->remaining = Money::ofMinor($capacity - $consumed, $currency)->getAmount();
        $entry->state = self::state($capacity, $consumed);

        return $entry;
    }

    /**
     * What one sales SOURCE reads as documented once credits are counted.
     *
     * A source is the order line, parcel, contribution or cart rule a document
     * line names; the pool reads the sum over its sources. A credit counts
     * against the sales documentation only as far as it offsets an over-claim
     * on the SAME source. Contract section 8: current 30, active documented 40,
     * drift -10; after CN-001 of -10 the active documented is 30 and the drift
     * is 0, so the facts reconcile without anyone marking a proposal done.
     * Three regions, each with the figure it publishes as consumed:
     *
     *   capacity > gross                        positive drift. Reads gross, so
     *                                           PARTIAL (NONE when nothing was
     *                                           invoiced). A credit never
     *                                           refills it: a credited unit is
     *                                           not re-invoiced.
     *   gross - credited <= capacity <= gross   nothing to correct in the pool.
     *                                           Reads the capacity, so FULL
     *                                           (NONE when the order no longer
     *                                           holds the pool: an empty pool,
     *                                           which the roll-up ignores). A
     *                                           goodwill credit with no order
     *                                           change, or an order raised
     *                                           after a credit.
     *   capacity < gross - credited             negative drift. Reads the net,
     *                                           so OVER, and remaining is
     *                                           exactly the uncorrected part.
     *
     * The published figure is the net only when OVER.
     *
     * PER SOURCE, NOT PER POOL. Netting a whole pool let a credit
     * on one line or parcel offset an uncredited over-claim on another, so the
     * pool read FULL, the verdict cleared and the panel withdrew its correction
     * while the proposer still had a negative drift to offer. The proposer
     * reads per order line and per charge source; so does this. Two limits
     * stay: the drift here is in money while the proposer's item drift is in
     * quantity, and an over-claim on one source still sums against a gap on
     * another source of the same pool (neither is credited, so no rule about
     * credits can separate them).
     *
     * The credit coverage is not netted: a credit draws on what was invoiced.
     *
     * @param int $capacity Minor units the order currently holds on the source.
     * @param int $gross Minor units issued sales documents claimed from it.
     * @param int $credited Minor units issued credit documents gave back on it.
     *
     * @return int Minor units the source reads as documented.
     *
     * @since  2.0.0
     */
    private static function documented(int $capacity, int $gross, int $credited): int
    {
        return max($gross - $credited, min($gross, $capacity));
    }

    /**
     * One member of a sales pool: an order line, a parcel, a fee or a rule.
     *
     * The same four figures as a pool, from the same arithmetic, plus what the
     * member is: its pool, its source id (the order line for items, the
     * ledger source for charges; 0 for the net shipping member) and the name
     * its documents printed, so a surface can say which one disagrees.
     *
     * @param string $poolName The pool it belongs to.
     * @param int $source Its identity.
     * @param int $capacity Minor units the order holds on it.
     * @param int $documented Minor units the documents read on it.
     * @param string $label The name its documents printed, or ''.
     * @param Currency $currency The order's frozen currency.
     *
     *
     * @since  2.0.0
     */
    private static function member(string $poolName, int $source, int $capacity, int $documented, string $label, Currency $currency): stdClass
    {
        $entry = self::pool($capacity, $documented, $currency);
        $entry->pool = $poolName;
        $entry->source = $source;
        $entry->label = $label;

        return $entry;
    }

    /**
     * A sales discount's state, in MONEY terms like every other pool's.
     *
     * A discount takes money off, so its figures run the other way: the pool
     * holds the discount the order gives (capacity) and the discount its
     * documents give net of the goods credited with it (documented()).
     *
     *   documents give LESS  they over-charge. A defect (OVER) once every order
     *                        line is invoiced; before that, the discount still
     *                        due lands on the invoice of the goods still due,
     *                        exactly as the sales builder places it (PARTIAL,
     *                        or NONE with nothing given yet).
     *   documents give MORE  they under-charge: a gap to invoice (PARTIAL),
     *                        never a claim beyond what existed.
     *   equal                FULL, or NONE when there is no discount at all.
     *
     * A coupon added or raised after invoicing is
     * money drift that must not be invisible.
     *
     * @param int $capacity Minor units the order gives.
     * @param int $documented Minor units the documents give.
     * @param bool $goodsInvoiced Every order line fully invoiced.
     *
     *
     * @since  2.0.0
     */
    private static function discountState(int $capacity, int $documented, bool $goodsInvoiced): string
    {
        return match (true) {
            $documented === $capacity => $capacity === 0 ? self::STATE_NONE : self::STATE_FULL,
            $documented > $capacity => self::STATE_PARTIAL,
            $goodsInvoiced => self::STATE_OVER,
            $documented === 0 => self::STATE_NONE,
            default => self::STATE_PARTIAL,
        };
    }

    /** A pool that does not apply, stated rather than omitted. */
    private static function notApplicable(Currency $currency): stdClass
    {
        $entry = new stdClass();
        $zero = Money::zero($currency)->getAmount();
        $entry->capacity = $zero;
        $entry->consumed = $zero;
        $entry->remaining = $zero;
        $entry->state = self::STATE_NOT_APPLICABLE;

        return $entry;
    }

    /**
     * Roll a set of pools up into one semantic's answer.
     *
     * OVER wins over everything: one over-claimed pool makes the semantic
     * over-claimed, however healthy the others are. Otherwise the aggregate is
     * the honest summary of the parts: all untouched is NONE, all exhausted is
     * FULL, anything between is PARTIAL.
     *
     * @param stdClass[] $pools Pool entries.
     *
     *
     * @since  2.0.0
     */
    private static function rollUp(array $pools): string
    {
        // AN EMPTY POOL CARRIES NO OPINION. A pool that never held anything and
        // has had nothing taken from it is not "NONE" in any sense a reader
        // cares about; it is silent. Counting it dragged the aggregate down:
        // an order with no shipping, no fees and no discounts, invoiced in
        // full, rolled up to PARTIAL because three empty pools voted NONE
        // against one FULL one, and the panel then headlined a perfectly
        // documented order as "partially documented".
        $live = array_values(array_filter(
            $pools,
            static fn (stdClass $p): bool => $p->state !== self::STATE_NOT_APPLICABLE
                && !((float) $p->capacity === 0.0 && (float) $p->consumed === 0.0),
        ));

        if ($live === []) {
            return self::STATE_NOT_APPLICABLE;
        }

        return self::rollUpStates(array_map(static fn (stdClass $p): string => $p->state, $live));
    }

    /**
     * The same roll-up over bare states, for callers that hold no pools.
     *
     * The independent semantic aggregates ALIASES, not pools, and an alias has
     * already rolled its own pools up. It used to reach rollUp() through a shim
     * object carrying only ->state, which broke the moment rollUp() started
     * reading capacity: a notice per alias, caught by the placement suite that
     * watches for exactly that. Two callers, two shapes, one rule, stated once.
     *
     * @param string[] $states The states to reduce.
     *
     *
     * @since  2.0.0
     */
    private static function rollUpStates(array $states): string
    {
        $states = array_values(array_filter(
            $states,
            static fn (string $state): bool => $state !== self::STATE_NOT_APPLICABLE,
        ));

        if ($states === []) {
            return self::STATE_NOT_APPLICABLE;
        }

        if (\in_array(self::STATE_OVER, $states, true)) {
            return self::STATE_OVER;
        }

        if (array_unique($states) === [self::STATE_NONE]) {
            return self::STATE_NONE;
        }

        if (array_unique($states) === [self::STATE_FULL]) {
            return self::STATE_FULL;
        }

        return self::STATE_PARTIAL;
    }

    /**
     * THE PURE CORE. Everything above is loading; this is the projection.
     *
     * Takes rows a caller already holds and returns the facts, issuing no query
     * of its own: the same split as {@see OrderFacts::fromRows()}, and for the
     * same reason: a list page batch-loads once and calls this per row at zero
     * marginal cost.
     *
     * $rows keys, all optional and all plain arrays of stdClass rows:
     *   lines           #__alfa_order_items for this order
     *   shipments       active #__alfa_order_shipments, gross, net and rate
     *   contributions   #__alfa_order_contributions, with their tax_rate
     *   rules           #__alfa_order_cart_rule the order applies (not
     *                   soft-deleted), with their discount_class
     *   documents       #__alfa_documents for this order
     *   document_items  #__alfa_document_items of those documents, with
     *                   the name each printed
     *   refunds         settled refund rows keyed by the credit document
     *   wallet          credit-note wallet transactions keyed by document
     *   payment_facts   the OrderFacts object, carried not re-derived
     *
     * @param array $rows The order's already-loaded rows.
     * @param Currency $currency The order's currency at its frozen scale.
     *
     *
     * @since  2.0.0
     */
    public static function fromRows(array $rows, Currency $currency): stdClass
    {
        $minor = static fn (mixed $v): int => Money::of((float) $v, $currency)->getMinorUnits();

        $lines = $rows['lines'] ?? [];
        $documents = $rows['documents'] ?? [];
        $documentItems = $rows['document_items'] ?? [];

        // which documents count, and for which semantic
        // ISSUED only: a voided document releases everything it held, which is
        // why voiding is the correction of last resort rather than a deletion.
        $issued = array_values(array_filter(
            $documents,
            static fn (object $d): bool => (int) $d->status === DocumentHelper::STATUS_ISSUED,
        ));

        $poolOfDocument = [];

        foreach ($issued as $document) {
            $poolOfDocument[(int) $document->id] = DocumentHelper::poolOf((string) $document->doc_type);
        }

        // the capacity side, straight off the order
        // Per pool, and per source inside it (the order line, parcel,
        // contribution or rule a document line names), because the sales
        // coverage nets credits source by source; see documented().
        $capacity = [
            'items' => 0,
            'shipping' => 0,
            'fees' => 0,
            'discounts' => 0,
        ];
        $sourceCapacity = [];

        foreach ($lines as $line) {
            $amount = $minor($line->total_price_tax_incl ?? 0);
            $capacity['items'] += $amount;
            $sourceCapacity['items'][(int) $line->id] = ($sourceCapacity['items'][(int) $line->id] ?? 0) + $amount;
        }

        foreach ($rows['shipments'] ?? [] as $parcel) {
            $amount = $minor($parcel->shipping_cost_tax_incl ?? 0);
            $capacity['shipping'] += $amount;
            $sourceCapacity['shipping'][(int) $parcel->id] = ($sourceCapacity['shipping'][(int) $parcel->id] ?? 0) + $amount;
        }

        foreach ($rows['contributions'] ?? [] as $fee) {
            $amount = $minor($fee->amount_tax_incl ?? 0);
            $capacity['fees'] += $amount;
            $sourceCapacity['fees'][(int) $fee->id] = ($sourceCapacity['fees'][(int) $fee->id] ?? 0) + $amount;
        }

        // A SHIPPING-CLASS RULE IS NOT A DISCOUNT: the order takes it off the
        // shipping, never below zero (OrderTotalHelper), so the sales coverage
        // reads it there.
        $shippingRules = [];
        $shippingRuleValue = 0;

        foreach ($rows['rules'] ?? [] as $rule) {
            // A discount is stored as the value it takes off; the pool is its
            // magnitude, so the sign does not decide whether it is covered.
            $amount = abs($minor($rule->value_tax_incl ?? 0));
            $capacity['discounts'] += $amount;

            if ((string) ($rule->discount_class ?? 'order') === 'shipping') {
                $shippingRules[(int) $rule->id] = true;
                $shippingRuleValue += $amount;

                continue;
            }

            $sourceCapacity['discounts'][(int) $rule->id] = ($sourceCapacity['discounts'][(int) $rule->id] ?? 0) + $amount;
        }

        // WHAT THE SALES COVERAGE MEASURES, pool by pool: what the order
        // charges. Shipping is the parcels less what the shipping-class rules
        // take off them, clamped as the order clamps it; the discounts pool
        // is the other rules. The independent semantic keeps the order's
        // gross figures.
        $shippingOffset = min($shippingRuleValue, $capacity['shipping']);
        $salesCapacity = [
            'items' => $capacity['items'],
            'shipping' => $capacity['shipping'] - $shippingOffset,
            'fees' => $capacity['fees'],
            'discounts' => $capacity['discounts'] - $shippingRuleValue,
        ];

        // the consumed side, grouped by the pool each document draws on
        $consumed = [];
        $sourceConsumed = [];
        $sourceLabel = [];
        $shippingRuleClaims = [];
        $discountGiven = [];
        $discountTakenBack = [];
        $salesQuantity = [];

        foreach ($documentItems as $item) {
            $documentId = (int) $item->id_document;
            $pool = $poolOfDocument[$documentId] ?? null;

            if ($pool === null) {
                continue;                       // a voided or foreign document
            }

            $key = self::poolKeyOf((string) ($item->line_type ?? DocumentHelper::LINE_ITEM));
            $docType = self::docTypeOf($documentId, $issued);
            $bucket = $pool === DocumentHelper::POOL_INDEPENDENT ? $pool . ':' . $docType : $pool;
            $signed = $minor($item->total_tax_incl ?? 0);
            $amount = abs($signed);
            $source = (int) ($key === 'items' ? ($item->id_order_item ?? 0) : ($item->id_source ?? 0));

            // A DISCOUNT MOVES THREE WAYS (OrderChargeLines::discountLedger()):
            // a sales line gives it (a positive one, a reversal, takes it
            // back), a credit's positive line GRANTS more, a credit's negative
            // line takes back the share of goods it credited. Given and taken
            // back are kept apart, and a grant is no credit of invoiced money.
            if ($key === 'discounts' && !isset($shippingRules[$source])
                && ($pool === DocumentHelper::POOL_SALES || $pool === DocumentHelper::POOL_CREDIT)) {
                if ($pool === DocumentHelper::POOL_SALES) {
                    $discountGiven[$source] = ($discountGiven[$source] ?? 0) - $signed;
                    $consumed[$bucket][$key] = ($consumed[$bucket][$key] ?? 0) - $signed;
                } elseif ($signed > 0) {
                    $discountGiven[$source] = ($discountGiven[$source] ?? 0) + $signed;
                } else {
                    $discountTakenBack[$source] = ($discountTakenBack[$source] ?? 0) + $amount;
                    $consumed[$bucket][$key] = ($consumed[$bucket][$key] ?? 0) + $amount;
                }

                $sourceLabel[$key][$source] ??= (string) ($item->name ?? '');

                continue;
            }

            // Every order line fully invoiced is what lets a discount still
            // missing from the documents count as drift, not as discount the
            // invoice of the remaining goods will carry.
            if ($key === 'items' && $pool === DocumentHelper::POOL_SALES) {
                $salesQuantity[$source] = ($salesQuantity[$source] ?? 0.0) + (float) ($item->quantity ?? 0);
            }

            $consumed[$bucket][$key] = ($consumed[$bucket][$key] ?? 0) + $amount;

            if ($pool === DocumentHelper::POOL_SALES || $pool === DocumentHelper::POOL_CREDIT) {
                // An item line names its order line; a charge line names its
                // ledger source. A line naming neither nets against nothing.

                // A shipping-class rule's line reduces the shipping, and is
                // netted there as one aggregate.
                if ($key === 'discounts' && isset($shippingRules[$source])) {
                    $shippingRuleClaims[$pool] = ($shippingRuleClaims[$pool] ?? 0) + $amount;

                    continue;
                }

                $sourceConsumed[$pool][$key][$source] = ($sourceConsumed[$pool][$key][$source] ?? 0) + $amount;
                $sourceLabel[$key][$source] ??= (string) ($item->name ?? '');
            }
        }

        $facts = new stdClass();
        $facts->currency_scale = $currency->getDecimalPlaces();

        // HOW MANY ISSUED DOCUMENTS THIS ORDER HAS, stated rather than inferred.
        // A surface needs to tell "nothing has been documented yet" apart from
        // "documented and fully covered", and both read NONE on every pool. It
        // is a count of rows already loaded, not a new derivation.
        $facts->issued_documents = \count($issued);
        $facts->coverage = new stdClass();

        // SALES: what was ordered, against what sales documents issued
        // Net of the credits that corrected an over-claim on the same source,
        // and of nothing else; see documented().
        //
        // SHIPPING IS READ NET OF ITS SHIPPING-CLASS DISCOUNTS,
        // as the order charges it. Each parcel is netted on its own, then the
        // shipping-class adjustment lines come off as ONE aggregate source,
        // sized at what the rules take off the parcels now. One aggregate on
        // purpose: stacked rules split a claim differently depending on row
        // order, their sum does not. Read gross beside the rule's frozen value,
        // a shipping cut the free-shipping discount absorbs whole stayed OVER
        // for ever, while the order total had not moved and the proposer
        // rightly had nothing to issue.
        //
        // PER IDENTITY FIRST. A document claims an
        // order line, a parcel, a fee or a rule (never a pool), so each of
        // them is a MEMBER stated on its own, and the pool is only their sum.
        // An over-claim on one member is a defect whatever the others hold:
        // line A documented one unit too many and line B one unit short sum to
        // a pool that balances, and neither fact went away. The pool keeps its
        // aggregate figures; `members` states every member, `anomalies` the
        // ones that over-claim. Shipping under a shipping-class discount is
        // ONE member, net: the discount is the order's, stored per rule and
        // never per parcel, so no smaller identity owns that money.
        $salesPools = [];
        $netShipping = $shippingOffset > 0 || ($shippingRuleClaims !== []);
        $goodsInvoiced = true;

        foreach ($lines as $line) {
            if (($salesQuantity[(int) $line->id] ?? 0.0) < (float) ($line->quantity ?? 0)) {
                $goodsInvoiced = false;

                break;
            }
        }

        foreach (self::POOLS as $poolName) {
            $sources = array_unique(array_merge(
                array_keys($sourceCapacity[$poolName] ?? []),
                array_keys($sourceConsumed[DocumentHelper::POOL_SALES][$poolName] ?? []),
                array_keys($sourceConsumed[DocumentHelper::POOL_CREDIT][$poolName] ?? []),
            ));
            sort($sources);
            $documented = 0;
            $members = [];

            if ($poolName === 'discounts') {
                $sources = array_unique(array_merge($sources, array_keys($discountGiven), array_keys($discountTakenBack)));
                sort($sources);
            }

            foreach ($sources as $source) {
                $capacityOf = $sourceCapacity[$poolName][$source] ?? 0;
                $documentedOf = $poolName === 'discounts'
                    ? self::documented(
                        capacity: $capacityOf,
                        gross: $discountGiven[$source] ?? 0,
                        credited: $discountTakenBack[$source] ?? 0,
                    )
                    : self::documented(
                        capacity: $capacityOf,
                        gross: $sourceConsumed[DocumentHelper::POOL_SALES][$poolName][$source] ?? 0,
                        credited: $sourceConsumed[DocumentHelper::POOL_CREDIT][$poolName][$source] ?? 0,
                    );
                $documented += $documentedOf;
                $member = self::member($poolName, $source, $capacityOf, $documentedOf, $sourceLabel[$poolName][$source] ?? '', $currency);

                if ($poolName === 'discounts') {
                    $member->state = self::discountState($capacityOf, $documentedOf, $goodsInvoiced);
                }

                $members[] = $member;
            }

            if ($poolName === 'shipping') {
                $documented -= self::documented(
                    capacity: $shippingOffset,
                    gross: $shippingRuleClaims[DocumentHelper::POOL_SALES] ?? 0,
                    credited: $shippingRuleClaims[DocumentHelper::POOL_CREDIT] ?? 0,
                );

                if ($netShipping) {
                    $members = [self::member($poolName, 0, $salesCapacity[$poolName], $documented, '', $currency)];
                }
            }

            $entry = self::pool($salesCapacity[$poolName], $documented, $currency);

            if ($poolName === 'discounts') {
                $entry->state = self::discountState($salesCapacity[$poolName], $documented, $goodsInvoiced);
            }

            $entry->members = $members;
            $entry->anomalies = array_values(array_filter(
                $members,
                static fn (stdClass $member): bool => $member->state === self::STATE_OVER,
            ));
            $salesPools[$poolName] = $entry;
        }

        $facts->coverage->sales = self::semantic(true, $salesPools);

        // CREDIT: what sales documents issued, against what credits took
        // The capacity is NOT the order: you cannot credit what was never
        // invoiced, so an uninvoiced order has nothing creditable and reads
        // NONE with a capacity of zero rather than looking half-credited.
        $creditPools = [];

        foreach (self::POOLS as $poolName) {
            $creditPools[$poolName] = self::pool(
                $consumed[DocumentHelper::POOL_SALES][$poolName] ?? 0,
                $consumed[DocumentHelper::POOL_CREDIT][$poolName] ?? 0,
                $currency,
            );
        }

        $facts->coverage->credit = self::semantic(true, $creditPools);

        // INDEPENDENT: one pool per alias, because that is what it means
        $facts->coverage->independent = new stdClass();
        $facts->coverage->independent->applicable = false;
        $facts->coverage->independent->aliases = new stdClass();

        foreach (DocumentHelper::aliasesByPool(pool: DocumentHelper::POOL_INDEPENDENT) as $alias) {
            $aliasPools = [];

            foreach (self::POOLS as $poolName) {
                $aliasPools[$poolName] = self::pool(
                    $capacity[$poolName],
                    $consumed[DocumentHelper::POOL_INDEPENDENT . ':' . $alias][$poolName] ?? 0,
                    $currency,
                );
            }

            $facts->coverage->independent->aliases->{$alias} = self::semantic(true, $aliasPools);
            $facts->coverage->independent->applicable = true;
        }

        $facts->coverage->independent->state = $facts->coverage->independent->applicable
            ? self::rollUpStates(array_map(
                static fn (stdClass $alias): string => $alias->state,
                array_values((array) $facts->coverage->independent->aliases),
            ))
            : self::STATE_NOT_APPLICABLE;

        // NONE: a real answer, stated so nobody reads its absence as a gap
        $facts->coverage->uncounted = new stdClass();
        $facts->coverage->uncounted->applicable = false;
        $facts->coverage->uncounted->state = self::STATE_NOT_APPLICABLE;
        $facts->coverage->uncounted->aliases = DocumentHelper::aliasesByPool(pool: DocumentHelper::POOL_NONE);

        // Semantic mismatch
        // Money can reconcile perfectly while a document still states
        // something the order now contradicts: a tax rate that was changed
        // after invoicing, on a line or on a charge, a line repointed at
        // another item. These are NOT money and must not be folded into a
        // coverage figure, because the sum would be right and the document
        // would still be wrong.
        //
        // ACTIVE ISSUED documents only. A void has no claim on the order and a
        // draft has not said anything yet.
        $linesById = [];

        foreach ($lines as $line) {
            $linesById[(int) $line->id] = $line;
        }

        $chargeSources = [DocumentHelper::LINE_SHIPPING => [], DocumentHelper::LINE_FEE => []];

        foreach ($rows['shipments'] ?? [] as $parcel) {
            $chargeSources[DocumentHelper::LINE_SHIPPING][(int) $parcel->id] = $parcel;
        }

        foreach ($rows['contributions'] ?? [] as $fee) {
            $chargeSources[DocumentHelper::LINE_FEE][(int) $fee->id] = $fee;
        }

        $facts->mismatches = [];

        foreach ($documentItems as $item) {
            $documentId = (int) $item->id_document;

            if (!isset($poolOfDocument[$documentId])) {
                continue;
            }

            $lineType = (string) ($item->line_type ?? DocumentHelper::LINE_ITEM);

            // A charge names no order line; it is compared with its own source
            // instead, on the SALES documents that froze its rate (a credit
            // copies the invoice's). A source the order no longer carries is
            // money drift, not this.
            if (isset($chargeSources[$lineType])) {
                $source = $chargeSources[$lineType][(int) ($item->id_source ?? 0)] ?? null;
                $charge = $source !== null && $poolOfDocument[$documentId] === DocumentHelper::POOL_SALES
                    ? self::chargeRateMismatch($item, $source)
                    : null;

                if ($charge !== null) {
                    $facts->mismatches[] = $charge;
                }

                continue;
            }

            $lineId = (int) ($item->id_order_item ?? 0);
            $line = $linesById[$lineId] ?? null;

            if ($line === null) {
                continue;                        // an adjustment names no order line
            }

            // Compared as the stored decimals, not as floats: tax_rate is
            // decimal(10,3) on both sides and a string compare of the
            // normalised figure cannot be defeated by binary representation.
            $documentRate = number_format((float) ($item->tax_rate ?? 0), 3, '.', '');
            $orderRate = number_format((float) ($line->tax_rate ?? 0), 3, '.', '');

            if ($documentRate !== $orderRate) {
                $facts->mismatches[] = self::mismatch(
                    self::MISMATCH_TAX_RATE,
                    $documentId,
                    $item,
                    $orderRate,
                    $documentRate,
                );
            }

            // id_item / id_combination are snapshots: they are SUPPOSED to be
            // frozen, so a difference means the ORDER moved underneath an
            // issued document, which is the defect. A NULL on the document is
            // a pre-snapshot row and states nothing, so it cannot disagree.
            if ($item->id_item !== null && (int) $item->id_item !== (int) ($line->id_item ?? 0)) {
                $facts->mismatches[] = self::mismatch(
                    self::MISMATCH_ITEM_IDENTITY,
                    $documentId,
                    $item,
                    (string) (int) ($line->id_item ?? 0),
                    (string) (int) $item->id_item,
                );
            }

            $documentCombination = $item->id_combination === null ? null : (int) $item->id_combination;
            $orderCombination = ($line->id_combination ?? null) === null ? null : (int) $line->id_combination;

            if ($documentCombination !== null && $documentCombination !== $orderCombination) {
                $facts->mismatches[] = self::mismatch(
                    self::MISMATCH_COMBINATION_IDENTITY,
                    $documentId,
                    $item,
                    $orderCombination === null ? '' : (string) $orderCombination,
                    (string) $documentCombination,
                );
            }
        }

        // Credit-note settlement: a separate axis, and it never alarms
        //
        // OWNERSHIP, stated because double counting is the whole hazard:
        //
        //   the REFUND ledger owns rows of #__alfa_order_payments whose
        //   payment_type is 'refund' and whose id_document names this note;
        //   the WALLET ledger owns rows of #__alfa_customer_credit_transactions
        //   whose source_type is 'credit_note' and whose source_id names it.
        //
        // The two cannot overlap. A payment row carrying id_credit_transaction
        // is a wallet SPEND (money the customer paid an order WITH), and its
        // type is 'payment', never 'refund'. So no settlement can be counted
        // twice, and the loader asserts that rather than trusting it.
        $refunds = $rows['refunds'] ?? [];
        $wallet = $rows['wallet'] ?? [];

        $facts->credit_settlement = new stdClass();
        $facts->credit_settlement->documents = new stdClass();

        $creditTotal = 0;
        $settledTotal = 0;

        foreach ($issued as $document) {
            if (($poolOfDocument[(int) $document->id] ?? '') !== DocumentHelper::POOL_CREDIT) {
                continue;
            }

            $documentId = (int) $document->id;

            // The NOTE'S OWN frozen scale, not the order's: an issued document
            // means what it meant when it was issued.
            $noteCurrency = $currency;
            $noteScale = $document->currency_scale ?? null;

            if ($noteScale !== null && $noteScale !== '') {
                try {
                    $noteCurrency = $currency->atScale((int) $noteScale);
                } catch (Throwable $e) {
                    $noteCurrency = $currency;
                }
            }

            $noteMinor = static fn (mixed $v): int => Money::of((float) $v, $noteCurrency)->getMinorUnits();

            $credit = abs($noteMinor($document->total_tax_incl ?? 0));
            $refunded = abs($noteMinor($refunds[$documentId] ?? 0));
            $credited = abs($noteMinor($wallet[$documentId] ?? 0));
            $settled = $refunded + $credited;

            $entry = new stdClass();
            $entry->credit_amount = Money::ofMinor($credit, $noteCurrency)->getAmount();
            $entry->refunded = Money::ofMinor($refunded, $noteCurrency)->getAmount();
            $entry->wallet = Money::ofMinor($credited, $noteCurrency)->getAmount();
            $entry->total_settled = Money::ofMinor($settled, $noteCurrency)->getAmount();
            $entry->remaining_unsettled = Money::ofMinor($credit - $settled, $noteCurrency)->getAmount();
            $entry->state = self::state($credit, $settled);
            $entry->currency_scale = $noteCurrency->getDecimalPlaces();

            $facts->credit_settlement->documents->{$documentId} = $entry;

            $creditTotal += $credit;
            $settledTotal += $settled;
        }

        $facts->credit_settlement->credit_amount = Money::ofMinor($creditTotal, $currency)->getAmount();
        $facts->credit_settlement->total_settled = Money::ofMinor($settledTotal, $currency)->getAmount();
        $facts->credit_settlement->remaining_unsettled = Money::ofMinor($creditTotal - $settledTotal, $currency)->getAmount();
        $facts->credit_settlement->state = $creditTotal === 0 && $settledTotal === 0
            ? self::STATE_NOT_APPLICABLE
            : self::state($creditTotal, $settledTotal);

        // The verdict, and only defects reach it
        $facts->reconciliation_reasons = [];

        foreach (['sales' => $salesPools, 'credit' => $creditPools] as $semantic => $pools) {
            foreach ($pools as $poolName => $entry) {
                if ($entry->state === self::STATE_OVER) {
                    $facts->reconciliation_reasons[] = self::REASON_OVER_CLAIMED . ':' . $semantic . '.' . $poolName;
                }

                // Distinct from OVER on purpose. OVER is "more was claimed than
                // existed"; a negative remaining is the arithmetic proof of it,
                // and a future pool could produce one without the state test
                // agreeing. Both are reported so neither can hide the other.
                // A sales DISCOUNT pool below zero is discount documents gave
                // beyond what the order gives: money they under-charge, a gap
                // to invoice, not a claim beyond what existed (discountState()).
                if ((float) $entry->remaining < 0.0 && !($semantic === 'sales' && $poolName === 'discounts')) {
                    $facts->reconciliation_reasons[] = self::REASON_NEGATIVE_REMAINING . ':' . $semantic . '.' . $poolName;
                }

                // A member's over-claim the pool total hides. Stated only when
                // the pool does not already read OVER, where the pool reason
                // says it; the member detail is on the pool either way.
                if ($entry->state !== self::STATE_OVER && ($entry->anomalies ?? []) !== []) {
                    $facts->reconciliation_reasons[] = self::REASON_MEMBER_OVER_CLAIMED . ':' . $semantic . '.' . $poolName;
                }
            }
        }

        foreach ($facts->mismatches as $mismatch) {
            $facts->reconciliation_reasons[] = self::REASON_SEMANTIC_MISMATCH . ':' . $mismatch->kind;
        }

        $facts->reconciliation_reasons = array_values(array_unique($facts->reconciliation_reasons));
        $facts->needs_reconciliation = $facts->reconciliation_reasons !== [];

        // The payment axis, carried and never re-derived
        $paymentFacts = $rows['payment_facts'] ?? null;
        $facts->overpaid = (bool) ($paymentFacts->overpaid ?? false);
        $facts->excess = (float) ($paymentFacts->excess ?? Money::zero($currency)->getAmount());

        // THE SIGNED BALANCE, CARRIED TOO, and it is not redundant beside
        // `overpaid`. "Not overpaid" covers TWO different orders: one settled
        // to the penny, and one that has not been paid at all. A surface given
        // only the boolean cannot tell them apart, and the panel duly told an
        // operator that the shop held exactly what an entirely unpaid order
        // asked for. Carried, never re-derived.
        $facts->balance = (float) ($paymentFacts->balance ?? Money::zero($currency)->getAmount());

        return $facts;
    }

    /** One semantic's pools plus its rolled-up state. */
    private static function semantic(bool $applicable, array $pools): stdClass
    {
        $entry = new stdClass();
        $entry->applicable = $applicable;
        $entry->pools = (object) $pools;
        $entry->state = self::rollUp($pools);

        return $entry;
    }

    /** A document line's pool key, from its line_type. */
    private static function poolKeyOf(string $lineType): string
    {
        return match ($lineType) {
            DocumentHelper::LINE_SHIPPING => 'shipping',
            DocumentHelper::LINE_FEE => 'fees',
            DocumentHelper::LINE_ADJUSTMENT => 'discounts',
            default => 'items',
        };
    }

    /** The doc_type of an issued document, by id. */
    private static function docTypeOf(int $documentId, array $issued): string
    {
        foreach ($issued as $document) {
            if ((int) $document->id === $documentId) {
                return (string) $document->doc_type;
            }
        }

        return '';
    }

    /**
     * A sales charge line whose frozen tax rate its source now contradicts, or null.
     *
     * section 17: a changed rate is semantic drift, shown as such and never cleared
     * with money. A fee and a parcel each store the rate they were charged at,
     * compared as the stored decimals exactly as an item line's is: a parcel
     * re-rated after its invoice disagrees with it, whatever its amounts.
     *
     * @param object $item The issued sales document's charge line.
     * @param object $source The parcel or fee the order still carries.
     *
     * @return stdClass|null A MISMATCH_TAX_RATE entry, or null when consistent.
     *
     * @since  2.0.0
     */
    private static function chargeRateMismatch(object $item, object $source): ?stdClass
    {
        $documentRate = number_format((float) ($item->tax_rate ?? 0), 3, '.', '');
        $orderRate = number_format(
            (float) ((string) $item->line_type === DocumentHelper::LINE_FEE ? ($source->tax_rate ?? 0) : ($source->shipping_tax_rate ?? 0)),
            3,
            '.',
            '',
        );

        return $orderRate === $documentRate
            ? null
            : self::mismatch(self::MISMATCH_TAX_RATE, (int) $item->id_document, $item, $orderRate, $documentRate);
    }

    /**
     * One mismatch, in the shape every surface reads.
     *
     * An item line is named by its order line (id_order_item), a charge line
     * by its type and ledger source (id_source), and both by the label the
     * document printed, so a surface can say which line disagrees without a
     * lookup of its own.
     *
     * @param string $kind One of the MISMATCH_* constants.
     * @param int $documentId The issued document.
     * @param object $item The document line that disagrees.
     * @param string $order What the order states.
     * @param string $document What the document states.
     *
     *
     * @since  2.0.0
     */
    private static function mismatch(string $kind, int $documentId, object $item, string $order, string $document): stdClass
    {
        $lineType = (string) ($item->line_type ?? DocumentHelper::LINE_ITEM);
        $isItem = $lineType === DocumentHelper::LINE_ITEM;

        $entry = new stdClass();
        $entry->kind = $kind;
        $entry->id_document = $documentId;
        $entry->id_order_item = $isItem ? (int) ($item->id_order_item ?? 0) : null;
        $entry->line_type = $lineType;
        $entry->id_source = $isItem ? null : (int) ($item->id_source ?? 0);
        $entry->label = (string) ($item->name ?? '');
        $entry->order_states = $order;
        $entry->document_states = $document;

        return $entry;
    }

    /**
     * THE BATCH LOADER. A whole list page in a fixed number of statements.
     *
     * TEN QUERIES, AND TEN IS TEN WHETHER THE PAGE SHOWS ONE ORDER OR FIVE
     * HUNDRED. That is the entire performance contract of this class, and it is
     * why {@see self::forOrder()} is written in terms of THIS and not the other
     * way round: a list that called forOrder() per row would issue ten
     * statements per row, which is the shape this component has removed
     * everywhere else.
     *
     * @param int[] $orderIds Orders to project.
     *
     * @return array<int, stdClass> Facts keyed by order id.
     *
     * @since  2.0.0
     */
    public static function forOrders(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));

        if ($orderIds === []) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $list = implode(',', $orderIds);

        // [1] the orders, for their frozen currency identity
        $orders = $db->setQuery(
            "SELECT id, id_currency, currency_scale FROM `#__alfa_orders` WHERE id IN ({$list})",
        )->loadObjectList('id') ?: [];

        // [2..5] the four capacity sides
        $lines = self::groupByOrder($db->setQuery(
            "SELECT id, id_order, id_item, id_combination, quantity, tax_rate,
                    total_price_tax_incl, total_price_tax_excl
               FROM `#__alfa_order_items` WHERE id_order IN ({$list})",
        )->loadObjectList() ?: []);

        // The SAME population rule the totals and the charge pools use: a
        // cancelled or returned parcel is not a charge the order still carries.
        $shipments = self::groupByOrder($db->setQuery(
            "SELECT id, id_order, shipping_cost_tax_incl, shipping_cost_tax_excl, shipping_tax_rate
               FROM `#__alfa_order_shipments`
              WHERE id_order IN ({$list}) AND status NOT IN ('cancelled', 'returned')",
        )->loadObjectList() ?: []);

        $contributions = self::groupByOrder($db->setQuery(
            "SELECT id, id_order, tax_rate, amount_tax_incl, amount_tax_excl
               FROM `#__alfa_order_contributions` WHERE id_order IN ({$list})",
        )->loadObjectList() ?: []);

        // The rules the order applies, as the totals and the charge pools read
        // them: a soft-deleted rule is not one, and a shipping-class rule
        // reduces the shipping.
        $rules = self::groupByOrder($db->setQuery(
            "SELECT id_order_cart_rule AS id, id_order, value_tax_incl, value_tax_excl, discount_class
               FROM `#__alfa_order_cart_rule` WHERE id_order IN ({$list}) AND deleted = 0",
        )->loadObjectList() ?: []);

        // [6] the documents
        $documentRows = $db->setQuery(
            "SELECT id, id_order, doc_type, status, id_corrects, currency_scale, total_tax_incl
               FROM `#__alfa_documents` WHERE id_order IN ({$list})",
        )->loadObjectList() ?: [];

        $documents = self::groupByOrder($documentRows);
        $documentIds = array_map(static fn (object $d): int => (int) $d->id, $documentRows);

        // [7] their lines, and [8..9] the two settlement ledgers
        $documentItems = [];
        $refunds = [];
        $wallet = [];

        if ($documentIds !== []) {
            $docList = implode(',', $documentIds);
            $ownerOfDocument = [];

            foreach ($documentRows as $document) {
                $ownerOfDocument[(int) $document->id] = (int) $document->id_order;
            }

            foreach ($db->setQuery(
                "SELECT id_document, id_order_item, id_item, id_combination, line_type,
                        id_source, name, quantity, tax_rate, total_tax_incl, total_tax_excl
                   FROM `#__alfa_document_items` WHERE id_document IN ({$docList})",
            )->loadObjectList() ?: [] as $item) {
                $documentItems[$ownerOfDocument[(int) $item->id_document] ?? 0][] = $item;
            }

            // THE REFUND LEDGER'S SHARE, from the ledger's own definition: net
            // of reversals, and bounded by id_credit_transaction IS NULL (a row
            // that carries one is a wallet SPEND and belongs to the other
            // ledger's story, so counting it here would settle the same money twice).
            $refunds = PaymentLedger::settledRefundsForDocuments($documentIds);

            foreach ($db->setQuery(
                "SELECT source_id, COALESCE(SUM(amount), 0) AS settled
                   FROM `#__alfa_customer_credit_transactions`
                  WHERE source_id IN ({$docList})
                    AND source_type = " . $db->quote('credit_note') . '
                  GROUP BY source_id',
            )->loadObjectList() ?: [] as $row) {
                $wallet[(int) $row->source_id] = (float) $row->settled;
            }
        }

        // [10] the payment rows, for the axis this class carries but never owns
        $payments = PaymentLedger::getByOrders($orderIds);

        $facts = [];

        // ONE RESOLUTION PER DISTINCT CURRENCY, not per order. Currency::loadById()
        // does not remember a FAILED lookup, so an order pointing at a deleted
        // currency would otherwise re-query (and re-fail) once per row, which
        // is precisely the per-row cost this loader exists to remove. Measured:
        // sixteen orders on an unresolvable currency cost sixteen extra
        // statements before this memo, and the constant-count arm caught it.
        // false = the pair names no currency, remembered like any other answer.
        $currencies = [];

        foreach ($orderIds as $orderId) {
            $order = $orders[$orderId] ?? null;

            // No row: there is no order to project.
            if ($order === null) {
                continue;
            }

            $key = (int) ($order->id_currency ?? 0) . ':' . (string) ($order->currency_scale ?? '');

            $currencies[$key] ??= OrderCurrency::findRow(
                orderId:    $orderId,
                currencyId: $order->id_currency ?? null,
                scale:      $order->currency_scale ?? null,
            ) ?? false;

            $currency = $currencies[$key];

            // A page is not refused for one order. An order that names no
            // currency has no money to project: it gets no facts, and the
            // surface that lists it says so in their place.
            if ($currency === false) {
                continue;
            }

            // The order total from rows ALREADY IN HAND: the same arithmetic
            // the list uses, so this costs nothing and cannot disagree with it.
            $totals = OrderTotalHelper::computeFromArrays(
                $lines[$orderId] ?? [],
                $shipments[$orderId] ?? [],
                $rules[$orderId] ?? [],
                $contributions[$orderId] ?? [],
            );

            $facts[$orderId] = self::fromRows([
                'lines' => $lines[$orderId] ?? [],
                'shipments' => $shipments[$orderId] ?? [],
                'contributions' => $contributions[$orderId] ?? [],
                'rules' => $rules[$orderId] ?? [],
                'documents' => $documents[$orderId] ?? [],
                'document_items' => $documentItems[$orderId] ?? [],
                'refunds' => $refunds,
                'wallet' => $wallet,
                'payment_facts' => OrderFacts::fromRows(
                    $totals->grand_total_tax_incl,
                    $payments[$orderId] ?? [],
                    $currency,
                ),
            ], $currency);
        }

        return $facts;
    }

    /**
     * One order's facts.
     *
     * Written in terms of the batch loader so there is ONE loading path. A
     * second one would be a second definition of what this class reads.
     *
     * @param int $orderId Order PK.
     *
     *
     * @throws OrderCurrencyException When the order names no currency.
     *
     * @since  2.0.0
     */
    public static function forOrder(int $orderId): stdClass
    {
        $facts = self::forOrders([$orderId])[$orderId] ?? null;

        if ($facts !== null) {
            return $facts;
        }

        // No order yet (the new-order form): the empty figures of an order
        // about to be created. An order that exists and has no facts names no
        // currency, and the reader says so.
        return self::fromRows(
            [],
            $orderId > 0 ? OrderCurrency::of(orderId: $orderId) : OrderCurrency::forNewOrder(),
        );
    }

    /** Rows grouped by their id_order, the shape fromRows() takes. */
    private static function groupByOrder(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row->id_order][] = $row;
        }

        return $grouped;
    }
}
