<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Pricing;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Field\AlfapricesField;
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Alfa\Component\Alfa\Site\Service\Pricing\ActiveDateCondition;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceComputationEngine;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseDriver;
use Throwable;

/**
 * The price check: what the prices of the catalogue say that a shop owner would not want them to say, read as a
 * report of sections, each a list of findings that link to the product, the price row, the discount or the tax.
 *
 * Read only, and never a pricing of the whole catalogue: the figures come from the price index (the resolved price of
 * every product for the anonymous visitor, the context the catalogue listing reads), from the price rows and from the
 * rules' scope links. The engine runs for one kind of finding only, the sale rows that have to be compared with the
 * taxes or the rules of their own context (the editor's own preview, one run per sale row), and it runs for at most
 * ENGINE_CAP rows a report; the rows beyond the cap are counted as not checked and the report says so. Each section
 * lists at most LIST_CAP rows; its count is the whole.
 *
 * The sections, in the order the page shows them:
 *
 * - unpriced:            published products the index holds no valid price for, and, by option, products priced at 0;
 * - sale_above:          sale rows above the price they replace (the net price, or the price with taxes when the sale
 *                        pins the final price), rows saved before the save refused them included;
 * - sale_large:          sale rows taking more than half the price they replace;
 * - sale_below_rules:    sale rows that give less than the discount rules they replace would give;
 * - sale_open_ended:     by option, sale rows without an end date;
 * - rules_out_of_period: discounts and taxes published while their period has ended or has not started;
 * - untaxed:             published products no published tax in its period reaches, while such taxes exist;
 * - stacked:             products two or more rules reach together whose total discount in the index is above half;
 * - inverted_rows:       a price row with a period and no sale beside a row without a period that carries the sale,
 *                        in the same scope: the sale was typed on the wrong row;
 * - index:               the counters of the price index check (missing rows, stale rows, gaps, products left out).
 *
 * @since  2.3.0
 */
final class PriceAudit
{
    /** @since 2.3.0 */
    public const SECTION_UNPRICED = 'unpriced';

    /** @since 2.3.0 */
    public const SECTION_SALE_ABOVE = 'sale_above';

    /** @since 2.3.0 */
    public const SECTION_SALE_LARGE = 'sale_large';

    /** @since 2.3.0 */
    public const SECTION_SALE_BELOW_RULES = 'sale_below_rules';

    /** @since 2.3.0 */
    public const SECTION_SALE_OPEN = 'sale_open_ended';

    /** @since 2.3.0 */
    public const SECTION_RULES_PERIOD = 'rules_out_of_period';

    /** @since 2.3.0 */
    public const SECTION_UNTAXED = 'untaxed';

    /** @since 2.3.0 */
    public const SECTION_STACKED = 'stacked';

    /** @since 2.3.0 */
    public const SECTION_INVERTED = 'inverted_rows';

    /** @since 2.3.0 */
    public const SECTION_INDEX = 'index';

    /**
     * The sections of findings, in the order the page shows them.
     *
     * @var list<string>
     *
     * @since  2.3.0
     */
    public const SECTIONS = [
        self::SECTION_UNPRICED,
        self::SECTION_SALE_ABOVE,
        self::SECTION_SALE_LARGE,
        self::SECTION_SALE_BELOW_RULES,
        self::SECTION_SALE_OPEN,
        self::SECTION_RULES_PERIOD,
        self::SECTION_UNTAXED,
        self::SECTION_STACKED,
        self::SECTION_INVERTED,
    ];

    /**
     * The most sale rows the engine prices in one report; the rest are counted as not checked.
     *
     * @since  2.3.0
     */
    public const ENGINE_CAP = 200;

    /**
     * The most rows a section lists; its count is the whole.
     *
     * @since  2.3.0
     */
    public const LIST_CAP = 500;

    /**
     * The share of the price, in percent, above which a sale or a stacked discount is a finding.
     *
     * @since  2.3.0
     */
    public const LARGE_SHARE = 50.0;

    /**
     * The option that lists published products priced at 0.
     *
     * @since  2.3.0
     */
    public const PARAM_ZERO = 'price_audit_zero';

    /**
     * The option that lists sales without an end date.
     *
     * @since  2.3.0
     */
    public const PARAM_OPEN_SALE = 'price_audit_open_sale';

    /**
     * The moment the report is read at (UTC, Y-m-d H:i:s): the engine's clock.
     *
     * @since  2.3.0
     */
    private readonly string $now;

    /**
     * The engine runs of this report, and the sale rows it could not check (the cap, or a failed pricing).
     *
     * @since  2.3.0
     */
    private int $engineRuns = 0;

    /** @since 2.3.0 */
    private int $engineUnchecked = 0;

    /**
     * @param DatabaseDriver $db The connection.
     * @param string|null $now The moment to read at; the engine's clock when omitted.
     *
     * @since  2.3.0
     */
    public function __construct(private readonly DatabaseDriver $db, ?string $now = null)
    {
        $this->now = $now ?? ActiveDateCondition::now();
    }

