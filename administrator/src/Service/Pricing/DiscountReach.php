<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Pricing;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * What a saved discount rule reaches, for its form's Reach card: how many published products it reaches today, as
 * the prices apply it (its categories, manufacturers and products take the product in, and neither the rule's
 * exclusions, nor the product's own switch, nor the rule's new-products choice leaves it out: RuleReach), with the
 * product list narrowed to exactly them; how many products of its manufacturers its categories leave out, when it
 * names both; the other rules it combines with on a product, each with the count; and how many products of its scope
 * it never reaches, excluded by the rule or on their own (DiscountExclusions). Counted over the catalogue's links,
 * never by pricing a product: the places, customer groups and customers of a rule narrow the shoppers, not the
 * products, so they are not part of the counts.
 *
 * Two rules stack on a product when both reach it and both combine or compound (behaviour 1 or 2): a rule set to
 * "only this" replaces the rules before it and ends the chain, so it stacks with none. Every other published rule in
 * its period that combines is compared, in one statement grouped by rule over the products this rule reaches.
 *
 * Asked for on demand from the form (its own action), never while the form renders, and counted from the rule as
 * last saved: changes typed on the form and not saved are not counted. The card's action first estimates the work
 * (estimate(): the products reached read with LIMIT WORK_NOW + 1, times the rules compared); within WORK_NOW pairs the
 * reach is counted in the request (of()); above it, or when the products pass the limit, the rule's job counts it in
 * pieces (DiscountReachJob: base(), then stacksWith() a batch of rules at a time) and stores it (store()), and the card
 * shows the stored reach with its moment (stored()). A save or a delete of the rule forgets what was stored.
 *
 * @since  2.3.0
 */
