<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The dialog of Remove extension and its data on the selected extensions, opened by its toolbar
 * button (View/Extensions/HtmlView.php); its confirm button submits the adminForm with the task
 * extensions.removeData.
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\ClearData\ClearDataExtensions;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

?>
<div class="p-4">
    <div class="d-flex align-items-start gap-3">
        <div class="text-danger" style="font-size: 2.5rem; line-height: 1;">
            <span class="icon-trash" aria-hidden="true"></span>
        </div>
        <div>
            <h4 class="text-danger mb-2"><?php echo Text::_('COM_ALFA_EXTENSIONS_REMOVE_WITH_DATA_HEADER'); ?></h4>
            <p><?php echo Text::_('COM_ALFA_EXTENSIONS_REMOVE_WITH_DATA_CONFIRM'); ?></p>
            <?php echo LayoutHelper::render('actions.typedconfirm', ['phrase' => ClearDataExtensions::PHRASE, 'id' => 'alfa-extensions-removedata-phrase', 'form' => 'adminForm'], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>
        </div>
    </div>
</div>

<div class="btn-toolbar p-3 border-top">
    <form method="dialog" class="m-0">
        <button type="submit" class="btn btn-outline-secondary"><?php echo Text::_('JCANCEL'); ?></button>
    </form>

    <joomla-toolbar-button task="extensions.removeData" form="adminForm" class="ms-auto">
        <button type="button" class="btn btn-danger">
            <span class="icon-trash" aria-hidden="true"></span>
            <?php echo Text::_('COM_ALFA_EXTENSIONS_REMOVE_WITH_DATA'); ?>
        </button>
    </joomla-toolbar-button>
</div>
