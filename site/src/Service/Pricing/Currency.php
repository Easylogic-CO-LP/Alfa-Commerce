<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

use InvalidArgumentException;
use Joomla\CMS\Factory;
use RuntimeException;

defined('_JEXEC') or die;

/**
 * Currency Value Object
 *
 * Immutable currency representation with formatting rules.
 * Loads currency data from database and caches it.
 * @since  1.0.0
 */
class Currency
{
    /**
     * The lowest monetary scale Alfa supports.
     *
     * A currency with no minor unit is a real currency, so 0 is a valid answer,
     * not a missing one.
     *
     * @since 1.10.0
     */
    public const SCALE_MIN = 0;

    /**
     * The highest monetary scale Alfa supports.
     *
     * `decimal_place` is the merchant's decision, not ISO 4217's: EUR at 0, 2
     * or 4 places is a configuration Alfa honours, so the only bound that
     * belongs here is the one the implementation can actually carry end to end.
     * That bound is eight: every money column is decimal(24,8), so a ninth
     * fractional digit could not survive a write; Money keeps the amount as
     * minor units in a PHP integer, and at scale 8 a 64-bit integer still holds
     * roughly 9.2e10 major units; and eight places is what Money's eight-decimal
     * crypto support is built for.
     *
     * @since 1.10.0
     */
    public const SCALE_MAX = 8;

    private int $id;
    private string $name;
    private string $code;
    private int $number;
    private string $symbol;
    private int $decimalPlaces;
    private string $decimalSeparator;
    private string $thousandSeparator;
    private string $formatPattern;

    /**
     * Per-request static cache for loaded currencies.
     * Keyed by lookup type + value: "id_47", "number_978", "code_EUR".
     * Currency is immutable: safe to cache and share.
     *
     * @var array<string, self>
     */
    private static array $cache = [];

    private function __construct(
        int $id,
        string $name,
        string $code,
        int $number,
        string $symbol,
        int $decimalPlaces,
        string $decimalSeparator,
        string $thousandSeparator,
        string $formatPattern,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->code = $code;
        $this->number = $number;
        $this->symbol = $symbol;
        $this->decimalPlaces = $decimalPlaces;
        $this->decimalSeparator = $decimalSeparator;
        $this->thousandSeparator = $thousandSeparator;
        $this->formatPattern = $formatPattern;
    }

    /**
     * Create Currency from database row.
     *
     * decimal_place is parsed FAIL-CLOSED: it is Alfa's authoritative monetary
     * scale for this currency (every rounding, every persisted amount, every
     * formatted figure and every gateway charge derives from it), so a blank or
     * out-of-range value must stop money code, never silently become 0 (which
     * would truncate every amount to whole units) or a guessed 2.
     *
     * The value is the merchant's, and it is taken as given: no ISO 4217
     * expectation is applied here or anywhere else in the runtime.
     *
     * @throws RuntimeException When decimal_place is not an integer within the supported range.
     * @since  1.0.0
     */
    public static function fromDatabase(object $row): self
    {
        $rawScale = $row->decimal_place ?? null;

        if (
            !is_numeric($rawScale)
            || (string) (int) $rawScale !== (string) $rawScale
            || (int) $rawScale < self::SCALE_MIN
            || (int) $rawScale > self::SCALE_MAX
        ) {
            throw new RuntimeException(sprintf(
                'Currency #%d (%s) has an invalid decimal_place %s: expected an integer %d..%d. Refusing to build a Money scale from it.',
                (int) ($row->id ?? 0),
                (string) ($row->code ?? '?'),
                var_export($rawScale, true),
                self::SCALE_MIN,
                self::SCALE_MAX,
            ));
        }

        return new self(
            (int) $row->id,
            $row->name,
            $row->code,
            (int) $row->number,
            $row->symbol,
            (int) $rawScale,
            $row->decimal_separator ?? '.',
            $row->thousand_separator ?? ',',
            $row->format_pattern ?? '{number} {symbol}',
        );
    }

    /**
     * Load currency by ISO number (e.g., 978 for EUR)
     * Uses caching for performance
     * @since  1.0.0
     */
    public static function loadByNumber(int $number): self
    {
        $cacheKey = "number_{$number}";

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__alfa_currencies')
            ->where($db->quoteName('number') . ' = ' . (int) $number)
            ->where($db->quoteName('state') . ' = 1');

        $db->setQuery($query);
        $row = $db->loadObject();

        if (!$row) {
            throw new RuntimeException("Currency with number {$number} not found");
        }

        $currency = self::fromDatabase($row);
        self::cacheAll($currency, published: true);

        return $currency;
    }

