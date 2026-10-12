<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * @package    Alfa Commerce
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

use InvalidArgumentException;
use Joomla\CMS\Language\Text;

/**
 * Price Computation Engine
 *
 * Calculates product prices with:
 * - Tiered pricing based on quantity
 * - Multiple discounts (before/after tax)
 * - Multiple taxes (combined/sequential)
 * - Full price breakdown
 *
 * All calculations use Money objects for:
 * - Proper currency handling
 * - Accurate decimal arithmetic
 * - Type safety
 * @since  1.0.0
 */
class PriceComputationEngine
{
    /**
     * The number of decimal places a catalogue unit carries.
     *
     * A price row's value, a sale's value, a fixed discount amount, a variant step
     * amount and every unit derived from them are decimals of at most this many
     * places: the precision the catalogue price columns store.
     *
     * @since  1.14.0
     */
    public const UNIT_PLACES = 8;

    /**
     * How many of the smallest catalogue unit make one: 10 to the power of {@see self::UNIT_PLACES}.
     *
     * @since  1.14.0
     */
    private const UNIT_COUNT = 100000000;

    /**
     * The denominator of a percentage applied to a unit count: a percent is itself a
     * count of the smallest unit, and one percent is a hundredth.
     *
     * @since  1.14.0
     */
    private const PERCENT_BASE = 10000000000;

    /**
     * The decimal places of {@see self::PERCENT_BASE}: an amount times a percent
     * count is that count of this place.
     *
     * @since  1.14.0
     */
    private const PERCENT_PLACES = 10;

    /**
     * How many ratios {@see self::$rateRatios} keeps before it starts again.
     *
     * @since  1.14.0
     */
    private const RATIO_STORE = 512;

    /**
     * The exact ratio of each rate and percentage already asked for, keyed by its
     * value. A ratio never changes, so it is worked out once.
     *
     * @var array<string, array{0: int, 1: int}>
     *
     * @since  1.14.0
     */
    private static array $rateRatios = [];

