<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Catalog\VisibilityChangedEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\DispatcherInterface;
use Throwable;

/**
 * Announces that a record's allowlist changed.
 *
 * A one-line call site is the point: the models that write allowlists say WHAT
 * changed and nothing else. How the signal travels, which plugin groups are loaded
 * to hear it, and what happens when nobody is listening all live here, so adding a
 * second emission point later cannot get any of it subtly different.
 *
 * Notification NEVER fails a save. A merchant editing a product must not see an
 * error because a search engine plugin threw while reacting: the catalogue is the
 * source of truth and has already been written correctly, and a stale index is a
 * recoverable condition while a lost edit is not.
 *
 * ## THE RULE THIS EXISTS TO UPHOLD
 *
 * **No catalogue state may change without an event-producing path.**
 *
 * A consumer that stores a copy of catalogue data can only be as correct as the
 * signals it receives. A mutation that writes rows and stays quiet does not fail:
 * it produces an index that keeps answering confidently and wrongly, and nothing in
 * the system contradicts it. The gap is INVISIBLE while the Indexed SQL engine is
 * the only consumer, because that engine reads live data and needs no signal at all;
 * it becomes real the day an external engine ships, at which point the missing call
 * sites are wherever they happen to be.
 *
 * The transitional rule (today)
 *
 * Any code that writes to `#__alfa_items`, `#__alfa_categories` or the four allowlist
 * pivots must call this before it is considered finished: batch actions, imports,
 * CLI tasks, API endpoints and migration scripts included, not only the edit forms.
 *
 * The target shape (where this is going)
 *
 * "Everyone remembers to call the notifier" is a convention, and conventions are
 * forgotten by the next contributor. The durable form is a catalogue service that
 * owns mutation and emits, with projections as CONSUMERS rather than as things
 * callers must remember:
 *
 *     Controller / API / import / CLI / batch
 *                      v
 *              CatalogService            <- mutates, then emits
 *                      v
 *              domain events
 *                v          v
 *      SearchProjection   cache / feeds / ERP / analytics
 *
 * Note what that reshuffles: {@see \Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager}
 * is NOT the endpoint callers should reach for; it is one subscriber among several,
 * and search is one projection of the canonical model rather than its privileged
 * consumer.
 *
 * This is not a pattern to import: {@see \Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryService}
 * already works this way in this very component: one service owns stock mutation,
 * nothing writes the tables directly, and it emits a before/after vocabulary
 * (`onInventoryReserved`, `onInventoryReleased`, `onInventoryCommitted`,
 * `onInventoryTransferred`, `onInventoryAdjusted`). The catalogue is simply behind
 * it. Follow that shape rather than inventing a second one.
 *
 * @since  1.5.0
 */
final class VisibilityNotifier
{
    /**
     * Announce a visibility change.
     *
     * @param int[] $itemIds Items whose own allowlist changed.
     * @param int[] $categoryIds Categories whose allowlist changed.
     * @param string $changeType What kind of change; see the event's constants.
     *
     *
     * @since  1.5.0
     */
    public static function changed(
        array $itemIds = [],
        array $categoryIds = [],
        string $changeType = VisibilityChangedEvent::AUDIENCE_CHANGED,
    ): void {
        $itemIds = array_values(array_filter(array_map('intval', $itemIds)));
        $categoryIds = array_values(array_filter(array_map('intval', $categoryIds)));

        if ($itemIds === [] && $categoryIds === []) {
            return;
        }

        try {
            $dispatcher = Factory::getContainer()->get(DispatcherInterface::class);

            // Both groups: search engines live in `alfa-search`, while a feature
            // package (analytics, a vendor portal) may also hold a projection.
            PluginHelper::importPlugin(type: 'alfa-search', plugin: null, autocreate: true, dispatcher: $dispatcher);
            PluginHelper::importPlugin(type: 'alfa', plugin: null, autocreate: true, dispatcher: $dispatcher);

            $dispatcher->dispatch(
                'onCatalogVisibilityChanged',
                new VisibilityChangedEvent('onCatalogVisibilityChanged', [
                    'itemIds' => $itemIds,
                    'categoryIds' => $categoryIds,
                    'changeType' => $changeType,
                ]),
            );
        } catch (Throwable $e) {
            Log::add(
                entry:    'VisibilityNotifier: listeners failed for '
                          . \count($itemIds) . ' item(s) / ' . \count($categoryIds) . ' category(ies): '
                          . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.catalog',
            );
        }
    }
}
