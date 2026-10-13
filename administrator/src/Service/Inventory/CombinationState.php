<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

/**
 * The catalogue states of a combination row, and the single definition of a
 * combination that is still in the current catalogue.
 *
 * A published or unpublished combination is CURRENT: it is part of the product's
 * variant set, makes the product a product with combinations and is a stock unit.
 * An ARCHIVED combination is kept only as the stock identity that existing order
 * history is fulfilled, cancelled and returned against: it is never listed,
 * selected, sold, generated as a new row or counted in a figure, and a product
 * whose combinations are all archived is a plain product again.
 *
 * Readers that mean "offered for sale" keep testing state = 1.
 *
 * The same values are the catalogue states of a product (items.state): an
 * ARCHIVED product has left current commerce and is kept, with every stock
 * identity of it, only as the identity of its order history; currentSql() and
 * isCurrent() answer for a product's state column in the same way.
 *
 * @since  2.0.0
 */
final class CombinationState
{
    /** Offered for sale. */
    public const PUBLISHED = 1;

    /** Part of the variant set, not offered for sale. */
    public const UNPUBLISHED = 0;

    /** Kept only as the stock identity of order history. */
    public const ARCHIVED = 2;

    /**
     * The SQL condition "this combination row is current" for a state column.
     *
     * @param string $column The quoted (and aliased) state column, e.g. c.`state`.
     *
     * @return string A parenthesised boolean expression.
     *
     * @since  2.0.0
     */
    public static function currentSql(string $column): string
    {
        return '(COALESCE(' . $column . ', 0) <> ' . self::ARCHIVED . ')';
    }

    /**
     * Whether a stored state value is current (anything but archived; NULL reads as unpublished).
     *
     * @param mixed $state The stored state.
     *
     *
     * @since  2.0.0
     */
    public static function isCurrent(mixed $state): bool
    {
        return (int) ($state ?? self::UNPUBLISHED) !== self::ARCHIVED;
    }
}
