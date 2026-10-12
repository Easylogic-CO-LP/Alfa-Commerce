<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

/**
 * One item-field value, ATTRIBUTED: which field it belongs to, which value it is,
 * and the text that makes it searchable.
 *
 * INVARIANT PROTECTED: a value can never reach the index as an anonymous token.
 * Today the builder selects only the value's name and alias, so "Size = M" and
 * "Grade = M" both arrive as the bare token "m": indistinguishable, unfilterable,
 * and a rename of either breaks nothing visibly while breaking everything
 * silently. Carrying (fieldId, valueId) means an engine can offer a real facet
 * ("Size: M"), a saved filter survives renames and translation, and two fields
 * that happen to share a label stay distinct.
 *
 * The alias is the transliterated form (greeklish and friends); it is indexed
 * alongside the name so a shopper typing in latin script finds a value written in
 * another one. Transliteration rules are per-locale and belong to whoever owns the
 * locale; core must ship none.
 *
 * @since  1.5.0
 */
final class FieldValue
{
    /**
     * @param int $fieldId Owning item-field id: the facet key.
     * @param int $valueId Value id: the filter value.
     * @param string $fieldName Per-language field name (e.g. "Size"); '' when untranslated.
     * @param string $valueName Per-language value name (e.g. "M"); '' when untranslated.
     * @param string $valueAlias Transliterated value form; '' when none.
     *
     * @since  1.5.0
     */
    public function __construct(
        public readonly int $fieldId,
        public readonly int $valueId,
        public readonly string $fieldName = '',
        public readonly string $valueName = '',
        public readonly string $valueAlias = '',
    ) {
    }
}
