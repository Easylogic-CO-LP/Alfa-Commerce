<?php

/**
 * A CART IS OFFERED THE SHIPPING METHODS EVERY LINE ALLOWS.
 *
 * A product names the shipping methods it ships with on its item screen (every method, or the chosen ones), a variant
 * its own in its window (as the product, every method, or the chosen ones); both live in #__alfa_items_shipments and
 * are read by ItemShipments. The method list (AlfaHelper::getFilteredMethods(), through each cart line's scope) offers
 * a shipping method only when every line allows it; the checkout names the products that keep a method off
 * (CartMethods::shipmentRefusals()) and, when the lists leave nothing at all, the products that restrict them
 * (CartMethods::shipmentNotSharedNotice()). The storefront, the REST cart and the order builder read the same list.
 *
 *   [IS1] ★ a product that ships with carrier A only: a cart of it is offered A and not B, and no sentence about the
 *         lists is shown.
 *   [IS2] ★ with a product that ships with every method beside it: still A and not B, and B's refusal names the
 *         restricted product alone.
 *   [IS3] ★ with a product that ships with B only beside it: nothing is offered, each carrier's refusal names the
 *         product that keeps it off, and the empty list's sentence names both products.
 *   [IS4] ★ the reader: a variant with rows of its own ships with them, one with the wildcard row with every method,
 *         one with no row as its product; the product's page scope (one line, its own list) lists A and not B.
 *   [T1]  every table of the schema holds exactly the rows it held, read on a second connection.
 *
 * Fixtures: two published carriers of this run's own, admitted to everyone (the wildcard row on each scope), three
 * published, priced products that do not track stock, one product with three variant rows, and two carts of this
 * run's own, all written inside the fixture transaction and rolled back. Nothing survives the run and no shop stock
 * is read or moved. The carriers are made before the first question about methods: the method set is read once per
 * process. The carts are opened as an operator placement, which keeps the scoped list without asking the carriers
 * for a price (the lists are the question here, not a quote).
 *
 *   cd <docroot> && php administrator/components/com_alfa/tests/cart/item-shipments-suite.php
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

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartSubject;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ItemShipments;
use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseFactory;
use Joomla\Session\SessionInterface;

require_once JPATH_ADMINISTRATOR . '/components/com_alfa/tests/harness/CliSessionRow.php';
require_once JPATH_ADMINISTRATOR . '/components/com_alfa/tests/harness/MailOff.php';
require_once JPATH_ADMINISTRATOR . '/components/com_alfa/tests/harness/InventoryFixtures.php';
require_once JPATH_ADMINISTRATOR . '/components/com_alfa/tests/harness/CartInternals.php';
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

echo "A cart is offered the shipping methods every line allows\n", str_repeat('=', 70), "\n";

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

/** A product's name in the request's language, as a cart line and a sentence name it. */
$nameOf = static fn (int $itemId): string => (string) MultilingualHelper::getTranslatedValue(
    id:                $itemId,
    tableName:         '#__alfa_items',
    primaryColumnName: 'id_item',
    field:             'name',
);

$before = $census();
$run = bin2hex(random_bytes(3));
$fx = new InventoryFixtures(db: $db, token: 'IS' . $run);
$fx->begin();

