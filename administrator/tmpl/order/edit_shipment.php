<?php

//use Joomla\CMS\Language\Text;

//extract($displayData);

use \Alfa\Component\Alfa\Administrator\Helper\PluginLayoutHelper;
use \Joomla\CMS\HTML\HTMLHelper;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Router\Route;


echo $this->form->renderFieldset('shipment');

?>
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

    </div>
<?php endif; ?>