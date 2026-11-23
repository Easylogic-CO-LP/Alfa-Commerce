<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Projection;

defined('_JEXEC') or die;

/**
 * A read-model projection rebuilt from the catalog's source of truth on write.
 *
 * Every catalog index (price, item-field values, search document) implements
 * this single contract so the {@see ProjectionManager} can fan out to all of
 * them without `ItemModel` ever naming an individual index. Implementations
 * MUST be idempotent: running the same sync twice produces identical rows.
 *
 * Layer ownership is strict: a projection only *materializes* relationships;
 * it never enforces dynamic visibility (state / usergroup / ACL), which stays
 * the sole responsibility of the catalog query.
 *
 * @since  2.0.0
 */
interface ProjectionInterface
{
    /**
     * Rebuild the projection rows for a single item.
     *
     * Safe to run inline on a per-entity save. The item's associated data must
     * already be committed before this is called.
     *
     * @param int $itemId The item primary key.
     *
     * @since  2.0.0
     */
    public function syncItem(int $itemId): void;

    /**
     * Rebuild the projection rows for a specific list of items.
     *
     * The bulk primitive: callers route fan-out (e.g. a value rename touching
     * many items) through here so it can batch, never as
     * N synchronous {@see self::syncItem()} calls inside one request.
     *
     * @param int[] $itemIds The item primary keys.
     *
     * @since  2.0.0
     */
    public function syncItems(array $itemIds): void;

    /**
     * Remove every projection row for one item.
     *
     * Called when an item is unpublished, trashed, or deleted so it leaves the
     * catalog immediately without waiting for a rebuild.
     *
     * Fan-out over a selection MUST NOT be built from N calls to this method:
     * {@see self::deleteForItems()} takes the selection.
     *
     * @param int $itemId The item primary key.
     *
     * @since  2.0.0
     */
    public function deleteForItem(int $itemId): void;

    /**
     * Rebuild the projection rows of several items and let a failure reach the caller: the catalogue rebuild job's
     * path, which marks the items owed when it fails.
     *
     * @param int[] $itemIds The item primary keys.
     *
     * @throws \Throwable Whatever the rebuild met.
     *
     * @since  2.2.0
     */
    public function syncItemsOrFail(array $itemIds): void;

    /**
     * Remove every projection row of a selection of items in one call: the delete counterpart of
     * {@see self::syncItems()}, so trashing a page of items costs one statement per projection.
     *
     * @param int[] $itemIds The item primary keys.
     *
     * @since  2.0.0
     */
    public function deleteForItems(array $itemIds): void;
}
