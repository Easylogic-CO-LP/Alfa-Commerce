<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Outbound;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Outbound\OutboundTopicsEvent;
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationText;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;
use DomainException;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * The consumers of the shop's changes and what staff do with them: the rows of Tools, Tasks' card of outgoing
 * notifications, the changes given up put back or discarded, a consumer paused and resumed, a webhook's test, and the
 * row of a plugin consumer kept in step with the plugin (its topics when it is saved, paused when it is disabled,
 * removed with it).
 *
 * @since  2.3.0
 */
final class OutboundConsumers
{
    /** Core posts signed JSON to an address. @since 2.3.0 */
    public const KIND_WEBHOOK = 'webhook';

    /** An alfa plugin delivers through its own client. @since 2.3.0 */
    public const KIND_PLUGIN = 'plugin';

    /**
     * The prefix of the notice of a consumer's changes given up: the consumer's id follows.
     *
     * @since  2.3.0
     */
    public const NOTICE = 'alfa.outbound.dead:';

    /**
     * Where the notice leads.
     *
     * @since  2.3.0
     */
    public const URL = 'index.php?option=com_alfa&view=tools#tasks';

    /**
     * The connection, as the driver whose transaction levels Alfa keeps.
     *
     * @since  2.3.0
     */
    public static function db(): DatabaseDriver
    {
        return TransactionState::driver(Factory::getContainer()->get(DatabaseInterface::class));
    }