    /**
     * Compute final price with all calculations
     *
     * ONE EXTENSION RULE. Every stored unit is multiplied by the quantity before anything is
     * rounded, and nothing inside the line is rounded at the currency's scale: the extension,
     * every discount before tax, each tax charged on the exact running amount, the gross and
     * every discount after tax are carried exactly ({@see ExactAmount}). The line is rounded at
     * its Money boundary only, and once: every gross it records (the base's, the line's and the
     * final one) is its exact value rounded once at the currency's scale, and its net and each
     * rate's tax are the ONE canonical split of that gross ({@see self::deriveFromGross()}). So a
     * net-stated base of 9.95161290 at 24% is 37.019999988 for three, 37.02 = 29.85 + 7.17, and
     * never the 29.85 + 7.16 a rounded extension would charge; 25.338 x 100 is 2533.80, and
     * 0.004 x 1000 is 4.00. A gross-stated unit (a sale typed as a final price, a variant's
     * gross target, an explicit custom rate) states the line gross itself. A fixed discount is
     * the amount x quantity.
     *
     * The arithmetic is exact throughout. A unit is a decimal of at most eight places, read once
     * by {@see self::catalogueUnit()} and never held as a float; a tax rate and a percentage are
     * exact ratios; and the net of a gross is that gross divided by the chain's exact factor. No
     * figure is the rounding of a float product, so a line is the same on every PHP version.
     *
     * @param int $productId Product identifier
     * @param int $quantity Quantity ordered
     * @param array $prices Array of price tier objects from PriceDataLoader
     * @param array $discounts Array of discount objects from PriceDataLoader
     * @param array $taxes Array of tax objects from PriceDataLoader
     * @param PriceContext $context Pricing context (currency, customer, location)
     * @param ExplicitPriceInstruction|null $price An operator's explicit price for THIS line, applied
     *                                             AFTER everything the catalogue decided; null is ordinary pricing
     * @param string|null $grossTarget The unit gross a variant's last gross-target adjustment produced, as its
     *                                 exact decimal text (a float cannot carry eight decimals of a large unit);
     *                                 null when none.
     *
     * @return PriceResult Complete price calculation result
     * @throws InvalidArgumentException When the gross target is not a plain decimal or is negative, the row is a
     *                                  sale pinned to the gross, a stored price is not a number, or a line total
     *                                  does not fit in minor units.
     * @since  1.0.0
     */
    public function compute(
        int $productId,
        int $quantity,
        array $prices,
        array $discounts,
        array $taxes,
        PriceContext $context,
        // LAST, AND THAT IS A CONSTRAINT RATHER THAN A STYLE CHOICE. One
        // production caller reaches this method WITHOUT the facade:
        // AlfapricesField builds the engine itself and calls compute() with
        // NAMED arguments, so a parameter appended here is invisible to it,
        // while a parameter inserted anywhere earlier would bind
        // PriceCalculator's five POSITIONAL compute() calls to the wrong slots
        // and that named-argument caller would keep working and show nothing.
        // Both late parameters are appended, in this order, for that reason.
        ?ExplicitPriceInstruction $price = null,
        ?string $grossTarget = null,
    ): PriceResult {
        $currency = $this->getCurrency($context);
        $breakdown = new PriceBreakdown();

        // Taxes are processed up front: an override pinned to the FINAL
        // price needs the exact factor the tax loop below will apply.
        $appliedTaxes = $this->processTaxes($taxes, $currency);
        $taxLoopFactor = 1.0;

        foreach ($appliedTaxes as $tax) {
            $taxLoopFactor *= (1 + $tax->rate / 100);
        }

        // The chain's rates, in order, and whether a gross can be split back
        // through it: a chain whose factor is not above zero (a rate at or
        // below -100%) cannot be.
        $rates = array_column($appliedTaxes, 'rate');
        $splittable = $appliedTaxes !== [] && $taxLoopFactor > 0.0;

        // 1. The line, exactly. Every unit is read once, exactly, and extended
        // by the quantity; nothing is rounded until a figure is recorded.
        //
        // A row with override_value set is a SALE price: it replaces every
        // regular discount with ONE custom discount that lands the line on the
        // sale. override_type says which end it states: 'start' is the net the
        // taxes apply to, 'final' is the gross the customer pays. Representing
        // it AS a discount keeps the frontend display logic intact:
        // strikethrough base, -amount, savings percent.
        $baseRow = $this->selectBaseRow($prices, $quantity, $context->groupDistances());
        $isOverride = $baseRow !== null
            && isset($baseRow->override_value)
            && $baseRow->override_value !== null
            && $baseRow->override_value !== '';
        $listUnit = $baseRow === null ? '0' : self::catalogueUnit(value: $baseRow->value);
        $listLine = ExactAmount::of($listUnit)->times($quantity);

        // The unit the line is stated by, on its own axis: this row's, until a
        // sale, a gross target or an explicit rate below states the line.
        $authoritativeUnit = $listUnit;

        // Where that unit comes from, for presentation only: a price row's
        // stored value, a sale's own price, or a unit computed for a variant
        // (a row the calculator marks as computed, or a gross target below).
        $unitOrigin = !empty($baseRow?->computed) ? UnitOrigin::Computed : ($isOverride ? UnitOrigin::Sale : UnitOrigin::PriceRow);

        // The exact gross a sale typed as a final price, or a variant's gross
        // target, states for the line; and the exact net a sale on the net states.
        $pinnedLine = null;
        $saleLine = null;
        $saleBelowList = false;
        $salePercent = 0.0;

        if ($isOverride) {
            // A sale price is consideration, not a credit: a sale stored below
            // zero is a sale of zero, before either reading of override_type.
            $saleUnit = self::catalogueUnit(value: $baseRow->override_value);
            $saleUnit = $saleUnit[0] === '-' ? '0' : $saleUnit;
            $authoritativeUnit = $saleUnit;

            // The exact factors of the chain a sale typed as a final price carries.
            $saleFactors = [];

            if (($baseRow->override_type ?? 'start') === 'final' && $taxLoopFactor > 0) {
                $pinnedLine = ExactAmount::of($saleUnit)->times($quantity);
                $saleFactors = self::chainFactors(rates: $rates);
            } else {
                $saleLine = ExactAmount::of($saleUnit)->times($quantity);
            }

            // Whether the sale is a reduction of its list is decided on the two
            // units themselves, exactly. A final price that equals its list with
            // tax is no reduction, whatever a float makes of the division; the
            // two lines may then round a minor unit apart, and that difference
            // is not a saving anybody was given.
            if (self::saleBelowList(sale: $saleUnit, list: $listUnit, factors: $saleFactors)) {
                $saleBelowList = true;
                $salePercent = self::salePercent(sale: $saleUnit, list: $listUnit, factors: $saleFactors);
            }
        }

        // 2. The regular discounts. A sale price replaces them all. A fixed
        // rule's exact line amount travels beside it, under the same key.
        $exactAmounts = [];
        $appliedDiscounts = $isOverride ? [] : $this->processDiscounts($discounts, $currency, $quantity, $exactAmounts);

        // A variant's gross target states the line gross like a sale typed as a
        // final price: not after a regular before-tax discount, which would be
        // taken from a net the gross no longer describes, and not without a tax
        // to split it by. A sale's own discount is the sale, so it does not
        // stop the pin.
        if ($grossTarget !== null) {
            // The target is the exact decimal text of a unit, and nothing
            // else is one: a target of nothing is not a target of zero.
            if (preg_match('/^-?\d+(?:\.\d+)?$/', $grossTarget) !== 1) {
                throw new InvalidArgumentException('A gross target is a decimal number.');
            }

            if (self::catalogueUnit(value: $grossTarget)[0] === '-') {
                throw new InvalidArgumentException('A gross target cannot be negative.');
            }

            if ($pinnedLine !== null) {
                throw new InvalidArgumentException('A gross target and a gross-pinned sale cannot both pin one line.');
            }

            $regularBeforeTax = array_filter($appliedDiscounts, fn ($d) => $d->timing === 'before_tax');

            if ($appliedTaxes !== [] && ($isOverride || $regularBeforeTax === [])) {
                $authoritativeUnit = self::catalogueUnit(value: $grossTarget);
                $pinnedLine = ExactAmount::of($authoritativeUnit)->times($quantity);
                $unitOrigin = UnitOrigin::Computed;
            }
        }

        $grossStated = $pinnedLine !== null;
        $beforeTaxDiscounts = array_filter($appliedDiscounts, fn ($d) => $d->timing === 'before_tax');

        // The line's exact net (a net-stated line) and its exact gross. The
        // before-tax rules are taken from the exact extension.
        $beforeTaken = [];

        if ($grossStated) {
            $grossLine = $pinnedLine;
        } else {
            if ($saleLine !== null) {
                $netLine = $saleLine;
            } else {
                [$netLine, $beforeTaken] = $this->applyDiscountStage(entry: $listLine, discounts: $beforeTaxDiscounts, exactAmounts: $exactAmounts);
            }

            $grossLine = self::grossOfLine(line: $netLine, appliedTaxes: $appliedTaxes);
        }

        // 3. The Money boundary. The line's gross is its exact value rounded
        // once, and its net and each rate's tax are the ONE split of that
        // gross. A line with no tax, or a chain that cannot be divided back
        // through, is charged forward on its net instead.
        if ($grossStated || $splittable) {
            $subtotalAfterTax = $grossLine->toMoney($currency);
            [$subtotalBeforeTax, $taxTotal, $charged] = $this->deriveFromGross(
                gross: $subtotalAfterTax,
                appliedTaxes: $appliedTaxes,
                taxLoopFactor: $taxLoopFactor,
                currency: $currency,
            );
        } else {
            $subtotalBeforeTax = $netLine->toMoney($currency);
            [$subtotalAfterTax, $taxTotal, $charged] = TaxChain::chargeForward(net: $subtotalBeforeTax, appliedTaxes: $appliedTaxes);
        }

        // The base and the struck "with tax" figure: the gross of the list's
        // exact extension rounded once, and its net the split of it. With
        // nothing taken before tax they are the line's own figures.
        $basePrice = $subtotalBeforeTax;
        $baseTotalWithTax = $subtotalAfterTax;
        $beforeSteps = [];

        if ($isOverride) {
            if ($saleBelowList) {
                [$listBase, $listGross] = $this->basePair(line: $listLine, appliedTaxes: $appliedTaxes, splittable: $splittable, currency: $currency);

                // A sale below its list: the list stays the base, and the one
                // custom discount is what the sale takes off the line.
                if ($subtotalBeforeTax->lessThan($listBase)) {
                    $basePrice = $listBase;
                    $baseTotalWithTax = $listGross;
                    $beforeSteps[] = $listBase->subtract($subtotalBeforeTax);
                    $appliedDiscounts = [new AppliedDiscount(
                        0,
                        Text::_('COM_ALFA_PRICE_CUSTOM_DISCOUNT'),
                        '',
                        $beforeSteps[0],
                        $salePercent,
                        'fixed_amount',
                        'before_tax',
                    )];
                }
            }
        } elseif (!$grossStated && $beforeTaken !== []) {
            [$basePrice, $baseTotalWithTax] = $this->basePair(line: $listLine, appliedTaxes: $appliedTaxes, splittable: $splittable, currency: $currency);

            // Each rule takes the difference of two consecutive running nets,
            // each the split of its exact remainder's gross: the steps add up
            // to the base less the net, and none is below zero.
            $running = $listLine;
            $previous = $basePrice;
            $last = array_key_last($beforeTaken);

            foreach ($beforeTaken as $position => [, $taken]) {
                $running = $running->minus($taken);
                $now = $position === $last
                    ? $subtotalBeforeTax
                    : $this->basePair(line: $running, appliedTaxes: $appliedTaxes, splittable: $splittable, currency: $currency)[0];
                $beforeSteps[] = $previous->subtract($now);
                $previous = $now;
            }
        }

        // The line's base amount: money of the whole quantity, not a unit price.
        $breakdown->addStep(Text::_('COM_ALFA_PRICE_BREAKDOWN_BASE'), $basePrice, 'set');

        $beforeTaxDiscountTotal = Money::zero($currency);

        foreach ($beforeSteps as $position => $step) {
            $breakdown->addStep($beforeTaken[$position][0] ?? self::stepLabel(discount: $appliedDiscounts[0]), $step, 'subtract');
            $beforeTaxDiscountTotal = $beforeTaxDiscountTotal->add($step);
        }

        // The taxes, as the split charged them. The step each rate wrote is
        // remembered so the after-tax stage below can restate it rather than
        // append beside it. The narrative is written from what was charged, so
        // the story and the money cannot drift apart.
        $taxStepAt = [];

        foreach ($charged as $tax) {
            $taxStepAt[] = $breakdown->addStep(
                $tax->name . ' (+' . $tax->rate . '%)',
                $tax->amount,
                'add',
            );
        }

        $appliedTaxes = $charged;

        // 4. The after-tax discounts, taken from the exact gross. Each step is
        // the difference of two consecutive running grosses, each rounded once.
        $afterTaxDiscounts = array_filter($appliedDiscounts, fn ($d) => $d->timing === 'after_tax');
        $afterTaxStepFrom = $breakdown->stepCount();
        $afterEntry = $taxLoopFactor > 0.0 ? $grossLine : ExactAmount::ofMoney($subtotalAfterTax);
        [, $afterTaken] = $this->applyDiscountStage(entry: $afterEntry, discounts: $afterTaxDiscounts, exactAmounts: $exactAmounts);
        $afterTaxDiscountTotal = Money::zero($currency);
        $afterTaxTaken = [];
        $running = $afterEntry;
        $finalPrice = $subtotalAfterTax;

        foreach ($afterTaken as [$label, $taken]) {
            $running = $running->minus($taken);
            $now = $running->toMoney($currency);
            $step = $finalPrice->subtract($now);
            $breakdown->addStep($label, $step, 'subtract');
            $afterTaxTaken[] = $step;
            $afterTaxDiscountTotal = $afterTaxDiscountTotal->add($step);
            $finalPrice = $now;
        }

        // 4b. THE AFTER-TAX RULE. "After Tax" says
        // which amount the discount is MEASURED on. It does not freeze VAT on
        // consideration the customer no longer pays: once the final tax-inclusive
        // amount is known, the net and the tax are derived back OUT of it through
        // the same chain that produced the gross, at whole minor units.
        //
        // GUARDED: a stage that removed nothing leaves the line's figures as
        // they are. A chain whose factor is not positive (a rate at or below
        // -100%) cannot be divided back through at all.
        $finalPrice = $finalPrice->nonNegative();
        $derived = !$afterTaxDiscountTotal->isZero() && $taxLoopFactor > 0.0;

        if ($derived) {
            $netBeforeDerivation = $subtotalBeforeTax;

            // EXTRACTED VERBATIM, not rewritten. There is exactly one
            // implementation of this arithmetic in the component, and the
            // explicit-price stage below calls the same one.
            [$subtotalBeforeTax, $taxTotal, $charged] = $this->deriveFromGross(
                gross: $finalPrice,
                appliedTaxes: $appliedTaxes,
                taxLoopFactor: $taxLoopFactor,
                currency: $currency,
            );

            $appliedTaxes = $charged;

            // THE NARRATIVE IS RESTATED, NEVER APPENDED TO. Every tax step now
            // carries the money its rate actually charges, and the after-tax steps
            // carry the NET they removed, so the chain still closes on the final
            // (1000.00 + 220.65 - 80.65 = 1140.00) and no surface quotes a VAT the
            // Tax column contradicts.
            foreach ($charged as $position => $tax) {
                $breakdown->restate($taxStepAt[$position], $tax->amount);
            }

            $this->restateAfterTaxSteps(
                breakdown: $breakdown,
                from: $afterTaxStepFrom,
                taken: $afterTaxTaken,
                netRemoved: $netBeforeDerivation->subtract($subtotalBeforeTax),
                currency: $currency,
            );
        }

        // What the customer pays a piece before discounts is on the gross axis: a
        // gross-stated unit as it is, a net-stated one through the chain's
        // factors (the result works it out when it is asked for).
        $payableFactors = $grossStated ? [] : array_map(static fn ($tax): int => self::factorCount(rate: $tax->rate), $appliedTaxes);

        // The rest of the line's provenance, for presentation only: which side
        // of the tax the stated unit is on (both when the chain's exact factor
        // is one, so no rate changes it), and whether a discount, or a sale
        // below its list, took anything from the line, exactly. No figure of
        // the line is computed from these.
        $untaxed = ExactAmount::of('1')->throughFactors(counts: array_map(static fn ($rate): int => self::factorCount(rate: (float) $rate), $rates), places: 20)->compare(ExactAmount::of('1')) === 0;
        $unitAxis = $untaxed ? UnitAxis::Both : ($grossStated ? UnitAxis::Gross : UnitAxis::Net);
        $reduced = $isOverride
            ? $saleBelowList
            : array_filter([...$beforeTaken, ...$afterTaken], static fn (array $step): bool => $step[1]->isPositive()) !== [];

        // The line's exact amounts after every discount, for presentation only:
        // the gross after the after-tax rules, and the net after the before-tax
        // rules or, where a gross was stated or an after-tax rule set it, that
        // gross divided back through the chain's exact factors.
        $shownGross = $afterTaken !== [] ? $running : $grossLine;
        $shownNet = !$grossStated && $afterTaken === [] ? $netLine : self::exactNetOf(gross: $shownGross, rates: $rates);

        // STAGE A ENDS HERE. Everything above is the CANONICAL result: what
        // the ordinary pricing produced for this item, variant and context
        // with no operator involved. The pricing contract defines
        // the comparison amount as exactly that, so it is captured now, before
        // the override stage can touch anything.
        $reference = new PriceReference(
            $subtotalBeforeTax,
            $taxTotal,
            $finalPrice,
            new DiscountSummary($beforeTaxDiscountTotal->add($afterTaxDiscountTotal), $appliedDiscounts),
            $breakdown,
        );

        $explicitNet = null;
        $explicitGross = null;

        // Stage B: the explicit operator price
        //
        // The ONLY new stage, and it runs only when a caller passed one. With
        // $price === null not a statement here executes, which is what makes
        // "no instruction changes nothing" a property of the control flow
        // rather than a promise.
        //
        // It writes no variable stage A reads (stage A is finished), so
        // getBaseTotal() and getBaseTotalWithTax() stay canonical by
        // construction. That single sentence is the whole answer to
        // the canonical comparison amount.
        //
        // NO STAGE C. Nothing here knows what a coupon, a cart rule, a manual
        // staff discount or an eligibility policy is. The cart/adjustment
        // layer owns which adjustments may follow an override and applies
        // them, exactly as it already owns every coupon in Alfa.
        if ($price !== null) {
            // THE RATE TIMES THE QUANTITY, ROUNDED ONCE: 0.125 x 1000 is 125.00;
            // the rate is never quantised first.
            $explicitGross = Money::ofRateTimesQuantity(rate: $price->rate, quantity: $quantity, currency: $currency);

            // The requested gross is PINNED and the net and tax are derived
            // back out of it, through the same chain that produced the
            // canonical gross, so the ruling's "a custom price declared as an
            // authoritative final amount must remain that requested final
            // amount; do not redefine 100.00 as 100.01" holds by construction
            // rather than by a clamp.
            [$explicitNet, $taxTotal, $appliedTaxes] = $this->deriveFromGross(
                gross: $explicitGross,
                appliedTaxes: $appliedTaxes,
                taxLoopFactor: $taxLoopFactor,
                currency: $currency,
            );

            $subtotalBeforeTax = $explicitNet;
            $finalPrice = $explicitGross;
            $authoritativeUnit = $price->rate;
            $payableFactors = [];
            $unitOrigin = UnitOrigin::AgreedRate;
            $shownGross = ExactAmount::of($price->rate)->times($quantity);
            $shownNet = self::exactNetOf(gross: $shownGross, rates: $rates);
            $unitAxis = $untaxed ? UnitAxis::Both : UnitAxis::Gross;
            $reduced = false;

            // THE OVERRIDE REPLACES THE AUTOMATIC RESULT; IT DOES NOT STACK ON
            // IT. "Catalogue 100 -> automatic 10% -> 90 -> override 80 => 80,
            // never 72." The superseded discounts stay readable on the
            // reference above: readable, never re-applied.
            //
            // BOTH HALVES EMPTY, and the pair is load-bearing. hasDiscount()
            // tests the summary's TOTAL; SaleTag and the price-matrix editor
            // read the applied LIST. A zero total with the catalogue list left
            // in place would satisfy every money consumer and still badge a
            // negotiated line as if it were on promotion.
            $appliedDiscounts = [];
            $beforeTaxDiscountTotal = Money::zero($currency);
            $afterTaxDiscountTotal = Money::zero($currency);

            // A NEW NARRATIVE, not an edit of the canonical one, which the
            // reference still holds, intact. It closes on the total the same
            // way every other breakdown does: set the net, add what each rate
            // charged. The first step names the rate, exactly as it was given,
            // and prints no multiplication: the amount beside it is the line's
            // NET, which the quantity times a tax-inclusive rate never equals.
            $breakdown = new PriceBreakdown();
            $breakdown->addStep(
                Text::sprintf(
                    'COM_ALFA_PRICE_CUSTOM_LINE_PRICE',
                    $currency->formatRate(rate: $price->rate, includeSymbol: false),
                ),
                $explicitNet,
                'set',
            );

            foreach ($appliedTaxes as $tax) {
                $breakdown->addStep(
                    $tax->name . ' (+' . $tax->rate . '%)',
                    $tax->amount,
                    'add',
                );
            }
        }

        // 5. Build result
        $totalDiscounts = $beforeTaxDiscountTotal->add($afterTaxDiscountTotal);
        $effectiveTaxRate = $this->calculateEffectiveTaxRate($appliedTaxes);

        $discountSummary = new DiscountSummary(
            $totalDiscounts,
            $appliedDiscounts,
        );

        $taxSummary = new TaxSummary(
            $taxTotal,
            $effectiveTaxRate,
            $appliedTaxes,
        );

        return new PriceResult(
            $basePrice,
            $subtotalBeforeTax,
            $taxTotal,
            $finalPrice, // floored at 4b, so the three figures close
            $quantity,
            $discountSummary,
            $taxSummary,
            $breakdown,
            // REQUIRED, never nullable-with-a-fallback. With no instruction
            // the engine hands the reference the very values it hands the
            // authoritative result, so "no instruction means the reference IS
            // the result" is enforced by construction and cannot drift.
            $reference,
            $authoritativeUnit,
            // The struck "with tax" figure is a stated fact, rounded once from
            // the exact base gross, never a tax charged on the rounded base.
            $baseTotalWithTax,
            $payableFactors,
            // Runtime provenance, read by presentation only.
            $unitOrigin,
            $unitAxis,
            $reduced,
            $listUnit,
            $shownNet?->text(),
            $shownGross->text(),
            $price,
            $explicitNet,
            $explicitGross,
        );
    }

