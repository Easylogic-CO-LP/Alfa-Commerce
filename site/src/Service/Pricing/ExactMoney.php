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
 * A MONEY AMOUNT AS IT WAS TYPED OR SENT, READ EXACTLY OR REFUSED.
 *
 * The one reader of a typed amount. Staff type money on the customer's credit
 * account, on an order's payments, parcels, refunds and recorded provider
 * facts, and on a credit note's charges; the REST verbs receive it in a body.
 * Each reads it here, in the money of the record it is for: a text as written,
 * an integer as itself, and a float (a JSON number) at the digits Money reads
 * one at. The answer is that figure as Money on its own digits, or a refusal
 * that names it ({@see ExactMoneyRefusal}):
 *
 *   - more decimal places than the currency holds: refused, never rounded.
 *     Places are counted on the value, so 5.000 is 5.00 at two places and
 *     5.004 is refused. Where the operator's comma is accepted as the decimal
 *     mark, a "1.000" or "5,000" may be a thousand written with a group
 *     separator, so there places are counted as written: a fraction longer
 *     than the currency's places is refused even when its extra digits are
 *     zeros;
 *   - larger than Money or a money column holds ({@see StoredDecimal});
 *   - not a plain decimal: an optional sign, digits, and a decimal point with
 *     digits. The text is not trimmed, and a final newline is refused like any
 *     other padding; an exponent, a group separator or a bare point is refused;
 *   - a float whose fifteen-digit print does not read back as the same float:
 *     a JSON number carries no more, so the figure was cut before it arrived.
 *     The sender is asked for the amount as a string.
 *
 * Rounding belongs to a Money boundary inside Alfa, never to a figure someone
 * typed.
 *
 * @since  2.0.0
 */
final class ExactMoney
{
    /**
     * {@see ExactMoneyRefusal::reason()}: the value is not a plain decimal.
     *
     * @since  2.0.0
     */
    public const NOT_A_NUMBER = 'not-a-number';

    /**
     * {@see ExactMoneyRefusal::reason()}: more decimal places than the currency holds.
     *
     * @since  2.0.0
     */
    public const FINER = 'finer';

    /**
     * {@see ExactMoneyRefusal::reason()}: larger than Money or a money column holds.
     *
     * @since  2.0.0
     */
    public const LARGER = 'larger';

    /**
     * {@see ExactMoneyRefusal::reason()}: a float that does not carry its figure exactly.
     *
     * @since  2.0.0
     */
    public const NOT_EXACT = 'not-exact';

    /**
     * {@see ExactMoneyRefusal::reason()}: where a comma is a decimal mark, a fraction longer than the currency's places.
     *
     * @since  2.0.0
     */
    public const GROUPED = 'grouped';

    /**
     * A typed amount as Money of a currency, on its own digits.
     *
     * @param string|int|float $value What was typed or sent.
     * @param Currency $currency The record's currency, at the scale the record keeps.
     * @param bool $commaDecimal Whether a comma is accepted as the decimal mark (the operator's).
     *
     * @return Money The amount, exactly.
     *
     * @throws ExactMoneyRefusal When the value is not a plain decimal or not an exact float, is finer than
     *                           the currency, is written past its places where a comma is a decimal mark,
     *                           or is larger than an amount of it holds.
     *
     * @since  2.0.0
     */
    public static function of(string|int|float $value, Currency $currency, bool $commaDecimal = false): Money
    {
        $typed = \is_float($value) && is_finite($value) ? ExactAmount::ofNumber($value)->text() : (string) $value;

        if (\is_float($value) && is_finite($value) && (float) $typed !== $value) {
            [$integer, $fraction] = Money::floatDigits(value: abs($value), shortest: true);

            throw new ExactMoneyRefusal(
                reason: self::NOT_EXACT,
                typed: ($value < 0 ? '-' : '') . $integer . ($fraction === '' ? '' : '.' . $fraction),
                currency: $currency,
            );
        }

        $mark = $commaDecimal ? '[.,]' : '\.';

        if (preg_match('/^([+-]?)(\d+)(?:' . $mark . '(\d+))?$/D', $typed, $parts) !== 1) {
            throw new ExactMoneyRefusal(reason: self::NOT_A_NUMBER, typed: $typed, currency: $currency);
        }

        // A trailing zero is no precision: the value decides.
        $written = $parts[3] ?? '';
        $places = rtrim($written, '0');

        if (\strlen($places) > $currency->getDecimalPlaces()) {
            throw new ExactMoneyRefusal(reason: self::FINER, typed: $typed, currency: $currency);
        }

        if ($commaDecimal && \strlen($written) > $currency->getDecimalPlaces()) {
            throw new ExactMoneyRefusal(reason: self::GROUPED, typed: $typed, currency: $currency);
        }

        $exact = ($parts[1] === '-' ? '-' : '') . $parts[2] . ($places === '' ? '' : '.' . $places);

        try {
            $money = Money::of($exact, $currency);
            StoredDecimal::of($money);
        } catch (InvalidArgumentException $e) {
            throw new ExactMoneyRefusal(reason: self::LARGER, typed: $typed, currency: $currency, previous: $e);
        }

        return $money;
    }
}
