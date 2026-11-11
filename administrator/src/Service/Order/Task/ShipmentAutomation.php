<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\ShipmentLedger;
use Alfa\Component\Alfa\Administrator\Service\Order\Transition;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskFailure;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRun;

/**
 * shipment.automation: the order status move a shipment's status change owes, per its method's map.
 *
 * Written with the shipment's write, inside its transaction ({"shipment", "method", "from", "to", "order_status"}), so
 * a request that ends after the commit cannot lose the move; run once that transaction committed and Alfa's named locks
 * are released (ShipmentLedger::applyStatusAutomation()). Before it moves anything it checks that the shipment still
 * holds the status the write left, and Transition checks under the order row lock that the order is still in the
 * status it had then (expectStatus()): a run that finds either changed is superseded, moves nothing and leaves one
 * row on the order's timeline, so a late recovery never moves an order that staff or another writer moved meanwhile.
 * Running it twice moves once. A move refused because the order row was busy is retried with backoff.
 *
 * @since  2.2.0
 */
final class ShipmentAutomation extends OrderTaskHandler
{
    /**
     * The handler's name.
     *
     * @since  2.2.0
     */
    public const NAME = 'shipment.automation';

    /**
     * {@inheritdoc}
     *
     * @since  2.2.0
     */
    public function policy(): TaskPolicy
    {
        return new TaskPolicy(label: 'COM_ALFA_TASKS_HANDLER_SHIPMENT_AUTOMATION', idempotent: true, retries: true, attention: 'warning');
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.0
     */
    public function run(TaskRun $run): void
    {
        $orderId = $run->subjectId();
        $payload = $run->payload();
        $shipmentId = (int) ($payload['shipment'] ?? 0);
        $to = (string) ($payload['to'] ?? '');
        if (self::currentStatus(orderId: $orderId) === null) {
            return;
        }

        // The row's guard here; the order's status is asked under Transition's own lock (expectStatus()).
        if (ShipmentLedger::statusOf(id: $shipmentId) !== $to) {
            $this->superseded(run: $run);

            return;
        }

        $outcome = ShipmentLedger::applyStatusAutomation(
            orderId: $orderId,
            shipmentId: $shipmentId,
            methodId: (int) ($payload['method'] ?? 0),
            oldStatus: (string) ($payload['from'] ?? ''),
            newStatus: $to,
            expectedOrderStatus: (int) ($payload['order_status'] ?? 0),
        );

        if ($outcome === Transition::OUTCOME_BUSY) {
            throw TaskFailure::retry(message: 'The order row was busy; the move is asked again.');
        }

        if ($outcome === Transition::OUTCOME_SUPERSEDED) {
            $this->superseded(run: $run);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.0
     */
    protected function what(array $payload): array
    {
        return self::text('COM_ALFA_ORDER_TASK_SHIPMENT_AUTOMATION', (int) ($payload['shipment'] ?? 0));
    }
}
