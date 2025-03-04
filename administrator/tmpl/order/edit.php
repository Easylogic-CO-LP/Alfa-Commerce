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
        method="post" enctype="multipart/form-data" name="adminForm" id="order-form"
        class="form-validate form-horizontal">

	<?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', array('active' => 'order')); ?>
	<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'order', Text::_('COM_ALFA_TAB_ORDER', true)); ?>

    <!-- Order Information Section -->
    <div class="row">
        <!-- Left Column (User Info) -->
        <div class="col-md-6">
            <fieldset class="adminform">
                <h5 class="mb-0"><?php echo Text::_('COM_ALFA_FIELDSET_USER_DETAILS'); ?></h5>

                <?php echo $this->form->renderFieldset('user_details'); ?>

            </fieldset>
        </div>

        <!-- Right Column (Delivery Info) -->
        <div class="col-md-6">
            <fieldset class="adminform">
                <h5 class="mb-0"><?php echo Text::_('COM_ALFA_FIELDSET_ORDER_DETAILS'); ?></h5>

                <?php echo $this->form->renderFieldset('order_details'); ?>

            </fieldset>

        </div>
    </div>

    <!-- Shipment Details -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0"><?php echo Text::_('COM_ALFA_FIELDSET_DELIVERY_DETAILS'); ?></h5>
                </div>
                <div class="card-body">
                    <?php echo $this->form->renderFieldset('shipment'); ?>
                </div>
            </div>
        </div>

        <!-- Payment Details -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h5 class="mb-0"><?php echo Text::_('COM_ALFA_PAYMENT_DETAILS'); ?></h5>
                    </div>
                    <div class="card-body">

                        <?php echo $this->form->renderField('id_paymentmethod'); ?>
                        
                        <div class="mb-3">
							<?php echo $this->form->getLabel('payment_name'); ?>
							<?php echo $this->form->getInput('payment_name'); ?>
                        </div>
                        <div>
                            <?php echo $this->form->renderField('order_payments'); ?>
							<?php //echo $this->paymentOnAdminOrderView; ?>

                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#paymentModal">
                                View Payment Logs
                            </button>

                            <?php
                            echo HTMLHelper::_(
                                'bootstrap.renderModal', //html helper type for bootstrap modals
                                'paymentModal', //the id of the modal
                                [
                                    'title'  => 'Payment Details',
                                    'footer' => '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>',
                                ],
                                $this->paymentOnAdminOrderView);
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Items Section -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="mb-0"><?php echo $this->form->getLabel('items'); ?></h5>
						<?php echo $this->form->getInput('items'); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order payments Section -->
<!--         <div class="row mt-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="mb-0">< ?php echo $this->form->getLabel('payments'); ?></h5>
                        < ?php echo $this->form->getInput('payments'); ?>
                    </div>
                </div>
            </div>
        </div> -->


		<?php echo HTMLHelper::_('uitab.endTab'); ?>

		<?php echo HTMLHelper::_('uitab.endTabSet'); ?>


        <input type="hidden" name="task" value=""/>
		<?php echo HTMLHelper::_('form.token'); ?>

</form>


