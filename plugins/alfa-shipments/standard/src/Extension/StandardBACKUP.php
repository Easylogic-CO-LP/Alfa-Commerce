<?php
/**
 * @package     Alfa.Plugin
 * @subpackage  AlfaShipments.Standard
 * @version     3.0.0
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2025 Easylogic CO LP
 * @license     GNU General Public License version 2 or later
 *
 * Standard shipment plugin — handles built-in shipping methods.
 *
 * Frontend hooks:  onItemView, onCartView, onCalculateShippingCost
 * Admin actions:   onGetShipmentActions, onExecuteShipmentAction
 *
 * Action patterns:
 *   response_layout SET   → Track, View Details → renders layout in popup
 *   response_layout NULL  → Mark Shipped, Mark Delivered, Cancel → message + refresh
 */

namespace Joomla\Plugin\AlfaShipments\Standard\Extension;

use Alfa\Component\Alfa\Administrator\Plugin\ShipmentsPlugin;
use Alfa\Component\Alfa\Administrator\Plugin\PluginAction;
use Alfa\Component\Alfa\Administrator\Plugin\ActionResult;
use Alfa\Component\Alfa\Administrator\Event\Shipments\GetShipmentActionsEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\ExecuteShipmentActionEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Standard Shipment Plugin
 *
 * @since  3.0.0
 */
final class Standard extends ShipmentsPlugin
{
	// =========================================================================
	// FRONTEND HOOKS (unchanged from original)
	// =========================================================================

	public function onItemView($event): void
	{
		$item   = $event->getItem();
		$method = $event->getMethod();

		$event->setLayout('default_item_view');
		$event->setLayoutData([
			'method' => $method,
			'item'   => $item,
		]);
	}

	public function onCartView($event): void
	{
		$cart   = $event->getCart();
		$method = $event->getMethod();

		$event->setLayout('default_cart_view');
		$event->setLayoutData([
			'method' => $method,
			'item'   => $cart,
		]);
	}

	public function onCalculateShippingCost($event): void
	{
		$cart   = $event->getCart();
		$method = $event->getMethod();

		$shippingCost = self::calculateShippingCost($cart, $method);
		$event->setShippingCost($shippingCost);
	}

	// =========================================================================
	// ADMIN ACTIONS — Defines what buttons appear for each shipment
	// =========================================================================

	/**
	 * Register actions based on shipment status.
	 *
	 * Each action defines:
	 *   button_layout   → null = default 'action_button', or custom layout name
	 *   response_layout → null = no popup (message+refresh), or layout name for popup
	 *
	 * @param   GetShipmentActionsEvent  $event
	 *
	 * @return  void
	 *
	 * @since   3.0.0
	 */
	public function onGetShipmentActions(GetShipmentActionsEvent $event): void
	{
		$shipment = $event->getShipment();
		$status   = $shipment->status ?? 'pending';

		// ── Mark Shipped (pending only) ─────────────────────────────────
		if (in_array($status, ['pending', ''])) {
			$event->addAction(new PluginAction([
				'id'                    => 'mark_shipped',
				'label'                 => 'Mark Shipped',
				'icon'                  => 'truck',
				'class'                 => 'btn-primary',
				'requires_confirmation' => true,
				'confirmation_message'  => 'Are you sure you want to mark this shipment as shipped?',
				'priority'              => 100,
				'response_layout'       => null,  // No popup — just message + refresh
			]));
		}

		// ── Mark Delivered (shipped only) ───────────────────────────────
		if ($status === 'shipped') {
			$event->addAction(new PluginAction([
				'id'                    => 'mark_delivered',
				'label'                 => 'Mark Delivered',
				'icon'                  => 'check-circle',
				'class'                 => 'btn-success',
				'requires_confirmation' => true,
				'confirmation_message'  => 'Mark this shipment as delivered?',
				'priority'              => 95,
				'response_layout'       => null,
			]));
		}

		// ── Track (when tracking number exists) ─────────────────────────
		$trackingNumber = $shipment->tracking_number ?? '';
		if (!empty($trackingNumber)) {
			$event->addAction(PluginAction::track([
				'response_layout' => 'action_tracking',
				'modal_title'     => 'Shipment Tracking',
				'modal_size'      => 'md',
			]));
		}

		// ── Cancel (not delivered/cancelled) ────────────────────────────
		if (!in_array($status, ['delivered', 'cancelled'])) {
			$event->addAction(PluginAction::cancel([
				'confirmation_message' => 'Cancel this shipment?',
				'priority'             => 30,
				'response_layout'      => null,
			]));
		}

		// ── View Details (always) ───────────────────────────────────────
		$event->addAction(PluginAction::viewDetails([
			'priority'        => 10,
			'response_layout' => 'action_view_details',
			'modal_title'     => 'Shipment Details #' . (int) $shipment->id,
			'modal_size'      => 'lg',
		]));
	}

