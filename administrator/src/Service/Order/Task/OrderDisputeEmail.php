<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\NotificationText;
use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Administrator\Service\Order\DisputeLedger;
use Alfa\Component\Alfa\Administrator\Service\Order\EmailService;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskFailure;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRun;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskSettlement;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskSettleRecorder;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Throwable;

/**
 * order.dispute_email: the staff e-mail of a card dispute, to the shop's address (Options, General, Store e-mail). Written
 * in the dispute's save when a dispute is recorded or first reported and at its outcome, and by its reminder job three
 * days before the deadline; it says what the dispute's notice says, in the shop's administrator language, with the link to the order. The customer gets no
 * e-mail about a dispute.
 *
 * The recipient is a unit: recorded sent, never sent again. Refused before or by the server: retried with backoff.
 * Handed over with no answer: held for staff (Resend). Sending mail switched off: failed at once, never retried by
 * itself. With no store address set nothing is owed: the save writes no such task.
 *
 * @since  2.2.2
 */
final class OrderDisputeEmail extends OrderTaskHandler implements TaskSettleRecorder
{
    /**
     * The handler's name.
     *
     * @since  2.2.2
     */
    public const NAME = 'order.dispute_email';

    /**
     * The state the reminder e-mail is owed for: the deadline is three days away and no evidence is marked sent.
     *
     * @since  2.2.2
     */
    public const REMINDER = 'reminder';

    /**
     * {@inheritdoc}
     *
     * @since  2.2.2
     */
    public function policy(): TaskPolicy
    {
        return new TaskPolicy(
            label: 'COM_ALFA_TASKS_HANDLER_ORDER_DISPUTE_EMAIL',
            retries: true,
            needsApplication: true,
            staffAction: TaskPolicy::ACTION_RESEND,
            attention: 'danger',
        );
    }

    /**
     * The shop's address the dispute e-mails go to; '' when none is set.
     *
     * @since  2.2.2
     */
    public static function recipient(): string
    {
        return trim((string) ComponentHelper::getParams('com_alfa')->get('store_email', ''));
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.2
     */
    public function run(TaskRun $run): void
    {
        $address = self::recipient();
        $payload = $run->payload();
        $record = DisputeLedger::get(disputeId: (int) ($payload['dispute'] ?? 0));

        // A record deleted with its order owes nothing; a shop that removed its address meanwhile has the bell.
        if ($record === null || $address === '') {
            return;
        }

        $unit = 'store:' . $address;

        if (!$run->shouldAttempt(unit: $unit)) {
            return;
        }

        if (!Factory::getApplication()->get('mailonline', 1)) {
            $run->unit(unit: $unit, outcome: TaskRun::FAILED, error: OrderStatusEmail::MAIL_OFF);

            throw TaskFailure::failed(message: OrderStatusEmail::MAIL_OFF . ', so nothing was sent to ' . $unit . '. Resend it once mail is on.');
        }

        try {
            [$title, $message] = DisputeLedger::noticeTexts(record: $record, state: (string) ($payload['state'] ?? $record->state));
            [$outcome, $error] = EmailService::sendStaffMessage(
                recipient: $address,
                subject: NotificationText::snapshot(text: $title),
                text: NotificationText::snapshot(text: $message),
                orderId: (int) $record->id_order,
            );
        } catch (Throwable $e) {
            // Raised before the message was handed over: nothing was delivered.
            throw TaskFailure::retry(message: $e->getMessage(), previous: $e);
        }

        $run->unit(unit: $unit, outcome: match ($outcome) {
            EmailService::OUTCOME_SENT => TaskRun::DONE,
            EmailService::OUTCOME_UNKNOWN => TaskRun::UNKNOWN,
            default => TaskRun::FAILED,
        }, error: $error);

        if ($outcome === EmailService::OUTCOME_UNKNOWN) {
            throw TaskFailure::unknown(message: 'Handed over with no answer: ' . $unit . '.');
        }

        if ($outcome !== EmailService::OUTCOME_SENT) {
            throw TaskFailure::retry(message: 'Not delivered: ' . $unit . '.');
        }
    }

    /**
     * The record of a sent e-mail on the order's timeline, written in the transaction that settles it.
     *
     * @param TaskSettlement $settlement The run.
     *
     * @since  2.2.2
     */
    public function recordDone(TaskSettlement $settlement): void
    {
        if ($settlement->subjectType !== 'order' || $settlement->subjectId <= 0) {
            return;
        }

        $sent = self::sentUnits(history: $settlement->history);

        if ($sent === []) {
            return;
        }

        $text = self::text('COM_ALFA_ORDER_TASK_EMAIL_SENT', $this->what($settlement->payload), $settlement->taskId, self::sentList(sent: $sent));
        OrderModel::logOrderActivityStrict(
            orderId: $settlement->subjectId,
            event: 'order.email_sent',
            summary: self::english(text: $text),
            context: ['task' => $settlement->taskId, 'handler' => $settlement->handler, 'sent' => $sent, 'text' => $text],
            actorId: 0,
        );
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.2
     */
    protected function what(array $payload): array
    {
        return self::text('COM_ALFA_ORDER_TASK_DISPUTE_EMAIL', (int) ($payload['dispute'] ?? 0));
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.2
     */
    protected function alreadySent(TaskSettlement $settlement): array
    {
        return self::sentUnits(history: $settlement->history);
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.2
     */
    protected function failedSummary(TaskSettlement $settlement, array $what): ?array
    {
        return str_starts_with($settlement->error, OrderStatusEmail::MAIL_OFF)
            ? self::text('COM_ALFA_ORDER_TASK_MAIL_OFF', $what, $settlement->taskId)
            : null;
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.2
     */
    protected function outcomeEvent(string $state): string
    {
        return $state === Tasks::STATE_HELD ? 'order.email_held' : 'order.email_failed';
    }
}