    /**
     * An exact gross divided back through each rate's exact factor, held at
     * twenty places: how a discounted net unit is shown, never charged. Null
     * when a factor is not above zero, as such a chain cannot be divided.
     *
     * @param ExactAmount $gross The exact gross.
     * @param array $rates The chain's rates, in order.
     *
     *
     * @since  1.14.0
     */
    private static function exactNetOf(ExactAmount $gross, array $rates): ?ExactAmount
    {
        $net = $gross;

        foreach ($rates as $rate) {
            $count = self::factorCount(rate: (float) $rate);

            if ($count <= 0) {
                return null;
            }

            $net = $net->times(10000000000)->dividedBy(whole: $count, places: 20);
        }

        return $net;
    }

    /**
     * DERIVE A NET AND A PER-RATE TAX BACK OUT OF A GROSS THAT IS ALREADY FIXED.
     *
     * ONE implementation, three callers: a gross the catalogue pins (a sale
     * typed as a final price, a variant's gross target), the re-derivation
     * after an after-tax discount, and the explicit-price stage. All three face
     * the same problem: a tax-inclusive amount is known and the net and the tax
     * behind it have to be stated at whole minor units, through the chain that
     * actually charged, not through a blended rate.
     *
     * The net is the gross divided by the chain's EXACT factor and rounded once
     * ({@see TaxChain::netOf()}): 0.14 at 12% is 0.125, a tie, 0.13. No float
     * holds the factor or the quotient.
     *
     * The leftover minor unit is absorbed into the tax total AND onto a rate, so
     * the per-rate money still sums to the tax total and net + tax closes on the
     * gross exactly. No rate is taken below zero by it: see {@see self::absorbInto()}.
     *
     * @param Money $gross The gross the answer must land on, to the minor unit.
     * @param array $appliedTaxes The rates that applied, in order.
     * @param float $taxLoopFactor The compounded factor those rates produce: it says whether the chain
     *                             can be divided back through, and is never divided by.
     * @param Currency $currency The line's currency.
     *
     * @return array{0: Money, 1: Money, 2: array} [net, taxTotal, taxes carrying their money]
     *
     * @since  1.11.0
     */
    protected function deriveFromGross(
        Money $gross,
        array $appliedTaxes,
        float $taxLoopFactor,
        Currency $currency,
    ): array {
        $net = $taxLoopFactor > 0.0 ? TaxChain::netOf(gross: $gross, rates: array_column($appliedTaxes, 'rate')) : $gross;

        // The inverse of TaxChain::chargeForward(), and it calls it: divide the
        // gross back, then charge the real chain forward again and absorb
        // whatever the round trip could not reach.
        [, $taxTotal, $charged] = TaxChain::chargeForward(net: $net, appliedTaxes: $appliedTaxes);

        // Per-cent arithmetic in the chain may miss the gross by a minor unit,
        // and the three figures have to close.
        $leftover = $gross->subtract($net)->subtract($taxTotal);

        if (!$leftover->isZero()) {
            $taxTotal = $taxTotal->add($leftover);
            $charged = $this->absorbInto($charged, $leftover, $currency);
        }

        return [$net, $taxTotal, $charged];
    }

    /**
     * Select the winning price ROW for the quantity.
     *
     * A price row is a commercial rule, not a candidate in a search for the
     * cheapest price. Among the rows whose quantity_start/quantity_end range
     * includes the quantity, the most specific COMMERCIAL SCOPE wins first: a
     * row for this customer, then a row for one of the customer's groups, then
     * a general row. Among group rows the NEAREST group wins first: a row of the
     * group the customer is placed in beats a row of its parent, which beats the
     * grandparent's (the distances are the
     * context's, {@see PriceContext::groupDistances()}). Within that scope, and
     * between groups at the same distance, the narrower quantity range wins,
     * compared exactly (a bounded range before an open-ended one; of two
     * open-ended ranges the one that starts higher; of two bounded ranges the
     * smaller width), then a row with a publish window, then a row carrying a
     * sale, then the lower price. So a customer's own price at any quantity is
     * never overridden by a general tier from 10 up, and the customer's own tier
     * from 10 up overrides the customer's own any-quantity price. The full row
     * is returned so compute() can honor an override_value (a sale) carried on it.
     *
     * @param array $prices Price tier objects loaded by PriceDataLoader
     * @param int $quantity Quantity being priced
     * @param array<int, int> $groupDistances How far each group is from the nearest group the customer is placed in
     *                                        ({@see PriceContext::groupDistances()}); a group row of a group not in it
     *                                        ranks after every group row of one that is.
     *
     * @return object|null The winning row, or null when nothing matched
     *
     * Public so the variant pricing path (PriceCalculator) can resolve a
     * combination's own price/adjust row with the identical matching rules.
     *
     * @since  1.3.0
     */
    public function selectBaseRow(array $prices, int $quantity, array $groupDistances = []): ?object
    {
        if (empty($prices)) {
            return null;
        }

        // Find all matching price tiers. Bounds normalize to floats: an
        // empty string means "no bound" exactly like NULL; PHP 8 would
        // otherwise compare '' >= 1 as STRINGS and silently reject the row.
        $bound = static function ($value): ?float {
            return ($value === null || $value === '') ? null : (float) $value;
        };
        $matches = [];

        foreach ($prices as $price) {
            $start = $bound($price->quantity_start ?? null);
            $end = $bound($price->quantity_end ?? null);

            if (($start === null || $start <= $quantity) && ($end === null || $end >= $quantity)) {
                $matches[] = $price;
            }
        }

        if (empty($matches)) {
            return null;
        }

        // 2 a row for one customer, 1 a row for a group, 0 a general row.
        $scope = static fn ($price): int => ((int) ($price->user_id ?? 0)) > 0
            ? 2
            : (((int) ($price->usergroup_id ?? 0)) > 0 ? 1 : 0);

        // A bound as its exact decimal; none for an empty one.
        $edge = static fn ($value): ?ExactAmount => ($value === null || $value === '')
            ? null
            : ExactAmount::of(self::catalogueUnit(value: $value));

        // A start below one, or none, is a start at one: no quantity below one
        // is ordered, so "from 1", "from 0" and an empty start are one range.
        $one = ExactAmount::of('1');
        $from = static function ($value) use ($edge, $one): ExactAmount {
            $start = $edge($value);

            return $start === null || $start->compare($one) < 0 ? $one : $start;
        };

        // Negative when $a's range is the narrower: a bounded range before an
        // open-ended one, the higher start of two open-ended ones, the smaller
        // width of two bounded ones.
        $narrower = static function ($a, $b) use ($edge, $from): int {
            $aEnd = $edge($a->quantity_end ?? null);
            $bEnd = $edge($b->quantity_end ?? null);
            $aStart = $from($a->quantity_start ?? null);
            $bStart = $from($b->quantity_start ?? null);

            if ($aEnd === null || $bEnd === null) {
                return ($aEnd === null) <=> ($bEnd === null) ?: $bStart->compare($aStart);
            }

            return $aEnd->minus($aStart)->compare($bEnd->minus($bStart));
        };

        // Then rows with a publish window beat open-ended ones (a copied promo
        // row with an Active period takes over for exactly that period), then
        // rows with a custom discount beat plain ones, then the lower price.
        $hasWindow = static function ($price): int {
            $up = (string) ($price->publish_up ?? '');
            $down = (string) ($price->publish_down ?? '');

            return (int) (($up !== '' && !str_starts_with($up, '0000'))
                || ($down !== '' && !str_starts_with($down, '0000')));
        };
        $hasOverride = static fn ($price): int => (int) (isset($price->override_value)
            && $price->override_value !== null
            && $price->override_value !== '');

        // Of two group rows, the nearer group first; a group the distances do not
        // name is farther than every one they do.
        $distance = static fn ($price): int => $groupDistances[(int) ($price->usergroup_id ?? 0)] ?? \count($groupDistances);

        usort($matches, static fn ($a, $b): int => ($scope($b) <=> $scope($a))
            ?: ($scope($a) === 1 ? $distance($a) <=> $distance($b) : 0)
            ?: $narrower($a, $b)
            ?: ($hasWindow($b) <=> $hasWindow($a))
            ?: ($hasOverride($b) <=> $hasOverride($a))
            ?: ($a->value <=> $b->value));

        return $matches[0];
    }

