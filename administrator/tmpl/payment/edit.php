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

// $fieldsets = $this->form->getFieldsets();
// print_r($fieldsets);

//exit;
?>

<form
	action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
	method="post" enctype="multipart/form-data" name="adminForm" id="payment-form" class="form-validate form-horizontal">

	<div class="row title-alias form-vertical mb-3">
        <div class="col-12">
            <?php echo $this->form->renderField('name'); ?>
        </div>
        <!-- <div class="col-12 col-md-6"> -->
            <?php //echo $this->form->renderField('alias'); ?>
        <!-- </div> -->
    </div>


	<?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'general', 'recall' => true, 'breakpoint' => 768]); ?>
	<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'general', Text::_('COM_ALFA_TAB_PAYMENT', true)); ?>
	<div class="row">
		<div class="col-lg-9">
			<fieldset class="adminform">
				<legend><?php echo Text::_('COM_ALFA_FIELDSET_PAYMENT'); ?></legend>
				<?php echo $this->form->renderField('type'); ?>
				<?php echo $this->form->renderField('color'); ?>
				<?php echo $this->form->renderField('bg_color'); ?>
				<?php echo $this->form->renderFieldset('paymentsparams'); ?>
                <?php echo $this->form->renderField('description'); ?>
                <?php echo $this->form->renderField('show_on_product'); ?>
                <?php echo $this->form->renderField('categories'); ?>
                <?php echo $this->form->renderField('manufacturers'); ?>
                <?php echo $this->form->renderField('places'); ?>
                <?php echo $this->form->renderField('usergroups'); ?>
                <?php echo $this->form->renderField('users'); ?>
			</fieldset>
		</div>
		 <div class="col-lg-3">
		 	<?php echo $this->form->renderField('state'); ?>

		 	<?php echo $this->form->renderField('created_by'); ?>
			<?php echo $this->form->renderField('modified_by'); ?>
			<?php echo $this->form->renderField('modified'); ?>
            <?php echo $this->form->renderField('id'); ?>
        

			<div class="control-group">
				<div class="control-label"><?php echo $this->form->getLabel('version_note'); ?></div>
				<div class="controls"><?php echo $this->form->getInput('version_note'); ?></div>
			</div>

        </div>
	</div>
	<?php echo HTMLHelper::_('uitab.endTab'); ?>
	
	<?php echo HTMLHelper::_('uitab.endTabSet'); ?>

	<input type="hidden" name="task" value=""/>
	<?php echo HTMLHelper::_('form.token'); ?>

</form>
