<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\CMS\MVC\Controller\Exception\ResourceNotFound;
use Joomla\CMS\MVC\Controller\Exception\Save;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use RuntimeException;
use Throwable;

/**
 * The Orders controller
 *
 * @since  1.0.0
 */
class OrdersController extends ApiController
{
    // The trait's add(), delete() and edit() are reached through aliases: this class overrides them, and its
    // parent::delete() would be ApiController's, which asks core.delete, and its parent::edit() ApiController's, which
    // does not refuse the keys an order's update does not write.
    use HasApiGuards {
        HasApiGuards::add as protected guardedAdd;
        HasApiGuards::delete as protected guardedDelete;
        HasApiGuards::edit as protected guardedEdit;
    }

    /**
     * The published attributes of an order that its update (PATCH) does not write (2.2.1): the order form
     * declares none of them, or drops them on an update (id_user, id_currency), so a PATCH of one answered 200 and the
     * order stayed as it was. They are worked out from the order's lines, payments and shipments, fixed when the order
     * is placed, or written by the order operations. id_order_status and state have refusals of their own (edit()).
     *
     * Two the order form would write as sent are refused too: id_address_delivery (a hidden field: the posted id would
     * point the order at any address record, and a guest order's name and e-mail are copied from the record it names)
     * and created (the time the order was placed). The delivery details are written through alfa_form_fields, and a
     * PATCH that leaves either out keeps it as stored.
     *
     * @var string[]
     * @since  2.2.1
     */
    private const NOT_UPDATED = [
        'reference', 'id_user', 'customer_name', 'customer_email', 'id_currency', 'currency', 'conversion_rate',
        'delivery_date', 'id_address_delivery', 'id_address_invoice', 'id_payment_method',
        'payment_method_name', 'id_shipment_method', 'shipment_method_name', 'status_name', 'payment_status',
        'fulfillment_status', 'fulfillment_percentage', 'payment_percentage', 'balance', 'overpaid', 'excess', 'still_held',
        'order_total', 'order_total_tax_excl', 'items_total', 'shipping_total', 'discount_total', 'contributions_total',
        'total_paid_real', 'total_refunded_real', 'source', 'created', 'modified', 'lines', 'payments', 'shipments',
        'contributions', 'history', 'delivery_address', 'invoice_address',
    ];

    /**
     * The method keys an order's create (POST) does not write (2.2.2): an order takes its payment and shipping
     * methods from its payments and parcels, and follows the latest live one of each (OrderMethods). The create
     * dropped them without a word, while the update refuses them by name; it refuses them too.
     *
     * @var string[]
     * @since  2.2.2
     */
    private const NOT_CREATED = ['id_payment_method', 'payment_method_name', 'id_shipment_method', 'shipment_method_name'];

    /**
     * The content type of the item.
     *
     * @var string
     * @since  1.0.0
     */
    protected $contentType = 'orders';

    /**
     * The default view for the display method.
     *
     * @var string
     * @since  1.0.0
     */
    protected $default_view = 'orders';

