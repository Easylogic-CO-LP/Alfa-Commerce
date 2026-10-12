<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access
defined('_JEXEC') or die;

use \Joomla\CMS\HTML\HTMLHelper;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Uri\Uri;
use \Joomla\CMS\Router\Route;
use \Joomla\CMS\Language\Text;

$wa = $this->document->getWebAssetManager();
$wa->useScript('keepalive')
	->useScript('form.validate')
	->useScript('com_alfa.validate.engine')
	->useStyle('com_alfa.validate');

?>

<form
	action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
	method="post" enctype="multipart/form-data" name="adminForm" id="discount-form" class="form-validate form-horizontal">


    <div class="row title-alias form-vertical mb-3">
        <div class="col-12 col-md-6">
            <?php echo $this->form->renderField('name'); ?>
        </div>
        <div class="col-12 col-md-6">
            <?php echo $this->form->renderField('name_customer'); ?>
        </div>
    </div>

    <?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', array('active' => 'discount')); ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'discount', Text::_('COM_ALFA_TAB_DISCOUNT', true)); ?>

    <div class="row">
        <div class="col-lg-9">
            <fieldset class="adminform">
                <?php echo $this->form->renderField('value'); ?>
                <?php echo $this->form->renderField('is_amount'); ?>

                <?php echo $this->form->renderField('apply_before_tax'); ?>
                <?php echo $this->form->renderField('behavior'); ?>

                <?php echo $this->form->renderField('publish_up'); ?>
                <?php echo $this->form->renderField('publish_down'); ?>
                <?php echo $this->form->renderField('categories'); ?>
                <?php echo $this->form->renderField('manufacturers'); ?>
                <?php echo $this->form->renderField('places'); ?>
                <?php echo $this->form->renderField('usergroups'); ?>
                <?php echo $this->form->renderField('users'); ?>
            </fieldset>
        </div>
        <div class="col-lg-3">
            <fieldset class="adminform">
                <?php echo $this->form->renderField('state'); ?>
                <?php echo $this->form->renderField('id'); ?>
                <?php echo $this->form->renderField('created_by'); ?>
                <?php echo $this->form->renderField('modified'); ?>
                <?php echo $this->form->renderField('modified_by'); ?>
            </fieldset>
        </div>
    </div>

	<?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'advanced', Text::_('COM_ALFA_TAB_DISCOUNT_ADVANCED', true)); ?>

    <div class="row">
        <div class="col-lg-9">
            <fieldset class="adminform">
                <legend><?php echo Text::_('COM_ALFA_DISCOUNT_TAG_FS_CARD'); ?></legend>
                <?php echo $this->form->renderField('tag_list_show'); ?>
                <?php echo $this->form->renderField('tag_list_slot'); ?>
            </fieldset>

            <fieldset class="adminform">
                <legend><?php echo Text::_('COM_ALFA_DISCOUNT_TAG_FS_PAGE'); ?></legend>
                <?php echo $this->form->renderField('tag_item_show'); ?>
                <?php echo $this->form->renderField('tag_item_slot'); ?>
            </fieldset>

            <fieldset class="adminform">
                <legend><?php echo Text::_('COM_ALFA_DISCOUNT_TAG_FS_APPEARANCE'); ?></legend>
                <?php echo $this->form->renderField('tag_bg'); ?>
                <?php echo $this->form->renderField('tag_color'); ?>
                <?php echo $this->form->renderField('tag_class'); ?>
            </fieldset>
        </div>
    </div>

	<?php echo HTMLHelper::_('uitab.endTab'); ?><?php
// CONTRIBUTED TABS. A plugin that loads a named <fieldset> into this form through
// onAlfaAdminForm gets it rendered here as its own tab, same rule as every Alfa edit
// screen: a contributed named fieldset is a new tab. This template renders no named
// fieldsets of its own (the form XML's single fieldset is unnamed), so $ownFieldsets
// stays empty.
$ownFieldsets = [];
foreach ($this->form->getFieldsets() as $fieldset) {
    if (in_array($fieldset->name, $ownFieldsets, true)) {
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

</form>
