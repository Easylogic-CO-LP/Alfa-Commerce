<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Orders;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Orders view
 *
 * Never render: secure_key (guest-tracking token), ip_address (PII), note
 * (internal), the _items/_shipments/_payments/_discounts/_contributions raw
 * arrays or any Money/Currency object properties.
 *
 * @since  1.0.0
 */
class JsonApiView extends BaseApiView
{
    /**
     * The fields to render item in the documents
     *
     * @var array
     * @since  1.0.0
     */
    protected $fieldsToRenderItem = [
        'id',
        'reference',
        'id_user',
        'customer_name',
        'customer_email',
        'id_currency',
        'currency',
        'conversion_rate',
        'invoice_number',
        'invoice_date',
        'delivery_date',
        'id_address_delivery',
        'id_address_invoice',
        'id_payment_method',
        'payment_method_name',
        'id_shipment_method',
        'shipment_method_name',
        'id_order_status',
        'status_name',
        'payment_status',
        // The payment facts the item MODEL already computes. They were derived
        // on every single-order read and then dropped by this list, so a REST
        // client could see that one order of a hundred was overpaid but never
        // ask about that one order. The list route has carried them all along;
        // the two routes now answer the same question the same way.
        'payment_percentage',
        'balance',
        'overpaid',
        'excess',
        'still_held',
        'order_total',
        'order_total_tax_excl',
        'items_total',
        'shipping_total',
        'discount_total',
        'contributions_total',
        'total_paid_real',
        'total_refunded_real',
        'customer_note',
        'source',
        'state',
        'created',
        'modified',
        'lines',
        'payments',
        'shipments',
        'contributions',
        'history',
        'delivery_address',
        'invoice_address',
    ];

    /**
     * The fields to render items in the documents
     *
     * @var array
     * @since  1.0.0
     */
    protected $fieldsToRenderList = [
        'id',
        'reference',
        'customer_name',
        'customer_email',
        'id_order_status',
        'status_name',
        'id_payment_method',
        'payment_method_name',
        'id_shipment_method',
        'shipment_method_name',
        'payment_status',
        'payment_percentage',
        // Signed due + the overpaid fact and its amount: derived, never stored.
        'balance',
        'overpaid',
        'excess',
        'still_held',
        'fulfillment_status',
        'fulfillment_percentage',
        'order_total',
        'order_total_tax_excl',
        'items_total',
        'shipping_total',
        'discount_total',
        'total_paid_real',
        'total_refunded_real',
        'id_currency',
        'currency',
        'source',
        'state',
        'created',
        'modified',
    ];

    /**
     * Answer one order.
     *
     * An order that names no currency is still answered, with what is stored
     * and no money made of it; every write on it is refused elsewhere.
     *
     * @param object|null $item The item to render; null reads the model's.
     *
     * @return string
     *
     * @since  2.0.0
     */
    public function displayItem($item = null)
    {
        if ($item === null) {
            /** @var \Alfa\Component\Alfa\Api\Model\OrderModel $model */
            $model = $this->getModel();

            try {
                $item = $this->prepareItem($model->getItem());
            } catch (OrderCurrencyException $e) {
                $item = $this->prepareItem($model->getItemWithoutCurrency(orderId: $e->orderId()));
            }
        }

        return parent::displayItem($item);
    }
}
