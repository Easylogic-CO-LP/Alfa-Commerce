<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Service\Download\Entitlements;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRun;

/**
 * order.entitlements: the order's download access, reconciled to its current status.
 *
 * Absolute: a cancelled order is revoked, a status that grants downloads grants them, any other status changes
 * nothing; the same decision a status change has always made, taken on the status the order is in when it runs.
 * Running it twice changes nothing more (a re-fire never mints). A failure is retried with backoff.
 *
 * @since  2.1.4
 */
final class OrderEntitlements extends OrderTaskHandler
{
    /**
     * The handler's name.
     *
     * @since  2.1.4
     */
    public const NAME = 'order.entitlements';

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    public function policy(): TaskPolicy
    {
        return new TaskPolicy(label: 'COM_ALFA_TASKS_HANDLER_ORDER_ENTITLEMENTS', idempotent: true, retries: true, attention: 'warning');
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    public function run(TaskRun $run): void
    {
        $orderId = $run->subjectId();
        $status = self::currentStatus(orderId: $orderId);

        if ($status === null) {
            return;
        }

        // Cancellation always revokes, even if the status also carries the grant flag: a cancelled order must never
        // re-open downloads. Otherwise the status must say so explicitly.
        if (OrderStatusHelper::isCancelled($status)) {
            Entitlements::revokeForOrder(orderId: $orderId);
        } elseif (Entitlements::grantsDownloads($status)) {
            Entitlements::grantForOrder(orderId: $orderId);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    protected function what(array $payload): array
    {
        return self::text('COM_ALFA_ORDER_TASK_ENTITLEMENTS');
    }
}
