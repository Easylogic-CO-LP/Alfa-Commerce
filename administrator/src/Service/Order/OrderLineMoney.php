<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\ChargeableQuantity;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\ExplicitPriceInstruction;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceComputationEngine;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceResult;
use Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal;
use Alfa\Component\Alfa\Site\Service\Pricing\TaxChain;
use DomainException;
use InvalidArgumentException;
use Joomla\CMS\Language\Text;
use RuntimeException;

/**
 * THE ORDER LINE'S MONEY, COMPUTED ONCE.
 *
 * Before this existed the order line had four writer branches that each built
 * their own money, and they did not agree:
 *
 *   - three of the five ways a line could be written rebuilt the WHOLE LINE as
 *     a rounded unit price MULTIPLIED BY QUANTITY, which the money rules forbid
 *     by name: only the checkout freeze read a whole-line total off the engine;
 *   - the undiscounted gross was reconstructed as net x a BLENDED rate, a
 *     percentage no rate in the shop ever charged;
 *   - and the two discount columns held a DIFFERENT MONEY SPACE depending on
 *     which branch last saved: whole-line from the storefront, per-unit from
 *     the admin, in the same column, with nothing downstream able to tell.
 *
 * All of that is one defect with one cure: the whole-line figures are FACTS the
 * pricing engine already computed, so they are read off it and written down,
 * never derived a second time by a writer. This class is the only place that
 * reading happens, so the four branches cannot drift apart again.
 *
 * EVERY FIGURE HERE IS WHOLE-LINE, and the per-unit columns beside them on the
 * row stay what PriceResult's own naming convention says they are: derived
 * display values. Nothing may rebuild a line total from them. A custom unit
 * rate finer than the currency is the one
 * unit that is an instruction: the line charges it times the quantity, rounded
 * once, and its gross unit column shows the rate itself while the line's total
 * is exactly that.
 *
 * A QUANTITY THAT IS NOT CHARGED IS NOT WRITTEN. A line whose payable unit
 * (what the customer pays a piece, before any discount) is above zero and
 * settles at zero at the quantity it would hold is never stored
 * ({@see ChargeableQuantity}): every factory states it, and
 * {@see self::toColumns()}, which every writer of an order line takes its
 * columns from, refuses such a line in the one sentence that names the
 * smallest quantity that is charged. The payable unit is stored with the
 * line, so a later quantity edit on the line's own terms is judged by the
 * same unit a new line is. Building the line's money for a display refuses
 * nothing.
 *
 * NULL IS NOT ZERO. A row whose `base_total_tax_excl` is NULL predates Item 3
 * and carries no authority; a row whose figures are 0.00 was authoritatively
 * priced at nothing. {@see self::hasAuthority()} is the one test, and a
 * consumer that cannot make that distinction must not aggregate the row.
 *
 * @since  1.12.0
 */
final class OrderLineMoney
{
    /**
     * The columns that state a line's whole-line authority beside its final money: a Set on a line with
     * none writes none of them, so a NULL reference stays NULL and is never guessed.
     *
     * @var string[]
     * @since  1.14.0
     */
    public const AUTHORITY_COLUMNS = [
        'base_total_tax_excl', 'base_total_tax_incl', 'reference_total_tax_excl', 'reference_total_tax_incl',
        'discount_amount_tax_excl', 'discount_amount_tax_incl', 'discount_percent', 'original_product_price',
    ];

    /**
     * Every whole-line figure is the Money it was computed as, in the line's
     * currency, and every unit is its exact decimal text: a float carries no
     * more than fifteen of a figure's digits, and the columns are handed the
     * exact decimal of each ({@see self::toColumns()}).
     *
     * @param Money $baseTotalTaxExcl Whole-line undiscounted net.
     * @param Money $baseTotalTaxIncl Whole-line undiscounted gross, through the real chain.
     * @param Money $referenceTotalTaxExcl Whole-line net before any explicit override.
     * @param Money $referenceTotalTaxIncl Whole-line gross before any explicit override.
     * @param Money $totalTaxExcl Whole-line FINAL net: what is charged.
     * @param Money $totalTaxIncl Whole-line FINAL gross: what is charged.
     * @param Money $discountTaxExcl Whole-line saving, net. base - final.
     * @param Money $discountTaxIncl Whole-line saving, gross. base - final.
     * @param float $discountPercent The saving as a percentage of the base gross.
     * @param string|null $customUnitPriceTaxIncl The operator's negotiated unit price, its canonical rate, or null.
     * @param string $unitTaxExcl DERIVED display: the net per unit.
     * @param string $unitTaxIncl DERIVED display: the gross per unit.
     * @param string $baseUnitTaxExcl DERIVED display: the undiscounted net per unit.
     * @param float[] $splitRates The rates, as percentages in the order they apply, a gross of this
     *                            line is split at: the line's stored tax rate while it is kept on
     *                            its stored terms; the chain the engine charged for a line it has
     *                            just priced.
     * @param Money|null $customTotalTaxExcl Whole-line net of the custom price as the engine applied it, before
     *                                       any manual discount; null with no custom price, or on a row written
     *                                       before the column.
     * @param Money|null $customTotalTaxIncl Whole-line gross of the same.
     * @param string|null $payableUnit The per-piece amount the customer pays before any discount, as the
     *                                 engine stated it (at most eight decimals); null on a line written
     *                                 before it was kept.
     * @param string|null $refusal The sentence a writer of this line is refused in; null when the
     *                             line may be written.
     */
    private function __construct(
        public readonly Money $baseTotalTaxExcl,
        public readonly Money $baseTotalTaxIncl,
        public readonly Money $referenceTotalTaxExcl,
        public readonly Money $referenceTotalTaxIncl,
        public readonly Money $totalTaxExcl,
        public readonly Money $totalTaxIncl,
        public readonly Money $discountTaxExcl,
        public readonly Money $discountTaxIncl,
        public readonly float $discountPercent,
        public readonly ?string $customUnitPriceTaxIncl,
        public readonly string $unitTaxExcl,
        public readonly string $unitTaxIncl,
        public readonly string $baseUnitTaxExcl,
        private readonly array $splitRates,
        public readonly ?Money $customTotalTaxExcl,
        public readonly ?Money $customTotalTaxIncl,
        public readonly ?string $payableUnit,
        private readonly ?string $refusal,
    ) {
    }

