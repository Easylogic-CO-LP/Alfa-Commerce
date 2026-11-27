<?php

/**
 * A DISCOUNT RULE REACHES THE PRODUCTS IT NAMES AND NEVER THE ONES IT, OR THE PRODUCT ITSELF, LEAVES OUT.
 *
 * A rule's scope gained a sixth part, its products: named, the rule reaches those alone within its categories and
 * manufacturers; none named, every product the other parts take in. Under Except, the rule names products and
 * categories it never reaches, and leaves new products out while they are new within the shop's days (Options >
 * General > Products) or days of its own. On the product, a switch takes it out of every rule, whatever the rules say;
 * a variant follows its product unless the Studio sets its own. The loader matches the rules, the collection applies
 * the product's switch, the calculator the variant's; the REST shape of every list is ids.
 *
 * Every arm runs on disposable fixtures of this run's own, written through the real models inside one transaction that
 * is rolled back: products as the item screen saves them, rules through the discount model, a variant row and two
 * creation dates by SQL.
 *
 *   [SC1] ★ a rule naming products reaches the named product and not another of its category.
 *   [SC2] ★ a product the rule excludes, and a product in a category it excludes, are not reached; the others are.
 *   [SC3] ★ a product whose switch excludes it is reached by no rule; the rules matched to it are still kept for a
 *         variant that takes them on its own, and a variant set to excluded takes none.
 *   [SC4] ★ a rule that leaves new products out for its own days does not reach a product made today and reaches one
 *         made thirty days ago; one that uses the shop's days follows the shop's setting, and 0 days leaves nothing out.
 *   [SC5] NewProducts judges the date, the days and the counted-from setting; the table refuses a rule with its own
 *         days and none given.
 *   [SC6] the product list field reads ids, rows and JSON alike; the saved rule answers ids for every list.
 *   [SC7] ★ deleting the only product a rule names is refused, naming the rule.
 *   [SC8] (needs a prepared price index context) the product's index periods end where the rule's days end.
 *   [MG1] ★ a per-language key of a field the form does not translate is not recovered from the post, every other
 *         undeclared key still is; a discount saved with a stray alias_<lang> and foo_<lang> saves its name and
 *         gives its language table no column; a product saves its name and its alias per language.
 *   [T1]  every table of the schema holds exactly the rows it held, read on a second connection.
 *
 * Fixtures: products and rules of this run's own in two published categories of the shop (read, not written). The run
 * needs those two categories; it says so and stops otherwise.
 *
 *   cd <docroot> && php administrator/components/com_alfa/tests/pricing/discount-scope-suite.php
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

use Alfa\Component\Alfa\Administrator\Field\DiscountItemsField;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper as Multilingual;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Alfa\Component\Alfa\Site\Service\Catalog\NewProducts;
use Alfa\Component\Alfa\Site\Service\Pricing\ActiveDateCondition;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceDataLoader;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
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

echo "A discount rule reaches the products it names and never the ones left out\n", str_repeat('=', 70), "\n";

/** Every table's row count, on the second connection. */
$census = static function () use ($oracle, $p): array {
    $out = [];

    foreach ($oracle->setQuery("SHOW TABLES LIKE '" . str_replace('_', '\\_', $p) . "%'")->loadColumn() as $table) {
        if (str_contains($table, 'session')) {
            continue;
        }

        $out[$table] = (int) $oracle->setQuery("SELECT COUNT(*) FROM `{$table}`")->loadResult();
    }

    return $out;
};

$before = $census();

$super = (int) $db->setQuery(
    "SELECT m.user_id FROM `{$p}user_usergroup_map` m INNER JOIN `{$p}users` u ON u.id = m.user_id WHERE m.group_id = 8 AND u.block = 0 ORDER BY m.user_id",
    0,
    1,
)->loadResult();
$operator = new User($super);
$operator->setParam('timezone', 'Europe/Athens');
$app->loadIdentity($operator);

