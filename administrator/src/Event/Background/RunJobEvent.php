<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Background;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Background\WorkFailure;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use DateTimeImmutable;
use DateTimeZone;
use Joomla\CMS\Event\AbstractEvent;

/**
 * One step of a plugin's job (onAlfaRunJob), addressed to the plugin that declared it. The step walks at most
 * getUnits() units from getCheckpoint() and ends with exactly one of next() (more to do, from this bookmark) or
 * finished() (done, with its result), after walked():
 *
 *     $job->walked(\count($rows))->next(['after' => $lastId]);
 *     $job->walked(\count($rows))->finished(['low' => $low]);
 *
 * total() reports the units in all, for the progress bar, on any step. A step that ends with neither, or both, fails.
 * A step must be safe to walk again: re-derive the work from your data, never assume what an earlier walk did.
 *
 * @api HOOK: core calls onAlfaRunJob() on the one plugin for each step of its job.
 *
 * @since  2.3.0
 */
final class RunJobEvent extends AbstractEvent
{
    /**
     * The event's name.
     *
     * @since  2.3.0
     */
    public const NAME = 'onAlfaRunJob';

    /**
     * The longest bookmark, encoded: 4 KiB.
     *
     * @since  2.3.0
     */
    public const CHECKPOINT_MAX = 4096;

    /**
     * The longest result, encoded: 16 KiB.
     *
     * @since  2.3.0
     */
    public const RESULT_MAX = 16384;

    /**
     * The units in all as reported, or null.
     *
     * @since  2.3.0
     */
    private ?int $total = null;

    /**
     * The units this step walked.
     *
     * @since  2.3.0
     */
    private int $walked = 0;

    /**
     * How the step ended: next, finished, both, or '' for neither.
     *
     * @since  2.3.0
     */
    private string $ending = '';

    /**
     * The bookmark next() gave.
     *
     * @var array<string, mixed>
     *
     * @since  2.3.0
     */
    private array $checkpoint = [];

    /**
     * The result finished() gave, as JSON.
     *
     * @since  2.3.0
     */
    private ?string $result = null;

    /**
     * @param JobRun $run The job as the step starts.
     * @param int $units At most this many units now.
     *
     * @internal Made by core's runner only.
     *
     * @since  2.3.0
     */
    public function __construct(private readonly JobRun $run, private readonly int $units)
    {
        parent::__construct(self::NAME, []);
    }

    /**
     * The job's id, as find() and Background know it.
     *
     * @since  2.3.0
     */
    public function getId(): int
    {
        return $this->run->id;
    }

    /**
     * The job's declared name. getName() stays the event's name, as on every Joomla event: the dispatcher calls the
     * plugin's method of that name.
     *
     * @since  2.3.0
     */
    public function getWorkName(): string
    {
        return $this->run->handler;
    }

    /**
     * What the job covers, as given to scope(); '' for a job with no scope.
     *
     * @since  2.3.0
     */
    public function getScope(): string
    {
        $prefix = $this->run->handler . ':';

        return str_starts_with($this->run->scopeKey, $prefix) ? substr($this->run->scopeKey, \strlen($prefix)) : '';
    }

    /**
     * What the job was given with with() when it was first asked for.
     *
     * @return array<string, mixed>
     *
     * @since  2.3.0
     */
    public function getData(): array
    {
        $data = $this->run->payload['data'] ?? [];

        return \is_array($data) ? $data : [];
    }

    /**
     * The bookmark the last step gave to next(); [] on the first step and when the job runs again from the start.
     *
     * @return array<string, mixed>
     *
     * @since  2.3.0
     */
    public function getCheckpoint(): array
    {
        return $this->run->checkpoint ?? [];
    }

    /**
     * Walk at most this many units now: the runner sizes the steps from the time they take.
     *
     * @since  2.3.0
     */
    public function getUnits(): int
    {
        return max(1, $this->units);
    }

    /**
     * Finish the step before this moment, a wait for a lock included.
     *
     * @since  2.3.0
     */
    public function getDeadline(): DateTimeImmutable
    {
        $until = $this->run->until ?? microtime(true) + 1.0;

        return DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $until))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The units in all, for the progress bar: on any step, as often as it changes.
     *
     * @param int $units The units in all.
     *
     * @since  2.3.0
     */
    public function total(int $units): static
    {
        $this->total = max(0, $units);

        return $this;
    }

    /**
     * The units this step walked.
     *
     * @param int $units The units.
     *
     * @since  2.3.0
     */
    public function walked(int $units): static
    {
        $this->walked = max(0, $units);

        return $this;
    }

    /**
     * The step ends with more to do: the next step continues from this bookmark (JSON-safe, at most 4 KiB encoded).
     *
     * @param array<string, mixed> $checkpoint The bookmark.
     *
     * @throws WorkFailure When the bookmark cannot be encoded or is larger: the job fails, its code is wrong.
     *
     * @since  2.3.0
     */
    public function next(array $checkpoint): void
    {
        $json = json_encode($checkpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false || \strlen($json) > self::CHECKPOINT_MAX) {
            throw WorkFailure::failed('A job\'s bookmark is JSON-safe and at most 4 KiB encoded.');
        }

        $this->checkpoint = $checkpoint;
        $this->ending = $this->ending === '' ? 'next' : 'both';
    }

    /**
     * The step ends and the job is done, with what it reports (JSON-safe, at most 16 KiB encoded), shown in
     * Background and kept with its row.
     *
     * @param array<string, mixed> $result The result.
     *
     * @throws WorkFailure When the result cannot be encoded or is larger: the job fails, its code is wrong.
     *
     * @since  2.3.0
     */
    public function finished(array $result = []): void
    {
        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false || \strlen($json) > self::RESULT_MAX) {
            throw WorkFailure::failed('A job\'s result is JSON-safe and at most 16 KiB encoded.');
        }

        $this->result = $json;
        $this->ending = $this->ending === '' ? 'finished' : 'both';
    }

    /**
     * How the step ended, for core's runner: next, finished, both or '' (neither), with what it reported.
     *
     * @return array{ending: string, walked: int, total: int|null, checkpoint: array<string, mixed>, result: string|null}
     *
     * @internal
     *
     * @since  2.3.0
     */
    public function outcome(): array
    {
        return ['ending' => $this->ending, 'walked' => $this->walked, 'total' => $this->total, 'checkpoint' => $this->checkpoint, 'result' => $this->result];
    }
}
