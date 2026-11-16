<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

use ArrayIterator;
use ArrayObject;
use Iterator;
use JsonSerializable;
use LogicException;

defined('_JEXEC') or die;

/**
 * The surfaces of a product page, a catalogue card or a cart line, by slot ($owner->surfaces, 2.2.1): the descriptors
 * every contributor's surface became, read-only once handed out.
 *
 * Core collects the contributions of each display phase in a list of its own and hands the result to the item or the
 * line as this map, so a plugin that holds the item or the line (the subject of onItemView, the item of onItemDisplay,
 * the line of onCartItemDisplay) reads what the others contributed and cannot change or remove it: setting, removing,
 * appending or reordering a slot is refused with a LogicException, and appending to a slot's list changes nothing (PHP
 * reports that an indirect change of the map has no effect). A plugin contributes through its own event
 * (addSurface(), or the item field events' surface builders); a template chooses among the surfaces with
 * {@see \Alfa\Component\Alfa\Site\Helper\Surface::render()} and its only and except lists.
 *
 * A template reads it as it read the array before: $item->surfaces[ItemSurface::BuyForm->value] ?? [] is the slot's
 * list (empty when no one contributed to it), isset() and count() answer as for an array, foreach walks the slots in
 * the order they were first filled, (array) $item->surfaces and toArray() give the array itself, and json_encode()
 * writes it as before.
 *
 * @extends ArrayObject<string, list<array<string, mixed>>>
 *
 * @since  2.2.1
 */
final class SurfaceMap extends ArrayObject implements JsonSerializable
{
    /**
     * @param array<string, list<array<string, mixed>>> $slots The descriptors by slot, as the display phase collected them.
     *
     * @since  2.2.1
     */
    public function __construct(array $slots = [])
    {
        parent::__construct($slots);
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
        return parent::offsetExists($slot) ? parent::offsetGet($slot) : [];
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
        return $this->getArrayCopy();
    }

    /**
     * The slot's descriptors; an empty list when no one contributed to it.
     *
     * @param mixed $key The slot.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.2.1
     */
    public function offsetGet(mixed $key): array
    {
        return $this->slot((string) $key);
    }

    /**
     * Refused: the surfaces are read-only once handed out.
     *
     * @param mixed $key The slot.
     * @param mixed $value The value.
     *
     * @throws LogicException Always.
     *
     * @since  2.2.1
     */
    public function offsetSet(mixed $key, mixed $value): void
    {
        throw self::readOnly();
    }

    /**
     * Refused: the surfaces are read-only once handed out.
     *
     * @param mixed $key The slot.
     *
     * @throws LogicException Always.
     *
     * @since  2.2.1
     */
    public function offsetUnset(mixed $key): void
    {
        throw self::readOnly();
    }

    /**
     * Refused: the surfaces are read-only once handed out.
     *
     * @param mixed $value The value.
     *
     * @throws LogicException Always.
     *
     * @since  2.2.1
     */
    public function append(mixed $value): void
    {
        throw self::readOnly();
    }

    /**
     * Refused: the surfaces are read-only once handed out.
     *
     * @param array<mixed>|object $array The replacement.
     *
     * @return array<mixed>
     *
     * @throws LogicException Always.
     *
     * @since  2.2.1
     */
    public function exchangeArray(array|object $array): array
    {
        throw self::readOnly();
    }

    /**
     * Refused: how the map reads is fixed.
     *
     * @param int $flags The flags.
     *
     * @throws LogicException Always.
     *
     * @since  2.2.1
     */
    public function setFlags(int $flags): void
    {
        throw self::readOnly();
    }

    /**
     * Refused: the slots keep the order they were first filled.
     *
     * @param int $flags The sort flags.
     *
     * @throws LogicException Always.
     *
     * @since  2.2.1
     */
    public function asort(int $flags = SORT_REGULAR): true
    {
        throw self::readOnly();
    }

    /**
     * Refused: the slots keep the order they were first filled.
     *
     * @param int $flags The sort flags.
     *
     * @throws LogicException Always.
     *
     * @since  2.2.1
     */
    public function ksort(int $flags = SORT_REGULAR): true
    {
        throw self::readOnly();
    }

    /**
     * Refused: the slots keep the order they were first filled.
     *
     * @param callable $callback The comparison.
     *
     * @throws LogicException Always.
     *
     * @since  2.2.1
     */
    public function uasort(callable $callback): true
    {
        throw self::readOnly();
    }

    /**
     * Refused: the slots keep the order they were first filled.
     *
     * @param callable $callback The comparison.
     *
     * @throws LogicException Always.
     *
     * @since  2.2.1
     */
    public function uksort(callable $callback): true
    {
        throw self::readOnly();
    }

    /**
     * Refused: the slots keep the order they were first filled.
     *
     * @throws LogicException Always.
     *
     * @since  2.2.1
     */
    public function natsort(): true
    {
        throw self::readOnly();
    }

    /**
     * Refused: the slots keep the order they were first filled.
     *
     * @throws LogicException Always.
     *
     * @since  2.2.1
     */
    public function natcasesort(): true
    {
        throw self::readOnly();
    }

    /**
     * The slots with their descriptors, walked over a copy, so nothing written through the iterator reaches the map.
     *
     * @return Iterator<string, list<array<string, mixed>>>
     *
     * @since  2.2.1
     */
    public function getIterator(): Iterator
    {
        return new ArrayIterator($this->getArrayCopy());
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
        return $this->getArrayCopy();
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
