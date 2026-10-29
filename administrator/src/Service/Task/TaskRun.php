<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/**
 * One run of one task, as its handler sees it: the payload, and the parts of the work already recorded.
 *
 * A part (a unit: one recipient of an e-mail, one listener of an event) is recorded in the task's history as soon as
 * its outcome is known, and the write renews the claim, so a long run is never taken for an interrupted one. A unit
 * recorded as done, by this task or by the task it resends or runs again, is never attempted again.
 *
 * @since  2.1.4
 */
final class TaskRun
{
    /**
     * Outcome of a unit: it took effect.
     *
     * @since  2.1.4
     */
    public const DONE = 'done';

    /**
     * Outcome of a unit: it failed before taking effect.
     *
     * @since  2.1.4
     */
    public const FAILED = 'failed';

    /**
     * Outcome of a unit: whether it took effect is unknown.
     *
     * @since  2.1.4
     */
    public const UNKNOWN = 'unknown';

    /**
     * @param DatabaseDriver $db The connection.
     * @param array<string, mixed> $row The task as claimed.
     * @param string $lease The claim of this run.
     * @param string $trigger What runs it (request, bell, tools, staff, runner, cli).
     * @param int $userId Who runs it, 0 for nobody in particular.
     * @param list<array<string, mixed>> $history The task's history so far.
     * @param list<array<string, mixed>> $earlier The history of the tasks it resends or runs again, oldest last.
     *
     * @since  2.1.4
     */
    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly array $row,
        private readonly string $lease,
        private readonly string $trigger,
        private readonly int $userId,
        private array $history,
        private readonly array $earlier = [],
    ) {
    }

    /**
     * The task's id.
     *
     * @since  2.1.4
     */
    public function id(): int
    {
        return (int) $this->row['id'];
    }

    /**
     * The handler's name.
     *
     * @since  2.1.4
     */
    public function handler(): string
    {
        return (string) $this->row['handler'];
    }

    /**
     * What the task is about, e.g. order; '' for nothing.
     *
     * @since  2.1.4
     */
    public function subjectType(): string
    {
        return (string) $this->row['subject_type'];
    }

    /**
     * The id of what the task is about, 0 for nothing.
     *
     * @since  2.1.4
     */
    public function subjectId(): int
    {
        return (int) $this->row['subject_id'];
    }

    /**
     * What the handler was given when the task was written.
     *
     * @return array<string, mixed>
     *
     * @since  2.1.4
     */
    public function payload(): array
    {
        $payload = json_decode((string) $this->row['payload'], true);

        return \is_array($payload) ? $payload : [];
    }

    /**
     * What runs it: request, bell, tools, staff, runner or cli.
     *
     * @since  2.1.4
     */
    public function trigger(): string
    {
        return $this->trigger;
    }

    /**
     * Whether this run may attempt a unit: not recorded as done by this task or the one it resends or runs again,
     * and not recorded by this task with an unknown outcome.
     *
     * @param string $unit The unit, e.g. a recipient's address or a listener's name.
     *
     * @since  2.1.4
     */
    public function shouldAttempt(string $unit): bool
    {
        foreach ([...$this->history, ...$this->earlier] as $entry) {
            if (($entry['unit'] ?? null) === $unit && ($entry['outcome'] ?? '') === self::DONE) {
                return false;
            }
        }

        foreach ($this->history as $entry) {
            if (($entry['unit'] ?? null) === $unit && ($entry['outcome'] ?? '') === self::UNKNOWN) {
                return false;
            }
        }

        return true;
    }

    /**
     * Record a unit's outcome in the task's history now, renewing the claim and the reservation of the rest of its batch.
     *
     * Never catch around it: when the claim was lost the run must stop, and the {@see TaskFailure} it throws tells the
     * runner so.
     *
     * @param string $unit The unit.
     * @param string $outcome self::DONE, self::FAILED or self::UNKNOWN.
     * @param string $error What failed, when it failed.
     *
     * @throws TaskFailure When this run no longer holds the task.
     *
     * @since  2.1.4
     */
    public function unit(string $unit, string $outcome, string $error = ''): void
    {
        $this->history[] = Tasks::entry(trigger: $this->trigger, userId: $this->userId, outcome: $outcome, unit: $unit, error: $error);
        $history = Tasks::encodeHistory($this->history);
        $id = $this->id();
        $lease = $this->lease;
        $db = $this->db;
        $query = $db->getQuery(true)
            ->update($db->quoteName(Tasks::TABLE))
            ->set($db->quoteName('history') . ' = :history')
            ->set($db->quoteName('lease_until') . ' = ' . Tasks::leaseEnd())
            ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('lease_owner') . ' = :lease')
            ->where($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING))
            ->bind(':history', $history)
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':lease', $lease);

        if (Tasks::changed(db: $db, query: $query) !== 1) {
            throw TaskFailure::lost(taskId: $id);
        }

        Tasks::renewReservation(db: $db, batch: (string) ($this->row['batch'] ?? ''), lease: $lease);
    }

    /**
     * Whether this task recorded a unit whose outcome is unknown: it is then never retried automatically.
     *
     * @since  2.1.4
     */
    public function hasUnknownUnit(): bool
    {
        foreach ($this->history as $entry) {
            if (isset($entry['unit']) && ($entry['outcome'] ?? '') === self::UNKNOWN) {
                return true;
            }
        }

        return false;
    }

    /**
     * The task's history with this run's units.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.1.4
     */
    public function history(): array
    {
        return $this->history;
    }
}
