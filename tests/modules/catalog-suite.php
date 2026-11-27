<?php

/**
 * THE CATALOG MODULE: ITS TABS, ITS THREE KINDS, ITS THREE PRESENTATIONS, AGAINST STAND-INS OF THE SHOP.
 *
 * mod_alfa_catalog shows products, categories or manufacturers, each tab as a grid, a slider or a list, and reads
 * every record through the component's models and services. This suite proves the module's own part of that: what it
 * asks the component for, how much, how often, and what it draws from the answer. The component is not in the run.
 * Its models and services are stand-ins declared in this file, over fixtures in memory, which record every read made
 * of them; Joomla is not booted either (its Registry and Input come from the docroot's vendor folder, the few CMS
 * classes the module calls are stand-ins too). So the suite needs no database and writes nothing anywhere.
 *
 * What it cannot say: whether the real models answer as the stand-ins do (the listing query grouped by
 * manufacturer, the Categories model's parent filter, the category tree's counts). That is the live suite's part:
 * catalog-live-suite.php, read only, on an install.
 *
 *   [CT1] kinds and values: a stored kind, id list, choice and number are read within what they accept; the look
 *         accepts a list beside the grid and the slider, and no card setting has an effect in a list.
 *   [CT2] the operator's number is the limit: a model is asked for exactly that many, and for all of them when the
 *         number is 0; a products tab keeps to the form's own range; a tree is read a level at a time, one read per
 *         level drawn whatever the size of the shop, and only the open branch below the first level.
 *   [CT3] no read per record: a tab of 50 categories is one read, of 40 manufacturers with their product counts
 *         two, of products one.
 *   [CT4] automatic tabs: one tab per chosen category, per chosen manufacturer and per chosen label, each named
 *         after its subject and showing that subject's products within the row's number; the names of a module's
 *         subjects are one read per type; a subject the visitor is not shown makes no tab; only the first tab is
 *         read with the page, the others when opened; thirty chosen subjects are thirty tabs.
 *   [CT5] the three presentations draw for the three kinds: a grid of cards, a slider of cards, a list of rows; an
 *         image never has an empty description, also when the record has neither a description nor a name.
 *   [CT6] the tree marks the visitor's category, opens its branch and no other, draws only the levels asked for,
 *         opens every branch when told to, and shows the counts when asked.
 *   [CT7] the cache: a list of categories is stored per visitor's category, a showcase of cards once; recently
 *         viewed products are never stored and keep their page out of the page cache.
 *   [CT8] assets: a single grid or list needs no script, only a slider the slider library, only products the
 *         shop's styles, only product cards the buying scripts.
 *   [CT10] best sellers are ranked after the tab's filters: a tab of one manufacturer's, one label's or one
 *         category's best sellers holds its number of them though the shop's overall best sellers are others;
 *         products that never sold are not best sellers; a stored ranking is one tab's and one audience's, and one
 *         that names a product gone from the listing is ranked again at once.
 *   [CT9] the source: every rule of the stylesheet is scoped with :where(.mod-alfa-catalog), none is forced, no
 *         class, data attribute or id in a layout or a script carries the alfa- prefix, nothing names another
 *         module, every language key used is defined, and the registry names the files the build made.
 *
 *   cd <docroot> && php administrator/components/com_alfa/tests/modules/catalog-suite.php
 *   php tests/modules/catalog-suite.php joomla=<docroot>     (from the repository)
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace {
    PHP_SAPI === 'cli' or exit;

    define('_JEXEC', 1);

    $options = [];

    foreach (\array_slice($argv, 1) as $argument) {
        [$name, $value] = explode('=', $argument, 2) + [1 => ''];
        $options[$name] = $value;
    }

    $docroot = rtrim((string) ($options['joomla'] ?? getcwd()), '/');
    $vendor = $docroot . '/libraries/vendor/autoload.php';

    if (!is_file($vendor)) {
        fwrite(STDERR, "No Joomla vendor folder under {$docroot}: run from a docroot, or pass joomla=<docroot>.\n");
        exit(2);
    }

    require_once $vendor;

    $module = rtrim((string) ($options['module'] ?? ''), '/');

    foreach ([$module, \dirname(__DIR__, 2) . '/modules/mod_alfa_catalog', $docroot . '/modules/mod_alfa_catalog'] as $candidate) {
        if ($candidate !== '' && is_file($candidate . '/mod_alfa_catalog.xml')) {
            define('CATALOG_MODULE', $candidate);

            break;
        }
    }

    if (!\defined('CATALOG_MODULE')) {
        fwrite(STDERR, "mod_alfa_catalog was not found: pass module=<its folder>.\n");
        exit(2);
    }

    define('JPATH_SITE', $docroot);
    define('JPATH_ROOT', $docroot);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Alfa\\Module\\AlfaCatalog\\Site\\';

        if (str_starts_with($class, $prefix)) {
            $file = CATALOG_MODULE . '/src/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        }
    });

    /**
     * The fixtures every stand-in answers from, and the record of what was asked of them.
     */
    final class Shop
    {
        /** @var array<int, array{name: string, parent: int, visible: bool, alt: string, desc: string}> */
        public static array $categories = [];

        /** @var array<int, array{name: string, visible: bool, logo: bool}> */
        public static array $manufacturers = [];

        /** @var array<int, string> */
        public static array $labels = [];

        /** @var array<int, array{name: string, categories: int[], manufacturers: int[], labels: int[]}> */
        public static array $products = [];

        /** @var array<string, int> How often each stand-in was read. */
        public static array $reads = [];

        /** @var array<int, array<string, mixed>> The state of every listing read, in order. */
        public static array $listings = [];

        /** @var array<string, mixed> The request. */
        public static array $request = [];

        /** @var array<int, int> Units sold per product. */
        public static array $sold = [];

        /** @var int[] Products that have left the listing. */
        public static array $hidden = [];

        /** @var array<int, array{days: int, limit: int}> Every ranking asked of the listing model, in order. */
        public static array $rankings = [];

        /** @var string[] The ids asked of the ranking store, in order. */
        public static array $rankingIds = [];

        /** @var int[]|null What the ranking store holds; null = nothing, so it calls back. */
        public static ?array $storedRanking = null;

        /** @var array<int, int[]> The parents of every read of children, in order. */
        public static array $childReads = [];

        /** @var string[] The cache ids asked for, in order. */
        public static array $cacheIds = [];

        public static int $pageCacheExcluded = 0;

        /** @var int[] */
        public static array $recentlyViewed = [];

        public static function read(string $what): void
        {
            self::$reads[$what] = (self::$reads[$what] ?? 0) + 1;
        }

        public static function reset(): void
        {
            self::$reads = [];
            self::$listings = [];
            self::$cacheIds = [];
            self::$childReads = [];
            self::$rankings = [];
            self::$rankingIds = [];
            self::$pageCacheExcluded = 0;
            \Alfa\Module\AlfaCatalog\Site\Helper\Subjects::forget();
        }

    }

    /** A list model's stand-in: its state, and one recorded read per getItems(). */
    class StandInListModel
    {
        protected array $state = [];

        public function __construct(protected string $name)
        {
        }

        public function setState(string $key, mixed $value): void
        {
            $this->state[$key] = $value;
        }

        public function getState(string $key, mixed $default = null): mixed
        {
            return $this->state[$key] ?? $default;
        }

        public function getItems(): array
        {
            Shop::read($this->name);
            $rows = [];

            if ($this->name === 'Categories') {
                foreach (Shop::$categories as $id => $category) {
                    $chosen = $this->state['filter.categories'] ?? null;
                    $parent = $this->state['filter.parent_id'] ?? null;

                    if (!$category['visible'] || ($chosen !== null && !\in_array($id, $chosen, true)) || ($parent !== null && $category['parent'] !== (int) $parent)) {
                        continue;
                    }

                    $rows[] = (object) [
                        'id' => $id, 'name' => $category['name'], 'desc' => $category['desc'], 'link' => '/category/' . $id,
                        'medias' => [(object) ['url' => '/images/c' . $id . '.jpg', 'thumbnail_url' => '/images/c' . $id . '-thumb.jpg', 'alt' => $category['alt']]],
                    ];
                }
            } else {
                foreach (Shop::$manufacturers as $id => $manufacturer) {
                    $chosen = $this->state['filter.manufacturers'] ?? null;

                    if (!$manufacturer['visible'] || ($chosen !== null && !\in_array($id, $chosen, true))) {
                        continue;
                    }

                    $rows[] = (object) [
                        'id' => $id, 'name' => $manufacturer['name'], 'link' => '/products?manufacturer=' . $id, 'details_link' => '/manufacturer/' . $id,
                        'medias' => $manufacturer['logo'] ? [(object) ['url' => '/images/m' . $id . '.png', 'alt' => '']] : [],
                    ];
                }
            }

            if (($this->state['list.ordering'] ?? '') === 'name') {
                usort($rows, static fn (object $a, object $b): int => strcmp($a->name, $b->name));
            }

            $limit = (int) ($this->state['list.limit'] ?? 0);
            Shop::$listings[] = ['model' => $this->name] + $this->state;

            return $limit > 0 ? \array_slice($rows, 0, $limit) : $rows;
        }
    }
}

