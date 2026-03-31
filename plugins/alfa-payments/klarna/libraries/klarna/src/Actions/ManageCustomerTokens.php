<?php
/**
 * @package     Alfa\PhpKlarna
 * @copyright   Copyright (C) Alfa. All rights reserved.
 * @license     MIT
 */

namespace Alfa\PhpKlarna\Actions;

defined('_JEXEC') or die;

use Alfa\PhpKlarna\Resources\CustomerToken;
use Alfa\PhpKlarna\Resources\Order;

/**
 * ManageCustomerTokens — Klarna Customer Token API
 *
 * For recurring / subscription payments.
 * A stored token lets you charge a customer without any browser interaction.
 *
 * GET  /customer-token/v1/tokens/{token}
 * POST /customer-token/v1/tokens/{token}/order
 */
trait ManageCustomerTokens
{
    /**
     * Read the status and details of a stored customer token.
     *
     * Returns status: ACTIVE | SUSPENDED | CANCELLED
     *
     * @see https://developers.klarna.com/api/#customer-token-api-read-customer-token
     */
    public function customerToken(string $customerToken): CustomerToken
    {
        return new CustomerToken($this->get('customer-token/v1/tokens/' . $customerToken));
    }

    /**
     * Create an order using a stored customer token (recurring charge).
     * No browser interaction required — the customer is not present.
     *
     * @see https://developers.klarna.com/api/#customer-token-api-create-a-new-order-that-will-be-paid-using-the-customer-token
     */
    public function createOrderFromCustomerToken(string $customerToken, array $data): Order
    {
        return new Order(
            $this->post('customer-token/v1/tokens/' . $customerToken . '/order', $data),
            $this
        );
    }
}
