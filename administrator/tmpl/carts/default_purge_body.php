<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Confirmation dialog body for purging the expired carts.
 * Triggered by $toolbar->popupButton('purge-confirm', ...) in
 * View/Carts/HtmlView.php; rendered via loadTemplate('purge_body').
 *
 * The button NAMES THE LIST FORM. Left unnamed, Joomla.submitbutton() posts
 * the first `form.form-validate` on the page (here the "new cart" modal),
 * and the list's token and control fields never take part.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$windows = (array) ($this->retentionWindows ?? []);
?>
<div class="p-4">
    <div class="d-flex align-items-start gap-3">
        <div class="text-danger" style="font-size: 2.5rem; line-height: 1;">
            <span class="icon-trash" aria-hidden="true"></span>
        </div>
        <div>
            <h4 class="text-danger mb-2"><?php echo Text::_('COM_ALFA_CARTS_PURGE_HEADER'); ?></h4>
            <p class="mb-0"><?php echo Text::sprintf(
                'COM_ALFA_CARTS_PURGE_CONFIRM',
                (int) ($windows['empty'] ?? 0),
                (int) ($windows['lines'] ?? 0),
            ); ?></p>
        </div>
    </div>
</div>

<div class="btn-toolbar p-3 border-top">
    <form method="dialog" class="m-0">
        <button type="submit" class="btn btn-outline-secondary"><?php echo Text::_('JCANCEL'); ?></button>
    </form>

    <joomla-toolbar-button task="carts.purge" form="adminForm" class="ms-auto">
        <button type="button" class="btn btn-danger">
            <span class="icon-trash" aria-hidden="true"></span>
            <?php echo Text::_('COM_ALFA_CARTS_PURGE_BUTTON'); ?>
        </button>
    </joomla-toolbar-button>
</div>
