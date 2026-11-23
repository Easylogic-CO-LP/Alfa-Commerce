<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task\Job;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Background\BackgroundWork;
use Alfa\Component\Alfa\Administrator\Background\Enqueued;
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundDelivery;
use Alfa\Component\Alfa\Administrator\Service\Task\DueRead;
use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Throwable;

/**
 * The delivery of what one consumer of the shop's changes is owed ({@see OutboundDelivery}), run by the task runner:
 * one active job a consumer (scope outbound.deliver:<consumer id>), a unit a change handed over, each chunk one batch.
 * The job ends when nothing of its consumer is due now; a change that waits out its backoff is due again later, and
 * the runner owes the consumer a new job then ({@see self::owe()}), so a retry never keeps a job waiting.
 *
 * The job runs outside the runner's chunk transaction (ownTransactions): a batch calls another system.
 *
 * @since  2.3.0
 */
final class OutboundDeliveryJob implements JobHandler
{
    /**
     * The handler's name.
     *
     * @since  2.3.0
     */
    public const NAME = DueRead::OUTBOUND_DELIVER;

    /**
     * Owe a consumer its delivery job: a new one, or the one waiting or running joined (which then runs once more).
     *
     * @param int $consumerId The consumer.
     *
     * @since  2.3.0
     */
    public static function enqueueFor(int $consumerId): Enqueued
    {
        return BackgroundWork::core()->job(self::NAME)->scope((string) $consumerId)->with(['consumer' => $consumerId])->enqueue();
    }

    /**
     * Owe a job to each delivering consumer that has a change due now and no job, read in one statement (the due read's
     * probe, {@see DueRead::outboundOwedWhere()}).
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.3.0
     */
    public static function owe(DatabaseDriver $db): void
    {
        foreach ($db->setQuery(
            'SELECT DISTINCT ' . $db->quoteName('d.consumer_id') . ' FROM ' . $db->quoteName(DueRead::OUTBOUND_DELIVERIES, 'd')
            . ' WHERE ' . DueRead::outboundOwedWhere(db: $db),
            0,
            50,
        )->loadColumn() ?: [] as $consumerId) {
            try {
                self::enqueueFor(consumerId: (int) $consumerId);
            } catch (Throwable $e) {
                Tasks::log(entry: 'Runner: the delivery of consumer #' . (int) $consumerId . ' could not be queued: ' . $e::class . ': ' . ErrorText::of($e), priority: Log::WARNING);
            }
        }
    }

    /**
     * Light, ahead of the shop's catalogue work and behind correctness work, a batch a unit, outside the runner's chunk
     * transaction.
     *
     * @since  2.3.0
     */
    public function policy(): JobPolicy
    {
        return new JobPolicy(
            label: 'COM_ALFA_TASKS_HANDLER_OUTBOUND_DELIVER',
            weight: JobPolicy::LIGHT,
            priority: 6,
            initialUnits: 1,
            rerunOnMerge: true,
            ownTransactions: true,
        );
    }

    /**
     * One unit a change: the changes of the consumer that wait now.
     *
     * @param JobRun $run The job.
     *
     * @since  2.3.0
     */
    public function total(JobRun $run): int
    {
        return 1;
    }

    /**
     * One batch of the consumer's due changes.
     *
     * @param JobRun $run The job.
     * @param int $units Ignored: a chunk is one batch.
     *
     * @since  2.3.0
     */
    public function chunk(JobRun $run, int $units): JobChunk
    {
        $done = (new OutboundDelivery())->run(consumerId: (int) ($run->payload['consumer'] ?? 0));

        return new JobChunk(units: $done['units'], checkpoint: null, finished: !$done['more']);
    }
}
