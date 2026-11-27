<?php

/**
 * A PRODUCT SHOWS THE LABELS IT TICKS, EACH IN ITS CORNER, THE LIST FILTERS BY THEM AND PAYS ONE QUERY FOR THEM.
 *
 * Four kinds of label wear one badge: a discount's sale tag, the automatic New label (the shop's days, Options >
 * General > Products), the shop's labels (the Labels screen) a product ticks, and a product's own label between its
 * dates. Labels decides what a product shows and puts the labels on its surface slots, one stack per slot and corner;
 * ItemLabels keeps a product's ticks (#__alfa_item_labels) and its own label with its New choice (label_settings).
 *
 * Every database arm runs on disposable fixtures of this run's own: labels through the real label model, products
 * through the real product model, inside one transaction that is rolled back; the shop's days are set on the
 * component's params in this process and put back. The labels' language tables are made before the transaction opens,
 * so no DDL commits it.
 *
 *   [LB1] a corner is one of four; an empty or unknown value is the top left.
 *   [LB2] a label from its settings: its name in the default language, its slots validated per context, shown only
 *         with a name and the context's flag; a product's choice puts it in another corner.
 *   [LB3] the ticks a save writes, from the form's map, a REST list of ids and a map of corners; the settings column
 *         from the New choice and the own label; an own label ending before it starts is refused.
 *   [LB4] ★ the shop's labels: saved with their names, read in their order, the unpublished one offered to tick but
 *         never shown, only the filterable ones offered as filters; no New label while the shop counts no product as new.
 *   [LB5] ★ a disposable product ticking a label in a corner of its own, its own label inside and outside its dates,
 *         and the New label by the days setting: the labels the card and the page would show.
 *   [LB6] ★ the card's image slot holds one stack per corner; the title slot its own row.
 *   [LB7] ★ the ticks of three products are read in one query; the admin form's values follow the rows; the REST item
 *         states the ticks as ids with the corners chosen.
 *   [LB8] ★ the catalogue list filtered by a label holds the product that ticks it and not the one that does not; the
 *         labels facet counts the result's products per label; the new-arrivals filter keeps the product made today.
 *   [T1]  every table of the schema holds exactly the rows it held, read on a second connection.
 *
 * Fixtures: labels and products of this run's own, the products in a published category of the shop (read, not
 * written). The run needs one; it says so and stops otherwise.
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

echo "A product shows the labels it ticks, each in its corner, and the list filters by them\n", str_repeat('=', 70), "\n";

// The labels' language tables, before any transaction: made here when the installation has not made them yet, so the
// first label save below runs no DDL inside the transaction that is rolled back.
MultilingualHelper::ensureLangSchema(tableName: '#__alfa_labels', primaryColumnName: 'id_label', fields: ['name']);

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
$label = Label::fromSettings(key: '7', kind: Label::KIND_PREDEFINED, settings: $settings);
check('[LB2] the name is the default language\'s, the colours trimmed, the corner read', [$label->name, $label->bg, $label->color, $label->corner->value], ['Black Friday', '#000', '#fff', 'bottom-right']);
check('[LB2] a slot the card has not lands in the image; the page keeps its own', [$label->slotFor(true)->value, $label->slotFor(false)->value], ['image', 'title']);
check('[LB2] shown by the context\'s flag only', [$label->shownOn(true), $label->shownOn(false)], [true, false]);
check('[LB2] a label without a name shows nowhere', Label::fromSettings('x', Label::KIND_PREDEFINED, ['name' => ['' => '']])->shownOn(true), false);
check('[LB2] the product\'s choice puts it in another corner and changes nothing else', [$label->inCorner(LabelCorner::TopRight)->corner->value, $label->inCorner(LabelCorner::TopRight)->name, $label->corner->value], ['top-right', 'Black Friday', 'bottom-right']);
check('[LB2] the REST shape is key, name and corner', $label->toArray(), ['key' => '7', 'name' => 'Black Friday', 'corner' => 'bottom-right']);

echo "\nthe ticks and the settings a save writes\n";
check('[LB3] the form\'s map: a ticked label keeps its corner, one without a corner sits in its own, an unticked one and a bad id have no row',
    ItemLabels::posted(labels: [3 => ['on' => '1', 'corner' => 'top-right'], 5 => ['on' => '1', 'corner' => ''], 8 => ['corner' => 'top-left'], 'x' => ['on' => '1'], 0 => ['on' => '1']]),
    [3 => 'top-right', 5 => null]);
check('[LB3] a REST list of ids, with the corners sent apart', ItemLabels::posted(labels: [3, '5', 0], corners: ['5' => 'bottom-left', 3 => 'middle']), [3 => null, 5 => 'bottom-left']);
check('[LB3] a map of id to corner', ItemLabels::posted(labels: ['3' => 'top-right', '5' => '']), [3 => 'top-right', 5 => null]);
check('[LB3] the New label shown in its own corner and no own label is no settings column', ItemLabels::settingsPosted(newShown: '1', newCorner: '', own: ['name' => [$defaultKey => '']], from: '', to: ''), null);
$posted = ItemLabels::settingsPosted(newShown: '0', newCorner: 'bottom-right', own: ['name' => [$defaultKey => ' Only here '], 'bg' => '#333', 'list_show' => '1', 'item_show' => '1', 'corner' => 'bottom-left'], from: '2026-12-01 00:00:00', to: '2026-12-31 23:59:59');
$decoded = ItemLabels::settingsOf($posted);
check('[LB3] the New label hidden and moved, and the own label with its settings and period, in the column',
    [$decoded['new'], $decoded['own']['name'], $decoded['own']['corner'], $decoded['own']['publish_down'], $decoded['own']['bg']],
    [['shown' => 0, 'corner' => 'bottom-right'], [$defaultKey => 'Only here'], 'bottom-left', '2026-12-31 23:59:59', '#333']);
check('[LB3] a column of nothing reads as the shop shows: the New label shown, no own label', ItemLabels::settingsOf(null), ['new' => ['shown' => 1, 'corner' => ''], 'own' => null]);
check('[LB3] an own label whose period ends before it starts is refused, one in order is not',
    [ItemLabels::refusals(ItemLabels::settingsPosted('1', '', ['name' => [$defaultKey => 'x']], '2026-12-31 00:00:00', '2026-12-01 00:00:00')), ItemLabels::refusals($posted)],
    [[Text::_('COM_ALFA_ITEM_LABEL_PERIOD_END_BEFORE_START')], []]);

// The shop's own category the fixtures live in.
$category = (int) $db->setQuery("SELECT c.id FROM `{$p}alfa_categories` c WHERE c.state = 1 ORDER BY c.id", 0, 1)->loadResult();

if ($category <= 0) {
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
$labelNamed = static function (string $base) use ($tags): array {
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

$saveLabel = static function (array $data) use ($app, $factory, $labelNamed, $run): int {
    $data += ['id' => 0, 'state' => 1, 'bg' => '', 'color' => '', 'class' => '', 'list_show' => 1, 'list_slot' => 'image', 'item_show' => 1, 'item_slot' => 'image', 'corner' => 'top-left', 'filterable' => 1]
        + $labelNamed('LB disposable ' . ($data['tag'] ?? '') . ' ' . $run);
    unset($data['tag']);
    $app->getInput()->post->set('jform', $data);
    $model = $factory->createModel('Label', 'Administrator', ['ignore_request' => true]);

    if (!$model->save($data)) {
        throw new \RuntimeException('label save refused: ' . $model->getError());
    }

    return (int) $model->getState('label.id');
};

$product = static function (string $tag, array $labels = [], array $settings = []) use ($saveItem, $named, $run, $category): int {
    return $saveItem([
        'id' => 0, 'state' => 1, 'sku' => 'LB-' . strtoupper($run) . '-' . $tag,
        'id_category_default' => $category, 'categories' => [$category], 'manufacturers' => [],
        'labels' => $labels,
        'new_label_shown' => $settings['new_shown'] ?? '1', 'new_label_corner' => $settings['new_corner'] ?? '',
        'own_label' => $settings['own'] ?? [], 'own_label_from' => $settings['from'] ?? '', 'own_label_to' => $settings['to'] ?? '',
        'prices' => [[
            'id' => 0, 'value' => '100.00000000', 'override_value' => '', 'override_type' => 'start', 'price_status' => '1',
            'country_id' => 0, 'usergroup_id' => 0, 'user_id' => 0, 'currency_id' => 0, 'quantity_start' => '', 'quantity_end' => '',
            'publish_up' => '', 'publish_down' => '',
        ]],
    ] + $named('LB disposable ' . $tag . ' ' . $run, 'lb-disposable-' . $tag . '-' . $run));
};

/** A card or page item as the storefront models hand it to Labels: the row's columns and an empty surface map. */
$asItem = static function (int $id) use ($db, $p): object {
    $row = $db->setQuery("SELECT id, created, publish_up, label_settings FROM `{$p}alfa_items` WHERE id = {$id}")->loadObject();
    $row->surfaces = new SurfaceMap([]);
    $row->price = null;

    return $row;
};
$keys = static fn (array $labels): array => array_map(static fn (Label $label): string => $label->key . '@' . $label->corner->value, $labels);
$own = ['name' => [$defaultKey => 'Only here'], 'bg' => '#333', 'color' => '#fff', 'class' => '', 'list_show' => '1', 'list_slot' => 'image', 'item_show' => '1', 'item_slot' => 'image', 'corner' => 'bottom-right'];

