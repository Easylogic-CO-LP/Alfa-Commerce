<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

defined('_JEXEC') or die;

/**
 * One checkout field's value on one address, as a plugin reads it.
 *
 * THREE STATES, NOT TWO. A plugin asking for "the postcode" can get an answer
 * ('15121'), an empty answer (the shopper cleared the box), or no answer at all
 * (the merchant never mapped a field, or mapped one that has since been deleted
 * or unpublished). Collapsing the last two into '' is what lets a carrier
 * silently price against a destination nobody supplied, so they are kept apart:
 * `exists()` says whether the field is there, `value()` says what is in it.
 *
 * @since  1.10.0
 */
final class FieldValue
{
    /**
     * @param bool $exists Whether a live checkout field backs this value.
     * @param string|null $raw What it holds; null when nothing does.
     * @param string $column The `#__alfa_user_info` column, '' when unresolved.
     *
     * @since  1.10.0
     */
    private function __construct(
        private readonly bool $exists,
        private readonly ?string $raw,
        private readonly string $column,
    ) {
    }

    /**
     * A value read from a live field.
     *
     * @param string|null $raw The value, or null when the record holds none.
     * @param string $column The column it was read from.
     *
     *
     * @since  1.10.0
     */
    public static function of(?string $raw, string $column): self
    {
        return new self(true, $raw, $column);
    }

    /**
     * No field backs this: never mapped, or mapped to one that is gone.
     *
     *
     * @since  1.10.0
     */
    public static function missing(): self
    {
        return new self(false, null, '');
    }

    /**
     * Whether a live, published checkout field backs this value.
     *
     * False means the merchant has not told this plugin which field to read:
     * a configuration question, not an empty box, so a plugin should say so
     * rather than behave as though the shopper left it blank.
     *
     *
     * @since  1.10.0
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * The value, trimmed; null when there is no field or nothing in it.
     *
     *
     * @since  1.10.0
     */
    public function value(): ?string
    {
        $value = trim((string) $this->raw);

        return $value !== '' ? $value : null;
    }

    /**
     * The value as an integer (a place id, a quantity) or null.
     *
     *
     * @since  1.10.0
     */
    public function int(): ?int
    {
        $value = $this->value();

        return $value !== null && is_numeric($value) ? (int) $value : null;
    }

    /**
     * The `#__alfa_user_info` column this came from, for logging and diagnosis.
     *
     *
     * @since  1.10.0
     */
    public function column(): string
    {
        return $this->column;
    }
}
