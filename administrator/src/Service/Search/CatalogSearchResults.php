<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

use InvalidArgumentException;

/**
 * What a {@see CatalogSearchRequest} produced: typed, hydrated hits per entity, in
 * the order a surface should present them, plus the engine's completions, the
 * analytics handle, and the merchant redirect channel.
 *
 * SECTIONS ARE AN ORDERED LIST, NOT NAMED PROPERTIES. Presentation order is a property
 * of the RESULT (the service decides it at assembly; today's policy is the canonical
 * products -> categories -> manufacturers), so it travels as list order: the same order
 * the wire emits and every surface renders. Named per-entity properties could never
 * express "categories first for this query", which is exactly the seam the premium
 * tier's section-ordering policy needs. Keyed access stays available via
 * {@see self::section()}.
 *
 * THE EVENT HANDLE RIDES THE ENVELOPE because the service is the only place both
 * halves meet: the engine minted it inside product retrieval (surfaced by the model's
 * passback), and the SURFACE (a premium module rendering its own results page, a JSON
 * consumer) is what must stamp it on a click beacon. Without it here, every service
 * consumer would be structurally unable to attribute a click.
 *
 * THE REDIRECT CHANNEL IS NAMED AT BIRTH, populated by nothing yet. When merchant
 * redirect rules arrive, a redirected search carries the URL here INSTEAD OF sections
 * (the constructor enforces that exclusivity), so "redirect plus results" is
 * unrepresentable. The service owns the short-circuit (it skips hydration); the
 * surface owns navigation. Naming the field now is deliberate: adding it later would
 * be a semantic change disguised as an addition: an older consumer ignoring the new
 * key would render "no results" for a query the merchant redirected.
 *
 * @since  1.6.0
 */
final class CatalogSearchResults
{
    /**
     * @param CatalogSearchSection[] $sections Non-empty sections in presentation
     *                                         order. Empty sections are not carried:
     *                                         whether a section exists is a property
     *                                         of the result, not a per-surface
     *                                         decision.
     * @param SearchSuggestion[] $suggestions Completions, most relevant first;
     *                                        empty when not requested or the engine
     *                                        cannot suggest.
     *
     * @throws InvalidArgumentException When an element is mistyped, or a redirect is
     *                                  combined with sections.
     *
     * @since  1.9.0
     */
    public function __construct(
        public readonly array $sections = [],
        public readonly array $suggestions = [],
        /**
         * The analytics handle of the recorded search, or null when it was not
         * recorded. Opaque and engine-issued; see {@see SearchResult::$eventId},
         * whose value this carries out of the model layer.
         */
        public readonly int|string|null $eventId = null,
        /**
         * Merchant redirect URL; null = none (the only value anything produces today).
         * Non-null means: do not render sections; navigate. See the class docblock.
         */
        public readonly ?string $redirect = null,
    ) {
        foreach ($this->sections as $section) {
            if (!$section instanceof CatalogSearchSection) {
                throw new InvalidArgumentException('Results hold CatalogSearchSection instances only.');
            }
        }

        foreach ($this->suggestions as $suggestion) {
            if (!$suggestion instanceof SearchSuggestion) {
                throw new InvalidArgumentException('Results hold SearchSuggestion instances only.');
            }
        }

        if ($this->redirect !== null && $this->sections !== []) {
            throw new InvalidArgumentException('A redirected search carries no sections.');
        }
    }

    /**
     * How many catalogue rows matched, across every entity.
     *
     * SUGGESTIONS ARE NOT COUNTED: a completion is an offer to search for something else,
     * not a thing that was found. Counting them would let a query that matched nothing
     * report a non-zero total and so render as a hit, which is also why the analytics
     * `results_count` must come from retrieval and not from a payload a surface sends.
     *
     * @return int Total matched rows; 0 when nothing matched.
     *
     * @since  1.6.0
     */
    public function total(): int
    {
        $total = 0;

        foreach ($this->sections as $section) {
            $total += \count($section->hits);
        }

        return $total;
    }

    /**
     * Whether nothing at all matched.
     *
     * @return bool True when no entity returned a row.
     *
     * @since  1.6.0
     */
    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }

    /**
     * One entity's section, when it has rows.
     *
     * @param SearchEntity $entity Whose section to return.
     *
     * @return CatalogSearchSection|null Null when the entity matched nothing.
     *
     * @since  1.9.0
     */
    public function section(SearchEntity $entity): ?CatalogSearchSection
    {
        foreach ($this->sections as $section) {
            if ($section->entity === $entity) {
                return $section;
            }
        }

        return null;
    }
}