    /**
     * Load a currency by its database id (cached across id/number/code keys).
     *
     * @param int $id Currency id.
     *
     * @return self The loaded currency.
     *
     * @throws RuntimeException When no currency with the given id exists.
     * @since  1.0.0
     */
    public static function loadById(int $id): self
    {
        $cacheKey = "id_{$id}";

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__alfa_currencies')
            ->where('id = ' . (int) $id);

        $db->setQuery($query);
        $row = $db->loadObject();

        if (!$row) {
            throw new RuntimeException("Currency with id {$id} not found");
        }

        $currency = self::fromDatabase($row);

        // DELIBERATELY UNFILTERED, AND HONEST ABOUT IT. This resolves the currency
        // a row REFERS TO (an order's historical currency, for money already
        // taken), so it must answer for an unpublished one. It therefore tells the
        // cache what it actually read, and an unpublished currency is cached under
        // the id key only: resolvable by id, still invisible by code.
        self::cacheAll($currency, published: (int) ($row->state ?? 0) === 1);

        return $currency;
    }

    /**
     * Load currency by ISO code (e.g., 'EUR', 'USD')
     * @since  1.0.0
     */
    public static function loadByCode(string $code): self
    {
        $cacheKey = "code_{$code}";

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__alfa_currencies')
            ->where($db->quoteName('code') . ' = ' . $db->quote($code))
            ->where($db->quoteName('state') . ' = 1');

        $db->setQuery($query);
        $row = $db->loadObject();

        if (!$row) {
            throw new RuntimeException("Currency with code {$code} not found");
        }

        $currency = self::fromDatabase($row);
        self::cacheAll($currency, published: true);

        return $currency;
    }

    /**
     * Get default currency from component configuration
     * @since  1.0.0
     */
    public static function getDefault(): self
    {
        $params = \Joomla\CMS\Component\ComponentHelper::getParams('com_alfa');
        $defaultNumber = (int) $params->get('default_currency', 978); // EUR
        return self::loadByNumber($defaultNumber);
    }

    /**
     * Cache a currency under the lookup keys it is ACTUALLY entitled to.
     *
     * THE CACHE MUST NOT LAUNDER THE STATE FILTER ("one authority for
     * currency resolution with one state-filter policy").
     *
     * The three loaders do not ask the same question. `loadByCode()` and
     * `loadByNumber()` mean "the currency the shop TRADES IN with this code",
     * and both say so with `state = 1`. `loadById()` means "the currency this
     * row REFERS TO" (an order's historical currency, resolved for money that
     * has already been taken), and it deliberately carries no filter, because
     * refusing to resolve it would break an issued order.
     *
     * Writing all three keys from any of them made the filtered answer depend on
     * CALL ORDER: an unfiltered `loadById()` populated the `code_` key, and the
     * next `loadByCode('XXX')` returned an unpublished currency its own WHERE
     * clause would have refused. Measured on the demo before this change, the
     * same call, same request: REFUSED on its own, RESOLVED after a loadById().
     *
     * So the id key is always written, and the two published keys only for a
     * currency that satisfies what they promise. An unpublished currency stays
     * resolvable by id and stays invisible by code, which is the one policy,
     * applied the same way whatever ran first.
     *
     * @param self $currency Currency to cache.
     * @param bool $published Whether the row satisfies the published filter.
     *
     * @since  1.0.0
     */
    private static function cacheAll(self $currency, bool $published): void
    {
        self::$cache["id_{$currency->id}"] = $currency;

        if ($published) {
            self::$cache["number_{$currency->number}"] = $currency;
            self::$cache["code_{$currency->code}"] = $currency;
        }
    }

    // Getters

