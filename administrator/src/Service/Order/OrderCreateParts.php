<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\CustomPriceCommand;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoney;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoneyRefusal;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal;
use InvalidArgumentException;

/**
 * The parts of an order created in one call: its lines, its parcel and its payment, read from the create's
 * data into what each part's own writer takes, the one reader for the REST create and for an extension that creates
 * an order through the order model. OrderModel::save() writes the order through OrderCreator, then each line through
 * the line writer (saveOrderItem(), the order screen's and POST v1/alfa/orders/:id/lines'), the parcel through the
 * shipments ledger and the payment through the payments ledger, in one transaction.
 *
 * Every part is optional. The shape of each is read before anything is written ({@see self::read()}); its money is
 * read in the order's own currency once the order row exists ({@see self::shipmentData()}, {@see self::paymentData()}),
 * with the rules of the order operations' verbs ({@see self::amount()}, {@see self::rate()}, which they call too). A
 * part refused is an OrderPartRefusedException naming it as a JSON pointer into the body (/lines/1/quantity).
 *
 * @api READ ONLY: the parts of an order create's data (lines, shipment, payment), checked and turned into the data
 *      OrderModel::saveOrderItem(), saveOrderShipment() and saveOrderPayment() take; a refusal names the part.
 *
 * @since  2.2.2
 */
final class OrderCreateParts
{
    /**
     * The keys of a line: the product, its combination, the quantity and the pricing instructions the line verbs take
     * ({@see LinePricing}).
     *
     * @since  2.2.2
     */
    public const LINE_KEYS = ['id_item', 'id_combination', 'quantity', CustomPriceCommand::FIELD, 'manual_discount_percent', 'manual_discount_scope'];

    /**
     * The keys of the parcel: its method, the three figures of its charge, its tracking number and its carrier.
     *
     * @since  2.2.2
     */
    public const SHIPMENT_KEYS = ['id_shipment_method', 'shipping_cost_tax_incl', 'shipping_cost_tax_excl', 'shipping_tax_rate', 'tracking_number', 'carrier'];

    /**
     * The keys of the payment: its method, its amount, its status and the provider's reference.
     *
     * @since  2.2.2
     */
    public const PAYMENT_KEYS = ['id_payment_method', 'amount', 'transaction_status', 'transaction_id'];

    /**
     * The statuses a payment is created in with its order: still to be paid, or paid.
     *
     * @since  2.2.2
     */
    public const PAYMENT_STATUSES = [PaymentLedger::STATUS_PENDING, PaymentLedger::STATUS_COMPLETED];

    /**
     * How much of a key the client sent a refusal shows.
     *
     * @since  2.2.2
     */
    private const KEY_SHOWN = 64;

