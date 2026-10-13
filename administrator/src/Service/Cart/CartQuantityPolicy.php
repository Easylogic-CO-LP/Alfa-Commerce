<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

use InvalidArgumentException;

defined('_JEXEC') or die;

/**
 * How much of a product a cart may hold.
 *
 * Two authorities answer that, and the order in which they are consulted
 * decides the number:
 *
 *   THE MERCHANT declares how the product is sold: a minimum, a maximum, and
 *   a step the quantity must be a multiple of. A combination that carries its
 *   own rules overrides the item's.
 *
 *   THE SHELF declares how much there is. That figure is cart-wide: what the
 *   same cart already draws from the same pool on its other lines is subtracted
 *   before anything else is decided, because two lines cannot both have the
 *   last unit.
 *
 * The merchant shapes the request first, then the shelf cuts it, and whatever
 * the shelf leaves is put back through the step, because a physical fact does
 * not get to hand a shopper a quantity the shop does not sell. Finally the
 * minimum is checked a second time: if what is available cannot reach the
 * quantity the merchant sells at, there is no answer that is both available and
 * sellable, and the sale is refused rather than shrunk.
 *
 * A request the merchant's rules alone leave below the minimum, or below one
 * unit where there is no minimum, is refused before the shelf is consulted
 * when the shelf this cart can still draw could supply the smallest sale: the
 * quantity asked for is one the shop does not sell, not one it cannot supply,
 * and the decision says so and carries the smallest sale and the step.
 *
 * Pure. It is given two numbers it does not look up (what the shelf can supply
 * and what this cart already draws), and it knows nothing about carts,
 * requests, databases or shoppers, which is what makes the rule testable
 * without any of them.
 *
 * The rules are exact decimals, compared and calculated exactly
 * ({@see \Alfa\Component\Alfa\Administrator\Service\Inventory\QuantityDecimal}),
 * while a line holds whole units, from one to LINE_CAPACITY. A unit is
 * therefore sold in the whole quantities that are at least one and at least the
 * minimum, an exact multiple of the step and at most the maximum: a minimum of
 * 2.5 sells from 3, a step of 1.5 sells in threes, a step of 0.5 sells any
 * whole quantity and a maximum of 7.5 caps a line at 7. The whole multiples of
 * a step are the multiples of its grid ({@see self::saleGrid()}).
 *
 * The same rules decide whether a unit can be sold at all: the smallest
 * quantity the merchant sells ({@see self::saleMinimum()}) is what the stock
 * verdict must be able to supply. That answer and the rule inheritance it rests
 * on also have SQL forms, built as text from the expressions they are given in
 * exact decimal arithmetic, so a listing query judges a unit by exactly the
 * rules the cart applies.
 *
 * @since  2.0.0
 */
final class CartQuantityPolicy
{
    /**
     * The most whole units one cart or order line holds: the capacity of its
     * quantity column. No rule sells more than this on one line.
     *
     * @since  2.0.0
     */
    public const LINE_CAPACITY = 4294967295;

    /**
     * How many rule sets {@see self::saleMinimum()} remembers before it starts
     * over.
     *
     * @since  2.0.0
     */
    private const SALE_MINIMUM_MEMO_SIZE = 256;

    /**
     * Smallest sales already worked out in this request, by rule set. The
     * answer depends on the three rules alone, so a remembered one is exact.
     *
     * @var array<string, int|null>
     *
     * @since  2.0.0
     */
    private static array $saleMinimums = [];

    /**
     * How far below a whole number a shelf figure may sit and still hold that
     * unit: the inventory's own quantity tolerance, so the cart counts whole
     * units exactly as the stock verdict does.
     *
     * @since  2.0.0
     */
    private const WHOLE_UNIT_TOLERANCE = \Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService::QUANTITY_TOLERANCE;

