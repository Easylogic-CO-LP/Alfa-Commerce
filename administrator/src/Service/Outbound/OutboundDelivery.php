<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Outbound;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Outbound\OutboundChange;
use Alfa\Component\Alfa\Administrator\Event\Outbound\OutboundDeliverEvent;
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Closure;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * One delivery of what a consumer is owed: the oldest due changes, up to {@see self::BATCH}, read with their values
 * now and handed to the consumer in one batch, then each settled.
 *
 * - A webhook is posted the batch as signed JSON ({@see WebhookSignature}) at the address the guard vets, pinned to
 *   it. A 2xx answer takes every change of the batch. No answer, a redirect, 408, 425, 429 or a 5xx retries the batch
 *   (a Retry-After longer than the backoff step is honoured); any other 4xx gives the batch up at once, the status and
 *   the first 255 bytes of the answer kept for staff.
 * - A plugin consumer is handed the batch by its targeted onAlfaOutboundDeliver ({@see OutboundDeliverEvent}), its
 *   public method of that name, and answers each change. A plugin with no such method consumes nothing: its changes
 *   settle as no consumer (STATE_NO_CONSUMER), never as a failure. A plugin that cannot be booted leaves the batch
 *   waiting one backoff step, its attempts unchanged.
 *
 * A retried change waits Tasks::BACKOFF_SECONDS for its count of failed attempts (60 seconds, 5 minutes, 30 minutes,
 * 2 hours, 6 hours, 24 hours); after the sixth retry fails it is given up (dead): staff see it in Tools, Tasks and the
 * notice, and may put it back or discard it. A stock or price change given up leaves its unit free to wait again, so
 * the next change of the unit is told with the newest figure. An order's change waits while an older change of the
 * same order is neither sent nor given up, so an order's changes arrive in the order they happened.
 *
 * Settled rows are removed by the consumer's own deliveries: a sent change, or one no consumer took, after
 * {@see self::SENT_KEEP_DAYS} days, a given up or discarded one after {@see self::DEAD_KEEP_DAYS}.
 *
 * @since  2.2.2
 */
final class OutboundDelivery
{
    /** A change that waits to be told. @since 2.2.2 */
    public const STATE_PENDING = 'pending';

    /** A change the consumer took. @since 2.2.2 */
    public const STATE_SENT = 'sent';

    /** A change given up: after the last retry, or refused by the consumer for good. @since 2.2.2 */
    public const STATE_DEAD = 'dead';

    /** A change given up that staff discarded: never sent, no longer counted. @since 2.2.2 */
    public const STATE_DISCARDED = 'discarded';

    /** A change its plugin does not take (the plugin has no onAlfaOutboundDeliver): settled, never an error. @since 2.2.2 */
    public const STATE_NO_CONSUMER = 'no_consumer';

    /**
     * The most changes in one batch.
     *
     * @since  2.2.2
     */
    public const BATCH = 100;

    /**
     * The retries a change is given before it is given up.
     *
     * @since  2.2.2
     */
    public const RETRIES = 6;

    /** Days a sent change is kept. @since 2.2.2 */
    public const SENT_KEEP_DAYS = 7;

    /** Days a change given up or discarded is kept. @since 2.2.2 */
    public const DEAD_KEEP_DAYS = 30;

    /**
     * The User-Agent of a webhook's request.
     *
     * @since  2.2.2
     */
    public const USER_AGENT = 'AlfaCommerce-Webhook';

    /**
     * Rows removed at most a delivery when it tidies its consumer's settled rows.
     *
     * @since  2.2.2
     */
    private const PRUNE_LIMIT = 500;

    /**
     * How an address is vetted before a webhook is posted to it: OutboundAddressGuard::vet() unless another check is
     * given.
     *
     * @var Closure(string): array{host: string, port: int, ip: string}
     *
     * @since  2.2.2
     */
    private readonly Closure $vet;