// [SC5] the pure judgements first.
echo "\nwhat makes a product new\n";
$clock = '2026-12-15 12:00:00';
check('[SC5] a product made ten days ago is new for thirty days', NewProducts::isNew('2026-12-05 12:00:00', null, 30, $clock, NewProducts::FROM_CREATED), true);
check('[SC5] and not for seven', NewProducts::isNew('2026-12-05 12:00:00', null, 7, $clock, NewProducts::FROM_CREATED), false);
check('[SC5] it stops being new at its date plus the days', NewProducts::until('2026-12-05 12:00:00', null, 7, NewProducts::FROM_CREATED), '2026-12-12 12:00:00');
check('[SC5] counted from publishing, the publish-up date rules; without one the day it was made', [NewProducts::isNew('2026-11-01 00:00:00', '2026-12-14 00:00:00', 7, $clock, NewProducts::FROM_PUBLISHED), NewProducts::isNew('2026-12-10 00:00:00', null, 7, $clock, NewProducts::FROM_PUBLISHED)], [true, true]);
check('[SC5] no date, or no days, is never new', [NewProducts::isNew(null, null, 30, $clock), NewProducts::isNew('2026-12-14 00:00:00', null, 0, $clock)], [false, false]);
check('[SC5] a product whose date has not come is not new yet', NewProducts::isNew('2026-12-20 00:00:00', null, 7, $clock, NewProducts::FROM_CREATED), false);

$factory = $app->bootComponent('com_alfa')->getMVCFactory();
$table = $factory->createTable('Discount', 'Administrator');
$table->bind(['value' => '10', 'is_amount' => 0, 'new_products' => 2, 'new_days' => 0]);
check('[SC5] the table refuses a rule with its own days and none given', [$table->check(), $table->getError()], [false, \Joomla\CMS\Language\Text::_('COM_ALFA_ERROR_DISCOUNT_NEW_DAYS')]);
$table = $factory->createTable('Discount', 'Administrator');
$table->bind(['value' => '10', 'is_amount' => 0, 'new_products' => 1, 'new_days' => 9]);
check('[SC5] a rule on the shop\'s days keeps no days of its own', [$table->check(), (int) $table->new_days], [true, 0]);

echo "\nthe language keys a save takes\n";
$defaultKey = MultilingualHelper::normaliseTag(tag: MultilingualHelper::getDefaultLanguageTag());
$guardForm = \Joomla\CMS\Form\Form::getInstance('com_alfa.discount.guard', JPATH_ADMINISTRATOR . '/components/com_alfa/forms/discount.xml', ['control' => 'jform']);
$merged = Multilingual::mergeValidatedFormData(
    ['value' => '10'],
    ['value' => '10', 'name_' . $defaultKey => 'kept', 'name_customer_' . $defaultKey => 'kept too', 'alias_' . $defaultKey => 'stray', 'foo_' . $defaultKey => 'stray', 'grid_note' => 'kept'],
    $guardForm,
);
check('[MG1] ★ the form\'s translated fields are recovered, a language key of any other field is dropped, another undeclared key is kept',
    [isset($merged['name_' . $defaultKey]), isset($merged['name_customer_' . $defaultKey]), isset($merged['alias_' . $defaultKey]), isset($merged['foo_' . $defaultKey]), isset($merged['grid_note'])],
    [true, true, false, false, true]);
check('[MG1] the declared fields of the discount table carry no alias; the product table does', [\in_array('alias', Multilingual::declaredFields('#__alfa_discounts') ?? [], true), \in_array('alias', Multilingual::declaredFields('#__alfa_items') ?? [], true), Multilingual::declaredFields('#__alfa_nothing_here')], [false, true, null]);

