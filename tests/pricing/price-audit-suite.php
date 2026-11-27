<?php

/**
 * THE PRICE CHECK FINDS WHAT THE CATALOGUE'S PRICES SAY WRONG, AND THE SCREENS AROUND IT SAY THE SAME.
 *
 * The price check (view=priceaudit, PriceAudit) reads the price index, the price rows and the rules' scope links and
 * lists, per kind, the sales above the price they replace or taking more than half of it, the sales that give less
 * than the rules they replace, the sales without an end date, the discounts and taxes published outside their period,
 * the products no tax reaches, the products two rules stack on above half, the price rows whose period sits on the
 * wrong row, and the products without a price. The same report feeds the Dashboard's Prices card (one sentence of
 * counts) and the daily job's bell notice. Beside it, the discounts list says where each rule's period stands, the
 * discount form's Reach card counts what the rule takes in and what it stacks with, and the product's price row says
 * which rules apply or that its sale replaces them.
 *
 * Every arm runs on disposable fixtures of this run's own, written through the real models (products as the item
 * screen saves them, discounts and taxes through their models, one sale row set above its price by SQL, as a row saved
 * before the save refused it) inside one transaction that is rolled back. The report is read inside that transaction
 * by the check's job (PriceAuditJob::chunk(), run chunk by chunk in this process from a checkpoint, two rows a chunk, so
 * every piece of the check crosses a chunk) and read back from the store (PriceAuditStore), as the page, the tile and
 * the bell read it; the SQL and the engine runs are the job's.
 *
 *   [PE1] ★ RulePeriod::status() judges a period as the engine does: an end equal to the clock is still active, a
 *         start equal to the clock has begun, empty and null dates are open ends.
 *   [PE2] the discounts list filter (filter.period) narrows to the expired and the not started rules through the
 *         engine's own conditions; Joomla's status icon of a published rule with such dates is expired or pending.
 *   [RE1] ★ the Reach card counts the rule's products (its link opening the Products list by the rule filter, whose set
 *         is the reach's own), the products its categories leave out, and the rules it stacks
 *         with; a rule set to only this stacks with none.
 *   [PA1] ★ a sale above the price it replaces is a finding naming its row; a sale below half is one; a sale without
 *         an end date is one by option.
 *   [PA2] ★ a discount published after its end and a tax published before its start are findings with their status.
 *   [PA3] ★ a period row without a sale beside a sale row without a period, in the same scope, is a finding naming both.
 *   [PA4] ★ a sale that gives less than the rules it replaces is a finding, priced by the engine under the row's own
 *         dimensions; the engine's runs stay within the cap.
 *   [PA5] (needs a prepared price index context) a published product with no price row is a finding; a product two
 *         rules reach whose index price is more than half off is a finding naming both rules.
 *   [PA6] counts() names each section with findings and sentence() words them; the option switches take a section out.
 *   [PA7] the notice follows the stored result: its facts are the kinds found, and a result with nothing withdraws them.
 *   [ST1] ★ the store's state: never run before a result is stored, complete once the job's last chunk stored it, out
 *         of date once a price or rule change is marked after the check's moment, running while a job waits.
 *   [ST2] ★ "Check again" twice queues one job: the second ask joins the first.
 *   [ST3] ★ the stored rows page: a page of one row, the next page, the total unchanged.
 *   [ST4] ★ every sale row is judged across chunks: the engine ran once for each of the shop's sale rows that need it,
 *         with more rows than one chunk holds.
 *   [ST5] ★ a change between two chunks of a run leaves the result it stores out of date: the result rests on the data
 *         as of the run's start.
 *   [PR1] the product's price row says the sale replaces the rules it names, or which rules apply now; prices.js keeps
 *         that sentence live.
 *   [T1]  nothing of the run outlived the rollback, by the ids it knows: a row of its products, rules or tax found on
 *         a second connection is a failure, removed and named; and every table holds exactly the rows it held, a
 *         table that moved with none of the run's rows named with its newest rows (another writer's, on a shared shop).
 *
 * Fixtures: products, discounts and taxes of this run's own, in two published categories and one published
 * manufacturer of the shop (read, not written). The run needs those three to exist; it says so and stops otherwise.
 *
 *   cd <docroot> && php administrator/components/com_alfa/tests/pricing/price-audit-suite.php
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

use Alfa\Component\Alfa\Administrator\Field\AlfapricesField;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Pricing\DiscountReach;
use Alfa\Component\Alfa\Administrator\Service\Pricing\PriceAudit;
use Alfa\Component\Alfa\Administrator\Service\Pricing\PriceAuditNotice;
use Alfa\Component\Alfa\Administrator\Service\Pricing\PriceAuditStore;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\PriceAuditJob;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use Alfa\Component\Alfa\Administrator\Service\Pricing\RulePeriod;
use Alfa\Component\Alfa\Administrator\Service\Pricing\RuleReach;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Alfa\Component\Alfa\Administrator\Service\Setup\ConditionNotice;
use Alfa\Component\Alfa\Site\Service\Pricing\ActiveDateCondition;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceComputationEngine;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceDataLoader;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseFactory;
use Joomla\Session\SessionInterface;

require_once JPATH_ADMINISTRATOR . '/components/com_alfa/tests/harness/CliSessionRow.php';
require_once JPATH_ADMINISTRATOR . '/components/com_alfa/tests/harness/MailOff.php';
require_once JPATH_ADMINISTRATOR . '/components/com_alfa/tests/harness/SourceFile.php';
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

// The price editor is rendered the way the item screen renders it; a suite runs from the site docroot, so an
// installed template is pinned.
$app->loadDocument();
$installedTemplates = array_values(array_filter(glob(JPATH_THEMES . '/*') ?: [], static fn (string $dir): bool => is_file($dir . '/index.php')));

if ($installedTemplates !== []) {
    (new \ReflectionProperty(\Joomla\CMS\Application\CMSApplication::class, 'template'))
        ->setValue($app, (object) ['template' => basename($installedTemplates[0]), 'params' => new \Joomla\Registry\Registry(), 'parent' => '']);
    $app->set('themeInherits', '');
}

$app->getDocument()->getWebAssetManager()->getRegistry()->addExtensionRegistryFile('com_alfa');

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

echo "The price check finds what the catalogue's prices say wrong\n", str_repeat('=', 70), "\n";

/** Every table's row count, on the second connection. */
$like = str_replace('_', '\\_', $p);
$census = static function () use ($oracle, $like): array {
    $out = [];

    foreach ($oracle->setQuery("SHOW TABLES LIKE '" . $like . "%'")->loadColumn() as $table) {
        if (str_contains($table, 'session')) {
            continue;
        }

        $out[$table] = (int) $oracle->setQuery("SELECT COUNT(*) FROM `{$table}`")->loadResult();
    }

    return $out;
};

