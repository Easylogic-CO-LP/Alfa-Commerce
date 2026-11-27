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
use Alfa\Component\Alfa\Administrator\Background\Enqueued;
use Alfa\Component\Alfa\Administrator\Service\Pricing\DiscountReach;
use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * Count a discount rule's reach when the card's estimate says it is too much work for the form's request: the base
 * counts first (the products reached, the products outside, the exclusions), then the comparison with the other
 * combining rules a batch of rules at a time, the runner sizing the batches by the time the last one took; the reach is
 * stored at the end (DiscountReach::store()) for the card to show with its moment. One job per rule, its scope the
 * rule's id; a second ask joins the one waiting or running. Light: it reads and writes the result only.
 *
 * @since  2.3.0
 */
final class DiscountReachJob implements JobHandler
{
    /**
     * The handler's name.
     *
     * @since  2.3.0
     */
    public const NAME = 'discount.reach';

    /**
     * The rules a first chunk compares: a batch, from which the runner sizes the next by the time it took.
     *
     * @since  2.3.0
     */
    private const FIRST_BATCH = 20;

    /**
     * The scope of a rule's job.
     *
     * @param int $discountId The rule.
     *
     * @since  2.3.0
     */
    public static function scopeFor(int $discountId): string
    {
        return self::NAME . ':' . $discountId;
    }

    /**
     * Owe a rule's count: its job, or the one it already has.
     *
     * @param int $discountId The rule.
     *
     * @since  2.3.0
     */
    public static function enqueueFor(int $discountId): Enqueued
    {
        return BackgroundWork::core()->job(self::NAME)->scope((string) $discountId)->with(['discount' => $discountId])->enqueue();
    }

    public function policy(): JobPolicy
    {
        return new JobPolicy(label: 'COM_ALFA_TASKS_HANDLER_DISCOUNT_REACH', weight: JobPolicy::LIGHT, priority: 10, initialUnits: self::FIRST_BATCH, rerunOnMerge: false, ownTransactions: true);
    }

    public function total(JobRun $run): int
    {
        $state = $run->checkpoint;

        if (\is_array($state) && isset($state['others'])) {
            return 1 + \count((array) $state['others']);
        }

        $reach = new DiscountReach(db: Factory::getContainer()->get(DatabaseInterface::class));
        $base = $reach->base(discountId: (int) ($run->payload['discount'] ?? 0));

        // One unit the base, one unit a rule compared.
        return 1 + ($base !== null && (int) $base['behavior'] !== 0 ? \count($reach->others(except: (int) $base['id'])) : 0);
    }

    public function chunk(JobRun $run, int $units): JobChunk
    {
        $discountId = (int) ($run->payload['discount'] ?? 0);
        $reach = new DiscountReach(db: Factory::getContainer()->get(DatabaseInterface::class));
        $state = $run->checkpoint;

        if (!\is_array($state) || !isset($state['base'])) {
            $base = $reach->base(discountId: $discountId);

            if ($base === null) {
                // The rule is gone: nothing to count, nothing to store.
                return new JobChunk(units: 1, checkpoint: null, finished: true);
            }

            $others = (int) $base['behavior'] !== 0 ? $reach->others(except: $discountId) : [];
            $state = [
                'base' => $base,
                'others' => array_map(static fn (object $other): array => ['id' => (int) $other->id, 'name' => (string) $other->name], $others),
                'after' => 0,
                'stacks' => [],
            ];

            return new JobChunk(units: 1, checkpoint: $state, finished: false, total: 1 + \count($others));
        }

        $others = (array) $state['others'];
        $batch = \array_slice($others, (int) $state['after'], max(1, $units));

        if ($batch !== []) {
            $stacks = $reach->stacksWith(discountId: $discountId, others: array_map(static fn (array $other): object => (object) $other, $batch));
            $state['stacks'] = array_merge((array) $state['stacks'], $stacks);
            $state['after'] = (int) $state['after'] + \count($batch);
        }

        if ((int) $state['after'] < \count($others)) {
            return new JobChunk(units: \count($batch), checkpoint: $state, finished: false);
        }

        $result = (array) $state['base'] + ['stacks' => (array) $state['stacks'], 'compared' => \count($others), 'computed_at' => gmdate('Y-m-d H:i:s')];
        $reach->store(discountId: $discountId, reach: $result);

        return new JobChunk(
            units: \count($batch),
            checkpoint: null,
            finished: true,
            result: json_encode(['reaches' => (int) $result['reaches'], 'stacks' => \count($result['stacks']), 'compared' => \count($others)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
