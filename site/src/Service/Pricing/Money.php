<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

use InvalidArgumentException;

defined('_JEXEC') or die;

/**
 * Money Value Object - Production Ready
 *
 * Immutable money representation following Martin Fowler's Money Pattern.
 * Stores amounts as integers (minor units) to avoid floating-point errors.
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
 * @author   Agamemnon Fakas
 * @version  2.0.0 - Integer-based implementation
 * @link     https://easylogic.gr
 */
class Money
{
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
     */
    private function __construct(int $minorUnits, Currency $currency)
    {
        $this->minorUnits = $minorUnits;
        $this->currency = $currency;
    }

    // ========================================================================
    // FACTORY METHODS
    // ========================================================================

    /**
     * Create Money from major units (dollars, euros, etc.)
     *
     * This is the primary way to create Money objects.
     * Converts the amount to minor units internally.
     *
     * @param float $amount Amount in major units (e.g., 19.99 for $19.99)
     * @param Currency $currency Currency object
     * @return self Immutable Money object
     *
     * @example
     * $price = Money::of(19.99, Currency::usd()); // $19.99
     * $btc = Money::of(0.5, Currency::btc()); // 0.5 BTC
     */
    public static function of(float $amount, Currency $currency): self
    {
        $minorUnits = self::convertToMinorUnits($amount, $currency);
        return new self($minorUnits, $currency);
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
     */
    public static function ofMinor(int $minorUnits, Currency $currency): self
    {
        return new self($minorUnits, $currency);
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
     */
    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Universal Money Parser - Production Grade
     *
     * Handles ANY format, ANY currency, with PERFECT precision.
     *
     * Features:
     * - Auto-detects format (US: "1,234.56" or EU: "1.234,56")
     * - Handles all currencies (USD, EUR, JPY, BTC, etc.)
     * - Perfect precision (uses integer math, no float errors)
     * - Supports negative formats: "-$50", "($50)"
     * - Currency-aware decimal handling
     * - Validates and sanitizes input
     *
     * @param string $amount Money string in any format
     * @param Currency $currency Target currency (provides decimal places + format rules)
     * @return self Money object
     * @throws InvalidArgumentException If string is invalid
     *
     * @example
     * $usd = Currency::loadByCode('USD');
     * Money::parse("$1,234.56", $usd);     // $1,234.56
     * Money::parse("1.234,56", $usd);      // $1,234.56 (auto-detects)
     * Money::parse("1234.56", $usd);       // $1,234.56
     *
     * $btc = Currency::loadByCode('BTC');
     * Money::parse("₿0.12345678", $btc);   // 0.12345678 BTC (8 decimals, perfect precision)
     * Money::parse("0.1", $btc);           // 0.10000000 BTC
     *
     * $jpy = Currency::loadByCode('JPY');
     * Money::parse("¥1,234", $jpy);        // ¥1,234 (no decimals)
     */
    public static function parse(string $amount, Currency $currency): self
    {
        if (empty($amount)) {
            throw new InvalidArgumentException('Cannot parse empty string as money');
        }

        $original = $amount;

        // ========================================================================
        // STEP 1: Handle negative numbers (multiple formats)
        // ========================================================================
        $isNegative = false;

        // Check for minus sign
        if (str_contains($amount, '-')) {
            $isNegative = true;
            $amount = str_replace('-', '', $amount);
        }

        // Check for accounting format: ($100.00)
        $amount = trim($amount);
        if (str_starts_with($amount, '(') && str_ends_with($amount, ')')) {
            $isNegative = true;
            $amount = trim($amount, '()');
        }

        // ========================================================================
        // STEP 2: Remove currency symbol and spaces
        // ========================================================================

        // Remove the currency's specific symbol
        $amount = str_replace($currency->getSymbol(), '', $amount);

        // Remove common currency symbols that might not match exactly
        $amount = preg_replace('/[$€£¥₹₽₿฿₩₪₴₦₵]/u', '', $amount);

        // Remove all spaces
        $amount = preg_replace('/\s+/', '', $amount);
        $amount = trim($amount);

        if (empty($amount)) {
            throw new InvalidArgumentException(
                "Cannot parse '{$original}' as money - no numeric content found",
            );
        }

        // ========================================================================
        // STEP 3: Smart Format Detection
        // ========================================================================

        // Find positions of separators
        $lastDot = strrpos($amount, '.');
        $lastComma = strrpos($amount, ',');
        $dotCount = substr_count($amount, '.');
        $commaCount = substr_count($amount, ',');

        $cleaned = '';

        // Strategy: Determine which separator is decimal vs thousand
        // The decimal separator is typically the LAST one, and appears once

        if ($lastDot === false && $lastComma === false) {
            // =====================================================================
            // Case 1: No separators - just digits (e.g., "1234" or "1234567")
            // =====================================================================
            $cleaned = $amount;
        } elseif ($lastDot !== false && $lastComma === false) {
            // =====================================================================
            // Case 2: Only dots present
            // =====================================================================

            if ($dotCount === 1) {
                // Single dot: Could be decimal (e.g., "1234.56") or thousand (e.g., "1.234")
                // Heuristic: Check digits after dot against currency's decimal places
                $afterDot = strlen(substr($amount, $lastDot + 1));

                // Be generous with max decimals to handle high-precision currencies
                $maxDecimals = max(3, $currency->getDecimalPlaces());

                if ($currency->getDecimalPlaces() > 0 && $afterDot > 0 && $afterDot <= $maxDecimals) {
                    // Likely decimal separator: "1234.56" or "0.12345678" (BTC)
                    $cleaned = $amount;
                } else {
                    // Likely thousand separator: "1.234" (EU format, unusual alone)
                    $cleaned = str_replace('.', '', $amount);
                }
            } else {
                // Multiple dots: Must be thousand separators (e.g., "1.234.567")
                $cleaned = str_replace('.', '', $amount);
            }
        } elseif ($lastComma !== false && $lastDot === false) {
            // =====================================================================
            // Case 3: Only commas present
            // =====================================================================

            if ($commaCount === 1) {
                // Single comma: Could be decimal (e.g., "1234,56") or thousand (e.g., "1,234")
                $afterComma = strlen(substr($amount, $lastComma + 1));

                // Be generous with max decimals
                $maxDecimals = max(3, $currency->getDecimalPlaces());

                if ($currency->getDecimalPlaces() > 0 && $afterComma > 0 && $afterComma <= $maxDecimals) {
                    // Likely decimal separator: "1234,56" (EU format)
                    $cleaned = str_replace(',', '.', $amount);
                } else {
                    // Likely thousand separator: "1,234"
                    $cleaned = str_replace(',', '', $amount);
                }
            } else {
                // Multiple commas: Must be thousand separators (e.g., "1,234,567")
                $cleaned = str_replace(',', '', $amount);
            }
        } else {
            // =====================================================================
            // Case 4: Both dots AND commas present
            // =====================================================================
            // Rule: The LAST separator is the decimal, the other is thousand

            if ($lastDot > $lastComma) {
                // Format: "1,234.56" (US/UK format)
                $cleaned = str_replace(',', '', $amount);
                // Dot is already the decimal separator
            } else {
                // Format: "1.234,56" (EU format)
                $cleaned = str_replace('.', '', $amount);
                $cleaned = str_replace(',', '.', $cleaned);
            }
        }

        // ========================================================================
        // STEP 4: Fallback to Currency rules if still ambiguous
        // ========================================================================

        // If we haven't cleaned yet (shouldn't happen, but safety net)
        if (empty($cleaned)) {
            $currencyDecimal = $currency->getDecimalSeparator();
            $currencyThousand = $currency->getThousandSeparator();

            $cleaned = $amount;

            if (!empty($currencyThousand)) {
                $cleaned = str_replace($currencyThousand, '', $cleaned);
            }

            if (!empty($currencyDecimal) && $currencyDecimal !== '.') {
                $cleaned = str_replace($currencyDecimal, '.', $cleaned);
            }
        }

        // ========================================================================
        // STEP 5: Final cleanup and validation
        // ========================================================================

        // Remove any remaining non-numeric characters except dot
        $cleaned = preg_replace('/[^\d.]/', '', $cleaned);

        if (empty($cleaned)) {
            throw new InvalidArgumentException(
                "Cannot parse '{$original}' as money - no valid numeric content",
            );
        }

        // Validate: Should have at most one decimal point
        if (substr_count($cleaned, '.') > 1) {
            throw new InvalidArgumentException(
                "Cannot parse '{$original}' as money - multiple decimal points detected",
            );
        }

        // ========================================================================
        // STEP 6: Handle currencies with 0 decimal places (like JPY)
        // ========================================================================

        if ($currency->getDecimalPlaces() === 0) {
            // For zero-decimal currencies, remove any decimal portion
            if (str_contains($cleaned, '.')) {
                // Round to nearest integer
                $cleaned = (string) round((float) $cleaned);
            }
        }

        // ========================================================================
        // STEP 7: Convert to minor units using BCMath (PERFECT PRECISION)
        // ========================================================================

        // This is the key improvement: We never use float arithmetic
        // We convert directly from string to integer minor units

        if (!extension_loaded('bcmath')) {
            // Fallback if bcmath not available (but warn about it)
            trigger_error(
                'BCMath extension not available - using float conversion (may lose precision)',
                E_USER_WARNING,
            );

            $floatAmount = (float) $cleaned;
            if ($isNegative) {
                $floatAmount = -$floatAmount;
            }
            return self::of($floatAmount, $currency);
        }

        // Use BCMath for perfect precision
        $decimalPlaces = $currency->getDecimalPlaces();

        // Multiply by 10^decimalPlaces to get minor units
        // bcmul handles arbitrary precision, bcpow creates the multiplier
        $multiplier = bcpow('10', (string) $decimalPlaces, 0);

        // Multiply and round to integer
        $minorUnits = bcmul($cleaned, $multiplier, 0);

        // Convert to integer
        $minorUnitsInt = (int) $minorUnits;

        // Apply negative sign
        if ($isNegative) {
            $minorUnitsInt = -$minorUnitsInt;
        }

        // ========================================================================
        // STEP 8: Create Money object directly from minor units
        // ========================================================================

        return self::ofMinor($minorUnitsInt, $currency);
    }

    // ========================================================================
    // GETTERS
    // ========================================================================

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
     */
    public function getMinorUnits(): int
    {
        return $this->minorUnits;
    }

    /**
     * Get currency object
     *
     * @return Currency Currency information
     *
     * @example
     * $money = Money::of(19.99, Currency::usd());
     * echo $money->getCurrency()->getCode(); // "USD"
     */
    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    // ========================================================================
    // ARITHMETIC OPERATIONS
    // All operations return NEW Money objects (immutability)
    // ========================================================================

    /**
     * Add another Money amount
     *
     * Both Money objects must have the same currency.
     * Returns a new Money object (immutable).
     *
     * @param Money $other Money to add
     * @return self New Money object with sum
     * @throws InvalidArgumentException If currencies don't match
     *
     * @example
     * $price = Money::of(10.00, Currency::usd());
     * $tax = Money::of(2.00, Currency::usd());
     * $total = $price->add($tax); // $12.00
     */
    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            $this->minorUnits + $other->minorUnits,
            $this->currency,
        );
    }

