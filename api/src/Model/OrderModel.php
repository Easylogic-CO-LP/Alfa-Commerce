<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderTotalHelper;
use Alfa\Component\Alfa\Administrator\Model\OrderModel as AdminOrderModel;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderFacts;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal;

/**
 * API Order model: the admin model with the edit-view enrichment mapped to
 * the same scalar attribute names the orders LIST route exposes.
 *
 * Admin getItem() wraps every total in Money objects under edit-view names
 * (total_products_tax_incl, total_paid_tax_incl = the grand total, ...) which
 * the JSON:API serializer cannot render; this model flattens them so a single
 * order reads exactly like a list row, plus item-only detail.
 *
 * @since  2.0.0
 */
class OrderModel extends AdminOrderModel
{
    use ProjectsMoney;

    /**
     * Orders created through the API are API orders.
     *
     * The parent writes 'admin' for its own form controller; this class IS the
     * API client, so overriding here needs no application probe and no change
     * to forms/order.xml.
     *
     *
     * @since  2.0.0
     */
    protected function newOrderSource(): string
    {
        return 'api';
    }

    /**
     * Get the order with API-consumable scalar totals and status names.
     *
     * @param int|null $pk The record id.
     *
     * @return mixed Object on success, false on failure.
     *
     * @throws OrderCurrencyException When the order names no currency. The
     *                                route that reads one order answers it
     *                                with {@see self::getItemWithoutCurrency()};
     *                                every write is refused.
     *
     * @since  2.0.0
     */
    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if (!$item || empty($item->id)) {
            return $item;
        }

        $currency = $item->currency_data ?? null;
        $currency = $currency instanceof Currency ? $currency : null;

        // The order's totals as the exact sums of its stored rows, the same
        // figures the edit view shows, read without a float: an order at eight
        // places can state more digits than a float holds.
        $totals = OrderTotalHelper::getExactBreakdown(orderId: (int) $item->id);

        // The same facts the admin list, the edit view and the totals card
        // read, derived from that grand total. This route used to run its own
        // ladder, comparing refunds against the order total instead of what is
        // still held, so an order overpaid and then partly refunded reported
        // 'refunded' here while the back office said 'partially_refunded'.
        $facts = $currency === null
            ? null
            : OrderFacts::forOrder((int) $item->id, Money::of($totals->grand_total_tax_incl, $currency));

