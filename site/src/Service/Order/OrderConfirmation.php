<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Order;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderTotalHelper;
use Alfa\Component\Alfa\Administrator\Helper\RoleRegistry;
use Alfa\Component\Alfa\Administrator\Service\Download\Entitlements;
use Alfa\Component\Alfa\Administrator\Service\Order\EmailService;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderFacts;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderIdentity;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Summary\SummaryRow;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * The order-completed page's order: the site read service and the one read-only value
 * it returns (2.2.0, design 2.17).
 *
 * WHO MAY SEE IT. access() answers for an order opened by its keyed address: the key is
 * compared with the order's own by hash_equals, and a missing, empty or wrong key, an
 * order with no key, or a missing order is refused. A guest order (no account, or an
 * account that no longer exists) needs the key alone; a customer account's order needs
 * the key and that customer logged in, as DownloadController judges a download. Never
 * by e-mail or name.
 *
 * WHAT IT HOLDS. read() reads the order once, in a handful of batched reads, into the
 * value the template prints and nothing else: the number, the date, the status's name,
 * the lines with their chosen options, the summary rows (OrderSummary::rows(), the
 * order's frozen money), the delivery and invoice addresses, the two method names the
 * order recorded, the payment state from the payments balance, the e-mail the
 * confirmation went to and whether downloads were granted. Never the phone, the notes,
 * the IP address, a transaction id or payment reference, a staff field or the key.
 *
 * It only reads. Nothing here writes, so a reload of the page repeats nothing. It logs
 * nothing either: the key never reaches a log.
 *
 * @since  2.2.0
 */
final class OrderConfirmation
{
    /** The visitor may see the order. @since 2.2.0 */
    public const GRANTED = 'granted';

    /** The order is a customer account's, and nobody is logged in. @since 2.2.0 */
    public const LOGIN = 'login';

    /** Not available to this visitor. @since 2.2.0 */
    public const REFUSED = 'refused';

    /** Payment state: the order is paid. @since 2.2.0 */
    public const PAID = 'paid';

    /** Payment state: part of what the order asks is held. @since 2.2.0 */
    public const PARTLY_PAID = 'partly_paid';

    /** Payment state: nothing has been taken yet. @since 2.2.0 */
    public const AWAITING_PAYMENT = 'awaiting_payment';

    /** Payment state: what was taken has been given back. @since 2.2.0 */
    public const REFUNDED = 'refunded';

    /**
     * @param int $id The order id.
     * @param string $number The number the customer knows the order by (its invoice number, else its id).
     * @param string $created When the order was placed, UTC, as stored.
     * @param string $status The order status's name in the current language.
     * @param object[] $lines Each line: name, options (each label and value), quantity, total (Money).
     * @param SummaryRow[] $summary The money summary rows.
     * @param object[] $delivery The delivery address: each field shown, label and value.
     * @param object[] $invoice The invoice address, read the same way.
     * @param string $shipment The shipping method's name as the order recorded it.
     * @param string $payment The payment method's name as the order recorded it.
     * @param string $paymentState One of the payment state constants; '' when the order asks for nothing.
     * @param string $email The address the confirmation went to.
     * @param bool $downloads Whether the order carries downloads ready to serve.
     * @param string $gateway The gateway of record: the plugin element the order recorded; '' for none.
     * @param object $order The order row, the gateway's event subject.
     * @param object|null $method The payment method record, the gateway's event argument; null when it is gone.
     *
     * @since  2.2.0
     */
    private function __construct(
        public readonly int $id,
        public readonly string $number,
        public readonly string $created,
        public readonly string $status,
        public readonly array $lines,
        public readonly array $summary,
        public readonly array $delivery,
        public readonly array $invoice,
        public readonly string $shipment,
        public readonly string $payment,
        public readonly string $paymentState,
        public readonly string $email,
        public readonly bool $downloads,
        public readonly string $gateway,
        private readonly object $order,
        private readonly ?object $method,
    ) {
    }

    /**
     * Whether a visitor may see an order opened by its keyed address.
     *
     * @param int $orderId The order id the address carries.
     * @param string $key The key the address carries.
     * @param int $viewerId The logged-in user, 0 for a guest.
     *
     * @return string self::GRANTED, self::LOGIN or self::REFUSED.
     *
     * @since  2.2.0
     */
    public static function access(int $orderId, string $key, int $viewerId): string
    {
        if ($orderId <= 0 || $key === '') {
            return self::REFUSED;
        }

        $db = self::db();
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select([$db->quoteName('o.secure_key'), $db->quoteName('u.id', 'account')])
                ->from($db->quoteName('#__alfa_orders', 'o'))
                ->join('LEFT', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('o.id_user'))
                ->where($db->quoteName('o.id') . ' = :id')
                ->bind(':id', $orderId, ParameterType::INTEGER),
        )->loadObject();

        $stored = (string) ($row->secure_key ?? '');

        if ($stored === '' || !hash_equals($stored, $key)) {
            return self::REFUSED;
        }

        // An order whose account no longer exists is judged as a guest order: its key
        // alone, never a login nobody can complete.
        $owner = (int) ($row->account ?? 0);

        if ($owner === 0) {
            return self::GRANTED;
        }

        if ($viewerId === 0) {
            return self::LOGIN;
        }

        return $viewerId === $owner ? self::GRANTED : self::REFUSED;
    }

    /**
     * The key of an order, for the arrival address's redirect to the keyed one.
     *
     * @param int $orderId The order the checkout just completed.
     *
     * @return string '' when the order is gone or carries no key.
     *
     * @since  2.2.0
     */
    public static function keyOf(int $orderId): string
    {
        if ($orderId <= 0) {
            return '';
        }

        $db = self::db();

        return (string) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('secure_key'))
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $orderId, ParameterType::INTEGER),
        )->loadResult();
    }

    /**
     * Read an order into the page's value. Access is the caller's to decide first.
     *
     * @param int $orderId The order.
     *
     * @return self|null Null when there is no such order.
     *
     * @since  2.2.0
     */
    public static function read(int $orderId): ?self
    {
        if ($orderId <= 0) {
            return null;
        }

        $db = self::db();
        $order = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $orderId, ParameterType::INTEGER),
        )->loadObject();

        if (!\is_object($order)) {
            return null;
        }

        $currency = OrderCurrency::ofRow(orderId: $orderId, currencyId: $order->id_currency ?? null, scale: $order->currency_scale ?? null);

        // The lines with their chosen options, names in the current language (two reads).
        $items = OrderHelper::getOrderItems($orderId, $currency);

        // The rows the order's totals are summed from, as OrderTotalHelper reads them:
        // the active shipments, the frozen rules in the order they were applied, the
        // frozen contributions in their sort order.
        $shipments = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['shipping_cost_tax_incl', 'shipping_cost_tax_excl']))
                ->from($db->quoteName('#__alfa_order_shipments'))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->where(OrderTotalHelper::activeShipmentsWhere($db)),
        )->loadObjectList() ?: [];
        $rules = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['name', 'code', 'value_tax_excl', 'value_tax_incl', 'discount_class', 'free_shipping']))
                ->from($db->quoteName('#__alfa_order_cart_rule'))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->where($db->quoteName('deleted') . ' = 0')
                ->order($db->quoteName('id_order_cart_rule') . ' ASC'),
        )->loadObjectList() ?: [];
        $contributions = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['name', 'amount_tax_incl', 'amount_tax_excl']))
                ->from($db->quoteName('#__alfa_order_contributions'))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->order([$db->quoteName('sort_order') . ' ASC', $db->quoteName('id') . ' ASC']),
        )->loadObjectList() ?: [];

        $breakdown = OrderTotalHelper::exactFromArrays(items: $items, shipments: $shipments, discounts: $rules, contributions: $contributions);
        $addresses = self::addresses(order: $order);

        return new self(
            id: $orderId,
            number: EmailService::orderNumber(order: $order),
            created: (string) ($order->created ?? ''),
            status: MultilingualHelper::getTranslatedValue(
                id: (int) ($order->id_order_status ?? 0),
                tableName: '#__alfa_orders_statuses',
                primaryColumnName: 'id_orderstatus',
                field: 'name',
            ),
            lines: array_map(
                static fn (object $item): object => (object) [
                    'name' => (string) $item->name,
                    'options' => array_map(
                        static fn (object $field): object => (object) ['label' => (string) $field->label, 'value' => (string) $field->value],
                        (array) ($item->fields ?? []),
                    ),
                    'quantity' => (int) $item->quantity,
                    'total' => $item->total_price_tax_incl,
                ],
                $items,
            ),
            summary: OrderSummary::rows(
                breakdown: $breakdown,
                lines: $items,
                shipments: $shipments,
                rules: $rules,
                contributions: $contributions,
                currency: $currency,
            ),
            delivery: $addresses['delivery'],
            invoice: $addresses['invoice'],
            shipment: (string) ($order->shipment_method_name ?? ''),
            payment: (string) ($order->payment_method_name ?? ''),
            paymentState: self::paymentState(orderId: $orderId, total: Money::of($breakdown->grand_total_tax_incl, $currency)),
            email: OrderIdentity::notificationEmail($order),
            downloads: self::hasDownloads(orderId: $orderId),
            gateway: (string) ($order->payment_method_type ?? ''),
            order: $order,
            method: self::method(methodId: (int) ($order->id_payment_method ?? 0)),
        );
    }

    /**
     * The subject of the gateway's completion view: the order's own row, which the
     * gateways read through the Order reader (getOrder(), id()) or its id. A copy, so a
     * handler cannot change what the page shows.
     *
     * @since  2.2.0
     */
    public function gatewaySubject(): object
    {
        return clone $this->order;
    }

    /**
     * The payment method record handed to the gateway's completion view (its settings
     * decoded), as the storefront hands it everywhere else; null when the method is gone.
     *
     * @since  2.2.0
     */
    public function gatewayMethod(): ?object
    {
        return $this->method === null ? null : clone $this->method;
    }

    /**
     * The order's delivery and invoice addresses as the page shows them: each field the
     * checkout section asks, with its label in the current language and its value, the
     * place fields by their place's name. Phone fields and the e-mail are not shown (the
     * confirmation line names the e-mail). An invoice that bills to the delivery record
     * reads that record.
     *
     * @param object $order The order row.
     *
     * @return array{delivery: object[], invoice: object[]}
     *
     * @since  2.2.0
     */
    private static function addresses(object $order): array
    {
        $deliveryId = (int) ($order->id_address_delivery ?? 0);
        $invoiceId = (int) ($order->id_address_invoice ?? 0);
        $invoiceId = $invoiceId > 0 ? $invoiceId : $deliveryId;
        $ids = array_values(array_unique(array_filter([$deliveryId, $invoiceId])));
        $records = [];

        if ($ids !== []) {
            $db = self::db();
            $records = $db->setQuery(
                $db->getQuery(true)
                    ->select('*')
                    ->from($db->quoteName('#__alfa_user_info'))
                    ->whereIn($db->quoteName('id'), $ids),
            )->loadObjectList('id') ?: [];
        }

        $customerId = (int) ($order->id_user ?? 0);
        $sections = [
            'delivery' => [$records[$deliveryId] ?? null, FieldsHelper::getFields('cart.shipping', null, $customerId)],
            'invoice' => [$records[$invoiceId] ?? null, FieldsHelper::getFields('cart.billing', null, $customerId)],
        ];

        // The places both addresses name, read once whatever their state: a place
        // unpublished since keeps its name on an order made with it.
        $placeIds = [];

        foreach ($sections as [$record, $fields]) {
            foreach ($fields as $field) {
                if ($record !== null && (string) ($field->type ?? '') === FieldsHelper::PLACE_FIELD_TYPE) {
                    $placeIds[] = (int) ($record->{$field->field_name} ?? 0);
                }
            }
        }

        $places = self::placeLabels(placeIds: $placeIds);
        $result = [];

        foreach ($sections as $name => [$record, $fields]) {
            $result[$name] = $record === null ? [] : self::addressFields(record: $record, fields: $fields, places: $places);
        }

        return $result;
    }

    /**
     * One address record's shown fields, in the checkout's order.
     *
     * @param object $record The #__alfa_user_info row.
     * @param object[] $fields The section's checkout fields.
     * @param array<int, string> $places Place id => its label.
     *
     * @return object[] Each: label, value.
     *
     * @since  2.2.0
     */
    private static function addressFields(object $record, array $fields, array $places): array
    {
        $shown = [];

        foreach ($fields as $field) {
            $role = (string) ($field->role ?? '');
            $type = (string) ($field->type ?? '');

            if ($type === 'tel' || $role === RoleRegistry::PHONE || $role === RoleRegistry::EMAIL) {
                continue;
            }

            $raw = trim((string) ($record->{$field->field_name} ?? ''));

            if ($raw === '' || $raw === '[]') {
                continue;
            }

            $value = match ($type) {
                FieldsHelper::PLACE_FIELD_TYPE => $places[(int) $raw] ?? '',
                'choice' => self::choiceText(field: $field, raw: $raw),
                default => $raw,
            };

            if ($value === '') {
                continue;
            }

            $label = trim((string) ($field->field_label ?? ''));

            $shown[] = (object) [
                'label' => $label !== '' || $role === '' ? $label : RoleRegistry::labelFallback($role),
                'value' => $value,
            ];
        }

        return $shown;
    }

    /**
     * A choice field's stored value as its options' labels in the current language, as
     * the field's own display layout words it.
     *
     * @param object $field The field row.
     * @param string $raw The stored value: one option value, or a JSON list of them.
     *
     * @since  2.2.0
     */
    private static function choiceText(object $field, string $raw): string
    {
        $params = MultilingualHelper::collapseToCurrent(json_decode((string) ($field->params ?? ''), true) ?: []);
        $labels = [];

        foreach ((array) ($params['options'] ?? []) as $option) {
            $option = (array) $option;
            $labels[(string) ($option['value'] ?? '')] = (string) ($option['text'] ?? '');
        }

        $values = json_decode($raw, true);
        $values = \is_array($values) ? $values : [$raw];

        return implode(', ', array_map(static fn ($value): string => $labels[(string) $value] ?? (string) $value, $values));
    }

    /**
     * Places by their label: the name, followed by its parents' up to the country
     * ("Larisa, Greece"), as the country field labels a place. One read per level.
     *
     * @param int[] $placeIds The places wanted.
     *
     * @return array<int, string>
     *
     * @since  2.2.0
     */
    private static function placeLabels(array $placeIds): array
    {
        $db = self::db();
        $known = [];
        $wanted = array_values(array_unique(array_filter($placeIds, static fn (int $id): bool => $id > 0)));

        for ($depth = 0; $wanted !== [] && $depth < 5; $depth++) {
            $rows = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'name', 'parent_id']))
                    ->from($db->quoteName('#__alfa_places'))
                    ->whereIn($db->quoteName('id'), $wanted),
            )->loadObjectList('id') ?: [];
            $known += $rows;
            $wanted = [];

            foreach ($rows as $row) {
                $parent = (int) $row->parent_id;

                if ($parent > 0 && !isset($known[$parent])) {
                    $wanted[] = $parent;
                }
            }

            $wanted = array_values(array_unique($wanted));
        }

        $labels = [];

        foreach (array_unique($placeIds) as $placeId) {
            $label = [];

            for ($current = (int) $placeId; $current > 0 && isset($known[$current]) && \count($label) < 5; $current = (int) $known[$current]->parent_id) {
                $label[] = (string) $known[$current]->name;
            }

            $labels[(int) $placeId] = implode(', ', $label);
        }

        return $labels;
    }

    /**
     * The order's recorded payment state, from the payments balance (OrderFacts): words
     * that never claim a payment the ledger does not hold. An authorisation not yet
     * captured is not a payment. An order that asks for nothing has no state to state.
     * "Partly paid" only while the shop still holds something of it: with nothing held
     * (a payment charged back in full, say) and a balance due, the order awaits payment.
     *
     * @param int $orderId The order.
     * @param Money $total What the order asks, the canonical total.
     *
     * @since  2.2.0
     */
    private static function paymentState(int $orderId, Money $total): string
    {
        $db = self::db();
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'payment_type', 'transaction_status', 'amount', 'id_refunded_payment', 'id_reversed_payment']))
                ->from($db->quoteName('#__alfa_order_payments'))
                ->where($db->quoteName('id_order') . ' = ' . $orderId),
        )->loadObjectList() ?: [];

        $facts = OrderFacts::fromRows($total, $rows, $total->getCurrency());
        $partly = $facts->money->still_held->isPositive() ? self::PARTLY_PAID : self::AWAITING_PAYMENT;

        return match ((string) $facts->payment_status) {
            'paid' => self::PAID,
            'partial' => $partly,
            'refunded' => self::REFUNDED,
            'partially_refunded' => $facts->is_paid ? self::PAID : $partly,
            default => $facts->is_paid ? '' : self::AWAITING_PAYMENT,
        };
    }

    /**
     * Whether a download of the order is ready to serve: only the notice is shown (the
     * links went by e-mail; composing them here would rotate their tokens).
     *
     * @param int $orderId The order.
     *
     * @since  2.2.0
     */
    private static function hasDownloads(int $orderId): bool
    {
        foreach (Entitlements::getForOrder(orderId: $orderId) as $entitlement) {
            if (Entitlements::isServable(status: (int) $entitlement->status)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The payment method record, its settings decoded and its name in the current
     * language, as the storefront reads a method (AlfaHelper::loadMethods()).
     *
     * @param int $methodId The order's payment method.
     *
     * @since  2.2.0
     */
    private static function method(int $methodId): ?object
    {
        if ($methodId <= 0) {
            return null;
        }

        $db = self::db();
        $query = $db->getQuery(true)
            ->select('m.*')
            ->from($db->quoteName('#__alfa_payments', 'm'))
            ->where($db->quoteName('m.id') . ' = ' . $methodId);

        MultilingualHelper::addMultilingualJoinToQuery(
            query: $query,
            mainAlias: 'm',
            mainPrimaryColumn: 'id',
            langTableBase: '#__alfa_payments',
            langPrimaryColumn: 'id_payment',
            fields: ['name', 'description'],
        );

        $method = $db->setQuery($query)->loadObject();

        if (!\is_object($method)) {
            return null;
        }

        $method->params = json_decode((string) ($method->params ?? ''), true) ?: [];

        return $method;
    }

    /**
     * The database.
     *
     * @since  2.2.0
     */
    private static function db(): DatabaseDriver
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }
}