try {
    $currency = (int) Currency::getDefault()->getId();

    // A published carrier of this run's own, admitted to everyone: every scope a method has must admit the cart, and a
    // scope with no row admits nobody, so each gets its wildcard row. Its name lives only in the per-language tables
    // and the method list joins them.
    $carrier = static function (string $label) use ($db, $p, $run): int {
        $row = (object) [
            'type' => 'standard', 'color' => '', 'bg_color' => '', 'params' => '{}',
            'show_on_product' => 1, 'ordering' => 0, 'created_by' => 0, 'modified_by' => 0, 'state' => 1,
        ];
        $db->insertObject('#__alfa_shipments', $row, 'id');
        $id = (int) $row->id;

        foreach (['alfa_shipment_categories' => 'category_id', 'alfa_shipment_manufacturers' => 'manufacturer_id',
            'alfa_shipment_users' => 'user_id', 'alfa_shipment_places' => 'place_id', 'alfa_shipment_usergroups' => 'usergroup_id'] as $table => $column) {
            $db->setQuery("INSERT INTO `{$p}{$table}` (shipment_id, {$column}) VALUES ({$id}, 0)")->execute();
        }

        foreach ($db->setQuery("SHOW TABLES LIKE '{$p}alfa_shipments\\_%'")->loadColumn() as $table) {
            if (!preg_match('/_[a-z]{2,3}_[a-z]{2,3}$/', $table)) {
                continue;
            }

            $columns = $db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName($table))->loadColumn();

            if (\in_array('id_shipment', $columns, true) && \in_array('name', $columns, true)) {
                $name = (object) ['id_shipment' => $id, 'name' => 'ALFA IS carrier ' . $label . ' ' . $run];
                $db->insertObject($table, $name);
            }
        }

        return $id;
    };

    $a = $carrier('A');
    $b = $carrier('B');
    check('fixture: two carriers of this run were made', $a > 0 && $b > 0 && $a !== $b, true);

    // A published, priced product that does not track stock, on sale now.
    $product = static function () use ($fx): int {
        $itemId = $fx->item(columns: [
            'state' => 1, 'manage_stock' => 0, 'is_virtual' => 0, 'quantity_min' => 1, 'quantity_step' => 1, 'weight' => 0,
            'publish_up' => gmdate('Y-m-d H:i:s', time() - 60), 'publish_down' => gmdate('Y-m-d H:i:s', time() + 1800),
        ]);
        $fx->price(itemId: $itemId, combinationId: null, value: 10.0);

        return $itemId;
    };

    $onlyA = $product();
    $onlyB = $product();
    $any = $product();
    ItemShipments::replace(db: $db, itemId: $onlyA, combinationId: 0, shipmentIds: [$a]);
    ItemShipments::replace(db: $db, itemId: $onlyB, combinationId: 0, shipmentIds: [$b]);

    // The operator placement keeps the scoped list without asking the carriers for a price.
    $operatorId = (int) $db->setQuery(
        "SELECT m.user_id FROM `{$p}user_usergroup_map` m"
        . " INNER JOIN `{$p}usergroups` g ON g.id = m.group_id AND g.title = 'Super Users'"
        . " INNER JOIN `{$p}users` u ON u.id = m.user_id AND u.block = 0"
        . ' ORDER BY m.user_id LIMIT 1',
    )->loadResult();
    check('fixture: an operator to open the carts as', $operatorId > 0, true);

    $cartOf = static function (array $itemIds) use ($db, $currency): int {
        $row = (object) [
            'id_payment' => 0, 'id_shipment' => 0,
            'id_user_info_delivery' => 0, 'id_user_info_invoice' => 0,
            'id_currency' => $currency, 'id_customer' => 0,
            'added' => gmdate('Y-m-d H:i:s'), 'updated' => gmdate('Y-m-d H:i:s'),
            'recognize_key' => bin2hex(random_bytes(32)),
        ];
        $db->insertObject('#__alfa_cart', $row, 'id');

        foreach ($itemIds as $itemId) {
            $line = (object) ['id_cart' => (int) $row->id, 'id_item' => $itemId, 'id_combination' => null, 'quantity' => 1];
            $db->insertObject('#__alfa_cart_items', $line, 'id');
        }

        return (int) $row->id;
    };

    $methodsOf = static function (array $itemIds) use ($cartOf, $operatorId): array {
        $opened = CartInternals::open($cartOf($itemIds), CartSubject::operator(new User(0), new User($operatorId)));
        $methods = $opened->computation->methods();

        return [
            'offered' => array_map('intval', array_keys($methods->shipments())),
            'refusals' => $methods->shipmentRefusals(),
            'notice' => $methods->shipmentNotSharedNotice(),
        ];
    };

    // [IS1]
    $one = $methodsOf([$onlyA]);
    check('[IS1] ★ a product shipping with A only: its cart is offered A', \in_array($a, $one['offered'], true), true);
    check('[IS1] ★ and not B', \in_array($b, $one['offered'], true), false);
    check('[IS1] no sentence about the lists while something is offered', $one['notice'], '');

    // [IS2]
    $two = $methodsOf([$onlyA, $any]);
    check('[IS2] ★ with a product shipping with every method beside it: A is offered', \in_array($a, $two['offered'], true), true);
    check('[IS2] ★ and B is not', \in_array($b, $two['offered'], true), false);
    check('[IS2] ★ B\'s refusal names the restricted product', str_contains((string) ($two['refusals'][$b] ?? ''), $nameOf($onlyA)), true);
    check('[IS2] and not the one that ships with everything', str_contains((string) ($two['refusals'][$b] ?? ''), $nameOf($any)), false);
    check('[IS2] A, offered, has no refusal', isset($two['refusals'][$a]), false);

    // [IS3]
    $three = $methodsOf([$onlyA, $onlyB]);
    check('[IS3] ★ two products sharing no method: nothing is offered', $three['offered'], []);
    check('[IS3] ★ A\'s refusal names the product that ships with B only', str_contains((string) ($three['refusals'][$a] ?? ''), $nameOf($onlyB)), true);
    check('[IS3] ★ B\'s refusal names the product that ships with A only', str_contains((string) ($three['refusals'][$b] ?? ''), $nameOf($onlyA)), true);
    check('[IS3] ★ the empty list\'s sentence names both products',
        $three['notice'] !== '' && str_contains($three['notice'], $nameOf($onlyA)) && str_contains($three['notice'], $nameOf($onlyB)), true);

    // [IS4] the reader, on rows of a product with variants
    $variants = $product();
    $own = $fx->combination(itemId: $variants, columns: []);
    $every = $fx->combination(itemId: $variants, columns: []);
    $asProduct = $fx->combination(itemId: $variants, columns: []);
    ItemShipments::replace(db: $db, itemId: $variants, combinationId: 0, shipmentIds: [$a]);
    ItemShipments::replace(db: $db, itemId: $variants, combinationId: $own, shipmentIds: [$b]);
    ItemShipments::replace(db: $db, itemId: $variants, combinationId: $every, shipmentIds: [ItemShipments::EVERY_METHOD]);
    $scope = ItemShipments::forItems($db, [$variants])[$variants] ?? null;
    check('[IS4] ★ the product ships with its own list', ItemShipments::allowed($scope, null), [$a]);
    check('[IS4] ★ a variant with rows of its own ships with them', ItemShipments::allowed($scope, $own), [$b]);
    check('[IS4] ★ a variant with the wildcard row ships with every method', ItemShipments::allowed($scope, $every), []);
    check('[IS4] ★ a variant with no row ships as its product', ItemShipments::allowed($scope, $asProduct), [$a]);

    $page = array_map('intval', array_keys(AlfaHelper::getFilteredMethods(
        categories:    [],
        manufacturers: [],
        usergroups:    [1],
        userId:        0,
        baseTable:     'shipment',
        lines:         [['categories' => [], 'manufacturers' => [], 'shipments' => ItemShipments::allowed($scope, null)]],
    )));
    check('[IS4] ★ the product page\'s scope lists A', \in_array($a, $page, true), true);
    check('[IS4] ★ and not B', \in_array($b, $page, true), false);
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

echo "\nitem shipments: {$pass} passed, {$fail} failed\n";

exit($fail === 0 ? 0 : 1);
