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
 * How the jobs of one handler are weighed, ordered and cut into chunks.
 *
 * @since  2.2.0
 */
final class JobPolicy
{
    /**
     * A job of this weight runs while no other heavy job does: it holds the heavy slot.
     *
     * @since  2.2.0
     */
    public const HEAVY = 'heavy';

    /**
     * A job of this weight runs beside any other.
     *
     * @since  2.2.0
     */
    public const LIGHT = 'light';

    /**
     * @param string $label Language key that names the job for staff.
     * @param string $weight self::HEAVY or self::LIGHT.
     * @param int $priority Claim order: a higher one is claimed first (correctness owed to the shop, then freshness the
     *                      customer sees, then maintenance).
     * @param int $initialUnits Units of the first chunk of a tick, as measured for the handler; the runner adapts the
     *                          rest of the tick from the cost it measures.
     * @param bool $rerunOnMerge A new request of the same scope once the job has started makes it run once more, from the
     *                           start ({@see JobHandler}).
     * @param int $version The handler's version, stored with every payload it is given.
     * @param bool $ownTransactions The handler's domain service keeps transactions of its own (the stock drain): its
     *                              chunk() runs before the runner's chunk transaction, which then carries only the
     *                              slot's renewal and the fenced progress. Its own claims must make a second run of
     *                              the same work harmless, since the fence can then only lose the progress.
     * @param int $everyMinutes The span of a job the runner owes by itself: owed when none of its owner and name waits
     *                          or runs and none was created within the span; 0 for a job only asked for.
     * @param bool $callsOut Its steps call another system: a failed step waits the tasks' ladder
     *                       ({@see Tasks::BACKOFF_SECONDS}) between tries, as a remote system that is down is down for
     *                       minutes or hours, not seconds.
     * @param bool $cancellable Its owner may cancel it before it finishes; the shop's correctness work never is.
     *
     * @since  2.2.0
     */
    public function __construct(
        public readonly string $label,
        public readonly string $weight = self::LIGHT,
        public readonly int $priority = 0,
        public readonly int $initialUnits = 1,
        public readonly bool $rerunOnMerge = true,
        public readonly int $version = 1,
        public readonly bool $ownTransactions = false,
        public readonly int $everyMinutes = 0,
        public readonly bool $callsOut = false,
        public readonly bool $cancellable = false,
    ) {
    }
}