    /**
     * Orders list view amended to add filtering of data
     *
     * Each filter goes onto the model state under the name the orders screen's filter form gives it, and the list
     * model applies it as it does for the screen (2.2.1: payment, fulfilment, waiting, reconciliation, overpaid, the
     * order date window and item_id; and the customer, by account or by the order's e-mail). The date window's bounds
     * are shop-local times, as the screen's calendars hold them; the screen's date presets are not read here.
     *
     * @return static A BaseController object to support chaining.
     *
     * @since  2.0.0
     */
    public function displayList()
    {
        $apiFilterInfo = $this->input->get('filter', [], 'array');

        if (\array_key_exists('search', $apiFilterInfo)) {
            $this->modelState->set('filter.search', RequestScalar::member($apiFilterInfo, 'search', 'STRING', 'filter'));
        }

        if (\array_key_exists('id_order_status', $apiFilterInfo)) {
            $this->modelState->set('filter.id_order_status', RequestScalar::member($apiFilterInfo, 'id_order_status', 'INT', 'filter'));
        }

        if (\array_key_exists('id_payment_method', $apiFilterInfo)) {
            $this->modelState->set('filter.id_payment_method', RequestScalar::member($apiFilterInfo, 'id_payment_method', 'INT', 'filter'));
        }

        if (\array_key_exists('id_shipment_method', $apiFilterInfo)) {
            $this->modelState->set('filter.id_shipment_method', RequestScalar::member($apiFilterInfo, 'id_shipment_method', 'INT', 'filter'));
        }

        if (\array_key_exists('show_trashed', $apiFilterInfo)) {
            $this->modelState->set('filter.show_trashed', RequestScalar::member($apiFilterInfo, 'show_trashed', 'INT', 'filter'));
        }

        if (\array_key_exists('payment', $apiFilterInfo)) {
            $this->modelState->set('filter.payment', RequestScalar::member($apiFilterInfo, 'payment', 'CMD', 'filter'));
        }

        if (\array_key_exists('fulfilment', $apiFilterInfo)) {
            $this->modelState->set('filter.fulfilment', RequestScalar::member($apiFilterInfo, 'fulfilment', 'CMD', 'filter'));
        }

        if (\array_key_exists('waiting', $apiFilterInfo)) {
            $this->modelState->set('filter.waiting', RequestScalar::member($apiFilterInfo, 'waiting', 'CMD', 'filter'));
        }

        if (\array_key_exists('reconciliation', $apiFilterInfo)) {
            $this->modelState->set('filter.reconciliation', RequestScalar::member($apiFilterInfo, 'reconciliation', 'CMD', 'filter'));
        }

        if (\array_key_exists('overpaid', $apiFilterInfo)) {
            $this->modelState->set('filter.overpaid', RequestScalar::member($apiFilterInfo, 'overpaid', 'CMD', 'filter'));
        }

        if (\array_key_exists('date_start', $apiFilterInfo)) {
            $this->modelState->set('filter.date_start', RequestScalar::member($apiFilterInfo, 'date_start', 'STRING', 'filter'));
        }

        if (\array_key_exists('date_end', $apiFilterInfo)) {
            $this->modelState->set('filter.date_end', RequestScalar::member($apiFilterInfo, 'date_end', 'STRING', 'filter'));
        }

        if (\array_key_exists('item_id', $apiFilterInfo)) {
            $this->modelState->set('filter.item_id', RequestScalar::member($apiFilterInfo, 'item_id', 'INT', 'filter'));
        }

        if (\array_key_exists('id_user', $apiFilterInfo)) {
            // CMD, not INT: an empty value stays empty (no filter), where INT would make it 0 (the guests' orders).
            $this->modelState->set('filter.id_user', RequestScalar::member($apiFilterInfo, 'id_user', 'CMD', 'filter'));
        }

        if (\array_key_exists('email', $apiFilterInfo)) {
            $this->modelState->set('filter.email', RequestScalar::member($apiFilterInfo, 'email', 'STRING', 'filter'));
        }

        $apiListInfo = $this->input->get('list', [], 'array');

        if (\array_key_exists('ordering', $apiListInfo)) {
            $this->modelState->set('list.ordering', RequestScalar::member($apiListInfo, 'ordering', 'STRING', 'list'));
        }

        if (\array_key_exists('direction', $apiListInfo)) {
            $this->modelState->set('list.direction', RequestScalar::member($apiListInfo, 'direction', 'STRING', 'list'));
        }

        return parent::displayList();
    }

    /**
     * Update an order: the guards' edit, after the refusals of the keys the update does not take.
     *
     * The invoice details are the model's to keep: an update that sends neither invoice_differs nor
     * alfa_form_fields_billing keeps the order's own invoice details, and the answer is the order as stored
     * (OrderModel::keepsStoredInvoice(), 2.2.2). Only invoice_differs 0 invoices the order to its delivery details.
     *
     * @param int|null $id The record id.
     *
     * @return mixed
     *
     * @since  2.0.0
     */
    public function edit($id = null)
    {
        // Status is deliberately NOT patchable here (see the route comment in
        // plugins/webservices/alfa): the fat save path always force()s, which
        // would silently bypass the cancelled-family guard and skip target
        // validation. POST /orders/:id/status is the one status entry point.
        $rawBody = (array) json_decode((string) $this->input->json->getRaw(), true);
        $bodyData = (array) ($rawBody['data']['attributes'] ?? $rawBody['data'] ?? $rawBody);

        if (array_key_exists('id_order_status', $rawBody) || array_key_exists('id_order_status', $bodyData)) {
            throw new Save('id_order_status cannot be changed via PATCH; use POST v1/alfa/orders/:id/status.', 422);
        }

        // The resource publishes state, the order row's trash flag. Only the administrator's Orders list moves an order
        // to the trash; over the API an order has no trash step and is deleted in one call, and the order form has no
        // such field, so a PATCH of it was answered 200 and not written (2.2.1). Refused, as the status is.
        if (array_key_exists('state', $rawBody) || array_key_exists('state', $bodyData)) {
            throw new Save('state cannot be changed via PATCH: over the API an order has no trash step and is deleted in one call (DELETE v1/alfa/orders/:id); its status changes through POST v1/alfa/orders/:id/status.', 422);
        }

        $this->refuseUnstatedInvoice();

        $orderId = (int) ($id ?? RequestScalar::read($this->input, 'id', null, 'int'));
        $this->refuseInvoiceWithoutDetails(orderId: $orderId);

        // The guards' edit: the published attributes the update does not write are refused first (2.2.1).
        return $this->guardedEdit();
    }

