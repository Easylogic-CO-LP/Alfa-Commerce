<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * A product's related products: the products its page shows after it, in the order staff set on its Related tab.
 *
 * A link goes one way. A row of `#__alfa_items_related` from A to B shows B on A's page and nothing on B's; B shows A
 * only through a row of its own. The links are kept whatever state either product is in (a trashed, unpublished,
 * archived or scheduled product keeps its links and shows again when it is published); the storefront shows only the
 * products the visitor may see in the listing. A deleted product's rows go on both sides ({@see self::deleteFor()}).
 *
 * The save asks {@see self::refusals()} before its transaction and writes {@see self::replace()} inside it: a product
 * related to itself, a product named twice and a product that does not exist are refused, nothing saved; a product
 * deleted between the check and the write is left out by the write itself, which inserts only rows whose product
 * still exists.
 *
 * @since  2.2.2
 */
final class RelatedItems
{
    /**
     * The table.
     *
     * @since  2.2.2
     */
    public const TABLE = '#__alfa_items_related';

    /**
     * The related product ids of a product, in their order.
     *
     * @param DatabaseInterface $db The database.
     * @param int $itemId The product.
     * @param int $limit How many at most; 0 for every one.
     * @param int $offset How many to skip first.
     *
     * @return list<int>
     *
     * @since  2.2.2
     */
    public static function ids(DatabaseInterface $db, int $itemId, int $limit = 0, int $offset = 0): array
    {
        if ($itemId <= 0) {
            return [];
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName('related_item_id'))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('item_id') . ' = :item')
            ->order($db->quoteName('ordering') . ' ASC, ' . $db->quoteName('related_item_id') . ' ASC')
            ->bind(':item', $itemId, ParameterType::INTEGER);

        return array_map('intval', $db->setQuery($query, $offset, $limit)->loadColumn() ?: []);
    }

    /**
     * The related products as the product form's rows: one {item_id} per product, in their order.
     *
     * @param DatabaseInterface $db The database.
     * @param int $itemId The product.
     *
     * @return list<array{item_id: int}>
     *
     * @since  2.2.2
     */
    public static function rows(DatabaseInterface $db, int $itemId): array
    {
        return array_map(static fn (int $id): array => ['item_id' => $id], self::ids(db: $db, itemId: $itemId));
    }

    /**
     * The product ids a save carries, in their order: the form's rows ({item_id}) or plain ids. An empty row (a
     * picker left empty) is no product.
     *
     * @param mixed $posted The save's `related` value.
     *
     * @return list<int>
     *
     * @since  2.2.2
     */
    public static function posted(mixed $posted): array
    {
        $ids = [];

        foreach (\is_array($posted) ? $posted : [] as $row) {
            $id = \is_array($row) ? ($row['item_id'] ?? 0) : (\is_object($row) ? ($row->item_id ?? 0) : $row);
            $id = is_numeric($id) ? (int) $id : 0;

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Why a save may not write these links, one sentence each; empty when it may: the product itself, a product named
     * twice, a product that does not exist.
     *
     * @param DatabaseInterface $db The database.
     * @param int $itemId The product being saved; 0 for a new one (a copy among them).
     * @param list<int> $ids The related product ids, in their order.
     *
     * @return list<string>
     *
     * @since  2.2.2
     */
    public static function refusals(DatabaseInterface $db, int $itemId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $refusals = [];

        if ($itemId > 0 && \in_array($itemId, $ids, true)) {
            $refusals[] = Text::_('COM_ALFA_ITEM_RELATED_SELF');
        }

        $unique = array_values(array_unique($ids));
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__alfa_items'))
            ->whereIn($db->quoteName('id'), $unique);
        $existing = array_map('intval', $db->setQuery($query)->loadColumn() ?: []);

        foreach (array_diff($unique, $existing) as $missing) {
            $refusals[] = Text::sprintf('COM_ALFA_ITEM_RELATED_MISSING', (int) $missing);
        }

        foreach (array_keys(array_filter(array_count_values($ids), static fn (int $count): bool => $count > 1)) as $twice) {
            if (\in_array($twice, $existing, true)) {
                $refusals[] = Text::sprintf('COM_ALFA_ITEM_RELATED_TWICE', self::nameOf(db: $db, itemId: (int) $twice));
            }
        }

        return $refusals;
    }

    /**
     * Replace a product's links by these, in this order (ordering 1, 2, 3). Run inside the save's transaction. Each row
     * is written only while its product exists, so a product deleted since the check leaves no link.
     *
     * @param DatabaseInterface $db The database.
     * @param int $itemId The product.
     * @param list<int> $ids The related product ids, in their order, checked by {@see self::refusals()}.
     *
     * @since  2.2.2
     */
    public static function replace(DatabaseInterface $db, int $itemId, array $ids): void
    {
        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName(self::TABLE))
                ->where($db->quoteName('item_id') . ' = ' . (int) $itemId),
        )->execute();

        $position = 0;

        foreach (array_values(array_unique($ids)) as $id) {
            if ((int) $id === $itemId) {
                continue;
            }

            $position++;
            $db->setQuery(
                'INSERT INTO ' . $db->quoteName(self::TABLE)
                . ' (' . $db->quoteName('item_id') . ', ' . $db->quoteName('related_item_id') . ', ' . $db->quoteName('ordering') . ')'
                . ' SELECT ' . (int) $itemId . ', ' . $db->quoteName('id') . ', ' . $position
                . ' FROM ' . $db->quoteName('#__alfa_items')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $id,
            )->execute();
        }
    }

    /**
     * Remove every link of these products, on both sides: the links they hold and the links other products hold to
     * them. The item delete's own statement ({@see \Alfa\Component\Alfa\Administrator\Model\ItemModel::delete()}).
     *
     * @param DatabaseInterface $db The database.
     * @param list<int> $itemIds The deleted products.
     *
     * @since  2.2.2
     */
    public static function deleteFor(DatabaseInterface $db, array $itemIds): void
    {
        $itemIds = array_values(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0));

        if ($itemIds === []) {
            return;
        }

        $list = implode(',', $itemIds);
        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName(self::TABLE))
                ->where('(' . $db->quoteName('item_id') . ' IN (' . $list . ') OR ' . $db->quoteName('related_item_id') . ' IN (' . $list . '))'),
        )->execute();
    }

    /**
     * A product's name for staff, for a refusal that names it: its name in the staff member's language, else its SKU,
     * else its number.
     *
     * @param DatabaseInterface $db The database.
     * @param int $itemId The product.
     *
     * @since  2.2.2
     */
    public static function nameOf(DatabaseInterface $db, int $itemId): string
    {
        $name = trim(MultilingualHelper::getTranslatedValue(id: $itemId, tableName: '#__alfa_items', primaryColumnName: 'id_item', field: 'name'));

        if ($name !== '') {
            return $name;
        }

        $sku = trim((string) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('sku'))
                ->from($db->quoteName('#__alfa_items'))
                ->where($db->quoteName('id') . ' = ' . (int) $itemId),
        )->loadResult());

        return $sku !== '' ? $sku : '#' . $itemId;
    }
}
