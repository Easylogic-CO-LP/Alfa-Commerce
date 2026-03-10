<?php

/**
 * @package    Com_Alfa
 * @subpackage Administrator.Service
 * @since      1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Service;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Throwable;

/**
 * PriceIndexSyncService — Builds and maintains the Architecture-B price index.
 *
 * COLUMN NAMING
 * -------------
 * All stored columns use the same names as the com_alfa component config fields:
 *
 *   base_price                — raw unit price, no discounts, no tax
 *   discount_amount           — money saved; 0 means no discount applies
 *   base_price_with_discounts — after discounts, before tax (B2B net/ex-VAT)
 *   tax_amount                — tax applied to the discounted price
 *   base_price_with_tax       — base + tax, NO discounts ("was" price)
 *   final_price           ★   — what visitor pays  (primary filter column)
 *   discount_percent          — % saving  (filter: save >= X%)
 *
 * NOTE: has_discount was removed. discount_amount > 0 is identical and avoids
 * the risk of two columns drifting out of sync.
 *
 * PUBLIC API
 * ----------
 *   syncItem($itemId)           — re-index one item after save/re-publish
 *   syncItems($itemIds)         — re-index a specific list of items
 *   syncByDiscount($discountId) — re-index items affected by a discount change
 *   syncByTax($taxId)           — re-index items affected by a tax change
 *   syncAll()                   — full rebuild; safe to run at any time
 *   deleteForItem($itemId)      — remove all index rows for one item
 *
 * SYNC TRIGGER MAP (complete — see model hooks for implementation)
 * ---------------------------------------------------------------
 *   ItemModel::save()            → syncItem()       (save with new prices)
 *   ItemModel::publish(state=1)  → syncItem()       (re-publish: re-add to index)
 *   ItemModel::publish(state≠1)  → deleteForItem()  (unpublish/trash: hide from filter)
 *   ItemModel::delete()          → deleteForItem()  (FK CASCADE also handles this)
 *
 *   DiscountModel::save()        → syncByDiscount() (new rule, changed value/scope)
 *   DiscountModel::publish()     → syncByDiscount() (enable/disable affects prices)
 *   DiscountModel::delete()      → syncItems()      (scope collected BEFORE delete)
 *
 *   TaxModel::save()             → syncByTax()      (new rate, changed scope)
 *   TaxModel::publish()          → syncByTax()      (enable/disable affects prices)
 *   TaxModel::delete()           → syncItems()      (scope collected BEFORE delete)
 *
 * All sync failures are non-fatal — logged as warnings, admin save never blocked.
 *
 * @since 1.0.0
 */
class PriceIndexSyncService
{
    // =========================================================================
    // Constants
    // =========================================================================

    /** Chunk size for bulk item syncs — balances memory vs DB round-trips. */
    private const CHUNK_SIZE = 50;

    // =========================================================================
    // Dependencies
    // =========================================================================

    /** @var \Joomla\Database\DatabaseInterface */
    private $db;

    /** @var PriceCalculator */
    private $calculator;

    // =========================================================================
    // Constructor
    // =========================================================================

    /**
     * @param PriceCalculator|null $calculator Injected for testing; defaults to new instance
     */
    public function __construct(?PriceCalculator $calculator = null)
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
        $this->calculator = $calculator ?? new PriceCalculator();
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Re-index a single item.
     *
     * Called from ItemModel::save() and ItemModel::publish() when an item is
     * re-published (state = 1). All associated data must already be committed.
     */
    public function syncItem(int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }

