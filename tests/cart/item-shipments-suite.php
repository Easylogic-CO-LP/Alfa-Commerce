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
 *   [IS5] ★ a shipping method no list names is deleted, alone and with another in one delete.
 *   [IS6] ★ a method a product allows beside another is not deleted until the delete is confirmed; confirmed, it is
 *         deleted, its row leaves the product's list and the product keeps its other method.
 *   [IS7] ★ a method that is the only one a product, or a variant, allows is refused whatever is confirmed, with the
 *         count; the method and the lists stay, and the product is still limited.
 *   [IS8] ★ a delete of several methods is judged as a whole: two methods that are each other's only alternative on
 *         a product are each confirmable alone and refused together.
 *   [IS9] ★ unpublishing the only method of products is allowed, changes no list and tells the operator how many
 *         products it leaves with no published method; their cart is offered nothing and names them; the Products
 *         list's filter lists exactly them; published again, the cart is offered the method with no list changed.
 *         Unpublishing several methods is judged as a whole too.
 *   [IS10] ★ a confirmed delete that leaves a product with only unpublished methods on its list is done, and says so.
 *   [IS11] ★ a method an order names is not deleted: the order keeps naming it and the method stays.
 *   [IS12] ★ the REST resource's model refuses the same deletes with the sentence and the count the API answers 409
 *         with, and takes the confirmation from force.
 *   [IS13] ★ "only these" with no method chosen is refused by the forms' rule and by the writer; a list is never
 *         written naming a method that does not exist; an unpublished method of a list is offered to its form, marked.
 *   [IS14] the Products list's filters: limited to a method, limited to methods alone.
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
use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Service\Catalog\DeleteRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ItemShipments;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCreator;
use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
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

    // The deletes and the state changes below go through the models, as the lists, the forms and the REST resource
    // send them. Every carrier made from here on is made after the method set was read, which only the cart reads;
    // where a cart is asked again, the set is read again.
    $factory = $app->bootComponent('com_alfa')->getMVCFactory();
    $app->loadIdentity(new User($operatorId));
    $app->getMessageQueue(true);

    $forgetMethodSet = static function (): void {
        (new \ReflectionProperty(AlfaHelper::class, 'loaded'))->setValue(null, []);
    };
    $exists = static fn (int $id): bool => (int) $db->setQuery("SELECT COUNT(*) FROM `{$p}alfa_shipments` WHERE id = {$id}")->loadResult() === 1;
    $rowsOf = static fn (int $shipmentId): int => (int) $db->setQuery("SELECT COUNT(*) FROM `{$p}alfa_items_shipments` WHERE shipment_id = {$shipmentId}")->loadResult();
    $listOf = static fn (int $itemId, ?int $combinationId = null): array => ItemShipments::allowed(ItemShipments::forItems($db, [$itemId])[$itemId] ?? null, $combinationId);

    /** A delete through a model: whether it was done, the refusal's sentence as the REST answer carries it, and what the operator was told. */
    $delete = static function (array $ids, bool $confirmed = false, string $name = 'Shipment', string $side = 'Administrator') use ($app, $factory): array {
        $model = $factory->createModel($name, $side, ['ignore_request' => true]);
        $model->setState('delete.confirmed', $confirmed);
        $pks = $ids;
        $done = (bool) $model->delete($pks);
        $error = $model->getError(null, false);

        return [
            'done' => $done,
            'refusal' => $error instanceof DeleteRefusedException ? $error->plain : (\is_string($error) ? $error : ''),
            'told' => array_column($app->getMessageQueue(true), 'message'),
        ];
    };

    /** A change of state through the model: whether it was done and what the operator was told. */
    $setState = static function (array $ids, int $state) use ($app, $factory): array {
        $model = $factory->createModel('Shipment', 'Administrator', ['ignore_request' => true]);
        $pks = $ids;

        return ['done' => (bool) $model->publish($pks, $state), 'told' => array_column($app->getMessageQueue(true), 'message')];
    };

    /** Whether the operator was told a sentence: the message starts with it, the link to the products follows. */
    $toldOf = static function (array $told, string $sentence): bool {
        foreach ($told as $message) {
            if (str_starts_with((string) $message, $sentence)) {
                return true;
            }
        }

        return false;
    };

    /** The Products list filtered by shipping list, every state: the product ids, ascending. */
    $listed = static function (array $ids, string $list) use ($factory): array {
        try {
            $model = $factory->createModel('Items', 'Administrator', ['ignore_request' => true]);
            $model->setState('filter.state', '*');
            $model->setState('filter.shipment', $ids);
            $model->setState('filter.shipment_list', $list);
            $model->setState('list.ordering', 'a.id');
            $model->setState('list.direction', 'ASC');
            $model->setState('list.start', 0);
            $model->setState('list.limit', 0);

            return array_map(static fn (object $row): int => (int) $row->id, $model->getItems() ?: []);
        } catch (\Throwable $e) {
            return ['error' => \get_class($e) . ': ' . $e->getMessage()];
        }
    };
    $sorted = static function (array $ids): array {
        sort($ids);

        return $ids;
    };

    $confirmHint = ' ' . Text::_('COM_ALFA_SHIPMENT_DELETE_CONFIRM_REST');

    try {
        // [IS5] methods no list names.
        $c = $carrier('C');
        $h = $carrier('H');
        $i = $carrier('I');
        $unused = $delete([$c]);
        check('[IS5] ★ a method no list names is deleted without a confirmation', [$unused['done'], $unused['refusal'], $exists($c)], [true, '', false]);
        $unusedTwo = $delete([$h, $i]);
        check('[IS5] ★ two such methods are deleted in one delete', [$unusedTwo['done'], $exists($h), $exists($i)], [true, false, false]);

        // [IS6] one of several.
        $d = $carrier('D');
        $e = $carrier('E');
        $both = $product();
        ItemShipments::replace(db: $db, itemId: $both, combinationId: 0, shipmentIds: [$d, $e]);
        $asked = $delete([$d]);
        check('[IS6] ★ a method a product allows beside another is not deleted unconfirmed', [$asked['done'], $exists($d), $rowsOf($d)], [false, true, 1]);
        check('[IS6] ★ the refusal says one product names it and how to confirm', $asked['refusal'], Text::plural('COM_ALFA_SHIPMENT_DELETE_CONFIRM', 1) . $confirmHint);
        check('[IS6] the Products list limited to the method lists that product', $listed([$d], ItemShipments::LIST_ANY), [$both]);
        $confirmed = $delete([$d], true);
        check('[IS6] ★ confirmed, it is deleted and leaves the list', [$confirmed['done'], $exists($d), $rowsOf($d)], [true, false, 0]);
        check('[IS6] ★ the product keeps its other method', $listOf($both), [$e]);

        // [IS7] the only method of a product, and of a variant.
        $refused = $delete([$e], true);
        check('[IS7] ★ the only method a product allows is not deleted, confirmed or not', [$refused['done'], $exists($e), $rowsOf($e)], [false, true, 1]);
        check('[IS7] ★ the refusal says one product would lose its restriction', $refused['refusal'], Text::plural('COM_ALFA_SHIPMENT_DELETE_ONLY_METHOD', 1));
        check('[IS7] ★ the product is still limited to it', $listOf($both), [$e]);
        $refusedTwo = $delete([$b], true);
        check('[IS7] ★ the only method of a product and of another product\'s variant is refused for both products',
            [$refusedTwo['done'], $refusedTwo['refusal'], $exists($b)], [false, Text::plural('COM_ALFA_SHIPMENT_DELETE_ONLY_METHOD', 2), true]);
        check('[IS7] ★ the product\'s list and the variant\'s list stand', [$listOf($onlyB), $listOf($variants, $own)], [[$b], [$b]]);
        check('[IS14] the Products list limited to that method alone lists the two products', $listed([$b], ItemShipments::LIST_ONLY), $sorted([$onlyB, $variants]));

        // [IS8] the whole selection is judged.
        $f = $carrier('F');
        $g = $carrier('G');
        $pair = $product();
        ItemShipments::replace(db: $db, itemId: $pair, combinationId: 0, shipmentIds: [$f, $g]);
        $alone = $delete([$f]);
        check('[IS8] ★ one of the two alone waits for a confirmation', [$alone['done'], $alone['refusal']], [false, Text::plural('COM_ALFA_SHIPMENT_DELETE_CONFIRM', 1) . $confirmHint]);
        $together = $delete([$f, $g], true);
        check('[IS8] ★ the two together are refused: they would leave the product no method', [$together['done'], $together['refusal']], [false, Text::plural('COM_ALFA_SHIPMENT_DELETE_ONLY_METHOD', 1)]);
        check('[IS8] ★ both methods and the list stand', [$exists($f), $exists($g), $listOf($pair)], [true, true, [$f, $g]]);
        check('[IS14] the Products list limited to the two alone lists the product; to one of them alone, nothing', [$listed([$f, $g], ItemShipments::LIST_ONLY), $listed([$f], ItemShipments::LIST_ONLY)], [[$pair], []]);

        // [IS9] unpublishing the only method of two products (the product that ships with A only, and the product
        // with variants, whose own list is A), then publishing it again.
        $leftWithout = Text::plural('COM_ALFA_SHIPMENT_UNPUBLISH_LEAVES_NONE', 2);
        $off = $setState([$a], 0);
        check('[IS9] ★ unpublishing the only method of two products is done', [$off['done'], (int) $db->setQuery("SELECT state FROM `{$p}alfa_shipments` WHERE id = {$a}")->loadResult()], [true, 0]);
        check('[IS9] ★ the operator is told two products have no published method now', $toldOf($off['told'], $leftWithout), true);
        check('[IS9] ★ no list changed', [$listOf($onlyA), $listOf($variants)], [[$a], [$a]]);
        check('[IS9] ★ the Products list of the method\'s products with no published method lists exactly them', $listed([$a], ItemShipments::LIST_NONE), $sorted([$onlyA, $variants]));
        $forgetMethodSet();
        $stranded = $methodsOf([$onlyA, $any]);
        check('[IS9] ★ a cart of such a product beside one that ships with everything is offered nothing', $stranded['offered'], []);
        check('[IS9] ★ and the empty list\'s sentence names the limited product', str_contains($stranded['notice'], $nameOf($onlyA)), true);
        $held = ItemShipments::heldUnpublished($db, [$a, $b]);
        check('[IS13] ★ the unpublished method of a list is offered to its form, marked; the published one is not among the held',
            [array_keys($held), (string) ($held[$a]->name ?? '')], [[$a], Text::sprintf('COM_ALFA_ITEM_SHIPMENTS_HELD_NOT_PUBLISHED', 'ALFA IS carrier A ' . $run)]);
        $on = $setState([$a], 1);
        check('[IS9] ★ published again, nothing is said and no list changed', [$on['done'], $toldOf($on['told'], $leftWithout), $listOf($onlyA)], [true, false, [$a]]);
        $forgetMethodSet();
        $back = $methodsOf([$onlyA, $any]);
        check('[IS9] ★ and the same cart is offered the method again', \in_array($a, $back['offered'], true), true);

        $oneOff = $setState([$f], 0);
        check('[IS9] unpublishing one of a product\'s two methods says nothing', $toldOf($oneOff['told'], Text::plural('COM_ALFA_SHIPMENT_UNPUBLISH_LEAVES_NONE', 1)), false);
        $setState([$f], 1);
        $bothOff = $setState([$f, $g], 0);
        check('[IS9] ★ unpublishing the two together says the product is left with none', $toldOf($bothOff['told'], Text::plural('COM_ALFA_SHIPMENT_UNPUBLISH_LEAVES_NONE', 1)), true);

        // [IS10] F published again, G still unpublished: the confirmed delete of F leaves the product limited to G alone.
        $setState([$f], 1);
        $last = $delete([$f], true);
        check('[IS10] ★ the confirmed delete is done and the product keeps its unpublished method', [$last['done'], $exists($f), $listOf($pair)], [true, false, [$g]]);
        check('[IS10] ★ the operator is told one product is left without an available method', $toldOf($last['told'], Text::plural('COM_ALFA_SHIPMENT_DELETE_LEAVES_NONE', 1)), true);
        check('[IS10] the Products list of products with no published method lists it', \in_array($pair, $listed([], ItemShipments::LIST_NONE), true), true);

        // [IS11] a method an order names.
        $o = $carrier('O');
        $historical = null;

        try {
            $order = OrderCreator::create(draft: [
                'id_user' => 0, 'customer_name' => 'Item shipments suite', 'customer_email' => 'host@easylogic.gr', 'customer_is_guest' => 1,
                'id_order_status' => (int) OrderStatusHelper::getInitialId(), 'id_address_delivery' => 0, 'id_address_invoice' => 0,
                'source' => 'backend', 'created_by' => $operatorId, 'actor_name' => 'Item shipments suite', 'allow_empty' => true, 'lines' => [],
            ], manageTransaction: false);
            $orderId = (int) $order->orderId;
            $db->setQuery("UPDATE `{$p}alfa_orders` SET id_shipment_method = {$o} WHERE id = {$orderId}")->execute();
            $kept = $delete([$o], true);
            $historical = [
                $kept['done'],
                $kept['refusal'] === Text::sprintf('COM_ALFA_SHIPMENT_DELETE_HAS_ORDERS', $o, 1, 0),
                $exists($o),
                (int) $db->setQuery("SELECT id_shipment_method FROM `{$p}alfa_orders` WHERE id = {$orderId}")->loadResult() === $o,
            ];
        } catch (\Throwable $e) {
            $historical = \get_class($e) . ': ' . $e->getMessage();
        }

        check('[IS11] ★ a method an order names is not deleted, the refusal counts the order, the method stays and the order still names it', $historical, [false, true, true, true]);

        // [IS12] the REST resource's model: the refusals the API answers 409 with, and its confirmation.
        $restOnly = $delete([$e], false, 'Shipmentmethod', 'Api');
        check('[IS12] ★ the REST model refuses the only method of a product with the count', [$restOnly['done'], $restOnly['refusal'], $exists($e)], [false, Text::plural('COM_ALFA_SHIPMENT_DELETE_ONLY_METHOD', 1), true]);
        $j = $carrier('J');
        ItemShipments::replace(db: $db, itemId: $both, combinationId: 0, shipmentIds: [$e, $j]);
        $restAsk = $delete([$j], false, 'Shipmentmethod', 'Api');
        check('[IS12] ★ and asks for force on a method a product allows beside another', [$restAsk['done'], $restAsk['refusal']], [false, Text::plural('COM_ALFA_SHIPMENT_DELETE_CONFIRM', 1) . $confirmHint]);
        $restForced = $delete([$j], true, 'Shipmentmethod', 'Api');
        check('[IS12] ★ forced, it is deleted and the product keeps the other', [$restForced['done'], $exists($j), $listOf($both)], [true, false, [$e]]);
        $apiController = (string) file_get_contents(JPATH_ROOT . '/api/components/com_alfa/src/Controller/ShipmentmethodsController.php');
        check('[IS12] the REST controller hands the model force as the confirmation', str_contains($apiController, "setState('delete.confirmed', RequestScalar::bool(\$this->input, 'force'))"), true);
        $guards = (string) file_get_contents(JPATH_ROOT . '/api/components/com_alfa/src/Controller/HasApiGuards.php');
        check('[IS12] and a refused delete is answered 409 with the sentence', str_contains($guards, 'throw new Save($refusal->plain, 409);'), true);

        // [IS13] the rule of the forms and of the writer.
        check('[IS13] ★ "only these" with no method chosen is refused, with methods or as "all methods" it is not',
            [ItemShipments::choiceRefusal(ItemShipments::ONLY, []), ItemShipments::choiceRefusal(ItemShipments::ONLY, [$e]), ItemShipments::choiceRefusal(ItemShipments::ALL, [])],
            [Text::_('COM_ALFA_ITEM_SHIPMENTS_ONLY_NEEDS_METHOD'), null, null]);
        $threw = static function (callable $call): bool {
            try {
                $call();
            } catch (\RuntimeException) {
                return true;
            }

            return false;
        };
        check('[IS13] ★ the writer refuses it too, for a product and for a variant',
            [$threw(static fn () => ItemShipments::posted(ItemShipments::ONLY, [], false)), $threw(static fn () => ItemShipments::posted(ItemShipments::ONLY, ['0', ''], true))], [true, true]);
        $missing = (int) $db->setQuery("SELECT COALESCE(MAX(id), 0) + 1000 FROM `{$p}alfa_shipments`")->loadResult();
        check('[IS13] ★ a list is not written naming a method that does not exist, and the list stays as it was',
            [$threw(static fn () => ItemShipments::replace(db: $db, itemId: $both, combinationId: 0, shipmentIds: [$missing])), $listOf($both)], [true, [$e]]);
    } finally {
        $app->getMessageQueue(true);
        $app->loadIdentity(new User(0));
        $forgetMethodSet();
    }
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
