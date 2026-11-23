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
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A job being asked for, read as a sentence:
 *
 *     BackgroundWork::of($this)->job('catalog.sync')->scope('42')->with(['category' => 42])
 *         ->notBefore(new \DateTimeImmutable('+15 minutes'))->enqueue();
 *
 * One waiting or running job per owner, name and scope: a second request joins it, its data never rewritten; if the
 * job has started, it runs once more from the start when it finishes, so it always ends having walked what was asked
 * last. Inside a transaction the job is born with it; outside, it is committed at once. It never runs in the request
 * that asked: the next tick of the runner runs it.
 *
 * @api WRITE: the job, written in the caller's transaction (DURABLE: it commits or rolls back with it).
 *
 * @since  2.3.0
 */
final class JobRequest
{
    /**
     * The most data, encoded: 16 KiB.
     *
     * @since  2.3.0
     */
    public const DATA_MAX = 16384;

    /**
     * How far ahead notBefore() may be: 365 days.
     *
     * @since  2.3.0
     */
    public const AHEAD_DAYS = 365;

    /**
     * What the job needs.
     *
     * @var array<string, mixed>
     *
     * @since  2.3.0
     */
    private array $data = [];

    /**
     * What it covers, or '' for one job of the name.
     *
     * @since  2.3.0
     */
    private string $scope = '';

    /**
     * Not before this moment, or null for now.
     *
     * @since  2.3.0
     */
    private ?DateTimeImmutable $notBefore = null;

    /**
     * @param string $owner com_alfa, or the plugin as group.element.
     * @param string $name The job's declared name.
     *
     * @internal Made by BackgroundWork::job() only.
     *
     * @since  2.3.0
     */
    public function __construct(private readonly string $owner, private readonly string $name)
    {
    }

    /**
     * What the job needs: JSON-safe, at most 16 KiB encoded. A joined job keeps the data of the request that wrote it.
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
            throw new InvalidArgumentException('A job\'s data is JSON-safe and at most 16 KiB encoded.');
        }

        $this->data = $data;

        return $this;
    }

    /**
     * What the job covers ('42' for category 42): one waiting or running job per owner, name and scope. Without it,
     * one job for the name.
     *
     * @param string $scope 1 to 100 of a to z, 0 to 9, _ . : and -.
     *
     * @throws InvalidArgumentException When the scope is not of that shape.
     *
     * @since  2.3.0
     */
    public function scope(string $scope): self
    {
        if (preg_match('/^[a-z0-9_.:-]{1,100}$/', $scope) !== 1) {
            throw new InvalidArgumentException('A job\'s scope is 1 to 100 of a to z, 0 to 9, _, ., : and - (given: ' . $scope . ').');
        }

        $this->scope = $scope;

        return $this;
    }

    /**
     * Not before this moment, at most 365 days ahead (further: refused, Enqueued::TOO_FAR, nothing written). Joining a
     * waiting job keeps the earlier of the two moments, so a delay gathers a burst: every request of the scope in those
     * minutes joins the one waiting job.
     *
     * @param DateTimeInterface $when The moment.
     *
     * @since  2.3.0
     */
    public function notBefore(DateTimeInterface $when): self
    {
        $this->notBefore = DateTimeImmutable::createFromInterface($when)->setTimezone(new DateTimeZone('UTC'));

        return $this;
    }

    /**
     * Write the job, or join the one of its scope that waits or runs.
     *
     * @since  2.3.0
     */
    public function enqueue(): Enqueued
    {
        if ($this->notBefore !== null && $this->notBefore > new DateTimeImmutable('+' . self::AHEAD_DAYS . ' days', new DateTimeZone('UTC'))) {
            return new Enqueued(id: 0, refused: true, reason: Enqueued::TOO_FAR);
        }

        $written = Runner::enqueue(
            handler: $this->name,
            scopeKey: $this->scope === '' ? $this->name : $this->name . ':' . $this->scope,
            payload: $this->owner === DueRead::CORE_OWNER ? $this->data : ['data' => $this->data],
            owner: $this->owner,
            notBefore: $this->notBefore,
        );

        return new Enqueued(id: $written->id, joined: $written->merged);
    }
}
