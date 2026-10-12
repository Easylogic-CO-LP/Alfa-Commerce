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
 * THE TEXT A DECIMAL COLUMN IS HANDED FOR A MONEY OR UNIT FIGURE.
 *
 * A figure reaches the database as the exact decimal text of its Money, or of
 * its ExactAmount for a unit or a rate, and never as a PHP float: a database
 * driver prints a float with fourteen significant digits, so 99,999,999.99999999
 * would be written as 100,000,000. The text is padded to the column's scale,
 * which is exactly how the column prints it back.
 *
 * The column never rounds and never overflows on its own: a figure finer than
 * its scale, or with more whole digits than it holds, is refused here by name,
 * and a typed figure is refused the same way where it is typed
 * ({@see self::refusal()}: the cart rule form's DecimalRule, an option's
 * delta, the REST verbs). Rounding belongs to the figure's Money boundary,
 * before it is written.
 *
 * @since  1.14.0
 */
final class StoredDecimal
{
    /**
     * The digits of the money and unit columns, DECIMAL(24,8).
     *
     * @since  1.14.0
     */
    public const PRECISION = 24;

    /**
     * The places of the money and unit columns, DECIMAL(24,8).
     *
     * @since  1.14.0
     */
    public const SCALE = 8;

    /**
     * {@see self::refusal()}: the figure has more places than the column.
     *
     * @since  1.14.0
     */
    public const FINER = 'finer';

    /**
     * {@see self::refusal()}: the figure has more whole digits than the column.
     *
     * @since  1.14.0
     */
    public const LARGER = 'larger';

    /**
     * Why a DECIMAL(precision, scale) column cannot hold a figure as it is.
     *
     * The one test {@see self::of()} refuses by, asked without a raise where a
     * typed figure is refused with the operator's own words.
     *
     * @param ExactAmount $value The figure on its digits.
     * @param int $precision The column's digits.
     * @param int $scale The column's places.
     *
     * @return string|null self::FINER, self::LARGER, or null when it holds the figure.
     *
     * @since  1.14.0
     */
    public static function refusal(ExactAmount $value, int $precision = self::PRECISION, int $scale = self::SCALE): ?string
    {
        [$whole, $places] = array_pad(explode('.', ltrim($value->text(), '-'), 2), 2, '');

        return match (true) {
            \strlen($places) > $scale => self::FINER,
            $whole !== '0' && \strlen($whole) > $precision - $scale => self::LARGER,
            default => null,
        };
    }

    /**
     * The text a DECIMAL(precision, scale) column is handed for a figure.
     *
     * @param Money|ExactAmount|null $value The figure; null for a column that states none.
     * @param int $precision The column's digits.
     * @param int $scale The column's places.
     *
     * @return string|null The decimal text at the column's scale, e.g. '12.34000000'; null for null.
     *
     * @throws InvalidArgumentException When the figure is finer than the column's scale or larger than it holds.
     *
     * @since  1.14.0
     */
    public static function of(Money|ExactAmount|null $value, int $precision = self::PRECISION, int $scale = self::SCALE): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($scale < 0 || $precision < 1 || $scale > $precision) {
            throw new InvalidArgumentException(sprintf('DECIMAL(%d,%d) is not a column a figure is written to.', $precision, $scale));
        }

        $exact = $value instanceof Money ? ExactAmount::ofMoney($value) : $value;
        $text = $exact->text();

        // An ExactAmount's text: an optional minus, its whole digits, and its
        // places without a trailing zero.
        $negative = str_starts_with($text, '-');
        [$whole, $places] = array_pad(explode('.', ltrim($text, '-'), 2), 2, '');

        match (self::refusal(value: $exact, precision: $precision, scale: $scale)) {
            self::FINER => throw new InvalidArgumentException(sprintf("'%s' is finer than a column of %d decimal places holds.", $text, $scale)),
            self::LARGER => throw new InvalidArgumentException(sprintf("'%s' is larger than a DECIMAL(%d,%d) column holds.", $text, $precision, $scale)),
            null => null,
        };

        return ($negative ? '-' : '') . $whole . ($scale > 0 ? '.' . str_pad($places, $scale, '0') : '');
    }
}
