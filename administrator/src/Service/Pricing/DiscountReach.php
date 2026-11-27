<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Pricing;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;

/**
 * What a saved discount rule reaches, for its form's Reach card: how many published products its categories and
 * manufacturers take in, with the product list narrowed to them; how many products of its manufacturers its categories
 * leave out, when it names both; the other rules it combines with on a product, each with the count; and how many
 * products of its scope it never reaches, excluded by the rule or on their own (DiscountExclusions). Counted over
 * the catalogue's scope links, never by pricing a product: the places, customer groups and customers of a rule narrow
 * the shoppers, not the products, so they are not part of the counts.
 *
 * Two rules stack on a product when both reach it and both combine or compound (behaviour 1 or 2): a rule set to
 * "only this" replaces the rules before it and ends the chain, so it stacks with none. The published rules in their
 * period are compared, the first COMPARE_CAP of them in their order; the rest are counted as not compared.
 *
 * @since  2.3.0
 */
final class DiscountReach
{
    /**
     * The most other rules compared for stacking, in their order: one count query each.
     *
     * @since  2.3.0
     */
    public const COMPARE_CAP = 20;

    /**
     * @param DatabaseInterface $db The connection.
     *
     * @since  2.3.0
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * The reach of one saved rule.
     *
     * @param int $discountId The rule.
     *
     * @return array{id: int, published: bool, period: string, behavior: int, reaches: int, url: string, outside: int|null, outside_url: string, stacks: list<array{id: int, name: string, count: int, url: string}>, compared: int, more: int, cap: int, exclusions: array{by_rule: int, own: int}}|null
     *                                                                                                                                                                                                                                 Null when the rule does not exist.
     *
     * @since  2.3.0
     */
    public function of(int $discountId): ?array
    {
        $db = $this->db;
        $rule = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'state', 'behavior', 'publish_up', 'publish_down']))
                ->from($db->quoteName('#__alfa_discounts'))
                ->where($db->quoteName('id') . ' = ' . $discountId),
        )->loadObject();

        if ($rule === null) {
            return null;
        }

        $categories = $this->scopeIds(table: '#__alfa_discount_categories', column: 'category_id', discountId: $discountId);
        $manufacturers = $this->scopeIds(table: '#__alfa_discount_manufacturers', column: 'manufacturer_id', discountId: $discountId);
        $items = $db->quoteName('#__alfa_items', 'a');
        $published = $db->quoteName('a.state') . ' = 1';
        $own = RuleReach::productSql(kind: RuleReach::DISCOUNT, ruleId: (string) $discountId, itemId: $db->quoteName('a.id'));

        $reaches = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $items . ' WHERE ' . $published . ' AND ' . $own)->loadResult();

        // The product list narrowed to the rule's scope: the same links the count read, as that list's own filters.
        $filter = ['state' => 1];

        if ($categories !== null) {
            $filter['category'] = $categories;
        }

        if ($manufacturers !== null) {
            $filter['manufacturer'] = $manufacturers;
        }

        $list = static fn (array $filter): string => Route::_('index.php?option=com_alfa&view=items&' . http_build_query(['filter' => $filter]), false);

        // The products of its manufacturers that its categories leave out: only a rule naming both has them.
        $outside = null;
        $outsideUrl = '';

        if ($categories !== null && $manufacturers !== null) {
            $inManufacturers = $db->quoteName('im.manufacturer_id') . ' IN (' . implode(',', $manufacturers) . ')';
            $inCategories = $db->quoteName('ic.category_id') . ' IN (' . implode(',', $categories) . ')';
            $outside = (int) $db->setQuery(
                'SELECT COUNT(*) FROM ' . $items . ' WHERE ' . $published
                . ' AND EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_items_manufacturers', 'im') . ' WHERE ' . $db->quoteName('im.item_id') . ' = ' . $db->quoteName('a.id') . ' AND ' . $inManufacturers . ')'
                . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_items_categories', 'ic') . ' WHERE ' . $db->quoteName('ic.item_id') . ' = ' . $db->quoteName('a.id') . ' AND ' . $inCategories . ')',
            )->loadResult();
            $outsideUrl = $list(['state' => 1, 'manufacturer' => $manufacturers]);
        }

        $stacks = [];
        $compared = 0;
        $more = 0;

        // A rule set to "only this" stacks with none; the others are compared with every other combining rule in its period.
        if ((int) $rule->behavior !== 0) {
            $others = $this->combiningRules(except: $discountId);
            $more = max(0, \count($others) - self::COMPARE_CAP);

            foreach (\array_slice($others, 0, self::COMPARE_CAP) as $other) {
                $compared++;
                $both = RuleReach::productSql(kind: RuleReach::DISCOUNT, ruleId: (string) (int) $other->id, itemId: $db->quoteName('a.id'));
                $count = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $items . ' WHERE ' . $published . ' AND ' . $own . ' AND ' . $both)->loadResult();

                if ($count > 0) {
                    $stacks[] = [
                        'id' => (int) $other->id,
                        'name' => (string) $other->name,
                        'count' => $count,
                        // The rule opens with this form as its way back: its own edit task, as a form is reached.
                        'url' => Route::_(AlfaHelper::withReturn('index.php?option=com_alfa&task=discount.edit&id=' . (int) $other->id), false),
                    ];
                }
            }
        }

        return [
            'id' => $discountId,
            'published' => (int) $rule->state === 1,
            'period' => RulePeriod::status(publishUp: $rule->publish_up, publishDown: $rule->publish_down),
            'behavior' => (int) $rule->behavior,
            'reaches' => $reaches,
            'url' => $list($filter),
            'outside' => $outside,
            'outside_url' => $outsideUrl,
            'stacks' => $stacks,
            'compared' => $compared,
            'more' => $more,
            'cap' => self::COMPARE_CAP,
            // The products the scope takes in and the rule never reaches: excluded by the rule, and on their own.
            'exclusions' => (new DiscountExclusions(db: $db))->counts(discountId: $discountId),
        ];
    }

    /**
     * One part of a rule's product scope: the ids it names, or null when it names every one (no row, or the row 0).
     *
     * @param string $table The scope table.
     * @param string $column Its value column.
     * @param int $discountId The rule.
     *
     * @return int[]|null
     *
     * @since  2.3.0
     */
    private function scopeIds(string $table, string $column, int $discountId): ?array
    {
        $db = $this->db;
        $ids = array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName($column))
                ->from($db->quoteName($table))
                ->where($db->quoteName('discount_id') . ' = ' . $discountId),
        )->loadColumn() ?: []);

        return $ids === [] || \in_array(0, $ids, true) ? null : array_values(array_unique($ids));
    }

    /**
     * The other published rules in their period that combine or compound, in the order the engine applies them, with
     * their names in the user's language.
     *
     * @param int $except The rule itself.
     *
     * @return list<object{id: int, name: string}>
     *
     * @since  2.3.0
     */
    private function combiningRules(int $except): array
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['d.id', 'd.ordering']))
            ->from($db->quoteName('#__alfa_discounts', 'd'))
            ->where($db->quoteName('d.id') . ' <> ' . $except)
            ->where($db->quoteName('d.state') . ' = 1')
            ->where($db->quoteName('d.behavior') . ' <> 0')
            ->where($db->quoteName('d.value') . ' <> 0')
            ->where(RulePeriod::sql(status: RulePeriod::ACTIVE, startColumn: 'd.publish_up', endColumn: 'd.publish_down'))
            ->order($db->quoteName('d.ordering') . ' ASC, ' . $db->quoteName('d.id') . ' ASC');

        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'd',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_discounts',
            langPrimaryColumn: 'id_discount',
            fields:            ['name'],
        );

        return $db->setQuery($query)->loadObjectList() ?: [];
    }
}
