<?php
/**
 * @package     Alfa\PhpKlarna
 * @copyright   Copyright (C) Alfa. All rights reserved.
 * @license     MIT
 */

namespace Alfa\PhpKlarna\Actions;

defined('_JEXEC') or die;

use Alfa\PhpKlarna\Resources\CustomerTokenFromAuthorization;
use Alfa\PhpKlarna\Resources\OrderCreated;
use Alfa\PhpKlarna\Resources\RespondSuccess;
use Alfa\PhpKlarna\Resources\Session;

/**
 * ManagePayments — Klarna Payments API
 *
 * createSession()                       POST /payments/v1/sessions
 * createCustomerToken($tok, $data)      POST /payments/v1/authorizations/{tok}/customer-token
 * createOrderFromAuthorizationToken()   POST /payments/v1/authorizations/{tok}/order
 * cancelAuthorization($tok)             DELETE /payments/v1/authorizations/{tok}
 */
trait ManagePayments
{
    /**
     * Create a server-side Klarna Payments session.
     *
     * Used in ALL three flows:
     *   Flow 1 (JS widget) → pass $session->clientToken to Klarna.js
     *   Flow 2 (HPP)       → pass $session->sessionId to createHppSession()
     *   Flow 3 (Checkout)  → use createCheckoutOrder() instead — not this method
     *
     * @see https://developers.klarna.com/api/#payments-api-create-a-new-credit-session
     */
    public function createSession(array $data): Session
    {
        return new Session($this->post('payments/v1/sessions', $data), $this);
    }

    /**
     * Create an order from an authorization token.
     *
     * In Flow 2 (HPP): call this with $hpp->authorizationToken after verifying
     * getHppSession()->status === 'COMPLETED'.
     *
     * In Flow 1 (JS widget): call this with the token from Klarna.js authorize() callback.
     *
     * @see https://developers.klarna.com/api/#payments-api-create-a-new-order
     */
    public function createOrderFromAuthorizationToken(string $authorizationToken, array $data): OrderCreated
    {
        return new OrderCreated(
            $this->post('payments/v1/authorizations/' . $authorizationToken . '/order', $data),
            $this
        );
    }

    /**
     * Store a reusable customer token from a one-time authorization token.
     * Enables subscription / recurring payments without re-authentication.
     *
     * @see https://developers.klarna.com/api/#payments-api-generate-a-consumer-token
     */
    public function createCustomerToken(string $authorizationToken, array $data): CustomerTokenFromAuthorization
    {
        return new CustomerTokenFromAuthorization(
            $this->post('payments/v1/authorizations/' . $authorizationToken . '/customer-token', $data),
            $this
        );
    }

    /**
     * Cancel an authorization token before it is used to create an order.
     *
     * @see https://developers.klarna.com/api/#payments-api-cancel-an-existing-authorization
     */
    public function cancelAuthorization(string $authorizationToken): RespondSuccess
    {
        return new RespondSuccess(
            $this->delete('payments/v1/authorizations/' . $authorizationToken),
            $this
        );
    }
}
