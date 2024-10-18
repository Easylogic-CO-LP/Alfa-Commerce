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
                    <label><?php echo Text::_('COM_ALFA_USER_NAME'); ?></label>
                    <input type="text" class="form-control" name="user_name" value="<?php echo $this->item->user_name ?>" readonly>
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
                            <label><?php echo Text::_('COM_ALFA_DELIVERY_ADDRESS'); ?></label>
                            <input type="text" class="form-control" name="delivery_address" value="123 Elm Street">
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
                        <div class="form-group">
                            <label><?php echo Text::_('COM_ALFA_DELIVERY_DATE'); ?></label>
                            <input type="text" class="form-control" name="delivery_date" value="2024-10-15">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label><?php echo Text::_('COM_ALFA_ORDER_STATUS'); ?></label>
                            <select class="form-control" name="order_status">
                                <option value="1">Pending</option>
                                <option value="2" selected>Completed</option>
                                <option value="3">Cancelled</option>
                            </select>
                        </div>
                    </div>
                </div>
            </fieldset>
        </div>
    </div>

    <!-- Items Section (Full width) -->
    <div class="row mt-4">
        <div class="col-12">
            <fieldset class="adminform">
                <legend><?php echo Text::_('COM_ALFA_FIELDSET_ORDER_ITEMS'); ?></legend>
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th><?php echo Text::_('COM_ALFA_ITEM_NAME'); ?></th>
                            <th><?php echo Text::_('COM_ALFA_ITEM_QUANTITY'); ?></th>
                            <th><?php echo Text::_('COM_ALFA_ITEM_PRICE'); ?></th>
                            <th><?php echo Text::_('COM_ALFA_ITEM_TOTAL'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Placeholder data for items -->
                        <?php foreach ($this->item->items as $item) : ?>
                        <tr>
                            <td><?php echo $item->name?></td>
                            <td><?php echo $item->quantity?></td>
                            <td><?php echo $item->price?></td>
                            <td>€100.00</td>
                        </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td colspan="3" class="text-right"><strong><?php echo Text::_('COM_ALFA_TOTAL'); ?>:</strong></td>
                            <td><strong><?php echo number_format($this->item->original_price, 2,',','.'); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </fieldset>
        </div>
    </div>

    <?php echo HTMLHelper::_('uitab.endTab'); ?>

	<?php echo HTMLHelper::_('uitab.endTabSet'); ?>


	<?php //echo $this->form->renderField('original_price'); ?>

	<!-- <div class="control-group"> -->
	        <?php //echo $this->form->getLabel('original_price'); ?>
	        <?php //echo $this->form->getInput('original_price'); ?>
	<!-- </div> -->

	<!-- <div class="row-fluid">
		<div class="col-md-12 form-horizontal">
			<fieldset class="adminform">
				<legend>< ?php echo Text::_('COM_ALFA_FIELDSET_ORDER'); ?></legend>
                < ?php echo $this->form->renderField('price');?>
                < ?php echo $this->item->original_price;?>
                < ?php echo $this->item->user_name; ?>

			</fieldset>
		</div>
	</div> -->

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
