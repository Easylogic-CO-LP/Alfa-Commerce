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
 * What one chunk of a job did (2.2.0, design 2.5): the units it walked, the checkpoint to resume from, and whether the
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
     *
     * @since  2.2.0
     */
    public function __construct(
        public readonly int $units,
        public readonly ?array $checkpoint,
        public readonly bool $finished,
    ) {
    }
}
