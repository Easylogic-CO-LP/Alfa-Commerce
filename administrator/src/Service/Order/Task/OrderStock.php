<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\Transition;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRun;

/**
 * order.stock: drain the order's stock obligation after a status change.
 *
 * The obligation stays the stock domain's own record (#__alfa_inventory_effect_outbox): this single only runs its
 * drain first in the change's batch, so the change's other work sees the stock moved. Draining is absolute, so a
 * second run finds nothing left. A drain that leaves the movement owed or refused says so (the stock notice and the
 * timeline) and the obligation keeps its own recovery (Tools > Database Sync), so the task itself always ends done.
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
        return new TaskPolicy(label: 'COM_ALFA_TASKS_HANDLER_ORDER_STOCK', idempotent: true, attention: '');
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    public function run(TaskRun $run): void
    {
        $payload = $run->payload();

        if (self::currentStatus(orderId: $run->subjectId()) === null) {
            return;
        }

        Transition::deliverStockOutcome(
            taskId: $run->id(),
            outcome: Transition::drainFor(orderId: $run->subjectId(), oldStatusId: (int) ($payload['from'] ?? 0), toStatusId: (int) ($payload['to'] ?? 0)),
        );
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
