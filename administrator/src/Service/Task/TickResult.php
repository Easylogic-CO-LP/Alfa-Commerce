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
 * What one tick of the runner did (2.2.0, design 2.4).
 *
 * @since  2.2.0
 */
final class TickResult
{
    /**
     * @param string $refused Why the tick did nothing at all: paused, maintenance, transaction or lock; '' when it ran.
     * @param array<string, int> $singles What the singles' recovery did, by outcome.
     * @param int $jobs The jobs claimed.
     * @param int $chunks The chunks committed.
     * @param int $units The units those chunks walked.
     * @param int $failed The chunks that failed and were rolled back.
     * @param int $lost The chunks rolled back because their lease was no longer this tick's.
     * @param int $deadlocks The deadlocks the tick met: the statements a deadlock ended, those run again after it included.
     * @param string $stoppedBy Why it stopped: budget, nothing, elsewhere (the asked job runs in another tick), or the refusal.
     * @param float $ms Its wall-clock time.
     *
     * @since  2.2.0
     */
    public function __construct(
        public readonly string $refused = '',
        public readonly array $singles = [],
        public readonly int $jobs = 0,
        public readonly int $chunks = 0,
        public readonly int $units = 0,
        public readonly int $failed = 0,
        public readonly int $lost = 0,
        public readonly int $deadlocks = 0,
        public readonly string $stoppedBy = '',
        public readonly float $ms = 0.0,
    ) {
    }
}
