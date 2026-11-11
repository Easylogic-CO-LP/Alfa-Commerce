<?php

/**
 * Tools > Tasks, focused on one job (&job=N): its progress, followed while the page is open by the progress script,
 * whose every call is a 2-second tick on this job (task=tasks.process), paced by the answer (the line under the bar
 * says why the page waits). Closing the page loses nothing: the job waits for the next run. When the job ends, one
 * sentence says how, coloured by it, a thumbnails job's report is printed here, and the jobs card below is drawn again
 * by its own layout (task=tasks.jobsCard; UX-84, UX-88). The script fills this layout's elements and swaps in the card
 * the server drew.
 *
 * Available $displayData:
 *   - job     (array|null) TaskStaff::job()
 *   - missing (bool)       &job named no job
 *   - id      (int)        the job asked for
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  2.2.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$job = $displayData['job'] ?? null;
$e   = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$tab = Route::_('index.php?option=com_alfa&view=tools') . '#tasks';

if (!empty($displayData['missing'])) : ?>
    <div class="alert alert-warning">
        <?php echo Text::sprintf('COM_ALFA_TASKS_JOB_GONE_ID', (int) ($displayData['id'] ?? 0)); ?>
        <a href="<?php echo $tab; ?>"><?php echo Text::_('COM_ALFA_TASKS_PROGRESS_BACK'); ?></a>
    </div>
    <?php return;
endif;

if (!is_array($job)) {
    return;
}

Factory::getApplication()->getDocument()->getWebAssetManager()->useScript('com_alfa.admin.task-progress');
$key = 'COM_ALFA_TASKS_JOB_STATE_' . strtoupper($job['state']);
// How the job ended, said once and coloured by it, the bar solid in the same colour (UX-88); the script shows the same when
// the job ends while the page is open.
$ends = [
    Tasks::STATE_DONE => ['COM_ALFA_TASKS_PROGRESS_END_DONE', 'text-success', 'bg-success'],
    Tasks::STATE_FAILED => ['COM_ALFA_TASKS_PROGRESS_END_FAILED', 'text-danger', 'bg-danger'],
    Tasks::STATE_RESOLVED => ['COM_ALFA_TASKS_PROGRESS_END_RESOLVED', 'text-muted', 'bg-secondary'],
];
$ended = $ends[$job['state']] ?? null;
$report = \is_array($job['report'] ?? null)
    ? Text::sprintf('COM_ALFA_TASKS_THUMBNAILS_REPORT', (int) $job['report']['made'], (int) $job['report']['kept'], (int) $job['report']['missing'], (int) $job['report']['failed'])
    : '';
?>
<div class="card mb-3" id="alfa-job-progress" data-alfa-job-progress data-id="<?php echo (int) $job['id']; ?>" data-token="<?php echo Session::getFormToken(); ?>"
    data-url="<?php echo $e(Route::_('index.php?option=com_alfa&task=tasks.process', false)); ?>"
    data-jobs-url="<?php echo $e(Route::_('index.php?option=com_alfa&task=tasks.jobsCard', false)); ?>">
    <div class="card-header">
        <?php echo Text::sprintf('COM_ALFA_TASKS_PROGRESS_TITLE', (int) $job['id'], $job['label'] !== '' ? Text::_($job['label']) : ''); ?>
    </div>
    <div class="card-body">
        <div class="progress mb-2" style="height: 1.25rem;">
            <div class="progress-bar<?php echo $ended === null ? ' progress-bar-striped progress-bar-animated' : ' ' . $ended[2]; ?>" data-alfa-job-bar role="progressbar"
                style="width: <?php echo (int) $job['percent']; ?>%" aria-valuemin="0" aria-valuemax="100"
                aria-valuenow="<?php echo (int) $job['percent']; ?>" aria-label="<?php echo $e(Text::_('COM_ALFA_TASKS_COL_PROGRESS')); ?>"></div>
        </div>
        <p class="mb-1">
            <?php // The state alone when the total is 0 (UX-04, NEW-6), else the state and how far the job is. ?>
            <?php $units = TaskStaff::unitsText(done: (int) $job['done'], total: (int) $job['total'], percent: (int) $job['percent']); ?>
            <strong data-alfa-job-state><?php echo Text::_($key) === $key ? $e($job['state']) : Text::_($key); ?></strong><span data-alfa-job-units-line<?php echo $units === '' ? ' hidden' : ''; ?>>,
            <span data-alfa-job-units><?php echo $e($units); ?></span></span>
        </p>
        <p class="text-danger mb-1" data-alfa-job-error<?php echo $job['error'] === '' ? ' hidden' : ''; ?>><?php echo $e(Tasks::errorText($job['error'])); ?></p>
        <p class="text-muted mb-1" data-alfa-job-wait hidden></p>
        <?php foreach ($ends as $endState => [$endKey, $endClass]) : ?>
            <p class="<?php echo $endClass; ?> mb-1" data-alfa-job-end="<?php echo $e($endState); ?>"<?php echo $job['state'] === $endState ? '' : ' hidden'; ?>><?php echo Text::_($endKey); ?></p>
        <?php endforeach; ?>
        <p class="mb-1" data-alfa-job-report<?php echo $report === '' ? ' hidden' : ''; ?>><?php echo $e($report); ?></p>
        <p class="small text-muted mb-2"><?php echo Text::_('COM_ALFA_TASKS_PROGRESS_DESC'); ?></p>
        <a class="btn btn-sm btn-secondary" href="<?php echo $tab; ?>"><?php echo Text::_('COM_ALFA_TASKS_PROGRESS_BACK'); ?></a>
    </div>
</div>