namespace Alfa\Component\Alfa\Site\Model {
    /** The catalogue listing model's stand-in: the products of the fixtures under the state it is handed. */
    class ItemsModel extends \StandInListModel
    {
        private int $context = 0;

        public function setCategoryContext(int $categoryId): self
        {
            $this->context = $categoryId;

            return $this;
        }

        /** The products of the fixtures that pass the state: [id => product]. */
        private function result(): array
        {
            $in = fn (string $state, array $own): bool => empty($this->state[$state]) || array_intersect((array) $this->state[$state], $own) !== [];

            return array_filter(
                \Shop::$products,
                fn (array $product, int $id): bool => !\in_array($id, \Shop::$hidden, true)
                    && ($this->context <= 0 || \in_array($this->context, $product['categories'], true))
                    && $in('filter.category', $product['categories'])
                    && $in('filter.manufacturer', $product['manufacturers'])
                    && $in('filter.label', $product['labels'])
                    && $in('filter.id', [$id]),
                ARRAY_FILTER_USE_BOTH,
            );
        }

        public function getItems(): array
        {
            \Shop::read('Items');
            \Shop::$listings[] = ['model' => 'Items', 'context' => $this->context] + $this->state;
            $items = [];

            foreach ($this->result() as $id => $product) {
                $items[] = (object) [
                    'id' => $id, 'name' => $product['name'], 'sku' => 'SKU' . $id, 'link' => '/product/' . $id, 'sellsOnline' => true,
                    'addToCartUrl' => '/cart/add', 'reloadUrl' => '',
                    'medias' => [(object) ['thumbnail' => 'p' . $id . '.jpg', 'thumbnail_url' => '/images/p' . $id . '.jpg', 'alt' => '']],
                    'manufacturers' => $product['manufacturers'], 'labels' => $product['labels'], 'categories' => $product['categories'],
                ];
            }

            $limit = (int) ($this->state['list.limit'] ?? 0);

            return $limit > 0 ? \array_slice($items, 0, $limit) : $items;
        }

        /** The best sellers of the result: its products that sold, most units first, within the number. */
        public function getBestSellingIds(int $days, int $limit): array
        {
            \Shop::read('ranking');
            \Shop::$rankings[] = ['days' => $days, 'limit' => $limit];
            $sold = array_intersect_key(\Shop::$sold, $this->result());
            arsort($sold);

            return \array_slice(array_keys($sold), 0, $limit);
        }

        /** The products of the result per manufacturer, counted in one recorded read. */
        public function getManufacturerCounts(array $manufacturerIds): array
        {
            \Shop::read('manufacturer counts');
            $counts = [];

            foreach ($manufacturerIds as $id) {
                $count = \count(array_filter(\Shop::$products, static fn (array $product): bool => \in_array($id, $product['manufacturers'], true)));

                if ($count > 0) {
                    $counts[$id] = $count;
                }
            }

            return $counts;
        }
    }
}

namespace Alfa\Component\Alfa\Site\Helper {
    final class CategoryHelper
    {
        public static function getChildren(array $parentIds, bool $byName = false, int $limit = 0): array
        {
            \Shop::read('children');
            \Shop::$childReads[] = $parentIds;
            $children = [];

            foreach (\Shop::$categories as $id => $category) {
                if ($category['visible'] && \in_array($category['parent'], $parentIds, true)) {
                    $children[$category['parent']][] = (object) ['id' => $id, 'parent_id' => $category['parent'], 'name' => $category['name']];
                }
            }

            foreach ($children as $parent => $rows) {
                if ($byName) {
                    usort($rows, static fn (object $a, object $b): int => strcmp($a->name, $b->name));
                }

                $children[$parent] = $limit > 0 && \count($parentIds) === 1 ? \array_slice($rows, 0, $limit) : $rows;
            }

            return $children;
        }

        public static function getCategoryPath(int $categoryId): array
        {
            $path = [];

            while ($categoryId > 0 && isset(\Shop::$categories[$categoryId])) {
                array_unshift($path, ['id' => $categoryId]);
                $categoryId = \Shop::$categories[$categoryId]['parent'];
            }

            return $path;
        }

        public static function getDescendantIds(int $parentId, int $maxDepth = 10): array
        {
            return [];
        }

        public static function getProductCounts(array $categoryIds): array
        {
            \Shop::read('category counts');
            $counts = [];

            foreach ($categoryIds as $id) {
                if (!empty(\Shop::$categories[$id]['visible'])) {
                    $counts[$id] = \count(array_filter(\Shop::$products, static fn (array $product): bool => \in_array($id, $product['categories'], true))) + 100;
                }
            }

            return $counts;
        }
    }

    final class PageCache
    {
        public static function excludeResponse(): void
        {
            \Shop::$pageCacheExcluded++;
        }
    }

    final class PriceSettings
    {
        public static function get(): array
        {
            return [];
        }
    }

    final class ShopMode
    {
        public static function sellsOnline(): bool
        {
            return true;
        }
    }

    final class RecentlyViewed
    {
        public static function ids(): array
        {
            return \Shop::$recentlyViewed;
        }
    }

    final class Layout
    {
        public static function render(string $layout, mixed $data = null): string
        {
            return '<span data-shop-layout="' . $layout . '"></span>';
        }
    }

    final class Surface
    {
        public static function render(object $owner, mixed $slot): string
        {
            return '';
        }

        public static function has(object $owner, mixed $slot): bool
        {
            return false;
        }
    }
}

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields {
    enum ItemListSurface
    {
        case Image;
        case Title;
        case BuyForm;
        case Specifications;
    }
}

namespace Alfa\Component\Alfa\Site\Service\Catalog {
    final class Labels
    {
        public static function predefined(): array
        {
            \Shop::read('labels');

            return array_map(static fn (string $name): object => (object) ['name' => $name], \Shop::$labels);
        }
    }
}

namespace Alfa\Component\Alfa\Site\Service\Pricing {
    final class PriceContext
    {
        public static function fromSession(): self
        {
            return new self();
        }

