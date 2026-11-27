<?php

/**
 * The Work tab of the Background screen: the job the page follows, the tools' notices, the filters and the list of
 * every task and job with its state of work and what staff may do with it. A row's actions are buttons and inputs
 * that name their own form (default_forms.php), outside the list's form.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Tasks\HtmlView $this
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\OutboundDeliveryJob;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn = $this->escape($this->state->get('list.direction'));
$users = $this->historyUsers;
// A date on one line at every width; on a phone the row folds, so the note around it wraps instead.
$date = fn (?string $at): string => (string) $at === '' ? '' : '<span class="nowrap">' . HTMLHelper::_('date', (string) $at, Text::_('DATE_FORMAT_LC6')) . '</span>';
// Words for the codes a history records; a code this version does not know is shown as it is.
$word = fn (string $prefix, string $code): string => Text::_($prefix . strtoupper(str_replace(' ', '_', $code))) === $prefix . strtoupper(str_replace(' ', '_', $code))
    ? $this->escape($code) : Text::_($prefix . strtoupper(str_replace(' ', '_', $code)));
$badge = [
    TaskStaff::WORK_RUNNING => 'text-bg-info',
    TaskStaff::WORK_WAITING => 'bg-secondary',
    TaskStaff::WORK_ATTENTION => 'bg-danger',
    TaskStaff::WORK_FINISHED => 'bg-success',
];
// A finished row's badge says how it ended.
$ended = [
    Tasks::STATE_DONE => ['COM_ALFA_TASKS_JOB_STATE_DONE', 'bg-success'],
    Tasks::STATE_RESOLVED => ['COM_ALFA_TASKS_JOB_STATE_RESOLVED', 'bg-secondary'],
    Tasks::STATE_CANCELLED => ['COM_ALFA_TASKS_JOB_STATE_CANCELLED', 'bg-secondary'],
];
$reasonInput = fn (string $form, int $id): string => '<label class="form-label small mb-0" for="' . $form . '-reason">' . Text::_('COM_ALFA_TASKS_REASON_LABEL') . '</label>'
    . '<input type="text" class="form-control form-control-sm mb-1" id="' . $form . '-reason" name="reason" form="' . $form . '" required size="8" maxlength="' . TaskStaff::REASON_MAX . '">';
?>
<?php if ($this->job !== null || $this->jobMissing) : ?>
    <?php echo LayoutHelper::render('tasks.progress', ['job' => $this->job, 'missing' => $this->jobMissing, 'id' => $this->jobId], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>
<?php endif; ?>

<?php // What the tools say needs doing: each registered action's notice, as its Tools card said it. ?>
<?php foreach ($this->actions as $action) : ?>
    <?php if ((string) ($action->metadata['notice'] ?? '') !== '') : ?>
        <div class="alert alert-warning">
            <span class="icon-warning" aria-hidden="true"></span>
            <?php echo Text::_((string) $action->metadata['notice']); ?>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

<?php if (empty($this->items)) : ?>
    <div class="alert alert-info">
        <span class="icon-info-circle" aria-hidden="true"></span>
        <?php echo Text::_('COM_ALFA_TASKS_LIST_NONE'); ?>
    </div>
<?php else : ?>
<div class="table-responsive">
<table class="table table-striped align-top fold" id="tasksList">
    <thead>
    <tr>
        <th class="w-1 text-center fold-keep"><?php echo HTMLHelper::_('grid.checkall'); ?></th>
        <th scope="col"><?php echo Text::_('COM_ALFA_TASKS_COL_KIND'); ?></th>
        <th scope="col" class="d-none d-md-table-cell"><?php echo Text::_('COM_ALFA_TASKS_COL_SUBJECT'); ?></th>
        <th scope="col"><?php echo Text::_('COM_ALFA_TASKS_COL_STATE'); ?></th>
        <th scope="col" class="d-none d-lg-table-cell"><?php echo Text::_('COM_ALFA_TASKS_COL_DETAILS'); ?></th>
        <th scope="col" class="d-none d-lg-table-cell">
            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_TASKS_COL_CREATED', 'a.created_at', $listDirn, $listOrder); ?>
        </th>
        <th scope="col"><?php echo Text::_('COM_ALFA_TASKS_COL_ACTIONS'); ?></th>
        <th scope="col" class="w-3 d-none d-lg-table-cell">
            <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
        </th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($this->items as $i => $item) : ?>
        <?php
        $id = (int) $item->id;
        $isJob = (string) $item->type === Tasks::TYPE_JOB;
        $active = \in_array($item->state, [Tasks::STATE_PENDING, Tasks::STATE_RUNNING], true);
        $units = ['done' => [], 'failed' => [], 'unknown' => []];

        foreach ((array) $item->units as $unit => $outcome) {
            if (isset($units[$outcome])) {
                $units[$outcome][] = $this->escape($unit);
            }
        }
        ?>
        <tr class="row<?php echo $i % 2; ?>">
            <td class="text-center">
                <?php // Only a finished entry can be deleted, so only it is checked. ?>
                <?php if ($item->work === TaskStaff::WORK_FINISHED) : ?>
                    <?php echo HTMLHelper::_('grid.id', $i, $id, false, 'cid', 'cb', $item->labelText); ?>
                <?php endif; ?>
            </td>
            <td>
                <strong><?php echo $this->escape($item->labelText); ?></strong>
                <div class="small text-muted">
                    #<?php echo $id; ?>
                    <?php echo Text::_($isJob ? 'COM_ALFA_TASKS_TYPE_JOB' : 'COM_ALFA_TASKS_TYPE_SINGLE'); ?>
                    <?php if ((string) $item->extension !== '') : ?>
                        , <?php echo $this->escape($item->extension); ?>
                    <?php endif; ?>
                </div>
                <?php if ((string) $item->waits !== '') : ?>
                    <div class="small text-warning-emphasis mt-1"><?php echo $this->escape($item->waits); ?></div>
                <?php endif; ?>
                <?php if ((int) $item->cancel_requested === 1 && $item->state === Tasks::STATE_RUNNING) : ?>
                    <div class="small text-muted mt-1"><?php echo Text::_('COM_ALFA_TASKS_CANCEL_ASKED'); ?></div>
                <?php endif; ?>
                <?php if ((string) $item->last_error !== '') : ?>
                    <div class="small text-danger mt-1 d-lg-none"><?php echo $this->escape(Tasks::errorText((string) $item->last_error)); ?></div>
                <?php endif; ?>
            </td>
            <td class="d-none d-md-table-cell">
                <?php if ($isJob) : ?>
                    <?php echo $this->escape($item->covers); ?>
                <?php elseif ($item->subject_type === 'order' && (int) $item->subject_id > 0) : ?>
                    <a href="<?php echo Route::_('index.php?option=com_alfa&task=order.edit&id=' . (int) $item->subject_id); ?>">
                        <?php echo Text::sprintf('COM_ALFA_TASKS_SUBJECT_ORDER', (int) $item->subject_id); ?></a>
                <?php elseif ((string) $item->subject_type !== '') : ?>
                    <?php echo $this->escape($item->subject_type . ' #' . (int) $item->subject_id); ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($item->work === TaskStaff::WORK_FINISHED) : ?>
                    <span class="badge <?php echo $ended[$item->state][1] ?? 'bg-secondary'; ?>"><?php echo Text::_($ended[$item->state][0] ?? 'COM_ALFA_TASKS_WORK_FINISHED'); ?></span>
                <?php elseif ($item->work === TaskStaff::WORK_ATTENTION) : ?>
                    <span class="badge <?php echo $item->state === Tasks::STATE_HELD ? 'bg-warning text-dark' : 'bg-danger'; ?>">
                        <?php echo Text::_($item->state === Tasks::STATE_HELD ? 'COM_ALFA_TASKS_STATE_HELD' : 'COM_ALFA_TASKS_STATE_FAILED'); ?></span>
                <?php else : ?>
                    <span class="badge <?php echo $badge[$item->work]; ?>"><?php echo Text::_('COM_ALFA_TASKS_WORK_' . strtoupper($item->work)); ?></span>
                <?php endif; ?>
                <?php if ($isJob && (string) $item->progressText !== '') : ?>
                    <div class="small mt-1"><?php echo $this->escape($item->progressText); ?></div>
                    <?php if ((int) $item->total_units > 0) : ?>
                        <div class="progress mt-1" style="height: .5rem;" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $item->percent; ?>"
                            aria-label="<?php echo $this->escape(Text::sprintf('COM_ALFA_TASKS_PROGRESS_OF_JOB', $id, $item->labelText)); ?>">
                            <div class="progress-bar" style="width: <?php echo (int) $item->percent; ?>%"></div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($item->state === Tasks::STATE_PENDING && (int) $item->failures > 0) : ?>
                    <div class="small text-muted mt-1"><?php echo Text::sprintf('COM_ALFA_TASKS_JOB_RETRY_AT', (int) $item->failures, $date((string) $item->run_after)); ?></div>
                <?php endif; ?>
                <?php if ((string) $item->started_at !== '' && $item->work !== TaskStaff::WORK_WAITING) : ?>
                    <div class="small text-muted mt-1"><?php echo Text::sprintf('COM_ALFA_TASKS_JOB_STARTED', $date((string) $item->started_at)); ?></div>
                <?php endif; ?>
                <?php if ((string) $item->finished_at !== '') : ?>
                    <div class="small text-muted"><?php echo Text::sprintf($item->work === TaskStaff::WORK_ATTENTION ? 'COM_ALFA_TASKS_STOPPED' : 'COM_ALFA_TASKS_JOB_FINISHED', $date((string) $item->finished_at)); ?></div>
                <?php endif; ?>
            </td>
            <td class="d-none d-lg-table-cell small">
                <?php if ((string) $item->last_error !== '') : ?>
                    <p class="text-danger mb-1"><?php echo $this->escape(Tasks::errorText((string) $item->last_error)); ?></p>
                <?php endif; ?>
                <?php foreach ($item->reportLines as $line) : ?>
                    <p class="mb-1"><?php echo $this->escape($line); ?></p>
                <?php endforeach; ?>
                <?php foreach ($item->resultLines as $line) : ?>
                    <p class="mb-1"><?php echo $this->escape($line); ?></p>
                <?php endforeach; ?>
                <?php foreach (['done' => 'COM_ALFA_TASKS_UNITS_DONE', 'failed' => 'COM_ALFA_TASKS_UNITS_FAILED', 'unknown' => 'COM_ALFA_TASKS_UNITS_UNKNOWN'] as $outcome => $key) : ?>
                    <?php if ($units[$outcome] !== []) : ?>
                        <p class="mb-1"><?php echo Text::sprintf($key, implode(', ', $units[$outcome])); ?></p>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($item->history !== []) : ?>
                    <details>
                        <summary><?php echo Text::sprintf('COM_ALFA_TASKS_HISTORY', \count($item->history)); ?></summary>
                        <ol class="mb-0">
                            <?php foreach ($item->history as $entry) : ?>
                                <li>
                                    <?php echo $date((string) ($entry['at'] ?? '')); ?>,
                                    <?php echo $word('COM_ALFA_TASKS_TRIGGER_', (string) ($entry['trigger'] ?? ''))
                                        . ((int) ($entry['user'] ?? 0) > 0 ? ' ' . Text::sprintf('COM_ALFA_TASKS_BY_USER', $this->escape($users[(int) $entry['user']] ?? ('#' . (int) $entry['user']))) : '')
                                        . ':'; ?>
                                    <?php echo $word('COM_ALFA_TASKS_OUTCOME_', (string) ($entry['outcome'] ?? '')); ?>
                                    <?php if (isset($entry['unit'])) : ?>
                                        (<?php echo $this->escape($entry['unit']); ?>)
                                    <?php endif; ?>
                                    <?php if (isset($entry['error'])) : ?>
                                        <span class="text-muted"><?php echo $this->escape(Tasks::errorText((string) $entry['error'])); ?></span>
                                    <?php endif; ?>
                                    <?php if (isset($entry['carried_from'])) : ?>
                                        <span class="text-muted"><?php echo Text::sprintf('COM_ALFA_TASKS_CARRIED', (int) $entry['carried_from']); ?></span>
                                    <?php endif; ?>
                                    <?php if (isset($entry['reason'])) : ?>
                                        <span class="text-muted"><?php echo $this->escape($entry['reason']); ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </details>
                <?php endif; ?>
            </td>
            <td class="d-none d-lg-table-cell small"><?php echo $date((string) $item->created_at); ?></td>
            <td class="fold-line">
                <?php if ($isJob && $active && $id === $this->jobId) : ?>
                    <?php // This page is the job's progress page: a link to the card above, which works it while the page is open. ?>
                    <a class="small" href="#job-progress"><?php echo Text::_('COM_ALFA_TASKS_PROGRESS_SEE_ABOVE'); ?></a>
                <?php elseif ($isJob && $active && $item->driven) : ?>
                    <?php // Something drives it already: its progress page follows it. ?>
                    <a class="btn btn-sm btn-secondary" href="<?php echo Route::_('index.php?option=com_alfa&view=tasks&job=' . $id); ?>"><?php echo Text::_('COM_ALFA_TASKS_FOLLOW'); ?></a>
                <?php elseif ($isJob && $active) : ?>
                    <a class="btn btn-sm btn-primary" href="<?php echo Route::_('index.php?option=com_alfa&view=tasks&job=' . $id); ?>"
                        title="<?php echo $this->escape(Text::_('COM_ALFA_TASKS_PROCESS_NOW_DESC')); ?>"><?php echo Text::_('COM_ALFA_TASKS_PROCESS_NOW'); ?></a>
                <?php elseif ($isJob && $item->state === Tasks::STATE_FAILED) : ?>
                    <button type="submit" form="task-retry-<?php echo $id; ?>" class="btn btn-sm btn-primary mb-2" title="<?php echo $this->escape(Text::_('COM_ALFA_TASKS_RETRY_DESC')); ?>"><?php echo Text::_('COM_ALFA_TASKS_RETRY'); ?></button>
                    <div class="mb-2">
                        <?php echo $reasonInput('task-resolve-' . $id, $id); ?>
                        <button type="submit" form="task-resolve-<?php echo $id; ?>" class="btn btn-sm btn-secondary"><?php echo Text::_('COM_ALFA_TASKS_RESOLVE'); ?></button>
                    </div>
                <?php elseif (!$isJob && $item->work === TaskStaff::WORK_ATTENTION) : ?>
                    <?php if ($item->action === TaskPolicy::ACTION_RESEND) : ?>
                        <button type="submit" form="task-resend-<?php echo $id; ?>" class="btn btn-sm btn-primary mb-2" title="<?php echo $this->escape(Text::_('COM_ALFA_TASKS_RESEND_DESC')); ?>"><?php echo Text::_('COM_ALFA_TASKS_RESEND'); ?></button>
                    <?php elseif ($item->action === TaskPolicy::ACTION_RERUN) : ?>
                        <button type="submit" form="task-runAgain-<?php echo $id; ?>" class="btn btn-sm btn-primary mb-2" title="<?php echo $this->escape(Text::_('COM_ALFA_TASKS_RUN_AGAIN_DESC')); ?>"><?php echo Text::_('COM_ALFA_TASKS_RUN_AGAIN_BTN'); ?></button>
                    <?php endif; ?>
                    <div class="mb-2">
                        <?php echo $reasonInput('task-resolve-' . $id, $id); ?>
                        <button type="submit" form="task-resolve-<?php echo $id; ?>" class="btn btn-sm btn-secondary"><?php echo Text::_('COM_ALFA_TASKS_RESOLVE'); ?></button>
                    </div>
                <?php endif; ?>
                <?php if ($isJob && str_starts_with((string) $item->scope_key, OutboundDeliveryJob::NAME . ':')) : ?>
                    <?php // The deliveries this job sends, on their own screen, filtered to its consumer; its way back is this page. ?>
                    <a class="btn btn-sm btn-outline-secondary mb-2" href="<?php echo Route::_('index.php?option=com_alfa&view=outbound&filter[kind]=' . (int) substr((string) $item->scope_key, \strlen(OutboundDeliveryJob::NAME) + 1) . '&return=' . rawurlencode(AlfaHelper::returnPage())); ?>"><?php echo Text::_('COM_ALFA_OUTBOUND_CARD_TITLE'); ?></a>
                <?php endif; ?>
                <?php if ($item->cancellable) : ?>
                    <div class="mb-2">
                        <?php echo $reasonInput('task-cancel-' . $id, $id); ?>
                        <button type="submit" form="task-cancel-<?php echo $id; ?>" class="btn btn-sm btn-outline-danger" title="<?php echo $this->escape(Text::_('COM_ALFA_TASKS_CANCEL_DESC')); ?>"><?php echo Text::_('COM_ALFA_TASKS_CANCEL'); ?></button>
                    </div>
                <?php endif; ?>
            </td>
            <td class="d-none d-lg-table-cell"><?php echo $id; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php echo $this->pagination->getListFooter(); ?>
<?php endif; ?>
