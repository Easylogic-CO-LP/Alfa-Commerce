<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderDocuments;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderEntitlements;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderStock;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\PaymentAutomation;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\PaymentRefundFlip;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\ShipmentAutomation;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\CatalogueRebuildJob;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\SearchRebuildJob;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\StockEffectsDrainJob;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\TasksPruneJob;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\ThumbnailsRecreateJob;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

/**
 * Which finished tasks may go, and their deletion a batch at a time (2.2.0): the one place the retention rules live.
 * The tasks.prune job, the bell's fallback, the Tools button and alfa:tasks:prune all call it.
 *
 * The rules, by state and handler:
 * - pending and running: never, while active;
 * - failed and held: never by age; they stay until staff act (Resend, Run again, Retry, Mark resolved) or, for a job,
 *   until a later success of its scope closes it;
 * - resolved and cancelled: KEEP_DAYS after the act (finished_at);
 * - done singles: SHORT_DAYS for those whose effect the order itself shows (stock, entitlements, documents, payment
 *   and shipment automation, the refund flip), KEEP_DAYS for every other (the e-mails, the plugin reactions);
 * - done jobs: KEEP_DAYS, except the newest done job of each shop-wide scope (SHOP_WIDE_JOBS), kept for the last run;
 *   a price set's job follows the plain KEEP_DAYS, its set row carrying prepared_at.
 *
 * A task of another owner than com_alfa (the task API's line): done singles and jobs KEEP_DAYS and no newest kept,
 * the rest as above. Every task is com_alfa's in 2.2.0 (core's handlers only), so the line costs no query: a handler
 * this class does not name is held to it, which is the KEEP_DAYS rule.
 *
 * Anything that must outlive a task is written to the order's timeline before the task may go (the order.email_sent
 * record, OrderStatusEmail).
 *
 * @since  2.2.0
 */
final class TaskRetention
{
    /**
     * The owner every task has in 2.2.0.
     *
     * @since  2.2.0
     */
    public const OWNER = 'com_alfa';

    /**
     * Days a resolved or cancelled task, a done job and a done single with no shorter window are kept.
     *
     * @since  2.2.0
     */
    public const KEEP_DAYS = 30;

    /**
     * Days the done singles of SHORT_HANDLERS are kept.
     *
     * @since  2.2.0
     */
    public const SHORT_DAYS = 7;

    /**
     * The singles whose effect the order shows on its own (its stock, downloads, documents and status moves): kept
     * SHORT_DAYS once done.
     *
     * @since  2.2.0
     */
    public const SHORT_HANDLERS = [
        OrderStock::NAME,
        OrderEntitlements::NAME,
        OrderDocuments::NAME,
        PaymentAutomation::NAME,
        ShipmentAutomation::NAME,
        PaymentRefundFlip::NAME,
    ];

    /**
     * The jobs of a shop-wide scope: the newest done job of each scope is kept beyond KEEP_DAYS.
     *
     * @since  2.2.0
     */
    public const SHOP_WIDE_JOBS = [
        StockEffectsDrainJob::NAME,
        CatalogueRebuildJob::NAME,
        SearchRebuildJob::NAME,
        TasksPruneJob::NAME,
        // Its last run's report (2.2.0, part C21).
        ThumbnailsRecreateJob::NAME,
    ];

    /**
     * Rows deleted per statement by prune().
     *
     * @since  2.2.0
     */
    public const BATCH = 500;

