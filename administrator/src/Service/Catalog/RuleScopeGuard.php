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
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseDriver;
use LogicException;

/**
 * Deleting a category, place or manufacturer never silently widens a surviving
 * discount, tax or cart rule.
 *
 * A discount or tax is scoped on each axis by the rows of its pivot, and the
 * pricing engine reads a rule with NO row on an axis as applying to every value
 * of it. Deleting an entity strips its pivot rows, so deleting the last entity a
 * rule names would silently widen the rule: a category discount would price the
 * whole catalogue, a reduced tax would tax every product. Such a delete is
 * refused, naming the rules; a merchant who wants a rule to apply everywhere
 * says so in the rule's editor, which stores that choice as the 0 row.
 *
 * A cart rule's "is NOT one of" condition on categories or manufacturers is
 * judged against each product's memberships, which the delete strips: the
 * deleted entity's products would leave the exclusion and the rule would reach
 * them. A delete of any entity such a condition names is refused, naming the
 * cart rule. An "is one of" condition only narrows when a membership goes (the
 * product stops matching it), so it does not refuse.
 *
 * The judgement covers the whole requested set at once, and every rule whatever
 * its state: an unpublished rule left without scope would be shop-wide the day it
 * is published. A discount or tax keeps its scope while any row of it names
 * something outside the set, the 0 row included.
 *
 * It runs inside the transaction that deletes, and reads the rows it judges
 * with an exclusive lock held until the delete commits: a rule editor rewriting
 * a scope or a condition waits for it (or the delete waits for the editor and
 * then judges what the editor committed).
 *
 * @since  2.0.0
 */
final class RuleScopeGuard
{
    /** @var string Categories: #__alfa_discount_categories, #__alfa_tax_categories, cart-rule category conditions. @since 2.0.0 */
    public const AXIS_CATEGORY = 'category';

    /** @var string Places: #__alfa_discount_places, #__alfa_tax_places. @since 2.0.0 */
    public const AXIS_PLACE = 'place';

    /** @var string Manufacturers: #__alfa_discount_manufacturers, #__alfa_tax_manufacturers, cart-rule manufacturer conditions. @since 2.0.0 */
    public const AXIS_MANUFACTURER = 'manufacturer';

    /**
     * Per axis: the entity table and column, the refusal's language keys, the
     * rule pivots as kind => [pivot table, rule column], and the cart-rule
     * condition type that names the axis (null: no condition names it).
     *
     * @var array<string, array{table: string, column: string, refusal: string, excludes: ?string, pivots: array<string, array{0: string, 1: string}>, conditionType: ?string}>
     * @since 2.0.0
     */
    private const AXES = [
        self::AXIS_CATEGORY => [
            'table' => '#__alfa_categories',
            'column' => 'category_id',
            'refusal' => 'COM_ALFA_CATEGORY_DELETE_LAST_RULE_SCOPE',
            'excludes' => 'COM_ALFA_CATEGORY_DELETE_CART_RULE_EXCLUDES',
            'pivots' => [
                'discount' => ['#__alfa_discount_categories', 'discount_id'],
                'tax' => ['#__alfa_tax_categories', 'tax_id'],
            ],
            'conditionType' => 'categories',
        ],
        self::AXIS_PLACE => [
            'table' => '#__alfa_places',
            'column' => 'place_id',
            'refusal' => 'COM_ALFA_PLACE_DELETE_LAST_RULE_SCOPE',
            'excludes' => null,
            'pivots' => [
                'discount' => ['#__alfa_discount_places', 'discount_id'],
                'tax' => ['#__alfa_tax_places', 'tax_id'],
            ],
            'conditionType' => null,
        ],
        self::AXIS_MANUFACTURER => [
            'table' => '#__alfa_manufacturers',
            'column' => 'manufacturer_id',
            'refusal' => 'COM_ALFA_MANUFACTURER_DELETE_LAST_RULE_SCOPE',
            'excludes' => 'COM_ALFA_MANUFACTURER_DELETE_CART_RULE_EXCLUDES',
            'pivots' => [
                'discount' => ['#__alfa_discount_manufacturers', 'discount_id'],
                'tax' => ['#__alfa_tax_manufacturers', 'tax_id'],
            ],
            'conditionType' => 'manufacturers',
        ],
    ];

