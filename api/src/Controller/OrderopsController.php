<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Model\OrderModel as AdminOrderModel;
use Alfa\Component\Alfa\Administrator\Service\Api\ApiTaskAccess;
use Alfa\Component\Alfa\Administrator\Service\Order\LinePricing;
use Alfa\Component\Alfa\Administrator\Service\Order\ManualDiscountScope;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCreateParts;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger;
use Alfa\Component\Alfa\Administrator\Service\Order\StaffSettleResult;
use Alfa\Component\Alfa\Administrator\Service\Order\Transition;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\CustomPriceCommand;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoney;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\Exception\ResourceNotFound;
use Joomla\CMS\MVC\Controller\Exception\Save;
use Joomla\Database\ParameterType;
use RuntimeException;
use Throwable;

/**
 * Operations on a placed order: lines, payments and shipments.
 *
 * These are VERBS on sub-resources, deliberately not a fat PATCH of the order.
 * Everything an order owns is guarded by an invariant that a column write would
 * walk straight past:
 *
 *   - lines      -> OrderModel::saveOrderItem() prices the product in the
 *                  ORDER's context and reconciles stock; a raw insert would
 *                  leave inventory wrong and the totals stale.
 *   - payments   -> PaymentLedger locks the order FOR UPDATE and enforces the
 *                  overpayment and over-refund caps. The lock is the only
 *                  thing stopping two concurrent writers both spending the
 *                  same free pool.
 *   - shipments  -> ShipmentLedger owns the per-parcel quantities in
 *                  #__alfa_order_shipment_items.
 *
 * Every verb answers with the FULL updated order, so a client sees the
 * recomputed totals and payment/fulfilment status without a second request.
 *
 * @since  2.0.0
 */
class OrderopsController extends OrdersController
{
    /**
     * The one task on this controller that moves the order between statuses.
     *
     * @since  2.0.0
     */
    private const STATUS_TASK = 'changeStatus';

    /**
     * What a payment's change takes: its method, the provider's reference and the note, as the payment window changes
     * them; its amount and status move only through the settle and the refund.
     *
     * @since  2.2.2
     */
    private const PAYMENT_CHANGES = ['id_payment_method', 'transaction_id', 'note'];

    /**
     * Every operation here mutates an existing order, so they gate on the order
     * EDIT permission rather than create/delete, except moving the order between
     * statuses, which asks Change State instead of edit, plus view (declared
     * here, enforced by HasApiGuards::assertAuthorisedForTask() before the task
     * runs, listed by Tools, Security).
     *
     * WHY THE STATUS TASK IS NOT ORDINARY EDITING. Entering a status runs stock
     * movements, issues a sales document against a GAPLESS series, emails the
     * customer, grants or revokes download entitlements and dispatches
     * onOrderStatusChange to every listening plugin. The component already
     * treats that as a separate permission everywhere a human does it: the
     * orders list renders a read-only badge without alfa.orders.edit.state, and
     * OrderModel refuses the change without it. This endpoint is the same act
     * through a different door, and it was reachable with ordinary edit rights.
     * The state permission is the one the administrator path enforces
     * (OrderModel::STATUS_CHANGE_ACTION names it), so one operator is never told
     * yes on one door and no on the other.
     *
     * WHY IT ALSO ASKS VIEW. The status task answers with the full order body
     * (customer, addresses, payments). In the admin the status select lives on
     * the orders list, which opens only with orders view, so edit.state alone
     * must not become a way to read orders.
     *
     * HasApiGuards::execute() enforces it BEFORE dispatching the task, so an
     * unauthorised caller is refused before the target status is read, before
     * force is considered, and before any Transition exists to have a side
     * effect. A Joomla Super User still passes, because the one Alfa check lets
     * a Super User through every action.
     *
     * @param string $task The task.
     *
     * @since  2.0.0
     */
    public function taskAccess(string $task): ApiTaskAccess
    {
        // The same split as the admin's orders list: view to see the order, edit.state to move it.
        if ($task === self::STATUS_TASK) {
            return ApiTaskAccess::asks($this->actionPermission(action: 'view'), AdminOrderModel::STATUS_CHANGE_ACTION);
        }

        return ApiTaskAccess::asks($this->actionPermission(action: 'edit'));
    }

    /**
     * Add a product line to an order.
     *
     * POST v1/alfa/orders/:id/lines
     * {id_item, quantity, id_combination?, custom_unit_price_tax_incl?, manual_discount_percent?, manual_discount_scope?}
     *
     * A new line is priced by today's canonical pipeline for its combination,
     * exactly as the order popup prices one. custom_unit_price_tax_incl is the
     * tri-state: omitted or null =
     * no override, a number (0 included) = that custom unit price. A variant
     * product needs its id_combination, and the quantity is required: neither
     * is guessed.
     *
     * A product with a required option cannot be added here: the body carries
     * no options, and a line missing a required option is refused (409) as
     * add-to-cart refuses it. A configuration payload is a separate API item.
     *
     * @return static
     *
     * @since  2.0.0
     */
    public function addLine()
    {
        $orderId = $this->requireOrderId();
        $body = $this->body();

        // The configuration's own refusals are worded in the storefront
        // catalogue (COM_ALFA_CART_INVALID_SELECTION has no admin twin); the
        // admin wording is re-applied over it, as the order popup does.
        $language = $this->app->getLanguage();
        $language->load('com_alfa', JPATH_SITE);
        $language->load('com_alfa', JPATH_ADMINISTRATOR, null, true);

        if ((int) ($body['id_item'] ?? 0) <= 0) {
            throw new Save('id_item is required', 400);
        }

        if (!\array_key_exists('quantity', $body)) {
            throw new Save('quantity is required: a whole number of 1 or more', 400);
        }

        $data = [
            'id' => 0,
            'id_order' => $orderId,
            'id_item' => (int) $body['id_item'],
            'quantity' => $this->lineQuantity($body['quantity']),
            'id_combination' => (int) ($body['id_combination'] ?? 0),
        ];

        $this->runOrderWrite('saveOrderItem', $data, $this->linePricing(body: $body));

        return $this->displayItem($orderId);
    }

