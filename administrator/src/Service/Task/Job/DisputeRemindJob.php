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
use Alfa\Component\Alfa\Administrator\Service\Order\DisputeLedger;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderDisputeEmail;
use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use DateTimeImmutable;
use DateTimeZone;

/**
 * order.dispute.remind: the staff reminder of a card dispute's deadline, three days before it. Written in the dispute's
 * save when a deadline is set or moved on an inquiry or an open chargeback, a delayed job of the deadline's own scope, so
 * a moved deadline makes a new job and leaves the old one to end with nothing.
 *
 * Its one step reads the case: still an inquiry or open, no evidence sent, the same deadline as its scope says; then it
 * writes the reminder e-mail (order.dispute_email) and calls nothing else. A case decided, answered or moved before its
 * time needs no cancel: the step ends with nothing.
 *
 * @since  2.2.2
 */
final class DisputeRemindJob implements JobHandler
{
    /**
     * The handler's name.
     *
     * @since  2.2.2
     */
    public const NAME = 'order.dispute.remind';

    /**
     * How long before the deadline staff are reminded.
     *
     * @since  2.2.2
     */
    public const DAYS_BEFORE = 3;

    /**
     * Owe the reminder of a deadline, in the caller's transaction: nothing when no store address is set (the bell
     * carries the case) or the deadline has passed.
     *
     * @param int $disputeId The dispute.
     * @param string $dueBy Its deadline, UTC Y-m-d H:i:s.
     *
     * @since  2.2.2
     */
    public static function owe(int $disputeId, string $dueBy): void
    {
        $utc = new DateTimeZone('UTC');
        $due = new DateTimeImmutable($dueBy, $utc);

        if (OrderDisputeEmail::recipient() === '' || $due <= new DateTimeImmutable('now', $utc)) {
            return;
        }

        BackgroundWork::core()
            ->job(self::NAME)
            ->scope($disputeId . ':' . $due->format('YmdHis'))
            ->with(['dispute' => $disputeId, 'due' => $due->format('Y-m-d H:i:s')])
            ->notBefore($due->modify('-' . self::DAYS_BEFORE . ' days'))
            ->enqueue();
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.2
     */
    public function policy(): JobPolicy
    {
        return new JobPolicy(label: 'COM_ALFA_TASKS_HANDLER_ORDER_DISPUTE_REMIND', weight: JobPolicy::LIGHT, initialUnits: 1, rerunOnMerge: false);
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.2
     */
    public function total(JobRun $run): int
    {
        return 1;
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.2
     */
    public function chunk(JobRun $run, int $units): JobChunk
    {
        $record = DisputeLedger::get(disputeId: (int) ($run->payload['dispute'] ?? 0));
        $owed = $record !== null
            && \in_array((string) $record->state, [DisputeLedger::STATE_INQUIRY, DisputeLedger::STATE_OPEN], true)
            && $record->evidence_sent_at === null
            && (string) $record->evidence_due_by === (string) ($run->payload['due'] ?? '');

        if ($owed && OrderDisputeEmail::recipient() !== '') {
            BackgroundWork::core()
                ->task(OrderDisputeEmail::NAME)
                ->about('order', (int) $record->id_order)
                ->with(['dispute' => (int) $record->id, 'state' => OrderDisputeEmail::REMINDER])
                ->enqueue();
        }

        return new JobChunk(units: 1, checkpoint: null, finished: true);
    }
}