    /**
     * The report, read inside one read-only snapshot that is always rolled back, so every figure it compares was read
     * at the same moment while the shop keeps selling. Opens its own transaction: not for a caller inside one.
     *
     * @param DatabaseDriver|null $db The connection; the site's when omitted.
     *
     * @return array The report (see report()).
     *
     * @since  2.3.0
     */
    public static function read(?DatabaseDriver $db = null): array
    {
        $db = TransactionState::driver(db: $db ?? Factory::getContainer()->get('DatabaseDriver'));

        // The next transaction read-only, opened by the driver: MySQL cannot prepare START TRANSACTION, and the MySQLi
        // driver prepares every statement it is handed. Under REPEATABLE READ the first read fixes the snapshot.
        $db->setQuery('SET TRANSACTION READ ONLY')->execute();
        $db->transactionStart();

        try {
            return (new self(db: $db))->report();
        } finally {
            TransactionState::rollBackLevel(db: $db);
        }
    }

    /**
     * Read every section.
     *
     * @return array{now: string, context: array{id: int|null, currency_id: int, groups: int[]}, options: array{zero: bool, open_sale: bool}, engine: array{cap: int, runs: int, unchecked: int}, list_cap: int, sections: array<string, array{rows: list<array>, count: int, available: bool}>, index: array}
     *
     * @since  2.3.0
     */
    public function report(): array
    {
        $params = ComponentHelper::getParams('com_alfa');
        $options = [
            'zero' => (int) $params->get(self::PARAM_ZERO, 1) === 1,
            'open_sale' => (int) $params->get(self::PARAM_OPEN_SALE, 1) === 1,
        ];
        $context = $this->context();
        $this->engineRuns = 0;
        $this->engineUnchecked = 0;

        $sections = [self::SECTION_UNPRICED => $this->unpriced(context: $context, zero: $options['zero'])];
        $sections += $this->sales(openEnded: $options['open_sale'], groups: $context['groups']);
        $sections[self::SECTION_RULES_PERIOD] = $this->rulesOutOfPeriod();
        $sections[self::SECTION_UNTAXED] = $this->untaxed(groups: $context['groups']);
        $sections[self::SECTION_STACKED] = $this->stacked(context: $context);
        $sections[self::SECTION_INVERTED] = $this->invertedRows();

        $ordered = [];

        foreach (self::SECTIONS as $key) {
            $ordered[$key] = $sections[$key];
        }

        return [
            'now' => $this->now,
            'context' => $context,
            'options' => $options,
            'engine' => ['cap' => self::ENGINE_CAP, 'runs' => $this->engineRuns, 'unchecked' => $this->engineUnchecked],
            'list_cap' => self::LIST_CAP,
            'sections' => $ordered,
            'index' => $this->index(context: $context),
        ];
    }

    /**
     * The sections with findings and their counts; the index counts once while it is not complete.
     *
     * @param array $report The report.
     *
     * @return array<string, int>
     *
     * @since  2.3.0
     */
    public static function counts(array $report): array
    {
        $counts = [];

        foreach ((array) $report['sections'] as $key => $section) {
            if ((int) $section['count'] > 0) {
                $counts[(string) $key] = (int) $section['count'];
            }
        }

        if (!(bool) ($report['index']['complete'] ?? false)) {
            $counts[self::SECTION_INDEX] = 1;
        }

        return $counts;
    }

    /**
     * The findings in one sentence: "3 sales above the price, 1 rule published outside its period". Empty when there is
     * nothing to say.
     *
     * @param array $report The report.
     *
     * @since  2.3.0
     */
    public static function sentence(array $report): string
    {
        return self::sentenceOf(counts: self::counts(report: $report), building: (bool) ($report['index']['building'] ?? false));
    }

    /**
     * The sentence from the counts alone (see counts()), for a reader that keeps the counts and not the report.
     *
     * @param array<string, int> $counts The sections with findings and their counts.
     * @param bool $building Whether the price index is being built after the update.
     *
     * @since  2.3.0
     */
    public static function sentenceOf(array $counts, bool $building = false): string
    {
        $parts = [];

        foreach ($counts as $key => $count) {
            if ($key === self::SECTION_INDEX) {
                $parts[] = Text::_($building ? 'COM_ALFA_PRICE_AUDIT_COUNT_INDEX_BUILDING' : 'COM_ALFA_PRICE_AUDIT_COUNT_INDEX');

                continue;
            }

            $parts[] = Text::plural('COM_ALFA_PRICE_AUDIT_COUNT_' . strtoupper((string) $key), (int) $count);
        }

        return implode(', ', $parts);
    }