$before = $census();

// The operator: a super user in Europe/Athens.
$super = (int) $db->setQuery(
    "SELECT m.user_id FROM `{$p}user_usergroup_map` m INNER JOIN `{$p}users` u ON u.id = m.user_id WHERE m.group_id = 8 AND u.block = 0 ORDER BY m.user_id",
    0,
    1,
)->loadResult();
$operator = new User($super);
$operator->setParam('timezone', 'Europe/Athens');
$app->loadIdentity($operator);

// [PE1] the period judgement, on fixed moments.
echo "\nthe period against the clock\n";
$clock = '2026-12-15 12:00:00';
check('[PE1] ★ a period that holds the clock is active', RulePeriod::status('2026-12-01 00:00:00', '2026-12-31 00:00:00', $clock), RulePeriod::ACTIVE);
check('[PE1] ★ an end before the clock is expired', RulePeriod::status(null, '2026-12-15 11:59:59', $clock), RulePeriod::EXPIRED);
check('[PE1] ★ an end equal to the clock is still active, as the engine reads it', RulePeriod::status(null, $clock, $clock), RulePeriod::ACTIVE);
check('[PE1] ★ a start after the clock is not started yet', RulePeriod::status('2026-12-15 12:00:01', null, $clock), RulePeriod::UPCOMING);
check('[PE1] ★ a start equal to the clock has begun', RulePeriod::status($clock, null, $clock), RulePeriod::ACTIVE);
check('[PE1] empty and null dates are open ends', [RulePeriod::status('', '0000-00-00 00:00:00', $clock), RulePeriod::status(null, null, $clock)], [RulePeriod::ACTIVE, RulePeriod::ACTIVE]);
check('[PE1] an unknown filter value narrows nothing', RulePeriod::sql('whatever', 'a.publish_up', 'a.publish_down', $clock), '1 = 1');

// The shop's own category pair and manufacturer the fixtures live in.
$categories = array_map('intval', $db->setQuery("SELECT c.id FROM `{$p}alfa_categories` c WHERE c.state = 1 ORDER BY c.id", 0, 2)->loadColumn());
$manufacturer = (int) $db->setQuery("SELECT m.id FROM `{$p}alfa_manufacturers` m WHERE m.state = 1 ORDER BY m.id", 0, 1)->loadResult();

if (\count($categories) < 2 || $manufacturer <= 0) {
    echo "\nThe shop needs two published categories and one published manufacturer for the fixtures; it has " . \count($categories) . " and " . ($manufacturer > 0 ? 1 : 0) . ".\n";
    echo "\nprice audit: {$pass} passed, " . ($fail + 1) . " failed\n";
    exit(1);
}

[$catA, $catB] = $categories;

// The content languages the names are typed in, as the multilingual fields post them (name_en_gb).
$tags = $db->setQuery("SELECT lang_code FROM `{$p}languages` WHERE published = 1")->loadColumn() ?: [MultilingualHelper::getDefaultLanguageTag()];
$named = static function (string $base, string $alias) use ($tags): array {
    $data = [];

    foreach ($tags as $tag) {
        $key = MultilingualHelper::normaliseTag(tag: (string) $tag);
        $data['name_' . $key] = $base;
        $data['alias_' . $key] = $alias . '-' . $key;
    }

    return $data;
};

// A rule has a name in each language and no alias: the language schema follows the keys posted, so an alias key would
// ask for a column the rule's tables do not have.
$ruleNamed = static function (string $base) use ($tags): array {
    $data = [];

    foreach ($tags as $tag) {
        $data['name_' . MultilingualHelper::normaliseTag(tag: (string) $tag)] = $base;
    }

    return $data;
};

