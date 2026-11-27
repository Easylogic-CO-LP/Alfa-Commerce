<?php

/**
 * THE CATALOG MODULE ON AN INSTALL: WHAT THE SHOP'S OWN MODELS ANSWER ITS PROVIDERS.
 *
 * catalog-suite.php proves the module against stand-ins of the shop. This suite asks the real thing: the component's
 * models and services, on the install's own catalogue. It reads only. It creates nothing, changes nothing and
 * consumes no stock; what it finds depends on the shop, so an arm whose subject the shop does not have (no
 * category, no manufacturer, no sale) says so and is skipped, never passed.
 *
 * A count of statements is only judged where it is the same on every run. The component keeps what it learns on
 * first use, so nothing is measured on a first read; each measured read is one whose cost the code fixes (a named
 * statement that must run once) or a comparison of two reads made one after the other.
 *
 *   [CL1] products: a tab's products come back within its number, in one listing read, and the manufacturers filter
 *         keeps only that manufacturer's products.
 *   [CL2] categories: the categories inside the top level through the Categories model, each with a link and never
 *         an image without a description; all of them cost the statements one of them costs; the tree marks a
 *         category, opens its branch and reads one statement per level it opens.
 *   [CL3] manufacturers: within the number; the product counts of all of them are ONE grouped read of the listing,
 *         and each count equals the listing's own total for that manufacturer.
 *   [CL4] automatic tabs: one tab per manufacturer and per label the shop has, the names in one read per type; a
 *         shop without a published label still reads its labels once and the row stays one plain tab.
 *   [CL6] best sellers: the listing model ranks its own result in ONE statement; under a manufacturer every ranked
 *         product is that manufacturer's, and the tab shows as many products as were ranked (the filters come
 *         before the ranking, so none is lost after it).
 *   [CL5] the three presentations draw for the three kinds on the shop's own records, with no empty image
 *         description and no link without an address.
 *
 * The module must be installed (its layouts are read from the site's modules folder).
 *
 *   cd <docroot> && php administrator/components/com_alfa/tests/modules/catalog-live-suite.php
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

PHP_SAPI === 'cli' or exit;

define('_JEXEC', 1);
define('JPATH_BASE', getcwd());

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Alfa\Component\Alfa\Site\Service\Catalog\Labels;
use Alfa\Module\AlfaCatalog\Site\Helper\AlfaCatalogHelper;
use Alfa\Module\AlfaCatalog\Site\Helper\Subjects;
use Alfa\Module\AlfaCatalog\Site\Provider\Providers;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Monitor\DebugMonitor;
use Joomla\Registry\Registry;
use Joomla\Session\SessionInterface;

require_once JPATH_ADMINISTRATOR . '/components/com_alfa/tests/harness/CliSessionRow.php';
CliSessionRow::watch();

$container = Factory::getContainer();
$container->alias('session', 'session.cli')
    ->alias('JSession', 'session.cli')
    ->alias(Session::class, 'session.cli')
    ->alias(SessionInterface::class, 'session.cli');

$app = $container->get(SiteApplication::class);
Factory::$application = $app;
$app->loadLanguage();
$app->createExtensionNamespaceMap();
$app->loadDocument();
$app->getLanguage()->load('mod_alfa_catalog', JPATH_SITE);
$app->getLanguage()->load('com_alfa', JPATH_SITE);

if (!is_file(JPATH_SITE . '/modules/mod_alfa_catalog/mod_alfa_catalog.xml')) {
    fwrite(STDERR, "mod_alfa_catalog is not installed on this site.\n");
    exit(2);
}

$db = $container->get(DatabaseInterface::class);
$pass = 0;
$fail = 0;
$skipped = [];

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

/** The statements a call runs, counted: every one of them, or the ones a pattern matches. */
$reads = static function (callable $call, ?string $pattern = null) use ($db): array {
    $monitor = new DebugMonitor();
    $db->setMonitor($monitor);

    try {
        $result = $call();
    } finally {
        $db->setMonitor(null);
    }

    return [$result, \count(array_filter($monitor->getLogs(), static fn (string $sql): bool => $pattern === null || preg_match($pattern, $sql) === 1))];
};

$tabs = static fn (array ...$rows): Registry => new Registry(['tabs' => $rows, 'owncache' => 0]);
$shown = static fn (string $display, array $row): array => $row + ['card' => json_encode(['base' => ['display' => $display]])];
$module = (object) ['id' => 0, 'title' => 'Catalog'];
$params = $tabs();

// [CL1] Products.
$query = Providers::of('products')->query(['limit' => 6], 'grid');
$products = Providers::of('products')->records($query, $params);

if ($products === []) {
    fwrite(STDERR, "This shop shows no product: the suite has nothing to read.\n");
    exit(2);
}

is_true('[CL1] a tab of 6 holds no more than 6 products', \count($products) <= 6);
is_true('[CL1] every product has a link', array_filter($products, static fn (object $item): bool => (string) ($item->link ?? '') === '') === []);

