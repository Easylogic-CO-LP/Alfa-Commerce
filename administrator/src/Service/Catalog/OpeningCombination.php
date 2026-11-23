<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Alfa\Component\Alfa\Administrator\Service\Projection\IndexRebuildLock;
use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\CatalogueRebuildJob;
use Alfa\Component\Alfa\Site\Helper\ItemFieldsHelper;
use Alfa\Component\Alfa\Site\Service\Variant\CombinationResolver;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Throwable;

/**
 * The combination a product's card opens on, stored as #__alfa_items.opening_combination_id, and beside it the
 * combination whose price the card shows, #__alfa_items.price_combination_id: the opening combination when it has price
 * rows of its own, else 0 (a combination without them is priced from the product's rows). The price index keeps a row
 * for the product and for each combination with price rows of its own, and a listing reads the price combination's row
 * by key.
 *
 * The pointer is decided by the card's own rule and by no other: the product's published combinations with the stock
 * verdict ItemFieldsHelper gives each (the shared stock rule), then CombinationResolver::defaultSelection() and
 * CombinationResolver::resolve(): the default combination when it can be bought, else the first buyable one in
 * canonical order, else the default, else the first. A product without a published combination has 0.
 *
 * It is refreshed where an input of that rule changes: after every inventory kernel command (stock, reservations,
 * integrity holds, tracking, sale rules) has committed, under the product's rebuild lock and from what is committed
 * then ({@see self::afterCommand()}; a location's state owes every product to the catalogue job); by the price index
 * writer for the products it writes, under their locks (the item save with its combinations, the catalogue job); and
 * for the whole catalogue when the shop-wide stock settings are saved. The price combination is decided in the same
 * refresh; a combination's own price rows are saved with its product, whose index write refreshes it. A refresh that
 * changes either writes no price row: only the row the listing reads moves.
 *
 * The same choices in SQL ({@see self::choiceSql()}, {@see self::priceCombinationSql()}) are kept for checks only:
 * Tools compares them with the stored values, and the suites use them as an oracle. No listing reads them.
 *
 * @since  2.2.2
 */
final class OpeningCombination
{
    /** Products decided per read of their combinations. */
    private const CHUNK = 400;

    /** Pointers written per statement. */
    private const WRITE_CHUNK = 500;

    /**
     * The combination each product's card opens on now, by the card's rule.
     *
     * @param int[] $itemIds The products.
     *
     * @return array<int, int> By product id, every id given: the combination id, 0 for none.
     *
     * @since  2.2.2
     */
    public static function decide(array $itemIds): array
    {
        $itemIds = self::ids(itemIds: $itemIds);
        $out = [];

        foreach (array_chunk($itemIds, self::CHUNK) as $chunk) {
            // Read as the database holds it now: a command that changed stock in this process must be seen.
            ItemFieldsHelper::clearCache();
            $data = ItemFieldsHelper::getDataForItems($chunk);

            foreach ($chunk as $itemId) {
                $out[$itemId] = 0;
                $item = $data[$itemId] ?? null;

                if ($item === null || empty($item->combinations)) {
                    continue;
                }

                $selection = CombinationResolver::defaultSelection($item->fields ?? [], $item->combinations);
                $resolved = CombinationResolver::resolve($item->fields ?? [], $item->combinations, $selection, null);
                $out[$itemId] = (int) ($resolved->activeCombination->id ?? 0);
            }
        }

        ItemFieldsHelper::clearCache();

        return $out;
    }

    /**
     * Decide the pointers of some products, with their price combinations, and store the ones that changed.
     *
     * @param int[] $itemIds The products.
     *
     * @return int[] The products whose opening or price combination changed.
     *
     * @since  2.2.2
     */
    public static function refresh(array $itemIds): array
    {
        $itemIds = self::ids(itemIds: $itemIds);

        if ($itemIds === []) {
            return [];
        }

        $db = self::db();
        $stored = [];

        foreach (array_chunk($itemIds, self::WRITE_CHUNK) as $chunk) {
            foreach ($db->setQuery(
                'SELECT ' . implode(', ', $db->quoteName(['id', 'opening_combination_id', 'price_combination_id'])) . ' FROM ' . $db->quoteName('#__alfa_items')
                . ' WHERE ' . $db->quoteName('id') . ' IN (' . implode(',', $chunk) . ')',
            )->loadAssocList() ?: [] as $row) {
                $stored[(int) $row['id']] = [(int) $row['opening_combination_id'], (int) $row['price_combination_id']];
            }
        }

        $decided = self::decide(itemIds: array_keys($stored));
        $ownPriced = self::ownPriced(combinationIds: array_values(array_filter($decided)));
        $changed = [];

        foreach ($decided as $itemId => $pointer) {
            $price = isset($ownPriced[$pointer]) ? $pointer : 0;

            if ($stored[$itemId] !== [$pointer, $price]) {
                $changed[$itemId] = [$pointer, $price];
            }
        }

        self::store(pointers: $changed);

        return array_keys($changed);
    }