        try {
            $this->writeIndexRows([$itemId], $this->getActiveContextCombinations());
        } catch (Throwable $e) {
            $this->logWarning("syncItem({$itemId}) failed: " . $e->getMessage());
        }
    }

    /**
     * Re-index a specific list of items.
     *
     * Used by the discount and tax delete hooks: the calling model collects
     * the affected item ids BEFORE performing the delete (while the scope
     * tables still exist), then calls this method AFTER the delete so prices
     * are computed without the removed discount/tax.
     *
     * @param int[] $itemIds
     * @return int Number of index rows written
     */
    public function syncItems(array $itemIds): int
    {
        $itemIds = array_filter(array_map('intval', $itemIds));

        if (empty($itemIds)) {
            return 0;
        }

        try {
            return $this->writeIndexRows(
                array_values($itemIds),
                $this->getActiveContextCombinations(),
            );
        } catch (Throwable $e) {
            $this->logWarning('syncItems() failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Re-index all items that a discount could affect.
     *
     * If the discount has category_id = 0 (applies globally) a full syncAll()
     * is triggered. Otherwise only items in its specific categories are synced.
     *
     * Called from DiscountModel::save() and DiscountModel::publish().
     * NOT used for delete — see syncItems() and the delete hook comments.
     *
     * @return int Number of index rows written
     */
    public function syncByDiscount(int $discountId): int
    {
        if ($discountId <= 0) {
            return 0;
        }

        try {
            if ($this->discountAppliesGlobally($discountId)) {
                return $this->syncAll();
            }

            $categoryIds = $this->getDiscountCategoryIds($discountId);
            if (empty($categoryIds)) {
                return 0;
            }

            $itemIds = $this->getItemIdsInCategories($categoryIds);
            if (empty($itemIds)) {
                return 0;
            }

            return $this->writeIndexRows($itemIds, $this->getActiveContextCombinations());
        } catch (Throwable $e) {
            $this->logWarning("syncByDiscount({$discountId}) failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Re-index all items that a tax change could affect.
     *
     * If the tax has category_id = 0 (applies globally) a full syncAll() is
     * triggered. Otherwise only items in its specific categories are synced.
     *
     * Called from TaxModel::save() and TaxModel::publish().
     * NOT used for delete — see syncItems() and the delete hook comments.
     *
     * @return int Number of index rows written
     */
    public function syncByTax(int $taxId): int
    {
        if ($taxId <= 0) {
            return 0;
        }

        try {
            if ($this->taxAppliesGlobally($taxId)) {
                return $this->syncAll();
            }

            $categoryIds = $this->getTaxCategoryIds($taxId);
            if (empty($categoryIds)) {
                return 0;
            }

            $itemIds = $this->getItemIdsInCategories($categoryIds);
            if (empty($itemIds)) {
                return 0;
            }

            return $this->writeIndexRows($itemIds, $this->getActiveContextCombinations());
        } catch (Throwable $e) {
            $this->logWarning("syncByTax({$taxId}) failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Rebuild the entire price index from scratch.
     *
     * Safe to run at any time — INSERT … ON DUPLICATE KEY UPDATE is idempotent.
     * Run this once after installation and after bulk catalogue imports.
     *
     * @return int Total number of index rows written
     */
    public function syncAll(): int
    {
        try {
            return $this->writeIndexRows(
                $this->getAllPublishedItemIds(),
                $this->getActiveContextCombinations(),
            );
        } catch (Throwable $e) {
            $this->logWarning('syncAll() failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Remove all index rows for a specific item.
     *
     * Call when an item is unpublished, trashed, or soft-deleted so it
     * disappears from the filter immediately without waiting for a full rebuild.
     * Hard deletes are also handled automatically by the FK ON DELETE CASCADE.
     */
    public function deleteForItem(int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }

        try {
            $query = $this->db->getQuery(true)
                ->delete($this->db->quoteName('#__alfa_items_price_index'))
                ->where($this->db->quoteName('item_id') . ' = ' . (int) $itemId);

            $this->db->setQuery($query)->execute();
        } catch (Throwable $e) {
            $this->logWarning("deleteForItem({$itemId}) failed: " . $e->getMessage());
        }
    }

    // =========================================================================
    // Scope helpers — called by model delete hooks BEFORE the actual delete
    // =========================================================================

    /**
     * Return all item ids affected by a discount.
     *
     * Call this BEFORE deleting the discount so the scope tables still exist.
     * If the discount applies globally (category_id = 0) all published items
     * are returned.
     *
     * @return int[]
     */
    public function getItemIdsForDiscount(int $discountId): array
    {
        if ($discountId <= 0) {
            return [];
        }

        if ($this->discountAppliesGlobally($discountId)) {
            return $this->getAllPublishedItemIds();
        }

        $categoryIds = $this->getDiscountCategoryIds($discountId);
        return $this->getItemIdsInCategories($categoryIds);
    }

    /**
     * Return all item ids affected by a tax.
     *
     * Call this BEFORE deleting the tax so the scope tables still exist.
     * If the tax applies globally (category_id = 0) all published items
     * are returned.
     *
     * @return int[]
     */
    public function getItemIdsForTax(int $taxId): array
    {
        if ($taxId <= 0) {
            return [];
        }

        if ($this->taxAppliesGlobally($taxId)) {
            return $this->getAllPublishedItemIds();
        }

        $categoryIds = $this->getTaxCategoryIds($taxId);
        return $this->getItemIdsInCategories($categoryIds);
    }

    // =========================================================================
    // Core indexing
    // =========================================================================

    /**
     * Write index rows for a list of items × context combinations.
     *
     * For each (item × currency × place × usergroup) slot:
     *   1. Build a PriceContext via PriceContext::forIndex()
     *   2. Batch-compute prices with PriceCalculator at quantity = 1
     *   3. Derive all column values from the PriceResult
     *   4. Upsert via INSERT … ON DUPLICATE KEY UPDATE
     *
     * Items where base_price = 0 (no price row matched) are skipped.
     *
     * @param int[] $itemIds
     * @param array $combinations From getActiveContextCombinations()
     * @return int Total rows written
     */
    private function writeIndexRows(array $itemIds, array $combinations): int
    {
        if (empty($itemIds) || empty($combinations)) {
            return 0;
        }

        $written = 0;

        foreach (array_chunk($itemIds, self::CHUNK_SIZE) as $chunk) {
            foreach ($combinations as $combo) {
                $currencyId = (int) $combo['currency_id'];
                $placeId = (int) $combo['place_id'];
                $usergroupId = (int) $combo['usergroup_id'];

                $context = PriceContext::forIndex($currencyId, $placeId, $usergroupId);

                try {
                    $results = $this->calculator->calculate($chunk, 1, $context);
                } catch (Throwable $e) {
                    $this->logWarning(
                        "Calculation failed for currency={$currencyId} place={$placeId} "
                        . "group={$usergroupId}: " . $e->getMessage(),
                    );
                    continue;
                }

                foreach ($chunk as $itemId) {
                    $result = $results[$itemId] ?? null;
                    if ($result === null) {
                        continue;
                    }

                    // ── Map PriceResult → index columns ───────────────────────
                    // Column names match config fields exactly.

                    $basePrice = round($result->getBaseTotal()->getAmount(), 4);

                    // Skip items with no price rows — 0 would pollute the slider
                    if ($basePrice <= 0) {
                        continue;
                    }

                    $discountAmount = round($result->getSavingsTotal()->getAmount(), 4);
                    $basePriceWithDiscounts = round($result->getSubtotal()->getAmount(), 4);
                    $taxAmount = round($result->getTaxTotal()->getAmount(), 4);
                    $finalPrice = round($result->getTotal()->getAmount(), 4);

                    // base_price_with_tax = base price + the same tax rate applied
                    // to the discounted subtotal. Derived: base × (final / subtotal).
                    // Guard against zero subtotal (tax-exempt items).
                    $basePriceWithTax = $basePriceWithDiscounts > 0
                        ? round($basePrice * ($finalPrice / $basePriceWithDiscounts), 4)
                        : $basePrice;

                    // discount_percent: use PriceResult's authoritative calculation
                    // which correctly handles both before-tax and after-tax discounts
                    // via DiscountSummary. This matches what the frontend price layout displays.
                    $discountPercent = $result->getSavingsPercent();

                    $this->upsertRow(
                        $itemId,
                        $currencyId,
                        $placeId,
                        $usergroupId,
                        $basePrice,
                        $discountAmount,
                        $basePriceWithDiscounts,
                        $taxAmount,
                        $basePriceWithTax,
                        $finalPrice,
                        $discountPercent,
                    );

                    $written++;
                }
            }
        }

        return $written;
    }

    /**
     * Upsert one row into #__alfa_items_price_index.
     *
     * INSERT … ON DUPLICATE KEY UPDATE is atomic and idempotent.
     * updated_at is refreshed automatically by the column definition.
     */
    private function upsertRow(
        int $itemId,
        int $currencyId,
        int $placeId,
        int $usergroupId,
        float $basePrice,
        float $discountAmount,
        float $basePriceWithDiscounts,
        float $taxAmount,
        float $basePriceWithTax,
        float $finalPrice,
        float $discountPercent,
    ): void {
        $db = $this->db;
        $table = $db->quoteName('#__alfa_items_price_index');
        $now = $db->quote(Factory::getDate()->toSql());

        $sql = "INSERT INTO {$table}
                    (item_id, currency_id, place_id, usergroup_id,
                     base_price, discount_amount, base_price_with_discounts,
                     tax_amount, base_price_with_tax, final_price,
                     discount_percent, updated_at)
                VALUES
                    ({$itemId}, {$currencyId}, {$placeId}, {$usergroupId},
                     {$basePrice}, {$discountAmount}, {$basePriceWithDiscounts},
                     {$taxAmount}, {$basePriceWithTax}, {$finalPrice},
                     {$discountPercent}, {$now})
                ON DUPLICATE KEY UPDATE
                    base_price                = VALUES(base_price),
                    discount_amount           = VALUES(discount_amount),
                    base_price_with_discounts = VALUES(base_price_with_discounts),
                    tax_amount                = VALUES(tax_amount),
                    base_price_with_tax       = VALUES(base_price_with_tax),
                    final_price               = VALUES(final_price),
                    discount_percent          = VALUES(discount_percent),
                    updated_at                = VALUES(updated_at)";

        $db->setQuery($sql)->execute();
    }

    // =========================================================================
    // Combination discovery
    // =========================================================================

    /**
     * Build all (currency × place × usergroup) combinations worth indexing.
     *
     * Only combinations that can produce different prices are included:
     *   Currencies — distinct currency_id from items_prices + sentinel 0
     *   Usergroups — distinct values from discount_usergroups,
     *                tax_usergroups, items_prices + sentinel 0
     *   Places     — distinct values from discount_places, tax_places,
     *                and country_id from items_prices + sentinel 0
     *
     * Sentinel 0 = "any / default" in every dimension, always included.
     *
     * @return array Array of ['currency_id'=>N, 'place_id'=>N, 'usergroup_id'=>N]
     */
    private function getActiveContextCombinations(): array
    {
        $currencies = $this->getDistinctCurrencyIds();
        $usergroups = $this->getDistinctUsergroupIds();
        $places = $this->getDistinctPlaceIds();

        $combinations = [];
        foreach ($currencies as $cid) {
            foreach ($places as $pid) {
                foreach ($usergroups as $ugid) {
                    $combinations[] = [
                        'currency_id' => $cid,
                        'place_id' => $pid,
                        'usergroup_id' => $ugid,
                    ];
                }
            }
        }

        return $combinations;
    }

    /**
     * Distinct currency DB ids in items_prices, plus sentinel 0.
     *
     * @return int[]
     */
    private function getDistinctCurrencyIds(): array
    {
        $query = $this->db->getQuery(true)
            ->select('DISTINCT ' . $this->db->quoteName('currency_id'))
            ->from($this->db->quoteName('#__alfa_items_prices'))
            ->where($this->db->quoteName('state') . ' = 1');

        $this->db->setQuery($query);
        return array_unique(array_merge([0], array_map('intval', $this->db->loadColumn())));
    }

    /**
     * Distinct usergroup ids from all pricing assignment tables, plus sentinel 0.
     *
     * @return int[]
     */
    private function getDistinctUsergroupIds(): array
    {
        $db = $this->db;
        $sql = "SELECT DISTINCT usergroup_id FROM {$db->quoteName('#__alfa_discount_usergroups')}
                UNION
                SELECT DISTINCT usergroup_id FROM {$db->quoteName('#__alfa_tax_usergroups')}
                UNION
                SELECT DISTINCT usergroup_id
                FROM {$db->quoteName('#__alfa_items_prices')}
                WHERE " . $db->quoteName('state') . ' = 1';

        $db->setQuery($sql);
        return array_unique(array_merge([0], array_map('intval', $db->loadColumn())));
    }

    /**
     * Distinct place ids from all pricing assignment tables, plus sentinel 0.
     *
     * discount_places / tax_places use column 'place_id'.
     * items_prices uses 'country_id' (same semantic: a #__alfa_places id).
     *
     * @return int[]
     */
    private function getDistinctPlaceIds(): array
    {
        $db = $this->db;
        $sql = "SELECT DISTINCT place_id FROM {$db->quoteName('#__alfa_discount_places')}
                UNION
                SELECT DISTINCT place_id FROM {$db->quoteName('#__alfa_tax_places')}
                UNION
                SELECT DISTINCT country_id AS place_id
                FROM {$db->quoteName('#__alfa_items_prices')}
                WHERE " . $db->quoteName('state') . ' = 1 AND country_id > 0';

        $db->setQuery($sql);
        return array_unique(array_merge([0], array_map('intval', $db->loadColumn())));
    }

    // =========================================================================
    // Item discovery
    // =========================================================================

    /**
     * All published item ids.
     *
     * @return int[]
     */
    private function getAllPublishedItemIds(): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__alfa_items'))
            ->where($this->db->quoteName('state') . ' = 1');

        $this->db->setQuery($query);
        return array_map('intval', $this->db->loadColumn());
    }

    /**
     * Item ids belonging to any of the given category ids.
     *
     * @param int[] $categoryIds
     * @return int[]
     */
    private function getItemIdsInCategories(array $categoryIds): array
    {
        if (empty($categoryIds)) {
            return [];
        }

        $query = $this->db->getQuery(true)
            ->select('DISTINCT ' . $this->db->quoteName('item_id'))
            ->from($this->db->quoteName('#__alfa_items_categories'))
            ->whereIn($this->db->quoteName('category_id'), $categoryIds);

        $this->db->setQuery($query);
        return array_map('intval', $this->db->loadColumn());
    }

    // =========================================================================
    // Discount scope helpers
    // =========================================================================

    /**
     * True if the discount has category_id = 0 (applies to all items).
     */
    private function discountAppliesGlobally(int $discountId): bool
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__alfa_discount_categories'))
            ->where($this->db->quoteName('discount_id') . ' = ' . (int) $discountId)
            ->where($this->db->quoteName('category_id') . ' = 0');

        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Category ids assigned to a discount (excluding sentinel 0).
     *
     * @return int[]
     */
    private function getDiscountCategoryIds(int $discountId): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('category_id'))
            ->from($this->db->quoteName('#__alfa_discount_categories'))
            ->where($this->db->quoteName('discount_id') . ' = ' . (int) $discountId)
            ->where($this->db->quoteName('category_id') . ' > 0');

        return array_map('intval', $this->db->setQuery($query)->loadColumn());
    }

    // =========================================================================
    // Tax scope helpers
    // =========================================================================

    /**
     * True if the tax has category_id = 0 (applies to all items).
     */
    private function taxAppliesGlobally(int $taxId): bool
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__alfa_tax_categories'))
            ->where($this->db->quoteName('tax_id') . ' = ' . (int) $taxId)
            ->where($this->db->quoteName('category_id') . ' = 0');

        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Category ids assigned to a tax (excluding sentinel 0).
     *
     * @return int[]
     */
    private function getTaxCategoryIds(int $taxId): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('category_id'))
            ->from($this->db->quoteName('#__alfa_tax_categories'))
            ->where($this->db->quoteName('tax_id') . ' = ' . (int) $taxId)
            ->where($this->db->quoteName('category_id') . ' > 0');

        return array_map('intval', $this->db->setQuery($query)->loadColumn());
    }

    // =========================================================================
    // Logging
    // =========================================================================

    /**
     * Log a non-fatal warning to the Joomla 'com_alfa' log channel.
     */
    private function logWarning(string $message): void
    {
        Log::add('[PriceIndexSyncService] ' . $message, Log::WARNING, 'com_alfa');
    }
}