    /**
     * THIS CURRENCY, READ AT A SCALE A HISTORICAL RECORD FROZE.
     *
     * The currency TABLE is configuration for NEW monetary facts. An order, a
     * payment or a document that was created earlier interprets itself from
     * the scale it recorded at the time, and this is how it gets a Currency
     * object that speaks that scale, for quantising through
     * {@see Money::of()} and for formatting alike, so a record has ONE
     * authority rather than one for arithmetic and another for display.
     *
     * Everything else about the currency (its code, symbol, separators and
     * pattern) comes from the live row on purpose: renaming a symbol or
     * changing a thousands separator is presentation, and a historical record
     * should follow it. Only the SCALE is money.
     *
     * MEASURED, AND THE REASON THIS EXISTS: before it, editing a currency from
     * two decimal places to none turned an order owed 10.40 with 10.36
     * collected from `partial` into `paid` (balance 0.04 became 0.00) with
     * no order row touched and no writer run. A display preference re-settled
     * a historical order, and drove status transitions and document
     * auto-issue with it.
     *
     * @param int $scale The frozen scale, 0 to SCALE_MAX.
     *
     * @return self The same currency, speaking that scale.
     *
     * @throws RuntimeException When the scale is outside the supported range.
     *
     * @since  1.13.0
     */
    public function atScale(int $scale): self
    {
        if ($scale < 0 || $scale > self::SCALE_MAX) {
            throw new RuntimeException(
                'A frozen monetary scale must be between 0 and ' . self::SCALE_MAX . '; got ' . $scale . '.',
            );
        }

        if ($scale === $this->decimalPlaces) {
            return $this;
        }

        return new self(
            $this->id,
            $this->name,
            $this->code,
            $this->number,
            $this->symbol,
            $scale,
            $this->decimalSeparator,
            $this->thousandSeparator,
            $this->formatPattern,
        );
    }

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get the currency display name.
     * @since  1.0.0
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the ISO 4217 alphabetic code (e.g. EUR, USD).
     * @since  1.0.0
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Get the ISO 4217 numeric code (e.g. 978 for EUR).
     * @since  1.0.0
     */
    public function getNumber(): int
    {
        return $this->number;
    }

    /**
     * Get the currency symbol (e.g. €, $).
     * @since  1.0.0
     */
    public function getSymbol(): string
    {
        return $this->symbol;
    }

    /**
     * Get this currency's monetary scale.
     *
     * The stored `decimal_place`, and the one number Alfa computes, rounds,
     * persists and formats money at for this currency. It is not a display
     * preference: changing it changes the amounts themselves.
     *
     * @since  1.0.0
     */
    public function getDecimalPlaces(): int
    {
        return $this->decimalPlaces;
    }

    /**
     * Get the decimal separator used for formatting.
     * @since  1.0.0
     */
    public function getDecimalSeparator(): string
    {
        return $this->decimalSeparator;
    }

    /**
     * Get the thousands separator used for formatting.
     * @since  1.0.0
     */
    public function getThousandSeparator(): string
    {
        return $this->thousandSeparator;
    }

    /**
     * Get the format pattern controlling symbol/amount placement.
     * @since  1.0.0
     */
    public function getFormatPattern(): string
    {
        return $this->formatPattern;
    }

    /**
     * Format an amount according to this currency's rules
     *
     * ONE pattern renders both signs: do not add a negative counterpart.
     * number_format() puts the minus INSIDE the formatted number, so {number}
     * already carries it and the same pattern serves positive and negative;
     * two patterns could disagree about which side the symbol sits on, which
     * is exactly what the inherited legacy columns had done.
     * Tokens: {number} (signed amount) and {symbol}. There is no {sign} token.
     *
     * The number of decimals shown is this currency's configured scale, the
     * same one the amount was computed and stored at, never a separate display
     * setting.
     *
     * @param float $amount Amount to format
     * @param bool $includeSymbol Whether to include currency symbol
     * @return string Formatted amount
     * @since  1.0.0
     */
    public function format(float $amount, bool $includeSymbol = true): string
    {
        // Format the number with proper separators
        $formatted = number_format(
            $amount,
            $this->decimalPlaces,
            $this->decimalSeparator,
            $this->thousandSeparator,
        );

        if (!$includeSymbol) {
            return $formatted;
        }

        // Apply the pattern
        $result = str_replace('{number}', $formatted, $this->formatPattern);
        $result = str_replace('{symbol}', $this->symbol, $result);

        return $result;
    }

