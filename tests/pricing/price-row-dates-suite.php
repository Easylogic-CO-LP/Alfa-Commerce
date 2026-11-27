<?php

/**
 * A PRICE ROW'S PERIOD IS TYPED IN THE OPERATOR'S TIME ZONE AND STORED AS UTC, AND A FORM SAVED UNCHANGED MOVES NOTHING.
 *
 * The item screen's price rows carry two calendar fields, Active from and Active until, inside one posted field
 * (jform[prices]) that Joomla does not filter row by row. The dates arrive as typed, in the operator's time zone;
 * the controller turns them into the stored UTC values (PriceRowDates::stored()) before the model writes them, and
 * the editor shows a stored value back in the operator's zone. The defect this pins against: the typed local time
 * was stored as if it were UTC, so every save shifted a row's period by the zone's offset.
 *
 * The fields follow the operator's language: each language's DATE_FORMAT_CALENDAR_DATETIME decides the order the
 * date is shown and typed in, and DATE_FORMAT_FILTER_DATETIME how it is read back. Every arm runs twice, as an
 * operator in English (2026-12-01 10:00:00) and as one in Greek (01-12-2026 10:00:00), both in Europe/Athens.
 *
 *   [PD1] ★ PriceRowDates::stored(): a date typed by an operator in Europe/Athens, in the operator's language's
 *         order, is stored as the UTC moment; an empty date stays null.
 *   [PD2] ★ a NEW product saved through the real ItemModel after the item controller's own pre-save hook stores
 *         the UTC values of the two typed dates.
 *   [PD3] the editor shows the stored row's dates as they were typed, in the operator's zone and date order.
 *   [PD4] ★ the form posted again unchanged, with the values the editor showed, stores the identical UTC values.
 *   [PR1] ★ a price row whose period ends at or before its start is refused, named by its row (the one judgement
 *         the item save, the variant window and the REST API share); an open or ordered period is not.
 *   [PR2] ★ a discount whose period ends before it starts is refused by its table, with its own sentence.
 *   [PR3] ★ a tax whose period ends before it starts is refused by its table, with its own sentence.
 *   [PR4] ★ the item screen's save of such a row is refused with the sentence and writes no product.
 *   [T1]  every table of the schema holds exactly the rows it held, read on a second connection.
 *
 * Fixtures: one unpublished product of this run's own per language, written inside a transaction that is rolled
 * back. It reads one category of the shop and writes nothing that survives the run. The Greek arms need the el-GR
 * administrator language installed; without it they fail and say so.
 *
 *   cd <docroot> && php administrator/components/com_alfa/tests/pricing/price-row-dates-suite.php
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
use Alfa\Component\Alfa\Administrator\Model\ItemModel;
use Alfa\Component\Alfa\Administrator\Service\Catalog\PriceRowDates;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\Text;
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
$app->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);
$app->createExtensionNamespaceMap();
MailOff::engage();

\defined('JPATH_COMPONENT') || \define('JPATH_COMPONENT', JPATH_ADMINISTRATOR . '/components/com_alfa');
\defined('JPATH_COMPONENT_ADMINISTRATOR') || \define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_ADMINISTRATOR . '/components/com_alfa');
\defined('JPATH_COMPONENT_SITE') || \define('JPATH_COMPONENT_SITE', JPATH_SITE . '/components/com_alfa');

// The editor is rendered the way the item screen renders it; a suite runs from the site docroot, so an installed
// template is pinned.
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
    echo "  FAIL {$what}\n    got  ", json_encode($got), "\n    want ", json_encode($want), "\n";
}

echo "A price row's period is stored as UTC from the operator's time zone\n", str_repeat('=', 70), "\n";

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

/** The value attribute of the first rendered row's calendar input for a column. */
$shownDate = static function (string $html, string $column): ?string {
    $rowsHtml = (string) strstr($html, '<template data-price-template>', true);

    if (!preg_match('~<input[^>]*\sname="jform\[prices\]\[0\]\[' . $column . '\]"[^>]*>~', $rowsHtml, $tag)) {
        return null;
    }

    return preg_match('~\svalue="([^"]*)"~', $tag[0], $found) ? html_entity_decode($found[1], ENT_QUOTES, 'UTF-8') : null;
};

