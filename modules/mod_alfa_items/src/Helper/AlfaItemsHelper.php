<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaItems\Site\Helper;

use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Service\Catalog\StockVisibilityPolicy;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Alfa\Component\Alfa\Site\Helper\PriceSettings;
use Alfa\Component\Alfa\Site\Helper\RecentlyViewed;
use Alfa\Component\Alfa\Site\Model\ItemsModel;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Joomla\CMS\Cache\Controller\CallbackController;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use stdClass;
use Throwable;

defined('_JEXEC') or die;

/**
 * Resolves what an items module shows and renders it.
 *
 * The module IS its tabs: each configured row is one listing with its own source,
 * categories, order and size, shown as a grid or a slider; one row renders as a
 * plain list, several as a tab strip. Every listing is a catalogue listing: the module never queries products
 * itself. It declares a state on the component's ItemsModel (category scope, sale
 * filter, ordering, or a ranked id list for best sellers / recently viewed) and
 * the model answers with fully decorated cards (visibility, publish window, stock policy,
 * live prices, variants, field plugins, sale badges), exactly as the catalogue
 * page does. The cards are the module's own (its `_card` layout): Alfa's data, and
 * the parts Alfa draws itself (surfaces, price, stock, add to cart) in the
 * module's markup and styles, so an override of the module's card changes the
 * module and nothing in the shop.
 *
 * @since  1.0.0
 */
final class AlfaItemsHelper
{
    public const SOURCE_ITEMS = 'items';
    public const SOURCE_ON_SALE = 'on_sale';
    public const SOURCE_BEST_SELLERS = 'best_sellers';
    public const SOURCE_RECENTLY_VIEWED = 'recently_viewed';

    /**
     * @var int Screens up to this width are tablets: the second column count / slides-per-view applies.
     * @since  1.0.0
     */
    public const TABLET = 1023;

    /**
     * @var int Screens up to this width are phones: the third column count / slides-per-view applies.
     * @since  1.0.0
     */
    public const MOBILE = 767;

    /**
     * @var array<int, array{key: string, max: int}> The module's two breakpoints, widest first: what a card setting may be set again for.
     * @since  1.0.0
     */
    public const BREAKPOINTS = [
        ['key' => 'tablet', 'max' => self::TABLET],
        ['key' => 'mobile', 'max' => self::MOBILE],
    ];

    /**
     * @var string Group key prefix of a tab (followed by its row index).
     * @since  1.0.0
     */
    private const TAB_KEY = 'tab-';

    /**
     * @var string Ordering of a row that has none stored.
     * @since  1.0.0
     */
    private const DEFAULT_ORDERING = 'a.id DESC';

    /**
     * @var int Size of a row that has none stored.
     * @since  1.0.0
     */
    private const DEFAULT_LIMIT = 8;

    /**
     * Orderings the module may hand the model, keyed by the parameter value.
     *
     * @var array<string, array{0: string, 1: string}>
     * @since  1.0.0
     */
    private const ORDERINGS = [
        'a.id DESC' => ['a.id', 'DESC'],
        'a.id ASC' => ['a.id', 'ASC'],
        'a.ordering ASC' => ['a.ordering', 'ASC'],
        'a.ordering DESC' => ['a.ordering', 'DESC'],
        'name ASC' => ['name', 'ASC'],
        'name DESC' => ['name', 'DESC'],
        'pf.final_price ASC' => ['pf.final_price', 'ASC'],
        'pf.final_price DESC' => ['pf.final_price', 'DESC'],
        'pf.discount_amount DESC' => ['pf.discount_amount', 'DESC'],
        'pf.discount_percent DESC' => ['pf.discount_percent', 'DESC'],
    ];

    /**
     * @var int Ranked candidates asked for per displayed item; some fall to
     *          visibility, publishing or stock when the catalogue reads them.
     * @since  1.0.0
     */
    private const RANK_OVERFETCH = 3;

    /**
     * @var int Hard bound on a ranked candidate list.
     * @since  1.0.0
     */
    private const RANK_CAP = 100;

    /**
     * @var int Upper bound of the per-group limit parameter.
     * @since  1.0.0
     */
    private const LIMIT_MAX = 60;

    /**
     * @var array<string, string> Layout files resolved this request, by name.
     * @since  1.0.0
     */
    private static array $layoutPaths = [];

    /**
     * @var array<string, string> The card endpoint of each place a card is shown, routed once per request.
     * @since  1.0.0
     */
    private static array $cardEndpoints = [];

