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
?>

<form
	action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
	method="post" enctype="multipart/form-data" name="adminForm" id="tax-form" class="form-validate form-horizontal">

	
    <?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'general', 'recall' => true, 'breakpoint' => 768]); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'general', Text::_('COM_ALFA_TAB_TAX', true)); ?>
    <div class="row">
        <div class="col-lg-9">
            <fieldset class="adminform">
                <?php echo $this->form->renderField('name'); ?>
                <?php echo $this->form->renderField('value'); ?>
                <?php echo $this->form->renderField('behavior'); ?>
                <?php echo $this->form->renderField('desc'); ?>
                <?php echo $this->form->renderField('categories'); ?>
                <?php echo $this->form->renderField('manufacturers'); ?>
                <?php echo $this->form->renderField('places'); ?>
                <?php echo $this->form->renderField('usergroups'); ?>
                <?php echo $this->form->renderField('users'); ?> 
            </fieldset>
        </div>
        <div class="col-lg-3">
            <fieldset class="adminform">
                <?php echo $this->form->renderField('checked_out'); ?>
                <?php echo $this->form->renderField('checked_out_time'); ?>
                <?php echo $this->form->renderField('created_by'); ?>
                <?php echo $this->form->renderField('modified'); ?>
                <?php echo $this->form->renderField('modified_by'); ?>
                <?php echo $this->form->renderField('ordering'); ?>
                <?php echo $this->form->renderField('state'); ?>
                <?php echo $this->form->renderField('id'); ?>
            </fieldset>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>
	
	<?php echo HTMLHelper::_('uitab.endTabSet'); ?>

	<input type="hidden" name="task" value=""/>
	<?php echo HTMLHelper::_('form.token'); ?>

</form>