    /**
     * The combinations among some that have price rows of their own.
     *
     * @param int[] $combinationIds The combinations.
     *
     * @return array<int, true> By combination id.
     *
     * @since  2.2.2
     */
    private static function ownPriced(array $combinationIds): array
    {
        $combinationIds = self::ids(itemIds: $combinationIds);
        $out = [];

        if ($combinationIds === []) {
            return [];
        }

        $db = self::db();

        foreach (array_chunk($combinationIds, self::WRITE_CHUNK) as $chunk) {
            foreach ($db->setQuery(
                'SELECT oc.id FROM ' . $db->quoteName('#__alfa_item_combinations', 'oc') . ' WHERE oc.id IN (' . implode(',', $chunk) . ')'
                . ' AND ' . PriceIndexSyncService::ownPriceSql(combinationAlias: 'oc'),
            )->loadColumn() ?: [] as $id) {
                $out[(int) $id] = true;
            }
        }

        return $out;
    }

    /**
     * Decide the pointers a stock command's change asks for, once its transaction has committed: each product
     * under its rebuild lock, read after the grant from what is committed, the way the price index writer reads (a
     * command's own snapshot may predate a concurrent command on another combination of the same product). A product
     * whose lock is not granted, or whose refresh fails, is owed to the catalogue job, which decides it again. A change
     * of every product's verdict owes every item and enqueues the job instead: its writer decides the pointers of the
     * products it writes, in the background, under their locks.
     *
     * @param int[] $itemIds The products the command changed.
     * @param bool $all Whether the command changed every product's verdict.
     *
     * @since  2.2.2
     */
    public static function afterCommand(array $itemIds, bool $all): void
    {
        if ($all) {
            try {
                ProjectionManager::markAllForRebuild();
                CatalogueRebuildJob::enqueue();
            } catch (Throwable $e) {
                Log::add('[OpeningCombination] The opening combinations of the catalogue could not be owed after a location changed: ' . ErrorText::of($e), Log::WARNING, 'com_alfa.inventory');
            }

            return;
        }

        $itemIds = self::ids(itemIds: $itemIds);

        if ($itemIds === []) {
            return;
        }

        $lock = new IndexRebuildLock(self::db());
        $granted = [];

        try {
            $status = $lock->acquire(itemIds: $itemIds);
            $granted = array_keys($status, IndexRebuildLock::GRANTED, true);
            $owed = array_values(array_diff($itemIds, $granted));

            try {
                self::refresh(itemIds: $granted);
            } catch (Throwable $e) {
                Log::add('[OpeningCombination] The opening combination of item(s) ' . implode(', ', $granted) . ' was not decided again and is owed: ' . ErrorText::of($e), Log::WARNING, 'com_alfa.inventory');
                $owed = $itemIds;
            }

            ProjectionManager::owe(itemIds: $owed);
        } catch (Throwable $e) {
            Log::add('[OpeningCombination] The opening combination of item(s) ' . implode(', ', $itemIds) . ' could not be decided again: ' . ErrorText::of($e), Log::WARNING, 'com_alfa.inventory');
            ProjectionManager::owe(itemIds: $itemIds);
        } finally {
            $lock->release(itemIds: $granted);
        }
    }

    /**
     * Refresh every product's pointer (the shop-wide stock settings saved, a location enabled or disabled, the update).
     *
     * @return int The products whose pointer changed.
     *
     * @since  2.2.2
     */
    public static function refreshAll(): int
    {
        $db = self::db();
        $after = 0;
        $changed = 0;

        do {
            $ids = array_map('intval', $db->setQuery(
                'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__alfa_items') . ' WHERE ' . $db->quoteName('id') . ' > ' . $after
                . ' ORDER BY ' . $db->quoteName('id'),
                0,
                self::CHUNK,
            )->loadColumn() ?: []);

            if ($ids !== []) {
                $changed += \count(self::refresh(itemIds: $ids));
                $after = max($ids);
            }
        } while (\count($ids) === self::CHUNK);

