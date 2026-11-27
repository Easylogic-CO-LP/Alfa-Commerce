<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The cost of the price index writer on this install: PriceIndexSyncService::syncItems() timed for 1, 50, 200 and
 * 1000 published products of the shop, each run inside one transaction that is rolled back, so no stored price, no
 * stock figure and no index row is changed. Run from the site docroot:
 *
 *   cd <docroot> && php administrator/components/com_alfa/tests/pricing/price-sync-benchmark.php
 *
 * What it reports for each run: the products asked and handled, their sellable units (the product and each published
 * combination with price rows of its own), the live pricing contexts, the index rows the writer wrote (one per unit,
 * context and period), the milliseconds in all and per product, per unit and per row, and whether anything was left to
 * the background work (a rebuild lock another writer held, a chunk that failed).
 *
 * The runs take the chunk path of the writer (chunks of 50 under the items' locks), never the generation path, which a
 * caller's open transaction forbids; the loader's cache is emptied before each run, so every run loads its own data.
 *
 *   [B1]  the index, the contexts and the rebuild marks hold exactly the rows they held, read on a second connection.
 */

PHP_SAPI === 'cli' or exit;
define('_JEXEC', 1);
// The docroot from this file's place: administrator/components/com_alfa/tests/pricing, five levels down.
define('JPATH_BASE', \dirname(__DIR__, 5));
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceDataLoader;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseFactory;
use Joomla\Session\SessionInterface;

require_once JPATH_ADMINISTRATOR . '/components/com_alfa/tests/harness/CliSessionRow.php';
require_once JPATH_ADMINISTRATOR . '/components/com_alfa/tests/harness/MailOff.php';
CliSessionRow::watch();

$container = Factory::getContainer();
$container->alias('session', 'session.cli')
    ->alias('JSession', 'session.cli')
    ->alias(Session::class, 'session.cli')
    ->alias(SessionInterface::class, 'session.cli');

$app = $container->get(\Joomla\CMS\Application\AdministratorApplication::class);
Factory::$application = $app;
$app->loadLanguage();
$english = Language::getInstance('en-GB');
Factory::$language = $english;
$app->loadLanguage($english);
$english->load('com_alfa', JPATH_ADMINISTRATOR);
$app->createExtensionNamespaceMap();
MailOff::engage();

\defined('JPATH_COMPONENT') || \define('JPATH_COMPONENT', JPATH_ADMINISTRATOR . '/components/com_alfa');
\defined('JPATH_COMPONENT_ADMINISTRATOR') || \define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_ADMINISTRATOR . '/components/com_alfa');
\defined('JPATH_COMPONENT_SITE') || \define('JPATH_COMPONENT_SITE', JPATH_SITE . '/components/com_alfa');

$db = $container->get('DatabaseDriver');
$p = $db->getPrefix();
$conf = $app->getConfig();
$oracle = (new DatabaseFactory())->getDriver($conf->get('dbtype'), [
    'host' => $conf->get('host'), 'user' => $conf->get('user'), 'password' => $conf->get('password'),
    'database' => $conf->get('db'), 'prefix' => $p,
]);

$pass = 0;
$fail = 0;

function check(string $what, $got, $want): void
{
    global $pass, $fail;

    if ($got === $want) {
        $pass++;
        echo "  ok   {$what}\n";

        return;
    }

    $fail++;
    echo "  FAIL {$what}\n    got  ", json_encode($got, JSON_UNESCAPED_UNICODE), "\n    want ", json_encode($want, JSON_UNESCAPED_UNICODE), "\n";
}

echo "The price index writer's cost on this install\n", str_repeat('=', 70), "\n";

/** The rows that must not move: the index, the contexts and the rebuild marks, on the second connection. */
$census = static fn (): array => [
    'index rows' => (int) $oracle->setQuery("SELECT COUNT(*) FROM `{$p}alfa_items_price_index`")->loadResult(),
    'contexts' => (int) $oracle->setQuery("SELECT COUNT(*) FROM `{$p}alfa_price_contexts`")->loadResult(),
    'marked items' => (int) $oracle->setQuery("SELECT COUNT(*) FROM `{$p}alfa_items` WHERE projection_dirty_at IS NOT NULL")->loadResult(),
];
$before = $census();