    /**
     * Decide the quantity, and say why it is not the one asked for.
     *
     * Every decision that read the rules also carries their smallest sale and
     * their whole-unit step, so a caller can name the quantities that would be
     * accepted; a cart with nothing left on the shelf is refused before any
     * rule is read and carries neither.
     *
     * @param object $item The item, with its quantity rules
     *                     and combinations.
     * @param int|null $combinationId The resolved combination, whose own
     *                                rules win where it declares them.
     * @param int $requested The quantity asked for.
     * @param float|null $purchasableCeiling What the shelf can supply, from the
     *                                       inventory authority; null when
     *                                       nothing limits it.
     * @param float $pooledDraw What this cart already draws from
     *                          the same pool on its other lines.
     *
     *
     * @since  2.0.0
     */
    public function decide(
        object $item,
        ?int $combinationId,
        int $requested,
        ?float $purchasableCeiling,
        float $pooledDraw = 0.0,
    ): QuantityDecision {
        $reasons = [];
        $quantity = $requested;
        $ceiling = $purchasableCeiling;

        // CART-WIDE, and decided before anything else: subtract what the cart
        // already draws from the same pool. A cart with nothing left is refused
        // here rather than shaped and then refused.
        if ($ceiling !== null) {
            $ceiling = max(0.0, $ceiling - $pooledDraw);

            if ($ceiling <= 0) {
                return QuantityDecision::of($requested, 0, [QuantityReason::StockExhausted], $ceiling);
            }
        }

        ['step' => $step, 'min' => $min, 'max' => $max] = self::quantityRules(
            item: $item,
            combinationId: $combinationId,
        );

        // The exact rules in the whole units a line holds: a minimum of 2.5 is
        // first met at 3, a maximum of 7.5 allows 7, a step of 1.5 sells in
        // threes. The line capacity bounds every quantity.
        $minimum = self::lineMinimum(min: $min);
        $maximum = self::lineLimit(max: $max);
        $grid = self::lineGrid(step: $step);

        // The smallest quantity these rules sell, or null when they sell none,
        // and the least a line holds: the minimum, and never under one unit.
        $saleMinimum = self::saleMinimum(min: $min, step: $step, max: $max);
        $least = $minimum > 0 ? $minimum : 1;

        // The merchant's rules shape the request
        // A request below the minimum is raised to it, capped at the maximum,
        // then rounded down to a sellable multiple.
        if ($minimum > 0 && $quantity < $minimum) {
            $quantity = $minimum;
            $reasons[] = QuantityReason::RaisedToMinimum;
        }

        if ($quantity > $maximum) {
            $quantity = $maximum;
            $reasons[] = QuantityReason::CappedAtMaximum;
        }

        if ($quantity % $grid !== 0) {
            $quantity = self::floorToMultiple(quantity: $quantity, grid: $grid);
            $reasons[] = QuantityReason::RoundedToStep;
        }

        // The shelf is counted in whole units, and a figure within the
        // tolerance of a whole number holds that unit, as the stock verdict
        // counts it.
        $wholeUnits = $ceiling === null ? null : (int) floor($ceiling + self::WHOLE_UNIT_TOLERANCE);

        // A quantity the shop does not sell
        // The rules left the request below the least a line holds (a step
        // with no minimum rounds a small request down to nothing), yet the
        // unit has a smallest sale and the shelf this cart can still draw
        // supplies it: the refusal is the rules', so it is decided here,
        // before the shelf could turn it into a shortage.
        if (
            $quantity < $least
            && $saleMinimum !== null
            && ($wholeUnits === null || $wholeUnits >= $saleMinimum)
        ) {
            $reasons[] = QuantityReason::NotASaleQuantity;

            return QuantityDecision::of(
                requested: $requested,
                quantity: 0,
                reasons: $reasons,
                ceiling: $ceiling,
                saleMinimum: $saleMinimum,
                saleIncrement: $grid,
            );
        }

        // Then the shelf has its say, and the rules still hold
        // Clamping is legitimate; what follows it is the point. Whatever the
        // shelf leaves must still be a quantity the merchant offers, so the
        // step is re-applied to it; otherwise stock 7 with a step of 5 hands
        // back 7, which is a multiple of nothing the shop sells.
        if ($wholeUnits !== null && $quantity > $wholeUnits) {
            $quantity = $wholeUnits;
            $reasons[] = QuantityReason::ClampedToStock;

            if ($quantity % $grid !== 0) {
                $quantity = self::floorToMultiple(quantity: $quantity, grid: $grid);
                $reasons[] = QuantityReason::RoundedToStepAfterStock;
            }
        }

        // The minimum is a condition of sale, not a preference
        // "Fewer than we sell" is not one of the answers: what is available
        // cannot reach the smallest sale, or the rules sell nothing at all.
        // A line of nothing is no sale either, with or without a minimum.
        if ($quantity < $least) {
            $reasons[] = QuantityReason::BelowMinimum;

            return QuantityDecision::of(
                requested: $requested,
                quantity: 0,
                reasons: $reasons,
                ceiling: $ceiling,
                saleMinimum: $saleMinimum,
                saleIncrement: $grid,
            );
        }

        return QuantityDecision::of(
            requested: $requested,
            quantity: max(0, $quantity),
            reasons: $reasons,
            ceiling: $ceiling,
            saleMinimum: $saleMinimum,
            saleIncrement: $grid,
        );
    }

