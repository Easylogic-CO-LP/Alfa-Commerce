<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/** @var \Joomla\CMS\WebAsset\WebAssetManager $wa */
$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('com_alfa.sync-tools');

// Generic completion label for actions whose server reply carries no message (backfill).
Text::script('COM_ALFA_TOOLS_BACKFILL_DONE');

// CSRF token carried in the AJAX URLs (validated server-side via Session::checkToken).
$token    = Session::getFormToken();
$ajaxBase = 'index.php?option=com_alfa&' . $token . '=1';

/**
 * Inline progress block markup, shared by every card. The JS resolves the bar
 * relative to the clicked button (closest .card-body), so the markup is identical.
 */
$progress = '<div class="alfa-progress mt-3" style="display:none;">'
    . '<div class="progress" style="height:1.5rem;">'
    . '<div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"'
    . ' style="width:0%;" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">0%</div>'
    . '</div>'
    . '<p class="small text-muted mt-2 mb-0" data-status></p>'
    . '</div>';
?>
<div class="com-alfa-tools p-3">

    <div class="card mb-3">
        <div class="card-header"><?php echo Text::_('COM_ALFA_TOOLS_LANG_SCHEMA_TITLE'); ?></div>
        <div class="card-body">
            <p class="text-muted mb-3"><?php echo Text::_('COM_ALFA_TOOLS_LANG_SCHEMA_DESC'); ?></p>
            <button type="button" class="btn btn-primary alfa-tool-btn" data-action="single"
                    data-url="<?php echo $this->escape($ajaxBase . '&task=sync.languages'); ?>">
                <span class="icon-refresh" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_TOOLBAR_RESYNC_LANGUAGES'); ?>
            </button>
            <?php echo $progress; ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><?php echo Text::_('COM_ALFA_TOOLS_USERS_TITLE'); ?></div>
        <div class="card-body">
            <p class="text-muted mb-3"><?php echo Text::_('COM_ALFA_TOOLS_USERS_DESC'); ?></p>
            <button type="button" class="btn btn-secondary alfa-tool-btn" data-action="single"
                    data-url="<?php echo $this->escape($ajaxBase . '&task=sync.users'); ?>">
                <span class="icon-users" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_TOOLS_RESYNC_USERS'); ?>
            </button>
            <?php echo $progress; ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><?php echo Text::_('COM_ALFA_TOOLS_BACKFILL_TITLE'); ?></div>
        <div class="card-body">
            <p class="text-muted mb-3"><?php echo Text::_('COM_ALFA_TOOLS_BACKFILL_DESC'); ?></p>
            <button type="button" class="btn btn-warning alfa-tool-btn" data-action="backfill"
                    data-plan-url="<?php echo $this->escape($ajaxBase . '&task=sync.backfillPlan'); ?>"
                    data-chunk-url="<?php echo $this->escape($ajaxBase . '&task=sync.backfillChunk'); ?>">
                <span class="icon-language" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_TOOLS_BACKFILL_BTN'); ?>
            </button>
            <?php echo $progress; ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><?php echo Text::_('COM_ALFA_TOOLS_MEDIA_TITLE'); ?></div>
        <div class="card-body">
            <p class="text-muted mb-3"><?php echo Text::_('COM_ALFA_TOOLS_MEDIA_DESC'); ?></p>
            <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_alfa&view=toolsmedia'); ?>">
                <span class="icon-images" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_TOOLS_MEDIA_BTN'); ?>
            </a>
        </div>
    </div>

    <?php echo $this->loadTemplate('devguide'); ?>

</div>