        public function toCacheKey(): string
        {
            return 'EUR';
        }
    }
}

namespace Alfa\Component\Alfa\Administrator\Service\Catalog {
    final class CatalogAccessPolicy
    {
        public function cacheKey(): string
        {
            return \Shop::$request['audience'] ?? 'guest';
        }
    }

    final class StockVisibilityPolicy
    {
        public function cacheKey(): string
        {
            return 'show';
        }
    }
}

namespace Alfa\Component\Alfa\Administrator\Service\Inventory {
    final class InventoryAvailabilityService
    {
        public static function inventoryVersion(mixed $db): int
        {
            return 7;
        }
    }
}

namespace Joomla\CMS {
    final class Factory
    {
        public static function getApplication(): object
        {
            return new class () {
                public function getInput(): \Joomla\Input\Input
                {
                    return new \Joomla\Input\Input(\Shop::$request);
                }

                public function bootComponent(string $component): object
                {
                    return new class () {
                        public function getMVCFactory(): object
                        {
                            return new class () {
                                public function createModel(string $name, string $prefix = '', array $config = []): object
                                {
                                    return $name === 'Items' ? new \Alfa\Component\Alfa\Site\Model\ItemsModel($name) : new \StandInListModel($name);
                                }
                            };
                        }
                    };
                }
            };
        }

        /** The ranking store: it records the id asked for, answers what it holds, else calls back. */
        public static function getCache(string $group, string $handler): object
        {
            return new class () {
                public function setCaching(bool $on): void
                {
                }

                public function setLifeTime(int $minutes): void
                {
                }

                public function get(callable $callback, array $arguments, string $id): mixed
                {
                    \Shop::$rankingIds[] = $id;

                    return \Shop::$storedRanking ?? \call_user_func_array($callback, $arguments);
                }
            };
        }

        public static function getContainer(): object
        {
            return new class () {
                public function get(string $name): object
                {
                    return new \stdClass();
                }
            };
        }
    }
}

namespace Joomla\CMS\Helper {
    final class ModuleHelper
    {
        public static function getLayoutPath(string $module, string $layout = 'default'): string
        {
            return CATALOG_MODULE . '/tmpl/' . $layout . '.php';
        }

        /** The module cache, never storing: it records the id asked for and calls back. */
        public static function moduleCache(object $module, object $params, object $cache): mixed
        {
            \Shop::$cacheIds[] = (string) $cache->modeparams;

            return \call_user_func_array([$cache->class, $cache->method], $cache->methodparams);
        }
    }
}

namespace Joomla\CMS\Language {
    final class Text
    {
        private static ?array $strings = null;

        public static function _(string $key): string
        {
            self::$strings ??= parse_ini_file(CATALOG_MODULE . '/language/en-GB/mod_alfa_catalog.ini', false, INI_SCANNER_RAW) ?: [];

            return (string) (self::$strings[$key] ?? $key);
        }

        public static function sprintf(string $key, mixed ...$values): string
        {
            return sprintf(self::_($key), ...$values);
        }

        public static function plural(string $key, int $count): string
        {
            $own = self::_($key . '_' . $count);

            return sprintf($own !== $key . '_' . $count ? $own : self::_($key), $count);
        }
    }
}

namespace Joomla\CMS\Router {
    final class Route
    {
        public static function _(string $url, bool $xhtml = true): string
        {
            return $url;
        }
    }
}

namespace Joomla\CMS\HTML {
    final class HTMLHelper
    {
        public static function _(string $key, mixed ...$arguments): string
        {
            return $key === 'string.truncate' ? mb_substr((string) $arguments[0], 0, (int) $arguments[1]) : '<input type="hidden" name="token" value="1">';
        }
    }
}

namespace {
    use Alfa\Module\AlfaCatalog\Site\Helper\AlfaCatalogHelper;
    use Alfa\Module\AlfaCatalog\Site\Helper\CardLook;
    use Alfa\Module\AlfaCatalog\Site\Helper\Subjects;
    use Alfa\Module\AlfaCatalog\Site\Provider\CategoriesProvider;
    use Alfa\Module\AlfaCatalog\Site\Provider\ManufacturersProvider;
    use Alfa\Module\AlfaCatalog\Site\Provider\ProductsProvider;
    use Alfa\Module\AlfaCatalog\Site\Provider\Providers;
    use Joomla\Registry\Registry;

    $pass = 0;
    $fail = 0;

    function is_true(string $what, bool $got): void
    {
        global $pass, $fail;

        if ($got) {
            $pass++;

            return;
        }

        $fail++;
        echo "  FAIL  {$what}\n";
    }

    function is_same(string $what, mixed $got, mixed $want): void
    {
        global $pass, $fail;

        if ($got === $want) {
            $pass++;

            return;
        }

        $fail++;
        echo "  FAIL  {$what}\n        got  " . json_encode($got) . "\n        want " . json_encode($want) . "\n";
    }

    /** A module's settings from its tab rows. */
    function module_params(array ...$rows): Registry
    {
        return new Registry(['tabs' => $rows]);
    }

    /** A tab row shown as a presentation. */
    function shown_as(string $display, array $row): array
    {
        return $row + ['card' => json_encode(['base' => ['display' => $display]])];
    }

    // The shop: three levels of categories, one of them hidden from this visitor; manufacturers, one hidden, one
    // without a logo; labels; products spread over them.
    Shop::$categories = [
        1 => ['name' => 'Shoes', 'parent' => 0, 'visible' => true, 'alt' => 'A row of shoes', 'desc' => '<p>Every <b>shoe</b> we sell.</p>'],
        2 => ['name' => 'Bags', 'parent' => 0, 'visible' => true, 'alt' => '', 'desc' => ''],
        3 => ['name' => 'Wholesale', 'parent' => 0, 'visible' => false, 'alt' => '', 'desc' => ''],
        4 => ['name' => 'Running', 'parent' => 1, 'visible' => true, 'alt' => '', 'desc' => ''],
        5 => ['name' => 'Trail', 'parent' => 4, 'visible' => true, 'alt' => '', 'desc' => ''],
        6 => ['name' => 'Boots', 'parent' => 1, 'visible' => true, 'alt' => '', 'desc' => ''],
        7 => ['name' => 'Backpacks', 'parent' => 2, 'visible' => true, 'alt' => '', 'desc' => ''],
        8 => ['name' => '', 'parent' => 0, 'visible' => true, 'alt' => '', 'desc' => ''],
    ];
    Shop::$manufacturers = [
        1 => ['name' => 'Acme', 'visible' => true, 'logo' => true],
        2 => ['name' => 'Borealis', 'visible' => true, 'logo' => false],
        3 => ['name' => 'Hidden Works', 'visible' => false, 'logo' => true],
        4 => ['name' => '', 'visible' => true, 'logo' => true],
    ];
    Shop::$labels = [1 => 'Black Friday', 2 => 'Eco'];

    for ($id = 1; $id <= 90; $id++) {
        Shop::$products[$id] = [
            'name' => $id === 1 ? '' : 'Product ' . $id,
            'categories' => [[1, 4], [2], [5], [6, 7]][$id % 4],
            'manufacturers' => [[1], [2], [1, 2]][$id % 3],
            'labels' => $id % 5 === 0 ? [1] : ($id % 7 === 0 ? [2] : []),
        ];
    }

    $module = (object) ['id' => 42, 'title' => 'Our catalogue'];