    /**
     * The context the figures are read in: the anonymous visitor in the shop's currency with no place, the one the
     * catalogue listing prices for, and the live price index context prepared for it; none while the index holds no
     * such context (then the sections read from the index say they are not available).
     *
     * @return array{id: int|null, currency_id: int, groups: int[]}
     *
     * @since  2.3.0
     */
    private function context(): array
    {
        $currencyId = 0;

        try {
            $currencyId = Currency::getDefault()->getId();
        } catch (Throwable $e) {
            $this->log('the shop currency could not be read: ' . ErrorText::of($e));
        }

        $read = null;

        if ($currencyId > 0) {
            try {
                $read = PriceIndexSyncService::readContext(
                    context: PriceContext::forIndex(currencyId: $currencyId, placeId: 0, usergroupId: 0)->withCustomer(customer: new User()),
                );
            } catch (Throwable $e) {
                $this->log('the price index context could not be read: ' . ErrorText::of($e));
            }
        }

        return ['id' => $read === null ? null : (int) $read['context'], 'currency_id' => $currencyId, 'groups' => RuleReach::guestGroups()];
    }

    /**
     * Published products the index holds no valid price for (every row of the product covering now has none), and, by
     * option, the units priced at 0.
     *
     * @param array{id: int|null} $context The context.
     * @param bool $zero Whether units priced at 0 are listed.
     *
     * @return array The section.
     *
     * @since  2.3.0
     */
    private function unpriced(array $context, bool $zero): array
    {
        if ($context['id'] === null) {
            return $this->section(rows: [], count: 0, available: false);
        }

        $db = $this->db;
        $covering = $this->coveringSql(alias: 'i', contextId: (int) $context['id']);
        $index = $db->quoteName(PriceIndexSyncService::INDEX_TABLE, 'i');
        $ids = array_map('intval', $db->setQuery(
            'SELECT ' . $db->quoteName('a.id') . ' FROM ' . $db->quoteName('#__alfa_items', 'a')
            . ' WHERE ' . $db->quoteName('a.state') . ' = 1'
            . ' AND EXISTS (SELECT 1 FROM ' . $index . ' WHERE ' . $db->quoteName('i.item_id') . ' = ' . $db->quoteName('a.id') . ' AND ' . $covering . ')'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $index . ' WHERE ' . $db->quoteName('i.item_id') . ' = ' . $db->quoteName('a.id') . ' AND ' . $covering
            . ' AND ' . $db->quoteName('i.final_price') . ' IS NOT NULL)'
            . ' ORDER BY ' . $db->quoteName('a.id'),
        )->loadColumn() ?: []);

        $rows = [];

        foreach (\array_slice($ids, 0, self::LIST_CAP) as $id) {
            $rows[] = ['item' => ['id' => $id, 'combination_id' => 0], 'rule' => null, 'row_id' => null, 'detail' => ['kind' => 'no_price']];
        }

        $count = \count($ids);

        if ($zero) {
            $zeroRows = $db->setQuery(
                'SELECT ' . implode(', ', $db->quoteName(['i.item_id', 'i.combination_id', 'i.final_price']))
                . ' FROM ' . $index . ' INNER JOIN ' . $db->quoteName('#__alfa_items', 'a') . ' ON ' . $db->quoteName('a.id') . ' = ' . $db->quoteName('i.item_id')
                . ' AND ' . $db->quoteName('a.state') . ' = 1'
                . ' WHERE ' . $covering . ' AND ' . $db->quoteName('i.final_price') . ' = 0'
                . ' ORDER BY ' . $db->quoteName('i.item_id') . ', ' . $db->quoteName('i.combination_id'),
            )->loadObjectList() ?: [];
            $count += \count($zeroRows);

            foreach (\array_slice($zeroRows, 0, max(0, self::LIST_CAP - \count($rows))) as $row) {
                $rows[] = [
                    'item' => ['id' => (int) $row->item_id, 'combination_id' => (int) $row->combination_id],
                    'rule' => null,
                    'row_id' => null,
                    'detail' => ['kind' => 'zero', 'final_price' => (string) $row->final_price],
                ];
            }
        }

        return $this->section(rows: $rows, count: $count);
    }