    /**
     * How many decimals a unit rate shows: its own stored ones, never fewer than the given minimum and never
     * more than {@see self::SCALE_MAX}.
     *
     * A unit rate may be finer than the currency's scale
     * and the rule is: "preserve/display the authoritative
     * entered unit-rate precision where the rate itself is shown". 0.125 shows
     * three decimals, 12.50 shows the minimum.
     *
     * @param float|string $rate The rate, as stored.
     * @param int $minimum The fewest decimals to show, usually the currency's scale.
     *
     *
     * @since  1.14.0
     */
    public static function rateDecimals(float|string $rate, int $minimum): int
    {
        $fraction = rtrim(explode('.', sprintf('%.' . self::SCALE_MAX . 'F', (float) $rate))[1], '0');

        return max($minimum, \strlen($fraction));
    }

    /**
     * Format a unit rate: its own decimals, at least this currency's scale.
     *
     * At the currency's scale it prints exactly what {@see self::format()}
     * prints; a finer rate keeps its decimals, so 0.125 is never shown as the
     * 0.13 it does not charge. Totals keep format().
     *
     * @param float|string $rate The rate, as stored.
     * @param bool $includeSymbol Whether to include the currency symbol.
     *
     *
     * @since  1.14.0
     */
    public function formatRate(float|string $rate, bool $includeSymbol = true): string
    {
        $formatted = number_format((float) $rate, self::rateDecimals(rate: $rate, minimum: $this->decimalPlaces), $this->decimalSeparator, $this->thousandSeparator);

        if (!$includeSymbol) {
            return $formatted;
        }

        return str_replace('{symbol}', $this->symbol, str_replace('{number}', $formatted, $this->formatPattern));
    }

    /**
     * Whether unit prices are shown in their Auto form: the shop's one unit display setting.
     *
     * Presentation only. It is the same for every customer and customer group,
     * and no price, total or stored figure ever reads it: it decides how many
     * decimals a unit price is printed with, never what is charged. Unset is Auto.
     *
     * @return bool True for Auto, false for "Currency decimals".
     *
     * @since 1.14.0
     */
    public static function unitPrecisionIsAuto(): bool
    {
        return \Joomla\CMS\Component\ComponentHelper::getParams('com_alfa')->get('unit_display_precision', 'auto') !== 'currency';
    }