$factory = $app->bootComponent('com_alfa')->getMVCFactory();
$itemModel = $factory->createModel('Item', 'Administrator', ['ignore_request' => true]);
$itemController = $factory->createController('Item', 'Administrator', [], $app, $app->getInput());
$hook = new \ReflectionMethod($itemController, 'preSaveHook');
$run = strtolower(substr(bin2hex(random_bytes(4)), 0, 8));
$now = ActiveDateCondition::now();
$days = static fn (int $n): string => gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') + ($n * 86400));

/** The item screen's save: the posted data validated, passed through the controller's hook and saved. */
$saveItem = static function (array $data) use ($app, $itemModel, $itemController, $hook): int {
    $app->getInput()->post->set('jform', $data);
    $form = $itemModel->getForm($data, false);
    $valid = $form ? $itemModel->validate($form, $data) : false;

    if ($valid === false) {
        throw new \RuntimeException('item validate refused: ' . implode(' ', array_map('strval', $itemModel->getErrors())));
    }

    $valid = $hook->invoke($itemController, $itemModel, $valid);

    if (!$itemModel->save($valid)) {
        throw new \RuntimeException('item save refused: ' . $itemModel->getError());
    }

    return (int) $itemModel->getState('item.id');
};

// The ids of the run's rows, by the column that references them: what the sweep after the rollback looks for.
$runIds = ['item_id' => [], 'discount_id' => [], 'tax_id' => []];

/** A published product of this run in a category, with or without a manufacturer, with the price rows given. */
$product = static function (string $tag, int $category, ?int $manufacturer, array $rows) use ($saveItem, $named, $run, &$runIds): int {
    $data = [
        'id' => 0, 'state' => 1, 'sku' => 'PA-' . strtoupper($run) . '-' . $tag,
        'id_category_default' => $category, 'categories' => [$category],
        'manufacturers' => $manufacturer === null ? [] : [$manufacturer],
        'prices' => $rows,
    ] + $named('PA disposable ' . $tag . ' ' . $run, 'pa-disposable-' . $tag . '-' . $run);
    $id = $saveItem($data);
    $runIds['item_id'][] = $id;

    return $id;
};

/** A price row as the item screen posts it. */
$row = static fn (string $value, string $sale = '', string $pins = 'start', string $up = '', string $down = ''): array => [
    'id' => 0, 'value' => $value, 'override_value' => $sale, 'override_type' => $pins, 'price_status' => '1',
    'country_id' => 0, 'usergroup_id' => 0, 'user_id' => 0, 'currency_id' => 0, 'quantity_start' => '', 'quantity_end' => '',
    'publish_up' => $up, 'publish_down' => $down,
];

/** A discount or a tax saved through its model, as its form posts it. */
$saveRule = static function (string $kind, array $data) use ($app, $factory, $ruleNamed, $run, &$runIds): int {
    $data += ['id' => 0, 'state' => 1, 'places' => [0], 'usergroups' => [0], 'users' => [0]] + $ruleNamed('PA disposable ' . $kind . ' ' . ($data['tag'] ?? '') . ' ' . $run);
    unset($data['tag']);
    $app->getInput()->post->set('jform', $data);
    $model = $factory->createModel(ucfirst($kind), 'Administrator', ['ignore_request' => true]);

    if (!$model->save($data)) {
        throw new \RuntimeException($kind . ' save refused: ' . $model->getError());
    }

    $id = (int) $model->getState($kind . '.id');
    $runIds[$kind . '_id'][] = $id;

    return $id;
};

/** The rows of a section whose product is the one given. */
$ofItem = static fn (array $section, int $itemId): array => array_values(array_filter($section['rows'], static fn (array $r): bool => $r['item'] !== null && (int) $r['item']['id'] === $itemId));

/** The rows of a section whose rule is the one given. */
$ofRule = static fn (array $section, string $kind, int $id): array => array_values(array_filter($section['rows'], static fn (array $r): bool => $r['rule'] !== null && $r['rule']['kind'] === $kind && (int) $r['rule']['id'] === $id));

$result = ['error' => 'not run'];
$db->transactionStart();