$before = $census();

// The operator: a super user whose time zone is Europe/Athens (UTC+2 in December, no summer time).
$super = (int) $db->setQuery(
    "SELECT m.user_id FROM `{$p}user_usergroup_map` m INNER JOIN `{$p}users` u ON u.id = m.user_id WHERE m.group_id = 8 AND u.block = 0 ORDER BY m.user_id",
    0,
    1,
)->loadResult();
$operator = new User($super);
$operator->setParam('timezone', 'Europe/Athens');
$app->loadIdentity($operator);

$category = (int) $db->setQuery("SELECT c.id FROM `{$p}alfa_categories` c WHERE c.state = 1 ORDER BY c.id", 0, 1)->loadResult();
$utcUp = '2026-12-01 08:00:00';
$utcDown = '2026-12-31 16:00:00';

$factory = $app->bootComponent('com_alfa')->getMVCFactory();
$model = $factory->createModel('Item', 'Administrator', ['ignore_request' => true]);
$controller = $factory->createController('Item', 'Administrator', [], $app, $app->getInput());
$hook = new \ReflectionMethod($controller, 'preSaveHook');

/** The item screen's save: the posted data validated, passed through the controller's hook and saved. */
$saveAsTheScreenDoes = static function (array $data) use ($app, $model, $controller, $hook): int {
    $app->getInput()->post->set('jform', $data);
    $form = $model->getForm($data, false);
    $valid = $form ? $model->validate($form, $data) : false;

    if ($valid === false) {
        throw new \RuntimeException('validate refused: ' . implode(' ', array_map('strval', $model->getErrors())));
    }

    $valid = $hook->invoke($controller, $model, $valid);

    if (!$model->save($valid)) {
        throw new \RuntimeException('save refused: ' . $model->getError());
    }

    return (int) $model->getState('item.id');
};

/** The item screen's post for a new product with one price row. */
$postOf = static function (string $run, array $row) use ($category): array {
    return [
        'id' => 0, 'state' => 0,
        'name_en_gb' => 'PD disposable ' . $run, 'name_el_gr' => 'PD disposable ' . $run,
        'alias_en_gb' => 'pd-disposable-' . strtolower($run), 'alias_el_gr' => 'pd-disposable-el-' . strtolower($run),
        'id_category_default' => $category, 'categories' => [$category],
        'prices' => [$row],
    ];
};
$rowOf = static fn (string $up, string $down): array => ['id' => 0, 'value' => '100.00000000', 'override_value' => '', 'override_type' => 'start',
    'price_status' => '1', 'country_id' => 0, 'usergroup_id' => 0, 'user_id' => 0, 'currency_id' => 0, 'quantity_start' => '', 'quantity_end' => '',
    'publish_up' => $up, 'publish_down' => $down];

// The same moments as each operator types them, in the order of the operator's language.
$languages = [
    'en-GB' => ['filter' => 'Y-m-d H:i:s', 'up' => '2026-12-01 10:00:00', 'down' => '2026-12-31 18:00:00'],
    'el-GR' => ['filter' => 'd-m-Y H:i:s', 'up' => '01-12-2026 10:00:00', 'down' => '31-12-2026 18:00:00'],
];

