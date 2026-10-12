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
 * An explicit operator price for ONE line, as the pricing engine receives it.
 *
 * Money, and nothing else: no actor, no permission, no currency, no quantity,
 * no source. Those belong to the write boundary that authorised the price
 * {@see \Alfa\Component\Alfa\Administrator\Service\Cart\CartTakeover}, never to
 * the calculator; a pricing result that depended on who asked for it would
 * poison the catalogue price index, which runs with no user at all.
 *
 * NOT A PriceContext. Currency, date, location, groups and user describe
 * pricing CONTEXT; an operator's negotiated price is an INSTRUCTION. The code
 * agrees three ways: a context is a cache key ({@see PriceContext::toCacheKey()}),
 * one context prices many lines (the price index builds one per segment), and a
 * context is picked up from ambient session state. A negotiated price on such an
 * object would either poison the key space or serve one customer's price to
 * another.
 *
 * @since  1.11.0
 */
final class ExplicitPriceInstruction
{
    /**
     * The most significant digits a unit rate may carry: what a float-carried amount survives through
     * every write, because the database drivers write a float as (string) at PHP's precision of 14.
     *
     * @since  1.14.0
     */
    public const MAX_SIGNIFICANT_DIGITS = 14;

    /**
     * The exception code of a rate refused for its precision, so a write boundary can word it.
     *
     * @since  1.14.0
     */
    public const REFUSED_PRECISION = 1;

    /**
     * @param float $unitTaxIncl The operator's price for ONE unit, tax included.
     * @param string $rate The same price as its canonical decimal text, at its own precision.
     */
    private function __construct(public readonly float $unitTaxIncl, public readonly string $rate)
    {
    }

    /**
     * The one shape the rulings name: a tax-inclusive UNIT price.
     *
     * A net-space or whole-line sibling is one named constructor here and one
     * branch in the engine's override stage, added when a ruling asks for one.
     * Unbuilt enum cases are unrequested mechanism, so there are none.
     *
     * A UNIT RATE KEEPS ITS OWN PRECISION.
     * The rate is canonicalised once, by {@see self::canonicalRate()}, and is
     * never rounded to the currency's scale: the engine multiplies it by the
     * quantity and rounds the whole line once.
     *
     * @param float|int|string $amount The unit price the operator typed, tax included.
     *
     *
     * @throws InvalidArgumentException When the amount is not a finite, non-negative plain decimal within
     *                                  Alfa's unit-rate precision.
     *
     * @since  1.11.0
     */
    public static function perUnitTaxIncl(float|int|string $amount): self
    {
        $rate = self::canonicalRate(value: $amount);

        // ZERO IS MONEY, NEVER A SENTINEL. There is deliberately no isEmpty(),
        // no ??, and no truthiness test anywhere on this object: 0 means an
        // explicit free price, and "no override" is expressed by the ABSENCE
        // of an instruction, which is a null parameter, not a zero amount.
        return new self(unitTaxIncl: (float) $rate, rate: $rate);
    }

