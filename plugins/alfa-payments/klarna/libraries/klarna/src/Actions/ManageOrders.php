<?php
/**
 * @package     Alfa\PhpKlarna
 * @copyright   Copyright (C) Alfa. All rights reserved.
 * @license     MIT
 */

namespace Alfa\PhpKlarna\Actions;

defined('_JEXEC') or die;

use Alfa\PhpKlarna\Resources\Order;
use Alfa\PhpKlarna\Resources\RespondSuccess;

/**
 * ManageOrders — Klarna Order Management API
 *
 * These methods apply AFTER an order is created (any flow).
 * The most important: acknowledgeOrder() — REQUIRED within 14 days.
 */
trait ManageOrders
{
    /**
     * Retrieve a full order from the Order Management API.
     *
     * @see https://developers.klarna.com/api/#order-management-api-get-order
     */
    public function order(string $orderId): Order
    {
        return new Order($this->get('ordermanagement/v1/orders/' . $orderId));
    }

    /**
     * Acknowledge an order. REQUIRED within 14 days of authorization.
     * Klarna auto-cancels unacknowledged orders. Call this immediately
     * after createOrderFromAuthorizationToken() and again on the push webhook.
     *
     * @see https://developers.klarna.com/api/#order-management-api-acknowledge-order
     */
    public function acknowledgeOrder(string $orderId): RespondSuccess
    {
        return new RespondSuccess($this->post('ordermanagement/v1/orders/' . $orderId . '/acknowledge'));
    }

    /**
     * Capture an authorized order. Call this when goods are shipped.
     * Do NOT capture before shipping — Klarna starts the payment clock on capture.
     *
     * @see https://developers.klarna.com/api/#order-management-api-create-capture
     */
    public function createCapture(string $orderId, array $data): RespondSuccess
    {
        return new RespondSuccess($this->post('ordermanagement/v1/orders/' . $orderId . '/captures', $data));
    }

    /**
     * Create a refund on a captured order.
     *
     * @see https://developers.klarna.com/api/#order-management-api-create-a-refund
     */
    public function createRefund(string $orderId, array $data): RespondSuccess
    {
        return new RespondSuccess($this->post('ordermanagement/v1/orders/' . $orderId . '/refunds', $data));
    }

    /**
     * Cancel an authorized (not yet captured) order.
     *
     * @see https://developers.klarna.com/api/#order-management-api-cancel-order
     */
    public function cancelOrder(string $orderId): RespondSuccess
    {
        return new RespondSuccess($this->post('ordermanagement/v1/orders/' . $orderId . '/cancel'));
    }

    /**
     * Extend the authorization time window.
     * Use for backorders — gives more time before Klarna auto-cancels.
     *
     * @see https://developers.klarna.com/api/#order-management-api-extend-authorization-time
     */
    public function extendAuthorizationTime(string $orderId): RespondSuccess
    {
        return new RespondSuccess(
            $this->post('ordermanagement/v1/orders/' . $orderId . '/extend-authorization-time')
        );
    }

    /**
     * Update merchant_reference1 and merchant_reference2 on an order.
     *
     * @see https://developers.klarna.com/api/#order-management-api-update-merchant-references
     */
    public function updateMerchantReferences(string $orderId, array $data): RespondSuccess
    {
        return new RespondSuccess(
            $this->patch('ordermanagement/v1/orders/' . $orderId . '/merchant-references', $data)
        );
    }

    /**
     * Update customer billing / shipping address on an order.
     *
     * @see https://developers.klarna.com/api/#order-management-api-update-customer-details
     */
    public function updateCustomerDetails(string $orderId, array $data): RespondSuccess
    {
        return new RespondSuccess(
            $this->patch('ordermanagement/v1/orders/' . $orderId . '/customer-details', $data)
        );
    }

    /**
     * Release the remaining authorized amount after a partial capture.
     *
     * @see https://developers.klarna.com/api/#order-management-api-release-remaining-authorization
     */
    public function releaseRemainingAuthorization(string $orderId): RespondSuccess
    {
        return new RespondSuccess(
            $this->post('ordermanagement/v1/orders/' . $orderId . '/release-remaining-authorization')
        );
    }
}