    // [CT1] Kinds and values.
    is_same('[CT1] an unknown kind is products', Providers::kind('orders'), 'products');
    is_same('[CT1] a known kind stays', Providers::kind('manufacturers'), 'manufacturers');
    is_same('[CT1] ids: whole, positive, once, in order', Providers::ids(['7', 3, '7', 0, -2, 'x', [1]]), [7, 3]);
    is_same('[CT1] a choice outside its choices is the first', Providers::choice('sideways', ['name', 'ordering']), 'name');
    is_same('[CT1] a number is kept within its bounds', Providers::bounded(999, 1, 60, 8), 60);
    is_same('[CT1] a missing number is the default', Providers::bounded(null, 1, 60, 8), 8);
    is_same('[CT1] the look accepts a list', CardLook::partial(['display' => 'list']), ['display' => 'list']);
    is_same('[CT1] the look still refuses anything else', CardLook::partial(['display' => 'carousel']), []);
    $cardSettings = array_diff(array_keys(CardLook::DEFAULTS), CardLook::GROUPS['display']);
    is_same(
        '[CT1] every card setting needs a grid or a slider',
        array_values(array_filter($cardSettings, static fn (string $key): bool => array_filter(CardLook::WHEN[$key] ?? [[]], static fn (array $when): bool => ($when['display'] ?? null) !== CardLook::CARDS) !== [])),
        [],
    );

    // [CT2] The operator's number is the limit.
    Shop::reset();
    $params = module_params();
    $query = Providers::of('products')->query(['limit' => 5000], 'grid');
    is_same('[CT2] a products tab keeps to the form\'s range, 1 to 60', $query['limit'], ProductsProvider::LIMIT_MAX);
    $records = Providers::of('products')->records(['limit' => 5000] + $query, $params);
    is_same('[CT2] the listing model is handed that range, never a number outside it', Shop::$listings[0]['list.limit'], ProductsProvider::LIMIT_MAX);
    is_same('[CT2] and no more products come back', \count($records), ProductsProvider::LIMIT_MAX);

    Shop::$manufacturers += array_combine(range(300, 549), array_map(static fn (int $n): array => ['name' => 'Maker ' . $n, 'visible' => true, 'logo' => true], range(300, 549)));
    Shop::reset();
    $records = Providers::of('manufacturers')->records(Providers::of('manufacturers')->query(['man_limit' => 0], 'grid'), $params);
    is_same('[CT2] 0 manufacturers is all of them: the model is asked without a limit', Shop::$listings[0]['list.limit'], 0);
    is_same('[CT2] and all 253 come back in one read', [\count($records), Shop::$reads], [253, ['Manufacturers' => 1]]);
    Shop::reset();
    $records = Providers::of('manufacturers')->records(Providers::of('manufacturers')->query(['man_limit' => 7], 'grid'), $params);
    is_same('[CT2] a number of manufacturers is asked of the model as it is', [Shop::$listings[0]['list.limit'], \count($records)], [7, 7]);
    Shop::$manufacturers = array_slice(Shop::$manufacturers, 0, 4, true);

    Shop::reset();
    Providers::of('categories')->records(Providers::of('categories')->query(['cat_limit' => 0], 'grid'), $params);
    is_same('[CT2] 0 categories is all of them', Shop::$listings[0]['list.limit'], 0);
    Shop::reset();
    Providers::of('categories')->records(Providers::of('categories')->query(['cat_limit' => 2], 'grid'), $params);
    is_same('[CT2] a number of categories is asked of the model as it is', Shop::$listings[0]['list.limit'], 2);
    is_same('[CT2] a stored 50 levels is read as the deepest the form offers', Providers::of('categories')->query(['cat_levels' => 50], 'list')['levels'], CategoriesProvider::LEVELS_MAX);

    // A shop of 40 x 40 x 2 categories.
    $kept = Shop::$categories;
    Shop::$categories = [];

    for ($top = 1; $top <= 40; $top++) {
        Shop::$categories[$top] = ['name' => 'Top ' . $top, 'parent' => 0, 'visible' => true, 'alt' => '', 'desc' => ''];

        for ($child = 1; $child <= 40; $child++) {
            $id = 1000 + $top * 100 + $child;
            Shop::$categories[$id] = ['name' => 'Child ' . $child, 'parent' => $top, 'visible' => true, 'alt' => '', 'desc' => ''];
            Shop::$categories[$id * 100 + 1] = ['name' => 'Leaf 1', 'parent' => $id, 'visible' => true, 'alt' => '', 'desc' => ''];
            Shop::$categories[$id * 100 + 2] = ['name' => 'Leaf 2', 'parent' => $id, 'visible' => true, 'alt' => '', 'desc' => ''];
        }
    }

    $count = static function (array $nodes) use (&$count): int {
        return array_sum(array_map(static fn (object $node): int => 1 + $count($node->children), $nodes));
    };
    Shop::reset();
    $tree = Providers::of('categories')->records(Providers::of('categories')->query(['cat_levels' => 2, 'cat_open' => 'all'], 'list'), $params);
    is_same('[CT2] every branch open to two levels: all 1640 categories of those levels, none left out', $count($tree), 1640);
    is_same('[CT2] in two reads, one per level', Shop::$reads, ['children' => 2]);
    Shop::reset();
    $tree = Providers::of('categories')->records(Providers::of('categories')->query(['cat_levels' => 3, 'cat_open' => 'all', 'cat_limit' => 3], 'list'), $params);
    is_same('[CT2] a number at the first level: 3, the 120 inside them and the 240 inside those', [\count($tree), $count($tree)], [3, 363]);
    is_same('[CT2] in three reads', Shop::$reads, ['children' => 3]);

    Shop::reset();
    Shop::$request = ['option' => 'com_alfa', 'view' => 'items', 'category_id' => 170701];
    $tree = Providers::of('categories')->records(Providers::of('categories')->query(['cat_levels' => 10, 'cat_open' => 'current'], 'list'), $params);
    is_same('[CT2] only the visitor\'s branch open: the 40 of the top, the 40 inside one and the 2 inside one of those', $count($tree), 82);
    is_same('[CT2] four reads though ten levels were allowed: three levels drawn, and the visitor\'s category found empty', Shop::$reads, ['children' => 4]);
    is_same('[CT2] below the first level each read is of one category', Shop::$childReads, [[0], [7], [1707], [170701]]);
    Shop::reset();
    Shop::$request = [];
    $tree = Providers::of('categories')->records(Providers::of('categories')->query(['cat_levels' => 10, 'cat_open' => 'current'], 'list'), $params);
    is_same('[CT2] with no visitor\'s category the first level alone, in one read', [$count($tree), Shop::$reads], [40, ['children' => 1]]);
    Shop::$categories = $kept;

    // [CT3] No read per record.
    Shop::$categories += array_combine(range(100, 149), array_map(static fn (int $n): array => ['name' => 'Extra ' . $n, 'parent' => 9, 'visible' => true, 'alt' => '', 'desc' => ''], range(100, 149)));
    Shop::reset();
    $records = Providers::of('categories')->records(Providers::of('categories')->query(['cat_parent' => 9], 'grid'), $params);
    is_same('[CT3] 50 categories', \count($records), 50);
    is_same('[CT3] are one read', Shop::$reads, ['Categories' => 1]);
    Shop::reset();
    $records = Providers::of('categories')->records(Providers::of('categories')->query(['cat_parent' => 9, 'cat_show_count' => 1], 'grid'), $params);
    is_same('[CT3] with their counts two: the categories, then every count together', Shop::$reads, ['Categories' => 1, 'category counts' => 1]);
    is_same('[CT3] a category\'s count is the shop\'s', $records[0]->count, 100);
    Shop::$categories = $kept;

