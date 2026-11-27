<?php

/**
 * The Background screen: two tabs, Work and Outgoing notifications, one listed at a time (the form's tab field names
 * it; choosing the other tab submits the form). The toolbar's tools show their progress and outcome under the toolbar.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Tasks\HtmlView $this
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\View\Tasks\HtmlView;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
    ->useScript('com_alfa.admin')
    ->useScript('com_alfa.sync-tools')
    ->useScript('com_alfa.admin.tasks')
    ->useScript('table.columns');

// The Tools script's own sentences.
Text::script('COM_ALFA_TOOLS_BACKFILL_DONE');
Text::script('COM_ALFA_SYNC_ERROR_PREFIX');
Text::script('COM_ALFA_TOOLS_JOB_OPENING');
Text::script('COM_ALFA_TOOLS_REQUEST_FAILED');

$onWork = $this->tab === HtmlView::TAB_WORK;
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=tasks'); ?>" method="post" name="adminForm" id="adminForm">
    <input type="hidden" name="tab" value="<?php echo $this->escape($this->tab); ?>">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php // Where the toolbar's tools show their progress and outcome: the Tools script fills it. ?>
                <div class="alfa-progress mb-3" id="<?php echo HtmlView::PROGRESS_ID; ?>" style="display:none;">
                    <div class="progress" style="height:1.5rem;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                             style="width:0%;" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">0%</div>
                    </div>
                    <p class="small text-muted mt-2 mb-0" data-status></p>
                </div>

                <?php echo HTMLHelper::_('uitab.startTabSet', 'alfaBackground', ['active' => 'background-' . $this->tab]); ?>

                <?php echo HTMLHelper::_('uitab.addTab', 'alfaBackground', 'background-' . HtmlView::TAB_WORK, Text::_('COM_ALFA_TASKS_TAB_WORK')); ?>
                    <p class="text-muted"><?php echo Text::_('COM_ALFA_TASKS_TAB_WORK_DESC'); ?></p>
                    <?php if ($onWork) : ?>
                        <?php echo $this->loadTemplate('work'); ?>
                    <?php endif; ?>
                <?php echo HTMLHelper::_('uitab.endTab'); ?>

                <?php echo HTMLHelper::_('uitab.addTab', 'alfaBackground', 'background-' . HtmlView::TAB_OUTBOUND, Text::_('COM_ALFA_OUTBOUND_CARD_TITLE')); ?>
                    <p class="text-muted"><?php echo Text::_('COM_ALFA_TASKS_TAB_OUTBOUND_DESC'); ?></p>
                    <?php if (!$onWork) : ?>
                        <?php echo $this->loadTemplate('outbound'); ?>
                    <?php endif; ?>
                <?php echo HTMLHelper::_('uitab.endTab'); ?>

                <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

                <input type="hidden" name="task" value=""/>
                <input type="hidden" name="boxchecked" value="0"/>
                <?php echo HTMLHelper::_('form.token'); ?>
            </div>
        </div>
    </div>
</form>

<?php // Each row's own actions are forms of their own, outside the list's form; the row's buttons and inputs name them. ?>
<?php if ($onWork) : ?>
    <?php echo $this->loadTemplate('forms'); ?>
<?php endif; ?>