    /**
     * Read a line's money off the result the engine already computed.
     *
     * Nothing is recomputed here. Every whole-line figure is a Money the engine
     * produced, taken at the currency's scale, and the saving is the difference
     * of two of them, not a per-unit saving grossed up, and not a percentage
     * turned back into money.
     *
     * @param PriceResult $price The engine's result for this line.
     *
     *
     * @since  1.12.0
     */
    public static function fromPriceResult(PriceResult $price): self
    {
        $base = $price->getBaseTotal();
        $baseGross = $price->getBaseTotalWithTax();
        $finalNet = $price->getSubtotal();
        $finalGross = $price->getTotal();

        // THE SAVING IS A DIFFERENCE OF TWO WHOLE-LINE AMOUNTS, in each space,
        // floored at zero. It is NOT getSavingsPrice(); that is the per-unit
        // face, and storing it here is the space collision this class exists to
        // end. It is not grossed up from the net one either: each space is the
        // difference of the two figures that already live in it.
        $savingNet = $base->subtract($finalNet)->nonNegative();
        $savingGross = $baseGross->subtract($finalGross)->nonNegative();

        [$soldUnitExcl, $soldUnitIncl] = self::unitsOf(net: $finalNet, gross: $finalGross, quantity: $price->getQuantity(), rate: $price->hasExplicitPrice() ? $price->getExplicitPrice()->rate : null, chargedAtRate: true, currency: $price->getCurrency());

        return new self(
            baseTotalTaxExcl: $base,
            baseTotalTaxIncl: $baseGross,
            referenceTotalTaxExcl: $price->getReference()->getSubtotal(),
            referenceTotalTaxIncl: $price->getReference()->getTotal(),
            totalTaxExcl: $finalNet,
            totalTaxIncl: $finalGross,
            discountTaxExcl: $savingNet,
            discountTaxIncl: $savingGross,
            // Derived from the money that was charged, and floored by
            // construction because its numerator is.
            discountPercent: Money::percentOf(part: $savingGross->getMinorUnits(), whole: $baseGross->getMinorUnits()),
            customUnitPriceTaxIncl: $price->hasExplicitPrice()
                ? $price->getExplicitPrice()->rate
                : null,
            unitTaxExcl: $soldUnitExcl,
            unitTaxIncl: $soldUnitIncl,
            baseUnitTaxExcl: ExactAmount::ofMoney($price->getBasePrice())->text(),
            // Freshly priced: a gross of this line splits through the chain the
            // engine charged, as the engine itself splits one.
            splitRates: array_column($price->getTaxes()->getApplied(), 'rate'),
            // THE CUSTOM PRICE'S OWN WHOLE LINE, as the override stage applied
            // it: the pre-manual amounts a manual discount on a custom price is
            // taken off (these origins are never reconstructed later from
            // final totals).
            customTotalTaxExcl: $price->hasExplicitPrice() ? $price->getExplicitPriceTotal() : null,
            customTotalTaxIncl: $price->hasExplicitPrice() ? $price->getExplicitPriceTotalWithTax() : null,
            payableUnit: $price->getPayableUnit(),
            // A fresh line is judged on what a piece is paid exactly; the unit
            // stored beside it, held at the eighth place, is what a kept line
            // is judged by later.
            refusal: self::refusalAt(unit: ChargeableQuantity::ofUnit(unit: $price->getPayableUnitExact(), currency: $price->getCurrency()), quantity: $price->getQuantity()),
        );
    }

