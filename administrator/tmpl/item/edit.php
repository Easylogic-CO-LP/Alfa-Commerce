<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

// No direct access
defined('_JEXEC') or die;

use \Joomla\CMS\HTML\HTMLHelper;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Uri\Uri;
use \Joomla\CMS\Router\Route;
use \Joomla\CMS\Language\Text;

$wa = $this->document->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
	->useScript('keepalive')
	->useScript('form.validate');


$input = Factory::getApplication()->getInput();

?>

<form
	action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
	method="post" enctype="multipart/form-data" name="adminForm" id="item-form" class="form-validate form-horizontal">

	<div class="row title-alias form-vertical mb-3">
        <div class="col-12 col-md-6">
            <?php echo $this->form->renderField('name'); ?>
        </div>
        <div class="col-12 col-md-6">
            <?php echo $this->form->renderField('alias'); ?>
        </div>
    </div>


	<?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'general', 'recall' => true, 'breakpoint' => 768]); ?>
	<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'general', Text::_('COM_ALFA_TAB_ITEM', true)); ?>
	<div class="row">
		<div class="col-lg-9">
			<fieldset class="adminform">
				<legend><?php echo Text::_('COM_ALFA_FIELDSET_ITEM'); ?></legend>
				<?php echo $this->form->renderField('sku'); ?>
				<?php echo $this->form->renderField('gtin'); ?>
				<?php echo $this->form->renderField('mpn'); ?>
				<?php echo $this->form->renderField('stock'); ?>
				<?php echo $this->form->renderField('stock_action'); ?>
				<?php echo $this->form->renderField('manage_stock'); ?>
				<?php echo $this->form->renderField('short_desc'); ?>
				<?php echo $this->form->renderField('full_desc'); ?>
			</fieldset>
		</div>
		 <div class="col-lg-3">
            <?php echo $this->form->renderField('categories'); ?>
            <?php echo $this->form->renderField('id_category_default'); ?>
            
            <?php echo $this->form->renderField('manufacturers'); ?>
            <?php echo $this->form->renderField('state'); ?>
            <?php if ($this->state->params->get('save_history', 1)) : ?>
					<div class="control-group">
						<div class="control-label"><?php echo $this->form->getLabel('version_note'); ?></div>
						<div class="controls"><?php echo $this->form->getInput('version_note'); ?></div>
					</div>
			<?php endif; ?>
        </div>
	</div>
	<?php echo HTMLHelper::_('uitab.endTab'); ?>

	<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'prices', Text::_('Prices')); ?>
	<div class="row">
		<div class="col-12">
			<fieldset id="fieldset-pricedata" class="options-form">
				<legend><?php echo Text::_('COM_ALFA_FORM_FIELDSET_PRICE'); ?></legend>

				<?php echo $this->form->renderField('prices'); ?>
				
			</fieldset>
		</div>
	
	</div>
	<?php echo HTMLHelper::_('uitab.endTab'); ?>

	<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'publishing', Text::_('COM_ALFA_FIELDSET_PUBLISHING_SEO')); ?>
	<div class="row">
		<div class="col-12 col-lg-6">
			<fieldset id="fieldset-publishingdata" class="options-form">
				<legend><?php echo Text::_('JGLOBAL_FIELDSET_PUBLISHING'); ?></legend>
				<?php echo $this->form->renderField('allowedUsers'); ?>
				<?php echo $this->form->renderField('allowedUserGroups'); ?>
				<?php echo $this->form->renderField('publish_up'); ?>
                <?php echo $this->form->renderField('publish_down'); ?>
				<?php echo $this->form->renderField('created_by'); ?>
				<?php echo $this->form->renderField('modified_by'); ?>
				<?php echo $this->form->renderField('modified'); ?>
                <?php echo $this->form->renderField('id'); ?>
			</fieldset>
		</div>
		<div class="col-12 col-lg-6">
            <fieldset id="fieldset-metadata" class="options-form">
                <legend><?php echo Text::_('JGLOBAL_FIELDSET_METADATA_OPTIONS'); ?></legend>
                <div>
                    <?php echo $this->form->renderField('meta_title'); ?>
                    <?php echo $this->form->renderField('meta_desc'); ?>
                </div>
            </fieldset>
        </div>
	</div>
	 <?php echo HTMLHelper::_('uitab.endTab'); ?>


    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <input type="hidden" name="task" value="">
    <input type="hidden" name="return" value="<?php echo $input->getBase64('return'); ?>">

    <?php echo HTMLHelper::_('form.token'); ?>

</form>