        return $changed;
    }

    /**
     * The opening combination chosen in SQL: CombinationResolver's preference written as a correlated choice over the
     * product's published combinations, with the shared stock rule as each one's verdict. For checks and suites only.
     *
     * @param string $itemAlias The alias of #__alfa_items in the caller's statement.
     *
     * @return string An SQL expression: the combination id, 0 for none.
     *
     * @since  2.2.2
     */
    public static function choiceSql(string $itemAlias = 'a'): string
    {
        $db = self::db();
        $combinations = $db->quoteName('#__alfa_item_combinations');
        $buyable = (new StockVisibilityPolicy())->combinationSellableExpression(combinationAlias: 'oc', itemAlias: $itemAlias);
        // The first default in canonical order.
        $default = '(SELECT od.id FROM ' . $combinations . ' AS od WHERE od.item_id = oc.item_id AND od.state = 1 AND od.default_on <> 0'
            . ' ORDER BY COALESCE(od.ordering, 0), od.id LIMIT 1)';

        return 'COALESCE((SELECT oc.id FROM ' . $combinations . ' AS oc WHERE oc.item_id = ' . $itemAlias . '.id AND oc.state = 1'
            . ' ORDER BY CASE WHEN ' . $buyable . ' THEN (CASE WHEN oc.id = ' . $default . ' THEN 0 ELSE 1 END)'
            . ' ELSE (CASE WHEN oc.id = ' . $default . ' THEN 2 ELSE 3 END) END, COALESCE(oc.ordering, 0), oc.id LIMIT 1), 0)';
    }

    /**
     * The price combination in SQL, from the stored pointer: the opening combination when it has price rows of its own,
     * else 0. For checks and suites only.
     *
     * @param string $itemAlias The alias of #__alfa_items in the caller's statement.
     *
     * @return string An SQL expression: the combination id, 0 for the product's own row.
     *
     * @since  2.2.2
     */
    public static function priceCombinationSql(string $itemAlias = 'a'): string
    {
        $db = self::db();

        return '(CASE WHEN ' . $itemAlias . '.opening_combination_id > 0 AND EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_item_combinations') . ' AS pc'
            . ' WHERE pc.id = ' . $itemAlias . '.opening_combination_id AND ' . PriceIndexSyncService::ownPriceSql(combinationAlias: 'pc') . ')'
            . ' THEN ' . $itemAlias . '.opening_combination_id ELSE 0 END)';
    }

    /**
     * The products whose stored pointer differs from the SQL choice, or whose stored price combination differs from the
     * one the pointer gives (the Tools check).
     *
     * @param int $limit At most this many listed.
     *
     * @return array{count: int, items: int[]} How many differ, and the first of them.
     *
     * @since  2.2.2
     */
    public static function disagreements(int $limit = 20): array
    {
        $db = self::db();
        $where = ' FROM ' . $db->quoteName('#__alfa_items', 'a') . ' WHERE a.state = 1 AND (a.opening_combination_id <> ' . self::choiceSql(itemAlias: 'a')
            . ' OR a.price_combination_id <> ' . self::priceCombinationSql(itemAlias: 'a') . ')';

        return [
            'count' => (int) $db->setQuery('SELECT COUNT(*)' . $where)->loadResult(),
            'items' => array_map('intval', $db->setQuery('SELECT a.id' . $where . ' ORDER BY a.id', 0, max(1, $limit))->loadColumn() ?: []),
        ];
    }

    /**
     * Store pointers and price combinations, one statement per chunk.
     *
     * @param array<int, array{0: int, 1: int}> $pointers By product id: the opening combination, the price combination.
     *
     * @since  2.2.2
     */
    private static function store(array $pointers): void
    {
        $db = self::db();

        foreach (array_chunk($pointers, self::WRITE_CHUNK, true) as $part) {
            $opening = '';
            $price = '';

            foreach ($part as $itemId => [$pointer, $priceCombination]) {
                $opening .= ' WHEN ' . (int) $itemId . ' THEN ' . (int) $pointer;
                $price .= ' WHEN ' . (int) $itemId . ' THEN ' . (int) $priceCombination;
            }

            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__alfa_items') . ' SET ' . $db->quoteName('opening_combination_id') . ' = CASE ' . $db->quoteName('id') . $opening . ' END, '
                . $db->quoteName('price_combination_id') . ' = CASE ' . $db->quoteName('id') . $price . ' END'
                . ' WHERE ' . $db->quoteName('id') . ' IN (' . implode(',', array_map('intval', array_keys($part))) . ')',
            )->execute();
        }
    }

    /**
     * Positive ids, unique, ascending.
     *
     * @param array $itemIds The ids.
     *
     * @return int[]
     *
     * @since  2.2.2
     */
    private static function ids(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
        sort($itemIds);

        return $itemIds;
    }

    /**
     * The connection.
     *
     * @since  2.2.2
     */
    private static function db(): DatabaseDriver
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }
}
