<?php
/**
 * @package     Alfa\PhpKlarna
 * @copyright   Copyright (C) Alfa. All rights reserved.
 * @license     MIT
 */

namespace Alfa\PhpKlarna\Actions;

defined('_JEXEC') or die;

use Alfa\PhpKlarna\Resources\RespondSuccess;

/**
 * ManageCheckouts — Klarna Checkout API (Flow 3)
 *
 * Returns an HTML snippet you echo directly into your template.
 * Klarna's own iframe handles the payment — you write no JS.
 *
 * POST  /checkout/v3/orders
 * GET   /checkout/v3/orders/{orderId}
 * POST  /checkout/v3/orders/{orderId}  (update)
 */
trait ManageCheckouts
{
    /**
     * Create a Klarna Checkout order.
     * The response contains $result->htmlSnippet — echo it in your template.
     *
     * @see https://developers.klarna.com/api/#checkout-api-create-an-order
     */
    public function createCheckoutOrder(array $data): RespondSuccess
    {
        return new RespondSuccess($this->post('checkout/v3/orders', $data));
    }

    /**
     * Retrieve an existing Klarna Checkout order.
     *
     * @see https://developers.klarna.com/api/#checkout-api-retrieve-an-order
     */
    public function getCheckoutOrder(string $orderId): RespondSuccess
    {
        return new RespondSuccess($this->get('checkout/v3/orders/' . $orderId));
    }

    /**
     * Update an existing Klarna Checkout order (e.g. customer changed cart).
     *
     * @see https://developers.klarna.com/api/#checkout-api-update-an-order
     */
    public function updateCheckoutOrder(string $orderId, array $data): RespondSuccess
    {
        return new RespondSuccess($this->post('checkout/v3/orders/' . $orderId, $data));
    }
}
