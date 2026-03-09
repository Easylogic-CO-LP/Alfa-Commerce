<?php
/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\View\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Shipments\AdminOrderViewLogsEvent as ShipmentOrderViewLogsEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\AdminOrderViewEvent as ShipmentOrderViewEvent;

use Alfa\Component\Alfa\Administrator\Event\Payments\AdminOrderViewLogsEvent as PaymentOrderViewLogsEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\AdminOrderViewEvent as PaymentOrderViewEvent;

use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * View class for a single Order.
 * We directly here extend the BaseHtmlView instead of FormView to directly handle all the layout and data
 * @since  1.0.1
 */
class HtmlView extends BaseHtmlView
{
	protected $state;

	protected $order;

	protected $form;

	protected $canDo;

	protected $onAdminOrderViewEventName = 'onAdminOrderView';
	protected $onAdminOrderViewLogsEventName = 'onAdminOrderViewLogs';

	public function display($tpl = null)
	{
		$model = $this->getModel();

		$this->state = $model->getState();

		$this->canDo = ContentHelper::getActions('com_alfa');

		$app   = Factory::getApplication();
		$input = $app->getInput();

		if ($this->_layout == 'edit_shipment' || $this->_layout == 'edit_shipment_return')
		{
			$shipmentId = $input->getInt('id', 0);
			$orderId   = $input->getInt('id_order', 0);

			$this->shipment = null;
			$this->order   = null;

			if ($shipmentId > 0)
			{
				$this->shipment = $model->getShipmentData($shipmentId);
				if (!$this->shipment) throw new \Exception('Shipment not found', 404);
				$this->order = $model->getItem((int) $this->shipment->id_order);
			}
			else
			{
				if ($orderId <= 0) throw new \Exception('Order ID is required when creating a shipment', 400);
				$this->order = $model->getItem($orderId);
			}

			if (!$this->order) throw new \Exception('Order not found', 404);

			$this->form = $model->getShipmentForm($this->shipment ?? []);
			self::addShipmentToolbar();

		}
		else if ($this->_layout == 'edit_payment' || $this->_layout == 'edit_payment_return')
		{
			$paymentId = $input->getInt('id', 0);
			$orderId   = $input->getInt('id_order', 0);

			$this->payment = null;
			$this->order   = null;

			if ($paymentId > 0)
			{
				$this->payment = $model->getPaymentData($paymentId);
				if (!$this->payment) throw new \Exception('Payment not found', 404);
				$this->order = $model->getItem((int) $this->payment->id_order);
			}
			else
			{
				if ($orderId <= 0) throw new \Exception('Order ID is required when creating a payment', 400);
				$this->order = $model->getItem($orderId);
			}

			if (!$this->order) throw new \Exception('Order not found', 404);

			$this->form = $model->getPaymentForm($this->payment ?? []);
			self::addPaymentToolbar();

		}
		else if ($this->_layout == 'edit_order_item' || $this->_layout == 'edit_order_item_return')
		{
			$itemId  = $input->getInt('id', 0);
			$orderId = $input->getInt('id_order', 0);

			$this->orderItem = null;
			$this->order     = null;

			if ($itemId > 0)
			{
				$this->orderItem = $model->getOrderItemData($itemId);
				if (!$this->orderItem) throw new \Exception('Order item not found', 404);
				$this->order = $model->getItem((int) $this->orderItem->id_order);
			}
			else
			{
				if ($orderId <= 0) throw new \Exception('Order ID is required when adding an item', 400);
				$this->order = $model->getItem($orderId);
			}

			if (!$this->order) throw new \Exception('Order not found', 404);

			$this->form = $model->getOrderItemForm($this->orderItem ?? []);
			self::addOrderItemToolbar();

		}
		else
		{
			$this->order = $model->getItem();
			$this->form  = $model->getForm();

			// Load activity log for History tab (unified — includes status changes)
			if ($this->order && $this->order->id) {
				$this->activityLog = $model->getOrderActivityLog((int) $this->order->id);
			} else {
				$this->activityLog = [];
			}

			$this->addToolbar();
		}

		parent::display($tpl);

	}