foreach ($languages as $tag => $typed) {
    $language = Language::getInstance($tag);
    Factory::$language = $language;
    $app->loadLanguage($language);
    $language->load('com_alfa', JPATH_ADMINISTRATOR);
    $typedUp = $typed['up'];
    $typedDown = $typed['down'];

    echo "\nas an operator in {$tag}\n";
    check("[{$tag}] the language's date filter format is the one the arms type in", Text::_('DATE_FORMAT_FILTER_DATETIME'), $typed['filter']);

    // [PD1] the conversion on its own.
    $stored = PriceRowDates::stored(row: ['value' => '100', 'publish_up' => $typedUp, 'publish_down' => $typedDown]);
    check("[PD1 {$tag}] ★ a period typed in Europe/Athens is stored as its UTC moments", [$stored['publish_up'], $stored['publish_down']], [$utcUp, $utcDown]);

    $empty = PriceRowDates::stored(row: ['value' => '100', 'publish_up' => '', 'publish_down' => '']);
    check("[PD1 {$tag}] an empty date stays null", [$empty['publish_up'], $empty['publish_down']], [null, null]);

    // [PD2] to [PD4] through the real model and the item controller's pre-save hook, as the item screen's save runs them.
    $run = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $result = ['error' => 'not run'];

    $db->transactionStart();

    try {
        $row = $rowOf($typedUp, $typedDown);
        $data = $postOf($run, $row);
        $itemId = $saveAsTheScreenDoes($data);

        if ($itemId <= 0) {
            throw new \RuntimeException('the new product has no id');
        }

        echo 'fixture ids: alfa_items=' . $itemId . " (inside the rolled-back transaction)\n";

        $storedRows = $db->setQuery("SELECT * FROM `{$p}alfa_items_prices` WHERE item_id = {$itemId} AND combination_id IS NULL ORDER BY id")->loadAssocList();
        $first = [$storedRows[0]['publish_up'] ?? null, $storedRows[0]['publish_down'] ?? null];

        // The editor, rendered for the stored rows as the item screen renders it.
        $html = AlfapricesField::renderPrices(controlBase: 'jform[prices]', rows: $storedRows, showModify: false, itemId: $itemId);
        $shown = [$shownDate($html, 'publish_up'), $shownDate($html, 'publish_down')];

        // The form saved again as shown, nothing changed.
        $again = $row;
        $again['id'] = (int) $storedRows[0]['id'];
        $again['publish_up'] = (string) $shown[0];
        $again['publish_down'] = (string) $shown[1];
        $data['id'] = $itemId;
        $data['prices'] = [$again];
        $saveAsTheScreenDoes($data);

        $second = $db->setQuery("SELECT publish_up, publish_down FROM `{$p}alfa_items_prices` WHERE item_id = {$itemId} AND combination_id IS NULL ORDER BY id")->loadAssoc();
        $result = ['first' => $first, 'shown' => $shown, 'second' => [$second['publish_up'] ?? null, $second['publish_down'] ?? null]];
    } catch (\Throwable $e) {
        $result = ['error' => \get_class($e) . ': ' . $e->getMessage()];
    } finally {
        $db->transactionRollback();
    }

    check("[PD2 {$tag}] ★ a NEW product saved as the item screen saves it stores the UTC values of the typed period", $result['first'] ?? $result, [$utcUp, $utcDown]);
    check("[PD3 {$tag}] the editor shows the stored period as it was typed, in the operator's zone and date order", $result['shown'] ?? $result, [$typedUp, $typedDown]);
    check("[PD4 {$tag}] ★ the form saved again unchanged stores the identical UTC values", $result['second'] ?? $result, $result['first'] ?? [$utcUp, $utcDown]);
}

// Refusals, as an operator in English (the sentences compared are the loaded language's).
$english = Language::getInstance('en-GB');
Factory::$language = $english;
$app->loadLanguage($english);
$english->load('com_alfa', JPATH_ADMINISTRATOR);

echo "\nrefusals\n";