final class DiscountReach
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
     * The one threshold of the card's policy: product-and-rule pairs the counting statements may evaluate inside the
     * form's request; above it, or when the products cannot be counted within it, the reach is worked out by its job.
     * The work of the card is the applicability predicate evaluated once per published product the rule reaches for
     * the reach itself and once more for each other combining rule compared, so the pairs are (products) x (1 + rules
     * compared). A starting value awaiting the benchmark of these statements: they run no engine and write nothing,
     * so one pair is assumed well under the 0.8 ms per product the price writer measured on the demo; 2000 pairs are
     * assumed to answer within a few hundred milliseconds.
     *
     * @since  2.3.0
     */
    public const WORK_NOW = 2000;

    /**
     * The prefix of a rule's stored reach in #__alfa_meta: the rule's id completes it.
     *
     * @since  2.3.0
     */
    public const META_PREFIX = 'discount_reach.';

    /**
     * The cheap estimate of the card's work for a saved rule: the products it reaches, read with LIMIT WORK_NOW + 1 and
     * so never counted past the threshold, and the other combining rules in their period it would be compared with.
     *
     * @param int $discountId The rule.
     *
     * @return array{products: int, capped: bool, rules: int, pairs: int, now: bool} The products read (capped when more
     *                                                                                exist), the rules to compare, the
     *                                                                                pairs, and whether the card may
     *                                                                                count now.
     *
     * @since  2.3.0
     */
    public function estimate(int $discountId): array
    {
        $db = $this->db;
        $rule = $this->rule(discountId: $discountId);
        $own = RuleReach::productSql(kind: RuleReach::DISCOUNT, ruleId: (string) $discountId, itemId: $db->quoteName('a.id'));
        $products = \count($db->setQuery(
            'SELECT 1 FROM ' . $db->quoteName('#__alfa_items', 'a') . ' WHERE ' . $db->quoteName('a.state') . ' = 1 AND ' . $own,
            0,
            self::WORK_NOW + 1,
        )->loadColumn() ?: []);
        $capped = $products > self::WORK_NOW;
        $rules = $rule !== null && (int) $rule->behavior !== 0 ? \count($this->combiningRules(except: $discountId)) : 0;
        $pairs = $products * (1 + $rules);

        return ['products' => $products, 'capped' => $capped, 'rules' => $rules, 'pairs' => $pairs, 'now' => !$capped && $pairs <= self::WORK_NOW];
    }

    /**
     * The reach of one saved rule, counted now and whole: its base counts and its comparison with every other rule.
     *
     * @param int $discountId The rule.
     *
     * @return array|null See base(), with stacks and compared; null when the rule does not exist.
     *
     * @since  2.3.0
     */
    public function of(int $discountId): ?array
    {
        $base = $this->base(discountId: $discountId);

        if ($base === null) {
            return null;
        }

        $others = (int) $base['behavior'] !== 0 ? $this->combiningRules(except: $discountId) : [];

        return $base + [
            'stacks' => $this->stacksWith(discountId: $discountId, others: $others),
            'compared' => \count($others),
            'computed_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * The base of a saved rule's reach: how many published products it reaches, with the product list narrowed to them;
     * how many products of its manufacturers its categories leave out; and the products of its scope it never reaches
     * (excluded by the rule, or on their own).
     *
     * @param int $discountId The rule.
     *
     * @return array{id: int, published: bool, period: string, behavior: int, reaches: int, url: string, outside: int|null, outside_url: string, exclusions: array{by_rule: int, own: int}}|null
     *                                                                                                                                                                      Null when the rule does not exist.
     *
     * @since  2.3.0
     */
    public function base(int $discountId): ?array
    {
        $db = $this->db;
        $rule = $this->rule(discountId: $discountId);

        if ($rule === null) {
            return null;
        }

        $categories = $this->scopeIds(table: '#__alfa_discount_categories', column: 'category_id', discountId: $discountId);
        $manufacturers = $this->scopeIds(table: '#__alfa_discount_manufacturers', column: 'manufacturer_id', discountId: $discountId);
        $items = $db->quoteName('#__alfa_items', 'a');
        $published = $db->quoteName('a.state') . ' = 1';
        $own = RuleReach::productSql(kind: RuleReach::DISCOUNT, ruleId: (string) $discountId, itemId: $db->quoteName('a.id'));
        $reaches = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $items . ' WHERE ' . $published . ' AND ' . $own)->loadResult();

        // The product list narrowed to the published products the rule reaches: that list's rule filter reads the
        // condition the count read.
        $filter = ['state' => 1, 'discount' => $discountId];
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

        return [
            'id' => $discountId,
            'published' => (int) $rule->state === 1,
            'period' => RulePeriod::status(publishUp: $rule->publish_up, publishDown: $rule->publish_down),
            'behavior' => (int) $rule->behavior,
            'reaches' => $reaches,
            'url' => $list($filter),
            'outside' => $outside,
            'outside_url' => $outsideUrl,
            // The products the scope takes in and the rule never reaches: excluded by the rule, and on their own.
            'exclusions' => (new DiscountExclusions(db: $db))->counts(discountId: $discountId),
        ];
    }

    /**
     * The rules a saved rule stacks with, among the other rules given, each with the count of products both reach: one
     * statement grouped by rule over the products this rule reaches. A batch of the other rules at a time is the job's
     * way; every other rule at once is the card's when the estimate allows it.
     *
     * @param int $discountId The rule.
     * @param list<object{id: int, name: string}> $others The other combining rules to compare, in their order.
     *
     * @return list<array{id: int, name: string, count: int, url: string}>
     *
     * @since  2.3.0
     */
    public function stacksWith(int $discountId, array $others): array
    {
        if ($others === []) {
            return [];
        }

        $db = $this->db;
        $items = $db->quoteName('#__alfa_items', 'a');
        $published = $db->quoteName('a.state') . ' = 1';
        $own = RuleReach::productSql(kind: RuleReach::DISCOUNT, ruleId: (string) $discountId, itemId: $db->quoteName('a.id'));
        $both = RuleReach::productSql(kind: RuleReach::DISCOUNT, ruleId: $db->quoteName('d.id'), itemId: $db->quoteName('a.id'));
        $otherIds = array_map(static fn (object $other): int => (int) $other->id, $others);
        $counts = $db->setQuery(
            'SELECT ' . $db->quoteName('d.id') . ', COUNT(*) AS n FROM ' . $db->quoteName('#__alfa_discounts', 'd')
            . ' INNER JOIN ' . $items . ' ON ' . $published . ' AND ' . $own . ' AND ' . $both
            . ' WHERE ' . $db->quoteName('d.id') . ' IN (' . implode(',', $otherIds) . ')'
            . ' GROUP BY ' . $db->quoteName('d.id'),
        )->loadAssocList('id', 'n') ?: [];
        $stacks = [];

        foreach ($others as $other) {
            $count = (int) ($counts[(int) $other->id] ?? 0);

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

        return $stacks;
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
    public function others(int $except): array
    {
        return $this->combiningRules(except: $except);
    }

    /**
     * The reach a rule's job stored, with the moment it was counted; null when none is stored.
     *
     * @param int $discountId The rule.
     *
     * @since  2.3.0
     */
    public function stored(int $discountId): ?array
    {
        $db = $this->db;
        $json = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('value'))
                ->from($db->quoteName('#__alfa_meta'))
                ->where($db->quoteName('name') . ' = ' . $db->quote(self::META_PREFIX . $discountId)),
        )->loadResult();
        $reach = $json === null ? null : json_decode((string) $json, true);

        return \is_array($reach) && isset($reach['reaches']) ? $reach : null;
    }

    /**
     * Keep a rule's reach, as counted now, for the card to show with its moment.
     *
     * @param int $discountId The rule.
     * @param array $reach What of() or the job assembled.
     *
     * @since  2.3.0
     */
    public function store(int $discountId, array $reach): void
    {
        $db = $this->db;
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__alfa_meta') . ' (' . $db->quoteName('name') . ', ' . $db->quoteName('value') . ')'
            . ' VALUES (' . $db->quote(self::META_PREFIX . $discountId) . ', ' . $db->quote(json_encode($reach, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('value') . ' = VALUES(' . $db->quoteName('value') . ')',
        )->execute();
    }

    /**
     * Forget a rule's stored reach: the rule changed, or is gone, so what was counted is of a rule that no longer is.
     *
     * @param int[] $discountIds The rules.
     * @param DatabaseInterface|null $db The connection; the site's when omitted.
     *
     * @since  2.3.0
     */
    public static function forget(array $discountIds, ?DatabaseInterface $db = null): void
    {
        $ids = array_values(array_filter(array_map('intval', $discountIds), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return;
        }

        $db ??= Factory::getContainer()->get(DatabaseInterface::class);
        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__alfa_meta'))
                ->whereIn($db->quoteName('name'), array_map(static fn (int $id): string => self::META_PREFIX . $id, $ids), ParameterType::STRING),
        )->execute();
    }

    /**
     * The rule's row: state, behaviour and period.
     *
     * @param int $discountId The rule.
     *
     * @since  2.3.0
     */
    private function rule(int $discountId): ?object
    {
        $db = $this->db;

        return $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'state', 'behavior', 'publish_up', 'publish_down']))
                ->from($db->quoteName('#__alfa_discounts'))
                ->where($db->quoteName('id') . ' = ' . $discountId),
        )->loadObject();
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
