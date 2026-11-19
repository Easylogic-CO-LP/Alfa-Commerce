<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\ItemFieldsHelper;
use Alfa\Component\Alfa\Site\Service\Variant\CombinationResolver;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseDriver;

/**
 * The combination a product's card opens on, stored as #__alfa_items.opening_combination_id (2.2.2): the price index
 * keeps a row for every published combination, and a listing reads the row of this one by key.
 *
 * The pointer is decided by the card's own rule and by no other: the product's published combinations with the stock
 * verdict ItemFieldsHelper gives each (the shared stock rule), then CombinationResolver::defaultSelection() and
 * CombinationResolver::resolve(): the default combination when it can be bought, else the first buyable one in
 * canonical order, else the default, else the first. A product without a published combination has 0.
 *
 * It is refreshed where an input of that rule changes: inside every inventory kernel command (stock, reservations,
 * integrity holds, tracking, sale rules, a location's state), by the price index writer for the products it writes
 * (the item save with its combinations, the catalogue job), and for the whole catalogue when the shop-wide stock
 * settings are saved. A refresh that changes the pointer writes no price row: only the row the listing reads moves.
 *
 * The same choice in SQL ({@see self::choiceSql()}) is kept for checks only: Tools compares it with the stored pointer,
 * and the suites use it as an oracle. No listing reads it.
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
     * Decide the pointers of some products and store the ones that changed.
     *
     * @param int[] $itemIds The products.
     *
     * @return int[] The products whose pointer changed.
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
                'SELECT ' . $db->quoteName('id') . ', ' . $db->quoteName('opening_combination_id') . ' FROM ' . $db->quoteName('#__alfa_items')
                . ' WHERE ' . $db->quoteName('id') . ' IN (' . implode(',', $chunk) . ')',
            )->loadAssocList('id', 'opening_combination_id') ?: [] as $id => $pointer) {
                $stored[(int) $id] = (int) $pointer;
            }
        }

        $changed = [];

        foreach (self::decide(itemIds: array_keys($stored)) as $itemId => $pointer) {
            if ($stored[$itemId] !== $pointer) {
                $changed[$itemId] = $pointer;
            }
        }

        self::store(pointers: $changed);

        return array_keys($changed);
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
     * The products whose stored pointer differs from the SQL choice (the Tools check).
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
        $where = ' FROM ' . $db->quoteName('#__alfa_items', 'a') . ' WHERE a.state = 1 AND a.opening_combination_id <> ' . self::choiceSql(itemAlias: 'a');

        return [
            'count' => (int) $db->setQuery('SELECT COUNT(*)' . $where)->loadResult(),
            'items' => array_map('intval', $db->setQuery('SELECT a.id' . $where . ' ORDER BY a.id', 0, max(1, $limit))->loadColumn() ?: []),
        ];
    }

    /**
     * Store pointers, one statement per chunk.
     *
     * @param array<int, int> $pointers By product id.
     *
     * @since  2.2.2
     */
    private static function store(array $pointers): void
    {
        $db = self::db();

        foreach (array_chunk($pointers, self::WRITE_CHUNK, true) as $part) {
            $case = '';

            foreach ($part as $itemId => $combinationId) {
                $case .= ' WHEN ' . (int) $itemId . ' THEN ' . (int) $combinationId;
            }

            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__alfa_items') . ' SET ' . $db->quoteName('opening_combination_id') . ' = CASE ' . $db->quoteName('id') . $case . ' END'
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
