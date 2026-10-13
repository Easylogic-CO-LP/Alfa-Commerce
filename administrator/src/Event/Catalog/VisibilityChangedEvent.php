<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Catalog;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Catalog\CatalogAccessPolicy;
use Joomla\CMS\Event\AbstractEvent;

/**
 * onCatalogVisibilityChanged: a record's allowlist changed.
 *
 * INVARIANT PROTECTED: a consumer holding a COPY of visibility can never be left
 * unaware that the original moved. Anything that materialises the catalogue's
 * audience (an external search index, an analytics projection, a cached
 * permissions table) is serving a snapshot, and a snapshot needs a signal.
 *
 * Without this seam the first such provider would hook the save events itself,
 * write its own query to work out what to refresh, and make its own assumptions
 * about inheritance. The second would do it differently. That is precisely how a
 * canonical model turns into several disagreeing ones, so the boundary exists
 * BEFORE the first provider rather than after.
 *
 *     public static function getSubscribedEvents(): array
 *     {
 *         return ['onCatalogVisibilityChanged' => 'onCatalogVisibilityChanged'];
 *     }
 *
 *     public function onCatalogVisibilityChanged(VisibilityChangedEvent $event): void
 *     {
 *         $this->reindex($event->affectedItemIds());
 *     }
 *
 * This is a SIGNAL, not a job. It is dispatched synchronously and carries no
 * scheduling, no retry and no ordering guarantee: whether to act immediately, queue,
 * or ignore is the listener's decision, and building a queue for a consumer that
 * does not exist yet would be inventing requirements.
 *
 * @since  2.0.0
 */
class VisibilityChangedEvent extends AbstractEvent
{
    /**
     * A record's allowed users / user groups were rewritten.
     *
     * @var string
     * @since  2.0.0
     */
    public const AUDIENCE_CHANGED = 'audience_changed';

    /**
     * What kind of change this was.
     *
     * Present so the event can carry more than allowlist edits later (a category
     * move, a mode switch, a merge) without a second event class and without a
     * listener having to guess from the id lists. Today there is exactly one value;
     * a listener that ignores it stays correct.
     *
     * @return string e.g. "audience_changed".
     *
     * @since  2.0.0
     */
    public function changeType(): string
    {
        return (string) ($this->arguments['changeType'] ?? self::AUDIENCE_CHANGED);
    }

    /**
     * Items whose OWN allowlist changed.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    public function itemIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) ($this->arguments['itemIds'] ?? []))));
    }

    /**
     * Categories whose allowlist changed.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    public function categoryIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) ($this->arguments['categoryIds'] ?? []))));
    }

    /**
     * Every item whose EFFECTIVE audience changed, inheritance resolved.
     *
     * Resolved by the catalogue policy, so a listener never has to know that a
     * product can inherit from its default category, or that the rule is
     * configurable. Computed on demand and memoised: a listener that only cares
     * about direct item edits pays nothing for the category fan-out, which is the
     * expensive half.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    public function affectedItemIds(): array
    {
        if (!isset($this->arguments['affectedItemIds'])) {
            $this->arguments['affectedItemIds'] = (new CatalogAccessPolicy())
                ->itemsAffectedBy($this->itemIds(), $this->categoryIds());
        }

        return (array) $this->arguments['affectedItemIds'];
    }
}
