<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use RuntimeException;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The shipping methods a product, or one of its combinations, is shipped with.
 *
 * THE ONE READER OF A UNIT'S SHIPPING LIST (#__alfa_items_shipments). A product with no row ships with every method
 * the shop offers; one with rows ships with those only. A combination with no row ships as its product does; one with
 * the wildcard row (shipment 0) ships with every method, whatever its product says; one with method rows ships with
 * those only. The method lists (AlfaHelper::getFilteredMethods()) and the reasons the checkout and the order builder
 * show (CartMethods) ask this, so a method is never offered by one and refused by another.
 *
 * A cart is offered the methods every line allows: a line's list is its variant's own when the variant has one, else
 * its product's, and a line with no list allows every method.
 *
 * @since  2.3.0
 */
final class ItemShipments
{
    /**
     * The form's choices: every method, the chosen methods only, and (a combination) as the product.
     *
     * @since  2.3.0
     */
    public const ALL = 'all';
    public const ONLY = 'only';
    public const AS_PRODUCT = 'inherit';

    /**
     * The wildcard row of a combination: every method, whatever the product says.
     *
     * @since  2.3.0
     */
    public const EVERY_METHOD = 0;

    /**
     * The kinds of list the product list filters by, and the counts are made of: a list that names some of the
     * methods asked about; one that names some of them and no other; one that holds no published method.
     *
     * The last is not the absence of a list. A product with no row has no restriction and ships with every method;
     * a product whose rows name nothing usable has a restriction and ships with none until one of its methods is
     * published or it is given another. Nothing here, and no reader, reads the second as the first.
     *
     * @since  2.3.0
     */
    public const LIST_ANY = '';
    public const LIST_ONLY = 'only';
    public const LIST_NONE = 'none';

