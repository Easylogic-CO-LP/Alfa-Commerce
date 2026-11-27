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
use Alfa\Component\Alfa\Administrator\Service\Pricing\PriceAudit;
use Alfa\Component\Alfa\Administrator\Service\Pricing\PriceAuditNotice;
use Alfa\Component\Alfa\Administrator\Service\Pricing\PriceAuditStore;
use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use Joomla\CMS\Factory;

/**
 * Check the prices: the whole price check computed in bounded pieces from a checkpoint, its result stored
 * (PriceAuditStore) for the price check page, the dashboard tile and the bell, which read nothing else. Owed by the
 * runner once a day (the span of its policy), queued by "Check again" on the page and the tile (one job: a second ask
 * joins the one waiting or running), and runnable from Tools > Tasks; every trigger runs this same handler.
 *
 * The pieces, in order: each section read as a set, its count in one statement, then its rows a page at a time in a
 * fixed order (one unit a section); then the sale rows, read by id after a cursor and judged by the engine a batch at a
 * time, every one of them (one unit a row), the runner sizing the batches by the time the last one took; last the price
 * index counters, the result stored in one transaction and the bell notice brought in line. The findings gather in
 * the checkpoint until then, so the previous complete result stays readable while a run is in progress, and a run that
 * stops goes on from its checkpoint. Light: it reads and prices, it writes the result only.
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
     * The rows a first chunk reads: a batch, from which the runner sizes the next by the time it took.
     *
     * @since  2.3.0
     */
    private const FIRST_BATCH = 50;

    /**
     * Owe a check: the job, or the one already waiting or running.
     *
     * @since  2.3.0
     */
    public static function enqueue(): Enqueued
    {
        return BackgroundWork::core()->job(self::NAME)->enqueue();
    }

    public function policy(): JobPolicy
    {
        return new JobPolicy(label: 'COM_ALFA_TASKS_HANDLER_PRICE_AUDIT', weight: JobPolicy::LIGHT, priority: 5, initialUnits: self::FIRST_BATCH, rerunOnMerge: false, ownTransactions: true, everyMinutes: 1440);
    }

    public function total(JobRun $run): int
    {
        $state = $run->checkpoint;
        $audit = \is_array($state) && isset($state['now']) ? PriceAudit::resume(db: Factory::getContainer()->get('DatabaseDriver'), state: $state) : PriceAudit::begin();

        // One unit a set section, one a sale row, one the index.
        return \count(PriceAudit::SET_SECTIONS) + $audit->saleCount() + 1;
    }

    public function chunk(JobRun $run, int $units): JobChunk
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $state = $run->checkpoint;
        $units = max(1, $units);

        if (!\is_array($state) || !isset($state['now'])) {
            $audit = PriceAudit::begin(db: $db);
            $state = [
                'now' => $audit->now(),
                'context' => $audit->context(),
                'options' => $audit->optionsUsed(),
                'phase' => 'sets',
                'section' => 0,
                'offset' => 0,
                'after' => 0,
                'engine' => ['runs' => 0, 'unchecked' => 0],
                'sections' => [],
                'rows' => [],
            ];
        } else {
            $audit = PriceAudit::resume(db: $db, state: $state);
        }

        if ($state['phase'] === 'sets') {
            $key = PriceAudit::SET_SECTIONS[(int) $state['section']];

            if (!isset($state['sections'][$key])) {
                $count = $audit->count(section: $key);
                $state['sections'][$key] = ['count' => (int) $count['count'], 'available' => $audit->available(section: $key)] + (isset($count['taxes']) ? ['taxes' => (int) $count['taxes']] : []);
                $state['rows'][$key] = [];
                $state['offset'] = 0;
            }

            $page = $audit->rows(section: $key, offset: (int) $state['offset'], limit: $units);
            $state['rows'][$key] = array_merge((array) $state['rows'][$key], $page);
            $state['offset'] = (int) $state['offset'] + \count($page);
            $sectionDone = \count($page) < $units || (int) $state['offset'] >= (int) $state['sections'][$key]['count'];

            if ($sectionDone) {
                $state['section'] = (int) $state['section'] + 1;
                $state['offset'] = 0;

                if ((int) $state['section'] >= \count(PriceAudit::SET_SECTIONS)) {
                    $state['phase'] = 'sales';
                }
            }

            return new JobChunk(units: $sectionDone ? 1 : 0, checkpoint: $state, finished: false);
        }

        if ($state['phase'] === 'sales') {
            $rows = $audit->saleRows(after: (int) $state['after'], limit: $units);
            $judged = $audit->judgeSales(rows: $rows);

            foreach ($judged['sections'] as $section => $found) {
                $state['rows'][$section] = array_merge((array) ($state['rows'][$section] ?? []), $found);
            }

            $state['engine']['runs'] = (int) $state['engine']['runs'] + (int) $judged['runs'];
            $state['engine']['unchecked'] = (int) $state['engine']['unchecked'] + (int) $judged['unchecked'];

            if ($rows !== []) {
                $state['after'] = (int) end($rows)->id;
            }

            if (\count($rows) < $units) {
                $state['phase'] = 'index';

                foreach (PriceAudit::SALE_SECTIONS as $section) {
                    $state['sections'][$section] = ['count' => \count((array) ($state['rows'][$section] ?? [])), 'available' => true];
                }
            }

            return new JobChunk(units: \count($rows), checkpoint: $state, finished: false);
        }

        // The last piece: the index counters, then the result stored whole and the bell brought in line with it.
        $summary = [
            'now' => $state['now'],
            'finished_at' => gmdate('Y-m-d H:i:s'),
            'context' => $state['context'],
            'options' => $state['options'],
            'engine' => $state['engine'],
            'sections' => $state['sections'],
            'index' => $audit->index(),
        ];
        (new PriceAuditStore(db: $db))->store(summary: $summary, rowsBySection: (array) $state['rows']);
        $counts = PriceAudit::counts(summary: $summary);
        $sentence = PriceAudit::sentence(summary: $summary);
        PriceAuditNotice::follow(counts: $counts, sentence: $sentence);

        return new JobChunk(
            units: 1,
            checkpoint: null,
            finished: true,
            result: json_encode(['counts' => $counts, 'sentence' => $sentence], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
