<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskSettleListener;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskSettlement;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\Database\ParameterType;

/**
 * What every task an order's change owes has in common: the order it is about, and the records it leaves on the
 * order's timeline when staff act on it (Resend, Run again, Mark resolved: who, when, why) and, for an e-mail and
 * onOrderStatusChange, when it ends failed or held.
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

        $args = array_map(static fn (mixed $arg): mixed => \is_array($arg) ? self::english(text: $arg) : $arg, $text['args']);

        return vsprintf($language->_($text['key']), $args);
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
            $error = rtrim(trim($settlement->error), '.');
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
