<?php

/**
 * THE CATALOG MODULE ON AN INSTALL: WHAT THE SHOP'S OWN MODELS ANSWER ITS PROVIDERS.
 *
 * catalog-suite.php proves the module against stand-ins of the shop. This suite asks the real thing: the component's
 * models and services, on the install's own catalogue. It reads only. It creates nothing, changes nothing and
 * consumes no stock; what it finds depends on the shop, so an arm whose subject the shop does not have (no
 * manufacturer, no label a product ticks) says so and is skipped, never passed.
 *
 *   [CL1] products: a tab's products come back within its number, in one listing read, and the manufacturers filter
 *         keeps only that manufacturer's products.
 *   [CL2] categories: the categories inside the top level through the Categories model, each with a link and never
 *         an image without a description; no read per category; the tree marks a category, opens its branch and
 *         costs one statement per level drawn.
 *   [CL3] manufacturers: within the number; the product counts of all of them are ONE grouped read of the listing,
 *         and each count equals the listing's own total for that manufacturer.
 *   [CL4] automatic tabs: one tab per manufacturer and per label the shop has, the names in one read per type.
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

/** The statements a call runs against a table, counted. */
$reads = static function (callable $call, string $table) use ($db): array {
    $monitor = new DebugMonitor();
    $db->setMonitor($monitor);

    try {
        $result = $call();
    } finally {
        $db->setMonitor(null);
    }

    return [$result, \count(array_filter($monitor->getLogs(), static fn (string $sql): bool => str_contains($sql, $table)))];
};

$tabs = static fn (array ...$rows): Registry => new Registry(['tabs' => $rows, 'owncache' => 0]);
$shown = static fn (string $display, array $row): array => $row + ['card' => json_encode(['base' => ['display' => $display]])];
$module = (object) ['id' => 0, 'title' => 'Catalog'];
$params = $tabs();

// [CL1] Products.
$query = Providers::of('products')->query(['limit' => 6], 'grid');
[$products, $listings] = $reads(static fn (): array => Providers::of('products')->records($query, $params), '_alfa_items ');

if ($products === []) {
    fwrite(STDERR, "This shop shows no product: the suite has nothing to read.\n");
    exit(2);
}

is_true('[CL1] a tab of 6 holds no more than 6 products', \count($products) <= 6);
is_true('[CL1] every product has a link', array_filter($products, static fn (object $item): bool => (string) ($item->link ?? '') === '') === []);
echo "  [CL1] the listing ran {$listings} statement(s) on the products table for " . \count($products) . " products\n";

// [CL2] Categories.
$query = Providers::of('categories')->query(['cat_show_count' => 1, 'cat_show_desc' => 1], 'grid');
[$categories, $categoryReads] = $reads(static fn (): array => Providers::of('categories')->records($query, $params), '_alfa_categories');

if ($categories === []) {
    $skipped[] = '[CL2] this shop shows no category';
} else {
    is_true('[CL2] the reads do not grow with the categories', $categoryReads <= 6);
    is_true('[CL2] every category has a link', array_filter($categories, static fn (object $category): bool => $category->link === '') === []);
    is_true('[CL2] every image has a description', array_filter($categories, static fn (object $category): bool => $category->image !== '' && trim($category->alt) === '') === []);
    is_true('[CL2] every count is a number', array_filter($categories, static fn (object $category): bool => !\is_int($category->count)) === []);
    echo "  [CL2] {$categoryReads} statement(s) on the categories tables for " . \count($categories) . " categories with counts\n";

    $current = (int) $categories[0]->id;
    $app->getInput()->set('option', 'com_alfa');
    $app->getInput()->set('view', 'items');
    $app->getInput()->set('category_id', $current);
    [$tree, $levelReads] = $reads(static fn (): array => Providers::of('categories')->records(Providers::of('categories')->query(['cat_levels' => 3], 'list'), $params), 'c.parent_id IN');
    is_true('[CL2] a tree of three levels reads at most three levels, one statement each', $levelReads >= 1 && $levelReads <= 3);
    echo "  [CL2] the tree ran {$levelReads} statement(s) for its levels\n";
    $marked = array_values(array_filter($tree, static fn (object $node): bool => $node->current));
    is_same('[CL2] the tree marks the visitor\'s category', array_column($marked, 'id'), [$current]);
    is_true('[CL2] and opens it', $marked !== [] && $marked[0]->open);
    is_true('[CL2] no other first-level category is open', \count(array_filter($tree, static fn (object $node): bool => $node->open)) === 1);
    $app->getInput()->set('option', '');
    $app->getInput()->set('category_id', 0);
}

// [CL3] Manufacturers.
$query = Providers::of('manufacturers')->query(['man_show_count' => 1, 'man_limit' => 20], 'grid');
[$manufacturers, $countReads] = $reads(static fn (): array => Providers::of('manufacturers')->records($query, $params), '_alfa_items_manufacturers');

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
    [$definitions, $nameReads] = $reads(static fn (): array => AlfaCatalogHelper::definitions($tabs(['kind' => 'products', 'auto' => 'manufacturer', 'manufacturers' => $ids, 'limit' => 4])), '_alfa_manufacturers ');
    is_same('[CL4] one tab per chosen manufacturer', \count($definitions), \count($ids));
    is_same('[CL4] named after them', array_column($definitions, 'label'), array_map(static fn (object $manufacturer): string => $manufacturer->name, \array_slice($manufacturers, 0, 3)));
    is_same('[CL4] their names in one read', $nameReads, 1);
}

$labels = \array_slice(array_keys(Labels::predefined()), 0, 3);

if ($labels === []) {
    $skipped[] = '[CL4] this shop has no published label';
} else {
    Subjects::forget();
    $definitions = AlfaCatalogHelper::definitions($tabs(['kind' => 'products', 'auto' => 'label', 'labels' => $labels, 'limit' => 4]));
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

foreach ($skipped as $line) {
    echo "  SKIPPED  {$line}\n";
}

echo ($fail ? "  {$fail} FAILED, " : '  ') . "{$pass}/" . ($pass + $fail) . " passed" . ($skipped ? ', ' . \count($skipped) . ' skipped' : '') . "\n";

exit($fail ? 1 : 0);