echo "\nthe product list field\n";
check('[SC6] the field reads ids, rows, objects and JSON alike, each product once, nothing for a row left empty',
    [DiscountItemsField::ids([5, '7', ['item_id' => 9], (object) ['item_id' => 11], 'x', ['item_id' => 0], 5]), DiscountItemsField::ids('[3, {"item_id": 4}]'), DiscountItemsField::ids('')],
    [[5, 7, 9, 11], [3, 4], []]);

// The shop's own categories the fixtures live in.
$categories = array_map('intval', $db->setQuery("SELECT c.id FROM `{$p}alfa_categories` c WHERE c.state = 1 ORDER BY c.id", 0, 2)->loadColumn());

if (\count($categories) < 2) {
    echo "\nThe shop needs two published categories for the fixtures; it has " . \count($categories) . ".\n";
    echo "\ndiscount scope: {$pass} passed, " . ($fail + 1) . " failed\n";
    exit(1);
}

[$catA, $catB] = $categories;
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

$itemModel = $factory->createModel('Item', 'Administrator', ['ignore_request' => true]);
$itemController = $factory->createController('Item', 'Administrator', [], $app, $app->getInput());
$hook = new \ReflectionMethod($itemController, 'preSaveHook');
$run = strtolower(substr(bin2hex(random_bytes(4)), 0, 8));
$now = ActiveDateCondition::now();

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

$product = static function (string $tag, int $category, int $rules = 1) use ($saveItem, $named, $run): int {
    return $saveItem([
        'id' => 0, 'state' => 1, 'sku' => 'DS-' . strtoupper($run) . '-' . $tag,
        'id_category_default' => $category, 'categories' => [$category], 'manufacturers' => [],
        'discount_rules' => $rules,
        'prices' => [[
            'id' => 0, 'value' => '100.00000000', 'override_value' => '', 'override_type' => 'start', 'price_status' => '1',
            'country_id' => 0, 'usergroup_id' => 0, 'user_id' => 0, 'currency_id' => 0, 'quantity_start' => '', 'quantity_end' => '',
            'publish_up' => '', 'publish_down' => '',
        ]],
    ] + $named('DS disposable ' . $tag . ' ' . $run, 'ds-disposable-' . $tag . '-' . $run));
};

$saveRule = static function (array $data) use ($app, $factory, $ruleNamed, $run): int {
    $data += ['id' => 0, 'state' => 1, 'is_amount' => 0, 'behavior' => 1, 'apply_before_tax' => 1, 'manufacturers' => [0], 'places' => [0], 'usergroups' => [0], 'users' => [0]]
        + $ruleNamed('DS disposable rule ' . ($data['tag'] ?? '') . ' ' . $run);
    unset($data['tag']);
    $app->getInput()->post->set('jform', $data);
    $model = $factory->createModel('Discount', 'Administrator', ['ignore_request' => true]);

    if (!$model->save($data)) {
        throw new \RuntimeException('discount save refused: ' . $model->getError());
    }

    return (int) $model->getState('discount.id');
};

$params = ComponentHelper::getParams('com_alfa');
$shopDaysBefore = $params->get(NewProducts::PARAM_DAYS);
$shopFromBefore = $params->get(NewProducts::PARAM_FROM);
$result = ['error' => 'not run'];
$db->transactionStart();

