<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Background\BackgroundWork;
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Administrator\Service\Order\EmailService;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskFailure;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRun;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskSettlement;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskSettleRecorder;
use Joomla\CMS\Factory;
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
 * Who an e-mail reached outlives its task: a done run writes 'order.email_sent' on the order's timeline in the
 * transaction that settles it, and a failed or held run's record lists the recipients already sent.
 *
 * @since  2.1.4
 */
class OrderStatusEmail extends OrderTaskHandler implements TaskSettleRecorder
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
     * Send the status e-mail again, asked by staff on the order screen: a new single of this handler for the order's
     * current status, carrying the operator's note to the customer. The customer is told whatever the status says
     * (that is what was asked), and the staff the status tells get theirs; the order is read as it stands when the
     * single runs. The ask is recorded on the order's history in the operator's name, then the single is written,
     * both in one transaction: the e-mail's own records follow the ask, and the single runs once the transaction has
     * committed, at once when none was open.
     *
     * @param int $orderId The order.
     * @param int $statusId Its current status.
     * @param string $note The note to the customer; '' for none.
     * @param int $userId Who asked.
     *
     * @return int The single's id.
     *
     * @throws Throwable When the record or the single could not be written (nothing was sent).
     *
     * @since  2.3.0
     */
    public static function sendAgain(int $orderId, int $statusId, string $note, int $userId): int
    {
        $note = trim($note);
        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->transactionStart(true);

        try {
            $task = BackgroundWork::core()->task(self::NAME)->about('order', $orderId)->with([
                'status' => $statusId,
                'customer' => true,
                'customer_asked' => true,
                'note' => $note,
            ])->enqueue()->id;

            $what = self::text('COM_ALFA_ORDER_TASK_STATUS_EMAIL', ['status' => $statusId]);
            $text = $note === ''
                ? self::text('COM_ALFA_ORDER_TASK_EMAIL_AGAIN', $what, $task)
                : self::text('COM_ALFA_ORDER_TASK_EMAIL_AGAIN_NOTE', $what, $task, $note);

            OrderModel::logOrderActivityStrict(
                orderId: $orderId,
                event: 'order.email_resent',
                summary: self::english(text: $text),
                context: ['task' => $task, 'handler' => self::NAME, 'text' => $text] + ($note !== '' ? ['note_to_customer' => $note] : []),
                actorId: $userId,
            );

            TransactionState::commitLevel($db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        return $task;
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
                // false for a change made silent for the customer; a single that carries no such key tells
                // the customer.
                customer: ($run->payload()['customer'] ?? true) !== false,
                // Staff asked for the customer's e-mail (the order screen's tick, or sending it again): the customer
                // is told whatever the status says; the note they typed goes into the e-mail.
                customerAsked: !empty($run->payload()['customer_asked']),
                note: (string) ($run->payload()['note'] ?? ''),
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
            throw TaskFailure::failed(message: ErrorText::of($e), previous: $e);
        } catch (Throwable $e) {
            // Raised before any recipient was sent to: nothing was delivered.
            throw TaskFailure::retry(message: ErrorText::of($e), previous: $e);
        }

        if ($unknown !== []) {
            throw TaskFailure::unknown(message: 'Handed over with no answer: ' . implode(', ', $unknown) . '.');
        }

        // A final failure at once: never retried by itself, so nothing goes out late once mail is on.
        if ($off !== []) {
            throw TaskFailure::failed(message: self::MAIL_OFF . ', so nothing was sent to ' . implode(', ', $off) . '. Resend it once mail is on.');
        }

        if ($failed !== []) {
            throw TaskFailure::retry(message: 'Not delivered: ' . implode(', ', $failed) . '.');
        }
    }

    /**
     * The record of a done e-mail, written in the transaction that settles it: 'order.email_sent' with the e-mail,
     * the task and each recipient it sent with the time it was handed over (a recipient carried from the task it
     * resends is that task's). A task that sent to nobody (the status no longer notifies anyone, the order is gone)
     * leaves none: no e-mail went out. A write that fails throws, and the settle is rolled back.
     *
     * @param TaskSettlement $settlement The run.
     *
     * @since  2.2.0
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

        // With the note the e-mail carried, so the history says what the customer was told and when.
        $note = trim((string) ($settlement->payload['note'] ?? ''));
        $text = $note === ''
            ? self::text('COM_ALFA_ORDER_TASK_EMAIL_SENT', $this->what($settlement->payload), $settlement->taskId, self::sentList(sent: $sent))
            : self::text('COM_ALFA_ORDER_TASK_EMAIL_SENT_NOTE', $this->what($settlement->payload), $settlement->taskId, self::sentList(sent: $sent), $note);
        OrderModel::logOrderActivityStrict(
            orderId: $settlement->subjectId,
            event: 'order.email_sent',
            summary: self::english(text: $text),
            context: ['task' => $settlement->taskId, 'handler' => $settlement->handler, 'sent' => $sent, 'text' => $text]
                + ($note !== '' ? ['note_to_customer' => $note] : []),
            actorId: 0,
        );
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    protected function what(array $payload): array
    {
        return self::text('COM_ALFA_ORDER_TASK_STATUS_EMAIL', ['status' => (int) ($payload['status'] ?? 0)]);
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.0
     */
    protected function alreadySent(TaskSettlement $settlement): array
    {
        return self::sentUnits(history: $settlement->history);
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
