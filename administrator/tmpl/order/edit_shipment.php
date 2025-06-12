<?php

defined('_JEXEC') or die;

use \Alfa\Component\Alfa\Administrator\Helper\PluginLayoutHelper;
use \Joomla\CMS\HTML\HTMLHelper;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Router\Route;

$wa = $this->document->getWebAssetManager();
$wa->useScript('keepalive')
    ->useScript('form.validate')
    ->useStyle('com_alfa.admin');

HTMLHelper::_('bootstrap.tooltip');

$input = Factory::getApplication()->input;

$shipmentID = $input->getInt("id", 0);
$orderID = $input->getInt("id_order", 0);


?>

<form
    action="<?php echo Route::_("index.php?option=com_alfa&layout=order_shipment&tmpl=component&id={$shipmentID}&id_order={$orderID}"); ?>"
    method="post" enctype="multipart/form-data" name="adminForm" id="order_shipment-form"
    class="form-validate form-horizontal">

    <button type="button" class="btn btn-success"
            onclick="Joomla.submitbutton('order.saveShipment')">
        <span class="icon-apply" aria-hidden="true"></span>
        <?php echo Text::_("COM_ALFA_ORDER_SHIPMENT_SAVE")?>
    </button>

    <?php if($shipmentID > 0): // Display delete button on existing shipments. ?>
        <button type="button" class="btn btn-danger"
                onclick="Joomla.submitbutton('order.deleteShipment')">
            <span class="icon-apply" aria-hidden="true"></span>
            <?php echo Text::_("COM_ALFA_ORDER_SHIPMENT_DELETE")?>
        </button>
    <?php endif;?>

    <?php echo $this->form->renderFieldset('shipment'); ?>

    <?php if ($this->shipment): ?>
        <div>

            <?php
            echo PluginLayoutHelper::pluginLayout(
                $this->shipment->onAdminOrderShipmentView->getLayoutPluginType(),
                $this->shipment->onAdminOrderShipmentView->getLayoutPluginName(),
                $this->shipment->onAdminOrderShipmentView->getLayout(),
            )->render($this->shipment->onAdminOrderShipmentView->getLayoutData());

            ?>

            <?php
            $logsContent = PluginLayoutHelper::pluginLayout(
                $this->shipment->onAdminOrderShipmentViewLogs->getLayoutPluginType(),
                $this->shipment->onAdminOrderShipmentViewLogs->getLayoutPluginName(),
                $this->shipment->onAdminOrderShipmentViewLogs->getLayout(),
            )->render($this->shipment->onAdminOrderShipmentViewLogs->getLayoutData());

            echo HTMLHelper::_(
                'bootstrap.renderModal',    //html helper type for bootstrap modals
                'logsModal',     //the id of the modal
                [
                    'title'  => 'Shipment Details',
                    'footer' => '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>',
                ],
                $logsContent
            );

            ?>

            <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                    data-bs-target="#logsModal">
                View Shipment Logs
            </button>

<!--            <button type="submit">submit</button>-->

        </div>
    <?php endif; ?>


    <?php echo HTMLHelper::_('form.token'); ?>
    <input type="hidden" name="task" value="" />

</form>
