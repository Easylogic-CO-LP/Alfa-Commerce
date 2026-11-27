<?php

/**
 * THE LAST ENTRY OF A SCOPE PART IS NEVER REMOVED SILENTLY.
 *
 * An empty part of a scope means everything is allowed, so what is deleted is refused when it is the last entry of a
 * part somewhere: a discount's, a tax's and a cart rule's (as before), and a payment or a shipping method's categories,
 * manufacturers and places. A category on a shipping method's categories not delivered refuses too. Joomla's customer
 * groups are refused through the users event the sync plugin answers; an account's delete cannot be refused there, so
 * its rules are named to staff. A form saved unchanged never turns a part whose entity was deleted into every one: the
 * pickers keep the deleted entity on its part, marked.
 *
 *   [SG1] ★ a category that is the last one a shipping method serves is not deleted: the refusal names the method,
 *         the category and the method's row stay; the REST model answers the same refusal as text.
 *   [SG2] ★ a category a method serves beside another is deleted, its row goes and the other stays.
 *   [SG3] ★ a delete of several is judged as a whole: a method's only two categories are refused together.
 *   [SG4] ★ a manufacturer that is the last one of a payment method is not deleted.
 *   [SG5] ★ a place that is the last one of a shipping method is not deleted.
 *   [SG6] ★ a category on a shipping method's categories not delivered is not deleted.
 *   [SG7] ★ a customer group that is the last one of a discount and of a payment method, and that a price row is
 *         for, refuses its delete: by the guard, and by the sync plugin's answer to Joomla's event, which names
 *         them and counts the price rows. A group nothing names alone is not refused.
 *   [SG8] ★ the rules and methods that name a deleted account are named (what its notification says).
 *   [SG9] ★ a discount whose customer group and customer no longer exist offers both to its form, marked as
 *         deleted; saved with what its form posts, the two parts are stored as they were, never as every group or
 *         every customer; emptied on purpose, a part is stored as every one.
 *   [T1]  every table of the schema holds exactly the rows it held, read on a second connection.
 *
 * Fixtures: categories, manufacturers, a place, payment and shipping methods, a customer group, a product with a
 * price row and discounts of this run's own, written inside the fixture transaction and rolled back. The group and
 * the account that "no longer exist" are ids above every existing one, never rows of the shop.
 *
 *   cd <docroot> && php administrator/components/com_alfa/tests/catalog/scope-guard-suite.php
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

use Alfa\Component\Alfa\Administrator\Field\ScopeusergroupsField;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Catalog\DeleteRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Catalog\RuleScopeGuard;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ScopeDeleteRefusedException;
use Joomla\CMS\Event\User\UserGroupBeforeDeleteEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Table\Usergroup;
use Joomla\CMS\User\User;
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

echo "The last entry of a scope part is never removed silently\n", str_repeat('=', 70), "\n";

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
$fx = new InventoryFixtures(db: $db, token: 'SG' . $run);
$fx->begin();

try {
    $factory = $app->bootComponent('com_alfa')->getMVCFactory();
    $now = gmdate('Y-m-d H:i:s');
    $operatorId = (int) $db->setQuery(
        "SELECT m.user_id FROM `{$p}user_usergroup_map` m INNER JOIN `{$p}users` u ON u.id = m.user_id AND u.block = 0 WHERE m.group_id = 8 ORDER BY m.user_id LIMIT 1",
    )->loadResult();
    check('fixture: an operator to delete and save as', $operatorId > 0, true);
    $app->loadIdentity(new User($operatorId));

    /** A row of this run's own, by SQL, and its name in every language table that has one. */
    $named = static function (string $base, string $langKey, int $id, string $name) use ($db, $p): void {
        foreach ($db->setQuery("SHOW TABLES LIKE '{$p}{$base}\\_%'")->loadColumn() as $table) {
            if (!preg_match('/^' . preg_quote($p . $base, '/') . '_[a-z]{2,3}_[a-z]{2,3}$/', $table)) {
                continue;
            }

            $columns = $db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName($table))->loadColumn();

            if (\in_array($langKey, $columns, true) && \in_array('name', $columns, true)) {
                $row = (object) [$langKey => $id, 'name' => $name];
                $db->insertObject($table, $row);
            }
        }
    };
    $category = static function (string $label) use ($db, $now, $named, $run): int {
        $row = (object) ['parent_id' => 0, 'state' => 1, 'modified' => $now];
        $db->insertObject('#__alfa_categories', $row, 'id');
        $named('alfa_categories', 'id_category', (int) $row->id, 'ALFA SG category ' . $label . ' ' . $run);

        return (int) $row->id;
    };
    $manufacturer = static function (string $label) use ($db, $now, $named, $run): int {
        $row = (object) ['state' => 1, 'modified' => $now];
        $db->insertObject('#__alfa_manufacturers', $row, 'id');
        $named('alfa_manufacturers', 'id_manufacturer', (int) $row->id, 'ALFA SG manufacturer ' . $label . ' ' . $run);

        return (int) $row->id;
    };
    $place = static function (string $label) use ($db, $run): int {
        $row = (object) ['name' => 'ALFA SG place ' . $label . ' ' . $run, 'code2' => 'ZZ', 'code3' => 'ZZZ', 'parent_id' => 0, 'state' => 1];
        $db->insertObject('#__alfa_places', $row, 'id');

        return (int) $row->id;
    };

    /** A payment or a shipping method of this run's own, with the scope rows given ([part => ids]); every other part is the "every one" row. */
    $method = static function (string $kind, string $label, array $scope) use ($db, $p, $named, $run): int {
        $row = (object) ['type' => 'standard', 'color' => '', 'bg_color' => '', 'params' => '{}', 'show_on_product' => 1, 'ordering' => 0, 'created_by' => 0, 'modified_by' => 0, 'state' => 1];
        $db->insertObject('#__alfa_' . $kind . 's', $row, 'id');
        $id = (int) $row->id;

        foreach (['categories' => 'category_id', 'manufacturers' => 'manufacturer_id', 'places' => 'place_id', 'usergroups' => 'usergroup_id', 'users' => 'user_id'] as $part => $column) {
            foreach ($scope[$part] ?? [0] as $value) {
                $db->setQuery("INSERT INTO `{$p}alfa_{$kind}_{$part}` ({$kind}_id, {$column}) VALUES ({$id}, " . (int) $value . ')')->execute();
            }
        }

        foreach ($scope['excluded_categories'] ?? [] as $value) {
            $db->setQuery("INSERT INTO `{$p}alfa_shipment_excluded_categories` (shipment_id, category_id) VALUES ({$id}, " . (int) $value . ')')->execute();
        }

        $named('alfa_' . $kind . 's', 'id_' . $kind, $id, 'ALFA SG ' . $kind . ' ' . $label . ' ' . $run);

        return $id;
    };
    $rowsOf = static fn (string $table, string $key, int $id, string $column): array => array_map('intval', $db->setQuery(
        "SELECT {$column} FROM `{$p}{$table}` WHERE {$key} = {$id} ORDER BY {$column}",
    )->loadColumn() ?: []);
    $exists = static fn (string $table, int $id): bool => (int) $db->setQuery("SELECT COUNT(*) FROM `{$p}{$table}` WHERE id = {$id}")->loadResult() === 1;

    /** A delete through a model: whether it was done, and the refusal as the REST answer carries it. */
    $delete = static function (string $name, array $ids, string $side = 'Administrator') use ($app, $factory): array {
        $model = $factory->createModel($name, $side, ['ignore_request' => true]);
        $pks = $ids;
        $done = (bool) $model->delete($pks);
        $error = $model->getError(null, false);
        $app->getMessageQueue(true);

        return [
            'done' => $done,
            'scope' => $error instanceof ScopeDeleteRefusedException,
            'refusal' => $error instanceof DeleteRefusedException ? $error->plain : (\is_string($error) ? $error : ''),
        ];
    };

    // [SG1] the last category of a shipping method.
    $catLast = $category('last');
    $shipOne = $method('shipment', 'one category', ['categories' => [$catLast]]);
    $shipName = 'ALFA SG shipment one category ' . $run;
    $one = $delete('Category', [$catLast]);
    check('[SG1] ★ the last category a shipping method serves is not deleted', [$one['done'], $one['scope'], $exists('alfa_categories', $catLast)], [false, true, true]);
    check('[SG1] ★ the refusal is the methods\' sentence and names the method', [str_contains($one['refusal'], $shipName), str_contains($one['refusal'], sprintf('(#%d)', $shipOne))], [true, true]);
    check('[SG1] ★ the method keeps its category', $rowsOf('alfa_shipment_categories', 'shipment_id', $shipOne, 'category_id'), [$catLast]);
    $rest = $delete('Category', [$catLast], 'Api');
    check('[SG1] ★ the REST model refuses it with the same sentence, as text', [$rest['done'], $rest['refusal'] === $one['refusal'], str_contains($rest['refusal'], '<')], [false, true, false]);

    // [SG2] one of two.
    $catA = $category('a');
    $catB = $category('b');
    $shipTwo = $method('shipment', 'two categories', ['categories' => [$catA, $catB]]);
    $two = $delete('Category', [$catA]);
    check('[SG2] ★ a category a method serves beside another is deleted', [$two['done'], $two['refusal'], $exists('alfa_categories', $catA)], [true, '', false]);
    check('[SG2] ★ its row goes and the other stays', $rowsOf('alfa_shipment_categories', 'shipment_id', $shipTwo, 'category_id'), [$catB]);

    // [SG3] the whole selection.
    $catC = $category('c');
    $catD = $category('d');
    $payTwo = $method('payment', 'two categories', ['categories' => [$catC, $catD]]);
    $together = $delete('Category', [$catC, $catD]);
    check('[SG3] ★ a payment method\'s only two categories are refused together', [$together['done'], $together['scope'], str_contains($together['refusal'], 'ALFA SG payment two categories ' . $run)], [false, true, true]);
    check('[SG3] ★ both stay, with their rows', [$exists('alfa_categories', $catC), $exists('alfa_categories', $catD), $rowsOf('alfa_payment_categories', 'payment_id', $payTwo, 'category_id')], [true, true, [$catC, $catD]]);

    // [SG4] the last manufacturer of a payment method.
    $maker = $manufacturer('last');
    $payMaker = $method('payment', 'one manufacturer', ['manufacturers' => [$maker]]);
    $made = $delete('Manufacturer', [$maker]);
    check('[SG4] ★ the last manufacturer of a payment method is not deleted, and the refusal names the method',
        [$made['done'], $made['scope'], str_contains($made['refusal'], 'ALFA SG payment one manufacturer ' . $run), $rowsOf('alfa_payment_manufacturers', 'payment_id', $payMaker, 'manufacturer_id')], [false, true, true, [$maker]]);

    // [SG5] the last place of a shipping method.
    $where = $place('last');
    $shipPlace = $method('shipment', 'one place', ['places' => [$where]]);
    $placed = $delete('Place', [$where]);
    check('[SG5] ★ the last place of a shipping method is not deleted, and the refusal names the method',
        [$placed['done'], $placed['scope'], str_contains($placed['refusal'], 'ALFA SG shipment one place ' . $run), $rowsOf('alfa_shipment_places', 'shipment_id', $shipPlace, 'place_id')], [false, true, true, [$where]]);

    // [SG6] a category not delivered.
    $catNot = $category('not delivered');
    $shipNot = $method('shipment', 'not delivered', ['excluded_categories' => [$catNot]]);
    $notDelivered = $delete('Category', [$catNot]);
    check('[SG6] ★ a category on a shipping method\'s categories not delivered is not deleted, and the refusal names the method',
        [$notDelivered['done'], $notDelivered['scope'], str_contains($notDelivered['refusal'], 'ALFA SG shipment not delivered ' . $run), $rowsOf('alfa_shipment_excluded_categories', 'shipment_id', $shipNot, 'category_id')], [false, true, true, [$catNot]]);

    // [SG7] a customer group of this run's own: the last group of a discount and of a payment method, with a price row.
    $groupRow = (object) ['parent_id' => 1, 'lft' => 0, 'rgt' => 0, 'title' => 'ALFA SG group ' . $run];
    $db->insertObject('#__usergroups', $groupRow, 'id');
    $group = (int) $groupRow->id;
    $free = (object) ['parent_id' => 1, 'lft' => 0, 'rgt' => 0, 'title' => 'ALFA SG free group ' . $run];
    $db->insertObject('#__usergroups', $free, 'id');
    $freeGroup = (int) $free->id;

    $tags = $db->setQuery("SELECT lang_code FROM `{$p}languages` WHERE published = 1")->loadColumn() ?: [MultilingualHelper::getDefaultLanguageTag()];
    $saveDiscount = static function (array $data) use ($app, $factory, $tags, $run): int {
        $data += ['id' => 0, 'state' => 1, 'value' => '10', 'is_amount' => 0, 'behavior' => 1, 'apply_before_tax' => 1,
            'categories' => [0], 'manufacturers' => [0], 'places' => [0], 'usergroups' => [0], 'users' => [0]];

        foreach ($tags as $tag) {
            $data['name_' . MultilingualHelper::normaliseTag(tag: (string) $tag)] ??= 'ALFA SG discount ' . ($data['tag'] ?? '') . ' ' . $run;
        }

        unset($data['tag']);
        $app->getInput()->post->set('jform', $data);
        $model = $factory->createModel('Discount', 'Administrator', ['ignore_request' => true]);

        if (!$model->save($data)) {
            throw new \RuntimeException('discount save refused: ' . $model->getError());
        }

        $app->getMessageQueue(true);

        return (int) ($data['id'] ?: $model->getState('discount.id'));
    };

    $forGroup = $saveDiscount(['tag' => 'group', 'usergroups' => [$group]]);
    $payGroup = $method('payment', 'one group', ['usergroups' => [$group]]);
    $priced = $fx->item(columns: ['state' => 1, 'manage_stock' => 0, 'is_virtual' => 0, 'quantity_min' => 1, 'quantity_step' => 1, 'weight' => 0]);
    $fx->price(itemId: $priced, combinationId: null, value: 10.0);
    $db->setQuery("UPDATE `{$p}alfa_items_prices` SET usergroup_id = {$group} WHERE item_id = {$priced}")->execute();

    $guarded = null;

    try {
        RuleScopeGuard::assertScopesSurvive(db: $db, axis: RuleScopeGuard::AXIS_USERGROUP, ids: [$group]);
    } catch (ScopeDeleteRefusedException $refused) {
        $guarded = [
            str_contains($refused->plain, 'ALFA SG discount group ' . $run),
            str_contains($refused->plain, 'ALFA SG payment one group ' . $run),
            array_column($refused->rules, 'id', 'kind'),
        ];
    }

    check('[SG7] ★ the guard refuses the group for the discount and the payment method it is the last group of', $guarded, [true, true, ['discount' => $forGroup, 'payment' => $payGroup]]);

    $asked = static function (int $groupId) use ($app, $db): array|string {
        try {
            $record = new Usergroup($db);
            $record->load($groupId);
            $event = new UserGroupBeforeDeleteEvent('onUserBeforeDeleteGroup', ['data' => $record->getProperties(), 'context' => 'com_users.group', 'subject' => $record]);
            $app->bootPlugin('alfasync', 'system')->onUserBeforeDeleteGroup($event);

            return ['refused' => \in_array(false, (array) $event->getArgument('result', []), true), 'error' => (string) $record->getError()];
        } catch (\Throwable $e) {
            return \get_class($e) . ': ' . $e->getMessage();
        }
    };
    $answer = $asked($group);
    check('[SG7] ★ the sync plugin answers Joomla\'s event with a refusal', \is_array($answer) ? $answer['refused'] : $answer, true);
    check('[SG7] ★ the error on the group names the discount and the method and counts the price row',
        \is_array($answer)
            ? [str_contains($answer['error'], 'ALFA SG discount group ' . $run), str_contains($answer['error'], 'ALFA SG payment one group ' . $run),
                str_contains($answer['error'], htmlspecialchars(Text::sprintf('COM_ALFA_USERGROUP_DELETE_HAS_PRICE_ROWS', 1, 1), ENT_QUOTES, 'UTF-8'))]
            : $answer,
        [true, true, true]);
    $freeAnswer = $asked($freeGroup);
    check('[SG7] a group nothing names is not refused', \is_array($freeAnswer) ? [$freeAnswer['refused'], $freeAnswer['error']] : $freeAnswer, [false, '']);

    // [SG8] and [SG9] a group and an account that do not exist: ids above every existing one.
    $goneGroup = (int) $db->setQuery("SELECT COALESCE(MAX(id), 0) + 1000 FROM `{$p}usergroups`")->loadResult();
    $goneUser = (int) $db->setQuery("SELECT COALESCE(MAX(id), 0) + 1000 FROM `{$p}users`")->loadResult();
    $orphaned = $saveDiscount(['tag' => 'orphaned', 'usergroups' => [$goneGroup], 'users' => [$goneUser]]);
    $shipUser = $method('shipment', 'one customer', ['users' => [$goneUser]]);
    $stillNamed = array_column(RuleScopeGuard::named(db: $db, axis: RuleScopeGuard::AXIS_USER, ids: [$goneUser]), 'id', 'kind');
    check('[SG8] ★ the discount and the shipping method that name a deleted account are named', $stillNamed, ['discount' => $orphaned, 'shipment' => $shipUser]);

    $model = $factory->createModel('Discount', 'Administrator', ['ignore_request' => true]);
    $model->setState('discount.id', $orphaned);
    $form = $model->getForm([], true);
    $offered = static function (string $field) use ($form): array {
        $picker = $form->getField($field);
        $options = (new \ReflectionMethod($picker, 'getOptions'))->invoke($picker);
        $texts = [];

        foreach ($options as $option) {
            $texts[(int) $option->value] = (string) $option->text;
        }

        // What a browser posts of a multiple select: the stored values that are among its options.
        return ['texts' => $texts, 'posted' => array_values(array_filter(array_map('intval', (array) $picker->value), static fn (int $id): bool => isset($texts[$id])))];
    };
    $groups = $offered('usergroups');
    $users = $offered('users');
    check('[SG9] ★ the form offers the deleted group, marked', [$form->getField('usergroups') instanceof ScopeusergroupsField, $groups['texts'][$goneGroup] ?? null], [true, Text::sprintf('COM_ALFA_SCOPE_ENTITY_DELETED', $goneGroup)]);
    check('[SG9] ★ and the deleted account, marked', $users['texts'][$goneUser] ?? null, Text::sprintf('COM_ALFA_SCOPE_ENTITY_DELETED', $goneUser));
    check('[SG9] ★ so a form saved unchanged posts both', [$groups['posted'], $users['posted']], [[$goneGroup], [$goneUser]]);

    $saveDiscount(['id' => $orphaned, 'tag' => 'orphaned', 'usergroups' => $groups['posted'], 'users' => $users['posted']]);
    check('[SG9] ★ saved with what the form posts, the two parts are stored as they were, never as every one',
        [$rowsOf('alfa_discount_usergroups', 'discount_id', $orphaned, 'usergroup_id'), $rowsOf('alfa_discount_users', 'discount_id', $orphaned, 'user_id')], [[$goneGroup], [$goneUser]]);
    $saveDiscount(['id' => $orphaned, 'tag' => 'orphaned', 'usergroups' => [], 'users' => $users['posted']]);
    check('[SG9] emptied on purpose, the groups are every group and the customers stay',
        [$rowsOf('alfa_discount_usergroups', 'discount_id', $orphaned, 'usergroup_id'), $rowsOf('alfa_discount_users', 'discount_id', $orphaned, 'user_id')], [[0], [$goneUser]]);
} catch (\Throwable $e) {
    check('the fixtures and the readings', \get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 'readings');
} finally {
    $app->getMessageQueue(true);
    $app->loadIdentity(new User(0));
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

echo "\nscope guard: {$pass} passed, {$fail} failed\n";

exit($fail === 0 ? 0 : 1);
