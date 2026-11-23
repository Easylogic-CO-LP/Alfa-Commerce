<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;

/**
 * The state of a unit's own price rows as one digest: every row's id and stored columns, in id order
 * ({@see ItemFieldsAdminHelper::priceRowsState()}, the digest the combination's Edit window posts). A product's own
 * rows are its item-level rows (combination_id NULL); a combination's are the rows that name it.
 *
 * Two systems that both write a product's prices read the state with the prices and send it back with the write: a
 * write whose state no longer matches the stored rows was made on prices someone else changed meanwhile, and is refused
 * ({@see self::assertUnchanged()}), so neither overwrites the other's change unseen. A write sent without a state is
 * written as sent: the last write wins.
 *
 * @since  2.3.0
 */
final class PricesState
{
    /**
     * The shape of a state as it is published and taken back: the digest's 32 lower-case hex digits.
     *
     * @since  2.3.0
     */
    public const PATTERN = '/^[0-9a-f]{32}$/D';

    /**
     * The state of each product's own price rows.
     *
     * @api READ ONLY: the prices_state of products (their item-level price rows), as the item read publishes it.
     *
     * @param int[] $itemIds The products.
     *
     * @return array<int, string> Product id => state; a product with no row has the state of no rows.
     *
     * @since  2.3.0
     */
    public static function ofItems(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));

        if ($itemIds === []) {
            return [];
        }

        $db = self::db();
        $rows = array_fill_keys($itemIds, []);

        foreach ($db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(array_merge(['item_id'], ItemFieldsAdminHelper::PRICE_ROW_COLUMNS)))
                ->from($db->quoteName('#__alfa_items_prices'))
                ->whereIn($db->quoteName('item_id'), $itemIds)
                ->where($db->quoteName('combination_id') . ' IS NULL')
                ->order($db->quoteName('id') . ' ASC'),
        )->loadAssocList() ?: [] as $row) {
            $rows[(int) $row['item_id']][] = $row;
        }

        return array_map(static fn (array $own): string => ItemFieldsAdminHelper::priceRowsState(rows: $own), $rows);
    }

    /**
     * The state of each unit's own price rows: a product's item-level rows for combination 0, a combination's rows
     * otherwise.
     *
     * @param array<int, array<int, mixed>> $units Combination ids (0 for the product itself) by product id, as keys.
     *
     * @return array<string, string> "product:combination" => state.
     *
     * @since  2.3.0
     */
    public static function ofUnits(array $units): array
    {
        $out = [];
        $products = [];
        $combinations = [];

        foreach ($units as $itemId => $unitIds) {
            foreach (array_keys($unitIds) as $combinationId) {
                if ((int) $combinationId > 0) {
                    $combinations[(int) $combinationId] = (int) $itemId;
                } else {
                    $products[] = (int) $itemId;
                }
            }
        }

        foreach (self::ofItems(itemIds: $products) as $itemId => $state) {
            $out[$itemId . ':0'] = $state;
        }

        if ($combinations !== []) {
            $rows = array_fill_keys(array_keys($combinations), []);
            $db = self::db();

            foreach ($db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(array_merge(['combination_id'], ItemFieldsAdminHelper::PRICE_ROW_COLUMNS)))
                    ->from($db->quoteName('#__alfa_items_prices'))
                    ->whereIn($db->quoteName('combination_id'), array_keys($combinations))
                    ->order($db->quoteName('id') . ' ASC'),
            )->loadAssocList() ?: [] as $row) {
                $rows[(int) $row['combination_id']][] = $row;
            }

            foreach ($rows as $combinationId => $own) {
                $out[$combinations[$combinationId] . ':' . $combinationId] = ItemFieldsAdminHelper::priceRowsState(rows: $own);
            }
        }

        return $out;
    }

    /**
     * Refuse a write of a unit's own prices made on a state that is no longer stored: the rows are read under a lock
     * the caller's transaction holds until it commits, so no other write of them can come between this check and the
     * caller's write.
     *
     * @param DatabaseDriver $db The connection, inside the write's transaction.
     * @param int $itemId The product.
     * @param string $expected The state the writer read.
     * @param int $combinationId The combination whose rows are written; 0 for the product's own rows.
     *
     * @throws PricesStateChanged When the stored rows have another state.
     *
     * @since  2.3.0
     */
    public static function assertUnchanged(DatabaseDriver $db, int $itemId, string $expected, int $combinationId = 0): void
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName(ItemFieldsAdminHelper::PRICE_ROW_COLUMNS))
            ->from($db->quoteName('#__alfa_items_prices'));

        if ($combinationId > 0) {
            $query->where($db->quoteName('combination_id') . ' = ' . $combinationId);
        } else {
            $query->where($db->quoteName('item_id') . ' = ' . $itemId)
                ->where($db->quoteName('combination_id') . ' IS NULL');
        }

        $rows = $db->setQuery($query->order($db->quoteName('id') . ' ASC') . ' FOR UPDATE')->loadAssocList() ?: [];

        if (!hash_equals(ItemFieldsAdminHelper::priceRowsState(rows: $rows), $expected)) {
            throw new PricesStateChanged(itemId: $itemId, combinationId: $combinationId);
        }
    }

    /**
     * The connection.
     *
     * @since  2.3.0
     */
    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
