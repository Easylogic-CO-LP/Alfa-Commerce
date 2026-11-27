<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Background;

\defined('_JEXEC') or die;

use RuntimeException;
use Throwable;

/**
 * How a run of an extension's task or job failed, thrown by its handler. retry(): nothing took effect, try again later
 * on the work's ladder (a task: 1 min, 5 min, 30 min, 2 h, 6 h, 24 h; a job: 30 s doubling, or the task's ladder for a
 * job that calls other systems), at least the seconds given. failed(): trying again cannot help, the work fails at once
 * and waits for staff in Background. Any other Throwable is an unexpected failure: a repeatable task and a job are
 * tried again, any other task is held for staff.
 *
 * The message is read by staff: no password, host or database text.
 *
 * @api WRITE: thrown by onAlfaRunTask() or onAlfaRunJob() to say how a run failed.
 *
 * @since  2.3.0
 */
final class WorkFailure extends RuntimeException
{
    /**
     * Nothing took effect: tried again later.
     *
     * @since  2.3.0
     */
    public const RETRY = 'retry';

    /**
     * Trying again cannot help: failed at once.
     *
     * @since  2.3.0
     */
    public const FAILED = 'failed';

    /**
     * @param string $why What happened, for staff.
     * @param string $kind self::RETRY or self::FAILED.
     * @param Throwable|null $previous The cause.
     * @param int $after Seconds to wait at least before the next try.
     *
     * @since  2.3.0
     */
    private function __construct(string $why, public readonly string $kind, ?Throwable $previous, public readonly int $after)
    {
        parent::__construct(message: $why, previous: $previous);
    }

    /**
     * Nothing took effect: try again later, on the ladder's next step or after $after seconds when that is longer (a
     * receiver's Retry-After).
     *
     * @param string $why What happened, for staff.
     * @param Throwable|null $previous The cause.
     * @param int $after Seconds to wait at least.
     *
     * @since  2.3.0
     */
    public static function retry(string $why, ?Throwable $previous = null, int $after = 0): self
    {
        return new self(why: $why, kind: self::RETRY, previous: $previous, after: max(0, $after));
    }

    /**
     * Trying again cannot help: the task or job fails at once and waits for staff.
     *
     * @param string $why What happened, for staff.
     * @param Throwable|null $previous The cause.
     *
     * @since  2.3.0
     */
    public static function failed(string $why, ?Throwable $previous = null): self
    {
        return new self(why: $why, kind: self::FAILED, previous: $previous, after: 0);
    }
}