    /**
     * THE ONE RULE FOR SHOWING A UNIT PRICE BESIDE ITS QUANTITY AND LINE TOTAL.
     *
     * A unit price is presentation: the line total is the money. The rule reads
     * only facts the line already holds (its stored unit, its quantity, its
     * whole-line amount in the same tax space and its explicit custom rate) and
     * answers the decimal text to print and how many decimals it has.
     *
     * - A quantity of zero shows the stored unit at the scale.
     * - A unit that IS the line's explicit custom rate (the stored unit equals
     *   the rate at eight places, so the line charges it uncut) is the agreed
     *   price itself. Auto shows its own stored decimals, trailing zeros
     *   dropped, never fewer than the scale; "Currency decimals" shows it at the
     *   scale. A net unit is never this case: the custom rate is a gross rate,
     *   so a caller passes the rate only beside the gross unit.
     * - Any other unit is the line's money per unit: the line total as the
     *   money it states (rounded once at the scale), divided by the quantity.
     *   "Currency decimals" shows it at the scale. Auto shows the fewest
     *   decimals, from the scale up to eight, at which that unit times the
     *   quantity, rounded at the scale, gives back the line's money; when no
     *   such precision exists it shows the scale. So 995.16 for 100 shows
     *   9.9516, 100.00 for 3 shows 33.333, and a stored division tail such as
     *   11.564516 on a line of 11.56 for 1 shows 11.56: no digit is shown that
     *   the line does not need.
     * - Auto's search is bounded by what an integer holds: where the unit at a
     *   finer precision would pass it (a unit above about 9.2e18 / 10^d), Auto
     *   shows the scale. No real figure is near that bound.
     *
     * Every value is read on its decimal digits (a float on its shortest text)
     * and the arithmetic is on integers, rounded half away from zero, so the
     * answer is the same on every PHP version.
     *
     * @param float|int|string $unit The stored unit price.
     * @param float|int|string $lineTotal The line's whole amount, in the unit's tax space.
     * @param float|int|string $quantity The line's quantity.
     * @param int $scale The record's monetary scale, 0 to SCALE_MAX.
     * @param float|int|string|null $rate The line's explicit custom rate; null when it has none.
     * @param bool $auto True for Auto, false for "Currency decimals".
     *
     * @return array{figure: string, decimals: int} The decimal text ('.' as the point, '-' for a
     *                                              negative unit) and its number of decimals.
     *
     * @throws InvalidArgumentException When a value is not a number, the scale is outside the supported
     *                                  range, or the unit is more than minor units can hold.
     *
     * @since 1.14.0
     */
    public static function unitFigure(
        float|int|string $unit,
        float|int|string $lineTotal,
        float|int|string $quantity,
        int $scale,
        float|int|string|null $rate,
        bool $auto,
    ): array {
        if ($scale < self::SCALE_MIN || $scale > self::SCALE_MAX) {
            throw new InvalidArgumentException('A unit is shown at a scale between ' . self::SCALE_MIN . ' and ' . self::SCALE_MAX . '; got ' . $scale . '.');
        }

        // The stored unit at eight places, on its digits at any length: a unit
        // may be larger than an integer holds at eight places and still price.
        $stored = self::exactOf(value: $unit)->roundedAt(self::SCALE_MAX);
        [$pieces, $piecePlaces] = self::quantityCount(quantity: $quantity);

        if ($pieces === 0) {
            return self::exactFigure(amount: $stored, decimals: $scale);
        }

        if ($rate !== null && self::exactOf(value: $rate)->roundedAt(self::SCALE_MAX)->compare($stored) === 0) {
            $text = $stored->text();
            $own = str_contains($text, '.') ? \strlen($text) - strpos($text, '.') - 1 : 0;

            return self::exactFigure(amount: $stored, decimals: $auto ? max($scale, $own) : $scale);
        }

        // The line amount per unit: the line's money at the scale, divided by
        // the quantity, at each precision in turn.
        $line = self::countAt(value: $lineTotal, places: $scale);
        $atScale = null;

        for ($decimals = $scale; $decimals <= ($auto ? self::SCALE_MAX : $scale); $decimals++) {
            $factor = 10 ** ($piecePlaces + $decimals - $scale);
            $perUnit = Money::scaleCount(count: $line, numerator: $factor, denominator: $pieces);

            if ($perUnit === null) {
                break;
            }

            $atScale ??= $perUnit;

            if (!$auto || Money::scaleCount(count: $perUnit, numerator: $pieces, denominator: $factor) === $line) {
                return self::figureOf(count: $perUnit, decimals: $decimals);
            }
        }

        if ($atScale === null) {
            throw new InvalidArgumentException('The unit of a line of ' . $line . ' minor units over ' . $pieces . ' pieces is more than minor units can hold.');
        }

        return self::figureOf(count: $atScale, decimals: $scale);
    }

    /**
     * A unit price as this currency prints it, by the rule of {@see self::unitFigure()}, at this currency's scale.
     *
     * Every screen that shows a unit beside its quantity and line total prints
     * it through here, from a Currency at the record's own frozen scale.
     *
     * @param float|int|string $unit The stored unit price.
     * @param float|int|string $lineTotal The line's whole amount, in the unit's tax space.
     * @param float|int|string $quantity The line's quantity.
     * @param float|int|string|null $rate The line's explicit custom rate, beside a gross unit only.
     * @param bool $includeSymbol Whether to include the currency symbol.
     * @param bool|null $auto Null: the shop's setting. True: the Auto form whatever the
     *                        setting, for a figure that is data or stored text.
     *
     *
     * @since 1.14.0
     */
    public function formatUnit(
        float|int|string $unit,
        float|int|string $lineTotal,
        float|int|string $quantity,
        float|int|string|null $rate = null,
        bool $includeSymbol = true,
        ?bool $auto = null,
    ): string {
        $figure = self::unitFigure(
            unit: $unit,
            lineTotal: $lineTotal,
            quantity: $quantity,
            scale: $this->decimalPlaces,
            rate: $rate,
            auto: $auto ?? self::unitPrecisionIsAuto(),
        );
        $formatted = self::groupFigure(figure: $figure['figure'], decimalSeparator: $this->decimalSeparator, thousandSeparator: $this->thousandSeparator);

        if (!$includeSymbol) {
            return $formatted;
        }

        return str_replace('{symbol}', $this->symbol, str_replace('{number}', $formatted, $this->formatPattern));
    }

