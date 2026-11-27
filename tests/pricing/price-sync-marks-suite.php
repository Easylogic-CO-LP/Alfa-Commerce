<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The price index writer leaves nothing behind when a request stops between its chunks: every product of a write is
 * marked owed a rebuild before the first chunk, and each chunk clears its marks once it committed, as the item save
 * does, so a request that dies between two chunks leaves the products of the chunks not written marked for the
 * catalogue rebuild job.
 *
 * Fixtures: one chunk of products and one more (the writer's chunk is 50 products), written through the item screen's
 * model inside one transaction that is rolled back. A query monitor on the connection watches the writer's lock asks
 * (one GET_LOCK statement per chunk): at the second it reads the marks on the same connection and stops the writer with
 * an exception, the way a request dying between chunks stops it.
 *
 *   [M1] ★ when the second chunk asks its locks, the first chunk's products are written and no longer marked, and
 *          the product of the second chunk is still marked: marked before the first chunk, cleared chunk by chunk.
 *   [M2] ★ after the interrupted write the product left is still owed: its mark stands.
 *   [M3]   a write that runs to its end clears every mark and reports nothing pending.
 *   [T1]   every table holds exactly the rows it held, read on a second connection, and nothing of the run's products
 *          outlived the rollback.
 *
 *   cd <docroot> && php administrator/components/com_alfa/tests/pricing/price-sync-marks-suite.php
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
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceDataLoader;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseFactory;
use Joomla\Database\QueryMonitorInterface;
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

echo "The price index writer leaves the rest owed when it stops between chunks\n", str_repeat('=', 70), "\n";

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

// The operator: a super user.
$super = (int) $db->setQuery(
    "SELECT m.user_id FROM `{$p}user_usergroup_map` m INNER JOIN `{$p}users` u ON u.id = m.user_id WHERE m.group_id = 8 AND u.block = 0 ORDER BY m.user_id",
    0,
    1,
)->loadResult();
$app->loadIdentity(new User($super));

$category = (int) $db->setQuery("SELECT c.id FROM `{$p}alfa_categories` c WHERE c.state = 1 ORDER BY c.id", 0, 1)->loadResult();

if ($category <= 0) {
    echo "\nThe shop needs one published category for the fixtures.\n\nprice sync marks: {$pass} passed, " . ($fail + 1) . " failed\n";
    exit(1);
}

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

$factory = $app->bootComponent('com_alfa')->getMVCFactory();
$itemModel = $factory->createModel('Item', 'Administrator', ['ignore_request' => true]);
$itemController = $factory->createController('Item', 'Administrator', [], $app, $app->getInput());
$hook = new \ReflectionMethod($itemController, 'preSaveHook');
$run = strtolower(substr(bin2hex(random_bytes(4)), 0, 8));

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

/** A published product of this run with one price row. */
$product = static function (int $n) use ($saveItem, $named, $run, $category): int {
    return $saveItem([
        'id' => 0, 'state' => 1, 'sku' => 'PM-' . strtoupper($run) . '-' . $n,
        'id_category_default' => $category, 'categories' => [$category], 'manufacturers' => [],
        'prices' => [[
            'id' => 0, 'value' => '10.00000000', 'override_value' => '', 'override_type' => 'start', 'price_status' => '1',
            'country_id' => 0, 'usergroup_id' => 0, 'user_id' => 0, 'currency_id' => 0, 'quantity_start' => '', 'quantity_end' => '',
            'publish_up' => '', 'publish_down' => '',
        ]],
    ] + $named('PM disposable ' . $n . ' ' . $run, 'pm-disposable-' . $n . '-' . $run));
};

/**
 * Watches the writer's lock asks on the connection. At the second GET_LOCK statement (the second chunk's) it reads, on
 * the same connection and inside the same transaction, which of the run's products are still marked and how many of
 * the first chunk's have index rows, then stops the writer with an exception.
 */
final class LockWatch implements QueryMonitorInterface
{
    public int $locks = 0;

    /** @var array{marked: int[], written: int}|null What was read at the second chunk's lock ask. */
    public ?array $seen = null;

    public function __construct(private readonly \mysqli $connection, private readonly string $prefix, private readonly array $itemIds, private readonly array $firstChunk)
    {
    }

    public function startQuery(string $sql, ?array $boundParams = null): void
    {
        if (!str_contains($sql, 'GET_LOCK(')) {
            return;
        }

        $this->locks++;

        if ($this->locks !== 2) {
            return;
        }

        // The driver's own statement is prepared and not run yet: the raw connection is asked, so its state is kept.
        $marked = [];
        $result = $this->connection->query("SELECT id FROM `{$this->prefix}alfa_items` WHERE projection_dirty_at IS NOT NULL AND id IN (" . implode(',', $this->itemIds) . ') ORDER BY id');

        foreach ($result->fetch_all() as $row) {
            $marked[] = (int) $row[0];
        }

        $written = (int) $this->connection->query("SELECT COUNT(DISTINCT item_id) FROM `{$this->prefix}alfa_items_price_index` WHERE item_id IN (" . implode(',', $this->firstChunk) . ')')->fetch_row()[0];
        $this->seen = ['marked' => $marked, 'written' => $written];

        throw new \RuntimeException('the request died between two chunks');
    }

    public function stopQuery(): void
    {
    }
}

$result = ['error' => 'not run'];
$runIds = [];
$db->transactionStart();

try {
    for ($n = 1; $n <= 51; $n++) {
        $runIds[] = $product($n);
    }

    sort($runIds);
    $firstChunk = \array_slice($runIds, 0, 50);
    $last = $runIds[50];

    // The saves left their marks and deferred their rebuilds to the commit, which never comes here: cleared first, so
    // what the arms read is the writer's own marking.
    $db->setQuery("UPDATE `{$p}alfa_items` SET projection_dirty_at = NULL WHERE id IN (" . implode(',', $runIds) . ')')->execute();
    PriceDataLoader::forgetLoaded();

    $watch = new LockWatch(connection: $db->getConnection(), prefix: $p, itemIds: $runIds, firstChunk: $firstChunk);
    $db->setMonitor($watch);
    $service = new PriceIndexSyncService();

    try {
        $service->syncItems($runIds);
    } finally {
        $db->setMonitor(null);
    }

    $interrupted = $service->outcome();
    $markedAfter = array_map('intval', $db->setQuery("SELECT id FROM `{$p}alfa_items` WHERE projection_dirty_at IS NOT NULL AND id IN (" . implode(',', $runIds) . ') ORDER BY id')->loadColumn() ?: []);
    $writtenAfter = (int) $db->setQuery("SELECT COUNT(DISTINCT item_id) FROM `{$p}alfa_items_price_index` WHERE item_id IN (" . implode(',', $firstChunk) . ')')->loadResult();

    // The same write run to its end.
    PriceDataLoader::forgetLoaded();
    $whole = new PriceIndexSyncService();
    $whole->syncItems($runIds);
    $completed = $whole->outcome();
    $markedWhole = (int) $db->setQuery("SELECT COUNT(*) FROM `{$p}alfa_items` WHERE projection_dirty_at IS NOT NULL AND id IN (" . implode(',', $runIds) . ')')->loadResult();

    $result = [
        'seen' => $watch->seen,
        'locks' => $watch->locks,
        'last' => $last,
        'interrupted' => ['products' => $interrupted->products, 'pending' => $interrupted->pending],
        'markedAfter' => $markedAfter,
        'writtenAfter' => $writtenAfter,
        'completed' => ['products' => $completed->products, 'pending' => $completed->pending],
        'markedWhole' => $markedWhole,
    ];
} catch (\Throwable $e) {
    $result = ['error' => \get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()];
} finally {
    $db->setMonitor(null);
    // Closed through TransactionState: the work the saves deferred to their commit goes with the transaction.
    TransactionState::rollBackLevel(db: $db);
}

if (isset($result['error'])) {
    check('the fixtures and the writes', $result, 'writes');
} else {
    echo "\nbetween two chunks\n";
    check('[M1] ★ at the second chunk\'s lock ask the first chunk is written and unmarked, the product left is still marked',
        [$result['seen']['written'] ?? null, $result['seen']['marked'] ?? null], [50, [$result['last']]]);
    check('[M1] the writer asked the locks of two chunks', $result['locks'], 2);

    echo "\nafter the interruption\n";
    check('[M2] ★ the product of the chunk not written is still owed, and the first chunk\'s rows stand', [\in_array($result['last'], $result['markedAfter'], true), $result['writtenAfter']], [true, 50]);
    check('[M2] the writer reports the interrupted write as pending', $result['interrupted']['pending'], true);

    echo "\nthe write run to its end\n";
    check('[M3] every mark is cleared and nothing is pending', [$result['markedWhole'], $result['completed']['products'], $result['completed']['pending']], [0, 51, false]);
}

// [T1] The run's products, by the ids it knows, in every table that references them, then every table as it was.
$after = $census();
$moved = array_filter($before, static fn (int $count, string $table): bool => ($after[$table] ?? null) !== $count, ARRAY_FILTER_USE_BOTH);
$columns = [];

foreach ($oracle->setQuery("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '" . $like . "%' AND COLUMN_NAME IN ('id', 'item_id', 'id_item')")->loadRowList() ?: [] as [$table, $column]) {
    $columns[$table][] = $column;
}

$outlived = [];

foreach (array_keys($moved) as $table) {
    if ($runIds === []) {
        break;
    }

    $where = [];

    foreach ($columns[$table] ?? [] as $column) {
        if ($column !== 'id' || $table === $p . 'alfa_items') {
            $where[] = "`{$column}` IN (" . implode(',', $runIds) . ')';
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
$others = [];

foreach ($moved as $table => $count) {
    if (($after[$table] ?? null) !== $count) {
        $others[$table] = ['held' => $count, 'holds' => $after[$table] ?? null];
    }
}

check('[T1] every table holds exactly the rows it held (' . \count($before) . ' read on a second connection)', $others, []);

echo "\nprice sync marks: {$pass} passed, {$fail} failed\n";

exit($fail === 0 ? 0 : 1);
