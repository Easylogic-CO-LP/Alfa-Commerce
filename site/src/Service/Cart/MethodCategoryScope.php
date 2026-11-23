<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Service\Catalog\CategoryPickerPolicy;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Which goods a method's category lists keep it from serving.
 *
 * THE ONE READER OF A METHOD'S CATEGORY LISTS. A method names the categories it serves only (#__alfa_payment_categories,
 * #__alfa_shipment_categories; the 0 row is every category) and a shipping method also the categories it does not
 * deliver (#__alfa_shipment_excluded_categories; no row, none). The method lists (AlfaHelper::getFilteredMethods()),
 * the reasons the checkout and the order builder show (CartMethods) and the parcels the shipments ledger writes
 * (ShipmentLedger) all ask this, so a method is never offered by one and refused by another.
 *
 * A CATEGORY COVERS ITS SUBCATEGORIES. A line is inside a listed category when one of its product's categories is that
 * category or lies anywhere below it. A line is refused when none of its categories is inside the served list, or when
 * any of them is inside the excluded list: the exclusion wins.
 *
 * @since  2.2.2
 */
final class MethodCategoryScope
{
    /**
     * Every category's parent, read once per request the first time a method that lists categories is judged.
     *
     * @var array<int, int>|null
     *
     * @since  2.2.2
     */
    private static ?array $parents = null;

    /**
     * @param int[]|null $served The categories the method serves only; null when it serves every category.
     * @param int[] $excluded The categories it does not serve; empty for none.
     *
     * @since  2.2.2
     */
    private function __construct(
        private readonly ?array $served,
        private readonly array $excluded,
    ) {
    }

    /**
     * The scope of a method as the method lists read it: its categories and, for a shipping method, its excluded
     * categories, each a comma list of ids.
     *
     * @param object $method The method row (categories, excluded_categories).
     *
     * @since  2.2.2
     */
    public static function of(object $method): self
    {
        $served = self::ids((string) ($method->categories ?? ''));

        return new self(
            served:   \in_array(0, $served, true) ? null : $served,
            excluded: array_values(array_filter(self::ids((string) ($method->excluded_categories ?? '')), static fn (int $id): bool => $id > 0)),
        );
    }

    /**
     * The scope of a shipping method read from its stored lists, published or not: what the shipments ledger judges a
     * new parcel by.
     *
     * @param int $methodId The shipping method.
     *
     * @since  2.2.2
     */
    public static function ofShipment(int $methodId): self
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $read = static fn (string $table): array => array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('category_id'))
                ->from($db->quoteName($table))
                ->where($db->quoteName('shipment_id') . ' = :methodId')
                ->bind(':methodId', $methodId, ParameterType::INTEGER),
        )->loadColumn() ?: []);

        return self::of((object) [
            'categories' => implode(',', $read('#__alfa_shipment_categories')),
            'excluded_categories' => implode(',', $read('#__alfa_shipment_excluded_categories')),
        ]);
    }

    /**
     * Whether the method's lists restrict anything at all: a method that serves every category and excludes none
     * refuses no line, and its lines' categories are never read.
     *
     * @since  2.2.2
     */
    public function restricts(): bool
    {
        return $this->served !== null || $this->excluded !== [];
    }

    /**
     * The lines the method refuses, in the order given.
     *
     * @param array<int|string, array{categories?: int[]}> $lines The lines, each with its product's category ids and
     *                                                            whatever else the caller keeps on it (a name).
     *
     * @return array<int|string, array> The refused lines, their keys kept.
     *
     * @since  2.2.2
     */
    public function refused(array $lines): array
    {
        if (!$this->restricts()) {
            return [];
        }

        $refused = [];

        foreach ($lines as $key => $line) {
            $within = self::withAncestors(array_map('intval', (array) ($line['categories'] ?? [])));

            if (
                ($this->served !== null && array_intersect($within, $this->served) === [])
                || array_intersect($within, $this->excluded) !== []
            ) {
                $refused[$key] = $line;
            }
        }

        return $refused;
    }

    /**
     * The lines of an order as the reader judges them: the product's categories and the name the order froze, by
     * order line id. A line of another order is not one of them.
     *
     * @param int $orderId The order.
     * @param int[] $orderLineIds Order line ids (#__alfa_order_items.id).
     *
     * @return array<int, array{categories: int[], name: string}>
     *
     * @since  2.2.2
     */
    public static function orderLines(int $orderId, array $orderLineIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderLineIds), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['oi.id', 'oi.name', 'ic.category_id']))
                ->from($db->quoteName('#__alfa_order_items', 'oi'))
                ->join('LEFT', $db->quoteName('#__alfa_items_categories', 'ic') . ' ON ic.item_id = oi.id_item')
                ->whereIn($db->quoteName('oi.id'), $ids)
                ->where($db->quoteName('oi.id_order') . ' = :orderId')
                ->bind(':orderId', $orderId, ParameterType::INTEGER)
                ->order($db->quoteName('oi.id')),
        )->loadObjectList() ?: [];
        $lines = [];

        foreach ($rows as $row) {
            $lines[(int) $row->id] ??= ['categories' => [], 'name' => (string) $row->name];

            if ($row->category_id !== null) {
                $lines[(int) $row->id]['categories'][] = (int) $row->category_id;
            }
        }

        return $lines;
    }

    /**
     * The categories given and every category above each of them.
     *
     * @param int[] $categories Category ids.
     *
     * @return int[]
     *
     * @since  2.2.2
     */
    private static function withAncestors(array $categories): array
    {
        self::$parents ??= array_map(static fn (array $node): int => $node['parent'], CategoryPickerPolicy::tree());
        $within = [];

        foreach ($categories as $id) {
            // A parent at most once each, so a loop in the stored tree ends.
            while ($id > 0 && !isset($within[$id])) {
                $within[$id] = $id;
                $id = self::$parents[$id] ?? 0;
            }
        }

        return array_values($within);
    }

    /**
     * The integer ids of a comma list.
     *
     * @param string $list The list.
     *
     * @return int[]
     *
     * @since  2.2.2
     */
    private static function ids(string $list): array
    {
        return $list === '' ? [] : array_map('intval', explode(',', $list));
    }
}
