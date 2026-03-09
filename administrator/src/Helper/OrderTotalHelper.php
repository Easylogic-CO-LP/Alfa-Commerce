<?php
/**
 * @package     Alfa.Component
 * @subpackage  Administrator.Helper
 * @version     4.1.0
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2026 Easylogic CO LP
 * @license     GNU General Public License version 2 or later
 *
 * Order Total Helper — Single Source of Truth for Order Totals
 *
 * The #__alfa_orders table has NO total columns. Every total is computed
 * from three component tables:
 *
 *   grand_total = items + shipping - discounts
 *
 *   items    → SUM(#__alfa_order_items.total_price_tax_incl)
 *   shipping → SUM(#__alfa_order_shipments.shipping_cost_tax_incl)
 *   discounts→ SUM(#__alfa_order_cart_rule.value_tax_excl) × avg_tax_multiplier
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *  SINGLE SOURCE OF TRUTH — computeFromArrays()
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The core formula lives in ONE place: computeFromArrays().
 * Both OrderModel (edit) and OrdersModel (list) call this method
 * with their already-loaded data — zero extra DB queries.
 *
 * The discount tax approximation is defined once here:
 *   discount_tax_incl = discount_tax_excl × (items_incl / items_excl)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *  WHO CALLS WHAT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  OrderModel::getItem()     → computeFromArrays($items, $shipments, $discounts)
 *    Data source: Money objects from loaded entities (extracted to floats)
 *    Frequency:   once per order (edit view)
 *
 *  OrdersModel::getItems()   → computeFromArrays($items, $shipments, $discounts)
 *    Data source: float arrays from batch IN queries
 *    Frequency:   once per order on current page (~20 orders)
 *
 *  OrderPaymentHelper        → getOrderTotal($orderId)
 *    Data source: queries DB directly (no pre-loaded entities)
 *    Frequency:   during payment amount auto-resolution (rare)
 *
 * Path: administrator/components/com_alfa/src/Helper/OrderTotalHelper.php
 *
 * @since  3.5.1
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\Factory;

class OrderTotalHelper
{
	// =========================================================================
	//  CORE COMPUTATION — Single Source of Truth
	//
	//  This method contains THE formula. Both models call it.
	//  No other code should compute grand totals independently.
	// =========================================================================

	/**
	 * Compute order totals from pre-loaded arrays.
	 *
	 * This is the SINGLE SOURCE OF TRUTH for the formula:
	 *   grand_total = items + shipping - discounts
	 *
	 * Both OrderModel and OrdersModel call this with their already-loaded
	 * data — zero extra DB queries.
	 *
	 * Each array element must have the following float-castable properties:
	 *
	 *   $items[]:     ->total_price_tax_incl, ->total_price_tax_excl
	 *                 (Money objects or floats — both handled)
	 *
	 *   $shipments[]: ->shipping_cost_tax_incl, ->shipping_cost_tax_excl
	 *                 (Money objects or floats — both handled)
	 *
	 *   $discounts[]: ->value_tax_excl
	 *                 (float only — cart rules never have Money objects)
	 *
	 * Discount tax-inclusive approximation:
	 *   discount_incl = discount_excl × (items_incl / items_excl)
	 *   This uses the average tax rate from items because order_cart_rule
	 *   only stores value_tax_excl. Same approach as PrestaShop/WooCommerce.
	 *
	 * @param   array  $items      Order items (with total_price_tax_incl/excl)
	 * @param   array  $shipments  Order shipments (with shipping_cost_tax_incl/excl)
	 * @param   array  $discounts  Order cart rules (with value_tax_excl)
	 *
	 * @return  object  Breakdown with properties:
	 *                  items_tax_incl, items_tax_excl,
	 *                  shipping_tax_incl, shipping_tax_excl,
	 *                  discount_tax_excl, discount_tax_incl,
	 *                  grand_total_tax_incl, grand_total_tax_excl
	 *
	 * @since   4.1.0
	 */
	public static function computeFromArrays(array $items, array $shipments, array $discounts): object
	{
		// ── Sum items ────────────────────────────────────────────
		$itemsIncl = 0.0;
		$itemsExcl = 0.0;

		foreach ($items as $item) {
			$itemsIncl += self::extractFloat($item->total_price_tax_incl ?? 0);
			$itemsExcl += self::extractFloat($item->total_price_tax_excl ?? 0);
		}

		// ── Sum shipping ─────────────────────────────────────────
		$shippingIncl = 0.0;
		$shippingExcl = 0.0;

		foreach ($shipments as $shipment) {
			$shippingIncl += self::extractFloat($shipment->shipping_cost_tax_incl ?? 0);
			$shippingExcl += self::extractFloat($shipment->shipping_cost_tax_excl ?? 0);
		}

		// ── Sum discounts (excl only — that's all the DB stores) ─
		$discountExcl = 0.0;

		foreach ($discounts as $discount) {
			$discountExcl += (float) ($discount->value_tax_excl ?? 0);
		}

		// ── Approximate discount tax-inclusive ────────────────────
		// order_cart_rule only stores value_tax_excl.
		// Approximate the incl amount using the average tax multiplier
		// from items: if items are 24% VAT, multiplier ≈ 1.24.
		$avgTaxMultiplier = ($itemsExcl > 0)
			? ($itemsIncl / $itemsExcl)
			: 1.0;

		$discountIncl = round($discountExcl * $avgTaxMultiplier, 6);

		// ── Grand totals ─────────────────────────────────────────
		// THE formula — defined once, used everywhere
		$grandIncl = $itemsIncl + $shippingIncl - $discountIncl;
		$grandExcl = $itemsExcl + $shippingExcl - $discountExcl;

		return (object) [
			'items_tax_incl'       => round($itemsIncl, 6),
			'items_tax_excl'       => round($itemsExcl, 6),
			'shipping_tax_incl'    => round($shippingIncl, 6),
			'shipping_tax_excl'    => round($shippingExcl, 6),
			'discount_tax_excl'    => round($discountExcl, 6),
			'discount_tax_incl'    => round($discountIncl, 6),
			'grand_total_tax_incl' => round($grandIncl, 6),
			'grand_total_tax_excl' => round($grandExcl, 6),
		];
	}

	// =========================================================================
	//  PUBLIC API — DB-Querying Methods (when no pre-loaded data exists)
	// =========================================================================

	/**
	 * Get the full order grand total via DB queries.
	 *
	 * Use only when no pre-loaded data exists (e.g. OrderPaymentHelper).
	 * For OrderModel/OrdersModel, call computeFromArrays() instead.
	 *
	 * @param   int          $orderId  Order PK
	 * @param   object|null  $order    Optional loaded order with ->items
	 *
	 * @return  float  Grand total (tax inclusive)
	 *
	 * @since   3.5.1
	 */
	public static function getOrderTotal(int $orderId, ?object $order = null): float
	{
		$breakdown = self::getBreakdown($orderId, $order);

		return $breakdown->grand_total_tax_incl;
	}

	/**
	 * Get a complete total breakdown via DB queries.
	 *
	 * Loads data from DB and delegates to computeFromArrays().
	 *
	 * @param   int          $orderId  Order PK
	 * @param   object|null  $order    Optional loaded order with ->items
	 *
	 * @return  object  Same structure as computeFromArrays()
	 *
	 * @since   3.5.1
	 */
	public static function getBreakdown(int $orderId, ?object $order = null): object
	{
		$db = self::db();

		// Load items from memory or DB
		if ($order && !empty($order->items)) {
			$items = $order->items;
		} else {
			$query = $db->getQuery(true)
				->select(['total_price_tax_incl', 'total_price_tax_excl'])
				->from('#__alfa_order_items')
				->where('id_order = ' . (int) $orderId);
			$db->setQuery($query);
			$items = $db->loadObjectList() ?: [];
		}

		// Load shipments from DB
		$query = $db->getQuery(true)
			->select(['shipping_cost_tax_incl', 'shipping_cost_tax_excl'])
			->from('#__alfa_order_shipments')
			->where('id_order = ' . (int) $orderId);
		$db->setQuery($query);
		$shipments = $db->loadObjectList() ?: [];

		// Load discounts from DB
		$query = $db->getQuery(true)
			->select(['value_tax_excl'])
			->from('#__alfa_order_cart_rule')
			->where('id_order = ' . (int) $orderId)
			->where('deleted = 0');
		$db->setQuery($query);
		$discounts = $db->loadObjectList() ?: [];

		// Delegate to the single computation method
		return self::computeFromArrays($items, $shipments, $discounts);
	}

	// =========================================================================
	//  COMPONENT TOTALS — Individual queries (for payment amount resolution)
	// =========================================================================

	/**
	 * Items total (tax inclusive).
	 *
	 * @param   int          $orderId  Order PK
	 * @param   object|null  $order    Optional loaded order with ->items
	 *
	 * @return  float
	 *
	 * @since   3.5.1
	 */
	public static function getItemsTotal(int $orderId, ?object $order = null): float
	{
		if ($order && !empty($order->items)) {
			$total = 0.0;

			foreach ($order->items as $item) {
				$total += self::extractFloat($item->total_price_tax_incl ?? 0);
			}

			return round($total, 6);
		}

		$db    = self::db();
		$query = $db->getQuery(true)
			->select('COALESCE(SUM(total_price_tax_incl), 0)')
			->from('#__alfa_order_items')
			->where('id_order = ' . (int) $orderId);

		$db->setQuery($query);

		return round((float) $db->loadResult(), 6);
	}

	/**
	 * Shipping total (tax inclusive).
	 *
	 * @param   int  $orderId  Order PK
	 *
	 * @return  float
	 *
	 * @since   3.5.1
	 */
	public static function getShippingTotal(int $orderId): float
	{
		$db    = self::db();
		$query = $db->getQuery(true)
			->select('COALESCE(SUM(shipping_cost_tax_incl), 0)')
			->from('#__alfa_order_shipments')
			->where('id_order = ' . (int) $orderId);

		$db->setQuery($query);

		return round((float) $db->loadResult(), 6);
	}

	/**
	 * Discount total (tax inclusive, approximated).
	 *
	 * @param   int  $orderId  Order PK
	 *
	 * @return  float
	 *
	 * @since   3.5.1
	 */
	public static function getDiscountTotal(int $orderId): float
	{
		$discountExcl = self::getDiscountTotalExcl($orderId);

		if ($discountExcl <= 0) {
			return 0.0;
		}

		$db    = self::db();
		$query = $db->getQuery(true)
			->select([
				'COALESCE(SUM(total_price_tax_incl), 0) AS incl',
				'COALESCE(SUM(total_price_tax_excl), 0) AS excl',
			])
			->from('#__alfa_order_items')
			->where('id_order = ' . (int) $orderId);

		$db->setQuery($query);
		$row = $db->loadObject();

		$excl = (float) ($row->excl ?? 0);
		$incl = (float) ($row->incl ?? 0);

		// Same multiplier logic as computeFromArrays()
		$multiplier = ($excl > 0) ? ($incl / $excl) : 1.0;

		return round($discountExcl * $multiplier, 6);
	}

	/**
	 * Discount total (tax exclusive).
	 *
	 * @param   int  $orderId  Order PK
	 *
	 * @return  float
	 *
	 * @since   3.5.1
	 */
	public static function getDiscountTotalExcl(int $orderId): float
	{
		$db    = self::db();
		$query = $db->getQuery(true)
			->select('COALESCE(SUM(value_tax_excl), 0)')
			->from('#__alfa_order_cart_rule')
			->where('id_order = ' . (int) $orderId)
			->where('deleted = 0');

		$db->setQuery($query);

		return round((float) $db->loadResult(), 6);
	}

	// =========================================================================
	//  INTERNAL UTILITIES
	// =========================================================================

	/**
	 * Extract a float from a value that might be a Money object or a number.
	 *
	 * Handles both OrderModel (Money objects) and OrdersModel (raw floats)
	 * transparently so computeFromArrays() works with either.
	 *
	 * @param   mixed  $value  Money object, numeric string, float, or int
	 *
	 * @return  float
	 *
	 * @since   4.1.0
	 */
	private static function extractFloat(mixed $value): float
	{
		if ($value instanceof Money) {
			return $value->getAmount();
		}

		if (is_object($value) && method_exists($value, 'getAmount')) {
			return (float) $value->getAmount();
		}

		return (float) $value;
	}

	/**
	 * Get the Joomla database driver.
	 *
	 * @return  \Joomla\Database\DatabaseDriver
	 */
	private static function db(): \Joomla\Database\DatabaseDriver
	{
		return Factory::getContainer()->get('DatabaseDriver');
	}
}