    /**
     * The largest quantity one cart line may hold: the only maximum any cart
     * surface renders.
     *
     * Not a second rule. The request put to decide() is the merchant's maximum
     * cut to the whole units the shelf leaves once the cart's other lines on
     * the same unit are counted, and the answer returned is decide()'s own, so
     * a stepper never offers a quantity the server clamp would change. The
     * whole units are counted as decide() counts them, with the same
     * tolerance, and the merchant's maximum is the whole units it allows (7
     * for a maximum of 7.5), within the line capacity.
     *
     * @param object $item The item, with its quantity rules
     *                     and combinations.
     * @param int|null $combinationId The line's combination, whose own
     *                                rules win where it declares them.
     * @param float|null $purchasableCeiling What the shelf can supply for the
     *                                       line's unit; null when nothing
     *                                       limits it.
     * @param float $pooledDraw What the cart's other lines draw
     *                          from the same unit.
     *
     * @return int|null Null when neither the merchant nor the shelf sets a
     *                  limit; 0 when no quantity is accepted.
     *
     * @since  2.0.0
     */
    public function lineMaximum(
        object $item,
        ?int $combinationId,
        ?float $purchasableCeiling,
        float $pooledDraw,
    ): ?int {
        $max = self::quantityRules(item: $item, combinationId: $combinationId)['max'];
        $limited = self::isPositive(value: $max);

        if ($purchasableCeiling === null && !$limited) {
            return null;
        }

        $headroom = null;

        if ($purchasableCeiling !== null) {
            $headroom = max(0, (int) floor($purchasableCeiling - $pooledDraw + self::WHOLE_UNIT_TOLERANCE));
        }

        $maximum = self::lineLimit(max: $max);
        $requested = $limited
            ? ($headroom === null ? $maximum : min($maximum, $headroom))
            : $headroom;

        if ($requested <= 0) {
            return 0;
        }

        return $this->decide(
            item: $item,
            combinationId: $combinationId,
            requested: $requested,
            purchasableCeiling: $purchasableCeiling,
            pooledDraw: $pooledDraw,
        )->quantity;
    }

