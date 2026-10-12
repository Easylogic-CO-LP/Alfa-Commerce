<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

use InvalidArgumentException;

\defined('_JEXEC') or die;

/**
 * Exact quantities: the PHP side of the canonical quantity type, DECIMAL(24,8).
 *
 * A quantity is a canonical string with exactly eight decimals ('2.50000000'),
 * the form the database driver returns for a DECIMAL(24,8) column. Every
 * comparison and every calculation runs through bcmath at that scale, so PHP
 * reaches exactly the answer SQL reaches over the stored value: there is no
 * float conversion and no tolerance anywhere in this class.
 *
 * Pure: it reads nothing and writes nothing.
 *
 * @since  1.14.0
 */
final class QuantityDecimal
{
    /**
     * Decimal places of the quantity type.
     *
     * @since  1.14.0
     */
    public const SCALE = 8;

    /**
     * Digits the quantity type holds before the decimal point.
     *
     * @since  1.14.0
     */
    public const INTEGER_DIGITS = 16;

    /**
     * Zero in canonical form.
     *
     * @since  1.14.0
     */
    public const ZERO = '0.00000000';

    /**
     * Ten to the power SCALE: a quantity times this is a whole number.
     *
     * @since  1.14.0
     */
    public const SCALE_FACTOR = '100000000';

    /**
     * How far a quantity is from being whole, one ladder per prime factor of
     * SCALE_FACTOR (2 and 5).
     *
     * A quantity is n / 10^8 for a whole n, and its smallest whole multiple is
     * the quantity times 2^(8 - min(v2, 8)) times 5^(8 - min(v5, 8)), where v2
     * and v5 count the factors 2 and 5 in n. Each ladder maps a divisor to that
     * factor, tested in order: the first divisor that divides n gives the
     * factor, and the last divisor (1) catches every n. The PHP form
     * ({@see self::wholeGrid()}) and the SQL form built from the same ladders
     * therefore take the same branch for every value.
     *
     * @since  1.14.0
     */
    public const WHOLE_GRID_LADDERS = [
        [256 => 1, 128 => 2, 64 => 4, 32 => 8, 16 => 16, 8 => 32, 4 => 64, 2 => 128, 1 => 256],
        [390625 => 1, 78125 => 5, 15625 => 25, 3125 => 125, 625 => 625, 125 => 3125, 25 => 15625, 5 => 78125, 1 => 390625],
    ];

    /**
     * Read a posted or API value as an exact quantity.
     *
     * Accepted: an integer; a string in plain decimal notation once trimmed (an
     * optional leading minus, digits, a '.' separator); a float, through its
     * shortest round-trip digits ({@see self::exportFloat()}), whatever the
     * host's serialize_precision, an exponent being shifted into plain
     * notation digit by digit. Trailing zeros after the point are ignored, so
     * '1.000000000' is exactly one.
     *
     * Refused (null): a string with an exponent, a sign other than a leading
     * minus, a thousands separator or any other character; NAN and INF;
     * booleans, arrays, objects and null; a value with more than SCALE
     * significant decimals or more than INTEGER_DIGITS digits before the point.
     *
     * @param mixed $value The value as posted, decoded or read.
     *
     * @return string|null The canonical string, or null when the value is not
     *                     an exact quantity of this type.
     *
     * @since  1.14.0
     */
    public static function parse(mixed $value): ?string
    {
        if (\is_int($value)) {
            $text = (string) $value;
        } elseif (\is_float($value)) {
            if (!is_finite($value)) {
                return null;
            }

            $text = self::plainDigits(exported: self::exportFloat(value: $value));
        } elseif (\is_string($value)) {
            $text = trim($value);
        } else {
            return null;
        }

        if ($text === null || !preg_match('/^(-?)(\d*)(?:\.(\d*))?$/D', $text, $parts)) {
            return null;
        }

        $whole = $parts[2];
        $fraction = $parts[3] ?? '';

        if ($whole === '' && $fraction === '') {
            return null;
        }

        $whole = ltrim($whole, '0');
        $fraction = rtrim($fraction, '0');

        if (\strlen($whole) > self::INTEGER_DIGITS || \strlen($fraction) > self::SCALE) {
            return null;
        }

        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($fraction, self::SCALE, '0');

        // Negative zero is zero.
        $negative = $parts[1] === '-' && ($whole !== '0' || trim($fraction, '0') !== '');

        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }

