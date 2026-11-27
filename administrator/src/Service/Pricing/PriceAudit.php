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
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
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
 * The price check: what the prices of the catalogue say that a shop owner would not want them to say, as sections,
 * each a list of findings that link to the product, the price row, the discount or the tax.
 *
 * Read only, and never a pricing of the whole catalogue: the figures come from the price index (the resolved price of
 * every product for the anonymous visitor, the context the catalogue listing reads), from the price rows and from the
 * rules' scope links. The engine runs for one kind of finding only, the sale rows that have to be compared with the
 * taxes or the rules of their own context (the editor's own preview, one run per sale row).
 *
 * The check is computed by its job (PriceAuditJob) in bounded pieces from a checkpoint, never inside a page request:
 * the sections read as sets answer their count with one statement and their rows a page at a time, in a fixed order,
 * so no catalogue of ids is loaded; the sale rows are read by id after a cursor and judged in batches, every one of
 * them across the batches. What the job stores (PriceAuditStore) is what the page, the dashboard and the bell read.
 *
 * The sections, in the order the page shows them:
 *
 * - unpriced:            published products the index holds no valid price for, and, by option, products priced at 0;
 * - sale_above:          sale rows above the price they replace (the net price, or the price with taxes when the sale
 *                        pins the final price), rows saved before the save refused them included;
 * - sale_large:          sale rows taking more than the option's share of the price they replace;
 * - sale_below_rules:    sale rows that give less than the discount rules they replace would give;
 * - sale_open_ended:     by option, sale rows without an end date;
 * - rules_out_of_period: discounts and taxes published while their period has ended or has not started;
 * - untaxed:             published products no published tax in its period reaches, while such taxes exist;
 * - stacked:             products two or more rules reach together whose total discount in the index is above the
 *                        option's share;
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
     * The sections read as sets: one statement for the count, the rows a page at a time in a fixed order.
     *
     * @var list<string>
     *
     * @since  2.3.0
     */
    public const SET_SECTIONS = [
        self::SECTION_UNPRICED,
        self::SECTION_RULES_PERIOD,
        self::SECTION_UNTAXED,
        self::SECTION_STACKED,
        self::SECTION_INVERTED,
    ];

    /**
     * The sections the sale rows are judged into, by the engine.
     *
     * @var list<string>
     *
     * @since  2.3.0
     */
    public const SALE_SECTIONS = [
        self::SECTION_SALE_ABOVE,
        self::SECTION_SALE_LARGE,
        self::SECTION_SALE_BELOW_RULES,
        self::SECTION_SALE_OPEN,
    ];

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
     * The option that sets the share of the price, in percent, above which a sale or a stacked discount is a finding.
     *
     * @since  2.3.0
     */
    public const PARAM_LARGE_SHARE = 'price_audit_large_share';

    /**
     * The share the option starts at, in percent.
     *
     * @since  2.3.0
     */
    public const LARGE_SHARE_DEFAULT = 50;

    /**
     * @param DatabaseDriver $db The connection.
     * @param string $now The moment the check reads at (UTC, Y-m-d H:i:s): the engine's clock when it began.
     * @param array{id: int|null, currency_id: int, groups: int[]} $context The index context of the anonymous visitor.
     * @param array{zero: bool, open_sale: bool, large_share: float} $options The options as the check began.
     *
     * @since  2.3.0
     */
    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly string $now,
        private readonly array $context,
        private readonly array $options,
    ) {
    }

    /**
     * A check as it begins: the moment, the anonymous visitor's index context and the options read now, so every
     * piece of the check judges by the same ones.
     *
     * @param DatabaseDriver|null $db The connection; the site's when omitted.
     *
     * @since  2.3.0
     */
    public static function begin(?DatabaseDriver $db = null): self
    {
        $db ??= Factory::getContainer()->get('DatabaseDriver');

        return new self(db: $db, now: ActiveDateCondition::now(), context: self::readContext(), options: self::options());
    }

    /**
     * A check as it stands from a checkpoint: the moment, the context and the options it began with.
     *
     * @param DatabaseDriver $db The connection.
     * @param array $state The checkpoint's now, context and options.
     *
     * @since  2.3.0
     */
    public static function resume(DatabaseDriver $db, array $state): self
    {
        return new self(db: $db, now: (string) $state['now'], context: (array) $state['context'], options: (array) $state['options']);
    }

    /**
     * The options of the check as set in Options.
     *
     * @return array{zero: bool, open_sale: bool, large_share: float}
     *
     * @since  2.3.0
     */
    public static function options(): array
    {
        $params = ComponentHelper::getParams('com_alfa');
        $share = (float) $params->get(self::PARAM_LARGE_SHARE, self::LARGE_SHARE_DEFAULT);

        return [
            'zero' => (int) $params->get(self::PARAM_ZERO, 1) === 1,
            'open_sale' => (int) $params->get(self::PARAM_OPEN_SALE, 1) === 1,
            'large_share' => $share > 0 && $share < 100 ? $share : (float) self::LARGE_SHARE_DEFAULT,
        ];
    }

    /**
     * The moment the check reads at.
     *
     * @since  2.3.0
     */
    public function now(): string
    {
        return $this->now;
    }

    /**
     * The context the figures are read in.
     *
     * @return array{id: int|null, currency_id: int, groups: int[]}
     *
     * @since  2.3.0
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * The options the check judges by.
     *
     * @return array{zero: bool, open_sale: bool, large_share: float}
     *
     * @since  2.3.0
     */
    public function optionsUsed(): array
    {
        return $this->options;
    }

    /**
     * Whether a set section can be read: the ones that read the index need the anonymous visitor's context prepared.
     *
     * @param string $section A set section.
     *
     * @since  2.3.0
     */
    public function available(string $section): bool
    {
        return !\in_array($section, [self::SECTION_UNPRICED, self::SECTION_STACKED], true) || $this->context['id'] !== null;
    }

    /**
     * The count of a set section, in one statement; the untaxed section also says how many published taxes in their
     * period exist, since it judges nothing while none does.
     *
     * @param string $section A set section.
     *
     * @return array{count: int, taxes?: int}
     *
     * @since  2.3.0
     */
    public function count(string $section): array
    {
        if (!$this->available(section: $section)) {
            return ['count' => 0];
        }

        $db = $this->db;

        if ($section === self::SECTION_UNTAXED) {
            $taxes = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_taxes', 't') . ' WHERE ' . $this->activeTaxSql())->loadResult();

            if ($taxes === 0) {
                return ['count' => 0, 'taxes' => 0];
            }

            return ['count' => (int) $db->setQuery('SELECT COUNT(*) FROM ' . $this->setSql(section: $section) . ' AS s')->loadResult(), 'taxes' => $taxes];
        }

        return ['count' => (int) $db->setQuery('SELECT COUNT(*) FROM ' . $this->setSql(section: $section) . ' AS s')->loadResult()];
    }

    /**
     * One page of a set section's findings, in the section's fixed order.
     *
     * @param string $section A set section.
     * @param int $offset The rows before this page.
     * @param int $limit At most this many rows.
     *
     * @return list<array> The findings, each item => [id, combination_id] or null, rule => [kind, id, name] or null,
     *                     row_id, detail.
     *
     * @since  2.3.0
     */
    public function rows(string $section, int $offset, int $limit): array
    {
        if (!$this->available(section: $section) || $limit <= 0) {
            return [];
        }

        if ($section === self::SECTION_UNTAXED && ($this->count(section: $section)['taxes'] ?? 0) === 0) {
            return [];
        }

        $rows = $this->db->setQuery('SELECT s.* FROM ' . $this->setSql(section: $section) . ' AS s ORDER BY ' . $this->setOrder(section: $section), max(0, $offset), $limit)->loadObjectList() ?: [];

        return match ($section) {
            self::SECTION_UNPRICED => array_map(static fn (object $row): array => [
                'item' => ['id' => (int) $row->item_id, 'combination_id' => (int) $row->combination_id],
                'rule' => null,
                'row_id' => null,
                'detail' => (string) $row->kind === 'zero' ? ['kind' => 'zero', 'final_price' => (string) $row->final_price] : ['kind' => 'no_price'],
            ], $rows),
            self::SECTION_RULES_PERIOD => array_map(fn (object $row): array => [
                'item' => null,
                'rule' => ['kind' => (string) $row->kind, 'id' => (int) $row->id, 'name' => (string) ($row->name ?? '')],
                'row_id' => null,
                'detail' => [
                    'status' => RulePeriod::status(publishUp: $row->publish_up, publishDown: $row->publish_down, now: $this->now),
                    'starts_at' => RulePeriod::isMoment($row->publish_up) ? (string) $row->publish_up : null,
                    'ends_at' => RulePeriod::isMoment($row->publish_down) ? (string) $row->publish_down : null,
                ],
            ], $rows),
            self::SECTION_UNTAXED => array_map(static fn (object $row): array => [
                'item' => ['id' => (int) $row->item_id, 'combination_id' => 0],
                'rule' => null,
                'row_id' => null,
                'detail' => [],
            ], $rows),
            self::SECTION_STACKED => $this->stackedRows(rows: $rows),
            self::SECTION_INVERTED => array_map(static fn (object $row): array => [
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
            ], $rows),
            default => [],
        };
    }

    /**
     * How many sale rows the check judges: published price rows with a custom discount, of published products, whose
     * period has not ended.
     *
     * @since  2.3.0
     */
    public function saleCount(): int
    {
        return (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->salesSql())->loadResult();
    }

    /**
     * The next sale rows after a cursor, by id.
     *
     * @param int $after The last row id judged; 0 for the first.
     * @param int $limit At most this many rows.
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    public function saleRows(int $after, int $limit): array
    {
        $db = $this->db;

        return $db->setQuery(
            'SELECT ' . implode(', ', $db->quoteName([
                'p.id', 'p.item_id', 'p.combination_id', 'p.value', 'p.override_value', 'p.override_type', 'p.currency_id', 'p.country_id',
                'p.usergroup_id', 'p.user_id', 'p.quantity_start', 'p.quantity_end', 'p.publish_up', 'p.publish_down',
            ]))
            . ' FROM ' . $this->salesSql() . ' AND ' . $db->quoteName('p.id') . ' > ' . max(0, $after)
            . ' ORDER BY ' . $db->quoteName('p.id'),
            0,
            max(1, $limit),
        )->loadObjectList() ?: [];
    }

    /**
     * Judge a batch of sale rows four ways: above the price they replace, taking more than the option's share of it,
     * giving less than the rules they replace would give, and (by option) without an end date. The price with taxes,
     * and what the rules would give, come from the engine under the row's own dimensions (the editor's preview).
     *
     * @param list<object> $rows Sale rows, from saleRows().
     *
     * @return array{sections: array<string, list<array>>, runs: int, unchecked: int} The findings by sale section, the
     *                                                                                 engine runs, and the rows the
     *                                                                                 engine could not price (logged).
     *
     * @since  2.3.0
     */
    public function judgeSales(array $rows): array
    {
        $db = $this->db;
        $found = array_fill_keys(self::SALE_SECTIONS, []);
        $runs = 0;
        $unchecked = 0;
        $reached = [];

        if ($rows !== []) {
            // The sale rows' products that some discount rule reaches now: their sales are compared with the rules.
            $itemIds = array_values(array_unique(array_map(static fn (object $row): int => (int) $row->item_id, $rows)));
            $reached = array_flip(array_map('intval', $db->setQuery(
                'SELECT ' . $db->quoteName('a.id') . ' FROM ' . $db->quoteName('#__alfa_items', 'a')
                . ' WHERE ' . $db->quoteName('a.id') . ' IN (' . implode(',', $itemIds) . ')'
                . ' AND EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_discounts', 'd') . ' WHERE ' . $this->activeDiscountSql()
                . ' AND ' . RuleReach::sql(kind: RuleReach::DISCOUNT, ruleId: $db->quoteName('d.id'), itemId: $db->quoteName('a.id'), groupIds: $this->context['groups'], now: $this->now) . ')',
            )->loadColumn() ?: []));
        }

        $share = (float) $this->options['large_share'];

        foreach ($rows as $row) {
            $value = PriceComputationEngine::catalogueUnit(value: $row->value);
            $sale = PriceComputationEngine::catalogueUnit(value: $row->override_value);
            $final = (string) $row->override_type === 'final';
            $base = ['item' => ['id' => (int) $row->item_id, 'combination_id' => (int) $row->combination_id], 'rule' => null, 'row_id' => (int) $row->id];

            if ($this->options['open_sale'] && !RulePeriod::isMoment($row->publish_down)) {
                $found[self::SECTION_SALE_OPEN][] = $base + ['detail' => ['sale_price' => $sale, 'sale_pins' => (string) $row->override_type, 'starts_at' => $row->publish_up]];
            }

            // The engine is asked for a sale on the final price (the taxes it carries) and for a product the rules reach
            // (what they would give); a sale on the net price of a product no rule reaches is judged on the row alone.
            $preview = null;

            if ($final || isset($reached[(int) $row->item_id])) {
                $runs++;
                $preview = $this->preview(row: $row);

                if ($preview === null) {
                    $unchecked++;
                }
            }

            $replaced = $final ? ($preview === null ? null : $this->times(decimal: $value, factor: (float) $preview['taxFactor'])) : $value;

            if ($replaced !== null) {
                $detail = ['sale_price' => $sale, 'replaced_price' => $replaced, 'sale_pins' => (string) $row->override_type];

                if (ExactAmount::of($sale)->compare(ExactAmount::of($replaced)) > 0) {
                    $found[self::SECTION_SALE_ABOVE][] = $base + ['detail' => $detail];
                } elseif ((float) $replaced > 0) {
                    $taken = (((float) $replaced - (float) $sale) / (float) $replaced) * 100;

                    if ($taken > $share) {
                        $found[self::SECTION_SALE_LARGE][] = $base + ['detail' => $detail + ['share_percent' => round($taken, 1)]];
                    }
                }
            }

            if ($preview !== null && $preview['discounts'] !== []) {
                $decimals = (int) $preview['decimals'];
                $rulesGross = round(max(0.0, ((float) $value * (float) $preview['a']) + (float) $preview['b']), $decimals);
                $saleGross = round(max(0.0, (float) $sale) * ($final ? 1.0 : (float) $preview['taxFactor']), $decimals);

                if ($rulesGross < $saleGross) {
                    $found[self::SECTION_SALE_BELOW_RULES][] = $base + ['detail' => [
                        'sale_final_price' => number_format($saleGross, $decimals, '.', ''),
                        'rules_final_price' => number_format($rulesGross, $decimals, '.', ''),
                        'rules' => implode(', ', array_map(static fn (array $discount): string => (string) $discount['name'], $preview['discounts'])),
                    ]];
                }
            }
        }

        return ['sections' => $found, 'runs' => $runs, 'unchecked' => $unchecked];
    }

    /**
     * The counters of the price index check (PriceIndexSyncService::completeness()), and the context this check read.
     *
     * @return array{available: bool, complete: bool, units: int, contexts: int, missing: int, stale: int, gaps: int, left_out: int[], pointers: int, read: int|null, building: bool}
     *
     * @since  2.3.0
     */
    public function index(): array
    {
        $building = PriceIndexSyncService::buildStartedAt() !== null;

        try {
            $check = (new PriceIndexSyncService())->completeness();
        } catch (Throwable $e) {
            $this->log('the price index could not be checked: ' . ErrorText::of($e));

            return ['available' => false, 'complete' => false, 'units' => 0, 'contexts' => 0, 'missing' => 0, 'stale' => 0, 'gaps' => 0, 'left_out' => [], 'pointers' => 0, 'read' => $this->context['id'], 'building' => $building];
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
            'read' => $this->context['id'],
            'building' => $building,
        ];
    }

    /**
     * The sections with findings and their counts, from a stored summary; the index counts as one when not complete.
     *
     * @param array $summary The stored summary (PriceAuditStore::summary()).
     *
     * @return array<string, int>
     *
     * @since  2.3.0
     */
    public static function counts(array $summary): array
    {
        $counts = [];

        foreach ((array) ($summary['sections'] ?? []) as $key => $section) {
            if ((int) ($section['count'] ?? 0) > 0) {
                $counts[(string) $key] = (int) $section['count'];
            }
        }

        if (!(bool) ($summary['index']['complete'] ?? false)) {
            $counts[self::SECTION_INDEX] = 1;
        }

        return $counts;
    }

    /**
     * The findings in one sentence: "3 sales above the price, 1 rule published outside its period". Empty when there is
     * nothing to say.
     *
     * @param array $summary The stored summary.
     *
     * @since  2.3.0
     */
    public static function sentence(array $summary): string
    {
        return self::sentenceOf(
            counts: self::counts(summary: $summary),
            building: (bool) ($summary['index']['building'] ?? false),
            share: (string) ($summary['options']['large_share'] ?? self::LARGE_SHARE_DEFAULT),
        );
    }

    /**
     * The sentence from the counts alone (see counts()), for a reader that keeps the counts and not the summary.
     *
     * @param array<string, int> $counts The sections with findings and their counts.
     * @param bool $building Whether the price index is being built after the update.
     * @param string $share The share of the price the sales and the stacked rules were judged against, in percent.
     *
     * @since  2.3.0
     */
    public static function sentenceOf(array $counts, bool $building = false, string $share = ''): string
    {
        $parts = [];
        $share = AlfaHelper::percentDisplay(value: $share === '' ? (string) self::LARGE_SHARE_DEFAULT : $share);

        foreach ($counts as $key => $count) {
            if ($key === self::SECTION_INDEX) {
                $parts[] = Text::_($building ? 'COM_ALFA_PRICE_AUDIT_COUNT_INDEX_BUILDING' : 'COM_ALFA_PRICE_AUDIT_COUNT_INDEX');

                continue;
            }

            $parts[] = Text::plural('COM_ALFA_PRICE_AUDIT_COUNT_' . strtoupper((string) $key), (int) $count, $share);
        }

        return implode(', ', $parts);
    }

    /**
     * The products of a page of findings named: id, name in the user's language and sku, filled into each row's item.
     *
     * @param DatabaseDriver $db The connection.
     * @param list<array> $rows Stored findings.
     *
     * @return list<array> The same rows, every item named.
     *
     * @since  2.3.0
     */
    public static function named(DatabaseDriver $db, array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if (\is_array($row['item'] ?? null)) {
                $ids[(int) $row['item']['id']] = (int) $row['item']['id'];
            }
        }

        if ($ids === []) {
            return $rows;
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName(['a.id', 'a.sku']))
            ->from($db->quoteName('#__alfa_items', 'a'))
            ->whereIn($db->quoteName('a.id'), array_values($ids));
        MultilingualHelper::addMultilingualJoinToQuery(query: $query, mainAlias: 'a', mainPrimaryColumn: 'id', langTableBase: '#__alfa_items', langPrimaryColumn: 'id_item', fields: ['name']);
        $items = [];

        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $item) {
            $items[(int) $item->id] = ['id' => (int) $item->id, 'name' => (string) ($item->name ?? ''), 'sku' => (string) ($item->sku ?? '')];
        }

        foreach ($rows as &$row) {
            if (\is_array($row['item'] ?? null)) {
                $id = (int) $row['item']['id'];
                $row['item'] = ($items[$id] ?? ['id' => $id, 'name' => '', 'sku' => '']) + ['combination_id' => (int) ($row['item']['combination_id'] ?? 0)];
            }
        }

        unset($row);

        return $rows;
    }

    /**
     * The context the figures are read in: the anonymous visitor in the shop's currency with no place, the one the
     * catalogue listing prices for, and the live price index context prepared for it; none while the index holds no
     * such context (then the sections that read the index are not available).
     *
     * @return array{id: int|null, currency_id: int, groups: int[]}
     *
     * @since  2.3.0
     */
    private static function readContext(): array
    {
        $currencyId = 0;

        try {
            $currencyId = Currency::getDefault()->getId();
        } catch (Throwable $e) {
            self::warn('the shop currency could not be read: ' . ErrorText::of($e));
        }

        $read = null;

        if ($currencyId > 0) {
            try {
                $read = PriceIndexSyncService::readContext(
                    context: PriceContext::forIndex(currencyId: $currencyId, placeId: 0, usergroupId: 0)->withCustomer(customer: new User()),
                );
            } catch (Throwable $e) {
                self::warn('the price index context could not be read: ' . ErrorText::of($e));
            }
        }

        return ['id' => $read === null ? null : (int) $read['context'], 'currency_id' => $currencyId, 'groups' => RuleReach::guestGroups()];
    }

    /**
     * A set section as one derived table: the same statement counts it and pages it.
     *
     * @param string $section A set section.
     *
     * @since  2.3.0
     */
    private function setSql(string $section): string
    {
        $db = $this->db;
        $items = $db->quoteName('#__alfa_items', 'a');
        $published = $db->quoteName('a.state') . ' = 1';

        switch ($section) {
            case self::SECTION_UNPRICED:
                $covering = $this->coveringSql(alias: 'i', contextId: (int) $this->context['id']);
                $index = $db->quoteName(PriceIndexSyncService::INDEX_TABLE, 'i');
                $noPrice = 'SELECT ' . $db->quoteName('a.id', 'item_id') . ', 0 AS combination_id, ' . $db->quote('no_price') . ' AS kind, NULL AS final_price'
                    . ' FROM ' . $items . ' WHERE ' . $published
                    . ' AND EXISTS (SELECT 1 FROM ' . $index . ' WHERE ' . $db->quoteName('i.item_id') . ' = ' . $db->quoteName('a.id') . ' AND ' . $covering . ')'
                    . ' AND NOT EXISTS (SELECT 1 FROM ' . $index . ' WHERE ' . $db->quoteName('i.item_id') . ' = ' . $db->quoteName('a.id') . ' AND ' . $covering
                    . ' AND ' . $db->quoteName('i.final_price') . ' IS NOT NULL)';

                if (!$this->options['zero']) {
                    return '(' . $noPrice . ')';
                }

                $zero = 'SELECT ' . $db->quoteName('i.item_id') . ', ' . $db->quoteName('i.combination_id') . ', ' . $db->quote('zero') . ' AS kind, ' . $db->quoteName('i.final_price')
                    . ' FROM ' . $index . ' INNER JOIN ' . $items . ' ON ' . $db->quoteName('a.id') . ' = ' . $db->quoteName('i.item_id') . ' AND ' . $published
                    . ' WHERE ' . $covering . ' AND ' . $db->quoteName('i.final_price') . ' = 0';

                return '(' . $noPrice . ' UNION ALL ' . $zero . ')';

            case self::SECTION_RULES_PERIOD:
                $parts = [];

                foreach ([RuleReach::DISCOUNT => ['#__alfa_discounts', 'id_discount'], RuleReach::TAX => ['#__alfa_taxes', 'id_tax']] as $kind => [$table, $langKey]) {
                    $query = $db->getQuery(true)
                        ->select($db->quote($kind) . ' AS kind, ' . implode(', ', $db->quoteName(['a.id', 'a.publish_up', 'a.publish_down'])))
                        ->from($db->quoteName($table, 'a'))
                        ->where($db->quoteName('a.state') . ' = 1')
                        ->where('(' . RulePeriod::sql(status: RulePeriod::EXPIRED, startColumn: 'a.publish_up', endColumn: 'a.publish_down', now: $this->now)
                            . ' OR ' . RulePeriod::sql(status: RulePeriod::UPCOMING, startColumn: 'a.publish_up', endColumn: 'a.publish_down', now: $this->now) . ')');
                    MultilingualHelper::addMultilingualJoinToQuery(query: $query, mainAlias: 'a', mainPrimaryColumn: 'id', langTableBase: $table, langPrimaryColumn: $langKey, fields: ['name']);
                    $parts[] = (string) $query;
                }

                return '(' . implode(' UNION ALL ', $parts) . ')';

            case self::SECTION_UNTAXED:
                return '(SELECT ' . $db->quoteName('a.id', 'item_id') . ' FROM ' . $items . ' WHERE ' . $published
                    . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_taxes', 't') . ' WHERE ' . $this->activeTaxSql()
                    . ' AND ' . RuleReach::sql(kind: RuleReach::TAX, ruleId: $db->quoteName('t.id'), itemId: $db->quoteName('a.id'), groupIds: $this->context['groups'], now: $this->now) . '))';

            case self::SECTION_STACKED:
                $reaching = ' FROM ' . $db->quoteName('#__alfa_discounts', 'd') . ' WHERE ' . $this->activeDiscountSql()
                    . ' AND ' . RuleReach::sql(kind: RuleReach::DISCOUNT, ruleId: $db->quoteName('d.id'), itemId: $db->quoteName('a.id'), groupIds: $this->context['groups'], now: $this->now);

                return '(SELECT ' . $db->quoteName('a.id', 'item_id') . ', ' . $db->quoteName('i.discount_percent')
                    . ', (SELECT GROUP_CONCAT(' . $db->quoteName('d.id') . ' ORDER BY ' . $db->quoteName('d.ordering') . ', ' . $db->quoteName('d.id') . ')' . $reaching . ') AS rule_ids'
                    . ' FROM ' . $items
                    . ' INNER JOIN ' . $db->quoteName(PriceIndexSyncService::INDEX_TABLE, 'i') . ' ON ' . $db->quoteName('i.item_id') . ' = ' . $db->quoteName('a.id')
                    . ' AND ' . $db->quoteName('i.combination_id') . ' = 0 AND ' . $this->coveringSql(alias: 'i', contextId: (int) $this->context['id'])
                    . ' WHERE ' . $published
                    . ' AND ' . $db->quoteName('i.discount_percent') . ' > ' . (float) $this->options['large_share']
                    . ' AND (SELECT COUNT(*)' . $reaching . ') >= 2'
                    . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_items_prices', 'p') . ' WHERE ' . $db->quoteName('p.item_id') . ' = ' . $db->quoteName('a.id')
                    . ' AND ' . $db->quoteName('p.combination_id') . ' IS NULL AND ' . $db->quoteName('p.state') . ' = 1 AND ' . $db->quoteName('p.mode') . ' = ' . $db->quote('price')
                    . ' AND ' . $db->quoteName('p.override_value') . ' IS NOT NULL AND ' . RulePeriod::sql(status: RulePeriod::ACTIVE, startColumn: 'p.publish_up', endColumn: 'p.publish_down', now: $this->now) . '))';

            case self::SECTION_INVERTED:
                $same = static fn (string $column): string => 'COALESCE(' . $db->quoteName('q.' . $column) . ', 0) = COALESCE(' . $db->quoteName('p.' . $column) . ', 0)';
                $price = $db->quote('price');

                return '(SELECT ' . $db->quoteName('p.id', 'period_row') . ', ' . $db->quoteName('q.id', 'sale_row') . ', ' . $db->quoteName('p.item_id')
                    . ', COALESCE(' . $db->quoteName('p.combination_id') . ', 0) AS combination_id'
                    . ', ' . implode(', ', $db->quoteName(['p.publish_up', 'p.publish_down', 'p.value', 'q.override_value', 'q.override_type']))
                    . ' FROM ' . $db->quoteName('#__alfa_items_prices', 'p')
                    . ' INNER JOIN ' . $items . ' ON ' . $db->quoteName('a.id') . ' = ' . $db->quoteName('p.item_id') . ' AND ' . $published
                    . ' INNER JOIN ' . $db->quoteName('#__alfa_items_prices', 'q') . ' ON ' . $db->quoteName('q.item_id') . ' = ' . $db->quoteName('p.item_id')
                    . ' AND ' . $db->quoteName('q.id') . ' <> ' . $db->quoteName('p.id') . ' AND ' . $db->quoteName('q.state') . ' = 1 AND ' . $db->quoteName('q.mode') . ' = ' . $price
                    . ' AND ' . $db->quoteName('q.override_value') . ' IS NOT NULL'
                    . ' AND NOT ' . RulePeriod::momentSql($db->quoteName('q.publish_up')) . ' AND NOT ' . RulePeriod::momentSql($db->quoteName('q.publish_down'))
                    . ' AND ' . implode(' AND ', array_map($same, ['combination_id', 'currency_id', 'usergroup_id', 'user_id', 'country_id', 'quantity_start', 'quantity_end']))
                    . ' WHERE ' . $db->quoteName('p.state') . ' = 1 AND ' . $db->quoteName('p.mode') . ' = ' . $price . ' AND ' . $db->quoteName('p.override_value') . ' IS NULL'
                    . ' AND (' . RulePeriod::momentSql($db->quoteName('p.publish_up')) . ' OR ' . RulePeriod::momentSql($db->quoteName('p.publish_down')) . ')'
                    . ' AND ' . (new ActiveDateCondition())->getNotEndedCondition(endField: 'p.publish_down', referenceDate: $this->now) . ')';
        }

        return '(SELECT NULL AS item_id WHERE 0 = 1)';
    }

    /**
     * The fixed order of a set section's rows, by which its pages follow one another.
     *
     * @param string $section A set section.
     *
     * @since  2.3.0
     */
    private function setOrder(string $section): string
    {
        $db = $this->db;

        return match ($section) {
            self::SECTION_UNPRICED => $db->quoteName('s.item_id') . ', ' . $db->quoteName('s.combination_id') . ', ' . $db->quoteName('s.kind'),
            self::SECTION_RULES_PERIOD => $db->quoteName('s.kind') . ', ' . $db->quoteName('s.id'),
            self::SECTION_STACKED => $db->quoteName('s.discount_percent') . ' DESC, ' . $db->quoteName('s.item_id'),
            self::SECTION_INVERTED => $db->quoteName('s.item_id') . ', ' . $db->quoteName('s.period_row'),
            default => $db->quoteName('s.item_id'),
        };
    }

    /**
     * The stacked section's rows, the rules of each named in the user's language.
     *
     * @param list<object> $rows One page, from the set.
     *
     * @return list<array>
     *
     * @since  2.3.0
     */
    private function stackedRows(array $rows): array
    {
        $ruleIds = [];

        foreach ($rows as $row) {
            foreach (explode(',', (string) $row->rule_ids) as $id) {
                $ruleIds[(int) $id] = (int) $id;
            }
        }

        $names = $this->rules(kind: RuleReach::DISCOUNT, ids: array_values($ruleIds));
        $out = [];

        foreach ($rows as $row) {
            $ids = array_map('intval', explode(',', (string) $row->rule_ids));
            $out[] = [
                'item' => ['id' => (int) $row->item_id, 'combination_id' => 0],
                'rule' => null,
                'row_id' => null,
                'detail' => [
                    'rules' => implode(', ', array_map(static fn (int $id) => (string) ($names[$id]['name'] ?? ('#' . $id)), $ids)),
                    'rule_count' => \count($ids),
                    'discount_percent' => (string) $row->discount_percent,
                ],
            ];
        }

        return $out;
    }

    /**
     * The sale rows the check judges, as the FROM and WHERE of a statement over alias p: published price rows with a
     * custom discount, of published products, whose period has not ended.
     *
     * @since  2.3.0
     */
    private function salesSql(): string
    {
        $db = $this->db;

        return $db->quoteName('#__alfa_items_prices', 'p')
            . ' INNER JOIN ' . $db->quoteName('#__alfa_items', 'a') . ' ON ' . $db->quoteName('a.id') . ' = ' . $db->quoteName('p.item_id') . ' AND ' . $db->quoteName('a.state') . ' = 1'
            . ' WHERE ' . $db->quoteName('p.state') . ' = 1 AND ' . $db->quoteName('p.mode') . ' = ' . $db->quote('price')
            . ' AND ' . $db->quoteName('p.override_value') . ' IS NOT NULL'
            . ' AND ' . (new ActiveDateCondition())->getNotEndedCondition(endField: 'p.publish_down', referenceDate: $this->now);
    }

    /**
     * The engine's preview of a sale row under its own dimensions (the editor's). Null when the row could not be priced
     * (logged): the row counts as not checked.
     *
     * @param object $row The price row.
     *
     * @return array|null See AlfapricesField::rowPreview().
     *
     * @since  2.3.0
     */
    private function preview(object $row): ?array
    {
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
            $this->log('price row #' . (int) $row->id . ' of product #' . (int) $row->item_id . ' could not be priced: ' . ErrorText::of($e));

            return null;
        }
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
     * The index rows of a context that cover the check's moment.
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
     * A tax (alias t) published and in its period now.
     *
     * @since  2.3.0
     */
    private function activeTaxSql(): string
    {
        return $this->db->quoteName('t.state') . ' = 1 AND ' . RulePeriod::sql(status: RulePeriod::ACTIVE, startColumn: 't.publish_up', endColumn: 't.publish_down', now: $this->now);
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
     * A line in the component log; a check never breaks on one figure it could not read.
     *
     * @param string $message What happened.
     *
     * @since  2.3.0
     */
    private function log(string $message): void
    {
        self::warn(message: $message);
    }

    /**
     * @param string $message What happened.
     *
     * @since  2.3.0
     */
    private static function warn(string $message): void
    {
        try {
            Log::add('Price check: ' . $message, Log::WARNING, 'com_alfa');
        } catch (Throwable) {
            // An unwritable log changes nothing that already happened.
        }
    }
}
