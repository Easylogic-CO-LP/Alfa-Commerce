<?php

/**
 * A PRODUCT SHOWS THE LABELS IT IS GIVEN, EACH IN ITS CORNER, AND THE LIST PAYS ONE QUERY FOR THEM.
 *
 * Four kinds of label wear one badge: a discount's sale tag, the automatic New label (the shop's days, Options >
 * General > Products), the shop's predefined labels (Options > Labels) a product ticks, and a product's own label
 * between its dates. Labels decides what a product shows and puts the labels on its surface slots, one stack per slot
 * and corner; ItemLabels keeps a product's rows.
 *
 * Every database arm runs on disposable fixtures of this run's own, written through the real product model inside one
 * transaction that is rolled back; the shop's labels are set on the component's params in this process and put back.
 *
 *   [LB1] a corner is one of four; an empty or unknown value is the top left.
 *   [LB2] a label from its settings: its name in the default language, its slots validated per context, shown only
 *         with a name and the context's flag; a product's choice puts it in another corner.
 *   [LB3] the rows a save writes: a ticked label with its corner, nothing for an unticked one, the New label only when
 *         hidden or moved, the product's own label only when named; an own label ending before it starts is refused.
 *   [LB4] the shop's labels in the order of the Options, a key used twice kept once, the reserved keys left out; no
 *         New label while the shop counts no product as new.
 *   [LB5] ★ a disposable product with a ticked predefined label in a corner of its own, its own label inside and
 *         outside its dates, and the New label by the days setting: the labels the card and the page would show.
 *   [LB6] ★ the card's image slot holds one stack per corner, the New label and a ticked label of the same corner in
 *         one, the own label in another; the title slot holds its own row.
 *   [LB7] ★ the rows of three products are read in one query; the admin form's values follow the rows.
 *   [T1]  every table of the schema holds exactly the rows it held, read on a second connection.
 *
 * Fixtures: products of this run's own in a published category of the shop (read, not written). The run needs one;
 * it says so and stops otherwise.
 *
 *   cd <docroot> && php administrator/components/com_alfa/tests/listing/item-labels-suite.php
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

PHP_SAPI === 'cli' or exit;

define('_JEXEC', 1);
define('JPATH_BASE', dirname(__DIR__, 5));
\defined('JDEBUG') or \define('JDEBUG', false);

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Alfa\Component\Alfa\Administrator\Event\ItemFields\SurfaceMap;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ItemLabels;
use Alfa\Component\Alfa\Site\Service\Catalog\Label;
use Alfa\Component\Alfa\Site\Service\Catalog\LabelCorner;
use Alfa\Component\Alfa\Site\Service\Catalog\Labels;
use Alfa\Component\Alfa\Site\Service\Catalog\NewProducts;
use Alfa\Component\Alfa\Site\Service\Pricing\ActiveDateCondition;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseFactory;
use Joomla\Database\Monitor\DebugMonitor;
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
$skip = 0;

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

echo "A product shows the labels it is given, each in its corner\n", str_repeat('=', 70), "\n";

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
$defaultKey = MultilingualHelper::normaliseTag(tag: MultilingualHelper::getDefaultLanguageTag());
$otherKey = $defaultKey === 'en_gb' ? 'el_gr' : 'en_gb';

echo "\nthe corners\n";
check('[LB1] the four corners read as themselves', array_map(static fn (LabelCorner $c): string => LabelCorner::of($c->value)->value, LabelCorner::cases()), ['top-left', 'top-right', 'bottom-left', 'bottom-right']);
check('[LB1] an empty, a null and an unknown value are the top left', [LabelCorner::of('')->value, LabelCorner::of(null)->value, LabelCorner::of('middle')->value], ['top-left', 'top-left', 'top-left']);

echo "\na label from its settings\n";
$settings = ['name' => [$defaultKey => 'Black Friday', $otherKey => 'Μαύρη Παρασκευή'], 'bg' => ' #000 ', 'color' => '#fff', 'class' => 'bf', 'list_show' => '1', 'list_slot' => 'short-description', 'item_show' => '0', 'item_slot' => 'title', 'corner' => 'bottom-right'];
$label = Label::fromSettings(key: 'bf', kind: Label::KIND_PREDEFINED, settings: $settings);
check('[LB2] the name is the default language\'s, the colours trimmed, the corner read', [$label->name, $label->bg, $label->color, $label->corner->value], ['Black Friday', '#000', '#fff', 'bottom-right']);
check('[LB2] a slot the card has not lands in the image; the page keeps its own', [$label->slotFor(true)->value, $label->slotFor(false)->value], ['image', 'title']);
check('[LB2] shown by the context\'s flag only', [$label->shownOn(true), $label->shownOn(false)], [true, false]);
check('[LB2] a label without a name shows nowhere', Label::fromSettings('x', Label::KIND_PREDEFINED, ['name' => ['' => '']])->shownOn(true), false);
check('[LB2] the product\'s choice puts it in another corner and changes nothing else', [$label->inCorner(LabelCorner::TopRight)->corner->value, $label->inCorner(LabelCorner::TopRight)->name, $label->corner->value], ['top-right', 'Black Friday', 'bottom-right']);
check('[LB2] the REST shape is key, name and corner', $label->toArray(), ['key' => 'bf', 'name' => 'Black Friday', 'corner' => 'bottom-right']);

echo "\nthe rows a save writes\n";
$posted = ItemLabels::posted(
    labels: ['new' => ['shown' => '1', 'corner' => ''], 'bf' => ['shown' => '1', 'corner' => 'top-right'], 'hot' => ['corner' => ''], 'Bad Key!' => ['shown' => '1'], 'custom' => ['shown' => '1']],
    custom: ['name' => [$defaultKey => '', $otherKey => ''], 'bg' => '', 'color' => '', 'class' => '', 'list_show' => '1', 'list_slot' => 'image', 'item_show' => '1', 'item_slot' => 'image', 'corner' => 'bottom-left'],
    from: '',
    to: '',
);
check('[LB3] a ticked label keeps its corner, an unticked one has no row, the New label as the shop shows it has none, a bad key and the reserved key are left out, an unnamed own label has none',
    array_map(static fn (array $row): array => [$row['label_key'], $row['shown'], $row['corner']], $posted), [['bf', 1, 'top-right']]);
$posted = ItemLabels::posted(labels: ['new' => ['corner' => 'bottom-right']], custom: ['name' => [$defaultKey => ' Only here ']], from: '2026-12-01 00:00:00', to: '2026-12-31 23:59:59');
check('[LB3] the New label hidden gets a row, and the own label its settings and period',
    [[$posted[0]['label_key'], $posted[0]['shown'], $posted[0]['corner']], $posted[1]['label_key'], $posted[1]['corner'], json_decode($posted[1]['custom'], true)['name'], json_decode($posted[1]['custom'], true)['publish_down']],
    [['new', 0, 'bottom-right'], 'custom', 'top-left', [$defaultKey => 'Only here'], '2026-12-31 23:59:59']);
check('[LB3] the New label moved to a corner keeps its showing', ItemLabels::posted(labels: ['new' => ['shown' => '1', 'corner' => 'top-right']], custom: [], from: '', to: '')[0], ['label_key' => 'new', 'shown' => 1, 'corner' => 'top-right', 'custom' => null]);
check('[LB3] an own label whose period ends before it starts is refused, one in order is not',
    [ItemLabels::refusals(ItemLabels::posted([], ['name' => [$defaultKey => 'x']], '2026-12-31 00:00:00', '2026-12-01 00:00:00')), ItemLabels::refusals($posted)],
    [[Text::_('COM_ALFA_ITEM_LABEL_PERIOD_END_BEFORE_START')], []]);

echo "\nthe shop's labels\n";
$params = ComponentHelper::getParams('com_alfa');
$kept = ['days' => $params->get(NewProducts::PARAM_DAYS), 'from' => $params->get(NewProducts::PARAM_FROM), 'labels' => $params->get(Labels::PARAM_LABELS), 'new' => $params->get(Labels::PARAM_NEW_LABEL)];
$restore = static function () use ($params, $kept): void {
    $params->set(NewProducts::PARAM_DAYS, $kept['days']);
    $params->set(NewProducts::PARAM_FROM, $kept['from']);
    $params->set(Labels::PARAM_LABELS, $kept['labels']);
    $params->set(Labels::PARAM_NEW_LABEL, $kept['new']);
    Labels::forget();
};
$row = static fn (string $key, string $name, string $corner = 'top-left', int $itemShow = 1): array => ['key' => $key, 'name' => [$defaultKey => $name], 'bg' => '', 'color' => '', 'class' => '', 'list_show' => 1, 'list_slot' => 'image', 'item_show' => $itemShow, 'item_slot' => 'image', 'corner' => $corner];
$params->set(Labels::PARAM_LABELS, (object) [
    'labels0' => (object) $row('bf', 'Black Friday'),
    'labels1' => (object) $row('hot', 'Bestseller', 'bottom-left', 0),
    'labels2' => (object) $row('BF', 'Black Friday again'),
    'labels3' => (object) $row('new', 'Not this one'),
    'labels4' => (object) $row('custom', 'Nor this'),
    'labels5' => (object) ['key' => '', 'name' => [$defaultKey => 'Keyless']],
]);
$params->set(Labels::PARAM_NEW_LABEL, (object) ['name' => (object) [$defaultKey => 'Fresh'], 'bg' => '#1d4ed8', 'color' => '', 'class' => '', 'list_show' => 1, 'list_slot' => 'image', 'item_show' => 1, 'item_slot' => 'title', 'corner' => 'top-right']);
$params->set(NewProducts::PARAM_DAYS, 0);
$params->set(NewProducts::PARAM_FROM, NewProducts::FROM_CREATED);
Labels::forget();
check('[LB4] the shop\'s labels in the Options\' order, a key twice kept once (lower case), the reserved and empty keys left out', array_keys(Labels::predefined()), ['bf', 'hot']);
check('[LB4] a predefined label reads its settings', [Labels::predefined()['hot']->name, Labels::predefined()['hot']->corner->value, Labels::predefined()['hot']->itemShow], ['Bestseller', 'bottom-left', false]);
check('[LB4] no New label while the shop counts no product as new', Labels::newLabel(), null);
$params->set(NewProducts::PARAM_DAYS, 30);
Labels::forget();
check('[LB4] the New label as the Options set it', [Labels::newLabel()?->name, Labels::newLabel()?->bg, Labels::newLabel()?->corner->value, Labels::newLabel()?->itemSlot], ['Fresh', '#1d4ed8', 'top-right', 'title']);
$params->set(Labels::PARAM_NEW_LABEL, null);
Labels::forget();
check('[LB4] until the Options are saved once, the New label says New', Labels::newLabel()?->name, Labels::NEW_NAME);
$params->set(Labels::PARAM_NEW_LABEL, (object) ['name' => (object) [$defaultKey => 'Fresh'], 'bg' => '#1d4ed8', 'color' => '', 'class' => '', 'list_show' => 1, 'list_slot' => 'image', 'item_show' => 1, 'item_slot' => 'title', 'corner' => 'top-right']);
Labels::forget();

// The shop's own category the fixtures live in.
$category = (int) $db->setQuery("SELECT c.id FROM `{$p}alfa_categories` c WHERE c.state = 1 ORDER BY c.id", 0, 1)->loadResult();

if ($category <= 0) {
    $restore();
    echo "\nThe shop needs a published category for the fixtures; it has none.\n";
    echo "\nitem labels: {$pass} passed, " . ($fail + 1) . " failed, {$skip} skipped\n";
    exit(1);
}

$factory = $app->bootComponent('com_alfa')->getMVCFactory();
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

$itemModel = $factory->createModel('Item', 'Administrator', ['ignore_request' => true]);
$itemController = $factory->createController('Item', 'Administrator', [], $app, $app->getInput());
$hook = new \ReflectionMethod($itemController, 'preSaveHook');
$run = strtolower(substr(bin2hex(random_bytes(4)), 0, 8));
$now = ActiveDateCondition::now();
$shift = static fn (int $seconds): string => gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') + $seconds);

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

$product = static function (string $tag, array $labels = [], array $custom = [], string $from = '', string $to = '') use ($saveItem, $named, $run, $category): int {
    return $saveItem([
        'id' => 0, 'state' => 1, 'sku' => 'LB-' . strtoupper($run) . '-' . $tag,
        'id_category_default' => $category, 'categories' => [$category], 'manufacturers' => [],
        'labels' => $labels, 'custom_label' => $custom, 'custom_label_from' => $from, 'custom_label_to' => $to,
        'prices' => [[
            'id' => 0, 'value' => '100.00000000', 'override_value' => '', 'override_type' => 'start', 'price_status' => '1',
            'country_id' => 0, 'usergroup_id' => 0, 'user_id' => 0, 'currency_id' => 0, 'quantity_start' => '', 'quantity_end' => '',
            'publish_up' => '', 'publish_down' => '',
        ]],
    ] + $named('LB disposable ' . $tag . ' ' . $run, 'lb-disposable-' . $tag . '-' . $run));
};

/** A card or page item as the storefront models hand it to Labels: the row's columns and an empty surface map. */
$asItem = static function (int $id) use ($db, $p): object {
    $row = $db->setQuery("SELECT id, created, publish_up FROM `{$p}alfa_items` WHERE id = {$id}")->loadObject();
    $row->surfaces = new SurfaceMap([]);
    $row->price = null;

    return $row;
};
$keys = static fn (array $labels): array => array_map(static fn (Label $label): string => $label->key . '@' . $label->corner->value, $labels);
$own = ['name' => [$defaultKey => 'Only here'], 'bg' => '#333', 'color' => '#fff', 'class' => '', 'list_show' => '1', 'list_slot' => 'image', 'item_show' => '1', 'item_slot' => 'image', 'corner' => 'bottom-right'];