$params = ComponentHelper::getParams('com_alfa');
$kept = ['days' => $params->get(NewProducts::PARAM_DAYS), 'from' => $params->get(NewProducts::PARAM_FROM), 'new' => $params->get(Labels::PARAM_NEW_LABEL)];
$restore = static function () use ($params, $kept): void {
    $params->set(NewProducts::PARAM_DAYS, $kept['days']);
    $params->set(NewProducts::PARAM_FROM, $kept['from']);
    $params->set(Labels::PARAM_NEW_LABEL, $kept['new']);
    Labels::forget();
};
$params->set(Labels::PARAM_NEW_LABEL, (object) ['name' => (object) [$defaultKey => 'Fresh'], 'bg' => '#1d4ed8', 'color' => '', 'class' => '', 'list_show' => 1, 'list_slot' => 'image', 'item_show' => 1, 'item_slot' => 'title', 'corner' => 'top-right']);
$params->set(NewProducts::PARAM_DAYS, 0);
$params->set(NewProducts::PARAM_FROM, NewProducts::FROM_CREATED);
Labels::forget();

echo "\nthe New label\n";
check('[LB4] no New label while the shop counts no product as new', Labels::newLabel(), null);
$params->set(NewProducts::PARAM_DAYS, 30);
Labels::forget();
check('[LB4] the New label as the Options set it', [Labels::newLabel()?->name, Labels::newLabel()?->bg, Labels::newLabel()?->corner->value, Labels::newLabel()?->itemSlot], ['Fresh', '#1d4ed8', 'top-right', 'title']);
$params->set(Labels::PARAM_NEW_LABEL, null);
Labels::forget();
check('[LB4] until the Options are saved once, the New label says New', Labels::newLabel()?->name, Labels::NEW_NAME);
$params->set(Labels::PARAM_NEW_LABEL, (object) ['name' => (object) [$defaultKey => 'Fresh'], 'bg' => '#1d4ed8', 'color' => '', 'class' => '', 'list_show' => 1, 'list_slot' => 'image', 'item_show' => 1, 'item_slot' => 'title', 'corner' => 'top-right']);
Labels::forget();

