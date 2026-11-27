<?php

/**
 * THE INVENTORY AUDIT'S BULK REPAIR RUNS AS A BACKGROUND JOB PAST FIFTY PRODUCTS, BY THE SAME RULE, AND THE BACKGROUND
 * SCREEN READS IT.
 *
 * What this pins, on the rows and on the handler, never on a return value alone:
 *
 *   B1   the threshold is fifty, the handler is core's, registered under its name, and the audit controller sends a
 *        larger selection to it;
 *   B2   one job per selection: the scope is the digest of the sorted products, so the same selection in any order
 *        joins the job that waits;
 *   B3   the handler stops at the first product that refuses, as the inline repair does: the result names that
 *        product and why, counts nothing as repaired, and the chunk ends the job;
 *   B4   the Background screen reads the finished row: its state of work, its label, what it covers and the report
 *        lines that say how far it got and where it stopped; its list model finds the row under Finished and under
 *        its kind, and not under Running now; the deliveries list compiles;
 *   B5   Delete on the Background screen deletes the finished row and refuses nothing else named with it.
 *
 * Disposable data only: the products named never existed (ids past the highest), so the inventory service refuses
 * the first of them and writes nothing; the one task row the suite writes is finished by the suite itself (the
 * runner is not ticked, so none of the shop's other due work runs here) and deleted through the screen's own delete.
 * A census of the tasks and inventory tables is taken before and after, and any difference fails the run.
 *
 * Run from the docroot:
 *
 *   cd <docroot> && php administrator/components/com_alfa/tests/background/stock-repair-job-suite.php
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

PHP_SAPI === 'cli' or exit;

define('_JEXEC', 1);
\defined('JDEBUG') or \define('JDEBUG', false);
define('JPATH_BASE', \dirname(__DIR__, 5));

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Alfa\Component\Alfa\Administrator\Model\OutboundModel;
use Alfa\Component\Alfa\Administrator\Model\TasksModel;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\StockRepairJob;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
use Joomla\Session\SessionInterface;

$container = Factory::getContainer();
$container->alias('session', 'session.cli')
    ->alias('JSession', 'session.cli')
    ->alias(Session::class, 'session.cli')
    ->alias(SessionInterface::class, 'session.cli');

$app = $container->get(AdministratorApplication::class);
Factory::$application = $app;
$app->loadLanguage();
$app->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);
$app->createExtensionNamespaceMap();

\define('JPATH_COMPONENT', JPATH_ADMINISTRATOR . '/components/com_alfa');
\define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_ADMINISTRATOR . '/components/com_alfa');

$db = $container->get('DatabaseDriver');
$p = $db->getPrefix();

// The repairs are recorded under a Super User, who holds every alfa.* action without a grant.
$superGroups = array_values(array_filter(
    array_map('intval', $db->setQuery("SELECT id FROM `{$p}usergroups`")->loadColumn() ?: []),
    static fn (int $groupId): bool => (bool) Access::checkGroup($groupId, 'core.admin'),
));
$superId = $superGroups === [] ? 0 : (int) $db->setQuery(
    "SELECT m.user_id FROM `{$p}user_usergroup_map` m JOIN `{$p}users` u ON u.id = m.user_id
      WHERE u.block = 0 AND m.group_id IN (" . implode(',', $superGroups) . ') ORDER BY m.user_id',
    0,
    1,
)->loadResult();
$app->loadIdentity(new User($superId));

$pass = 0;
$fail = 0;

/**
 * @param string $what What is being asserted, in words.
 * @param mixed  $got  What the code did.
 * @param mixed  $want What it had to do.
 */
$check = static function (string $what, $got, $want) use (&$pass, &$fail): void {
    if ($got === $want) {
        $pass++;
        echo "  ok    {$what}\n";

        return;
    }

    $fail++;
    echo "  FAIL  {$what}\n        got  " . json_encode($got) . "\n        want " . json_encode($want) . "\n";
};

$tables = [
    '#__alfa_tasks', '#__alfa_inventory_stock', '#__alfa_inventory_movements', '#__alfa_inventory_operations',
    '#__alfa_inventory_reservations', '#__alfa_inventory_effect_outbox', '#__alfa_inventory_integrity_holds',
];
$census = static function () use ($db, $tables): array {
    $counts = [];

    foreach ($tables as $table) {
        $counts[$table] = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName($table))->loadResult();
    }

    return $counts;
};
$before = $census();