    /**
     * Subtract another Money amount
     *
     * Both Money objects must have the same currency.
     * Can result in negative money.
     *
     * @param Money $other Money to subtract
     * @return self New Money object with difference
     * @throws InvalidArgumentException If currencies don't match
     *
     * @example
     * $price = Money::of(100.00, Currency::usd());
     * $discount = Money::of(10.00, Currency::usd());
     * $final = $price->subtract($discount); // $90.00
     */
    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            $this->minorUnits - $other->minorUnits,
            $this->currency,
        );
    }

    /**
     * Multiply by a numeric factor
     *
     * Useful for calculating quantities or applying rates.
     * Uses rounding to maintain integer precision.
     *
     * @param float $multiplier Factor to multiply by
     * @return self New Money object with result
     *
     * @example
     * $unitPrice = Money::of(19.99, Currency::usd());
     * $total = $unitPrice->multiply(5); // $99.95
     */
    public function multiply(float $multiplier): self
    {
        $newAmount = (int) round($this->minorUnits * $multiplier);

        return new self($newAmount, $this->currency);
    }

    /**
     * Divide by a numeric divisor
     *
     * Uses rounding to maintain integer precision.
     * Use allocate() if you need to split money without losing cents.
     *
     * @param float $divisor Number to divide by
     * @return self New Money object with result
     * @throws InvalidArgumentException If divisor is zero
     *
     * @example
     * $total = Money::of(100.00, Currency::usd());
     * $perMonth = $total->divide(12); // $8.33 (rounded)
     */
    public function divide(float $divisor): self
    {
        if ($divisor == 0) {
            throw new InvalidArgumentException('Cannot divide money by zero');
        }

        $newAmount = (int) round($this->minorUnits / $divisor);

        return new self($newAmount, $this->currency);
    }

    /**
     * Calculate percentage of this amount
     *
     * Commonly used for taxes, discounts, tips, etc.
     *
     * @param float $percent Percentage (e.g., 10 for 10%)
     * @return self New Money object with percentage amount
     *
     * @example
     * $price = Money::of(100.00, Currency::usd());
     * $tax = $price->percentage(10); // $10.00 (10% of $100)
     * $discount = $price->percentage(15); // $15.00 (15% of $100)
     */
    public function percentage(float $percent): self
    {
        $newAmount = (int) round($this->minorUnits * ($percent / 100));

        return new self($newAmount, $this->currency);
    }

    /**
     * Get absolute value
     *
     * Converts negative money to positive.
     *
     * @return self New Money object with absolute value
     *
     * @example
     * $debt = Money::of(-50.00, Currency::usd());
     * $amount = $debt->abs(); // $50.00
     */
    public function abs(): self
    {
        return new self(abs($this->minorUnits), $this->currency);
    }

    /**
     * Negate the amount
     *
     * Converts positive to negative and vice versa.
     *
     * @return self New Money object with negated amount
     *
     * @example
     * $credit = Money::of(50.00, Currency::usd());
     * $debit = $credit->negate(); // -$50.00
     */
    public function negate(): self
    {
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
     */
    public function round(int $mode = PHP_ROUND_HALF_UP): self
    {
        // With integer storage, we're already at the smallest unit
        // No rounding needed!
        return $this;
    }

    // ========================================================================
    // ALLOCATION (Fowler's Algorithm - Prevents Losing Cents)
    // ========================================================================

    /**
     * Allocate money equally across N targets
     *
     * Uses Martin Fowler's allocation algorithm to distribute money
     * without losing cents due to rounding.
     *
     * The remainder is distributed one cent at a time to the first targets.
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
     */
    public function allocate(int $n): array
    {
        if ($n < 1) {
            throw new InvalidArgumentException('Cannot allocate to less than 1 target');
        }

        // Calculate base amount for each target
        $low = (int) floor($this->minorUnits / $n);
        $high = $low + 1;

        // Calculate remainder (cents that need distribution)
        $remainder = $this->minorUnits % $n;

        $result = [];

        // First $remainder targets get $high, rest get $low
        // This ensures: ($high * $remainder) + ($low * ($n - $remainder)) = $this->minorUnits
        for ($i = 0; $i < $n; $i++) {
            $result[] = new self(
                $i < $remainder ? $high : $low,
                $this->currency,
            );
        }

        return $result;
    }

    /**
     * Allocate money by ratios
     *
     * Distributes money according to specified ratios without losing cents.
     * The last portion gets the remainder to ensure exact sum.
     *
     * @param array<int|float> $ratios Array of ratios (e.g., [1, 2, 3] or [30, 70])
     * @return array<Money> Array of Money objects
     * @throws InvalidArgumentException If ratios are invalid
     *
     * @example
     * $profit = Money::of(100.00, Currency::usd());
     * $split = $profit->allocateByRatios([30, 70]);
     * // Result: [$30.00, $70.00]
     *
     * $profit = Money::of(100.00, Currency::usd());
     * $split = $profit->allocateByRatios([1, 1, 1]);
     * // Result: [$33.33, $33.33, $33.34] = $100.00
     */
    public function allocateByRatios(array $ratios): array
    {
        $total = array_sum($ratios);

        if ($total <= 0) {
            throw new InvalidArgumentException('Sum of ratios must be positive');
        }

        $result = [];
        $allocated = 0;

        foreach ($ratios as $i => $ratio) {
            if ($i === count($ratios) - 1) {
                // Last item gets remainder to prevent losing cents
                $result[] = new self(
                    $this->minorUnits - $allocated,
                    $this->currency,
                );
            } else {
                // Calculate share for this ratio
                $share = (int) round(($this->minorUnits * $ratio) / $total);
                $allocated += $share;
                $result[] = new self($share, $this->currency);
            }
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

    // ========================================================================
    // COMPARISON METHODS
    // ========================================================================

    /**
     * Check if amount is zero
     *
     * @return bool True if zero
     *
     * @example
     * $money = Money::of(0, Currency::usd());
     * $money->isZero(); // true
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
     */
    public function lessThanOrEqual(Money $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    // ========================================================================
    // FORMATTING AND DISPLAY
    // ========================================================================

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
     */
    public function format(bool $includeSymbol = true): string
    {
        return $this->currency->format($this->getAmount(), $includeSymbol);
    }

    /**
     * Export as array
     *
     * Useful for JSON APIs, serialization, etc.
     *
     * @return array{amount: float, formatted: string, currency_code: string, currency_symbol: string, minor_units: int}
     *
     * @example
     * $price = Money::of(19.99, Currency::usd());
     * $array = $price->toArray();
     * // [
     * //   'amount' => 19.99,
     * //   'formatted' => '$19.99',
     * //   'currency_code' => 'USD',
     * //   'currency_symbol' => '$',
     * //   'minor_units' => 1999
     * // ]
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->getAmount(),
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
     */
    public function __toString(): string
    {
        return $this->format();
    }

    // ========================================================================
    // CURRENCY CONVERSION
    // ========================================================================

    /**
     * Convert to different currency
     *
     * Note: Exchange rate should be target/source.
     * For accurate conversions, use a proper exchange rate service.
     *
     * @param Currency $targetCurrency Target currency
     * @param float $exchangeRate Exchange rate (target per source unit)
     * @return Money Money in target currency
     *
     * @example
     * $usd = Money::of(100.00, Currency::usd());
     * $eur = $usd->convertTo(Currency::eur(), 0.85); // €85.00
     */
    public function convertTo(Currency $targetCurrency, float $exchangeRate): self
    {
        // Convert to major units, apply rate, convert back to minor units
        $majorAmount = $this->getAmount();
        $convertedAmount = $majorAmount * $exchangeRate;

        return self::of($convertedAmount, $targetCurrency);
    }

    // ========================================================================
    // HELPER METHODS
    // ========================================================================

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
     */
    public static function avg(Money ...$amounts): Money
    {
        if (empty($amounts)) {
            throw new InvalidArgumentException('Need at least one Money object');
        }

        $total = self::sum(...$amounts);
        return $total->divide(count($amounts));
    }

    // ========================================================================
    // INTERNAL HELPER METHODS
    // ========================================================================

    /**
     * Convert major units to minor units
     *
     * Examples:
     * - $19.99 → 1999 cents (USD, 2 decimals)
     * - 0.5 BTC → 50,000,000 satoshis (BTC, 8 decimals)
     * - ¥100 → 100 (JPY, 0 decimals)
     *
     * @param float $amount Amount in major units
     * @param Currency $currency Currency object
     * @return int Amount in minor units
     */
    private static function convertToMinorUnits(float $amount, Currency $currency): int
    {
        $decimalPlaces = $currency->getDecimalPlaces();
        $multiplier = pow(10, $decimalPlaces);

        return (int) round($amount * $multiplier);
    }

    /**
     * Convert minor units to major units
     *
     * Examples:
     * - 1999 cents → $19.99 (USD, 2 decimals)
     * - 50,000,000 satoshis → 0.5 BTC (BTC, 8 decimals)
     * - 100 → ¥100 (JPY, 0 decimals)
     *
     * @param int $minorUnits Amount in minor units
     * @param Currency $currency Currency object
     * @return float Amount in major units
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