    /**
     * Change a line's quantity, price or discount.
     *
     * PATCH v1/alfa/orders/:id/lines/:lineId
     * {quantity?, custom_unit_price_tax_incl?, manual_discount_percent?, manual_discount_scope?}
     *
     * The SAME contract as the admin popup (API parity), so the
     * stored row is identical whichever door the operation came through:
     *
     *   custom_unit_price_tax_incl omitted  Leave unchanged: the line keeps its
     *                                       commercial terms, and a quantity
     *                                       change rescales its own whole-line
     *                                       money
     *   custom_unit_price_tax_incl null     Clear: the override goes and the
     *                                       line is repriced canonically
     *   custom_unit_price_tax_incl number   Set: that custom unit price
     *
     *   manual_discount_percent omitted     the line's manual discount stands
     *   manual_discount_percent 0-100       the line's manual discount becomes
     *                                       that (0 clears it); the same value
     *                                       sent again changes nothing
     *   manual_discount_scope omitted       the line's choice stands
     *   manual_discount_scope value         exclude_custom_price or
     *                                       apply_to_custom_price: whether the
     *                                       manual discount reduces the line's
     *                                       custom price (the popup's tick)
     *
     * The product itself cannot be swapped; the admin refuses it too, because
     * the line carries a frozen snapshot of what was actually sold.
     *
     * @return static
     *
     * @since  2.0.0
     */
    public function updateLine()
    {
        $orderId = $this->requireOrderId();
        $line = $this->requireOwnedLine($orderId);
        $body = $this->body();

        if (isset($body['id_item']) && (int) $body['id_item'] !== (int) $line->id_item) {
            throw new Save('A line\'s product cannot be changed; remove the line and add the new product', 409);
        }

        // An omitted quantity keeps the line's own; a sent one must be a
        // quantity, never coerced into one.
        $data = [
            'id' => (int) $line->id,
            'id_order' => $orderId,
            'id_item' => (int) $line->id_item,
            'quantity' => \array_key_exists('quantity', $body) ? $this->lineQuantity($body['quantity']) : (int) $line->quantity,
        ];

        $this->runOrderWrite('saveOrderItem', $data, $this->linePricing(body: $body));

        return $this->displayItem($orderId);
    }

    /**
     * A line quantity from the body, or a 400 naming it.
     *
     * The writer's own rule ({@see AdminOrderModel::positiveQuantity()}): a
     * whole number of 1 or more. 0, -5, "abc", true, 2.9 and "2.9" are
     * refused, never coerced: (int) "abc" is 0, and max(1, 0) used to be a
     * one-unit line nobody asked for.
     *
     * @param mixed $value The body's quantity.
     *
     *
     * @throws Save 400 when the value is not a quantity.
     *
     * @since   2.0.0
     */
    private function lineQuantity(mixed $value): int
    {
        $quantity = AdminOrderModel::positiveQuantity($value);

        if ($quantity === null) {
            throw new Save('quantity must be a whole number of 1 or more', 400);
        }

        return $quantity;
    }

    /**
     * The pricing instructions of a line body, validated before anything is written: the line's own reader
     * ({@see LinePricing::fromPayload()}), its refusal answered 400 with the sentence that names the field.
     *
     * @param array $body The decoded request body.
     *
     * @return array{price: CustomPriceCommand, manualDiscountPercent: ?float, manualDiscountScope: ?ManualDiscountScope}
     *
     * @throws Save 400 on an input that is not a usable instruction.
     *
     * @since   2.0.0
     */
    private function linePricing(array $body): array
    {
        try {
            return LinePricing::fromPayload(payload: $body);
        } catch (InvalidArgumentException $e) {
            throw new Save(ErrorText::of($e), 400);
        }
    }

    /**
     * Remove a line from an order.
     *
     * DELETE v1/alfa/orders/:id/lines/:lineId
     *
     * @return static
     *
     * @since  2.0.0
     */
    public function removeLine()
    {
        $orderId = $this->requireOrderId();
        $lineId = (int) $this->requireOwnedLine($orderId)->id;
        $model = $this->orderModel();

        if (!$model->deleteOrderItem($lineId, $orderId)) {
            throw new Save($this->modelError($model, 'The line could not be removed'), 409);
        }

        return $this->displayItem($orderId);
    }

