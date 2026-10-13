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
 * THE TEXT A PUBLISHED DOCUMENT CARRIES FOR A MONEY FIGURE, A UNIT, A RATE OR A PERCENTAGE.
 *
 * Every canonical figure Alfa publishes as data (the REST resources, the storefront
 * search payload, the back office's JSON) is an exact decimal string, never a JSON
 * number. A PHP float is printed by json_encode() at the server's serialize_precision,
 * which may show its binary expansion (12.4 as 12.4000000000000003552...), and a
 * float cannot hold every figure a column holds. So each figure is formed from its
 * Money or from its exact decimal text, with no float in between, and keeps the
 * precision its own rule gives it:
 *
 *  - money: the Money at its currency's scale ("12.40", "1000", "105.637");
 *  - a unit or a stored amount of a currency: its own digits, never fewer than the
 *    currency's scale ("12.40", "0.004464", "0.125");
 *  - a rate, a percentage or a figure with no currency: its own digits ("24", "6.5").
 *
 * The text a person reads is published beside it, as its own field.
 *
 * @since  2.0.0
 */
final class PublishedDecimal
{
    /**
     * The most places a published unit or rate keeps: the places of a stored unit,
     * where a derived unit is held as well.
     *
     * @since  2.0.0
     */
    public const PLACES = 8;

    /**
     * Money at its currency's scale: its minor units with the point placed.
     *
     * @param Money $money The amount.
     *
     * @return string E.g. '12.40', '-3.50', '1000' at scale 0, '105.637' at scale 3.
     *
     * @since  2.0.0
     */
    public static function money(Money $money): string
    {
        $minorUnits = $money->getMinorUnits();
        $scale = $money->getCurrency()->getDecimalPlaces();
        $digits = str_pad(ltrim((string) $minorUnits, '-'), $scale + 1, '0', STR_PAD_LEFT);
        $text = $scale === 0 ? $digits : substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);

        return ($minorUnits < 0 ? '-' : '') . $text;
    }

    /**
     * A unit price, an agreed rate or a stored amount of a currency: its own digits,
     * never fewer than the currency's scale.
     *
     * The stored branch of the one unit rule ({@see Currency::formatStored()}),
     * without grouping: trailing zeros past the scale are dropped, and a figure finer
     * than the scale keeps every digit it has.
     *
     * @param ExactAmount|string $value The figure: its exact amount, or its stored text.
     * @param int $scale The currency's scale.
     *
     * @return string E.g. '12.40', '0.004464', '0.125' at scale 2.
     *
     * @throws InvalidArgumentException When the text is not a number.
     *
     * @since  2.0.0
     */
    public static function unit(ExactAmount|string $value, int $scale): string
    {
        $text = self::held(value: $value)->text();
        $negative = str_starts_with($text, '-');
        [$whole, $places] = array_pad(explode('.', ltrim($text, '-'), 2), 2, '');
        $places = str_pad($places, max(0, $scale), '0');

        return ($negative ? '-' : '') . $whole . ($places === '' ? '' : '.' . $places);
    }

    /**
     * A rate, a percentage, a factor, or a stored figure with no currency to state it
     * at: its own digits, with no trailing zero.
     *
     * @param ExactAmount|string $value The figure: its exact amount, or its stored text.
     *
     * @return string E.g. '24', '6.5', '0.125'.
     *
     * @throws InvalidArgumentException When the text is not a number.
     *
     * @since  2.0.0
     */
    public static function decimal(ExactAmount|string $value): string
    {
        return self::held(value: $value)->text();
    }

    /**
     * A percentage at two places, as the one percentage rule forms it
     * ({@see Money::percentOf()}): read back on its whole count of hundredths.
     *
     * The rule answers a count of hundredths over 100, which a float carries
     * exactly, so the count is recovered without loss. Only a figure already at two
     * places is read here.
     *
     * @param float $percent The percentage.
     *
     * @return string E.g. '53.13', '10', '0'.
     *
     * @throws InvalidArgumentException When the percentage is not finite or larger than a count of hundredths holds.
     *
     * @since  2.0.0
     */
    public static function percent(float $percent): string
    {
        $hundredths = round($percent * 100);

        if (!is_finite($hundredths) || abs($hundredths) >= 2 ** 53) {
            throw new InvalidArgumentException(sprintf('A percentage of %s is not a count of hundredths.', var_export($percent, true)));
        }

        $count = (int) $hundredths;
        $digits = str_pad((string) abs($count), 3, '0', STR_PAD_LEFT);

        return ExactAmount::of(($count < 0 ? '-' : '') . substr($digits, 0, -2) . '.' . substr($digits, -2))->text();
    }

    /**
     * The count of minor units a figure is, when it sits on the scale.
     *
     * @param ExactAmount|string $value The figure: its exact amount, or its stored text.
     * @param int $scale The currency's scale.
     *
     * @return int|null The count; null when the figure is finer than the scale, or more than an integer holds.
     *
     * @throws InvalidArgumentException When the text is not a number.
     *
     * @since  2.0.0
     */
    public static function minorUnits(ExactAmount|string $value, int $scale): ?int
    {
        $exact = self::read(value: $value);

        if ($exact->compare($exact->roundedAt($scale)) !== 0) {
            return null;
        }

        $text = $exact->text();
        [$whole, $places] = array_pad(explode('.', ltrim($text, '-'), 2), 2, '');
        $digits = ltrim($whole . str_pad($places, max(0, $scale), '0'), '0');
        $max = (string) PHP_INT_MAX;

        if (\strlen($digits) > \strlen($max) || (\strlen($digits) === \strlen($max) && strcmp($digits, $max) > 0)) {
            return null;
        }

        return str_starts_with($text, '-') ? -(int) $digits : (int) $digits;
    }

    /**
     * A figure as an exact amount: an ExactAmount as it is, a plain decimal text as
     * written, and any other numeric text as the float it names (a DOUBLE column
     * prints 0.00001 as '1e-5'), {@see ExactAmount::ofNumber()}.
     *
     * @param ExactAmount|string $value The figure.
     *
     *
     * @throws InvalidArgumentException When the text is not a number.
     */
    private static function read(ExactAmount|string $value): ExactAmount
    {
        return $value instanceof ExactAmount ? $value : ExactAmount::ofNumber($value);
    }

    /**
     * A figure held at the eighth place, rounded half away from zero only when it has
     * more: a stored column never has, a derived unit is held there already.
     *
     * @param ExactAmount|string $value The figure.
     *
     *
     * @throws InvalidArgumentException When the text is not a number.
     */
    private static function held(ExactAmount|string $value): ExactAmount
    {
        return self::read(value: $value)->roundedAt(self::PLACES);
    }
}
