<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaCatalog\Site\Helper;

use Alfa\Component\Alfa\Site\Helper\PageCache;
use Alfa\Component\Alfa\Site\Helper\PriceSettings;
use Alfa\Module\AlfaCatalog\Site\Provider\ProductsProvider;
use Alfa\Module\AlfaCatalog\Site\Provider\Providers;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Input\Input;
use Joomla\Registry\Registry;
use stdClass;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Resolves what a catalog module shows and renders it.
 *
 * The module IS its tabs: each configured row is one listing of one kind (products, categories or manufacturers)
 * with its own selection, order and size, shown as a grid, a slider or a list; one tab renders alone, several as a
 * tab strip. A products row may also stand for several tabs at once: one per chosen category, manufacturer or label,
 * each showing that subject's products.
 *
 * The helper knows tabs, not records: what a tab shows is read by the provider of its kind (Provider\*), through the
 * component's models and services, and drawn by the module's own layouts, so an override of a card or a list
 * changes the module and nothing in the shop.
 *
 * @since  2.3.0
 */
final class AlfaCatalogHelper
{
    /**
     * @var int Screens up to this width are tablets: the second column count / slides-per-view applies.
     * @since  2.3.0
     */
    public const TABLET = 1023;

    /**
     * @var int Screens up to this width are phones: the third column count / slides-per-view applies.
     * @since  2.3.0
     */
    public const MOBILE = 767;

    /**
     * @var array<int, array{key: string, max: int}> The module's two breakpoints, widest first: what a card setting may be set again for.
     * @since  2.3.0
     */
    public const BREAKPOINTS = [
        ['key' => 'tablet', 'max' => self::TABLET],
        ['key' => 'mobile', 'max' => self::MOBILE],
    ];

    /**
     * @var int The most tabs one row makes when it stands for one tab per category, manufacturer or label.
     * @since  2.3.0
     */
    public const AUTO_TABS_MAX = 12;

    /**
     * @var int The most tabs a module draws.
     * @since  2.3.0
     */
    public const TABS_MAX = 24;

    /**
     * @var string Group key prefix of a tab (followed by its row index).
     * @since  2.3.0
     */
    private const TAB_KEY = 'tab-';

    /**
     * The row field that holds the subjects of an automatic row, and the letter its tabs' keys carry, by subject type.
     *
     * @var array<string, array{0: string, 1: string}>
     * @since  2.3.0
     */
    private const AUTO = [
        Subjects::CATEGORY => ['categories', 'c'],
        Subjects::MANUFACTURER => ['manufacturers', 'm'],
        Subjects::LABEL => ['labels', 'l'],
    ];

    /**
     * @var array<string, string> The name of a kind's own layouts: its card and its list.
     * @since  2.3.0
     */
    private const LAYOUT = [
        Providers::KIND_PRODUCTS => 'product',
        Providers::KIND_CATEGORIES => 'category',
        Providers::KIND_MANUFACTURERS => 'manufacturer',
    ];

    /**
     * @var array<string, string> Layout files resolved this request, by name.
     * @since  2.3.0
     */
    private static array $layoutPaths = [];

    /**
     * @var array<string, string> The card endpoint of each place a card is shown, routed once per request.
     * @since  2.3.0
     */
    private static array $cardEndpoints = [];

    // Tabs

