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
 * A search engine that can propose completions for a partial query.
 *
 * A CAPABILITY, NOT A TIER FLAG. This is one small interface that only some engines
 * implement, discovered with `instanceof` at the one call site that wants it (the
 * storefront search module's progressive enhancement), never a capability enum or a
 * registry of feature bits (SearchCapability was deleted for having zero callers; this
 * is its opposite: born with its first real consumer). An engine that cannot suggest
 * simply does not implement this, and the module renders no suggestions section rather
 * than a broken one.
 *
 * WHY IT IS SEPARATE FROM {@see SearchProviderInterface}. Matching answers "which products",
 * suggestion answers "what might you mean": a different question over a different
 * store (the term dictionary, not the postings), returned as text the shopper reads
 * and picks, not ids. The database baseline and the flat Indexed engine match without
 * a vocabulary to suggest from; only an engine that keeps a term dictionary can offer
 * this, which is exactly why it is the Enterprise tier's to implement.
 *
 * THE SUGGESTION IS THE BRIDGE, NOT A QUERY REWRITE. The engine never transforms the
 * shopper's query for them: that would make the same query mean different things per
 * engine. It offers spellings; the shopper picks one, and the real search runs with
 * the real chosen text. A greeklish prefix can therefore surface the native-script
 * word it was derived from, and the shopper converts by choosing, not the machine.
 *
 * @since  2.0.0
 */
interface SuggestionProviderInterface
{
    /**
     * Completions for what the shopper has typed so far.
     *
     * @param string $prefix What the shopper has typed: the partial last word, in
     *                       whatever script they are typing.
     * @param string $lang Content language tag (display or storage form).
     * @param int $limit Maximum suggestions to return.
     *
     * @return SearchSuggestion[] Suggestions, most relevant first, at most $limit, each
     *                            a complete word the shopper can search with as-is. An engine that
     *                            makes no entity claim returns them with `entity = null`; the
     *                            DECLARED labelling (exact catalogue-name matches) is layered on by
     *                            {@see CatalogSearchService}, not owed by the engine. Empty when
     *                            nothing matches or the engine cannot answer right now.
     *
     * @since  2.0.0
     */
    public function suggest(string $prefix, string $lang, int $limit = 10): array;
}