    /**
     * Record a payment against an order.
     *
     * POST v1/alfa/orders/:id/payments
     * {amount, id_payment_method?, transaction_status?, payment_type?, transaction_id?, note?}
     *
     * Goes through PaymentLedger, which caps the amount at the order's
     * outstanding balance under a row lock. A refund is NOT created here; use
     * the refund verb, which links it to the payment it reverses.
     *
     * @return static
     *
     * @since  2.0.0
     */
    public function addPayment()
    {
        $orderId = $this->requireOrderId();
        $body = $this->body();

        if (!isset($body['amount'])) {
            throw new Save('amount is required and must be greater than zero', 400);
        }

        // The order's Money on the text's own digits: the ledger writes them.
        $amount = $this->exactAmount(value: $body['amount'], field: 'amount', currency: OrderCurrency::of(orderId: $orderId));

        if (!$amount->isPositive()) {
            throw new Save('amount is required and must be greater than zero', 400);
        }

        $data = [
            'id_order' => $orderId,
            'amount' => $amount,
            'id_payment_method' => (int) ($body['id_payment_method'] ?? 0),
            'payment_type' => (string) ($body['payment_type'] ?? 'payment'),
            'transaction_status' => (string) ($body['transaction_status'] ?? 'pending'),
            'transaction_id' => $body['transaction_id'] ?? null,
            'note' => $body['note'] ?? '',
        ];

        if ($data['payment_type'] === 'refund') {
            throw new Save('Use POST /orders/:id/payments/:paymentId/refund to return money', 409);
        }

        if (\in_array($data['payment_type'], PaymentLedger::FACT_TYPES, true)) {
            throw new Save('A reversal or chargeback is recorded from the provider\'s report, never added as a payment', 409);
        }

        $this->runOrderWrite('saveOrderPayment', $data);

        return $this->displayItem($orderId);
    }

    /**
     * Create a shipment / fulfil lines.
     *
     * POST v1/alfa/orders/:id/shipments
     * {id_shipment_method, tracking_number?, carrier?, status?, shipping_cost_tax_incl?, shipping_cost_tax_excl?,
     *  shipping_tax_rate?, items?: [{id_order_item, quantity}]}
     *
     * `carrier` is the carrier company's name (the parcel's carrier_name). A parcel keeps no note: a `note` is refused
     * (422).
     *
     * A parcel with no charge (no figures, or both at 0.00) states no rate: it takes the shipping tax that applies.
     * The order is charged shipping once: a second open parcel's charge is set to 0 and meta.warnings says
     * so (shipping_charged_once), and a quantity beyond what the line still has to send is cut to it and said
     * (quantity_cut).
     *
     * The charge: shipping_cost_tax_incl alone is an untaxed charge (the net is
     * the gross, the rate 0). A taxed charge sends all three; the VAT rate is a
     * percentage of at most three decimal places, best sent as a string ("24").
     * A rate without the net, or a taxed charge without a rate, is refused. The
     * tax is the shipping tax that applies to the order (resolved as checkout
     * resolves it): the rate sent must be exactly its combined rate, and a
     * charge sent untaxed stands only where none applies. The charge is read
     * under its method's entry mode, which the parcel keeps with its ordered
     * rates, and must be exactly what its input makes through them: with tax
     * included the gross is the input (10.00 at 24% is 8.06 + 1.94, so a net
     * of 8.07 is refused), with tax excluded the net is. How a charge was
     * formed is never an input: a shipping_charge_basis in the body is not read.
     *
     * @return static
     *
     * @since  2.0.0
     */
    public function addShipment()
    {
        $orderId = $this->requireOrderId();
        $body = $this->body();
        $this->refuseParcelNote(body: $body);

        $data = [
            'id_order' => $orderId,
            'id_shipment_method' => (int) ($body['id_shipment_method'] ?? 0),
            'tracking_number' => $body['tracking_number'] ?? '',
            'status' => (string) ($body['status'] ?? 'pending'),
        ];

        // The carrier company's name is the parcel's carrier_name.
        if (isset($body['carrier'])) {
            $data['carrier_name'] = (string) $body['carrier'];
        }

        foreach (['shipping_cost_tax_incl', 'shipping_cost_tax_excl'] as $key) {
            if (isset($body[$key])) {
                $data[$key] = $this->parcelCost(value: $body[$key], field: $key, currency: OrderCurrency::of(orderId: $orderId));
            }
        }

        if (isset($body['shipping_tax_rate'])) {
            $data['shipping_tax_rate'] = $this->parcelRate(value: $body['shipping_tax_rate']);
        }

        if (isset($body['weight'])) {
            $data['weight'] = (float) $body['weight'];
        }

        if (isset($body['items']) && \is_array($body['items'])) {
            $data['items'] = $body['items'];
        }

        $this->runOrderWrite('saveOrderShipment', $data);

        return $this->displayItem($orderId);
    }

    /**
     * Update a shipment: status, tracking, carrier, cost or composition.
     *
     * PATCH v1/alfa/orders/:id/shipments/:shipmentId
     * {status?, tracking_number?, carrier?, id_shipment_method?, weight?, shipping_cost_tax_incl?, shipping_cost_tax_excl?,
     *  shipping_tax_rate?, items?}
     *
     * `carrier` writes the parcel's carrier_name; a `note` is refused (422), a parcel keeps none.
     *
     * The charge follows the rules of addShipment(), on the entry mode the
     * parcel kept: a figure the body does not carry is the parcel's own, and the
     * whole charge the parcel will hold is judged through the ordered rates it
     * froze; a figure sent as 0 is 0, and its rate cannot change. A change of a
     * carrier's stated charge is judged on its method's entry mode, which the
     * parcel then keeps; a PATCH that changes nothing in the charge leaves it
     * as it stands.
     *
     * Setting status to "cancelled" is how a parcel is cancelled: it fires the
     * shipment method's status_on_cancelled map, which can move the ORDER's
     * status, release stock and mail the customer. Cancelling also drops the
     * parcel's shipping cost out of the order total, which shrinks the
     * overpayment cap: a payment that fitted before may not fit after.
     *
     * `items` is deliberately three-valued: absent leaves the parcel
     * composition untouched, [] empties it, and a list replaces it. The admin
     * form posts [] whenever its multi-select is empty, which is an HTML
     * workaround, not the contract; copying it here would erase a parcel's
     * contents on a tracking-number edit.
     *
     * @return static
     *
     * @since  2.0.0
     */
    public function updateShipment()
    {
        $orderId = $this->requireOrderId();
        $shipment = $this->requireOwnedShipment($orderId);
        $body = $this->body();
        $this->refuseParcelNote(body: $body);

        $data = [
            'id' => (int) $shipment->id,
            'id_order' => $orderId,
        ];

        foreach (['status', 'tracking_number'] as $key) {
            if (isset($body[$key])) {
                $data[$key] = (string) $body[$key];
            }
        }

        // The carrier company's name is the parcel's carrier_name.
        if (isset($body['carrier'])) {
            $data['carrier_name'] = (string) $body['carrier'];
        }

        foreach (['shipping_cost_tax_incl', 'shipping_cost_tax_excl'] as $key) {
            if (isset($body[$key])) {
                $data[$key] = $this->parcelCost(value: $body[$key], field: $key, currency: OrderCurrency::of(orderId: $orderId));
            }
        }

        if (isset($body['shipping_tax_rate'])) {
            $data['shipping_tax_rate'] = $this->parcelRate(value: $body['shipping_tax_rate']);
        }

        if (isset($body['weight'])) {
            $data['weight'] = (float) $body['weight'];
        }

        if (isset($body['id_shipment_method'])) {
            $data['id_shipment_method'] = (int) $body['id_shipment_method'];
        }

        // Only forwarded when the caller actually said something about it.
        if (\array_key_exists('items', $body) && \is_array($body['items'])) {
            $data['items'] = $body['items'];
        }

        $this->runOrderWrite('saveOrderShipment', $data);

        return $this->displayItem($orderId);
    }