$result = ['error' => 'not run'];
$db->transactionStart();

try {
    // A new product ticking Black Friday in the top right, with its own label inside its dates.
    $pTicked = $product('ticked', ['new' => ['shown' => '1', 'corner' => ''], 'bf' => ['shown' => '1', 'corner' => 'top-right']], $own, $shift(-86400), $shift(86400));
    // Its own label's period over; the New label hidden. The dates post as the operator's local time and are stored
    // as UTC, so each margin is a day, wider than any zone.
    $pOver = $product('over', ['new' => ['corner' => '']], $own, '', $shift(-86400));
    // Its own label not yet begun; nothing ticked; made sixty days ago.
    $pLater = $product('later', [], $own, $shift(86400), '');
    $db->setQuery("UPDATE `{$p}alfa_items` SET created = " . $db->quote($shift(-60 * 86400)) . " WHERE id = {$pLater}")->execute();
    // Bestseller ticked, in its own corner (bottom left), shown on cards only.
    $pHot = $product('hot', ['hot' => ['shown' => '1', 'corner' => '']]);

    echo "fixture ids: items={$pTicked},{$pOver},{$pLater},{$pHot} (inside the rolled-back transaction)\n";

    $rows = ItemLabels::forItems(db: $db, itemIds: [$pTicked, $pOver, $pLater, $pHot]);
    $stored = [];

    foreach ([$pTicked, $pOver, $pLater, $pHot] as $id) {
        $stored[] = array_map(static fn (object $r): array => [(string) $r->label_key, (int) $r->shown, $r->corner], array_values($rows[$id] ?? []));
    }

    // The admin form's values from the rows.
    $form = $factory->createModel('Item', 'Administrator', ['ignore_request' => true])->getItem($pTicked);
    $formValues = ['labels' => $form->labels, 'own' => [$form->custom_label['name'] ?? null, $form->custom_label['corner'] ?? null], 'from' => \strlen((string) $form->custom_label_from)];

    // What the card and the page show, at thirty days.
    Labels::forget();
    $params->set(NewProducts::PARAM_DAYS, 30);
    $shown = [];

    foreach (['ticked' => $pTicked, 'over' => $pOver, 'later' => $pLater, 'hot' => $pHot] as $name => $id) {
        $shown[$name] = ['card' => $keys(Labels::of($asItem($id), true, $now)), 'page' => $keys(Labels::of($asItem($id), false, $now))];
    }

    // The same product once the shop counts no product as new, and at a moment past its own label.
    Labels::forget();
    $params->set(NewProducts::PARAM_DAYS, 0);
    $noDays = $keys(Labels::of($asItem($pTicked), true, $now));
    $params->set(NewProducts::PARAM_DAYS, 30);
    Labels::forget();
    $later = $keys(Labels::of($asItem($pTicked), true, $shift(3 * 86400)));

    // The card's slots.
    Labels::forget();
    $card = $asItem($pTicked);
    Labels::contribute(item: $card, isListing: true);
    $image = array_map(static fn (array $d): array => [$d['layout'], $d['layoutData']['corner'], $keys($d['layoutData']['labels'])], $card->surfaces['image']);
    $page = $asItem($pTicked);
    Labels::contribute(item: $page, isListing: false);
    $pageSlots = array_map(static fn (array $list): array => array_map(static fn (array $d): array => [$d['layoutData']['corner'], $keys($d['layoutData']['labels'])], $list), $page->surfaces->toArray());

    // One query for three products.
    Labels::forget();
    $monitor = new DebugMonitor();
    $db->setMonitor($monitor);
    Labels::warm([$pTicked, $pOver, $pHot]);
    $three = [\count(Labels::of($asItem($pTicked), true, $now)), \count(Labels::of($asItem($pOver), true, $now)), \count(Labels::of($asItem($pHot), true, $now))];
    $db->setMonitor(null);
    $labelQueries = \count(array_filter($monitor->getLogs(), static fn (string $sql): bool => str_contains($sql, 'alfa_item_labels')));

    $result = ['stored' => $stored, 'form' => $formValues, 'shown' => $shown, 'noDays' => $noDays, 'later' => $later, 'image' => $image, 'page' => $pageSlots, 'three' => $three, 'queries' => $labelQueries, 'ids' => [$pTicked, $pOver, $pLater, $pHot]];
} catch (\Throwable $e) {
    $result = ['error' => \get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()];
} finally {
    $db->setMonitor(null);
    $db->transactionRollback();
    $restore();
}

