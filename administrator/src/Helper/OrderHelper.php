<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * OrderHelper V3 - With Money Objects
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 *
 * ITEM PRICES ARE MONEY OBJECTS, EXCEPT THE UNITS
 * - unit_price_tax_incl: the stored decimal, with unit_price_tax_incl_formatted
 * - unit_price_tax_excl: the stored decimal, with unit_price_tax_excl_formatted
 *   (each text by Currency::formatUnit(): a unit is presentation, the line total is the money)
 * - total_price_tax_incl: Money
 * - total_price_tax_excl: Money
 * - original_product_price: Money
 * - discount_amount_tax_incl: Money
 * - discount_amount_tax_excl: Money
 *
 * READ SIDE ONLY. Order lines are written by OrderModel; this helper reads them
 * back, decorates them with their item-field snapshot, and builds the customer's
 * price context for the admin order screen.
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderLineMoney;
use Alfa\Component\Alfa\Site\Helper\PriceUnits;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;
use RuntimeException;

class OrderHelper
{
    /**
     * Get order items with Money objects for all prices
     *
     * @param int $orderId Order ID
     * @param Currency|null $currency The order's currency; read from the order when not given
     * @return array Array of order item objects with Money prices
     *
     * @throws OrderCurrencyException When no currency is given and the order names none.
     *
     * @since  1.0.0
     */
    public static function getOrderItems(int $orderId, ?Currency $currency = null): array
    {
        if ($orderId <= 0) {
            return [];
        }

        // The lines' money is the order's: its own currency, or none at all.
        $currency ??= OrderCurrency::of(orderId: $orderId);

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        $query->select(['oi.*'])
            ->from($db->quoteName('#__alfa_order_items', 'oi'))
            ->where('oi.id_order = ' . intval($orderId))
            ->order('oi.added ASC');

        $db->setQuery($query);

        try {
            $items = $db->loadObjectList();
        } catch (Exception $e) {
            Log::add('Error loading order items: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
            return [];
        }

        if (empty($items)) {
            return [];
        }

        // Convert prices to Money objects
        foreach ($items as $item) {
            // Frozen {lang: text} name -> the admin language (legacy plain passes through).
            $item->name = MultilingualHelper::resolveLangMap($item->name);

            // The units stay the stored decimals and are shown by the one unit
            // rule, from the line's own quantity and total in the same tax
            // space, at the order's frozen scale. The agreed custom rate is a
            // gross rate, so it is passed beside the gross unit only. Totals
            // are Money.
            $item->unit_price_tax_incl_formatted = $currency->formatUnit(
                unit: $item->unit_price_tax_incl,
                lineTotal: $item->total_price_tax_incl ?? 0,
                quantity: $item->quantity,
                rate: $item->custom_unit_price_tax_incl ?? null,
            );
            $item->unit_price_tax_excl_formatted = $currency->formatUnit(
                unit: $item->unit_price_tax_excl,
                lineTotal: $item->total_price_tax_excl ?? 0,
                quantity: $item->quantity,
            );

            // Core price fields -> Money objects
            $item->total_price_tax_incl = Money::of($item->total_price_tax_incl ?? 0, $currency);
            $item->total_price_tax_excl = Money::of($item->total_price_tax_excl ?? 0, $currency);
            $item->original_product_price = Money::of($item->original_product_price ?? 0, $currency);

            // Discount amounts
            $item->discount_amount_tax_incl = Money::of($item->discount_amount_tax_incl ?? 0, $currency);
            $item->discount_amount_tax_excl = Money::of($item->discount_amount_tax_excl ?? 0, $currency);

            // Refund amounts
            $item->refunded_amount_tax_excl = Money::of($item->refunded_amount_tax_excl ?? 0, $currency);
            $item->refunded_amount_tax_incl = Money::of($item->refunded_amount_tax_incl ?? 0, $currency);

            // Backward compatibility aliases (used by subform binding)
            $item->total = $item->total_price_tax_incl; // Money object

            // No per-unit `price` alias: the line total divided at the currency's
            // scale printed a unit finer than it as 0.00, and nothing reads it. A
            // unit is the one rule's figure (unit_price_tax_*_formatted above).

            // Store currency object
            $item->currency = $currency;
        }

        self::attachOrderItemFields($items);

        return $items;
    }

    /**
     * Attach the frozen item-field snapshot to each order line as $item->fields,
     * each row resolved to the current (admin) language from its {lang:text} map,
     * falling back to the default language. Display-only; the soft foreign keys
     * and raw maps stay in #__alfa_order_item_fields for any-language rendering.
     *
     * @param array $items Order line objects (each carries ->id = order_items.id).
     *
     * @since  2.0.0
     */
    public static function attachOrderItemFields(array $items): void
    {
        foreach ($items as $item) {
            $item->fields = [];
        }

        $ids = array_values(array_filter(array_map(static fn ($i) => (int) $i->id, $items)));

        if (!$ids) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->select([
                        $db->quoteName('id_order_item'),
                        $db->quoteName('field_label'),
                        $db->quoteName('value_text'),
                        $db->quoteName('payload'),
                        $db->quoteName('breakdown'),
                        $db->quoteName('price_impact_tax_incl'),
                    ])
                    ->from($db->quoteName('#__alfa_order_item_fields'))
                    ->whereIn($db->quoteName('id_order_item'), $ids)
                    ->order($db->quoteName('id') . ' ASC'),
            );
            $rows = $db->loadObjectList() ?: [];
        } catch (Exception $e) {
            return;
        }