    /**
     * Process raw discount data into AppliedDiscount objects.
     *
     * Handles behavior modes:
     *   0 = "only this discount": previous discounts cleared, loop stops
     *   1 = combine (additive stacking)
     *   2 = sequential (compound stacking)
     *
     * A fixed-amount rule is PER UNIT: the money it carries is its amount times
     * the quantity, rounded once at the currency's scale (0.005 off at quantity
     * 1000 is 5.00). The stage then caps it at what it is taken from, so a unit
     * never goes below zero and the same unit sells at the same discounted
     * price at every quantity.
     *
     * The rule's own money (AppliedDiscount::$amount) is that extension rounded
     * once, as the rule states it; what the line is discounted by is the exact
     * extension, handed back in $exactAmounts under the rule's key. What the
     * line took is the breakdown step (and the saving): a difference of rounded
     * running totals, which can be a minor unit away from the rule's own figure
     * (0.005 off a unit of 0.014 states 0.01 and takes 0.00). The step is the
     * money; the rule's figure is what the rule offers, like a percentage's
     * percent.
     *
     * @param array $discounts Raw discount rows from PriceDataLoader
     * @param Currency $currency Active currency for fixed-amount discounts
     * @param int $quantity The line's quantity, which a fixed amount is extended by
     * @param array|null $exactAmounts Filled with each fixed rule's exact line amount, keyed as the returned rules
     *
     * @return AppliedDiscount[]
     * @since  1.0.0
     */
    protected function processDiscounts(array $discounts, Currency $currency, int $quantity, ?array &$exactAmounts = null): array
    {
        $applied = [];
        $exactAmounts = [];

        foreach ($discounts as $discount) {
            // A DECIMAL column answers "0.00000000": PHP string truthiness must never decide
            // whether a rule applies. Exactly zero is skipped, as it always was; a genuine
            // sub-hundredth rule is not, so no tolerance may be introduced here.
            if ((float) $discount->value === 0.0) {
                continue;
            }

            $type = $discount->is_amount == 1 ? 'fixed_amount' : 'percentage';
            $timing = $discount->apply_before_tax == 1 ? 'before_tax' : 'after_tax';

            // Customer-facing label: the shopper-friendly name_customer when set,
            // otherwise the internal name. Used everywhere customer-facing (badge +
            // breakdown line); the raw internal name stays in admin only.
            $displayName = ((string) ($discount->name_customer ?? '')) !== ''
                ? (string) $discount->name_customer
                : (string) ($discount->name ?? 'Discount');

            // The 100% ceiling, extended to EVERY rule: a
            // percentage never removes more than the applicable base, and a negative
            // value is never a surcharge. Clamping here rather than at the money is
            // what makes the breakdown step, the admin price chip and the API payload
            // quote the rate the engine actually applied.
            $percent = $type === 'percentage'
                ? max(0.0, min(100.0, (float) $discount->value))
                : 0.0;
            $amount = null;
            $exact = null;

            if ($type === 'fixed_amount') {
                // The same floor as the percentage's: a negative amount takes nothing.
                $unitAmount = self::catalogueUnit(value: $discount->value);
                $unitAmount = $unitAmount[0] === '-' ? '0' : $unitAmount;
                $exact = ExactAmount::of($unitAmount)->times($quantity);
                $amount = self::extendUnit(unit: $unitAmount, quantity: $quantity, currency: $currency);
            }

            // Handle behavior: 0=only this, 1=combine, 2=sequential
            if ($discount->behavior == 0) {
                // "Only this discount": clear previous and use only this one
                $applied = [];
                $exactAmounts = $exact === null ? [] : [0 => $exact];
                $applied[] = new AppliedDiscount(
                    $discount->id,
                    $displayName,
                    '',
                    $amount,
                    $percent,
                    $type,
                    $timing,
                    tagListShow: (bool) ($discount->tag_list_show ?? 0),
                    tagListSlot: (string) ($discount->tag_list_slot ?? 'image'),
                    tagItemShow: (bool) ($discount->tag_item_show ?? 0),
                    tagItemSlot: (string) ($discount->tag_item_slot ?? 'image'),
                    tagBg: (string) ($discount->tag_bg ?? ''),
                    tagColor: (string) ($discount->tag_color ?? ''),
                    tagClass: (string) ($discount->tag_class ?? ''),
                    // "Only this" applies ALONE, as an ordinary single rule and
                    // never as a pool of one; pooling is about how rules share a
                    // base, and an exclusive rule has no one to share with.
                    behavior: 2,
                );
                break;
            } else {
                // Add to list (combine or sequential handled in application)
                if ($exact !== null) {
                    $exactAmounts[\count($applied)] = $exact;
                }

                $applied[] = new AppliedDiscount(
                    $discount->id,
                    $displayName,
                    '',
                    $amount,
                    $percent,
                    $type,
                    $timing,
                    tagListShow: (bool) ($discount->tag_list_show ?? 0),
                    tagListSlot: (string) ($discount->tag_list_slot ?? 'image'),
                    tagItemShow: (bool) ($discount->tag_item_show ?? 0),
                    tagItemSlot: (string) ($discount->tag_item_slot ?? 'image'),
                    tagBg: (string) ($discount->tag_bg ?? ''),
                    tagColor: (string) ($discount->tag_color ?? ''),
                    tagClass: (string) ($discount->tag_class ?? ''),
                    // 1 = Combined (pools); anything else = Sequential, which is
                    // the reading this else-branch has always given an
                    // unrecognised value.
                    behavior: ((int) $discount->behavior === 1) ? 1 : 2,
                );
            }
        }

        return $applied;
    }

    /**
     * Apply ONE discount stage (the pooled Combined block, then the Sequential
     * chain) and report what is left and what was taken.
     *
     * ## The contract
     *
     * The stage has ONE entry amount `E`: the base line total for the before-tax
     * stage, the post-tax subtotal for the after-tax stage. Rules split in two:
     *
     *     Pool   behavior === 1  (Combined)
     *     Seq    everything else (Sequential; an unrecognised value reads as
     *            Sequential, which is how this engine has always treated one)
     *
     * THE POOL SHARES THE STAGE ENTRY AND ITS MEMBERS NEVER SEE EACH OTHER:
     *
     *     P     = min( SUM of the pool's percentage RATES , 100 )
     *             the rates are summed exactly, as counts of the smallest
     *             unit of a percent, so P is the rate the rules state
     *     Dpct  = E * P/100            <- ONE rounding event for the whole pool,
     *             formed on integers: no float product is rounded
     *     F     = SUM of the pool's fixed amounts, each ALREADY its amount x
     *             quantity: a fixed rule is per unit, and what it carries here
     *             is its money for the whole line
     *     Dpool = min( Dpct + F , E )  <- ONE clamp, at the pool
     *
     * The percentages are taken on `E` and the fixed sum is then added. That
     * order is forced by the contract rather than chosen: subtracting the fixed
     * sum first would make every percentage rule measure the fixed rules'
     * intermediate figure, which is exactly what "must not see each other's
     * result" forbids.
     *
     * THE POOL RESOLVES FIRST, and that too is forced: its base is defined as the
     * stage ENTRY, so a pool placed after a sequential rule would be measuring
     * that rule's remainder instead. Sequential rules then compound on `E - Dpool`
     * exactly as they always have. The tax sibling already has this shape:
     * {@see self::processTaxes()} unshifts the pooled additive rate to the FRONT
     * so sequential rates compound on top of it.
     *
     * THE SEQUENTIAL CHAIN. With no Combined rule in the stage this is the plain
     * chain: each rule is taken from what the one before it left. Every rule
     * has its own cap: a percentage is at most 100% and no value is below zero,
     * and a fixed rule takes its line money (amount x quantity) but never more
     * than is left. So a stage reduces consideration to zero at most and never
     * below (before tax and after tax alike), and the saving a line reports is
     * always its base minus its final.
     *
     * SHIPPING IS NOT HERE, and the contract says so rather than leaving it to be
     * read off the code: this engine prices ITEMS. `$basePrice` is the item price
     * times quantity and carries no shipping component; shipping charges live on
     * the cart and the order, and shipping tax has its own contract. A pool cannot reach them.
     *
     * TAX IS CHARGED BETWEEN THE STAGES AND RE-DERIVED AFTER THEM. A pool changes
     * how much a stage removes, never when tax is computed nor on what base. What
     * an after-tax stage DOES change is the consideration the tax finally sits on:
     * compute() derives the net and the tax back out of the final gross through
     * this same chain, so "After Tax" names the basis the discount is measured
     * on and nothing else.
     *
     * EXACT. The stage rounds nothing: the entry, what each step takes and what
     * is left are exact amounts. compute() records each step as the difference
     * of two consecutive running totals, each rounded once at the line's Money
     * boundary, so the recorded steps add up to what the stage took.
     *
     * @param ExactAmount $entry The amount arriving at this stage.
     * @param AppliedDiscount[] $discounts The stage's rules.
     * @param ExactAmount[] $exactAmounts Each fixed rule's exact line amount, under the rule's key; a fixed rule
     *                                    with none takes nothing.
     *
     * @return array{0: ExactAmount, 1: array<int, array{0: string, 1: ExactAmount}>} [what is left, each step's
     *                                                                                label and what it took, in step order]
     *
     * @since  1.10.0
     */
    protected function applyDiscountStage(
        ExactAmount $entry,
        array $discounts,
        array $exactAmounts,
    ): array {
        $remaining = $entry;
        $taken = [];

        $pool = array_filter($discounts, static fn (AppliedDiscount $d): bool => $d->behavior === 1);
        $seq = array_filter($discounts, static fn (AppliedDiscount $d): bool => $d->behavior !== 1);

        if ($pool !== [] && $entry->isPositive()) {
            // The pool's rate, as a count of the smallest unit of a percent.
            $rate = 0;
            $fixed = ExactAmount::of('0');
            $names = [];

            foreach ($pool as $key => $discount) {
                if ($discount->type === 'fixed_amount' && isset($exactAmounts[$key])) {
                    $fixed = $fixed->plus($exactAmounts[$key]);
                } else {
                    $rate += self::percentCount(percent: $discount->percent);
                }

                $names[] = $discount->name;
            }

            // The cap binds on the RATE, before the fixed sum joins it: a pooled
            // 120% is a pooled 100%, and the fixed amounts are then absorbed by
            // the clamp below rather than silently dropped.
            //
            // It is REDUNDANT AND KEPT ON PURPOSE. Given the clamp two lines
            // below, min(rate, 100) can never change the answer: once the rate
            // reaches 100 the pooled figure already exceeds the entry, so both
            // forms clamp to the entry. It stays because it is the
            // contract's own wording ("percentages summed, capped at 100%").
            //
            // THE TWO FLOORS ARE NOT REDUNDANT WITH EACH OTHER. Both accumulators
            // above are signed, so a negative rate offset by a positive pooled fixed
            // amount leaves the pool positive at the wrong figure; max(0, ...)
            // binds there, while a pool that goes under as a whole is caught by the
            // isNegative() branch below. Without them the subtraction two lines down
            // ADDS to the price, which is a surcharge.
            $share = $entry->timesCount(count: max(0, min($rate, 100 * self::UNIT_COUNT)), places: self::PERCENT_PLACES);

            // The clamp is taken before the sum is formed.
            $pooled = $fixed->compare($entry->minus($share)) > 0 ? $entry : $share->plus($fixed);

            if ($pooled->isNegative()) {
                $pooled = ExactAmount::of('0');
            }

            $remaining = $remaining->minus($pooled);

            // ONE step for the pool, named the way the tax sibling names its
            // pooled rate: the breakdown should show what the shopper's money
            // actually did, and the pool did one thing.
            $taken[] = [implode(' + ', $names), $pooled];
        }

        foreach ($seq as $key => $discount) {
            $amount = $this->calculateDiscountAmount($remaining, $discount, $exactAmounts[$key] ?? null);
            $remaining = $remaining->minus($amount);
            $taken[] = [self::stepLabel(discount: $discount), $amount];
        }

        return [$remaining, $taken];
    }

