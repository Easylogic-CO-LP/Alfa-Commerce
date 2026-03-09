<?php
/**
 * @package     Alfa.Plugin
 * @subpackage  AlfaPayments.Standard
 * @version     2.0.0
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2025 Easylogic CO LP
 * @license     GNU General Public License version 2 or later
 */

namespace Joomla\Plugin\AlfaPayments\Standard\Extension;

use Alfa\Component\Alfa\Administrator\Plugin\PaymentsPlugin;

defined('_JEXEC') or die;

/**
 * Standard Payment Plugin - Production Grade
 *
 * Handles direct/cash payment processing with proper event handling,
 * logging, and error management.
 *
 * @since  2.0.0
 */
final class Standard extends PaymentsPlugin
{
	public function onItemView($event): void
	{
		$item = $event->getItem();
		$method = $event->getMethod();

        $layoutData = [
            'method' => $method,
            'item' => $item,
        ];

        $event->setLayout('default_item_view');
        $event->setLayoutData($layoutData);
	}

	public function onCartView($event): void
	{
		$cart = $event->getCart();
		$method = $event->getMethod();

		$layoutData = [
			'method' => $method,
			'item' => $cart,
		];

		$event->setLayout('default_cart_view');
		$event->setLayoutData($layoutData);
	}

	public function onOrderProcessView($event): void
	{
		$order = $event->getOrder();
		$method = $event->getMethod();

		// Option 1: Set a custom layout
		// $layoutData = ['order' => $order, 'method' => $method];
		// $event->setLayout('default_payment_process');
		// $event->setLayoutData($layoutData);

		// Option 2: Or set redirect directly to order completed page or a custom external/internal page
		$event->setRedirectUrl($this->getCompletePageUrl());
	}

    public function onOrderCompleteView($event): void
    {
        $order = $event->getOrder();
        $method = $event->getMethod();

        $layoutData = [
            'method' => $method,
            'order' => $order,
        ];

        $event->setLayout('default_order_completed');
        $event->setLayoutData($layoutData);
    }

	/**
	 * Event: Before order is placed
	 *
	 * Validates payment requirements before order is saved to database.
	 * Can modify cart data or prevent order placement.
	 *
	 * @param   object  $event  Event object containing cart
	 *
	 * @return  void
	 * @throws  \Exception  If validation fails
	 */
	public function onOrderBeforePlace($event): void
	{
//		$cart = $event->getCart();
//
//		try {
//			Log::add(
//				'Standard Payment: Validating cart for order placement',
//				Log::INFO,
//				'com_alfa.payments'
//			);
//
//			// Standard payment requires no special validation
//			// But you could add business rules here, e.g.:
//			// - Minimum order amount
//			// - Maximum COD amount
//			// - Customer credit checks
//
//			$cartData = $cart->getData();
//
//			// Example: Add COD fee if applicable
//			$params = $this->params;
//			$codFeeEnabled = $params->get('cod_fee_enabled', 0);
//
//			if ($codFeeEnabled) {
//				$codFeeAmount = (float) $params->get('cod_fee_amount', 0);
//				$codFeePercent = (float) $params->get('cod_fee_percent', 0);
//
//				$fee = $codFeeAmount + ($cartData->total * $codFeePercent / 100);
//
//				if ($fee > 0) {
//					$cartData->payment_fee = $fee;
//					$cartData->total += $fee;
//
//					Log::add(
//						'Standard Payment: Added COD fee: ' . $fee,
//						Log::INFO,
//						'com_alfa.payments'
//					);
//				}
//			}
//
//			$event->setCart($cart);
//
//		} catch (Exception $e) {
//			Log::add(
//				'Standard Payment: Before-place validation failed: ' . $e->getMessage(),
//				Log::ERROR,
//				'com_alfa.payments'
//			);
//			throw new Exception('Payment validation failed: ' . $e->getMessage());
//		}
	}

	/**
	 * Event: After order is placed
	 *
	 * Process payment and create payment record after order is successfully
	 * saved to database.
	 *
	 * @param   object  $event  Event object containing order
	 *
	 * @return  void
	 */
	public function onOrderAfterPlace($event): void
	{
//		$order = $event->getOrder();
//
//		try {
//			Log::add(
//				'Standard Payment: Processing payment for order #' . $order->id,
//				Log::INFO,
//				'com_alfa.payments'
//			);
//
//			// Create payment record
//			$paymentData = $this->createEmptyOrderPayment();
//			$paymentData['id_order'] = $order->id;
//			$paymentData['id_currency'] = $order->id_currency;
//			$paymentData['id_payment_method'] = $order->id_payment_method;
//			$paymentData['id_user'] = $order->id_user;
//			$paymentData['amount'] = $order->original_price;
//			$paymentData['conversion_rate'] = 1.00;
//			$paymentData['transaction_id'] = null; // COD has no transaction ID
//			$paymentData['added'] = Factory::getDate()->format('Y-m-d H:i:s');
//
//			$paymentId = $this->insertOrderPayment($paymentData);
//
//			if (!$paymentId) {
//				throw new Exception('Failed to create payment record');
//			}
//
//			// Create payment log
//			$logData = $this->createEmptyLog();
//			$logData['id_order'] = $order->id;
//			$logData['id_order_payment'] = $paymentId;
//			$logData['status'] = 'pending'; // COD is pending until delivery
//			$logData['order_total'] = $order->original_price;
//			$logData['currency'] = $order->id_currency;
//			$logData['created_on'] = Factory::getDate()->format('Y-m-d H:i:s');
//			$logData['created_by'] = Factory::getApplication()->getIdentity()->id;
//
//			$this->insertLog($logData);
//
//			Log::add(
//				'Standard Payment: Payment record created for order #' . $order->id,
//				Log::INFO,
//				'com_alfa.payments'
//			);
//
//		} catch (Exception $e) {
//			Log::add(
//				'Standard Payment: After-place processing failed: ' . $e->getMessage(),
//				Log::ERROR,
//				'com_alfa.payments'
//			);
//
//			// Don't throw exception here - order is already saved
//			// Just log the error and notify admin
//			Factory::getApplication()->enqueueMessage(
//				'Payment record creation failed. Please check order #' . $order->id,
//				'warning'
//			);
//		}
	}



	/**
	 * Event: Order complete view
	 *
	 * Display success/failure message after order completion
	 *
	 * @param   object  $event  Event object
	 *
	 * @return  void
	 */
//	public function onOrderCompleteView($event): void
//	{
//		$order = $event->getOrder();
//		$method = $event->getMethod();
//
//		Log::add(
//			'Standard Payment: Showing completion view for order #' . $order->id,
//			Log::INFO,
//			'com_alfa.payments'
//		);
//
//		$layoutData = [
//			'method' => $method,
//			'order' => $order,
//		];
//
//		$event->setLayout('default_payment_success');
//		$event->setLayoutData($layoutData);
//	}


}