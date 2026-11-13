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
    // The trait's delete() is reached through an alias: this class overrides delete(), and its
    // parent::delete() would be ApiController's, which asks core.delete.
    use HasApiGuards {
        HasApiGuards::delete as protected guardedDelete;
    }

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
     * Keep a separate invoice address alive across a partial update.
     *
     * OrderModel::save() runs saveInvoiceInfo() on EVERY save, and decides the
     * invoice address from the billing bucket. In the admin that bucket only
     * arrives when the "different details" checkbox is ticked (an unticked box
     * posts nothing), so its absence legitimately means "invoice to the
     * delivery address". An API PATCH never posts a form bucket either, so
     * every partial update silently collapsed id_address_invoice onto
     * id_address_delivery and orphaned the customer's real invoice record.
     *
     * The model cannot tell the two cases apart, so the API layer states its
     * own contract (absent means unchanged, exactly as for relations) by
     * capturing the pointer before the save and putting it back afterwards.
     *
     * Re-supplying the values instead does not work: for a GUEST order
     * UserInfoHelper::insertData() always writes a fresh row, so every PATCH
     * would leave a duplicate address behind.
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

        // The resource publishes state, the order row's trash flag, but no screen or request changes it: an order is
        // never trashed, and the order form has no such field, so a PATCH of it was answered 200 and not written
        // (2.2.1). Refused, as the status is.
        if (array_key_exists('state', $rawBody) || array_key_exists('state', $bodyData)) {
            throw new Save('state cannot be changed via PATCH: an order is not trashed; its status changes through POST v1/alfa/orders/:id/status.', 422);
        }

        $orderId = (int) ($id ?? RequestScalar::read($this->input, 'id', null, 'int'));
        $restore = $this->billingMentioned() ? null : $this->invoiceAddressId($orderId);

        $result = parent::edit($id);

        if ($restore !== null) {
            $this->restoreInvoiceAddress($orderId, $restore);
        }

        return $result;
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
     * Did the caller say anything about the invoice address?
     *
     *
     * @since  2.0.0
     */
    private function billingMentioned(): bool
    {
        $body = $this->input->get('data', json_decode($this->input->json->getRaw(), true), 'array');

        return \array_key_exists(FieldsHelper::BILLING_FIELDS_KEY, (array) $body)
            || \array_key_exists('invoice_differs', (array) $body);
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

    /**
     * Put the invoice pointer back, and clear up any snapshot the save minted.
     *
     * @param int $orderId Order id.
     * @param int $addressId The invoice address it pointed at before.
     *
     *
     * @since  2.0.0
     */
    private function restoreInvoiceAddress(int $orderId, int $addressId): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $current = (int) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id_address_invoice'))
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = ' . $orderId),
        )->loadResult();

        if ($current === $addressId) {
            return;
        }

        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__alfa_orders'))
                ->set($db->quoteName('id_address_invoice') . ' = ' . $addressId)
                ->where($db->quoteName('id') . ' = ' . $orderId),
        )->execute();

        // A guest save mints a brand-new address row every time; drop the one
        // this request created, but only once nothing points at it any more.
        if ($current <= 0) {
            return;
        }

        $stillUsed = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id_address_invoice') . ' = ' . $current)
                ->orWhere($db->quoteName('id_address_delivery') . ' = ' . $current),
        )->loadResult();

        if ($stillUsed === 0) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_user_info'))
                    ->where($db->quoteName('id') . ' = ' . $current),
            )->execute();
        }
    }
}
