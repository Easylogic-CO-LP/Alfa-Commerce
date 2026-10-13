<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/**
 * Whether a quantity of a priced unit is charged anything at all.
 *
 * A unit finer than its currency's scale is a valid price: 0.004 a piece is
 * 4.00 for a thousand. One piece of it is a line of 0.00, though, and goods
 * that have a price are not handed over for nothing because a line rounds to
 * nothing. So a QUANTITY is refused when the line's unit is above zero and
 * that unit times the quantity, rounded once at the currency's scale, is
 * zero. The price itself is never refused, a unit of zero is free at every
 * quantity, and a line a discount takes to zero is not asked about here: the
 * test is on the unit times the quantity, before any discount.
 *
 * The unit is held as money per pieces, two whole numbers, whatever the
 * number of places it is given with: a unit the engine states (eight places
 * at most), a stored payable unit, or what a piece is paid exactly, the
 * stated unit through every factor of its tax chain with nothing rounded. A
 * unit of more places than two whole numbers hold is held as it is and asked
 * the same question on its digits.
 *
 * Every command that creates a line or changes its quantity asks this before
 * it writes. Nothing here prices, raises a quantity or charges a minor unit:
 * the command is refused and says which quantity is the smallest one charged.
 *
 * @since  2.0.0
 */
final class ChargeableQuantity
{
    /**
     * The refusal, naming the smallest quantity that is charged.
     *
     * @since  2.0.0
     */
    public const TOO_SMALL = 'COM_ALFA_QUANTITY_TOO_SMALL_TO_CHARGE';

    /**
     * The refusal when no quantity that may be ordered is charged.
     *
     * @since  2.0.0
     */
    public const NEVER = 'COM_ALFA_QUANTITY_NEVER_CHARGED';

    /**
     * The most places of a unit's fraction that one whole number holds.
     *
     * @since  2.0.0
     */
    private const WHOLE_PLACES = 18;

    /**
     * The largest quantity a unit held on its digits is multiplied by; past it no
     * line can hold the quantity anyway.
     *
     * @since  2.0.0
     */
    private const MOST = 900000000000000;

    /**
     * @param int $amount What $pieces pieces cost before any discount, in minor units; zero or below when
     *                    the unit is.
     * @param int $pieces The pieces that amount is for, one or more.
     * @param ExactAmount|null $fine A unit of more places than two whole numbers hold, as it is; null otherwise.
     * @param int $scale The currency's decimal places, for a unit held as it is.
     *
     * @since  2.0.0
     */
    private function __construct(
        private readonly int $amount,
        private readonly int $pieces,
        private readonly ?ExactAmount $fine = null,
        private readonly int $scale = 0,
    ) {
    }

    /**
     * A unit given as its decimal text, in the currency its line is charged in,
     * of any number of places.
     *
     * A unit of p places is a count of 10^-p, and 10^(p - scale) of them make
     * one minor unit: 0.004 at two decimals is 4 minor units for 1,000 pieces,
     * and 0.0049999988 is 49,999,988 for 10^10. A unit of one whole currency
     * unit or more is one minor unit a piece at the least, which is all the
     * question needs of it.
     *
     * @param string $unit A plain decimal: a canonical unit, a stored payable unit, or an exact payable unit
     *                     ({@see PriceResult::getPayableUnitExact()}).
     * @param Currency $currency The currency, at the scale the line is charged in.
     *
     *
     * @since  2.0.0
     */
    public static function ofUnit(string $unit, Currency $currency): self
    {
        $value = ExactAmount::of($unit);

        if (!$value->isPositive()) {
            return new self(amount: 0, pieces: 1);
        }

        if ($unit[0] !== '0') {
            return new self(amount: 1, pieces: 1);
        }

        $scale = $currency->getDecimalPlaces();
        $fraction = rtrim(explode('.', $unit)[1] ?? '', '0');
        $places = \strlen($fraction);

        // Whole minor units a piece.
        if ($places <= $scale) {
            return new self(amount: (int) str_pad($fraction, $scale, '0'), pieces: 1);
        }

        if ($places <= self::WHOLE_PLACES) {
            return new self(amount: (int) ltrim($fraction, '0'), pieces: 10 ** ($places - $scale));
        }

        return new self(amount: 1, pieces: 1, fine: $value, scale: $scale);
    }

    /**
     * Whether a line of this quantity is refused: its unit is above zero and
     * the line settles at zero.
     *
     * The line is the quantity times the unit, rounded once, half away from
     * zero: on integers by the one exact scaling money is computed with
     * ({@see Money::scaleCount()}), or on the digits of a unit too fine for
     * them.
     *
     * @param int $quantity The quantity the line would hold.
     *
     *
     * @since  2.0.0
     */
    public function refuses(int $quantity): bool
    {
        if ($this->fine !== null) {
            return $quantity > 0 && $this->fine->times(min($quantity, self::MOST))->roundedAt($this->scale)->isZero();
        }

        return $this->amount > 0
            && $quantity > 0
            && Money::scaleCount(count: $quantity, numerator: $this->amount, denominator: $this->pieces) === 0;
    }

    /**
     * The smallest quantity that costs at least one minor unit.
     *
     * The line rounds to one minor unit from half of one, so it is the first
     * quantity at or above pieces / (2 x amount): 2 for 0.004 at two decimals
     * (0.008 is 0.01), 1 for 0.005 (a tie rounds away from zero). One for a
     * unit of zero or below, of which no quantity is refused. A unit too fine
     * for two whole numbers is asked quantity by quantity, doubling and then
     * halving the step, on the same rounding as {@see self::refuses()}; past
     * any quantity a line can hold the answer is PHP_INT_MAX.
     *
     *
     * @since  2.0.0
     */
    public function smallest(): int
    {
        if ($this->fine !== null) {
            return $this->firstCharged();
        }

        // Twice the amount is formed only where it is below the pieces.
        if ($this->amount <= 0 || $this->amount >= intdiv($this->pieces + 1, 2)) {
            return 1;
        }

        return intdiv($this->pieces + 2 * $this->amount - 1, 2 * $this->amount);
    }

    /**
     * The first quantity a unit held on its digits charges: the quantity is
     * doubled until it is charged, then the gap is halved.
     *
     *
     * @since  2.0.0
     */
    private function firstCharged(): int
    {
        if (!$this->refuses(quantity: 1)) {
            return 1;
        }

        $refused = 1;
        $charged = 2;

        while ($this->refuses(quantity: $charged)) {
            if ($charged >= self::MOST) {
                return PHP_INT_MAX;
            }

            $refused = $charged;
            $charged = min(2 * $charged, self::MOST);
        }

        while ($charged - $refused > 1) {
            $middle = intdiv($refused + $charged, 2);

            if ($this->refuses(quantity: $middle)) {
                $refused = $middle;
            } else {
                $charged = $middle;
            }
        }

        return $charged;
    }

    /**
     * The refusal, in the reader's language: the one sentence every surface says.
     *
     * @param int|null $smallest The smallest quantity that may be ordered and is charged; null when there is none.
     *
     *
     * @since  2.0.0
     */
    public static function sentence(?int $smallest): string
    {
        return $smallest === null ? Text::_(self::NEVER) : Text::sprintf(self::TOO_SMALL, $smallest);
    }
}
