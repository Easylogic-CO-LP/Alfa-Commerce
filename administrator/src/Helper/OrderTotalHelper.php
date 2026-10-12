<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Order Total Helper: Single Source of Truth for Order Totals
 *
 * The #__alfa_orders table has NO total columns. Every total is computed
 * from three component tables:
 *
 *   grand_total = items + shipping - discounts
 *
 *   items    -> SUM(#__alfa_order_items.total_price_tax_incl)
 *   shipping -> SUM(#__alfa_order_shipments.shipping_cost_tax_incl)
 *   discounts-> SUM(#__alfa_order_cart_rule.value_tax_incl): the rule's OWN
 *              frozen figure; shipping-class rows reduce shipping instead
 *
 *  SINGLE SOURCE OF TRUTH: computeFromArrays()
 *
 * The core formula lives in ONE place: computeFromArrays().
 * Both OrderModel (edit) and OrdersModel (list) call this method
 * with their already-loaded data: zero extra DB queries.
 *
 * There is no longer any tax APPROXIMATION here. Each frozen rule carries its
 * own value_tax_incl; re-deriving it from the items' average VAT was wrong for
 * every rule taxed differently from the items (a free-shipping benefit on an
 * untaxed carrier charge came back inflated by the whole items VAT rate).
 *
 *  Who calls what
 *
 *  OrderModel::getItem()     -> computeFromArrays($items, $shipments, $discounts)
 *    Data source: Money objects from loaded entities (extracted to floats)
 *    Frequency:   once per order (edit view)
 *
 *  OrdersModel::getItems()   -> computeFromArrays($items, $shipments, $discounts)
 *    Data source: float arrays from batch IN queries
 *    Frequency:   once per order on current page (~20 orders)
 *
 *  PaymentLedger        -> getOrderTotal($orderId)
 *    Data source: queries DB directly (no pre-loaded entities)
 *    Frequency:   during payment amount auto-resolution (rare)
 *
 * Path: administrator/components/com_alfa/src/Helper/OrderTotalHelper.php
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\Factory;

class OrderTotalHelper
{
    /**
     * Shipment lifecycle statuses that do NOT count toward order totals.
     *
     * The one population definition. A cancelled-and-replaced parcel
     * charging shipping twice was possible only because each loader used to
     * define "which shipments count" for itself: the edit view and the list
     * included cancelled parcels while this helper excluded them, so the same
     * order had two grand totals. Every totals input, SQL or in-memory, must
     * come through activeShipmentsWhere()/activeShipments().
     *
     * Display is a different question: panels SHOW cancelled parcels; only
     * the money formula excludes them.
     *
     * @since 1.10.0
     */
    public const INACTIVE_SHIPMENT_STATUSES = ['cancelled', 'returned'];

    /**
     * SQL predicate selecting the shipments that count toward totals.
     *
     * @param \Joomla\Database\DatabaseDriver $db Driver (for quoting).
     * @param string $prefix Optional table alias.
     *
     * @since 1.10.0
     */
    public static function activeShipmentsWhere(\Joomla\Database\DatabaseDriver $db, string $prefix = ''): string
    {
        $column = ($prefix !== '' ? $prefix . '.' : '') . 'status';

        return $db->quoteName($column) . ' NOT IN ('
            . implode(', ', array_map([$db, 'quote'], self::INACTIVE_SHIPMENT_STATUSES)) . ')';
    }

    /**
     * In-memory twin of activeShipmentsWhere() for already-loaded rows.
     *
     * @param object[] $shipments Loaded shipment rows (must carry ->status).
     *
     * @return object[] The rows that count toward totals.
     *
     * @since 1.10.0
     */
    public static function activeShipments(array $shipments): array
    {
        return array_values(array_filter(
            $shipments,
            static fn (object $s): bool => !\in_array((string) ($s->status ?? ''), self::INACTIVE_SHIPMENT_STATUSES, true),
        ));
    }

    //  CORE COMPUTATION: Single Source of Truth
    //
    //  This method contains THE formula. Both models call it.
    //  No other code should compute grand totals independently.

    /**
     * Compute order totals from pre-loaded arrays.
     *
     * This is the SINGLE SOURCE OF TRUTH for the formula:
     *   grand_total = items + shipping + contributions - discounts
     *
     * Both OrderModel and OrdersModel call this with their already-loaded
     * data: zero extra DB queries.
     *
     * Each array element must have the following properties, each a stored
     * decimal (the column's text), a Money, or a number:
     *
     *   $items[]:     ->total_price_tax_incl, ->total_price_tax_excl
     *
     *   $shipments[]: ->shipping_cost_tax_incl, ->shipping_cost_tax_excl
     *
     *   $discounts[]: ->value_tax_incl, ->value_tax_excl, ->discount_class
     *
     * EXACT. Every figure is the exact sum of the stored amounts: each is read
     * as the decimal it is (a column's text as written, a Money's minor units,
     * a number at the fifteen significant digits it carries) and the sums and
     * differences are formed on those digits, never in floats and never cut
     * to a fixed number of places. Amounts stored at their record's scale add
     * up to a figure at that scale, so no currency has to be resolved here.
     * The figures are handed back as floats, the shape every caller reads;
     * {@see self::exactFromArrays()} hands the same figures as their exact
     * decimal texts.
     *
     * @param array $items Order items (with total_price_tax_incl/excl)
     * @param array $shipments Order shipments (with shipping_cost_tax_incl/excl)
     * @param array $discounts Order cart rules (with value_tax_excl)
     *
     * @return object Breakdown with properties:
     *                items_tax_incl, items_tax_excl,
     *                shipping_tax_incl, shipping_tax_excl,
     *                discount_tax_excl, discount_tax_incl,
     *                grand_total_tax_incl, grand_total_tax_excl
     *
     * @since  1.0.0
     */
    public static function computeFromArrays(array $items, array $shipments, array $discounts, array $contributions = []): object
    {
        return (object) array_map(
            static fn (string $text): float => (float) $text,
            (array) self::exactFromArrays(items: $items, shipments: $shipments, discounts: $discounts, contributions: $contributions),
        );
    }

    /**
     * The order's totals as their exact decimal texts: the one formula of
     * {@see self::computeFromArrays()}, with no float in the answer.
     *
     * A reader that publishes a total, or makes Money of it, reads it here: a
     * float holds fifteen significant digits, and an order at eight places can
     * state more.
     *
     * @param array $items Order items (with total_price_tax_incl/excl).
     * @param array $shipments Order shipments (with shipping_cost_tax_incl/excl).
     * @param array $discounts Order cart rules (with value_tax_incl/excl and discount_class).
     * @param array $contributions Order contributions (with amount_tax_incl/excl).
     *
     * @return object The same ten properties as computeFromArrays(), each a plain decimal text.
     *
     * @since  1.14.0
     */
    public static function exactFromArrays(array $items, array $shipments, array $discounts, array $contributions = []): object
    {
        $zero = ExactAmount::of('0');

        // Sum items
        $itemsIncl = $zero;
        $itemsExcl = $zero;

        foreach ($items as $item) {
            $itemsIncl = $itemsIncl->plus(self::exact($item->total_price_tax_incl ?? 0));
            $itemsExcl = $itemsExcl->plus(self::exact($item->total_price_tax_excl ?? 0));
        }

        // Sum shipping
        $shippingIncl = $zero;
        $shippingExcl = $zero;

        foreach ($shipments as $shipment) {
            $shippingIncl = $shippingIncl->plus(self::exact($shipment->shipping_cost_tax_incl ?? 0));
            $shippingExcl = $shippingExcl->plus(self::exact($shipment->shipping_cost_tax_excl ?? 0));
        }

        // Sum discounts, and take shipping benefits off SHIPPING
        // A shipping-class rule is not a discount: it reduces the carrier
        // charge. Counting it here as well as leaving the gross shipping in
        // place subtracted it twice. Rows frozen before 1.9.0 carry
        // discount_class='order', so they behave exactly as they used to.
        $discountExcl = $zero;
        $discountIncl = $zero;

        foreach ($discounts as $discount) {
            $rowExcl = self::exact($discount->value_tax_excl ?? 0);
            // The rule's OWN inclusive figure, frozen at placement. Never
            // re-derive it from the items' average VAT; that multiplier is
            // wrong for anything taxed differently, shipping above all.
            $rowIncl = self::exact($discount->value_tax_incl ?? 0);

            // Pre-1.9.0 rows have no inclusive figure; the excl value is the
            // only honest thing left to use.
            if (!$rowIncl->isPositive()) {
                $rowIncl = $rowExcl;
            }

            if ((string) ($discount->discount_class ?? 'order') === 'shipping') {
                $shippingIncl = self::atLeastZero($shippingIncl->minus($rowIncl));
                $shippingExcl = self::atLeastZero($shippingExcl->minus($rowExcl));

                continue;
            }

            $discountExcl = $discountExcl->plus($rowExcl);
            $discountIncl = $discountIncl->plus($rowIncl);
        }

        // Sum contributions (frozen cart charges: full accounting rows)
        $contributionsIncl = $zero;
        $contributionsExcl = $zero;

        foreach ($contributions as $contribution) {
            $contributionsIncl = $contributionsIncl->plus(self::exact($contribution->amount_tax_incl ?? 0));
            $contributionsExcl = $contributionsExcl->plus(self::exact($contribution->amount_tax_excl ?? 0));
        }

        // Grand totals
        // THE formula: defined once, used everywhere
        $grandIncl = $itemsIncl->plus($shippingIncl)->plus($contributionsIncl)->minus($discountIncl);
        $grandExcl = $itemsExcl->plus($shippingExcl)->plus($contributionsExcl)->minus($discountExcl);

        return (object) [
            'items_tax_incl' => $itemsIncl->text(),
            'items_tax_excl' => $itemsExcl->text(),
            'shipping_tax_incl' => $shippingIncl->text(),
            'shipping_tax_excl' => $shippingExcl->text(),
            'contributions_tax_incl' => $contributionsIncl->text(),
            'contributions_tax_excl' => $contributionsExcl->text(),
            'discount_tax_excl' => $discountExcl->text(),
            'discount_tax_incl' => $discountIncl->text(),
            'grand_total_tax_incl' => $grandIncl->text(),
            'grand_total_tax_excl' => $grandExcl->text(),
        ];
    }

    //  PUBLIC API: DB-Querying Methods (when no pre-loaded data exists)

    /**
     * Get the full order grand total via DB queries.
     *
     * Use only when no pre-loaded data exists (e.g. PaymentLedger).
     * For OrderModel/OrdersModel, call computeFromArrays() instead.
     *
     * @param int $orderId Order PK
     * @param object|null $order Optional loaded order with ->items
     *
     * @return float Grand total (tax inclusive)
     *
     * @since  1.0.0
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
     * @param int $orderId Order PK
     * @param object|null $order Optional loaded order with ->items
     *
     * @return object computeFromArrays() structure plus display extras:
     *                cart_rules (the frozen rows) and
     *                shipping_gross_tax_incl/excl (carrier charge before
     *                shipping-class rules reduced it)
     *
     * @since  1.0.0
     */
    public static function getBreakdown(int $orderId, ?object $order = null): object
    {
        [$items, $shipments, $discounts, $contributions] = self::loadRows(orderId: $orderId, order: $order);

        // Delegate to the single computation method
        $breakdown = self::computeFromArrays($items, $shipments, $discounts, $contributions);

        // Display extras the formula itself has no use for: the frozen rows
        // (per-rule email lines) and the GROSS carrier charge, so a renderer
        // can show "was X -> now Y / Free" instead of a bare net figure.
        $grossIncl = ExactAmount::of('0');
        $grossExcl = ExactAmount::of('0');

        foreach ($shipments as $shipment) {
            $grossIncl = $grossIncl->plus(self::exact($shipment->shipping_cost_tax_incl ?? 0));
            $grossExcl = $grossExcl->plus(self::exact($shipment->shipping_cost_tax_excl ?? 0));
        }

        $breakdown->cart_rules = $discounts;
        $breakdown->shipping_gross_tax_incl = (float) $grossIncl->text();
        $breakdown->shipping_gross_tax_excl = (float) $grossExcl->text();

        return $breakdown;
    }

    /**
     * The order's totals as exact decimal texts, read from the database as
     * {@see self::getBreakdown()} reads them ({@see self::exactFromArrays()}).
     *
     * @param int $orderId The order.
     * @param object|null $order The loaded order, when its ->items are already in memory.
     *
     * @return object The ten totals, each a plain decimal text.
     *
     * @since  1.14.0
     */
    public static function getExactBreakdown(int $orderId, ?object $order = null): object
    {
        [$items, $shipments, $discounts, $contributions] = self::loadRows(orderId: $orderId, order: $order);

        return self::exactFromArrays(items: $items, shipments: $shipments, discounts: $discounts, contributions: $contributions);
    }

    /**
     * The rows an order's totals are summed from: its lines (from memory when the
     * order is loaded), its active shipments, its frozen cart rules and its
     * contributions.
     *
     * @param int $orderId The order.
     * @param object|null $order The loaded order, when its ->items are already in memory.
     *
     * @return array{0: array, 1: array, 2: array, 3: array} [items, shipments, cart rules, contributions]
     *
     * @since  1.14.0
     */
    private static function loadRows(int $orderId, ?object $order): array
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

        // Load shipments from DB; cancelled/returned shipments must NOT count toward
        // the total, or a cancel-and-replace double-charges the shipping cost.
        $query = $db->getQuery(true)
            ->select(['shipping_cost_tax_incl', 'shipping_cost_tax_excl'])
            ->from('#__alfa_order_shipments')
            ->where('id_order = ' . (int) $orderId)
            ->where(self::activeShipmentsWhere($db));
        $db->setQuery($query);
        $shipments = $db->loadObjectList() ?: [];

        // Load discounts from DB: the FULL frozen row. Selecting only
        // value_tax_excl silently degraded computeFromArrays: the incl figure
        // fell back to excl (overstating the grand total by the discount's tax)
        // and discount_class defaulted to 'order' (shipping benefits inflated
        // the Discount row instead of reducing Shipping). Emails paid for both.
        // In row order: stacked shipping-class rules are clamped one after
        // another, and OrderChargeLines::remaining() reads them in the same order.
        $query = $db->getQuery(true)
            ->select(['name', 'code', 'value_tax_excl', 'value_tax_incl', 'discount_class', 'free_shipping'])
            ->from('#__alfa_order_cart_rule')
            ->where('id_order = ' . (int) $orderId)
            ->where('deleted = 0')
            ->order('id_order_cart_rule ASC');
        $db->setQuery($query);
        $discounts = $db->loadObjectList() ?: [];

        // Load frozen cart contributions from DB
        $query = $db->getQuery(true)
            ->select(['amount_tax_incl', 'amount_tax_excl'])
            ->from('#__alfa_order_contributions')
            ->where('id_order = ' . (int) $orderId);
        $db->setQuery($query);
        $contributions = $db->loadObjectList() ?: [];

        return [$items, $shipments, $discounts, $contributions];
    }

    //  COMPONENT TOTALS: Individual queries (for payment amount resolution)

    /**
     * Items total (tax inclusive).
     *
     * @param int $orderId Order PK
     * @param object|null $order Optional loaded order with ->items
     *
     *
     * @since  1.0.0
     */
    public static function getItemsTotal(int $orderId, ?object $order = null): float
    {
        if ($order && !empty($order->items)) {
            $total = ExactAmount::of('0');

            foreach ($order->items as $item) {
                $total = $total->plus(self::exact($item->total_price_tax_incl ?? 0));
            }

            return (float) $total->text();
        }

        $db = self::db();
        $query = $db->getQuery(true)
            ->select('COALESCE(SUM(total_price_tax_incl), 0)')
            ->from('#__alfa_order_items')
            ->where('id_order = ' . (int) $orderId);

        $db->setQuery($query);

        // The database sums the stored decimals exactly.
        return (float) $db->loadResult();
    }

    /**
     * Shipping total (tax inclusive).
     *
     * @param int $orderId Order PK
     *
     *
     * @since  1.0.0
     */
    public static function getShippingTotal(int $orderId): float
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select('COALESCE(SUM(shipping_cost_tax_incl), 0)')
            ->from('#__alfa_order_shipments')
            ->where('id_order = ' . (int) $orderId)
            ->where(self::activeShipmentsWhere($db));

        $db->setQuery($query);

        return (float) $db->loadResult();
    }

    /**
     * Discount total (tax inclusive, approximated).
     *
     * @param int $orderId Order PK
     *
     *
     * @since  1.0.0
     */
    public static function getDiscountTotal(int $orderId): float
    {
        $db = self::db();

        // Read the discounts' OWN frozen inclusive figures. The old code
        // rebuilt them from the items' average VAT multiplier, which is wrong
        // for any rule taxed differently from the items: a free-shipping
        // benefit on a zero-tax carrier charge came back inflated by the whole
        // items VAT rate. COALESCE keeps pre-1.9.0 rows (incl = 0) working off
        // their excl value.
        //
        // Shipping-class rules are excluded: they reduce the carrier charge,
        // they are not order discounts.
        $query = $db->getQuery(true)
            ->select('COALESCE(SUM(CASE WHEN value_tax_incl > 0 THEN value_tax_incl ELSE value_tax_excl END), 0)')
            ->from('#__alfa_order_cart_rule')
            ->where('id_order = ' . (int) $orderId)
            ->where('deleted = 0')
            ->where('discount_class <> ' . $db->quote('shipping'));

        $db->setQuery($query);

        return (float) $db->loadResult();
    }

    /**
     * Discount total (tax exclusive).
     *
     * @param int $orderId Order PK
     *
     *
     * @since  1.0.0
     */
    public static function getDiscountTotalExcl(int $orderId): float
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select('COALESCE(SUM(value_tax_excl), 0)')
            ->from('#__alfa_order_cart_rule')
            ->where('id_order = ' . (int) $orderId)
            ->where('deleted = 0')
            ->where('discount_class <> ' . $db->quote('shipping'));

        $db->setQuery($query);

        return (float) $db->loadResult();
    }

    //  Internal utilities

    /**
     * A stored amount as the exact decimal it is: a Money as its minor units
     * at its scale, anything else as the number it states
     * ({@see ExactAmount::ofNumber()}).
     *
     * @param mixed $value A decimal text, a Money, an integer, a float, or null for nothing.
     *
     *
     * @since  1.14.0
     */
    private static function exact(mixed $value): ExactAmount
    {
        if ($value instanceof Money) {
            return ExactAmount::ofMoney($value);
        }

        return ExactAmount::ofNumber((\is_string($value) && is_numeric($value)) || \is_int($value) || \is_float($value) ? $value : (float) $value);
    }

    /**
     * An amount, or zero when it is below zero.
     *
     * @param ExactAmount $amount The amount.
     *
     *
     * @since  1.14.0
     */
    private static function atLeastZero(ExactAmount $amount): ExactAmount
    {
        return $amount->isNegative() ? ExactAmount::of('0') : $amount;
    }

    /**
     * Get the Joomla database driver.
     * @since  1.0.0
     */
    private static function db(): \Joomla\Database\DatabaseDriver
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }
}
