<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

use DivisionByZeroError;
use InvalidArgumentException;

defined('_JEXEC') or die;

/**
 * Money Value Object - Production Ready
 *
 * Immutable money representation following Martin Fowler's Money Pattern.
 * Stores amounts as integers (minor units) to avoid floating-point errors.
 *
 * The scale those minor units are counted at is the currency's configured
 * decimal_place, Alfa's authoritative monetary scale, chosen by the merchant.
 * Every amount here is rounded, stored and formatted at that scale, and a
 * gateway that needs a different one asks {@see self::toScale()} for it.
 *
 * EVERY AMOUNT IS COMPUTED ON INTEGERS AND ROUNDED ONCE, half away from zero,
 * at the currency's scale, so a figure is the same on every PHP version. An
 * integer or a decimal text is read as written, on its digits. A float is
 * read at the fifteen significant digits it carries: every decimal of up to
 * fifteen digits survives a float, and what a float holds beyond them is the
 * remainder of its binary form or of the arithmetic that produced it. A
 * percentage, a factor and a divisor are exact ratios
 * ({@see self::timesRatio()}): no float product is formed, and none is rounded.
 *
 * Features:
 * - Perfect precision (no floating-point errors)
 * - Immutable (thread-safe)
 * - Currency-aware operations
 * - Allocation without losing cents (Fowler's algorithm)
 * - Support for cryptocurrencies (8+ decimals)
 *
 * Examples:
 * - $19.99 is stored as 1999 cents
 * - 1 BTC is stored as 100,000,000 satoshis
 * - €50.00 is stored as 5000 cents
 *
 *
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @link     https://easylogic.gr
 * @since  1.0.0
 */
class Money
{
    /**
     * How a float is printed at a currency's scale, by scale. A float whose
     * print at the scale reads back as exactly it IS that decimal.
     *
     * @since  2.0.0
     */
    private const PRINT_AT_SCALE = ['%.0F', '%.1F', '%.2F', '%.3F', '%.4F', '%.5F', '%.6F', '%.7F', '%.8F'];

    /**
     * Below this, by scale, the fifteen significant digits of a float keep
     * two decimals past the scale: 10 to the power of 13 less the scale.
     *
     * One decimal past the scale is not enough. The fifteenth digit is itself
     * a rounding, so an amount a little under a tie (256542587804.1246) would
     * be rounded to the tie first (.125) and past it second (.13).
     *
     * @since  2.0.0
     */
    private const FIFTEEN_REACH = [1.0E+13, 1.0E+12, 1.0E+11, 1.0E+10, 1.0E+9, 1.0E+8, 1.0E+7, 1.0E+6, 1.0E+5];

    /**
     * The largest denominator the integer scaling divides by: twice it still
     * fits an integer. A larger one is divided on its digits.
     *
     * @since  2.0.0
     */
    private const HALF_MAX = PHP_INT_MAX >> 1;

    /**
     * Amount in minor units (cents, satoshis, etc.)
     *
     * Examples:
     * - $19.99 = 1999 cents
     * - 1 BTC = 100,000,000 satoshis
     * - ¥100 = 100 (JPY has 0 decimal places)
     */
    private int $minorUnits;

    /**
     * Currency information
     */
    private Currency $currency;

    /**
     * Private constructor to enforce factory methods
     *
     * @param int $minorUnits Amount in minor units (cents)
     * @param Currency $currency Currency object
     * @since  1.0.0
     */
    private function __construct(int $minorUnits, Currency $currency)
    {
        $this->minorUnits = $minorUnits;
        $this->currency = $currency;
    }

    // Factory methods