    Shop::$manufacturers += array_combine(range(100, 139), array_map(static fn (int $n): array => ['name' => 'Maker ' . $n, 'visible' => true, 'logo' => true], range(100, 139)));
    Shop::reset();
    $records = Providers::of('manufacturers')->records(Providers::of('manufacturers')->query(['man_show_count' => 1], 'grid'), $params);
    is_same('[CT3] 43 manufacturers with their counts', \count($records), 43);
    is_same('[CT3] are two reads: the manufacturers, then every count together', Shop::$reads, ['Manufacturers' => 1, 'manufacturer counts' => 1]);
    is_same('[CT3] a count is the catalogue\'s', array_column($records, 'count', 'id')[1], 60);
    is_same('[CT3] a manufacturer with no product counts zero', array_column($records, 'count', 'id')[100], 0);
    Shop::$manufacturers = array_slice(Shop::$manufacturers, 0, 4, true);

    Shop::reset();
    $records = Providers::of('products')->records(Providers::of('products')->query(['limit' => 30], 'grid'), $params);
    is_same('[CT3] 30 products are one read', [\count($records), Shop::$reads], [30, ['Items' => 1]]);

    // [CT4] Automatic tabs.
    Shop::reset();
    $params = module_params(['kind' => 'products', 'auto' => 'manufacturer', 'manufacturers' => [1, 3, 2], 'limit' => 4]);
    $tabs = AlfaCatalogHelper::definitions($params);
    is_same('[CT4] one tab per manufacturer the visitor is shown', array_column($tabs, 'key'), ['tab-0-m1', 'tab-0-m2']);
    is_same('[CT4] each named after its manufacturer', array_column($tabs, 'label'), ['Acme', 'Borealis']);
    is_same('[CT4] each with that manufacturer alone', array_map(static fn (array $tab): array => $tab['query']['manufacturers'], $tabs), [[1], [2]]);
    is_same('[CT4] each with its subject', $tabs[1]['subject'], ['type' => 'manufacturer', 'id' => 2, 'name' => 'Borealis']);
    is_same('[CT4] the names of every manufacturer in one read', Shop::$reads, ['Manufacturers' => 1]);

    Shop::reset();
    $groups = AlfaCatalogHelper::getGroups($params, 42);
    is_same('[CT4] the first tab is read with the page, the second later', [$groups[0]['lazy'], $groups[1]['lazy'], $groups[1]['items']], [false, true, null]);
    is_same('[CT4] the first tab holds no more than the row\'s number', \count($groups[0]['items']), 4);
    is_same('[CT4] and only that manufacturer\'s products', array_filter($groups[0]['items'], static fn (object $item): bool => !\in_array(1, $item->manufacturers, true)), []);
    is_same('[CT4] one listing read for the page, whatever the number of tabs', Shop::$reads['Items'], 1);
    $opened = AlfaCatalogHelper::getGroups($params, 42, 'tab-0-m2');
    is_same('[CT4] an opened tab is that manufacturer\'s products, within the number', [\count($opened), \count($opened[0]['items']), array_filter($opened[0]['items'], static fn (object $item): bool => !\in_array(2, $item->manufacturers, true))], [1, 4, []]);

    Shop::reset();
    $params = module_params(['kind' => 'products', 'auto' => 'label', 'labels' => [2, 9, 1], 'limit' => 6, 'source' => 'on_sale']);
    $tabs = AlfaCatalogHelper::definitions($params);
    is_same('[CT4] one tab per label that exists', array_column($tabs, 'key'), ['tab-0-l2', 'tab-0-l1']);
    is_same('[CT4] each named after its label', array_column($tabs, 'label'), ['Eco', 'Black Friday']);
    is_same('[CT4] each keeps the row\'s source', array_column(array_column($tabs, 'query'), 'source'), ['on_sale', 'on_sale']);
    is_same('[CT4] the labels in one read', Shop::$reads, ['labels' => 1]);
    $opened = AlfaCatalogHelper::getGroups($params, 42, 'tab-0-l1');
    is_same('[CT4] a label\'s tab holds only products that tick it', array_filter($opened[0]['items'], static fn (object $item): bool => !\in_array(1, $item->labels, true)), []);
    is_true('[CT4] within the number', \count($opened[0]['items']) <= 6 && $opened[0]['items'] !== []);

    Shop::reset();
    $params = module_params(
        ['kind' => 'products', 'auto' => 'category', 'categories' => [1, 3, 2], 'limit' => 3],
        ['kind' => 'products', 'categories' => [6, 7]],
        ['kind' => 'products', 'auto' => 'manufacturer'],
    );
    $tabs = AlfaCatalogHelper::definitions($params);
    is_same('[CT4] one tab per category the visitor may see, then the plain rows', array_column($tabs, 'key'), ['tab-0-c1', 'tab-0-c2', 'tab-1', 'tab-2']);
    is_same('[CT4] a category\'s tab is a listing of it', [$tabs[0]['query']['context'], $tabs[1]['query']['context']], [1, 2]);
    is_same('[CT4] a plain tab of two categories is named after both', $tabs[2]['label'], 'Boots, Backpacks');
    is_same('[CT4] a row with nothing chosen stays one tab, named after its source', $tabs[3]['label'], 'Items');
    is_same('[CT4] the categories of every row in one read', Shop::$reads, ['Categories' => 1]);

    Shop::$manufacturers += array_combine(range(200, 229), array_map(static fn (int $n): array => ['name' => 'Maker ' . $n, 'visible' => true, 'logo' => true], range(200, 229)));
    Shop::reset();
    $tabs = AlfaCatalogHelper::definitions(module_params(['kind' => 'products', 'auto' => 'manufacturer', 'manufacturers' => range(200, 229)]));
    is_same('[CT4] thirty chosen manufacturers make thirty tabs', \count($tabs), 30);
    is_same('[CT4] their names still one read', Shop::$reads, ['Manufacturers' => 1]);
    Shop::reset();
    $groups = AlfaCatalogHelper::getGroups(module_params(['kind' => 'products', 'auto' => 'manufacturer', 'manufacturers' => array_merge([1], range(200, 228))]), 42);
    is_same('[CT4] and of thirty tabs only the first is read with the page', [\count($groups), \count(array_filter($groups, static fn (array $group): bool => !$group['lazy'])), Shop::$reads['Items']], [30, 1, 1]);
    Shop::$manufacturers = array_slice(Shop::$manufacturers, 0, 4, true);

    Shop::reset();
    is_same('[CT4] a tab whose every category is hidden is not made', AlfaCatalogHelper::definitions(module_params(['kind' => 'products', 'categories' => [3]])), []);

    // [CT5] The three presentations for the three kinds.
    $rows = [
        'products' => ['kind' => 'products', 'limit' => 5, 'show_link' => 1],
        'categories' => ['kind' => 'categories', 'cat_show_desc' => 1, 'cat_show_count' => 1],
        'manufacturers' => ['kind' => 'manufacturers', 'man_show_count' => 1, 'show_link' => 1],
    ];
    $marks = [
        'grid' => ['class="grid"', 'class="item-card"'],
        'slider' => ['data-slider', 'keen-slider__slide', 'class="item-card"', 'data-bands='],
        'list' => ['class="entry-link"'],
    ];
    $one = ['products' => 'product', 'categories' => 'category', 'manufacturers' => 'manufacturer'];

