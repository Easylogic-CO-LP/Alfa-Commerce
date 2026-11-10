<?php

/**
 * Tools > Tasks, focused on one job (&job=N): its progress, followed while the page is open by the progress script,
 * whose every call is a 2-second tick on this job (task=tasks.process), paced by the answer (the line under the bar
 * says why the page waits). Closing the page loses nothing: the job waits for the next run. The script fills this
 * layout's elements only.
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
?>
<div class="card mb-3" data-alfa-job-progress data-id="<?php echo (int) $job['id']; ?>" data-token="<?php echo Session::getFormToken(); ?>"
    data-url="<?php echo $e(Route::_('index.php?option=com_alfa&task=tasks.process', false)); ?>">
    <div class="card-header">
        <?php echo Text::sprintf('COM_ALFA_TASKS_PROGRESS_TITLE', (int) $job['id'], $job['label'] !== '' ? Text::_($job['label']) : ''); ?>
    </div>
    <div class="card-body">
        <div class="progress mb-2" style="height: 1.25rem;">
            <div class="progress-bar progress-bar-striped progress-bar-animated" data-alfa-job-bar role="progressbar"
                style="width: <?php echo (int) $job['percent']; ?>%" aria-valuemin="0" aria-valuemax="100"
                aria-valuenow="<?php echo (int) $job['percent']; ?>" aria-label="<?php echo $e(Text::_('COM_ALFA_TASKS_COL_PROGRESS')); ?>"></div>
        </div>
        <p class="mb-1">
            <strong data-alfa-job-state><?php echo Text::_($key) === $key ? $e($job['state']) : Text::_($key); ?></strong>,
            <span data-alfa-job-units><?php echo Text::sprintf('COM_ALFA_TASKS_PROGRESS_UNITS', (int) $job['done'], (int) $job['total'], (int) $job['percent']); ?></span>
        </p>
        <p class="text-danger mb-1" data-alfa-job-error<?php echo $job['error'] === '' ? ' hidden' : ''; ?>><?php echo $e($job['error']); ?></p>
        <p class="text-muted mb-1" data-alfa-job-wait hidden></p>
        <p class="text-success mb-1" data-alfa-job-finished hidden><?php echo Text::_('COM_ALFA_TASKS_PROGRESS_FINISHED'); ?></p>
        <p class="small text-muted mb-2"><?php echo Text::_('COM_ALFA_TASKS_PROGRESS_DESC'); ?></p>
        <a class="btn btn-sm btn-secondary" href="<?php echo $tab; ?>"><?php echo Text::_('COM_ALFA_TASKS_PROGRESS_BACK'); ?></a>
    </div>
</div>
