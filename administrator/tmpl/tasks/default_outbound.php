<?php

/**
 * The Outgoing notifications tab of the Background screen: the card of who is told of the shop's changes, with what
 * each consumer is owed and the staff actions on it, then the filters and the list of every change owed or delivered,
 * in its state of work.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Tasks\HtmlView $this
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundDelivery;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn = $this->escape($this->state->get('list.direction'));
$date = fn (?string $at): string => (string) $at === '' ? '' : '<span class="nowrap">' . HTMLHelper::_('date', (string) $at, Text::_('DATE_FORMAT_LC6')) . '</span>';
$said = static fn (string $result): string => str_starts_with($result, 'COM_ALFA_') ? Text::_($result) : $result;
$badge = [
    TaskStaff::WORK_RUNNING => 'text-bg-info',
    TaskStaff::WORK_WAITING => 'bg-secondary',
    TaskStaff::WORK_ATTENTION => 'bg-danger',
    TaskStaff::WORK_FINISHED => 'bg-success',
];
// A finished row's badge says how it ended.
$ended = [
    OutboundDelivery::STATE_SENT => ['COM_ALFA_OUTBOUND_STATE_SENT', 'bg-success'],
    OutboundDelivery::STATE_DISCARDED => ['COM_ALFA_OUTBOUND_STATE_DISCARDED', 'bg-secondary'],
    OutboundDelivery::STATE_NO_CONSUMER => ['COM_ALFA_OUTBOUND_STATE_NO_CONSUMER', 'bg-secondary'],
];
?>
<?php echo LayoutHelper::render('tasks.outbound', $this->consumers, JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>

<?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

<?php if (empty($this->items)) : ?>
    <div class="alert alert-info">
        <span class="icon-info-circle" aria-hidden="true"></span>
        <?php echo Text::_('COM_ALFA_OUTBOUND_LIST_NONE'); ?>
    </div>
<?php else : ?>
<div class="table-responsive">
<table class="table table-striped align-top fold" id="outboundList">
    <thead>
    <tr>
        <th class="w-1 text-center fold-keep"><?php echo HTMLHelper::_('grid.checkall'); ?></th>
        <th scope="col"><?php echo Text::_('COM_ALFA_OUTBOUND_COL_CONSUMER'); ?></th>
        <th scope="col" class="d-none d-md-table-cell"><?php echo Text::_('COM_ALFA_TASKS_COL_SUBJECT'); ?></th>
        <th scope="col"><?php echo Text::_('COM_ALFA_TASKS_COL_STATE'); ?></th>
        <th scope="col" class="d-none d-lg-table-cell"><?php echo Text::_('COM_ALFA_TASKS_COL_DETAILS'); ?></th>
        <th scope="col" class="d-none d-lg-table-cell">
            <?php echo HTMLHelper::_('searchtools.sort', 'COM_ALFA_TASKS_COL_CREATED', 'd.created', $listDirn, $listOrder); ?>
        </th>
        <th scope="col" class="w-3 d-none d-lg-table-cell">
            <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'd.id', $listDirn, $listOrder); ?>
        </th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($this->items as $i => $item) : ?>
        <?php $id = (int) $item->id; ?>
        <tr class="row<?php echo $i % 2; ?>">
            <td class="text-center">
                <?php // Only a finished entry can be deleted, so only it is checked. ?>
                <?php if ($item->work === TaskStaff::WORK_FINISHED) : ?>
                    <?php echo HTMLHelper::_('grid.id', $i, $id, false, 'cid', 'cb', $item->name); ?>
                <?php endif; ?>
            </td>
            <td>
                <strong><?php echo $this->escape($item->name); ?></strong>
                <?php if ((int) $item->consumer_state !== 1) : ?>
                    <span class="badge bg-secondary"><?php echo Text::_('COM_ALFA_OUTBOUND_PAUSED'); ?></span>
                <?php endif; ?>
                <div class="small text-muted">#<?php echo $id; ?>, <?php echo $this->escape($item->consumerText); ?></div>
                <?php if ((string) $item->last_error !== '') : ?>
                    <div class="small text-danger mt-1 d-lg-none"><?php echo $this->escape($said((string) $item->last_error)); ?></div>
                <?php endif; ?>
            </td>
            <td class="d-none d-md-table-cell">
                <span class="badge bg-light text-dark"><?php echo $this->escape($item->topicText); ?></span>
                <?php echo $this->escape($item->aboutText); ?>
                <?php if ((string) $item->payload !== '') : ?>
                    <div class="small text-muted"><?php echo $this->escape($item->payload); ?></div>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($item->work === TaskStaff::WORK_FINISHED) : ?>
                    <span class="badge <?php echo $ended[$item->state][1] ?? 'bg-secondary'; ?>"><?php echo Text::_($ended[$item->state][0] ?? 'COM_ALFA_TASKS_WORK_FINISHED'); ?></span>
                <?php elseif ($item->work === TaskStaff::WORK_ATTENTION) : ?>
                    <span class="badge bg-danger"><?php echo Text::_('COM_ALFA_OUTBOUND_STATE_DEAD'); ?></span>
                <?php else : ?>
                    <span class="badge <?php echo $badge[$item->work]; ?>"><?php echo Text::_('COM_ALFA_TASKS_WORK_' . strtoupper($item->work)); ?></span>
                <?php endif; ?>
                <?php if ($item->work === TaskStaff::WORK_WAITING && (int) $item->attempts > 0) : ?>
                    <div class="small text-muted mt-1"><?php echo Text::sprintf('COM_ALFA_TASKS_JOB_RETRY_AT', (int) $item->attempts, $date((string) $item->next_attempt_at)); ?></div>
                <?php endif; ?>
                <?php if ((string) $item->sent_at !== '') : ?>
                    <div class="small text-muted mt-1"><?php echo Text::sprintf('COM_ALFA_OUTBOUND_TAKEN_ON', $date((string) $item->sent_at)); ?></div>
                <?php elseif ((string) $item->dead_at !== '') : ?>
                    <div class="small text-muted mt-1"><?php echo Text::sprintf('COM_ALFA_TASKS_STOPPED', $date((string) $item->dead_at)); ?></div>
                <?php endif; ?>
            </td>
            <td class="d-none d-lg-table-cell small">
                <?php if ((string) $item->last_error !== '') : ?>
                    <p class="text-danger mb-1"><?php echo $this->escape($said((string) $item->last_error)); ?></p>
                <?php endif; ?>
                <?php if ($item->work === TaskStaff::WORK_ATTENTION) : ?>
                    <p class="mb-0"><?php echo Text::plural('COM_ALFA_OUTBOUND_ATTEMPTS', (int) $item->attempts); ?></p>
                <?php endif; ?>
            </td>
            <td class="d-none d-lg-table-cell small"><?php echo $date((string) $item->created); ?></td>
            <td class="d-none d-lg-table-cell"><?php echo $id; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php echo $this->pagination->getListFooter(); ?>
<?php endif; ?>