    /**
     * Create Money from major units (dollars, euros, etc.)
     *
     * This is the primary way to create Money objects.
     * Converts the amount to minor units internally.
     *
     * The amount is rounded ONCE at the currency's scale, half away from zero,
     * on its decimal digits. An integer or a decimal text is read as written
     * (the text a decimal column prints, of any length). A float is read at
     * the fifteen significant digits it carries, so 1.005 is 1.01 at two
     * decimals, and so is the 1.0049999999999999 float arithmetic can make of
     * it. An amount so large that fifteen digits do not keep two decimals
     * past the scale is read on the shortest text that reads back as the very
     * float, so no digit of it is dropped and it is rounded once.
     *
     * @param string|int|float $amount Amount in major units (e.g., 19.99 for $19.99)
     * @param Currency $currency Currency object
     * @return self Immutable Money object
     *
     * @throws InvalidArgumentException When the amount is not a finite number, or is more than minor units can hold.
     *
     * @example
     * $price = Money::of(19.99, Currency::usd()); // $19.99
     * $price = Money::of('19.99000000', Currency::usd()); // $19.99, as a decimal column prints it
     * $btc = Money::of(0.5, Currency::btc()); // 0.5 BTC
     * @since  1.0.0
     */
    public static function of(string|int|float $amount, Currency $currency): self
    {
        $scale = $currency->getDecimalPlaces();

        if (\is_float($amount)) {
            $reaches = abs($amount) < self::FIFTEEN_REACH[$scale];

            // The common case: the float IS a decimal at this scale when its
            // print at the scale reads back as exactly it.
            if ($reaches) {
                $printed = sprintf(self::PRINT_AT_SCALE[$scale], $amount);

                if ((float) $printed === $amount) {
                    return new self((int) str_replace('.', '', $printed), $currency);
                }
            }

            if (!is_finite($amount)) {
                throw new InvalidArgumentException('An amount must be a finite number.');
            }

            [$integer, $fraction] = self::floatDigits(value: abs($amount), shortest: !$reaches);

            return new self(self::minorUnitsOf(
                negative: $amount < 0,
                integer: $integer,
                fraction: $fraction,
                original: ($amount < 0 ? '-' : '') . $integer . ($fraction === '' ? '' : '.' . $fraction),
                currency: $currency,
            ), $currency);
        }

        if (\is_int($amount)) {
            $minorUnits = $amount * 10 ** $scale;

            if (!\is_int($minorUnits)) {
                throw new InvalidArgumentException(sprintf("'%d' is more %s than minor units can hold.", $amount, $currency->getCode()));
            }

            return new self($minorUnits, $currency);
        }

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $amount, $m) !== 1) {
            // Exponent notation and the like: numeric text is read the way the
            // float it names is.
            if (!is_numeric($amount)) {
                throw new InvalidArgumentException(sprintf("Cannot read '%s' as an amount: it is not a number.", $amount));
            }

            return self::of((float) $amount, $currency);
        }

        return new self(self::minorUnitsOf(
            negative: $m[1] === '-',
            integer: $m[2],
            fraction: $m[3] ?? '',
            original: $amount,
            currency: $currency,
        ), $currency);
    }

    /**
     * Create Money from minor units (cents, satoshis, etc.)
     *
     * Use this when you already have the amount in cents.
     * This is the most precise way to create Money.
     *
     * @param int $minorUnits Amount in minor units (e.g., 1999 for $19.99)
     * @param Currency $currency Currency object
     * @return self Immutable Money object
     *
     * @example
     * $price = Money::ofMinor(1999, Currency::usd()); // $19.99
     * $btc = Money::ofMinor(50000000, Currency::btc()); // 0.5 BTC
     * @since  1.0.0
     */
    public static function ofMinor(int $minorUnits, Currency $currency): self
    {
        return new self($minorUnits, $currency);
    }

    /**
     * A unit rate times a quantity, rounded ONCE at the currency's scale.
     *
     * The rate is never quantised first: 0.125 x 1000 is 125.00, where the
     * unit rounded to 0.13 first would make it 130.00. The arithmetic is on
     * integers throughout, ties away from zero, so no float holds the product.
     *
     * A RATE OF ANY LENGTH IS EXTENDED WHEN ITS LINE FITS. The rate is split
     * at the currency's scale before either part becomes an integer: what the
     * scale holds is one unit in minor units and is multiplied by the
     * quantity, and what is finer is rounded with the quantity. So
     * 123456789012.12345678 x 3 is 370,370,367,036.37, and the only refusals
     * are of a unit, or a line, that minor units cannot hold.
     *
     * @param string $rate A non-negative plain decimal, e.g. a canonical rate.
     * @param int $quantity The quantity, 0 or more.
     * @param Currency $currency The currency, at the scale the line is charged in.
     *
     *
     * @throws InvalidArgumentException When the rate is not a plain decimal, the quantity is negative, or one
     *                                  unit or the line total does not fit in minor units.
     *
     * @since  2.0.0
     */
    public static function ofRateTimesQuantity(string $rate, int $quantity, Currency $currency): self
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $rate, $m) !== 1 || $quantity < 0) {
            throw new InvalidArgumentException(sprintf("A rate times a quantity needs a plain decimal rate and a quantity of 0 or more, not '%s' x %d.", $rate, $quantity));
        }

        if ($quantity === 0) {
            return new self(0, $currency);
        }

        $scale = $currency->getDecimalPlaces();
        $fraction = $m[2] ?? '';
        $unit = self::minorUnitsFromDigits(
            digits: ltrim($m[1] . substr(str_pad($fraction, $scale, '0'), 0, $scale), '0'),
            original: $rate,
            currency: $currency,
        );
        $line = $unit * $quantity;

        // What the scale does not hold, as a count of its own smallest place:
        // quantity x that count / 10^places, rounded once.
        $finer = rtrim((string) substr($fraction, $scale), '0');

        if ($finer !== '') {
            $line += \strlen($finer) <= 18
                ? self::scaleCount(count: $quantity, numerator: (int) $finer, denominator: 10 ** \strlen($finer))
                : self::scaleOnDigits(negative: false, count: (string) $quantity, numerator: $finer, denominator: '1' . str_repeat('0', \strlen($finer)));
        }

        // An integer product or sum that overflows is answered with a float.
        if (!\is_int($line)) {
            throw new InvalidArgumentException('The line total is larger than minor units can hold.');
        }

        return new self($line, $currency);
    }

    /**
     * Create zero money
     *
     * Useful as a starting point for accumulation.
     *
     * @param Currency $currency Currency object
     * @return self Money object with zero value
     *
     * @example
     * $total = Money::zero(Currency::usd());
     * foreach ($items as $item) {
     *     $total = $total->add($item->price);
     * }
     * @since  1.0.0
     */
    /**
     * THE monetary rounding primitive: one rule, one place.
     *
     * Every rounding of a monetary amount goes through here, so the whole
     * system rounds identically instead of each caller picking a scale. The
     * scale is never assumed: it is the CURRENCY's, which Currency parses
     * fail-closed from decimal_place, so a figure that comes out of this method
     * is by construction expressible in the money it claims to be.
     *
     * An ad-hoc round($x, 6) hard-codes a scale that belongs to no currency:
     * on a 2-decimal shop it leaves four digits of residue in figures that
     * are then frozen and read back as truth.
     *
     * The amount is read as {@see self::of()} reads it, so the rounding is
     * done on its digits and is the same on every PHP version.
     *
     * @param string|int|float $amount The amount to round.
     * @param Currency $currency The currency whose scale decides.
     *
     * @return float The amount, expressible in that currency.
     *
     * @since  2.0.0
     */
    public static function roundToCurrency(string|int|float $amount, Currency $currency): float
    {
        return self::of($amount, $currency)->getAmount();
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Read a written amount, in the syntax its currency is written in.
     *
     * The currency decides everything monetary: which character separates the
     * fraction, which one groups the thousands, how many decimals the result
     * has, and how a longer input is rounded to them. Nothing is inferred from
     * the input itself: an amount whose spelling does not match the syntax is
     * refused, never re-read as some other amount.
     *
     * Callers that already hold a canonical ungrouped decimal (an API body, a
     * database column, a computed figure) want {@see self::of()} instead:
     * there is no presentation in "1234.56" to interpret.
     *
     * @param string $amount The written amount, optionally carrying this currency's own symbol.
     * @param Currency $currency The currency it is in. Its scale and its rounding are the result's.
     * @param MoneyInputFormat|null $format An input syntax to read it in, for feeds not written the way this currency is displayed. It changes the SPELLING only: not the currency, not the scale, not the rounding.
     * @return self The amount, at this currency's scale.
     * @throws InvalidArgumentException When the text is not this amount written in that syntax.
     *
     * @example
     * $eur = Currency::loadByCode('EUR');   // decimal ',', thousand '.', 2 places
     * Money::parse('1.234,56', $eur);       // 1234.56
     * Money::parse('1.234', $eur);          // 1234.00 - '.' groups, it never decimates
     * Money::parse('1,006', $eur);          // 1.01    - rounded to the currency's scale
     * Money::parse('1.0060', $eur);         // refused - '0060' is not a group of three
     * Money::parse('$100,00', $eur);        // refused - that is not this currency's symbol
     *
     * // A CSV feed that writes EUR the American way:
     * Money::parse('1,234.56', $eur, MoneyInputFormat::of(decimalSeparator: '.', thousandSeparator: ','));
     * @since  1.0.0
     */
    public static function parse(string $amount, Currency $currency, ?MoneyInputFormat $format = null): self
    {
        $format ??= MoneyInputFormat::fromCurrency($currency);

        $text = self::trimBlanks($amount);
        $symbol = $currency->getSymbol();

        // The symbol is presentation too. It may sit on either side, as this
        // currency's format pattern puts it, and it is this currency's symbol
        // or none: a '$' on a EUR amount is a wrong amount, not a stray glyph.
        if ($symbol !== '') {
            if (str_starts_with($text, $symbol)) {
                $text = self::trimBlanks(substr($text, \strlen($symbol)));
            } elseif (str_ends_with($text, $symbol)) {
                $text = self::trimBlanks(substr($text, 0, -\strlen($symbol)));
            }
        }

        $decimal = $format->getDecimalSeparator();
        $thousand = $format->getThousandSeparator();

        // Grouping is optional (number_format() omits it below a thousand),
        // but a grouped number must be grouped correctly: one to three digits,
        // then groups of exactly three.
        $integer = $thousand === ''
            ? '\d+'
            : '(?:\d{1,3}(?:' . preg_quote($thousand, '/') . '\d{3})+|\d+)';

        // The sign has one position: in front of the number, where
        // number_format() puts it and therefore where Currency::format()
        // renders it. A fraction, when written, has digits in it.
        $pattern = '/^(-?)(' . $integer . ')(?:' . preg_quote($decimal, '/') . '(\d+))?$/u';

        if (preg_match($pattern, $text, $parts) !== 1) {
            throw new InvalidArgumentException(sprintf(
                "Cannot read '%s' as %s: expected a number written with '%s' for decimals%s.",
                $amount,
                $currency->getCode(),
                $decimal,
                $thousand === '' ? ' and no grouping' : " and '" . $thousand . "' for grouping",
            ));
        }

        $digits = $thousand === '' ? $parts[2] : str_replace($thousand, '', $parts[2]);

        // The currency's scale says how many of the fraction digits are
        // money; the first one that is not says whether the last one that is
        // rounds up. Half away from zero, on the digits themselves, so the
        // answer never depends on a float having held the number: the one
        // rounding every amount takes, written or computed.
        return self::ofMinor(self::minorUnitsOf(
            negative: $parts[1] === '-',
            integer: $digits,
            fraction: $parts[3] ?? '',
            original: $amount,
            currency: $currency,
        ), $currency);
    }

    // Getters

    /**
     * Get amount in major units (dollars, euros, etc.)
     *
     * Converts internal minor units back to readable format.
     *
     * @return float Amount in major units (e.g., 19.99)
     *
     * @example
     * $money = Money::of(19.99, Currency::usd());
     * echo $money->getAmount(); // 19.99
     * @since  1.0.0
     */
    public function getAmount(): float
    {
        return self::convertToMajorUnits($this->minorUnits, $this->currency);
    }

    /**
     * Get amount in minor units (cents, satoshis, etc.)
     *
     * Returns the raw internal representation.
     * This is the most precise representation.
     *
     * @return int Amount in minor units (e.g., 1999)
     *
     * @example
     * $money = Money::of(19.99, Currency::usd());
     * echo $money->getMinorUnits(); // 1999
     * @since  1.0.0
     */
    public function getMinorUnits(): int
    {
        return $this->minorUnits;
    }

    /**
     * This amount as an integer at a given scale: the rescaling primitive.
     *
     * Alfa's internal scale is the currency's configured decimal_place, and it
     * is nobody else's. A payment protocol that wants an integer wants it at
     * ITS OWN exponent, so an adapter must convert from the monetary VALUE
     * instead of reusing {@see self::getMinorUnits()}: on a shop that runs EUR
     * at four places, 10.55 is 105500 internally and 1055 on a cents wire, and
     * handing over the internal figure overcharges by a hundred.
     *
     * The conversion is exact integer arithmetic (no float sees the amount)
     * and ties round away from zero, the same rule
     * {@see self::roundToCurrency()} applies.
     *
     * Core holds no table of protocol exponents. The adapter knows its own and
     * passes it here.
     *
     * @param int $scale Decimal places the protocol's integer is expressed in.
     *
     * @return int This value expressed at that scale.
     *
     * @throws InvalidArgumentException If the scale is negative, or the amount at that scale is more than an
     *                                  integer can hold.
     *
     * @example
     * // A shop running EUR at four decimal places, order due 10.55:
     * Money::of(10.55, $eur4)->getMinorUnits(); // 105500 - internal, never a wire amount
     * Money::of(10.55, $eur4)->toScale(2);      // 1055   - what a cents gateway must receive
     * @since  2.0.0
     */
    public function toScale(int $scale): int
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('Cannot express money at a negative scale: ' . $scale);
        }

        $from = $this->currency->getDecimalPlaces();

        if ($scale === $from || $this->minorUnits === 0) {
            return $this->minorUnits;
        }

        if ($scale > $from) {
            // An integer product that overflows is answered with a float.
            $scaled = $this->minorUnits * 10 ** ($scale - $from);

            if (!\is_int($scaled)) {
                throw new InvalidArgumentException('The amount is larger than an integer at scale ' . $scale . ' can hold.');
            }

            return $scaled;
        }

        // A coarser scale holds fewer digits, so the quotient always fits.
        return (int) self::scaleCount(count: $this->minorUnits, numerator: 1, denominator: 10 ** ($from - $scale));
    }

    /**
     * This whole-line amount per unit, at Alfa's finest scale.
     *
     * The per-unit face of a line whose rate is finer than its currency: 100.81
     * over 1000 units is 0.10081, where a divide at the currency's scale would
     * say 0.10. Exact integer arithmetic at {@see Currency::SCALE_MAX} places,
     * ties away from zero.
     *
     * @param int $quantity The quantity, 1 or more.
     *
     * @return float The unit, at up to eight decimal places.
     *
     * @throws InvalidArgumentException When the quantity is not positive, or the amount does not fit at scale 8.
     *
     * @since  2.0.0
     */
    public function perUnitAtMaxScale(int $quantity): float
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A per-unit amount needs a quantity of 1 or more.');
        }

        $from = $this->currency->getDecimalPlaces();

        if (abs($this->minorUnits) > intdiv(PHP_INT_MAX, (int) (10 ** (Currency::SCALE_MAX - $from)))) {
            throw new InvalidArgumentException('The amount is larger than eight decimal places can hold.');
        }

        $units = $this->toScale(scale: Currency::SCALE_MAX);

        // A share of the count never exceeds it, so it always fits.
        return (int) self::scaleCount(count: $units, numerator: 1, denominator: $quantity) / 10 ** Currency::SCALE_MAX;
    }

    /**
     * Get currency object
     *
     * @return Currency Currency information
     *
     * @example
     * $money = Money::of(19.99, Currency::usd());
     * echo $money->getCurrency()->getCode(); // "USD"
     * @since  1.0.0
     */
    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    // Arithmetic operations
    // All operations return NEW Money objects (immutability)

    /**
     * Add another Money amount
     *
     * Both Money objects must have the same currency.
     * Returns a new Money object (immutable).
     *
     * @param Money $other Money to add
     * @return self New Money object with sum
     * @throws InvalidArgumentException If currencies don't match, or the sum is more than minor units can hold
     *
     * @example
     * $price = Money::of(10.00, Currency::usd());
     * $tax = Money::of(2.00, Currency::usd());
     * $total = $price->add($tax); // $12.00
     * @since  1.0.0
     */
    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);

        // An integer sum that overflows is answered with a float.
        $sum = $this->minorUnits + $other->minorUnits;

        if (!\is_int($sum)) {
            throw new InvalidArgumentException('The sum is larger than minor units can hold.');
        }

        return new self($sum, $this->currency);
    }

    /**
     * Subtract another Money amount
     *
     * Both Money objects must have the same currency.
     * Can result in negative money.
     *
     * @param Money $other Money to subtract
     * @return self New Money object with difference
     * @throws InvalidArgumentException If currencies don't match, or the difference is more than minor units can hold
     *
     * @example
     * $price = Money::of(100.00, Currency::usd());
     * $discount = Money::of(10.00, Currency::usd());
     * $final = $price->subtract($discount); // $90.00
     * @since  1.0.0
     */
    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);

        // An integer difference that overflows is answered with a float.
        $difference = $this->minorUnits - $other->minorUnits;

        if (!\is_int($difference)) {
            throw new InvalidArgumentException('The difference is larger than minor units can hold.');
        }

        return new self($difference, $this->currency);
    }

    /**
     * Multiply by a numeric factor
     *
     * The factor is an exact decimal: the product is formed on integers and
     * rounded once ({@see self::timesRatio()}). A factor that is itself a
     * quotient of two numbers is handed to timesRatio() as those two numbers.
     *
     * @param string|int|float $multiplier Factor to multiply by
     * @return self New Money object with result
     * @throws InvalidArgumentException If the factor is not a finite number, or the result is more than minor
     *                                  units can hold
     *
     * @example
     * $unitPrice = Money::of(19.99, Currency::usd());
     * $total = $unitPrice->multiply(5); // $99.95
     * @since  1.0.0
     */
    public function multiply(string|int|float $multiplier): self
    {
        return $this->timesRatio(numerator: $multiplier, denominator: 1);
    }

    /**
     * Divide by a numeric divisor
     *
     * The divisor is an exact decimal: the quotient is formed on integers and
     * rounded once ({@see self::timesRatio()}).
     * Use allocate() if you need to split money without losing cents.
     *
     * @param string|int|float $divisor Number to divide by
     * @return self New Money object with result
     * @throws InvalidArgumentException If divisor is zero or not a finite number, or the result is more than
     *                                  minor units can hold
     *
     * @example
     * $total = Money::of(100.00, Currency::usd());
     * $perMonth = $total->divide(12); // $8.33 (rounded)
     * @since  1.0.0
     */
    public function divide(string|int|float $divisor): self
    {
        return $this->timesRatio(numerator: 1, denominator: $divisor);
    }

    /**
     * Calculate percentage of this amount
     *
     * Commonly used for taxes, discounts, tips, etc. The percentage is an
     * exact decimal and the amount it takes is the ratio percent / 100,
     * formed on integers and rounded once ({@see self::timesRatio()}): 35% of
     * 0.90 is 0.315, a tie, 0.32.
     *
     * @param string|int|float $percent Percentage (e.g., 10 for 10%)
     * @return self New Money object with percentage amount
     * @throws InvalidArgumentException If the percentage is not a finite number, or the result is more than
     *                                  minor units can hold
     *
     * @example
     * $price = Money::of(100.00, Currency::usd());
     * $tax = $price->percentage(10); // $10.00 (10% of $100)
     * $discount = $price->percentage('17.500'); // $17.50, the rate as a decimal column prints it
     * @since  1.0.0
     */
    public function percentage(string|int|float $percent): self
    {
        return $this->timesRatio(numerator: $percent, denominator: 100);
    }

    /**
     * This amount times an exact ratio, rounded ONCE at the currency's scale,
     * half away from zero: amount x numerator / denominator.
     *
     * THE ONE MULTIPLICATION AND THE ONE DIVISION OF MONEY. A percentage is
     * the ratio percent / 100, a factor is factor / 1, a divisor is
     * 1 / divisor, a share of a quantity is taken / whole, and the net of a
     * gross is the gross times the tax factor's denominator over its
     * numerator. Whatever the two operands are, the product and the quotient
     * are formed on integers, so no float holds either and a tie is a tie on
     * every PHP version.
     *
     * Each operand is an integer, a decimal text (read as written, of any
     * length) or a float (read at the fifteen significant digits it
     * carries). A caller that holds two amounts or two quantities hands both:
     * a quotient formed in floats first (1 / 6) is no longer a sixth.
     *
     * @param string|int|float $numerator What the amount is multiplied by.
     * @param string|int|float $denominator What it is divided by; not zero.
     *
     *
     * @throws InvalidArgumentException When the denominator is zero, an operand is not a finite number, or the
     *                                  result is more than minor units can hold.
     *
     * @example
     * $net   = $gross->timesRatio(numerator: 25, denominator: 31); // a gross at 24%, back to its net
     * $share = $saving->timesRatio(numerator: $quantity, denominator: $lineQuantity);
     * @since  2.0.0
     */
    public function timesRatio(string|int|float $numerator, string|int|float $denominator): self
    {
        if (\is_int($numerator) && \is_int($denominator)) {
            if ($denominator === 0) {
                throw new InvalidArgumentException('Cannot divide money by zero');
            }

            $scaled = self::scaleCount(count: $this->minorUnits, numerator: $numerator, denominator: $denominator);
        } else {
            $scaled = self::scaleByDecimals(count: $this->minorUnits, numerator: $numerator, denominator: $denominator, shift: 0);
        }

        if ($scaled === null) {
            throw new InvalidArgumentException('The result is larger than minor units can hold.');
        }

        return new self($scaled, $this->currency);
    }

    /**
     * This amount times a SUM of exact ratios over one divisor, rounded ONCE
     * at the currency's scale, half away from zero:
     * amount x (a1 x b1 / d1 + a2 x b2 / d2 + ...) / divisor.
     *
     * For a figure several lines make together, each at a ratio of its own.
     * The net of a discount taken across lines of different tax rates is the
     * discount times the sum of each line's net times what the line has left
     * over its gross, over the base. No line's part of that sum is a decimal,
     * so none can be rounded before the others are added: each rounding would
     * move the figure by up to half a minor unit. The whole sum is formed on
     * integers and only the result is rounded.
     *
     * @param array<int, array{0: int, 1: int, 2: int}> $terms The terms, each [a, b, d] read a x b / d, with
     *                                                         d above zero.
     * @param int $divisor What the sum is divided by, above zero.
     *
     *
     * @throws InvalidArgumentException When the divisor or a term's denominator is not above zero, a term is not
     *                                  three whole numbers, or the result is more than minor units can hold.
     *
     * @example
     * // The net of a discount on two lines, a quarter of the first already taken:
     * $net = $discount->timesRatioSum(terms: [[1000, 930, 1240], [806, 999, 999]], divisor: 1929);
     * @since  2.0.0
     */
    public function timesRatioSum(array $terms, int $divisor): self
    {
        if ($divisor <= 0) {
            throw new InvalidArgumentException('A sum of ratios is divided by a whole number above zero.');
        }

        foreach ($terms as $term) {
            if (
                !\is_array($term) || \count($term) !== 3
                || !\is_int($term[0] ?? null) || !\is_int($term[1] ?? null) || !\is_int($term[2] ?? null)
                || $term[2] <= 0
            ) {
                throw new InvalidArgumentException('Each term of a sum of ratios is two whole numbers over a whole number above zero.');
            }
        }

        $scaled = self::scaleBySum(count: $this->minorUnits, terms: $terms, divisor: $divisor);

        if ($scaled === null) {
            throw new InvalidArgumentException('The result is larger than minor units can hold.');
        }

        return new self($scaled, $this->currency);
    }

    /**
     * A whole count times an exact ratio, rounded ONCE, half away from zero:
     * the one exact scaling, for minor units and for any other count of a
     * smallest unit.
     *
     * count x numerator / denominator is formed without leaving integers.
     * When the product fits one, it is one multiplication and one division.
     * When it does not, it is formed in three exact pieces: with count =
     * whole x denominator + rest and numerator = times x denominator + part,
     * it is whole x numerator + rest x times + (rest x part) / denominator.
     * Only rest x part can be larger than an integer holds; it is then built
     * one binary digit of part at a time and reduced by the denominator
     * whenever it reaches it, so nothing above twice the denominator is ever
     * held. An operand too large for that (the smallest integer, a
     * denominator above half the largest), and a result at the very edge
     * below zero, are taken on their decimal digits. Every path gives the
     * same answer, and what is left over decides the rounding.
     *
     * @param int $count The count.
     * @param int $numerator The ratio's numerator.
     * @param int $denominator The ratio's denominator, not zero.
     *
     * @return int|null The scaled count; null when it is more than an integer holds, so each caller words its
     *                  own refusal.
     *
     * @throws DivisionByZeroError When the denominator is zero.
     *
     * @since  2.0.0
     */
    public static function scaleCount(int $count, int $numerator, int $denominator): ?int
    {
        if ($denominator === 0) {
            throw new DivisionByZeroError('Division by zero');
        }

        $negative = ($count < 0) !== (($numerator < 0) !== ($denominator < 0));

        if (
            $count === PHP_INT_MIN || $numerator === PHP_INT_MIN || $denominator === PHP_INT_MIN
            || $denominator > self::HALF_MAX || $denominator < -self::HALF_MAX
        ) {
            return self::scaleOnDigits(
                negative: $negative,
                count: ltrim((string) $count, '-'),
                numerator: ltrim((string) $numerator, '-'),
                denominator: ltrim((string) $denominator, '-'),
            );
        }

        [$count, $numerator, $denominator] = [abs($count), abs($numerator), abs($denominator)];

        // An integer product that overflows is answered with a float.
        $product = $count * $numerator;

        if (\is_int($product)) {
            $quotient = intdiv($product, $denominator);
            $remainder = $product - $quotient * $denominator;
        } else {
            $whole = intdiv($count, $denominator);
            $rest = $count % $denominator;
            $times = intdiv($numerator, $denominator);
            $part = $numerator % $denominator;

            if ($rest === 0 || $part <= intdiv(PHP_INT_MAX, $rest)) {
                $quotient = intdiv($rest * $part, $denominator);
                $remainder = ($rest * $part) % $denominator;
            } else {
                $quotient = 0;
                $remainder = 0;

                foreach (str_split(decbin($part)) as $digit) {
                    $quotient += $quotient;
                    $remainder += $remainder;

                    if ($remainder >= $denominator) {
                        $remainder -= $denominator;
                        $quotient++;
                    }

                    if ($digit === '1') {
                        $remainder += $rest;

                        if ($remainder >= $denominator) {
                            $remainder -= $denominator;
                            $quotient++;
                        }
                    }
                }
            }

            $quotient = $whole * $numerator + $rest * $times + $quotient;

            // Past the largest integer. Below zero that can still be the
            // smallest one, exactly: decided on the digits.
            if (!\is_int($quotient)) {
                return $negative
                    ? self::scaleOnDigits(negative: true, count: (string) $count, numerator: (string) $numerator, denominator: (string) $denominator)
                    : null;
            }
        }

        // Half away from zero: up when the remainder is at least half the denominator.
        if ($remainder >= $denominator - $remainder) {
            if ($quotient === PHP_INT_MAX) {
                return $negative ? PHP_INT_MIN : null;
            }

            $quotient++;
        }

        return $negative ? -$quotient : $quotient;
    }

    /**
     * What one whole count is of another, as a percentage at two decimals,
     * half away from zero: THE ONE PERCENTAGE OF TWO AMOUNTS.
     *
     * A saving against its base, a sale against its list: part x 10000 /
     * whole is formed on integers ({@see self::scaleCount()}) and rounded
     * once, in hundredths of a percent. So 2.38 of 4.48 is 53.125 and reads
     * 53.13 on every PHP version, where the float quotient of the two lands
     * beside the tie (53.124999999999986) and its rounding is not the same
     * from one PHP version to the next.
     *
     * The two counts are of one unit (minor units of one currency, or any
     * other smallest unit), each an integer or, when no integer holds it, its
     * decimal digits. Of nothing there is no percentage: a whole of zero
     * states 0.0.
     *
     * @param int|string $part The count the percentage is asked of; below zero for a negative percentage.
     * @param int|string $whole The count it is measured against.
     *
     * @return float The percentage, at two decimals.
     *
     * @throws InvalidArgumentException When a count given as text is not a whole number, or the percentage is
     *                                  larger than a count of hundredths can hold.
     *
     * @example
     * $percent = Money::percentOf(part: $saving->getMinorUnits(), whole: $base->getMinorUnits());
     * @since  2.0.0
     */
    public static function percentOf(int|string $part, int|string $whole): float
    {
        if (\is_int($part) && \is_int($whole)) {
            if ($whole === 0) {
                return 0.0;
            }

            $hundredths = self::scaleCount(count: $part, numerator: 10000, denominator: $whole);
        } else {
            foreach ([$part, $whole] as $count) {
                if (\is_string($count) && preg_match('/^-?\d+$/', $count) !== 1) {
                    throw new InvalidArgumentException(sprintf("A percentage is taken of whole counts, and '%s' is not one.", $count));
                }
            }

            if (ltrim((string) $whole, '-0') === '') {
                return 0.0;
            }

            $hundredths = self::scaleByDecimals(count: 10000, numerator: $part, denominator: $whole, shift: 0);
        }

        if ($hundredths === null) {
            throw new InvalidArgumentException('The percentage is larger than a count of hundredths can hold.');
        }

        return $hundredths / 100;
    }

    /**
     * Get absolute value
     *
     * Converts negative money to positive.
     *
     * @return self New Money object with absolute value
     * @throws InvalidArgumentException If the absolute value is more than minor units can hold
     *
     * @example
     * $debt = Money::of(-50.00, Currency::usd());
     * $amount = $debt->abs(); // $50.00
     * @since  1.0.0
     */
    public function abs(): self
    {
        return $this->minorUnits < 0 ? $this->negate() : new self($this->minorUnits, $this->currency);
    }

    /**
     * Negate the amount
     *
     * Converts positive to negative and vice versa.
     *
     * @return self New Money object with negated amount
     * @throws InvalidArgumentException If the negated amount is more than minor units can hold
     *
     * @example
     * $credit = Money::of(50.00, Currency::usd());
     * $debit = $credit->negate(); // -$50.00
     * @since  1.0.0
     */
    public function negate(): self
    {
        // The smallest integer has no opposite an integer holds.
        if ($this->minorUnits === PHP_INT_MIN) {
            throw new InvalidArgumentException('The opposite of this amount is larger than minor units can hold.');
        }

        return new self(-$this->minorUnits, $this->currency);
    }

    /**
     * Ensure non-negative (floor at zero)
     *
     * Useful when discounts shouldn't exceed price.
     *
     * @return self New Money object, zero if negative
     *
     * @example
     * $price = Money::of(10.00, Currency::usd());
     * $discount = Money::of(20.00, Currency::usd());
     * $final = $price->subtract($discount)->nonNegative(); // $0.00
     * @since  1.0.0
     */
    public function nonNegative(): self
    {
        return new self(max(0, $this->minorUnits), $this->currency);
    }

    /**
     * Round to currency's decimal places
     *
     * When using integers, money is already at the smallest unit.
     * This method exists for API compatibility but doesn't change the value.
     *
     * @param int $mode Rounding mode (not used with integers)
     * @return self Returns self (already rounded)
     * @since  1.0.0
     */
    public function round(int $mode = PHP_ROUND_HALF_UP): self
    {
        // With integer storage, we're already at the smallest unit
        // No rounding needed!
        return $this;
    }

    // ALLOCATION (Fowler's Algorithm - Prevents Losing Cents)

    /**
     * Allocate money equally across N targets
     *
     * Uses Martin Fowler's allocation algorithm to distribute money
     * without losing cents due to rounding.
     *
     * The remainder is distributed one cent at a time to the first targets.
     *
     * A NEGATIVE AMOUNT SPLITS AS ITS POSITIVE TWIN, NEGATED: allocate(-X, n) is
     * allocate(X, n) with every part negated, so the parts still sum to the
     * original and the extra minor units go to the same targets whatever the
     * sign. floor() on a negative integer does not give that: it sent -0.10
     * over three to three parts of -0.04.
     *
     * @param int $n Number of targets
     * @return array<Money> Array of Money objects that sum to original
     * @throws InvalidArgumentException If n < 1
     *
     * @example
     * $money = Money::of(10.00, Currency::usd());
     * $parts = $money->allocate(3);
     * // Result: [$3.34, $3.33, $3.33] = $10.00 (no cents lost!)
     *
     * $money = Money::of(5.00, Currency::usd());
     * $parts = $money->allocate(3);
     * // Result: [$1.67, $1.67, $1.66] = $5.00
     * @since  1.0.0
     */
    public function allocate(int $n): array
    {
        if ($n < 1) {
            throw new InvalidArgumentException('Cannot allocate to less than 1 target');
        }

        // intdiv() truncates TOWARDS ZERO, so the base share and the remainder both
        // carry the amount's sign and a negative amount mirrors its positive twin.
        // No abs() on the amount: abs(PHP_INT_MIN) is a float.
        $base = intdiv($this->minorUnits, $n);
        $remainder = $this->minorUnits - $base * $n;

        // The remainder is dealt one minor unit at a time, in its own direction.
        $step = $remainder < 0 ? -1 : 1;
        $extras = abs($remainder);

        $result = [];

        // The first $extras targets take one unit more (or, for a negative amount,
        // one unit less): ($base + $step) * $extras + $base * ($n - $extras) is the amount.
        for ($i = 0; $i < $n; $i++) {
            $result[] = new self(
                $i < $extras ? $base + $step : $base,
                $this->currency,
            );
        }

        return $result;
    }

    /**
     * Allocate money by ratios, CUMULATIVELY: the one allocation rule.
     *
     * For each part in the order given, the cumulative target is the amount
     * times the ratios so far over all the ratios, formed on integers and
     * rounded once, half away from zero; the part is that target less the
     * target before it. The last target is the whole amount, so the parts sum
     * to it exactly and the last part takes the exact remainder.
     *
     * The targets never fall as the ratios add up, so no part has the other
     * sign than the amount. Each target is within half a minor unit of its
     * exact value, so a part is less than one minor unit above its exact
     * share: when the ratios are amounts in the same minor units and the
     * amount is at most their sum, no part is above its own ratio. Rounding
     * each part on its own and handing the last part the rest guaranteed
     * neither: 0.03 over five ratios of 1000 and one of 100 gave 0.01 five
     * times and -0.02.
     *
     * A ratio is an integer or an exact decimal, never a float product, and
     * none is below zero.
     *
     * @param array<int|float|string> $ratios Array of ratios (e.g., [1, 2, 3] or [30, 70]), each at least zero
     *                                        and their sum above zero; read positionally.
     * @return array<Money> Array of Money objects, one per ratio, in the same order
     * @throws InvalidArgumentException If a ratio is below zero or not a finite number, the ratios sum to nothing,
     *                                  or a part is more than minor units can hold
     *
     * @example
     * $profit = Money::of(100.00, Currency::usd());
     * $split = $profit->allocateByRatios([30, 70]);
     * // Result: [$30.00, $70.00]
     *
     * $profit = Money::of(100.00, Currency::usd());
     * $split = $profit->allocateByRatios([1, 1, 1]);
     * // Result: [$33.33, $33.34, $33.33] = $100.00
     * @since  1.0.0
     */
    public function allocateByRatios(array $ratios): array
    {
        // POSITION IS THE CONTRACT, NOT THE KEY. The parts are answered as a
        // list in the ratios' order, so the ratios are read positionally: a
        // keyed array (array_filter() keeps keys, and is this codebase's habit
        // for narrowing a collection) is split exactly as its list would be.
        $weights = self::wholeWeights(ratios: array_values($ratios));

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new InvalidArgumentException('A split is made over ratios of zero or more, and one is below zero.');
            }
        }

        $total = array_sum($weights);

        if (!\is_int($total)) {
            throw new InvalidArgumentException('The ratios are larger than a split can hold.');
        }

        if ($total <= 0) {
            throw new InvalidArgumentException('Sum of ratios must be positive');
        }

        $result = [];
        $sofar = 0;
        $given = 0;

        foreach ($weights as $weight) {
            // The ratios so far never pass their sum, which is an integer.
            $sofar += $weight;
            $target = self::scaleCount(count: $this->minorUnits, numerator: $sofar, denominator: $total);

            // A target no integer holds is null.
            if (!\is_int($target)) {
                throw new InvalidArgumentException('A share of this split is larger than minor units can hold.');
            }

            $result[] = new self($target - $given, $this->currency);
            $given = $target;
        }

        return $result;
    }

    /**
     * Allocate money to specific amounts, distribute remainder
     *
     * Useful when you have fixed costs and need to distribute the remainder.
     *
     * @param array<Money> $targets Target amounts (must be same currency)
     * @return array<Money> Array with each target + proportional remainder
     * @throws InvalidArgumentException If targets exceed total
     *
     * @example
     * $total = Money::of(100.00, Currency::usd());
     * $rent = Money::of(50.00, Currency::usd());
     * $food = Money::of(30.00, Currency::usd());
     * $allocated = $total->allocateToTargets([$rent, $food]);
     * // Remainder $20 distributed proportionally
     * @since  1.0.0
     */
    public function allocateToTargets(array $targets): array
    {
        if (empty($targets)) {
            return [];
        }

        // Calculate total of targets
        $targetTotal = Money::zero($this->currency);
        foreach ($targets as $target) {
            $this->assertSameCurrency($target);
            $targetTotal = $targetTotal->add($target);
        }

        // Check if targets exceed total
        if ($targetTotal->greaterThan($this)) {
            throw new InvalidArgumentException('Target amounts exceed total money');
        }

        // Calculate remainder
        $remainder = $this->subtract($targetTotal);

        // Same reasoning as allocateByRatios(): this method answers positionally,
        // so it reads its input positionally. array_map() below PRESERVES the
        // targets' keys, and the distribution list it feeds is a plain list, so
        // indexing that list with a target's key found nothing at all. Measured
        // with targets keyed 3 and 8: a TypeError out of add(), null given.
        $targets = array_values($targets);

        // If no remainder, return targets as-is
        if ($remainder->isZero()) {
            return $targets;
        }

        // Distribute remainder proportionally
        $ratios = array_map(fn ($t) => $t->getMinorUnits(), $targets);
        $distributions = $remainder->allocateByRatios($ratios);

        // Add distributions to targets
        $result = [];
        foreach ($targets as $i => $target) {
            $result[] = $target->add($distributions[$i]);
        }

        return $result;
    }

    // Comparison methods

    /**
     * Check if amount is zero
     *
     * @return bool True if zero
     *
     * @example
     * $money = Money::of(0, Currency::usd());
     * $money->isZero(); // true
     * @since  1.0.0
     */
    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    /**
     * Check if amount is positive
     *
     * @return bool True if greater than zero
     *
     * @example
     * $money = Money::of(10.00, Currency::usd());
     * $money->isPositive(); // true
     * @since  1.0.0
     */
    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    /**
     * Check if amount is negative
     *
     * @return bool True if less than zero
     *
     * @example
     * $debt = Money::of(-50.00, Currency::usd());
     * $debt->isNegative(); // true
     * @since  1.0.0
     */
    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    /**
     * Compare with another Money
     *
     * @param Money $other Money to compare with
     * @return int -1 if less, 0 if equal, 1 if greater
     * @throws InvalidArgumentException If currencies don't match
     *
     * @example
     * $a = Money::of(10.00, Currency::usd());
     * $b = Money::of(20.00, Currency::usd());
     * $a->compareTo($b); // -1 (less than)
     * @since  1.0.0
     */
    public function compareTo(Money $other): int
    {
        $this->assertSameCurrency($other);

        if ($this->minorUnits < $other->minorUnits) {
            return -1;
        }

        if ($this->minorUnits > $other->minorUnits) {
            return 1;
        }

        return 0;
    }

    /**
     * Check if equal to another Money
     *
     * Both amount and currency must match.
     *
     * @param Money $other Money to compare with
     * @return bool True if equal
     *
     * @example
     * $a = Money::of(10.00, Currency::usd());
     * $b = Money::of(10.00, Currency::usd());
     * $a->equals($b); // true
     * @since  1.0.0
     */
    public function equals(Money $other): bool
    {
        return $this->currency->equals($other->currency)
            && $this->minorUnits === $other->minorUnits;
    }

    /**
     * Check if greater than another Money
     *
     * @param Money $other Money to compare with
     * @return bool True if greater
     * @throws InvalidArgumentException If currencies don't match
     *
     * @example
     * $a = Money::of(20.00, Currency::usd());
     * $b = Money::of(10.00, Currency::usd());
     * $a->greaterThan($b); // true
     * @since  1.0.0
     */
    public function greaterThan(Money $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    /**
     * Check if greater than or equal to another Money
     *
     * @param Money $other Money to compare with
     * @return bool True if greater or equal
     * @throws InvalidArgumentException If currencies don't match
     * @since  1.0.0
     */
    public function greaterThanOrEqual(Money $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    /**
     * Check if less than another Money
     *
     * @param Money $other Money to compare with
     * @return bool True if less
     * @throws InvalidArgumentException If currencies don't match
     *
     * @example
     * $a = Money::of(10.00, Currency::usd());
     * $b = Money::of(20.00, Currency::usd());
     * $a->lessThan($b); // true
     * @since  1.0.0
     */
    public function lessThan(Money $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /**
     * Check if less than or equal to another Money
     *
     * @param Money $other Money to compare with
     * @return bool True if less or equal
     * @throws InvalidArgumentException If currencies don't match
     * @since  1.0.0
     */
    public function lessThanOrEqual(Money $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    // Formatting and display

    /**
     * Format money for display
     *
     * Uses currency's formatting rules (symbol position, decimal separator, etc.)
     *
     * @param bool $includeSymbol Whether to include currency symbol
     * @return string Formatted money string
     *
     * @example
     * $price = Money::of(1999.99, Currency::usd());
     * echo $price->format(); // "$1,999.99"
     * echo $price->format(false); // "1,999.99"
     * @since  1.0.0
     */
    public function format(bool $includeSymbol = true): string
    {
        return $this->currency->format($this->getAmount(), $includeSymbol);
    }

    /**
     * Export as array
     *
     * The shape a published document carries: the amount is the money at its
     * currency's scale as an exact decimal string ({@see PublishedDecimal::money()}),
     * never a float; the formatted text is its own field.
     *
     * @return array{amount: string, formatted: string, currency_code: string, currency_symbol: string, minor_units: int}
     *
     * @example
     * $price = Money::of(19.99, Currency::usd());
     * $array = $price->toArray();
     * // [
     * //   'amount' => '19.99',
     * //   'formatted' => '$19.99',
     * //   'currency_code' => 'USD',
     * //   'currency_symbol' => '$',
     * //   'minor_units' => 1999
     * // ]
     * @since  1.0.0
     */
    public function toArray(): array
    {
        return [
            'amount' => PublishedDecimal::money($this),
            'formatted' => $this->format(),
            'currency_code' => $this->currency->getCode(),
            'currency_symbol' => $this->currency->getSymbol(),
            'minor_units' => $this->minorUnits,
        ];
    }

    /**
     * String representation
     *
     * Allows Money to be used in string contexts.
     *
     * @return string Formatted money with symbol
     *
     * @example
     * $price = Money::of(19.99, Currency::usd());
     * echo $price; // "$19.99"
     * @since  1.0.0
     */
    public function __toString(): string
    {
        return $this->format();
    }

    // Currency conversion

    /**
     * Convert to different currency
     *
     * Note: Exchange rate should be target/source.
     * For accurate conversions, use a proper exchange rate service.
     *
     * The rate is an exact decimal. The target's minor units are this amount
     * times the rate, at the target's scale, formed on integers and rounded
     * once.
     *
     * @param Currency $targetCurrency Target currency
     * @param string|int|float $exchangeRate Exchange rate (target per source unit)
     * @return Money Money in target currency
     * @throws InvalidArgumentException If the rate is not a finite number, or the result is more than minor
     *                                  units can hold
     *
     * @example
     * $usd = Money::of(100.00, Currency::usd());
     * $eur = $usd->convertTo(Currency::eur(), 0.85); // €85.00
     * @since  1.0.0
     */
    public function convertTo(Currency $targetCurrency, string|int|float $exchangeRate): self
    {
        $converted = self::scaleByDecimals(
            count: $this->minorUnits,
            numerator: $exchangeRate,
            denominator: 1,
            shift: $targetCurrency->getDecimalPlaces() - $this->currency->getDecimalPlaces(),
        );

        if ($converted === null) {
            throw new InvalidArgumentException('The result is larger than minor units can hold.');
        }

        return new self($converted, $targetCurrency);
    }

    // Helper methods

    /**
     * Get minimum of multiple Money objects
     *
     * @param Money ...$amounts Money objects to compare
     * @return Money Money with minimum value
     * @throws InvalidArgumentException If no amounts or different currencies
     *
     * @example
     * $min = Money::min(
     *     Money::of(10.00, Currency::usd()),
     *     Money::of(5.00, Currency::usd()),
     *     Money::of(15.00, Currency::usd())
     * ); // $5.00
     * @since  1.0.0
     */
    public static function min(Money ...$amounts): Money
    {
        if (empty($amounts)) {
            throw new InvalidArgumentException('Need at least one Money object');
        }

        $min = $amounts[0];
        foreach ($amounts as $amount) {
            if ($amount->lessThan($min)) {
                $min = $amount;
            }
        }

        return $min;
    }

    /**
     * Get maximum of multiple Money objects
     *
     * @param Money ...$amounts Money objects to compare
     * @return Money Money with maximum value
     * @throws InvalidArgumentException If no amounts or different currencies
     *
     * @example
     * $max = Money::max(
     *     Money::of(10.00, Currency::usd()),
     *     Money::of(5.00, Currency::usd()),
     *     Money::of(15.00, Currency::usd())
     * ); // $15.00
     * @since  1.0.0
     */
    public static function max(Money ...$amounts): Money
    {
        if (empty($amounts)) {
            throw new InvalidArgumentException('Need at least one Money object');
        }

        $max = $amounts[0];
        foreach ($amounts as $amount) {
            if ($amount->greaterThan($max)) {
                $max = $amount;
            }
        }

        return $max;
    }

    /**
     * Sum multiple Money objects
     *
     * @param Money ...$amounts Money objects to sum
     * @return Money Total of all amounts
     * @throws InvalidArgumentException If different currencies
     *
     * @example
     * $total = Money::sum(
     *     Money::of(10.00, Currency::usd()),
     *     Money::of(5.00, Currency::usd()),
     *     Money::of(15.00, Currency::usd())
     * ); // $30.00
     * @since  1.0.0
     */
    public static function sum(Money ...$amounts): Money
    {
        if (empty($amounts)) {
            throw new InvalidArgumentException('Need at least one Money object');
        }

        $total = $amounts[0];
        for ($i = 1; $i < count($amounts); $i++) {
            $total = $total->add($amounts[$i]);
        }

        return $total;
    }

    /**
     * Average multiple Money objects
     *
     * @param Money ...$amounts Money objects to average
     * @return Money Average of all amounts
     * @throws InvalidArgumentException If no amounts or different currencies
     *
     * @example
     * $avg = Money::avg(
     *     Money::of(10.00, Currency::usd()),
     *     Money::of(20.00, Currency::usd()),
     *     Money::of(30.00, Currency::usd())
     * ); // $20.00
     * @since  1.0.0
     */
    public static function avg(Money ...$amounts): Money
    {
        if (empty($amounts)) {
            throw new InvalidArgumentException('Need at least one Money object');
        }

        $total = self::sum(...$amounts);
        return $total->divide(count($amounts));
    }

    // Internal helper methods

    /**
     * Edge whitespace only.
     *
     * A space inside the number is either this syntax's grouping character, in
     * which case the pattern reads it, or a defect; it is never something to
     * delete on the way past.
     *
     * @param string $text The text to trim.
     * @return string The text without leading or trailing blanks, including the non-breaking ones.
     * @since  2.0.0
     */
    private static function trimBlanks(string $text): string
    {
        return (string) preg_replace('/^[\s\x{00A0}\x{202F}]+|[\s\x{00A0}\x{202F}]+$/u', '', $text);
    }

    /**
     * A digit string as minor units, or a refusal.
     *
     * (int) on an oversized numeric string saturates at PHP_INT_MAX, so an
     * amount bigger than this system can hold would come back as a different,
     * smaller amount rather than as an error.
     *
     * @param string $digits The magnitude, already stripped of leading zeros.
     * @param string $original The caller's text, for the message.
     * @param Currency $currency The currency, for the message.
     * @return int The magnitude in minor units.
     * @throws InvalidArgumentException When it does not fit.
     * @since  2.0.0
     */
    private static function minorUnitsFromDigits(string $digits, string $original, Currency $currency): int
    {
        if ($digits === '') {
            return 0;
        }

        $ceiling = (string) PHP_INT_MAX;

        if (
            \strlen($digits) > \strlen($ceiling)
            || (\strlen($digits) === \strlen($ceiling) && strcmp($digits, $ceiling) > 0)
        ) {
            throw new InvalidArgumentException(sprintf(
                "'%s' is more %s than minor units can hold.",
                $original,
                $currency->getCode(),
            ));
        }

        return (int) $digits;
    }

    /**
     * A decimal given as its digits, as minor units: rounded once at the
     * currency's scale, half away from zero, on the digits themselves.
     *
     * The one rounding of an amount that is written or carried:
     * {@see self::of()} and {@see self::parse()} both end here, so no float
     * decides a tie.
     *
     * @param bool $negative Whether the decimal is below zero.
     * @param string $integer The digits before the point.
     * @param string $fraction The digits after it; '' when none.
     * @param string $original The caller's text, for the message.
     * @param Currency $currency The currency whose scale decides.
     *
     *
     * @throws InvalidArgumentException When the amount is more than minor units can hold.
     *
     * @since  2.0.0
     */
    private static function minorUnitsOf(bool $negative, string $integer, string $fraction, string $original, Currency $currency): int
    {
        $scale = $currency->getDecimalPlaces();
        $minorUnits = self::minorUnitsFromDigits(ltrim($integer . substr(str_pad($fraction, $scale, '0'), 0, $scale), '0'), $original, $currency);

        // The first digit the scale does not hold says whether the last one
        // it holds rounds up.
        if (\strlen($fraction) > $scale && $fraction[$scale] >= '5') {
            if ($minorUnits === PHP_INT_MAX) {
                throw new InvalidArgumentException(sprintf("'%s' is more %s than minor units can hold.", $original, $currency->getCode()));
            }

            $minorUnits++;
        }

        return $negative ? -$minorUnits : $minorUnits;
    }

    /**
     * THE ONE READER OF A FLOAT: the digits of the decimal a float is read as.
     *
     * A float is printed, correctly rounded, at fifteen significant digits.
     * Every decimal of up to fifteen digits survives a float and comes back,
     * so that print is the decimal the float was given or meant: 9.95 is
     * 9.95, and the 4.974999999999999 that 9.95 x 50 / 100 leaves is 4.975.
     * What a float holds beyond fifteen digits is the remainder of its binary
     * form, or of the arithmetic that produced it.
     *
     * A stored value that must come back digit for digit is read on its
     * SHORTEST text instead: fifteen digits when they read back as the very
     * float, else sixteen, else seventeen. No precision setting is consulted
     * either way.
     *
     * @param float $value A finite float, zero or more.
     * @param bool $shortest True for the shortest text that reads back as the very float; false for fifteen
     *                       significant digits.
     *
     * @return array{0: string, 1: string} [the digits before the point, the digits after it; '' when none]
     *
     * @throws InvalidArgumentException When the value is not finite: an infinity and a not-a-number have no digits.
     *
     * @since  2.0.0
     */
    public static function floatDigits(float $value, bool $shortest): array
    {
        if (!is_finite($value)) {
            throw new InvalidArgumentException('Only a finite number has decimal digits.');
        }

        $printed = sprintf('%.14e', $value);

        if ($shortest && (float) $printed !== $value) {
            $printed = sprintf('%.15e', $value);

            if ((float) $printed !== $value) {
                $printed = sprintf('%.16e', $value);
            }
        }

        [$mantissa, $exponent] = explode('e', $printed);
        $figures = str_replace('.', '', $mantissa);
        $point = 1 + (int) $exponent;

        if ($point <= 0) {
            return ['0', str_repeat('0', -$point) . $figures];
        }

        if ($point >= \strlen($figures)) {
            return [$figures . str_repeat('0', $point - \strlen($figures)), ''];
        }

        return [substr($figures, 0, $point), substr($figures, $point)];
    }

    /**
     * A factor, a percentage, a divisor or a ratio as the decimal it states.
     *
     * An integer and a plain decimal text are their own digits; other
     * numeric text is read the way the float it names is; a float is read at
     * fifteen significant digits ({@see self::floatDigits()}).
     *
     * @param string|int|float $value The operand.
     *
     * @return array{0: bool, 1: string, 2: string} [below zero, the digits before the point, the digits after it]
     *
     * @throws InvalidArgumentException When the operand is not a finite number.
     *
     * @since  2.0.0
     */
    private static function decimal(string|int|float $value): array
    {
        if (\is_int($value)) {
            return [$value < 0, ltrim((string) $value, '-'), ''];
        }

        if (\is_string($value)) {
            if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m) === 1) {
                return [$m[1] === '-', $m[2], $m[3] ?? ''];
            }

            if (!is_numeric($value)) {
                throw new InvalidArgumentException(sprintf("Cannot read '%s' as a number.", $value));
            }

            $value = (float) $value;
        }

        if (!is_finite($value)) {
            throw new InvalidArgumentException('A factor of money must be a finite number.');
        }

        [$integer, $fraction] = self::floatDigits(value: abs($value), shortest: false);

        return [$value < 0, $integer, $fraction];
    }

    /**
     * A count times the ratio of two decimals, rounded once: the operands as
     * whole numbers at the finer one's places, then {@see self::scaleCount()}
     * when both fit an integer and their digits when they do not.
     *
     * @param int $count The count.
     * @param string|int|float $numerator What it is multiplied by.
     * @param string|int|float $denominator What it is divided by.
     * @param int $shift A power of ten the ratio is multiplied by (the scales of a conversion).
     *
     * @return int|null The scaled count; null when it is more than an integer holds.
     *
     * @throws InvalidArgumentException When the denominator is zero, or an operand is not a finite number.
     *
     * @since  2.0.0
     */
    private static function scaleByDecimals(int $count, string|int|float $numerator, string|int|float $denominator, int $shift): ?int
    {
        [$above, $aboveInteger, $aboveFraction] = self::decimal($numerator);
        [$below, $belowInteger, $belowFraction] = self::decimal($denominator);

        $aboveFraction = rtrim($aboveFraction, '0');
        $belowFraction = rtrim($belowFraction, '0');

        // n / 10^a over d / 10^b, times 10^shift, is n x 10^(b - a + shift) over d.
        $tens = \strlen($belowFraction) - \strlen($aboveFraction) + $shift;
        $whole = ltrim($aboveInteger . $aboveFraction, '0');
        $parts = ltrim($belowInteger . $belowFraction, '0');

        if ($parts === '') {
            throw new InvalidArgumentException('Cannot divide money by zero');
        }

        if ($whole === '' || $count === 0) {
            return 0;
        }

        $whole .= str_repeat('0', max(0, $tens));
        $parts .= str_repeat('0', max(0, -$tens));

        // Eighteen digits always fit an integer.
        if (\strlen($whole) <= 18 && \strlen($parts) <= 18) {
            return self::scaleCount(count: $count, numerator: $above !== $below ? -(int) $whole : (int) $whole, denominator: (int) $parts);
        }

        return self::scaleOnDigits(negative: ($count < 0) !== ($above !== $below), count: ltrim((string) $count, '-'), numerator: $whole, denominator: $parts);
    }

    /**
     * A whole count times a sum of exact ratios over one divisor, rounded
     * once, half away from zero: count x (a1 x b1 / d1 + ...) / divisor.
     *
     * ON INTEGERS WHILE THEY HOLD IT. Each term times the count is split into
     * a whole part and a fraction below one, so the product is a whole number
     * plus fractions that sum to less than their number. Rounded, that is
     * the floor of (twice the whole + the divisor + twice the fractions) over
     * twice the divisor, and the fractions can carry that floor up only when
     * twice their number reaches what the rest lacks to the next multiple.
     * When it does not, the floor is the answer and no fraction is ever
     * added; a single fraction is decided by one comparison.
     *
     * Otherwise (several fractions that may carry, a product no integer
     * holds, an operand below zero) the sum is formed on decimal digits
     * ({@see self::sumOnDigits()}). Both paths give the same answer.
     *
     * @param int $count The count.
     * @param array $terms The terms, each [a, b, d] read a x b / d, with d above zero.
     * @param int $divisor The divisor, above zero.
     *
     * @return int|null The scaled count; null when it is more than an integer holds.
     *
     * @since  2.0.0
     */
    private static function scaleBySum(int $count, array $terms, int $divisor): ?int
    {
        // The smallest integer has no magnitude an integer holds.
        $size = abs($count);
        $fits = \is_int($size);

        if ($fits) {
            $whole = 0;
            $fractions = 0;
            $over = 0;
            $under = 1;

            foreach ($terms as [$a, $b, $d]) {
                // An integer product or sum that overflows is answered with a float.
                $product = $a * $b;
                $times = \is_int($product) ? $size * intdiv($product, $d) : null;
                $rest = \is_int($product) ? $size * ($product % $d) : null;

                if ($a < 0 || $b < 0 || !\is_int($times) || !\is_int($rest)) {
                    $fits = false;

                    break;
                }

                $whole += $times + intdiv($rest, $d);

                if ($rest % $d !== 0) {
                    $fractions++;
                    $over = $rest % $d;
                    $under = $d;
                }
            }

            $twice = 2 * $whole + $divisor;
            $span = 2 * $divisor;

            if ($fits && \is_int($twice) && \is_int($span)) {
                $rounded = intdiv($twice, $span);
                $lacking = $span - $twice % $span;

                if (2 * $fractions <= $lacking) {
                    return $count < 0 ? -$rounded : $rounded;
                }

                // One fraction, and half a unit lacking: it carries when it is at least a half.
                if ($fractions === 1) {
                    $rounded += $over >= $under - $over ? 1 : 0;

                    return $count < 0 ? -$rounded : $rounded;
                }
            }
        }

        return self::sumOnDigits(count: $count, terms: $terms, divisor: $divisor);
    }

    /**
     * count x (a1 x b1 / d1 + a2 x b2 / d2 + ...) / divisor on decimal digits,
     * rounded once, half away from zero. The terms are added one by one over
     * the product of their denominators, each with its sign, and the one
     * division is {@see self::scaleOnDigits()}: nothing is approximated.
     *
     * @param int $count The count.
     * @param array $terms The terms, each [a, b, d] read a x b / d, with d above zero.
     * @param int $divisor The divisor, above zero.
     *
     * @return int|null The scaled count; null when it is more than an integer holds.
     *
     * @since  2.0.0
     */
    private static function sumOnDigits(int $count, array $terms, int $divisor): ?int
    {
        // The sum so far: its sign, and its numerator over its denominator.
        $negative = false;
        $above = '';
        $below = '1';

        foreach ($terms as [$a, $b, $d]) {
            // A ratio of one leaves the whole number it multiplies.
            if ($b === $d) {
                [$b, $d] = [1, 1];
            }

            $minus = ($a < 0) !== ($b < 0);
            $term = self::digitsProduct(self::digitsProduct(ltrim((string) $a, '-'), ltrim((string) $b, '-')), $below);

            if ($d !== 1) {
                $above = self::digitsProduct($above, (string) $d);
                $below = self::digitsProduct($below, (string) $d);
            }

            if ($minus === $negative) {
                $above = self::digitsPlus($above, $term);
            } elseif (self::digitsCompare($above, $term) >= 0) {
                $above = self::digitsMinus($above, $term);
            } else {
                $above = self::digitsMinus($term, $above);
                $negative = $minus;
            }
        }

        return self::scaleOnDigits(
            negative: ($count < 0) !== $negative,
            count: ltrim((string) $count, '-'),
            numerator: $above,
            denominator: self::digitsProduct($below, (string) $divisor),
        );
    }

    /**
     * count x numerator / denominator on decimal digits, rounded once, half
     * away from zero: the scaling of operands no integer holds. The product
     * is formed digit by digit and divided by long division, each quotient
     * digit found by subtraction, so nothing is approximated.
     *
     * @param bool $negative Whether the result is below zero.
     * @param string $count The digits of the count's magnitude.
     * @param string $numerator The digits of the numerator's magnitude.
     * @param string $denominator The digits of the denominator's magnitude, not zero.
     *
     * @return int|null The scaled count; null when it is more than an integer holds.
     *
     * @since  2.0.0
     */
    private static function scaleOnDigits(bool $negative, string $count, string $numerator, string $denominator): ?int
    {
        $denominator = ltrim($denominator, '0');
        $quotient = '';
        $remainder = '';

        foreach (str_split(self::digitsProduct($count, $numerator) ?: '0') as $digit) {
            $remainder = ltrim($remainder . $digit, '0');
            $fits = 0;

            while (self::digitsCompare($remainder, $denominator) >= 0) {
                $remainder = self::digitsMinus($remainder, $denominator);
                $fits++;
            }

            $quotient .= $fits;
        }

        $quotient = ltrim($quotient, '0');
        $ceiling = (string) PHP_INT_MAX;

        // One past the largest integer is still the smallest one, below zero.
        if (\strlen($quotient) > \strlen($ceiling) || (\strlen($quotient) === \strlen($ceiling) && strcmp($quotient, $ceiling) > 0)) {
            return $negative && $quotient === ltrim((string) PHP_INT_MIN, '-') && self::digitsCompare(self::digitsProduct($remainder, '2'), $denominator) < 0
                ? PHP_INT_MIN
                : null;
        }

        $scaled = (int) $quotient;

        // Half away from zero: up when twice the remainder is at least the denominator.
        if (self::digitsCompare(self::digitsProduct($remainder, '2'), $denominator) >= 0) {
            if ($scaled === PHP_INT_MAX) {
                return $negative ? PHP_INT_MIN : null;
            }

            $scaled++;
        }

        return $negative ? -$scaled : $scaled;
    }

    /**
     * The product of two whole numbers written in decimal digits.
     *
     * @param string $a The digits of one.
     * @param string $b The digits of the other.
     *
     * @return string The digits of the product, with no leading zeros; '' is zero.
     *
     * @since  2.0.0
     */
    private static function digitsProduct(string $a, string $b): string
    {
        $a = strrev($a);
        $b = strrev($b);
        $cells = array_fill(0, \strlen($a) + \strlen($b), 0);

        for ($i = 0, $n = \strlen($a); $i < $n; $i++) {
            for ($j = 0, $m = \strlen($b); $j < $m; $j++) {
                $cells[$i + $j] += (int) $a[$i] * (int) $b[$j];
            }
        }

        $digits = '';
        $carry = 0;

        foreach ($cells as $cell) {
            $cell += $carry;
            $digits .= $cell % 10;
            $carry = intdiv($cell, 10);
        }

        return ltrim(strrev($digits), '0');
    }

    /**
     * Two whole numbers written in decimal digits with no leading zeros,
     * compared.
     *
     * @param string $a The digits of one; '' is zero.
     * @param string $b The digits of the other.
     *
     * @return int Below zero, zero or above it, as the first is smaller, equal or larger.
     *
     * @since  2.0.0
     */
    private static function digitsCompare(string $a, string $b): int
    {
        return \strlen($a) <=> \strlen($b) ?: strcmp($a, $b);
    }

    /**
     * The sum of two whole numbers written in decimal digits.
     *
     * @param string $a The digits of one; '' is zero.
     * @param string $b The digits of the other; '' is zero.
     *
     * @return string The digits of the sum, with no leading zeros; '' is zero.
     *
     * @since  2.0.0
     */
    private static function digitsPlus(string $a, string $b): string
    {
        $length = max(\strlen($a), \strlen($b));
        $a = str_pad($a, $length, '0', STR_PAD_LEFT);
        $b = str_pad($b, $length, '0', STR_PAD_LEFT);
        $carry = 0;

        for ($at = $length - 1; $at >= 0; $at--) {
            $digit = (int) $a[$at] + (int) $b[$at] + $carry;
            $carry = intdiv($digit, 10);
            $a[$at] = (string) ($digit % 10);
        }

        return ltrim(($carry === 1 ? '1' : '') . $a, '0');
    }

    /**
     * The difference of two whole numbers written in decimal digits, the
     * first not smaller than the second.
     *
     * @param string $a The digits of the larger.
     * @param string $b The digits of the smaller.
     *
     * @return string The digits of the difference, with no leading zeros; '' is zero.
     *
     * @since  2.0.0
     */
    private static function digitsMinus(string $a, string $b): string
    {
        $b = str_pad($b, \strlen($a), '0', STR_PAD_LEFT);
        $borrow = 0;

        for ($at = \strlen($a) - 1; $at >= 0; $at--) {
            $digit = (int) $a[$at] - (int) $b[$at] - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $a[$at] = (string) ($digit + 10 * $borrow);
        }

        return ltrim($a, '0');
    }

    /**
     * Ratios as whole weights: each ratio read as the decimal it states, all
     * brought to the finest one's places, so their proportions are exact.
     *
     * @param array<int, int|float|string> $ratios The ratios, as a list.
     *
     * @return int[] One weight per ratio, in the same order.
     *
     * @throws InvalidArgumentException When a ratio is not a finite number, or is larger than a weight can be.
     *
     * @since  2.0.0
     */
    private static function wholeWeights(array $ratios): array
    {
        // Whole ratios are their own weights.
        if (array_filter($ratios, '\is_int') === $ratios) {
            return $ratios;
        }

        $decimals = [];
        $places = 0;

        foreach ($ratios as $ratio) {
            [$negative, $integer, $fraction] = self::decimal($ratio);
            $fraction = rtrim($fraction, '0');
            $places = max($places, \strlen($fraction));
            $decimals[] = [$negative, $integer, $fraction];
        }

        $weights = [];

        foreach ($decimals as [$negative, $integer, $fraction]) {
            $digits = ltrim($integer . str_pad($fraction, $places, '0'), '0');

            // Eighteen digits always fit an integer.
            if (\strlen($digits) > 18) {
                throw new InvalidArgumentException('The ratios are larger than a split can hold.');
            }

            $weights[] = $negative ? -(int) $digits : (int) $digits;
        }

        return $weights;
    }

    /**
     * Convert minor units to major units
     *
     * Examples:
     * - 1999 cents -> $19.99 (USD, 2 decimals)
     * - 50,000,000 satoshis -> 0.5 BTC (BTC, 8 decimals)
     * - 100 -> ¥100 (JPY, 0 decimals)
     *
     * @param int $minorUnits Amount in minor units
     * @param Currency $currency Currency object
     * @return float Amount in major units
     * @since  1.0.0
     */
    private static function convertToMajorUnits(int $minorUnits, Currency $currency): float
    {
        $decimalPlaces = $currency->getDecimalPlaces();
        $divisor = pow(10, $decimalPlaces);

        return $minorUnits / $divisor;
    }

    /**
     * Assert that both Money objects have the same currency
     *
     * Prevents invalid operations like adding USD to EUR.
     *
     * @param Money $other Money to compare currency with
     * @throws InvalidArgumentException If currencies don't match
     * @since  1.0.0
     */
    private function assertSameCurrency(Money $other): void
    {
        if (!$this->currency->equals($other->currency)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Cannot operate on different currencies: %s and %s. Convert to same currency first.',
                    $this->currency->getCode(),
                    $other->currency->getCode(),
                ),
            );
        }
    }
}
