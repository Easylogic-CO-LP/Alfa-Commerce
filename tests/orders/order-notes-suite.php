<?php

/**
 * ORDER NOTES: THREE NOTES, ONE HISTORY.
 *
 * A status change made on the order screen carries a note to the customer and the choice whether the customer is
 * e-mailed; the status e-mail can be sent again with a note; the internal note is staff's own. Every one of them is on
 * the order's history, and the internal note is never in an e-mail or in what the API publishes.
 *
 *   [P1] the e-mail layout's discovery lists intro and outro and never the note position: no editor is offered for
 *        a note that is typed per send.
 *   [P2] a send with a note fills the note position, escaped, with its label; the shipped layout prints it after the
 *        intro and before the outro.
 *   [S1] ★ a status change saved with a note and the e-mail box ticked moves the order, records the move with the
 *        note and "the customer is e-mailed", and writes the e-mail single with the note and the ask; the History
 *        line says the note.
 *   [S2] ★ the e-mail single attempted the customer at host@easylogic.gr: sent when this run may send mail, else the
 *        attempt is recorded with its reason (would send); its record on the history carries the note when sent.
 *   [S3] a status change that says nothing about the e-mail (the inline list, the API) leaves the choice to the
 *        status: its record carries neither a note nor the e-mail fact.
 *   [A1] ★ the status e-mail sent again by staff with a note: recorded in the operator's name with the note, a new
 *        single carrying the note and the ask, and the History line says the note.
 *   [I1] ★ the internal note saved on the order is stored, its save reads as "Internal note saved" on the History,
 *        and the text is in no e-mail and in no attribute the API renders; the API refuses the key.
 *   [I2] the API's history for the order lists the status changes with their notes to the customer and the e-mail
 *        sent again with its note, and nothing of the internal note.
 *   [T1] every table touched holds exactly the rows it held.
 *
 * Fixtures: one disposable guest order of this run's own, with no lines, whose customer address is
 * host@easylogic.gr; it and every row written about it are removed before the census. No shop stock is touched.
 * Mail is off for the run unless --mail is given AND the site sends mail: only then is the e-mail really sent, to
 * host@easylogic.gr alone.
 *
 *   cd <docroot> && php administrator/components/com_alfa/tests/orders/order-notes-suite.php [--mail]
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
$_SERVER['REQUEST_URI'] = '/administrator/index.php';
$_SERVER['SCRIPT_NAME'] = '/administrator/index.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Alfa\Component\Alfa\Administrator\Helper\OrderHistoryHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Administrator\Service\Order\EmailService;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCreator;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderStatusEmail;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
use Joomla\Session\SessionInterface;

require_once JPATH_ADMINISTRATOR . '/components/com_alfa/tests/harness/CliSessionRow.php';
require_once JPATH_ADMINISTRATOR . '/components/com_alfa/tests/harness/MailOff.php';
require_once JPATH_ADMINISTRATOR . '/components/com_alfa/tests/harness/OrderNotifications.php';
require_once JPATH_ADMINISTRATOR . '/components/com_alfa/tests/harness/SourceFile.php';
CliSessionRow::watch();

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

\defined('JPATH_COMPONENT') || \define('JPATH_COMPONENT', JPATH_ADMINISTRATOR . '/components/com_alfa');
\defined('JPATH_COMPONENT_ADMINISTRATOR') || \define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_ADMINISTRATOR . '/components/com_alfa');
\defined('JPATH_COMPONENT_SITE') || \define('JPATH_COMPONENT_SITE', JPATH_SITE . '/components/com_alfa');

$db = $container->get('DatabaseDriver');
$p = $db->getPrefix();

// The e-mail goes out only when asked for and the site sends mail; otherwise the attempt is recorded as it would be
// on a site with mail off, which is the "would send" record.
$sendsMail = \in_array('--mail', $argv, true) && (bool) $app->get('mailonline', 1);

if (!$sendsMail) {
    MailOff::engage();
}

$pass = 0;
$fail = 0;

/**
 * @param string $what What is being asserted.
 * @param mixed  $got  What happened.
 * @param mixed  $want What had to happen.
 */
