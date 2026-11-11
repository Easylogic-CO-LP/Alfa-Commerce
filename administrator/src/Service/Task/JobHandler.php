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
 * A job's handler (2.2.0, design 2.5): it walks the job's units through its domain service, chunk by chunk.
 *
 * The runner owns the lease, the retry, the budget, the checkpoint and the progress; the handler owns none of them.
 * chunk() runs inside the runner's chunk transaction, so the domain rows, the checkpoint and the fence commit together:
 * the domain service opens its own level (a savepoint); a handler whose policy names ownTransactions (a domain service
 * that keeps its own transactions, as the stock drain) runs before the chunk transaction opens, which then carries only
 * the progress. No external call belongs in a chunk: the fence cannot undo a
 * call a worker that lost its lease already made; external work a job owes is written as a single by the chunk. A
 * chunk that throws is rolled back and retried with backoff.
 *
 * A rerun: when work arrives for the job's scope after the job has started (while it runs, or while it waits between
 * ticks with progress), the finish puts the job back to pending with its checkpoint NULL and done_units 0, and it is
 * walked again from the start; total() is asked again at the claim. The handler re-derives its work from the domain
 * state at each chunk and never assumes what an earlier walk did.
 *
 * @since  2.2.0
 */
interface JobHandler
{
    /**
     * How its jobs are weighed, ordered and cut into chunks.
     *
     * @since  2.2.0
     */
    public function policy(): JobPolicy;

    /**
     * The units the job expects to walk in all, asked at each claim.
     *
     * @param JobRun $run The job.
     *
     * @since  2.2.0
     */
    public function total(JobRun $run): int;

    /**
     * Walk up to that many units from the run's checkpoint.
     *
     * @param JobRun $run The job.
     * @param int $units At most this many units.
     *
     * @since  2.2.0
     */
    public function chunk(JobRun $run, int $units): JobChunk;
}
