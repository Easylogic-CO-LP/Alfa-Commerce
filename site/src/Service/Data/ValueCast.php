<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Data;

defined('_JEXEC') or die;

/**
 * The one checked conversion, for every stored value this component hands out.
 *
 * TWO READERS, ONE SET OF RULES. A plugin payload
 * ({@see PluginPayload::getInt()}) and a checkout address
 * ({@see \Alfa\Component\Alfa\Site\Service\Cart\AddressView::getInt()}) hold
 * different things in different columns, but "is this a whole number" has one
 * right answer and it is written here once. Two implementations would
 * eventually disagree, and the disagreement would be found in production, on a
 * quantity or an instalment count.
 *
 * CHECKED MEANS IT REFUSES. PHP's own casts are the problem being solved:
 *
 *     (int) '6.5'      6      a quantity silently reduced
 *     (int) 'six'      0      reads downstream as a legitimate zero
 *     (bool) 'false'   true   the string every JSON-ish source produces
 *     (bool) '0'       false  but (bool) '0.0' is true
 *
 * None of those are conversions; they are guesses. Every one of them here
 * either returns the value asked for or throws {@see ValueException}.
 *
 * MISSING IS NOT EMPTY. A default answers a value that is absent or null:
 * nothing was stored. It does NOT answer '', false or 0: those are values
 * somebody stored on purpose, and handing back a default instead would erase a
 * deliberately cleared field. It does not answer a malformed value either; that
 * is a fault, and a default would hide it forever.
 *
 * @since  1.10.0
 */
final class ValueCast
{
    /**
     * The filters {@see PluginPayload::get()} and AddressView::get() accept.
     *
     * @since  1.10.0
     */
    public const FILTERS = ['int', 'string', 'bool', 'array'];

    /**
     * Apply a named filter, or none.
     *
     * @param mixed $value The stored value; null when absent.
     * @param string|null $filter One of self::FILTERS, or null for the value as stored.
     * @param mixed $default Returned when the value is absent or null.
     * @param string $where What is being read, for the error message.
     *
     *
     * @throws ValueException On an unknown filter, or a value that will not convert.
     *
     * @since  1.10.0
     */
    public static function apply(mixed $value, ?string $filter, mixed $default, string $where): mixed
    {
        if ($filter === null) {
            return $value ?? $default;
        }

        return match ($filter) {
            'int' => self::toInt($value, $default, $where),
            'string' => self::toString($value, $default, $where),
            'bool' => self::toBool($value, $default, $where),
            'array' => self::toArray($value, $default, $where),
            default => throw new ValueException(sprintf(
                '%s: "%s" is not a filter; use one of %s, or none for the value as stored.',
                $where,
                $filter,
                implode(', ', self::FILTERS),
            )),
        };
    }

    /**
     * A string, with its bytes intact.
     *
     * LEADING ZEROS ARE THE POINT. A parcel id is '0077123456' and a postcode is
     * '01234'; both become different values the moment anything treats them as
     * numbers, so a string that is stored stays exactly as it was stored, and
     * '' is a value (a field the shopper deliberately cleared), not an absence.
     *
     * Numbers are accepted and rendered, because a value stored as a number
     * still names the same thing. Booleans and arrays are refused: a boolean's
     * string form is '1' and '' to PHP and 'true' and 'false' to everyone else,
     * and an array has none worth guessing at.
     *
     * @param mixed $value The stored value.
     * @param mixed $default Returned when absent or null.
     * @param string $where What is being read, for the error message.
     *
     *
     * @throws ValueException When a present value will not convert.
     *
     * @since  1.10.0
     */
    public static function toString(mixed $value, mixed $default = null, string $where = 'value'): ?string
    {
        if ($value === null) {
            return $default === null ? null : (string) $default;
        }

        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        throw self::refuse($where, 'a string', $value);
    }