/** The sellable units of some products, as the writer counts them (PriceIndexSyncService::unitsSql()). */
$unitsOf = static function (array $itemIds) use ($db, $p): int {
    $in = implode(',', array_map('intval', $itemIds));

    return (int) $db->setQuery(
        "SELECT COUNT(*) FROM (SELECT a.id AS item_id, 0 AS combination_id FROM `{$p}alfa_items` a WHERE a.state = 1 AND a.id IN ({$in})"
        . " UNION ALL SELECT oc.item_id, oc.id FROM `{$p}alfa_item_combinations` oc INNER JOIN `{$p}alfa_items` a ON a.id = oc.item_id AND a.state = 1"
        . " WHERE oc.state = 1 AND oc.item_id IN ({$in}) AND EXISTS (SELECT 1 FROM `{$p}alfa_items_prices` own WHERE own.combination_id = oc.id AND own.item_id = oc.item_id AND own.state = 1)) u",
    )->loadResult();
};

$published = (int) $db->setQuery("SELECT COUNT(*) FROM `{$p}alfa_items` WHERE state = 1")->loadResult();
$contexts = (int) $db->setQuery("SELECT COUNT(*) FROM `{$p}alfa_price_contexts` WHERE is_live = 1")->loadResult();
$waiting = (int) $db->setQuery("SELECT COUNT(*) FROM `{$p}alfa_price_contexts` WHERE is_live IS NULL AND retired_at IS NULL")->loadResult();
$allUnits = (int) $db->setQuery(
    "SELECT COUNT(*) FROM (SELECT a.id FROM `{$p}alfa_items` a WHERE a.state = 1"
    . " UNION ALL SELECT oc.id FROM `{$p}alfa_item_combinations` oc INNER JOIN `{$p}alfa_items` a ON a.id = oc.item_id AND a.state = 1"
    . " WHERE oc.state = 1 AND EXISTS (SELECT 1 FROM `{$p}alfa_items_prices` own WHERE own.combination_id = oc.id AND own.item_id = oc.item_id AND own.state = 1)) u",
)->loadResult();

printf("the shop: %d published products, %d sellable units, %d live pricing contexts (%d waiting), %d index rows\n\n", $published, $allUnits, $contexts, $waiting, $before['index rows']);
printf("  %6s  %8s  %6s  %9s  %10s  %10s  %9s  %9s  %s\n", 'asked', 'handled', 'units', 'rows', 'ms', 'ms/product', 'ms/unit', 'ms/row', 'left to background');

foreach ([1, 50, 200, 1000] as $asked) {
    $ids = array_map('intval', $db->setQuery("SELECT id FROM `{$p}alfa_items` WHERE state = 1 ORDER BY id", 0, $asked)->loadColumn() ?: []);

    if ($ids === []) {
        echo "  the shop has no published product; nothing to time\n";
        break;
    }

    $units = $unitsOf($ids);
    PriceDataLoader::forgetLoaded();
    $service = new PriceIndexSyncService();
    $rows = 0;
    $error = null;
    $db->transactionStart();

    try {
        $started = hrtime(true);
        $rows = $service->syncItems($ids);
        $ms = (hrtime(true) - $started) / 1e6;
        $outcome = $service->outcome();
    } catch (\Throwable $e) {
        $ms = 0.0;
        $error = \get_class($e) . ': ' . $e->getMessage();
        $outcome = null;
    } finally {
        // Closed through TransactionState: the announcement the writer deferred to the commit goes with the transaction.
        TransactionState::rollBackLevel(db: $db);
    }

    if ($error !== null) {
        printf("  %6d  the writer failed: %s\n", $asked, $error);
        continue;
    }

    printf(
        "  %6d  %8d  %6d  %9d  %10.1f  %10.2f  %9.2f  %9.3f  %s\n",
        $asked,
        $outcome->products,
        $units,
        $rows,
        $ms,
        $outcome->products > 0 ? $ms / $outcome->products : 0,
        $units > 0 ? $ms / $units : 0,
        $rows > 0 ? $ms / $rows : 0,
        $outcome->pending ? 'yes' : 'no',
    );

    if (\count($ids) < $asked) {
        printf("  %6s  (the shop has %d published products; the run took them all)\n", '', \count($ids));
        break;
    }
}

echo "\n";
$after = $census();
$moved = [];

foreach ($before as $what => $count) {
    if (($after[$what] ?? null) !== $count) {
        $moved[$what] = [$count, $after[$what] ?? null];
    }
}

check('[B1] the index, the contexts and the rebuild marks hold exactly the rows they held (read on a second connection)', $moved, []);

echo "\nprice sync benchmark: {$pass} passed, {$fail} failed\n";

exit($fail === 0 ? 0 : 1);