    /**
     * The breakdown's label of one rule's step: the rate it took, or no rate at
     * all. After processDiscounts() clamps, every percent an AppliedDiscount
     * carries IS the effective one (including the sale/override rule, whose
     * percent is derived from its own money), so a zero percent means "this
     * rule has no rate to state", not "this rule took nothing".
     *
     * @param AppliedDiscount $discount The rule.
     *
     *
     * @since  1.14.0
     */
    private static function stepLabel(AppliedDiscount $discount): string
    {
        return $discount->percent > 0
            ? $discount->name . ' (−' . $discount->percent . '%)'
            : $discount->name;
    }

    /**
     * Calculate what one discount takes from an amount, exactly.
     *
     * THE INVARIANT: what one rule takes is in [0, price]. A discount reduces
     * consideration and can reduce it to zero, but it can never take more than the
     * base it is taken from and can never be negative: a negative discount would be
     * a surcharge, which is a separate concept this engine does not price.
     *
     * The bounds are defensive here. processDiscounts() already clamps every rule it
     * builds, so through compute() nothing out of range reaches this method; the
     * guards below hold for an AppliedDiscount that came from an extension, an
     * import, a direct database write or any other non-form source.
     *
     * @param ExactAmount $price The amount to discount.
     * @param AppliedDiscount $discount The discount to apply.
     * @param ExactAmount|null $amount A fixed rule's exact line amount; null when it carries none.
     *
     * @return ExactAmount What the discount takes.
     * @since  1.0.0
     */
    protected function calculateDiscountAmount(ExactAmount $price, AppliedDiscount $discount, ?ExactAmount $amount = null): ExactAmount
    {
        if ($discount->type === 'fixed_amount') {
            // A fixed rule that carries no amount takes nothing rather than fatally.
            if ($amount === null || $amount->isNegative()) {
                return ExactAmount::of('0');
            }

            // Can't discount more than the price
            return $amount->compare($price) > 0 ? $price : $amount;
        }

        // The defensive effective-rate clamp, clamp(percent, 0, 100), taken as
        // one exact count of a percent.
        return $price->timesCount(
            count: max(0, min(100 * self::UNIT_COUNT, self::percentCount(percent: $discount->percent))),
            places: self::PERCENT_PLACES,
        );
    }

    /**
     * Restate the after-tax stage's narrative on the NET it removed.
     *
     * The stage subtracted a GROSS; after the re-derivation the chain's tax steps
     * carry the tax on the payable consideration, so the discount steps have to
     * carry the net share or the narrative stops adding up to the final. A stage
     * may have written several steps (one pooled, one per sequential rule): the
     * net is split across them in proportion to the gross each one took, by
     * Money's one allocation ({@see Money::allocateByRatios()}: each step the
     * share up to its own weight, rounded once, less what was shared before
     * it), so no minor unit is created or lost and no step takes a negative
     * amount.
     *
     * @param PriceBreakdown $breakdown The running narrative.
     * @param int $from The first step index the stage wrote.
     * @param Money[] $taken The gross each of those steps took, in step order.
     * @param Money $netRemoved Old net minus re-derived net.
     * @param Currency $currency Active currency.
     *
     *
     * @since  1.10.0
     */
    protected function restateAfterTaxSteps(
        PriceBreakdown $breakdown,
        int $from,
        array $taken,
        Money $netRemoved,
        Currency $currency,
    ): void {
        // range() counts DOWNWARDS when the start passes the end, so the bound is
        // checked before the range is built, never after it.
        if ($from >= $breakdown->stepCount()) {
            return;
        }

        $indexes = range($from, $breakdown->stepCount() - 1);

        // The weights are the minor units each step took: whole numbers, so the
        // shares are exact at every size.
        $ratios = array_map(static fn (Money $step): int => $step->getMinorUnits(), array_values($taken));

        // A stage whose steps do not sum to a positive gross cannot be shared out
        // proportionally: the whole net goes on the first step and the rest are
        // restated to zero, so the narrative still closes.
        if (array_sum($ratios) <= 0) {
            foreach ($indexes as $position => $index) {
                $breakdown->restate($index, $position === 0 ? $netRemoved : Money::zero($currency));
            }

            return;
        }

        // The shares add up to the net removed and none has the other sign: a
        // discount step never reads as a surcharge.
        foreach ($netRemoved->allocateByRatios($ratios) as $position => $share) {
            $breakdown->restate($indexes[$position], $share);
        }
    }

    /**
     * Put a leftover minor unit on the applied taxes, so the per-rate money
     * still sums to the line's tax total, and NO RATE IS TAKEN BELOW ZERO.
     *
     * A positive leftover goes on the LAST rate. The last one and not the
     * largest: it is the rate computed on the most compounded base, so it is
     * where a chain's own rounding accumulated.
     *
     * A negative leftover starts there too, but a rate gives up at most the
     * money it carries: what the last rate cannot give is taken from the rate
     * before it, and so on back through the chain. A tax that charged 0.01
     * never reads -0.01 because the chain rounded up three times.
     *
     * Only a rate that is negative by its own percentage can leave a remainder
     * the walk cannot place; it stays on the last rate, so the sum still holds.
     *
     * @param AppliedTax[] $taxes The chain, already carrying money.
     * @param Money $leftover The amount unaccounted for (either sign).
     * @param Currency $currency Active currency.
     *
     * @return AppliedTax[]
     *
     * @since  1.10.0
     */
    protected function absorbInto(array $taxes, Money $leftover, Currency $currency): array
    {
        if ($taxes === []) {
            return $taxes;
        }

        $last = array_key_last($taxes);

        if ($leftover->isNegative()) {
            foreach (array_reverse(array_keys($taxes)) as $key) {
                $carried = $taxes[$key]->amount ?? Money::zero($currency);

                if (!$carried->isPositive()) {
                    continue;
                }

                // At most what this rate carries; the rest walks back.
                $given = $carried->add($leftover)->isNegative() ? $carried->negate() : $leftover;
                $taxes[$key] = $taxes[$key]->withAmount($carried->add($given));
                $leftover = $leftover->subtract($given);

                if ($leftover->isZero()) {
                    return $taxes;
                }
            }
        }

        $taxes[$last] = $taxes[$last]->withAmount(
            ($taxes[$last]->amount ?? Money::zero($currency))->add($leftover),
        );

        return $taxes;
    }

    /**
     * Process raw tax data into AppliedTax objects.
     *
     * Delegates rate calculation to calculateTaxRates() which handles
     * combined (additive) vs sequential (compound) behavior.
     *
     * @param array $taxes Raw tax rows from PriceDataLoader
     * @param Currency $currency Active currency (currently unused but kept for future use)
     *
     * @return AppliedTax[]
     * @since  1.0.0
     */
    protected static function processTaxes(array $taxes, Currency $currency): array
    {
        // Build the applied list directly from the behavior semantics so the
        // compounding loop in compute() lands exactly on calculateTaxRates()'
        // intent (the old index-mapping double-counted combined taxes and
        // dropped sequential rates):
        //   0 = only this tax (replaces all others, stops processing)
        //   1 = combine / additive (rates pool into ONE applied entry)
        //   2 = sequential (own entry; compounds on the running subtotal)
        $applied = [];
        $additiveRate = 0.0;
        $additiveNames = [];
        $additiveId = 0;

        foreach ($taxes as $tax) {
            // "0.000" is zero. empty() would let a 0% rule apply, and a 0% "only this tax"
            // rule returns below, replacing every other tax on the item.
            if ((float) $tax->value === 0.0) {
                continue;
            }

            if ($tax->behavior == 0) {
                return [new AppliedTax((int) $tax->id, $tax->name ?? 'Tax', (float) $tax->value, 'national')];
            }

            if ($tax->behavior == 1) {
                $additiveRate += (float) $tax->value;
                $additiveNames[] = $tax->name ?? 'Tax';
                $additiveId = $additiveId ?: (int) $tax->id;

                continue;
            }

            $applied[] = new AppliedTax((int) $tax->id, $tax->name ?? 'Tax', (float) $tax->value, 'national');
        }

        if ($additiveRate > 0) {
            array_unshift($applied, new AppliedTax($additiveId, implode(' + ', $additiveNames), $additiveRate, 'national'));
        }

        return $applied;
    }

