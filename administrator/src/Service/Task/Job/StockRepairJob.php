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
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryIntegrityReader;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryUnitException;
use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use RuntimeException;

/**
 * Repair the stock figures of the products a section of the inventory audit lists, one product after the other, in
 * the background: the audited repair of each ({@see InventoryService::reconcile()}), recorded under the operator who
 * asked, with the figures corrected and the units offered for sale again counted as it goes. The run stops at the
 * first product that refuses, as the inline repair does, and its result names that product and why; what was repaired
 * before it stands.
 *
 * The inventory audit repairs a listed selection of up to INLINE_MAX products in the request itself; more than that,
 * and a whole audit section, is this job. A section is the job's scope: the shop's products are walked by id in
 * steps and the ones the section lists when they are reached are repaired, so no total is carried in the request and
 * no list is too long. One job per selection or section: the same one asked again joins the job that waits or runs.
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
     * The most listed products the inventory audit repairs in the request itself; more than that, and a whole
     * section, runs as this job.
     *
     * @since  2.3.0
     */
    public const INLINE_MAX = 50;

    /**
     * The audit's sections whose rows a repair fixes: a job asked for a section walks every product of the shop and
     * repairs those the section lists at that moment, so no list of ids is ever carried.
     *
     * @var list<string>
     *
     * @since  2.3.0
     */
    public const SECTIONS = [
        InventoryIntegrityReader::CATEGORY_LEDGER_MISSING,
        InventoryIntegrityReader::CATEGORY_PHYSICAL_MISMATCH,
        InventoryIntegrityReader::CATEGORY_SALABLE_MISMATCH,
        InventoryIntegrityReader::CATEGORY_UNTRACKED_PROJECTION,
        InventoryIntegrityReader::CATEGORY_INTEGRITY_HOLD,
    ];

    /**
     * Ask for the repair of these products, recorded under this operator: the job written, or the one of the same
     * products that waits or runs, joined. A list too large for a job's data is refused by the request
     * (InvalidArgumentException): a whole section is asked for with {@see self::enqueueSection()} instead.
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
     * Ask for the repair of every product an audit section lists, recorded under this operator: the job walks the
     * shop's products in steps and repairs the ones the section lists when it reaches them. One job per section: the
     * same section asked again joins the job that waits or runs.
     *
     * @param string $section The section ({@see self::SECTIONS}).
     * @param int $userId The operator the repairs are recorded under.
     *
     * @throws InvalidArgumentException When the section is not one a repair fixes.
     *
     * @since  2.3.0
     */
    public static function enqueueSection(string $section, int $userId): Enqueued
    {
        if (!\in_array($section, self::SECTIONS, true)) {
            throw new InvalidArgumentException('No repair fixes the audit section ' . $section . '.');
        }

        return BackgroundWork::core()->job(self::NAME)
            ->scope('section:' . $section)
            ->with(['section' => $section, 'user' => $userId])
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

    /**
     * The section a job repairs, '' for a job of listed products.
     *
     * @param array<string, mixed> $payload The job's data.
     *
     * @since  2.3.0
     */
    public static function section(array $payload): string
    {
        return (string) ($payload['section'] ?? '');
    }

    public function policy(): JobPolicy
    {
        return new JobPolicy(label: 'COM_ALFA_TASKS_HANDLER_STOCK_REPAIR', weight: JobPolicy::LIGHT, priority: 100, initialUnits: 5, rerunOnMerge: false);
    }

    public function total(JobRun $run): int
    {
        if (self::section(payload: $run->payload) === '') {
            return \count(self::items(run: $run));
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        return (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_items'))->loadResult();
    }

    public function chunk(JobRun $run, int $units): JobChunk
    {
        $section = self::section(payload: $run->payload);
        $checkpoint = $run->checkpoint ?? ['total' => $this->total(run: $run), 'next' => 0, 'after' => 0, 'checked' => 0, 'repaired' => 0, 'corrected' => 0, 'released' => 0];
        $service = new InventoryService();
        $context = new InventoryContext(userId: (int) ($run->payload['user'] ?? 0));

        // The products this step checks: the listed ones from the next index, or the shop's next products by id with
        // what the section lists of them at this moment.
        if ($section === '') {
            $items = self::items(run: $run);
            $checked = \array_slice($items, (int) $checkpoint['next'], $units);
            $toRepair = $checked;
            $finished = (int) $checkpoint['next'] + \count($checked) >= \count($items);
        } else {
            $checked = self::productsAfter(after: (int) $checkpoint['after'], limit: $units);
            $toRepair = self::listedBy(section: $section, itemIds: $checked);
            $finished = \count($checked) < $units;
        }

        $walked = 0;

        foreach ($toRepair as $itemId) {
            try {
                $result = $service->reconcile(itemId: $itemId, sourceType: 'inventory_repair', sourceId: $itemId, context: $context);
            } catch (RuntimeException $e) {
                // The first refusal ends the run; what was repaired before it stands, and the products checked before it
                // are counted.
                $checkpoint['checked'] = (int) $checkpoint['checked'] + $walked;
                $checkpoint['next'] = (int) $checkpoint['next'] + $walked;

                return new JobChunk(units: $walked, checkpoint: $checkpoint, finished: true, result: self::result(checkpoint: $checkpoint, refused: [
                    'item' => $itemId,
                    'gone' => $e instanceof InventoryUnitException && $e->reason === InventoryUnitException::ITEM_GONE,
                    'reason' => $e instanceof InventoryUnitException ? $e->operatorMessage() : ErrorText::of($e),
                ]));
            }

            $walked++;
            $checkpoint['repaired'] = (int) $checkpoint['repaired'] + 1;
            $checkpoint['corrected'] = (int) $checkpoint['corrected'] + \count($result['corrections']);
            $checkpoint['released'] = (int) $checkpoint['released'] + (int) $result['holds_released'];
        }

        $checkpoint['checked'] = (int) $checkpoint['checked'] + \count($checked);
        $checkpoint['next'] = (int) $checkpoint['next'] + \count($checked);
        $checkpoint['after'] = $checked === [] ? (int) $checkpoint['after'] : max((int) $checkpoint['after'], max($checked));

        return new JobChunk(
            units: \count($checked),
            checkpoint: $checkpoint,
            finished: $finished,
            result: $finished ? self::result(checkpoint: $checkpoint, refused: null) : null,
        );
    }

    /**
     * The shop's next products by id after the one given.
     *
     * @param int $after The last product checked, 0 at the start.
     * @param int $limit At most this many.
     *
     * @return list<int>
     *
     * @since  2.3.0
     */
    private static function productsAfter(int $after, int $limit): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        return array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_items'))
                ->where($db->quoteName('id') . ' > :after')
                ->order($db->quoteName('id') . ' ASC')
                ->bind(':after', $after, ParameterType::INTEGER),
            0,
            max(1, $limit),
        )->loadColumn() ?: []);
    }

    /**
     * Of these products, the ones an audit section lists at this moment, each once, in the order given.
     *
     * @param string $section The section.
     * @param list<int> $itemIds The products checked.
     *
     * @return list<int>
     *
     * @since  2.3.0
     */
    private static function listedBy(string $section, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $findings = (new InventoryIntegrityReader(db: Factory::getContainer()->get(DatabaseInterface::class)))->audit(itemIds: $itemIds)['findings'][$section] ?? [];
        $listed = [];

        foreach ($findings as $finding) {
            if ((int) ($finding['item_id'] ?? 0) > 0) {
                $listed[(int) $finding['item_id']] = true;
            }
        }

        return array_values(array_filter($itemIds, static fn (int $id): bool => isset($listed[$id])));
    }

    /**
     * What a job reports so far, from its checkpoint while it runs and from its result once it ended: the products
     * in all, checked and repaired, the figures corrected, the units offered for sale again, and the product it
     * stopped at with why.
     *
     * @param array<string, mixed> $row The job's row: its checkpoint and result as read.
     *
     * @return array{total: int, checked: int, repaired: int, corrected: int, released: int, refused: array{item: int, gone: bool, reason: string}|null}|null
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

        return \is_array($checkpoint) && isset($checkpoint['checked']) ? self::shape(report: $checkpoint + ['refused' => null]) : null;
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
     * @param array<string, mixed> $checkpoint The counts so far, the products in all among them.
     * @param array{item: int, gone: bool, reason: string}|null $refused The product the run stopped at, or null.
     *
     * @since  2.3.0
     */
    private static function result(array $checkpoint, ?array $refused): string
    {
        return (string) json_encode(self::shape(report: $checkpoint + ['refused' => $refused]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A report in its fixed shape, whatever it was read from.
     *
     * @param array<string, mixed> $report The counts and the refusal.
     *
     * @return array{total: int, checked: int, repaired: int, corrected: int, released: int, refused: array{item: int, gone: bool, reason: string}|null}
     *
     * @since  2.3.0
     */
    private static function shape(array $report): array
    {
        $refused = $report['refused'] ?? null;

        return [
            'total' => (int) ($report['total'] ?? 0),
            'checked' => (int) ($report['checked'] ?? 0),
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
