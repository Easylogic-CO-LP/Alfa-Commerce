<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Background;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Background\TaskPart;
use Alfa\Component\Alfa\Administrator\Background\WorkFailure;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRun;
use Joomla\CMS\Event\AbstractEvent;

/**
 * One run of a plugin's task (onAlfaRunTask), addressed to the plugin that declared it. A plugin with several tasks
 * answers by name:
 *
 *     public function onAlfaRunTask(RunTaskEvent $task): void
 *     {
 *         match ($task->getWorkName()) {
 *             'invoice.submit' => $this->submitInvoice($task),
 *         };
 *     }
 *
 * Returning ends the task done; WorkFailure::retry() tries it again later, its parts done skipped; WorkFailure::failed()
 * fails it for staff; part(...)->unknown() holds it for staff. It runs as nobody, outside the caller's transaction.
 *
 * @api HOOK: core calls onAlfaRunTask() on the one plugin for each run of its task.
 *
 * @since  2.3.0
 */
final class RunTaskEvent extends AbstractEvent
{
    /**
     * The event's name.
     *
     * @since  2.3.0
     */
    public const NAME = 'onAlfaRunTask';

    /**
     * The longest result, encoded: 16 KiB.
     *
     * @since  2.3.0
     */
    public const RESULT_MAX = 16384;

    /**
     * @param TaskRun $run The running task.
     *
     * @internal Made by core's runner only.
     *
     * @since  2.3.0
     */
    public function __construct(private readonly TaskRun $run)
    {
        parent::__construct(self::NAME, []);
    }

    /**
     * The task's id, as find() and Background know it.
     *
     * @since  2.3.0
     */
    public function getId(): int
    {
        return $this->run->id();
    }

    /**
     * The task's declared name. getName() stays the event's name, as on every Joomla event: the dispatcher calls the
     * plugin's method of that name.
     *
     * @since  2.3.0
     */
    public function getWorkName(): string
    {
        return $this->run->handler();
    }

    /**
     * What the task was given with with().
     *
     * @return array<string, mixed>
     *
     * @since  2.3.0
     */
    public function getData(): array
    {
        $data = $this->run->payload()['data'] ?? [];

        return \is_array($data) ? $data : [];
    }

    /**
     * What the task concerns, as given to about() ('order'); '' when it concerns nothing in particular.
     *
     * @since  2.3.0
     */
    public function getSubjectType(): string
    {
        return $this->run->subjectType();
    }

    /**
     * The id of what the task concerns; 0 when it concerns nothing in particular.
     *
     * @since  2.3.0
     */
    public function getSubjectId(): int
    {
        return $this->run->subjectId();
    }

    /**
     * A named part of the task: ask isDone(), then record done(), failed() or unknown() as soon as its outcome is known.
     *
     * @param string $name The part's name (a recipient, a call), at most 190 characters.
     *
     * @throws WorkFailure When the name is empty or too long: the task fails, its code is wrong.
     *
     * @since  2.3.0
     */
    public function part(string $name): TaskPart
    {
        if ($name === '' || mb_strlen($name) > 190) {
            throw WorkFailure::failed('A part of a task is named by 1 to 190 characters (given: ' . mb_strlen($name) . ').');
        }

        return new TaskPart(run: $this->run, name: $name);
    }

    /**
     * What the finished task reports, kept with its row and read through find() and the REST API (Background shows
     * the results of jobs only): JSON-safe, at most 16 KiB encoded.
     *
     * @param array<string, mixed> $result The result.
     *
     * @throws WorkFailure When it cannot be encoded or is larger: the task fails, its code is wrong.
     *
     * @since  2.3.0
     */
    public function result(array $result): static
    {
        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false || \strlen($json) > self::RESULT_MAX) {
            throw WorkFailure::failed('A task\'s result is JSON-safe and at most 16 KiB encoded.');
        }

        $this->run->keepResult(json: $json);

        return $this;
    }
}