    foreach ($rows as $kind => $row) {
        foreach ($marks as $displayAs => $wanted) {
            Shop::reset();
            $params = module_params(shown_as($displayAs, $row));
            $groups = AlfaCatalogHelper::getGroups($params, 42);
            $html = AlfaCatalogHelper::renderGroup($module, $params, $groups[0] ?? []);

            is_same("[CT5] {$kind} as a {$displayAs}: the tab is that presentation", $groups[0]['display'] ?? null, $displayAs);

            foreach ($wanted as $mark) {
                is_true("[CT5] {$kind} as a {$displayAs} draws {$mark}", str_contains($html, $mark));
            }

            if ($displayAs !== 'list') {
                is_same("[CT5] {$kind} as a {$displayAs}: one card of its kind per record", substr_count($html, 'data-kind="' . $one[$kind] . '"'), \count($groups[0]['items']));
                is_true("[CT5] {$kind} as a {$displayAs}: the cards name their place", str_contains($html, 'data-look="42-tab-0"'));
                is_true("[CT5] {$kind} as a {$displayAs}: no list row", !str_contains($html, 'class="entry'));
            } else {
                is_true("[CT5] {$kind} as a list: no card", !str_contains($html, 'item-card'));
                is_true("[CT5] {$kind} as a list: no slider", !str_contains($html, 'data-slider'));
            }

            preg_match_all('/<img\b[^>]*>/', $html, $images);
            is_true("[CT5] {$kind} as a {$displayAs} draws images", $kind === 'categories' && $displayAs === 'list' ? $images[0] === [] : $images[0] !== []);
            is_same("[CT5] {$kind} as a {$displayAs}: no image without a description", array_values(array_filter($images[0], static fn (string $image): bool => !preg_match('/\salt="[^"]+"/', $image))), []);
            is_same("[CT5] {$kind} as a {$displayAs}: no link without an address", preg_match('/<a\b[^>]*href=""/', $html), 0);
            is_same("[CT5] {$kind} as a {$displayAs}: the view-all link when there is somewhere to go", str_contains($html, 'data-action="view-all"'), $kind !== 'categories' && !empty($row['show_link']));
        }
    }

    Shop::reset();
    $params = module_params(shown_as('grid', $rows['categories']));
    $html = AlfaCatalogHelper::renderGroup($module, $params, AlfaCatalogHelper::getGroups($params, 42)[0]);
    is_true('[CT5] a category card takes its image\'s own description', str_contains($html, 'alt="A row of shoes"'));
    is_true('[CT5] and the category\'s name where the image has none', str_contains($html, 'alt="Bags"'));
    is_true('[CT5] a category without a name is named by its id', str_contains($html, 'alt="Category 8"'));
    is_true('[CT5] the description is drawn as text', str_contains($html, 'Every shoe we sell.') && !str_contains($html, '<b>shoe</b>'));
    is_true('[CT5] the count is drawn in words', str_contains($html, ' products</p>'));

    Shop::reset();
    $params = module_params(shown_as('grid', ['kind' => 'manufacturers', 'man_show' => 'logo']));
    $html = AlfaCatalogHelper::renderGroup($module, $params, AlfaCatalogHelper::getGroups($params, 42)[0]);
    is_true('[CT5] a logo alone is described by the manufacturer\'s name', str_contains($html, 'alt="Acme"'));
    is_true('[CT5] and is the card\'s link, in the tab order', (bool) preg_match('/<a href="[^"]+">\s*<img[^>]*alt="Acme"/', $html));
    is_true('[CT5] a manufacturer without a logo shows its name', (bool) preg_match('/item-card-title">\s*<a[^>]*>Borealis</', $html));
    is_true('[CT5] a manufacturer without a name is named by its id', str_contains($html, 'alt="Manufacturer 4"'));
    $params = module_params(shown_as('grid', ['kind' => 'manufacturers', 'man_link' => 'page']));
    $html = AlfaCatalogHelper::renderGroup($module, $params, AlfaCatalogHelper::getGroups($params, 42)[0]);
    is_true('[CT5] a manufacturer leads to its page when told to', str_contains($html, 'href="/manufacturer/1"') && !str_contains($html, 'href="/products?manufacturer=1"'));

    Shop::reset();
    $params = module_params(shown_as('list', $rows['products']));
    $html = AlfaCatalogHelper::renderGroup($module, $params, AlfaCatalogHelper::getGroups($params, 42)[0]);
    is_true('[CT5] a product row draws the shop\'s price', str_contains($html, 'data-shop-layout="item.price"'));
    is_true('[CT5] and no buy form', !str_contains($html, '<form'));
    is_true('[CT5] a product without a name is named by its code', str_contains($html, 'alt="SKU1"'));

    // [CT6] The tree.
    Shop::reset();
    Shop::$request = ['option' => 'com_alfa', 'view' => 'items', 'category_id' => 5];
    $params = module_params(shown_as('list', ['kind' => 'categories', 'cat_levels' => 3, 'cat_open' => 'current', 'cat_show_count' => 1]));
    $groups = AlfaCatalogHelper::getGroups($params, 42);
    $html = AlfaCatalogHelper::renderGroup($module, $params, $groups[0]);
    is_same('[CT6] one category is the visitor\'s', substr_count($html, 'aria-current="page"'), 1);
    is_true('[CT6] and it is the right one', (bool) preg_match('/aria-current="page">\s*<span class="entry-name">Trail</', $html));
    is_same('[CT6] its branch is open: itself and its two ancestors', substr_count($html, 'data-state="open"'), 3);
    is_true('[CT6] the levels of its branch are drawn', str_contains($html, '>Running<') && str_contains($html, '>Boots<'));
    is_true('[CT6] no other branch is', !str_contains($html, '>Backpacks<'));
    is_true('[CT6] a category hidden from the visitor is not drawn', !str_contains($html, 'Wholesale'));
    is_true('[CT6] a category without a name is named by its id', str_contains($html, '<span class="entry-name">Category 8</span>'));
    is_true('[CT6] the counts are drawn', substr_count($html, 'class="entry-count"') === substr_count($html, 'class="node"'));
    is_true('[CT6] in a navigation landmark named after the module', str_contains($html, '<nav class="tree-nav" aria-label="Our catalogue">'));
    is_same('[CT6] three levels drawn are three reads, and every count one more', Shop::$reads, ['children' => 3, 'category counts' => 1]);

    $all = module_params(shown_as('list', ['kind' => 'categories', 'cat_levels' => 3, 'cat_open' => 'all']));
    $html = AlfaCatalogHelper::renderGroup($module, $all, AlfaCatalogHelper::getGroups($all, 42)[0]);
    is_true('[CT6] every branch opens when told to', str_contains($html, '>Backpacks<') && str_contains($html, '>Trail<'));
    is_true('[CT6] without counts none is drawn', !str_contains($html, 'entry-count'));

    $two = module_params(shown_as('list', ['kind' => 'categories', 'cat_levels' => 2, 'cat_open' => 'all']));
    $html = AlfaCatalogHelper::renderGroup($module, $two, AlfaCatalogHelper::getGroups($two, 42)[0]);
    is_true('[CT6] two levels stop above the third', str_contains($html, '>Running<') && !str_contains($html, '>Trail<'));

    $from = module_params(shown_as('list', ['kind' => 'categories', 'cat_parent' => 1, 'cat_levels' => 2, 'cat_ordering' => 'name']));
    $groups = AlfaCatalogHelper::getGroups($from, 42);
    is_same('[CT6] started from a category, its children by name', array_column($groups[0]['items'], 'name'), ['Boots', 'Running']);
    is_same('[CT6] and the tab is named after that category', $groups[0]['label'], 'Shoes');

    Shop::$request = ['option' => 'com_content', 'view' => 'article', 'category_id' => 5];
    $html = AlfaCatalogHelper::renderGroup($module, $params, AlfaCatalogHelper::getGroups($params, 42)[0]);
    is_true('[CT6] off the shop\'s pages nothing is marked or open', !str_contains($html, 'aria-current') && !str_contains($html, 'data-state="open"'));

