<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright   (C) 2015 Open Source Matters, Inc. <https://www.joomla.org>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \Joomla\Component\Content\Administrator\View\Articles\HtmlView $this */

// $params = ComponentHelper::getParams('com_content');

// $published = (int) $this->state->get('filter.published');

$user = $this->getCurrentUser();
?>

<div class="p-3">
    <div class="row">
        
        <div class="form-group col-md-6">
            <?php echo LayoutHelper::render('batch.categories'); ?>
        </div>

        <div class="form-group col-md-6">
            <?php echo LayoutHelper::render('batch.manufacturers'); ?>
        </div>
       
    </div>

    <div class="row">
        
        <div class="form-group col-md-6">
          <?php echo LayoutHelper::render('batch.user'); ?>
        </div>

       <div class="form-group col-md-6">
            <?php echo LayoutHelper::render('batch.usergroups'); ?>
        </div>
    </div>

    
</div>
<div class="btn-toolbar p-3">
    <joomla-toolbar-button task="item.batch" class="ms-auto">
        <button type="button" class="btn btn-success"><?php echo Text::_('JGLOBAL_BATCH_PROCESS'); ?></button>
    </joomla-toolbar-button>
</div>