    /**
     * Answer 422 naming note when a parcel's write carries one: a parcel keeps no note (#__alfa_order_shipments
     * has no column for one, and its plugin data is the carrier plugin's), so a note sent was dropped with a 200. An
     * empty note asks nothing and is let through.
     *
     * @param array $body The request body.
     *
     * @throws Save When the body carries a note (422).
     *
     * @since  2.2.2
     */
    private function refuseParcelNote(array $body): void
    {
        if (($body['note'] ?? null) !== null && $body['note'] !== '') {
            throw new Save('note cannot be written through the API: a parcel keeps no note. Write the order\'s note to the'
                . ' customer through PATCH v1/alfa/orders/:id (customer_note), or a status change\'s note through POST'
                . ' v1/alfa/orders/:id/status. Nothing was saved.', 422);
        }
    }

    /**
     * Change a payment, as the payment window of the order screen changes one.
     *
     * PATCH v1/alfa/orders/:id/payments/:paymentId
     * {id_payment_method?, transaction_id?, note?}
     *
     * Written by the order model's saveOrderPayment(), the screen's own save, with its rules: the staff guard keeps the
     * method and the provider's transaction id of a payment its gateway collected, a payment that live refunds or
     * provider facts name keeps its method, and the order's payment method follows its latest live payment. The amount
     * and the status are not changed here: a payment is settled with POST .../settle and money is returned with POST
     * .../refund; any other key is refused with 422. A refusal answers 409 with the ledger's sentence.
     *
     * @return static
     *
     * @since  2.2.2
     */
    public function updatePayment()
    {
        $orderId = $this->requireOrderId();
        $payment = $this->requireOwnedPayment($orderId);
        $body = $this->body();
        $other = array_values(array_diff(array_map('strval', array_keys($body)), self::PAYMENT_CHANGES));

        if ($other !== []) {
            throw new Save(implode(', ', array_map(fn (string $key): string => $this->shownKey(key: $key), $other))
                . ' cannot be changed on a payment: send id_payment_method, transaction_id or note. A payment is settled with'
                . ' POST v1/alfa/orders/:id/payments/:paymentId/settle and money is returned with .../refund. Nothing was saved.', 422);
        }

        $data = ['id' => (int) $payment->id, 'id_order' => $orderId];

        if (\array_key_exists('id_payment_method', $body)) {
            $data['id_payment_method'] = $this->paymentMethodId(value: $body['id_payment_method']);
        }

        foreach (['transaction_id', 'note'] as $key) {
            if (\array_key_exists($key, $body)) {
                if ($body[$key] !== null && !\is_string($body[$key])) {
                    throw new Save($key . ' must be text. Nothing was saved.', 422);
                }

                $data[$key] = (string) $body[$key];
            }
        }

        $this->runOrderWrite('saveOrderPayment', $data);

        return $this->displayItem($orderId);
    }

    /**
     * Remove a payment, as the order screen removes one.
     *
     * DELETE v1/alfa/orders/:id/payments/:paymentId
     *
     * Through the order model's deleteOrderPayment(), the screen's own delete: only a payment that moved no money goes
     * (pending, authorized, failed or cancelled); a completed or refunded payment, a refund that reserves its amount, a
     * provider's report and a payment one of them names are refused with 409 and the ledger's sentence. The order's
     * history records the removal, and the answer is the order as it now stands.
     *
     * @return static
     *
     * @since  2.2.2
     */
    public function removePayment()
    {
        $orderId = $this->requireOrderId();
        $payment = $this->requireOwnedPayment($orderId);
        $this->runOrderRemoval('deleteOrderPayment', (int) $payment->id, $orderId);

        return $this->displayItem($orderId);
    }

    /**
     * Remove a parcel, as the order screen removes one.
     *
     * DELETE v1/alfa/orders/:id/shipments/:shipmentId
     *
     * Through the order model's deleteOrderShipment(), the screen's own delete: the parcel's lines are released, so
     * they may go in another parcel, and the order's history records the removal; the order's shipping method follows
     * its latest live parcel. The carrier is not asked: a parcel booked with a carrier is removed from the order only.
     * The answer is the order as it now stands.
     *
     * @return static
     *
     * @since  2.2.2
     */
    public function removeShipment()
    {
        $orderId = $this->requireOrderId();
        $shipment = $this->requireOwnedShipment($orderId);
        $this->runOrderRemoval('deleteOrderShipment', (int) $shipment->id, $orderId);

        return $this->displayItem($orderId);
    }

