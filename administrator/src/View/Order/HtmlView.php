<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\View\Order;
// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Shipments\AdminOrderViewLogsEvent as ShipmentOrderViewLogsEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\AdminOrderViewEvent as ShipmentOrderViewEvent;

use Alfa\Component\Alfa\Administrator\Event\Payments\AdminOrderViewLogsEvent as PaymentOrderViewLogsEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\AdminOrderViewEvent as PaymentOrderViewEvent;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use \Joomla\CMS\Toolbar\ToolbarHelper;
use \Joomla\CMS\Factory;
use \Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use \Joomla\CMS\Language\Text;

/**
 * View class for a single Order.
 *
 * @since  1.0.1
 */
class HtmlView extends BaseHtmlView
{
	protected $state;

	protected $order;

	protected $form;

	protected $onAdminOrderViewEventName = 'onAdminOrderView';
	protected $onAdminOrderViewLogsEventName = 'onAdminOrderViewLogs';

	// protected $shipment;

	/**
	 * Display the view
	 *
	 * @param   string  $tpl  Template name
	 *
	 * @return void
	 *
	 * @throws Exception
	 */
	public function display($tpl = null)
	{
		$this->state = $this->get('State');

		$model = $this->getModel();

		$app   = Factory::getApplication();
		$input = $app->input;

		// Check for errors.
		if (count($errors = $this->get('Errors')))
		{
			throw new \Exception(implode("\n", $errors));
		}

		if ($this->_layout == 'edit_shipment')
		{
			$this->shipment = null;
			$shipment_id = $input->getInt('id', 0);

            $shipmentData = [];
			if ($shipment_id > 0)
			{
				$this->shipment = $model->getShipmentData($shipment_id);
				$this->order    = $model->getItem($this->shipment->id_order);
                $shipmentData = $this->shipment;
				//$this->form->bind((array) $this->shipment);
				self::addShipmentEvents($this->shipment);
			}

            $this->form = $model->getShipmentForm($shipmentData);
            self::addShipmentToolbar();

		}
        else if ($this->_layout == 'edit_payment')
		{
			$this->payment = null;
			$payment_id = $input->getInt('id', 0);

            $paymentData = [];
			if ($payment_id > 0)
			{
				$this->payment = $model->getPaymentData($payment_id);
				$this->order    = $model->getItem($this->payment->id_order);

                $paymentData = $this->payment;
				self::addPaymentEvents($this->payment);
			}

            $this->form = $model->getPaymentForm($paymentData);
            self::addPaymentToolbar();

		}
		else
		{
			$this->order = $this->get('Item');
			$this->form  = $this->get('Form');

//			foreach($this->order->payments as &$payment){
//				self::addPaymentEvents($shipment);
//			}
//			foreach($this->order->shipments as &$shipment){
//				self::addShipmentEvents($shipment);
//			}

			$this->addToolbar();
		}

		parent::display($tpl);

	}

	protected function addPaymentEvents(&$payment)
	{

		$onAdminOrderViewEventName     = 'onAdminOrderPaymentView';
		$onAdminOrderViewLogsEventName = 'onAdminOrderPaymentViewLogs';

		$app = Factory::getApplication();

		// Payments admin view.
		$pluginType = 'alfa-payments';
		$pluginName = $payment->params->type;

		$bootedPlugin = $app->bootPlugin($pluginName, $pluginType);

		// ADMIN ORDER VIEW ORDER EVENT
		$paymentOnAdminOrderViewEvent = new PaymentOrderViewEvent($onAdminOrderViewEventName, [
			"subject" => $this->order,
			"method"  => $payment
		]);
		$bootedPlugin->{$paymentOnAdminOrderViewEvent->getName()}($paymentOnAdminOrderViewEvent);

		if (empty($paymentOnAdminOrderViewEvent->getLayoutPluginName())) $paymentOnAdminOrderViewEvent->setLayoutPluginName($pluginName);
		if (empty($paymentOnAdminOrderViewEvent->getLayoutPluginType())) $paymentOnAdminOrderViewEvent->setLayoutPluginType($pluginType);
		//	check also redirect url
		$payment->{$paymentOnAdminOrderViewEvent->getName()} = $paymentOnAdminOrderViewEvent;

		// ADMIN ORDER VIEW LOGS EVENT
		$paymentOnAdminOrderViewLogsEvent = new PaymentOrderViewLogsEvent($onAdminOrderViewLogsEventName, [
			"subject" => $this->order,
			"method"  => $payment
		]);
		$bootedPlugin->{$paymentOnAdminOrderViewLogsEvent->getName()}($paymentOnAdminOrderViewLogsEvent);
		if (empty($paymentOnAdminOrderViewLogsEvent->getLayoutPluginName())) $paymentOnAdminOrderViewLogsEvent->setLayoutPluginName($pluginName);
		if (empty($paymentOnAdminOrderViewLogsEvent->getLayoutPluginType())) $paymentOnAdminOrderViewLogsEvent->setLayoutPluginType($pluginType);
		//	check also redirect url
		$payment->{$paymentOnAdminOrderViewLogsEvent->getName()} = $paymentOnAdminOrderViewLogsEvent;
	}

