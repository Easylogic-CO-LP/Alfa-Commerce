<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task\Job;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Media\ThumbnailRecreation;
use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;

/**
 * Recreate the gallery thumbnails: one media row a unit, by ascending id, through
 * ThumbnailRecreation, in the mode the shop asked for (all, or only the missing and undersized ones).
 *
 * One scope, the handler's name: one job at a time. A second request merges into the active one; "all" asked while it
 * runs makes it walk once more from the start in "all" (rerun on merge), and "missing" asked while one is active is
 * covered by it (ThumbnailRecreation::request()). Heavy: it writes image files. It keeps its own transactions: each
 * row's thumbnail is written, then its row updated in one statement of its own, so the runner's chunk transaction
 * carries only the heavy slot's renewal and the fenced progress, and a chunk walked twice after a kill writes the same
 * files again. The checkpoint holds the walk's mode, the last row walked and the report (made, kept, missing image
 * files, failed), which the job keeps when it finishes: Tools > Tasks reads it.
 *
 * @since  2.2.0
 */
final class ThumbnailsRecreateJob implements JobHandler
{
    /**
     * The handler's name, and its one scope.
     *
     * @since  2.2.0
     */
    public const NAME = 'media.thumbnails.recreate';

    public function policy(): JobPolicy
    {
        // Priority 20: below catalogue.rebuild (30), above search.rebuild (10). The first chunk is one row: with a
        // thumbnail size up to 5000 px a side one image can take a second or more to decode, resize and write, so the
        // runner grows the chunk only from the cost it measures, and chunk() stops at the tick's deadline after the row
        // it is on.
        return new JobPolicy(label: 'COM_ALFA_TASKS_HANDLER_THUMBNAILS_RECREATE', weight: JobPolicy::HEAVY, priority: 20, initialUnits: 1, rerunOnMerge: true, ownTransactions: true);
    }

    public function total(JobRun $run): int
    {
        return ThumbnailRecreation::rowCount();
    }

    public function chunk(JobRun $run, int $units): JobChunk
    {
        // The walk's mode is read once, at its start (a rerun starts again with no checkpoint).
        $checkpoint = $run->checkpoint ?? ['mode' => ThumbnailRecreation::mode(), 'after' => 0, 'report' => ThumbnailRecreation::emptyReport()];
        $mode = $checkpoint['mode'] === ThumbnailRecreation::MODE_ALL ? ThumbnailRecreation::MODE_ALL : ThumbnailRecreation::MODE_MISSING;
        $report = (array) ($checkpoint['report'] ?? ThumbnailRecreation::emptyReport());
        $rows = ThumbnailRecreation::rowsAfter(after: (int) ($checkpoint['after'] ?? 0), limit: $units);
        $after = (int) ($checkpoint['after'] ?? 0);
        $walked = 0;

        foreach ($rows as $row) {
            $report = ThumbnailRecreation::noted(report: $report, id: (int) $row->id, outcome: ThumbnailRecreation::recreate(row: $row, mode: $mode));
            $after = (int) $row->id;
            $walked++;

            // A large image takes long: the rows after it wait for the next chunk rather than run past the tick.
            if ($run->until !== null && microtime(true) >= $run->until) {
                break;
            }
        }

        return new JobChunk(units: $walked, checkpoint: ['mode' => $mode, 'after' => $after, 'report' => $report], finished: \count($rows) < $units && $walked === \count($rows));
    }
}