	// =========================================================================
	// ACTION EXECUTION — Custom routing + parent fallback
	// =========================================================================

	/**
	 * Execute a shipment action.
	 *
	 * Custom actions (mark_shipped) handled here.
	 * Standard actions (cancel, view_details, mark_delivered, track)
	 * routed to parent base class handlers.
	 *
	 * @param   ExecuteShipmentActionEvent  $event
	 *
	 * @return  void
	 *
	 * @since   3.0.0
	 */
	public function onExecuteShipmentAction(ExecuteShipmentActionEvent $event): void
	{
		$result = match ($event->getAction()) {
			'mark_shipped' => $this->handleMarkShipped($event),
			default        => $this->routeAction($event),
		};

		$event->setResult($result);
	}

	// =========================================================================
	// HANDLERS
	// =========================================================================

	/**
	 * Mark Shipped — DB update, message, page refresh. No popup.
	 *
	 * @param   ExecuteShipmentActionEvent  $event
	 *
	 * @return  ActionResult
	 *
	 * @since   3.0.0
	 */
	protected function handleMarkShipped(ExecuteShipmentActionEvent $event): ActionResult
	{
		$shipment = $event->getShipment();

		try {
			$db    = Factory::getContainer()->get('DatabaseDriver');
			$query = $db->getQuery(true)
				->update($db->quoteName('#__alfa_order_shipments'))
				->set($db->quoteName('status') . ' = ' . $db->quote('shipped'))
				->set($db->quoteName('shipped_at') . ' = ' . $db->quote(Factory::getDate()->toSql()))
				->where($db->quoteName('id') . ' = ' . (int) $shipment->id);

			$db->setQuery($query);
			$db->execute();

			return ActionResult::success('Shipment marked as shipped.', ['refresh' => true]);
		} catch (\Exception $e) {
			return ActionResult::error($e->getMessage());
		}
	}

	/**
	 * Track — Returns layout name + data. Controller renders in popup.
	 *
	 * Layout resolution (by controller):
	 *   1. plugins/alfa-shipments/standard/tmpl/action_tracking.php
	 *   2. layouts/shipments/action_tracking.php
	 *
	 * @param   ExecuteShipmentActionEvent  $event
	 *
	 * @return  ActionResult
	 *
	 * @since   3.0.0
	 */
	protected function handleTrack(ExecuteShipmentActionEvent $event): ActionResult
	{
		$shipment = $event->getShipment();

		if (empty($shipment->tracking_number ?? '')) {
			return ActionResult::error('No tracking number available.');
		}

		return ActionResult::withLayout(
			'action_tracking',
			['shipment' => $shipment, 'order' => $event->getOrder()],
			'Shipment Tracking',
			'md'
		);
	}

