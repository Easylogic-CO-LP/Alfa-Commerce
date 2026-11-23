<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * The stock records as the inventory service keeps them, one a unit and location: the quantity on hand, the quantity
 * open orders reserve, and what is left to sell there. Read a page at a time by the record's id.
 *
 *     $page = StockRecords::page(itemId: 42, combinationId: 304, locationId: 0, limit: 100, after: 0);
 *
 * @api READ ONLY: the stock records of units and locations.
 *
 * @since  2.3.0
 */
final class StockRecords
{
    /**
     * The rows a page holds when the caller names none, and the most it takes.
     *
     * @since  2.3.0
     */
    public const PAGE_DEFAULT = 100;

    /**
     * @since  2.3.0
     */
    public const PAGE_MAX = 1000;

    /**
     * One page of records in id order.
     *
     * @param int $itemId A product's records only; 0 for every product.
     * @param int|null $combinationId A combination's records only (0 the product's own); null for every unit.
     * @param int $locationId One location's records only; 0 for every location.
     * @param int $limit The rows of the page.
     * @param int $after The id of the last record of the page before; 0 for the first page.
     *
     * @return array{rows: list<object>, next: int|null} The records (id, item_id, combination_id, location_id,
     *                                                   quantity, reserved_quantity, available), and the key of the next
     *                                                   page, null on the last.
     *
     * @since  2.3.0
     */
    public static function page(int $itemId, ?int $combinationId, int $locationId, int $limit, int $after): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(
                ['s.id', 's.item_id', 's.combination_id', 's.location_id', 's.quantity', 's.reserved_quantity', 'l.title'],
                ['id', 'item_id', 'combination_id', 'location_id', 'quantity', 'reserved_quantity', 'location_title'],
            ))
            ->from($db->quoteName('#__alfa_inventory_stock', 's'))
            ->join('LEFT', $db->quoteName('#__alfa_inventory_locations', 'l'), $db->quoteName('l.id') . ' = ' . $db->quoteName('s.location_id'))
            ->where($db->quoteName('s.id') . ' > ' . max(0, $after))
            ->order($db->quoteName('s.id'));

        if ($itemId > 0) {
            $query->where($db->quoteName('s.item_id') . ' = ' . $itemId);
        }

        if ($combinationId !== null) {
            $query->where($db->quoteName('s.combination_id') . ' = ' . max(0, $combinationId));
        }

        if ($locationId > 0) {
            $query->where($db->quoteName('s.location_id') . ' = ' . $locationId);
        }

        $rows = $db->setQuery($query, 0, $limit + 1)->loadObjectList() ?: [];
        $next = null;

        if (\count($rows) > $limit) {
            array_pop($rows);
            $next = (int) end($rows)->id;
        }

        foreach ($rows as $row) {
            $row->available = (float) $row->quantity - (float) $row->reserved_quantity;
        }

        return ['rows' => $rows, 'next' => $next];
    }
}