    /**
     * @param WebhookClient $client The client that posts a webhook's request.
     * @param Closure|null $vet How an address is vetted; null for OutboundAddressGuard::vet().
     *
     * @since  2.2.2
     */
    public function __construct(private readonly WebhookClient $client = new WebhookClient(), ?Closure $vet = null)
    {
        $this->vet = $vet ?? static fn (string $url): array => OutboundAddressGuard::vet(url: $url);
    }

    /**
     * Deliver one batch of a consumer's due changes.
     *
     * @param int $consumerId The consumer.
     *
     * @return array{units: int, more: bool} The changes handed over, and whether more are due now.
     *
     * @since  2.2.2
     */
    public function run(int $consumerId): array
    {
        $db = OutboundConsumers::db();
        $consumer = OutboundConsumers::find(id: $consumerId);

        if ($consumer === null || (int) $consumer->state !== 1) {
            return ['units' => 0, 'more' => false];
        }

        $rows = $this->due(db: $db, consumerId: $consumerId);

        if ($rows === []) {
            $this->prune(db: $db, consumerId: $consumerId);

            return ['units' => 0, 'more' => false];
        }

        $values = OutboundValues::for(rows: $rows);
        $outcome = (string) $consumer->kind === OutboundConsumers::KIND_PLUGIN
            ? $this->toPlugin(consumer: $consumer, rows: $rows, values: $values)
            : $this->toWebhook(consumer: $consumer, rows: $rows, values: $values);

        $this->settle(db: $db, rows: $rows, answers: $outcome['answers'], retryAfter: $outcome['retryAfter']);
        OutboundConsumers::noteAttempt(id: $consumerId, ok: $outcome['ok'], result: $outcome['result']);
        OutboundConsumers::syncNotice(consumerId: $consumerId);

        return ['units' => \count($rows), 'more' => $this->due(db: $db, consumerId: $consumerId, limit: 1) !== []];
    }

