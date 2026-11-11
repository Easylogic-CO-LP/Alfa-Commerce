<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskFailure;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRun;

/**
 * payment.refund_flip: a payment refunded once its settled refunds cover it.
 *
 * Written by the write that settled a linked refund, inside its transaction ({"payment"}), so a request that ends after
 * the commit cannot lose the flip; run once that transaction committed and Alfa's named locks are released
 * (PaymentLedger::flipIfCovered()). It decides again under the order's lock, from the books as they are then, and
 * writes through a compare-and-set (still completed, covered), so running it twice flips once. A flip whose write
 * failed (the order row busy) is retried with backoff.
 *
 * @since  2.2.0
 */
final class PaymentRefundFlip extends OrderTaskHandler
{
    /**
     * The handler's name.
     *
     * @since  2.2.0
     */
    public const NAME = 'payment.refund_flip';

    /**
     * {@inheritdoc}
     *
     * @since  2.2.0
     */
    public function policy(): TaskPolicy
    {
        return new TaskPolicy(label: 'COM_ALFA_TASKS_HANDLER_PAYMENT_REFUND_FLIP', idempotent: true, retries: true, attention: 'warning');
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.0
     */
    public function run(TaskRun $run): void
    {
        $orderId = $run->subjectId();
        $paymentId = (int) ($run->payload()['payment'] ?? 0);

        if (self::currentStatus(orderId: $orderId) === null || PaymentLedger::statusOf(id: $paymentId) === null) {
            return;
        }

        if (!PaymentLedger::flipIfCovered(paymentId: $paymentId, orderId: $orderId)) {
            throw TaskFailure::retry(message: 'Payment #' . $paymentId . ' could not be marked refunded; it is asked again.');
        }
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.0
     */
    protected function what(array $payload): array
    {
        return self::text('COM_ALFA_ORDER_TASK_REFUND_FLIP', (int) ($payload['payment'] ?? 0));
    }
}