if (isset($result['error'])) {
    check('the fixtures and the readings', $result, 'readings');
} else {
    echo "\nthe rows a product keeps\n";
    check('[LB5] ★ the ticked product keeps Black Friday in the top right and its own label; the New label, shown as the shop shows it, has no row',
        $result['stored'][0], [['bf', 1, 'top-right'], ['custom', 1, 'bottom-right']]);
    check('[LB5] the product hiding the New label keeps that and its own label', array_map(static fn (array $r): string => $r[0] . ':' . $r[1], $result['stored'][1]), ['custom:1', 'new:0']);
    check('[LB5] the product ticking nothing keeps only its own label; Bestseller ticked in the label\'s own corner keeps no corner', [$result['stored'][2], $result['stored'][3]], [[['custom', 1, 'top-left']], [['hot', 1, null]]]);
    check('[LB7] ★ the admin form reads the ticks, the corners and the own label from the rows',
        $result['form'], ['labels' => ['new' => ['shown' => 1, 'corner' => ''], 'bf' => ['shown' => 1, 'corner' => 'top-right']], 'own' => [[$defaultKey => 'Only here'], 'bottom-right'], 'from' => 19]);

    echo "\nwhat a product shows\n";
    check('[LB5] ★ the new product shows the New label, Black Friday in the product\'s corner and its own label, on the card and on the page',
        $result['shown']['ticked'], ['card' => ['new@top-right', 'bf@top-right', 'custom@bottom-right'], 'page' => ['new@top-right', 'bf@top-right', 'custom@bottom-right']]);
    check('[LB5] ★ the product whose own label is over and hides the New label shows nothing', $result['shown']['over'], ['card' => [], 'page' => []]);
    check('[LB5] ★ the product made sixty days ago is not new, and its own label has not begun', $result['shown']['later'], ['card' => [], 'page' => []]);
    check('[LB5] Bestseller shows on the card in its own corner and not on the page', $result['shown']['hot'], ['card' => ['new@top-right', 'hot@bottom-left'], 'page' => ['new@top-right']]);
    check('[LB5] ★ with no days the New label goes; three days on, the own label is over', [$result['noDays'], $result['later']], [['bf@top-right', 'custom@bottom-right'], ['new@top-right', 'bf@top-right']]);

    echo "\nthe slots\n";
    check('[LB6] ★ the card\'s image slot holds one stack per corner: the New label and Black Friday together in the top right, the own label in the bottom right',
        $result['image'], [['item.labels', 'top-right', ['new@top-right', 'bf@top-right']], ['item.labels', 'bottom-right', ['custom@bottom-right']]]);
    check('[LB6] ★ on the page the New label stands in the title slot, in a row without a corner, and the others in the image slot',
        $result['page'], ['title' => [['', ['new@top-right']]], 'image' => [['top-right', ['bf@top-right']], ['bottom-right', ['custom@bottom-right']]]]);

    echo "\nwhat the list pays\n";
    check('[LB7] ★ the rows of three products are read in one query, and each product then answers without one', [$result['queries'], $result['three']], [1, [3, 0, 2]]);
}

echo "\nthe tables as they were\n";
$after = $census();
$diff = [];

foreach ($after as $table => $count) {
    if (($before[$table] ?? null) !== $count) {
        $diff[$table] = [$before[$table] ?? null, $count];
    }
}

check('[T1] every table holds exactly the rows it held', $diff, []);

echo "\nitem labels: {$pass} passed, {$fail} failed, {$skip} skipped\n";
exit($fail > 0 ? 1 : 0);
