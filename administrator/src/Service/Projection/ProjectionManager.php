<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Projection;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Catalog\CollectCatalogProjectionsEvent;
use Alfa\Component\Alfa\Administrator\Service\Search\ItemSearchProjection;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\DispatcherInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Throwable;

/**
 * Single write-path entry point that fans an item change out to every projection.
 *
 * `ItemModel` calls only `$manager->syncItem($id)` / `syncItems($ids)` /
 * `deleteForItem($id)` / `deleteForItems($ids)` (the plural forms are the batch
 * entry points and the ones a multi-row admin action must use), and it never
 * names price, item-field, or search indexes. As new projections appear
 * (stock, recommendations, analytics) they are registered here, not threaded
 * through the model.
 *
 * The manager holds no projection logic of its own; it dispatches. Each
 * projection is invoked inside its own try/catch so one failing index never
 * blocks the others, and a sync failure never blocks the admin save (mirrors
 * the original non-fatal price-sync behaviour).
 *
 * @since  1.3.0
 */
class ProjectionManager
{
    /** @var ProjectionInterface[] */
    private array $projections;

    /**
     * @param ProjectionInterface[]|null $projections Injected for testing; defaults
     *                                                to the standard catalog set.
     *
     * @since  1.3.0
     */
    public function __construct(?array $projections = null)
    {
        $this->projections = $projections ?? array_merge(
            [
                new PriceProjection(),
                new ItemFieldValueProjection(),
                new ItemSearchProjection(),
            ],
            self::collectExtensionProjections(),
        );
    }

    /**
     * Projections contributed by installed extensions.
     *
     * The seam that keeps a third-party projection on the SAME write path as the
     * built-in ones. Without it, a search or analytics package can only hook the
     * models directly and re-derive "what changed" for itself, and two packages
     * would derive it differently. Registered here, they are invoked with the same
     * ids, on the same triggers, inside the same per-projection try/catch.
     *
     * A failure to collect is logged and ignored: the catalogue's own projections
     * must keep working when a third-party package is broken.
     *
     * @return ProjectionInterface[]
     *
     * @since  1.5.0
     */
    private static function collectExtensionProjections(): array
    {
        $projections = [];

        try {
            $dispatcher = Factory::getContainer()->get(DispatcherInterface::class);

            PluginHelper::importPlugin(type: 'alfa-search', plugin: null, autocreate: true, dispatcher: $dispatcher);
            PluginHelper::importPlugin(type: 'alfa', plugin: null, autocreate: true, dispatcher: $dispatcher);

            $event = new CollectCatalogProjectionsEvent('onCollectCatalogProjections', ['projections' => []]);
            $dispatcher->dispatch($event->getName(), $event);

            foreach ($event->getProjections() as $entry) {
                if (($entry['projection'] ?? null) instanceof ProjectionInterface) {
                    $projections[] = $entry['projection'];
                }
            }
        } catch (Throwable $e) {
            Log::add(
                '[ProjectionManager] collecting extension projections failed; NO extension projections'
                . ' were registered for this request: ' . $e->getMessage(),
                Log::ERROR,
                'com_alfa',
            );
        }

        return $projections;
    }

    /**
     * Rebuild every projection for a single item (save / re-publish).
     *
     * @param int $itemId The item primary key.
     *
     * @since  1.3.0
     */
    public function syncItem(int $itemId): void
    {
        foreach ($this->projections as $projection) {
            try {
                $projection->syncItem($itemId);
            } catch (Throwable $e) {
                $this->logFailure($projection, 'syncItem(' . $itemId . ')', $e);
            }
        }
    }

    /**
     * Rebuild every projection for a batch of items (bulk fan-out).
     *
     * @param int[] $itemIds The item primary keys.
     *
     * @since  1.3.0
     */
    public function syncItems(array $itemIds): void
    {
        foreach ($this->projections as $projection) {
            try {
                $projection->syncItems($itemIds);
            } catch (Throwable $e) {
                $this->logFailure($projection, 'syncItems(' . count($itemIds) . ' items)', $e);
            }
        }
    }

