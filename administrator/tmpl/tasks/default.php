<?php

/**
 * The Background screen: one list of the shop's background work. The toolbar's tools show their progress and outcome
 * under the toolbar.
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
    ->useScript('table.columns');

// The Tools script's own sentences.
Text::script('COM_ALFA_TOOLS_BACKFILL_DONE');
Text::script('COM_ALFA_SYNC_ERROR_PREFIX');
Text::script('COM_ALFA_TOOLS_JOB_OPENING');
Text::script('COM_ALFA_TOOLS_REQUEST_FAILED');
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=tasks'); ?>" method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php // Where the toolbar's tools show their progress and outcome: the Tools script fills it. ?>
                <div class="tool-progress mb-3" id="<?php echo HtmlView::PROGRESS_ID; ?>" style="display:none;">
                    <div class="progress" style="height:1.5rem;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                             style="width:0%;" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">0%</div>
                    </div>
                    <p class="small text-muted mt-2 mb-0" data-status></p>
                </div>

                <p class="text-muted"><?php echo Text::_('COM_ALFA_TASKS_DESC'); ?></p>
                <?php echo $this->loadTemplate('work'); ?>

                <input type="hidden" name="task" value=""/>
                <input type="hidden" name="boxchecked" value="0"/>
                <?php echo HTMLHelper::_('form.token'); ?>
            </div>
        </div>
    </div>
</form>

<?php // Each row's own actions are forms of their own, outside the list's form; the row's buttons and inputs name them. ?>
<?php echo $this->loadTemplate('forms'); ?>