    /**
     * A stored value as an exact quantity.
     *
     * @param mixed $value The value as the database driver returns it, or an
     *                     integer.
     *
     * @return string The canonical string.
     *
     * @throws InvalidArgumentException When the value is not an exact quantity
     *                                  of this type ({@see self::parse()}).
     *
     * @since  1.14.0
     */
    public static function canonical(mixed $value): string
    {
        $canonical = self::parse(value: $value);

        if ($canonical === null) {
            throw new InvalidArgumentException(
                'Not an exact quantity with at most ' . self::SCALE . ' decimal places and '
                . self::INTEGER_DIGITS . ' digits before the decimal point: ' . get_debug_type($value) . '.',
            );
        }

        return $canonical;
    }

    /**
     * Compare two quantities exactly.
     *
     * @param string|int|float $a The first quantity.
     * @param string|int|float $b The second quantity.
     *
     * @return int -1, 0 or 1 as a is below, equal to or above b.
     *
     * @throws InvalidArgumentException When either is not an exact quantity.
     *
     * @since  1.14.0
     */
    public static function compare(string|int|float $a, string|int|float $b): int
    {
        return bccomp(self::canonical(value: $a), self::canonical(value: $b), self::SCALE);
    }

    /**
     * Whether a quantity is exactly zero.
     *
     * @param string|int|float $a The quantity.
     *
     *
     * @throws InvalidArgumentException When it is not an exact quantity.
     *
     * @since  1.14.0
     */
    public static function isZero(string|int|float $a): bool
    {
        return bccomp(self::canonical(value: $a), self::ZERO, self::SCALE) === 0;
    }

    /**
     * Whether a quantity is below zero.
     *
     * @param string|int|float $a The quantity.
     *
     *
     * @throws InvalidArgumentException When it is not an exact quantity.
     *
     * @since  1.14.0
     */
    public static function isNegative(string|int|float $a): bool
    {
        return bccomp(self::canonical(value: $a), self::ZERO, self::SCALE) < 0;
    }

    /**
     * The smallest whole number at or above a quantity.
     *
     * @param string|int|float $a The quantity.
     *
     * @return string A whole number without decimals ('3' for 2.99999950).
     *
     * @throws InvalidArgumentException When it is not an exact quantity.
     *
     * @since  1.14.0
     */
    public static function ceilWhole(string|int|float $a): string
    {
        $value = self::canonical(value: $a);
        $truncated = bcadd($value, '0', 0);

        if (bccomp($value, $truncated, self::SCALE) > 0) {
            return bcadd($truncated, '1', 0);
        }

        return $truncated;
    }

    /**
     * The largest whole number at or below a quantity.
     *
     * @param string|int|float $a The quantity.
     *
     * @return string A whole number without decimals ('2' for 2.99999950).
     *
     * @throws InvalidArgumentException When it is not an exact quantity.
     *
     * @since  1.14.0
     */
    public static function floorWhole(string|int|float $a): string
    {
        $value = self::canonical(value: $a);
        $truncated = bcadd($value, '0', 0);

        if (bccomp($value, $truncated, self::SCALE) < 0) {
            return bcsub($truncated, '1', 0);
        }

        return $truncated;
    }

