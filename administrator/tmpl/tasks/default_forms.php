<?php

/**
 * The Work tab's row actions as forms of their own, outside the list's form: Resend, Run again, Retry, Mark resolved
 * and Cancel, each a POST with the form token answered by TasksController. The row's buttons and reason inputs name
 * these forms by id; only the forms a row offers are rendered.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Tasks\HtmlView $this
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Task\TaskPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;

$form = static fn (string $task, int $id): string => '<form id="alfa-task-' . $task . '-' . $id . '" method="post" action="' . Route::_('index.php?option=com_alfa&task=tasks.' . $task) . '">'
    . '<input type="hidden" name="id" value="' . $id . '">' . HTMLHelper::_('form.token') . '</form>';
?>
<?php foreach ((array) $this->items as $item) : ?>
    <?php
    $id = (int) $item->id;
    $isJob = (string) $item->type === Tasks::TYPE_JOB;
    ?>
    <?php if ($isJob && $item->state === Tasks::STATE_FAILED) : ?>
        <?php echo $form('retry', $id), $form('resolve', $id); ?>
    <?php elseif (!$isJob && $item->work === TaskStaff::WORK_ATTENTION) : ?>
        <?php if ($item->action === TaskPolicy::ACTION_RESEND) : ?>
            <?php echo $form('resend', $id); ?>
        <?php elseif ($item->action === TaskPolicy::ACTION_RERUN) : ?>
            <?php echo $form('runAgain', $id); ?>
        <?php endif; ?>
        <?php echo $form('resolve', $id); ?>
    <?php endif; ?>
    <?php if ($item->cancellable) : ?>
        <?php echo $form('cancel', $id); ?>
    <?php endif; ?>
<?php endforeach; ?>
