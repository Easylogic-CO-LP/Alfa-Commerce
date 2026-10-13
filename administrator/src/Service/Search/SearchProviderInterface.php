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
