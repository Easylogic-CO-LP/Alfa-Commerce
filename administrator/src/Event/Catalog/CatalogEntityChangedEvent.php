<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Catalog;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractEvent;

/**
 * Catalogue rows of ONE auxiliary entity changed: broadcast, for anyone keeping a copy.
 *
 * `onAlfaCatalogEntityChanged`, emitted through
 * {@see \Alfa\Component\Alfa\Administrator\Service\Catalog\EntityChangeNotifier}
 * whenever category or manufacturer rows are created, renamed, re-described,
 * state-changed or deleted. The first consumer is the Enterprise search engine, which
 * materialises these rows as entity documents, but the event is deliberately about
 * the CATALOGUE fact, not about search: a cache, a feed or an ERP bridge may listen
 * with equal right.
 *
 * WHY ITEMS ARE NOT IN THIS VOCABULARY: item changes already travel through the
 * projection pipeline (`ProjectionManager`), which owns per-item fan-out, batching and
 * the reconciliation bookkeeping a rebuild needs. Auxiliary entities deliberately
 * bypass that machinery (their row counts are tiny and their consumers re-project
 * whole rows), so they get the lighter signal instead. Sending items through here
 * would create a second, weaker path for a concern that already has a strong one.
 *
 * This is a SIGNAL, not a job: synchronous, no retry, no ordering guarantee; whether
 * to act immediately, queue, or ignore is the listener's decision.
 *
 * @since  1.9.0
 */
class CatalogEntityChangedEvent extends AbstractEvent
{
    /**
     * What kind of catalogue entity the ids belong to.
     *
     * The values are the {@see \Alfa\Component\Alfa\Administrator\Service\Search\SearchEntity}
     * backed values (`category` | `manufacturer`), the one entity vocabulary, but the
     * event deliberately carries the STRING: a listener that predates a future entity
     * kind must be able to read (and ignore) it without an enum case existing.
     *
     * @return string e.g. "category".
     *
     * @since  1.9.0
     */
    public function entity(): string
    {
        return (string) ($this->arguments['entity'] ?? '');
    }

    /**
     * The changed row ids, in the entity's own table domain.
     *
     * @return int[]
     *
     * @since  1.9.0
     */
    public function ids(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) ($this->arguments['ids'] ?? [])))));
    }

    /**
     * Whether the rows were REMOVED rather than changed.
     *
     * True only for deletion. A state change (unpublish, trash) travels as a plain
     * change: the row still exists, and a consumer re-reading it sees the state and
     * decides for itself; only a row that can no longer be read at all needs the
     * distinction.
     *
     *
     * @since  1.9.0
     */
    public function removed(): bool
    {
        return (bool) ($this->arguments['removed'] ?? false);
    }
}
