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
$wa->useScript('keepalive')
	->useScript('form.validate');
HTMLHelper::_('bootstrap.tooltip');

$input = Factory::getApplication()->getInput();

?>

<form
	action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
	method="post" enctype="multipart/form-data" name="adminForm" id="coupon-form" class="form-validate form-horizontal">

	
	<?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', array('active' => 'coupon')); ?>
	<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'coupon', Text::_('COM_ALFA_TAB_COUPON', true)); ?>
	<div class="row-fluid">
		<div class="col-md-12 form-horizontal">
			<fieldset class="adminform">
				<legend><?php echo Text::_('COM_ALFA_FIELDSET_COUPON'); ?></legend>
				<?php echo $this->form->renderField('coupon_code'); ?>
				<?php echo $this->form->renderField('num_of_uses'); ?>
				<?php echo $this->form->renderField('value_type'); ?>
				<?php echo $this->form->renderField('value'); ?>
				<?php echo $this->form->renderField('min_value'); ?>
				<?php echo $this->form->renderField('max_value'); ?>
				<?php echo $this->form->renderField('hidden'); ?>

                <?php echo $this->form->renderField('publish_up'); ?>

                <?php echo $this->form->renderField('publish_down'); ?>

                <?php echo $this->form->renderField('user_associated'); ?>

                <?php echo $this->form->renderField('associate_to_new_users'); ?>

                <?php echo $this->form->renderField('allowedUsers'); ?>

                <?php echo $this->form->renderField('allowedUserGroups'); ?>

                <?php echo $this->form->renderField('created_by'); ?>

                <?php echo $this->form->renderField('modified'); ?>

                <?php echo $this->form->renderField('modified_by'); ?>

                <?php echo $this->form->renderField('id'); ?>
            </fieldset>
		</div>
	</div>
	<?php echo HTMLHelper::_('uitab.endTab'); ?>
	<input type="hidden" name="jform[id]" value="<?php echo $this->item->id; ?>" />
	<input type="hidden" name="jform[state]" value="<?php echo $this->item->state; ?>" />
	<input type="hidden" name="jform[ordering]" value="<?php echo $this->item->ordering; ?>" />
	<input type="hidden" name="jform[checked_out]" value="<?php echo $this->item->checked_out; ?>" />
	<input type="hidden" name="jform[checked_out_time]" value="<?php echo $this->item->checked_out_time; ?>" />

    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <input type="hidden" name="task" value="">
    <input type="hidden" name="return" value="<?php echo $input->getBase64('return'); ?>">

	<?php echo HTMLHelper::_('form.token'); ?>

</form>