    // Groups

    /**
     * The groups the module renders, in order: one per configured tab row the
     * visitor may see. With several tabs only the first non-empty one carries
     * items; the rest are decorated and fetched when opened: a tab strip
     * never pays for cards nobody looks at. A group found empty on the way is
     * dropped rather than offered as a tab that opens on nothing.
     *
     * @param Registry $params Module parameters.
     * @param int $moduleId Module instance id.
     * @param string|null $onlyKey Resolve just this group, with its items (the on-demand fetch).
     *
     * @return array<int, array{key: string, label: string, items: array|null, lazy: bool, link: string, look: array}>
     *
     * @since  1.0.0
     */
    public static function getGroups(Registry $params, int $moduleId, ?string $onlyKey = null): array
    {
        $definitions = self::groupDefinitions($params);
        $lazy = $onlyKey === null && \count($definitions) > 1;
        $groups = [];
        $eagerDone = false;

        foreach ($definitions as $definition) {
            if ($onlyKey !== null && $onlyKey !== $definition['key']) {
                continue;
            }

            if ($lazy && $eagerDone) {
                $groups[] = $definition + ['items' => null, 'lazy' => true];

                continue;
            }

            $items = self::cachedItems(
                moduleId:          $moduleId,
                params:            $params,
                contextCategoryId: $definition['context'],
                scopeCategoryIds:  $definition['scope'],
                source:            $definition['source'],
                limit:             $definition['limit'],
                ordering:          $definition['ordering'],
            );

            if ($items === [] && $onlyKey === null) {
                continue;
            }

            $groups[] = $definition + ['items' => $items, 'lazy' => false];
            $eagerDone = true;
        }

        return $groups;
    }

    /**
     * What each tab IS, before any item is read: key, label, listing context or
     * scope, source, order and size, its "view all" link and its
     * own card look.
     * Categories the visitor may not see are dropped here, so a tab never
     * names a restricted category; a tab whose every category is hidden is
     * dropped with them.
     *
     * @param Registry $params Module parameters.
     *
     * @return array<int, array{key: string, label: string, context: int, scope: int[], source: string, limit: int, ordering: string, link: string, look: array}>
     *
     * @since  1.0.0
     */
    private static function groupDefinitions(Registry $params): array
    {
        $definitions = [];

        foreach (self::tabRows($params) as $index => $row) {
            $names = [];

            foreach ($row['categories'] as $categoryId) {
                $name = self::categoryName($categoryId);

                if ($name !== null) {
                    $names[$categoryId] = $name;
                }
            }

            if ($row['categories'] !== [] && $names === []) {
                continue;
            }

            $visible = array_keys($names);

            $definitions[] = [
                'key' => self::TAB_KEY . $index,
                'label' => $row['label'] !== ''
                    ? Text::_($row['label'])
                    : ($names !== [] ? implode(', ', $names) : Text::_('MOD_ALFA_ITEMS_SOURCE_' . strtoupper($row['source']))),
                // One category = a listing OF it (its layouts and links); several = a mixed listing scoped to them.
                'context' => \count($visible) === 1 ? (int) $visible[0] : 0,
                'scope' => \count($visible) > 1 ? $visible : [],
                'source' => $row['source'],
                'limit' => $row['limit'],
                'ordering' => $row['ordering'],
                'link' => $row['link'] ? self::viewAllUrl(source: $row['source'], categoryIds: $visible, ordering: $row['ordering']) : '',
                'look' => $row['look'],
            ];
        }

        return $definitions;
    }

    /**
     * Where "view all" of a tab leads: one category = that category's page; several
     * = the catalogue filtered to them; none = the whole catalogue. An on-sale tab
     * carries the sale filter and a non-default order the sort, so the page shows
     * the tab's listing in full. Best sellers and recently viewed have no catalogue
     * page that ranks like them: they link to their single category, or nowhere.
     *
     * @param string $source The tab's source.
     * @param int[] $categoryIds The tab's visible categories.
     * @param string $ordering The tab's ordering.
     *
     * @return string A routed URL, or '' when there is nowhere to go.
     *
     * @since  1.0.0
     */
    private static function viewAllUrl(string $source, array $categoryIds, string $ordering): string
    {
        $single = \count($categoryIds) === 1 ? (int) $categoryIds[0] : 0;

        if ($source === self::SOURCE_BEST_SELLERS || $source === self::SOURCE_RECENTLY_VIEWED) {
            return $single > 0 ? Route::_('index.php?option=com_alfa&view=items&category_id=' . $single) : '';
        }

        $url = 'index.php?option=com_alfa&view=items';

        if ($single > 0) {
            $url .= '&category_id=' . $single;
        } else {
            foreach ($categoryIds as $categoryId) {
                $url .= '&filter[category][]=' . (int) $categoryId;
            }
        }

        if ($source === self::SOURCE_ON_SALE) {
            $url .= '&filter[on_sale]=1';
        }

        if ($ordering !== self::DEFAULT_ORDERING) {
            $url .= '&list[fullordering]=' . rawurlencode($ordering);
        }

        return Route::_($url);
    }

