<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Confirmation dialog body for deleting ALL missing-file media rows across all
 * pages. Triggered by $toolbar->popupButton('delete-missing', ...) in
 * View/Toolsmedia/HtmlView.php; rendered via loadTemplate('delete_missing_body').
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

?>
<div class="p-4">
    <div class="d-flex align-items-start gap-3">
        <div class="text-warning" style="font-size: 2.5rem; line-height: 1;">
            <span class="icon-warning" aria-hidden="true"></span>
        </div>
        <div>
            <h4 class="mb-2"><?php echo Text::_('COM_ALFA_TOOLSMEDIA_DELETE_MISSING_HEADER'); ?></h4>
            <p class="mb-0"><?php echo Text::_('COM_ALFA_TOOLSMEDIA_DELETE_MISSING_CONFIRM'); ?></p>
        </div>
    </div>
</div>

<div class="btn-toolbar p-3 border-top">
    <form method="dialog" class="m-0">
        <button type="submit" class="btn btn-outline-secondary"><?php echo Text::_('JCANCEL'); ?></button>
    </form>

    <joomla-toolbar-button task="toolsmedia.deleteAllMissing" class="ms-auto">
        <button type="button" class="btn btn-danger">
            <span class="icon-trash" aria-hidden="true"></span>
            <?php echo Text::_('COM_ALFA_TOOLSMEDIA_DELETE_ALL_MISSING'); ?>
        </button>
    </joomla-toolbar-button>
</div>
