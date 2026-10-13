<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

use InvalidArgumentException;

/**
 * AN AMOUNT CARRIED EXACTLY THROUGH THE PRICING OF ONE LINE.
 *
 * A line is calculated from its authoritative unit at full precision: the unit
 * times the quantity, every discount taken from that, each tax charged on the
 * exact running amount, every after-tax discount taken from the exact gross.
 * Nothing is rounded to the currency's scale until a figure is recorded, and a
 * recorded figure is rounded once, half away from zero ({@see self::toMoney()}).
 *
 * Every such amount is a terminating decimal: a unit has at most eight places,
 * and a percentage or a tax rate multiplies by a whole count over 10^10. So the
 * value is held as its decimal digits and the number of places after the
 * point, with a sign: no float holds it, and no size limits it.
 *
 * @since  2.0.0
 */
final class ExactAmount
{
    /**
     * How many digits an integer always holds.
     *
     * @since  2.0.0
     */
    private const INT_DIGITS = 18;

    /**
     * @param bool $negative Whether the amount is below zero; never true for zero.
     * @param string $digits Its digits, with no leading zero ('0' for zero).
     * @param int $places How many of them follow the point; the last one is never a zero.
     */
    private function __construct(
        private readonly bool $negative,
        private readonly string $digits,
        private readonly int $places,
    ) {
    }

