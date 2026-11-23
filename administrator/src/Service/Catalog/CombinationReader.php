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
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * The combinations staff manage, as the variant window shows them: the current ones (published and unpublished;
 * archived ones on request), each with its values, price rows, stock records, overrides and names in every content
 * language. What a visitor may buy is the storefront's loader's; this reader is the administrator's and the API's.
 *
 *     $rows = CombinationReader::of(itemId: 42);                       // every current combination
 *     $one = CombinationReader::one(itemId: 42, combinationId: 305);   // one, or null
 *
 * @api READ ONLY: a product's combinations with their values, prices, stock and texts.
 *
 * @since  2.3.0
 */
final class CombinationReader
{
    /**
     * A product's combinations in their order.
     *
     * @param int $itemId The product.
     * @param bool $archived Archived combinations too.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.3.0
     */
    public static function of(int $itemId, bool $archived = false): array
    {
        return self::read(itemId: $itemId, ids: null, archived: $archived);
    }

    /**
     * One combination of a product; null when it is not one of its current combinations (archived ones on request).
     *
     * @param int $itemId The product.
     * @param int $combinationId The combination.
     * @param bool $archived An archived one too.
     *
     * @return array<string, mixed>|null
     *
     * @since  2.3.0
     */
    public static function one(int $itemId, int $combinationId, bool $archived = false): ?array
    {
        return self::read(itemId: $itemId, ids: [$combinationId], archived: $archived)[0] ?? null;
    }

