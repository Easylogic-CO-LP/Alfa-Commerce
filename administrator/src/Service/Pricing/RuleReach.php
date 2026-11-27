<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Pricing;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Catalog\NewProducts;
use Alfa\Component\Alfa\Site\Service\Pricing\ActiveDateCondition;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

/**
 * Whether a discount or a tax reaches a product, as SQL, by the rule the pricing engine applies: every part of the
 * rule's scope must match, and nothing may take the product out.
 *
 * The product side of a scope is its categories and manufacturers, and for a discount its products (a part with no
 * row, or the "every" row 0, matches every product; a product with no category is reached by nothing, as the loader
 * joins the rules through the product's categories). A discount then leaves out the products and the categories it
 * excludes, a product whose own switch takes it out of every rule, and a product that is new while the rule leaves new
 * products out. A tax has none of the four.
 *
 * The shopper side is the place, the customer groups and the customer: here the shopper is the anonymous visitor with
 * no place, the one the price index's default context and the catalogue listing price for, so a rule naming a place or
 * a customer reaches nobody and a rule naming a group reaches the guest's groups only.
 *
 * The one builder of these conditions for everything that counts or lists what a rule reaches: the price check, the
 * discount form's Reach card with its stack counts and exclusion counts, and the product list's rule filter. The
 * engine's loader matches the scope by joins of its own (it also returns the matched place and prices a product not
 * saved yet) and reads the exclusions and the new-products choice from the same builders, excludedSql() and
 * NewProducts::reachedByRuleSql().
 *
 * The product's own switch is the product's: a variant set in the Studio to take the rules is priced with them while
 * its product is not, and is not counted here.
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
     * The scope tables of each kind, with the column that names the rule in them. Only a discount names products.
     *
     * @var array<string, array{categories: string, manufacturers: string, items: string|null, places: string, groups: string, users: string, key: string}>
     *
     * @since  2.3.0
     */
    private const SCOPES = [
        self::DISCOUNT => [
            'categories' => '#__alfa_discount_categories',
            'manufacturers' => '#__alfa_discount_manufacturers',
            'items' => '#__alfa_discount_items',
            'places' => '#__alfa_discount_places',
            'groups' => '#__alfa_discount_usergroups',
            'users' => '#__alfa_discount_users',
            'key' => 'discount_id',
        ],
        self::TAX => [
            'categories' => '#__alfa_tax_categories',
            'manufacturers' => '#__alfa_tax_manufacturers',
            'items' => null,
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
     * @param string|null $now The moment a product is judged new at (UTC); the engine's clock when omitted.
     *
     * @return string A parenthesised condition.
     *
     * @throws InvalidArgumentException For a kind that is neither.
     *
     * @since  2.3.0
     */
    public static function sql(string $kind, string $ruleId, string $itemId, ?array $groupIds = null, ?string $now = null): string
    {
        $scopes = self::scopes(kind: $kind);
        $groups = implode(',', array_map('intval', $groupIds ?? self::guestGroups())) ?: '0';

        return '(' . self::productSide(kind: $kind, ruleId: $ruleId, itemId: $itemId, now: $now)
            . ' AND ' . self::scope(scopes: $scopes, table: $scopes['places'], column: 'place_id', ruleId: $ruleId, in: null)
            . ' AND ' . self::scope(scopes: $scopes, table: $scopes['groups'], column: 'usergroup_id', ruleId: $ruleId, in: $groups)
            . ' AND ' . self::scope(scopes: $scopes, table: $scopes['users'], column: 'user_id', ruleId: $ruleId, in: null) . ')';
    }

    /**
     * The product side alone, for any shopper: the rule's scope takes the product in and nothing takes it out. What
     * a rule's reach over the catalogue is counted and listed by.
     *
     * @param string $kind DISCOUNT or TAX.
     * @param string $ruleId The rule's id as SQL.
     * @param string $itemId The product's id as SQL.
     * @param string|null $now The moment a product is judged new at (UTC); the engine's clock when omitted.
     *
     * @return string A parenthesised condition.
     *
     * @throws InvalidArgumentException For a kind that is neither.
     *
     * @since  2.3.0
     */
    public static function productSql(string $kind, string $ruleId, string $itemId, ?string $now = null): string
    {
        return '(' . self::productSide(kind: $kind, ruleId: $ruleId, itemId: $itemId, now: $now) . ')';
    }

    /**
     * What the rule's scope takes in, before anything is left out: the product has a category, and the rule's
     * categories, manufacturers and (a discount's) products match it. The products a discount excludes, or that are
     * excluded on their own, are counted against this.
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
    public static function scopeSql(string $kind, string $ruleId, string $itemId): string
    {
        return '(' . self::scopeSide(scopes: self::scopes(kind: $kind), ruleId: $ruleId, itemId: $itemId) . ')';
    }

    /**
     * The condition that a discount rule's own exclusions take a product out: the rule names the product, or a
     * category the product is in, under Except.
     *
     * @param string $ruleId The rule's id as SQL.
     * @param string $itemId The product's id as SQL.
     * @param string|null $categoryLinks The product-to-category links as SQL (a table or a derived table of its
     *                                   shape); the link table when omitted.
     *
     * @return string A parenthesised condition.
     *
     * @since  2.3.0
     */
    public static function excludedSql(string $ruleId, string $itemId, ?string $categoryLinks = null): string
    {
        $db = self::db();
        $categoryLinks ??= $db->quoteName('#__alfa_items_categories');

        return '(EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_discount_excluded_items', 'xi')
            . ' WHERE ' . $db->quoteName('xi.discount_id') . ' = ' . $ruleId . ' AND ' . $db->quoteName('xi.item_id') . ' = ' . $itemId . ')'
            . ' OR EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_discount_excluded_categories', 'xc')
            . ' INNER JOIN ' . $categoryLinks . ' AS ' . $db->quoteName('xic') . ' ON ' . $db->quoteName('xic.item_id') . ' = ' . $itemId
            . ' WHERE ' . $db->quoteName('xc.discount_id') . ' = ' . $ruleId . ' AND ' . $db->quoteName('xc.category_id') . ' = ' . $db->quoteName('xic.category_id') . '))';
    }

    /**
     * The condition that a product's own switch takes it out of every discount rule.
     *
     * @param string $itemId The product's id as SQL.
     *
     * @return string A parenthesised condition.
     *
     * @since  2.3.0
     */
    public static function switchedOffSql(string $itemId): string
    {
        $db = self::db();

        return '(EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_items', 'sw') . ' WHERE ' . $db->quoteName('sw.id') . ' = ' . $itemId
            . ' AND ' . $db->quoteName('sw.discount_rules') . ' = 0))';
    }

    /**
     * The scope tables of a kind.
     *
     * @param string $kind DISCOUNT or TAX.
     *
     * @return array{categories: string, manufacturers: string, items: string|null, places: string, groups: string, users: string, key: string}
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
     * The product side: the scope takes the product in, and for a discount nothing takes it out: not the rule's
     * exclusions, not the product's own switch, not the rule's new-products choice at the moment.
     *
     * @param string $kind DISCOUNT or TAX.
     * @param string $ruleId The rule's id as SQL.
     * @param string $itemId The product's id as SQL.
     * @param string|null $now The moment a product is judged new at (UTC); the engine's clock when omitted.
     *
     * @throws InvalidArgumentException For a kind that is neither.
     *
     * @since  2.3.0
     */
    private static function productSide(string $kind, string $ruleId, string $itemId, ?string $now): string
    {
        $side = self::scopeSide(scopes: self::scopes(kind: $kind), ruleId: $ruleId, itemId: $itemId);

        if ($kind !== self::DISCOUNT) {
            return $side;
        }

        $db = self::db();

        // The rule's new-products choice is read from the rule's own row beside the product's, in the engine's words.
        $notLeftOutAsNew = 'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_discounts', 'nr')
            . ' INNER JOIN ' . $db->quoteName('#__alfa_items', 'ni') . ' ON ' . $db->quoteName('ni.id') . ' = ' . $itemId
            . ' WHERE ' . $db->quoteName('nr.id') . ' = ' . $ruleId
            . ' AND ' . NewProducts::reachedByRuleSql(ruleAlias: 'nr', itemAlias: 'ni', momentSql: $db->quote($now ?? ActiveDateCondition::now())) . ')';

        return $side
            . ' AND NOT ' . self::excludedSql(ruleId: $ruleId, itemId: $itemId)
            . ' AND NOT ' . self::switchedOffSql(itemId: $itemId)
            . ' AND ' . $notLeftOutAsNew;
    }

    /**
     * What the scope takes in: the product has a category, and the rule's categories, manufacturers and (a
     * discount's) products match the product's.
     *
     * @param array{categories: string, manufacturers: string, items: string|null, key: string} $scopes The kind's tables.
     * @param string $ruleId The rule's id as SQL.
     * @param string $itemId The product's id as SQL.
     *
     * @since  2.3.0
     */
    private static function scopeSide(array $scopes, string $ruleId, string $itemId): string
    {
        $db = self::db();
        $itemCategories = 'SELECT ' . $db->quoteName('ic.category_id') . ' FROM ' . $db->quoteName('#__alfa_items_categories', 'ic')
            . ' WHERE ' . $db->quoteName('ic.item_id') . ' = ' . $itemId;
        $itemManufacturers = 'SELECT ' . $db->quoteName('im.manufacturer_id') . ' FROM ' . $db->quoteName('#__alfa_items_manufacturers', 'im')
            . ' WHERE ' . $db->quoteName('im.item_id') . ' = ' . $itemId;

        return 'EXISTS (' . $itemCategories . ')'
            . ' AND ' . self::scope(scopes: $scopes, table: $scopes['categories'], column: 'category_id', ruleId: $ruleId, in: $itemCategories)
            . ' AND ' . self::scope(scopes: $scopes, table: $scopes['manufacturers'], column: 'manufacturer_id', ruleId: $ruleId, in: $itemManufacturers)
            . ($scopes['items'] === null ? '' : ' AND ' . self::scope(scopes: $scopes, table: $scopes['items'], column: 'item_id', ruleId: $ruleId, in: $itemId));
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
