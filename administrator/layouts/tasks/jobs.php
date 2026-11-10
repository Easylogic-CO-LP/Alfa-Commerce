<?php

/**
 * Tools > Tasks: the shop's background jobs, running and waiting first, then failed, then the finished ones: what each
 * covers, how far it is, and what staff may do. A waiting job opens its progress page (Process now); a failed one is
 * put back in the queue (Retry) or closed with a reason (Mark resolved). A job is never cancelled. Every action is a
 * plain form POST with the form token, answered by TasksController.
 *
 * Available $displayData:
 *   - rows   (array)  TaskStaff::jobRows()
 *   - groups (array)  customer group id => title, for the price sets' jobs
 *   - unreadable (bool) the jobs could not be read: one line instead of the list
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  2.2.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Task\Job\PriceSetPrepareJob;
use Alfa\Component\Alfa\Administrator\Service\Media\ThumbnailRecreation;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\SearchRebuildJob;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\ThumbnailsRecreateJob;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskDocument;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$rows   = (array) ($displayData['rows'] ?? []);
$groups = (array) ($displayData['groups'] ?? []);
$e      = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$date   = static fn (?string $at): string => (string) $at === '' ? '' : HTMLHelper::_('date', (string) $at, Text::_('DATE_FORMAT_LC6'));
$state  = static function (string $code) use ($e): string {
    $key = 'COM_ALFA_TASKS_JOB_STATE_' . strtoupper($code);

    return Text::_($key) === $key ? $e($code) : Text::_($key);
};
$badge  = [
    Tasks::STATE_RUNNING => 'bg-info text-dark',
    Tasks::STATE_PENDING => 'bg-secondary',
    Tasks::STATE_FAILED  => 'bg-danger',
    Tasks::STATE_DONE    => 'bg-success',
];
$form   = static function (string $task, int $id, string $inner): string {
    return '<form method="post" class="mb-2" action="' . Route::_('index.php?option=com_alfa&task=tasks.' . $task) . '">'
        . $inner . '<input type="hidden" name="id" value="' . $id . '">' . HTMLHelper::_('form.token') . '</form>';
};
$priceSet = PriceSetPrepareJob::NAME . ':';
$search   = SearchRebuildJob::NAME . ':';
?>
<div class="card mb-3">
    <div class="card-header"><?php echo Text::_('COM_ALFA_TASKS_JOBS_TITLE'); ?></div>
    <div class="card-body">
        <p class="text-muted mb-3"><?php echo Text::_('COM_ALFA_TASKS_JOBS_DESC'); ?></p>
        <?php if (!empty($displayData['unreadable'])) : ?>
            <div class="alert alert-warning mb-0"><span class="icon-warning" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_TASKS_UNREADABLE'); ?></div>
        <?php elseif ($rows === []) : ?>
            <p class="mb-0"><?php echo Text::_('COM_ALFA_TASKS_JOBS_NONE'); ?></p>
        <?php else : ?>
            <table class="table table-sm align-top">
                <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('COM_ALFA_TASKS_COL_JOB'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_TASKS_COL_COVERS'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_TASKS_COL_OWNER'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_TASKS_COL_STATE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_TASKS_COL_PROGRESS'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_TASKS_COL_TIMES'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_TASKS_COL_ACTIONS'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php
                    $id = (int) $row['id'];
                    $scope = (string) $row['scope_key'];
                    $percent = TaskDocument::percent(row: $row);

                    if (str_starts_with($scope, $priceSet)) {
                        $covers = $row['groups'] === []
                            ? Text::_('COM_ALFA_TOOLS_PRICE_SETS_NO_GROUP')
                            : Text::sprintf('COM_ALFA_TASKS_COVERS_GROUPS', $e(implode(' + ', array_map(static fn (int $group): string => (string) ($groups[$group] ?? '#' . $group), $row['groups']))));
                    } elseif (str_starts_with($scope, $search)) {
                        $covers = Text::sprintf('COM_ALFA_TASKS_COVERS_SEARCH', $e(substr($scope, strlen($search))));
                    } elseif ($scope === ThumbnailsRecreateJob::NAME) {
                        // The thumbnails job (2.2.0): its mode (a job still waiting has no report yet: the mode its walk
                        // will read), and what its walk did so far, the image files missing, the thumbnails not made
                        // with their reason and the earlier thumbnails kept on disk, named by their media row.
                        $report = $row['report'] ?? null;
                        $mode = $report['mode'] ?? ThumbnailRecreation::mode();
                        $covers = Text::_($mode === ThumbnailRecreation::MODE_ALL ? 'COM_ALFA_TASKS_COVERS_THUMBNAILS_ALL' : 'COM_ALFA_TASKS_COVERS_THUMBNAILS_MISSING');
                        if ($report !== null) {
                            $covers .= '<div class="small mt-1">' . Text::sprintf('COM_ALFA_TASKS_THUMBNAILS_REPORT', (int) $report['made'], (int) $report['kept'], (int) $report['missing'], (int) $report['failed']) . '</div>';
                            $named = static fn (array $ids, array $reasons = []): string => $e(implode(', ', array_map(
                                static fn ($id): string => '#' . (int) $id . (isset($reasons[(string) $id]) ? ' (' . Text::_('COM_ALFA_TASKS_THUMBNAILS_REASON_' . strtoupper((string) $reasons[(string) $id])) . ')' : ''),
                                $ids,
                            )));
                            if ($report['missingIds'] !== []) {
                                $covers .= '<div class="small text-muted">' . Text::sprintf('COM_ALFA_TASKS_THUMBNAILS_MISSING_ROWS', $named($report['missingIds'])) . '</div>';
                            }
                            if ($report['failedIds'] !== []) {
                                $covers .= '<div class="small text-muted">' . Text::sprintf('COM_ALFA_TASKS_THUMBNAILS_FAILED_ROWS', $named($report['failedIds'], (array) $report['failedReasons'])) . '</div>';
                            }
                            if ((int) $report['previous'] > 0) {
                                $covers .= '<div class="small text-muted">' . Text::sprintf('COM_ALFA_TASKS_THUMBNAILS_PREVIOUS_ROWS', (int) $report['previous'], $named($report['previousIds'])) . '</div>';
                            }
                        }
                    } else {
                        $covers = Text::_('COM_ALFA_TASKS_COVERS_SHOP');
                    }
                    ?>
                    <tr>
                        <td>
                            #<?php echo $id; ?>
                            <?php echo $row['label'] !== ''
                                ? Text::_($row['label'])
                                : Text::sprintf('COM_ALFA_TASKS_HANDLER_UNKNOWN', $e($row['handler'])); ?>
                        </td>
                        <td><?php echo $covers; ?></td>
                        <td><?php echo Text::_('COM_ALFA'); ?></td>
                        <td>
                            <span class="badge <?php echo $badge[$row['state']] ?? 'bg-light text-dark'; ?>"><?php echo $state((string) $row['state']); ?></span>
                            <?php if ($row['state'] === Tasks::STATE_PENDING && (int) $row['failures'] > 0) : ?>
                                <div class="small text-muted mt-1"><?php echo Text::sprintf('COM_ALFA_TASKS_JOB_RETRY_AT', (int) $row['failures'], $date((string) $row['run_after'])); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo Text::sprintf('COM_ALFA_TASKS_PROGRESS_UNITS', (int) $row['done_units'], (int) $row['total_units'], $percent); ?>
                            <div class="progress mt-1" style="height: .5rem;" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $percent; ?>"
                                aria-label="<?php echo $e(Text::_('COM_ALFA_TASKS_COL_PROGRESS')); ?>">
                                <div class="progress-bar" style="width: <?php echo $percent; ?>%"></div>
                            </div>
                        </td>
                        <td class="small">
                            <?php if ((string) $row['started_at'] !== '') : ?>
                                <div><?php echo Text::sprintf('COM_ALFA_TASKS_JOB_STARTED', $date((string) $row['started_at'])); ?></div>
                            <?php endif; ?>
                            <?php if ((string) $row['finished_at'] !== '') : ?>
                                <div><?php echo Text::sprintf('COM_ALFA_TASKS_JOB_FINISHED', $date((string) $row['finished_at'])); ?></div>
                            <?php endif; ?>
                            <?php if ((string) $row['last_error'] !== '') : ?>
                                <div class="text-danger mt-1"><?php echo $e($row['last_error']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (in_array($row['state'], [Tasks::STATE_PENDING, Tasks::STATE_RUNNING], true)) : ?>
                                <a class="btn btn-sm btn-primary" href="<?php echo Route::_('index.php?option=com_alfa&view=tools&job=' . $id) . '#tasks'; ?>"
                                    title="<?php echo $e(Text::_('COM_ALFA_TASKS_PROCESS_NOW_DESC')); ?>"><?php echo Text::_('COM_ALFA_TASKS_PROCESS_NOW'); ?></a>
                            <?php elseif ($row['state'] === Tasks::STATE_FAILED) : ?>
                                <?php echo $form('retry', $id, '<button type="submit" class="btn btn-sm btn-primary" title="'
                                    . $e(Text::_('COM_ALFA_TASKS_RETRY_DESC')) . '">' . Text::_('COM_ALFA_TASKS_RETRY') . '</button>'); ?>
                                <?php echo $form('resolve', $id, '<label class="form-label small" for="alfa-job-reason-' . $id . '">'
                                    . Text::_('COM_ALFA_TASKS_REASON_LABEL') . '</label><input type="text" class="form-control form-control-sm mb-1"'
                                    . ' id="alfa-job-reason-' . $id . '" name="reason" required maxlength="' . TaskStaff::REASON_MAX . '">'
                                    . '<button type="submit" class="btn btn-sm btn-secondary">' . Text::_('COM_ALFA_TASKS_RESOLVE') . '</button>'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
