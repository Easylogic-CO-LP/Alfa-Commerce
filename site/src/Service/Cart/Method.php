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
 * The carrier or gateway being asked, as its own plugin reads it.
 *
 * A METHOD IS ONE THING, SO IT IS HANDED OVER AS ONE THING. For a while the
 * dispatcher took the method record apart and passed a few fields out of it:
 * the id, the type, the params, and everything else the record carried was
 * dropped. That made the core the gatekeeper of what a plugin may know about
 * ITS OWN method: a carrier wanting the ordering, the colours, or the
 * catalogue scoping could not have them without a change to the component. It
 * also did not survive contact with reality: a gateway that needed a method
 * object simply rebuilt a fake one out of the accessors it was left with.
 *
 * NOT THE RAW ROW EITHER. The record is a database row whose shape depends on
 * which model loaded it and which joins ran, and handing it over directly is
 * how `$method->params->params` came to be written. This reads it instead:
 * the named accessors are typed and total, and {@see self::get()} is there for
 * the column no accessor has been written for yet, so a plugin is never
 * blocked waiting for the core to expose something.
 *
 * READ ONLY. Changing a method is an administrative act with its own model and
 * its own permissions, never something a plugin does while pricing a basket.
 *
 * @since  2.0.0
 */
final class Method
{
    /**
     * @param object $record The method row, params already decoded.
     * @param bool $selected Whether the cart currently carries this method.
     *
     * @since  2.0.0
     */
    private function __construct(
        private readonly object $record,
        private readonly bool $selected,
    ) {
    }

    /**
     * Wrap a loaded method record.
     *
     * @param object $record The row as `AlfaHelper::getFilteredMethods()` returns it.
     * @param bool $selected Whether this is the method the cart records as chosen.
     *
     *
     * @since  2.0.0
     */
    public static function of(object $record, bool $selected = false): self
    {
        return new self($record, $selected);
    }

    /** @return int This method's id. @since 2.0.0 */
    public function id(): int
    {
        return (int) ($this->record->id ?? 0);
    }

    /** @return string The plugin type that answers for it. @since 2.0.0 */
    public function type(): string
    {
        return (string) ($this->record->type ?? '');
    }

    /**
     * The shopper-facing name, in the active language.
     *
     *
     * @since  2.0.0
     */
    public function name(): string
    {
        return (string) ($this->record->name ?? '');
    }

    /**
     * The shopper-facing description, in the active language.
     *
     *
     * @since  2.0.0
     */
    public function description(): string
    {
        return (string) ($this->record->description ?? '');
    }

    /**
     * Whether the cart currently carries this method.
     *
     *
     * @since  2.0.0
     */
    public function isSelected(): bool
    {
        return $this->selected;
    }

    /**
     * This method's own configuration, as the merchant saved it.
     *
     * Already decoded: the loader turns the stored JSON into an array before a
     * plugin ever sees it, so there is no Registry to unwrap and no second
     * `params` inside the first.
     *
     *
     * @since  2.0.0
     */
    public function params(): array
    {
        return (array) ($this->record->params ?? []);
    }

    /**
     * One configured value.
     *
     * @param string $key The params key.
     * @param mixed $default Returned when the merchant has not set it.
     *
     *
     * @since  2.0.0
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params()[$key] ?? $default;
    }

    /**
     * Anything else the record carries.
     *
     * The escape hatch that keeps this class from becoming a bottleneck: a
     * plugin needing a column no accessor names yet reads it here rather than
     * waiting for the component to grow a method for it. A value read this way
     * is whatever the loader put there, so check it before trusting it.
     *
     * @param string $property The record property.
     * @param mixed $default Returned when the record has no such property.
     *
     *
     * @since  2.0.0
     */
    public function get(string $property, mixed $default = null): mixed
    {
        return $this->record->{$property} ?? $default;
    }
}