    $mixed = module_params(['kind' => 'products', 'limit' => 2], shown_as('list', ['kind' => 'categories']));
    $groups = AlfaCatalogHelper::getGroups($mixed, 42);
    is_same('[CT6] a tree in a second tab is read with the page, where the visitor\'s category is known', [$groups[1]['lazy'], $groups[1]['items'] !== null], [false, true]);

    // [CT7] The cache.
    $treeTab = module_params(shown_as('list', ['kind' => 'categories', 'cat_levels' => 3]));
    $cardsTab = module_params(shown_as('grid', ['kind' => 'categories']), shown_as('slider', ['kind' => 'manufacturers']));
    $ids = [];

    foreach ([5, 6, 0] as $current) {
        Shop::reset();
        Shop::$request = $current > 0 ? ['option' => 'com_alfa', 'view' => 'items', 'category_id' => $current] : [];
        AlfaCatalogHelper::getGroups($treeTab, 42);
        $ids['tree'][] = Shop::$cacheIds[0];
        Shop::reset();
        AlfaCatalogHelper::getGroups($cardsTab, 42);
        AlfaCatalogHelper::getGroups($cardsTab, 42, 'tab-1');
        $ids['cards'][] = Shop::$cacheIds;
    }

    is_same('[CT7] a list of categories is stored once per visitor\'s category', \count(array_unique($ids['tree'])), 3);
    is_same('[CT7] a showcase of cards is stored once, whatever the category', \count(array_unique(array_map('json_encode', $ids['cards']))), 1);
    is_true('[CT7] two tabs of one module are two entries', $ids['cards'][0][0] !== $ids['cards'][0][1]);
    Shop::reset();
    Shop::$request = ['Itemid' => 9];
    AlfaCatalogHelper::getGroups($cardsTab, 42);
    is_true('[CT7] another page is another entry (a link follows the page\'s menu item)', Shop::$cacheIds[0] !== $ids['cards'][0][0]);
    Shop::$request = [];
    Shop::reset();
    AlfaCatalogHelper::getGroups($cardsTab, 43);
    is_true('[CT7] another module is another entry', Shop::$cacheIds[0] !== $ids['cards'][0][0]);

    Shop::reset();
    Shop::$recentlyViewed = [12, 3, 44];
    $recent = module_params(['kind' => 'products', 'source' => 'recently_viewed', 'limit' => 2]);
    $groups = AlfaCatalogHelper::getGroups($recent, 42);
    is_same('[CT7] recently viewed: most recent first, within the number', array_column($groups[0]['items'], 'id'), [12, 3]);
    is_same('[CT7] never asked of the cache', Shop::$cacheIds, []);
    is_same('[CT7] and the page is kept out of the page cache', Shop::$pageCacheExcluded, 1);
    Shop::reset();
    Shop::$recentlyViewed = [];
    is_same('[CT7] a visitor who viewed nothing gets no module and no exclusion', [AlfaCatalogHelper::getGroups($recent, 42), Shop::$pageCacheExcluded], [[], 0]);

    // [CT8] Assets.
    $needs = static fn (array ...$tabRows): array => array_keys(array_filter(AlfaCatalogHelper::needs(AlfaCatalogHelper::definitions(module_params(...$tabRows)))));
    is_same('[CT8] one grid of categories needs nothing but the stylesheet', $needs(shown_as('grid', ['kind' => 'categories'])), []);
    is_same('[CT8] one list of manufacturers neither', $needs(shown_as('list', ['kind' => 'manufacturers'])), []);
    is_same('[CT8] a slider needs the script and the slider library', $needs(shown_as('slider', ['kind' => 'manufacturers'])), ['script', 'slider']);
    is_same('[CT8] a grid of products needs the shop\'s styles and the buying scripts', $needs(shown_as('grid', ['kind' => 'products'])), ['shop', 'buy']);
    is_same('[CT8] a list of products needs the shop\'s styles only', $needs(shown_as('list', ['kind' => 'products'])), ['shop']);
    is_same('[CT8] two tabs need the script', $needs(shown_as('grid', ['kind' => 'categories']), shown_as('list', ['kind' => 'manufacturers'])), ['script']);
    Shop::reset();
    $css = AlfaCatalogHelper::responsiveCss(42, new Registry(), AlfaCatalogHelper::definitions(module_params(shown_as('grid', ['kind' => 'categories']), shown_as('list', ['kind' => 'manufacturers']))));
    is_true('[CT8] a grid tab gets its columns per width', str_contains($css, '.mod-alfa-catalog-42 > [data-panel="tab-0"]') && str_contains($css, '--mod-alfa-catalog-columns:4'));
    is_true('[CT8] a list tab gets none', !str_contains($css, 'tab-1'));
    $css = AlfaCatalogHelper::lookCss(42, new Registry(), AlfaCatalogHelper::definitions(module_params(['kind' => 'manufacturers', 'card' => json_encode(['base' => ['display' => 'grid', 'radius' => 8]])])));
    is_true('[CT8] a card rule is scoped with :where and names its place', str_starts_with($css, ':where(.mod-alfa-catalog) .item-card[data-look="42-tab-0"]{'));

    // [CT10] Best sellers: the tab's filters first, then the ranking.
    // The shop's best sellers by far are the products of manufacturer 1 alone; the others sold a little, and
    // every seventh product never sold.
    foreach (Shop::$products as $id => $product) {
        if ($id % 7 !== 0) {
            Shop::$sold[$id] = $product['manufacturers'] === [1] ? 1000 + $id : $id;
        }
    }

    $best = static fn (array $row, array $settings = []): Registry => new Registry(['tabs' => [$row + ['kind' => 'products', 'source' => 'best_sellers']]] + $settings);
    $live = ['bestsellers_cache' => 0];
    Shop::reset();
    $items = AlfaCatalogHelper::getGroups($best(['manufacturers' => [2], 'limit' => 5], $live), 42)[0]['items'];
    is_same('[CT10] a manufacturer\'s best sellers: the number asked for', \count($items), 5);
    is_same('[CT10] every one of that manufacturer', array_filter($items, static fn (object $item): bool => !\in_array(2, $item->manufacturers, true)), []);
    $units = array_map(static fn (object $item): int => Shop::$sold[$item->id], $items);
    is_same('[CT10] most sold first', $units, [89, 88, 86, 85, 83]);
    is_same('[CT10] one ranking and one listing read, each for exactly the number', [Shop::$reads, Shop::$rankings[0]['limit'], Shop::$listings[0]['list.limit']], [['ranking' => 1, 'Items' => 1], 5, 5]);
    is_same('[CT10] with the refresh at 0 the ranking store is not asked', Shop::$rankingIds, []);

    Shop::reset();
    $items = AlfaCatalogHelper::getGroups($best(['labels' => [1], 'categories' => [2], 'limit' => 3], $live), 42)[0]['items'];
    is_same('[CT10] a label\'s best sellers within a category: the number asked for', \count($items), 3);
    is_same('[CT10] every one ticks the label and is in the category', array_filter($items, static fn (object $item): bool => !\in_array(1, $item->labels, true) || !\in_array(2, $item->categories, true)), []);

    Shop::reset();
    $items = AlfaCatalogHelper::getGroups($best(['labels' => [2], 'limit' => 60], $live), 42);
    is_same('[CT10] a product that never sold is not a best seller: a label only unsold products tick gives no tab', $items, []);

    Shop::reset();
    $tabs = $best(['auto' => 'manufacturer', 'manufacturers' => [1, 2], 'limit' => 4], $live);
    $second = AlfaCatalogHelper::getGroups($tabs, 42, 'tab-0-m2')[0]['items'];
    is_same('[CT10] an automatic tab per manufacturer holds that manufacturer\'s best sellers, the number of them', [\count($second), array_filter($second, static fn (object $item): bool => !\in_array(2, $item->manufacturers, true))], [4, []]);