    /**
     * The SAME LINE AT ANOTHER QUANTITY, from the money it already carries.
     *
     * A quantity-only edit scales the money already agreed; it does not ask
     * the catalogue what the line should cost today. Nothing here reads a
     * price, a discount, a tax or a rule. THE GROSS IS THE AUTHORITY: each
     * whole-line gross the row stores (base, reference and final) becomes
     *
     *     round(old whole-line gross x new quantity / old quantity)
     *
     * at the order's frozen scale, ROUNDED ONCE, in integer minor units with
     * ties away from zero. It is never rebuilt from a rounded unit price:
     * 100.00 at quantity 3 edited to 2 is 66.67, where 33.33 x 2 would be 66.66.
     *
     * EACH NET IS THE SPLIT OF ITS GROSS at the line's stored tax rate
     * ({@see self::netAtStoredRate()}), never the old net rescaled on its own.
     * Two amounts rounded apart can state a VAT no rate charges: 0.07 with
     * tax and 0.07 without for one unit at 5.5% became 70.00 and 70.00 for a
     * thousand, where the split of 70.00 is 66.35. A row whose quantity does
     * not move derives no total and is returned as it stands, pair for pair.
     *
     * THE SAVING IS BASE - FINAL. After a quantity edit the stored saving is
     * the new base minus the new final, in each space, floored at zero: one
     * whole-line meaning, never a saving rescaled on its own.
     *
     * THE STATED RATE IS THE LINE'S OWN. discount_percent is a ratio of two
     * quantity-proportional amounts, not an amount: it is carried unchanged,
     * never recomputed from the rescaled figures, so a quantity edit does not
     * move it (5.00% stays 5.00%, where a recomputation could say 5.01%). A
     * custom rate re-extended to a new quantity is the exception: its final no
     * longer moves with its base, so its rate is re-derived as base - final
     * over base.
     *
     * The per-unit columns are derived from the rescaled whole line the way
     * PriceResult derives them, whole-line / quantity. The custom price, a
     * per-unit instruction, is carried as it is: provenance does not move. A
     * CUSTOM RATE IS RE-EXTENDED, NOT RESCALED. When the quantity of a line
     * with a custom unit rate moves, the rate is the authority: the custom
     * gross is rate x new quantity rounded once, its net is that gross split
     * at the line's stored tax rate, and the custom pair stores those two. A
     * manual cut that reduces the custom price comes off that gross, and the
     * charged net is the split of what is left. At the quantity the line
     * holds, it keeps what it stores: the engine split that pair through the
     * real tax chain. Base and reference follow the rule of every other
     * line. A row written before the pair keeps a NULL pair.
     *
     * A NEW QUANTITY IS JUDGED AS A NEW LINE IS: by the payable unit the line
     * stores, carried unchanged, times the new quantity
     * ({@see self::refusalOnLeave()}); never by the money it stores.
     *
     * @param object|array $row The stored order line.
     * @param int $newQuantity The quantity the line is saved at.
     * @param Currency $currency The order's currency at its frozen scale.
     *
     *
     * @throws DomainException When the row carries no whole-line authority, no tax rate or no positive
     *                         quantity.
     * @throws InvalidArgumentException When the new quantity is not positive.
     *
     * @since  1.14.0
     */
    public static function rescaledForQuantity(object|array $row, int $newQuantity, Currency $currency): self
    {
        $row = (object) $row;
        $oldQuantity = (int) ($row->quantity ?? 0);

        if ($newQuantity <= 0) {
            throw new InvalidArgumentException('A line is rescaled to a positive quantity only.');
        }

        // NO RATIO, NO RESCALE. A quantity of 0 has no whole-line amount per
        // unit to scale from, and inventing one would be guessing.
        if ($oldQuantity <= 0) {
            throw new DomainException('The line has no positive quantity to rescale from.');
        }

        // NULL IS NOT ZERO (see hasAuthority()). A pre-Item-3 row states no
        // whole-line truth, so there is nothing authoritative to scale; and a
        // row handed in WITHOUT a column the rescale reads is refused too,
        // never read as zero.
        foreach ([
            'base_total_tax_excl', 'base_total_tax_incl', 'reference_total_tax_excl', 'reference_total_tax_incl',
            'total_price_tax_excl', 'total_price_tax_incl', 'discount_amount_tax_excl', 'discount_amount_tax_incl',
            'discount_percent', 'tax_rate',
        ] as $column) {
            if (($row->{$column} ?? null) === null) {
                throw new DomainException('The line carries no authoritative whole-line money to rescale.');
            }
        }

        $scale = static function (mixed $stored) use ($currency, $oldQuantity, $newQuantity): Money {
            $numerator = Money::of($stored, $currency)->getMinorUnits() * $newQuantity;
            $quotient = intdiv($numerator, $oldQuantity);
            $remainder = $numerator - $quotient * $oldQuantity;

            // Half away from zero, on integers: no float ever holds the ratio.
            if (2 * abs($remainder) >= $oldQuantity) {
                $quotient += $numerator < 0 ? -1 : 1;
            }

            return Money::ofMinor($quotient, $currency);
        };

        // THE GROSS IS THE AUTHORITY. When the quantity moves, each net is the
        // split of its rescaled gross at the line's stored rate; a line whose
        // quantity does not move derives nothing and keeps the nets it stores.
        $moved = $newQuantity !== $oldQuantity;
        $taxRate = (float) $row->tax_rate;
        $netOf = static fn (Money $gross, mixed $storedNet): Money => $moved
            ? self::netAtStoredRate(gross: $gross, taxRate: $taxRate)
            : $scale($storedNet);

        $baseGross = $scale($row->base_total_tax_incl);
        $baseNet = $netOf($baseGross, $row->base_total_tax_excl);
        $referenceGross = $scale($row->reference_total_tax_incl);
        $referenceNet = $netOf($referenceGross, $row->reference_total_tax_excl);
        $finalGross = $scale($row->total_price_tax_incl);
        $finalNet = $netOf($finalGross, $row->total_price_tax_excl);

        $rate = ($row->custom_unit_price_tax_incl ?? null) === null
            ? null
            : ExplicitPriceInstruction::canonicalRate(value: $row->custom_unit_price_tax_incl);
        $customNet = null;
        $customGross = null;
        $customCut = 0.0;
        $atRate = false;

        if ($rate !== null) {
            if (($row->manual_discount_percent ?? null) === null) {
                throw new DomainException('The line carries no manual discount to re-extend its custom rate with.');
            }

            $customCut = self::customPriceCut(row: $row);
            $atRate = $customCut == 0.0;
        }

        // THE RATE IS THE AUTHORITY: a new quantity re-extends it, rounded once and split at the line's
        // stored tax rate. A manual cut that reduces the custom price comes off that gross, and the
        // charged net is the split of what is left. At the quantity it holds, the line keeps the pair
        // it stores.
        if ($rate !== null && $moved) {
            $customGross = Money::ofRateTimesQuantity(rate: $rate, quantity: $newQuantity, currency: $currency);
            $customNet = self::netAtStoredRate(gross: $customGross, taxRate: $taxRate);
            $finalGross = self::cutBy(amount: $customGross, percent: $customCut);
            $finalNet = self::netAtStoredRate(gross: $finalGross, taxRate: $taxRate);
        }

        $savingNet = $baseNet->subtract(other: $finalNet)->nonNegative();
        $savingGross = $baseGross->subtract(other: $finalGross)->nonNegative();

        $payableUnit = ($row->payable_unit ?? null) === null ? null : PriceComputationEngine::catalogueUnit(value: $row->payable_unit);

        [$keptUnitExcl, $keptUnitIncl] = self::unitsOf(net: $finalNet, gross: $finalGross, quantity: $newQuantity, rate: $rate, chargedAtRate: $atRate, currency: $currency);

        return new self(
            baseTotalTaxExcl: $baseNet,
            baseTotalTaxIncl: $baseGross,
            referenceTotalTaxExcl: $referenceNet,
            referenceTotalTaxIncl: $referenceGross,
            totalTaxExcl: $finalNet,
            totalTaxIncl: $finalGross,
            discountTaxExcl: $savingNet,
            discountTaxIncl: $savingGross,
            discountPercent: $customGross === null
                ? (float) $row->discount_percent
                : Money::percentOf(part: $savingGross->getMinorUnits(), whole: $baseGross->getMinorUnits()),
            customUnitPriceTaxIncl: $rate,
            unitTaxExcl: $keptUnitExcl,
            unitTaxIncl: $keptUnitIncl,
            baseUnitTaxExcl: ExactAmount::ofMoney($baseNet->divide(divisor: $newQuantity))->text(),
            splitRates: [$taxRate],
            customTotalTaxExcl: ($row->custom_total_tax_excl ?? null) === null
                ? null
                : ($customNet ?? $scale($row->custom_total_tax_excl)),
            customTotalTaxIncl: ($row->custom_total_tax_incl ?? null) === null
                ? null
                : ($customGross ?? $scale($row->custom_total_tax_incl)),
            payableUnit: $payableUnit,
            refusal: self::refusalOnLeave(payableUnit: $payableUnit, moved: $moved, quantity: $newQuantity, currency: $currency),
        );
    }

