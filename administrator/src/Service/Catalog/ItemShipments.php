<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

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
     * @since  2.3.0
     */
    public static function replace(DatabaseInterface $db, int $itemId, int $combinationId, ?array $shipmentIds): void
    {
        if ($itemId <= 0) {
            return;
        }

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__alfa_items_shipments'))
                ->where($db->quoteName('item_id') . ' = :itemId')
                ->where($db->quoteName('combination_id') . ' = :combinationId')
                ->bind(':itemId', $itemId, ParameterType::INTEGER)
                ->bind(':combinationId', $combinationId, ParameterType::INTEGER),
        )->execute();

        $rows = array_values(array_unique(array_map('intval', $shipmentIds ?? [])));

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
}