    /**
     * One quantity rule (minimum, step or maximum) as a line applies it.
     *
     * The combination's value wins when it is not NULL and not exactly zero;
     * otherwise the item's value applies, and an item without the rule has
     * none (zero). Each rule is resolved on its own: a combination that
     * declares only a maximum keeps the item's minimum and step. The value is
     * kept exact, never cut to whole units, so a combination value of 0.5 is
     * its own rule. An empty value counts as absent, like NULL.
     *
     * @param mixed $combination The combination's stored value, as the driver
     *                           returns it; NULL for the item's own unit.
     * @param mixed $item The item's stored value, as the driver returns
     *                    it.
     *
     * @return string The rule as a canonical exact quantity; zero means no
     *                rule.
     *
     * @throws InvalidArgumentException When a value present is not an exact
     *                                  quantity.
     *
     * @since  2.0.0
     */
    public static function ruleValue(mixed $combination, mixed $item): string
    {
        if ($combination !== null && $combination !== '') {
            $own = \Alfa\Component\Alfa\Administrator\Service\Inventory\QuantityDecimal::canonical(value: $combination);

            if (!\Alfa\Component\Alfa\Administrator\Service\Inventory\QuantityDecimal::isZero(a: $own)) {
                return $own;
            }
        }

        if ($item !== null && $item !== '') {
            return \Alfa\Component\Alfa\Administrator\Service\Inventory\QuantityDecimal::canonical(value: $item);
        }

        return \Alfa\Component\Alfa\Administrator\Service\Inventory\QuantityDecimal::ZERO;
    }

    /**
     * The smallest quantity the merchant sells a unit at, when stock does not
     * limit it: the smallest request decide() accepts unchanged.
     *
     * At least one unit and at least the minimum (its whole ceiling: 3 for
     * 2.5, and 3 for 2.99999950, never "close to" anything), raised to the
     * next multiple of the step's grid. When that is above the maximum's whole
     * floor or the line capacity, no quantity satisfies the rules together and
     * the unit cannot be sold in any amount. Exact: no rule value is rounded
     * or compared within a tolerance.
     *
     * @param string $min The resolved minimum ({@see self::ruleValue()}); zero
     *                    or below means none.
     * @param string $step The resolved step; zero or below means none.
     * @param string $max The resolved maximum; zero or below means none.
     *
     * @return int|null The quantity, or null when the rules accept none.
     *
     * @throws InvalidArgumentException When a rule is not an exact quantity.
     *
     * @since  2.0.0
     */
    public static function saleMinimum(string $min, string $step, string $max): ?int
    {
        $key = $min . '|' . $step . '|' . $max;

        if (\array_key_exists($key, self::$saleMinimums)) {
            return self::$saleMinimums[$key];
        }

        $first = (string) max(1, self::lineMinimum(min: $min));
        $grid = self::saleGrid(step: $step);
        $rest = bcmod($first, $grid, 0);
        $sale = $rest === '0' ? $first : bcadd(bcsub($first, $rest, 0), $grid, 0);

        $answer = bccomp($sale, (string) self::lineLimit(max: $max), 0) > 0 ? null : (int) $sale;

        if (\count(self::$saleMinimums) >= self::SALE_MINIMUM_MEMO_SIZE) {
            self::$saleMinimums = [];
        }

        return self::$saleMinimums[$key] = $answer;
    }

    /**
     * The grid a step sells on: the smallest whole quantity that is an exact
     * multiple of the step. A line holds whole units, so its quantity must be
     * a multiple of this.
     *
     * The step itself when it is whole; 1 for a step of 0.5 or 0.25 (every
     * whole quantity is a multiple); 3 for 1.5 or 0.3; 5999999 for 2.99999950;
     * 1 for no step.
     *
     * @param string $step The resolved step ({@see self::ruleValue()}); zero or
     *                     below means none.
     *
     * @return string A whole number without decimals.
     *
     * @throws InvalidArgumentException When the step is not an exact quantity.
     *
     * @since  2.0.0
     */
    public static function saleGrid(string $step): string
    {
        return \Alfa\Component\Alfa\Administrator\Service\Inventory\QuantityDecimal::wholeGrid(step: $step);
    }