$result = ['error' => 'not run'];
$db->transactionStart();

try {
    // The shop's labels of this run: Black Friday, filterable; Bestseller, cards only, in the bottom left, not a filter;
    // Soon, unpublished.
    $lBf = $saveLabel(['tag' => 'bf']);
    $lHot = $saveLabel(['tag' => 'hot', 'corner' => 'bottom-left', 'item_show' => 0, 'filterable' => 0]);
    $lSoon = $saveLabel(['tag' => 'soon', 'state' => 0]);
    Labels::forget();

    $mine = [$lBf, $lHot, $lSoon];
    $shopLabels = [
        'published' => array_values(array_intersect(array_keys(Labels::predefined()), $mine)),
        'offered' => array_values(array_intersect(array_keys(Labels::all()), $mine)),
        'filterable' => array_values(array_intersect(array_keys(Labels::filterable()), $mine)),
        'names' => [Labels::predefined()[$lBf]->name ?? null, Labels::all()[$lSoon]->state ?? null],
        'hot' => [Labels::predefined()[$lHot]->corner->value ?? null, Labels::predefined()[$lHot]->itemShow ?? null],
    ];

    // A new product ticking Black Friday in the top right and Soon, with its own label inside its dates.
    $pTicked = $product('ticked', [$lBf => ['on' => '1', 'corner' => 'top-right'], $lSoon => ['on' => '1', 'corner' => '']], ['own' => $own, 'from' => $shift(-86400), 'to' => $shift(86400)]);
    // Its own label's period over; the New label hidden. The dates post as the operator's local time and are stored
    // as UTC, so each margin is a day, wider than any zone.
    $pOver = $product('over', [], ['new_shown' => '0', 'own' => $own, 'to' => $shift(-86400)]);
    // Its own label not yet begun; nothing ticked; made sixty days ago.
    $pLater = $product('later', [], ['own' => $own, 'from' => $shift(86400)]);
    $db->setQuery("UPDATE `{$p}alfa_items` SET created = " . $db->quote($shift(-60 * 86400)) . " WHERE id = {$pLater}")->execute();
    // Bestseller ticked, in its own corner (bottom left), shown on cards only.
    $pHot = $product('hot', [$lHot => ['on' => '1', 'corner' => '']]);

    echo "fixture ids: labels={$lBf},{$lHot},{$lSoon} items={$pTicked},{$pOver},{$pLater},{$pHot} (inside the rolled-back transaction)\n";

    $rows = ItemLabels::forItems(db: $db, itemIds: [$pTicked, $pOver, $pLater, $pHot]);
    $stored = [];

    foreach ([$pTicked, $pOver, $pLater, $pHot] as $id) {
        $stored[] = $rows[$id] ?? [];
        $stored[] = $db->setQuery("SELECT label_settings FROM `{$p}alfa_items` WHERE id = {$id}")->loadResult();
    }

    // The admin form's values from the rows, and the REST item's shape of them.
    $form = $factory->createModel('Item', 'Administrator', ['ignore_request' => true])->getItem($pTicked);
    $restTicks = ItemLabels::posted(labels: $form->labels);
    $formValues = [
        'labels' => $form->labels,
        'rest' => [array_keys($restTicks), array_filter($restTicks)],
        'new' => [$form->new_label_shown, $form->new_label_corner],
        'own' => [$form->own_label['name'] ?? null, $form->own_label['corner'] ?? null, \strlen((string) $form->own_label_from)],
    ];

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
    Labels::predefined();
    $monitor = new DebugMonitor();
    $db->setMonitor($monitor);
    Labels::warm([$pTicked, $pOver, $pHot]);
    $three = [\count(Labels::of($asItem($pTicked), true, $now)), \count(Labels::of($asItem($pOver), true, $now)), \count(Labels::of($asItem($pHot), true, $now))];
    $db->setMonitor(null);
    $labelQueries = \count(array_filter($monitor->getLogs(), static fn (string $sql): bool => str_contains($sql, 'alfa_item_labels')));

    // The catalogue list filtered by a label, and the labels facet: the storefront listing model over this run's
    // products alone (filter.id), nothing drawn, no media, no prices.
    $listed = static function (array $state) use ($factory, $pTicked, $pOver, $pLater, $pHot): array {
        $model = $factory->createModel('Items', 'Site', ['ignore_request' => true]);
        $model->setState('filter.id', [$pTicked, $pOver, $pLater, $pHot]);
        $model->setState('plugin.layouts', false);
        $model->setState('list.media', false);
        $model->setState('list.price', false);
        $model->setState('list.limit', 0);
        $model->setState('list.start', 0);

        foreach ($state as $key => $value) {
            $model->setState($key, $value);
        }

        return $model;
    };
    $ids = static fn (array $items): array => array_values(array_map(static fn (object $item): int => (int) $item->id, $items));
    $filtered = ['error' => null];

    try {
        Labels::forget();
        $byBf = $listed(['filter.label' => [$lBf]]);
        $filtered['bf'] = $ids($byBf->getItems() ?: []);
        $filtered['hot'] = $ids($listed(['filter.label' => [$lHot]])->getItems() ?: []);
        $filtered['either'] = $ids($listed(['filter.label' => [$lBf, $lHot]])->getItems() ?: []);
        $facet = $listed([])->getAvailableLabels(withCounts: true);
        $filtered['facet'] = array_map(static fn (array $row): array => [$row['name'], $row['count'] ?? null], array_intersect_key($facet, array_flip($mine)));
        $filtered['new'] = $ids($listed(['filter.new' => 1])->getItems() ?: []);
    } catch (\Throwable $e) {
        $filtered['error'] = \get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    $result = ['shop' => $shopLabels, 'stored' => $stored, 'form' => $formValues, 'shown' => $shown, 'noDays' => $noDays, 'later' => $later, 'image' => $image, 'page' => $pageSlots, 'three' => $three, 'queries' => $labelQueries, 'filtered' => $filtered,
        'ids' => ['bf' => $lBf, 'hot' => $lHot, 'soon' => $lSoon, 'ticked' => $pTicked, 'over' => $pOver, 'later' => $pLater, 'phot' => $pHot]];
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
    ['bf' => $lBf, 'hot' => $lHot, 'soon' => $lSoon, 'ticked' => $pTicked, 'over' => $pOver, 'later' => $pLater, 'phot' => $pHot] = $result['ids'];
    $bf = (string) $lBf;
    $hot = (string) $lHot;

    echo "\nthe shop's labels\n";
    check('[LB4] ★ the published labels in their order, the unpublished one offered to tick but not shown, only Black Friday offered as a filter',
        [$result['shop']['published'], $result['shop']['offered'], $result['shop']['filterable']], [[$lBf, $lHot], [$lBf, $lHot, $lSoon], [$lBf]]);
    check('[LB4] a label reads its name, its state and its settings', [$result['shop']['names'], $result['shop']['hot']], [['LB disposable bf ' . $run, 0], ['bottom-left', false]]);

    echo "\nthe rows a product keeps\n";
    check('[LB5] ★ the ticked product keeps Black Friday in the top right and Soon in its own corner, and its own label in the column',
        [$result['stored'][0], ItemLabels::settingsOf($result['stored'][1])['own']['name'] ?? null, ItemLabels::settingsOf($result['stored'][1])['new']],
        [[$lBf => 'top-right', $lSoon => null], [$defaultKey => 'Only here'], ['shown' => 1, 'corner' => '']]);
    check('[LB5] the product hiding the New label keeps that in the column and no tick', [$result['stored'][2], ItemLabels::settingsOf($result['stored'][3])['new']['shown']], [[], 0]);
    check('[LB5] the product ticking nothing keeps only its own label; Bestseller ticked in the label\'s own corner keeps no corner',
        [$result['stored'][4], ItemLabels::settingsOf($result['stored'][5])['own']['corner'] ?? null, $result['stored'][6], $result['stored'][7]], [[], 'bottom-right', [$lHot => null], null]);
    check('[LB7] ★ the admin form reads the ticks, the corners, the New choice and the own label; the REST item states ids and the corners chosen',
        $result['form'], ['labels' => [$lBf => ['on' => 1, 'corner' => 'top-right'], $lSoon => ['on' => 1, 'corner' => '']], 'rest' => [[$lBf, $lSoon], [$lBf => 'top-right']], 'new' => [1, ''], 'own' => [[$defaultKey => 'Only here'], 'bottom-right', 19]]);

    echo "\nwhat a product shows\n";
    check('[LB5] ★ the new product shows the New label, Black Friday in the product\'s corner and its own label, never the unpublished label, on the card and on the page',
        $result['shown']['ticked'], ['card' => ['new@top-right', $bf . '@top-right', 'custom@bottom-right'], 'page' => ['new@top-right', $bf . '@top-right', 'custom@bottom-right']]);
    check('[LB5] ★ the product whose own label is over and hides the New label shows nothing', $result['shown']['over'], ['card' => [], 'page' => []]);
    check('[LB5] ★ the product made sixty days ago is not new, and its own label has not begun', $result['shown']['later'], ['card' => [], 'page' => []]);
    check('[LB5] Bestseller shows on the card in its own corner and not on the page', $result['shown']['hot'], ['card' => ['new@top-right', $hot . '@bottom-left'], 'page' => ['new@top-right']]);
    check('[LB5] ★ with no days the New label goes; three days on, the own label is over', [$result['noDays'], $result['later']], [[$bf . '@top-right', 'custom@bottom-right'], ['new@top-right', $bf . '@top-right']]);

    echo "\nthe slots\n";
    check('[LB6] ★ the card\'s image slot holds one stack per corner: the New label and Black Friday together in the top right, the own label in the bottom right',
        $result['image'], [['item.labels', 'top-right', ['new@top-right', $bf . '@top-right']], ['item.labels', 'bottom-right', ['custom@bottom-right']]]);
    check('[LB6] ★ on the page the New label stands in the title slot, in a row without a corner, and the others in the image slot',
        $result['page'], ['title' => [['', ['new@top-right']]], 'image' => [['top-right', [$bf . '@top-right']], ['bottom-right', ['custom@bottom-right']]]]);

    echo "\nwhat the list pays\n";
    check('[LB7] ★ the ticks of three products are read in one query, and each product then answers without one', [$result['queries'], $result['three']], [1, [3, 0, 2]]);

    echo "\nthe filter\n";

    if ($result['filtered']['error'] !== null) {
        $skip++;
        echo "  skip [LB8] the storefront listing model could not run in this process: {$result['filtered']['error']}\n";
    } else {
        check('[LB8] ★ filtered by Black Friday the list holds the product that ticks it and not the others', $result['filtered']['bf'], [$pTicked]);
        $either = $result['filtered']['either'];
        sort($either);
        check('[LB8] ★ filtered by Bestseller, the product that ticks it; by either, both', [$result['filtered']['hot'], $either], [[$pHot], [$pTicked, $pHot]]);
        check('[LB8] ★ the labels facet offers Black Friday with one product and not the label that filters nothing', $result['filtered']['facet'], [$lBf => ['LB disposable bf ' . $run, 1]]);
        check('[LB8] ★ the new-arrivals filter keeps the products made today and drops the one made sixty days ago', [\in_array($pTicked, $result['filtered']['new'], true), \in_array($pHot, $result['filtered']['new'], true), \in_array($pLater, $result['filtered']['new'], true)], [true, true, false]);
    }
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