    /**
     * The parts of a create's data, their shape checked before anything is written.
     *
     * @param array $data The create's data: lines (a list), shipment and payment (objects), each optional.
     *
     * @return array{lines: list<array{id_item: int, id_combination: int, quantity: int, pricing: array}>, shipment: ?array, payment: ?array}
     *
     * @throws OrderPartRefusedException When a part is not in a shape the create takes (MALFORMED).
     *
     * @since  2.2.2
     */
    public static function read(array $data): array
    {
        $lines = $data['lines'] ?? [];

        if (!\is_array($lines) || !array_is_list($lines)) {
            throw new OrderPartRefusedException('/lines', 'must be a list of lines, each an object with id_item and quantity.', OrderPartRefusedException::MALFORMED);
        }

        $read = ['lines' => [], 'shipment' => null, 'payment' => null];

        foreach ($lines as $index => $line) {
            $at = '/lines/' . $index;
            $line = self::object(value: $line, at: $at, keys: self::LINE_KEYS);
            $combination = $line['id_combination'] ?? null;

            if ($combination !== null && $combination !== 0 && self::id($combination) === 0) {
                throw new OrderPartRefusedException($at . '/id_combination', 'must be the id of one of the product\'s combinations.', OrderPartRefusedException::MALFORMED);
            }

            try {
                $pricing = LinePricing::fromPayload(payload: $line);
            } catch (InvalidArgumentException $e) {
                throw new OrderPartRefusedException($at, $e->getMessage(), OrderPartRefusedException::MALFORMED);
            }

            $read['lines'][] = [
                'id_item' => self::requiredId(part: $line, key: 'id_item', at: $at, what: 'a product'),
                'id_combination' => $combination === null ? 0 : self::id($combination),
                'quantity' => OrderModel::positiveQuantity($line['quantity'] ?? null)
                    ?? throw new OrderPartRefusedException($at . '/quantity', 'must be a whole number of 1 or more.', OrderPartRefusedException::MALFORMED),
                'pricing' => $pricing,
            ];
        }

        if (($data['shipment'] ?? null) !== null) {
            $shipment = self::object(value: $data['shipment'], at: '/shipment', keys: self::SHIPMENT_KEYS);
            self::requiredId(part: $shipment, key: 'id_shipment_method', at: '/shipment', what: 'a shipping method');

            if (\array_key_exists('shipping_tax_rate', $shipment)) {
                self::checked(at: '/shipment/shipping_tax_rate', read: static fn () => self::rate(value: $shipment['shipping_tax_rate'], field: 'shipping_tax_rate'));
            }

            foreach (['tracking_number', 'carrier'] as $key) {
                if (($shipment[$key] ?? null) !== null && !\is_string($shipment[$key])) {
                    throw new OrderPartRefusedException('/shipment/' . $key, 'must be text.', OrderPartRefusedException::MALFORMED);
                }
            }

            $read['shipment'] = $shipment;
        }

        if (($data['payment'] ?? null) !== null) {
            $payment = self::object(value: $data['payment'], at: '/payment', keys: self::PAYMENT_KEYS);
            self::requiredId(part: $payment, key: 'id_payment_method', at: '/payment', what: 'a payment method');

            if (($payment['amount'] ?? null) === null) {
                throw new OrderPartRefusedException('/payment/amount', 'is required: the amount paid, or to be paid, greater than zero.', OrderPartRefusedException::MALFORMED);
            }

            $status = $payment['transaction_status'] ?? PaymentLedger::STATUS_PENDING;

            if (!\in_array($status, self::PAYMENT_STATUSES, true)) {
                throw new OrderPartRefusedException('/payment/transaction_status', 'must be ' . implode(' or ', self::PAYMENT_STATUSES)
                    . ': a payment is created with its order still to be paid or paid; it is settled or refunded through the order operations.', OrderPartRefusedException::MALFORMED);
            }

            if (($payment['transaction_id'] ?? null) !== null && !\is_string($payment['transaction_id'])) {
                throw new OrderPartRefusedException('/payment/transaction_id', 'must be text.', OrderPartRefusedException::MALFORMED);
            }

            $read['payment'] = ['transaction_status' => $status] + $payment;
        }

        return $read;
    }

    /**
     * Whether a create's data carries a part.
     *
     * @param array $data The create's data.
     *
     * @since  2.2.2
     */
    public static function named(array $data): bool
    {
        return ($data['lines'] ?? []) !== [] || ($data['shipment'] ?? null) !== null || ($data['payment'] ?? null) !== null;
    }

    /**
     * A line's data for the line writer (saveOrderItem()), with the order it is added to.
     *
     * @param array $line A line as read() answered it.
     * @param int $orderId The order.
     *
     * @return array{id: int, id_order: int, id_item: int, quantity: int, id_combination: int}
     *
     * @since  2.2.2
     */
    public static function lineData(array $line, int $orderId): array
    {
        return [
            'id' => 0,
            'id_order' => $orderId,
            'id_item' => (int) $line['id_item'],
            'quantity' => (int) $line['quantity'],
            'id_combination' => (int) $line['id_combination'],
        ];
    }