// [CL2] Categories.
// What a read costs must not depend on what ran before it. The component keeps what it learns on first use (who may
// see which category, the images of a set of categories, its stored trees), so a first read costs statements a
// second does not. Both reads are therefore made once unmeasured, and the measure is a comparison made after them,
// without the counts, whose stored tree is rebuilt whenever stock moves: one category and all of them must cost
// the same statements.
$query = Providers::of('categories')->query(['cat_show_desc' => 1], 'grid');
$categories = Providers::of('categories')->records($query, $params);
Providers::of('categories')->records(['limit' => 1] + $query, $params);

if ($categories === []) {
    $skipped[] = '[CL2] this shop shows no category';
} else {
    [$categories, $readsOfAll] = $reads(static fn (): array => Providers::of('categories')->records($query, $params));
    [$single, $readsOfOne] = $reads(static fn (): array => Providers::of('categories')->records(['limit' => 1] + $query, $params));
    is_same('[CL2] a number of 1 is one category', \count($single), 1);
    is_same('[CL2] ' . \count($categories) . ' categories cost the statements one category costs', $readsOfAll, $readsOfOne);
    is_true('[CL2] every category has a link', array_filter($categories, static fn (object $category): bool => $category->link === '') === []);
    is_true('[CL2] every image has a description', array_filter($categories, static fn (object $category): bool => $category->image !== '' && trim($category->alt) === '') === []);

    $counted = Providers::of('categories')->records(['counts' => true] + $query, $params);
    is_same('[CL2] with counts the same categories', array_column($counted, 'id'), array_column($categories, 'id'));
    is_true('[CL2] every count is a number', array_filter($counted, static fn (object $category): bool => !\is_int($category->count)) === []);

    $current = (int) $categories[0]->id;
    $app->getInput()->set('option', 'com_alfa');
    $app->getInput()->set('view', 'items');
    $app->getInput()->set('category_id', $current);
    // The visitor is in a first-level category: the first level is one statement, the categories inside the
    // visitor's own are a second, and nothing below them is open, so a third level is never read.
    [$tree, $levelReads] = $reads(static fn (): array => Providers::of('categories')->records(Providers::of('categories')->query(['cat_levels' => 3], 'list'), $params), '/\bparent_id\W+IN\b/');
    is_same('[CL2] a tree of three levels with a first-level category open reads two levels, one statement each', $levelReads, 2);
    $marked = array_values(array_filter($tree, static fn (object $node): bool => $node->current));
    is_same('[CL2] the tree marks the visitor\'s category', array_column($marked, 'id'), [$current]);
    is_true('[CL2] and opens it', $marked !== [] && $marked[0]->open);
    is_true('[CL2] no other first-level category is open', \count(array_filter($tree, static fn (object $node): bool => $node->open)) === 1);
    $app->getInput()->set('option', '');
    $app->getInput()->set('category_id', 0);
}

// [CL3] Manufacturers.
$query = Providers::of('manufacturers')->query(['man_show_count' => 1, 'man_limit' => 20], 'grid');
[$manufacturers, $countReads] = $reads(static fn (): array => Providers::of('manufacturers')->records($query, $params), '/_alfa_items_manufacturers\b/');

if ($manufacturers === []) {
    $skipped[] = '[CL3] and [CL4] this shop shows no manufacturer';
} else {
    is_true('[CL3] no more than 20 manufacturers', \count($manufacturers) <= 20);
    is_same('[CL3] the counts of all of them are one read of the listing', $countReads, 1);
    is_true('[CL3] every logo has a description', array_filter($manufacturers, static fn (object $manufacturer): bool => $manufacturer->image !== '' && trim($manufacturer->alt) === '') === []);

    $first = $manufacturers[0];
    $model = $app->bootComponent('com_alfa')->getMVCFactory()->createModel('Items', 'Site', ['ignore_request' => true]);
    $model->getState('list.ordering');
    $model->setState('filter.manufacturer', [(int) $first->id]);
    is_same('[CL3] a count is the listing\'s own total for that manufacturer', $first->count, (int) $model->getTotal());

    $own = Providers::of('products')->records(Providers::of('products')->query(['manufacturers' => [(int) $first->id], 'limit' => 5], 'grid'), $params);
    is_true('[CL1] a manufacturer\'s tab holds only its products', array_filter($own, static fn (object $item): bool => !\in_array((int) $first->id, array_map('intval', explode(',', (string) ($item->manufacturer_ids ?? ''))), true)) === []);

    // [CL4] Automatic tabs per manufacturer.
    Subjects::forget();
    $ids = array_map(static fn (object $manufacturer): int => (int) $manufacturer->id, \array_slice($manufacturers, 0, 3));
    [$definitions, $nameReads] = $reads(static fn (): array => AlfaCatalogHelper::definitions($tabs(['kind' => 'products', 'auto' => 'manufacturer', 'manufacturers' => $ids, 'limit' => 4])), '/_alfa_manufacturers\b/');
    is_same('[CL4] one tab per chosen manufacturer', \count($definitions), \count($ids));
    is_same('[CL4] named after them', array_column($definitions, 'label'), array_map(static fn (object $manufacturer): string => $manufacturer->name, \array_slice($manufacturers, 0, 3)));
    is_same('[CL4] their names in one read', $nameReads, 1);
}