    // Items

    /**
     * The decorated items of one group, read through the catalogue listing model.
     *
     * A category CONTEXT makes the group a listing OF that category (its layouts,
     * its links); a category SCOPE narrows a mixed listing without giving it a
     * context. Ranked sources (best sellers, recently viewed) hand the model an id
     * list and restore the rank afterwards, since the model orders only by columns.
     *
     * @param Registry $params Module parameters (best-seller window and cache).
     * @param int $contextCategoryId The listing's category, 0 for a mixed listing.
     * @param int[] $scopeCategoryIds Categories a mixed listing is limited to.
     * @param string $source The tab's source (a SOURCE_* constant).
     * @param int $limit The tab's size.
     * @param string $ordering The tab's ordering (a key of ORDERINGS).
     *
     * @return object[] Decorated items, in display order.
     *
     * @since  1.0.0
     */
    public static function getItems(
        Registry $params,
        int $contextCategoryId,
        array $scopeCategoryIds,
        string $source,
        int $limit,
        string $ordering,
    ): array {
        $source = self::source($source);
        $limit = self::bounded($limit, 1, self::LIMIT_MAX);
        $model = self::createModel();

        $model->setCategoryContext(categoryId: $contextCategoryId);

        if ($contextCategoryId <= 0 && $scopeCategoryIds !== []) {
            $model->setState('filter.category', $scopeCategoryIds);
        }

        $ranked = null;

        switch ($source) {
            case self::SOURCE_ON_SALE:
                $model->setState('filter.on_sale', 1);
                self::order($model, $ordering);
                break;

            case self::SOURCE_BEST_SELLERS:
                $ranked = self::bestSellerIds(
                    params:      $params,
                    categoryIds: self::rankScope($contextCategoryId, $scopeCategoryIds),
                    limit:       $limit,
                );
                break;

            case self::SOURCE_RECENTLY_VIEWED:
                $ranked = self::recentlyViewedIds();
                break;

            case self::SOURCE_ITEMS:
            default:
                self::order($model, $ordering);
                break;
        }

        if ($ranked !== null) {
            if ($ranked === []) {
                return [];
            }

            $model->setState('filter.id', $ranked);
            $model->setState('list.limit', 0);
            self::order($model, self::DEFAULT_ORDERING);
        } else {
            $model->setState('list.limit', $limit);
        }

        $model->setState('list.start', 0);

        $items = $model->getItems();

        if (!\is_array($items) || $items === []) {
            return [];
        }

        if ($ranked !== null) {
            $rank = array_flip($ranked);

            usort(
                $items,
                static fn (object $a, object $b): int =>
                    ($rank[(int) $a->id] ?? PHP_INT_MAX) <=> ($rank[(int) $b->id] ?? PHP_INT_MAX),
            );

            $items = \array_slice($items, 0, $limit);
        }

        return array_values($items);
    }

