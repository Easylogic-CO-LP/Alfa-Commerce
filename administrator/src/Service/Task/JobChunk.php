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
 * What one chunk of a job did: the units it walked, the checkpoint to resume from, and whether the
 * job is finished.
 *
 * @since  2.2.0
 */
final class JobChunk
{
    /**
     * @param int $units The units this chunk walked.
     * @param array<string, mixed>|null $checkpoint Where the next chunk resumes, owned by the handler; null for none.
     * @param bool $finished Whether the job has no unit left.
     * @param int|null $total The units in all as the step counted them, for the progress; null when the step did not
     *                        count them (the claim's count stands).
     * @param string|null $result What the finished job reports, as JSON, written with the finish; null for none.
     *
     * @since  2.2.0
     */
    public function __construct(
        public readonly int $units,
        public readonly ?array $checkpoint,
        public readonly bool $finished,
        public readonly ?int $total = null,
        public readonly ?string $result = null,
    ) {
    }
}