    /**
     * A payment method's id from the body: a whole number that names a payment method of the shop.
     *
     * @param mixed $value The body's id_payment_method.
     *
     * @throws Save When it names no payment method (422).
     *
     * @since  2.2.2
     */
    private function paymentMethodId(mixed $value): int
    {
        $id = \is_int($value) ? $value : (\is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1 ? (int) $value : 0);
        $db = Factory::getContainer()->get('DatabaseDriver');
        $found = $id > 0 && $id <= 2147483647 && (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_payments'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER),
        )->loadResult() > 0;

        if (!$found) {
            throw new Save('id_payment_method must be the id of a payment method of the shop. Nothing was saved.', 422);
        }

        return $id;
    }

    /**
     * Run a removal on the order model, a refusal answered as runOrderWrite() answers one: 409 with the model's error,
     * else the sentence the ledger told staff.
     *
     * @param string $method deleteOrderPayment or deleteOrderShipment.
     * @param int $rowId The payment or the parcel.
     * @param int $orderId The order it belongs to.
     *
     * @throws Save When the model refuses the removal (409).
     *
     * @since  2.2.2
     */
    private function runOrderRemoval(string $method, int $rowId, int $orderId): void
    {
        $model = $this->orderModel();
        $told = \count($this->app->getMessageQueue());

        if (!$model->$method($rowId, $orderId)) {
            throw new Save($this->modelError($model, PaymentLedger::queuedRefusal(app: $this->app, told: $told) ?: 'The order could not be updated'), 409);
        }
    }

    /**
     * Return money against a payment.
     *
     * POST v1/alfa/orders/:id/payments/:paymentId/refund
     * {amount?, reason?, id_document?}
     *
     * Omitting the amount (or sending null) refunds the whole remaining
     * refundable amount of that payment. Sending 0 is a 400: a zero refund
     * does not exist, and a zero never means "everything".
     *
     * RECORDS money only; it calls no gateway. Move the money at the provider
     * first, then post it here. While a provider has reported a refund on this
     * order that the books do not hold, no refund is recorded here: 409 with the
     * reason (PaymentLedger::refundStartRefusal()). Nor is one beyond what the
     * order may still refund once its pending provider refunds are reserved: the
     * refusal names them (PaymentLedger::refundableNow()).
     *
     * @return static
     *
     * @since  2.0.0
     */
    public function refundPayment()
    {
        $orderId = $this->requireOrderId();
        $payment = $this->requireOwnedPayment($orderId);

        $body = $this->body();

        // The audit row inherits the original's method, and create() only
        // snapshots the name when that id is non-zero. payment_method is NOT
        // NULL with no default, so a method-less original fails the INSERT
        // AFTER the parent has been flipped, bouncing the order's status.
        if ((int) $payment->id_payment_method <= 0) {
            throw new Save(
                sprintf('Payment %d carries no payment method; set one before refunding', (int) $payment->id),
                409,
            );
        }

        $remainder = PaymentLedger::refundableRemainder((int) $payment->id);
        $amount = null;

        // ONLY an absent (or explicitly null) amount means "everything left".
        // A zero is a validation error, not a full refund: a monetary zero must
        // never stand for "no amount was supplied".
        if (\array_key_exists('amount', $body) && $body['amount'] !== null) {
            $amount = $this->refundAmount($body['amount'], $remainder);
        }

        // refund_type is DERIVED from the amount, and "full" is what flips the
        // original to refunded, which cascades into the order status, the
        // customer email, stock release and download revocation. Accepting a
        // contradicting label silently would be the worst bug this endpoint
        // could have, so it is an assertion the caller has to get right.
        if (isset($body['refund_type'])) {
            // The ledger's own rule: full only when refunds alone cover the
            // payment, so a chargeback never makes one full.
            $implied = PaymentLedger::refundTypeFor(paymentId: (int) $payment->id, amount: $amount);

            if ((string) $body['refund_type'] !== $implied) {
                throw new Save(
                    sprintf('refund_type is derived from the amount; this refund is "%s"', $implied),
                    409,
                );
            }
        }

        $result = PaymentLedger::refundPayment(
            (int) $payment->id,
            $amount,
            [
                // varchar(255); a longer reason is a strict-mode INSERT failure
                // that unwinds the parent flip with it.
                'reason' => mb_substr(trim((string) ($body['reason'] ?? '')), 0, 255),
                'document' => (int) ($body['id_document'] ?? 0),

                // False: this verb books, it never moves money. True would
                // suppress the compensating rollback.
                'gateway_moved' => false,
            ],
        );

        $this->assertRefundOutcome($result);

        return $this->displayItem($orderId);
    }