    Shop::reset();
    AlfaCatalogHelper::getGroups($best(['manufacturers' => [2], 'limit' => 5]), 42);
    AlfaCatalogHelper::getGroups($best(['manufacturers' => [1], 'limit' => 5]), 42);
    AlfaCatalogHelper::getGroups($best(['manufacturers' => [2], 'limit' => 6]), 42);
    Shop::$request = ['audience' => 'wholesale'];
    AlfaCatalogHelper::getGroups($best(['manufacturers' => [2], 'limit' => 5]), 42);
    Shop::$request = [];
    AlfaCatalogHelper::getGroups($best(['manufacturers' => [2], 'limit' => 5]), 42);
    is_same('[CT10] a stored ranking is one tab\'s (its filters, its number) and one audience\'s', [\count(array_unique(Shop::$rankingIds)), Shop::$rankingIds[0] === Shop::$rankingIds[4]], [4, true]);

    Shop::reset();
    Shop::$storedRanking = [89, 88, 86, 85, 83];
    Shop::$hidden = [88];
    $items = AlfaCatalogHelper::getGroups($best(['manufacturers' => [2], 'limit' => 5]), 42)[0]['items'];
    is_same('[CT10] a stored ranking that names a product gone from the listing is ranked again: the number is kept', array_column($items, 'id'), [89, 86, 85, 83, 82]);
    is_same('[CT10] at the cost of one ranking and one more listing read', Shop::$reads, ['Items' => 2, 'ranking' => 1]);
    Shop::$hidden = [];
    Shop::reset();
    $items = AlfaCatalogHelper::getGroups($best(['manufacturers' => [2], 'limit' => 5]), 42)[0]['items'];
    is_same('[CT10] a stored ranking that still holds is used as it is', [array_column($items, 'id'), Shop::$reads], [[89, 88, 86, 85, 83], ['Items' => 1]]);
    Shop::$storedRanking = null;

    // [CT9] The source.
    $read = static fn (string $file): string => (string) file_get_contents(CATALOG_MODULE . '/' . $file);
    $css = preg_replace('#/\*.*?\*/#s', '', $read('media/css/catalog.css'));
    $unscoped = [];

    foreach (explode('}', (string) preg_replace('/@(media|supports)[^{]*\{/', '', $css)) as $rule) {
        $selectors = trim((string) strstr($rule, '{', true));

        if ($selectors === '' || str_starts_with($selectors, '@keyframes') || preg_match('/^(from|to|\d+%)$/', $selectors)) {
            continue;
        }

        // The list's own commas, never the ones inside :is() or :not().
        $depth = 0;
        $selector = '';

        foreach (str_split($selectors . ',') as $char) {
            $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);

            if ($char !== ',' || $depth > 0) {
                $selector .= $char;

                continue;
            }

            if (!str_contains($selector, ':where(.mod-alfa-catalog)')) {
                $unscoped[] = trim($selector);
            }

            $selector = '';
        }
    }

    is_same('[CT9] every rule of the stylesheet is scoped with :where(.mod-alfa-catalog)', $unscoped, []);
    is_true('[CT9] no declaration is forced', !str_contains($css, '!important'));
    is_true('[CT9] the stylesheet is not empty', substr_count($css, ':where(.mod-alfa-catalog)') > 50);

    $sources = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CATALOG_MODULE, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (preg_match('/\.(php|xml|ini|json|css|js|md)$/', $file->getFilename()) && !preg_match('/\.min\.|keen-slider/', $file->getFilename())) {
            $sources[substr($file->getPathname(), \strlen(CATALOG_MODULE) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }

    $classes = [];

    foreach ($sources as $name => $source) {
        if ((str_starts_with($name, 'tmpl/') || str_starts_with($name, 'layouts/')) && preg_match_all('/class="([^"<]*)/', $source, $found)) {
            $classes = array_merge($classes, preg_split('/\s+/', implode(' ', $found[1]), -1, PREG_SPLIT_NO_EMPTY));
        }
    }

    is_same('[CT9] no class in a layout carries the alfa- prefix', array_values(array_filter(array_unique($classes), static fn (string $class): bool => str_starts_with($class, 'alfa-'))), []);
    is_true('[CT9] the layouts were read', \count(array_unique($classes)) > 15);
    is_same(
        '[CT9] no data attribute, element id or script name carries the prefix either',
        array_keys(array_filter($sources, static fn (string $source, string $name): bool => !str_ends_with($name, '.md') && preg_match('/data-alfa-|dataset\.alfa|id="alfa-|[\s"\'.]alfa-look|com-alfa\b/', $source) === 1, ARRAY_FILTER_USE_BOTH)),
        [],
    );
    is_true('[CT9] the root is the module\'s own class and nothing of the shop\'s', str_contains($sources['tmpl/default.php'], '<div class="mod-alfa-catalog mod-alfa-catalog-<?php echo $moduleId; ?>'));
    is_same(
        '[CT9] nothing but the migration notes names another module of the shop',
        array_keys(array_filter($sources, static fn (string $source, string $name): bool => $name !== 'MIGRATION-NOTES.md' && preg_match('/mod[_-]alfa[_-](items|categories|manufacturers)/i', $source) === 1, ARRAY_FILTER_USE_BOTH)),
        [],
    );
    is_same('[CT9] no long dash anywhere', array_keys(array_filter($sources, static fn (string $source): bool => str_contains($source, "\u{2014}") || str_contains($source, "\u{2013}"))), []);

    $defined = array_keys(parse_ini_file(CATALOG_MODULE . '/language/en-GB/mod_alfa_catalog.ini', false, INI_SCANNER_RAW) ?: []);
    preg_match_all('/MOD_ALFA_CATALOG_[A-Z0-9_]*[A-Z0-9]/', implode("\n", array_filter($sources, static fn (string $name): bool => !str_starts_with($name, 'language/') && !str_ends_with($name, '.md'), ARRAY_FILTER_USE_KEY)), $used);
    $prefixes = ['MOD_ALFA_CATALOG_CARD', 'MOD_ALFA_CATALOG_SOURCE', 'MOD_ALFA_CATALOG_KIND', 'MOD_ALFA_CATALOG_LOOK_INHERIT', 'MOD_ALFA_CATALOG_LOOK_DEVICE', 'MOD_ALFA_CATALOG_LOOK_GROUP'];
    is_same('[CT9] every language key used is defined', array_values(array_diff(array_unique($used[0]), $defined, $prefixes)), []);
    is_same('[CT9] the keys of a card setting are all defined', array_values(array_filter(
        array_map(static fn (string $key): string => 'MOD_ALFA_CATALOG_CARD_DISPLAY_' . strtoupper($key), CardLook::CHOICES['display']),
        static fn (string $key): bool => !\in_array($key, $defined, true),
    )), []);

    $missing = [];

    foreach (json_decode($read('media/joomla.asset.json'), true)['assets'] as $asset) {
        $file = 'media/' . ($asset['type'] === 'style' ? 'css' : 'js') . '/' . basename($asset['uri']);

        foreach ([$file, $file . '.gz'] as $built) {
            if (!is_file(CATALOG_MODULE . '/' . $built)) {
                $missing[] = $built;
            }
        }
    }

    is_same('[CT9] every registered file and its gzip exist', $missing, []);

    echo ($fail ? "  {$fail} FAILED, " : '  ') . "{$pass}/" . ($pass + $fail) . " passed\n";

    exit($fail ? 1 : 0);
}
