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

$paymentID = $input->getInt("id");
$orderID = $input->getInt("id_order", 0);

?>

<form
    action="<?php echo Route::_("index.php?option=com_alfa&layout=order_payment&tmpl=component&id={$paymentID}&id_order={$orderID}"); ?>"
    method="post" enctype="multipart/form-data" name="adminForm" id="order_payment-form"
    class="form-validate form-horizontal">

    <button type="button" class="btn btn-success"
            onclick="Joomla.submitbutton('order.savePayment')">
        <span class="icon-apply" aria-hidden="true"></span>
        <?php echo Text::_("COM_ALFA_ORDER_PAYMENT_SAVE")?>
    </button>

    <?php if($paymentID > 0): // Display delete button on existing payments. ?>
        <button type="button" class="btn btn-danger"
            onclick="Joomla.submitbutton('order.deletePayment')">
        <span class="icon-apply" aria-hidden="true"></span>
        <?php echo Text::_("COM_ALFA_ORDER_PAYMENT_DELETE")?>
        </button>
    <?php endif;?>

    <?php echo $this->form->renderFieldset('payment'); ?>

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

    <?php echo HTMLHelper::_('form.token'); ?>
    <input type="hidden" name="task" value="" />

</form>


