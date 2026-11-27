<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaCatalog\Site\Provider;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Service\Catalog\StockVisibilityPolicy;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Alfa\Component\Alfa\Site\Helper\RecentlyViewed;
use Alfa\Component\Alfa\Site\Helper\ShopMode;
use Alfa\Component\Alfa\Site\Model\ItemsModel;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Alfa\Module\AlfaCatalog\Site\Helper\Subjects;
use Joomla\CMS\Cache\Controller\CallbackController;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use Throwable;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The products of a tab, read through the catalogue's listing model.
 *
 * The provider never queries products itself. It declares a state on the component's ItemsModel (a category context
 * or scope, manufacturers, labels, the sale or new filter, an ordering, or a ranked id list for best sellers and
 * recently viewed) and the model answers with fully decorated items (visibility, publish window, stock policy, live
 * prices, variants, field plugins, labels), exactly as the catalogue page reads them.
 *
 * @since  2.3.0
 */
final class ProductsProvider implements ProviderInterface
{
    public const SOURCE_ITEMS = 'items';
    public const SOURCE_ON_SALE = 'on_sale';
    public const SOURCE_NEW = 'new';
    public const SOURCE_BEST_SELLERS = 'best_sellers';
    public const SOURCE_RECENTLY_VIEWED = 'recently_viewed';

    /**
     * @var string[] The sources, the default first.
     * @since  2.3.0
     */
    public const SOURCES = [
        self::SOURCE_ITEMS,
        self::SOURCE_ON_SALE,
        self::SOURCE_NEW,
        self::SOURCE_BEST_SELLERS,
        self::SOURCE_RECENTLY_VIEWED,
    ];

    /**
     * @var string Ordering of a row that has none stored.
     * @since  2.3.0
     */
    public const DEFAULT_ORDERING = 'a.id DESC';

    /**
     * @var int Size of a row that has none stored.
     * @since  2.3.0
     */
    public const DEFAULT_LIMIT = 8;

    /**
     * @var int The most products a tab shows.
     * @since  2.3.0
     */
    public const LIMIT_MAX = 60;