    /**
     * A stored figure as this currency prints it: a stored unit, or a stored per-unit amount such as an option's
     * price impact, that is a fact of its own and not a line's money per unit.
     *
     * The stored branch of the one unit rule ({@see self::unitFigure()}): Auto shows the figure's own stored
     * decimals, trailing zeros dropped, never fewer than this currency's scale; "Currency decimals" shows it at the
     * scale. A figure below one minor unit is shown, never taken for zero.
     *
     * @param float|int|string $value The stored figure.
     * @param bool $includeSymbol Whether to include the currency symbol.
     * @param bool|null $auto Null: the shop's setting. True: the Auto form whatever the setting.
     *
     *
     * @since 1.14.0
     */
    public function formatStored(float|int|string $value, bool $includeSymbol = true, ?bool $auto = null): string
    {
        return $this->formatUnit(unit: $value, lineTotal: $value, quantity: 1, rate: $value, includeSymbol: $includeSymbol, auto: $auto);
    }

    /**
     * A decimal text with a decimal separator and thousands groups, digit for digit: no float in between.
     *
     * @param string $figure A decimal text: an optional '-', digits, an optional '.' and digits.
     * @param string $decimalSeparator The decimal separator.
     * @param string $thousandSeparator The thousands separator ('' for none).
     *
     *
     * @since 1.14.0
     */
    public static function groupFigure(string $figure, string $decimalSeparator, string $thousandSeparator): string
    {
        $negative = str_starts_with($figure, '-');
        $parts = explode('.', ltrim($figure, '-'), 2);
        $whole = $parts[0];
        $groups = [];

        for ($end = \strlen($whole); $end > 0; $end -= 3) {
            array_unshift($groups, substr($whole, max(0, $end - 3), $end - max(0, $end - 3)));
        }

        return ($negative ? '-' : '') . implode($thousandSeparator, $groups)
            . (isset($parts[1]) ? $decimalSeparator . $parts[1] : '');
    }

    /**
     * A value as a whole count of 10^-places, rounded once, half away from zero, on its digits.
     *
     * @param float|int|string $value The value: an integer, a decimal text, or a float (read on its shortest text).
     * @param int $places The places to count at.
     *
     *
     * @throws InvalidArgumentException When the value is not a finite number or is more than an integer holds.
     *
     * @since 1.14.0
     */
    private static function countAt(float|int|string $value, int $places): int
    {
        if (\is_string($value) && preg_match('/^\s*([+-]?)(\d+)(?:\.(\d*))?\s*$/', $value, $m) === 1) {
            [$negative, $integer, $fraction] = [$m[1] === '-', $m[2], $m[3] ?? ''];
        } elseif (\is_string($value)) {
            if (!is_numeric(trim($value))) {
                throw new InvalidArgumentException(sprintf("A unit figure reads a number, not '%s'.", $value));
            }

            return self::countAt(value: (float) trim($value), places: $places);
        } elseif (\is_int($value)) {
            [$negative, $integer, $fraction] = [$value < 0, ltrim((string) $value, '-'), ''];
        } else {
            [$integer, $fraction] = Money::floatDigits(value: abs($value), shortest: true);
            $negative = $value < 0;
        }

        $digits = ltrim($integer . substr(str_pad($fraction, $places, '0'), 0, $places), '0');
        $max = (string) PHP_INT_MAX;

        if (\strlen($digits) > \strlen($max) || (\strlen($digits) === \strlen($max) && strcmp($digits, $max) > 0)) {
            throw new InvalidArgumentException(sprintf("'%s' is more than a unit figure can hold.", (string) $value));
        }

        $count = (int) $digits;

        if (\strlen($fraction) > $places && $fraction[$places] >= '5') {
            if ($count === PHP_INT_MAX) {
                throw new InvalidArgumentException(sprintf("'%s' is more than a unit figure can hold.", (string) $value));
            }

            $count++;
        }

        return $negative ? -$count : $count;
    }

    /**
     * A quantity as a whole count over a power of ten: [count, places], trailing zeros dropped.
     *
     * @param float|int|string $quantity The quantity, up to SCALE_MAX decimals.
     *
     * @return array{0: int, 1: int}
     *
     * @since 1.14.0
     */
    private static function quantityCount(float|int|string $quantity): array
    {
        $count = self::countAt(value: $quantity, places: self::SCALE_MAX);
        $places = self::SCALE_MAX;

        while ($places > 0 && $count % 10 === 0) {
            $count = intdiv($count, 10);
            $places--;
        }

        return [$count, $places];
    }