	protected function addShipmentEvents(&$shipment)
	{

		$onAdminOrderViewEventName     = 'onAdminOrderShipmentView';
		$onAdminOrderViewLogsEventName = 'onAdminOrderShipmentViewLogs';

		$app = Factory::getApplication();

		// Shipments admin view.
		$pluginType = 'alfa-shipments';
		$pluginName = $shipment->params->type;

		$bootedPlugin = $app->bootPlugin($pluginName, $pluginType);

		// ADMIN ORDER VIEW ORDER EVENT
		$shipmentOnAdminOrderViewEvent = new ShipmentOrderViewEvent($onAdminOrderViewEventName, [
			"subject" => $this->order,
			"method"  => $shipment
		]);
		$bootedPlugin->{$shipmentOnAdminOrderViewEvent->getName()}($shipmentOnAdminOrderViewEvent);

		if (empty($shipmentOnAdminOrderViewEvent->getLayoutPluginName())) $shipmentOnAdminOrderViewEvent->setLayoutPluginName($pluginName);
		if (empty($shipmentOnAdminOrderViewEvent->getLayoutPluginType())) $shipmentOnAdminOrderViewEvent->setLayoutPluginType($pluginType);
		//	check also redirect url
		$shipment->{$shipmentOnAdminOrderViewEvent->getName()} = $shipmentOnAdminOrderViewEvent;

		// ADMIN ORDER VIEW LOGS EVENT
		$shipmentOnAdminOrderViewLogsEvent = new ShipmentOrderViewLogsEvent($onAdminOrderViewLogsEventName, [
			"subject" => $this->order,
			"method"  => $shipment
		]);
		$bootedPlugin->{$shipmentOnAdminOrderViewLogsEvent->getName()}($shipmentOnAdminOrderViewLogsEvent);
		if (empty($shipmentOnAdminOrderViewLogsEvent->getLayoutPluginName())) $shipmentOnAdminOrderViewLogsEvent->setLayoutPluginName($pluginName);
		if (empty($shipmentOnAdminOrderViewLogsEvent->getLayoutPluginType())) $shipmentOnAdminOrderViewLogsEvent->setLayoutPluginType($pluginType);
		//	check also redirect url
		$shipment->{$shipmentOnAdminOrderViewLogsEvent->getName()} = $shipmentOnAdminOrderViewLogsEvent;
	}

	/**
	 * Add the page title and toolbar.
	 *
	 * @return void
	 *
	 * @throws Exception
	 */
	protected function addToolbar()
	{

//        exit;
		Factory::getApplication()->input->set('hidemainmenu', true);

		$user  = Factory::getApplication()->getIdentity();
		$isNew = ($this->order->id == 0);

		if (isset($this->order->checked_out))
		{
			$checkedOut = !($this->order->checked_out == 0 || $this->order->checked_out == $user->get('id'));
		}
		else
		{
			$checkedOut = false;
		}

		$canDo = AlfaHelper::getActions();

		ToolbarHelper::title(Text::_('COM_ALFA_TITLE_ORDER'), "generic");

		// If not checked out, can save the item.
		if (!$checkedOut && ($canDo->get('core.edit') || ($canDo->get('core.create'))))
		{
			ToolbarHelper::apply('order.apply', 'JTOOLBAR_APPLY');
			ToolbarHelper::save('order.save', 'JTOOLBAR_SAVE');
		}


		//Save as new
//		if (!$checkedOut && ($canDo->get('core.create')))
//		{
//			ToolbarHelper::custom('order.save2new', 'save-new.png', 'save-new_f2.png', 'JTOOLBAR_SAVE_AND_NEW', false);
//		}

		// If an existing item, can save to a copy.
//		if (!$isNew && $canDo->get('core.create'))
//		{
//			ToolbarHelper::custom('order.save2copy', 'save-copy.png', 'save-copy_f2.png', 'JTOOLBAR_SAVE_AS_COPY', false);
//		}


		if (empty($this->order->id))
		{
			ToolbarHelper::cancel('order.cancel', 'JTOOLBAR_CANCEL');
		}
		else
		{
			ToolbarHelper::cancel('order.cancel', 'JTOOLBAR_CLOSE');
		}
	}

    protected function addPaymentToolbar(){

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_ORDERR'), "aaa");



    }

    protected function addShipmentToolbar(){
//        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_ORDER'), "generic");
//        ToolbarHelper::apply('order.apply', 'JTOOLBAR_APPLY');
        ToolbarHelper::save('order.savePayment', 'JTOOLBAR_SAVE');
    }

}