    /**
     * The sale rows (a published price row with a custom discount, of a published product, whose period has not
     * ended), judged four ways: above the price they replace, taking more than half of it, giving less than the rules
     * they replace would give, and (by option) without an end date. The price with taxes, and what the rules would
     * give, come from the engine under the row's own dimensions (the editor's preview), for at most ENGINE_CAP rows.
     *
     * @param bool $openEnded Whether sales without an end date are listed.
     * @param int[] $groups The anonymous visitor's groups.
     *
     * @return array<string, array> The four sections.
     *
     * @since  2.3.0
     */
    private function sales(bool $openEnded, array $groups): array
    {
        $db = $this->db;
        $rows = $db->setQuery(
            'SELECT ' . implode(', ', $db->quoteName([
                'p.id', 'p.item_id', 'p.combination_id', 'p.value', 'p.override_value', 'p.override_type', 'p.currency_id', 'p.country_id',
                'p.usergroup_id', 'p.user_id', 'p.quantity_start', 'p.quantity_end', 'p.publish_up', 'p.publish_down',
            ]))
            . ' FROM ' . $db->quoteName('#__alfa_items_prices', 'p')
            . ' INNER JOIN ' . $db->quoteName('#__alfa_items', 'a') . ' ON ' . $db->quoteName('a.id') . ' = ' . $db->quoteName('p.item_id') . ' AND ' . $db->quoteName('a.state') . ' = 1'
            . ' WHERE ' . $db->quoteName('p.state') . ' = 1 AND ' . $db->quoteName('p.mode') . ' = ' . $db->quote('price')
            . ' AND ' . $db->quoteName('p.override_value') . ' IS NOT NULL'
            . ' AND ' . (new ActiveDateCondition())->getNotEndedCondition(endField: 'p.publish_down', referenceDate: $this->now)
            . ' ORDER BY ' . $db->quoteName('p.item_id') . ', ' . $db->quoteName('p.id'),
        )->loadObjectList() ?: [];

        $above = [];
        $large = [];
        $below = [];
        $open = [];
        $reached = [];

        if ($rows !== []) {
            // The sale rows' products that some discount rule reaches now: their sales are compared with the rules.
            $itemIds = array_values(array_unique(array_map(static fn (object $row): int => (int) $row->item_id, $rows)));
            $reached = array_flip(array_map('intval', $db->setQuery(
                'SELECT ' . $db->quoteName('a.id') . ' FROM ' . $db->quoteName('#__alfa_items', 'a')
                . ' WHERE ' . $db->quoteName('a.id') . ' IN (' . implode(',', $itemIds) . ')'
                . ' AND EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_discounts', 'd') . ' WHERE ' . $this->activeDiscountSql() . ' AND ' . RuleReach::sql(kind: RuleReach::DISCOUNT, ruleId: $db->quoteName('d.id'), itemId: $db->quoteName('a.id'), groupIds: $groups) . ')',
            )->loadColumn() ?: []));
        }

        foreach ($rows as $row) {
            $value = PriceComputationEngine::catalogueUnit(value: $row->value);
            $sale = PriceComputationEngine::catalogueUnit(value: $row->override_value);
            $final = (string) $row->override_type === 'final';
            $base = ['item' => ['id' => (int) $row->item_id, 'combination_id' => (int) $row->combination_id], 'rule' => null, 'row_id' => (int) $row->id];

            if ($openEnded && !RulePeriod::isMoment($row->publish_down)) {
                $open[] = $base + ['detail' => ['sale_price' => $sale, 'sale_pins' => (string) $row->override_type, 'starts_at' => $row->publish_up]];
            }

            // The engine is asked for a sale on the final price (the taxes it carries) and for a product the rules reach
            // (what they would give); a sale on the net price of a product no rule reaches is judged on the row alone.
            $preview = $final || isset($reached[(int) $row->item_id]) ? $this->preview(row: $row) : null;
            $replaced = $final ? ($preview === null ? null : $this->times(decimal: $value, factor: (float) $preview['taxFactor'])) : $value;

            if ($replaced !== null) {
                $detail = ['sale_price' => $sale, 'replaced_price' => $replaced, 'sale_pins' => (string) $row->override_type];

                if (ExactAmount::of($sale)->compare(ExactAmount::of($replaced)) > 0) {
                    $above[] = $base + ['detail' => $detail];
                } elseif ((float) $replaced > 0) {
                    $share = (((float) $replaced - (float) $sale) / (float) $replaced) * 100;

                    if ($share > self::LARGE_SHARE) {
                        $large[] = $base + ['detail' => $detail + ['share_percent' => round($share, 1)]];
                    }
                }
            }

            if ($preview !== null && $preview['discounts'] !== []) {
                $decimals = (int) $preview['decimals'];
                $rulesGross = round(max(0.0, ((float) $value * (float) $preview['a']) + (float) $preview['b']), $decimals);
                $saleGross = round(max(0.0, (float) $sale) * ($final ? 1.0 : (float) $preview['taxFactor']), $decimals);

                if ($rulesGross < $saleGross) {
                    $below[] = $base + ['detail' => [
                        'sale_final_price' => number_format($saleGross, $decimals, '.', ''),
                        'rules_final_price' => number_format($rulesGross, $decimals, '.', ''),
                        'rules' => implode(', ', array_map(static fn (array $discount): string => (string) $discount['name'], $preview['discounts'])),
                    ]];
                }
            }
        }

