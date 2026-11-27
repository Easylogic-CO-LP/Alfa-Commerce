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
 * A handler that hears how its tasks end: a run settled, recovery held a run that stopped, or staff resent, ran again
 * or resolved one. A job's handler hears when its job failed for good and when staff resolved a failed one. What it
 * does with that (an order's timeline record, a check's bell) never changes the task.
 *
 * @since  2.1.4
 */
interface TaskSettleListener
{
    /**
     * One of this handler's tasks ended, or staff acted on it.
     *
     * @param TaskSettlement $settlement What happened.
     *
     * @since  2.1.4
     */
    public function settled(TaskSettlement $settlement): void;
}
