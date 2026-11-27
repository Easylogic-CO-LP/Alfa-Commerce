<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task\Job;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Background\BackgroundWork;
use Alfa\Component\Alfa\Administrator\Service\Pricing\PriceAudit;
use Alfa\Component\Alfa\Administrator\Service\Pricing\PriceAuditNotice;
use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;

/**
 * Check the prices: the price check's report (PriceAudit::read()), read in one go, and the bell notice that follows
 * its findings (PriceAuditNotice::follow()), so that a finding is seen without the dashboard. Owed by the runner once
 * a day (the span of its policy, as it owes every scheduled job of every owner), and runnable from Tools > Tasks.
 *
 * Light, low priority, one unit: the report reads the price index and the price rows and prices at most a capped number
 * of sale rows. It keeps its own transactions: the report is one read-only snapshot, the notice its own write. Its
 * result is the findings' counts and their sentence.
 *
 * @since  2.3.0
 */
final class PriceAuditJob implements JobHandler
{
    /**
     * The handler's name, and its one scope.
     *
     * @since  2.3.0
     */
    public const NAME = 'price.audit';

    /**
     * Owe a check: the job, or the one already waiting or running.
     *
     * @return int The job.
     *
     * @since  2.3.0
     */
    public static function enqueue(): int
    {
        return BackgroundWork::core()->job(self::NAME)->enqueue()->id;
    }

    public function policy(): JobPolicy
    {
        return new JobPolicy(label: 'COM_ALFA_TASKS_HANDLER_PRICE_AUDIT', weight: JobPolicy::LIGHT, priority: 5, initialUnits: 1, rerunOnMerge: false, ownTransactions: true, everyMinutes: 1440);
    }

    public function total(JobRun $run): int
    {
        return 1;
    }

    public function chunk(JobRun $run, int $units): JobChunk
    {
        $report = PriceAudit::read();
        PriceAuditNotice::follow(report: $report);

        return new JobChunk(
            units: 1,
            checkpoint: null,
            finished: true,
            total: 1,
            result: json_encode(['counts' => PriceAudit::counts(report: $report), 'sentence' => PriceAudit::sentence(report: $report)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