    /**
     * Create an order: the guards' create, after the invoice details' own refusal ({@see self::refuseUnstatedInvoice()})
     * and the refusal of the method keys ({@see self::NOT_CREATED}).
     *
     * "silent": true leaves the customer out of the e-mail the status the order opens in owes, as on a status change;
     * an order created without lines owes no e-mail (OrderModel::save(), 2.2.2).
     *
     * @return void
     *
     * @throws Save When the body carries a method key (422).
     *
     * @since  2.2.2
     */
    public function add()
    {
        $this->refuseUnstatedInvoice();

        $carried = array_values(array_intersect(self::NOT_CREATED, array_keys($this->requestBody())));

        if ($carried !== []) {
            throw new Save(implode(', ', $carried) . ' cannot be written when an order is created: an order takes its payment'
                . ' and shipping methods from its payments and parcels (POST v1/alfa/orders/:id/payments, POST'
                . ' v1/alfa/orders/:id/shipments) and follows the latest of each. Nothing was saved.', 422);
        }

        $this->guardedAdd();
    }

    /**
     * Answer 400 naming invoice_differs when the body sends invoice details (alfa_form_fields_billing) without it
     * (2.2.2). The invoice details are written only by the choice invoice_differs states; details sent without it were
     * dropped without a word, so a client was not told that its invoice details were not saved.
     *
     * @throws Save When the body sends the invoice details without invoice_differs (400).
     *
     * @since  2.2.2
     */
    private function refuseUnstatedInvoice(): void
    {
        $body = $this->requestBody();

        if (($body[FieldsHelper::BILLING_FIELDS_KEY] ?? null) !== null && !\array_key_exists('invoice_differs', $body)) {
            throw new Save('invoice_differs: send invoice_differs with alfa_form_fields_billing (1 for invoice details of their own,'
                . ' 0 to invoice to the delivery details). Nothing was saved.', 400);
        }
    }

    /**
     * Answer 400 naming alfa_form_fields_billing when a PATCH states invoice_differs 1 without the invoice details and
     * the order has no invoice details of its own (2.2.2). With its own invoice details the order keeps them whole, as a
     * section a PATCH does not send keeps its stored value; without them there is nothing to keep and nothing to write.
     *
     * @param int $orderId The order.
     *
     * @throws Save When the order has no invoice details of its own to keep (400).
     *
     * @since  2.2.2
     */
    private function refuseInvoiceWithoutDetails(int $orderId): void
    {
        $body = $this->requestBody();

        if (empty($body['invoice_differs']) || \is_array($body[FieldsHelper::BILLING_FIELDS_KEY] ?? null) || $orderId <= 0) {
            return;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $exists = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $orderId, ParameterType::INTEGER),
        )->loadResult() > 0;