    /**
     * The figures a quantity stepper draws for a unit, from its rules alone.
     *
     * minimum is the smallest sale ({@see self::saleMinimum()}); increment is
     * the grid of the step ({@see self::saleGrid()}); maximum is the largest
     * sale within the merchant's maximum and the line capacity, the last
     * multiple of the increment at or below the maximum's whole units (6 for
     * a minimum of 2.5, a step of 1.5 and a maximum of 7.5), null when the
     * unit has no maximum. Every figure the stepper can reach is therefore a
     * quantity decide() keeps unchanged. When the rules accept no quantity,
     * minimum and maximum are null and increment is 1. Stock is not
     * consulted: a cart line's own ceiling is {@see self::lineMaximum()}.
     *
     * @param object $item The item, with its quantity rules and
     *                     combinations.
     * @param int|null $combinationId The combination the stepper is for, whose
     *                                own rules win where it declares them.
     *
     * @return array{minimum: int|null, increment: int, maximum: int|null}
     *
     * @throws InvalidArgumentException When a stored rule is not an exact
     *                                  quantity.
     *
     * @since  2.0.0
     */
    public static function bounds(object $item, ?int $combinationId): array
    {
        ['step' => $step, 'min' => $min, 'max' => $max] = self::quantityRules(
            item: $item,
            combinationId: $combinationId,
        );

        $minimum = self::saleMinimum(min: $min, step: $step, max: $max);

        if ($minimum === null) {
            return ['minimum' => null, 'increment' => 1, 'maximum' => null];
        }

        $grid = self::lineGrid(step: $step);

        // The smallest sale is a multiple of the grid within the cap, so the
        // last multiple within the cap is never below it.
        return [
            'minimum' => $minimum,
            'increment' => $grid,
            'maximum' => self::isPositive(value: $max)
                ? self::floorToMultiple(quantity: self::lineLimit(max: $max), grid: $grid)
                : null,
        ];
    }

    /**
     * The SQL form of {@see self::ruleValue()}.
     *
     * A combination value of 0 or NULL inherits the item's and NULL at both
     * levels is 0. The stored value is kept exact, as the PHP form keeps it.
     * The item's own unit has no combination value, so its form reads the item
     * column alone and keeps that column's exact decimal type: a bare NULL
     * inside NULLIF() is typed as text, which would turn the arithmetic built
     * on the rule into floating point.
     *
     * @param string $combinationExpr SQL yielding the combination's stored
     *                                value; 'NULL' for the item's own unit.
     * @param string $itemExpr SQL yielding the item's stored value.
     *
     * @return string A numeric SQL expression of the stored columns' exact
     *                type, never NULL.
     *
     * @since  2.0.0
     */
    public static function ruleValueSql(string $combinationExpr, string $itemExpr): string
    {
        if (strtoupper(string: trim(string: $combinationExpr)) === 'NULL') {
            return 'COALESCE(' . $itemExpr . ', 0)';
        }

        return 'COALESCE(NULLIF(' . $combinationExpr . ', 0), ' . $itemExpr . ', 0)';
    }

    /**
     * The SQL form of {@see self::saleGrid()}, in exact decimal arithmetic.
     *
     * The step times the factors that make it whole, each chosen by the same
     * divisibility ladder the PHP form walks
     * ({@see \Alfa\Component\Alfa\Administrator\Service\Inventory\QuantityDecimal::WHOLE_GRID_LADDERS}),
     * over the step in units of the quantity type's last decimal; 1 when the
     * step is zero or below.
     *
     * @param string $stepExpr SQL yielding the resolved step as an exact
     *                         decimal, never NULL ({@see self::ruleValueSql()}).
     *
     * @return string A parenthesised numeric SQL expression.
     *
     * @since  2.0.0
     */
    public static function saleGridSql(string $stepExpr): string
    {
        $units = '(' . $stepExpr . ') * '
            . \Alfa\Component\Alfa\Administrator\Service\Inventory\QuantityDecimal::SCALE_FACTOR;
        $factors = [];

        foreach (\Alfa\Component\Alfa\Administrator\Service\Inventory\QuantityDecimal::WHOLE_GRID_LADDERS as $ladder) {
            $branches = '';
            $otherwise = '1';

            foreach ($ladder as $divisor => $multiplier) {
                // Every whole number is a multiple of 1: that rung is the ELSE.
                if ($divisor === 1) {
                    $otherwise = (string) $multiplier;

                    continue;
                }

                $branches .= ' WHEN MOD(' . $units . ', ' . $divisor . ') = 0 THEN ' . $multiplier;
            }

            $factors[] = '(CASE' . $branches . ' ELSE ' . $otherwise . ' END)';
        }

        return '(CASE WHEN (' . $stepExpr . ') > 0 THEN (' . $stepExpr . ') * '
            . implode(' * ', $factors) . ' ELSE 1 END)';
    }

