<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Document\AutoIssuePolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskFailure;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRun;
use Throwable;

/**
 * order.documents: the documents a status issues for the order.
 *
 * For the status entered ({"status": N}), or for the status the order is in now ({"current": true}: the second chance
 * a settled payment gives a status that issues only when paid). Issuing is transactional and issues only what is
 * outstanding, so running it twice issues nothing twice. A document the shop's data refuses (no series, a missing tax
 * number, a refusal of the issue) raises its own alert and is not retried, as before. A failure that reaches this task
 * (a deadlock or a lock wait while issuing, or the status and its rules not read) raises the order's existing `failed`
 * alert and is retried with backoff; the document issued later retires that alert.
 *
 * @since  2.1.4
 */
final class OrderDocuments extends OrderTaskHandler
{
    /**
     * The handler's name.
     *
     * @since  2.1.4
     */
    public const NAME = 'order.documents';

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    public function policy(): TaskPolicy
    {
        return new TaskPolicy(label: 'COM_ALFA_TASKS_HANDLER_ORDER_DOCUMENTS', idempotent: true, retries: true, attention: 'warning');
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    public function run(TaskRun $run): void
    {
        $orderId = $run->subjectId();
        $payload = $run->payload();
        $current = self::currentStatus(orderId: $orderId);

        if ($current === null) {
            return;
        }

        $status = !empty($payload['current']) ? $current : (int) ($payload['status'] ?? 0);

        if ($status <= 0) {
            return;
        }

        try {
            AutoIssuePolicy::issueForStatus(orderId: $orderId, newStatusId: $status);
        } catch (Throwable $e) {
            AutoIssuePolicy::reportFailure(orderId: $orderId, newStatusId: $status, failure: $e);

            throw TaskFailure::retry(message: 'Automatic document issue failed: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    protected function what(array $payload): array
    {
        return !empty($payload['current'])
            ? self::text('COM_ALFA_ORDER_TASK_DOCUMENTS_PAID')
            : self::text('COM_ALFA_ORDER_TASK_DOCUMENTS', (int) ($payload['status'] ?? 0));
    }
}