    /**
     * Orderings the provider may hand the model, keyed by the stored value.
     *
     * @var array<string, array{0: string, 1: string}>
     * @since  2.3.0
     */
    public const ORDERINGS = [
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
     * @var int Ranked candidates asked for per displayed product; some fall to visibility, publishing, stock or the
     *          tab's other filters when the catalogue reads them.
     * @since  2.3.0
     */
    private const RANK_OVERFETCH = 3;

    /**
     * @var int Hard bound on a ranked candidate list.
     * @since  2.3.0
     */
    private const RANK_CAP = 100;

    /**
     * A products tab's query. One visible category makes the tab a listing OF it (its layouts and links); several
     * narrow a mixed listing. A row whose every chosen category is hidden from this visitor has no query at all.
     *
     * @param array $row The stored tab row.
     * @param string $display The tab's presentation.
     *
     * @return array<string, mixed> [] when the visitor may see none of the row's categories.
     *
     * @since  2.3.0
     */
    public function query(array $row, string $display): array
    {
        $chosen = Providers::ids($row['categories'] ?? []);
        $visible = array_keys(Subjects::names(type: Subjects::CATEGORY, ids: $chosen));

        if ($chosen !== [] && $visible === []) {
            return [];
        }

        $ordering = (string) ($row['ordering'] ?? '');

        return [
            'source' => Providers::choice($row['source'] ?? '', self::SOURCES),
            'context' => \count($visible) === 1 ? (int) $visible[0] : 0,
            'scope' => \count($visible) > 1 ? $visible : [],
            'manufacturers' => Providers::ids($row['manufacturers'] ?? []),
            'labels' => Providers::ids($row['labels'] ?? []),
            'limit' => Providers::bounded($row['limit'] ?? null, 1, self::LIMIT_MAX, self::DEFAULT_LIMIT),
            'ordering' => isset(self::ORDERINGS[$ordering]) ? $ordering : self::DEFAULT_ORDERING,
        ];
    }

    /**
     * The decorated products of a tab, in display order, never more than its number.
     *
     * A ranked source (best sellers, recently viewed) hands the model a bounded id list and restores the rank
     * afterwards, since the model orders only by columns.
     *
     * @param array<string, mixed> $query The tab's query.
     * @param Registry $params The module's settings (the best sellers period and its refresh).
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    public function records(array $query, Registry $params): array
    {
        $limit = Providers::bounded($query['limit'] ?? null, 1, self::LIMIT_MAX, self::DEFAULT_LIMIT);
        $context = (int) ($query['context'] ?? 0);
        $scope = Providers::ids($query['scope'] ?? []);
        $model = self::model();

        $model->setCategoryContext(categoryId: $context);

        if ($context <= 0 && $scope !== []) {
            $model->setState('filter.category', $scope);
        }

        foreach (['manufacturers' => 'filter.manufacturer', 'labels' => 'filter.label'] as $key => $state) {
            $ids = Providers::ids($query[$key] ?? []);

            if ($ids !== []) {
                $model->setState($state, $ids);
            }
        }

        $ordering = (string) ($query['ordering'] ?? self::DEFAULT_ORDERING);
        $ranked = null;

        switch (Providers::choice($query['source'] ?? '', self::SOURCES)) {
            case self::SOURCE_ON_SALE:
                $model->setState('filter.on_sale', 1);
                break;

            case self::SOURCE_NEW:
                $model->setState('filter.new', 1);
                break;

            case self::SOURCE_BEST_SELLERS:
                $ranked = self::bestSellerIds(params: $params, categoryIds: self::rankScope($context, $scope), limit: $limit);
                break;

            case self::SOURCE_RECENTLY_VIEWED:
                $ranked = \array_slice(self::recentlyViewedIds(), 0, self::RANK_CAP);
                break;
        }

        if ($ranked === []) {
            return [];
        }

        if ($ranked !== null) {
            // A bounded id list: the model reads at most that many rows.
            $model->setState('filter.id', $ranked);
            $model->setState('list.limit', \count($ranked));
            $ordering = self::DEFAULT_ORDERING;
        } else {
            $model->setState('list.limit', $limit);
        }

        [$column, $direction] = self::ORDERINGS[$ordering] ?? self::ORDERINGS[self::DEFAULT_ORDERING];
        $model->setState('list.ordering', $column);
        $model->setState('list.direction', $direction);
        $model->setState('list.fullordering', $column . ' ' . $direction);
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
        }

        return \array_slice(array_values($items), 0, $limit);
    }

    /**
     * A stored listing holds decorated items (prices, stock verdicts, links) for one way of selling, one best
     * sellers period, one pricing context (currency, place, customer groups), one page (an item's link follows the
     * page's menu item), the shop's stock settings and the inventory version: a stock movement moves the version and
     * the next render reads afresh. One visitor's recently viewed products are never stored.
     *
     * @param array<string, mixed> $query The tab's query.
     * @param Registry $params The module's settings.
     *
     * @return array<int, mixed>|null
     *
     * @since  2.3.0
     */
    public function varies(array $query, Registry $params): ?array
    {
        if (($query['source'] ?? '') === self::SOURCE_RECENTLY_VIEWED) {
            return null;
        }

        return [
            ShopMode::sellsOnline(),
            (int) $params->get('bestsellers_days', 90),
            PriceContext::fromSession()->toCacheKey(),
            Factory::getApplication()->getInput()->getInt('Itemid', 0),
            (new StockVisibilityPolicy())->cacheKey(),
            InventoryAvailabilityService::inventoryVersion(db: Factory::getContainer()->get(DatabaseInterface::class)),
        ];
    }

    /**
     * One product for a card drawn again, read in a tab's listing context; the model reads the buyer's selection
     * from the request itself.
     *
     * @param int $id The product.
     * @param int $context The tab's category context, 0 for none.
     *
     * @return object|null Null when the product is not shown to this visitor.
     *
     * @since  2.3.0
     */
    public function one(int $id, int $context): ?object
    {
        return self::model()->setCategoryContext(categoryId: $context)->getItem($id);
    }

    /**
     * A fresh catalogue listing model that reads nothing from the request.
     *
     * @since  2.3.0
     */
    private static function model(): ItemsModel
    {
        /** @var ItemsModel $model */
        $model = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory()
            ->createModel('Items', 'Site', ['ignore_request' => true]);

        // Under ignore_request populateState() never runs: the state is exactly what the caller sets, and this
        // read only marks it as populated.
        $model->getState('list.ordering');

        return $model;
    }

    /**
     * The categories a ranking is computed within: the context or the scope, with descendants when the catalogue
     * includes subcategories.
     *
     * @param int $context The listing's category, 0 for none.
     * @param int[] $scope The mixed listing's scope.
     *
     * @return int[] Empty = the whole catalogue.
     *
     * @since  2.3.0
     */
    private static function rankScope(int $context, array $scope): array
    {
        $ids = $context > 0 ? [$context] : $scope;

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

    /**
     * Best-selling product ids, most sold first, recomputed at most every `bestsellers_cache` minutes. The ranking
     * counts units on non-cancelled orders inside the configured period; it is the same for every visitor, so
     * storing it is safe: visibility is applied when the ids are read as a listing.
     *
     * @param Registry $params The module's settings.
     * @param int[] $categoryIds Categories to rank within (empty = all).
     * @param int $limit Products the tab will show.
     *
     * @return int[]
     *
     * @since  2.3.0
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
        $cache = Factory::getCache('mod_alfa_catalog', 'callback');
        $cache->setCaching(true);
        // Joomla counts a cache lifetime in minutes, as the option is.
        $cache->setLifeTime($minutes);

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
     * A cancelled sale is not a sale, and trashed orders never count. The period is on the order's creation moment,
     * in UTC like the stored value.
     *
     * @param int $days Period in days, 0 = all time.
     * @param int[] $categoryIds Categories to rank within (empty = all).
     * @param int $limit Candidates to return.
     *
     * @return int[] Product ids, most sold first.
     *
     * @since  2.3.0
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
            ->setLimit(max(1, min(self::RANK_CAP, $limit)));

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
            Log::add('mod_alfa_catalog best sellers ranking failed: ' . ErrorText::of($e), Log::ERROR, 'com_alfa');

            return [];
        }
    }

    /**
     * The visitor's recently viewed ids, most recent first, without the product currently on screen, which a
     * "recently viewed" strip on its own page must not repeat.
     *
     * @return int[]
     *
     * @since  2.3.0
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
}
