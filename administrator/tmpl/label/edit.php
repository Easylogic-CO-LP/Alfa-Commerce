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
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

$wa = $this->document->getWebAssetManager();
$wa->useScript('keepalive')
    ->useScript('form.validate')
    ->useScript('com_alfa.validate.engine')
    ->useStyle('com_alfa.validate');
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
      method="post" enctype="multipart/form-data" name="adminForm" id="label-form" class="form-validate form-horizontal">

    <div class="row form-vertical mb-3">
        <div class="col-12 col-md-8">
            <?php echo $this->form->renderField('name'); ?>
        </div>
    </div>

    <?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'label', 'recall' => true, 'breakpoint' => 768]); ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'label', Text::_('COM_ALFA_TAB_LABEL', true)); ?>
    <div class="row">
        <div class="col-lg-9">
            <fieldset class="adminform">
                <legend><?php echo Text::_('COM_ALFA_LABEL_FS_LOOK'); ?></legend>
                <?php echo $this->form->renderField('bg'); ?>
                <?php echo $this->form->renderField('color'); ?>
                <?php echo $this->form->renderField('class'); ?>
            </fieldset>

            <fieldset class="adminform">
                <legend><?php echo Text::_('COM_ALFA_LABEL_FS_PLACES'); ?></legend>
                <?php echo $this->form->renderField('list_show'); ?>
                <?php echo $this->form->renderField('list_slot'); ?>
                <?php echo $this->form->renderField('item_show'); ?>
                <?php echo $this->form->renderField('item_slot'); ?>
                <?php echo $this->form->renderField('corner'); ?>
            </fieldset>
        </div>
        <div class="col-lg-3">
            <?php echo $this->form->renderField('state'); ?>
            <?php echo $this->form->renderField('filterable'); ?>
            <?php echo $this->form->renderField('publish_up'); ?>
            <?php echo $this->form->renderField('publish_down'); ?>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'publishing', Text::_('JGLOBAL_FIELDSET_PUBLISHING')); ?>
    <div class="row">
        <div class="col-12">
            <fieldset id="fieldset-publishingdata" class="options-form">
                <legend><?php echo Text::_('JGLOBAL_FIELDSET_PUBLISHING'); ?></legend>
                <div>
                    <?php echo $this->form->renderField('created_by'); ?>
                    <?php echo $this->form->renderField('modified_by'); ?>
                    <?php echo $this->form->renderField('modified'); ?>
                    <?php echo $this->form->renderField('id'); ?>
                </div>
            </fieldset>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php
    // CONTRIBUTED TABS. A plugin that loads a named <fieldset> into this form through onAlfaAdminForm gets it rendered
    // here as its own tab, the rule of every Alfa edit screen. This template renders no named fieldsets of its own.
    foreach ($this->form->getFieldsets() as $fieldset) {
        if ($fieldset->name === '' || $fieldset->name === null) {
            continue;
        }

        $fieldsetLabel = !empty($fieldset->label)
            ? Text::_($fieldset->label)
            : ucwords(str_replace('_', ' ', $fieldset->name));

        echo HTMLHelper::_('uitab.addTab', 'myTab', 'plg-' . $fieldset->name, $fieldsetLabel);
        echo '<div class="row"><div class="col-12"><fieldset class="options-form"><legend>'
            . $fieldsetLabel . '</legend><div>'
            . $this->form->renderFieldset($fieldset->name)
            . '</div></fieldset></div></div>';
        echo HTMLHelper::_('uitab.endTab');
    }
    ?>
    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <?php echo $this->form->renderControlFields(); ?>
    <?php echo LayoutHelper::render('form.return', [], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>
</form>
