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
 * A filterable reference: the stable id you filter ON, plus the per-language name
 * you search and display BY.
 *
 * INVARIANT PROTECTED: a filter can never be built from a display label. Labels
 * are per-language and merchants rename them; ids are not and they do not. The
 * bug this makes impossible is the classic one: a merchant renames the
 * manufacturer "Bosch" to "Bosch GmbH", or translates a category, and every saved
 * filter, feed mapping and engine facet silently stops matching. Carrying both
 * means renames are free and the text stays searchable.
 *
 * Used for manufacturer and categories. Categories carry ASSIGNED ids only, never
 * the ancestor closure: closure is a query-time concern, and baking it into the
 * document makes one category move invalidate every descendant's document.
 *
 * @since  1.5.0
 */
final class FacetRef
{
    /**
     * @param int $id Stable identifier: the value a filter is built from.
     * @param string $name Per-language display/searchable name; may be '' when untranslated.
     * @param string $alias Per-language URL slug: the accent-free, transliterated form
     *                      of the name. Searchable when the merchant's scope says
     *                      aliases are; may be '' when untranslated.
     *
     * @since  1.5.0
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name = '',
        public readonly string $alias = '',
    ) {
    }
}
