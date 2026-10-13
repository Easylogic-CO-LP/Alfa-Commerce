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
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\CMS\MVC\Controller\Exception\Save;
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
     * @return static A BaseController object to support chaining.
     *
     * @since  2.0.0
     */
    public function displayList()
    {
        $apiFilterInfo = $this->input->get('filter', [], 'array');
        $filter = InputFilter::getInstance();

        if (\array_key_exists('search', $apiFilterInfo)) {
            $this->modelState->set('filter.search', $filter->clean($apiFilterInfo['search'], 'STRING'));
        }

        if (\array_key_exists('id_order_status', $apiFilterInfo)) {
            $this->modelState->set('filter.id_order_status', $filter->clean($apiFilterInfo['id_order_status'], 'INT'));
        }

        if (\array_key_exists('id_payment_method', $apiFilterInfo)) {
            $this->modelState->set('filter.id_payment_method', $filter->clean($apiFilterInfo['id_payment_method'], 'INT'));
        }

        if (\array_key_exists('id_shipment_method', $apiFilterInfo)) {
            $this->modelState->set('filter.id_shipment_method', $filter->clean($apiFilterInfo['id_shipment_method'], 'INT'));
        }

        if (\array_key_exists('show_trashed', $apiFilterInfo)) {
            $this->modelState->set('filter.show_trashed', $filter->clean($apiFilterInfo['show_trashed'], 'INT'));
        }

        $apiListInfo = $this->input->get('list', [], 'array');

        if (\array_key_exists('ordering', $apiListInfo)) {
            $this->modelState->set('list.ordering', $filter->clean($apiListInfo['ordering'], 'STRING'));
        }

        if (\array_key_exists('direction', $apiListInfo)) {
            $this->modelState->set('list.direction', $filter->clean($apiListInfo['direction'], 'STRING'));
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

        $orderId = (int) ($id ?? $this->input->getInt('id'));
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
     * under its lock.
     *
     * @param int|null $id The record id.
     *
     * @return void
     *
     * @since  2.0.0
     */
    public function delete($id = null)
    {
        $orderId = (int) ($id ?? $this->input->getInt('id'));
        $model = $this->getModel('order', '', ['ignore_request' => true]);

        if ($model && $orderId > 0) {
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
