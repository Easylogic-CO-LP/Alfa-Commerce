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
 * How a write boundary was told to mutate the persisted custom price.
 *
 * TRANSIENT, AND THAT IS A RULE: "this control is a transient form
 * command only. It must NOT be persisted as a separate custom-price flag or
 * database column." It never reaches the pricing engine: the engine receives
 * {@see ExplicitPriceInstruction}, which is money and nothing else. This object
 * is consumed by the writer and discarded, and the persisted representation
 * stays ONE nullable money column with no companion boolean.
 *
 * ONE DECODER, TWO WIRE VOCABULARIES. {@see self::fromPayload()} holds the only
 * table that turns a wire value into a command; {@see self::fromAdminPost()}
 * translates the admin form's three-way control into that same table rather
 * than repeating it. Building the decoder twice (once for the form and once
 * for the API) is precisely the divergence the API parity rule forbids.
 *
 * A UNIT RATE, NOT A CURRENCY AMOUNT. A
 * Set amount may be finer than the currency's scale, up to Alfa's unit-rate
 * precision (8 decimal places, 14 significant digits); beyond that it is
 * refused, at every door, by the one canonicaliser
 * {@see ExplicitPriceInstruction::canonicalRate()}. The line charges the rate
 * times the quantity, rounded once.
 *
 * @since  2.0.0
 */
final class CustomPriceCommand
{
    /** The field this command speaks about, on the wire and in the column. */
    public const FIELD = 'custom_unit_price_tax_incl';

    /** The admin form's three-way control. */
    public const ACTION_FIELD = 'custom_price_action';

    /**
     * @param CustomPriceAction $action What to do.
     * @param float|null $amount The amount, for Set only. 0.0 is valid money.
     */
    private function __construct(
        public readonly CustomPriceAction $action,
        public readonly ?float $amount,
    ) {
    }

    /**
     * THE ONE DECODER. A REST body and an admin post alike arrive here.
     *
     * The tri-state, from the REST zero-unit-price rule and the price-command rule read
     * together:
     *
     *   key ABSENT          -> Leave  - preserve the existing custom-price state
     *   key present, null   -> Clear  - return the line to canonical repricing
     *   key present, 0      -> Set(0.0) - an explicit free price
     *   key present, n > 0  -> Set(n), including ABOVE the catalogue price
     *   key present, n < 0  -> REFUSED, naming the field, writing nothing
     *   key present, junk   -> REFUSED - "abc" must not become 0, true must not
     *                         become 1, "" must not become anything at all
     *   key present, finer  -> REFUSED - beyond Alfa's unit-rate precision
     *
     * A string reaches the canonicaliser as it was written, never through a
     * float first.
     *
     * @param array $payload The decoded request body or post.
     * @param string $key The field name, for a caller that names it otherwise.
     *
     *
     * @throws InvalidArgumentException When the value is present but is not a usable amount.
     *
     * @since  2.0.0
     */
    public static function fromPayload(array $payload, string $key = self::FIELD): self
    {
        // ABSENCE IS NOT AN INSTRUCTION TO ZERO. array_key_exists, never
        // isset(): isset() answers false for a present null, which is exactly
        // the Clear case, and would silently turn every Clear into a Leave.
        if (!\array_key_exists($key, $payload)) {
            return self::leave();
        }

        $value = $payload[$key];

        if ($value === null) {
            return self::clear();
        }

        // NO LANGUAGE COERCION. The failures to avoid: "abc" becoming 0,
        // true becoming 1. is_numeric() refuses both, and refuses "" and []
        // with them. A numeric string is accepted because that is what a form
        // post and a JSON body both legitimately carry.
        if (\is_bool($value) || !\is_scalar($value) || !is_numeric($value)) {
            throw new InvalidArgumentException(
                'The field "' . $key . '" must be a number, null, or absent.',
            );
        }

        // A WRITTEN AMOUNT IS A PLAIN DECIMAL. is_numeric() also admits
        // "1e1", " 7.5" and "7.5 ", which are not amounts anyone writes and
        // which count as malformed numeric input. A string must be the
        // canonical ungrouped decimal an API body or a number input carries,
        // untrimmed, the same grammar the REST refund amount holds to.
        if (\is_string($value) && preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException(
                'The field "' . $key . '" must be a plain decimal number such as 12.50, null, or absent.',
            );
        }

        return self::set(amount: $value);
    }

    /**
     * The admin popup's three-way control, mapped onto the same table.
     *
     * The control posts leave | set | clear. With leave and clear the amount
     * input is not posted at all, so the rule "an empty visible input must not
     * automatically mean Clear" falls out by construction: with Leave the
     * amount is never read, and with Set an empty amount is a refusal: the
     * operator chose Set and typed nothing, which is a mistake, not a command.
     *
     * @param array $post The request data.
     * @param string $actionKey The control's field name.
     * @param string $key The amount's field name.
     *
     *
     * @throws InvalidArgumentException When the action is unknown, or Set carries no usable amount.
     *
     * @since  2.0.0
     */
    public static function fromAdminPost(
        array $post,
        string $actionKey = self::ACTION_FIELD,
        string $key = self::FIELD,
    ): self {
        $action = $post[$actionKey] ?? 'leave';

        if (!\is_string($action)) {
            throw new InvalidArgumentException('The field "' . $actionKey . '" must be leave, set or clear.');
        }

        return match (strtolower(trim($action))) {
            'leave' => self::fromPayload([], $key),
            'clear' => self::fromPayload([$key => null], $key),
            'set' => self::fromPayload([$key => $post[$key] ?? ''], $key),
            default => throw new InvalidArgumentException(
                'The field "' . $actionKey . '" must be leave, set or clear.',
            ),
        };
    }

    /**
     * No pricing mutation. Requires no pricing authority.
     *
     *
     * @since  2.0.0
     */
    public static function leave(): self
    {
        return new self(CustomPriceAction::Leave, null);
    }

    /**
     * Set an explicit price. Zero is accepted and is money.
     *
     * The amount is validated by the same rules the engine's instruction
     * enforces, so a command that cannot become an instruction is refused at
     * the boundary rather than at pricing time. It is kept as the canonical
     * rate's float, which is exact under the precision guard.
     *
     * @param float|int|string $amount The operator's unit price, tax included.
     *
     *
     * @throws InvalidArgumentException When the amount is not a finite, non-negative plain decimal within
     *                                  Alfa's unit-rate precision.
     *
     * @since  2.0.0
     */
    public static function set(float|int|string $amount): self
    {
        return new self(action: CustomPriceAction::Set, amount: (float) ExplicitPriceInstruction::perUnitTaxIncl(amount: $amount)->rate);
    }

    /**
     * Return the line to canonical catalogue/customer repricing.
     *
     *
     * @since  2.0.0
     */
    public static function clear(): self
    {
        return new self(CustomPriceAction::Clear, null);
    }

    /**
     * Whether this command changes the persisted custom price at all.
     *
     * The transient Leave unchanged command is NOT a custom-price
     * mutation and requires no additional pricing authority. This is the one
     * place that sentence is expressed, so a write boundary asks the permission
     * for exactly the acts that need it.
     *
     *
     * @since  2.0.0
     */
    public function mutates(): bool
    {
        return $this->action !== CustomPriceAction::Leave;
    }
}
