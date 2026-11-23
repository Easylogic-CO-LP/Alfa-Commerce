<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Outbound;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Catalog\PriceChange;
use Alfa\Component\Alfa\Administrator\Helper\MysqlError;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\OutboundDeliveryJob;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * What the shop owes the consumers of its changes, written by each change's one writer inside the change's own
 * transaction: the stock of a unit (InventoryService), the selling price of a unit (the price index writer), an order
 * placed (OrderCreator) and an order's status (Transition). A rollback takes the rows with the change.
 *
 * A stock or price change waits once per consumer, topic and unit (pending 1, the table's unique key): a second change
 * of the unit before the first is sent joins it, and the value is read when the row is sent, so the consumer is told
 * the newest figure. Order changes are never merged: every status move is told, in order.
 *
 * The consumers are read once and kept for {@see self::MEMO_SECONDS}: a shop with no consumer of a topic pays that one
 * small read and writes nothing. Saving a consumer forgets the memo ({@see self::forget()}).
 *
 * A row that cannot be written never refuses the change: a failure that ended the change's transaction (a deadlock)
 * is rethrown, as any write of the change would be; any other is logged CRITICAL, naming the topic and the subject,
 * and the change commits without its notification (the integrator's daily reconciliation read heals it).
 *
 * @since  2.2.2
 */
final class OutboundFeed
{
    /**
     * The table of consumers.
     *
     * @since  2.2.2
     */
    public const CONSUMERS = '#__alfa_outbound_consumers';

    /**
     * The table of what each consumer is owed.
     *
     * @since  2.2.2
     */
    public const DELIVERIES = '#__alfa_outbound_deliveries';

    /**
     * The log category of outgoing notifications.
     *
     * @since  2.2.2
     */
    public const LOG = 'com_alfa.outbound';

    /**
     * Seconds the consumers read once are kept, so a long run of the task runner sees a consumer saved meanwhile.
     *
     * @since  2.2.2
     */
    private const MEMO_SECONDS = 30;

    /**
     * The delivering consumers of each topic, as read: topic => consumer ids.
     *
     * @var array<string, int[]>|null
     *
     * @since  2.2.2
     */
    private static ?array $byTopic = null;

    /**
     * When the consumers were read (time()).
     *
     * @since  2.2.2
     */
    private static int $readAt = 0;

    /**
     * The consumers whose delivery job this request has already owed, by consumer id, with the connection's count of
     * rollbacks at that moment: a rollback since takes the job with it, so it is owed again.
     *
     * @var array<int, int>
     *
     * @since  2.2.2
     */
    private static array $owed = [];

    /**
     * Record a change for every consumer of its topic, in the caller's transaction.
     *
     * @api WRITE: owe the consumers of a topic one change of a subject; the caller is the change's own writer and calls
     *      it inside the change's transaction. Stock and price are merged a unit; order changes are each kept.
     *
     * @param DatabaseDriver $db The connection holding the change's transaction.
     * @param string $topic One of {@see OutboundTopics::ALL}.
     * @param int $subjectId The product (stock, price) or the order (order changes).
     * @param int $unitId The combination for stock and price; 0 for a product without combinations, and for an order.
     * @param string $payload order.status: the status left and the status entered as from:to; empty otherwise.
     *
     * @throws Throwable A failure that ended the caller's transaction.
     *
     * @since  2.2.2
     */
    public static function record(DatabaseDriver $db, string $topic, int $subjectId, int $unitId = 0, string $payload = ''): void
    {
        if ($subjectId <= 0 || !OutboundTopics::known($topic)) {
            return;
        }

        try {
            $consumers = self::consumersOf(db: $db, topic: $topic);

            foreach ($consumers as $consumerId) {
                self::write(db: $db, consumerId: $consumerId, topic: $topic, subjectId: $subjectId, unitId: max(0, $unitId), payload: $payload);
            }
        } catch (Throwable $e) {
            if (TransactionState::endedBy(db: $db, failure: $e)) {
                throw $e;
            }

            Log::add(
                'Outgoing notifications: the ' . $topic . ' change of #' . $subjectId . ($unitId > 0 ? ' (combination ' . $unitId . ')' : '')
                . ' was not recorded, so no consumer is told of it: ' . $e::class . ': ' . $e->getMessage(),
                Log::CRITICAL,
                self::LOG,
            );
        }
    }

    /**
     * Record the price changes the index writer found in its transaction: a unit whose selling price for the shop's
     * default customer set changed now (the live window's final price), merged a unit.
     *
     * @param DatabaseDriver $db The connection holding the index writer's transaction.
     * @param PriceChange[] $changes What the writer changed.
     * @param string $now The writer's instant, UTC `Y-m-d H:i:s`: the moment the live window holds.
     *
     * @throws Throwable A failure that ended the caller's transaction.
     *
     * @since  2.2.2
     */
    public static function recordPriceChanges(DatabaseDriver $db, array $changes, string $now): void
    {
        if ($changes === [] || !self::wants(db: $db, topic: OutboundTopics::PRICE)) {
            return;
        }

        $terms = OutboundValues::defaultPriceTerms();
        $units = [];

        foreach ($changes as $change) {
            if (!$change instanceof PriceChange || $change->userId !== 0 || $change->oldFinalPrice === $change->newFinalPrice) {
                continue;
            }

            // A window that does not hold now (one that starts later) changes nothing the shop sells at this moment;
            // its opening is told when it opens.
            if (strcmp($change->validFrom, $now) > 0 || ($change->validUntil !== null && strcmp($change->validUntil, $now) <= 0)) {
                continue;
            }

            // The shop's default customer set at no place, when it is known; while no context of it is prepared yet,
            // every set's change is told: a notice too many costs one row, a missed one a wrong figure elsewhere.
            if ($terms !== null && ($change->groupSetId !== $terms['set'] || $change->currencyId !== $terms['currency'] || $change->placeId !== 0)) {
                continue;
            }

            $units[$change->itemId . ':' . $change->combinationId] = [$change->itemId, $change->combinationId];
        }

        foreach ($units as [$itemId, $combinationId]) {
            self::record(db: $db, topic: OutboundTopics::PRICE, subjectId: $itemId, unitId: $combinationId);
        }
    }

    /**
     * Whether any delivering consumer takes a topic: what a writer asks before it does work only notifications need.
     *
     * @api READ ONLY: whether a delivering consumer takes the topic.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $topic One of {@see OutboundTopics::ALL}.
     *
     * @since  2.2.2
     */
    public static function wants(DatabaseDriver $db, string $topic): bool
    {
        try {
            return self::consumersOf(db: $db, topic: $topic) !== [];
        } catch (Throwable $e) {
            Log::add('Outgoing notifications: the consumers could not be read: ' . $e::class . ': ' . $e->getMessage(), Log::WARNING, self::LOG);

            return false;
        }
    }

    /**
     * Whether a change of a unit still waits for a consumer: the two-writer rule of prices. A plugin that writes prices
     * on behalf of its integrator refuses that write while the shop's own change of the unit has not been told yet, so
     * the integrator first learns the shop's price.
     *
     * @api READ ONLY: whether a consumer still owes the telling of a unit's change of a topic (pending, not sent or dead).
     *
     * @param int $consumerId The consumer.
     * @param string $topic stock or price.
     * @param int $itemId The product.
     * @param int $unitId The combination; 0 for a product without combinations.
     *
     * @since  2.2.2
     */
    public static function waiting(int $consumerId, string $topic, int $itemId, int $unitId = 0): bool
    {
        $db = OutboundConsumers::db();
        $pending = 1;

        return (bool) $db->setQuery(
            $db->getQuery(true)
                ->select('1')
                ->from($db->quoteName(self::DELIVERIES))
                ->where($db->quoteName('consumer_id') . ' = :consumer')
                ->where($db->quoteName('topic') . ' = :topic')
                ->where($db->quoteName('subject_id') . ' = :subject')
                ->where($db->quoteName('unit_id') . ' = :unit')
                ->where($db->quoteName('pending') . ' = :pending')
                ->bind(':consumer', $consumerId, ParameterType::INTEGER)
                ->bind(':topic', $topic)
                ->bind(':subject', $itemId, ParameterType::INTEGER)
                ->bind(':unit', $unitId, ParameterType::INTEGER)
                ->bind(':pending', $pending, ParameterType::INTEGER),
            0,
            1,
        )->loadResult();
    }

    /**
     * Forget the consumers read: one was saved, paused or resumed.
     *
     * @since  2.2.2
     */
    public static function forget(): void
    {
        self::$byTopic = null;
        self::$readAt = 0;
    }

    /**
     * The delivering consumers of a topic.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $topic The topic.
     *
     * @return int[]
     *
     * @since  2.2.2
     */
    private static function consumersOf(DatabaseDriver $db, string $topic): array
    {
        if (self::$byTopic === null || time() - self::$readAt >= self::MEMO_SECONDS) {
            $byTopic = [];

            foreach ($db->setQuery(
                'SELECT ' . $db->quoteName('id') . ', ' . $db->quoteName('topics') . ' FROM ' . $db->quoteName(self::CONSUMERS)
                . ' WHERE ' . $db->quoteName('state') . ' = 1',
            )->loadObjectList() ?: [] as $row) {
                foreach (OutboundTopics::parse(topics: (string) $row->topics) as $taken) {
                    $byTopic[$taken][] = (int) $row->id;
                }
            }

            self::$byTopic = $byTopic;
            self::$readAt = time();
        }

        return self::$byTopic[$topic] ?? [];
    }

    /**
     * One consumer's row: a merged topic's waiting row when none waits, an order change always; the consumer's delivery
     * job owed with the first row this request writes for it.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $consumerId The consumer.
     * @param string $topic The topic.
     * @param int $subjectId The subject.
     * @param int $unitId The unit.
     * @param string $payload The payload.
     *
     * @since  2.2.2
     */
    private static function write(DatabaseDriver $db, int $consumerId, string $topic, int $subjectId, int $unitId, string $payload): void
    {
        $merged = \in_array($topic, OutboundTopics::MERGED, true);

        if ($merged && self::waitsAlready(db: $db, consumerId: $consumerId, topic: $topic, subjectId: $subjectId, unitId: $unitId)) {
            return;
        }

        $payload = substr($payload, 0, 255);

        try {
            $db->setQuery(
                $db->getQuery(true)->setQuery(
                    'INSERT INTO ' . $db->quoteName(self::DELIVERIES)
                    . ' (' . implode(', ', $db->quoteName(['consumer_id', 'topic', 'subject_id', 'unit_id', 'payload', 'pending', 'state', 'created'])) . ')'
                    . ' VALUES (:consumer, :topic, :subject, :unit, :payload, ' . ($merged ? '1' : 'NULL') . ', ' . $db->quote(OutboundDelivery::STATE_PENDING) . ', UTC_TIMESTAMP(6))',
                )
                    ->bind(':consumer', $consumerId, ParameterType::INTEGER)
                    ->bind(':topic', $topic)
                    ->bind(':subject', $subjectId, ParameterType::INTEGER)
                    ->bind(':unit', $unitId, ParameterType::INTEGER)
                    ->bind(':payload', $payload),
            )->execute();
        } catch (Throwable $e) {
            // Another change of the unit queued it between the read and the write: already owed.
            if ($merged && MysqlError::number(error: $e) === 1062) {
                return;
            }

            throw $e;
        }

        $rollbacks = TransactionState::rollbacks(db: $db);

        if ((self::$owed[$consumerId] ?? -1) !== $rollbacks || !TransactionState::isOpen(db: $db)) {
            OutboundDeliveryJob::enqueueFor(consumerId: $consumerId);
            self::$owed[$consumerId] = $rollbacks;
        }
    }

    /**
     * Whether a merged topic's row of the unit already waits for the consumer.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $consumerId The consumer.
     * @param string $topic The topic.
     * @param int $subjectId The product.
     * @param int $unitId The combination, or 0.
     *
     * @since  2.2.2
     */
    private static function waitsAlready(DatabaseDriver $db, int $consumerId, string $topic, int $subjectId, int $unitId): bool
    {
        $pending = 1;

        return (bool) $db->setQuery(
            $db->getQuery(true)
                ->select('1')
                ->from($db->quoteName(self::DELIVERIES))
                ->where($db->quoteName('consumer_id') . ' = :consumer')
                ->where($db->quoteName('topic') . ' = :topic')
                ->where($db->quoteName('subject_id') . ' = :subject')
                ->where($db->quoteName('unit_id') . ' = :unit')
                ->where($db->quoteName('pending') . ' = :pending')
                ->bind(':consumer', $consumerId, ParameterType::INTEGER)
                ->bind(':topic', $topic)
                ->bind(':subject', $subjectId, ParameterType::INTEGER)
                ->bind(':unit', $unitId, ParameterType::INTEGER)
                ->bind(':pending', $pending, ParameterType::INTEGER),
            0,
            1,
        )->loadResult();
    }
}
