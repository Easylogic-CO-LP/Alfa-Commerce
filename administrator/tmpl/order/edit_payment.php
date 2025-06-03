<?php

//use Joomla\CMS\Language\Text;

//extract($displayData);

use \Alfa\Component\Alfa\Administrator\Helper\PluginLayoutHelper;
use \Joomla\CMS\HTML\HTMLHelper;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Router\Route;


echo $this->form->renderFieldset('payment');

?>
<?php if ($this->payment): ?>
    <div>


		<?php
		echo PluginLayoutHelper::pluginLayout(
			$this->payment->onAdminOrderPaymentView->getLayoutPluginType(),
			$this->payment->onAdminOrderPaymentView->getLayoutPluginName(),
			$this->payment->onAdminOrderPaymentView->getLayout(),
		)->render($this->payment->onAdminOrderPaymentView->getLayoutData());

		?>

		<?php
		$logsContent = PluginLayoutHelper::pluginLayout(
			$this->payment->onAdminOrderPaymentViewLogs->getLayoutPluginType(),
			$this->payment->onAdminOrderPaymentViewLogs->getLayoutPluginName(),
			$this->payment->onAdminOrderPaymentViewLogs->getLayout(),
		)->render($this->payment->onAdminOrderPaymentViewLogs->getLayoutData());

		echo HTMLHelper::_(
			'bootstrap.renderModal',    //html helper type for bootstrap modals
			'logsModal',     //the id of the modal
			[
				'title'  => 'Payment Details',
				'footer' => '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>',
			],
			$logsContent
		);

		?>

        <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                data-bs-target="#logsModal">
            View Payment Logs
        </button>

    </div>
<?php endif; ?>