    /**
     * The part of a line's manual discount that reduces its custom price, as a percentage.
     *
     * @param object $row The stored order line.
     *
     * @return float 0-100.
     *
     * @throws DomainException When the stored scope is unknown.
     *
     * @since  1.14.0
     */
    private static function customPriceCut(object $row): float
    {
        $scope = ManualDiscountScope::tryFrom((string) ($row->manual_discount_scope ?? ''));

        if ($scope === null) {
            throw new DomainException('The line carries an unknown manual discount scope.');
        }

        return $scope->effectivePercent(manualPercent: (float) $row->manual_discount_percent, hasCustomPrice: true);
    }

    /**
     * A SET ON THE LINE'S OWN STORED TERMS: the custom price for a sale the catalogue can no longer resolve.
     *
     * Nothing here reads a price, a discount, a tax or a rule. The custom gross
     * is the rate times the quantity, rounded once; its net is that gross
     * divided out at the line's STORED tax_rate. Base and reference are the
     * line's own, rescaled to the quantity. A row with no
     * whole-line authority gets placeholders for them, which the writer drops
     * through {@see self::AUTHORITY_COLUMNS}: its reference stays unknown
     * rather than guessed.
     *
     * @param object|array $row The stored order line.
     * @param string $rate The canonical custom unit rate.
     * @param int $quantity The quantity the line is saved at.
     * @param Currency $currency The order's currency at its frozen scale.
     *
     *
     * @throws InvalidArgumentException When the quantity is not positive.
     * @throws DomainException When the row has no positive quantity, or only part of its authority.
     *
     * @since  1.14.0
     */
    public static function customPriceOnStoredTerms(object|array $row, string $rate, int $quantity, Currency $currency): self
    {
        $row = (object) $row;

        if ($quantity <= 0) {
            throw new InvalidArgumentException('A custom price is set on a line of a positive quantity only.');
        }

        if ((int) ($row->quantity ?? 0) <= 0) {
            throw new DomainException('The line has no positive quantity to set a custom price on.');
        }

        $gross = Money::ofRateTimesQuantity(rate: $rate, quantity: $quantity, currency: $currency);
        $net = self::netAtStoredRate(gross: $gross, taxRate: (float) $row->tax_rate);

        if (self::hasAuthority(row: $row)) {
            $kept = self::rescaledForQuantity(row: $row, newQuantity: $quantity, currency: $currency);
            $baseNet = $kept->baseTotalTaxExcl;
            $baseGross = $kept->baseTotalTaxIncl;
            $referenceNet = $kept->referenceTotalTaxExcl;
            $referenceGross = $kept->referenceTotalTaxIncl;
            $baseUnitTaxExcl = $kept->baseUnitTaxExcl;
        } else {
            // Placeholders only: the writer drops every authority column for such a row.
            $baseNet = $net;
            $baseGross = $gross;
            $referenceNet = $net;
            $referenceGross = $gross;
            $baseUnitTaxExcl = ExactAmount::ofMoney($net->divide(divisor: $quantity))->text();
        }

        $storedSavingNet = $baseNet->subtract(other: $net)->nonNegative();
        $storedSavingGross = $baseGross->subtract(other: $gross)->nonNegative();

        [$setUnitExcl, $setUnitIncl] = self::unitsOf(net: $net, gross: $gross, quantity: $quantity, rate: $rate, chargedAtRate: true, currency: $currency);

        return new self(
            baseTotalTaxExcl: $baseNet,
            baseTotalTaxIncl: $baseGross,
            referenceTotalTaxExcl: $referenceNet,
            referenceTotalTaxIncl: $referenceGross,
            totalTaxExcl: $net,
            totalTaxIncl: $gross,
            discountTaxExcl: $storedSavingNet,
            discountTaxIncl: $storedSavingGross,
            discountPercent: Money::percentOf(part: $storedSavingGross->getMinorUnits(), whole: $baseGross->getMinorUnits()),
            customUnitPriceTaxIncl: $rate,
            unitTaxExcl: $setUnitExcl,
            unitTaxIncl: $setUnitIncl,
            baseUnitTaxExcl: $baseUnitTaxExcl,
            splitRates: [(float) $row->tax_rate],
            customTotalTaxExcl: $net,
            customTotalTaxIncl: $gross,
            // The custom rate is the gross a piece is charged.
            payableUnit: $rate,
            refusal: self::refusalAt(unit: ChargeableQuantity::ofUnit(unit: $rate, currency: $currency), quantity: $quantity),
        );
    }

