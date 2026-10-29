<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\EmailService;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskFailure;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRun;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskSettlement;
use Throwable;

/**
 * order.status_email: one status e-mail, for the status the order entered.
 *
 * Each recipient is a unit, and its outcome is written to the task's history as it comes: a recipient recorded sent
 * is never sent again. A recipient refused before the message was handed over, or in the server's answer to it, is
 * retried with backoff, alone. One handed over with no answer may have been delivered: the task is held and staff
 * decide (Resend sends to the recipients not recorded as sent). With sending mail switched off for the site (Joomla's
 * Send Mail: No) nothing is sent and the task fails at once, never retried by itself: staff resend it once mail is on,
 * so a copy of a shop kept with mail off sends nothing late. An order's e-mails go in the order they were written
 * (the sequence order.email), and each names the status it was made for.
 *
 * @since  2.1.4
 */
class OrderStatusEmail extends OrderTaskHandler
{
    /**
     * The handler's name.
     *
     * @since  2.1.4
     */
    public const NAME = 'order.status_email';

    /**
     * The sequence an order's e-mails run in.
     *
     * @since  2.1.4
     */
    public const SEQUENCE = 'order.email';

    /**
     * How the failure of a task whose e-mail found sending mail switched off begins.
     *
     * @since  2.1.4
     */
    public const MAIL_OFF = 'Sending mail is switched off';

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    public function policy(): TaskPolicy
    {
        return new TaskPolicy(
            label: 'COM_ALFA_TASKS_HANDLER_ORDER_STATUS_EMAIL',
            retries: true,
            needsApplication: true,
            staffAction: TaskPolicy::ACTION_RESEND,
            attention: 'danger',
            sequence: self::SEQUENCE,
        );
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    public function run(TaskRun $run): void
    {
        $failed = [];
        $unknown = [];
        $off = [];

        try {
            EmailService::deliverForStatus(
                orderId: $run->subjectId(),
                statusId: (int) ($run->payload()['status'] ?? 0),
                attempt: static fn (string $unit): bool => $run->shouldAttempt(unit: $unit),
                outcome: static function (string $unit, string $outcome, string $error) use ($run, &$failed, &$unknown, &$off): void {
                    $run->unit(unit: $unit, outcome: match ($outcome) {
                        EmailService::OUTCOME_SENT => TaskRun::DONE,
                        EmailService::OUTCOME_UNKNOWN => TaskRun::UNKNOWN,
                        default => TaskRun::FAILED,
                    }, error: $error);

                    if ($outcome === EmailService::OUTCOME_UNKNOWN) {
                        $unknown[] = $unit;
                    } elseif ($outcome === EmailService::OUTCOME_MAIL_OFF) {
                        $off[] = $unit;
                    } elseif ($outcome !== EmailService::OUTCOME_SENT) {
                        $failed[] = $unit;
                    }
                },
            );
        } catch (TaskFailure $e) {
            throw $e;
        } catch (OrderCurrencyException $e) {
            // No e-mail states an amount of an order that names no currency: a retry meets the same refusal.
            throw TaskFailure::failed(message: $e->getMessage(), previous: $e);
        } catch (Throwable $e) {
            // Raised before any recipient was sent to: nothing was delivered.
            throw TaskFailure::retry(message: $e->getMessage(), previous: $e);
        }

        if ($unknown !== []) {
            throw TaskFailure::unknown(message: 'Handed over with no answer: ' . implode(', ', $unknown) . '.');
        }

        // A final failure at once (s68): never retried by itself, so nothing goes out late once mail is on.
        if ($off !== []) {
            throw TaskFailure::failed(message: self::MAIL_OFF . ', so nothing was sent to ' . implode(', ', $off) . '. Resend it once mail is on.');
        }

        if ($failed !== []) {
            throw TaskFailure::retry(message: 'Not delivered: ' . implode(', ', $failed) . '.');
        }
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    protected function what(array $payload): array
    {
        return self::text('COM_ALFA_ORDER_TASK_STATUS_EMAIL', (int) ($payload['status'] ?? 0));
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    protected function failedSummary(TaskSettlement $settlement, array $what): ?array
    {
        return str_starts_with($settlement->error, self::MAIL_OFF)
            ? self::text('COM_ALFA_ORDER_TASK_MAIL_OFF', $what, $settlement->taskId)
            : null;
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    protected function outcomeEvent(string $state): string
    {
        return $state === Tasks::STATE_HELD ? 'order.email_held' : 'order.email_failed';
    }
}