    /**
     * A tab's (or a block's) items through Joomla's module cache, the way Joomla's
     * own modules read their lists: the Advanced tab's Caching and Cache Time
     * decide, Global Configuration must have caching on, and a logged-in visitor
     * always reads fresh. The cached entry is the decorated items (prices, stock,
     * fields) for one pricing context (currency, place, customer groups) and page;
     * the card itself, with its add-to-cart token, is still rendered on every
     * request. One visitor's recently viewed items and a random order are never
     * cached.
     *
     * The entry also carries each item's stock verdict and figures, so the key holds
     * the shop-wide stock settings the listing is judged by and the inventory version
     * (see inventoryVersion()): a stock movement, a combination publication change or
     * a tracking change moves the key and the next render builds a fresh entry.
     * Staleness is bounded by the module's cache time, because an inventory command
     * that commits after a later one adds no higher version.
     *
     * @param int $moduleId Module instance id.
     * @param Registry $params Module parameters (cache settings, best-seller window).
     * @param int $contextCategoryId The listing's category, 0 for a mixed listing.
     * @param int[] $scopeCategoryIds Categories a mixed listing is limited to.
     * @param string $source The source (a SOURCE_* constant).
     * @param int $limit The size.
     * @param string $ordering The ordering (a key of ORDERINGS).
     *
     * @return object[] Decorated items, in display order.
     *
     * @since  1.0.0
     */
    public static function cachedItems(int $moduleId, Registry $params, int $contextCategoryId, array $scopeCategoryIds, string $source, int $limit, string $ordering): array
    {
        if (self::source($source) === self::SOURCE_RECENTLY_VIEWED || $ordering === 'random') {
            return self::getItems(params: $params, contextCategoryId: $contextCategoryId, scopeCategoryIds: $scopeCategoryIds, source: $source, limit: $limit, ordering: $ordering);
        }

        $cache = new stdClass();
        $cache->cachemode = 'id';
        $cache->class = self::class;
        $cache->method = 'getItems';
        $cache->methodparams = [$params, $contextCategoryId, $scopeCategoryIds, $source, $limit, $ordering];
        // One entry per module, query, pricing context, page (an item's link follows
        // the page's menu item), stock settings and inventory version; the cache keeps
        // languages apart itself.
        $cache->modeparams = md5(serialize([
            $moduleId,
            $contextCategoryId,
            $scopeCategoryIds,
            $source,
            $limit,
            $ordering,
            (int) $params->get('bestsellers_days', 90),
            PriceContext::fromSession()->toCacheKey(),
            Factory::getApplication()->getInput()->getInt('Itemid', 0),
            (new StockVisibilityPolicy())->cacheKey(),
            self::inventoryVersion(),
        ]));

        $items = ModuleHelper::moduleCache((object) ['id' => $moduleId, 'module' => 'mod_alfa_items'], $params, $cache);

        return \is_array($items) ? $items : [];
    }

    /**
     * The inventory version a cached listing is built under: the highest inventory
     * operation id. Every committed inventory command, including a combination
     * publication change and a tracking change, writes one operation row.
     *
     * The id is assigned when a command writes its row, not when it commits: a
     * command that commits after a later one adds no higher id, so an entry built
     * in between keeps its key until the module's cache time expires it.
     *
     * @return int The highest operation id, 0 when there is none.
     *
     * @since  1.0.0
     */
    private static function inventoryVersion(): int
    {
        $db = Factory::getContainer()->get(resourceName: DatabaseInterface::class);

        return (int) $db->setQuery(
            query: $db->createQuery()
                ->select(columns: 'MAX(' . $db->quoteName(name: 'id') . ')')
                ->from(table: $db->quoteName(name: '#__alfa_inventory_operations')),
        )->loadResult();
    }

    /**
     * A fresh catalogue listing model that reads nothing from the request.
     *
     *
     * @since  1.0.0
     */
    private static function createModel(): ItemsModel
    {
        /** @var ItemsModel $model */
        $model = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory()
            ->createModel('Items', 'Site', ['ignore_request' => true]);

        // Under ignore_request populateState() never runs: the state is exactly
        // what is set below, and this read only marks it as populated.
        $model->getState('list.ordering');

        return $model;
    }

    /**
     * Apply one of the allowed orderings to the model; unknown values fall to newest first.
     *
     * @param ItemsModel $model The listing model.
     * @param string $ordering A key of self::ORDERINGS.
     *
     *
     * @since  1.0.0
     */
    private static function order(ItemsModel $model, string $ordering): void
    {
        [$column, $direction] = self::ORDERINGS[$ordering] ?? self::ORDERINGS[self::DEFAULT_ORDERING];

        $model->setState('list.ordering', $column);
        $model->setState('list.direction', $direction);
        $model->setState('list.fullordering', $column . ' ' . $direction);
    }

