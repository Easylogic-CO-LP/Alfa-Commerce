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
 * Optional set-shaped delete
 *
 * Beyond the three declared methods a projection MAY also expose:
 *
 * ```php
 * public function deleteForItems(array $itemIds): void;
 * ```
 *
 * the delete counterpart of {@see self::syncItems()}. {@see ProjectionManager}
 * detects it and hands the whole selection over in one call, so trashing a page
 * of items costs one statement per projection instead of one per item; a
 * projection that declares only {@see self::deleteForItem()} still receives one
 * call per id and stays correct. The parameter name `$itemIds` is part of that
 * optional contract: the manager invokes it as a named argument, and a
 * projection that spells the parameter differently is driven one id at a time
 * rather than silently skipped.
 *
 * It is deliberately not declared abstract here: adding an abstract method to
 * this interface would fatal every implementer that has not been updated in the
 * same commit, including projections contributed by installed extensions.
 * Declare it on your projection and it will be used automatically.
 *
 * @since  1.3.0
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
     * @since  1.3.0
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
     * @since  1.3.0
     */
    public function syncItems(array $itemIds): void;

    /**
     * Remove every projection row for one item.
     *
     * Called when an item is unpublished, trashed, or deleted so it leaves the
     * catalog immediately without waiting for a rebuild.
     *
     * Fan-out over a selection MUST NOT be built from N calls to this method:
     * declare the optional `deleteForItems(array $itemIds): void` documented in
     * the class docblock and {@see ProjectionManager} will batch for you.
     *
     * @param int $itemId The item primary key.
     *
     * @since  1.3.0
     */
    public function deleteForItem(int $itemId): void;
}
