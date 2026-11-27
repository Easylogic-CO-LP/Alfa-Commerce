<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Pricing;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

/**
 * Whether a discount or a tax reaches a product, as SQL, by the rule the pricing engine's loader applies: every part of
 * the rule's scope must match. The product side is its categories and manufacturers (a scope with no row, or the
 * "every" row 0, matches every product; a product with no category is reached by nothing, as the loader joins the rules
 * through the product's categories). The shopper side is the place, the customer groups and the customer: here the
 * shopper is the anonymous visitor with no place, the one the price index's default context and the catalogue
 * listing price for, so a rule naming a place or a customer reaches nobody and a rule naming a group reaches the guest's
 * groups only.
 *
 * Read by the price check and the discount form's Reach card; the engine itself reads the loader, never this.
 *
 * @since  2.3.0
 */
final class RuleReach
{
    /**
     * A discount rule.
     *
     * @since  2.3.0
     */
    public const DISCOUNT = 'discount';

    /**
     * A tax rule.
     *
     * @since  2.3.0
     */
    public const TAX = 'tax';

    /**
     * The scope tables of each kind, with the column that names the rule in them.
     *
     * @var array<string, array{categories: string, manufacturers: string, places: string, groups: string, users: string, key: string}>
     *
     * @since  2.3.0
     */
    private const SCOPES = [
        self::DISCOUNT => [
            'categories' => '#__alfa_discount_categories',
            'manufacturers' => '#__alfa_discount_manufacturers',
            'places' => '#__alfa_discount_places',
            'groups' => '#__alfa_discount_usergroups',
            'users' => '#__alfa_discount_users',
            'key' => 'discount_id',
        ],
        self::TAX => [
            'categories' => '#__alfa_tax_categories',
            'manufacturers' => '#__alfa_tax_manufacturers',
            'places' => '#__alfa_tax_places',
            'groups' => '#__alfa_tax_usergroups',
            'users' => '#__alfa_tax_users',
            'key' => 'tax_id',
        ],
    ];

    /**
     * The groups the anonymous visitor is priced with: the "any group" sentinel and the guest group with its parents.
     *
     * @return int[]
     *
     * @since  2.3.0
     */
    public static function guestGroups(): array
    {
        return array_values(array_unique([0, ...PriceContext::customerGroups(customer: new User())]));
    }

    /**
     * The condition that a rule reaches a product for the anonymous visitor: the product side and the shopper side.
     *
     * @param string $kind DISCOUNT or TAX.
     * @param string $ruleId The rule's id as SQL: a quoted column (d.id) or a literal.
     * @param string $itemId The product's id as SQL: a quoted column (a.id) or a literal.
     * @param int[]|null $groupIds The shopper's groups; the guest's when omitted.
     *
     * @return string A parenthesised condition.
     *
     * @throws InvalidArgumentException For a kind that is neither.
     *
     * @since  2.3.0
     */
    public static function sql(string $kind, string $ruleId, string $itemId, ?array $groupIds = null): string
    {
        $scopes = self::scopes(kind: $kind);
        $groups = implode(',', array_map('intval', $groupIds ?? self::guestGroups())) ?: '0';

        return '(' . self::productSide(scopes: $scopes, ruleId: $ruleId, itemId: $itemId)
            . ' AND ' . self::scope(scopes: $scopes, table: $scopes['places'], column: 'place_id', ruleId: $ruleId, in: null)
            . ' AND ' . self::scope(scopes: $scopes, table: $scopes['groups'], column: 'usergroup_id', ruleId: $ruleId, in: $groups)
            . ' AND ' . self::scope(scopes: $scopes, table: $scopes['users'], column: 'user_id', ruleId: $ruleId, in: null) . ')';
    }

    /**
     * The product side alone: the rule's categories and manufacturers against the product's, for any shopper. What a
     * rule's reach over the catalogue is counted by.
     *
     * @param string $kind DISCOUNT or TAX.
     * @param string $ruleId The rule's id as SQL.
     * @param string $itemId The product's id as SQL.
     *
     * @return string A parenthesised condition.
     *
     * @throws InvalidArgumentException For a kind that is neither.
     *
     * @since  2.3.0
     */
    public static function productSql(string $kind, string $ruleId, string $itemId): string
    {
        return '(' . self::productSide(scopes: self::scopes(kind: $kind), ruleId: $ruleId, itemId: $itemId) . ')';
    }

    /**
     * The scope tables of a kind.
     *
     * @param string $kind DISCOUNT or TAX.
     *
     * @return array{categories: string, manufacturers: string, places: string, groups: string, users: string, key: string}
     *
     * @throws InvalidArgumentException For a kind that is neither.
     *
     * @since  2.3.0
     */
    private static function scopes(string $kind): array
    {
        return self::SCOPES[$kind] ?? throw new InvalidArgumentException(sprintf('A rule is a %s or a %s, not "%s".', self::DISCOUNT, self::TAX, $kind));
    }

    /**
     * The product side: the product has a category, and the rule's categories and manufacturers match the product's.
     *
     * @param array{categories: string, manufacturers: string, key: string} $scopes The kind's tables.
     * @param string $ruleId The rule's id as SQL.
     * @param string $itemId The product's id as SQL.
     *
     * @since  2.3.0
     */
    private static function productSide(array $scopes, string $ruleId, string $itemId): string
    {
        $db = self::db();
        $itemCategories = 'SELECT ' . $db->quoteName('ic.category_id') . ' FROM ' . $db->quoteName('#__alfa_items_categories', 'ic')
            . ' WHERE ' . $db->quoteName('ic.item_id') . ' = ' . $itemId;
        $itemManufacturers = 'SELECT ' . $db->quoteName('im.manufacturer_id') . ' FROM ' . $db->quoteName('#__alfa_items_manufacturers', 'im')
            . ' WHERE ' . $db->quoteName('im.item_id') . ' = ' . $itemId;

        return 'EXISTS (' . $itemCategories . ')'
            . ' AND ' . self::scope(scopes: $scopes, table: $scopes['categories'], column: 'category_id', ruleId: $ruleId, in: $itemCategories)
            . ' AND ' . self::scope(scopes: $scopes, table: $scopes['manufacturers'], column: 'manufacturer_id', ruleId: $ruleId, in: $itemManufacturers);
    }

    /**
     * One part of a scope: the rule has no row of it, or a row that is the "every" row 0 or names one of the values
     * given (a list, a subquery, or nothing: then only the "every" row matches).
     *
     * @param array{key: string} $scopes The kind's tables.
     * @param string $table The part's table.
     * @param string $column The part's value column.
     * @param string $ruleId The rule's id as SQL.
     * @param string|null $in The values that match, as SQL inside IN (...); null for none besides the "every" row.
     *
     * @since  2.3.0
     */
    private static function scope(array $scopes, string $table, string $column, string $ruleId, ?string $in): string
    {
        $db = self::db();
        $own = $db->quoteName('s.' . $scopes['key']) . ' = ' . $ruleId;
        $value = $db->quoteName('s.' . $column);
        $match = $value . ' = 0' . ($in === null ? '' : ' OR ' . $value . ' IN (' . $in . ')');
        $from = ' FROM ' . $db->quoteName($table, 's') . ' WHERE ' . $own;

        return '(NOT EXISTS (SELECT 1' . $from . ') OR EXISTS (SELECT 1' . $from . ' AND (' . $match . ')))';
    }

    /**
     * The database, for quoting names.
     *
     * @since  2.3.0
     */
    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
