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
	->useScript('form.validate')
    ->useStyle('com_alfa.admin');

HTMLHelper::_('bootstrap.tooltip');
?>

<form
	action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->order->id); ?>"
	method="post" enctype="multipart/form-data" name="adminForm" id="order-form" class="form-validate form-horizontal">

	<?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', array('active' => 'order')); ?>
	<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'order', Text::_('COM_ALFA_TAB_ORDER', true)); ?>

    <!-- Order Information Section -->
    <div class="row">
        <!-- Left Column (User Info) -->
        <div class="col-md-3">
            <fieldset class="adminform">

                <legend><?php echo Text::_('COM_ALFA_FIELDSET_USER_DETAILS'); ?></legend>
                <div class="form-group">
                    <?php echo $this->form->getLabel('user_name'); ?>
                    <?php echo $this->form->getInput('user_name'); ?>
                </div>
                <div class="control-group">
	                <?php echo $this->form->getLabel('user_email'); ?>
		        	<?php echo $this->form->getInput('user_email'); ?>
		        </div>

                <div class="form-group">
                    <label><?php echo Text::_('COM_ALFA_USER_PHONE'); ?></label>
                    <input type="text" class="form-control" name="user_phone" value="+123456789" readonly>
                </div>
            </fieldset>
        </div>

        <!-- Right Column (Delivery Info) -->
        <div class="col-md-9">
            <fieldset class="adminform">
                <legend><?php echo Text::_('COM_ALFA_FIELDSET_DELIVERY_DETAILS'); ?></legend>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <?php echo $this->form->getLabel('shipping_address'); ?>
                            <?php echo $this->form->getInput('shipping_address'); ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label><?php echo Text::_('COM_ALFA_DELIVERY_METHOD'); ?></label>
                            <select class="form-control" name="delivery_method">
                                <option value="1">Standard Shipping</option>
                                <option value="2">Express Shipping</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                            <?php echo $this->form->getLabel('created'); ?>
                            <?php echo $this->form->getInput('created'); ?>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <?php echo $this->form->getLabel('id_order_status'); ?>
                            <?php echo $this->form->getInput('id_order_status'); ?>
                        </div>
                    </div>
                </div>
            </fieldset>
        </div>
    </div>


    <!-- Items Section (Full width) -->
    <div class="row mt-4">
        <div class="col-12">
            <?php echo $this->form->getLabel('items'); ?>
            <?php echo $this->form->getInput('items'); ?>
        </div>
    </div>

    <?php echo HTMLHelper::_('uitab.endTab'); ?>

	<?php echo HTMLHelper::_('uitab.endTabSet'); ?>

	<!--	<input type="hidden" name="jform[id]" value="--><?php //echo $this->item->id; ?><!--" />-->
	<!--	<input type="hidden" name="jform[state]" value="--><?php //echo $this->item->state; ?><!--" />-->
	<!--	<input type="hidden" name="jform[ordering]" value="--><?php //echo $this->item->ordering; ?><!--" />-->
	<!--	<input type="hidden" name="jform[checked_out]" value="--><?php //echo $this->item->checked_out; ?><!--" />-->
	<!--	<input type="hidden" name="jform[checked_out_time]" value="--><?php //echo $this->item->checked_out_time; ?><!--" />-->
	<!--	--><?php //echo $this->form->renderField('created_by'); ?>
	<!--	--><?php //echo $this->form->renderField('modified_by'); ?>

	<input type="hidden" name="task" value=""/>
	<?php echo HTMLHelper::_('form.token'); ?>

</form>