    /**
     * Move a payment to another transaction_status, exactly once.
     *
     * POST v1/alfa/orders/:id/payments/:paymentId/settle
     * {to_status, from_status?, transaction_id?, note?}
     *
     * Compare-and-set, because a gateway webhook and this verb reach the same
     * row concurrently; a plain update lets both through and fires the status
     * automation, the customer email and the entitlement grant twice.
     *
     * A REFUND row is the exception. This verb is staff's act (or an
     * integration's), never a provider confirming money that has already
     * moved, and the ledger's compare-and-set is the door of a payment
     * plugin's confirmation: a refund settled through it would be written
     * even against a voided credit note. So a refund is written through the
     * ledger's update(), the order screen's door, and judged as a settle
     * there is; a refusal answers 422 with the ledger's own sentence. A
     * refund row drives no status automation, mail or entitlement, which is
     * what the compare-and-set exists to keep from happening twice.
     *
     * The verb is a staff entry point: its write, by either door, is held to
     * the ledger's staff guard (PaymentLedger::staffWriteRefusal()).
     * A gateway payment's provider transaction id is not cleared or
     * changed here, and a gateway payment's refund is not moved into a
     * settled status: 422 with the ledger's sentence.
     *
     * While a provider has reported a refund on the order that the books do not
     * hold, a failed or cancelled refund is not brought back: 409 with the
     * block's reason (PaymentLedger::staffRefundRefusal()); nor beyond what the
     * order may still refund once its pending provider refunds are reserved:
     * 409 naming them (PaymentLedger::staffRefundReservedRefusal()).
     *
     * @return static
     *
     * @since  2.0.0
     */
    public function settlePayment()
    {
        $orderId = $this->requireOrderId();
        $payment = $this->requireOwnedPayment($orderId);
        $body = $this->body();

        // The settle's rules are the ledger's: this verb words its outcome in HTTP.
        $result = PaymentLedger::staffSettle(
            payment: $payment,
            orderId: $orderId,
            toStatus: (string) ($body['to_status'] ?? ''),
            fromStatus: (string) ($body['from_status'] ?? ''),
            transactionId: isset($body['transaction_id']) ? (string) $body['transaction_id'] : null,
            note: isset($body['note']) ? (string) $body['note'] : null,
        );

        if (!$result->settled()) {
            throw new Save($result->sentence, match ($result->outcome) {
                StaffSettleResult::UNKNOWN_STATUS => 400,
                StaffSettleResult::REFUSED => 422,
                default => 409,
            });
        }

        return $this->displayItem($orderId);
    }

    /**
     * Change the order's status through the transition funnel.
     *
     * POST v1/alfa/orders/:id/status
     * {id_order_status, note?, force?, silent?}
     *
     * Deliberately not a PATCH of the column: the fat save path always
     * force()s, so it reinstates cancelled orders with no client opt-in, and
     * it re-derives the invoice address on every write.
     *
     * A 200 here means the status changed, and everything the change owes
     * was written with it, in the same transaction, as tasks: the stock
     * step, the e-mails, document issuance and the plugin reactions. They
     * run once that transaction has committed, before this answer, and
     * meta.tasks lists each with the state it ended in. One that failed or
     * whose outcome is unknown waits for staff in Tools > Tasks; none is
     * lost when the request ends early, because a later tick recovers it.
     *
     * @return static
     *
     * @since  2.0.0
     */
    public function changeStatus()
    {
        $orderId = $this->requireOrderId();
        $body = $this->body();

        $toStatusId = (int) ($body['id_order_status'] ?? 0);

        if ($toStatusId <= 0) {
            throw new Save('id_order_status is required', 400);
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $current = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id_order_status', 'state']))
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $orderId, ParameterType::INTEGER),
        )->loadObject();

        if ($current === null) {
            throw new ResourceNotFound(Text::_('JLIB_APPLICATION_ERROR_RECORD'), 404);
        }

        // An order that names no currency is not moved. The transition
        // refuses it too; asked here so the answer carries the refusal's own
        // sentence, where a refused transition only says that it was refused.
        OrderCurrency::of(orderId: $orderId);

        // Transition never validates the target. An unknown id writes a
        // dangling FK, reads as stock_operation 0, emails nothing and issues
        // nothing: a silent half-transition that looks like a success.
        if (OrderStatusHelper::getById($toStatusId) === null) {
            throw new Save('Unknown id_order_status ' . $toStatusId, 422);
        }

        $fromStatusId = (int) $current->id_order_status;

        if ($fromStatusId === $toStatusId) {
            return $this->displayItem($orderId);
        }

        $forced = (bool) ($body['force'] ?? false);

        // The transition's own rule: leaving the cancelled family needs force().
        if (!$forced && Transition::leavesCancelledFamily(fromStatusId: $fromStatusId, toStatusId: $toStatusId)) {
            throw new Save('Order is in the cancelled family; send "force": true to reinstate it', 409);
        }

        $transition = Transition::for($orderId)
            ->to($toStatusId)
            ->byUser((int) $this->app->getIdentity()->id);

        if (isset($body['note'])) {
            $transition->note((string) $body['note']);
        }

        // Suppresses the customer status email ONLY: the staff the status
        // tells are still sent theirs. Stock, entitlements, document issuance, both activity
        // rows and the plugin event all still fire.
        if (!empty($body['silent'])) {
            $transition->silentCustomer();
        }

        if ($forced) {
            $transition->force();
        }

        if (!$transition->save()) {
            throw new Save(
                sprintf('The transition from status %d to %d was refused', $fromStatusId, $toStatusId),
                409,
            );
        }