$check = static function (string $what, $got, $want) use (&$pass, &$fail): void {
    if ($got === $want) {
        $pass++;

        return;
    }

    $fail++;
    echo "  FAIL {$what}\n";
    echo '    got  ', var_export($got, true), "\n";
    echo '    want ', var_export($want, true), "\n";
};

echo "Order notes: three notes, one history\n", str_repeat('=', 70), "\n\n";
echo $sendsMail ? "  mail: ON, the e-mail goes to host@easylogic.gr\n\n" : "  mail: OFF for this run, the attempt is recorded (would send)\n\n";

// The census, for the no-side-effect proof
$tables = [
    'alfa_orders', 'alfa_order_activity_log', 'alfa_tasks', 'alfa_notifications', 'alfa_user_info',
    'alfa_inventory_operations', 'alfa_inventory_effect_outbox', 'alfa_outbound_deliveries', 'action_logs',
];

$census = static function () use ($db, $p, $tables): array {
    $counts = [];

    foreach ($tables as $table) {
        $counts[$table] = (int) $db->setQuery('SELECT COUNT(*) FROM `' . $p . $table . '`')->loadResult();
    }

    return $counts;
};

$opening = $census();

// The operator: the first Super User, who may edit and move orders.
$operatorId = (int) $db->setQuery(
    "SELECT u.id FROM `{$p}users` u INNER JOIN `{$p}user_usergroup_map` m ON m.user_id = u.id WHERE m.group_id = 8 AND u.block = 0 ORDER BY u.id LIMIT 1",
)->loadResult();

if ($operatorId <= 0) {
    echo "  no Super User to act as; nothing run\n";
    exit(1);
}

$app->loadIdentity(new User($operatorId));

// The statuses: where the order starts, and a published status to move it to, one with customer e-mail texts in the
// default language when the shop has one, so the e-mail has something to carry.
$initial = (int) OrderStatusHelper::getInitialId();
$defaultTag = strtolower(str_replace('-', '_', (string) \Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper::getDefaultLanguageCode()));
$withTexts = (int) $db->setQuery(
    "SELECT s.id FROM `{$p}alfa_orders_statuses` s INNER JOIN `{$p}alfa_orders_statuses_{$defaultTag}` l ON l.id_orderstatus = s.id"
    . " WHERE s.state = 1 AND s.id <> {$initial} AND l.email_positions_customer IS NOT NULL AND l.email_positions_customer <> '' AND l.email_positions_customer <> '[]' AND l.email_positions_customer <> '{}' ORDER BY s.ordering LIMIT 1",
)->loadResult();
$target = $withTexts ?: (int) $db->setQuery(
    "SELECT s.id FROM `{$p}alfa_orders_statuses` s WHERE s.state = 1 AND s.id <> {$initial} ORDER BY s.ordering LIMIT 1",
)->loadResult();

if ($initial <= 0 || $target <= 0) {
    echo "  the shop has no initial status or no second published status; nothing run\n";
    exit(1);
}

echo '  statuses: from #', $initial, ' to #', $target, $withTexts ? ' (has customer e-mail texts)' : ' (no customer e-mail texts: the customer unit fails and says so)', "\n\n";

$factory = $app->bootComponent('com_alfa')->getMVCFactory();
$model = static fn (): OrderModel => $factory->createModel('Order', 'Administrator', ['ignore_request' => true]);

/** The order's history rows of one event, newest first. */
$rowsOf = static function (int $orderId, string $event) use ($db, $p): array {
    return $db->setQuery(
        "SELECT * FROM `{$p}alfa_order_activity_log` WHERE id_order = {$orderId} AND event = " . $db->quote($event) . ' ORDER BY id DESC',
    )->loadObjectList() ?: [];
};