    /**
     * The body of a webhook's request for some changes.
     *
     * @param list<array<string, mixed>> $changes The changes as they are told.
     *
     * @since  2.2.2
     */
    public static function body(array $changes): string
    {
        return json_encode(
            ['shop' => OutboundValues::shop(), 'sent_at' => gmdate('Y-m-d\TH:i:s\Z'), 'changes' => $changes],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Post a body to a webhook, signed: what a batch and a test both send.
     *
     * @param object $consumer The webhook's row (url, secret).
     * @param string $body The body.
     * @param string $deliveryId What X-Alfa-Delivery says: the first and last change of the batch.
     *
     * @return array{status: int, body: string, error: string, retryAfter: int|null}
     *
     * @throws OutboundAddressRefused When the address is refused; nothing was sent.
     *
     * @since  2.2.2
     */
    public function post(object $consumer, string $body, string $deliveryId): array
    {
        $url = (string) $consumer->url;
        $vetted = ($this->vet)($url);

        return $this->client->post(url: $url, vetted: $vetted, body: $body, headers: [
            'Content-Type' => 'application/json',
            'User-Agent' => self::USER_AGENT,
            'X-Alfa-Delivery' => $deliveryId,
            WebhookSignature::HEADER => WebhookSignature::header(secret: (string) $consumer->secret, timestamp: time(), body: $body),
        ]);
    }

    /**
     * Hand a batch to a webhook.
     *
     * @param object $consumer The consumer's row.
     * @param list<object> $rows The batch.
     * @param array<int, array<string, mixed>> $values The changes as they are told, by row id.
     *
     * @return array{answers: array<int, array{0: string, 1: string}>, retryAfter: int|null, ok: bool, result: string}
     *
     * @since  2.2.2
     */
    private function toWebhook(object $consumer, array $rows, array $values): array
    {
        $ids = array_map(static fn (object $row): int => (int) $row->id, $rows);
        $all = static fn (string $answer, string $reason): array => array_fill_keys($ids, [$answer, $reason]);

        try {
            $answer = $this->post(consumer: $consumer, body: self::body(changes: array_values(array_intersect_key($values, array_flip($ids)))), deliveryId: min($ids) . '-' . max($ids));
        } catch (OutboundAddressRefused $e) {
            return ['answers' => $all('retry', $e->reason), 'retryAfter' => null, 'ok' => false, 'result' => $e->reason];
        } catch (Throwable $e) {
            Log::add('Outgoing notifications: the webhook #' . (int) $consumer->id . ' could not be posted: ' . $e::class . ': ' . ErrorText::of($e), Log::ERROR, OutboundFeed::LOG);

            return ['answers' => $all('retry', $e::class), 'retryAfter' => null, 'ok' => false, 'result' => $e::class];
        }

        $status = $answer['status'];

        if ($status >= 200 && $status < 300) {
            return ['answers' => $all('delivered', ''), 'retryAfter' => null, 'ok' => true, 'result' => 'HTTP ' . $status];
        }

        if ($status === 0) {
            $reason = 'No answer: ' . $answer['error'];

            return ['answers' => $all('retry', $reason), 'retryAfter' => null, 'ok' => false, 'result' => $reason];
        }

        $reason = 'HTTP ' . $status . ($answer['body'] !== '' ? ': ' . $answer['body'] : '');

        if (self::retriable(status: $status)) {
            return ['answers' => $all('retry', $reason), 'retryAfter' => $answer['retryAfter'], 'ok' => false, 'result' => $reason];
        }

        return ['answers' => $all('refused', $reason), 'retryAfter' => null, 'ok' => false, 'result' => $reason];
    }

    /**
     * Hand a batch to a plugin consumer, booted for the work whatever its Access (the delivery runs as the system, not
     * as the visitor whose request ran the runner): a plugin that is disabled, missing or cannot be loaded holds the
     * batch one backoff step with no attempt counted.
     *
     * @param object $consumer The consumer's row.
     * @param list<object> $rows The batch.
     * @param array<int, array<string, mixed>> $values The changes as they are told, by row id.
     *
     * @return array{answers: array<int, array{0: string, 1: string}>, retryAfter: int|null, ok: bool, result: string}
     *
     * @since  2.2.2
     */
    private function toPlugin(object $consumer, array $rows, array $values): array
    {
        $ids = array_map(static fn (object $row): int => (int) $row->id, $rows);
        $all = static fn (string $answer, string $reason): array => array_fill_keys($ids, [$answer, $reason]);
        $helper = AlfaPluginHelper::get();

        try {
            $plugin = PluginId::of((string) $consumer->plugin);
            $loaded = $helper->bootForWork($plugin);
        } catch (PluginUnavailable $e) {
            return ['answers' => $all('hold', ErrorText::of($e)), 'retryAfter' => null, 'ok' => false, 'result' => ErrorText::of($e)];
        }

        if (!$helper->declares($loaded, OutboundDeliverEvent::NAME)) {
            $reason = 'The plugin ' . $plugin . ' has no ' . OutboundDeliverEvent::NAME . '(): no consumer.';

            return ['answers' => $all('none', $reason), 'retryAfter' => null, 'ok' => true, 'result' => $reason];
        }

        $changes = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $told = $values[$id] ?? [];
            $changes[] = new OutboundChange(
                id: $id,
                topic: (string) $row->topic,
                subjectId: (int) $row->subject_id,
                unitId: (int) $row->unit_id,
                occurredAt: (string) ($told['occurred_at'] ?? ''),
                attempts: (int) $row->attempts,
                values: $told,
            );
        }

        $event = new OutboundDeliverEvent(consumerId: (int) $consumer->id, changes: $changes);

        try {
            $helper->dispatchTo($loaded, $event);
        } catch (Throwable $e) {
            Log::add('Outgoing notifications: the plugin ' . $plugin . ' failed on a batch of ' . \count($ids) . ' changes; every one is retried: ' . $e::class . ': ' . ErrorText::of($e), Log::ERROR, OutboundFeed::LOG);
            $reason = 'The plugin ' . $plugin . ' failed (' . $e::class . '); see the log ' . OutboundFeed::LOG . '.';

            return ['answers' => $all('retry', $reason), 'retryAfter' => null, 'ok' => false, 'result' => $reason];
        }

        $answers = $all('retry', 'The plugin gave no answer for this change.');
        $delivered = 0;

        foreach ($event->answers() as $id => [$answer, $reason]) {
            $answers[$id] = [$answer, $reason];
            $delivered += $answer === 'delivered' ? 1 : 0;
        }

        return ['answers' => $answers, 'retryAfter' => null, 'ok' => $delivered === \count($ids), 'result' => $delivered . ' / ' . \count($ids) . ' delivered'];
    }

    /**
     * Whether a webhook's status is retried: a redirect (not followed), 408, 425, 429 or a 5xx.
     *
     * @param int $status The status.
     *
     * @since  2.2.2
     */
    private static function retriable(int $status): bool
    {
        return ($status >= 300 && $status < 400) || \in_array($status, [408, 425, 429], true) || $status >= 500;
    }

    /**
     * The consumer's oldest due changes: pending and due, in id order, an order's change only while no older change of
     * the same order waits.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $consumerId The consumer.
     * @param int $limit The most rows.
     *
     * @return list<object>
     *
     * @since  2.2.2
     */
    private function due(DatabaseDriver $db, int $consumerId, int $limit = self::BATCH): array
    {
        $pending = self::STATE_PENDING;
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'topic', 'subject_id', 'unit_id', 'payload', 'attempts', 'created']))
                ->from($db->quoteName(OutboundFeed::DELIVERIES))
                ->where($db->quoteName('consumer_id') . ' = :consumer')
                ->where($db->quoteName('state') . ' = :state')
                ->where('(' . $db->quoteName('next_attempt_at') . ' IS NULL OR ' . $db->quoteName('next_attempt_at') . ' <= UTC_TIMESTAMP(6))')
                ->order($db->quoteName('id') . ' ASC')
                ->bind(':consumer', $consumerId, ParameterType::INTEGER)
                ->bind(':state', $pending),
            0,
            $limit === 1 ? self::BATCH : $limit,
        )->loadObjectList() ?: [];

        $orders = [];

        foreach ($rows as $row) {
            if (!\in_array((string) $row->topic, OutboundTopics::MERGED, true)) {
                $orders[(int) $row->subject_id] = true;
            }
        }

        // The oldest waiting change of each order, due or not: only it may go now.
        $first = [];

        if ($orders !== []) {
            $topics = [OutboundTopics::ORDER_CREATED, OutboundTopics::ORDER_STATUS];
            $first = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('subject_id') . ', MIN(' . $db->quoteName('id') . ') AS ' . $db->quoteName('first_id'))
                    ->from($db->quoteName(OutboundFeed::DELIVERIES))
                    ->where($db->quoteName('consumer_id') . ' = :consumer')
                    ->where($db->quoteName('state') . ' = :state')
                    ->whereIn($db->quoteName('topic'), $topics, ParameterType::STRING)
                    ->whereIn($db->quoteName('subject_id'), array_keys($orders))
                    ->group($db->quoteName('subject_id'))
                    ->bind(':consumer', $consumerId, ParameterType::INTEGER)
                    ->bind(':state', $pending),
            )->loadAssocList('subject_id', 'first_id') ?: [];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!\in_array((string) $row->topic, OutboundTopics::MERGED, true) && (int) ($first[(int) $row->subject_id] ?? 0) !== (int) $row->id) {
                continue;
            }

            $out[] = $row;

            if (\count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Settle a batch by its answers, in one transaction: taken, retried on the backoff (given up after the last
     * retry), given up at once, taken by no consumer, or held one backoff step without counting an attempt.
     *
     * @param DatabaseDriver $db The connection.
     * @param list<object> $rows The batch.
     * @param array<int, array{0: string, 1: string}> $answers By row id: delivered, retry, refused, none or hold, and why.
     * @param int|null $retryAfter Seconds the consumer asked to wait, or null.
     *
     * @since  2.2.2
     */
    private function settle(DatabaseDriver $db, array $rows, array $answers, ?int $retryAfter): void
    {
        $db->transactionStart(true);

        try {
            foreach ($rows as $row) {
                $id = (int) $row->id;
                [$answer, $reason] = $answers[$id] ?? ['retry', 'No answer for this change.'];
                $failed = (int) $row->attempts + 1;
                $query = $db->getQuery(true)->update($db->quoteName(OutboundFeed::DELIVERIES))->where($db->quoteName('id') . ' = ' . $id);

                if ($answer === 'delivered') {
                    $query->set($db->quoteName('state') . ' = ' . $db->quote(self::STATE_SENT))
                        ->set($db->quoteName('pending') . ' = NULL')
                        ->set($db->quoteName('sent_at') . ' = UTC_TIMESTAMP(6)')
                        ->set($db->quoteName('last_error') . ' = ' . $db->quote(''));
                } elseif ($answer === 'refused' || ($answer === 'retry' && $failed > self::RETRIES)) {
                    $query->set($db->quoteName('state') . ' = ' . $db->quote(self::STATE_DEAD))
                        ->set($db->quoteName('pending') . ' = NULL')
                        ->set($db->quoteName('attempts') . ' = ' . min(65535, $failed))
                        ->set($db->quoteName('dead_at') . ' = UTC_TIMESTAMP(6)')
                        ->set($db->quoteName('last_error') . ' = ' . $db->quote(substr($reason, 0, 500)));
                } elseif ($answer === 'none') {
                    $query->set($db->quoteName('state') . ' = ' . $db->quote(self::STATE_NO_CONSUMER))
                        ->set($db->quoteName('pending') . ' = NULL')
                        ->set($db->quoteName('sent_at') . ' = UTC_TIMESTAMP(6)')
                        ->set($db->quoteName('last_error') . ' = ' . $db->quote(substr($reason, 0, 500)));
                } elseif ($answer === 'hold') {
                    $wait = Tasks::BACKOFF_SECONDS[min((int) $row->attempts, \count(Tasks::BACKOFF_SECONDS) - 1)];
                    $query->set($db->quoteName('next_attempt_at') . ' = UTC_TIMESTAMP(6) + INTERVAL ' . $wait . ' SECOND')
                        ->set($db->quoteName('last_error') . ' = ' . $db->quote(substr($reason, 0, 500)));
                } else {
                    $wait = max(Tasks::BACKOFF_SECONDS[min($failed - 1, \count(Tasks::BACKOFF_SECONDS) - 1)], min((int) $retryAfter, 86400));
                    $query->set($db->quoteName('attempts') . ' = ' . $failed)
                        ->set($db->quoteName('next_attempt_at') . ' = UTC_TIMESTAMP(6) + INTERVAL ' . $wait . ' SECOND')
                        ->set($db->quoteName('last_error') . ' = ' . $db->quote(substr($reason, 0, 500)));
                }

                $db->setQuery($query)->execute();
            }

            TransactionState::commitLevel(db: $db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }
    }

    /**
     * Remove the consumer's settled rows past their keeping: sent after SENT_KEEP_DAYS, given up or discarded after
     * DEAD_KEEP_DAYS.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $consumerId The consumer.
     *
     * @since  2.2.2
     */
    private function prune(DatabaseDriver $db, int $consumerId): void
    {
        $db->setQuery(
            'DELETE FROM ' . $db->quoteName(OutboundFeed::DELIVERIES) . ' WHERE ' . $db->quoteName('consumer_id') . ' = ' . $consumerId
            . ' AND ((' . $db->quoteName('state') . ' IN (' . $db->quote(self::STATE_SENT) . ', ' . $db->quote(self::STATE_NO_CONSUMER) . ') AND ' . $db->quoteName('sent_at') . ' < UTC_TIMESTAMP(6) - INTERVAL ' . self::SENT_KEEP_DAYS . ' DAY)'
            . ' OR (' . $db->quoteName('state') . ' IN (' . $db->quote(self::STATE_DEAD) . ', ' . $db->quote(self::STATE_DISCARDED) . ') AND ' . $db->quoteName('dead_at') . ' < UTC_TIMESTAMP(6) - INTERVAL ' . self::DEAD_KEEP_DAYS . ' DAY))'
            . ' LIMIT ' . self::PRUNE_LIMIT,
        )->execute();
    }
}