    /**
     * The parcel's data for the shipments ledger (saveOrderShipment()), its charge read in the order's money.
     *
     * @param array $shipment The parcel as read() answered it.
     * @param int $orderId The order, written in this transaction.
     *
     * @throws OrderPartRefusedException When a figure is not an amount of the order's money (MALFORMED).
     *
     * @since  2.2.2
     */
    public static function shipmentData(array $shipment, int $orderId): array
    {
        $data = [
            'id_order' => $orderId,
            'id_shipment_method' => (int) self::id($shipment['id_shipment_method']),
            'tracking_number' => (string) ($shipment['tracking_number'] ?? ''),
            'status' => ShipmentLedger::STATUS_PENDING,
        ];

        // The carrier company's name is the parcel's carrier_name, as on POST v1/alfa/orders/:id/shipments.
        if (($shipment['carrier'] ?? null) !== null) {
            $data['carrier_name'] = (string) $shipment['carrier'];
        }

        $currency = OrderCurrency::of(orderId: $orderId);

        foreach (['shipping_cost_tax_incl', 'shipping_cost_tax_excl'] as $key) {
            if (($shipment[$key] ?? null) !== null) {
                $data[$key] = self::checked(at: '/shipment/' . $key, read: static function () use ($shipment, $key, $currency): Money {
                    $cost = self::amount(value: $shipment[$key], field: $key, currency: $currency);

                    if ($cost->isNegative()) {
                        throw new InvalidArgumentException($key . ' must be zero or more');
                    }

                    return $cost;
                });
            }
        }

        if (($shipment['shipping_tax_rate'] ?? null) !== null) {
            $data['shipping_tax_rate'] = self::rate(value: $shipment['shipping_tax_rate'], field: 'shipping_tax_rate');
        }

        return $data;
    }

    /**
     * The payment's data for the payments ledger (saveOrderPayment()), its amount read in the order's money.
     *
     * @param array $payment The payment as read() answered it.
     * @param int $orderId The order, written in this transaction.
     *
     * @throws OrderPartRefusedException When the amount is not an amount of the order's money above zero (MALFORMED).
     *
     * @since  2.2.2
     */
    public static function paymentData(array $payment, int $orderId): array
    {
        $currency = OrderCurrency::of(orderId: $orderId);
        $amount = self::checked(at: '/payment/amount', read: static function () use ($payment, $currency): Money {
            $amount = self::amount(value: $payment['amount'], field: 'amount', currency: $currency);

            if (!$amount->isPositive()) {
                throw new InvalidArgumentException('amount must be greater than zero');
            }

            return $amount;
        });

        return [
            'id_order' => $orderId,
            'amount' => $amount,
            'id_payment_method' => (int) self::id($payment['id_payment_method']),
            'payment_type' => PaymentLedger::TYPE_PAYMENT,
            'transaction_status' => (string) $payment['transaction_status'],
            'transaction_id' => ($payment['transaction_id'] ?? null) !== null ? (string) $payment['transaction_id'] : null,
            'note' => '',
        ];
    }

    /**
     * A money figure sent by a client, as the order's Money on its own digits: the rule of every amount the order
     * operations write. A text as written, never through a float; an integer as itself; a JSON number at the digits
     * Money reads one at. A figure finer than the currency, or larger than its column or Money holds, is refused by
     * name: it is never rounded or cut. Canonical ungrouped decimals only, and not trimmed.
     *
     * @param mixed $value The value sent, known to be present.
     * @param string $field The key, for the refusal.
     * @param Currency $currency The order's currency at its frozen scale.
     *
     * @throws InvalidArgumentException When the value is not an amount of this money; the message names the field.
     *
     * @since  2.2.2
     */
    public static function amount(mixed $value, string $field, Currency $currency): Money
    {
        if (!\is_int($value) && !\is_float($value) && !\is_string($value)) {
            throw new InvalidArgumentException($field . ' must be a number');
        }

        try {
            return ExactMoney::of(value: $value, currency: $currency);
        } catch (ExactMoneyRefusal $e) {
            throw new InvalidArgumentException(
                match ($e->reason()) {
                    ExactMoney::FINER => sprintf('%s has more decimal places than %s allows (%d)', $field, $currency->getCode(), $currency->getDecimalPlaces()),
                    ExactMoney::LARGER => sprintf('%s is larger than an amount of %s holds', $field, $currency->getCode()),
                    ExactMoney::NOT_EXACT => sprintf('%s is a JSON number that does not carry its digits exactly: send the amount as a string', $field),
                    default => $field . ' must be a decimal number',
                },
                0,
                $e,
            );
        }
    }