    /**
     * One consumer's row, or null.
     *
     * @param int $id The consumer.
     *
     * @since  2.3.0
     */
    public static function find(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $db = self::db();

        return $db->setQuery(
            $db->getQuery(true)->select('*')->from($db->quoteName(OutboundFeed::CONSUMERS))->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER),
        )->loadObject() ?: null;
    }

    /**
     * The consumer row core keeps for a plugin, or null when the plugin consumes no change.
     *
     * @api READ ONLY: the consumer id of a plugin consumer, what OutboundFeed::waiting() asks with.
     *
     * @param PluginId $plugin The plugin.
     *
     * @since  2.3.0
     */
    public static function ofPlugin(PluginId $plugin): ?int
    {
        $db = self::db();
        $key = (string) $plugin;
        $id = (int) $db->setQuery(
            $db->getQuery(true)->select($db->quoteName('id'))->from($db->quoteName(OutboundFeed::CONSUMERS))
                ->where($db->quoteName('plugin') . ' = :plugin')->bind(':plugin', $key),
        )->loadResult();

        return $id > 0 ? $id : null;
    }

    /**
     * The card's rows: each consumer with its kind, topics, state, what waits, what was given up, and its last attempt.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.3.0
     */
    public static function cardRows(): array
    {
        $db = self::db();
        $counts = [];

        foreach ($db->setQuery(
            'SELECT ' . $db->quoteName('consumer_id') . ', ' . $db->quoteName('state') . ', COUNT(*) AS ' . $db->quoteName('n')
            . ' FROM ' . $db->quoteName(OutboundFeed::DELIVERIES)
            . ' WHERE ' . $db->quoteName('state') . ' IN (' . $db->quote(OutboundDelivery::STATE_PENDING) . ', ' . $db->quote(OutboundDelivery::STATE_DEAD) . ')'
            . ' GROUP BY ' . $db->quoteName('consumer_id') . ', ' . $db->quoteName('state'),
        )->loadObjectList() ?: [] as $count) {
            $counts[(int) $count->consumer_id][(string) $count->state] = (int) $count->n;
        }

        $rows = [];

        foreach ($db->setQuery(
            'SELECT ' . implode(', ', $db->quoteName(['id', 'kind', 'plugin', 'name', 'url', 'topics', 'state', 'last_attempt_at', 'last_ok', 'last_result']))
            . ' FROM ' . $db->quoteName(OutboundFeed::CONSUMERS) . ' ORDER BY ' . $db->quoteName('name') . ', ' . $db->quoteName('id'),
        )->loadObjectList() ?: [] as $consumer) {
            $id = (int) $consumer->id;
            $rows[] = [
                'id' => $id,
                'kind' => (string) $consumer->kind,
                'plugin' => (string) ($consumer->plugin ?? ''),
                'name' => (string) $consumer->name,
                'host' => (string) (parse_url((string) $consumer->url, PHP_URL_HOST) ?: ''),
                'topics' => OutboundTopics::parse(topics: (string) $consumer->topics),
                'state' => (int) $consumer->state,
                'waiting' => $counts[$id][OutboundDelivery::STATE_PENDING] ?? 0,
                'dead' => $counts[$id][OutboundDelivery::STATE_DEAD] ?? 0,
                'lastAttemptAt' => $consumer->last_attempt_at === null ? '' : (string) $consumer->last_attempt_at,
                'lastOk' => $consumer->last_ok === null ? null : (int) $consumer->last_ok === 1,
                'lastResult' => (string) $consumer->last_result,
            ];
        }

        return $rows;
    }

    /**
     * Put a consumer's changes given up back in the queue, their attempts reset. A stock or price change whose unit
     * already waits again is covered by the waiting one and is discarded instead.
     *
     * @param int $id The consumer.
     *
     * @return int The changes put back.
     *
     * @throws DomainException When there is no such consumer.
     *
     * @since  2.3.0
     */
    public static function retryDead(int $id): int
    {
        $db = self::db();
        self::existing(id: $id);
        $back = 0;
        $db->transactionStart(true);

        try {
            $dead = $db->setQuery(
                'SELECT ' . implode(', ', $db->quoteName(['id', 'topic', 'subject_id', 'unit_id'])) . ' FROM ' . $db->quoteName(OutboundFeed::DELIVERIES)
                . ' WHERE ' . $db->quoteName('consumer_id') . ' = ' . $id . ' AND ' . $db->quoteName('state') . ' = ' . $db->quote(OutboundDelivery::STATE_DEAD)
                . ' ORDER BY ' . $db->quoteName('id') . ' FOR UPDATE',
            )->loadObjectList() ?: [];
            $waiting = [];

            foreach ($dead as $row) {
                $merged = \in_array((string) $row->topic, OutboundTopics::MERGED, true);
                $key = $row->topic . ':' . (int) $row->subject_id . ':' . (int) $row->unit_id;

                if ($merged && ($waiting[$key] ?? OutboundFeed::waiting(consumerId: $id, topic: (string) $row->topic, itemId: (int) $row->subject_id, unitId: (int) $row->unit_id))) {
                    $db->setQuery(
                        'UPDATE ' . $db->quoteName(OutboundFeed::DELIVERIES) . ' SET ' . $db->quoteName('state') . ' = ' . $db->quote(OutboundDelivery::STATE_DISCARDED)
                        . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $row->id,
                    )->execute();

                    continue;
                }

                $db->setQuery(
                    'UPDATE ' . $db->quoteName(OutboundFeed::DELIVERIES) . ' SET ' . $db->quoteName('state') . ' = ' . $db->quote(OutboundDelivery::STATE_PENDING)
                    . ', ' . $db->quoteName('pending') . ' = ' . ($merged ? '1' : 'NULL') . ', ' . $db->quoteName('attempts') . ' = 0, '
                    . $db->quoteName('next_attempt_at') . ' = NULL, ' . $db->quoteName('dead_at') . ' = NULL, ' . $db->quoteName('last_error') . ' = ' . $db->quote('')
                    . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $row->id,
                )->execute();
                $waiting[$key] = $merged;
                $back++;
            }

            TransactionState::commitLevel(db: $db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        self::syncNotice(consumerId: $id);

        return $back;
    }

    /**
     * Discard a consumer's changes given up: never sent, no longer counted, removed with the other settled rows.
     *
     * @param int $id The consumer.
     *
     * @return int The changes discarded.
     *
     * @throws DomainException When there is no such consumer.
     *
     * @since  2.3.0
     */
    public static function discardDead(int $id): int
    {
        $db = self::db();
        self::existing(id: $id);
        $db->setQuery(
            'UPDATE ' . $db->quoteName(OutboundFeed::DELIVERIES) . ' SET ' . $db->quoteName('state') . ' = ' . $db->quote(OutboundDelivery::STATE_DISCARDED)
            . ' WHERE ' . $db->quoteName('consumer_id') . ' = ' . $id . ' AND ' . $db->quoteName('state') . ' = ' . $db->quote(OutboundDelivery::STATE_DEAD),
        )->execute();
        $discarded = (int) $db->getAffectedRows();
        self::syncNotice(consumerId: $id);

        return $discarded;
    }

    /**
     * Pause or resume a consumer: paused, nothing new is queued for it and nothing waiting is sent or lost.
     *
     * @param int $id The consumer.
     * @param bool $delivering True to resume, false to pause.
     *
     * @throws DomainException When there is no such consumer.
     *
     * @since  2.3.0
     */
    public static function setDelivering(int $id, bool $delivering): void
    {
        $db = self::db();
        self::existing(id: $id);
        $db->setQuery(
            'UPDATE ' . $db->quoteName(OutboundFeed::CONSUMERS) . ' SET ' . $db->quoteName('state') . ' = ' . ($delivering ? 1 : 0)
            . ', ' . $db->quoteName('modified') . ' = UTC_TIMESTAMP() WHERE ' . $db->quoteName('id') . ' = ' . $id,
        )->execute();
        OutboundFeed::forget();
    }

    /**
     * Note a consumer's last attempt and what it answered.
     *
     * @param int $id The consumer.
     * @param bool $ok Whether it was taken.
     * @param string $result The answer, or why it failed.
     *
     * @since  2.3.0
     */
    public static function noteAttempt(int $id, bool $ok, string $result): void
    {
        $db = self::db();
        $result = substr($result, 0, 500);
        $db->setQuery(
            $db->getQuery(true)->setQuery(
                'UPDATE ' . $db->quoteName(OutboundFeed::CONSUMERS) . ' SET ' . $db->quoteName('last_attempt_at') . ' = UTC_TIMESTAMP(6), '
                . $db->quoteName('last_ok') . ' = ' . ($ok ? 1 : 0) . ', ' . $db->quoteName('last_result') . ' = :result WHERE ' . $db->quoteName('id') . ' = ' . $id,
            )->bind(':result', $result),
        )->execute();
    }

    /**
     * The notice of a consumer's changes given up: raised, or brought up to date, while any stands; cleared when none
     * does. Never breaks the caller.
     *
     * @param int $consumerId The consumer.
     *
     * @since  2.3.0
     */
    public static function syncNotice(int $consumerId): void
    {
        try {
            $db = self::db();
            $consumer = self::find(id: $consumerId);
            $dead = $consumer === null ? 0 : (int) $db->setQuery(
                'SELECT COUNT(*) FROM ' . $db->quoteName(OutboundFeed::DELIVERIES) . ' WHERE ' . $db->quoteName('consumer_id') . ' = ' . $consumerId
                . ' AND ' . $db->quoteName('state') . ' = ' . $db->quote(OutboundDelivery::STATE_DEAD),
            )->loadResult();
            $key = self::NOTICE . $consumerId;

            if ($dead === 0) {
                if (NotificationHelper::isOpen(dedupKey: $key)) {
                    NotificationHelper::clear(dedupKey: $key);
                }

                return;
            }

            NotificationHelper::push(
                $key,
                NotificationText::of('COM_ALFA_NOTIFY_OUTBOUND_DEAD_TITLE', (string) $consumer->name, $dead),
                [
                    'group' => NotificationHelper::GROUP_GENERAL,
                    'severity' => 'warning',
                    'message' => NotificationText::of('COM_ALFA_NOTIFY_OUTBOUND_DEAD_MSG', (string) $consumer->name, $dead),
                    'dismissible' => false,
                    'url' => self::URL,
                    'view' => ['action' => 'alfa.tools', 'asset' => 'com_alfa'],
                    'link' => ['action' => 'alfa.tools', 'asset' => 'com_alfa'],
                ],
            );
        } catch (Throwable $e) {
            Log::add('Outgoing notifications: the notice of consumer #' . $consumerId . ' could not be brought up to date: ' . ErrorText::of($e), Log::WARNING, OutboundFeed::LOG);
        }
    }

    /**
     * Send a webhook one test change (topic ping), signed as every request, and note what it answered.
     *
     * @param int $id The webhook.
     * @param OutboundDelivery $delivery The delivery that posts it.
     *
     * @return array{ok: bool, result: string} Whether a 2xx came back, and the answer or the refusal (a language key
     *                                         for a refused address).
     *
     * @throws DomainException When there is no such webhook.
     *
     * @since  2.3.0
     */
    public static function sendTest(int $id, OutboundDelivery $delivery = new OutboundDelivery()): array
    {
        $consumer = self::existing(id: $id);

        if ((string) $consumer->kind !== self::KIND_WEBHOOK) {
            throw new DomainException('Only a webhook is sent a test.');
        }

        $body = OutboundDelivery::body(changes: [['id' => 0, 'topic' => OutboundTopics::PING, 'occurred_at' => gmdate('Y-m-d\TH:i:s.000000\Z')]]);

        try {
            $answer = $delivery->post(consumer: $consumer, body: $body, deliveryId: 'test');
            $ok = $answer['status'] >= 200 && $answer['status'] < 300;
            $result = $answer['status'] === 0 ? 'No answer: ' . $answer['error'] : 'HTTP ' . $answer['status'] . ($ok || $answer['body'] === '' ? '' : ': ' . $answer['body']);
        } catch (OutboundAddressRefused $e) {
            $ok = false;
            $result = $e->reason;
        }

        self::noteAttempt(id: $id, ok: $ok, result: $result);

        return ['ok' => $ok, 'result' => $result];
    }

    /**
     * Remove consumers and everything they are owed: the Webhooks screen's delete.
     *
     * @param int[] $ids The consumers.
     *
     * @since  2.3.0
     */
    public static function removeDeliveries(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return;
        }

        $db = self::db();
        $db->setQuery('DELETE FROM ' . $db->quoteName(OutboundFeed::DELIVERIES) . ' WHERE ' . $db->quoteName('consumer_id') . ' IN (' . implode(',', $ids) . ')')->execute();

        foreach ($ids as $id) {
            if (NotificationHelper::isOpen(dedupKey: self::NOTICE . $id)) {
                NotificationHelper::clear(dedupKey: self::NOTICE . $id);
            }
        }

        OutboundFeed::forget();
    }

    /**
     * Keep the rows of the alfa plugins among some extensions in step with them: an enabled plugin that names topics
     * ({@see OutboundTopicsEvent}) has a row with those topics (made delivering, or its topics brought up to date), a
     * disabled one has its row paused, one that names none has no row (removed with what it was owed). Never breaks
     * the caller: a failure is logged.
     *
     * @param int[] $extensionIds Extension ids saved, enabled or disabled.
     *
     * @since  2.3.0
     */
    public static function followPlugins(array $extensionIds): void
    {
        $extensionIds = array_values(array_filter(array_map('intval', $extensionIds), static fn (int $id): bool => $id > 0));

        if ($extensionIds === []) {
            return;
        }

        try {
            $db = self::db();
            $plugins = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['folder', 'element', 'name', 'enabled']))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    ->where($db->quoteName('folder') . ' = ' . $db->quote('alfa'))
                    ->whereIn($db->quoteName('extension_id'), $extensionIds),
            )->loadObjectList() ?: [];

            foreach ($plugins as $plugin) {
                self::follow(db: $db, plugin: PluginId::from(group: 'alfa', element: (string) $plugin->element), name: (string) $plugin->name, enabled: (int) $plugin->enabled === 1);
            }
        } catch (Throwable $e) {
            Log::add('Outgoing notifications: the consumers of the saved plugins could not be kept in step: ' . $e::class . ': ' . ErrorText::of($e), Log::ERROR, OutboundFeed::LOG);
        }
    }

    /**
     * Remove the rows of plugin consumers whose plugin is no longer installed, with what they were owed. Never breaks
     * the caller.
     *
     * @since  2.3.0
     */
    public static function forgetMissingPlugins(): void
    {
        try {
            $db = self::db();
            $gone = $db->setQuery(
                'SELECT ' . $db->quoteName('c.id') . ' FROM ' . $db->quoteName(OutboundFeed::CONSUMERS, 'c')
                . ' WHERE ' . $db->quoteName('c.kind') . ' = ' . $db->quote(self::KIND_PLUGIN)
                . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__extensions', 'e') . ' WHERE ' . $db->quoteName('e.type') . ' = ' . $db->quote('plugin')
                . ' AND CONCAT(' . $db->quoteName('e.folder') . ', ' . $db->quote('.') . ', ' . $db->quoteName('e.element') . ') = ' . $db->quoteName('c.plugin') . ')',
            )->loadColumn() ?: [];

            if ($gone !== []) {
                self::removeDeliveries(ids: $gone);
                $db->setQuery('DELETE FROM ' . $db->quoteName(OutboundFeed::CONSUMERS) . ' WHERE ' . $db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $gone)) . ')')->execute();
            }
        } catch (Throwable $e) {
            Log::add('Outgoing notifications: the consumers of removed plugins could not be removed: ' . $e::class . ': ' . ErrorText::of($e), Log::ERROR, OutboundFeed::LOG);
        }
    }

    /**
     * One plugin's row in step with it.
     *
     * @param DatabaseDriver $db The connection.
     * @param PluginId $plugin The plugin.
     * @param string $name Its name, as staff read it.
     * @param bool $enabled Whether it is enabled.
     *
     * @since  2.3.0
     */
    private static function follow(DatabaseDriver $db, PluginId $plugin, string $name, bool $enabled): void
    {
        $key = (string) $plugin;
        $row = $db->setQuery(
            $db->getQuery(true)->select($db->quoteName(['id', 'state']))->from($db->quoteName(OutboundFeed::CONSUMERS))
                ->where($db->quoteName('plugin') . ' = :plugin')->bind(':plugin', $key),
        )->loadObject();

        if (!$enabled) {
            if ($row !== null && (int) $row->state === 1) {
                self::setDelivering(id: (int) $row->id, delivering: false);
            }

            return;
        }

        $topics = self::topicsOf(plugin: $plugin);

        if ($topics === null) {
            return;
        }

        if ($topics === []) {
            if ($row !== null) {
                self::removeDeliveries(ids: [(int) $row->id]);
                $db->setQuery('DELETE FROM ' . $db->quoteName(OutboundFeed::CONSUMERS) . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $row->id)->execute();
            }

            return;
        }

        $list = implode(',', $topics);
        $kind = self::KIND_PLUGIN;

        if ($row === null) {
            $db->setQuery(
                $db->getQuery(true)->setQuery(
                    'INSERT INTO ' . $db->quoteName(OutboundFeed::CONSUMERS)
                    . ' (' . implode(', ', $db->quoteName(['kind', 'plugin', 'name', 'topics', 'state', 'created', 'modified'])) . ')'
                    . ' VALUES (:kind, :plugin, :name, :topics, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                )->bind(':kind', $kind)->bind(':plugin', $key)->bind(':name', $name)->bind(':topics', $list),
            )->execute();
        } else {
            $db->setQuery(
                $db->getQuery(true)->setQuery(
                    'UPDATE ' . $db->quoteName(OutboundFeed::CONSUMERS) . ' SET ' . $db->quoteName('topics') . ' = :topics, ' . $db->quoteName('name') . ' = :name, '
                    . $db->quoteName('state') . ' = 1, ' . $db->quoteName('modified') . ' = UTC_TIMESTAMP() WHERE ' . $db->quoteName('id') . ' = ' . (int) $row->id,
                )->bind(':topics', $list)->bind(':name', $name),
            )->execute();
        }

        OutboundFeed::forget();
    }

    /**
     * The topics a plugin names, booted alone: an empty list when it does not consume changes, null when it could not
     * be asked (its row is left as it is, logged).
     *
     * @param PluginId $plugin The plugin.
     *
     * @return string[]|null
     *
     * @since  2.3.0
     */
    private static function topicsOf(PluginId $plugin): ?array
    {
        $helper = AlfaPluginHelper::get();

        try {
            $loaded = $helper->bootSingle($plugin);

            if (!$helper->declares($loaded, OutboundTopicsEvent::NAME)) {
                return [];
            }

            $event = new OutboundTopicsEvent();
            $helper->dispatchTo($loaded, $event);

            return $event->getTopics();
        } catch (PluginUnavailable $e) {
            Log::add('Outgoing notifications: the plugin ' . $plugin . ' could not be asked its topics: ' . ErrorText::of($e), Log::WARNING, OutboundFeed::LOG);
        } catch (Throwable $e) {
            Log::add('Outgoing notifications: the plugin ' . $plugin . ' failed when asked its topics: ' . $e::class . ': ' . ErrorText::of($e), Log::ERROR, OutboundFeed::LOG);
        }

        return null;
    }

    /**
     * A consumer's row, refused when there is none.
     *
     * @param int $id The consumer.
     *
     * @throws DomainException When there is no such consumer.
     *
     * @since  2.3.0
     */
    private static function existing(int $id): object
    {
        $consumer = self::find(id: $id);

        if ($consumer === null) {
            throw new DomainException('There is no consumer ' . $id . '.');
        }

        return $consumer;
    }
}
