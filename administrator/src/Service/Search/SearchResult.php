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
 * Immutable provider result: ONE entity's ranked candidate ids, plus whether the cap
 * truncated them and the analytics handle the search was recorded under.
 *
 * ENTITY-TYPED, ONE ENTITY PER RESULT. A result answers exactly the entity its
 * {@see SearchQuery} declared and echoes it back, so a consumer can assert it was
 * answered in the domain it asked in. Mixed-entity id lists are deliberately
 * impossible here: every downstream ranking structure keys plain int item ids, and a
 * category id sharing that integer space would collide silently. Cross-entity search
 * is the SERVICE's fan-out ({@see CatalogSearchService}), never a provider's merge.
 *
 * RELEVANCE IS ARRAY ORDER. Ids arrive in the order the engine ranked them and are
 * consumed in that order; there is no per-hit score, because no consumer reads one
 * yet. An engine that gains a real score consumer adds a parallel id => score
 * structure here rather than resurrecting a value object wrapping one integer:
 * scores would be EVIDENCE, never ordering, since commercial re-ranking and pins
 * reorder $ids without recomputing them.
 *
 * Reserved for later, as appended nullable fields so the API never has to break:
 * `didYouMean` (deferred until analytics can judge corrections) and the merchant
 * redirect channel (the service/wire side already carries `redirect`; the
 * engine-side field arrives when merchant redirect rules exist, travelling out of
 * the model through the same passback the eventId uses).
 *
 * @since  1.3.0
 */
final class SearchResult
{
    /**
     * @param SearchEntity $entity WHOSE rows the ids name; echoes the query's declared
     *                             entity. An items-only provider answering a CATEGORY
     *                             query returns an EMPTY result carrying CATEGORY,
     *                             never item ids wearing the wrong domain.
     * @param int[] $ids The candidate ids in the entity's own table domain,
     *                   most relevant first. ORDER IS THE CONTRACT.
     * @param bool $limitApplied Whether the cap truncated the candidate set: the one
     *                           signal that says "there were more", and the reason a
     *                           catalogue silently capped at 1000 is visible instead
     *                           of merely wrong.
     *
     * @since  1.9.0
     */
    public function __construct(
        public readonly SearchEntity $entity = SearchEntity::ITEM,
        public readonly array $ids = [],
        public readonly bool $limitApplied = false,
        /**
         * The analytics handle this search was recorded under, or null when it was not
         * recorded at all.
         *
         * THE ATTRIBUTION EDGE, and it has to come back out here because this is the only
         * moment both halves exist: the engine has just written the search fact and knows
         * its id, while the surface that will render the results has not run yet. Without
         * this the storefront could not tell a click which search produced it, and the
         * alternative (inferring "the most recent search by this visitor" at click time)
         * is a guess that breaks on two tabs, a back button, or any concurrency.
         *
         * OPAQUE AND ENGINE-ISSUED: `int|string` because the handle's shape belongs to
         * the engine that minted it: the Enterprise engine issues integer event-row ids;
         * an external engine (hosted search services issue string query ids) is equally
         * entitled to the channel. Consumers carry it and hand it back; only the minting
         * engine interprets it. WHICH searches carry one is engine policy: the
         * Enterprise engine mints on ITEM searches only, one event per expressed search.
         *
         * NULL IS THE NORMAL CASE, not an error: a TYPEAHEAD search is deliberately not
         * recorded ({@see SearchIntent::recordsAnalytics()}), so there is no parent to
         * attribute to and no click may be recorded against it. Consumers must treat null
         * as "attribution unavailable" and record nothing, never as "attribute to zero".
         */
        public readonly int|string|null $eventId = null,
    ) {
    }

    /**
     * The matched ids, in relevance order, in the result entity's domain.
     *
     * @return int[]
     * @since  1.9.0
     */
    public function ids(): array
    {
        return $this->ids;
    }
}