    /**
     * Whether the rules accept any quantity at all: the SQL form of
     * "{@see self::saleMinimum()} is not null".
     *
     * True exactly when the largest multiple of the grid within the cap (the
     * maximum's whole floor, or the line capacity) is at least the first whole
     * quantity the minimum allows. Exact decimal arithmetic: no tolerance,
     * rounding or conversion to a floating-point type.
     *
     * @param string $minExpr SQL yielding the resolved minimum as an exact
     *                        decimal; zero or below means none.
     * @param string $stepExpr SQL yielding the resolved step; zero or below
     *                         means none.
     * @param string $maxExpr SQL yielding the resolved maximum; zero or below
     *                        means none.
     *
     * @return string A parenthesised boolean SQL expression, never NULL for
     *                non-NULL rules.
     *
     * @since  2.0.0
     */
    public static function saleOrderableSql(string $minExpr, string $stepExpr, string $maxExpr): string
    {
        $cap = self::capSql(maxExpr: $maxExpr);

        return '(' . $cap . ' - MOD(' . $cap . ', ' . self::saleGridSql(stepExpr: $stepExpr) . ')'
            . ' >= ' . self::firstSql(minExpr: $minExpr) . ')';
    }

    /**
     * Whether a number of whole units covers the smallest sale: the SQL form of
     * "{@see self::saleMinimum()} is not null and at most the given units".
     *
     * True exactly when the largest multiple of the grid within both the cap
     * and the given units is at least the first whole quantity the minimum
     * allows. The rule side is exact; the whole units are whatever the caller
     * counts on the stock side.
     *
     * @param string $wholeUnitsExpr SQL yielding a whole number of units; NULL
     *                               yields NULL.
     * @param string $minExpr SQL yielding the resolved minimum as an
     *                        exact decimal; zero or below means none.
     * @param string $stepExpr SQL yielding the resolved step; zero or
     *                         below means none.
     * @param string $maxExpr SQL yielding the resolved maximum; zero or
     *                        below means none.
     *
     * @return string A parenthesised boolean SQL expression.
     *
     * @since  2.0.0
     */
    public static function saleReachableSql(string $wholeUnitsExpr, string $minExpr, string $stepExpr, string $maxExpr): string
    {
        $within = 'LEAST(' . self::capSql(maxExpr: $maxExpr) . ', (' . $wholeUnitsExpr . '))';

        return '(' . $within . ' - MOD(' . $within . ', ' . self::saleGridSql(stepExpr: $stepExpr) . ')'
            . ' >= ' . self::firstSql(minExpr: $minExpr) . ')';
    }

    /**
     * The merchant's quantity rules for a line. A combination overrides the
     * item rule by rule, wherever it declares a non-zero value.
     *
     * @param object $item The item, with its quantity rules and
     *                     combinations.
     * @param int|null $combinationId The resolved combination, or null.
     *
     * @return array{step: string, min: string, max: string} Canonical exact
     *                                                       quantities.
     *
     * @since  2.0.0
     */
    private static function quantityRules(object $item, ?int $combinationId): array
    {
        // The combination carries the per-line quantity rules (step/min/max);
        // the canonical inventory unit for a combination line is the
        // combination itself.
        $comboRules = null;

        if ($combinationId) {
            foreach ($item->combinations ?? [] as $combo) {
                if ((int) ($combo->id ?? 0) === $combinationId) {
                    $comboRules = $combo;
                    break;
                }
            }
        }

        return [
            'step' => self::ruleValue(combination: $comboRules->quantity_step ?? null, item: $item->quantity_step ?? null),
            'min' => self::ruleValue(combination: $comboRules->quantity_min ?? null, item: $item->quantity_min ?? null),
            'max' => self::ruleValue(combination: $comboRules->quantity_max ?? null, item: $item->quantity_max ?? null),
        ];
    }