    /**
     * The smallest positive whole number that is an exact multiple of a step.
     *
     * The step times 2^(8 - min(v2, 8)) times 5^(8 - min(v5, 8)), with v2 and
     * v5 read from the step times SCALE_FACTOR through
     * {@see self::WHOLE_GRID_LADDERS}: 1 for a step of 0.5, 3 for 1.5 and for
     * 0.3, 5999999 for 2.99999950, the step itself for a whole step.
     *
     * @param string|int|float $step The step.
     *
     * @return string A whole number without decimals; '1' for a step of zero
     *                or below, which constrains nothing.
     *
     * @throws InvalidArgumentException When it is not an exact quantity.
     *
     * @since  1.14.0
     */
    public static function wholeGrid(string|int|float $step): string
    {
        $value = self::canonical(value: $step);

        if (bccomp($value, self::ZERO, self::SCALE) <= 0) {
            return '1';
        }

        $units = bcmul($value, self::SCALE_FACTOR, 0);
        $factor = '1';

        foreach (self::WHOLE_GRID_LADDERS as $ladder) {
            foreach ($ladder as $divisor => $multiplier) {
                if (bcmod($units, (string) $divisor, 0) === '0') {
                    $factor = bcmul($factor, (string) $multiplier, 0);

                    break;
                }
            }
        }

        // units x factor is a whole multiple of SCALE_FACTOR, so the division
        // is exact.
        return bcdiv(bcmul($units, $factor, 0), self::SCALE_FACTOR, 0);
    }

    /**
     * A quantity for display: exact, without trailing zeros or a trailing point.
     *
     * @param string|int|float $a The quantity.
     *
     * @return string '1' for 1.00000000, '2.9999995' for 2.99999950.
     *
     * @throws InvalidArgumentException When it is not an exact quantity.
     *
     * @since  1.14.0
     */
    public static function display(string|int|float $a): string
    {
        return rtrim(rtrim(self::canonical(value: $a), '0'), '.');
    }

    /**
     * A float's shortest round-trip digits, as var_export prints them.
     *
     * var_export follows serialize_precision, and only its value -1 prints the
     * shortest digits that read back as the same float (0.1, never
     * 0.10000000000000001). The setting is pinned to -1 for the export and put
     * back afterwards, so a host configured otherwise reads a float the same
     * way.
     *
     * @param float $value A finite float.
     *
     * @return string What var_export printed, e.g. '2.5' or '1.0E-5'.
     *
     * @since  1.14.0
     */
    private static function exportFloat(float $value): string
    {
        $precision = ini_get(option: 'serialize_precision');
        $pinned = $precision !== '-1';

        if ($pinned) {
            ini_set(option: 'serialize_precision', value: '-1');
        }

        try {
            return var_export(value: $value, return: true);
        } finally {
            if ($pinned && \is_string($precision)) {
                ini_set(option: 'serialize_precision', value: $precision);
            }
        }
    }

    /**
     * The digits var_export printed for a float, in plain decimal notation.
     *
     * An exponent is applied by moving the decimal point over the printed
     * digits, so the digits themselves are never recomputed. A finite float's
     * exponent is small enough that the result stays short; one outside the
     * quantity type is then refused by parse() on its length.
     *
     * @param string $exported What var_export printed, e.g. '2.5', '1.0E-5' or
     *                         '1.0E+25'.
     *
     * @return string|null Plain decimal notation, or null when the text is not
     *                     one var_export prints for a finite float.
     *
     * @since  1.14.0
     */
    private static function plainDigits(string $exported): ?string
    {
        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:E([+-]?\d+))?$/D', $exported, $parts)) {
            return null;
        }

        if (($parts[4] ?? '') === '') {
            return $exported;
        }

        $digits = $parts[2] . ($parts[3] ?? '');
        $point = \strlen($parts[2]) + (int) $parts[4];

        if ($point <= 0) {
            return $parts[1] . '0.' . str_repeat('0', -$point) . $digits;
        }

        if ($point >= \strlen($digits)) {
            return $parts[1] . $digits . str_repeat('0', $point - \strlen($digits));
        }

        return $parts[1] . substr($digits, 0, $point) . '.' . substr($digits, $point);
    }
}