        return [
            self::SECTION_SALE_ABOVE => $this->section(rows: $above, count: \count($above)),
            self::SECTION_SALE_LARGE => $this->section(rows: $large, count: \count($large)),
            self::SECTION_SALE_BELOW_RULES => $this->section(rows: $below, count: \count($below)),
            self::SECTION_SALE_OPEN => $this->section(rows: $open, count: \count($open)),
        ];
    }

    /**
     * Discounts and taxes published while their period has ended or has not started.
     *
     * @return array The section.
     *
     * @since  2.3.0
     */
    private function rulesOutOfPeriod(): array
    {
        $db = $this->db;
        $rows = [];

        foreach ([RuleReach::DISCOUNT => ['#__alfa_discounts', 'id_discount'], RuleReach::TAX => ['#__alfa_taxes', 'id_tax']] as $kind => [$table, $langKey]) {
            $query = $db->getQuery(true)
                ->select($db->quoteName(['a.id', 'a.publish_up', 'a.publish_down']))
                ->from($db->quoteName($table, 'a'))
                ->where($db->quoteName('a.state') . ' = 1')
                ->where('(' . RulePeriod::sql(status: RulePeriod::EXPIRED, startColumn: 'a.publish_up', endColumn: 'a.publish_down', now: $this->now)
                    . ' OR ' . RulePeriod::sql(status: RulePeriod::UPCOMING, startColumn: 'a.publish_up', endColumn: 'a.publish_down', now: $this->now) . ')')
                ->order($db->quoteName('a.id'));
            MultilingualHelper::addMultilingualJoinToQuery(query: $query, mainAlias: 'a', mainPrimaryColumn: 'id', langTableBase: $table, langPrimaryColumn: $langKey, fields: ['name']);

            foreach ($db->setQuery($query)->loadObjectList() ?: [] as $rule) {
                $rows[] = [
                    'item' => null,
                    'rule' => ['kind' => $kind, 'id' => (int) $rule->id, 'name' => (string) ($rule->name ?? '')],
                    'row_id' => null,
                    'detail' => [
                        'status' => RulePeriod::status(publishUp: $rule->publish_up, publishDown: $rule->publish_down, now: $this->now),
                        'starts_at' => RulePeriod::isMoment($rule->publish_up) ? (string) $rule->publish_up : null,
                        'ends_at' => RulePeriod::isMoment($rule->publish_down) ? (string) $rule->publish_down : null,
                    ],
                ];
            }
        }

        return $this->section(rows: $rows, count: \count($rows));
    }

    /**
     * Published products no published tax in its period reaches for the anonymous visitor, while such taxes exist. A
     * product with no category is reached by nothing, so it is listed too.
     *
     * @param int[] $groups The anonymous visitor's groups.
     *
     * @return array The section, with the published taxes counted under 'taxes'.
     *
     * @since  2.3.0
     */
    private function untaxed(array $groups): array
    {
        $db = $this->db;
        $active = $db->quoteName('t.state') . ' = 1 AND ' . RulePeriod::sql(status: RulePeriod::ACTIVE, startColumn: 't.publish_up', endColumn: 't.publish_down', now: $this->now);
        $taxes = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_taxes', 't') . ' WHERE ' . $active)->loadResult();

        if ($taxes === 0) {
            return $this->section(rows: [], count: 0) + ['taxes' => 0];
        }

        $ids = array_map('intval', $db->setQuery(
            'SELECT ' . $db->quoteName('a.id') . ' FROM ' . $db->quoteName('#__alfa_items', 'a')
            . ' WHERE ' . $db->quoteName('a.state') . ' = 1'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_taxes', 't') . ' WHERE ' . $active
            . ' AND ' . RuleReach::sql(kind: RuleReach::TAX, ruleId: $db->quoteName('t.id'), itemId: $db->quoteName('a.id'), groupIds: $groups) . ')'
            . ' ORDER BY ' . $db->quoteName('a.id'),
        )->loadColumn() ?: []);
        $rows = [];

        foreach (\array_slice($ids, 0, self::LIST_CAP) as $id) {
            $rows[] = ['item' => ['id' => $id, 'combination_id' => 0], 'rule' => null, 'row_id' => null, 'detail' => []];
        }

        return $this->section(rows: $rows, count: \count($ids)) + ['taxes' => $taxes];
    }

    /**
     * Published products that two or more discount rules reach together, whose total discount in the index (the
     * product's own price for the anonymous visitor) is above half, and that carry no sale of their own now (a sale is
     * one custom discount, judged with the sales).
     *
     * @param array{id: int|null, groups: int[]} $context The context.
     *
     * @return array The section.
     *
     * @since  2.3.0
     */
    private function stacked(array $context): array
    {
        if ($context['id'] === null) {
            return $this->section(rows: [], count: 0, available: false);
        }

        $db = $this->db;
        $reaching = ' FROM ' . $db->quoteName('#__alfa_discounts', 'd') . ' WHERE ' . $this->activeDiscountSql()
            . ' AND ' . RuleReach::sql(kind: RuleReach::DISCOUNT, ruleId: $db->quoteName('d.id'), itemId: $db->quoteName('a.id'), groupIds: $context['groups']);
        $rows = $db->setQuery(
            'SELECT ' . $db->quoteName('a.id') . ', ' . $db->quoteName('i.discount_percent')
            . ', (SELECT GROUP_CONCAT(' . $db->quoteName('d.id') . ' ORDER BY ' . $db->quoteName('d.ordering') . ', ' . $db->quoteName('d.id') . ')' . $reaching . ') AS rule_ids'
            . ' FROM ' . $db->quoteName('#__alfa_items', 'a')
            . ' INNER JOIN ' . $db->quoteName(PriceIndexSyncService::INDEX_TABLE, 'i') . ' ON ' . $db->quoteName('i.item_id') . ' = ' . $db->quoteName('a.id')
            . ' AND ' . $db->quoteName('i.combination_id') . ' = 0 AND ' . $this->coveringSql(alias: 'i', contextId: (int) $context['id'])
            . ' WHERE ' . $db->quoteName('a.state') . ' = 1'
            . ' AND ' . $db->quoteName('i.discount_percent') . ' > ' . self::LARGE_SHARE
            . ' AND (SELECT COUNT(*)' . $reaching . ') >= 2'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_items_prices', 'p') . ' WHERE ' . $db->quoteName('p.item_id') . ' = ' . $db->quoteName('a.id')
            . ' AND ' . $db->quoteName('p.combination_id') . ' IS NULL AND ' . $db->quoteName('p.state') . ' = 1 AND ' . $db->quoteName('p.mode') . ' = ' . $db->quote('price')
            . ' AND ' . $db->quoteName('p.override_value') . ' IS NOT NULL AND ' . RulePeriod::sql(status: RulePeriod::ACTIVE, startColumn: 'p.publish_up', endColumn: 'p.publish_down', now: $this->now) . ')'
            . ' ORDER BY ' . $db->quoteName('i.discount_percent') . ' DESC, ' . $db->quoteName('a.id'),
        )->loadObjectList() ?: [];

        $listed = \array_slice($rows, 0, self::LIST_CAP);
        $ruleIds = [];

        foreach ($listed as $row) {
            foreach (explode(',', (string) $row->rule_ids) as $id) {
                $ruleIds[(int) $id] = (int) $id;
            }
        }

        $names = $this->rules(kind: RuleReach::DISCOUNT, ids: array_values($ruleIds));
        $out = [];

        foreach ($listed as $row) {
            $ids = array_map('intval', explode(',', (string) $row->rule_ids));
            $out[] = [
                'item' => ['id' => (int) $row->id, 'combination_id' => 0],
                'rule' => null,
                'row_id' => null,
                'detail' => [
                    'rules' => implode(', ', array_map(static fn (int $id) => (string) ($names[$id]['name'] ?? ('#' . $id)), $ids)),
                    'rule_count' => \count($ids),
                    'discount_percent' => (string) $row->discount_percent,
                ],
            ];
        }

        return $this->section(rows: $out, count: \count($rows));
    }

    /**
     * A price row with a period and no sale, beside a row of the same scope and quantity range without a period that
     * carries the sale: the sale was typed on the wrong row. Only period rows that have not ended.
     *
     * @return array The section.
     *
     * @since  2.3.0
     */
    private function invertedRows(): array
    {
        $db = $this->db;
        $same = static fn (string $column): string => 'COALESCE(' . $db->quoteName('q.' . $column) . ', 0) = COALESCE(' . $db->quoteName('p.' . $column) . ', 0)';
        $price = $db->quote('price');
        $rows = $db->setQuery(
            'SELECT ' . $db->quoteName('p.id', 'period_row') . ', ' . $db->quoteName('q.id', 'sale_row') . ', ' . $db->quoteName('p.item_id')
            . ', COALESCE(' . $db->quoteName('p.combination_id') . ', 0) AS combination_id'
            . ', ' . implode(', ', $db->quoteName(['p.publish_up', 'p.publish_down', 'p.value', 'q.override_value', 'q.override_type']))
            . ' FROM ' . $db->quoteName('#__alfa_items_prices', 'p')
            . ' INNER JOIN ' . $db->quoteName('#__alfa_items', 'a') . ' ON ' . $db->quoteName('a.id') . ' = ' . $db->quoteName('p.item_id') . ' AND ' . $db->quoteName('a.state') . ' = 1'
            . ' INNER JOIN ' . $db->quoteName('#__alfa_items_prices', 'q') . ' ON ' . $db->quoteName('q.item_id') . ' = ' . $db->quoteName('p.item_id')
            . ' AND ' . $db->quoteName('q.id') . ' <> ' . $db->quoteName('p.id') . ' AND ' . $db->quoteName('q.state') . ' = 1 AND ' . $db->quoteName('q.mode') . ' = ' . $price
            . ' AND ' . $db->quoteName('q.override_value') . ' IS NOT NULL'
            . ' AND NOT ' . RulePeriod::momentSql($db->quoteName('q.publish_up')) . ' AND NOT ' . RulePeriod::momentSql($db->quoteName('q.publish_down'))
            . ' AND ' . implode(' AND ', array_map($same, ['combination_id', 'currency_id', 'usergroup_id', 'user_id', 'country_id', 'quantity_start', 'quantity_end']))
            . ' WHERE ' . $db->quoteName('p.state') . ' = 1 AND ' . $db->quoteName('p.mode') . ' = ' . $price . ' AND ' . $db->quoteName('p.override_value') . ' IS NULL'
            . ' AND (' . RulePeriod::momentSql($db->quoteName('p.publish_up')) . ' OR ' . RulePeriod::momentSql($db->quoteName('p.publish_down')) . ')'
            . ' AND ' . (new ActiveDateCondition())->getNotEndedCondition(endField: 'p.publish_down', referenceDate: $this->now)
            . ' ORDER BY ' . $db->quoteName('p.item_id') . ', ' . $db->quoteName('p.id'),
        )->loadObjectList() ?: [];
        $out = [];

        foreach (\array_slice($rows, 0, self::LIST_CAP) as $row) {
            $out[] = [
                'item' => ['id' => (int) $row->item_id, 'combination_id' => (int) $row->combination_id],
                'rule' => null,
                'row_id' => (int) $row->period_row,
                'detail' => [
                    'sale_row' => (int) $row->sale_row,
                    'period_price' => PriceComputationEngine::catalogueUnit(value: $row->value),
                    'sale_price' => PriceComputationEngine::catalogueUnit(value: $row->override_value),
                    'sale_pins' => (string) $row->override_type,
                    'starts_at' => RulePeriod::isMoment($row->publish_up) ? (string) $row->publish_up : null,
                    'ends_at' => RulePeriod::isMoment($row->publish_down) ? (string) $row->publish_down : null,
                ],
            ];
        }

        return $this->section(rows: $out, count: \count($rows));
    }

    /**
     * The counters of the price index check (PriceIndexSyncService::completeness()), and the context this report read.
     *
     * @param array{id: int|null} $context The context.
     *
     * @return array{available: bool, complete: bool, units: int, contexts: int, missing: int, stale: int, gaps: int, left_out: int[], pointers: int, read: int|null, building: bool}
     *
     * @since  2.3.0
     */
    private function index(array $context): array
    {
        $building = PriceIndexSyncService::buildStartedAt() !== null;

        try {
            $check = (new PriceIndexSyncService())->completeness();
        } catch (Throwable $e) {
            $this->log('the price index could not be checked: ' . ErrorText::of($e));

            return ['available' => false, 'complete' => false, 'units' => 0, 'contexts' => 0, 'missing' => 0, 'stale' => 0, 'gaps' => 0, 'left_out' => [], 'pointers' => 0, 'read' => $context['id'], 'building' => $building];
        }

        $missing = 0;
        $stale = 0;

        foreach ((array) $check['contexts'] as $entry) {
            $missing += max(0, (int) $entry['expected'] - (int) $entry['covering']);
            $stale += (int) $entry['stale'];
        }

        return [
            'available' => true,
            'complete' => (bool) $check['complete'],
            'units' => (int) $check['units'],
            'contexts' => \count((array) $check['contexts']),
            'missing' => $missing,
            'stale' => $stale,
            'gaps' => (int) $check['gaps'],
            'left_out' => array_map('intval', (array) $check['leftOut']),
            'pointers' => (int) ($check['pointers']['count'] ?? 0),
            'read' => $context['id'],
            'building' => $building,
        ];
    }

    /**
     * A section: its rows (the first LIST_CAP, their products named), the whole count, and whether it could be read.
     *
     * @param list<array> $rows The findings.
     * @param int $count How many there are.
     * @param bool $available False when the section needs a price index context that is not prepared.
     *
     * @return array{rows: list<array>, count: int, available: bool}
     *
     * @since  2.3.0
     */
    private function section(array $rows, int $count, bool $available = true): array
    {
        $rows = \array_slice($rows, 0, self::LIST_CAP);
        $ids = [];

        foreach ($rows as $row) {
            if ($row['item'] !== null) {
                $ids[(int) $row['item']['id']] = (int) $row['item']['id'];
            }
        }

        $items = $this->items(ids: array_values($ids));

        foreach ($rows as &$row) {
            if ($row['item'] !== null) {
                $id = (int) $row['item']['id'];
                $row['item'] = ($items[$id] ?? ['id' => $id, 'name' => '', 'sku' => '']) + ['combination_id' => (int) $row['item']['combination_id']];
            }
        }

        unset($row);

        return ['rows' => $rows, 'count' => $count, 'available' => $available];
    }

    /**
     * The engine's preview of a sale row under its own dimensions (the editor's), counted against the cap. Null beyond
     * the cap, or when the row could not be priced (logged); both count as not checked.
     *
     * @param object $row The price row.
     *
     * @return array|null See AlfapricesField::rowPreview().
     *
     * @since  2.3.0
     */
    private function preview(object $row): ?array
    {
        if ($this->engineRuns >= self::ENGINE_CAP) {
            $this->engineUnchecked++;

            return null;
        }

        $this->engineRuns++;

        try {
            return AlfapricesField::rowPreview(itemId: (int) $row->item_id, row: [
                'currency_id' => (int) $row->currency_id,
                'country_id' => (int) $row->country_id,
                'usergroup_id' => (int) $row->usergroup_id,
                'user_id' => (int) $row->user_id,
                'quantity_start' => $row->quantity_start,
                'quantity_end' => $row->quantity_end,
            ]);
        } catch (Throwable $e) {
            $this->engineUnchecked++;
            $this->log('price row #' . (int) $row->id . ' of product #' . (int) $row->item_id . ' could not be priced: ' . ErrorText::of($e));

            return null;
        }
    }

    /**
     * The products named: id, name in the user's language and sku, by id.
     *
     * @param int[] $ids The products.
     *
     * @return array<int, array{id: int, name: string, sku: string}>
     *
     * @since  2.3.0
     */
    private function items(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['a.id', 'a.sku']))
            ->from($db->quoteName('#__alfa_items', 'a'))
            ->whereIn($db->quoteName('a.id'), $ids);
        MultilingualHelper::addMultilingualJoinToQuery(query: $query, mainAlias: 'a', mainPrimaryColumn: 'id', langTableBase: '#__alfa_items', langPrimaryColumn: 'id_item', fields: ['name']);
        $items = [];

        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $items[(int) $row->id] = ['id' => (int) $row->id, 'name' => (string) ($row->name ?? ''), 'sku' => (string) ($row->sku ?? '')];
        }

        return $items;
    }

    /**
     * The rules named: kind, id and name in the user's language, by id.
     *
     * @param string $kind RuleReach::DISCOUNT or RuleReach::TAX.
     * @param int[] $ids The rules.
     *
     * @return array<int, array{kind: string, id: int, name: string}>
     *
     * @since  2.3.0
     */
    private function rules(string $kind, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $db = $this->db;
        [$table, $langKey] = $kind === RuleReach::TAX ? ['#__alfa_taxes', 'id_tax'] : ['#__alfa_discounts', 'id_discount'];
        $query = $db->getQuery(true)
            ->select($db->quoteName('a.id'))
            ->from($db->quoteName($table, 'a'))
            ->whereIn($db->quoteName('a.id'), $ids);
        MultilingualHelper::addMultilingualJoinToQuery(query: $query, mainAlias: 'a', mainPrimaryColumn: 'id', langTableBase: $table, langPrimaryColumn: $langKey, fields: ['name']);
        $rules = [];

        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $rules[(int) $row->id] = ['kind' => $kind, 'id' => (int) $row->id, 'name' => (string) ($row->name ?? '')];
        }

        return $rules;
    }

    /**
     * The index rows of a context that cover the report's moment.
     *
     * @param string $alias The index table's alias.
     * @param int $contextId The context.
     *
     * @since  2.3.0
     */
    private function coveringSql(string $alias, int $contextId): string
    {
        $db = $this->db;
        $now = $db->quote($this->now);

        return $db->quoteName($alias . '.context_id') . ' = ' . $contextId
            . ' AND ' . $db->quoteName($alias . '.valid_from') . ' <= ' . $now
            . ' AND (' . $db->quoteName($alias . '.valid_until') . ' IS NULL OR ' . $db->quoteName($alias . '.valid_until') . ' > ' . $now . ')';
    }

    /**
     * A discount rule (alias d) that lowers a price now: published, in its period, with a value.
     *
     * @since  2.3.0
     */
    private function activeDiscountSql(): string
    {
        $db = $this->db;

        return $db->quoteName('d.state') . ' = 1 AND ' . $db->quoteName('d.value') . ' <> 0 AND '
            . RulePeriod::sql(status: RulePeriod::ACTIVE, startColumn: 'd.publish_up', endColumn: 'd.publish_down', now: $this->now);
    }

    /**
     * A stored decimal times a factor, as a decimal of the catalogue's places.
     *
     * @param string $decimal The decimal.
     * @param float $factor The factor.
     *
     * @since  2.3.0
     */
    private function times(string $decimal, float $factor): string
    {
        return number_format((float) $decimal * $factor, PriceComputationEngine::UNIT_PLACES, '.', '');
    }

    /**
     * A line in the component log; a report never breaks on one figure it could not read.
     *
     * @param string $message What happened.
     *
     * @since  2.3.0
     */
    private function log(string $message): void
    {
        try {
            Log::add('Price check: ' . $message, Log::WARNING, 'com_alfa');
        } catch (Throwable) {
            // An unwritable log changes nothing that already happened.
        }
    }
}