    /**
     * Calculate effective tax rates handling combined/sequential behavior.
     *
     * Behavior modes:
     *   0 = only this tax (replaces all others)
     *   1 = combine / additive (rates simply add up)
     *   2 = sequential / compound  (each rate stacks on the previous)
     *
     * @param array $taxes Raw tax rows from PriceDataLoader
     *
     * @return float[] One rate per AppliedTax index
     * @since  1.0.0
     */
    protected function calculateTaxRates(array $taxes): array
    {
        $rates = [0.0];

        foreach ($taxes as $tax) {
            // "0.000" is zero; a truthiness test would let a 0% "only this" rule collapse
            // every accumulated rate below.
            if ((float) $tax->value === 0.0) {
                continue;
            }

            if ($tax->behavior == 0) {
                // Only this tax: discard any previously accumulated rates
                $rates = [(float) $tax->value];
                break;
            } elseif ($tax->behavior == 1) {
                // Combine (additive)
                $rates[0] += (float) $tax->value;
            } else {
                // Sequential (compounding)
                $rates[] = (float) $tax->value;
            }
        }

        return $rates;
    }

    /**
     * THE ONE READER: a stored catalogue value as its canonical unit.
     *
     * A catalogue unit (a price row's value, a sale's value, a fixed discount
     * amount, a variant step amount, a tax rate read for a unit's sake) is a
     * plain decimal of at most {@see self::UNIT_PLACES} places. This turns what a
     * row holds into that decimal, as text, and nothing else in the pricing
     * service reads one: a unit is never a float between its row and the
     * quantity it is multiplied by.
     *
     * Text is read as written, on its digits. A float is read as the decimal
     * text that reads back as that very float, found without consulting any
     * precision setting, so a stored 9.95 is 9.95 and never 9.9499999999999993.
     * Either way, more than eight places are rounded half away from zero at the
     * eighth, which is what a decimal column of that precision stores.
     *
     * The sign is kept: whether a negative is floored is the caller's rule.
     *
     * @param mixed $value The stored value: null, an int, a finite float or numeric text.
     *
     * @return string The unit: an optional minus, digits, and up to eight decimals, with no padding zeros.
     *
     * @throws InvalidArgumentException When the value is not a number: a price is never read as zero by default.
     *
     * @since  1.14.0
     */
    public static function catalogueUnit(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        if (\is_string($value) && preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m) === 1) {
            return self::unitFromDigits(negative: $m[1] === '-', integer: $m[2], fraction: $m[3] ?? '');
        }

        // Exponent notation and the like: numeric text a driver may print for a
        // floating-point column is read the way a float is.
        if (\is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }

        if (!\is_float($value) || !is_finite($value)) {
            throw new InvalidArgumentException('A catalogue price must be a number.');
        }

        // The common case, with the same answer as the digits below: the float
        // IS an eight-place decimal when its print at eight places reads back as
        // exactly it. Below 2^26 a float's own spacing is finer than the smallest
        // unit, so no second eight-place decimal can read back as it.
        if (abs($value) < 67108864.0) {
            $printed = sprintf('%.' . self::UNIT_PLACES . 'F', $value);

            if ((float) $printed === $value) {
                $unit = rtrim(rtrim($printed, '0'), '.');

                return $unit === '-0' ? '0' : $unit;
            }
        }

        // Otherwise the shortest correctly rounded print that reads back as the
        // float: 15 significant digits when they suffice, else 16, else 17. A
        // stored value comes back digit for digit.
        [$integer, $fraction] = Money::floatDigits(value: abs($value), shortest: true);