try {
    $params->set(NewProducts::PARAM_DAYS, 0);
    $params->set(NewProducts::PARAM_FROM, NewProducts::FROM_CREATED);

    $pIn = $product('in', $catA);
    $pOut = $product('out', $catA);
    $pExcl = $product('excl', $catA);
    $pCatExcl = $product('catexcl', $catB);
    $pSwitch = $product('switch', $catA, 0);
    $pNew = $product('new', $catA);
    $pOld = $product('old', $catA);

    // A product made thirty days ago, and a variant of the excluded product that takes the rules on its own and one
    // that takes none.
    $db->setQuery("UPDATE `{$p}alfa_items` SET created = " . $db->quote(gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - 30 * 86400)) . " WHERE id = {$pOld}")->execute();
    $db->setQuery("INSERT INTO `{$p}alfa_item_combinations` (item_id, signature, sku, state, discount_rules) VALUES ({$pSwitch}, 'ds-{$run}-a', 'DS-{$run}-VA', 1, 1), ({$pSwitch}, 'ds-{$run}-b', 'DS-{$run}-VB', 1, 0)")->execute();
    [$vApply, $vExcluded] = array_map('intval', $db->setQuery("SELECT id FROM `{$p}alfa_item_combinations` WHERE item_id = {$pSwitch} ORDER BY id")->loadColumn());

    $d1 = $saveRule(['tag' => 'D1', 'value' => '10', 'categories' => [$catA], 'items' => [$pIn]]);
    $d2 = $saveRule(['tag' => 'D2', 'value' => '20', 'categories' => [$catA, $catB], 'excluded_items' => [$pExcl], 'excluded_categories' => [$catB]]);
    $d3 = $saveRule(['tag' => 'D3', 'value' => '5', 'categories' => [$catA], 'new_products' => 2, 'new_days' => 7]);
    $d4 = $saveRule(['tag' => 'D4', 'value' => '7', 'categories' => [$catA], 'new_products' => 1]);

    // [MG1] a rule saved with stray language keys: saved, named, and no column made of them; a product keeps its alias.
    $gStray = $saveRule(['tag' => 'G', 'value' => '1', 'categories' => [$catA], 'alias_' . $defaultKey => 'stray-alias', 'foo_' . $defaultKey => 'stray']);
    $ruleColumns = $db->setQuery('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $db->quote($p . 'alfa_discounts_' . $defaultKey))->loadColumn() ?: [];
    $guard = [
        'saved' => $gStray > 0,
        'name' => $db->setQuery("SELECT name FROM `{$p}alfa_discounts_{$defaultKey}` WHERE id_discount = {$gStray}")->loadResult(),
        'columns' => [\in_array('alias', $ruleColumns, true), \in_array('foo', $ruleColumns, true)],
        'product' => $db->setQuery("SELECT name, alias FROM `{$p}alfa_items_{$defaultKey}` WHERE id_item = {$pIn}")->loadAssoc(),
    ];

    echo "fixture ids: items={$pIn},{$pOut},{$pExcl},{$pCatExcl},{$pSwitch},{$pNew},{$pOld} variants={$vApply},{$vExcluded} discounts={$d1},{$d2},{$d3},{$d4},{$gStray} (inside the rolled-back transaction)\n";

    $context = PriceContext::forIndex(currencyId: Currency::getDefault()->getId(), placeId: 0, usergroupId: 0)->withCustomer(customer: new User());
    $items = [$pIn, $pOut, $pExcl, $pCatExcl, $pSwitch, $pNew, $pOld];

    /** The rules of this run matched to a product by their scope and exclusions, and after the product's switch. */
    $matched = static function (int $itemId, array $mine) use ($context, $items): array {
        PriceDataLoader::forgetLoaded();
        $data = (new PriceDataLoader())->loadBatch($items, $context);
        $ids = static fn (array $rows): array => array_values(array_intersect(array_map(static fn (object $row): int => (int) $row->id, $rows), $mine));

        return ['rules' => $ids($data->getRuleDiscountsFor($itemId)), 'applied' => $ids($data->getDiscountsFor($itemId)), 'apply' => $data->rulesApplyFor($itemId)];
    };
    $mine = [$d1, $d2, $d3, $d4];

    $reach = [];

    foreach (['in' => $pIn, 'out' => $pOut, 'excl' => $pExcl, 'catexcl' => $pCatExcl, 'switch' => $pSwitch, 'new' => $pNew, 'old' => $pOld] as $name => $id) {
        $reach[$name] = $matched($id, $mine);
    }

    // The shop's days: ten, then none.
    $params->set(NewProducts::PARAM_DAYS, 10);
    $shopTen = ['new' => $matched($pNew, [$d4]), 'old' => $matched($pOld, [$d4])];
    $params->set(NewProducts::PARAM_DAYS, 0);

    // The variants: one takes the rules on its own, one takes none, while the product takes none.
    PriceDataLoader::forgetLoaded();
    $loader = new PriceDataLoader();
    $switches = [$loader->combinationDiscountSwitch($vApply), $loader->combinationDiscountSwitch($vExcluded), $loader->combinationDiscountSwitch(0)];
    $units = (new PriceCalculator())->calculateUnits([$pSwitch => [0, $vApply, $vExcluded]], 1, $context);
    $appliedIds = static fn ($result): array => array_values(array_intersect(array_map(static fn ($d): int => (int) $d->id, $result->getDiscounts()->getApplied()), $mine));
    $variants = ['product' => $appliedIds($units[$pSwitch][0]), 'apply' => $appliedIds($units[$pSwitch][$vApply]), 'excluded' => $appliedIds($units[$pSwitch][$vExcluded])];

    // The saved rule, as the form and the REST resource read it.
    $saved = $factory->createModel('Discount', 'Administrator', ['ignore_request' => true])->getItem($d2);
    $savedShape = ['items' => $saved->items, 'excluded_items' => $saved->excluded_items, 'excluded_categories' => $saved->excluded_categories, 'new' => [(int) $saved->new_products, (int) $saved->new_days]];
    $savedD3 = $factory->createModel('Discount', 'Administrator', ['ignore_request' => true])->getItem($d3);

    // [SC7] the only product D1 names cannot be deleted under it.
    $deleteModel = $factory->createModel('Item', 'Administrator', ['ignore_request' => true]);
    $pks = [$pIn];
    $deleted = $deleteModel->delete($pks);
    $deleteError = (string) $deleteModel->getError();
    $stillThere = (int) $db->setQuery("SELECT COUNT(*) FROM `{$p}alfa_items` WHERE id = {$pIn}")->loadResult();

    // [SC8] the index periods of the product made today end where D3's seven days end.
    $read = PriceIndexSyncService::readContext(context: $context);
    $edge = null;

    if ($read !== null) {
        (new PriceIndexSyncService())->syncItem($pNew);
        $created = (string) $db->setQuery("SELECT created FROM `{$p}alfa_items` WHERE id = {$pNew}")->loadResult();
        $edge = [
            'until' => $db->setQuery("SELECT valid_until FROM `{$p}alfa_items_price_index` WHERE context_id = " . (int) $read['context'] . " AND item_id = {$pNew} AND combination_id = 0 ORDER BY valid_from", 0, 1)->loadResult(),
            'expected' => NewProducts::until($created, null, 7, NewProducts::FROM_CREATED),
        ];
    }

    $result = ['reach' => $reach, 'shopTen' => $shopTen, 'switches' => $switches, 'variants' => $variants, 'saved' => $savedShape, 'savedD3' => [(int) $savedD3->new_products, (int) $savedD3->new_days],
        'delete' => ['result' => $deleted, 'named' => str_contains($deleteError, 'DS disposable rule D1 ' . $run), 'kept' => $stillThere], 'edge' => $edge,
        'ids' => ['d1' => $d1, 'd2' => $d2, 'd3' => $d3, 'd4' => $d4, 'excl' => $pExcl], 'guard' => $guard];
} catch (\Throwable $e) {
    $result = ['error' => \get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()];
} finally {
    $db->transactionRollback();
    $params->set(NewProducts::PARAM_DAYS, $shopDaysBefore);
    $params->set(NewProducts::PARAM_FROM, $shopFromBefore);
    PriceDataLoader::forgetLoaded();
}

