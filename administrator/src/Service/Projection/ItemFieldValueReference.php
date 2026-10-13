<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Projection;

defined('_JEXEC') or die;

/**
 * Immutable (field, value) pair an item carries, as resolved by the collector.
 *
 * A typed reference instead of a loose `['field_id' => .., 'value_id' => ..]`
 * array: callers get IDE completion, type safety, and a single shape that the
 * filter-index projection inserts verbatim.
 *
 * @since  2.0.0
 */
final class ItemFieldValueReference
{
    /**
     * @param int $fieldId The owning field id (`#__alfa_item_fields.id`).
     * @param int $valueId The pool value id (`#__alfa_item_field_values.id`).
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly int $fieldId,
        public readonly int $valueId,
    ) {
    }
}