    /**
     * Combinations across products, flat: by SKU (exact, letter case aside), by product, by state.
     *
     * @param string $sku A SKU, or '' for any.
     * @param int $itemId A product, or 0 for any.
     * @param int|null $state 1, 0 or 2 (archived), or null for every current one.
     * @param int $limit The rows of the page.
     * @param int $after The id of the last combination of the page before.
     *
     * @return array{rows: list<array<string, mixed>>, next: int|null}
     *
     * @since  2.3.0
     */
    public static function page(string $sku, int $itemId, ?int $state, int $limit, int $after): array
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'item_id']))
            ->from($db->quoteName('#__alfa_item_combinations'))
            ->where($db->quoteName('id') . ' > ' . max(0, $after))
            ->order($db->quoteName('id'));

        if ($sku !== '') {
            $query->where($db->quoteName('sku') . ' = ' . $db->quote($sku));
        }

        if ($itemId > 0) {
            $query->where($db->quoteName('item_id') . ' = ' . $itemId);
        }

        $query->where($state === null ? CombinationState::currentSql(column: $db->quoteName('state')) : $db->quoteName('state') . ' = ' . $state);
        $found = $db->setQuery($query, 0, $limit + 1)->loadObjectList() ?: [];
        $next = null;

        if (\count($found) > $limit) {
            array_pop($found);
            $next = (int) end($found)->id;
        }

        $rows = [];

        foreach ($found as $row) {
            $one = self::one(itemId: (int) $row->item_id, combinationId: (int) $row->id, archived: true);

            if ($one !== null) {
                $rows[] = $one;
            }
        }

        return ['rows' => $rows, 'next' => $next];
    }

    /**
     * The combinations read.
     *
     * @param list<int>|null $ids Some combinations, or null for every one.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.3.0
     */
    private static function read(int $itemId, ?array $ids, bool $archived): array
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_item_combinations'))
            ->where($db->quoteName('item_id') . ' = ' . $itemId)
            ->order($db->quoteName('ordering') . ', ' . $db->quoteName('id'));

        if (!$archived) {
            $query->where(CombinationState::currentSql(column: $db->quoteName('state')));
        }

        if ($ids !== null) {
            $query->where($db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $ids ?: [0])) . ')');
        }

        $rows = $db->setQuery($query)->loadObjectList('id') ?: [];

        if ($rows === []) {
            return [];
        }

        $combinationIds = array_map('intval', array_keys($rows));
        $in = implode(',', $combinationIds);
        $values = [];

        foreach ($db->setQuery(
            'SELECT cv.combination_id, cv.value_id, v.item_field_id, f.field_name FROM ' . $db->quoteName('#__alfa_item_combination_values', 'cv')
            . ' INNER JOIN ' . $db->quoteName('#__alfa_item_field_values', 'v') . ' ON v.id = cv.value_id'
            . ' INNER JOIN ' . $db->quoteName('#__alfa_item_fields', 'f') . ' ON f.id = v.item_field_id'
            . ' WHERE cv.combination_id IN (' . $in . ') ORDER BY f.ordering, f.id',
        )->loadObjectList() ?: [] as $value) {
            $values[(int) $value->combination_id][] = ['field_id' => (int) $value->item_field_id, 'field_name' => (string) $value->field_name,
                'value_id' => (int) $value->value_id];
        }

        $prices = [];

        foreach ($db->setQuery('SELECT * FROM ' . $db->quoteName('#__alfa_items_prices') . ' WHERE ' . $db->quoteName('combination_id')
            . ' IN (' . $in . ') ORDER BY ' . $db->quoteName('id'))->loadAssocList() ?: [] as $price) {
            $prices[(int) $price['combination_id']][] = [
                'id' => (int) $price['id'], 'mode' => (string) $price['mode'], 'operation' => $price['operation'], 'value' => (string) $price['value'],
                'override_value' => $price['override_value'], 'override_type' => (string) $price['override_type'], 'currency_id' => (int) $price['currency_id'],
                'country_id' => (int) $price['country_id'], 'usergroup_id' => (int) $price['usergroup_id'], 'user_id' => (int) $price['user_id'],
                'quantity_start' => $price['quantity_start'], 'quantity_end' => $price['quantity_end'], 'publish_up' => $price['publish_up'],
                'publish_down' => $price['publish_down'], 'state' => (int) $price['state'],
            ];
        }

        $stock = [];

        foreach ($db->setQuery('SELECT combination_id, location_id, quantity, reserved_quantity FROM ' . $db->quoteName('#__alfa_inventory_stock')
            . ' WHERE item_id = ' . $itemId . ' AND combination_id IN (' . $in . ') ORDER BY location_id')->loadObjectList() ?: [] as $record) {
            $stock[(int) $record->combination_id][] = ['location_id' => (int) $record->location_id, 'quantity' => (float) $record->quantity,
                'reserved_quantity' => (float) $record->reserved_quantity];
        }

        $names = [];

        foreach (MultilingualHelper::getLanguageTags() as $tag) {
            $suffix = MultilingualHelper::normaliseTag(tag: $tag);

            try {
                $texts = $db->setQuery('SELECT id_item_combination, name FROM ' . $db->quoteName('#__alfa_item_combinations_' . $suffix)
                    . ' WHERE id_item_combination IN (' . $in . ')')->loadObjectList() ?: [];
            } catch (Throwable) {
                // A language whose texts table was never made holds no text.
                $texts = [];
            }

            foreach ($texts as $text) {
                $names[(int) $text->id_item_combination][$suffix] = ['name' => (string) $text->name];
            }
        }

        $read = [];

        foreach ($rows as $id => $row) {
            $id = (int) $id;
            $overrides = [];

            foreach (array_keys(ItemFieldsAdminHelper::COMBINATION_OVERRIDE_CASTS) as $column) {
                if ($column !== 'manage_stock') {
                    $overrides[$column] = $row->{$column} ?? null;
                }
            }

            $read[] = [
                'id' => $id,
                'item_id' => (int) $row->item_id,
                'signature' => (string) $row->signature,
                'values' => $values[$id] ?? [],
                'sku' => (string) $row->sku,
                'gtin' => (string) $row->gtin,
                'mpn' => (string) $row->mpn,
                'state' => (int) $row->state,
                'default' => (int) $row->default_on === 1,
                'ordering' => (int) $row->ordering,
                'manage_stock' => $row->manage_stock === null ? null : (int) $row->manage_stock,
                'stock' => (float) $row->stock,
                'available_quantity' => (float) $row->available_quantity,
                'stock_by_location' => $stock[$id] ?? [],
                'prices' => $prices[$id] ?? [],
                'overrides' => $overrides,
                'translations' => $names[$id] ?? (object) [],
            ];
        }

        return $read;
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