    /**
     * Delete one batch of the tasks the rules let go, by primary key: the long rule's first, oldest finished first,
     * then the short rule's.
     *
     * @param int $limit At most this many.
     *
     * @return int How many were deleted.
     *
     * @since  2.2.0
     */
    public static function pruneChunk(int $limit): int
    {
        $db = self::db();
        $limit = max(1, $limit);
        $ids = self::ids(db: $db, query: self::pastKeep(db: $db)->order($db->quoteName('finished_at') . ' ASC'), limit: $limit);

        if (\count($ids) < $limit) {
            $ids = [...$ids, ...self::ids(db: $db, query: self::pastShort(db: $db), limit: $limit - \count($ids))];
        }

        if ($ids === []) {
            return 0;
        }

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName(Tasks::TABLE))
                ->whereIn($db->quoteName('id'), $ids)
                ->whereIn($db->quoteName('state'), [Tasks::STATE_DONE, Tasks::STATE_RESOLVED, Tasks::STATE_CANCELLED], ParameterType::STRING),
        )->execute();

        return $db->getAffectedRows();
    }

    /**
     * Delete what the rules let go, a batch at a time, until nothing is left or the budget is spent.
     *
     * @param int $budgetMs The time it may take; the batch running when it ends finishes.
     *
     * @return int How many were deleted.
     *
     * @since  2.2.0
     */
    public static function prune(int $budgetMs): int
    {
        $start = hrtime(true);
        $deleted = 0;

        do {
            $batch = self::pruneChunk(limit: self::BATCH);
            $deleted += $batch;
        } while ($batch === self::BATCH && (hrtime(true) - $start) / 1e6 < $budgetMs);

        return $deleted;
    }

    /**
     * How many tasks the rules let go now.
     *
     * @since  2.2.0
     */
    public static function eligibleCount(): int
    {
        $db = self::db();

        return (int) $db->setQuery(self::pastKeep(db: $db)->select('COUNT(*)'))->loadResult()
            + (int) $db->setQuery(self::pastShort(db: $db)->select('COUNT(*)'))->loadResult();
    }

    /**
     * Whether a tasks.prune job finished within the hours given: the runner's prune is keeping up.
     *
     * @param int $hours The hours.
     *
     * @since  2.2.0
     */
    public static function prunedWithin(int $hours): bool
    {
        $db = self::db();
        $handler = TasksPruneJob::NAME;
        $done = Tasks::STATE_DONE;

        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('handler') . ' = :handler')
                ->where($db->quoteName('state') . ' = :done')
                ->where($db->quoteName('finished_at') . ' >= UTC_TIMESTAMP(3) - INTERVAL ' . max(1, $hours) . ' HOUR')
                ->bind(':handler', $handler)
                ->bind(':done', $done),
            0,
            1,
        )->loadResult() > 0;
    }

    /**
     * The long rule: every finished task past KEEP_DAYS (done, resolved, cancelled) but the newest done job of each
     * shop-wide scope. A range of idx_finished, which in steady state holds little else than what may go: the failed
     * and held tasks staff have not handled.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.2.0
     */
    private static function pastKeep(DatabaseDriver $db): QueryInterface
    {
        $kept = self::newestOfShopWideScopes(db: $db);
        $query = $db->getQuery(true)
            ->from($db->quoteName(Tasks::TABLE))
            ->where($db->quoteName('finished_at') . ' < UTC_TIMESTAMP(3) - INTERVAL ' . self::KEEP_DAYS . ' DAY')
            ->whereIn($db->quoteName('state'), [Tasks::STATE_DONE, Tasks::STATE_RESOLVED, Tasks::STATE_CANCELLED], ParameterType::STRING);

        return $kept === [] ? $query : $query->whereNotIn($db->quoteName('id'), $kept);
    }

    /**
     * The short rule: the done singles of SHORT_HANDLERS between SHORT_DAYS and KEEP_DAYS (the older ones are the long
     * rule's). A range of idx_handler_state_finished for each handler, so the done e-mails and reactions of the same
     * days are never read.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.2.0
     */
    private static function pastShort(DatabaseDriver $db): QueryInterface
    {
        $done = Tasks::STATE_DONE;

        return $db->getQuery(true)
            ->from($db->quoteName(Tasks::TABLE))
            ->whereIn($db->quoteName('handler'), self::SHORT_HANDLERS, ParameterType::STRING)
            ->where($db->quoteName('state') . ' = :done')
            ->where($db->quoteName('finished_at') . ' < UTC_TIMESTAMP(3) - INTERVAL ' . self::SHORT_DAYS . ' DAY')
            ->where($db->quoteName('finished_at') . ' >= UTC_TIMESTAMP(3) - INTERVAL ' . self::KEEP_DAYS . ' DAY')
            ->bind(':done', $done);
    }

    /**
     * The ids a rule's query selects, at most a limit.
     *
     * @param DatabaseDriver $db The connection.
     * @param QueryInterface $query The rule.
     * @param int $limit At most this many.
     *
     * @return int[]
     *
     * @since  2.2.0
     */
    private static function ids(DatabaseDriver $db, QueryInterface $query, int $limit): array
    {
        return array_map('intval', $db->setQuery($query->select($db->quoteName('id')), 0, $limit)->loadColumn() ?: []);
    }

    /**
     * The newest done job of each shop-wide scope: one id per scope_key (a scope's jobs run one after the other, so
     * the highest id is the last to finish).
     *
     * @param DatabaseDriver $db The connection.
     *
     * @return int[]
     *
     * @since  2.2.0
     */
    private static function newestOfShopWideScopes(DatabaseDriver $db): array
    {
        $type = Tasks::TYPE_JOB;
        $done = Tasks::STATE_DONE;

        return array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select('MAX(' . $db->quoteName('id') . ')')
                ->from($db->quoteName(Tasks::TABLE))
                ->whereIn($db->quoteName('handler'), self::SHOP_WIDE_JOBS, ParameterType::STRING)
                ->where($db->quoteName('state') . ' = :done')
                ->where($db->quoteName('type') . ' = :type')
                ->group($db->quoteName('scope_key'))
                ->bind(':done', $done)
                ->bind(':type', $type),
        )->loadColumn() ?: []);
    }

    private static function db(): DatabaseDriver
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }
}