    /**
     * The categories a ranking is computed within: the context or the scope,
     * with descendants when the catalogue includes subcategories.
     *
     * @param int $contextCategoryId The listing's category, 0 for none.
     * @param int[] $scopeCategoryIds The mixed listing's scope.
     *
     * @return int[] Empty = the whole catalogue.
     *
     * @since  1.0.0
     */
    private static function rankScope(int $contextCategoryId, array $scopeCategoryIds): array
    {
        $ids = $contextCategoryId > 0 ? [$contextCategoryId] : $scopeCategoryIds;

        if ($ids === []) {
            return [];
        }

        if ((int) ComponentHelper::getParams('com_alfa')->get('include_subcategories', 1) === 1) {
            foreach ($ids as $id) {
                $ids = array_merge($ids, CategoryHelper::getDescendantIds(parentId: (int) $id));
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    // Ranked sources

    /**
     * Best-selling item ids, most sold first, recomputed at most every
     * `bestsellers_cache` minutes. The ranking counts units on non-cancelled
     * orders inside the configured window; it is visitor-independent, so caching
     * it is safe: visibility is applied when the ids are read as a listing.
     *
     * @param Registry $params Module parameters.
     * @param int[] $categoryIds Categories to rank within (empty = all).
     * @param int $limit Items the module will display.
     *
     * @return int[]
     *
     * @since  1.0.0
     */
    private static function bestSellerIds(Registry $params, array $categoryIds, int $limit): array
    {
        $days = max(0, (int) $params->get('bestsellers_days', 90));
        $wanted = min(self::RANK_CAP, $limit * self::RANK_OVERFETCH);
        $minutes = max(0, (int) $params->get('bestsellers_cache', 60));

        if ($minutes === 0) {
            return self::queryBestSellerIds(days: $days, categoryIds: $categoryIds, limit: $wanted);
        }

        /** @var CallbackController $cache */
        $cache = Factory::getCache('mod_alfa_items', 'callback');
        $cache->setCaching(true);
        $cache->setLifeTime($minutes * 60);

        $ids = $cache->get(
            [self::class, 'queryBestSellerIds'],
            [$days, $categoryIds, $wanted],
            md5(serialize([$days, $categoryIds, $wanted])),
        );

        return \is_array($ids) ? $ids : [];
    }

    /**
     * Aggregate the order lines into a ranking. Public: the cache controller calls it back.
     *
     * A cancelled sale is not a sale, and trashed orders never count. The window
     * is on the order's creation moment, in UTC like the stored value.
     *
     * @param int $days Window in days, 0 = all time.
     * @param int[] $categoryIds Categories to rank within (empty = all).
     * @param int $limit Candidates to return.
     *
     * @return int[] Item ids, most sold first.
     *
     * @since  1.0.0
     */
    public static function queryBestSellerIds(int $days, array $categoryIds, int $limit): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select($db->quoteName('oi.id_item'))
            ->select('SUM(' . $db->quoteName('oi.quantity') . ') AS ' . $db->quoteName('sold'))
            ->from($db->quoteName('#__alfa_order_items', 'oi'))
            ->join('INNER', $db->quoteName('#__alfa_orders', 'o'), $db->quoteName('o.id') . ' = ' . $db->quoteName('oi.id_order'))
            ->where($db->quoteName('o.state') . ' <> -2')
            ->group($db->quoteName('oi.id_item'))
            ->order($db->quoteName('sold') . ' DESC')
            ->setLimit(max(1, $limit));

        $cancelled = OrderStatusHelper::getCancelledIds();

        if ($cancelled !== []) {
            $query->whereNotIn($db->quoteName('o.id_order_status'), $cancelled);
        }

        if ($days > 0) {
            $since = Factory::getDate('-' . $days . ' days')->toSql();
            $query->where($db->quoteName('o.created') . ' >= :since')
                ->bind(':since', $since, ParameterType::STRING);
        }

        $categoryIds = array_values(array_filter(array_map('intval', $categoryIds)));

        if ($categoryIds !== []) {
            $query->where(
                $db->quoteName('oi.id_item') . ' IN ('
                . 'SELECT ' . $db->quoteName('item_id')
                . ' FROM ' . $db->quoteName('#__alfa_items_categories')
                . ' WHERE ' . $db->quoteName('category_id') . ' IN (' . implode(',', $categoryIds) . ')'
                . ')',
            );
        }

        try {
            return array_map('intval', (array) $db->setQuery($query)->loadColumn());
        } catch (Throwable $e) {
            Log::add('mod_alfa_items best-sellers ranking failed: ' . $e->getMessage(), Log::ERROR, 'com_alfa');

            return [];
        }
    }

    /**
     * The visitor's recently viewed ids, most recent first, without the product
     * currently on screen, which a "recently viewed" strip on its own page must
     * not repeat.
     *
     * @return int[]
     *
     * @since  1.0.0
     */
    private static function recentlyViewedIds(): array
    {
        $input = Factory::getApplication()->getInput();
        $current = ($input->getCmd('option') === 'com_alfa' && $input->getCmd('view') === 'item')
            ? $input->getInt('id', 0)
            : 0;

        $ids = RecentlyViewed::ids();

        return $current > 0 ? array_values(array_diff($ids, [$current])) : $ids;
    }

    // Rendering

    /**
     * Render one group (a grid or a slider of cards) through the module's group
     * layout, template overrides included. Used by the page render and by the
     * on-demand tab fetch alike, so both produce the same markup.
     *
     * @param object $module The module instance (id, params, layout).
     * @param Registry $params Module parameters.
     * @param array $group A group as getGroups() builds it, with its items.
     *
     *
     * @since  1.0.0
     */
    public static function renderGroup(object $module, Registry $params, array $group): string
    {
        $path = ModuleHelper::getLayoutPath('mod_alfa_items', (string) $params->get('layout', 'default') . '_group');

        if (!is_file($path)) {
            return '';
        }

        $display = self::displayOptions($params);
        // The tab: the module's settings, then its own - a grid or a slider, and the slider's
        // settings on every device. Every card of it is named after its place: the module and the tab.
        $resolved = CardLook::resolve(levels: [$display['level'], $group['look'] ?? []], breakpoints: self::BREAKPOINTS);
        $display['mode'] = $resolved['']['display'];
        $display['bands'] = CardLook::bands($resolved, self::BREAKPOINTS);
        $display['place'] = (int) $module->id . '-' . $group['key'];
        $priceSettings = PriceSettings::get();
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
     * Render one product card through the module's `_card` layout, template overrides
     * first: the module's markup and look around Alfa's data and the parts Alfa draws.
     *
     * @param object $item A decorated item (getItems(), or Alfa's getItem() for a re-drawn card).
     * @param Registry $params Module parameters (the layout choice).
     * @param string $place The card's place, which its rules are written for (CardLook::css).
     * @param array $priceSettings Price visibility settings.
     * @param string $reloadUrl Where a variant or option change re-draws the card (reloadUrl()).
     *
     *
     * @since  1.0.0
     */
    public static function renderCard(object $item, Registry $params, string $place, array $priceSettings, string $reloadUrl): string
    {
        $path = self::layoutPath($params, '_card');

        if ($path === '') {
            return '';
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
     * Where a card re-draws itself when its buyer changes a variant or an option: this
     * module's card endpoint for the tab it is shown in, which renders it again, with
     * the same look, for the new selection. Only an item with something to choose has
     * one: Alfa gives exactly those an address of its own for its listing.
     *
     * @param int $moduleId Module instance id.
     * @param string $tab The key of the tab the card is shown in.
     * @param object $item The decorated item.
     *
     * @return string A rooted, SEF-aware URL; '' when the item has nothing to choose.
     *
     * @since  1.0.0
     */
    public static function reloadUrl(int $moduleId, string $tab, object $item): string
    {
        if ((string) ($item->reloadUrl ?? '') === '') {
            return '';
        }

        // Routed once per place; the item rides behind it.
        $endpoint = self::$cardEndpoints[$moduleId . ':' . $tab] ??= Route::_(
            'index.php?option=com_ajax&module=alfa_items&method=getCard&format=raw&module_id=' . $moduleId . '&tab=' . rawurlencode($tab),
            false,
        );

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . 'id=' . (int) $item->id;
    }

    /**
     * A layout file of this module for the instance's chosen layout, template
     * overrides first; resolved once per request.
     *
     * @param Registry $params Module parameters.
     * @param string $suffix The sub-layout suffix, e.g. `_card`.
     *
     * @return string The path, or '' when no such layout exists.
     *
     * @since  1.0.0
     */
    private static function layoutPath(Registry $params, string $suffix): string
    {
        $name = (string) $params->get('layout', 'default') . $suffix;

        if (!isset(self::$layoutPaths[$name])) {
            $path = ModuleHelper::getLayoutPath('mod_alfa_items', $name);
            self::$layoutPaths[$name] = is_file($path) ? $path : '';
        }

        return self::$layoutPaths[$name];
    }

    /**
     * A tab row's own card settings, for every screen and per breakpoint: only what it
     * fills in. An empty one follows the module.
     *
     * @param array $row A stored tab row.
     *
     * @return array{base: array, bp: array} A level (CardLook).
     *
     * @since  1.0.0
     */
    public static function tabLook(array $row): array
    {
        return CardLook::level($row);
    }

    /**
     * The card rules of a module instance (CardLook::css): one place per tab, fetched
     * yet or not, so a tab opened later (or a card drawn again for another variant)
     * finds its rules in the page.
     *
     * @param int $moduleId Module instance id.
     * @param Registry $params Module parameters.
     * @param array $groups The module's groups (getGroups()).
     *
     * @return string CSS.
     *
     * @since  1.0.0
     */
    public static function lookCss(int $moduleId, Registry $params, array $groups): string
    {
        $level = self::displayOptions($params)['level'];
        $places = [];

        foreach ($groups as $group) {
            $places[$moduleId . '-' . $group['key']] = CardLook::resolve(levels: [$level, $group['look'] ?? []], breakpoints: self::BREAKPOINTS);
        }

        return CardLook::css(places: $places, breakpoints: self::BREAKPOINTS);
    }

    /**
     * The module's own settings (CardLook), for every screen and per breakpoint: how a
     * tab shows its cards (a grid or a slider, its columns or cards per view, its gap)
     * and how the cards look. A tab lays its own over them.
     *
     * @param Registry $params Module parameters.
     *
     * @return array{level: array{base: array, bp: array}}
     *
     * @since  1.0.0
     */
    public static function displayOptions(Registry $params): array
    {
        return ['level' => CardLook::level($params->toArray())];
    }

    /**
     * The per-instance CSS of the grids: every tab's gap and columns on wide screens,
     * then what changes at the module's two widths (per tab, since a tab may set its own).
     *
     * @param int $moduleId Module instance id.
     * @param Registry $params Module parameters.
     * @param array $groups The module's groups (getGroups()).
     *
     * @return string CSS.
     *
     * @since  1.0.0
     */
    public static function responsiveCss(int $moduleId, Registry $params, array $groups): string
    {
        $level = self::displayOptions($params)['level'];
        $rules = [];

        foreach ($groups as $group) {
            $selector = '.mod-alfa-items-' . $moduleId . ' [data-panel="' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $group['key']) . '"]';
            $before = [];

            foreach (CardLook::resolve(levels: [$level, $group['look'] ?? []], breakpoints: self::BREAKPOINTS) as $device => $there) {
                $now = ['--mod-alfa-items-gap' => (int) $there['gap'] . 'px', '--mod-alfa-items-columns' => (string) (int) $there['columns']];
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
     * Whether any tab is a slider: decides whether the slider library is
     * loaded with the page. A tab still to be fetched counts, since it will be.
     *
     * @param Registry $params Module parameters.
     *
     *
     * @since  1.0.0
     */
    public static function usesSlider(Registry $params): bool
    {
        $level = self::displayOptions($params)['level'];

        foreach (self::tabRows($params) as $row) {
            if (CardLook::resolve(levels: [$level, $row['look']], breakpoints: [])['']['display'] === 'slider') {
                return true;
            }
        }

        return false;
    }

    /**
     * The on-demand tab endpoint for a module instance; the script appends the group key.
     *
     * @param int $moduleId Module instance id.
     *
     * @return string A rooted, SEF-aware URL.
     *
     * @since  1.0.0
     */
    public static function lazyUrl(int $moduleId): string
    {
        return Route::_(
            'index.php?option=com_ajax&module=alfa_items&method=getTab&format=json&module_id=' . $moduleId,
            false,
        );
    }

    // On-demand tab (com_ajax)

    /**
     * AJAX endpoint: render one group of a published module instance and return
     * it as JSON. The instance is resolved by id and gated by its access level,
     * so a tab can only ever show what the page itself would.
     *
     *
     * @since  1.0.0
     */
    public function getTabAjax(): void
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        header('Content-Type: application/json');

        $app->getLanguage()->load('com_alfa', JPATH_SITE);
        $app->getLanguage()->load('mod_alfa_items', JPATH_SITE);

        $moduleId = $input->getInt('module_id', 0);
        $key = $input->getCmd('tab', '');
        $module = self::loadModule($moduleId);

        if (!$module || $key === '') {
            echo new JsonResponse(null, Text::_('MOD_ALFA_ITEMS_ERROR_NOT_FOUND'), true);
            $app->close();
        }

        $params = new Registry($module->params);
        $groups = self::getGroups(params: $params, moduleId: $moduleId, onlyKey: $key);
        $group = $groups[0] ?? ['key' => $key, 'label' => '', 'items' => [], 'lazy' => false, 'link' => '', 'look' => []];

        echo new JsonResponse(['html' => self::renderGroup(module: $module, params: $params, group: $group)]);

        $app->close();
    }

    /**
     * AJAX endpoint: re-draw one card of a published module instance for its buyer's
     * new variant or option selection, as HTML for Alfa's item script to swap in. The
     * instance is resolved by id and gated by its access level, the tab must still
     * exist, and Alfa reads the item (published, visible to this visitor, priced for
     * the selection in the request) in the tab's listing context, so the card comes
     * back as the tab draws it.
     *
     *
     * @since  1.0.0
     */
    public function getCardAjax(): void
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        $app->getLanguage()->load('com_alfa', JPATH_SITE);
        $app->getLanguage()->load('mod_alfa_items', JPATH_SITE);

        $moduleId = $input->getInt('module_id', 0);
        $key = $input->getCmd('tab', '');
        $module = self::loadModule($moduleId);
        $params = new Registry($module->params ?? '');
        $tab = null;

        foreach (($module ? self::groupDefinitions($params) : []) as $definition) {
            if ($definition['key'] === $key) {
                $tab = $definition;

                break;
            }
        }

        // Alfa reads the selection (sel[], opt[], conf[]) from the request itself.
        $item = $tab !== null
            ? self::createModel()->setCategoryContext(categoryId: $tab['context'])->getItem($input->getInt('id', 0))
            : null;

        if ($item === null) {
            http_response_code(404);
            $app->close();
        }

        header('Content-Type: text/html; charset=utf-8');

        echo self::renderCard(
            item:          $item,
            params:        $params,
            place:         $moduleId . '-' . $key,
            priceSettings: PriceSettings::get(),
            reloadUrl:     self::reloadUrl(moduleId: $moduleId, tab: $key, item: $item),
        );

        $app->close();
    }

    /**
     * A published site instance of this module the current visitor may see.
     *
     * @param int $moduleId Module instance id.
     *
     * @return object|null id, module, params, or null.
     *
     * @since  1.0.0
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
            ->where($db->quoteName('module') . ' = ' . $db->quote('mod_alfa_items'))
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

    // Parameter readers

    /**
     * A row's source, defaulting to plain items.
     *
     * @param string $source The stored value.
     *
     * @return string One of the SOURCE_* constants.
     *
     * @since  1.0.0
     */
    private static function source(string $source): string
    {
        return \in_array($source, [
            self::SOURCE_ITEMS,
            self::SOURCE_ON_SALE,
            self::SOURCE_BEST_SELLERS,
            self::SOURCE_RECENTLY_VIEWED,
        ], true) ? $source : self::SOURCE_ITEMS;
    }

    /**
     * The tab rows, normalised: label, source, category ids, ordering, size,
     * its link and its own card
     * look (none while it follows the module). Row order is tab order.
     *
     * @param Registry $params Module parameters.
     *
     * @return array<int, array{label: string, source: string, categories: int[], limit: int, ordering: string, link: bool, look: array<string, string|int>}>
     *
     * @since  1.0.0
     */
    private static function tabRows(Registry $params): array
    {
        $rows = [];

        foreach ((array) $params->get('tabs', []) as $row) {
            $row = (array) $row;
            $ordering = (string) ($row['ordering'] ?? '');

            $rows[] = [
                'label' => trim((string) ($row['label'] ?? '')),
                'source' => self::source((string) ($row['source'] ?? '')),
                'categories' => array_values(array_unique(array_filter(array_map('intval', (array) ($row['categories'] ?? []))))),
                'limit' => self::bounded($row['limit'] ?? self::DEFAULT_LIMIT, 1, self::LIMIT_MAX),
                'ordering' => isset(self::ORDERINGS[$ordering]) ? $ordering : self::DEFAULT_ORDERING,
                'link' => (bool) ($row['show_link'] ?? false),
                'look' => self::tabLook($row),
            ];
        }

        return $rows;
    }

    /**
     * A category's name in the active language, or null when this visitor may
     * not see it (unpublished, or outside the access allowlist).
     *
     * @param int $categoryId Category id.
     *
     *
     * @since  1.0.0
     */
    private static function categoryName(int $categoryId): ?string
    {
        $category = CategoryHelper::loadCategoryFromDatabase(categoryId: $categoryId);

        return $category ? (string) ($category['name'] ?? '') : null;
    }

    /**
     * An integer parameter clamped to a range.
     *
     * @param mixed $value The stored value.
     * @param int $min Lower bound.
     * @param int $max Upper bound.
     *
     *
     * @since  1.0.0
     */
    private static function bounded(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }
}
