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
 * The job an enqueue wrote or joined (2.2.0, design 2.4).
 *
 * @since  2.2.0
 */
final class EnqueueResult
{
    /**
     * @param int $id The job.
     * @param bool $merged Whether it joined the scope's active job rather than writing a new one.
     *
     * @since  2.2.0
     */
    public function __construct(
        public readonly int $id,
        public readonly bool $merged,
    ) {
    }
}