try {
    // The rules: D1 40% on category A for the manufacturer, combining; D2 30% on category A, compounding; D3 only this,
    // on category A, not started yet; D4 10% on category B, ended. T1 24% on category B, not started yet.
    $d1 = $saveRule('discount', ['tag' => 'D1', 'value' => '40', 'is_amount' => 0, 'behavior' => 1, 'apply_before_tax' => 1, 'categories' => [$catA], 'manufacturers' => [$manufacturer]]);
    $d2 = $saveRule('discount', ['tag' => 'D2', 'value' => '30', 'is_amount' => 0, 'behavior' => 2, 'apply_before_tax' => 1, 'categories' => [$catA], 'manufacturers' => [0]]);
    $d3 = $saveRule('discount', ['tag' => 'D3', 'value' => '5', 'is_amount' => 0, 'behavior' => 0, 'apply_before_tax' => 1, 'categories' => [$catA], 'manufacturers' => [0], 'publish_up' => $days(5), 'publish_down' => $days(10)]);
    $d4 = $saveRule('discount', ['tag' => 'D4', 'value' => '10', 'is_amount' => 0, 'behavior' => 1, 'apply_before_tax' => 1, 'categories' => [$catB], 'manufacturers' => [0], 'publish_up' => $days(-10), 'publish_down' => $days(-1)]);
    $t1 = $saveRule('tax', ['tag' => 'T1', 'value' => '24', 'behavior' => 1, 'categories' => [$catB], 'manufacturers' => [0], 'publish_up' => $days(10)]);

    // The products, in category B so that D1 and D2 leave them alone unless said otherwise.
    $pAbove = $product('above', $catB, null, [$row('60.00000000', '50.00000000')]);
    $pLarge = $product('large', $catB, null, [$row('100.00000000', '10.00000000')]);
    $pInverted = $product('inverted', $catB, null, [$row('100.00000000', '', 'start', $days(-1), $days(30)), $row('100.00000000', '50.00000000')]);
    $pBelow = $product('below', $catA, $manufacturer, [$row('100.00000000', '70.00000000')]);
    $pStacked = $product('stacked', $catA, $manufacturer, [$row('100.00000000')]);
    $pNoPrice = $product('noprice', $catB, null, []);
    $pOutside = $product('outside', $catB, $manufacturer, [$row('10.00000000')]);

    // The sale above its price: a row saved before the save refused it, set by SQL.
    $aboveRow = (int) $db->setQuery("SELECT id FROM `{$p}alfa_items_prices` WHERE item_id = {$pAbove} AND combination_id IS NULL ORDER BY id", 0, 1)->loadResult();
    $db->setQuery("UPDATE `{$p}alfa_items_prices` SET override_value = '80.00000000' WHERE id = {$aboveRow}")->execute();

    // The index rows of the fixtures, written now: a save leaves the product's rebuild to the background work, and the
    // sections that read the index would not see the fixtures otherwise.
    PriceDataLoader::forgetLoaded();
    (new PriceIndexSyncService())->syncItems([$pAbove, $pLarge, $pInverted, $pBelow, $pStacked, $pNoPrice, $pOutside]);

    echo "fixture ids: discounts={$d1},{$d2},{$d3},{$d4} tax={$t1} items={$pAbove},{$pLarge},{$pInverted},{$pBelow},{$pStacked},{$pNoPrice},{$pOutside} (inside the rolled-back transaction)\n";

    // [PE2] the list filter and the status icon.
    $listed = static function (string $period) use ($factory): array {
        $model = $factory->createModel('Discounts', 'Administrator', ['ignore_request' => true]);
        $model->setState('filter.period', $period);
        $model->setState('filter.state', '');
        $model->setState('list.ordering', 'a.id');
        $model->setState('list.direction', 'ASC');
        $model->setState('list.limit', 0);

        return array_map('intval', array_column($model->getItems(), 'id'));
    };
    $expired = $listed(RulePeriod::EXPIRED);
    $upcoming = $listed(RulePeriod::UPCOMING);
    $active = $listed(RulePeriod::ACTIVE);
    $periodFilter = [
        'expired' => [\in_array($d4, $expired, true), \in_array($d1, $expired, true), \in_array($d3, $expired, true)],
        'upcoming' => [\in_array($d3, $upcoming, true), \in_array($d1, $upcoming, true), \in_array($d4, $upcoming, true)],
        'active' => [\in_array($d1, $active, true), \in_array($d2, $active, true), \in_array($d3, $active, true), \in_array($d4, $active, true)],
    ];
    $icons = [
        'expired' => str_contains((string) HTMLHelper::_('jgrid.published', 1, 0, 'discounts.', true, 'cb', $days(-10), $days(-1)), 'expired'),
        'pending' => str_contains((string) HTMLHelper::_('jgrid.published', 1, 0, 'discounts.', true, 'cb', $days(5), $days(10)), 'pending'),
        'plain' => !str_contains((string) HTMLHelper::_('jgrid.published', 1, 0, 'discounts.', true, 'cb', $days(-5), $days(10)), 'expired'),
    ];

    // [RE1] the Reach card.
    $reach = new DiscountReach(db: $db);
    $reach1 = $reach->of($d1);
    $reach3 = $reach->of($d3);
    $stacksWith = static fn (array $reach, int $id): ?int => array_values(array_filter($reach['stacks'], static fn (array $s): bool => $s['id'] === $id))[0]['count'] ?? null;

    // The product list the card's link opens (the Products list's rule filter), against the reach's own set.
    $itemsModel = $factory->createModel('Items', 'Administrator', ['ignore_request' => true]);
    $itemsModel->setState('filter.discount', $d1);
    $itemsModel->setState('filter.state', 1);
    $itemsModel->setState('list.ordering', 'a.id');
    $itemsModel->setState('list.direction', 'ASC');
    $itemsModel->setState('list.limit', 0);
    $listedByFilter = array_map(static fn (object $row): int => (int) $row->id, $itemsModel->getItems() ?: []);
    sort($listedByFilter);
    $reachSet = array_map('intval', $db->setQuery(
        "SELECT a.id FROM `{$p}alfa_items` a WHERE a.state = 1 AND " . RuleReach::productSql(kind: RuleReach::DISCOUNT, ruleId: (string) $d1, itemId: 'a.id') . ' ORDER BY a.id',
    )->loadColumn() ?: []);

    // The check, by its job's chunks in this transaction, two rows a chunk, read back from the store as the page reads it.
    PriceDataLoader::forgetLoaded();
    $store = new PriceAuditStore(db: $db);
    $db->setQuery("DELETE FROM `{$p}alfa_meta` WHERE name LIKE 'price\\_audit.%'")->execute();
    $stateBefore = $store->status()['state'];
    $runJob = static function (int $units, ?callable $betweenChunks = null): array {
        $job = new PriceAuditJob();
        $run = new JobRun(id: 0, handler: PriceAuditJob::NAME, scopeKey: '', payload: [], checkpoint: null, doneUnits: 0, totalUnits: 0, trigger: 'suite');
        $chunks = 0;

        do {
            $chunk = $job->chunk($run, $units);
            $chunks++;
            $run = $run->at(checkpoint: $chunk->checkpoint, doneUnits: $run->doneUnits + $chunk->units, totalUnits: $run->totalUnits);

            if ($chunks === 1 && $betweenChunks !== null) {
                $betweenChunks();
            }
        } while (!$chunk->finished && $chunks < 100000);

        return ['chunks' => $chunks, 'result' => json_decode((string) $chunk->result, true)];
    };
    $saleRows = PriceAudit::begin(db: $db)->saleCount();
    $ran = $runJob(2);
    $summary = $store->summary();
    $stateAfter = $store->status()['state'];
    $sections = [];

    foreach (PriceAudit::SECTIONS as $key) {
        $sections[$key] = ['rows' => $store->rows(section: $key)['rows'], 'count' => (int) ($summary['sections'][$key]['count'] ?? 0), 'available' => (bool) ($summary['sections'][$key]['available'] ?? false)];
    }

    $context = $summary['context']['id'];
    $report = $summary;

    // [ST3] a page of one row, then the next, of the section that holds the rules out of their period.
    $pageOne = $store->rows(section: PriceAudit::SECTION_RULES_PERIOD, offset: 0, limit: 1);
    $pageTwo = $store->rows(section: PriceAudit::SECTION_RULES_PERIOD, offset: 1, limit: 1);

    // [ST1] out of date once a change is marked after the check's moment; running while a job waits.
    PriceAuditStore::markStale(db: $db);
    $stateStale = $store->status()['state'];
    $firstAsk = PriceAuditJob::enqueue();
    $secondAsk = PriceAuditJob::enqueue();
    $stateRunning = $store->status();

    // [ST5] a run that overlaps a change: the price rows change between its first and second chunk, and the result it
    // stores at its end is out of date from the moment it is stored. The job rows of the asks above are removed first,
    // so the state read is the result's, not a waiting job's.
    $db->setQuery("DELETE FROM `{$p}alfa_tasks` WHERE id IN (" . (int) $firstAsk->id . ', ' . (int) $secondAsk->id . ')')->execute();
    $ranOver = $runJob(2, static function () use ($db): void {
        // The clock has one second: the change is marked one second after the run began, so a change later than the
        // start is what is judged, not a tie.
        sleep(1);
        PriceAuditStore::markStale(db: $db);
    });
    $stateOverlap = $store->status();

    // [PR1] the row's sentence, rendered as the item screen renders it.
    $sentenceOf = static function (int $itemId) use ($db, $p): array {
        $rows = $db->setQuery("SELECT * FROM `{$p}alfa_items_prices` WHERE item_id = {$itemId} AND combination_id IS NULL ORDER BY id")->loadAssocList();
        $html = AlfapricesField::renderPrices(controlBase: 'jform[prices]', rows: $rows, showModify: false, itemId: $itemId);
        $rowsHtml = (string) strstr($html, '<template data-price-template>', true);

        if (!preg_match('~<div class="price-cell" data-price-rules-note data-rule-names="([^"]*)"\s+data-rules-excluded="[^"]*"([^>]*)>\s*<span class="override-hint[^"]*" data-price-rules-text>([^<]*)</span>~', $rowsHtml, $m)) {
            return ['names' => null, 'hidden' => null, 'text' => null];
        }

        return ['names' => html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), 'hidden' => str_contains($m[2], 'hidden'), 'text' => html_entity_decode($m[3], ENT_QUOTES, 'UTF-8')];
    };
    $belowSentence = $sentenceOf($pBelow);
    $stackedSentence = $sentenceOf($pStacked);

    // [PA7] the notice followed the stored result in the job's last chunk; a result with nothing withdraws it.
    $toldWith = ConditionNotice::told(key: PriceAuditNotice::KEY);
    $followed = array_keys(PriceAudit::counts(summary: $summary));
    PriceAuditNotice::follow(status: ['state' => PriceAuditStore::STATE_COMPLETE, 'summary' => ['sections' => [], 'index' => ['complete' => true]], 'failure' => '']);
    $toldQuiet = ConditionNotice::told(key: PriceAuditNotice::KEY);

    $result = [
        'periodFilter' => $periodFilter,
        'icons' => $icons,
        'reach1' => $reach1,
        'reach3' => $reach3,
        'stacks12' => $stacksWith($reach1, $d2),
        'stacks13' => $stacksWith($reach1, $d3),
        'reachLink' => [str_contains($reach1['url'], 'filter%5Bdiscount%5D=' . $d1), str_contains($reach1['url'], 'filter%5Bstate%5D=1')],
        'reachList' => ['same' => $listedByFilter === $reachSet, 'counted' => \count($reachSet) === (int) $reach1['reaches'], 'holds' => \in_array($pBelow, $listedByFilter, true) && \in_array($pStacked, $listedByFilter, true)],
        'context' => $context,
        'engine' => $report['engine'],
        'saleRows' => $saleRows,
        'chunks' => $ran['chunks'],
        'jobResult' => $ran['result'],
        'states' => ['before' => $stateBefore, 'after' => $stateAfter, 'stale' => $stateStale, 'running' => $stateRunning['state'], 'job' => $stateRunning['job']['id'] ?? null],
        'overlap' => ['state' => $stateOverlap['state'], 'finished' => \is_array($ranOver['result']), 'staleAfterStart' => ($stateOverlap['stale_since'] ?? '') >= (string) ($stateOverlap['summary']['now'] ?? 'x')],
        'asks' => ['first' => $firstAsk->joined, 'second' => $secondAsk->joined, 'same' => $firstAsk->id === $secondAsk->id],
        'pages' => ['one' => \count($pageOne['rows']), 'two' => \count($pageTwo['rows']), 'totals' => [$pageOne['total'], $pageTwo['total']], 'distinct' => ($pageOne['rows'][0]['rule']['id'] ?? null) !== ($pageTwo['rows'][0]['rule']['id'] ?? null)],
        'above' => $ofItem($sections[PriceAudit::SECTION_SALE_ABOVE], $pAbove),
        'aboveRow' => $aboveRow,
        'large' => $ofItem($sections[PriceAudit::SECTION_SALE_LARGE], $pLarge),
        'largeOpen' => $ofItem($sections[PriceAudit::SECTION_SALE_OPEN], $pLarge),
        'aboveNotLarge' => $ofItem($sections[PriceAudit::SECTION_SALE_LARGE], $pAbove),
        'expiredDiscount' => $ofRule($sections[PriceAudit::SECTION_RULES_PERIOD], 'discount', $d4),
        'upcomingDiscount' => $ofRule($sections[PriceAudit::SECTION_RULES_PERIOD], 'discount', $d3),
        'upcomingTax' => $ofRule($sections[PriceAudit::SECTION_RULES_PERIOD], 'tax', $t1),
        'activeDiscount' => $ofRule($sections[PriceAudit::SECTION_RULES_PERIOD], 'discount', $d1),
        'inverted' => $ofItem($sections[PriceAudit::SECTION_INVERTED], $pInverted),
        'belowRules' => $ofItem($sections[PriceAudit::SECTION_SALE_BELOW_RULES], $pBelow),
        'noPrice' => $ofItem($sections[PriceAudit::SECTION_UNPRICED], $pNoPrice),
        'stacked' => $ofItem($sections[PriceAudit::SECTION_STACKED], $pStacked),
        'available' => [$sections[PriceAudit::SECTION_UNPRICED]['available'], $sections[PriceAudit::SECTION_STACKED]['available']],
        'counts' => PriceAudit::counts(summary: $summary),
        'sentence' => PriceAudit::sentence(summary: $summary),
        'belowSentence' => $belowSentence,
        'stackedSentence' => $stackedSentence,
        'told' => ['followed' => $followed, 'with' => $toldWith, 'quiet' => $toldQuiet],
        'd' => [$d1, $d2, $d3, $d4],
    ];
} catch (\Throwable $e) {
    $result = ['error' => \get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()];
} finally {
    // Closed through TransactionState: the work the saves deferred to their commit goes with the transaction.
    TransactionState::rollBackLevel(db: $db);
}

