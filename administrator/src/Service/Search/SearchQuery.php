<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Catalog\CatalogAccessPolicy;

/**
 * Immutable search request: the normalised keyword, the language to search, a
 * candidate cap (so "a" / "red" can't return tens of thousands of ids), and WHO IS
 * ASKING.
 *
 * The principals say WHO IS ASKING. An engine that can filter natively resolves
 * visibility itself against them, reading the catalogue's rules through
 * {@see CatalogAccessPolicy}, instead of returning candidates for the catalogue to
 * discard. Carrying them here rather than letting a provider read the current user
 * means one request can legitimately search as someone else (a scheduled export, a
 * preview, a support tool), and that a provider never has to know a Joomla user exists.
 *
 * The Indexed SQL engine leaves these unused: it returns candidate ids that the
 * catalogue query filters with the same policy, so applying it twice would only add
 * a way for the two to disagree.
 *
 * @since  2.0.0
 */
final class SearchQuery
{
    /**
     * @param string $keyword The normalised keyword.
     * @param string $lang The language tag to search, lower-snake form (e.g. en_gb);
     *                     the display form (en-GB) is normalised by providers.
     * @param int $limit Maximum candidate ids to return.
     * @param int $userId Asking user id; 0 for a guest.
     * @param int[] $groups Asking user's authorised usergroup ids.
     * @param SearchIntent $intent WHY this search is running; see below.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $keyword,
        public readonly string $lang,
        public readonly int $limit = 1000,
        public readonly int $userId = 0,
        public readonly array $groups = [],
        /**
         * DECLARED BY THE SURFACE, NEVER INFERRED. A provider cannot know whether it is
         * completing a half-typed word or answering a query the shopper submitted, and the
         * previous attempt to guess (reading `option=com_ajax` off the request) silently
         * stopped working the moment a second search surface existed.
         *
         * DEFAULTS TO TYPEAHEAD, which is the non-recording case: a caller that forgets to
         * declare loses an analytics row rather than inventing one.
         * {@see SearchIntent::recordsAnalytics()} for why that asymmetry is the right way
         * round.
         */
        public readonly SearchIntent $intent = SearchIntent::TYPEAHEAD,
        /**
         * WHOSE rows this query asks for. One query = one entity's candidates; the
         * cross-entity fan-out belongs to {@see CatalogSearchService}, never to a
         * provider merge, because every ranking structure downstream keys plain int
         * item ids and a second entity in the same list would collide silently.
         *
         * DEFAULTS TO ITEM, so every existing call site keeps meaning exactly what it
         * meant. A provider that cannot serve the declared entity answers an EMPTY
         * result echoing it ({@see SearchResult::$entity}), never another entity's ids.
         *
         * @since 2.0.0
         */
        public readonly SearchEntity $entity = SearchEntity::ITEM,
    ) {
    }

    /**
     * Build a query for the current visitor, resolving the principals from the
     * catalogue policy so search and catalogue can never disagree about identity.
     *
     * @param string $keyword The normalised keyword.
     * @param string $lang The language tag to search.
     * @param int $limit Maximum candidate ids to return.
     * @param SearchIntent $intent Why the search is running; defaults to the non-recording
     *                             {@see SearchIntent::TYPEAHEAD}, so a caller that does not
     *                             declare cannot accidentally create analytics data.
     * @param SearchEntity $entity Whose rows to ask for; defaults to {@see SearchEntity::ITEM}.
     *
     *
     * @since  2.0.0
     */
    public static function forCurrentVisitor(
        string $keyword,
        string $lang,
        int $limit = 1000,
        SearchIntent $intent = SearchIntent::TYPEAHEAD,
        SearchEntity $entity = SearchEntity::ITEM,
    ): self {
        $principals = (new CatalogAccessPolicy())->principals();

        return new self(
            keyword: $keyword,
            lang:    $lang,
            limit:   $limit,
            userId:  (int) $principals['userId'],
            groups:  (array) $principals['groups'],
            intent:  $intent,
            entity:  $entity,
        );
    }
}
