<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Background;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Task\DueRead;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use InvalidArgumentException;

/**
 * A task being asked for, read as a sentence:
 *
 *     BackgroundWork::of($this)->task('invoice.submit')->about('order', $orderId)->with(['order' => $orderId])
 *         ->once('order:' . $orderId)->enqueue();
 *
 * It is written on the shop's connection, in whatever transaction is open there: it commits with the caller's change
 * and runs right after the outermost commit, in the same request; a rollback removes it with the change. Close your
 * own transaction with TransactionState::commitLevel() and rollBackLevel(): a plain driver commit keeps the task but
 * leaves its run to recovery, two minutes later. With no transaction open it is written and run at once, before
 * enqueue() returns. Work that must not delay the request is a job.
 *
 * @api WRITE: the task, written in the caller's transaction (DURABLE: it commits or rolls back with it).
 *
 * @since  2.3.0
 */
final class TaskRequest
{
    /**
     * The most data, encoded: 16 KiB.
     *
     * @since  2.3.0
     */
    public const DATA_MAX = 16384;

    /**
     * What it concerns, or ''.
     *
     * @since  2.3.0
     */
    private string $subjectType = '';

    /**
     * The id of what it concerns, or 0.
     *
     * @since  2.3.0
     */
    private int $subjectId = 0;

    /**
     * What the task needs.
     *
     * @var array<string, mixed>
     *
     * @since  2.3.0
     */
    private array $data = [];

    /**
     * The key of which at most one task of this name waits or runs, or null.
     *
     * @since  2.3.0
     */
    private ?string $once = null;

    /**
     * @param string $owner com_alfa, or the plugin as group.element.
     * @param string $name The task's declared name.
     *
     * @internal Made by BackgroundWork::task() only.
     *
     * @since  2.3.0
     */
    public function __construct(private readonly string $owner, private readonly string $name)
    {
    }

    /**
     * What the task concerns, shown in Background ("Order 15").
     *
     * @param string $type A lower-case word of at most 32 characters (order).
     * @param int $id Its id, above zero.
     *
     * @throws InvalidArgumentException When either is not of that shape.
     *
     * @since  2.3.0
     */
    public function about(string $type, int $id): self
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,31}$/', $type) !== 1 || $id <= 0) {
            throw new InvalidArgumentException('A task is about a lower-case word of at most 32 characters and an id above zero (given: ' . $type . ', ' . $id . ').');
        }

        $this->subjectType = $type;
        $this->subjectId = $id;

        return $this;
    }

    /**
     * What the task needs: JSON-safe, at most 16 KiB encoded. Read current values when it runs, never what they were
     * when it was asked.
     *
     * @param array<string, mixed> $data The data.
     *
     * @throws InvalidArgumentException When it cannot be encoded or is larger.
     *
     * @since  2.3.0
     */
    public function with(array $data): self
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false || \strlen($json) > self::DATA_MAX) {
            throw new InvalidArgumentException('A task\'s data is JSON-safe and at most 16 KiB encoded.');
        }

        $this->data = $data;

        return $this;
    }

    /**
     * At most one waiting or running task of this name and key: a second request with the same data joins it; one
     * with other data writes nothing and is answered refused, Enqueued::OTHER_DATA, with the waiting task's id.
     *
     * @param string $key 1 to 100 of a to z, 0 to 9, _ . : and -.
     *
     * @throws InvalidArgumentException When the key is not of that shape.
     *
     * @since  2.3.0
     */
    public function once(string $key): self
    {
        if (preg_match('/^[a-z0-9_.:-]{1,100}$/', $key) !== 1) {
            throw new InvalidArgumentException('A task\'s once() key is 1 to 100 of a to z, 0 to 9, _, ., : and - (given: ' . $key . ').');
        }

        $this->once = $key;

        return $this;
    }

    /**
     * Write the task.
     *
     * @since  2.3.0
     */
    public function enqueue(): Enqueued
    {
        $payload = $this->owner === DueRead::CORE_OWNER ? $this->data : ['data' => $this->data];

        if ($this->once === null && $this->owner === DueRead::CORE_OWNER) {
            return new Enqueued(id: Tasks::single(handler: $this->name, subjectType: $this->subjectType, subjectId: $this->subjectId, payload: $payload));
        }

        if ($this->once === null) {
            return new Enqueued(id: Tasks::write(
                handler: $this->name,
                subjectType: $this->subjectType,
                subjectId: $this->subjectId,
                payload: $payload,
                trigger: 'request',
                userId: Tasks::userId(),
                owner: $this->owner,
            ));
        }

        $written = Tasks::writeOnce(handler: $this->name, subjectType: $this->subjectType, subjectId: $this->subjectId, payload: $payload, owner: $this->owner, onceKey: $this->once);

        return new Enqueued(id: $written['id'], joined: $written['joined'], refused: $written['refused'], reason: $written['refused'] ? Enqueued::OTHER_DATA : '');
    }
}