    /**
     * Whether a resolved rule is set: above zero, exactly.
     *
     * @param string $value A resolved rule ({@see self::ruleValue()}).
     *
     *
     * @since  2.0.0
     */
    private static function isPositive(string $value): bool
    {
        return \Alfa\Component\Alfa\Administrator\Service\Inventory\QuantityDecimal::compare(
            a: $value,
            b: \Alfa\Component\Alfa\Administrator\Service\Inventory\QuantityDecimal::ZERO,
        ) > 0;
    }

    /**
     * The minimum in whole units: the first whole quantity at or above it.
     *
     * @param string $min The resolved minimum.
     *
     * @return int 0 when the unit has no minimum.
     *
     * @since  2.0.0
     */
    private static function lineMinimum(string $min): int
    {
        return self::isPositive(value: $min)
            ? (int) \Alfa\Component\Alfa\Administrator\Service\Inventory\QuantityDecimal::ceilWhole(a: $min)
            : 0;
    }

    /**
     * The most whole units a line may hold under the maximum: its whole floor
     * within the line capacity, or the line capacity when there is no maximum.
     *
     * @param string $max The resolved maximum.
     *
     * @return int 0 when the maximum is below one unit.
     *
     * @since  2.0.0
     */
    private static function lineLimit(string $max): int
    {
        if (!self::isPositive(value: $max)) {
            return self::LINE_CAPACITY;
        }

        $floor = \Alfa\Component\Alfa\Administrator\Service\Inventory\QuantityDecimal::floorWhole(a: $max);

        return bccomp($floor, (string) self::LINE_CAPACITY, 0) > 0 ? self::LINE_CAPACITY : (int) $floor;
    }

    /**
     * The step's grid as a whole number a line quantity can be divided by.
     *
     * A grid above the line capacity is carried as one more than the capacity:
     * no line quantity is a multiple of either, and the remainder of any line
     * quantity is the same for both.
     *
     * @param string $step The resolved step.
     *
     * @return int At least 1.
     *
     * @since  2.0.0
     */
    private static function lineGrid(string $step): int
    {
        $grid = self::saleGrid(step: $step);
        $bound = (string) (self::LINE_CAPACITY + 1);

        return bccomp($grid, $bound, 0) > 0 ? (int) $bound : (int) $grid;
    }

    /**
     * The largest multiple of the grid at or below a quantity.
     *
     * @param int $quantity The quantity.
     * @param int $grid The grid, at least 1.
     *
     *
     * @since  2.0.0
     */
    private static function floorToMultiple(int $quantity, int $grid): int
    {
        $rest = $quantity % $grid;

        return $quantity - ($rest < 0 ? $rest + $grid : $rest);
    }

    /**
     * The SQL cap on a line quantity: the maximum's whole floor within the
     * line capacity, or the line capacity when there is no maximum.
     *
     * @param string $maxExpr SQL yielding the resolved maximum.
     *
     * @return string A parenthesised numeric SQL expression.
     *
     * @since  2.0.0
     */
    private static function capSql(string $maxExpr): string
    {
        return '(CASE WHEN (' . $maxExpr . ') > 0 THEN LEAST(FLOOR(' . $maxExpr . '), ' . self::LINE_CAPACITY . ')'
            . ' ELSE ' . self::LINE_CAPACITY . ' END)';
    }

    /**
     * The SQL first whole quantity the minimum allows: at least one.
     *
     * @param string $minExpr SQL yielding the resolved minimum.
     *
     * @return string A numeric SQL expression.
     *
     * @since  2.0.0
     */
    private static function firstSql(string $minExpr): string
    {
        return 'GREATEST(1, CEIL(' . $minExpr . '))';
    }
}