    /**
     * A whole number, or nothing at all.
     *
     * Accepts an int, a float with no fractional part (6.0), and a numeric
     * string that is whole ('6', '6.0', '007'). Refuses a fraction, a
     * non-numeric string, a boolean, an array, and anything outside PHP's
     * integer range, every one of which PHP would otherwise turn into a
     * plausible-looking number.
     *
     * Numeric strings are accepted deliberately: a value that has been through a
     * database column, a form post or another language's JSON writer routinely
     * arrives as one, and refusing them would push callers back to casting.
     *
     * @param mixed $value The stored value.
     * @param mixed $default Returned when absent or null.
     * @param string $where What is being read, for the error message.
     *
     *
     * @throws ValueException When a present value is not a whole number.
     *
     * @since  1.10.0
     */
    public static function toInt(mixed $value, mixed $default = null, string $where = 'value'): ?int
    {
        if ($value === null) {
            return $default === null ? null : (int) $default;
        }

        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value) || (\is_string($value) && is_numeric($value))) {
            $number = (float) $value;

            // The bound is PHP_INT_MAX as a float; beyond it (int) wraps or
            // saturates, which would turn a too-large id into a valid-looking
            // small one.
            if (floor($number) === $number && abs($number) <= (float) PHP_INT_MAX) {
                return (int) $number;
            }
        }

        throw self::refuse($where, 'a whole number', $value);
    }

    /**
     * A boolean, from the shapes that actually reach storage.
     *
     * SPELLED OUT, NEVER CAST. `(bool) 'false'` is true and `(bool) '0.0'` is
     * true, which is how a disabled setting comes back enabled. So the accepted
     * spellings are listed and everything else is refused:
     *
     *   true   true,  1, '1', 'true',  'on',  'yes'
     *   false  false, 0, '0', 'false', 'off', 'no', ''
     *
     * '' is false because that is what an unticked checkbox stores, and it is a
     * PRESENT value: a field somebody cleared, not one nobody wrote.
     *
     * @param mixed $value The stored value.
     * @param mixed $default Returned when absent or null.
     * @param string $where What is being read, for the error message.
     *
     *
     * @throws ValueException When a present value is not one of the spellings.
     *
     * @since  1.10.0
     */
    public static function toBool(mixed $value, mixed $default = null, string $where = 'value'): ?bool
    {
        if ($value === null) {
            return $default === null ? null : (bool) $default;
        }

        if (\is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === 0) {
            return $value === 1;
        }

        if (\is_string($value)) {
            $spelling = strtolower(trim($value));

            if (\in_array($spelling, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }

            if (\in_array($spelling, ['0', 'false', 'off', 'no', ''], true)) {
                return false;
            }
        }

        throw self::refuse($where, 'a boolean', $value);
    }

    /**
     * An array, exactly as stored.
     *
     * The nested structure is whoever wrote it's own shape (a configurator
     * scene, a list of tracking events) and it is handed back untouched.
     *
     * @param mixed $value The stored value.
     * @param mixed $default Returned when absent or null.
     * @param string $where What is being read, for the error message.
     *
     *
     * @throws ValueException When a present value is not an array.
     *
     * @since  1.10.0
     */
    public static function toArray(mixed $value, mixed $default = null, string $where = 'value'): ?array
    {
        if ($value === null) {
            return $default === null ? null : (array) $default;
        }

        if (\is_array($value)) {
            return $value;
        }

        throw self::refuse($where, 'an array', $value);
    }

    /**
     * The refusal, naming what was read, what was wanted and what is there.
     *
     * A stored-value fault is read months later out of a log line, so all three
     * go in the message: "cannot convert" alone sends a developer back to the
     * database to find out what they already had.
     *
     * @param string $where What is being read.
     * @param string $wanted What the caller asked for.
     * @param mixed $value What is actually stored.
     *
     *
     * @since  1.10.0
     */
    private static function refuse(string $where, string $wanted, mixed $value): ValueException
    {
        return new ValueException(sprintf(
            '%s is not %s; it holds %s.',
            $where,
            $wanted,
            \is_scalar($value)
                ? \gettype($value) . ' ' . var_export($value, true)
                : \gettype($value),
        ));
    }
}