/** The order's e-mail singles, newest first, each with its decoded payload and history. */
$emailTasks = static function (int $orderId) use ($db, $p): array {
    $rows = $db->setQuery(
        "SELECT * FROM `{$p}alfa_tasks` WHERE subject_type = 'order' AND subject_id = {$orderId} AND handler = " . $db->quote(OrderStatusEmail::NAME) . ' ORDER BY id DESC',
    )->loadAssocList() ?: [];

    foreach ($rows as &$row) {
        $row['payload'] = json_decode((string) $row['payload'], true) ?: [];
        $row['history'] = Tasks::decodeHistory($row['history']);
    }

    return $rows;
};

/** A task history's entry for one unit, or null. */
$unitOf = static function (array $task, string $unit): ?array {
    foreach (array_reverse($task['history']) as $entry) {
        if (($entry['unit'] ?? '') === $unit) {
            return $entry;
        }
    }

    return null;
};

$customerUnit = 'customer:host@easylogic.gr';
$orderId = 0;

try {
    // P1 and P2: the layout and the position
    $positions = EmailService::discoverPositions('emails.order.default');
    $check('[P1] the shipped layout asks for intro and outro', \in_array('intro', $positions, true) && \in_array('outro', $positions, true), true);
    $check('[P1] and never offers an editor for the note position', \in_array(EmailService::NOTE_POSITION, $positions, true), false);

    $withNote = new \ReflectionMethod(EmailService::class, 'withNote');
    $render = new \ReflectionMethod(EmailService::class, 'renderEmailHtml');
    $defaults = EmailService::defaultsForLanguage(layoutId: 'emails.order.default', langTag: 'en-GB');
    $noteText = "Your parcel leaves tomorrow <before> noon.\n\nAsk for Maria at the desk.";
    $filled = $withNote->invoke(null, $defaults, $noteText, 'en-GB');

    $check('[P2] a send with a note fills the note position', isset($filled[EmailService::NOTE_POSITION]), true);
    $check('[P2] the note is escaped and split into paragraphs',
        str_contains((string) ($filled['note'] ?? ''), '<p>Your parcel leaves tomorrow &lt;before&gt; noon.</p><p>Ask for Maria at the desk.</p>'), true);
    $check('[P2] the note carries its label', str_contains((string) ($filled['note'] ?? ''), '<h3>A note from the shop</h3>'), true);
    $check('[P2] no note leaves the positions as they are', $withNote->invoke(null, $defaults, '', 'en-GB') === $defaults, true);
    $check('[P2] empty positions stay empty: there is no e-mail to put the note in', $withNote->invoke(null, [], $noteText, 'en-GB'), []);

    $emptyContext = ['items' => [], 'payments' => [], 'shipments' => [], 'downloads' => [], 'totals' => [], 'langTag' => 'en-GB'];
    $html = (string) $render->invoke(null, $filled, [], 'emails.order.default', $emptyContext);
    $introAt = strpos($html, 'Your order <strong>');
    $noteAt = strpos($html, 'Ask for Maria at the desk.');
    $outroAt = strpos($html, 'Thank you for shopping with');
    $check('[P2] the shipped layout prints the note after the intro', $introAt !== false && $noteAt !== false && $introAt < $noteAt, true);
    $check('[P2] and before the outro', $noteAt !== false && $outroAt !== false && $noteAt < $outroAt, true);
    $check('[P2] a send without a note prints no note block',
        str_contains((string) $render->invoke(null, $defaults, [], 'emails.order.default', $emptyContext), 'A note from the shop'), false);

    // The disposable order
    $created = OrderCreator::create(draft: [
        'id_user' => 0,
        'customer_name' => 'Order notes suite',
        'customer_email' => 'host@easylogic.gr',
        'customer_is_guest' => 1,
        'id_order_status' => $initial,
        'id_address_delivery' => 0,
        'id_address_invoice' => 0,
        'source' => 'backend',
        'created_by' => $operatorId,
        'actor_name' => 'Order notes suite',
        'allow_empty' => true,
        'lines' => [],
    ]);
    $orderId = (int) $created->orderId;
    $check('fixture: the order exists in the initial status', OrderModel::persistedStatus($orderId), $initial);

    // S1 and S2: the status change with a note and the e-mail asked
    $statusNote = 'Your order is being prepared; the courier picks it up on Monday.';
    $subject = $model();
    $saved = $subject->save($subject->prefillData($orderId, ['id_order_status' => $target, 'status_note' => $statusNote, 'status_notify' => 1]));
    $app->getMessageQueue(true);
    $check('[S1] the save is accepted', (bool) $saved, true);
    $check('[S1] the order moved', OrderModel::persistedStatus($orderId), $target);

    $moves = $rowsOf($orderId, 'order.status_changed');
    $move = $moves[0] ?? null;
    $moveContext = $move ? (json_decode((string) $move->context, true) ?: []) : [];
    $check('[S1] one status change is recorded', \count($moves), 1);
    $check('[S1] it records the note to the customer', $moveContext['note_to_customer'] ?? null, $statusNote);
    $check('[S1] and that the customer is e-mailed', $moveContext['customer_emailed'] ?? null, true);
    $check('[S1] by the operator', $move ? (int) $move->id_employee : 0, $operatorId);
    $check('[S1] the stored summary names the note', $move ? str_contains((string) $move->summary, 'Note to the customer: ' . $statusNote) : false, true);

    $order = $model()->getItem($orderId);
    $line = $move ? OrderHistoryHelper::line($move, $order) : '';
    $check('[S1] the History line says the note', str_contains($line, 'Note to the customer: ' . $statusNote), true);
    $check('[S1] and that the customer is e-mailed', str_contains($line, 'The customer is e-mailed about it.'), true);

    $tasks = $emailTasks($orderId);
    $emailTask = $tasks[0] ?? null;
    $check('[S1] the e-mail single was written for the status entered', (int) ($emailTask['payload']['status'] ?? 0), $target);
    $check('[S1] it carries the note', $emailTask['payload']['note'] ?? null, $statusNote);
    $check('[S1] and the ask for the customer', $emailTask['payload']['customer_asked'] ?? null, true);

    $unit = $emailTask ? $unitOf($emailTask, $customerUnit) : null;
    $check('[S2] the single attempted the customer at host@easylogic.gr', $unit !== null, true);
    echo '    e-mail single #', (int) ($emailTask['id'] ?? 0), ' ended ', (string) ($emailTask['state'] ?? '?'), '; customer unit: ',
        (string) ($unit['outcome'] ?? 'not attempted'), ($unit['error'] ?? '') !== '' ? ' (' . $unit['error'] . ')' : '', "\n";

    if ($sendsMail && $withTexts) {
        $check('[S2] the customer was sent to', $unit['outcome'] ?? null, 'done');
        $sent = $rowsOf($orderId, 'order.email_sent');
        $sentContext = isset($sent[0]) ? (json_decode((string) $sent[0]->context, true) ?: []) : [];
        $check('[S2] the e-mail record carries the note', $sentContext['note_to_customer'] ?? null, $statusNote);
        $check('[S2] and names the customer reached', ($sentContext['sent'][0]['unit'] ?? null), $customerUnit);
    } elseif (!$sendsMail) {
        $check('[S2] would send: the attempt failed for mail off, with the reason', str_starts_with((string) ($unit['error'] ?? ''), 'Sending mail is switched off'), true);
        $check('[S2] the failure is on the History', \count($rowsOf($orderId, 'order.email_failed')) >= 1, true);
    } else {
        $check('[S2] a status without customer texts says so', str_contains((string) ($unit['error'] ?? ''), 'has no customer e-mail texts'), true);
    }

    // S3: a change that says nothing about the e-mail
    $subject = $model();
    $saved = $subject->save($subject->prefillData($orderId, ['id_order_status' => $initial]));
    $app->getMessageQueue(true);
    $check('[S3] the move back is accepted', (bool) $saved, true);
    $back = $rowsOf($orderId, 'order.status_changed')[0] ?? null;
    $backContext = $back ? (json_decode((string) $back->context, true) ?: []) : [];
    $check('[S3] it is a new record', $back && (int) $back->id !== (int) ($move->id ?? 0), true);
    $check('[S3] with no note to the customer', \array_key_exists('note_to_customer', $backContext), false);
    $check('[S3] and no e-mail fact: the status decides', \array_key_exists('customer_emailed', $backContext), false);

    // A1: the status e-mail sent again
    $againNote = 'Sent again on request; the tracking number follows by SMS.';
    $tasksBefore = \count($emailTasks($orderId));
    $againTask = OrderStatusEmail::sendAgain(orderId: $orderId, statusId: $initial, note: $againNote, userId: $operatorId);
    $check('[A1] a new single is written', $againTask > 0 && \count($emailTasks($orderId)) === $tasksBefore + 1, true);

    $again = $rowsOf($orderId, 'order.email_resent')[0] ?? null;
    $againContext = $again ? (json_decode((string) $again->context, true) ?: []) : [];
    $check('[A1] the ask is recorded in the operator\'s name', $again ? (int) $again->id_employee : 0, $operatorId);
    $check('[A1] with the note', $againContext['note_to_customer'] ?? null, $againNote);
    $check('[A1] naming the single', (int) ($againContext['task'] ?? 0), $againTask);
    $check('[A1] the History line says the note', $again ? str_contains(OrderHistoryHelper::line($again, $order), $againNote) : false, true);

    $againRow = $emailTasks($orderId)[0] ?? null;
    $check('[A1] the single is the one named', (int) ($againRow['id'] ?? 0), $againTask);
    $check('[A1] it carries the note and the ask', ($againRow['payload']['note'] ?? null) === $againNote && ($againRow['payload']['customer_asked'] ?? null) === true, true);
    $check('[A1] the ask is recorded before the single\'s own records',
        $again && ($rowsOf($orderId, 'order.email_sent')[0] ?? $rowsOf($orderId, 'order.email_failed')[0] ?? null) !== null
            ? (int) $again->id < (int) ($rowsOf($orderId, 'order.email_sent')[0] ?? $rowsOf($orderId, 'order.email_failed')[0])->id
            : false,
        true);
    $againUnit = $againRow ? $unitOf($againRow, $customerUnit) : null;
    echo '    e-mail single #', $againTask, ' ended ', (string) ($againRow['state'] ?? '?'), '; customer unit: ',
        (string) ($againUnit['outcome'] ?? 'not attempted'), ($againUnit['error'] ?? '') !== '' ? ' (' . $againUnit['error'] . ')' : '', "\n";

    // I1: the internal note
    $internal = 'Internal: customer called twice, prefers afternoon delivery.';
    $subject = $model();
    $saved = $subject->save($subject->prefillData($orderId, ['note' => $internal]));
    $app->getMessageQueue(true);
    $check('[I1] the save is accepted', (bool) $saved, true);
    $check('[I1] the note is stored on the order', (string) $db->setQuery("SELECT note FROM `{$p}alfa_orders` WHERE id = {$orderId}")->loadResult(), $internal);

    $edit = $rowsOf($orderId, 'order.edited')[0] ?? null;
    $editContext = $edit ? (json_decode((string) $edit->context, true) ?: []) : [];
    $check('[I1] its save is one history entry naming the note alone', array_keys($editContext), ['note']);
    $check('[I1] the History line reads as the internal note saved', $edit ? OrderHistoryHelper::line($edit, $order) : '', 'Internal note saved: ' . $internal);

    $statusRow = $db->setQuery("SELECT email_positions_customer FROM `{$p}alfa_orders_statuses_{$defaultTag}` WHERE id_orderstatus = {$initial}")->loadResult();
    $statusPositions = json_decode((string) $statusRow, true) ?: $defaults;
    $mail = (string) $render->invoke(null, $withNote->invoke(null, $statusPositions, $againNote, 'en-GB'), [], 'emails.order.default', $emptyContext);
    $check('[I1] the internal note is in no e-mail', str_contains($mail, $internal), false);
    $check('[I1] while the note to the customer is', str_contains($mail, $againNote), true);

    $view = new \ReflectionProperty(\Alfa\Component\Alfa\Api\View\Orders\JsonApiView::class, 'fieldsToRenderItem');
    $check('[I1] the API renders no note attribute for one order', \in_array('note', $view->getDefaultValue(), true), false);
    $list = new \ReflectionProperty(\Alfa\Component\Alfa\Api\View\Orders\JsonApiView::class, 'fieldsToRenderList');
    $check('[I1] nor in the list', \in_array('note', $list->getDefaultValue(), true), false);
    $controller = SourceFile::read(JPATH_ROOT . '/api/components/com_alfa/src/Controller/OrdersController.php');
    $check('[I1] the API refuses the key on a create and an update', str_contains($controller, "protected function unwrittenKeys(): array\n    {\n        return ['note'];"), true);

    // I2: the API's history
    $apiModel = $factory->createModel('Order', 'Api', ['ignore_request' => true]);
    $apiItem = $apiModel->getItem($orderId);
    $history = \is_array($apiItem->history ?? null) ? $apiItem->history : [];
    $kinds = array_count_values(array_column($history, 'kind'));
    $check('[I2] two status changes are published', $kinds['status_change'] ?? 0, 2);
    $check('[I2] and the e-mail sent again', $kinds['status_email_again'] ?? 0, 1);
    $notes = array_values(array_filter(array_column($history, 'note')));
    $check('[I2] with the notes to the customer', $notes, [$againNote, $statusNote]);
    $firstMove = array_values(array_filter($history, static fn (array $h): bool => $h['kind'] === 'status_change' && $h['note'] === $statusNote))[0] ?? [];
    $check('[I2] the change says it e-mailed the customer', $firstMove['customer_emailed'] ?? null, true);
    $check('[I2] and its statuses by id and name', ($firstMove['from_status']['id'] ?? 0) === $initial && ($firstMove['to_status']['id'] ?? 0) === $target && ($firstMove['to_status']['name'] ?? '') !== '', true);
    $check('[I2] the internal note is nowhere in the history', str_contains(json_encode($history, JSON_UNESCAPED_UNICODE), $internal), false);
    $check('[I2] no entry names who on staff did it', str_contains(json_encode($history), '"actor"') || str_contains(json_encode($history), 'employee'), false);
} finally {
    // Teardown: only this run's own rows.
    if ($orderId > 0) {
        foreach (['alfa_order_activity_log' => 'id_order', 'alfa_inventory_effect_outbox' => 'order_id'] as $table => $column) {
            $db->setQuery("DELETE FROM `{$p}{$table}` WHERE `{$column}` = {$orderId}")->execute();
        }

        $db->setQuery("DELETE FROM `{$p}alfa_tasks` WHERE subject_type = 'order' AND subject_id = {$orderId}")->execute();
        $db->setQuery("DELETE FROM `{$p}alfa_outbound_deliveries` WHERE topic LIKE 'order.%' AND subject_id = {$orderId}")->execute();
        $db->setQuery("DELETE FROM `{$p}alfa_inventory_operations` WHERE source_type = 'order' AND source_id = {$orderId}")->execute();
        OrderNotifications::forget($db, [$orderId]);
        $db->setQuery("DELETE FROM `{$p}alfa_orders` WHERE id = {$orderId}")->execute();
    }

    $app->loadIdentity(new User(0));
}

$closing = $census();
$residue = [];

foreach ($opening as $table => $count) {
    if ($closing[$table] !== $count) {
        $residue[] = $table . ': ' . $count . ' -> ' . $closing[$table];
    }
}

$check('[T1] every table touched holds exactly the rows it held', $residue, []);

echo "\n", $pass, ' passed, ', $fail, " failed\n";
exit($fail > 0 ? 1 : 0);