        // An id that names no order is core's to answer (404).
        if ($exists && $this->invoiceAddressId($orderId) === null) {
            throw new Save('alfa_form_fields_billing: send the invoice details with invoice_differs 1; this order has no invoice'
                . ' details of its own to keep. Nothing was saved.', 400);
        }
    }

    /**
     * The published attributes an order's update does not write ({@see self::NOT_UPDATED}); a create (POST) takes its
     * own keys and is not changed here.
     *
     * @return string[]
     *
     * @since  2.2.1
     */
    protected function unwrittenOnUpdate(): array
    {
        return self::NOT_UPDATED;
    }

    /**
     * Where an order is written instead.
     *
     * @since  2.2.1
     */
    protected function unwrittenReason(): string
    {
        return 'an order\'s update writes its customer_note and its delivery and invoice details (alfa_form_fields, and'
            . ' alfa_form_fields_billing with invoice_differs); its status changes through POST v1/alfa/orders/:id/status, its'
            . ' lines, payments and shipments through the order operations, and the other fields are worked out from them or'
            . ' fixed when the order is placed. Nothing was saved.';
    }

    /**
     * Refuse to delete an order that carries issued sales documents.
     *
     * The model enforces this too, and is the real guard; it also covers the
     * back office, and it decides under the order lock. This override exists
     * for the HTTP contract: a model setError() surfaces through
     * ApiController::delete() as a 500, so the refusal has to be raised here to
     * come out as a 409 carrying the document numbers the operator needs. That
     * holds for a document issued while the delete ran, which the model refuses
     * under its lock. An id that names no order answers 404 (2.2.0); an order
     * whose payment or shipping plugin of record cannot be asked is refused by
     * the model as a DeleteRefusedException, which the guards answer 409 with
     * its sentence (2.2.0, A-E24-1).
     *
     * @param int|null $id The record id.
     *
     * @return void
     *
     * @since  2.0.0
     */
    public function delete($id = null)
    {
        $orderId = (int) ($id ?? RequestScalar::read($this->input, 'id', null, 'int'));
        $model = $this->getModel('order', '', ['ignore_request' => true]);

        if ($model && $orderId > 0) {
            $this->refuseMissingOrder(orderId: $orderId);
            $this->refuseDocumentedOrder(model: $model, orderId: $orderId);
            $this->refuseOrderWithoutCurrency(orderId: $orderId);
        }

        try {
            $this->guardedDelete($id);
        } catch (RuntimeException $e) {
            // Refused after the check above: the order still holds the
            // document that was issued in between.
            if ($model && $orderId > 0) {
                $this->refuseDocumentedOrder(model: $model, orderId: $orderId, previous: $e);
            }

            throw $e;
        }
    }

    /**
     * Answer 404 when the id names no order (a second delete of the same order among them), as reading it would: the
     * model's delete would read an order that is not there and answer 204 (2.2.0).
     *
     * @param int $orderId The order id.
     *
     * @throws ResourceNotFound When no order has that id.
     *
     * @since  2.2.0
     */
    private function refuseMissingOrder(int $orderId): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $found = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $orderId, ParameterType::INTEGER),
        )->loadResult();

        if ($found === 0) {
            throw new ResourceNotFound(Text::_('JLIB_APPLICATION_ERROR_RECORD'), 404);
        }
    }

    /**
     * Answer 409 with the refusal's sentence when the order names no currency.
     *
     * The model refuses the delete too, and is the real guard. Raised here
     * for the same reason as the documents refusal: a model setError()
     * surfaces through ApiController::delete() as a 500. An id that names no
     * order is not this refusal's to answer.
     *
     * @param int $orderId The order id.
     *
     *
     * @throws OrderCurrencyException When the order exists and names no currency.
     *
     * @since  2.0.0
     */
    private function refuseOrderWithoutCurrency(int $orderId): void
    {
        try {
            OrderCurrency::of(orderId: $orderId);
        } catch (OrderCurrencyException $e) {
            if ($e->orderExists()) {
                throw $e;
            }
        }
    }

    /**
     * Answer 409 with the document numbers when the order holds a sales document.
     *
     * @param object $model The order model.
     * @param int $orderId The order id.
     * @param Throwable|null $previous The refusal this answers, when there was one.
     *
     *
     * @throws Save When the order holds a document.
     *
     * @since  2.0.0
     */
    private function refuseDocumentedOrder(object $model, int $orderId, ?Throwable $previous = null): void
    {
        $blocked = $model->ordersWithDocuments(pks: [$orderId]);

        if ($blocked !== []) {
            throw new Save(
                message: Text::sprintf('COM_ALFA_ORDER_ERROR_DELETE_HAS_DOCUMENTS', implode(separator: ', ', array: $blocked)),
                code: 409,
                previous: $previous,
            );
        }
    }

    /**
     * The order's current invoice address id, or null when it has none of its own.
     *
     * @param int $orderId Order id.
     *
     *
     * @since  2.0.0
     */
    private function invoiceAddressId(int $orderId): ?int
    {
        if ($orderId <= 0) {
            return null;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id_address_delivery', 'id_address_invoice']))
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = ' . $orderId),
        )->loadObject();

        $invoice = (int) ($row->id_address_invoice ?? 0);
        $delivery = (int) ($row->id_address_delivery ?? 0);

        return ($invoice > 0 && $invoice !== $delivery) ? $invoice : null;
    }
}