        $byItem = [];

        foreach ($rows as $row) {
            $byItem[(int) $row->id_order_item][] = $row;
        }

        // A price impact is a stored per-unit figure: printed by the one unit
        // rule's stored branch at the order's frozen scale under the shop's
        // setting, so an impact finer than the scale is shown and never taken
        // for zero. Only an impact that is exactly zero states nothing. The "+"
        // marks a positive impact; a negative one carries its own minus.
        $signed = static function ($amount, $currency): string {
            if ($currency === null || !is_numeric($amount) || (float) $amount == 0.0) {
                return '';
            }

            $text = $currency->formatStored(value: \is_string($amount) ? $amount : (float) $amount);

            return (float) $amount < 0 ? $text : '+' . $text;
        };

        foreach ($items as $item) {
            $currency = $item->currency ?? null;
            $fields = [];

            foreach ($byItem[(int) $item->id] ?? [] as $row) {
                $breakdown = [];

                foreach ((array) json_decode((string) ($row->breakdown ?? ''), true) as $line) {
                    $breakdown[] = (object) [
                        'label' => (string) ($line['label'] ?? ''),
                        'impact' => $signed($line['price_impact_tax_incl'] ?? 0, $currency),
                    ];
                }

                $fields[] = (object) [
                    'label' => MultilingualHelper::resolveLangMap($row->field_label),
                    'value' => MultilingualHelper::resolveLangMap($row->value_text),
                    'has_payload' => $row->payload !== null,
                    'impact' => $signed($row->price_impact_tax_incl ?? 0, $currency),
                    // The stored impact as its decimal text: a consumer derives the
                    // base (the stored unit less the summed impacts) exactly from it.
                    'impact_amount' => (string) ($row->price_impact_tax_incl ?? '0'),
                    'breakdown' => $breakdown,
                ];
            }

            $item->fields = $fields;
        }
    }

    // PRODUCT SEARCH (for admin order item popup)

    /**
     * Search products for the order item popup
     *
     * Searches by name, SKU, EAN13, MPN. Returns product data
     * with calculated price from PriceCalculator (includes tax, discounts).
     *
     * @return array Array of product objects with calculated prices

    // Pricing context

    /**
     * Build a PriceContext for an order's customer
     *
     * Instead of using the admin's session (which gives admin prices),
     * this builds context from the order's actual customer (their user group,
     * their currency), so prices/discounts/taxes reflect what THEY would see.
     * An order with no account to load (a guest order, or an account since
     * deleted) prices as the anonymous visitor.
     *
     * @param int $orderId Order ID to load customer context from
     *
     * @return \Alfa\Component\Alfa\Site\Service\Pricing\PriceContext The customer's context in the order's currency.
     *
     * @throws RuntimeException When the order does not exist.
     * @throws OrderCurrencyException When the order names no currency: a line is priced in
     *                                the order's own currency or not at all.
     *
     * @since  1.0.0
     */
    public static function buildOrderPriceContext(int $orderId): \Alfa\Component\Alfa\Site\Service\Pricing\PriceContext
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        // Load order's customer and currency
        $query = $db->getQuery(true)
            ->select(['o.id_user', 'o.id_currency'])
            ->from($db->quoteName('#__alfa_orders', 'o'))
            ->where('o.id = ' . intval($orderId));
        $db->setQuery($query);
        $order = $db->loadObject();

        // An order that does not exist has no customer and no currency to price
        // with: refused, never built from currency 0 (the no-fallback rule).
        if (!$order) {
            throw new RuntimeException(Text::_('COM_ALFA_ORDER_NOT_FOUND'));
        }

        // The currency the order names, or a refusal. A context with no
        // currency admits only the price rows valid in every currency, so a
        // product priced per currency would read as free.
        $currency = OrderCurrency::ofRow(orderId: $orderId, currencyId: $order->id_currency, scale: null);

        // Priced as the order's customer, by the rule the cart line follows
        // (id_user 0 or NULL is a guest order). The context takes the primary
        // key of #__alfa_currencies, not the ISO code.
        return \Alfa\Component\Alfa\Site\Service\Pricing\PriceContext::forIndex(currencyId: $currency->getId(), placeId: 0, usergroupId: 0)
            ->withCustomer(customer: new User(identifier: (int) $order->id_user));
    }

    // PRODUCT SEARCH (Admin Order Items)

    /**
     * Search products for the order item popup
     *
     * Uses PriceCalculator with the ORDER'S CUSTOMER CONTEXT, not the admin's
     * session, so prices, discounts, and taxes reflect what the customer sees.
     *
     * @param string $searchTerm Search term (min 2 chars)
     * @param \Alfa\Component\Alfa\Site\Service\Pricing\PriceContext $context Customer pricing context
     * @param int $limit Max results (default 20)
     * @return array Array of product objects with calculated prices
     * @since  1.0.0
     */
    public static function searchProducts(
        string $searchTerm,
        \Alfa\Component\Alfa\Site\Service\Pricing\PriceContext $context,
        int $limit = 20,
    ): array {
        $searchTerm = trim($searchTerm);
        if (strlen($searchTerm) < 2) {
            return [];
        }

        // Pull translated rows from ItemsModel (it owns the per-language name
        // join + the name/sku/gtin/mpn search), then attach customer pricing below.
        $model = self::bootItemsModel();

        if (!$model) {
            return [];
        }

        $model->setState('filter.state', 1);            // published only
        $model->setState('filter.search', $searchTerm); // name / sku / gtin / mpn
        $model->setState('list.limit', $limit);
        $model->setState('list.start', 0);
        $model->setState('list.ordering', 'name');
        $model->setState('list.direction', 'ASC');

        try {
            $items = $model->getItems() ?: [];
        } catch (Exception $e) {
            Log::add('Product search error: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
            return [];
        }

        if (empty($items)) {
            return [];
        }

        // Use PriceCalculator with CUSTOMER context (not admin session)
        try {
            $calculator = new \Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator();

            $productIds = array_column($items, 'id');
            $quantities = array_fill_keys($productIds, 1);

            $prices = $calculator->calculate($productIds, $quantities, $context);

            foreach ($items as $item) {
                $priceResult = $prices[$item->id] ?? null;
                self::attachPriceResult($item, $priceResult);
            }
        } catch (Exception $e) {
            Log::add('PriceCalculator failed, falling back to raw prices: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
            $rawPrices = self::getBaseProductPrices(array_column($items, 'id'));
            foreach ($items as $item) {
                self::attachPriceResult($item, null, $rawPrices[$item->id] ?? 0);
            }
        }

        return $items;
    }

    /**
     * Boot the admin Items list model with populateState() already run, so the
     * caller's setState() values aren't overwritten inside getItems(). Rows come
     * back with the per-language name resolved by the model: single source of
     * the item query (search, joins, translation) for the order screens.
     *
     * @return \Joomla\CMS\MVC\Model\ListModel|null
     *
     * @since  1.0.0
     */
    private static function bootItemsModel()
    {
        $factory = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory();
        $model = $factory->createModel('Items', 'Administrator');

        if ($model) {
            // Force populateState() now; later setState() calls then take effect.
            $model->getState('list.ordering');
        }

        return $model ?: null;
    }

    /**
     * Get a single product by ID with calculated prices
     *
     * Uses PriceCalculator with customer context for proper tax/discount.
     *
     * @param int $productId Product ID
     * @param \Alfa\Component\Alfa\Site\Service\Pricing\PriceContext $context Customer pricing context
     * @param int $quantity Quantity for price calculation (default 1)
     * @return object|null Product object with price fields or null
     * @since  1.0.0
     */
    public static function getProductById(
        int $productId,
        \Alfa\Component\Alfa\Site\Service\Pricing\PriceContext $context,
        int $quantity = 1,
    ): ?object {
        if ($productId <= 0) {
            return null;
        }

        // Translated single row via ItemsModel; the `id:` search matches any
        // state (order items may reference unpublished products).
        $model = self::bootItemsModel();

        if (!$model) {
            return null;
        }

        $model->setState('filter.state', '*');
        $model->setState('filter.search', 'id:' . $productId);
        $model->setState('list.limit', 1);
        $model->setState('list.start', 0);

        try {
            $items = $model->getItems() ?: [];
        } catch (Exception $e) {
            return null;
        }

        $item = $items[0] ?? null;

        if (!$item) {
            return null;
        }

        try {
            $calculator = new \Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator();
            $priceResult = $calculator->calculate($productId, $quantity, $context);
            self::attachPriceResult($item, is_object($priceResult) ? $priceResult : null);
        } catch (Exception $e) {
            Log::add('PriceCalculator failed for product ' . $productId . ': ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
            $rawPrices = self::getBaseProductPrices([$productId]);
            self::attachPriceResult($item, null, $rawPrices[$productId] ?? 0);
        }

        return $item;
    }

    /**
     * Attach PriceResult data to a product item object
     *
     * Centralizes the mapping from PriceResult to flat item properties.
     * Used by both searchProducts() and getProductById().
     *
     * @param object $item Product object to attach prices to
     * @param object|null $priceResult Calculated PriceResult (null = fallback)
     * @param float $fallbackPrice Raw price if PriceResult unavailable
     * @since  1.0.0
     */
    protected static function attachPriceResult(object $item, $priceResult, float $fallbackPrice = 0): void
    {
        if ($priceResult && is_object($priceResult)) {
            $item->price_result = $priceResult;

            // Correct price mapping:
            //
            // getSubtotalPrice() = per unit, after discounts, BEFORE tax
            // getTaxPrice()      = per unit tax amount
            // getPrice()         = per unit FINAL (after all discounts + tax)
            // getBasePrice()     = per unit ORIGINAL (before any discounts)
            //
            // For order_items we need the accounting split:
            //   price_tax_excl = subtotal (after discounts, before tax)
            //   price_tax_incl = subtotal + tax
            //   tax_rate       = from TaxSummary (the REAL rate, not computed)
            //
            // The engine derives the net and the tax from what the customer pays
            // so at quantity 1 subtotal + tax is getPrice().
            // Order-level rules (coupons, etc.) are tracked separately.
            $item->price_tax_excl = $priceResult->getSubtotalPrice()->getAmount();
            $item->price_tax_incl = $item->price_tax_excl + $priceResult->getTaxPrice()->getAmount();
            $item->base_price = $priceResult->getBasePrice()->getAmount();
            $item->customer_price = $priceResult->getPrice()->getAmount(); // What customer actually pays
            $item->has_discount = $priceResult->hasDiscount();
            $item->discount_percent = $priceResult->hasDiscount() ? $priceResult->getSavingsPercent() : 0;

            // The texts the add-line search prints: one piece's final unit and
            // its net, as a page shows them, by the one unit rule. Empty when
            // the product has no price row.
            $units = PriceUnits::page(price: $priceResult, figures: ['final', 'subtotal']);
            $priced = $priceResult->getAuthoritativeUnit() !== '0';
            $item->price_text = $priced ? $units['final'] : '';
            $item->price_tax_excl_text = $priced ? $units['subtotal'] : '';

            // THE WHOLE-LINE FACTS, BESIDE THE PER-UNIT ONES (Item 3).
            //
            // Everything above is PER-UNIT, and before Item 3 that was all this
            // helper offered, so every whole-line figure the admin screens
            // showed was rebuilt by multiplying a rounded unit by a quantity.
            // The authority is whole-line, so it is carried here as well, and a
            // consumer that needs a line total takes it rather than
            // reconstructing one.
            $item->line_money = OrderLineMoney::fromPriceResult($priceResult);

            // Tax rate from TaxSummary: the ACTUAL configured rate
            // NOT computed from price difference (which breaks with after-tax discounts)
            $item->tax_rate = $priceResult->getTaxes()->getEffectiveRate();

            // Tax name from first applied tax (for order_items.tax_name field)
            $appliedTaxes = $priceResult->getTaxes()->getApplied();
            $item->tax_name = !empty($appliedTaxes) ? $appliedTaxes[0]->name : '';
        } else {
            $item->price_result = null;
            $item->price_tax_incl = $fallbackPrice;
            $item->price_tax_excl = $fallbackPrice;
            $item->base_price = $fallbackPrice;
            $item->customer_price = $fallbackPrice;
            $item->has_discount = false;
            $item->discount_percent = 0;
            $item->tax_rate = 0;
            $item->tax_name = '';
            $item->price_text = '';
            $item->price_tax_excl_text = '';
            // NULL, never a zeroed object: no PriceResult means no authority,
            // and a consumer must be able to tell that from an authoritative
            // zero.
            $item->line_money = null;
        }
    }

    /**
     * Load the active base price for each product id from #__alfa_items_prices,
     * scoped to the default user/country and (optionally) the given currency or
     * the default currency. The first matching row per product wins (lowest
     * ordering), preferring its overwritten value over the base value.
     *
     * @param int[] $productIds Item ids to fetch prices for
     * @param int|null $currencyId Currency id to match, or null/0 for default only
     *
     * @return array Map of item_id => price (float)
     * @since  1.0.0
     */
    protected static function getBaseProductPrices(array $productIds, ?int $currencyId = null): array
    {
        if (empty($productIds)) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        $query->select([
            'p.item_id',
            'p.value',
            'p.override_value',
        ])
            ->from($db->quoteName('#__alfa_items_prices', 'p'))
            ->whereIn('p.item_id', array_map('intval', $productIds))
            ->where('p.state = 1')
            ->where('(p.user_id = 0 OR p.user_id IS NULL)')
            ->where('(p.country_id = 0 OR p.country_id IS NULL)')
            ->order('p.ordering ASC');

        // Currency filter: match specific or default (0 = default currency)
        if ($currencyId !== null && $currencyId > 0) {
            $query->where('(p.currency_id = 0 OR p.currency_id IS NULL OR p.currency_id = ' . intval($currencyId) . ')');
        }

        $db->setQuery($query);

        try {
            $rows = $db->loadObjectList();
        } catch (Exception $e) {
            return [];
        }

        // Build price map: first match per product wins (ordered by ordering)
        $prices = [];
        foreach ($rows as $row) {
            if (!isset($prices[$row->item_id])) {
                // Use overwritten value if set, otherwise base value
                $prices[$row->item_id] = ($row->override_value !== null)
                    ? (float) $row->override_value
                    : (float) $row->value;
            }
        }

        return $prices;
    }
}