    /**
     * Remove a batch of items from every projection (unpublish / trash / delete).
     *
     * The batch entry point: an admin action operating on a selection calls this
     * once with the whole set instead of looping {@see self::deleteForItem()},
     * so each projection can collapse the removal into a single statement.
     *
     * Projections that expose the optional `deleteForItems(array $itemIds)` get
     * the whole set in one call; the rest are driven one id at a time so an
     * implementer written against the older single-item contract keeps working.
     *
     * @param int[] $itemIds The item primary keys.
     *
     * @since  1.5.0
     */
    public function deleteForItems(array $itemIds): void
    {
        $itemIds = self::positiveIds(itemIds: $itemIds);

        // Nothing selected: never spin the projection list, and never let an
        // empty set reach a projection that would build `IN ()`.
        if ($itemIds === []) {
            return;
        }

        foreach ($this->projections as $projection) {
            if (!self::acceptsItemIdBatch(projection: $projection)) {
                $this->deleteOneByOne(projection: $projection, itemIds: $itemIds);

                continue;
            }

            try {
                $projection->deleteForItems(itemIds: $itemIds);
            } catch (Throwable $e) {
                $this->logFailure($projection, 'deleteForItems(' . count($itemIds) . ' items)', $e);
            }
        }
    }

    /**
     * Whether a projection can take the whole selection in one call.
     *
     * The optional `deleteForItems()` is invoked as a named argument, so the
     * parameter name is part of the contract: an implementer that spells it
     * differently would throw `Unknown named parameter`, and a caught throw here
     * means the projection silently keeps rows for deleted items. Checking the
     * signature up front sends such a projection down the one-by-one path
     * instead: slower, but its rows still go away.
     *
     * @param ProjectionInterface $projection The projection to probe.
     *
     * @return bool True when `deleteForItems(array $itemIds)` is callable as declared.
     *
     * @since  1.5.0
     */
    private static function acceptsItemIdBatch(ProjectionInterface $projection): bool
    {
        if (!is_callable([$projection, 'deleteForItems'])) {
            return false;
        }

        try {
            // Magic __call() exposes no signature to name an argument against.
            $parameters = (new ReflectionMethod($projection, 'deleteForItems'))->getParameters();
        } catch (ReflectionException) {
            return false;
        }

        return isset($parameters[0]) && $parameters[0]->getName() === 'itemIds';
    }

    /**
     * Drive a projection that only implements the single-item delete.
     *
     * Each id keeps its own try/catch so one unremovable item never costs the
     * rest of the selection its removal.
     *
     * @param ProjectionInterface $projection The projection to drive.
     * @param int[] $itemIds The item primary keys.
     *
     * @since  1.5.0
     */
    private function deleteOneByOne(ProjectionInterface $projection, array $itemIds): void
    {
        foreach ($itemIds as $itemId) {
            try {
                $projection->deleteForItem(itemId: $itemId);
            } catch (Throwable $e) {
                $this->logFailure($projection, 'deleteForItem(' . $itemId . ')', $e);
            }
        }
    }

    /**
     * Remove one item from every projection (unpublish / trash / delete).
     *
     * Convenience for callers holding a single id; the set-shaped
     * {@see self::deleteForItems()} is the primitive.
     *
     * @param int $itemId The item primary key.
     *
     * @since  1.3.0
     */
    public function deleteForItem(int $itemId): void
    {
        $this->deleteForItems(itemIds: [$itemId]);
    }

    /**
     * Reduce a raw id list to unique, positive integers.
     *
     * `array_filter()` alone keeps negatives, and a negative id can throw out of
     * a projection's own lookups and abort the rest of the batch.
     *
     * @param int[]|string[] $itemIds The raw id list.
     *
     * @return int[] The normalized ids, re-indexed from zero.
     *
     * @since  1.5.0
     */
    private static function positiveIds(array $itemIds): array
    {
        return array_values(
            array_unique(
                array_filter(
                    array_map('intval', $itemIds),
                    static fn (int $itemId): bool => $itemId > 0,
                ),
            ),
        );
    }

    /**
     * Log a non-fatal projection failure to the com_alfa channel.
     *
     * @param ProjectionInterface $projection The projection that threw.
     * @param string $operation Human-readable operation label.
     * @param Throwable $e The caught exception.
     *
     * @since  1.3.0
     */
    private function logFailure(ProjectionInterface $projection, string $operation, Throwable $e): void
    {
        $short = (new ReflectionClass($projection))->getShortName();

        Log::add(
            '[ProjectionManager] ' . $short . '::' . $operation . ' failed: ' . $e->getMessage(),
            Log::WARNING,
            'com_alfa',
        );
    }
}