    /**
     * The same line charging its PRE-MANUAL amounts: what it charged before any
     * manual staff discount, from its own terms.
     *
     * A manual discount is the line's SETTING, not a cut on top of the last
     * one: an explicit percentage sets it and an explicit 0 clears it, so a
     * new percentage is always taken off these amounts and 0 returns the line
     * to them. They are never worked back out of the discounted total, and
     * nothing here asks the catalogue anything:
     *
     *   - no custom price: the stored REFERENCE, what the ordinary pricing
     *     charged. A manual discount never moves it (see
     *     withManualDiscountPercent()), and with no override it is what the
     *     engine charged (PriceResult: "the reference IS the result").
     *   - a custom price: the STORED custom whole line (custom_total_tax_*),
     *     the net and gross the engine's explicit-price stage computed when
     *     the price was set, rescaled with the line since. Never rebuilt: the
     *     row keeps the effective rate at three decimals only, and under a
     *     sequential chain (17.5% then 1.5% = 19.2625%, stored 19.263) a net
     *     divided out at it is not the engine's.
     *   - a custom price on a row written before those columns (NULL, never
     *     backfilled): the custom unit rate times this quantity, rounded once,
     *     in the gross, the net split at the stored rate
     *     ({@see self::netAtStoredRate()}); exact on a single rate only.
     *
     * The saving is re-derived as base - final and the stated rate with it
     * (one whole-line meaning); the per-unit columns follow the whole line,
     * except that a custom rate finer than the currency shows the rate
     * itself. Base, reference and the custom price stay as they are.
     *
     * @param Currency $currency The order currency at its frozen scale.
     * @param int $quantity The line's quantity.
     * @param float $taxRate The line's stored tax rate, a percentage: a custom-priced row with no stored
     *                       custom whole line is split at it, and so is a manual cut taken off the line
     *                       this returns.
     *
     *
     * @throws InvalidArgumentException When the quantity is not positive.
     *
     * @since  1.14.0
     */
    public function withPreManualTotals(Currency $currency, int $quantity, float $taxRate): self
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A line has pre-manual amounts at a positive quantity only.');
        }

        $rate = $this->customUnitPriceTaxIncl === null ? null : ExplicitPriceInstruction::canonicalRate(value: $this->customUnitPriceTaxIncl);

        if ($this->customUnitPriceTaxIncl === null) {
            $net = $this->referenceTotalTaxExcl;
            $gross = $this->referenceTotalTaxIncl;
        } elseif ($this->customTotalTaxExcl !== null && $this->customTotalTaxIncl !== null) {
            // Every writer stores the pair together, from one engine result.
            $net = $this->customTotalTaxExcl;
            $gross = $this->customTotalTaxIncl;
        } else {
            $gross = Money::ofRateTimesQuantity(rate: ExplicitPriceInstruction::canonicalRate(value: $this->customUnitPriceTaxIncl), quantity: $quantity, currency: $currency);
            $net = self::netAtStoredRate(gross: $gross, taxRate: $taxRate);
        }

        $baseNet = $this->baseTotalTaxExcl;
        $baseGross = $this->baseTotalTaxIncl;
        $savingGross = $baseGross->subtract($gross)->nonNegative();

        [$preUnitExcl, $preUnitIncl] = self::unitsOf(net: $net, gross: $gross, quantity: $quantity, rate: $rate, chargedAtRate: true, currency: $currency);

        return new self(
            baseTotalTaxExcl: $this->baseTotalTaxExcl,
            baseTotalTaxIncl: $this->baseTotalTaxIncl,
            referenceTotalTaxExcl: $this->referenceTotalTaxExcl,
            referenceTotalTaxIncl: $this->referenceTotalTaxIncl,
            totalTaxExcl: $net,
            totalTaxIncl: $gross,
            discountTaxExcl: $baseNet->subtract($net)->nonNegative(),
            discountTaxIncl: $savingGross,
            discountPercent: Money::percentOf(part: $savingGross->getMinorUnits(), whole: $baseGross->getMinorUnits()),
            customUnitPriceTaxIncl: $this->customUnitPriceTaxIncl,
            unitTaxExcl: $preUnitExcl,
            unitTaxIncl: $preUnitIncl,
            baseUnitTaxExcl: $this->baseUnitTaxExcl,
            splitRates: [$taxRate],
            customTotalTaxExcl: $this->customTotalTaxExcl,
            customTotalTaxIncl: $this->customTotalTaxIncl,
            payableUnit: $this->payableUnit,
            refusal: $this->refusal,
        );
    }

    /**
     * The same line with a MANUAL staff discount taken off it, whole-line.
     *
     * The order edit popup offers a percentage. It used to be applied to the
     * UNIT price and the resulting per-unit amounts were written into the
     * whole-line discount columns: half of the space collision this class
     * ends. It is taken off the whole line now, and the saving stays what it
     * has to be: the difference between the undiscounted line and what is
     * actually charged.
     *
     * THE CUT COMES OFF THE GROSS, AND THE NET IS THE SPLIT OF WHAT IS LEFT,
     * on every line. The gross loses its percentage, rounded once; the net is
     * that gross split once through the line's own tax context
     * ({@see TaxChain::netOf()}): the stored tax rate of a line kept on its
     * stored terms, the chain the engine charged for a line it has just
     * priced. So a kept line and a freshly priced one at the same terms store
     * the same pair. A net cut on its own could state a VAT no rate charges:
     * 0.13 with tax and 0.12 without at 5.5%, each less 20%, is 0.10 and
     * 0.10, where the split of 0.10 is 0.09. A percentage of nothing cuts
     * nothing and derives no total: the line keeps the pair it holds.
     *
     * IT CUTS WHAT THIS LINE CHARGES. The caller hands in the line at its
     * pre-manual amounts: the engine's own result for a repriced line, or
     * {@see self::withPreManualTotals()} for a kept one. Called on a line that
     * already carries a manual cut it would stack the two, and a manual
     * discount is a setting, never a cut on a cut.
     *
     * The REFERENCE is untouched. A manual reduction is an adjustment to what
     * is charged, not a claim about what the ordinary pricing would have
     * produced, and the two stay apart.
     *
     * THE UNIT FOLLOWS THE LINE. The per-unit display columns are re-derived
     * from the discounted whole line, whole-line / quantity, exactly as
     * fromPriceResult() and rescaledForQuantity() derive them. Carrying the
     * pre-discount unit left a row whose unit times quantity was not its
     * total, and the next quantity-only edit then visibly changed the unit
     * price (25.00 became 22.50) although nobody asked for a price change.
     * The undiscounted base unit stays: it describes the base, which a manual
     * discount does not move.
     *
     * @param float $percent 0-100, already clamped by the caller.
     * @param Currency $currency The order currency, so the cut quantises once.
     * @param int $quantity The line's quantity, to derive the unit columns from the whole line.
     *
     *
     * @throws InvalidArgumentException When the quantity is not positive.
     *
     * @since  1.12.0
     */
    public function withManualDiscountPercent(float $percent, Currency $currency, int $quantity): self
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A manual discount is taken off a line of a positive quantity only.');
        }

        $percent = max(0.0, min(100.0, $percent));

        $finalNet = $this->totalTaxExcl;
        $finalGross = $this->totalTaxIncl;

        $newGross = self::cutBy(amount: $finalGross, percent: $percent);
        $newNet = $percent == 0.0 ? $finalNet : TaxChain::netOf(gross: $newGross, rates: $this->splitRates);

        $baseNet = $this->baseTotalTaxExcl;
        $baseGross = $this->baseTotalTaxIncl;

        $savingNet = $baseNet->subtract($newNet)->nonNegative();
        $savingGross = $baseGross->subtract($newGross)->nonNegative();

        $rate = $this->customUnitPriceTaxIncl === null ? null : ExplicitPriceInstruction::canonicalRate(value: $this->customUnitPriceTaxIncl);
        [$cutUnitExcl, $cutUnitIncl] = self::unitsOf(net: $newNet, gross: $newGross, quantity: $quantity, rate: $rate, chargedAtRate: $percent == 0.0, currency: $currency);

        return new self(
            baseTotalTaxExcl: $this->baseTotalTaxExcl,
            baseTotalTaxIncl: $this->baseTotalTaxIncl,
            referenceTotalTaxExcl: $this->referenceTotalTaxExcl,
            referenceTotalTaxIncl: $this->referenceTotalTaxIncl,
            totalTaxExcl: $newNet,
            totalTaxIncl: $newGross,
            discountTaxExcl: $savingNet,
            discountTaxIncl: $savingGross,
            discountPercent: Money::percentOf(part: $savingGross->getMinorUnits(), whole: $baseGross->getMinorUnits()),
            customUnitPriceTaxIncl: $this->customUnitPriceTaxIncl,
            unitTaxExcl: $cutUnitExcl,
            unitTaxIncl: $cutUnitIncl,
            baseUnitTaxExcl: $this->baseUnitTaxExcl,
            splitRates: $this->splitRates,
            // The custom price's own whole line is what the cut came off, not
            // what it produced: it stays, so the tick off can return to it.
            customTotalTaxExcl: $this->customTotalTaxExcl,
            customTotalTaxIncl: $this->customTotalTaxIncl,
            // A discount is not what makes a quantity unchargeable: the line's
            // unit and quantity are as they were.
            payableUnit: $this->payableUnit,
            refusal: $this->refusal,
        );
    }

    /**
     * The refusal of a quantity that is not charged ({@see ChargeableQuantity}).
     *
     * @param ChargeableQuantity $unit The line's payable unit.
     * @param int $quantity The quantity the line would hold.
     *
     * @return string|null The sentence that names the smallest quantity that is charged; null when that
     *                     quantity is charged, or the unit is free.
     *
     * @since  1.14.0
     */
    private static function refusalAt(ChargeableQuantity $unit, int $quantity): ?string
    {
        return $unit->refuses(quantity: $quantity) ? ChargeableQuantity::sentence(smallest: $unit->smallest()) : null;
    }

    /**
     * The refusal of a line kept on its own terms (Leave) at the quantity it
     * is saved at.
     *
     * A new quantity is judged exactly as a new line is: by the payable unit
     * the line was priced on, stored when it was written, times the new
     * quantity, before any discount. Never by the money the line stores: a
     * sale stores its list as the base, and a discount is not what makes a
     * quantity unchargeable. A line written before its payable unit was kept
     * is not judged from its totals either: it is refused, and its price is
     * set again (Set) before its quantity can change. A quantity that does
     * not move is not judged.
     *
     * @param string|null $payableUnit The stored payable unit, canonical; null when the line has none.
     * @param bool $moved Whether the quantity moves.
     * @param int $quantity The quantity the line is saved at.
     * @param Currency $currency The order's currency at its frozen scale.
     *
     * @return string|null The sentence the line is refused in; null when it may be written.
     *
     * @since  1.14.0
     */
    private static function refusalOnLeave(?string $payableUnit, bool $moved, int $quantity, Currency $currency): ?string
    {
        return match (true) {
            !$moved => null,
            $payableUnit === null => Text::_('COM_ALFA_ORDER_ITEM_ERROR_NO_PAYABLE_UNIT'),
            default => self::refusalAt(unit: ChargeableQuantity::ofUnit(unit: $payableUnit, currency: $currency), quantity: $quantity),
        };
    }

    /**
     * The per-unit display columns of a whole line: [net unit, gross unit].
     *
     * A line with no custom rate, or with a rate at the currency's scale,
     * keeps whole line / quantity at the currency's scale, as PriceResult
     * derives it. A rate finer than the currency keeps its precision: the
     * gross unit is the rate itself while the line charges it, and otherwise
     * whole line / quantity at eight decimals, as is the net unit (100.81 /
     * 1000 = 0.10081), never a rounded 0.10 x 1000.
     *
     * THE RATE IS SHOWN ONLY BESIDE THE TOTAL IT CHARGES. The line charges the
     * rate when no manual cut applies to it AND its gross is the rate x quantity,
     * rounded once. A line priced unit-first before custom rates finer than the scale were kept
     * (0.125 x 1000 stored as 130.00) therefore shows 130.00 / 1000 = 0.13, never
     * 0.125 beside 130.00.
     *
     * @param Money $net The whole-line net.
     * @param Money $gross The whole-line gross.
     * @param int $quantity The line's quantity.
     * @param string|null $rate The line's canonical custom rate, or null.
     * @param bool $chargedAtRate Whether the line charges the rate uncut.
     * @param Currency $currency The currency, at the line's scale.
     *
     * Each unit is its exact decimal text: at the currency's scale, or at
     * eight places, divided out on its digits and rounded once.
     *
     * @return array{0: string, 1: string}
     *
     * @since  1.14.0
     */
    private static function unitsOf(Money $net, Money $gross, int $quantity, ?string $rate, bool $chargedAtRate, Currency $currency): array
    {
        if ($quantity === 0) {
            return ['0', '0'];
        }

        if ($rate !== null && self::finerThan(rate: $rate, currency: $currency)) {
            $shownAtRate = $chargedAtRate && $gross->getMinorUnits() === Money::ofRateTimesQuantity(rate: $rate, quantity: $quantity, currency: $currency)->getMinorUnits();
            $perUnit = static fn (Money $whole): string => ExactAmount::ofMoney($whole)->dividedBy(whole: $quantity, places: Currency::SCALE_MAX)->text();

            return [$perUnit($net), $shownAtRate ? $rate : $perUnit($gross)];
        }

        return [ExactAmount::ofMoney($net->divide(divisor: $quantity))->text(), ExactAmount::ofMoney($gross->divide(divisor: $quantity))->text()];
    }

    /**
     * THE FROZEN-RATE SPLIT: the net of a gross made from money a line already
     * stores, divided out at the line's stored tax_rate and rounded once.
     *
     * The one place this rule is written. Wherever a new total is derived
     * from a line's stored money the gross is the authority and its net is
     * split here, so no two paths split differently and no net is rescaled,
     * cut or allocated on its own: an ordinary line kept at a new quantity
     * (base, reference and charged total alike), a custom rate re-extended to
     * a new quantity, a Set on a line's stored terms, a custom price written
     * before its pair, and every
     * part of a line that a document states
     * ({@see \Alfa\Component\Alfa\Administrator\Service\Document\OrderItemLines::claim()}).
     * The stored three-decimal rate is the tax context a line keeps: exact on
     * a single rate, and under a sequential chain about gross x 3.5 x 10^-6
     * from the engine's own split, in the net and the VAT only. The gross
     * never differs.
     *
     * A manual percentage splits what is left of the gross the same way,
     * through the line's own rates ({@see self::withManualDiscountPercent()}):
     * on a kept line, this one rate.
     *
     * The division is the pricing engine's own, on a chain of this one rate:
     * the gross over the rate's exact factor, formed on integers and rounded
     * once ({@see TaxChain::netOf()}). 0.14 at 12% is 0.125, a tie, 0.13. A
     * rate at or below -100% leaves the gross as it is.
     *
     * @param Money $gross The whole-line gross.
     * @param float $taxRate The line's stored tax rate, a percentage.
     *
     *
     * @since  1.14.0
     */
    public static function netAtStoredRate(Money $gross, float $taxRate): Money
    {
        return TaxChain::netOf(gross: $gross, rates: [$taxRate]);
    }

    /**
     * A manual cut taken off a whole-line amount: the amount less its
     * percentage, rounded once.
     *
     * The one place a manual cut is computed, for a manual discount and for a
     * custom rate re-extended under a cut alike, so the tick off returns a
     * quantity-edited line to exactly the amounts the cut came off.
     *
     * @param Money $amount The whole-line amount.
     * @param float $percent The cut, 0-100.
     *
     *
     * @since  1.14.0
     */
    private static function cutBy(Money $amount, float $percent): Money
    {
        return $amount->subtract(other: $amount->percentage(percent: $percent));
    }

    /**
     * Is this canonical rate finer than the currency's scale?
     *
     * @param string $rate A canonical rate.
     * @param Currency $currency The currency, at the line's scale.
     *
     *
     * @since  1.14.0
     */
    private static function finerThan(string $rate, Currency $currency): bool
    {
        $point = strpos($rate, '.');

        return $point !== false && \strlen($rate) - $point - 1 > $currency->getDecimalPlaces();
    }

    /**
     * The columns, as a writer assigns them.
     *
     * One map, so the four branches cannot disagree about which column holds
     * which fact: the disagreement that made `discount_amount_tax_excl` mean
     * two different things.
     *
     * A LINE THAT MAY NOT BE WRITTEN HAS NO COLUMNS TO WRITE. One whose payable
     * unit is above zero and settles at zero at its quantity is refused here,
     * before anything is stored, in the sentence that names the smallest
     * quantity that is charged; one kept at another quantity with no payable
     * unit to judge it by, in the sentence that asks for its price to be set
     * again.
     *
     * EACH FIGURE IS THE EXACT DECIMAL TEXT ITS COLUMN IS HANDED
     * ({@see StoredDecimal::of()}): never a float, which a database driver
     * prints with fourteen significant digits. The stated percentage is the
     * one float: a DECIMAL(5,2) holds five digits.
     *
     * @return array<string, float|string|null>
     *
     * @throws RuntimeException When the line may not be written; the message is the refusal, for the person
     *                          writing the line.
     *
     * @since  1.12.0
     */
    public function toColumns(): array
    {
        if ($this->refusal !== null) {
            throw new RuntimeException($this->refusal);
        }

        $unit = static fn (?string $text): ?string => $text === null ? null : StoredDecimal::of(ExactAmount::of($text));

        return [
            'base_total_tax_excl' => StoredDecimal::of($this->baseTotalTaxExcl),
            'base_total_tax_incl' => StoredDecimal::of($this->baseTotalTaxIncl),
            'reference_total_tax_excl' => StoredDecimal::of($this->referenceTotalTaxExcl),
            'reference_total_tax_incl' => StoredDecimal::of($this->referenceTotalTaxIncl),
            'total_price_tax_excl' => StoredDecimal::of($this->totalTaxExcl),
            'total_price_tax_incl' => StoredDecimal::of($this->totalTaxIncl),
            'unit_price_tax_excl' => $unit($this->unitTaxExcl),
            'unit_price_tax_incl' => $unit($this->unitTaxIncl),
            // STAYS PER-UNIT, and that is deliberate. This column has always
            // held a unit price and the document builders read it as one;
            // changing its space would create exactly the collision Item 3
            // exists to remove. The whole-line authority is the new
            // base_total_tax_* pair beside it, and consumers are re-pointed
            // onto that rather than onto a column that quietly changed meaning.
            'original_product_price' => $unit($this->baseUnitTaxExcl),
            'discount_amount_tax_excl' => StoredDecimal::of($this->discountTaxExcl),
            'discount_amount_tax_incl' => StoredDecimal::of($this->discountTaxIncl),
            'discount_percent' => $this->discountPercent,
            'custom_unit_price_tax_incl' => $unit($this->customUnitPriceTaxIncl),
            // NULL whenever the custom price is: a Clear writes both NULLs.
            'custom_total_tax_excl' => StoredDecimal::of($this->customTotalTaxExcl),
            'custom_total_tax_incl' => StoredDecimal::of($this->customTotalTaxIncl),
            // What a later quantity edit on the line's own terms is judged by.
            'payable_unit' => $unit($this->payableUnit),
        ];
    }

    /**
     * Does this ROW carry Item 3's authoritative whole-line facts at all?
     *
     * The one test every consumer asks before it reads or aggregates a stored
     * line. A row written before Item 3 has NULL here and its money cannot be
     * compared with, or summed beside, a row written after it: the old columns
     * held two different money spaces depending on the writer, so a legacy
     * figure is not merely imprecise, it is of unknown meaning.
     *
     * Pre-v1 legacy rows are deliberately NOT backfilled: fabricating financial
     * truth from an ambiguous figure is worse than reporting that there is
     * none. The approved pre-v1 reset regenerates the corpus through these
     * writers.
     *
     * @param object|array $row The stored order line.
     *
     *
     * @since  1.12.0
     */
    public static function hasAuthority(object|array $row): bool
    {
        $value = \is_array($row)
            ? ($row['base_total_tax_excl'] ?? null)
            : ($row->base_total_tax_excl ?? null);

        // NULL means no authority. 0.00000000 is a real, authoritative zero and
        // must not be mistaken for it, which is why this is an identity test
        // and not a truthiness one.
        return $value !== null;
    }

    /**
     * The whole-line saving a STORED row authoritatively carries, or null when
     * the row has no authority.
     *
     * The difference of the two stored columns, taken on their decimal digits:
     * a float difference of two amounts of sixteen or seventeen digits is
     * not their difference (123456789.12345678 less 123456789.12345670 is
     * 0.00000008, and 0.00000007 in floats).
     *
     * @param object $row The stored order line.
     * @param bool $withTax Gross when true, net when false.
     *
     *
     * @since  1.12.0
     */
    public static function storedSaving(object $row, bool $withTax = true): ?float
    {
        $saving = self::storedSavingExact(row: $row, withTax: $withTax);

        return $saving === null ? null : (float) $saving->text();
    }

    /**
     * {@see self::storedSaving()} on its digits: the saving a document prints
     * is formed from it, never from a float.
     *
     * @param object $row The stored order line.
     * @param bool $withTax Gross when true, net when false.
     *
     * @return ExactAmount|null Never below zero; null when the row has no authority.
     *
     * @since  1.14.0
     */
    public static function storedSavingExact(object $row, bool $withTax = true): ?ExactAmount
    {
        if (!self::hasAuthority($row)) {
            return null;
        }

        $base = $withTax ? $row->base_total_tax_incl : $row->base_total_tax_excl;
        $final = $withTax ? $row->total_price_tax_incl : $row->total_price_tax_excl;
        $saving = ExactAmount::ofNumber(\is_string($base) || \is_int($base) ? $base : (float) $base)
            ->minus(ExactAmount::ofNumber(\is_string($final) || \is_int($final) ? $final : (float) $final));

        return $saving->isNegative() ? ExactAmount::of('0') : $saving;
    }
}
