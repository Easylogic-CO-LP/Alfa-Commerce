<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use RuntimeException;
use Throwable;

/**
 * Why a task did not complete, as far as its handler knows.
 *
 * @since  2.1.4
 */
final class TaskFailure extends RuntimeException
{
    /**
     * Known to have failed before anything took effect: retried with backoff when the handler retries.
     *
     * @since  2.1.4
     */
    public const RETRY = 'retry';

    /**
     * Failed for good: never retried automatically; staff decide.
     *
     * @since  2.1.4
     */
    public const FAILED = 'failed';

    /**
     * Whether it took effect is unknown (handed over with no answer): held, never run again automatically.
     *
     * @since  2.1.4
     */
    public const UNKNOWN = 'unknown';

    /**
     * The run lost its claim to another worker, which now owns the task. Raised by {@see TaskRun::unit()}.
     *
     * @since  2.1.4
     */
    public const LOST = 'lost';

    /**
     * @param string $message What happened.
     * @param string $kind One of the constants.
     * @param Throwable|null $previous The cause.
     *
     * @since  2.1.4
     */
    private function __construct(string $message, public readonly string $kind, ?Throwable $previous = null)
    {
        parent::__construct(message: $message, previous: $previous);
    }

    /**
     * A known failure before anything took effect.
     *
     * @param string $message What happened.
     * @param Throwable|null $previous The cause.
     *
     * @since  2.1.4
     */
    public static function retry(string $message, ?Throwable $previous = null): self
    {
        return new self(message: $message, kind: self::RETRY, previous: $previous);
    }

    /**
     * A failure that must not be retried automatically.
     *
     * @param string $message What happened.
     * @param Throwable|null $previous The cause.
     *
     * @since  2.1.4
     */
    public static function failed(string $message, ?Throwable $previous = null): self
    {
        return new self(message: $message, kind: self::FAILED, previous: $previous);
    }

    /**
     * An outcome nobody can know.
     *
     * @param string $message What happened.
     * @param Throwable|null $previous The cause.
     *
     * @since  2.1.4
     */
    public static function unknown(string $message, ?Throwable $previous = null): self
    {
        return new self(message: $message, kind: self::UNKNOWN, previous: $previous);
    }

    /**
     * The claim was lost.
     *
     * @param int $taskId The task.
     *
     * @since  2.1.4
     */
    public static function lost(int $taskId): self
    {
        return new self(message: 'Task #' . $taskId . ' is no longer claimed by this run.', kind: self::LOST);
    }
}