if (isset($result['error'])) {
    check('the fixtures and the readings', $result, 'readings');
} else {
    ['d1' => $d1, 'd2' => $d2, 'd3' => $d3, 'd4' => $d4, 'excl' => $pExcl] = $result['ids'];
    $r = $result['reach'];
    $has = static fn (array $list, int $id): bool => \in_array($id, $list, true);

    echo "\nthe products a rule reaches\n";
    check('[SC1] ★ the rule naming a product reaches it', $has($r['in']['rules'], $d1), true);
    check('[SC1] ★ and not another product of its category', $has($r['out']['rules'], $d1), false);
    check('[SC2] ★ the rule with exclusions reaches the products it does not name', [$has($r['in']['rules'], $d2), $has($r['out']['rules'], $d2)], [true, true]);
    check('[SC2] ★ not the product it excludes', $has($r['excl']['rules'], $d2), false);
    check('[SC2] ★ not a product in a category it excludes', $has($r['catexcl']['rules'], $d2), false);
    check('[SC3] ★ the product whose switch excludes it is matched by the rules and takes none', [$has($r['switch']['rules'], $d2), $r['switch']['applied'], $r['switch']['apply']], [true, [], false]);
    check('[SC3] a product with the switch on takes what matches it', [$r['in']['apply'], $has($r['in']['applied'], $d2)], [true, true]);
    check('[SC3] the variants read their own switch: on its own, excluded, and none for the product itself', $result['switches'], [1, 0, null]);
    check('[SC3] ★ the excluded product\'s own unit takes no rule, its variant set to take them does, its variant set to excluded does not',
        [$result['variants']['product'], $has($result['variants']['apply'], $d2), $result['variants']['excluded']], [[], true, []]);
    check('[SC4] ★ a rule leaving new products out for seven days of its own skips the product made today and reaches the one made thirty days ago', [$has($r['new']['rules'], $d3), $has($r['old']['rules'], $d3)], [false, true]);
    check('[SC4] ★ a rule on the shop\'s days reaches both while the shop counts no product as new', [$has($r['new']['rules'], $d4), $has($r['old']['rules'], $d4)], [true, true]);
    check('[SC4] ★ and skips the product made today once the shop counts ten days', [$has($result['shopTen']['new']['rules'], $d4), $has($result['shopTen']['old']['rules'], $d4)], [false, true]);

    echo "\nthe saved rule\n";
    check('[SC6] the saved rule answers ids for its products, its excluded products and its excluded categories, and its new-products choice', $result['saved'], ['items' => [], 'excluded_items' => [$pExcl], 'excluded_categories' => [$catB], 'new' => [0, 0]]);
    check('[SC6] the rule with its own days keeps them', $result['savedD3'], [2, 7]);

    echo "\nthe language keys a save takes\n";
    $guard = $result['guard'];
    check('[MG1] ★ a rule saved with a stray alias and a stray foo key saves its name and gets no column of them', [$guard['saved'], $guard['name'], $guard['columns']], [true, 'DS disposable rule G ' . $run, [false, false]]);
    check('[MG1] a product still saves its name and its alias in the language', [$guard['product']['name'] ?? null, ($guard['product']['alias'] ?? '') !== ''], ['DS disposable in ' . $run, true]);

    echo "\nthe delete guard\n";
    check('[SC7] ★ deleting the only product a rule names is refused, naming the rule, and the product stays', $result['delete'], ['result' => false, 'named' => true, 'kept' => 1]);

    if ($result['edge'] === null) {
        echo "  skip [SC8] the price index holds no prepared context for the anonymous visitor on this shop\n";
    } else {
        check('[SC8] the index period of the product made today ends where the rule\'s seven days end', $result['edge']['until'], $result['edge']['expected']);
    }
}

$after = $census();
$moved = [];

foreach ($before as $key => $count) {
    if (($after[$key] ?? null) !== $count) {
        $moved[$key] = [$count, $after[$key] ?? null];
    }
}

check('[T1] every table holds exactly the rows it held (' . \count($before) . ' read on a second connection)', $moved, []);

echo "\ndiscount scope: {$pass} passed, {$fail} failed\n";

exit($fail === 0 ? 0 : 1);
