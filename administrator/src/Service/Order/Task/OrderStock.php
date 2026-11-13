<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryEffectOutbox;
use Alfa\Component\Alfa\Administrator\Service\Order\Transition;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\StockEffectsDrainJob;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRun;

/**
 * order.stock: drain the order's stock obligation after a status change.
 *
 * The obligation stays the stock domain's own record (#__alfa_inventory_effect_outbox): this single only runs its
 * drain first in the change's batch, so the change's other work sees the stock moved. Draining is absolute, so a
 * second run finds nothing left. A drain that leaves the movement owed or refused says so (the stock notice and the
 * timeline) and the obligation keeps its own recovery: this single owes the stock.effects.drain job (2.2.0), and Tools >
 * Database Sync applies what is owed.
 *
 * A failure around the drain (the order's status not read, a lost connection) is retried with the task engine's
 * backoff, as every other order task's is, and still owes the drain job whatever threw; a task that fails for good
 * shows in Tools > Tasks and the bell (2.2.1).
 *
 * @since  2.1.4
 */
final class OrderStock extends OrderTaskHandler
{
    /**
     * The handler's name.
     *
     * @since  2.1.4
     */
    public const NAME = 'order.stock';

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    public function policy(): TaskPolicy
    {
        return new TaskPolicy(label: 'COM_ALFA_TASKS_HANDLER_ORDER_STOCK', idempotent: true, retries: true, attention: 'warning');
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    public function run(TaskRun $run): void
    {
        $payload = $run->payload();

        try {
            if (self::currentStatus(orderId: $run->subjectId()) === null) {
                return;
            }

            Transition::deliverStockOutcome(
                taskId: $run->id(),
                outcome: Transition::drainFor(orderId: $run->subjectId(), oldStatusId: (int) ($payload['from'] ?? 0), toStatusId: (int) ($payload['to'] ?? 0)),
            );
        } finally {
            // An obligation left owed, by the drain or by a failure around it, is the stock.effects.drain job's (2.2.0).
            if (InventoryEffectOutbox::owesOrderClaim(orderId: $run->subjectId())) {
                StockEffectsDrainJob::enqueue();
            }
        }
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    protected function what(array $payload): array
    {
        return self::text('COM_ALFA_ORDER_TASK_STOCK', (int) ($payload['to'] ?? 0));
    }
}
