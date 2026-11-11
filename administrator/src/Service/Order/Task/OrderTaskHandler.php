<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRun;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskSettleListener;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskSettlement;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Log\Log;
use Joomla\Database\ParameterType;

/**
 * What every task an order's change owes has in common: the order it is about, and the records it leaves on the
 * order's timeline when staff act on it (Resend, Run again, Mark resolved: who, when, why) and, for an e-mail and
 * onOrderStatusChange, when it ends failed or held; an e-mail's failed or held record also lists the recipients it had
 * already reached (2.2.0).
 *
 * Each record keeps its sentence as a language key with its parameters in its context (`text`), which the History tab
 * renders in the reader's language (OrderHistoryHelper::line()); its summary is the same sentence in English, the
 * snapshot older readers and search use.
 *
 * @since  2.1.4
 */
abstract class OrderTaskHandler implements TaskHandler, TaskSettleListener
{
    /**
     * What the task is, named in its timeline sentences: a text ({@see self::text()}), e.g. "the status e-mail for
     * status 3".
     *
     * @param array<string, mixed> $payload What the task was given.
     *
     * @return array{key: string, args: list<mixed>}
     *
     * @since  2.1.4
     */
    abstract protected function what(array $payload): array;

    /**
     * The sentence of a failed run that needs its own, or null for the common ones.
     *
     * @param TaskSettlement $settlement The failed run.
     * @param array{key: string, args: list<mixed>} $what What the task is.
     *
     * @return array{key: string, args: list<mixed>}|null
     *
     * @since  2.1.4
     */
    protected function failedSummary(TaskSettlement $settlement, array $what): ?array
    {
        return null;
    }

    /**
     * A sentence of the timeline as stored: its en-GB key and its parameters, each a scalar or a text of its own.
     *
     * @param string $key The language key.
     * @param mixed ...$args Its parameters.
     *
     * @return array{key: string, args: list<mixed>}
     *
     * @since  2.1.4
     */
    protected static function text(string $key, mixed ...$args): array
    {
        return ['key' => $key, 'args' => array_values($args)];
    }

    /**
     * A text in English, as every timeline summary: the snapshot the record keeps beside its key.
     *
     * @param array{key: string, args: list<mixed>} $text The text.
     *
     * @since  2.1.4
     */
    protected static function english(array $text): string
    {
        static $language = null;

        if (!$language instanceof Language) {
            $language = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage('en-GB');
            $language->load(extension: 'com_alfa', basePath: JPATH_ADMINISTRATOR);
        }

        $args = array_map(static fn (mixed $arg): mixed => match (true) {
            // The record's English: a status by its name, recipients by their units and their UTC times.
            \is_array($arg) && \array_key_exists('status', $arg) => (string) (AlfaHelper::getOrderStatuses()[(int) $arg['status']]->name ?? '#' . (int) $arg['status']),
            \is_array($arg) && \is_array($arg['recipients'] ?? null) => implode(', ', array_map(
                static fn (array $one): string => $one['unit'] . ($one['at'] !== '' ? ' (' . substr($one['at'], 0, 19) . ' UTC)' : ''),
                $arg['recipients'],
            )),
            \is_array($arg) => self::english(text: $arg),
            default => $arg,
        }, $text['args']);

        return vsprintf($language->_($text['key']), $args);
    }

    /**
     * The units this task's history records sent, each with the time it was recorded: [['unit' => ..., 'at' => ...]],
     * in the order sent. A unit carried from the task it resends or runs again is that task's, and left out.
     *
     * @param list<array<string, mixed>> $history The task's history.
     *
     * @return list<array{unit: string, at: string}>
     *
     * @since  2.2.0
     */
    protected static function sentUnits(array $history): array
    {
        $sent = [];

        foreach ($history as $entry) {
            if (isset($entry['unit']) && ($entry['outcome'] ?? '') === TaskRun::DONE && !isset($entry['carried_from'])) {
                $sent[(string) $entry['unit']] = ['unit' => (string) $entry['unit'], 'at' => (string) ($entry['at'] ?? '')];
            }
        }

        return array_values($sent);
    }

    /**
     * The units sent, as a sentence's argument: stored as they are, said where the line is read, each recipient with the
     * time it was handed over in the reader's time zone (UX-47).
     *
     * @param list<array{unit: string, at: string}> $sent What sentUnits() answered.
     *
     * @return array{recipients: list<array{unit: string, at: string}>}
     *
     * @since  2.2.0
     */
    protected static function sentList(array $sent): array
    {
        return ['recipients' => $sent];
    }

    /**
     * What a failed or held run of this task had already delivered, for its record; none by default.
     *
     * @param TaskSettlement $settlement The run.
     *
     * @return list<array{unit: string, at: string}>
     *
     * @since  2.2.0
     */
    protected function alreadySent(TaskSettlement $settlement): array
    {
        return [];
    }

    /**
     * The timeline event a failed or held run leaves, by state; '' when it leaves none.
     *
     * @param string $state Failed or held.
     *
     * @since  2.1.4
     */
    protected function outcomeEvent(string $state): string
    {
        return '';
    }

    /**
     * Record an automation the order no longer stood for (2.2.0): an INFO line in the tasks log and one row on the
     * order's timeline, so a move left out is never silent. Nothing is moved; the task ends done.
     *
     * @param TaskRun $run The task.
     *
     * @since  2.2.0
     */
    protected function superseded(TaskRun $run): void
    {
        $orderId = $run->subjectId();
        // The status by its id, said by its name where the line is read (UX-45).
        $text = self::text('COM_ALFA_ORDER_TASK_SUPERSEDED', $this->what($run->payload()), $run->id(), ['status' => self::currentStatus(orderId: $orderId) ?? 0]);
        $summary = self::english(text: $text);
        Tasks::log(entry: 'Task #' . $run->id() . ' (' . $run->handler() . ') superseded: ' . $summary, priority: Log::INFO);
        OrderModel::logOrderActivity(
            orderId: $orderId,
            event: 'order.automation_superseded',
            summary: $summary,
            context: ['task' => $run->id(), 'handler' => $run->handler(), 'text' => $text],
            actorId: 0,
        );
    }