if (isset($result['error'])) {
    check('the fixtures and the report', $result, 'a report');
} else {
    echo "\nthe discounts list\n";
    check('[PE2] the period filter narrows to the expired rule alone', $result['periodFilter']['expired'], [true, false, false]);
    check('[PE2] the period filter narrows to the not started rule alone', $result['periodFilter']['upcoming'], [true, false, false]);
    check('[PE2] the period filter keeps the rules in their period and no other', $result['periodFilter']['active'], [true, true, false, false]);
    check('[PE2] a published rule with such dates shows the expired or the pending icon, never the plain check', $result['icons'], ['expired' => true, 'pending' => true, 'plain' => true]);

    echo "\nthe Reach card\n";
    $reach1 = $result['reach1'];
    check('[RE1] ★ the rule reaches at least its two products of the category and the manufacturer', $reach1['reaches'] >= 2, true);
    check('[RE1] ★ the product list it opens is the Products list narrowed by the rule filter, published only', $result['reachLink'], [true, true]);
    check('[RE1] ★ the list that filter opens is the reach\'s own set: the same products, the count the card shows, the rule\'s two products among them', $result['reachList'], ['same' => true, 'counted' => true, 'holds' => true]);
    check('[RE1] ★ at least one product of its manufacturer stays out for not being in its category', $reach1['outside'] >= 1, true);
    check('[RE1] ★ it stacks with the compounding rule on at least its two products', ($result['stacks12'] ?? 0) >= 2, true);
    check('[RE1] ★ it does not stack with the rule set to only this', $result['stacks13'], null);
    check('[RE1] the rule set to only this stacks with none and says so', [$result['reach3']['behavior'], $result['reach3']['stacks'], $result['reach3']['period']], [0, [], RulePeriod::UPCOMING]);
    check('[RE1] the rule in its period is read as such, compared with every other combining rule', [$reach1['period'], $reach1['published'], $reach1['compared'] >= 1], [RulePeriod::ACTIVE, true, true]);

    echo "\nthe price check\n";
    $above = $result['above'];
    // The audit states a price as the catalogue unit (the stored figure on its digits, no padding zeros).
    $unit = static fn (string $stored): string => PriceComputationEngine::catalogueUnit(value: $stored);
    check('[PA1] ★ a sale above the price it replaces is a finding naming its row', [\count($above), $above[0]['row_id'] ?? null, $above[0]['detail']['sale_price'] ?? null, $above[0]['detail']['replaced_price'] ?? null], [1, $result['aboveRow'], $unit('80.00000000'), $unit('60.00000000')]);
    check('[PA1] a sale above the price is not also a large one', $result['aboveNotLarge'], []);
    $large = $result['large'];
    check('[PA1] ★ a sale below half the price is a finding with its share', [\count($large), (float) ($large[0]['detail']['share_percent'] ?? -1)], [1, 90.0]);
    check('[PA1] ★ a sale without an end date is a finding by option', \count($result['largeOpen']), 1);
    check('[PA2] ★ a discount published after its end is a finding, expired', [\count($result['expiredDiscount']), $result['expiredDiscount'][0]['detail']['status'] ?? null], [1, RulePeriod::EXPIRED]);
    check('[PA2] ★ a discount published before its start is a finding, not started', [\count($result['upcomingDiscount']), $result['upcomingDiscount'][0]['detail']['status'] ?? null], [1, RulePeriod::UPCOMING]);
    check('[PA2] ★ a tax published before its start is a finding, not started', [\count($result['upcomingTax']), $result['upcomingTax'][0]['detail']['status'] ?? null], [1, RulePeriod::UPCOMING]);
    check('[PA2] a discount in its period is not one', $result['activeDiscount'], []);
    $inverted = $result['inverted'];
    check('[PA3] ★ a period row without a sale beside a sale row without a period is a finding naming both rows', [\count($inverted), ($inverted[0]['row_id'] ?? 0) > 0, ($inverted[0]['detail']['sale_row'] ?? 0) > 0, $inverted[0]['detail']['sale_price'] ?? null], [1, true, true, $unit('50.00000000')]);
    $below = $result['belowRules'];
    check('[PA4] ★ a sale that gives less than the rules it replaces is a finding naming the rules', [\count($below), str_contains((string) ($below[0]['detail']['rules'] ?? ''), 'PA disposable discount D1'), str_contains((string) ($below[0]['detail']['rules'] ?? ''), 'PA disposable discount D2')], [1, true, true]);
    check('[PA4] ★ the engine ran for the sale rows, none left unchecked', [$result['engine']['runs'] > 0, $result['engine']['unchecked']], [true, 0]);

    if ($result['context'] === null) {
        echo "  skip [PA5] the price index holds no prepared context for the anonymous visitor on this shop; the sections that read it say so\n";
        check('[PA5] the sections that read the index say they are not available', $result['available'], [false, false]);
    } else {
        check('[PA5] ★ a published product with no price row is a finding', [\count($result['noPrice']), $result['noPrice'][0]['detail']['kind'] ?? null], [1, 'no_price']);
        $stacked = $result['stacked'];
        check('[PA5] ★ a product two rules reach whose index price is more than half off is a finding naming both rules', [\count($stacked), (int) ($stacked[0]['detail']['rule_count'] ?? 0) >= 2, str_contains((string) ($stacked[0]['detail']['rules'] ?? ''), 'D1'), str_contains((string) ($stacked[0]['detail']['rules'] ?? ''), 'D2')], [1, true, true, true]);
    }

    $counts = $result['counts'];
    check('[PA6] counts() names each section with findings', [isset($counts[PriceAudit::SECTION_SALE_ABOVE]), isset($counts[PriceAudit::SECTION_SALE_LARGE]), isset($counts[PriceAudit::SECTION_RULES_PERIOD]), isset($counts[PriceAudit::SECTION_INVERTED]), isset($counts[PriceAudit::SECTION_SALE_BELOW_RULES])], [true, true, true, true, true]);
    check('[PA6] sentence() words them', [str_contains($result['sentence'], Text::plural('COM_ALFA_PRICE_AUDIT_COUNT_SALE_ABOVE', $counts[PriceAudit::SECTION_SALE_ABOVE] ?? 0)), $result['sentence'] !== ''], [true, true]);

    echo "\nthe notice\n";
    $kinds = array_map('strval', array_keys($counts));
    sort($kinds, SORT_STRING);
    check('[PA7] the notice\'s facts are the kinds found', [$result['told']['followed'], $result['told']['with']], [array_keys($counts), $kinds]);
    check('[PA7] a result with nothing withdraws them', $result['told']['quiet'], null);

    echo "\nthe stored result\n";
    check('[ST1] ★ never run before a result is stored, complete once stored, out of date once a change is marked, running while a job waits',
        [$result['states']['before'], $result['states']['after'], $result['states']['stale'], $result['states']['running'], ($result['states']['job'] ?? 0) > 0],
        [PriceAuditStore::STATE_NEVER, PriceAuditStore::STATE_COMPLETE, PriceAuditStore::STATE_STALE, PriceAuditStore::STATE_RUNNING, true]);
    check('[ST2] ★ a second Check again joins the job already waiting: one job', [$result['asks']['first'], $result['asks']['second'], $result['asks']['same']], [false, true, true]);
    check('[ST3] ★ the stored rows page: one row a page, the next page another row, the total the same', [$result['pages']['one'], $result['pages']['two'], $result['pages']['totals'][0] === $result['pages']['totals'][1], $result['pages']['distinct']], [1, 1, true, true]);
    check('[ST5] ★ a change between two chunks leaves the finished result out of date', [$result['overlap']['finished'], $result['overlap']['state'], $result['overlap']['staleAfterStart']], [true, PriceAuditStore::STATE_STALE, true]);
    check('[ST4] ★ every sale row judged across chunks: more chunks than one, the job\'s result the counts', [$result['chunks'] > \count(PriceAudit::SET_SECTIONS) + 2, \is_array($result['jobResult']['counts'] ?? null), $result['engine']['runs'] <= $result['saleRows']], [true, true, true]);

    echo "\nthe product's price row\n";
    $d1Name = 'PA disposable discount D1 ' . $run;
    check('[PR1] the row with a sale says the sale replaces the rules it names', [str_starts_with((string) $result['belowSentence']['text'], strstr(Text::_('COM_ALFA_PRICES_SALE_REPLACES_RULES'), '%1$s', true)), str_contains((string) $result['belowSentence']['names'], $d1Name), $result['belowSentence']['hidden']], [true, true, false]);
    check('[PR1] the row without a sale says which rules apply now', [str_starts_with((string) $result['stackedSentence']['text'], strstr(Text::_('COM_ALFA_PRICES_RULES_APPLIED'), '%s', true)), str_contains((string) $result['stackedSentence']['names'], $d1Name), $result['stackedSentence']['hidden']], [true, true, false]);
}