// A row of one tab per label reads the labels once, whether the shop has any or not. Without a published label
// the row names one that does not exist: the read still happens, and the row stays one plain tab.
Labels::forget();
$labels = \array_slice(array_keys(Labels::predefined()), 0, 3);
$chosen = $labels ?: [2147483647];
Labels::forget();
Subjects::forget();
[$definitions, $labelReads] = $reads(static fn (): array => AlfaCatalogHelper::definitions($tabs(['kind' => 'products', 'auto' => 'label', 'labels' => $chosen, 'limit' => 4])), '/_alfa_labels\b/');
is_same('[CL4] the labels are read once', $labelReads, 1);

if ($labels === []) {
    is_same('[CL4] no published label in this shop: the row stays one plain tab', array_column($definitions, 'key'), ['tab-0']);
} else {
    is_same('[CL4] one tab per chosen label', array_map(static fn (array $tab): array => $tab['query']['labels'], $definitions), array_map(static fn (int $id): array => [$id], $labels));
}

// [CL5] The three presentations on the shop's own records.
foreach (['products' => ['kind' => 'products', 'limit' => 4], 'categories' => ['kind' => 'categories', 'cat_limit' => 4], 'manufacturers' => ['kind' => 'manufacturers', 'man_limit' => 4]] as $kind => $row) {
    foreach (['grid', 'slider', 'list'] as $display) {
        $moduleParams = $tabs($shown($display, $row));
        $groups = AlfaCatalogHelper::getGroups($moduleParams, 0);

        if ($groups === []) {
            $skipped[] = "[CL5] {$kind} as a {$display}: nothing to show";

            continue;
        }

        $html = AlfaCatalogHelper::renderGroup($module, $moduleParams, $groups[0]);
        preg_match_all('/<img\b[^>]*>/', $html, $images);

        is_true("[CL5] {$kind} as a {$display} draws", trim($html) !== '');
        is_same("[CL5] {$kind} as a {$display}: no image without a description", array_values(array_filter($images[0], static fn (string $image): bool => !preg_match('/\salt="[^"]+"/', $image))), []);
        is_same("[CL5] {$kind} as a {$display}: no link without an address", preg_match('/<a\b[^>]*href=""/', $html), 0);
    }
}

// [CL6] Best sellers.
$ranker = static function (array $state) use ($app): object {
    $model = $app->bootComponent('com_alfa')->getMVCFactory()->createModel('Items', 'Site', ['ignore_request' => true]);
    $model->getState('list.ordering');

    foreach ($state as $key => $value) {
        $model->setState($key, $value);
    }

    return $model;
};
[$overall, $rankReads] = $reads(static fn (): array => $ranker([])->getBestSellingIds(0, 5), '/_alfa_order_items\b/');

if ($overall === []) {
    $skipped[] = '[CL6] this shop has no sale of a product it shows';
} else {
    is_same('[CL6] the ranking is one statement', $rankReads, 1);
    is_true('[CL6] no more than asked for, each product once', \count($overall) <= 5 && \count(array_unique($overall)) === \count($overall));

    $sellers = array_keys($ranker([])->getManufacturerCounts(array_map(static fn (object $manufacturer): int => (int) $manufacturer->id, $manufacturers)));
    $ranked = [];
    $seller = 0;

    // The first manufacturer with a best seller of its own.
    foreach ($sellers as $candidate) {
        $ranked = $ranker(['filter.manufacturer' => [$candidate]])->getBestSellingIds(0, 5);

        if ($ranked !== []) {
            $seller = $candidate;

            break;
        }
    }

    if ($seller === 0) {
        $skipped[] = '[CL6] no manufacturer among the first 20 has a sale';
    } else {
        $params0 = new Registry(['bestsellers_days' => 0, 'bestsellers_cache' => 0, 'owncache' => 0]);
        $tab = Providers::of('products')->records(Providers::of('products')->query(['source' => 'best_sellers', 'manufacturers' => [$seller], 'limit' => 5], 'grid'), $params0);
        is_same('[CL6] the tab shows the ranked products, in their order, none lost', array_map(static fn (object $item): int => (int) $item->id, $tab), $ranked);
        is_true('[CL6] every one of that manufacturer', array_filter($tab, static fn (object $item): bool => !\in_array($seller, array_map('intval', explode(',', (string) ($item->manufacturer_ids ?? ''))), true)) === []);
    }
}

foreach ($skipped as $line) {
    echo "  SKIPPED  {$line}\n";
}

echo ($fail ? "  {$fail} FAILED, " : '  ') . "{$pass}/" . ($pass + $fail) . " passed" . ($skipped ? ', ' . \count($skipped) . ' skipped' : '') . "\n";

exit($fail ? 1 : 0);
