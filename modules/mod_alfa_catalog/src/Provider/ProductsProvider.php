<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaCatalog\Site\Provider;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Service\Catalog\CatalogAccessPolicy;
use Alfa\Component\Alfa\Administrator\Service\Catalog\StockVisibilityPolicy;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Site\Helper\RecentlyViewed;
use Alfa\Component\Alfa\Site\Helper\ShopMode;
use Alfa\Component\Alfa\Site\Model\ItemsModel;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Alfa\Module\AlfaCatalog\Site\Helper\Subjects;
use Joomla\CMS\Cache\Controller\CallbackController;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Throwable;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The products of a tab, read through the catalogue's listing model.
 *
 * The provider never queries a table. It declares a state on the component's ItemsModel (a category context or
 * scope, manufacturers, labels, the sale or new filter, an ordering, or a ranked id list for best sellers and
 * recently viewed) and the model answers with fully decorated items (visibility, publish window, stock policy, live
 * prices, variants, field plugins, labels), exactly as the catalogue page reads them.
 *
 * Best sellers are ranked by the model too, AFTER the tab's filters: the best sellers OF the tab's category,
 * manufacturers and labels among the products this visitor is shown, so a tab holds its number of them whenever
 * that many have sold.
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
     * @var int The most products a tab shows. A display limit: the top of the range of the form's field, which
     *          states it (1 to 60) and goes no higher.
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
     * A ranked source (best sellers, recently viewed) hands the model its ids and restores the rank afterwards,
     * since the model orders only by columns. The best sellers are ranked within the tab's filters, so they all
     * pass them; the visitor's recently viewed products are their own list, which the tab's filters narrow.
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
        $source = Providers::choice($query['source'] ?? '', self::SOURCES);

        if ($source === self::SOURCE_RECENTLY_VIEWED) {
            return self::ranked(query: $query, ids: self::recentlyViewedIds(), limit: $limit);
        }

        if ($source === self::SOURCE_BEST_SELLERS) {
            $stored = max(0, (int) $params->get('bestsellers_cache', 60)) > 0;
            $ids = self::bestSellerIds(query: $query, params: $params, limit: $limit, stored: $stored);
            $items = self::ranked(query: $query, ids: $ids, limit: $limit);

            // A stored ranking can name a product that has left the listing since (sold out, unpublished): the
            // listing then answers with fewer than the ranking promised, and the tab is ranked again now.
            if ($stored && \count($items) < \count($ids)) {
                $items = self::ranked(query: $query, ids: self::bestSellerIds(query: $query, params: $params, limit: $limit, stored: false), limit: $limit);
            }

            return $items;
        }

        $model = self::filtered($query);

        if ($source === self::SOURCE_ON_SALE) {
            $model->setState('filter.on_sale', 1);
        }

        if ($source === self::SOURCE_NEW) {
            $model->setState('filter.new', 1);
        }

        self::order($model, (string) ($query['ordering'] ?? self::DEFAULT_ORDERING));
        $model->setState('list.limit', $limit);

        $items = $model->getItems();

        return \is_array($items) ? array_values($items) : [];
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
     * Rank a tab's best sellers now. Public: the cache controller calls it back.
     *
     * @param int $days The period in days, 0 = all time.
     * @param int $limit The tab's number.
     * @param array<string, mixed> $filters The tab's category context or scope, manufacturers and labels.
     *
     * @return int[] Product ids, most sold first, never more than the number.
     *
     * @since  2.3.0
     */
    public static function rankBestSellers(int $days, int $limit, array $filters): array
    {
        return self::filtered($filters)->getBestSellingIds(days: $days, limit: $limit);
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
     * A listing model under a tab's filters: its category (one makes the listing a listing OF it, several narrow a
     * mixed listing), its manufacturers and its labels.
     *
     * @param array<string, mixed> $query The tab's query, or its filters alone.
     *
     * @since  2.3.0
     */
    private static function filtered(array $query): ItemsModel
    {
        $model = self::model();
        $context = (int) ($query['context'] ?? 0);
        $scope = Providers::ids($query['scope'] ?? []);

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

        $model->setState('list.start', 0);

        return $model;
    }

    /**
     * Hand the model one of the allowed orderings; an unknown value is newest first.
     *
     * @param ItemsModel $model The listing model.
     * @param string $ordering A key of ORDERINGS.
     *
     * @since  2.3.0
     */
    private static function order(ItemsModel $model, string $ordering): void
    {
        [$column, $direction] = self::ORDERINGS[$ordering] ?? self::ORDERINGS[self::DEFAULT_ORDERING];

        $model->setState('list.ordering', $column);
        $model->setState('list.direction', $direction);
        $model->setState('list.fullordering', $column . ' ' . $direction);
    }

    /**
     * The products of a ranked id list under a tab's filters, in the list's order, never more than the tab's
     * number. The model is asked for exactly the ids: as many rows as the list is long.
     *
     * @param array<string, mixed> $query The tab's query.
     * @param int[] $ids The ranked ids, first place first.
     * @param int $limit The tab's number.
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    private static function ranked(array $query, array $ids, int $limit): array
    {
        if ($ids === []) {
            return [];
        }

        $model = self::filtered($query);
        $model->setState('filter.id', $ids);
        $model->setState('list.limit', \count($ids));
        self::order($model, self::DEFAULT_ORDERING);

        $items = $model->getItems();

        if (!\is_array($items) || $items === []) {
            return [];
        }

        $rank = array_flip($ids);

        usort(
            $items,
            static fn (object $a, object $b): int =>
                ($rank[(int) $a->id] ?? PHP_INT_MAX) <=> ($rank[(int) $b->id] ?? PHP_INT_MAX),
        );

        return \array_slice(array_values($items), 0, $limit);
    }

    /**
     * A tab's best-selling product ids, most sold first: exactly the tab's number of them when that many of the
     * tab's products have sold, ranked by the listing model within the tab's filters and this visitor's listing.
     *
     * The ranking is recomputed at most every `bestsellers_cache` minutes. A stored ranking is one tab's (its
     * period, number and filters) for one audience (who may see which products, and the shop's stock settings), so
     * it never crosses to a visitor who is shown other products.
     *
     * A ranking that cannot be made is logged and the tab is empty: it never takes the module down, and it is not
     * stored.
     *
     * @param array<string, mixed> $query The tab's query.
     * @param Registry $params The module's settings.
     * @param int $limit The tab's number.
     * @param bool $stored Whether a stored ranking may answer.
     *
     * @return int[]
     *
     * @since  2.3.0
     */
    private static function bestSellerIds(array $query, Registry $params, int $limit, bool $stored): array
    {
        $days = max(0, (int) $params->get('bestsellers_days', 90));
        $filters = array_intersect_key($query, ['context' => 1, 'scope' => 1, 'manufacturers' => 1, 'labels' => 1]);

        try {
            if (!$stored) {
                return self::rankBestSellers(days: $days, limit: $limit, filters: $filters);
            }

            /** @var CallbackController $cache */
            $cache = Factory::getCache('mod_alfa_catalog', 'callback');
            $cache->setCaching(true);
            // Joomla counts a cache lifetime in minutes, as the option is.
            $cache->setLifeTime(max(1, (int) $params->get('bestsellers_cache', 60)));

            $ids = $cache->get(
                [self::class, 'rankBestSellers'],
                [$days, $limit, $filters],
                md5(serialize([$days, $limit, $filters, (new CatalogAccessPolicy())->cacheKey(), (new StockVisibilityPolicy())->cacheKey()])),
            );

            return \is_array($ids) ? array_map('intval', $ids) : [];
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
