<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Catalog\CatalogEntityChangedEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\DispatcherInterface;
use Throwable;

/**
 * Announces that auxiliary catalogue entity rows (categories, manufacturers) changed.
 *
 * THE SIBLING OF {@see VisibilityNotifier}, upholding the same rule for a different
 * band of state: **no catalogue state may change without an event-producing path.**
 * A consumer materialising these rows (the Enterprise engine's entity documents are
 * the first) is only as correct as the signals it receives; a rename that stays
 * quiet does not fail, it produces an index that answers confidently with the OLD
 * name until the next full rebuild.
 *
 * A one-line call site is the point: the mutation sites say WHAT changed and nothing
 * else. Which plugin groups are loaded to hear it and what happens when nobody
 * listens live here, so a new emission point cannot get either subtly different.
 * Today's emission points: category/manufacturer save, delete, state change, inline
 * rename, and the tools backfill. Batch reparenting deliberately does NOT emit: it
 * changes tree position, not the name/description/state a materialised copy carries.
 *
 * Notification NEVER fails a save: the catalogue is the source of truth and has
 * already been written; a stale copy is recoverable, a lost edit is not.
 *
 * @since  1.9.0
 */
final class EntityChangeNotifier
{
    /**
     * Announce changed (or removed) entity rows.
     *
     * @param string $entity Entity kind: `category` | `manufacturer` (the
     *                       SearchEntity backed values).
     * @param int[] $ids Changed row ids in the entity's own table domain.
     * @param bool $removed True when the rows were deleted (they can no longer be
     *                      re-read); false for every other change.
     *
     *
     * @since  1.9.0
     */
    public static function changed(string $entity, array $ids, bool $removed = false): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($entity === '' || $ids === []) {
            return;
        }

        try {
            $dispatcher = Factory::getContainer()->get(DispatcherInterface::class);

            // Both groups, mirroring VisibilityNotifier: search engines live in
            // `alfa-search`, while a feature package may also keep a copy.
            PluginHelper::importPlugin(type: 'alfa-search', plugin: null, autocreate: true, dispatcher: $dispatcher);
            PluginHelper::importPlugin(type: 'alfa', plugin: null, autocreate: true, dispatcher: $dispatcher);

            $dispatcher->dispatch(
                'onAlfaCatalogEntityChanged',
                new CatalogEntityChangedEvent('onAlfaCatalogEntityChanged', [
                    'entity' => $entity,
                    'ids' => $ids,
                    'removed' => $removed,
                ]),
            );
        } catch (Throwable $e) {
            Log::add(
                entry:    'EntityChangeNotifier: listeners failed for ' . \count($ids)
                          . ' ' . $entity . ' row(s): ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.catalog',
            );
        }
    }
}