	protected function addPaymentEvents(&$payment)
	{

//		$onAdminOrderViewEventName     = 'onAdminOrderPaymentView';
//		$onAdminOrderViewLogsEventName = 'onAdminOrderPaymentViewLogs';
//
//		$app = Factory::getApplication();
//
//		// Payments admin view.
//		$pluginType = 'alfa-payments';
//		$pluginName = $payment->params->type;
//
//		$bootedPlugin = $app->bootPlugin($pluginName, $pluginType);
//
//		// ADMIN ORDER VIEW ORDER EVENT
//		$paymentOnAdminOrderViewEvent = new PaymentOrderViewEvent($onAdminOrderViewEventName, [
//			"subject" => $this->order,
//			"method"  => $payment
//		]);
//		$bootedPlugin->{$paymentOnAdminOrderViewEvent->getName()}($paymentOnAdminOrderViewEvent);
//
//		if (empty($paymentOnAdminOrderViewEvent->getLayoutPluginName())) $paymentOnAdminOrderViewEvent->setLayoutPluginName($pluginName);
//		if (empty($paymentOnAdminOrderViewEvent->getLayoutPluginType())) $paymentOnAdminOrderViewEvent->setLayoutPluginType($pluginType);
//		//	check also redirect url
//		$payment->{$paymentOnAdminOrderViewEvent->getName()} = $paymentOnAdminOrderViewEvent;
//
//		// ADMIN ORDER VIEW LOGS EVENT
//		$paymentOnAdminOrderViewLogsEvent = new PaymentOrderViewLogsEvent($onAdminOrderViewLogsEventName, [
//			"subject" => $this->order,
//			"method"  => $payment
//		]);
//		$bootedPlugin->{$paymentOnAdminOrderViewLogsEvent->getName()}($paymentOnAdminOrderViewLogsEvent);
//		if (empty($paymentOnAdminOrderViewLogsEvent->getLayoutPluginName())) $paymentOnAdminOrderViewLogsEvent->setLayoutPluginName($pluginName);
//		if (empty($paymentOnAdminOrderViewLogsEvent->getLayoutPluginType())) $paymentOnAdminOrderViewLogsEvent->setLayoutPluginType($pluginType);
//		//	check also redirect url
//		$payment->{$paymentOnAdminOrderViewLogsEvent->getName()} = $paymentOnAdminOrderViewLogsEvent;
	}

	protected function addShipmentEvents(&$shipment)
	{

//		$onAdminOrderViewEventName     = 'onAdminOrderShipmentView';
//		$onAdminOrderViewLogsEventName = 'onAdminOrderShipmentViewLogs';
//
//		$app = Factory::getApplication();
//
//		// Shipments admin view.
//		$pluginType = 'alfa-shipments';
//		$pluginName = $shipment->params->type;
//
//		$bootedPlugin = $app->bootPlugin($pluginName, $pluginType);
//
//		// ADMIN ORDER VIEW ORDER EVENT
//		$shipmentOnAdminOrderViewEvent = new ShipmentOrderViewEvent($onAdminOrderViewEventName, [
//			"subject" => $this->order,
//			"method"  => $shipment
//		]);
//		$bootedPlugin->{$shipmentOnAdminOrderViewEvent->getName()}($shipmentOnAdminOrderViewEvent);
//
//		if (empty($shipmentOnAdminOrderViewEvent->getLayoutPluginName())) $shipmentOnAdminOrderViewEvent->setLayoutPluginName($pluginName);
//		if (empty($shipmentOnAdminOrderViewEvent->getLayoutPluginType())) $shipmentOnAdminOrderViewEvent->setLayoutPluginType($pluginType);
//		//	check also redirect url
//		$shipment->{$shipmentOnAdminOrderViewEvent->getName()} = $shipmentOnAdminOrderViewEvent;
//
//		// ADMIN ORDER VIEW LOGS EVENT
//		$shipmentOnAdminOrderViewLogsEvent = new ShipmentOrderViewLogsEvent($onAdminOrderViewLogsEventName, [
//			"subject" => $this->order,
//			"method"  => $shipment
//		]);
//		$bootedPlugin->{$shipmentOnAdminOrderViewLogsEvent->getName()}($shipmentOnAdminOrderViewLogsEvent);
//		if (empty($shipmentOnAdminOrderViewLogsEvent->getLayoutPluginName())) $shipmentOnAdminOrderViewLogsEvent->setLayoutPluginName($pluginName);
//		if (empty($shipmentOnAdminOrderViewLogsEvent->getLayoutPluginType())) $shipmentOnAdminOrderViewLogsEvent->setLayoutPluginType($pluginType);
//		//	check also redirect url
//		$shipment->{$shipmentOnAdminOrderViewLogsEvent->getName()} = $shipmentOnAdminOrderViewLogsEvent;
	}

