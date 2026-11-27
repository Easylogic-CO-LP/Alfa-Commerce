<?php

/**
 * A FIELD IS SHOWN WHERE ITS PLACEMENT SAYS, AND A TEMPLATE CAN PRINT IT ANYWHERE.
 *
 * An item field definition names where the product page shows it (#__alfa_item_fields.placement, FieldPlacement):
 * the buy box as a specification (the default), the Specifications section, a section of its own, under the title,
 * under the price, beside add to cart, or nowhere by itself. The field's plugin asks the item view event for the slot
 * the placement names ($event->placement()); the product's fields reach the template by machine name
 * ($item->fields, ItemField), each with its label, the values the item carries and render(); the page sections
 * (PageSections) carry the Specifications table and each field placed in a section of its own.
 *
 *   [FP1] ★ the item's fields are keyed by machine name; each answers its label, its value and its placement.
 *   [FP2] ★ each field's surface lands in the slot its placement names, and in no other.
 *   [FP3] ★ render() draws the value's name as the placement would: a table row for the Specifications section, the
 *         values alone for a section of its own, label and values elsewhere; a field not shown automatically still
 *         renders for an override.
 *   [FP4] ★ the page sections carry the Specifications table and the field's own section, keyed by its name.
 *   [T1]  every table of the schema holds exactly the rows it held, read on a second connection.
 *
 * Fixtures: one category, one published product of this run's own with a price, and seven informational choice
 * fields, one per placement, each with one value the product carries as a specification; all written inside the
 * fixture transaction and rolled back. Nothing survives the run and no shop stock is read or moved. The fields and
 * their values are made before the product is read: the catalogue memoises what a product carries per request.
 *
 *   cd <docroot> && php administrator/components/com_alfa/tests/item/field-placement-suite.php
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

use Alfa\Component\Alfa\Administrator\Event\ItemFields\FieldPlacement;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemSurface;
use Alfa\Component\Alfa\Site\Helper\ItemField;
use Alfa\Component\Alfa\Site\Helper\PageSections;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseFactory;
use Joomla\Session\SessionInterface;

require_once JPATH_ADMINISTRATOR . '/components/com_alfa/tests/harness/CliSessionRow.php';
require_once JPATH_ADMINISTRATOR . '/components/com_alfa/tests/harness/MailOff.php';
require_once JPATH_ADMINISTRATOR . '/components/com_alfa/tests/harness/InventoryFixtures.php';
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
$app->getLanguage()->load('com_alfa', JPATH_SITE);
$app->createExtensionNamespaceMap();
MailOff::engage();

\defined('JPATH_COMPONENT') || \define('JPATH_COMPONENT', JPATH_ADMINISTRATOR . '/components/com_alfa');
\defined('JPATH_COMPONENT_ADMINISTRATOR') || \define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_ADMINISTRATOR . '/components/com_alfa');
\defined('JPATH_COMPONENT_SITE') || \define('JPATH_COMPONENT_SITE', JPATH_SITE . '/components/com_alfa');

// render() draws through the field plugins' layouts, which ask an administrator application for its template; a
// suite runs from the site docroot, where the admin's template is out of reach, so an installed template is pinned,
// as the suites that compose e-mails do.
$installedTemplates = array_values(array_filter(glob(JPATH_THEMES . '/*') ?: [], static fn (string $dir): bool => is_file($dir . '/index.php')));

if ($installedTemplates !== []) {
    (new \ReflectionProperty(\Joomla\CMS\Application\CMSApplication::class, 'template'))
        ->setValue($app, (object) ['template' => basename($installedTemplates[0]), 'params' => new \Joomla\Registry\Registry(), 'parent' => '']);
    $app->set('themeInherits', '');
}

$app->loadDocument();

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

echo "A field is shown where its placement says, and a template can print it anywhere\n", str_repeat('=', 70), "\n";

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
$run = bin2hex(random_bytes(3));
$fx = new InventoryFixtures(db: $db, token: 'FP' . $run);
$fx->begin();

try {
    $category = $fx->category(state: 1);
    $itemId = $fx->item(columns: ['state' => 1, 'manage_stock' => 0, 'id_category_default' => $category]);
    $fx->price(itemId: $itemId, combinationId: null, value: 10.0);

    // One informational field per placement, each with one value the product carries as a specification.
    $fields = [];

    foreach (FieldPlacement::cases() as $placement) {
        $made = $fx->choiceField(values: ['Value ' . $placement->value], categoryId: $category);
        $fieldId = (int) $made['field_id'];
        $valueId = (int) reset($made['values']);

        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__alfa_item_fields')
            . ' SET ' . $db->quoteName('informational') . ' = 1, ' . $db->quoteName('placement') . ' = ' . $db->quote($placement->value)
            . ' WHERE ' . $db->quoteName('id') . ' = ' . $fieldId,
        )->execute();
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__alfa_item_info_values') . ' (' . implode(', ', $db->quoteName(['item_id', 'value_id', 'ordering'])) . ')'
            . ' VALUES (' . $itemId . ', ' . $valueId . ', 0)',
        )->execute();

        $name = (string) $db->setQuery('SELECT ' . $db->quoteName('field_name') . ' FROM ' . $db->quoteName('#__alfa_item_fields') . ' WHERE ' . $db->quoteName('id') . ' = ' . $fieldId)->loadResult();
        $fields[$placement->value] = ['id' => $fieldId, 'name' => $name, 'value' => $valueId];
    }

    check('fixture: seven fields of this run were made, one per placement', \count($fields), \count(FieldPlacement::cases()));

    $factory = $app->bootComponent('com_alfa')->getMVCFactory();
    $item = $factory->createModel('Item', 'Site', ['ignore_request' => true])->getItem($itemId);

    // [FP1] the fields by machine name, each with its label, value and placement.
    $keyed = true;
    $labelled = true;
    $valued = true;
    $placed = [];

    foreach ($fields as $placement => $field) {
        $entry = $item->fields[$field['name']] ?? null;
        $keyed = $keyed && $entry instanceof ItemField && (int) $entry->id === $field['id'];
        $labelled = $labelled && $entry !== null && $entry->label !== '' && $entry->label === (string) ($entry->display_name ?? '');
        $valued = $valued && $entry !== null && array_map(static fn (object $value): int => (int) $value->id, $entry->value) === [$field['value']];
        $placed[$placement] = $entry?->placement;
    }

    check('[FP1] ★ the item\'s fields are keyed by machine name, each the field itself', $keyed, true);
    check('[FP1] ★ each answers its label, the field\'s name in the page\'s language', $labelled, true);
    check('[FP1] ★ each answers its value, the specification value the product carries', $valued, true);
    check('[FP1] ★ each answers the placement its definition stores', $placed, array_combine(array_keys($fields), array_keys($fields)));

    // [FP2] each surface in the slot its placement names, and in no other.
    $landed = [];

    foreach ($fields as $placement => $field) {
        $slots = [];

        foreach ($item->surfaces as $slot => $descriptors) {
            foreach ($descriptors as $descriptor) {
                if ((int) ($descriptor['fieldId'] ?? 0) === $field['id']) {
                    $slots[] = $slot;
                }
            }
        }

        $landed[$placement] = $slots;
    }

    $expected = [];

    foreach (FieldPlacement::cases() as $placement) {
        $expected[$placement->value] = [$placement->surface()->value];
    }

    check('[FP2] ★ each field\'s surface lands in the slot its placement names, and in no other', $landed, $expected);
    check('[FP2] the manual slot is one no template prints, and the field is there for an override', isset($item->surfaces[ItemSurface::Manual->value][0]['fieldId']), true);

    // [FP3] render() draws the value's name as the placement would.
    $drawn = [];

    foreach ($fields as $placement => $field) {
        $html = $item->fields[$field['name']]->render();
        $drawn[$placement] = [
            'value' => str_contains($html, 'Value ' . $placement),
            'row' => str_contains($html, '<th scope="row"'),
            'label' => str_contains($html, 'spec-field-label'),
        ];
    }

    check('[FP3] ★ every field renders its value\'s name, the field not shown automatically included', array_column($drawn, 'value'), array_fill(0, \count($fields), true));
    check('[FP3] ★ the Specifications section draws a table row, no other placement does', array_column($drawn, 'row'), array_map(static fn (string $placement): bool => $placement === FieldPlacement::SpecificationsSection->value, array_keys($drawn)));
    check('[FP3] ★ a section of its own draws the values alone; every other placement draws the label too', array_column($drawn, 'label'), array_map(static fn (string $placement): bool => $placement !== FieldPlacement::Section->value, array_keys($drawn)));

    // [FP4] the page sections.
    $sections = PageSections::collect($item, ComponentHelper::getParams('com_alfa'));
    $byKey = array_column($sections, null, 'key');
    $ownKey = PageSections::key($fields[FieldPlacement::Section->value]['name']);

    check('[FP4] ★ the page sections carry the Specifications table', str_contains((string) ($byKey[PageSections::SPECIFICATIONS]['body'] ?? ''), '<table class="alfa-spec-table">'), true);
    check('[FP4] ★ and the field\'s own section, keyed by its name and titled with its label', [isset($byKey[$ownKey]), $byKey[$ownKey]['title'] ?? null], [true, $item->fields[$fields[FieldPlacement::Section->value]['name']]->label]);
    check('[FP4] the specifications come right after the description, the field\'s section after them', array_search(PageSections::SPECIFICATIONS, array_keys($byKey), true) < array_search($ownKey, array_keys($byKey), true), true);
} finally {
    $fx->rollback();
}

$after = $census();
$moved = [];

foreach ($before as $key => $count) {
    if (($after[$key] ?? null) !== $count) {
        $moved[$key] = [$count, $after[$key] ?? null];
    }
}

check('[T1] every table holds exactly the rows it held (' . \count($before) . ' read on a second connection)', $moved, []);

echo "\nfield placement: {$pass} passed, {$fail} failed\n";

exit($fail === 0 ? 0 : 1);
