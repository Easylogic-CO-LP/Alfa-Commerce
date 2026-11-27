<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Pricing;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

/**
 * How many published products a saved discount rule's scope takes in and yet never reaches: the products excluded by
 * the rule itself (its excluded products and categories), and the products excluded on their own (their discount rules
 * switch off). Counted over the catalogue's links by the product side of the rule's scope, as the loader matches it:
 * its categories, its manufacturers and its products; places, customer groups and customers narrow the shoppers, not
 * the products.
 *
 * @since  2.3.0
 */
final class DiscountExclusions
{
    /**
     * @param DatabaseInterface $db The connection.
     *
     * @since  2.3.0
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * The counts of one saved rule.
     *
     * @param int $discountId The rule.
     *
     * @return array{by_rule: int, own: int}
     *
     * @since  2.3.0
     */
    public function counts(int $discountId): array
    {
        $db = $this->db;
        $item = $db->quoteName('a.id');
        $itemCategories = 'SELECT ' . $db->quoteName('ic.category_id') . ' FROM ' . $db->quoteName('#__alfa_items_categories', 'ic') . ' WHERE ' . $db->quoteName('ic.item_id') . ' = ' . $item;
        $itemManufacturers = 'SELECT ' . $db->quoteName('im.manufacturer_id') . ' FROM ' . $db->quoteName('#__alfa_items_manufacturers', 'im') . ' WHERE ' . $db->quoteName('im.item_id') . ' = ' . $item;

        // One part of the scope: no row, the "every" row 0, or a row naming one of the product's values.
        $part = static function (string $table, string $column, string $in) use ($db, $discountId): string {
            $from = ' FROM ' . $db->quoteName($table, 's') . ' WHERE ' . $db->quoteName('s.discount_id') . ' = ' . $discountId;
            $value = $db->quoteName('s.' . $column);

            return '(NOT EXISTS (SELECT 1' . $from . ') OR EXISTS (SELECT 1' . $from . ' AND (' . $value . ' = 0 OR ' . $value . ' IN (' . $in . '))))';
        };

        $scope = $db->quoteName('a.state') . ' = 1 AND EXISTS (' . $itemCategories . ')'
            . ' AND ' . $part('#__alfa_discount_categories', 'category_id', $itemCategories)
            . ' AND ' . $part('#__alfa_discount_manufacturers', 'manufacturer_id', $itemManufacturers)
            . ' AND ' . $part('#__alfa_discount_items', 'item_id', $item);

        $byRule = '(EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_discount_excluded_items', 'xi') . ' WHERE ' . $db->quoteName('xi.discount_id') . ' = ' . $discountId
            . ' AND ' . $db->quoteName('xi.item_id') . ' = ' . $item . ')'
            . ' OR EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_discount_excluded_categories', 'xc') . ' WHERE ' . $db->quoteName('xc.discount_id') . ' = ' . $discountId
            . ' AND ' . $db->quoteName('xc.category_id') . ' IN (' . $itemCategories . ')))';

        $from = ' FROM ' . $db->quoteName('#__alfa_items', 'a') . ' WHERE ' . $scope;

        return [
            'by_rule' => (int) $db->setQuery('SELECT COUNT(*)' . $from . ' AND ' . $byRule)->loadResult(),
            'own' => (int) $db->setQuery('SELECT COUNT(*)' . $from . ' AND NOT ' . $byRule . ' AND ' . $db->quoteName('a.discount_rules') . ' = 0')->loadResult(),
        ];
    }
}