    /**
     * The rule tables, their translation key column and their label keys.
     *
     * @var array<string, array{table: string, langColumn: string, named: string, unnamed: string}>
     * @since 2.0.0
     */
    private const KINDS = [
        'discount' => [
            'table' => '#__alfa_discounts',
            'langColumn' => 'id_discount',
            'named' => 'COM_ALFA_SCOPE_RULE_DISCOUNT',
            'unnamed' => 'COM_ALFA_SCOPE_RULE_DISCOUNT_UNNAMED',
        ],
        'tax' => [
            'table' => '#__alfa_taxes',
            'langColumn' => 'id_tax',
            'named' => 'COM_ALFA_SCOPE_RULE_TAX',
            'unnamed' => 'COM_ALFA_SCOPE_RULE_TAX_UNNAMED',
        ],
        'cart_rule' => [
            'table' => '#__alfa_cart_rules',
            'langColumn' => 'id_cart_rule',
            'named' => 'COM_ALFA_SCOPE_RULE_CART_RULE',
            'unnamed' => 'COM_ALFA_SCOPE_RULE_CART_RULE_UNNAMED',
        ],
    ];

    /**
     * The entities of a request that exist, in the order asked, locked until the
     * transaction ends.
     *
     * A delete acts on these alone. Core's delete stops at the first id it cannot
     * load (a row another admin deleted since the list was drawn) and still
     * answers success, so stripping the pivots of every requested id would strip
     * the rule scope, links and names of entities it never deleted. The lock also
     * keeps a concurrent delete of the same rows out until the commit.
     *
     * @param DatabaseDriver $db The connection the delete runs on.
     * @param string $axis One of the AXIS_* constants.
     * @param int[] $ids The requested ids.
     *
     * @return int[]
     *
     * @throws LogicException When no transaction is open or the axis is unknown.
     *
     * @since   2.0.0
     */
    public static function lockExisting(DatabaseDriver $db, string $axis, array $ids): array
    {
        $spec = self::AXES[$axis] ?? null;

        if ($spec === null) {
            throw new LogicException('Unknown rule scope axis: ' . $axis);
        }

        if (!TransactionState::isOpen($db)) {
            throw new LogicException('The rows to delete must be read inside the transaction that deletes.');
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $found = array_flip(array_map('intval', (array) $db->setQuery(
            'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName($spec['table'])
            . ' WHERE ' . $db->quoteName('id') . ' IN (' . implode(',', $ids) . ') FOR UPDATE',
        )->loadColumn()));

        return array_values(array_filter($ids, static fn (int $id): bool => isset($found[$id])));
    }

    /**
     * Refuse the delete of these entities when it would leave any discount or tax
     * rule with no scope row on their axis, or take products out of a cart
     * rule's "is NOT one of" condition.
     *
     * Must run inside the transaction that then deletes the entities and their
     * pivot rows: the rows it reads stay locked until that transaction ends.
     *
     * @param DatabaseDriver $db The connection the delete runs on.
     * @param string $axis One of the AXIS_* constants.
     * @param int[] $ids Every entity the request deletes.
     *
     *
     * @throws ScopeDeleteRefusedException When a rule would be widened; nothing has been written.
     * @throws LogicException When no transaction is open or the axis is unknown.
     *
     * @since   2.0.0
     */
    public static function assertScopesSurvive(DatabaseDriver $db, string $axis, array $ids): void
    {
        $spec = self::AXES[$axis] ?? null;

        if ($spec === null) {
            throw new LogicException('Unknown rule scope axis: ' . $axis);
        }

        // Outside a transaction the locks would end with each read, and a rule
        // editor could empty a scope between this judgement and the delete.
        if (!TransactionState::isOpen($db)) {
            throw new LogicException('The rule scope check must run inside the transaction that deletes.');
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return;
        }

        $column = $db->quoteName($spec['column']);
        $idList = implode(',', $ids);
        $deleting = array_flip($ids);
        $refused = [];

        foreach ($spec['pivots'] as $kind => [$pivot, $ruleColumn]) {
            $table = $db->quoteName($pivot);
            $rule = $db->quoteName($ruleColumn);

            $ruleIds = array_values(array_unique(array_map('intval', (array) $db->setQuery(
                'SELECT ' . $rule . ' FROM ' . $table . ' WHERE ' . $column . ' IN (' . $idList . ') FOR UPDATE',
            )->loadColumn())));

            if ($ruleIds === []) {
                continue;
            }

            // Every row of those rules: a rule keeps its scope while one of them
            // names something outside the set (the 0 row and an id whose entity
            // is already gone both do).
            $rows = (array) $db->setQuery(
                'SELECT ' . $rule . ', ' . $column . ' FROM ' . $table
                . ' WHERE ' . $rule . ' IN (' . implode(',', $ruleIds) . ') FOR UPDATE',
            )->loadRowList();

            $kept = [];

            foreach ($rows as [$ruleId, $entityId]) {
                if (!isset($deleting[(int) $entityId])) {
                    $kept[(int) $ruleId] = true;
                }
            }

            $unscoped = array_values(array_filter($ruleIds, static fn (int $ruleId): bool => !isset($kept[$ruleId])));

            if ($unscoped !== []) {
                $refused[$kind] = self::existing(db: $db, kind: $kind, ids: $unscoped);
            }
        }

        if ($spec['conditionType'] !== null) {
            // Cart rules whose "is NOT one of" condition names an entity of the set.
            $excluding = array_values(array_unique(array_map('intval', (array) $db->setQuery(
                'SELECT ' . $db->quoteName('g.id_cart_rule')
                . ' FROM ' . $db->quoteName('#__alfa_cart_rule_condition_values', 'v')
                . ' INNER JOIN ' . $db->quoteName('#__alfa_cart_rule_conditions', 'c') . ' ON ' . $db->quoteName('c.id') . ' = ' . $db->quoteName('v.id_condition')
                . ' INNER JOIN ' . $db->quoteName('#__alfa_cart_rule_groups', 'g') . ' ON ' . $db->quoteName('g.id') . ' = ' . $db->quoteName('c.id_group')
                . ' WHERE ' . $db->quoteName('c.type') . ' = ' . $db->quote($spec['conditionType'])
                . ' AND ' . $db->quoteName('c.exclude') . ' = 1'
                . ' AND ' . $db->quoteName('v.id_value') . ' IN (' . $idList . ') FOR UPDATE',
            )->loadColumn())));

            if ($excluding !== []) {
                $refused['cart_rule'] = self::existing(db: $db, kind: 'cart_rule', ids: $excluding);
            }
        }

        $refused = array_filter($refused);

        if ($refused === []) {
            return;
        }

        $rules = self::describe(db: $db, refused: $refused);
        $scoped = array_values(array_filter($rules, static fn (array $rule): bool => $rule['kind'] !== 'cart_rule'));
        $excluding = array_values(array_filter($rules, static fn (array $rule): bool => $rule['kind'] === 'cart_rule'));
        $message = [];
        $plain = [];

        if ($scoped !== []) {
            $message[] = Text::sprintf($spec['refusal'], implode(', ', array_column($scoped, 'label')));
            $plain[] = Text::sprintf($spec['refusal'], implode(', ', array_column($scoped, 'plainLabel')));
        }

        if ($excluding !== []) {
            $message[] = Text::sprintf($spec['excludes'], implode(', ', array_column($excluding, 'label')));
            $plain[] = Text::sprintf($spec['excludes'], implode(', ', array_column($excluding, 'plainLabel')));
        }

        throw new ScopeDeleteRefusedException(
            axis: $axis,
            rules: array_map(static fn (array $rule): array => array_diff_key($rule, ['label' => true, 'plainLabel' => true]), $rules),
            message: implode(' ', $message),
            plain: implode(' ', $plain),
        );
    }

    /**
     * Which of these rules exist, read under lock: a pivot or condition row left
     * by a rule already deleted protects nothing, and a rule created since this
     * transaction's first read is seen.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $kind A key of KINDS.
     * @param int[] $ids Rule ids.
     *
     * @return int[] The existing ones, ascending.
     *
     * @since   2.0.0
     */
    private static function existing(DatabaseDriver $db, string $kind, array $ids): array
    {
        return array_map('intval', (array) $db->setQuery(
            'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName(self::KINDS[$kind]['table'])
            . ' WHERE ' . $db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $ids)) . ')'
            . ' ORDER BY ' . $db->quoteName('id') . ' FOR UPDATE',
        )->loadColumn());
    }

    /**
     * The refused rules with their names in the current language (the default
     * language's where it has none) and the labels the refusal prints: escaped
     * for the back office, which prints messages as HTML, and as text.
     *
     * @param DatabaseDriver $db The connection.
     * @param array<string, int[]> $refused Rule ids by kind, ascending.
     *
     * @return array<int, array{kind: string, id: int, name: string, label: string, plainLabel: string}>
     *
     * @since   2.0.0
     */
    private static function describe(DatabaseDriver $db, array $refused): array
    {
        $rules = [];

        foreach (self::KINDS as $kind => $spec) {
            $ids = $refused[$kind] ?? [];

            if ($ids === []) {
                continue;
            }

            $records = MultilingualHelper::getRecordsByIds(
                db: $db,
                ids: $ids,
                table: $spec['table'],
                langTableBase: $spec['table'],
                langPrimaryColumn: $spec['langColumn'],
            );

            foreach ($ids as $id) {
                $name = trim((string) ($records[$id]['name'] ?? ''));

                $rules[] = [
                    'kind' => $kind,
                    'id' => $id,
                    'name' => $name,
                    'label' => $name === ''
                        ? Text::sprintf($spec['unnamed'], $id)
                        : Text::sprintf($spec['named'], $id, htmlspecialchars($name, ENT_QUOTES, 'UTF-8')),
                    'plainLabel' => $name === ''
                        ? Text::sprintf($spec['unnamed'], $id)
                        : Text::sprintf($spec['named'], $id, $name),
                ];
            }
        }

        return $rules;
    }
}
