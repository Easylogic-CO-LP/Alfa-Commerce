<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal;

/**
 * One money shape for the whole API.
 *
 * Every monetary value is rendered as an object, never a bare number:
 *
 *     "order_total": { "amount": "371.48", "formatted": "371,48 €", "minor_units": 37148 }
 *
 * `amount` is the exact decimal string ({@see PublishedDecimal}): money at the
 * record's scale, a unit or a rate as its own digits, never a float, so no
 * client or server precision setting can change it. `formatted` saves every
 * client re-implementing this shop's currency rules (symbol placement and
 * separators are per-currency and easy to get wrong), and `minor_units` is the
 * integer count at the record's scale, where the figure sits on it.
 *
 * The currency itself is stated ONCE per resource as `currency`, rather than
 * repeated on all twenty money fields. This matches the catalogue payload,
 * where PriceResult::toArray() already emits {amount, formatted} plus a single
 * currency block.
 *
 * @since  2.0.0
 */
trait ProjectsMoney
{
    /**
     * Render one monetary value.
     *
     * A stored figure is read as written (the column's text), never through a
     * float, and becomes Money of the record's currency, rounded once at its
     * scale.
     *
     * @param mixed $value Money object, stored decimal text, a number, or null.
     * @param Currency|null $currency Currency for a stored figure; null when the record names none.
     *
     * @return array|null {amount, formatted, minor_units}, or null.
     *
     * @since  2.0.0
     */
    protected function money(mixed $value, ?Currency $currency = null): ?array
    {
        if ($value instanceof Money) {
            return [
                'amount' => PublishedDecimal::money($value),
                'formatted' => $value->format(),
                'minor_units' => $value->getMinorUnits(),
            ];
        }

        if (!is_numeric($value)) {
            return null;
        }

        if ($currency === null) {
            // The order or document names no currency: the stored figure, in
            // the same shape, with the notice where its formatted money would
            // be. Never formatted in another currency, and no minor units,
            // which only a currency's scale defines.
            return ['amount' => PublishedDecimal::decimal(value: $this->storedText(value: $value)), 'formatted' => OrderCurrency::notice(), 'minor_units' => null];
        }

        return $this->money(Money::of($this->storedText(value: $value), $currency));
    }

    /**
     * Render one unit rate, in the same {amount, formatted, minor_units} shape.
     *
     * A custom unit rate may be finer than the currency
     * scale: `amount` is the rate itself, never
     * quantised, `formatted` shows its own decimals, and `minor_units` is null
     * for a finer rate, which has no whole number of minor units (a rounded
     * one would be the 0.13 the ruling forbids for 0.125). At the currency's
     * scale it answers exactly what {@see self::money()} answers. No key is
     * added: a new REST field is an API-contract item.
     *
     * @param mixed $value The stored rate (the column's text), or null.
     * @param Currency|null $currency Currency for formatting.
     *
     * @return array|null {amount, formatted, minor_units}, or null.
     *
     * @since  2.0.0
     */
    protected function rate(mixed $value, ?Currency $currency = null): ?array
    {
        if (!is_numeric($value)) {
            return null;
        }

        $value = $this->storedText(value: $value);

        if ($currency === null) {
            return ['amount' => PublishedDecimal::decimal(value: $value), 'formatted' => OrderCurrency::notice(), 'minor_units' => null];
        }

        return [
            'amount' => PublishedDecimal::unit(value: $value, scale: $currency->getDecimalPlaces()),
            'formatted' => $currency->formatRate(rate: $value),
            'minor_units' => PublishedDecimal::minorUnits(value: $value, scale: $currency->getDecimalPlaces()),
        ];
    }

    /**
     * Render one unit price of a line, in the same {amount, formatted, minor_units} shape.
     *
     * A unit is data here, not a screen: `amount` is the stored unit, never
     * quantised; `formatted` is ONE fixed form that no shop setting moves, the
     * Auto form of the one unit rule ({@see Currency::unitFigure()}) at the
     * record's frozen scale, read from the line's own quantity and total in the
     * same tax space, so a stored division tail the line does not need is never
     * printed and an agreed rate shows as agreed; `minor_units` is the stored
     * unit's own minor units when it sits on the scale, and null when it is
     * finer (a rounded count would state a unit the line does not charge).
     *
     * @param mixed $value The stored unit, or null.
     * @param mixed $lineTotal The line's whole amount in the unit's tax space: Money or a number.
     * @param mixed $quantity The line's quantity.
     * @param mixed $rate The line's agreed custom rate, beside the gross unit only; null when none.
     * @param Currency|null $currency The record's currency at its frozen scale; null when it names none.
     *
     * @return array|null {amount, formatted, minor_units}, or null.
     *
     * @since  2.0.0
     */
    protected function unit(mixed $value, mixed $lineTotal, mixed $quantity, mixed $rate, ?Currency $currency): ?array
    {
        if (!is_numeric($value)) {
            return null;
        }

        // A record that names no currency: the stored number and the notice,
        // exactly as a rate is answered.
        if ($currency === null) {
            return $this->rate(value: $value, currency: null);
        }

        $value = $this->storedText(value: $value);

        return [
            'amount' => PublishedDecimal::unit(value: $value, scale: $currency->getDecimalPlaces()),
            'formatted' => $currency->formatUnit(
                unit: $value,
                lineTotal: $lineTotal instanceof Money ? $lineTotal->getAmount() : (is_numeric($lineTotal) ? $lineTotal : 0),
                quantity: is_numeric($quantity) ? $quantity : 0,
                rate: is_numeric($rate) ? $rate : null,
                auto: true,
            ),
            'minor_units' => PublishedDecimal::minorUnits(value: $value, scale: $currency->getDecimalPlaces()),
        ];
    }

    /**
     * Render a rate or a percentage: its exact decimal text, no trailing zero.
     *
     * A tax rate, a conversion rate, a manual discount percentage: the column's
     * text as written ("24.000" is "24"), a number held as a float at the
     * fifteen significant digits it carries.
     *
     * @param mixed $value The stored figure, or null.
     *
     * @return string|null Null when there is no figure.
     *
     * @since  2.0.0
     */
    protected static function decimal(mixed $value): ?string
    {
        return is_numeric($value) ? PublishedDecimal::decimal(value: ExactAmount::ofNumber($value)) : null;
    }

    /**
     * A stored figure as the decimal text it states: a column's text as written, an
     * integer as itself, and a number held as a float at the fifteen significant
     * digits it carries, never at PHP's printing precision.
     *
     * @param int|float|string $value A numeric figure.
     *
     *
     * @since  2.0.0
     */
    private function storedText(int|float|string $value): string
    {
        return ExactAmount::ofNumber($value)->text();
    }

    /**
     * Render the currency block that the money values are expressed in.
     *
     * @param Currency|null $currency The resolved currency.
     *
     *
     * @since  2.0.0
     */
    protected function currencyBlock(?Currency $currency): ?array
    {
        if (!$currency instanceof Currency) {
            return null;
        }

        return [
            'id' => $currency->getId(),
            'code' => $currency->getCode(),
            'symbol' => $currency->getSymbol(),
            'decimal_places' => $currency->getDecimalPlaces(),
            'decimal_separator' => $currency->getDecimalSeparator(),
            'thousand_separator' => $currency->getThousandSeparator(),
            'format_pattern' => $currency->getFormatPattern(),
        ];
    }
}