    /**
     * The lists of a set of products, read in one query, by product id: the product's own method ids ([] for every
     * method) and, by combination id, each combination's own rows (absent for a combination with none).
     *
     * @param DatabaseInterface $db The database.
     * @param int[] $itemIds The products.
     *
     * @return array<int, object{item: int[], combinations: array<int, int[]>}>
     *
     * @since  2.3.0
     */
    public static function forItems(DatabaseInterface $db, array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['item_id', 'combination_id', 'shipment_id']))
                ->from($db->quoteName('#__alfa_items_shipments'))
                ->whereIn($db->quoteName('item_id'), $ids)
                ->order($db->quoteName(['item_id', 'combination_id', 'shipment_id'])),
        )->loadObjectList() ?: [];

        $scopes = [];

        foreach ($rows as $row) {
            $itemId = (int) $row->item_id;
            $scopes[$itemId] ??= (object) ['item' => [], 'combinations' => []];

            if ((int) $row->combination_id === 0) {
                $scopes[$itemId]->item[] = (int) $row->shipment_id;
            } else {
                $scopes[$itemId]->combinations[(int) $row->combination_id][] = (int) $row->shipment_id;
            }
        }

        return $scopes;
    }

    /**
     * The methods a unit ships with: the combination's own list when it has one (its wildcard row means every
     * method), else the product's. [] means every method.
     *
     * @param object|null $scope The product's lists ({@see self::forItems()}), null for a product with none.
     * @param int|null $combinationId The combination the unit is, null or 0 for the product itself.
     *
     * @return int[]
     *
     * @since  2.3.0
     */
    public static function allowed(?object $scope, ?int $combinationId): array
    {
        if ($scope === null) {
            return [];
        }

        if ($combinationId !== null && $combinationId > 0 && isset($scope->combinations[$combinationId])) {
            $own = array_map('intval', (array) $scope->combinations[$combinationId]);

            return \in_array(self::EVERY_METHOD, $own, true) ? [] : $own;
        }

        return array_map('intval', (array) ($scope->item ?? []));
    }

    /**
     * The lines a method does not ship: those whose list leaves it out. A line with no list allows every method.
     *
     * @param int $methodId The shipping method.
     * @param array<int|string, array{shipments?: int[]}> $lines The lines, each with its unit's allowed method ids
     *                                                           and whatever else the caller keeps on it (a name).
     *
     * @return array<int|string, array> The refused lines, their keys kept.
     *
     * @since  2.3.0
     */
    public static function refused(int $methodId, array $lines): array
    {
        $refused = [];

        foreach ($lines as $key => $line) {
            $allowed = array_map('intval', (array) ($line['shipments'] ?? []));

            if ($allowed !== [] && !\in_array($methodId, $allowed, true)) {
                $refused[$key] = $line;
            }
        }

        return $refused;
    }

    /**
     * The lines that restrict their methods at all, their keys kept.
     *
     * @param array<int|string, array{shipments?: int[]}> $lines The lines.
     *
     * @return array<int|string, array>
     *
     * @since  2.3.0
     */
    public static function restricting(array $lines): array
    {
        return array_filter($lines, static fn (array $line): bool => (array) ($line['shipments'] ?? []) !== []);
    }

    /**
     * The choice a product's stored list answers on its form.
     *
     * @param int[] $stored The product's method ids, [] for every method.
     *
     * @since  2.3.0
     */
    public static function productChoice(array $stored): string
    {
        return $stored === [] ? self::ALL : self::ONLY;
    }

    /**
     * The choice a combination's stored rows answer in the variant window.
     *
     * @param int[]|null $stored The combination's rows, null for none.
     *
     * @since  2.3.0
     */
    public static function combinationChoice(?array $stored): string
    {
        if ($stored === null || $stored === []) {
            return self::AS_PRODUCT;
        }

        return \in_array(self::EVERY_METHOD, array_map('intval', $stored), true) ? self::ALL : self::ONLY;
    }

    /**
     * The rows a posted choice stores for a unit, or null for none: every method is no row for a product and the
     * wildcard row for a combination; the chosen methods are their rows; a choice with no method chosen restricts
     * nothing; as the product is no row.
     *
     * @param string $choice ALL, ONLY or AS_PRODUCT.
     * @param mixed $ids The chosen method ids, as posted.
     * @param bool $combination Whether the unit is a combination.
     *
     * @return int[]|null
     *
     * @since  2.3.0
     */
    public static function posted(string $choice, mixed $ids, bool $combination): ?array
    {
        if ($choice === self::ONLY) {
            $chosen = array_values(array_unique(array_filter(
                array_map('intval', \is_array($ids) ? $ids : []),
                static fn (int $id): bool => $id > 0,
            )));

            if ($chosen !== []) {
                return $chosen;
            }

            $choice = $combination ? self::AS_PRODUCT : self::ALL;
        }

        if ($choice === self::ALL) {
            return $combination ? [self::EVERY_METHOD] : null;
        }

        return null;
    }

    /**
     * Replace the rows of one unit: the product's (combination 0) or one combination's.
     *
     * @param DatabaseInterface $db The database.
     * @param int $itemId The product.
     * @param int $combinationId The combination, 0 for the product itself.
     * @param int[]|null $shipmentIds The rows to hold, null or [] for none.
     *
     * @throws RuntimeException When a method the rows name does not exist; nothing is written.
     *
     * @since  2.3.0
     */
    public static function replace(DatabaseInterface $db, int $itemId, int $combinationId, ?array $shipmentIds): void
    {
        if ($itemId <= 0) {
            return;
        }

        $rows = array_values(array_unique(array_map('intval', $shipmentIds ?? [])));
        $methods = self::methodIds($rows);

        // The methods the list will name must exist, read under a shared lock held to the end of the save's
        // transaction, before any row of the list is touched: a delete of one of them locks its row first, so the
        // two never pass each other. A list is never written naming a method that is gone, and never emptied
        // because one went: nothing is saved.
        if ($methods !== []) {
            $existing = array_map('intval', $db->setQuery(
                'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__alfa_shipments')
                . ' WHERE ' . $db->quoteName('id') . ' IN (' . implode(',', $methods) . ') LOCK IN SHARE MODE',
            )->loadColumn() ?: []);
            $gone = array_values(array_diff($methods, $existing));

            if ($gone !== []) {
                throw new RuntimeException(Text::sprintf('COM_ALFA_ITEM_SHIPMENTS_METHOD_GONE', implode(', ', $gone)));
            }
        }

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__alfa_items_shipments'))
                ->where($db->quoteName('item_id') . ' = :itemId')
                ->where($db->quoteName('combination_id') . ' = :combinationId')
                ->bind(':itemId', $itemId, ParameterType::INTEGER)
                ->bind(':combinationId', $combinationId, ParameterType::INTEGER),
        )->execute();

        if ($rows === []) {
            return;
        }

        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__alfa_items_shipments'))
            ->columns($db->quoteName(['item_id', 'combination_id', 'shipment_id']));

        foreach ($rows as $shipmentId) {
            $query->values(implode(',', [$itemId, $combinationId, $shipmentId]));
        }

        $db->setQuery($query)->execute();
    }

    /**
     * Remove the rows of deleted products, their combinations' included.
     *
     * @param DatabaseInterface $db The database.
     * @param int[] $itemIds The products.
     *
     * @since  2.3.0
     */
    public static function deleteFor(DatabaseInterface $db, array $itemIds): void
    {
        $ids = array_values(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return;
        }

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__alfa_items_shipments'))
                ->whereIn($db->quoteName('item_id'), $ids),
        )->execute();
    }

    /**
     * Remove the rows of deleted combinations.
     *
     * @param DatabaseInterface $db The database.
     * @param int[] $combinationIds The combinations.
     *
     * @since  2.3.0
     */
    public static function forgetCombinations(DatabaseInterface $db, array $combinationIds): void
    {
        $ids = array_values(array_filter(array_map('intval', $combinationIds), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return;
        }

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__alfa_items_shipments'))
                ->whereIn($db->quoteName('combination_id'), $ids),
        )->execute();
    }

    /**
     * The products that name one of some shipping methods on a list of theirs, the product's own or a variant's.
     *
     * One statement on the list table's method index (idx_shipment_id), whatever the catalogue's size. Read with
     * the rows locked when asked, inside the transaction that then deletes the methods: the rows that name the
     * methods, and the gaps a new one would go in, stay locked until it ends, so a product save that adds or takes
     * away one of the methods waits for the delete, or the delete waits for the save and judges what it committed.
     *
     * @param DatabaseInterface $db The database.
     * @param int[] $shipmentIds The shipping methods, taken together.
     * @param bool $lock Whether the rows read are locked for the transaction.
     *
     * @since  2.3.0
     */
    public static function countNaming(DatabaseInterface $db, array $shipmentIds, bool $lock = false): int
    {
        $ids = self::methodIds($shipmentIds);

        if ($ids === []) {
            return 0;
        }

        return (int) $db->setQuery(
            'SELECT COUNT(DISTINCT ' . $db->quoteName('d.item_id') . ') FROM ' . $db->quoteName('#__alfa_items_shipments', 'd')
            . ' WHERE ' . self::unitSql(db: $db, shipmentIds: $ids, list: self::LIST_ANY) . ($lock ? ' FOR UPDATE' : ''),
        )->loadResult();
    }

    /**
     * The products with a list, the product's own or a variant's, that names some of these shipping methods and no
     * method outside them: the methods are, together, the only ones the list allows. Judged on the whole set at
     * once, so two methods that are each other's only alternative count when both are in it.
     *
     * One statement: the method index finds the rows of the methods, the primary key (item, combination, method)
     * answers whether the same unit holds a row outside them.
     *
     * @param DatabaseInterface $db The database.
     * @param int[] $shipmentIds The shipping methods, taken together.
     *
     * @since  2.3.0
     */
    public static function countLimitedTo(DatabaseInterface $db, array $shipmentIds): int
    {
        $ids = self::methodIds($shipmentIds);

        if ($ids === []) {
            return 0;
        }

        return (int) $db->setQuery(
            'SELECT COUNT(DISTINCT ' . $db->quoteName('d.item_id') . ') FROM ' . $db->quoteName('#__alfa_items_shipments', 'd')
            . ' WHERE ' . self::unitSql(db: $db, shipmentIds: $ids, list: self::LIST_ONLY),
        )->loadResult();
    }

    /**
     * The products with a list that names some of these shipping methods and would hold no published method once
     * they are gone or unpublished: a restriction that stands with nothing usable on it, so the product cannot be
     * shipped until one of its methods is published or it is given another. Judged on the whole set at once.
     *
     * One statement: the method index finds the rows, the primary keys of the list and of the methods answer the rest.
     *
     * @param DatabaseInterface $db The database.
     * @param int[] $shipmentIds The shipping methods leaving, taken together.
     *
     * @since  2.3.0
     */
    public static function countLeftWithoutPublished(DatabaseInterface $db, array $shipmentIds): int
    {
        $ids = self::methodIds($shipmentIds);

        if ($ids === []) {
            return 0;
        }

        return (int) $db->setQuery(
            'SELECT COUNT(DISTINCT ' . $db->quoteName('d.item_id') . ') FROM ' . $db->quoteName('#__alfa_items_shipments', 'd')
            . ' WHERE ' . self::unitSql(db: $db, shipmentIds: $ids, list: self::LIST_NONE, leaving: $ids),
        )->loadResult();
    }

    /**
     * The condition that a product has a list, its own or a variant's, of one of the kinds the product list filters
     * by: one that names some of the methods given (LIST_ANY), one that names some of them and no other (LIST_ONLY),
     * or one that holds no published method (LIST_NONE, among the lists naming the methods when some are given).
     * The words the counts above are made of, so a count and the list it links to are the same products.
     *
     * @param DatabaseInterface $db The database.
     * @param string $itemId The product's id as SQL: a quoted column (a.id).
     * @param int[] $shipmentIds The shipping methods; none for LIST_NONE alone.
     * @param string $list LIST_ANY, LIST_ONLY or LIST_NONE.
     *
     * @return string|null A condition, or null when the choice narrows nothing (no method given, and not LIST_NONE).
     *
     * @since  2.3.0
     */
    public static function productSql(DatabaseInterface $db, string $itemId, array $shipmentIds, string $list): ?string
    {
        $ids = self::methodIds($shipmentIds);
        $list = \in_array($list, [self::LIST_ONLY, self::LIST_NONE], true) ? $list : self::LIST_ANY;

        if ($ids === [] && $list !== self::LIST_NONE) {
            return null;
        }

        return 'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_items_shipments', 'd')
            . ' WHERE ' . $db->quoteName('d.item_id') . ' = ' . $itemId
            . ' AND ' . self::unitSql(db: $db, shipmentIds: $ids, list: $list) . ')';
    }

    /**
     * Remove deleted shipping methods from every product's and combination's list. Called inside the method
     * delete's transaction, after it judged that no list names these methods alone
     * ({@see self::countLimitedTo()}): a list that loses a method here keeps another, so no restricted unit becomes
     * an unrestricted one.
     *
     * @param DatabaseInterface $db The database.
     * @param int[] $shipmentIds The deleted shipping methods.
     *
     * @since  2.3.0
     */
    public static function forgetMethods(DatabaseInterface $db, array $shipmentIds): void
    {
        $ids = self::methodIds($shipmentIds);

        if ($ids === []) {
            return;
        }

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__alfa_items_shipments'))
                ->whereIn($db->quoteName('shipment_id'), $ids),
        )->execute();
    }

    /**
     * The condition on one row (alias d) of the list table that its unit's list is of a kind: it names one of the
     * methods; it names one of them and the unit holds no row outside them; or the unit holds no row that is usable
     * now, neither the "every method" row of a combination nor a published method (one of the methods leaving
     * counts as not published).
     *
     * @param DatabaseInterface $db The database.
     * @param int[] $shipmentIds The methods, cleaned; none for LIST_NONE alone.
     * @param string $list LIST_ANY, LIST_ONLY or LIST_NONE.
     * @param int[] $leaving For LIST_NONE: published methods about to leave, read as not published.
     *
     * @since  2.3.0
     */
    private static function unitSql(DatabaseInterface $db, array $shipmentIds, string $list, array $leaving = []): string
    {
        $in = implode(',', $shipmentIds);
        $names = $shipmentIds === [] ? '' : $db->quoteName('d.shipment_id') . ' IN (' . $in . ')';
        $sameUnit = $db->quoteName('k.item_id') . ' = ' . $db->quoteName('d.item_id')
            . ' AND ' . $db->quoteName('k.combination_id') . ' = ' . $db->quoteName('d.combination_id');
        $rows = $db->quoteName('#__alfa_items_shipments', 'k');

        if ($list === self::LIST_ONLY) {
            return $names . ' AND NOT EXISTS (SELECT 1 FROM ' . $rows . ' WHERE ' . $sameUnit
                . ' AND ' . $db->quoteName('k.shipment_id') . ' NOT IN (' . $in . '))';
        }

        if ($list === self::LIST_NONE) {
            $published = $db->quoteName('m.state') . ' = 1'
                . ($leaving === [] ? '' : ' AND ' . $db->quoteName('k.shipment_id') . ' NOT IN (' . implode(',', $leaving) . ')');

            return ($names === '' ? '' : $names . ' AND ') . 'NOT EXISTS (SELECT 1 FROM ' . $rows
                . ' LEFT JOIN ' . $db->quoteName('#__alfa_shipments', 'm') . ' ON ' . $db->quoteName('m.id') . ' = ' . $db->quoteName('k.shipment_id')
                . ' WHERE ' . $sameUnit
                . ' AND (' . $db->quoteName('k.shipment_id') . ' = ' . self::EVERY_METHOD . ' OR (' . $published . ')))';
        }

        return $names;
    }

    /**
     * Shipping method ids as given, cleaned: whole numbers above zero, each once.
     *
     * @param array $shipmentIds The ids as given.
     *
     * @return int[]
     *
     * @since  2.3.0
     */
    private static function methodIds(array $shipmentIds): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $shipmentIds), static fn (int $id): bool => $id > 0)));
    }
}