// Products that never existed: past the highest id, so nothing of the shop is touched.
$top = (int) $db->setQuery('SELECT COALESCE(MAX(id), 0) FROM ' . $db->quoteName('#__alfa_items'))->loadResult();
$items = [$top + 1003, $top + 1001, $top + 1002];
$mvc = $app->bootComponent('com_alfa')->getMVCFactory();

// ── B1: the threshold, the handler, the controller ───────────────────────────
echo "B1 the threshold and the handler\n";

$check('B1a the request itself repairs at most fifty products', StockRepairJob::INLINE_MAX, 50);
$check('B1b the handler is one of core\'s, under its name', Runner::handler(name: StockRepairJob::NAME) instanceof StockRepairJob, true);
$check('B1c core declares it among its job handlers', \in_array(StockRepairJob::NAME, Runner::coreHandlerNames(), true), true);
$check('B1d its policy names it for staff', Runner::handler(name: StockRepairJob::NAME)?->policy()->label, 'COM_ALFA_TASKS_HANDLER_STOCK_REPAIR');
$controller = (string) file_get_contents(JPATH_COMPONENT_ADMINISTRATOR . '/src/Controller/InventoryauditController.php');
$check('B1e the audit controller sends more than the threshold to the job', str_contains($controller, 'StockRepairJob::INLINE_MAX') && str_contains($controller, 'StockRepairJob::enqueue('), true);

// ── B2: one job per selection ────────────────────────────────────────────────
echo "B2 one job per selection\n";

$check('B2a the scope is the digest of the sorted products', StockRepairJob::scope(itemIds: [3, 1, 2]), StockRepairJob::scope(itemIds: [1, 2, 3]));

$queued = StockRepairJob::enqueue(itemIds: $items, userId: $superId);
$check('B2b the job is written', $queued->id > 0 && !$queued->joined, true);

$row = $db->setQuery(
    $db->getQuery(true)
        ->select($db->quoteName(['handler', 'type', 'state', 'owner', 'scope_key', 'payload']))
        ->from($db->quoteName(Tasks::TABLE))
        ->where($db->quoteName('id') . ' = ' . (int) $queued->id),
)->loadAssoc();
$payload = json_decode((string) ($row['payload'] ?? ''), true);
$check('B2c the row is a waiting job of core, under the handler', [$row['handler'] ?? null, $row['type'] ?? null, $row['state'] ?? null, $row['owner'] ?? null], [StockRepairJob::NAME, Tasks::TYPE_JOB, Tasks::STATE_PENDING, 'com_alfa']);
$check('B2d its scope is the digest of its products', $row['scope_key'] ?? null, StockRepairJob::NAME . ':' . StockRepairJob::scope(itemIds: $items));
$check('B2e its data names the products in the order given and the operator', [$payload['items'] ?? null, $payload['user'] ?? null], [$items, $superId]);

$again = StockRepairJob::enqueue(itemIds: array_reverse($items), userId: $superId);
$check('B2f the same selection in another order joins the waiting job', [$again->id, $again->joined], [$queued->id, true]);

// ── B3: the handler stops at the first refusal ───────────────────────────────
echo "B3 the first refusal ends the run\n";

$handler = new StockRepairJob();
$run = new JobRun(id: $queued->id, handler: StockRepairJob::NAME, scopeKey: (string) $row['scope_key'], payload: $payload, checkpoint: null, doneUnits: 0, totalUnits: 0, trigger: 'cli');
$check('B3a the total is the products named', $handler->total(run: $run), 3);

$chunk = $handler->chunk(run: $run, units: 5);
$result = json_decode((string) $chunk->result, true);
$check('B3b the chunk ends the job at the first product, which does not exist', [$chunk->finished, $chunk->units], [true, 0]);
$check('B3c the result counts the products and none repaired', [$result['total'] ?? null, $result['repaired'] ?? null, $result['corrected'] ?? null, $result['released'] ?? null], [3, 0, 0, 0]);
$check('B3d the result names the product it stopped at, gone', [$result['refused']['item'] ?? null, $result['refused']['gone'] ?? null], [$items[0], true]);
$check('B3e the checkpoint stays at that product', $chunk->checkpoint['next'] ?? null, 0);

// ── B4: the Background screen reads the finished row ─────────────────────────
echo "B4 the Background screen reads it\n";

// The run's end as the runner writes it; the runner itself is not ticked here, so none of the shop's other due work
// runs in this process.
$db->setQuery(
    $db->getQuery(true)
        ->update($db->quoteName(Tasks::TABLE))
        ->set($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_DONE))
        ->set($db->quoteName('result') . ' = ' . $db->quote((string) $chunk->result))
        ->set($db->quoteName('checkpoint') . ' = ' . $db->quote((string) json_encode($chunk->checkpoint)))
        ->set($db->quoteName('done_units') . ' = 0')
        ->set($db->quoteName('total_units') . ' = 3')
        ->set($db->quoteName('started_at') . ' = UTC_TIMESTAMP(3)')
        ->set($db->quoteName('finished_at') . ' = UTC_TIMESTAMP(3)')
        ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
        ->where($db->quoteName('id') . ' = ' . (int) $queued->id),
)->execute();

