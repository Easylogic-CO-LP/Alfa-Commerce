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
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryContext;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryUnitException;
use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use RuntimeException;

/**
 * Repair the stock figures of the products a section of the inventory audit lists, one product after the other, in
 * the background: the audited repair of each ({@see InventoryService::reconcile()}), recorded under the operator who
 * asked, with the figures corrected and the units offered for sale again counted as it goes. The run stops at the
 * first product that refuses, as the inline repair does, and its result names that product and why; what was repaired
 * before it stands.
 *
 * The inventory audit repairs up to INLINE_MAX products in the request itself; more than that is this job. One job per
 * selection of products: the same selection asked again joins the job that waits or runs.
 *
 * @since  2.3.0
 */
final class StockRepairJob implements JobHandler
{
    /**
     * The handler's name.
     *
     * @since  2.3.0
     */
    public const NAME = 'stock.repair';

    /**
     * The most products the inventory audit repairs in the request itself; more than that runs as this job.
     *
     * @since  2.3.0
     */
    public const INLINE_MAX = 50;

    /**
     * The most products one job takes: its data is at most 16 KiB encoded.
     *
     * @since  2.3.0
     */
    public const ITEMS_MAX = 1500;

    /**
     * Ask for the repair of these products, recorded under this operator: the job written, or the one of the same
     * products that waits or runs, joined.
     *
     * @param list<int> $itemIds The products, in the order they are repaired.
     * @param int $userId The operator the repairs are recorded under.
     *
     * @since  2.3.0
     */
    public static function enqueue(array $itemIds, int $userId): Enqueued
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));

        return BackgroundWork::core()->job(self::NAME)
            ->scope(self::scope(itemIds: $itemIds))
            ->with(['items' => $itemIds, 'user' => $userId])
            ->enqueue();
    }

    /**
     * What a job of these products covers: a digest of the sorted products, so the same selection in any order is one
     * job.
     *
     * @param list<int> $itemIds The products.
     *
     * @since  2.3.0
     */
    public static function scope(array $itemIds): string
    {
        sort($itemIds);

        return md5(implode(',', $itemIds));
    }

    public function policy(): JobPolicy
    {
        return new JobPolicy(label: 'COM_ALFA_TASKS_HANDLER_STOCK_REPAIR', weight: JobPolicy::LIGHT, priority: 100, initialUnits: 5, rerunOnMerge: false);
    }

    public function total(JobRun $run): int
    {
        return \count(self::items(run: $run));
    }

    public function chunk(JobRun $run, int $units): JobChunk
    {
        $items = self::items(run: $run);
        $checkpoint = $run->checkpoint ?? ['total' => \count($items), 'next' => 0, 'repaired' => 0, 'corrected' => 0, 'released' => 0];
        $next = (int) $checkpoint['next'];
        $service = new InventoryService();
        $context = new InventoryContext(userId: (int) ($run->payload['user'] ?? 0));
        $walked = 0;

        while ($walked < $units && $next < \count($items)) {
            $itemId = $items[$next];

            try {
                $result = $service->reconcile(itemId: $itemId, sourceType: 'inventory_repair', sourceId: $itemId, context: $context);
            } catch (RuntimeException $e) {
                // The first refusal ends the run; what was repaired before it stands.
                $checkpoint['next'] = $next;

                return new JobChunk(units: $walked, checkpoint: $checkpoint, finished: true, result: self::result(checkpoint: $checkpoint, total: \count($items), refused: [
                    'item' => $itemId,
                    'gone' => $e instanceof InventoryUnitException && $e->reason === InventoryUnitException::ITEM_GONE,
                    'reason' => $e instanceof InventoryUnitException ? $e->operatorMessage() : ErrorText::of($e),
                ]));
            }

            $next++;
            $walked++;
            $checkpoint['next'] = $next;
            $checkpoint['repaired'] = (int) $checkpoint['repaired'] + 1;
            $checkpoint['corrected'] = (int) $checkpoint['corrected'] + \count($result['corrections']);
            $checkpoint['released'] = (int) $checkpoint['released'] + (int) $result['holds_released'];
        }

        $finished = $next >= \count($items);

        return new JobChunk(
            units: $walked,
            checkpoint: $checkpoint,
            finished: $finished,
            result: $finished ? self::result(checkpoint: $checkpoint, total: \count($items), refused: null) : null,
        );
    }

    /**
     * What a job reports so far, from its checkpoint while it runs and from its result once it ended: the products
     * in all and repaired, the figures corrected, the units offered for sale again, and the product it stopped at
     * with why.
     *
     * @param array<string, mixed> $row The job's row: its checkpoint and result as read.
     *
     * @return array{total: int, repaired: int, corrected: int, released: int, refused: array{item: int, gone: bool, reason: string}|null}|null
     *                                  Null before its first step.
     *
     * @since  2.3.0
     */
    public static function reportOf(array $row): ?array
    {
        $result = json_decode((string) ($row['result'] ?? ''), true);

        if (\is_array($result) && isset($result['total'])) {
            return self::shape(report: $result);
        }

        $checkpoint = json_decode((string) ($row['checkpoint'] ?? ''), true);

        return \is_array($checkpoint) && isset($checkpoint['next']) ? self::shape(report: $checkpoint + ['refused' => null]) : null;
    }

    /**
     * The products a job repairs, as its data names them.
     *
     * @param JobRun $run The job.
     *
     * @return list<int>
     *
     * @since  2.3.0
     */
    private static function items(JobRun $run): array
    {
        return array_values(array_map('intval', (array) ($run->payload['items'] ?? [])));
    }

    /**
     * The result written with the finish, as JSON.
     *
     * @param array<string, mixed> $checkpoint The counts so far.
     * @param int $total The products in all.
     * @param array{item: int, gone: bool, reason: string}|null $refused The product the run stopped at, or null.
     *
     * @since  2.3.0
     */
    private static function result(array $checkpoint, int $total, ?array $refused): string
    {
        return (string) json_encode(self::shape(report: $checkpoint + ['total' => $total, 'refused' => $refused]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A report in its fixed shape, whatever it was read from.
     *
     * @param array<string, mixed> $report The counts and the refusal.
     *
     * @return array{total: int, repaired: int, corrected: int, released: int, refused: array{item: int, gone: bool, reason: string}|null}
     *
     * @since  2.3.0
     */
    private static function shape(array $report): array
    {
        $refused = $report['refused'] ?? null;

        return [
            'total' => (int) ($report['total'] ?? 0),
            'repaired' => (int) ($report['repaired'] ?? 0),
            'corrected' => (int) ($report['corrected'] ?? 0),
            'released' => (int) ($report['released'] ?? 0),
            'refused' => \is_array($refused) ? [
                'item' => (int) ($refused['item'] ?? 0),
                'gone' => (bool) ($refused['gone'] ?? false),
                'reason' => (string) ($refused['reason'] ?? ''),
            ] : null,
        ];
    }
}
