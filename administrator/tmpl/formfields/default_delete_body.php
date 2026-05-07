<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Permanent-delete confirmation dialog body.
 * Triggered by $toolbar->popupButton('delete-confirm', ...) in
 * View/Formfields/HtmlView.php; rendered via loadTemplate('delete_body').
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

?>
<div class="p-4">
    <div class="d-flex align-items-start gap-3">
        <div class="text-danger" style="font-size: 2.5rem; line-height: 1;">
            <span class="icon-warning" aria-hidden="true"></span>
        </div>
        <div>
            <h4 class="text-danger mb-2"><?php echo Text::_('COM_ALFA_FORMFIELD_DELETE_PERMANENT_HEADER'); ?></h4>
            <p class="mb-0"><?php echo Text::_('COM_ALFA_FORMFIELD_DELETE_PERMANENT_CONFIRM'); ?></p>
        </div>
    </div>
</div>

<div class="btn-toolbar p-3 border-top">
    <button type="button" class="btn btn-outline-secondary" data-button-cancel>
        <?php echo Text::_('JCANCEL'); ?>
    </button>

    <joomla-toolbar-button task="formfields.delete" class="ms-auto">
        <button type="button" class="btn btn-danger">
            <span class="icon-trash" aria-hidden="true"></span>
            <?php echo Text::_('COM_ALFA_FORMFIELD_DELETE_PERMANENT'); ?>
        </button>
    </joomla-toolbar-button>
</div>
