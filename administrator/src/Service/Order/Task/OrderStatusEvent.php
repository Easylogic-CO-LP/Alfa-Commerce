<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Orders\OrderStatusChangeEvent;
use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskFailure;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRun;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Closure;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\ParameterType;
use ReflectionFunction;
use Throwable;

/**
 * order.status_event: onOrderStatusChange, once, after the committed save.
 *
 * Each listener is called on its own and its completion written to the task's history as it goes; a listener that
 * throws is recorded with its error and the others still run. Then the task ends failed when one threw, and is never
 * run again automatically; a run that stopped midway is held. Staff decide: Run again calls the listeners not recorded
 * as completed. Listeners observe the order as committed.
 *
 * @since  2.1.4
 */
final class OrderStatusEvent extends OrderTaskHandler
{
    /**
     * The handler's name.
     *
     * @since  2.1.4
     */
    public const NAME = 'order.status_event';

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    public function policy(): TaskPolicy
    {
        return new TaskPolicy(
            label: 'COM_ALFA_TASKS_HANDLER_ORDER_STATUS_EVENT',
            needsApplication: true,
            staffAction: TaskPolicy::ACTION_RERUN,
            attention: 'danger',
        );
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
        $db = Factory::getContainer()->get('DatabaseDriver');
        $order = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $orderId, ParameterType::INTEGER),
        )->loadObject();

        if ($order === null) {
            return;
        }

        $from = (int) ($payload['from'] ?? 0);
        $to = (int) ($payload['to'] ?? 0);
        $event = new OrderStatusChangeEvent('onOrderStatusChange', [
            'order' => $order,
            'previousStatusId' => $from,
            'newStatusId' => $to,
            'enteredCompleted' => !OrderStatusHelper::isCompleted($from) && OrderStatusHelper::isCompleted($to),
            'enteredCancelled' => !OrderStatusHelper::isCancelled($from) && OrderStatusHelper::isCancelled($to),
            'leftCompleted' => OrderStatusHelper::isCompleted($from) && !OrderStatusHelper::isCompleted($to),
            'leftCancelled' => OrderStatusHelper::isCancelled($from) && !OrderStatusHelper::isCancelled($to),
            'actor' => (string) ($payload['actor'] ?? ''),
            'note' => (string) ($payload['note'] ?? ''),
            'transitionTime' => (string) ($payload['at'] ?? Factory::getDate('now', 'UTC')->toSql()),
        ]);

        // The feature plugins listen here; the component imports them when it boots, which a recovery run in another
        // process may not have done.
        PluginHelper::importPlugin('alfa');

        $failed = [];
        $seen = [];

        foreach (Factory::getApplication()->getDispatcher()->getListeners('onOrderStatusChange') as $listener) {
            // A second listener of the same name gets its position.
            $name = self::listenerName(listener: $listener);
            $seen[$name] = ($seen[$name] ?? 0) + 1;
            $unit = $seen[$name] === 1 ? $name : $name . '#' . $seen[$name];

            if ($event->isStopped()) {
                break;
            }

            if (!$run->shouldAttempt(unit: $unit)) {
                continue;
            }

            $error = null;

            try {
                $listener($event);
            } catch (Throwable $e) {
                $error = $e;
            }

            if ($error === null) {
                $run->unit(unit: $unit, outcome: TaskRun::DONE);

                continue;
            }

            $failed[] = $unit;
            $run->unit(unit: $unit, outcome: TaskRun::FAILED, error: $error::class . ': ' . $error->getMessage());
            Log::add(
                entry:    'onOrderStatusChange listener ' . $unit . ' failed for order ' . $orderId . ' (' . $from . ' -> ' . $to . '): '
                          . $error->getMessage() . "\n" . $error->getTraceAsString(),
                priority: Log::ERROR,
                category: 'com_alfa.orders',
            );
        }

        if ($failed !== []) {
            throw TaskFailure::failed(message: 'onOrderStatusChange listener failed: ' . implode(', ', $failed) . '.');
        }
    }

    /**
     * A listener's name, stable across requests: its class and method, a closure by the object or class it was made
     * in.
     *
     * @param callable $listener The listener.
     *
     * @since  2.1.4
     */
    private static function listenerName(callable $listener): string
    {
        if (\is_array($listener)) {
            $name = (\is_object($listener[0]) ? $listener[0]::class : (string) $listener[0]) . '::' . $listener[1];
        } elseif ($listener instanceof Closure) {
            $function = new ReflectionFunction($listener);
            $owner = $function->getClosureThis();
            $name = ($owner !== null ? $owner::class : ($function->getClosureScopeClass()?->getName() ?? 'closure')) . '::{closure}';
        } elseif (\is_object($listener)) {
            $name = $listener::class;
        } else {
            $name = \is_string($listener) ? $listener : 'listener';
        }

        return $name;
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    protected function what(array $payload): array
    {
        return self::text('COM_ALFA_ORDER_TASK_STATUS_EVENT', (int) ($payload['to'] ?? 0));
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.1.4
     */
    protected function outcomeEvent(string $state): string
    {
        return $state === Tasks::STATE_HELD ? 'order.hook_held' : 'order.hook_failed';
    }
}