	/**
	 * Cancel — DB update, message, page refresh. No popup.
	 *
	 * @param   ExecuteShipmentActionEvent  $event
	 *
	 * @return  ActionResult
	 *
	 * @since   3.0.0
	 */
	protected function handleCancel(ExecuteShipmentActionEvent $event): ActionResult
	{
		$shipment = $event->getShipment();

		try {
			$this->updateShipmentStatus((int) $shipment->id, 'cancelled');

			return ActionResult::success('Shipment cancelled.', ['refresh' => true]);
		} catch (\Exception $e) {
			return ActionResult::error($e->getMessage());
		}
	}

	/**
	 * View Details — Returns layout name + data. Controller renders in popup.
	 *
	 * @param   ExecuteShipmentActionEvent  $event
	 *
	 * @return  ActionResult
	 *
	 * @since   3.0.0
	 */
	protected function handleViewDetails(ExecuteShipmentActionEvent $event): ActionResult
	{
		$shipment = $event->getShipment();

		return ActionResult::withLayout(
			'action_view_details',
			['shipment' => $shipment, 'order' => $event->getOrder()],
			'Shipment Details #' . (int) $shipment->id,
			'lg'
		);
	}

	// =========================================================================
	// SHIPPING COST CALCULATION (unchanged from original)
	// =========================================================================

	public function calculateShippingCost($cart, $method)
	{
		$cartData         = $cart->getData();
		$shipmentPackages = $method->params;
		$countrySelected  = 84; // Greece

		$zipCode = '000000';
		if (isset($cartData->user_info_delivery->zip_code) && !empty($cartData->user_info_delivery->zip_code)) {
			$zipCode = $cartData->user_info_delivery->zip_code;
		}

		$calculationData = null;
		foreach ($shipmentPackages['cost-per-place'] as $entry) {
			if (isset($entry['places'])) {
				foreach ($entry['places'] as $place) {
					if ($place == $countrySelected) {
						$calculationData = $entry;
					}
				}
			} else {
				$calculationData = $entry;
			}
		}

		if (empty($calculationData)) {
			return 0;
		}

		return self::findBestShippingMethod($cart->getData()->items, $calculationData['costs'], $zipCode);
	}

	public function findBestShippingMethod($products, $shippingMethods, $zipCode = -1)
	{
		if (empty($products) || empty($shippingMethods)) {
			return 0;
		}

		$packageDimensions = self::getTotalDimensions($products);

		usort($shippingMethods, function ($a, $b) {
			return $a['cost'] <=> $b['cost'];
		});

		foreach ($shippingMethods as $method) {
			if ($packageDimensions['width'] <= $method['width-max']
				&& $packageDimensions['height'] <= $method['height-max']
				&& $packageDimensions['depth'] <= $method['depth-max']
				&& $packageDimensions['weight'] <= $method['weight-max']
				&& self::isValueInRange($zipCode, $method['zip-start'], $method['zip-end'])) {
				return $method['cost'];
			}
		}

		return 0;
	}

	public function getTotalDimensions($products)
	{
		$totalWidth  = 0;
		$maxHeight   = 0;
		$maxDepth    = 0;
		$totalWeight = 0;

		foreach ($products as $product) {
			$totalWidth  += $product->width * $product->quantity;
			$maxHeight    = max($maxHeight, $product->height);
			$maxDepth     = max($maxDepth, $product->depth);
			$totalWeight += $product->weight * $product->quantity;
		}

		return [
			'width'  => $totalWidth,
			'height' => $maxHeight,
			'depth'  => $maxDepth,
			'weight' => $totalWeight,
		];
	}

	public function isValueInRange($value, $min, $max)
	{
		$valueStr = is_string($value) ? $value : strval($value);
		$minStr   = is_string($min)   ? $min   : strval($min);
		$maxStr   = is_string($max)   ? $max   : strval($max);

		if (is_numeric($valueStr) && is_numeric($minStr) && is_numeric($maxStr)) {
			return $valueStr >= $minStr && $valueStr <= $maxStr;
		}

		return strcmp($valueStr, $minStr) >= 0 && strcmp($valueStr, $maxStr) <= 0;
	}
}