	/**
	 * Add the page title and toolbar.
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function addToolbar()
	{
		Factory::getApplication()->getInput()->set('hidemainmenu', true);

		$user       = $this->getCurrentUser();
		$userId     = $user->id;
		$isNew      = ($this->order->id == 0);
		$checkedOut = !(\is_null($this->order->checked_out) || $this->order->checked_out == $userId);

		$canDo = $this->canDo;

		ToolbarHelper::title(Text::_('COM_ALFA_TITLE_ORDER'), "generic");

		// If not checked out, can save the item.
		if (!$checkedOut && ($canDo->get('core.edit') || ($canDo->get('core.create'))))
		{
			ToolbarHelper::apply('order.apply', 'JTOOLBAR_APPLY');
			ToolbarHelper::save('order.save', 'JTOOLBAR_SAVE');
		}


		ToolbarHelper::cancel('order.cancel', 'JTOOLBAR_CANCEL');

	}

	/**
	 * Add toolbar for payment edit view
	 *
	 * @return  void
	 */
	protected function addPaymentToolbar(): void
	{
		Factory::getApplication()->getInput()->set('hidemainmenu', true);

		$paymentId = Factory::getApplication()->getInput()->getInt('id', 0);
		$isNew     = ($paymentId == 0);
		$toolbar  = $this->getDocument()->getToolbar();

		$title = $isNew
			? Text::_('COM_ALFA_TITLE_PAYMENT_NEW')
			: Text::_('COM_ALFA_TITLE_PAYMENT_EDIT');

		ToolbarHelper::title($title, 'credit');

		// Save button
		$toolbar->save('order.savePayment');

		// Delete button (only for existing payments)
		if (!$isNew)
		{
			$toolbar->delete('order.deletePayment')
				->text('JTOOLBAR_DELETE')
				->message('JGLOBAL_CONFIRM_DELETE');
		}

		// Cancel button
		$toolbar->cancel('order.cancelPayment');
	}

	/**
	 * Add toolbar for shipment edit view
	 *
	 * @return  void
	 */
	protected function addShipmentToolbar(): void
	{
		Factory::getApplication()->getInput()->set('hidemainmenu', true);

		$shipmentId = Factory::getApplication()->getInput()->getInt('id', 0);
		$isNew = ($shipmentId == 0);
		$toolbar  = $this->getDocument()->getToolbar();

		$title = $isNew
			? Text::_('COM_ALFA_TITLE_SHIPMENT_NEW')
			: Text::_('COM_ALFA_TITLE_SHIPMENT_EDIT');

		ToolbarHelper::title($title, 'shipping');

		// Save button
		$toolbar->save('order.saveShipment');

		// Delete button (only for existing payments)
		if (!$isNew)
		{
			$toolbar->delete('order.deleteShipment')
				->text('JTOOLBAR_DELETE')
				->message('JGLOBAL_CONFIRM_DELETE');
		}

		// Cancel button
		$toolbar->cancel('order.cancelShipment');
	}

	/**
	 * Add toolbar for order item edit view
	 *
	 * @return  void
	 */
	protected function addOrderItemToolbar(): void
	{
		Factory::getApplication()->getInput()->set('hidemainmenu', true);

		$itemId = Factory::getApplication()->getInput()->getInt('id', 0);
		$isNew  = ($itemId == 0);
		$toolbar = $this->getDocument()->getToolbar();

		$title = $isNew
			? Text::_('COM_ALFA_TITLE_ORDER_ITEM_NEW')
			: Text::_('COM_ALFA_TITLE_ORDER_ITEM_EDIT');

		ToolbarHelper::title($title, 'cart');

		// Save button
		$toolbar->save('order.saveOrderItem');

		// Delete button (only for existing items)
		if (!$isNew)
		{
			$toolbar->delete('order.deleteOrderItem')
				->text('JTOOLBAR_DELETE')
				->message('JGLOBAL_CONFIRM_DELETE');
		}

		// Cancel button
		$toolbar->cancel('order.cancelOrderItem');
	}

}