    /**
     * What each tab IS, before any record is read: its key, label, kind, presentation, query (what its provider
     * reads), "view all" link and own look. A products row set to one tab per category, manufacturer or label
     * becomes one tab per chosen subject this visitor is shown, each with that subject alone. The names every tab
     * needs are read together, one read per type for the whole module.
     *
     * @param Registry $params Module parameters.
     *
     * @return list<array{key: string, label: string, kind: string, display: string, query: array, link: string, look: array, subject: array|null, eager: bool}>
     *
     * @since  2.3.0
     */
    public static function definitions(Registry $params): array
    {
        $rows = array_map(static fn (mixed $row): array => (array) $row, array_values((array) $params->get('tabs', [])));
        $wanted = array_fill_keys(Subjects::TYPES, []);

        foreach ($rows as $row) {
            $kind = Providers::kind($row['kind'] ?? '');

            if ($kind === Providers::KIND_PRODUCTS) {
                $wanted[Subjects::CATEGORY] = array_merge($wanted[Subjects::CATEGORY], Providers::ids($row['categories'] ?? []));
                $auto = self::auto($row);

                if ($auto !== '' && $auto !== Subjects::CATEGORY) {
                    $wanted[$auto] = array_merge($wanted[$auto], Providers::ids($row[self::AUTO[$auto][0]] ?? []));
                }
            } elseif ($kind === Providers::KIND_CATEGORIES && (int) ($row['cat_parent'] ?? 0) > 0) {
                $wanted[Subjects::CATEGORY][] = (int) $row['cat_parent'];
            }
        }

        foreach ($wanted as $type => $ids) {
            if ($ids !== []) {
                Subjects::prime(type: $type, ids: $ids);
            }
        }

        $module = CardLook::level($params->toArray());
        $definitions = [];

        foreach ($rows as $index => $row) {
            $kind = Providers::kind($row['kind'] ?? '');
            $look = CardLook::level($row);
            $display = (string) CardLook::resolve(levels: [$module, $look], breakpoints: [])['']['display'];
            $auto = $kind === Providers::KIND_PRODUCTS ? self::auto($row) : '';
            $subjects = $auto !== '' ? Subjects::names(type: $auto, ids: Providers::ids($row[self::AUTO[$auto][0]] ?? [])) : [];

            // One tab per subject; a row with no subject chosen (or none this visitor is shown) is one plain tab.
            $tabs = [];

            foreach (\array_slice($subjects, 0, self::AUTO_TABS_MAX, true) as $id => $name) {
                $tabs[] = [
                    'key' => self::TAB_KEY . $index . '-' . self::AUTO[$auto][1] . $id,
                    'row' => [self::AUTO[$auto][0] => [$id]] + $row,
                    'label' => $name,
                    'subject' => ['type' => $auto, 'id' => (int) $id, 'name' => $name],
                ];
            }

            if ($tabs === []) {
                $tabs[] = ['key' => self::TAB_KEY . $index, 'row' => $row, 'label' => null, 'subject' => null];
            }

            foreach ($tabs as $tab) {
                $query = Providers::of($kind)->query(row: $tab['row'], display: $display);

                if ($query === [] || \count($definitions) >= self::TABS_MAX) {
                    continue;
                }

                $definitions[] = [
                    'key' => $tab['key'],
                    'label' => $tab['label'] ?? self::label(row: $row, kind: $kind, query: $query),
                    'kind' => $kind,
                    'display' => $display,
                    'query' => $query,
                    'link' => (int) ($row['show_link'] ?? 0) === 1 ? self::viewAllUrl(kind: $kind, query: $query) : '',
                    'look' => $look,
                    'subject' => $tab['subject'],
                    // A list of categories marks the visitor's category, which only the page's own request names.
                    'eager' => $kind === Providers::KIND_CATEGORIES && $display === 'list',
                ];
            }
        }

        return $definitions;
    }

    /**
     * What a products row makes one tab of each: a category, a manufacturer or a label; '' for one plain tab.
     *
     * @param array $row The stored tab row.
     *
     * @since  2.3.0
     */
    private static function auto(array $row): string
    {
        $auto = (string) ($row['auto'] ?? '');

        return isset(self::AUTO[$auto]) ? $auto : '';
    }

    /**
     * A plain tab's title: its own label (a language key is translated), else what it shows: the names of its
     * categories, its parent category, or the name of its source or kind.
     *
     * @param array $row The stored tab row.
     * @param string $kind The tab's kind.
     * @param array $query The tab's query.
     *
     * @since  2.3.0
     */
    private static function label(array $row, string $kind, array $query): string
    {
        $label = trim((string) ($row['label'] ?? ''));

        if ($label !== '') {
            return Text::_($label);
        }

        if ($kind === Providers::KIND_PRODUCTS) {
            $ids = (int) $query['context'] > 0 ? [(int) $query['context']] : (array) $query['scope'];
            $names = Subjects::names(type: Subjects::CATEGORY, ids: $ids);

            return $names !== [] ? implode(', ', $names) : Text::_('MOD_ALFA_CATALOG_SOURCE_' . strtoupper((string) $query['source']));
        }

        if ($kind === Providers::KIND_CATEGORIES && (int) $query['parent'] > 0) {
            $names = Subjects::names(type: Subjects::CATEGORY, ids: [(int) $query['parent']]);

            if ($names !== []) {
                return (string) reset($names);
            }
        }

        return Text::_('MOD_ALFA_CATALOG_KIND_' . strtoupper($kind));
    }