$read = $db->setQuery(
    $db->getQuery(true)
        ->select($db->quoteName(TaskStaff::COLUMNS))
        ->select(TaskStaff::workSql(db: $db) . ' AS ' . $db->quoteName('work'))
        ->select(TaskStaff::drivenSql(db: $db) . ' AS ' . $db->quoteName('driven'))
        ->select(TaskStaff::checkpointSql(db: $db) . ' AS ' . $db->quoteName('checkpoint'))
        ->from($db->quoteName(Tasks::TABLE))
        ->where($db->quoteName('id') . ' = ' . (int) $queued->id),
)->loadAssoc();
$described = TaskStaff::describe(rows: [$read])['rows'][0];
$check('B4a the row is finished, not driven', [$described['work'], $described['driven']], [TaskStaff::WORK_FINISHED, false]);
$check('B4b its label is the handler\'s for staff', $described['labelText'], Text::_('COM_ALFA_TASKS_HANDLER_STOCK_REPAIR'));
$check('B4c it covers the products of a stock audit section', $described['covers'], Text::_('COM_ALFA_TASKS_COVERS_STOCK_REPAIR'));
$check('B4d its report says how far it got', $described['reportLines'][0] ?? null, Text::sprintf('COM_ALFA_TASKS_STOCK_REPAIR_REPORT', 0, 3, 0, 0));
$check('B4e its report names the product it stopped at and why', $described['reportLines'][1] ?? null, Text::sprintf('COM_ALFA_TASKS_STOCK_REPAIR_STOPPED', $items[0], Text::_('COM_ALFA_INVENTORY_AUDIT_REASON_ITEM_GONE')));
$check('B4f the progress card reads the same report', TaskStaff::job(id: $queued->id)['reportLines'] ?? null, $described['reportLines']);

$listed = static function (array $filters) use ($mvc, $queued): bool {
    /** @var TasksModel $model */
    $model = $mvc->createModel('Tasks', 'Administrator', ['ignore_request' => true]);
    $model->setState('list.limit', 500);
    $model->setState('list.start', 0);
    $model->setState('list.ordering', 'rank');
    $model->setState('list.direction', 'ASC');

    foreach ($filters as $name => $value) {
        $model->setState('filter.' . $name, $value);
    }

    foreach ($model->getItems() as $item) {
        if ((int) $item->id === $queued->id) {
            return true;
        }
    }

    return false;
};
$check('B4g the list finds it under Finished and its kind', $listed(['work' => TaskStaff::WORK_FINISHED, 'kind' => StockRepairJob::NAME]), true);
$check('B4h the list does not find it under Running now', $listed(['work' => TaskStaff::WORK_RUNNING]), false);
$check('B4i the list finds it by its id', $listed(['search' => '#' . $queued->id]), true);
$check('B4j the counts of work count it as finished', (TaskStaff::workCounts()[TaskStaff::WORK_FINISHED] ?? 0) >= 1, true);

/** @var OutboundModel $outbound */
$outbound = $mvc->createModel('Outbound', 'Administrator', ['ignore_request' => true]);
$outbound->setState('list.limit', 5);
$outbound->setState('list.start', 0);
$outbound->setState('list.ordering', 'rank');
$outbound->setState('list.direction', 'ASC');
$outbound->setState('filter.work', TaskStaff::WORK_WAITING);
$check('B4k the deliveries list reads', \is_array($outbound->getItems()), true);

// ── B5: Delete on the screen ─────────────────────────────────────────────────
echo "B5 the delete of finished entries\n";

$deleted = TaskStaff::deleteFinished(ids: [$queued->id, 0, $queued->id]);
$check('B5a the finished row is deleted, once', $deleted, ['deleted' => 1, 'kept' => 0]);
$check('B5b the row is gone', (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName(Tasks::TABLE) . ' WHERE id = ' . (int) $queued->id)->loadResult(), 0);
$check('B5c a row named again is kept, as no longer finished here', TaskStaff::deleteFinished(ids: [$queued->id]), ['deleted' => 0, 'kept' => 1]);

// ── the database as it was ───────────────────────────────────────────────────
echo "census\n";
$check('every table counted is as it was', $census(), $before);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