$pricesJs = SourceFile::read(JPATH_ROOT . '/media/com_alfa/js/admin/prices.js');
check('[PR1] prices.js keeps the sentence live from the preview\'s rules, the row\'s sale and the product\'s switch',
    [str_contains($pricesJs, "row.querySelector('[data-price-rules-note]')"), str_contains($pricesJs, "Joomla.Text._('COM_ALFA_PRICES_SALE_REPLACES_RULES')"), str_contains($pricesJs, "Joomla.Text._('COM_ALFA_PRICES_RULES_APPLIED')"), str_contains($pricesJs, 'note.dataset.ruleNames = discounts.map(ruleLabel)'), str_contains($pricesJs, "Joomla.Text._('COM_ALFA_PRICES_RULES_EXCLUDED')")],
    [true, true, true, true, true]);

// [T1] The run's rows, by the ids it knows: a product, rule or tax id of this run in a table that references them
// (information_schema names the columns) outlived the rollback. Looked for in the tables whose count moved, since a
// row of the run moves its table's count; removed, so the shop is left as found, and named as the failure it is.
$after = $census();
$moved = array_filter($before, static fn (int $count, string $table): bool => ($after[$table] ?? null) !== $count, ARRAY_FILTER_USE_BOTH);
$known = ['item_id' => $runIds['item_id'], 'id_item' => $runIds['item_id'], 'discount_id' => $runIds['discount_id'], 'id_discount' => $runIds['discount_id'], 'tax_id' => $runIds['tax_id'], 'id_tax' => $runIds['tax_id']];
$primary = [$p . 'alfa_items' => $runIds['item_id'], $p . 'alfa_discounts' => $runIds['discount_id'], $p . 'alfa_taxes' => $runIds['tax_id']];
$columns = [];