    /**
     * A value as an exact decimal, on its digits (a float on its shortest text).
     *
     * @param float|int|string $value The value.
     *
     *
     * @throws InvalidArgumentException When the value is not a finite number.
     *
     * @since 1.14.0
     */
    private static function exactOf(float|int|string $value): ExactAmount
    {
        if (\is_string($value) && preg_match('/^\s*([+-]?)(\d+)(?:\.(\d*))?\s*$/', $value, $m) === 1) {
            return ExactAmount::of(($m[1] === '-' ? '-' : '') . $m[2] . (($m[3] ?? '') !== '' ? '.' . $m[3] : ''));
        }

        if (\is_string($value)) {
            if (!is_numeric(trim($value))) {
                throw new InvalidArgumentException(sprintf("A unit figure reads a number, not '%s'.", $value));
            }

            return self::exactOf(value: (float) trim($value));
        }

        if (\is_int($value)) {
            return ExactAmount::of((string) $value);
        }

        [$integer, $fraction] = Money::floatDigits(value: abs($value), shortest: true);

        return ExactAmount::of(($value < 0 ? '-' : '') . $integer . ($fraction !== '' ? '.' . $fraction : ''));
    }

    /**
     * An exact amount at a number of decimals as its decimal text: rounded once, half away from zero, zeros kept.
     *
     * @param ExactAmount $amount The amount.
     * @param int $decimals The decimals to show.
     *
     * @return array{figure: string, decimals: int}
     *
     * @since 1.14.0
     */
    private static function exactFigure(ExactAmount $amount, int $decimals): array
    {
        $parts = explode('.', $amount->roundedAt($decimals)->text(), 2);
        $figure = $parts[0] . ($decimals > 0 ? '.' . str_pad($parts[1] ?? '', $decimals, '0') : '');

        return ['figure' => $figure, 'decimals' => $decimals];
    }

    /**
     * A count at a number of decimals as its decimal text.
     *
     * @param int $count The count.
     * @param int $decimals Its decimals.
     *
     * @return array{figure: string, decimals: int}
     *
     * @since 1.14.0
     */
    private static function figureOf(int $count, int $decimals): array
    {
        $digits = str_pad(ltrim((string) $count, '-'), $decimals + 1, '0', STR_PAD_LEFT);
        $figure = $decimals === 0 ? $digits : substr($digits, 0, -$decimals) . '.' . substr($digits, -$decimals);

        return ['figure' => ($count < 0 ? '-' : '') . $figure, 'decimals' => $decimals];
    }

    /**
     * Check if this currency equals another
     * @since  1.0.0
     */
    public function equals(Currency $other): bool
    {
        return $this->number === $other->number;
    }

    /**
     * Check if this is a specific currency by code
     * @since  1.0.0
     */
    public function is(string $code): bool
    {
        return strcasecmp($this->code, $code) === 0;
    }

    /**
     * Export as array
     * @since  1.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'number' => $this->number,
            'symbol' => $this->symbol,
            'decimal_places' => $this->decimalPlaces,
            'decimal_separator' => $this->decimalSeparator,
            'thousand_separator' => $this->thousandSeparator,
            'format_pattern' => $this->formatPattern,
        ];
    }

    /**
     * String representation
     * @since  1.0.0
     */
    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->code, $this->symbol);
    }

    /**
     * Allow serialization for caching
     * @since  1.0.0
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'number' => $this->number,
            'symbol' => $this->symbol,
            'decimalPlaces' => $this->decimalPlaces,
            'decimalSeparator' => $this->decimalSeparator,
            'thousandSeparator' => $this->thousandSeparator,
            'formatPattern' => $this->formatPattern,
        ];
    }

    /**
     * Allow deserialization from cache
     * @since  1.0.0
     */
    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->name = $data['name'];
        $this->code = $data['code'];
        $this->number = $data['number'];
        $this->symbol = $data['symbol'];
        $this->decimalPlaces = $data['decimalPlaces'];
        $this->decimalSeparator = $data['decimalSeparator'];
        $this->thousandSeparator = $data['thousandSeparator'];
        $this->formatPattern = $data['formatPattern'];
    }
}
