<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Background;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Task\TaskDocument;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRun;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * One task or job as it stands: what find() answers, and what onAlfaWorkSettled hands a plugin when its work ended.
 *
 * $done is a job's units walked, or a task's parts done; $total a job's units in all (0 while not counted, and for a
 * task); $percent a job's share walked, null for a task or a job not counted. $result is set once the work is done;
 * $failure is the last error shortened, never a database's text, '' when none. Times are UTC.
 *
 * @api READ ONLY: a snapshot of one task or job of the asking owner.
 *
 * @since  2.3.0
 */
final class WorkStatus
{
    /**
     * @param int $id The task or job.
     * @param string $name Its declared name.
     * @param WorkState $state Its state.
     * @param int $done A job's units walked, a task's parts done.
     * @param int $total A job's units in all, 0 while not counted and for a task.
     * @param int|null $percent A job's share walked, 0 to 100; null for a task or a job not counted.
     * @param array<string, mixed>|null $result What the finished work reported; null before it is done.
     * @param string $failure The last error, shortened; '' when none.
     * @param DateTimeImmutable|null $finishedAt When it ended, UTC; null while it has not.
     *
     * @since  2.3.0
     */
    private function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly WorkState $state,
        public readonly int $done,
        public readonly int $total,
        public readonly ?int $percent,
        public readonly ?array $result,
        public readonly string $failure,
        public readonly ?DateTimeImmutable $finishedAt,
    ) {
    }

    /**
     * Whether nothing automatic is left: done, failed, held, resolved or cancelled.
     *
     * @since  2.3.0
     */
    public function isFinished(): bool
    {
        return !\in_array($this->state, [WorkState::Waiting, WorkState::Running], true);
    }

    /**
     * The status of a task row (id, type, handler, state, done_units, total_units, history, result, last_error,
     * finished_at). Core's reader of the row builds it; an extension is handed it.
     *
     * @param array<string, mixed> $row The row.
     *
     * @internal
     *
     * @since  2.3.0
     */
    public static function fromRow(array $row): self
    {
        $isJob = (string) $row['type'] === Tasks::TYPE_JOB;
        $done = $isJob ? (int) $row['done_units'] : \count(array_filter(
            Tasks::unitOutcomes(history: Tasks::decodeHistory($row['history'] ?? null))[TaskRun::DONE] ?? [],
        ));
        $total = $isJob ? (int) $row['total_units'] : 0;
        $result = json_decode((string) ($row['result'] ?? ''), true);
        $finished = null;

        if ((string) ($row['finished_at'] ?? '') !== '') {
            try {
                $finished = new DateTimeImmutable((string) $row['finished_at'], new DateTimeZone('UTC'));
            } catch (Throwable) {
                $finished = null;
            }
        }

        return new self(
            id: (int) $row['id'],
            name: (string) $row['handler'],
            state: WorkState::from((string) $row['state']),
            done: $done,
            total: $total,
            percent: $isJob && ($total > 0 || (string) $row['state'] === Tasks::STATE_DONE) ? TaskDocument::percent(row: $row) : null,
            result: \is_array($result) ? $result : null,
            failure: TaskDocument::shortError(error: Tasks::errorText((string) ($row['last_error'] ?? ''))),
            finishedAt: $finished,
        );
    }
}