    /**
     * A parcel's VAT rate sent by a client: a percentage of zero or more with at most three decimal places, as the
     * column holds it. A JSON number is read at the digits it carries; text as written, never rounded.
     *
     * @param mixed $value The value sent, known to be present.
     * @param string $field The key, for the refusal.
     *
     * @return string The rate's decimal text.
     *
     * @throws InvalidArgumentException When the value is not such a percentage; the message names the field.
     *
     * @since  2.2.2
     */
    public static function rate(mixed $value, string $field): string
    {
        $text = \is_float($value) && is_finite($value) ? ExactAmount::ofNumber($value)->text() : $value;

        if ((!\is_string($text) && !\is_int($text)) || preg_match('/^\d+(?:\.\d+)?$/D', (string) $text) !== 1
            || StoredDecimal::refusal(value: ExactAmount::of((string) $text), precision: 10, scale: 3) !== null) {
            throw new InvalidArgumentException($field . ' must be a percentage of zero or more with at most three decimal places');
        }

        return (string) $text;
    }

    /**
     * A part's object, refused when it is not one or carries a key the part does not take.
     *
     * @param mixed $value The part as sent.
     * @param string $at Its pointer.
     * @param string[] $keys The keys it takes.
     *
     * @throws OrderPartRefusedException When it is not an object of those keys.
     *
     * @since  2.2.2
     */
    private static function object(mixed $value, string $at, array $keys): array
    {
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new OrderPartRefusedException($at, 'must be an object of ' . implode(', ', $keys) . '.', OrderPartRefusedException::MALFORMED);
        }

        foreach (array_keys($value) as $key) {
            if (!\in_array($key, $keys, true)) {
                throw new OrderPartRefusedException(
                    $at . '/' . self::pointerSegment(key: (string) $key),
                    'is not a key of this part, which takes ' . implode(', ', $keys) . '.',
                    OrderPartRefusedException::MALFORMED,
                );
            }
        }

        return $value;
    }

    /**
     * The id a part must name, refused when it is not a positive whole number.
     *
     * @param array $part The part.
     * @param string $key The key.
     * @param string $at The part's pointer.
     * @param string $what What the id names, for the refusal.
     *
     * @throws OrderPartRefusedException When it is missing or not an id.
     *
     * @since  2.2.2
     */
    private static function requiredId(array $part, string $key, string $at, string $what): int
    {
        $id = self::id($part[$key] ?? null);

        if ($id === 0) {
            throw new OrderPartRefusedException($at . '/' . $key, 'is required: the id of ' . $what . '.', OrderPartRefusedException::MALFORMED);
        }

        return $id;
    }

    /**
     * A positive whole number sent as an integer or as its digits, or 0.
     *
     * @param mixed $value The value.
     *
     * @since  2.2.2
     */
    private static function id(mixed $value): int
    {
        if (\is_int($value)) {
            return max(0, $value);
        }

        return \is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1 && (int) $value <= 2147483647 ? (int) $value : 0;
    }

    /**
     * Run a reader whose refusal is an InvalidArgumentException, and name the part when it refuses.
     *
     * @param string $at The pointer of the value read.
     * @param callable $read The reader.
     *
     * @throws OrderPartRefusedException When the reader refuses (MALFORMED).
     *
     * @since  2.2.2
     */
    private static function checked(string $at, callable $read): mixed
    {
        try {
            return $read();
        } catch (InvalidArgumentException $e) {
            throw new OrderPartRefusedException($at, $e->getMessage(), OrderPartRefusedException::MALFORMED);
        }
    }

    /**
     * A key the client sent, as a segment of a JSON pointer: ~ and / escaped (RFC 6901), at most its first
     * KEY_SHOWN characters.
     *
     * @param string $key The key.
     *
     * @since  2.2.2
     */
    private static function pointerSegment(string $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], mb_substr($key, 0, self::KEY_SHOWN, 'UTF-8'));
    }
}
