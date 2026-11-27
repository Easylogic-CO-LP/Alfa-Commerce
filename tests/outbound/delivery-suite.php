<?php

/**
 * THE OUTGOING NOTIFICATIONS TELL EVERY CHANGE ONCE, IN ORDER, AND TIDY AFTER THEMSELVES.
 *
 * What this pins, on the rows, with the webhook's request replaced by a stand in that answers what each arm needs:
 *
 *   D1   a stock change made while its unit's batch is being delivered is told: the batch's row stops standing for
 *        the unit before the values are read, so the change queues a row of its own, and the delivered row is sent;
 *        a retried row stands for its unit again, unless a newer change waits, when it is discarded as overtaken;
 *   D2   a retried order change takes the order's later changes along to the same moment, so none of them is due
 *        while the oldest waits;
 *   D4   a given up order change put back is discarded as overtaken when a later change of the order was delivered
 *        since, and the count says so;
 *   D5   the waiting changes of a topic the consumer drops are discarded with the reason;
 *   D6   order.created tells the status recorded with the change, not the order's status of the moment;
 *   D8   the retention pass removes settled rows past their keeping of a paused consumer too.
 *
 * Disposable data only: one webhook consumer of its own (a reserved address never posted to), its delivery rows,
 * and the delivery job rows its changes owe; every one removed at the end, and a census of the tables before and after
 * fails the run on any difference. No order or product of the shop is touched: the subjects are ids past the highest.
 *
 * Run from the docroot:
 *
 *   cd <docroot> && php administrator/components/com_alfa/tests/outbound/delivery-suite.php
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

use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundConsumers;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundDelivery;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundFeed;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundTopics;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundValues;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRetention;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
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

$db = $container->get('DatabaseDriver');
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

$tables = [OutboundFeed::CONSUMERS, OutboundFeed::DELIVERIES, Tasks::TABLE];
$census = static function () use ($db, $tables): array {
    $counts = [];

    foreach ($tables as $table) {
        $counts[$table] = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName($table))->loadResult();
    }

    return $counts;
};
$before = $census();

// The suite's consumer: a webhook of every topic at an address that is never posted to (the stand in answers).
$db->setQuery(
    'INSERT INTO ' . $db->quoteName(OutboundFeed::CONSUMERS) . ' (' . implode(', ', $db->quoteName(['kind', 'name', 'url', 'secret', 'topics', 'state', 'created', 'modified'])) . ')'
    . ' VALUES (' . $db->quote(OutboundConsumers::KIND_WEBHOOK) . ', ' . $db->quote('Delivery suite ' . bin2hex(random_bytes(3))) . ', ' . $db->quote('https://suite.invalid/hook')
    . ', ' . $db->quote('suite') . ', ' . $db->quote(implode(',', OutboundTopics::ALL)) . ', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
)->execute();
$consumer = (int) $db->insertid();
OutboundFeed::forget();

$top = (int) $db->setQuery('SELECT COALESCE(MAX(id), 0) FROM ' . $db->quoteName('#__alfa_items'))->loadResult();
$product = $top + 1001;
$order = (int) $db->setQuery('SELECT COALESCE(MAX(id), 0) FROM ' . $db->quoteName('#__alfa_orders'))->loadResult() + 1001;

$row = static function (string $topic, int $subject, string $state = OutboundDelivery::STATE_PENDING, array $more = []) use ($db, $consumer): int {
    $columns = ['consumer_id' => $consumer, 'topic' => $topic, 'subject_id' => $subject, 'unit_id' => 0, 'payload' => '', 'state' => $state,
        'pending' => $state === OutboundDelivery::STATE_PENDING && \in_array($topic, OutboundTopics::MERGED, true) ? 1 : null, 'attempts' => 0] + $more;
    $values = [];

    foreach ($columns as $column => $value) {
        $values[] = $value === null ? 'NULL' : (\is_int($value) ? (string) $value : (str_starts_with((string) $value, 'UTC_TIMESTAMP') ? $value : $db->quote((string) $value)));
    }

    $db->setQuery(
        'INSERT INTO ' . $db->quoteName(OutboundFeed::DELIVERIES) . ' (' . implode(', ', $db->quoteName(array_keys($columns))) . ', ' . $db->quoteName('created') . ')'
        . ' VALUES (' . implode(', ', $values) . ', UTC_TIMESTAMP(6))',
    )->execute();

    return (int) $db->insertid();
};
$read = static fn (int $id): ?array => $db->setQuery(
    $db->getQuery(true)->select('*')->from($db->quoteName(OutboundFeed::DELIVERIES))->where($db->quoteName('id') . ' = ' . $id),
)->loadAssoc() ?: null;
$deliver = static function (callable $answer) use ($consumer): array {
    $delivery = new OutboundDelivery(
        vet: static fn (string $url): array => ['host' => 'suite.invalid', 'port' => 443, 'ip' => '192.0.2.1'],
        post: static fn (string $url, array $vetted, string $body, array $headers): array => $answer($body),
    );

    return $delivery->run(consumerId: $consumer);
};
$ok = static fn (string $body): array => ['status' => 200, 'body' => '', 'error' => '', 'retryAfter' => null];
$down = static fn (string $body): array => ['status' => 503, 'body' => '', 'error' => '', 'retryAfter' => null];

try {
    // ── D1: a change during the delivery ────────────────────────────────────
    echo "D1 a change made while the batch is delivered\n";

    $stock = $row(OutboundTopics::STOCK, $product);
    $during = 0;
    $result = $deliver(static function (string $body) use ($db, $product, $ok, &$during): array {
        // The stock changes again while the request is out: recorded as every change is.
        OutboundFeed::record(db: $db, topic: OutboundTopics::STOCK, subjectId: $product);
        $during = (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName(OutboundFeed::DELIVERIES) . ' WHERE ' . $db->quoteName('subject_id') . ' = ' . $product . ' AND ' . $db->quoteName('pending') . ' = 1',
        )->loadResult();

        return $ok($body);
    });
    $check('D1a the batch was delivered', $result['units'], 1);
    $check('D1b the change made during the request queued a row of its own', $during, 1);
    $check('D1c the delivered row is sent, no longer standing for the unit', [$read($stock)['state'], $read($stock)['pending']], [OutboundDelivery::STATE_SENT, null]);
    $later = $db->setQuery(
        'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName(OutboundFeed::DELIVERIES) . ' WHERE ' . $db->quoteName('consumer_id') . ' = ' . $consumer
        . ' AND ' . $db->quoteName('subject_id') . ' = ' . $product . ' AND ' . $db->quoteName('state') . ' = ' . $db->quote(OutboundDelivery::STATE_PENDING),
    )->loadColumn();
    $check('D1d the newer change waits to be told', \count($later), 1);

    $deliver($down);
    $check('D1e a retried row stands for its unit again', [$read((int) $later[0])['state'], (int) $read((int) $later[0])['pending']], [OutboundDelivery::STATE_PENDING, 1]);
    OutboundFeed::record(db: $db, topic: OutboundTopics::PRICE, subjectId: $product);
    $price = (int) $db->setQuery(
        'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName(OutboundFeed::DELIVERIES) . ' WHERE ' . $db->quoteName('consumer_id') . ' = ' . $consumer
        . ' AND ' . $db->quoteName('topic') . ' = ' . $db->quote(OutboundTopics::PRICE) . ' AND ' . $db->quoteName('subject_id') . ' = ' . $product,
    )->loadResult();
    $deliver(static function (string $body) use ($db, $product, $down): array {
        OutboundFeed::record(db: $db, topic: OutboundTopics::PRICE, subjectId: $product);

        return $down($body);
    });
    $check('D1f a retried row whose unit has a newer waiting change is discarded as overtaken', [$read($price)['state'], $read($price)['last_error']], [OutboundDelivery::STATE_DISCARDED, 'COM_ALFA_OUTBOUND_REASON_OVERTAKEN']);

    // ── D2: an order's later changes wait with the oldest ───────────────────
    echo "D2 an order's changes wait together\n";

    $db->setQuery(
        'UPDATE ' . $db->quoteName(OutboundFeed::DELIVERIES) . ' SET ' . $db->quoteName('state') . ' = ' . $db->quote(OutboundDelivery::STATE_DISCARDED) . ', ' . $db->quoteName('pending') . ' = NULL, '
        . $db->quoteName('dead_at') . ' = UTC_TIMESTAMP(6) WHERE ' . $db->quoteName('consumer_id') . ' = ' . $consumer . ' AND ' . $db->quoteName('state') . ' = ' . $db->quote(OutboundDelivery::STATE_PENDING),
    )->execute();
    $first = $row(OutboundTopics::ORDER_CREATED, $order, more: ['payload' => '3']);
    $second = $row(OutboundTopics::ORDER_STATUS, $order, more: ['payload' => '3:4']);
    $deliver($down);
    $check('D2a the oldest change waits out its backoff', $read($first)['next_attempt_at'] !== null && (int) $read($first)['attempts'] === 1, true);
    $check('D2b the later change waits to the same moment', $read($second)['next_attempt_at'], $read($first)['next_attempt_at']);
    $check('D2c nothing of the order is due now', $deliver($ok)['units'], 0);

    // ── D4: a put back order change overtaken ───────────────────────────────
    echo "D4 put back\n";

    $db->setQuery('UPDATE ' . $db->quoteName(OutboundFeed::DELIVERIES) . ' SET ' . $db->quoteName('state') . ' = ' . $db->quote(OutboundDelivery::STATE_DEAD) . ', ' . $db->quoteName('dead_at') . ' = UTC_TIMESTAMP(6) WHERE ' . $db->quoteName('id') . ' = ' . $first)->execute();
    $db->setQuery('UPDATE ' . $db->quoteName(OutboundFeed::DELIVERIES) . ' SET ' . $db->quoteName('state') . ' = ' . $db->quote(OutboundDelivery::STATE_SENT) . ', ' . $db->quoteName('sent_at') . ' = UTC_TIMESTAMP(6), ' . $db->quoteName('next_attempt_at') . ' = NULL WHERE ' . $db->quoteName('id') . ' = ' . $second)->execute();
    $put = OutboundConsumers::retryDead(id: $consumer);
    $check('D4a the overtaken order change is discarded, not put back, and counted', [$put['back'], $put['overtaken'], $read($first)['state']], [0, 1, OutboundDelivery::STATE_DISCARDED]);

    // ── D5: a topic dropped ─────────────────────────────────────────────────
    echo "D5 a topic dropped\n";

    $dispute = $row(OutboundTopics::ORDER_DISPUTE, $order);
    $kept = $row(OutboundTopics::STOCK, $product + 1);
    $check('D5a the waiting changes of the dropped topic are discarded with the reason', [OutboundConsumers::discardTopics(id: $consumer, topics: [OutboundTopics::ORDER_DISPUTE]), $read($dispute)['state'], $read($dispute)['last_error']], [1, OutboundDelivery::STATE_DISCARDED, 'COM_ALFA_OUTBOUND_REASON_TOPIC_DROPPED']);
    $check('D5b the other topics\' changes still wait', $read($kept)['state'], OutboundDelivery::STATE_PENDING);

    // ── D6: the opening status ──────────────────────────────────────────────
    echo "D6 order.created\n";

    $values = OutboundValues::for(rows: [(object) ['id' => 1, 'topic' => OutboundTopics::ORDER_CREATED, 'subject_id' => $order, 'unit_id' => 0, 'payload' => '3', 'created' => gmdate('Y-m-d H:i:s')]]);
    $check('D6a the status told is the one recorded with the change', $values[1]['status_id'] ?? null, 3);
    $values = OutboundValues::for(rows: [(object) ['id' => 2, 'topic' => OutboundTopics::ORDER_CREATED, 'subject_id' => $order, 'unit_id' => 0, 'payload' => '', 'created' => gmdate('Y-m-d H:i:s')]]);
    $check('D6b a row recorded without it tells status_id null, the field present', [\array_key_exists('status_id', $values[2] ?? []), $values[2]['status_id'] ?? 'missing'], [true, null]);

    // ── D8: retention of a paused consumer's rows ───────────────────────────
    echo "D8 retention\n";

    $db->setQuery('UPDATE ' . $db->quoteName(OutboundFeed::CONSUMERS) . ' SET ' . $db->quoteName('state') . ' = 0 WHERE ' . $db->quoteName('id') . ' = ' . $consumer)->execute();
    $old = $row(OutboundTopics::STOCK, $product + 2, OutboundDelivery::STATE_SENT, ['sent_at' => 'UTC_TIMESTAMP(6) - INTERVAL ' . (OutboundDelivery::SENT_KEEP_DAYS + 1) . ' DAY']);
    $fresh = $row(OutboundTopics::STOCK, $product + 3, OutboundDelivery::STATE_SENT, ['sent_at' => 'UTC_TIMESTAMP(6)']);
    TaskRetention::prune(budgetMs: 2000);
    $check('D8a a paused consumer\'s row past its keeping is gone', $read($old), null);
    $check('D8b a row within its keeping stays', $read($fresh)['state'] ?? null, OutboundDelivery::STATE_SENT);
} finally {
    // Only what the suite made goes: its rows, its consumer and the delivery jobs its changes owed.
    $db->setQuery('DELETE FROM ' . $db->quoteName(OutboundFeed::DELIVERIES) . ' WHERE ' . $db->quoteName('consumer_id') . ' = ' . $consumer)->execute();
    $db->setQuery('DELETE FROM ' . $db->quoteName(OutboundFeed::CONSUMERS) . ' WHERE ' . $db->quoteName('id') . ' = ' . $consumer)->execute();
    $db->setQuery('DELETE FROM ' . $db->quoteName(Tasks::TABLE) . ' WHERE ' . $db->quoteName('scope_key') . ' = ' . $db->quote('outbound.deliver:' . $consumer))->execute();
    OutboundFeed::forget();
}

echo "census\n";
$check('every table counted is as it was', $census(), $before);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
