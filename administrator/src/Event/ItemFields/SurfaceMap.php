<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use Traversable;

defined('_JEXEC') or die;

/**
 * The surfaces of a product page, a catalogue card or a cart line, by slot ($owner->surfaces, 2.2.1): the descriptors
 * every contributor's surface became, read-only once handed out.
 *
 * Core collects the contributions of each display phase in a list of its own and hands the result to the item or the
 * line as this map, so a plugin that holds the item or the line (the subject of onItemView, the item of onItemDisplay,
 * the line of onCartItemDisplay) reads what the others contributed and cannot change or remove it: writing a slot
 * through array access is refused with a LogicException. A plugin contributes through its own event
 * (addSurface(), or the item field events' surface builders); a template chooses among the surfaces with
 * {@see \Alfa\Component\Alfa\Site\Helper\Surface::render()} and its only and except lists.
 *
 * A template reads it as before: $item->surfaces[ItemSurface::BuyForm->value] ?? [] is the slot's list (empty when no
 * one contributed to it), and foreach walks the slots in the order they were first filled.
 *
 * @implements ArrayAccess<string, list<array<string, mixed>>>
 * @implements IteratorAggregate<string, list<array<string, mixed>>>
 *
 * @since  2.2.1
 */
final class SurfaceMap implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * The descriptors by slot, each slot's list in contribution order.
     *
     * @var array<string, list<array<string, mixed>>>
     * @since  2.2.1
     */
    private array $slots;

    /**
     * @param array<string, list<array<string, mixed>>> $slots The descriptors by slot, as the display phase collected them.
     *
     * @since  2.2.1
     */
    public function __construct(array $slots = [])
    {
        $this->slots = $slots;
    }

    /**
     * One slot's descriptors, in contribution order; an empty list when no one contributed to it.
     *
     * @param string $slot The slot (a surface enum case's value).
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.2.1
     */
    public function slot(string $slot): array
    {
        return $this->slots[$slot] ?? [];
    }

    /**
     * Every slot with its descriptors, the slots in the order they were first filled.
     *
     * @return array<string, list<array<string, mixed>>>
     *
     * @since  2.2.1
     */
    public function toArray(): array
    {
        return $this->slots;
    }

    /**
     * Whether any contributor filled the slot.
     *
     * @param mixed $offset The slot.
     *
     * @since  2.2.1
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->slots[(string) $offset]);
    }

    /**
     * The slot's descriptors; an empty list when no one contributed to it.
     *
     * @param mixed $offset The slot.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.2.1
     */
    public function offsetGet(mixed $offset): array
    {
        return $this->slot((string) $offset);
    }

    /**
     * Refused: the surfaces are read-only once handed out.
     *
     * @param mixed $offset The slot.
     * @param mixed $value The value.
     *
     * @throws LogicException Always.
     *
     * @since  2.2.1
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw self::readOnly();
    }

    /**
     * Refused: the surfaces are read-only once handed out.
     *
     * @param mixed $offset The slot.
     *
     * @throws LogicException Always.
     *
     * @since  2.2.1
     */
    public function offsetUnset(mixed $offset): void
    {
        throw self::readOnly();
    }

    /**
     * How many slots hold a contribution.
     *
     * @since  2.2.1
     */
    public function count(): int
    {
        return \count($this->slots);
    }

    /**
     * The slots with their descriptors.
     *
     * @return Traversable<string, list<array<string, mixed>>>
     *
     * @since  2.2.1
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->slots);
    }

    /**
     * The slots with their descriptors, as an array.
     *
     * @return array<string, list<array<string, mixed>>>
     *
     * @since  2.2.1
     */
    public function jsonSerialize(): array
    {
        return $this->slots;
    }

    /**
     * The refusal of a write.
     *
     * @since  2.2.1
     */
    private static function readOnly(): LogicException
    {
        return new LogicException(
            'The surfaces of a page or a cart line are read-only: a plugin contributes its own through its display event,'
            . ' and a template chooses among them with Surface::render().',
        );
    }
}