foreach ($oracle->setQuery("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '" . $like . "%'")->loadRowList() ?: [] as [$table, $column]) {
    $columns[$table][] = $column;
}

$outlived = [];

foreach (array_keys($moved) as $table) {
    $where = [];

    foreach ($columns[$table] ?? [] as $column) {
        $ids = $column === 'id' ? ($primary[$table] ?? []) : ($known[$column] ?? []);

        if ($ids !== []) {
            $where[] = "`{$column}` IN (" . implode(',', $ids) . ')';
        }
    }

    if ($where === []) {
        continue;
    }

    $found = (int) $oracle->setQuery("SELECT COUNT(*) FROM `{$table}` WHERE " . implode(' OR ', $where))->loadResult();

    if ($found > 0) {
        $oracle->setQuery("DELETE FROM `{$table}` WHERE " . implode(' OR ', $where))->execute();
        $outlived[$table] = $found;
        $after[$table] = (int) $oracle->setQuery("SELECT COUNT(*) FROM `{$table}`")->loadResult();
    }
}

check('[T1] ★ nothing of the run outlived the rollback, by the ids it knows (a row found is removed and named)', $outlived, []);

// Every other table as it was. One that moved holds none of the run's rows: its newest rows are named, with the
// columns that say who wrote them when it has them, so a writer sharing the shop during the run can be told apart.
$telling = ['id', 'created_at', 'created', 'modified', 'handler', 'origin', 'subject_type', 'dedup_key', 'name'];
$others = [];

foreach ($moved as $table => $count) {
    if (($after[$table] ?? null) === $count) {
        continue;
    }

    $shown = array_values(array_intersect($telling, $columns[$table] ?? []));
    $newest = [];

    if (\in_array('id', $shown, true) && ($after[$table] ?? 0) > $count) {
        $newest = $oracle->setQuery('SELECT `' . implode('`, `', $shown) . "` FROM `{$table}` ORDER BY `id` DESC", 0, min(3, $after[$table] - $count))->loadAssocList();
    }

    $others[$table] = ['held' => $count, 'holds' => $after[$table] ?? null, 'newest' => $newest];
}

check('[T1] every table holds exactly the rows it held (' . \count($before) . ' read on a second connection)', $others, []);

echo "\nprice audit: {$pass} passed, {$fail} failed\n";

exit($fail === 0 ? 0 : 1);
