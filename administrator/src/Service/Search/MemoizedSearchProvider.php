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
 * Request-scoped memo in front of another provider.
 *
 * Rendering one catalog page executes retrieval once for the listing and once
 * more per filterable field, because the facet builder clones the model and
 * rebuilds the same query. Every one of those calls carries the SAME keyword and
 * language, so without a memo a seven-facet catalog pays eight identical
 * full-document scans per request: the dominant cost of the search path.
 *
 * The cache is static (one PHP request = one process) and keyed on the WHOLE query,
 * principals included. Retrieval is read-only, so serving a repeat call from memory
 * is indistinguishable from re-running it, but only while the key is complete.
 *
 * THE PRINCIPALS ARE PART OF THE KEY, and that is a correctness requirement rather
 * than a precaution. {@see SearchQuery} carries who is asking precisely so an engine
 * that can filter natively resolves visibility itself instead of returning candidates
 * for the catalogue to discard. The moment such an engine is installed, two visitors
 * with different usergroups issuing the same keyword in the same request (a shopper
 * and an administrator previewing, a guest and a logged-in customer under a shared
 * cache warm-up) legitimately get DIFFERENT result sets, and a key that omits them
 * hands the first visitor's set to the second. The Indexed SQL engine ignores the
 * principals, so this costs nothing today; it exists so that installing an engine
 * cannot introduce a leak in a class the engine author never sees.
 *
 * @since  2.0.0
 */
final class MemoizedSearchProvider implements SearchProviderInterface
{
    /**
     * @var array<string, SearchResult>
     *
     * @since  2.0.0
     */
    private static array $cache = [];

    /**
     * @param SearchProviderInterface $inner The provider whose results are memoised.
     * @param string $scope Distinguishes provider compositions sharing the cache.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly SearchProviderInterface $inner,
        private readonly string $scope = 'catalog',
    ) {
    }

    /**
     * Return the memoised result, executing the inner provider only on a miss.
     *
     * @param SearchQuery $query The normalised search request.
     *
     *
     * @since  2.0.0
     */
    public function search(SearchQuery $query): SearchResult
    {
        return self::$cache[$this->keyFor($query)] ??= $this->inner->search($query);
    }

    /**
     * The cache key: every input that can change the result, and nothing else.
     *
     * Groups are SORTED before they are folded in, so the same visitor keyed twice
     * within a request cannot miss their own entry because the policy happened to
     * return their usergroups in another order.
     *
     * The keyword goes LAST and the parts are joined with a separator that cannot
     * occur in the fixed-width fields before it, so no combination of inputs can
     * spell another combination's key.
     *
     * @param SearchQuery $query The request being keyed.
     *
     *
     * @since  2.0.0
     */
    private function keyFor(SearchQuery $query): string
    {
        $groups = array_map('intval', $query->groups);
        sort($groups, SORT_NUMERIC);

        // The entity and the intent sit BEFORE the keyword, like every other
        // fixed-vocabulary part, so the documented invariant above (keyword last,
        // nothing can spell another combination's key) keeps holding by construction
        // rather than by accident. THE INTENT IS PART OF THE KEY for the same reason
        // the principals are: the moment an engine widens TYPEAHEAD retrieval (the
        // enterprise prefix group), the same keyword under two intents legitimately
        // returns DIFFERENT result sets, and a key that omits it hands a keystroke's
        // widened answer to the expressed search that follows it in the same request.
        return implode('|', [
            $this->scope,
            $query->lang,
            $query->limit,
            $query->userId,
            implode(',', $groups),
            $query->entity->value,
            $query->intent->value,
            $query->keyword,
        ]);
    }
}