        return $this->project(
            item:     $item,
            currency: $currency,
            // The list route's names. The grand total is items + shipping +
            // contributions - discounts.
            figures:  [
                'order_total' => $totals->grand_total_tax_incl,
                'order_total_tax_excl' => $totals->grand_total_tax_excl,
                'items_total' => $totals->items_tax_incl,
                'shipping_total' => $totals->shipping_tax_incl,
                'discount_total' => $totals->discount_tax_incl,
                'contributions_total' => $totals->contributions_tax_incl,
                'total_paid_real' => $facts?->money->paid_real,
                'total_refunded_real' => $facts?->money->refunded_real,
            ],
            facts:    $facts,
        );
    }

    /**
     * An order that names no currency, as the route that reads one order
     * answers it.
     *
     * The back-office screen still opens such an order, and a client reading
     * it is told what is stored rather than refused: `currency` is null, every
     * money value carries the stored number and the notice where its
     * formatted amount would be, and there is no payment state, which cannot
     * be derived without a currency. Nothing is formatted in another
     * currency.
     *
     * @param int $orderId The order.
     *
     * @return object|false The order, or false when there is no such order.
     *
     * @since  2.0.0
     */
    public function getItemWithoutCurrency(int $orderId): object|false
    {
        $item = $this->getItemWithoutMoney($orderId);

        if ($item === null) {
            return false;
        }

        $totals = OrderTotalHelper::getExactBreakdown(orderId: $orderId);

        return $this->project(
            item:     $item,
            currency: null,
            figures:  [
                'order_total' => $totals->grand_total_tax_incl,
                'order_total_tax_excl' => $totals->grand_total_tax_excl,
                'items_total' => $totals->items_tax_incl,
                'shipping_total' => $totals->shipping_tax_incl,
                'discount_total' => $totals->discount_tax_incl,
                'contributions_total' => $totals->contributions_tax_incl,
                'total_paid_real' => null,
                'total_refunded_real' => null,
            ],
            facts:    null,
        );
    }

    /**
     * The loaded order, as the route answers it.
     *
     * @param object $item The order: the admin model's item, or the order without money.
     * @param Currency|null $currency The order's currency; null when it names none.
     * @param array $figures The order's totals by route name: Money, an exact decimal text, or null.
     * @param object|null $facts The order's payment facts; null when it names no currency.
     *
     *
     * @since  2.0.0
     */
    private function project(object $item, ?Currency $currency, array $figures, ?object $facts): object
    {
        // Money is rendered as {amount, formatted, minor_units} everywhere;
        // see ProjectsMoney for why a bare float is not enough for an order.
        $amount = fn ($value): ?array => $this->money($value, $currency);

        $item->currency = $this->currencyBlock($currency);

        foreach ($figures as $name => $figure) {
            $item->$name = $amount($figure);
        }

        $statuses = AlfaHelper::getOrderStatuses();
        $item->status_name = $statuses[(int) ($item->id_order_status ?? 0)]->name ?? '';

        $this->projectOrderDetail($item, $amount, $currency);

        $item->payment_status = $facts?->payment_status;
        $item->payment_percentage = $facts === null ? null : PublishedDecimal::percent($facts->payment_percentage);
        $item->still_held = $facts === null ? null : $amount($facts->money->still_held);
        // Signed: negative = overpaid.
        $item->balance = $facts === null ? null : $amount($facts->money->balance);
        $item->overpaid = $facts?->overpaid;
        // The excess as its own amount, so an API consumer never negates a
        // signed figure to learn what it is owed back.
        $item->excess = $facts === null ? null : $amount($facts->money->excess);
        $item->conversion_rate = self::decimal(value: $item->conversion_rate ?? null);

        // Customer identity: the order's own snapshot, falling back to the
        // account for orders placed before the snapshot existed, the same
        // answer the grid, the emails and the documents give (OrderIdentity,
        // resolved by the parent model into user_name / user_email).
        $item->customer_name = (string) ($item->customer_name ?: ($item->user_name ?? ''));
        $item->customer_email = (string) ($item->customer_email ?: ($item->user_email ?? ''));

        return $item;
    }

    /**
     * Project the order's child collections, the same detail the back-office
     * order screen shows: lines, payments, shipments, contributions, history
     * and the buyer's addresses.
     *
     * Everything is flattened to scalars. The raw rows are unusable on the
     * wire for two separate reasons:
     *
     *   MONEY: unit/total prices, shipping costs and contribution amounts are
     *   Money objects (private properties, no JsonSerializable), so they would
     *   serialise to {} with a 200 and no error.
     *
     *   SECRETS: OrderModel::getItem() hangs the whole payment/shipment METHOD
     *   record off each row as ->params (and on ->selected_payment /
     *   ->selected_shipment), which carries the gateway merchant id, API key
     *   and client secret. Those are dropped here and never rendered.
     *
     * @param object $item The order, modified in place.
     * @param callable $amount Money|numeric -> float|null.
     * @param Currency|null $currency The order's currency; null when it names none.
     *
     *
     * @since  2.0.0
     */
    private function projectOrderDetail(object $item, callable $amount, ?Currency $currency): void
    {
        // The line's units arrive as the stored decimals and are rendered by the
        // one unit rule's fixed Auto form from the line's own quantity and
        // totals; the agreed custom rate is the rate itself, as data.
        $rate = fn ($value): ?array => $this->rate(value: $value, currency: $currency);
        $unit = fn ($value, $lineTotal, $quantity, $customRate = null): ?array => $this->unit(
            value: $value,
            lineTotal: $lineTotal,
            quantity: $quantity,
            rate: $customRate,
            currency: $currency,
        );

        $rows = static fn ($v): array => \is_array($v) ? array_values($v) : [];

        $item->lines = array_map(
            static fn ($l): array => [
                'id' => (int) ($l->id ?? 0),
                'id_item' => isset($l->id_item) ? (int) $l->id_item : null,
                'id_combination' => isset($l->id_combination) ? (int) $l->id_combination : null,
                'name' => \is_string($l->name ?? null) ? $l->name : '',
                'sku' => $l->sku ?? '',
                'quantity' => isset($l->quantity) ? (float) $l->quantity : null,
                'quantity_removed' => isset($l->quantity_removed) ? (float) $l->quantity_removed : null,
                'unit_price_tax_incl' => $unit($l->unit_price_tax_incl ?? null, $l->total_price_tax_incl ?? null, $l->quantity ?? 0, $l->custom_unit_price_tax_incl ?? null),
                'unit_price_tax_excl' => $unit($l->unit_price_tax_excl ?? null, $l->total_price_tax_excl ?? null, $l->quantity ?? 0),
                'total_price_tax_incl' => $amount($l->total_price_tax_incl ?? null),
                'total_price_tax_excl' => $amount($l->total_price_tax_excl ?? null),
                // The line's stated pricing instructions, as the order-line
                // popup shows them ("The API must expose the same
                // explicit state as the administrator UI"). A PATCH that omits
                // one keeps it, so a client can read what it keeps. Both
                // manual columns are NOT NULL; a null custom price is no override.
                'custom_unit_price_tax_incl' => $rate($l->custom_unit_price_tax_incl ?? null),
                'manual_discount_percent' => self::decimal(value: $l->manual_discount_percent),
                'manual_discount_scope' => (string) $l->manual_discount_scope,
                'tax_rate' => self::decimal(value: $l->tax_rate ?? null),
                'id_document' => isset($l->id_document) ? (int) $l->id_document : null,
            ],
            $rows($item->items ?? null),
        );

        $item->payments = array_map(
            static fn ($p): array => [
                'id' => (int) ($p->id ?? 0),
                'id_payment_method' => isset($p->id_payment_method) ? (int) $p->id_payment_method : null,
                'payment_type' => $p->payment_type ?? null,
                'transaction_status' => $p->transaction_status ?? null,
                'transaction_id' => $p->transaction_id ?? null,
                'amount' => $amount($p->amount ?? null),
                'conversion_rate' => self::decimal(value: $p->conversion_rate ?? null),
                'refund_type' => $p->refund_type ?? null,
                'note' => $p->note ?? null,
                'id_document' => isset($p->id_document) ? (int) $p->id_document : null,
                'created' => $p->created ?? null,
            ],
            $rows($item->payments ?? null),
        );

        $item->shipments = array_map(
            static fn ($s): array => [
                'id' => (int) ($s->id ?? 0),
                'id_shipment_method' => isset($s->id_shipment_method) ? (int) $s->id_shipment_method : null,
                'tracking_number' => $s->tracking_number ?? null,
                'shipping_cost_tax_incl' => $amount($s->shipping_cost_tax_incl ?? null),
                'shipping_cost_tax_excl' => $amount($s->shipping_cost_tax_excl ?? null),
                // The VAT rate the charge was taxed at, as its exact decimal text.
                'shipping_tax_rate' => self::decimal(value: $s->shipping_tax_rate ?? null),
                'weight' => isset($s->weight) ? (float) $s->weight : null,
                'note' => $s->note ?? null,
                'created' => $s->created ?? null,
            ],
            $rows($item->shipments ?? null),
        );

        $item->contributions = array_map(
            static fn ($c): array => [
                'id' => (int) ($c->id ?? 0),
                'name' => $c->name ?? '',
                'amount_tax_incl' => $amount($c->amount_tax_incl ?? null),
                'amount_tax_excl' => $amount($c->amount_tax_excl ?? null),
            ],
            $rows($item->contributions ?? null),
        );

        $item->history = array_map(
            static fn ($h): array => [
                'id' => (int) ($h->id ?? 0),
                'id_order_status' => isset($h->id_order_status) ? (int) $h->id_order_status : null,
                'created' => $h->created ?? null,
                'created_by' => isset($h->created_by) ? (int) $h->created_by : null,
            ],
            $rows($item->history ?? null),
        );

        // Buyer identity: this is a back-office endpoint, so the delivery and
        // invoice addresses belong here, but only as flat scalars.
        foreach (['user_info' => 'delivery_address', 'invoice_info' => 'invoice_address'] as $src => $dest) {
            $item->$dest = isset($item->$src) && \is_object($item->$src)
                ? array_filter(
                    (array) $item->$src,
                    static fn ($k): bool => !\in_array($k, ['id', 'user_id', 'password', 'otpKey', 'otep'], true),
                    \ARRAY_FILTER_USE_KEY,
                )
                : null;
        }

        // Never on the wire: the method records carry gateway credentials, and
        // `items` is superseded by the projected `lines`.
        unset($item->items, $item->selected_payment, $item->selected_shipment, $item->currency_data, $item->user_info, $item->invoice_info);
    }
}