        return $this->displayItem($orderId);
    }

    //  Helpers

    /**
     * Resolve the :paymentId route variable against the :id order.
     *
     * Neither the ledger's load() nor updateIfStatus() nor refundPayment()
     * scopes anything to a caller-supplied order; they all read id_order off
     * the row. Without this check /orders/999/payments/5/refund refunds order
     * 1's payment and answers with order 999.
     *
     * @param int $orderId The order the payment must belong to.
     *
     * @return object The payment row, with amount hydrated to Money.
     *
     * @since  2.0.0
     */
    private function requireOwnedPayment(int $orderId): object
    {
        $payment = PaymentLedger::get($this->requireSubId('paymentId'));

        if (!$payment || (int) $payment->id_order !== $orderId) {
            throw new ResourceNotFound(Text::_('JLIB_APPLICATION_ERROR_RECORD'), 404);
        }

        return $payment;
    }

    /**
     * Resolve the :lineId route variable against the :id order.
     *
     * saveOrderItem() loads an existing line by PK alone and writes the
     * id_order it is handed, so an unscoped call could re-point another
     * order's line into this one.
     *
     * @param int $orderId The order the line must belong to.
     *
     * @return object The order-item row.
     *
     * @since  2.0.0
     */
    private function requireOwnedLine(int $orderId): object
    {
        $lineId = $this->requireSubId('lineId');
        $db = Factory::getContainer()->get('DatabaseDriver');

        $row = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'id_order', 'id_item', 'id_combination', 'quantity', 'unit_price_tax_incl']))
                ->from($db->quoteName('#__alfa_order_items'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $lineId, ParameterType::INTEGER),
        )->loadObject();

        if (!$row || (int) $row->id_order !== $orderId) {
            throw new ResourceNotFound(Text::_('JLIB_APPLICATION_ERROR_RECORD'), 404);
        }

        return $row;
    }

    /**
     * Resolve the :shipmentId route variable against the :id order.
     *
     * ShipmentLedger::update() writes by PK and accepts an id_order, so an
     * unscoped call would move another order's parcel into this one.
     *
     * @param int $orderId The order the shipment must belong to.
     *
     * @return object The shipment row.
     *
     * @since  2.0.0
     */
    private function requireOwnedShipment(int $orderId): object
    {
        $shipmentId = $this->requireSubId('shipmentId');
        $db = Factory::getContainer()->get('DatabaseDriver');

        $row = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'id_order', 'status']))
                ->from($db->quoteName('#__alfa_order_shipments'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $shipmentId, ParameterType::INTEGER),
        )->loadObject();

        if (!$row || (int) $row->id_order !== $orderId) {
            throw new ResourceNotFound(Text::_('JLIB_APPLICATION_ERROR_RECORD'), 404);
        }

        return $row;
    }

    /**
     * Translate a refund verdict into an HTTP outcome.
     *
     * Collapsing these loses the distinction that matters most: an idempotent
     * replay is a success, while "the money moved but the books refused" must
     * never be presented as retryable.
     *
     * @param array $result The verdict from PaymentLedger::refundPayment().
     *
     *
     * @since  2.0.0
     */
    private function assertRefundOutcome(array $result): void
    {
        $outcome = (string) ($result['outcome'] ?? PaymentLedger::OUTCOME_FAILED);
        $reason = (string) ($result['reason'] ?? '');

        switch ($outcome) {
            case PaymentLedger::OUTCOME_OK:
            case PaymentLedger::OUTCOME_ALREADY_REFUNDED:
                return;

            case PaymentLedger::OUTCOME_RACED:
                throw new Save($reason ?: 'The payment was changed by another writer', 409);
                // The refund block: the provider has reported refunds on this order
                // that the books do not hold. They are recorded first.
            case PaymentLedger::OUTCOME_BLOCKED:
                throw new Save($reason, 409);
            case PaymentLedger::OUTCOME_NOT_REFUNDABLE:
            case PaymentLedger::OUTCOME_NOTHING_LEFT:
                throw new Save($reason ?: 'That payment cannot be refunded', 422);
                // The gateway moved the money and the ledger refused the record.
                // 5xx on purpose: a retry would refund a second time at the bank.
            case PaymentLedger::OUTCOME_UNRECONCILED:
                throw new Save($reason ?: 'Refund is unreconciled; reconcile manually', 500);
            default:
                throw new Save($reason ?: 'The refund was refused', 409);
        }
    }

    /**
     * Run a write on the order model and translate failure into an HTTP error.
     *
     * The refusal's sentence is the model's error when it set one (a line), else the sentence the payments or the
     * shipments ledger told staff about the write it refused (a second pending payment while one covers the
     * balance, a parcel's charge the tax rule refuses), read by PaymentLedger::queuedRefusal() as the settle reads
     * it; the generic sentence only when neither said why.
     *
     * @param string $method The model method to call.
     * @param array $data Data passed by reference to the model.
     * @param array $arguments Further arguments, by name (the line writer's pricing instructions).
     *
     *
     * @since  2.0.0
     */
    private function runOrderWrite(string $method, array $data, array $arguments = []): void
    {
        $model = $this->orderModel();
        $told = \count($this->app->getMessageQueue());

        if (!$model->$method($data, ...$arguments)) {
            throw new Save($this->modelError($model, PaymentLedger::queuedRefusal(app: $this->app, told: $told) ?: 'The order could not be updated'), 409);
        }
    }

    /**
     * The singular order model.
     *
     * @return \Joomla\CMS\MVC\Model\AdminModel
     *
     * @since  2.0.0
     */
    private function orderModel()
    {
        $model = $this->getModel('order');

        if (!$model) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_MODEL_CREATE'));
        }

        return $model;
    }

    /**
     * The order id from the route, verified to exist.
     *
     *
     * @since  2.0.0
     */
    private function requireOrderId(): int
    {
        $orderId = $this->routeInt('id');

        if ($orderId <= 0) {
            throw new ResourceNotFound(Text::_('JLIB_APPLICATION_ERROR_RECORD'), 404);
        }

        return $orderId;
    }

    /**
     * A sub-resource id from the route.
     *
     * @param string $name Route variable name.
     *
     *
     * @since  2.0.0
     */
    private function requireSubId(string $name): int
    {
        $id = $this->routeInt($name);

        if ($id <= 0) {
            throw new ResourceNotFound(Text::_('JLIB_APPLICATION_ERROR_RECORD'), 404);
        }

        return $id;
    }

    /**
     * The JSON request body.
     *
     * The decoded raw body wins outright. Reading through
     * $input->get('data', ...) instead would let ?data[amount]=1 in the query
     * string shadow the body, and every verb here either moves money or
     * changes fulfilment state.
     *
     *
     * @since  2.0.0
     */
    private function body(): array
    {
        $decoded = json_decode((string) $this->input->json->getRaw(), true);

        if (\is_array($decoded)) {
            return $decoded;
        }

        return (array) $this->input->get('data', [], 'array');
    }

    /**
     * The refund amount from the body, as Money, never a silent zero.
     *
     * (float) on raw body input is what erased the difference between "no
     * amount" and "not an amount": '', 'abc', true and 0 all became 0.0, and a
     * zero used to mean "refund everything remaining". Everything that is
     * PRESENT must therefore be a real, positive figure the order's currency can
     * express, or a 400; the ledger's own 422s are about refundability, not
     * about the caller's syntax. It is read by {@see self::exactAmount()}.
     *
     * @param mixed $value The body's 'amount', already known to be non-null.
     * @param Money $remainder The refundable remainder; its currency decides the scale.
     *
     *
     * @throws Save 400 when the value is not an amount this order can return.
     *
     * @since  2.0.0
     */
    private function refundAmount($value, Money $remainder): Money
    {
        $amount = $this->exactAmount(value: $value, field: 'amount', currency: $remainder->getCurrency());

        if (!$amount->isPositive()) {
            throw new Save('amount must be greater than zero; omit it to refund the remainder', 400);
        }

        return $amount;
    }

    /**
     * A parcel's VAT rate from the body: a percentage of zero or more with at
     * most three decimal places, as the column holds it. A JSON number is read
     * at the digits it carries; text as written, never rounded. The rule is
     * {@see OrderCreateParts::rate()}, which the one-call create reads too.
     *
     * @param mixed $value The body's value, already known to be present.
     *
     * @return string The rate's decimal text.
     *
     * @throws Save 400 when the value is not such a percentage.
     *
     * @since  2.0.0
     */
    private function parcelRate(mixed $value): string
    {
        try {
            return OrderCreateParts::rate(value: $value, field: 'shipping_tax_rate');
        } catch (InvalidArgumentException $e) {
            throw new Save(ErrorText::of($e), 400);
        }
    }

    /**
     * A parcel's cost from the body: an amount of the order's money, zero or more.
     *
     * @param mixed $value The body's value, already known to be present.
     * @param string $field The body key, for the refusal.
     * @param Currency $currency The order's currency at its frozen scale.
     *
     *
     * @throws Save 400 when the value is not an amount of this order's money, or is below zero.
     *
     * @since   2.0.0
     */
    private function parcelCost(mixed $value, string $field, Currency $currency): Money
    {
        $cost = $this->exactAmount(value: $value, field: $field, currency: $currency);

        if ($cost->isNegative()) {
            throw new Save($field . ' must be zero or more', 400);
        }

        return $cost;
    }

    /**
     * A money figure from the body, as the order's Money on its own digits.
     *
     * Every amount these verbs write is read by the one reader of a typed
     * amount, {@see ExactMoney}: a text as written, never through a float, an
     * integer as itself, and a JSON number, which arrives as a float, at the
     * digits Money reads one at. A figure finer than the order's currency, or
     * larger than its column or Money holds, is refused by name: it is never
     * rounded or cut.
     *
     * Canonical ungrouped decimals only, and NOT trimmed: presentation formats
     * ("1.234,56", " 5") belong to a parser with a format contract, not to a
     * money verb. The rule is {@see OrderCreateParts::amount()}, which the
     * one-call create reads too.
     *
     * @param mixed $value The body's value, already known to be present.
     * @param string $field The body key, for the refusal.
     * @param Currency $currency The order's currency at its frozen scale.
     *
     *
     * @throws Save 400 when the value is not an amount of this order's money.
     *
     * @since   2.0.0
     */
    private function exactAmount(mixed $value, string $field, Currency $currency): Money
    {
        try {
            return OrderCreateParts::amount(value: $value, field: $field, currency: $currency);
        } catch (InvalidArgumentException $e) {
            throw new Save(ErrorText::of($e), 400, $e);
        }
    }

    /**
     * The model's own error, so the domain's message reaches the client rather
     * than a generic one; the ledgers explain exactly which cap was hit.
     *
     * @param object $model The model just called.
     * @param string $fallback Message when the model set none.
     *
     *
     * @since  2.0.0
     */
    private function modelError($model, string $fallback): string
    {
        $errors = method_exists($model, 'getErrors') ? $model->getErrors() : [];
        $first = $errors[0] ?? ($model->getError() ?? '');

        return $first instanceof Throwable ? $first->getMessage() : ((string) $first ?: $fallback);
    }

    /**
     * Read a route variable from the bag the router actually wrote it to.
     *
     * ApiApplication::route() puts route variables into $input->post for a
     * POST and into the main input for every other method, so the bag is
     * chosen by method rather than by falling back between the two: the main
     * input is $_REQUEST-backed, so a fallback let a query string shadow the
     * path on exactly the POST verbs that move money:
     * POST /orders/5/payments?id=9 booked against order 9.
     *
     * @param string $name Route variable name.
     *
     *
     * @since  2.0.0
     */
    private function routeInt(string $name): int
    {
        $bag = strtoupper((string) $this->input->getMethod()) === 'POST'
            ? $this->input->post
            : $this->input;

        return RequestScalar::int($bag, $name, 0);
    }
}