$refused = ItemModel::priceRefusals(rows: [
    ['value' => '100', 'publish_up' => '2026-12-31 16:00:00', 'publish_down' => '2026-12-01 08:00:00'],
    ['value' => '100', 'publish_up' => '2026-12-01 08:00:00', 'publish_down' => '2026-12-01 08:00:00'],
    ['value' => '100', 'publish_up' => '2026-12-01 08:00:00', 'publish_down' => '2026-12-31 16:00:00'],
    ['value' => '100', 'publish_up' => '', 'publish_down' => '2026-12-31 16:00:00'],
    ['value' => '100', 'publish_up' => '2026-12-01 08:00:00', 'publish_down' => null],
]);
check('[PR1] ★ a price row whose period ends at or before its start is refused, named by its row; an open or ordered period is not',
    $refused,
    [Text::sprintf('COM_ALFA_PRICES_PERIOD_END_BEFORE_START', 1), Text::sprintf('COM_ALFA_PRICES_PERIOD_END_BEFORE_START', 2)]);

$discount = $factory->createTable('Discount', 'Administrator');
$discount->bind(['name' => 'PD disposable', 'value' => '10', 'is_amount' => 0, 'publish_up' => '2026-12-31 16:00:00', 'publish_down' => '2026-12-01 08:00:00']);
check('[PR2] ★ a discount whose period ends before it starts is refused by its table', [$discount->check(), $discount->getError()], [false, Text::_('COM_ALFA_DISCOUNT_PERIOD_END_BEFORE_START')]);

$discountInOrder = $factory->createTable('Discount', 'Administrator');
$discountInOrder->bind(['name' => 'PD disposable', 'value' => '10', 'is_amount' => 0, 'publish_up' => '2026-12-01 08:00:00', 'publish_down' => '2026-12-31 16:00:00']);
check('[PR2] a discount whose period is in order passes its table', $discountInOrder->check(), true);

$tax = $factory->createTable('Tax', 'Administrator');
$tax->bind(['name' => 'PD disposable', 'rate' => '24', 'publish_up' => '2026-12-31 16:00:00', 'publish_down' => '2026-12-31 16:00:00']);
check('[PR3] ★ a tax whose period ends when it starts is refused by its table', [$tax->check(), $tax->getError()], [false, Text::_('COM_ALFA_TAX_PERIOD_END_BEFORE_START')]);

$taxOpen = $factory->createTable('Tax', 'Administrator');
$taxOpen->bind(['name' => 'PD disposable', 'rate' => '24', 'publish_up' => '2026-12-01 08:00:00', 'publish_down' => null]);
check('[PR3] a tax with no end passes its table', $taxOpen->check(), true);

// [PR4] the item screen's save of a row whose period is reversed: refused before anything is written.
$outcome = ['error' => 'not run'];
$db->transactionStart();

try {
    $itemsBefore = (int) $db->setQuery("SELECT COUNT(*) FROM `{$p}alfa_items`")->loadResult();
    $message = '';

    try {
        $saveAsTheScreenDoes($postOf(strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)), $rowOf('2026-12-31 18:00:00', '2026-12-01 10:00:00')));
    } catch (\RuntimeException $e) {
        $message = $e->getMessage();
    }

    $outcome = ['message' => $message, 'items_added' => (int) $db->setQuery("SELECT COUNT(*) FROM `{$p}alfa_items`")->loadResult() - $itemsBefore];
} catch (\Throwable $e) {
    $outcome = ['error' => \get_class($e) . ': ' . $e->getMessage()];
} finally {
    $db->transactionRollback();
}

check('[PR4] ★ the item screen\'s save of a reversed period is refused with the sentence and writes no product',
    $outcome,
    ['message' => 'save refused: ' . Text::sprintf('COM_ALFA_PRICES_PERIOD_END_BEFORE_START', 1), 'items_added' => 0]);

$after = $census();
$moved = [];

foreach ($before as $key => $count) {
    if (($after[$key] ?? null) !== $count) {
        $moved[$key] = [$count, $after[$key] ?? null];
    }
}

check('[T1] every table holds exactly the rows it held (' . \count($before) . ' read on a second connection)', $moved, []);

echo "\nprice row dates: {$pass} passed, {$fail} failed\n";

exit($fail === 0 ? 0 : 1);