    /**
     * The order's current status, or null when the order no longer exists.
     *
     * @param int $orderId The order.
     *
     * @since  2.1.4
     */
    protected static function currentStatus(int $orderId): ?int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $status = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id_order_status'))
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $orderId, ParameterType::INTEGER),
        )->loadResult();

        return $status === null ? null : (int) $status;
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    public function settled(TaskSettlement $settlement): void
    {
        if ($settlement->subjectType !== 'order' || $settlement->subjectId <= 0) {
            return;
        }

        $what = $this->what($settlement->payload);
        $task = $settlement->taskId;
        $context = ['task' => $task, 'handler' => $settlement->handler];
        $units = $this->listed($settlement->units, ['failed', 'unknown']);
        [$event, $text, $actor] = match ($settlement->event) {
            TaskSettlement::RESOLVED => [
                'order.task_resolved',
                self::text('COM_ALFA_ORDER_TASK_RESOLVED', $what, $task, $settlement->reason),
                $settlement->userId,
            ],
            TaskSettlement::RESENT => [
                'order.email_resent',
                $units !== ''
                    ? self::text('COM_ALFA_ORDER_TASK_RESENT_TO', $what, $task, $settlement->newTaskId, $units)
                    : self::text('COM_ALFA_ORDER_TASK_RESENT', $what, $task, $settlement->newTaskId),
                $settlement->userId,
            ],
            TaskSettlement::RUN_AGAIN => [
                'order.hook_rerun',
                $units !== ''
                    ? self::text('COM_ALFA_ORDER_TASK_RUN_AGAIN_FOR', $what, $task, $settlement->newTaskId, $units)
                    : self::text('COM_ALFA_ORDER_TASK_RUN_AGAIN', $what, $task, $settlement->newTaskId),
                $settlement->userId,
            ],
            default => $this->outcomeRecord(settlement: $settlement, what: $what),
        };

        if ($event === '') {
            return;
        }

        // A failed or held run also says what it had already delivered (2.2.0): that delivery outlives the task.
        $sent = $settlement->event === TaskSettlement::RUN || $settlement->event === TaskSettlement::INTERRUPTED ? $this->alreadySent(settlement: $settlement) : [];

        if ($sent !== []) {
            $text = self::text('COM_ALFA_ORDER_TASK_ALREADY_SENT', $text, self::sentList(sent: $sent));
            $context['sent'] = $sent;
        }

        OrderModel::logOrderActivity(
            orderId: $settlement->subjectId,
            event: $event,
            summary: self::english(text: $text),
            context: $context + ($settlement->reason !== '' ? ['reason' => $settlement->reason] : []) + ($settlement->newTaskId > 0 ? ['new_task' => $settlement->newTaskId] : []) + ['text' => $text],
            actorId: $actor,
        );
    }

    /**
     * The record of a run that ended failed or held, or of one recovery held: [event, text, actor]. A sentence that
     * names the units leaves out the task's error, which only lists them again (Tools > Tasks shows each unit's).
     *
     * @param TaskSettlement $settlement What happened.
     * @param array{key: string, args: list<mixed>} $what What the task is.
     *
     * @return array{0: string, 1: array{key: string, args: list<mixed>}, 2: int}
     *
     * @since  2.1.4
     */
    private function outcomeRecord(TaskSettlement $settlement, array $what): array
    {
        if (!\in_array($settlement->state, [Tasks::STATE_FAILED, Tasks::STATE_HELD], true)) {
            return ['', $what, 0];
        }

        $event = $this->outcomeEvent(state: $settlement->state);

        if ($event === '') {
            return ['', $what, 0];
        }

        $task = $settlement->taskId;

        if ($settlement->event === TaskSettlement::INTERRUPTED) {
            $text = self::text('COM_ALFA_ORDER_TASK_INTERRUPTED', $what, $task);
        } elseif ($settlement->state === Tasks::STATE_HELD) {
            $unknown = $this->listed($settlement->units, ['unknown']);
            $text = $unknown !== ''
                ? self::text('COM_ALFA_ORDER_TASK_HELD_FOR', $what, $task, $unknown)
                : self::text('COM_ALFA_ORDER_TASK_HELD', $what, $task);
        } else {
            $failed = $this->listed($settlement->units, ['failed']);
            // The work's own failure as its key and arguments, said where the line is read (UX-09).
            $error = Tasks::errorArgument(stored: $settlement->error);
            $text = $this->failedSummary(settlement: $settlement, what: $what) ?? match (true) {
                $failed !== '' => self::text('COM_ALFA_ORDER_TASK_FAILED_FOR', $what, $task, $failed),
                $error !== '' => self::text('COM_ALFA_ORDER_TASK_FAILED', $what, $task, $error),
                default => self::text('COM_ALFA_ORDER_TASK_FAILED_NO_REASON', $what, $task),
            };
        }

        return [$event, $text, 0];
    }

    /**
     * The units of some outcomes, listed.
     *
     * @param array<string, list<string>> $units Units by outcome.
     * @param list<string> $outcomes The outcomes.
     *
     * @since  2.1.4
     */
    private function listed(array $units, array $outcomes): string
    {
        $list = [];

        foreach ($outcomes as $outcome) {
            array_push($list, ...($units[$outcome] ?? []));
        }

        return implode(', ', $list);
    }
}
