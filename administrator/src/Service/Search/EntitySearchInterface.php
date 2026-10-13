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
 * A search engine that can retrieve entities beyond products.
 *
 * A CAPABILITY, NOT A TIER FLAG. The {@see SuggestionProviderInterface} pattern
 * exactly: one small interface only some engines implement, discovered with
 * `instanceof` at the one call site that routes by it ({@see CatalogSearchService}),
 * never a capability enum. An engine that cannot serve an entity simply is not asked,
 * and the service keeps the model-LIKE fallback that has always answered.
 *
 * IMPLEMENTED BY THE ENGINE, NOT THE PROVIDER, for a mechanical reason: the service
 * resolves providers through {@see MemoizedSearchProvider}, and an `instanceof` on
 * the wrapped provider is always false through the decorator. The engine is also the
 * honest party: capability is a property of what its SERVING index contains, which
 * the provider cannot know without asking the same ledger.
 *
 * ANSWER HONESTLY, PER GENERATION. `canSearch()` must reflect what the serving index
 * can actually retrieve NOW: an engine whose serving generation predates entity
 * documents must answer false, so the service falls back to the live-tables path
 * instead of serving structurally empty sections. Capability returns with the first
 * rebuild that carries the documents; deployment needs no flag day.
 *
 * Every product engine serves ITEM by definition (the floor contract), so the
 * question only ever matters for the other entities; answering true for ITEM is
 * still required, so a caller may probe uniformly.
 *
 * @since  2.0.0
 */
interface EntitySearchInterface
{
    /**
     * Whether the engine's SERVING index can retrieve this entity right now.
     *
     * @param SearchEntity $entity The entity a caller wants to route through the engine.
     *
     * @return bool True when a search for this entity would scan real documents.
     *
     * @since  2.0.0
     */
    public function canSearch(SearchEntity $entity): bool;
}