    /**
     * A plain decimal, as written: a catalogue unit or any other decimal text.
     *
     * @param string $decimal An optional minus, digits, and an optional point with digits.
     *
     *
     * @throws InvalidArgumentException When the text is not a plain decimal.
     *
     * @since  2.0.0
     */
    public static function of(string $decimal): self
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $decimal, $m) !== 1) {
            throw new InvalidArgumentException(sprintf("An exact amount is a plain decimal, not '%s'.", $decimal));
        }

        $fraction = $m[3] ?? '';

        return self::normal(negative: $m[1] === '-', digits: $m[2] . $fraction, places: \strlen($fraction));
    }

    /**
     * A stored amount as the decimal it states: a column's text as written,
     * an integer as itself, and a float at the fifteen significant digits it
     * carries, as Money reads one ({@see Money::floatDigits()}): 0.1 is 0.1,
     * not the binary fraction beside it.
     *
     * @param string|int|float $value The amount; other numeric text is read as the float it names.
     *
     *
     * @throws InvalidArgumentException When the value is not a finite number.
     *
     * @since  2.0.0
     */
    public static function ofNumber(string|int|float $value): self
    {
        if (\is_int($value)) {
            return self::of((string) $value);
        }

        if (\is_string($value)) {
            if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1) {
                return self::of($value);
            }

            if (!is_numeric($value)) {
                throw new InvalidArgumentException(sprintf("An exact amount is a number, not '%s'.", $value));
            }

            $value = (float) $value;
        }

        [$integer, $fraction] = Money::floatDigits(value: abs($value), shortest: false);

        return self::of(($value < 0 ? '-' : '') . $integer . ($fraction === '' ? '' : '.' . $fraction));
    }

    /**
     * Money, exactly: its minor units at its currency's scale.
     *
     * @param Money $money The amount.
     *
     *
     * @since  2.0.0
     */
    public static function ofMoney(Money $money): self
    {
        $minorUnits = $money->getMinorUnits();

        return self::normal(
            negative: $minorUnits < 0,
            digits: ltrim((string) $minorUnits, '-'),
            places: $money->getCurrency()->getDecimalPlaces(),
        );
    }

    /**
     * This amount times a whole number.
     *
     * @param int $whole The multiplier (a quantity).
     *
     *
     * @since  2.0.0
     */
    public function times(int $whole): self
    {
        return $this->timesCount(count: $whole, places: 0);
    }

    /**
     * This amount times a count of a decimal place: amount x count / 10^places,
     * exactly. A percentage of 10^-8 percent counts is a count over 10^10, and a
     * tax factor (1 + rate / 100) is 10^10 plus the rate's count, over 10^10.
     *
     * @param int $count The count.
     * @param int $places The place it counts, zero or more.
     *
     *
     * @since  2.0.0
     */
    public function timesCount(int $count, int $places): self
    {
        if ($count === PHP_INT_MIN) {
            throw new InvalidArgumentException('A count of that size cannot multiply an amount.');
        }

        return self::normal(
            negative: $this->negative !== ($count < 0),
            digits: self::multiply(digits: $this->digits, by: abs($count)),
            places: $this->places + $places,
        );
    }

    /**
     * This amount divided by a whole number, held at a number of places: the
     * quotient is taken digit by digit one place further than kept, then
     * rounded once, half away from zero.
     *
     * @param int $whole The divisor; not zero, of at most seventeen digits.
     * @param int $places The places the quotient is held at, zero or more.
     *
     *
     * @throws InvalidArgumentException When the divisor is zero or too large to divide by.
     *
     * @since  2.0.0
     */
    public function dividedBy(int $whole, int $places): self
    {
        if ($whole === 0 || $whole === PHP_INT_MIN || abs($whole) > intdiv(PHP_INT_MAX - 9, 10)) {
            throw new InvalidArgumentException('An amount is divided by a whole number that is not zero and has at most seventeen digits.');
        }

        $extra = max(0, $places + 1 - $this->places);
        $divisor = abs($whole);
        $quotient = '';
        $rest = 0;

        foreach (str_split($this->digits . str_repeat('0', $extra)) as $digit) {
            $rest = $rest * 10 + (int) $digit;
            $quotient .= (string) intdiv($rest, $divisor);
            $rest %= $divisor;
        }

        return self::normal(negative: $this->negative !== ($whole < 0), digits: $quotient, places: $this->places + $extra)->roundedAt($places);
    }

    /**
     * This amount taken through factors, each a count over 10^10, held at a
     * number of places after each one: how a derived unit is carried through a
     * tax chain.
     *
     * @param int[] $counts Each factor, as its count over 10^10.
     * @param int $places The places it is held at after each factor.
     *
     *
     * @since  2.0.0
     */
    public function throughFactors(array $counts, int $places): self
    {
        $amount = $this;

        foreach ($counts as $count) {
            $amount = $amount->timesCount(count: $count, places: 10)->roundedAt($places);
        }

        return $amount;
    }

    /**
     * This amount plus another.
     *
     * @param self $other The other amount.
     *
     *
     * @since  2.0.0
     */
    public function plus(self $other): self
    {
        $places = max($this->places, $other->places);
        $mine = $this->digits . str_repeat('0', $places - $this->places);
        $theirs = $other->digits . str_repeat('0', $places - $other->places);

        if ($this->negative === $other->negative) {
            return self::normal(negative: $this->negative, digits: self::add(a: $mine, b: $theirs), places: $places);
        }

        // Opposite signs: the larger magnitude keeps its sign.
        return self::compareDigits(a: $mine, b: $theirs) >= 0
            ? self::normal(negative: $this->negative, digits: self::subtract(a: $mine, b: $theirs), places: $places)
            : self::normal(negative: $other->negative, digits: self::subtract(a: $theirs, b: $mine), places: $places);
    }

    /**
     * This amount less another.
     *
     * @param self $other The other amount.
     *
     *
     * @since  2.0.0
     */
    public function minus(self $other): self
    {
        return $this->plus($other->negated());
    }

    /**
     * The same amount with the other sign.
     *
     *
     * @since  2.0.0
     */
    public function negated(): self
    {
        return self::normal(negative: !$this->negative, digits: $this->digits, places: $this->places);
    }

    /**
     * -1, 0 or 1 as this amount is below, equal to or above another.
     *
     * @param self $other The other amount.
     *
     *
     * @since  2.0.0
     */
    public function compare(self $other): int
    {
        if ($this->negative !== $other->negative) {
            return $this->negative ? -1 : 1;
        }

        $places = max($this->places, $other->places);
        $magnitude = self::compareDigits(
            a: $this->digits . str_repeat('0', $places - $this->places),
            b: $other->digits . str_repeat('0', $places - $other->places),
        );

        return $this->negative ? -$magnitude : $magnitude;
    }

    /**
     * Whether the amount is above zero.
     *
     *
     * @since  2.0.0
     */
    public function isPositive(): bool
    {
        return !$this->negative && $this->digits !== '0';
    }

    /**
     * Whether the amount is below zero.
     *
     *
     * @since  2.0.0
     */
    public function isNegative(): bool
    {
        return $this->negative;
    }

    /**
     * Whether the amount is zero.
     *
     *
     * @since  2.0.0
     */
    public function isZero(): bool
    {
        return $this->digits === '0';
    }

    /**
     * The amount held at a number of places, rounded half away from zero when
     * it has more: how a derived unit is held at its eighth place.
     *
     * @param int $places The places to keep, zero or more.
     *
     *
     * @since  2.0.0
     */
    public function roundedAt(int $places): self
    {
        if ($this->places <= $places) {
            return $this;
        }

        $cut = $this->places - $places;
        $digits = str_pad($this->digits, $cut + 1, '0', STR_PAD_LEFT);
        $kept = substr($digits, 0, -$cut);

        return self::normal(
            negative: $this->negative,
            digits: $digits[\strlen($digits) - $cut] >= '5' ? self::add(a: $kept, b: '1') : $kept,
            places: $places,
        );
    }

    /**
     * The amount as recorded money: rounded ONCE at the currency's scale, half
     * away from zero, on its digits ({@see Money::of()}).
     *
     * @param Currency $currency The line's currency.
     *
     *
     * @throws InvalidArgumentException When the amount is more than minor units can hold.
     *
     * @since  2.0.0
     */
    public function toMoney(Currency $currency): Money
    {
        return Money::of($this->text(), $currency);
    }

    /**
     * The amount as a plain decimal, with no padding zero.
     *
     *
     * @since  2.0.0
     */
    public function text(): string
    {
        $digits = str_pad($this->digits, $this->places + 1, '0', STR_PAD_LEFT);
        $text = $this->places === 0 ? $digits : substr($digits, 0, -$this->places) . '.' . substr($digits, -$this->places);

        return ($this->negative ? '-' : '') . $text;
    }

    /**
     * The one form of a value: no leading zero, no trailing zero after the
     * point, and zero never negative.
     *
     * @param bool $negative Whether it is below zero.
     * @param string $digits Its digits.
     * @param int $places How many of them follow the point.
     */
    private static function normal(bool $negative, string $digits, int $places): self
    {
        if ($places > 0) {
            // The zeros that end the fraction carry nothing: drop as many as it has.
            $trimmed = rtrim($digits, '0');
            $drop = min($places, \strlen($digits) - \strlen($trimmed));
            $digits = substr($digits, 0, \strlen($digits) - $drop);
            $places -= $drop;
        }

        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return new self(false, '0', 0);
        }

        return new self($negative, $digits, $places);
    }

    /**
     * Digits times a whole number, zero or more.
     *
     * @param string $digits The digits of a whole number.
     * @param int $by The multiplier.
     */
    private static function multiply(string $digits, int $by): string
    {
        if (\strlen($digits) <= self::INT_DIGITS) {
            // An integer product that overflows is answered with a float.
            $product = (int) $digits * $by;

            if (\is_int($product)) {
                return (string) $product;
            }
        }

        // Four digits at a time, from the right: a group times the multiplier,
        // plus what the group before it carried, is one whole number for any
        // multiplier below 9 x 10^14.
        if ($by > 900000000000000) {
            throw new InvalidArgumentException('A count of that size cannot multiply an amount.');
        }

        $product = '';
        $carry = 0;

        for ($end = \strlen($digits); $end > 0; $end -= 4) {
            $start = max(0, $end - 4);
            $carry += (int) substr($digits, $start, $end - $start) * $by;
            $product = str_pad((string) ($carry % 10000), 4, '0', STR_PAD_LEFT) . $product;
            $carry = intdiv($carry, 10000);
        }

        return $carry . $product;
    }

    /**
     * The sum of two whole numbers written in digits.
     *
     * @param string $a Digits.
     * @param string $b Digits.
     */
    private static function add(string $a, string $b): string
    {
        if (\strlen($a) <= self::INT_DIGITS && \strlen($b) <= self::INT_DIGITS) {
            return (string) ((int) $a + (int) $b);
        }

        $width = max(\strlen($a), \strlen($b));
        $a = str_pad($a, $width, '0', STR_PAD_LEFT);
        $b = str_pad($b, $width, '0', STR_PAD_LEFT);
        $sum = '';
        $carry = 0;

        for ($end = $width; $end > 0; $end -= 9) {
            $start = max(0, $end - 9);
            $group = (int) substr($a, $start, $end - $start) + (int) substr($b, $start, $end - $start) + $carry;
            $carry = intdiv($group, 10 ** ($end - $start));
            $sum = str_pad((string) ($group % 10 ** ($end - $start)), $end - $start, '0', STR_PAD_LEFT) . $sum;
        }

        return ($carry > 0 ? (string) $carry : '') . $sum;
    }

    /**
     * The difference of two whole numbers written in digits, the first the larger.
     *
     * @param string $a Digits, at least $b.
     * @param string $b Digits.
     */
    private static function subtract(string $a, string $b): string
    {
        if (\strlen($a) <= self::INT_DIGITS) {
            return (string) ((int) $a - (int) $b);
        }

        $b = str_pad($b, \strlen($a), '0', STR_PAD_LEFT);
        $difference = '';
        $borrow = 0;

        for ($end = \strlen($a); $end > 0; $end -= 9) {
            $start = max(0, $end - 9);
            $group = (int) substr($a, $start, $end - $start) - (int) substr($b, $start, $end - $start) - $borrow;
            $borrow = $group < 0 ? 1 : 0;
            $difference = str_pad((string) ($group + $borrow * 10 ** ($end - $start)), $end - $start, '0', STR_PAD_LEFT) . $difference;
        }

        return $difference;
    }

    /**
     * -1, 0 or 1 as one whole number written in digits is below, equal to or
     * above another.
     *
     * @param string $a Digits.
     * @param string $b Digits.
     */
    private static function compareDigits(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');

        return \strlen($a) === \strlen($b) ? strcmp($a, $b) <=> 0 : \strlen($a) <=> \strlen($b);
    }
}