        return self::unitFromDigits(negative: $value < 0, integer: $integer, fraction: $fraction);
    }

    /**
     * A decimal given as its digits, as a canonical unit: rounded half away
     * from zero at the eighth place when it is finer, with no padding zeros.
     *
     * The rounding is done on the digits, so no float decides a tie.
     *
     * @param bool $negative Whether the decimal is below zero.
     * @param string $integer The digits before the point.
     * @param string $fraction The digits after it; '' when none.
     *
     *
     * @since  1.14.0
     */
    private static function unitFromDigits(bool $negative, string $integer, string $fraction): string
    {
        if (\strlen($fraction) > self::UNIT_PLACES) {
            $up = $fraction[self::UNIT_PLACES] >= '5';
            $fraction = substr($fraction, 0, self::UNIT_PLACES);

            if ($up) {
                // One of the smallest unit more, carried through the digits.
                $digits = $integer . $fraction;
                $at = \strlen($digits) - 1;

                while ($at >= 0 && $digits[$at] === '9') {
                    $digits[$at--] = '0';
                }

                if ($at >= 0) {
                    $digits[$at] = (string) ((int) $digits[$at] + 1);
                } else {
                    $digits = '1' . $digits;
                }

                $integer = substr($digits, 0, -self::UNIT_PLACES);
                $fraction = substr($digits, -self::UNIT_PLACES);
            }
        }

        $integer = ltrim($integer, '0');
        $fraction = rtrim($fraction, '0');
        $unit = ($integer === '' ? '0' : $integer) . ($fraction === '' ? '' : '.' . $fraction);

        return $negative && $unit !== '0' ? '-' . $unit : $unit;
    }

    /**
     * A unit times a quantity, as line money: THE ONE EXTENSION.
     *
     * The unit is never rounded first. The product is formed on integers and
     * rounded once at the currency's scale, ties away from zero, by
     * {@see Money::ofRateTimesQuantity()}; a negative unit or quantity is the
     * same magnitude, negated.
     *
     * @param string $unit A canonical unit, from {@see self::catalogueUnit()}.
     * @param int $quantity The line's quantity.
     * @param Currency $currency The line's currency, at the scale it is charged in.
     *
     *
     * @throws InvalidArgumentException When the line total does not fit in minor units.
     *
     * @since  1.14.0
     */
    private static function extendUnit(string $unit, int $quantity, Currency $currency): Money
    {
        $negative = $unit[0] === '-';
        $line = Money::ofRateTimesQuantity(
            rate: $negative ? substr($unit, 1) : $unit,
            quantity: abs($quantity),
            currency: $currency,
        );

        return $negative !== ($quantity < 0) ? $line->negate() : $line;
    }

    /**
     * A canonical unit as a count of the smallest unit (10^-8): the form the
     * exact arithmetic of a DERIVED unit runs on. A plain row never becomes
     * one; its text goes straight to the extension.
     *
     * @param string $unit A canonical unit, from {@see self::catalogueUnit()}.
     *
     *
     * @throws InvalidArgumentException When the unit is larger than a derived unit can be.
     *
     * @since  1.14.0
     */
    public static function unitCount(string $unit): int
    {
        $negative = $unit[0] === '-';
        $parts = explode('.', $negative ? substr($unit, 1) : $unit);
        $digits = ltrim($parts[0] . str_pad($parts[1] ?? '', self::UNIT_PLACES, '0'), '0');
        $ceiling = (string) PHP_INT_MAX;

        if (\strlen($digits) > \strlen($ceiling) || (\strlen($digits) === \strlen($ceiling) && strcmp($digits, $ceiling) > 0)) {
            throw new InvalidArgumentException('A catalogue unit of that size cannot be adjusted: a derived unit is below 92,233,720,368.54775808.');
        }

        return $negative ? -(int) $digits : (int) $digits;
    }

    /**
     * A count of the smallest unit as its canonical unit text: what a derived
     * unit is handed on as, once its arithmetic is done.
     *
     * @param int $count The count of 10^-8.
     *
     *
     * @since  1.14.0
     */
    public static function unitText(int $count): string
    {
        $magnitude = abs($count);
        $fraction = $magnitude % self::UNIT_COUNT;
        $unit = (string) intdiv($magnitude, self::UNIT_COUNT);

        if ($fraction !== 0) {
            $unit .= '.' . rtrim(str_pad((string) $fraction, self::UNIT_PLACES, '0', STR_PAD_LEFT), '0');
        }

        return $count < 0 ? '-' . $unit : $unit;
    }

    /**
     * An integer result, or a refusal: PHP answers an integer operation that
     * overflows with a float, and a float is never carried on as a unit.
     *
     * @param int|float $result What an addition or a multiplication of counts answered.
     *
     *
     * @throws InvalidArgumentException When the operation overflowed.
     *
     * @since  1.14.0
     */
    private static function wholeCount(int|float $result): int
    {
        if (!\is_int($result)) {
            throw new InvalidArgumentException('A catalogue unit of that size cannot be adjusted: a derived unit is below 92,233,720,368.54775808.');
        }

        return $result;
    }

    /**
     * A percentage as the exact ratio it multiplies a unit by: 1 + percent/100,
     * in lowest terms. 24 is 31/25; -3.2258 is 483871/500000.
     *
     * @param int $percent The percentage, as a count of 10^-8 percent.
     *
     * @return array{0: int, 1: int} [numerator, denominator]; the denominator is positive and divides 10^10.
     *
     * @since  1.14.0
     */
    private static function ratio(int $percent): array
    {
        $numerator = self::wholeCount(self::PERCENT_BASE + $percent);
        [$a, $b] = [abs($numerator), self::PERCENT_BASE];

        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return [intdiv($numerator, $a), intdiv(self::PERCENT_BASE, $a)];
    }

    /**
     * A unit count times an exact ratio, rounded ONCE, half away from zero, at
     * the smallest unit: the one multiplication a derived unit is made with.
     *
     * The product and the quotient are formed on integers, whatever their
     * size, by the one exact scaling money itself is computed with
     * ({@see Money::scaleCount()}): the answer is exact, and what is left over
     * decides the rounding.
     *
     * @param int $units The unit, as a count of 10^-8.
     * @param int $numerator The ratio's numerator.
     * @param int $denominator The ratio's denominator, not zero.
     *
     *
     * @throws InvalidArgumentException When the result does not fit a count.
     *
     * @since  1.14.0
     */
    private static function scaleUnit(int $units, int $numerator, int $denominator): int
    {
        // No answer means the result is past what a count holds.
        return self::wholeCount(Money::scaleCount(count: $units, numerator: $numerator, denominator: $denominator) ?? PHP_INT_MAX + 1);
    }

    /**
     * Whether a sale's unit is below its list's unit: exactly, and across the tax
     * chain when the sale states the gross.
     *
     * A sale of S on the gross is below a list of L on the net when S is below L
     * times every factor of the chain. With each factor an exact ratio n/d, that
     * is S x d1 x d2 ... below L x n1 x n2 ...: two whole numbers. They are
     * multiplied as machine integers while those hold them and on their decimal
     * digits when they do not, so no product is too large to compare and no sale
     * is refused for its size.
     *
     * @param string $sale The sale's unit, never negative.
     * @param string $list The list's unit.
     * @param array[] $factors The exact [numerator, denominator] of each factor the sale's gross carries,
     *                         from {@see self::chainFactors()}; empty when the sale states the net.
     *
     *
     * @since  1.14.0
     */
    private static function saleBelowList(string $sale, string $list, array $factors): bool
    {
        // A sale is never negative, so it is not below a list that is.
        if ($list[0] === '-') {
            return false;
        }

        [$left, $right] = self::saleSides(sale: $sale, list: $list, factors: $factors);

        if (\is_int($left)) {
            return $left < $right;
        }

        return \strlen($left) === \strlen($right) ? strcmp($left, $right) < 0 : \strlen($left) < \strlen($right);
    }

    /**
     * The percentage a sale takes off its list, at two decimals, from the two
     * units themselves: what the list's side is above the sale's, of the
     * list's side ({@see self::saleSides()}, {@see Money::percentOf()}).
     *
     * No float holds either unit or their quotient. A sale of 1.59 on a list
     * of 1.60 is 0.625 off and reads 0.63; the float difference 1 - 1.59 / 1.60
     * lands under that tie and reads 0.62.
     *
     * @param string $sale The sale's unit, never negative.
     * @param string $list The list's unit, above the sale's on the list's axis.
     * @param array[] $factors The exact [numerator, denominator] of each factor the sale's gross carries,
     *                         from {@see self::chainFactors()}; empty when the sale states the net.
     *
     *
     * @since  1.14.0
     */
    private static function salePercent(string $sale, string $list, array $factors): float
    {
        [$left, $right] = self::saleSides(sale: $sale, list: $list, factors: $factors);

        return Money::percentOf(
            part: \is_int($left) ? $right - $left : self::minusWhole(digits: $right, less: $left),
            whole: $right,
        );
    }

    /**
     * A sale's unit and its list's unit as two whole numbers on one axis: the
     * sale times the denominator of every factor its gross carries, the list
     * times every numerator.
     *
     * They are multiplied as machine integers while those hold them and on
     * their decimal digits when they do not, so no product is too large to
     * compare.
     *
     * @param string $sale The sale's unit, never negative.
     * @param string $list The list's unit, never negative.
     * @param array[] $factors The exact [numerator, denominator] of each factor the sale's gross carries;
     *                         empty when the sale states the net.
     *
     * @return array{0: int|string, 1: int|string} [the sale's side, the list's side]: two integers, or the
     *                                             decimal digits of both with no leading zero ('' is zero).
     *
     * @since  1.14.0
     */
    private static function saleSides(string $sale, string $list, array $factors): array
    {
        $sale = self::countDigits(unit: $sale);
        $list = self::countDigits(unit: $list);

        // Machine integers while they hold: eighteen digits always fit one, and a
        // product that outgrows one comes back as a float, which sends both
        // sides to their digits below.
        if (\strlen($sale) <= 18 && \strlen($list) <= 18) {
            $left = (int) $sale;
            $right = (int) $list;

            foreach ($factors as [$numerator, $denominator]) {
                $left *= abs($denominator);
                $right *= abs($numerator);
            }

            if (\is_int($left) && \is_int($right)) {
                return [$left, $right];
            }
        }

        foreach ($factors as [$numerator, $denominator]) {
            $sale = self::timesWhole(digits: $sale, by: abs($denominator));
            $list = self::timesWhole(digits: $list, by: abs($numerator));
        }

        return [ltrim($sale, '0'), ltrim($list, '0')];
    }

    /**
     * A unit as its count of the smallest unit, in digits: the unit written with
     * eight decimals and no point. Zeros may lead it.
     *
     * @param string $unit A canonical unit, never negative.
     *
     *
     * @since  1.14.0
     */
    private static function countDigits(string $unit): string
    {
        $point = strpos($unit, '.');

        return $point === false
            ? $unit . '00000000'
            : substr($unit, 0, $point) . str_pad(substr($unit, $point + 1), self::UNIT_PLACES, '0');
    }

    /**
     * A whole number written in decimal digits, multiplied by a whole number.
     *
     * @param string $digits The digits of a whole number.
     * @param int $by What it is multiplied by, zero or more.
     *
     * @return string The digits of the product; zeros may lead it.
     *
     * @throws InvalidArgumentException When the multiplier is too large to multiply a group of digits by.
     *
     * @since  1.14.0
     */
    private static function timesWhole(string $digits, int $by): string
    {
        $product = '';
        $carry = 0;

        // Six digits at a time, from the right: a group times the multiplier,
        // plus what the group before it carried, is one whole number.
        for ($end = \strlen($digits); $end > 0; $end -= 6) {
            $start = max(0, $end - 6);
            $carry = self::wholeCount($carry + (int) substr($digits, $start, $end - $start) * $by);
            $product = str_pad((string) ($carry % 1000000), 6, '0', STR_PAD_LEFT) . $product;
            $carry = intdiv($carry, 1000000);
        }

        return $carry . $product;
    }

    /**
     * A whole number written in decimal digits, less a smaller one.
     *
     * @param string $digits The digits of the larger, with no leading zero.
     * @param string $less The digits of the smaller; '' is zero.
     *
     * @return string The digits of the difference, with no leading zero; '0' when the two are equal.
     *
     * @since  1.14.0
     */
    private static function minusWhole(string $digits, string $less): string
    {
        $less = str_pad($less, \strlen($digits), '0', STR_PAD_LEFT);
        $difference = '';
        $borrow = 0;

        // Six digits at a time, from the right: a group less its counterpart,
        // less what the group before it borrowed.
        for ($end = \strlen($digits); $end > 0; $end -= 6) {
            $start = max(0, $end - 6);
            $group = (int) substr($digits, $start, $end - $start) - (int) substr($less, $start, $end - $start) - $borrow;
            $borrow = $group < 0 ? 1 : 0;
            $difference = str_pad((string) ($group + $borrow * 10 ** ($end - $start)), $end - $start, '0', STR_PAD_LEFT) . $difference;
        }

        return ltrim($difference, '0') ?: '0';
    }

    /**
     * An exact amount taken through the tax chain: each rate's factor
     * (1 + rate / 100) multiplied exactly, in chain order. Nothing is rounded,
     * so the gross is the net's own, to the last digit.
     *
     * @param ExactAmount $line The exact net.
     * @param AppliedTax[] $appliedTaxes The chain, in order.
     *
     *
     * @since  1.14.0
     */
    private static function grossOfLine(ExactAmount $line, array $appliedTaxes): ExactAmount
    {
        foreach ($appliedTaxes as $tax) {
            $line = $line->timesCount(count: self::factorCount(rate: $tax->rate), places: self::PERCENT_PLACES);
        }

        return $line;
    }

    /**
     * A rate's factor (1 + rate / 100) as a count over 10^10: the count an
     * exact amount is multiplied by. 24% is 12,400,000,000.
     *
     * @param float $rate The rate, as a percentage.
     *
     *
     * @since  1.14.0
     */
    private static function factorCount(float $rate): int
    {
        [$numerator, $denominator] = self::rateFactor(rate: $rate);

        return self::wholeCount(intdiv(self::PERCENT_BASE, $denominator) * $numerator);
    }

    /**
     * The base and the struck "with tax" figure of an exact net extension, at
     * the line's Money boundary: the gross rounded once from the exact gross,
     * and the net the ONE split of it. With no tax, or a chain that cannot be
     * divided back through, the net is the extension rounded once and the
     * gross the chain charged forward on it.
     *
     * @param ExactAmount $line The exact net extension.
     * @param AppliedTax[] $appliedTaxes The chain, in order.
     * @param bool $splittable Whether a gross can be divided back through the chain.
     * @param Currency $currency The line's currency.
     *
     * @return array{0: Money, 1: Money} [the net, the gross]
     *
     * @since  1.14.0
     */
    private function basePair(ExactAmount $line, array $appliedTaxes, bool $splittable, Currency $currency): array
    {
        if ($splittable) {
            $gross = self::grossOfLine(line: $line, appliedTaxes: $appliedTaxes)->toMoney($currency);

            return [TaxChain::netOf(gross: $gross, rates: array_column($appliedTaxes, 'rate')), $gross];
        }

        $net = $line->toMoney($currency);

        return [$net, $appliedTaxes === [] ? $net : TaxChain::chargeForward(net: $net, appliedTaxes: $appliedTaxes)[0]];
    }

    /**
     * A unit taken through the tax chain, exactly: a net to its gross (each
     * factor in chain order), or a gross back to its net (each factor undone,
     * last first). Every factor rounds once, half away from zero, at the eighth
     * place, so the unit a gross target states is held at eight places.
     *
     * @param int $units The unit, as a count of 10^-8.
     * @param array[] $factors The chain, from {@see self::taxFactors()}.
     * @param bool $toGross True for net to gross, false for gross to net.
     *
     *
     * @since  1.14.0
     */
    private static function throughChain(int $units, array $factors, bool $toGross): int
    {
        foreach ($toGross ? $factors : array_reverse($factors) as [$numerator, $denominator]) {
            $units = $toGross
                ? self::scaleUnit(units: $units, numerator: $numerator, denominator: $denominator)
                : self::scaleUnit(units: $units, numerator: $denominator, denominator: $numerator);
        }

        return $units;
    }

    /**
     * Apply ONE variant step (a combination's adjust row, an option delta or a
     * plugin contribution) to a unit, exactly.
     *
     * The operation (+ / - / % / -%) lands on the net itself, or, for a step
     * that targets the gross of a taxed item, on the gross, whose net is then
     * that gross divided back through the tax chain. An amount is added or
     * subtracted exactly; a percentage multiplies exactly and is rounded once,
     * half away from zero, at the eighth place, as each tax factor is.
     *
     * THE AXIS IS KEPT. A step on the gross applies to the gross the previous
     * step stated when there is one, and to the net times the tax chain when
     * there is not: 9.95 with "+ 5.00" then "+ 8.00" on the gross is exactly
     * 25.338, never a gross rebuilt from a net that was itself divided out of
     * it. A step on the net forgets the gross. An operation of null changes
     * nothing but the axis.
     *
     * Units are counts of the smallest unit ({@see self::unitCount()}), never
     * floats.
     *
     * @param int $net The net unit so far.
     * @param int|null $gross The gross unit the last step stated; null when the unit is held as a net.
     * @param string|null $operation '+', '-', '%', '-%'; null = no-op.
     * @param int $amount The step's amount (currency for +/-, percent for %).
     * @param bool $onGross Whether the step targets the gross of a taxed item.
     * @param array[] $factors The item's tax chain, from {@see self::taxFactors()}.
     *
     * @return array{0: int, 1: int|null} [the net unit, the gross unit this step stated or null]
     *
     * @throws InvalidArgumentException When a unit is larger than a derived unit can be.
     *
     * @since  1.3.0
     */
    public static function applyVariantAdjust(int $net, ?int $gross, ?string $operation, int $amount, bool $onGross, array $factors): array
    {
        $unit = $net;

        if ($onGross) {
            $unit = $gross ?? self::throughChain(units: $net, factors: $factors, toGross: true);
        }

        if ($operation === '%' || $operation === '-%') {
            [$numerator, $denominator] = self::ratio(percent: $operation === '%' ? $amount : -$amount);
            $unit = self::scaleUnit(units: $unit, numerator: $numerator, denominator: $denominator);
        } elseif ($operation !== null) {
            $unit = self::wholeCount($operation === '-' ? $unit - $amount : $unit + $amount);
        }

        return $onGross
            ? [self::throughChain(units: $unit, factors: $factors, toGross: false), $unit]
            : [$unit, null];
    }

    /**
     * Fold one cart-level contribution into money: the net charge and the tax
     * that sits on top of it, both quantised to the currency.
     *
     * NOT applyVariantAdjust, and deliberately not merged with it. '%' there
     * is a percent of the running net (or of the gross, for a 'final' target)
     * of the one price being adjusted; '%' here is a percent of the cart's
     * chargeable net (the goods after the cart rules and the shipping after the
     * shipping rules), a figure no item price knows. The two share a character
     * and nothing else.
     *
     * Additive only, matching CartContribution's vocabulary: a reduction is
     * the cart rules engine's business, so any operation other than '+' or '%'
     * is refused rather than guessed at.
     *
     * Both figures are rounded at the currency's scale, so a percent that
     * lands below the smallest unit folds to 0.00: a charge too small to
     * exist. Whether such a row is kept or dropped is the caller's policy.
     *
     * Neither figure is the rounding of a float product. The base is money and
     * is read at the currency's scale, an amount is read as a unit is, and the
     * percent and the tax rate are exact ratios: 35% of 2.90 is 1.015, a tie,
     * 1.02, on every PHP version.
     *
     * @param float $baseExcl The cart's chargeable net, for '%': the goods after the cart
     *                        rules plus the shipping after the shipping rules.
     * @param string $operation '+' (net currency amount) or '%' (percent of the base).
     * @param float $amount Magnitude: currency for '+', percent for '%'.
     * @param float $taxRate The contribution's own tax rate (percent).
     * @param Currency $currency The cart's currency, which sets the scale.
     *
     * @return array{excl: float, tax: float} The net charge and the tax on top.
     *
     * @since  1.10.0
     */
    public static function foldCartContribution(
        float $baseExcl,
        string $operation,
        float $amount,
        float $taxRate,
        Currency $currency,
    ): array {
        if (!\in_array($operation, ['+', '%'], true)) {
            throw new InvalidArgumentException(
                'Cart contributions are additive: operation must be "+" or "%", got "' . $operation . '".',
            );
        }

        $excl = $operation === '%'
            ? self::extendUnit(unit: self::catalogueUnit(value: $baseExcl), quantity: 1, currency: $currency)
                ->timesRatio(numerator: self::percentCount(percent: $amount), denominator: self::PERCENT_BASE)
            : self::extendUnit(unit: self::catalogueUnit(value: $amount), quantity: 1, currency: $currency);

        return [
            'excl' => $excl->getAmount(),
            'tax' => $excl->timesRatio(numerator: self::percentCount(percent: $taxRate), denominator: self::PERCENT_BASE)->getAmount(),
        ];
    }

    /**
     * The effective tax multiplier for a set of raw tax rows: the factor
     * that turns a stored (net) price into the tax-inclusive price the
     * visitor pays. Shares calculateTaxRates() with compute(), so the
     * behavior modes (only this / combined / sequential) resolve identically:
     * factor = the product of (1 + rate / 100) terms.
     *
     * @param array $taxes Raw tax rows from PriceDataLoader
     *
     * @return float Multiplier (1.24 for an effective 24% load; 1.0 = no tax)
     * @since  1.3.0
     */
    public function taxFactor(array $taxes): float
    {
        $factor = 1.0;

        foreach ($this->calculateTaxRates($taxes) as $rate) {
            $factor *= (1 + $rate / 100);
        }

        return $factor;
    }

    /**
     * The tax chain of {@see self::taxFactor()} as the EXACT ratio of each rate,
     * in chain order: what a unit is multiplied by, factor by factor, to state
     * its gross, and divided by, last first, to come back to its net. The float
     * factor is a figure to show and to compare; a unit is never multiplied by it.
     *
     * @param array $taxes Raw tax rows from PriceDataLoader
     *
     * @return array[] Each [numerator, denominator] in lowest terms (24% is [31, 25]); a rate of zero is left out.
     *
     * @since  1.14.0
     */
    public function taxFactors(array $taxes): array
    {
        return self::chainFactors(rates: $this->calculateTaxRates($taxes));
    }

    /**
     * The applied chain for raw tax rows, read with the behaviour every
     * catalogue price is taxed with (only this / combined / one after another).
     *
     * For a charge that is not a catalogue item, such as shipping, so it is
     * taxed by this engine's reading of the rules and never by a second one.
     *
     * @param array $taxes Raw tax rows (id, name, value, behavior), in rule order.
     * @param Currency $currency The charge's currency.
     *
     * @return AppliedTax[]
     *
     * @since  1.14.0
     */
    public static function chainOf(array $taxes, Currency $currency): array
    {
        return self::processTaxes($taxes, $currency);
    }

    /**
     * The single effective rate of an applied chain, in percent: 15.5 for 10%
     * then 5%. A figure to state and to freeze beside the money, never one to
     * compute money with.
     *
     * @param AppliedTax[] $appliedTaxes The chain.
     *
     *
     * @since  1.14.0
     */
    public static function chainRate(array $appliedTaxes): float
    {
        return self::calculateEffectiveTaxRate($appliedTaxes);
    }

    /**
     * The exact ratio of each rate of a tax chain, in chain order.
     *
     * @param float[] $rates The rates of the chain, as percentages.
     *
     * @return array[] Each [numerator, denominator] in lowest terms (24% is [31, 25]); a rate of zero is left out.
     *
     * @since  1.14.0
     */
    private static function chainFactors(array $rates): array
    {
        $factors = [];

        foreach ($rates as $rate) {
            $ratio = self::rateFactor(rate: $rate);

            if ($ratio[0] !== $ratio[1]) {
                $factors[] = $ratio;
            }
        }

        return $factors;
    }

    /**
     * The factor of ONE rate as its exact ratio: 1 + rate / 100 in lowest
     * terms. 24 is 31/25, so the tax a rate charges is the amount times
     * (numerator - denominator) / denominator, and the net of a gross is the
     * gross times denominator / numerator.
     *
     * The rate is read by the one reader, as a decimal of at most eight
     * places, and its ratio is worked out once.
     *
     * @param float $rate The rate, as a percentage.
     *
     * @return array{0: int, 1: int} [numerator, denominator]; the denominator is positive and divides 10^10.
     *
     * @since  1.14.0
     */
    public static function rateFactor(float $rate): array
    {
        $key = pack('E', $rate);

        if (!isset(self::$rateRatios[$key])) {
            // A shop's rates and percentages are few: the store starts again
            // rather than grow with every value it is ever asked for.
            if (\count(self::$rateRatios) >= self::RATIO_STORE) {
                self::$rateRatios = [];
            }

            self::$rateRatios[$key] = self::ratio(percent: self::unitCount(unit: self::catalogueUnit(value: $rate)));
        }

        return self::$rateRatios[$key];
    }

    /**
     * The factor of a whole chain as ONE exact ratio: the product of each
     * rate's ratio. 10% then 5% is 231/200.
     *
     * The two products are machine integers while those hold them and decimal
     * digits when they do not, so no chain is too long to divide a gross by.
     *
     * @param float[] $rates The rates of the chain, as percentages.
     *
     * @return array{0: int|string, 1: int|string}|null [numerator, denominator]; null when the factor is not
     *                                                  positive (a rate at or below -100%), which no gross can be divided back through.
     *
     * @since  1.14.0
     */
    public static function chainFactor(array $rates): ?array
    {
        $numerator = 1;
        $denominator = 1;
        $positive = true;

        foreach ($rates as $rate) {
            [$above, $below] = self::rateFactor(rate: $rate);

            if ($above === 0) {
                return null;
            }

            $positive = $positive === ($above > 0);
            $numerator = self::timesFactor(product: $numerator, by: abs($above));
            $denominator = self::timesFactor(product: $denominator, by: $below);
        }

        return $positive ? [$numerator, $denominator] : null;
    }

    /**
     * A running product of whole numbers, times one more: a machine integer
     * while one holds it, its decimal digits once it does not.
     *
     * @param int|string $product The product so far.
     * @param int $by What it is multiplied by, zero or more.
     *
     *
     * @since  1.14.0
     */
    private static function timesFactor(int|string $product, int $by): int|string
    {
        if (\is_int($product)) {
            // An integer product that overflows is answered with a float.
            $whole = $product * $by;

            if (\is_int($whole)) {
                return $whole;
            }

            $product = (string) $product;
        }

        return self::timesWhole(digits: $product, by: $by);
    }

    /**
     * A percentage as a count of the smallest unit of a percent (10^-8),
     * read by the one reader: 35 is 3,500,000,000. What a pool sums, and what
     * a percentage of money is taken by, over {@see self::PERCENT_BASE}.
     *
     * @param float $percent The percentage.
     *
     *
     * @throws InvalidArgumentException When the percentage is larger than a count holds.
     *
     * @since  1.14.0
     */
    private static function percentCount(float $percent): int
    {
        [$numerator, $denominator] = self::rateFactor(rate: $percent);

        return self::wholeCount(intdiv(self::PERCENT_BASE, $denominator) * ($numerator - $denominator));
    }

    /**
     * Calculate the single effective tax rate produced by multiple sequential taxes.
     *
     * For sequential taxes the compound effect is:
     *   10% + 5% sequential = 15.5% effective  (1.10 x 1.05 = 1.155)
     *
     * @param AppliedTax[] $appliedTaxes
     *
     * @return float Effective percentage rate
     * @since  1.0.0
     */
    protected static function calculateEffectiveTaxRate(array $appliedTaxes): float
    {
        if (empty($appliedTaxes)) {
            return 0.0;
        }

        $multiplier = 1.0;

        foreach ($appliedTaxes as $tax) {
            $multiplier *= (1 + $tax->rate / 100);
        }

        return ($multiplier - 1) * 100;
    }

    /**
     * Load the Currency object for the current context.
     *
     * CRITICAL: PriceContext stores the DB primary key from #__alfa_currencies
     * (e.g. id = 47), NOT the ISO numeric code (e.g. 978 for EUR).
     *
     * We MUST use Currency::loadById() here.
     * Currency::loadByNumber() would look up ISO codes and throw RuntimeException
     * for any id value like 47 that does not happen to be a valid ISO code,
     * silently killing every price computation.
     * @since  1.0.0
     */
    protected function getCurrency(PriceContext $context): Currency
    {
        $currencyId = $context->getCurrencyId();

        if ($currencyId > 0) {
            // Load by DB primary key: the correct identifier stored in PriceContext
            return Currency::loadById($currencyId);
        }

        // currencyId = 0 means "use component default"
        return Currency::getDefault();
    }
}
