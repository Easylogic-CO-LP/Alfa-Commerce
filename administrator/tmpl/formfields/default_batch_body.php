<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

?>
<div class="p-3">
    <div class="row">
        <div class="form-group col-md-12">
            <?php echo LayoutHelper::render('batch.field_groups'); ?>
        </div>
    </div>
</div>
<div class="btn-toolbar p-3">
    <joomla-toolbar-button task="formfield.batch" class="ms-auto">
        <button type="button" class="btn btn-success"><?php echo Text::_('JGLOBAL_BATCH_PROCESS'); ?></button>
    </joomla-toolbar-button>
</div>