    /**
     * Where "view all" of a tab leads. Products: one category = that category's page; several = the catalogue
     * filtered to them; none = the whole catalogue, with the tab's manufacturers, labels, sale or new filter and a
     * non-default order, so the page shows the tab's listing in full. Best sellers and recently viewed have no
     * catalogue page that ranks like them: they link to their single category, or nowhere. Categories inside a
     * parent lead to that parent's page; manufacturers to the shop's Manufacturers page.
     *
     * @param string $kind The tab's kind.
     * @param array $query The tab's query.
     *
     * @return string A routed URL, or '' when there is nowhere to go.
     *
     * @since  2.3.0
     */
    private static function viewAllUrl(string $kind, array $query): string
    {
        if ($kind === Providers::KIND_MANUFACTURERS) {
            return Route::_('index.php?option=com_alfa&view=manufacturers');
        }

        if ($kind === Providers::KIND_CATEGORIES) {
            return (int) $query['parent'] > 0 ? Route::_('index.php?option=com_alfa&view=items&category_id=' . (int) $query['parent']) : '';
        }

        $single = (int) $query['context'];
        $source = (string) $query['source'];
        $url = 'index.php?option=com_alfa&view=items';

        if ($single > 0) {
            $url .= '&category_id=' . $single;
        }

        if ($source === ProductsProvider::SOURCE_BEST_SELLERS || $source === ProductsProvider::SOURCE_RECENTLY_VIEWED) {
            return $single > 0 ? Route::_($url) : '';
        }

        foreach (['scope' => 'category', 'manufacturers' => 'manufacturer', 'labels' => 'label'] as $key => $filter) {
            foreach ((array) $query[$key] as $id) {
                $url .= '&filter[' . $filter . '][]=' . (int) $id;
            }
        }

        if ($source === ProductsProvider::SOURCE_ON_SALE) {
            $url .= '&filter[on_sale]=1';
        }

        if ($source === ProductsProvider::SOURCE_NEW) {
            $url .= '&filter[new]=1';
        }

        if ($query['ordering'] !== ProductsProvider::DEFAULT_ORDERING) {
            $url .= '&list[fullordering]=' . rawurlencode((string) $query['ordering']);
        }

        return Route::_($url);
    }

    // Groups

    /**
     * The groups the module renders, in order: one per tab the visitor may see. With several tabs only the first
     * non-empty one carries records; the rest are fetched when opened: a tab strip never pays for what nobody looks
     * at. A list of categories is the exception (see definitions()). A group found empty on the way is dropped
     * rather than offered as a tab that opens on nothing.
     *
     * @param Registry $params Module parameters.
     * @param int $moduleId Module instance id.
     * @param string|null $onlyKey Resolve just this group, with its records (the on-demand fetch).
     *
     * @return list<array> Each a definition (definitions()) with `items` (its records, or null) and `lazy`.
     *
     * @since  2.3.0
     */
    public static function getGroups(Registry $params, int $moduleId, ?string $onlyKey = null): array
    {
        $definitions = self::definitions($params);
        $lazy = $onlyKey === null && \count($definitions) > 1;
        $groups = [];
        $eagerDone = false;

        foreach ($definitions as $definition) {
            if ($onlyKey !== null && $onlyKey !== $definition['key']) {
                continue;
            }

            if ($lazy && $eagerDone && !$definition['eager']) {
                $groups[] = $definition + ['items' => null, 'lazy' => true];

                continue;
            }

            $items = self::records(moduleId: $moduleId, params: $params, definition: $definition);

            if ($items === [] && $onlyKey === null) {
                continue;
            }

            $groups[] = $definition + ['items' => $items, 'lazy' => false];
            $eagerDone = true;
        }

        return $groups;
    }

