<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

/**
 * Retrieval-only contract: given a query, return candidate item ids. The matching
 * engine (LIKE -> FULLTEXT -> an external service) is an implementation
 * detail behind this interface; every provider obeys the same limit semantics and
 * reports truncation the same way, and relevance is expressed as the ORDER of the
 * returned ids, never as a policy knob (ranking is a separate, later layer).
 * Visibility / usergroup / price stay with the catalog query that filters these
 * candidates.
 *
 * ATTRIBUTION (2.2.0). The handle names the rendered result set of this request, not
 * the search. A provider may return one for any query that carries a ResultSetContext,
 * on any page and under any intent, and relates the handles of one shopper's pages
 * internally, as its own analytics model decides. A query without a ResultSetContext
 * backs no rendered result set (a facet re-read, a type-ahead lookup, a programmatic
 * read) and gets no handle. Core carries the handle and renders it with the global
 * position; it never interprets it. A provider sets SearchResult::$responseSpecific when
 * its handle belongs to this response or visitor alone.
 *
 * @since  2.0.0
 */
interface SearchProviderInterface
{
    /**
     * @param SearchQuery $query The normalised search request.
     *
     * @return SearchResult Candidate hits.
     * @since  2.0.0
     */
    public function search(SearchQuery $query): SearchResult;
}
