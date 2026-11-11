<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Throwable;

/**
 * A handler whose done run leaves a record that must outlive the task (2.2.0): anything that must outlive a task is
 * written to the business history before the task may be pruned. The record is written inside the transaction that
 * settles the run done, with a write that reports failure, so a task is never done without it: when it cannot be
 * written the settle is rolled back, the task stays running until its claim passes, and recovery holds it for staff.
 *
 * @since  2.2.0
 */
interface TaskSettleRecorder
{
    /**
     * Write the record of a run that settled done, inside the settle's transaction.
     *
     * @param TaskSettlement $settlement The run, with its history.
     *
     * @throws Throwable When the record cannot be written: the settle is rolled back.
     *
     * @since  2.2.0
     */
    public function recordDone(TaskSettlement $settlement): void;
}
