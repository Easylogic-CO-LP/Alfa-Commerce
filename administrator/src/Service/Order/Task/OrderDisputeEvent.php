<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Payments\DisputeChangedEvent;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Service\Order\DisputeLedger;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskFailure;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRun;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Joomla\CMS\Log\Log;
use Throwable;

/**
 * order.dispute_event: onAlfaDisputeChanged, once, after a dispute's committed save, written in that save only when the
 * event has a listener. Each listener is a unit and its completion is written to the task's history as it goes; a
 * listener that throws is recorded with its error and the others still run. The task then ends failed, never run again
 * by itself; staff press Run again, which calls the listeners not recorded as completed.
 *
 * @since  2.2.2
 */
final class OrderDisputeEvent extends OrderTaskHandler
{
    /**
     * The handler's name.
     *
     * @since  2.2.2
     */
    public const NAME = 'order.dispute_event';

    /**
     * {@inheritdoc}
     *
     * @since  2.2.2
     */
    public function policy(): TaskPolicy
    {
        return new TaskPolicy(
            label: 'COM_ALFA_TASKS_HANDLER_ORDER_DISPUTE_EVENT',
            needsApplication: true,
            staffAction: TaskPolicy::ACTION_RERUN,
            attention: 'danger',
        );
    }

    /**
     * Whether onAlfaDisputeChanged has a listener once the alfa group is imported; owed when the listeners cannot be
     * known (the group not imported, a plugin of it that failed to load).
     *
     * @since  2.2.2
     */
    public static function hasListeners(): bool
    {
        $plugins = AlfaPluginHelper::get();
        $failed = $plugins->importGroup('alfa');

        if ($failed === null || $failed !== []) {
            return true;
        }

        return $plugins->hasListeners(DisputeChangedEvent::NAME);
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.2
     */
    public function run(TaskRun $run): void
    {
        $payload = $run->payload();
        $disputeId = (int) ($payload['dispute'] ?? 0);
        $record = DisputeLedger::get(disputeId: $disputeId);
        $event = new DisputeChangedEvent(DisputeChangedEvent::NAME, [
            'dispute' => $record === null ? null : (array) $record,
            'disputeId' => $disputeId,
            'orderId' => $run->subjectId(),
            'paymentId' => (int) ($payload['payment'] ?? 0),
            'stateBefore' => (string) ($payload['from'] ?? ''),
            'stateAfter' => (string) ($payload['to'] ?? ''),
            'booked' => (string) ($payload['booked'] ?? '0'),
        ]);
        $plugins = AlfaPluginHelper::get();
        $plugins->importGroup('alfa');
        $failed = [];

        foreach ($plugins->listeners(DisputeChangedEvent::NAME) as $unit => $listener) {
            if (!$run->shouldAttempt(unit: $unit)) {
                continue;
            }

            try {
                $listener($event);
                $run->unit(unit: $unit, outcome: TaskRun::DONE);
            } catch (Throwable $e) {
                $failed[] = $unit;
                $run->unit(unit: $unit, outcome: TaskRun::FAILED, error: $e::class . ': ' . $e->getMessage());
                Log::add(
                    entry:    DisputeChangedEvent::NAME . ' listener ' . $unit . ' failed for dispute #' . $disputeId . ': ' . $e->getMessage(),
                    priority: Log::ERROR,
                    category: 'com_alfa.orders',
                );
            }
        }

        if ($failed !== []) {
            throw TaskFailure::failed(message: DisputeChangedEvent::NAME . ' listener failed: ' . implode(', ', $failed) . '.');
        }
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.2
     */
    protected function what(array $payload): array
    {
        return self::text('COM_ALFA_ORDER_TASK_DISPUTE_EVENT', (int) ($payload['dispute'] ?? 0));
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.2
     */
    protected function outcomeEvent(string $state): string
    {
        return $state === Tasks::STATE_HELD ? 'order.hook_held' : 'order.hook_failed';
    }
}
