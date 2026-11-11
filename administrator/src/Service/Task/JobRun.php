<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

/**
 * A claimed job as its handler sees it (2.2.0, design 2.5): its id, handler, scope, payload, checkpoint and progress,
 * what runs it, and the moment its chunk must be done by.
 *
 * @since  2.2.0
 */
final class JobRun
{
    /**
     * @param int $id The job.
     * @param string $handler Its handler's name.
     * @param string $scopeKey What it covers.
     * @param array<string, mixed> $payload What the handler needs.
     * @param array<string, mixed>|null $checkpoint Where the next chunk resumes; null at the start.
     * @param int $doneUnits The units walked so far.
     * @param int $totalUnits The units expected in all, as last known.
     * @param string $trigger What runs it.
     * @param float|null $until The moment the chunk must be done by (microtime(true)): the tick's budget, and never past
     *                          the job's lease; a handler that waits (for a lock) waits no longer. Null outside a tick.
     *
     * @since  2.2.0
     */
    public function __construct(
        public readonly int $id,
        public readonly string $handler,
        public readonly string $scopeKey,
        public readonly array $payload,
        public readonly ?array $checkpoint,
        public readonly int $doneUnits,
        public readonly int $totalUnits,
        public readonly string $trigger,
        public readonly ?float $until = null,
    ) {
    }

    /**
     * The same run resuming from another checkpoint, after a chunk.
     *
     * @param array<string, mixed>|null $checkpoint The new checkpoint.
     * @param int $doneUnits The units walked so far.
     * @param int $totalUnits The units expected in all.
     *
     * @since  2.2.0
     */
    public function at(?array $checkpoint, int $doneUnits, int $totalUnits): self
    {
        return new self(
            id: $this->id,
            handler: $this->handler,
            scopeKey: $this->scopeKey,
            payload: $this->payload,
            checkpoint: $checkpoint,
            doneUnits: $doneUnits,
            totalUnits: $totalUnits,
            trigger: $this->trigger,
            until: $this->until,
        );
    }

    /**
     * The same run with the moment its next chunk must be done by.
     *
     * @param float $until The deadline, as microtime(true).
     *
     * @since  2.2.0
     */
    public function until(float $until): self
    {
        return new self(
            id: $this->id,
            handler: $this->handler,
            scopeKey: $this->scopeKey,
            payload: $this->payload,
            checkpoint: $this->checkpoint,
            doneUnits: $this->doneUnits,
            totalUnits: $this->totalUnits,
            trigger: $this->trigger,
            until: $until,
        );
    }
}