    /**
     * A unit rate as its canonical decimal text, or a refusal.
     *
     * THE ONE CANONICALISER. A string is read as written, never through a
     * float, so '0.30000000000000001' is refused rather than read as 0.3. A
     * float is read as the shortest text that round-trips to it, whatever the
     * host's serialize_precision says, so 9.995 is 9.995 and 0.1 + 0.2 is
     * refused for its precision rather than accepted as 0.3.
     *
     * The bounds are the storage bounds, never the currency's scale: at most
     * {@see Currency::SCALE_MAX} fractional digits (every money column is
     * decimal(24,8)) and {@see self::MAX_SIGNIFICANT_DIGITS} significant digits.
     *
     * @param mixed $value The rate: an int, a finite float, or a plain decimal string.
     *
     * @return string The rate without a sign, leading zeros or trailing fractional zeros.
     *
     * @throws InvalidArgumentException Code 0 when the value is not a non-negative plain decimal; code
     *                                  {@see self::REFUSED_PRECISION} when it is finer than Alfa keeps.
     *
     * @since  1.14.0
     */
    public static function canonicalRate(mixed $value): string
    {
        if (\is_bool($value) || !(\is_int($value) || \is_float($value) || \is_string($value))) {
            throw new InvalidArgumentException('An explicit custom price must be a number.');
        }

        // NAN and INF reaching Money::of() is a silent catastrophe: it
        // quantises to an integer minor-unit count and the nonsense travels
        // the whole chain looking like money.
        if (\is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('An explicit custom price must be a finite amount.');
            }

            $value = self::plainDecimal(text: self::roundTripText(value: $value));
        } elseif (\is_int($value)) {
            $value = (string) $value;
        }

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m) !== 1) {
            throw new InvalidArgumentException('An explicit custom price must be a plain decimal number such as 12.50.');
        }

        $integer = ltrim($m[2], '0') === '' ? '0' : ltrim($m[2], '0');
        $fraction = rtrim($m[3] ?? '', '0');

        // REFUSED, NOT CLAMPED. The engine floors a negative CATALOGUE
        // override_value because that is dirty data on a column with no
        // minimum; an operator instruction is a typed COMMAND, and -10 is a
        // mistake: a sale-line price is not a payout. -0 is 0.
        if ($m[1] === '-' && ($integer !== '0' || $fraction !== '')) {
            throw new InvalidArgumentException(
                'An explicit custom price may not be negative; a sale line is not a payout.',
            );
        }

        if (\strlen($fraction) > Currency::SCALE_MAX) {
            throw new InvalidArgumentException(
                'An explicit custom price has at most ' . Currency::SCALE_MAX . ' decimal places.',
                self::REFUSED_PRECISION,
            );
        }

        $significant = \strlen(ltrim(($integer === '0' ? '' : $integer) . $fraction, '0'));

        if ($significant > self::MAX_SIGNIFICANT_DIGITS) {
            throw new InvalidArgumentException(
                'An explicit custom price has at most ' . self::MAX_SIGNIFICANT_DIGITS . ' significant digits.',
                self::REFUSED_PRECISION,
            );
        }

        return $fraction === '' ? $integer : $integer . '.' . $fraction;
    }

    /**
     * The shortest decimal text that reads back as this float, in exponent form.
     *
     * Independent of the host's serialize_precision: var_export() and
     * json_encode() print 9.995 as 9.9949999999999992184... where that setting
     * is 100, which would refuse an ordinary REST rate for its precision.
     *
     * @param float $value A finite float.
     *
     * @return string E.g. '9.995e+0', '1e-5'.
     *
     * @since  1.14.0
     */
    private static function roundTripText(float $value): string
    {
        for ($digits = 0; $digits < 17; $digits++) {
            $text = sprintf('%.' . $digits . 'e', $value);

            if ((float) $text === $value) {
                return $text;
            }
        }

        return sprintf('%.17e', $value);
    }

    /**
     * Exponent notation rewritten as a plain decimal; any other text unchanged.
     *
     * @param string $text E.g. '1.0E-5'.
     *
     * @return string E.g. '0.000010'.
     *
     * @since  1.14.0
     */
    private static function plainDecimal(string $text): string
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?E([+-]?\d+)$/i', $text, $m) !== 1) {
            return $text;
        }

        $digits = $m[2] . ($m[3] ?? '');
        $point = \strlen($m[2]) + (int) $m[4];

        if ($point <= 0) {
            return $m[1] . '0.' . str_repeat('0', -$point) . $digits;
        }

        if ($point >= \strlen($digits)) {
            return $m[1] . $digits . str_repeat('0', $point - \strlen($digits));
        }

        return $m[1] . substr($digits, 0, $point) . '.' . substr($digits, $point);
    }

    /**
     * THE ONE PLACE A STORED COLUMN BECOMES AN INSTRUCTION.
     *
     * Two producers read `#__alfa_cart_items.custom_unit_price_tax_incl` (the
     * hydrated cart line and the builder's re-configure popup), and if each
     * wrote its own conditional they could disagree about the case that
     * matters. The whole rule is here, once:
     *
     *   SQL NULL      -> no override. Ordinary pricing, null instruction.
     *   0.00000000    -> an explicit FREE price. A real instruction.
     *   anything else -> that amount.
     *
     * A `?:`, an `empty()` or a truthiness test at either call site would
     * collapse the middle row into the first and price a negotiated free line
     * from the catalogue. There is deliberately no such test anywhere on this
     * path, and the only question asked is `=== null`.
     *
     * The driver's decimal string reaches the canonicaliser as it is, never
     * through a float, so a stored rate keeps every digit the column holds.
     *
     * @param mixed $stored The column as the driver returned it: null, or a numeric string.
     *
     *
     * @throws InvalidArgumentException When a stored value is not a usable amount.
     *
     * @since  1.11.0
     */
    public static function fromStoredColumn(mixed $stored): ?self
    {
        if ($stored === null) {
            return null;
        }

        return self::perUnitTaxIncl(amount: $stored);
    }
}