    /**
     * A tab's records through Joomla's module cache, the way Joomla's own modules read their lists: the Advanced
     * tab's Caching and Cache Time decide, Global Configuration must have caching on, and a logged-in visitor always
     * reads fresh. What is stored is the records, never markup: a card, with its add-to-cart token, is rendered on
     * every request.
     *
     * One entry per module, kind, query and whatever the kind's provider says its result depends on (varies()); the
     * cache keeps languages apart itself. So a showcase (cards of any kind) is one entry per page whatever category
     * the visitor is in, while a list of categories, whose query names the visitor's category, is one entry per
     * category. A result that is one visitor's own is never stored, and the page that shows it is kept out of
     * Joomla's page cache: the next visitor would be shown it.
     *
     * @param int $moduleId Module instance id.
     * @param Registry $params Module parameters (cache settings).
     * @param array $definition A tab (definitions()).
     *
     * @return list<object> Records, in display order.
     *
     * @since  2.3.0
     */
    public static function records(int $moduleId, Registry $params, array $definition): array
    {
        $varies = Providers::of($definition['kind'])->varies(query: $definition['query'], params: $params);

        if ($varies === null) {
            $records = self::read(kind: $definition['kind'], query: $definition['query'], params: $params);

            if ($records !== []) {
                PageCache::excludeResponse();
            }

            return $records;
        }

        $cache = new stdClass();
        $cache->cachemode = 'id';
        $cache->class = self::class;
        $cache->method = 'read';
        $cache->methodparams = [$definition['kind'], $definition['query'], $params];
        $cache->modeparams = self::cacheId(moduleId: $moduleId, kind: $definition['kind'], query: $definition['query'], varies: $varies);

        $records = ModuleHelper::moduleCache((object) ['id' => $moduleId, 'module' => 'mod_alfa_catalog'], $params, $cache);

        return \is_array($records) ? array_values($records) : [];
    }

    /**
     * The id of a tab's cache entry.
     *
     * @param int $moduleId Module instance id.
     * @param string $kind The tab's kind.
     * @param array $query The tab's query.
     * @param array $varies What the kind's provider says the result depends on.
     *
     * @since  2.3.0
     */
    public static function cacheId(int $moduleId, string $kind, array $query, array $varies): string
    {
        return md5(serialize([$moduleId, $kind, $query, $varies]));
    }

