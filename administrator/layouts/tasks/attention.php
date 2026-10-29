<?php

/**
 * Tools > Tasks: the failed and held tasks, each with what staff may do with it (Resend, Run again, Mark resolved).
 * Every action is a plain form POST with the form token, answered by TasksController; nothing here needs a script.
 *
 * Available $displayData:
 *   - rows  (array)  TaskStaff::attentionRows()
 *   - total (int)    TaskStaff::attentionTotal()
 *   - users (array)  user id => name, for the history
 *   - unreadable (bool) the tasks could not be read (the table missing or damaged): one line instead of the list
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  2.1.4
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Task\TaskPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$rows  = (array) ($displayData['rows'] ?? []);
$total = (int) ($displayData['total'] ?? 0);
$users = (array) ($displayData['users'] ?? []);
$e     = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// Words for the codes the history records; a code this version does not know is shown as it is.
$word = static function (string $prefix, string $code) use ($e): string {
    $key = $prefix . strtoupper(str_replace(' ', '_', $code));

    return Text::_($key) === $key ? $e($code) : Text::_($key);
};
$date = static fn (string $at): string => $at === '' ? '' : HTMLHelper::_('date', $at, Text::_('DATE_FORMAT_LC6'));
$form = static function (string $task, int $id, string $inner): string {
    return '<form method="post" class="mb-2" action="' . Route::_('index.php?option=com_alfa&task=tasks.' . $task) . '">'
        . $inner . '<input type="hidden" name="id" value="' . $id . '">' . HTMLHelper::_('form.token') . '</form>';
};
?>
<div class="card mb-3">
    <div class="card-header"><?php echo Text::_('COM_ALFA_TASKS_CARD_TITLE'); ?></div>
    <div class="card-body">
        <p class="text-muted mb-3"><?php echo Text::_('COM_ALFA_TASKS_CARD_DESC'); ?></p>

        <?php if (!empty($displayData['unreadable'])) : ?>
            <div class="alert alert-warning mb-0"><span class="icon-warning" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_TASKS_UNREADABLE'); ?></div>
        <?php elseif ($rows === []) : ?>
            <p class="mb-0"><?php echo Text::_('COM_ALFA_TASKS_NONE'); ?></p>
        <?php else : ?>
            <?php if ($total > count($rows)) : ?>
                <p><?php echo Text::sprintf('COM_ALFA_TASKS_SHOWN', count($rows), $total); ?></p>
            <?php endif; ?>
            <table class="table table-sm align-top">
                <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('COM_ALFA_TASKS_COL_TASK'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_TASKS_COL_SUBJECT'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_TASKS_COL_STATE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_TASKS_COL_DETAILS'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_TASKS_COL_ACTIONS'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php
                    $id = (int) $row['id'];
                    $units = ['done' => [], 'failed' => [], 'unknown' => []];

                    foreach ((array) $row['units'] as $unit => $outcome) {
                        if (isset($units[$outcome])) {
                            $units[$outcome][] = $e($unit);
                        }
                    }
                    ?>
                    <tr>
                        <td>
                            #<?php echo $id; ?>
                            <?php echo $row['label'] !== ''
                                ? Text::_($row['label'])
                                : Text::sprintf('COM_ALFA_TASKS_HANDLER_UNKNOWN', $e($row['handler'])); ?>
                        </td>
                        <td>
                            <?php if ($row['subject_type'] === 'order' && (int) $row['subject_id'] > 0) : ?>
                                <a href="<?php echo Route::_('index.php?option=com_alfa&task=order.edit&id=' . (int) $row['subject_id']); ?>">
                                    <?php echo Text::sprintf('COM_ALFA_TASKS_SUBJECT_ORDER', (int) $row['subject_id']); ?></a>
                            <?php elseif ($row['subject_type'] !== '') : ?>
                                <?php echo $e($row['subject_type'] . ' #' . (int) $row['subject_id']); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $row['state'] === Tasks::STATE_HELD ? 'bg-warning text-dark' : 'bg-danger'; ?>">
                                <?php echo Text::_($row['state'] === Tasks::STATE_HELD ? 'COM_ALFA_TASKS_STATE_HELD' : 'COM_ALFA_TASKS_STATE_FAILED'); ?></span>
                            <div class="small text-muted mt-1"><?php echo Text::sprintf('COM_ALFA_TASKS_STOPPED', $date((string) $row['finished_at'])); ?></div>
                        </td>
                        <td>
                            <?php if ((string) $row['last_error'] !== '') : ?>
                                <p class="mb-1"><?php echo $e($row['last_error']); ?></p>
                            <?php endif; ?>
                            <?php foreach (['done' => 'COM_ALFA_TASKS_UNITS_DONE', 'failed' => 'COM_ALFA_TASKS_UNITS_FAILED', 'unknown' => 'COM_ALFA_TASKS_UNITS_UNKNOWN'] as $outcome => $key) : ?>
                                <?php if ($units[$outcome] !== []) : ?>
                                    <p class="small mb-1"><?php echo Text::sprintf($key, implode(', ', $units[$outcome])); ?></p>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <details>
                                <summary class="small"><?php echo Text::sprintf('COM_ALFA_TASKS_HISTORY', count($row['history'])); ?></summary>
                                <ol class="small mb-0">
                                    <?php foreach ($row['history'] as $entry) : ?>
                                        <li>
                                            <?php echo $date((string) ($entry['at'] ?? '')); ?>,
                                            <?php echo $word('COM_ALFA_TASKS_TRIGGER_', (string) ($entry['trigger'] ?? ''))
                                                . ((int) ($entry['user'] ?? 0) > 0 ? ' ' . Text::sprintf('COM_ALFA_TASKS_BY_USER', $e($users[(int) $entry['user']] ?? ('#' . (int) $entry['user']))) : '')
                                                . ':'; ?>
                                            <?php echo $word('COM_ALFA_TASKS_OUTCOME_', (string) ($entry['outcome'] ?? '')); ?>
                                            <?php if (isset($entry['unit'])) : ?>
                                                (<?php echo $e($entry['unit']); ?>)
                                            <?php endif; ?>
                                            <?php if (isset($entry['error'])) : ?>
                                                <span class="text-muted"><?php echo $e($entry['error']); ?></span>
                                            <?php endif; ?>
                                            <?php if (isset($entry['carried_from'])) : ?>
                                                <span class="text-muted"><?php echo Text::sprintf('COM_ALFA_TASKS_CARRIED', (int) $entry['carried_from']); ?></span>
                                            <?php endif; ?>
                                            <?php if (isset($entry['reason'])) : ?>
                                                <span class="text-muted"><?php echo $e($entry['reason']); ?></span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            </details>
                        </td>
                        <td>
                            <?php if ($row['action'] === TaskPolicy::ACTION_RESEND) : ?>
                                <?php echo $form('resend', $id, '<button type="submit" class="btn btn-sm btn-primary" title="'
                                    . str_replace('"', '&quot;', Text::_('COM_ALFA_TASKS_RESEND_DESC')) . '">' . Text::_('COM_ALFA_TASKS_RESEND') . '</button>'); ?>
                            <?php elseif ($row['action'] === TaskPolicy::ACTION_RERUN) : ?>
                                <?php echo $form('runAgain', $id, '<button type="submit" class="btn btn-sm btn-primary" title="'
                                    . str_replace('"', '&quot;', Text::_('COM_ALFA_TASKS_RUN_AGAIN_DESC')) . '">' . Text::_('COM_ALFA_TASKS_RUN_AGAIN_BTN') . '</button>'); ?>
                            <?php endif; ?>
                            <?php echo $form('resolve', $id, '<label class="form-label small" for="alfa-task-reason-' . $id . '">'
                                . Text::_('COM_ALFA_TASKS_REASON_LABEL') . '</label><input type="text" class="form-control form-control-sm mb-1"'
                                . ' id="alfa-task-reason-' . $id . '" name="reason" required maxlength="' . TaskStaff::REASON_MAX . '">'
                                . '<button type="submit" class="btn btn-sm btn-secondary">' . Text::_('COM_ALFA_TASKS_RESOLVE') . '</button>'); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
