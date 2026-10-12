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
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
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
 * Removal is the one operation the adapter performs itself: the pricing service
 * exposes only a single-item delete, and a mass trash must not cost one DELETE
 * per item, so the set-shaped statement lives here.
 *
 * @since  1.3.0
 */
class PriceProjection implements ProjectionInterface
{
    /** Ids per DELETE; bounds the IN-list, matching the other catalog projections. */
    private const CHUNK_SIZE = 100;

    /** The price index table this projection maintains. */
    private const TABLE = '#__alfa_items_price_index';

    private PriceIndexSyncService $service;

    private DatabaseInterface $db;

    /**
     * @param PriceIndexSyncService|null $service Injected for testing; defaults to a new instance.
     * @param DatabaseInterface|null $db Injected for testing; defaults to the container driver.
     *
     * @since  1.3.0
     */
    public function __construct(?PriceIndexSyncService $service = null, ?DatabaseInterface $db = null)
    {
        $this->service = $service ?? new PriceIndexSyncService();
        $this->db = $db ?? Factory::getContainer()->get('DatabaseDriver');
    }

    /**
     * @inheritDoc
     * @since  1.3.0
     */
    public function syncItem(int $itemId): void
    {
        $this->service->syncItem($itemId);
    }

    /**
     * @inheritDoc
     * @since  1.3.0
     */
    public function syncItems(array $itemIds): void
    {
        $this->service->syncItems($itemIds);
    }

    /**
     * @inheritDoc
     * @since  1.3.0
     */
    public function deleteForItem(int $itemId): void
    {
        $this->deleteForItems(itemIds: [$itemId]);
    }

    /**
     * Remove the price index rows for a whole batch of items.
     *
     * The set-shaped delete {@see ProjectionManager} prefers: one statement for
     * the entire selection, chunked only so the IN-list cannot outgrow the
     * driver's placeholder limit on a select-all trash.
     *
     * @param int[] $itemIds The item primary keys.
     *
     * @since  1.5.0
     */
    public function deleteForItems(array $itemIds): void
    {
        $itemIds = array_values(
            array_unique(
                array_filter(
                    array_map('intval', $itemIds),
                    static fn (int $itemId): bool => $itemId > 0,
                ),
            ),
        );

        // An empty list would render as `IN ()`, which is a MySQL syntax error.
        if ($itemIds === []) {
            return;
        }

        // The chunk only bounds the IN-list; it is not a correctness boundary, so
        // a failed chunk must not stop the remaining ones from draining.
        foreach (array_chunk($itemIds, self::CHUNK_SIZE) as $chunk) {
            try {
                $query = $this->db->getQuery(true)
                    ->delete($this->db->quoteName(self::TABLE))
                    ->whereIn($this->db->quoteName('item_id'), $chunk, ParameterType::INTEGER);

                $this->db->setQuery($query)->execute();
            } catch (Throwable $e) {
                Log::add(
                    '[PriceProjection] deleteForItems() chunk failed: ' . $e->getMessage(),
                    Log::WARNING,
                    'com_alfa',
                );
            }
        }
    }
}