    /**
     * Read a tab's records from the provider of its kind. Public: the cache controller calls it back.
     *
     * @param string $kind The tab's kind.
     * @param array $query The tab's query.
     * @param Registry $params Module parameters.
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    public static function read(string $kind, array $query, Registry $params): array
    {
        return Providers::of(Providers::kind($kind))->records(query: $query, params: $params);
    }

    // Rendering

    /**
     * Render one group (a grid, a slider or a list) through the module's group layout, template overrides
     * included. Used by the page render and by the on-demand tab fetch alike, so both produce the same markup.
     *
     * @param object $module The module instance (id, params, layout).
     * @param Registry $params Module parameters.
     * @param array $group A group as getGroups() builds it, with its records.
     *
     * @since  2.3.0
     */
    public static function renderGroup(object $module, Registry $params, array $group): string
    {
        $path = self::layoutPath($params, '_group');

        if ($path === '') {
            return '';
        }

        // The tab: the module's settings, then its own; for a slider, its settings on every device. Every card of
        // it is named after its place: the module and the tab.
        $resolved = CardLook::resolve(levels: [CardLook::level($params->toArray()), $group['look'] ?? []], breakpoints: self::BREAKPOINTS);
        $display = [
            'mode' => (string) ($group['display'] ?? $resolved['']['display']),
            'bands' => CardLook::bands($resolved, self::BREAKPOINTS),
            'place' => (int) $module->id . '-' . $group['key'],
        ];
        $moduleId = (int) $module->id;

        ob_start();

        try {
            include $path;

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Render one card through the layout of its kind (`_card_product`, `_card_category`, `_card_manufacturer`),
     * template overrides first.
     *
     * @param array $group The tab the card is shown in (its kind, key and query).
     * @param object $record The record: a decorated product, a category or a manufacturer.
     * @param Registry $params Module parameters (the layout choice).
     * @param string $place The card's place, which its rules are written for (CardLook::css).
     * @param int $moduleId Module instance id.
     *
     * @since  2.3.0
     */
    public static function renderCard(array $group, object $record, Registry $params, string $place, int $moduleId): string
    {
        $kind = Providers::kind($group['kind'] ?? '');
        $path = self::layoutPath($params, '_card_' . self::LAYOUT[$kind]);

        if ($path === '') {
            return '';
        }

        $options = (array) ($group['query'] ?? []);

        if ($kind === Providers::KIND_PRODUCTS) {
            $item = $record;
            $priceSettings = PriceSettings::get();
            $reloadUrl = self::reloadUrl(moduleId: $moduleId, tab: (string) $group['key'], item: $record);
        }

        ob_start();

        try {
            include $path;

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * The layout file that draws a tab as a list, by its kind: `_list_product`, `_list_category`,
     * `_list_manufacturer`, template overrides first.
     *
     * @param Registry $params Module parameters.
     * @param string $kind The tab's kind.
     * @param string $part '' for the list itself, or the suffix of a part of it, e.g. `_level`.
     *
     * @return string The path, or '' when no such layout exists.
     *
     * @since  2.3.0
     */
    public static function listLayout(Registry $params, string $kind, string $part = ''): string
    {
        return self::layoutPath($params, '_list_' . self::LAYOUT[Providers::kind($kind)] . $part);
    }

    /**
     * Where a product card re-draws itself when its buyer changes a variant or an option: this module's card
     * endpoint for the tab it is shown in, which renders it again, with the same look, for the new selection. Only
     * a product with something to choose has one: the catalogue gives exactly those an address of their own.
     *
     * @param int $moduleId Module instance id.
     * @param string $tab The key of the tab the card is shown in.
     * @param object $item The decorated product.
     *
     * @return string A rooted, SEF-aware URL; '' when the product has nothing to choose.
     *
     * @since  2.3.0
     */
    public static function reloadUrl(int $moduleId, string $tab, object $item): string
    {
        if ((string) ($item->reloadUrl ?? '') === '') {
            return '';
        }

        // Routed once per place; the product rides behind it.
        $endpoint = self::$cardEndpoints[$moduleId . ':' . $tab] ??= Route::_(
            'index.php?option=com_ajax&module=alfa_catalog&method=getCard&format=raw&module_id=' . $moduleId . '&tab=' . rawurlencode($tab),
            false,
        );

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . 'id=' . (int) $item->id;
    }

    /**
     * A layout file of this module for the instance's chosen layout, template overrides first; resolved once per
     * request.
     *
     * @param Registry $params Module parameters.
     * @param string $suffix The sub-layout suffix, e.g. `_card_product`.
     *
     * @return string The path, or '' when no such layout exists.
     *
     * @since  2.3.0
     */
    private static function layoutPath(Registry $params, string $suffix): string
    {
        $name = (string) $params->get('layout', 'default') . $suffix;

        if (!isset(self::$layoutPaths[$name])) {
            $path = ModuleHelper::getLayoutPath('mod_alfa_catalog', $name);
            self::$layoutPaths[$name] = is_file($path) ? $path : '';
        }

        return self::$layoutPaths[$name];
    }

    // Per-instance CSS

    /**
     * The card rules of a module instance (CardLook::css): one place per tab of cards, fetched yet or not, so a tab
     * opened later (or a card drawn again for another variant) finds its rules in the page. A list has no cards.
     *
     * @param int $moduleId Module instance id.
     * @param Registry $params Module parameters.
     * @param array $tabs The module's tabs (definitions() or getGroups()).
     *
     * @return string CSS.
     *
     * @since  2.3.0
     */
    public static function lookCss(int $moduleId, Registry $params, array $tabs): string
    {
        $level = CardLook::level($params->toArray());
        $places = [];

        foreach ($tabs as $tab) {
            if ($tab['display'] !== 'list') {
                $places[$moduleId . '-' . $tab['key']] = CardLook::resolve(levels: [$level, $tab['look'] ?? []], breakpoints: self::BREAKPOINTS);
            }
        }

        return CardLook::css(places: $places, breakpoints: self::BREAKPOINTS);
    }

    /**
     * The per-instance CSS of the grids: every grid tab's gap and columns on wide screens, then what changes at the
     * module's two widths (per tab, since a tab may set its own).
     *
     * @param int $moduleId Module instance id.
     * @param Registry $params Module parameters.
     * @param array $tabs The module's tabs (definitions() or getGroups()).
     *
     * @return string CSS.
     *
     * @since  2.3.0
     */
    public static function responsiveCss(int $moduleId, Registry $params, array $tabs): string
    {
        $level = CardLook::level($params->toArray());
        $rules = [];

        foreach ($tabs as $tab) {
            if ($tab['display'] !== 'grid') {
                continue;
            }

            // The child combinator: a panel of this module's own, never one of a module nested inside it.
            $selector = '.mod-alfa-catalog-' . $moduleId . ' > [data-panel="' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $tab['key']) . '"]';
            $before = [];

            foreach (CardLook::resolve(levels: [$level, $tab['look'] ?? []], breakpoints: self::BREAKPOINTS) as $device => $there) {
                $now = ['--mod-alfa-catalog-gap' => (int) $there['gap'] . 'px', '--mod-alfa-catalog-columns' => (string) (int) $there['columns']];
                $rule = '';

                foreach (array_diff_assoc($now, $before) as $property => $value) {
                    $rule .= $property . ':' . $value . ';';
                }

                // Tabs that say the same share the rule.
                if ($rule !== '') {
                    $rules[$device][rtrim($rule, ';')][] = $selector;
                }

                $before = $now;
            }
        }

        $out = '';

        foreach (array_merge([['key' => '', 'max' => 0]], self::BREAKPOINTS) as $breakpoint) {
            $css = '';

            foreach ($rules[$breakpoint['key']] ?? [] as $body => $selectors) {
                $css .= implode(',', $selectors) . '{' . $body . '}';
            }

            if ($css !== '') {
                $out .= (int) $breakpoint['max'] > 0 ? '@media (max-width:' . (int) $breakpoint['max'] . 'px){' . $css . '}' : $css;
            }
        }

        return $out;
    }

    /**
     * What the page must load for these tabs, fetched yet or not: nothing a tab does not use.
     *
     *   script  the module's script: a tab strip, or a slider;
     *   slider  the slider library: a tab shown as a slider;
     *   shop    the component's styles for what it draws in a product (price, stock): a products tab;
     *   buy     the component's buying scripts: products shown as cards (a list row has no buy form).
     *
     * @param array $tabs The module's tabs (definitions() or getGroups()).
     *
     * @return array{script: bool, slider: bool, shop: bool, buy: bool}
     *
     * @since  2.3.0
     */
    public static function needs(array $tabs): array
    {
        $needs = ['script' => \count($tabs) > 1, 'slider' => false, 'shop' => false, 'buy' => false];

        foreach ($tabs as $tab) {
            $products = $tab['kind'] === Providers::KIND_PRODUCTS;

            $needs['slider'] = $needs['slider'] || $tab['display'] === 'slider';
            $needs['shop'] = $needs['shop'] || $products;
            $needs['buy'] = $needs['buy'] || ($products && $tab['display'] !== 'list');
        }

        $needs['script'] = $needs['script'] || $needs['slider'];

        return $needs;
    }

    /**
     * The on-demand tab endpoint for a module instance; the script appends the group key.
     *
     * @param int $moduleId Module instance id.
     *
     * @return string A rooted, SEF-aware URL.
     *
     * @since  2.3.0
     */
    public static function lazyUrl(int $moduleId): string
    {
        return Route::_(
            'index.php?option=com_ajax&module=alfa_catalog&method=getTab&format=json&module_id=' . $moduleId,
            false,
        );
    }

    // On-demand tab (com_ajax)

    /**
     * AJAX endpoint: render one group of a published module instance and return it as JSON. The instance is
     * resolved by id and gated by its access level, so a tab can only ever show what the page itself would.
     *
     * @since  2.3.0
     */
    public function getTabAjax(): void
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        header('Content-Type: application/json');

        $app->getLanguage()->load('mod_alfa_catalog', JPATH_SITE);

        $moduleId = self::requestValue(input: $input, name: 'module_id', filter: 'int', default: 0);
        $key = self::requestValue(input: $input, name: 'tab', filter: 'cmd', default: '');

        if ($moduleId === null || $key === null) {
            self::refuseRequestValue();

            return;
        }

        $module = self::loadModule((int) $moduleId);

        if (!$module || $key === '') {
            echo new JsonResponse(null, Text::_('MOD_ALFA_CATALOG_ERROR_NOT_FOUND'), true);
            $app->close();
        }

        $params = new Registry($module->params);
        $groups = self::getGroups(params: $params, moduleId: $moduleId, onlyKey: $key);

        if (($groups[0]['kind'] ?? '') === Providers::KIND_PRODUCTS) {
            $app->getLanguage()->load('com_alfa', JPATH_SITE);
        }

        echo new JsonResponse(['html' => isset($groups[0]) ? self::renderGroup(module: $module, params: $params, group: $groups[0]) : '']);

        $app->close();
    }

    /**
     * AJAX endpoint: re-draw one product card of a published module instance for its buyer's new variant or option
     * selection, as HTML for the catalogue's item script to swap in. The instance is resolved by id and gated by
     * its access level, the tab must still exist and show products, and the catalogue reads the product (published,
     * visible to this visitor, priced for the selection in the request) in the tab's listing context, so the card
     * comes back as the tab draws it.
     *
     * @since  2.3.0
     */
    public function getCardAjax(): void
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        $app->getLanguage()->load('com_alfa', JPATH_SITE);
        $app->getLanguage()->load('mod_alfa_catalog', JPATH_SITE);

        $moduleId = self::requestValue(input: $input, name: 'module_id', filter: 'int', default: 0);
        $key = self::requestValue(input: $input, name: 'tab', filter: 'cmd', default: '');
        $itemId = self::requestValue(input: $input, name: 'id', filter: 'int', default: 0);

        if ($moduleId === null || $key === null || $itemId === null) {
            self::refuseRequestValue();

            return;
        }

        $module = self::loadModule((int) $moduleId);
        $params = new Registry($module->params ?? '');
        $tab = null;

        foreach (($module ? self::definitions($params) : []) as $definition) {
            if ($definition['key'] === $key && $definition['kind'] === Providers::KIND_PRODUCTS) {
                $tab = $definition;

                break;
            }
        }

        $item = $tab !== null ? (new ProductsProvider())->one(id: (int) $itemId, context: (int) $tab['query']['context']) : null;

        if ($item === null) {
            http_response_code(404);
            $app->close();
        }

        header('Content-Type: text/html; charset=utf-8');

        echo self::renderCard(group: $tab, record: $item, params: $params, place: $moduleId . '-' . $key, moduleId: (int) $moduleId);

        $app->close();
    }

    /**
     * A published site instance of this module the current visitor may see.
     *
     * @param int $moduleId Module instance id.
     *
     * @return object|null id, module, params, or null.
     *
     * @since  2.3.0
     */
    private static function loadModule(int $moduleId): ?object
    {
        if ($moduleId <= 0) {
            return null;
        }

        $app = Factory::getApplication();
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $levels = array_map('intval', (array) $app->getIdentity()->getAuthorisedViewLevels());
        $now = Factory::getDate()->toSql();

        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'module', 'params']))
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('module') . ' = ' . $db->quote('mod_alfa_catalog'))
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('client_id') . ' = 0')
            ->where('(' . $db->quoteName('publish_up') . ' IS NULL OR ' . $db->quoteName('publish_up') . ' <= :up)')
            ->where('(' . $db->quoteName('publish_down') . ' IS NULL OR ' . $db->quoteName('publish_down') . ' >= :down)')
            ->bind(':id', $moduleId, ParameterType::INTEGER)
            ->bind(':up', $now, ParameterType::STRING)
            ->bind(':down', $now, ParameterType::STRING);

        if ($levels !== []) {
            $query->whereIn($db->quoteName('access'), $levels);
        }

        return $db->setQuery($query)->loadObject() ?: null;
    }

    /**
     * One value of the request, through Joomla's filter, or null when the request sends a list in its place: a
     * list handed on to a typed parameter fails with an error page that names a file of the site, so it is refused
     * before any use.
     *
     * @param Input $input The request.
     * @param string $name The value's name.
     * @param string $filter Joomla's filter: int, cmd or string.
     * @param int|string $default The value when the request carries none.
     *
     * @since  2.3.0
     */
    private static function requestValue(Input $input, string $name, string $filter, int|string $default): mixed
    {
        if (\is_array($input->get($name, null, 'raw'))) {
            return null;
        }

        return $input->get($name, $default, $filter);
    }

    /**
     * Answer a request that sent a list where one value is expected: 400, with this module's sentence and no file
     * of the site named.
     *
     * @since  2.3.0
     */
    private static function refuseRequestValue(): void
    {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo new JsonResponse(null, Text::_('MOD_ALFA_CATALOG_ERROR_REQUEST_VALUE'), true);
        Factory::getApplication()->close();
    }
}
