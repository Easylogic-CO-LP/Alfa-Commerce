<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('keepalive')
    ->useScript('form.validate')
    ->useScript('com_alfa.validate.engine')
    ->useStyle('com_alfa.validate');

$item = $this->item;
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $item->id); ?>"
      method="post" enctype="multipart/form-data" name="adminForm" id="location-form"
      class="form-validate form-horizontal">

    <?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'location', 'recall' => true, 'breakpoint' => 768]); ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'location', Text::_('COM_ALFA_TAB_LOCATION', true)); ?>

    <div class="row">
        <div class="col-lg-6">
            <?php echo $this->form->renderField('title'); ?>
            <?php echo $this->form->renderField('name'); ?>
            <?php echo $this->form->renderField('is_default'); ?>
            <?php echo $this->form->renderField('ordering'); ?>
            <?php echo $this->form->renderField('state'); ?>
        </div>
        <div class="col-lg-6">
            <?php echo $this->form->renderField('location_type'); ?>
            <?php echo $this->form->renderField('capabilities'); ?>
            <?php echo $this->form->renderField('kind'); ?>

            <div class="alert alert-info mt-3">
                <span class="icon-info-circle" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_LOCATION_QUANTITIES_HINT'); ?>
            </div>
        </div>
    </div>

    <?php echo HTMLHelper::_('uitab.endTab'); ?>
    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <input type="hidden" name="task" value=""/>
    <?php echo $this->form->getInput('id'); ?>
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
