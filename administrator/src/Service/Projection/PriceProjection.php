<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Projection;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Throwable;

/**
 * Adapter exposing the existing {@see PriceIndexSyncService} as a projection.
 *
 * The price index predates the projection layer and carries extra,
 * pricing-specific entry points (syncByDiscount/syncByTax). This thin adapter
 * lets it sit behind the common {@see ProjectionManager} fan-out alongside the
 * item-field and search projections, so `ItemModel` calls one boundary and the
 * pricing service keeps its own richer API untouched.
 *
 * Removal too is the service's (2.2.0, deleteForItems(): a chunked, set-shaped
 * DELETE), so the index has one writer and it announces every row it removes.
 *
 * @since  2.0.0
 */
class PriceProjection implements ProjectionInterface
{
    private PriceIndexSyncService $service;

    /**
     * @param PriceIndexSyncService|null $service Injected for testing; defaults to a new instance.
     *
     * @since  2.0.0
     */
    public function __construct(?PriceIndexSyncService $service = null)
    {
        $this->service = $service ?? new PriceIndexSyncService();
    }

    /**
     * @inheritDoc
     * @since  2.0.0
     */
    public function syncItem(int $itemId): void
    {
        $this->service->syncItem($itemId);
    }

    /**
     * @inheritDoc
     * @since  2.0.0
     */
    public function syncItems(array $itemIds): void
    {
        $this->service->syncItems($itemIds);
    }

    /**
     * Rebuild the price index rows of several items and let a failure reach the caller (2.2.0, the catalogue.rebuild
     * job's path).
     *
     * @param int[] $itemIds The item primary keys.
     *
     * @throws Throwable Whatever the rebuild met.
     *
     * @since  2.2.0
     */
    public function syncItemsOrFail(array $itemIds): void
    {
        $this->service->syncItemsOrFail(itemIds: $itemIds);
    }

    /**
     * @inheritDoc
     * @since  2.0.0
     */
    public function deleteForItem(int $itemId): void
    {
        $this->deleteForItems(itemIds: [$itemId]);
    }

    /**
     * Remove the price index rows for a whole batch of items.
     *
     * The set-shaped delete {@see ProjectionManager} prefers, made by the service
     * (2.2.0): one statement per chunk of the selection, so the IN-list cannot
     * outgrow the driver's placeholder limit on a select-all trash.
     *
     * @param int[] $itemIds The item primary keys.
     *
     * @since  2.0.0
     */
    public function deleteForItems(array $itemIds): void
    {
        // The index has one writer (2.2.0): the removal is the service's, which announces the rows it removes
        // (onAlfaPricesChanged).
        $this->service->deleteForItems(itemIds: $itemIds);
    }
}
