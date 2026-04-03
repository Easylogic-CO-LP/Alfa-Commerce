<?php

/**
 * @package     Alfa\PhpKlarna
 * @copyright   Copyright (C) Alfa. All rights reserved.
 * @license     MIT
 */

namespace Alfa\PhpKlarna\Actions;

defined('_JEXEC') or die;

use Alfa\PhpKlarna\Resources\HppSession;

/**
 * ManageHostedPaymentPage
 *
 * Covers the Klarna Hosted Payment Page (HPP) API — 100% server-side, zero JS.
 *
 * ─── FULL FLOW ────────────────────────────────────────────────────────────────
 *
 *  YOUR PLUGIN                        KLARNA
 *  ──────────────────────────────     ──────────────────────────────────────────
 *  1. createSession()              →  Klarna Payments API → returns sessionId
 *  2. createHppSession(sessionId)  →  HPP API → returns redirectUrl
 *  3. header('Location: redirectUrl')
 *                                  ←  Customer lands on Klarna's page
 *                                     Klarna shows payment UI (their JS, not yours)
 *                                     Customer authenticates and pays
 *                                  →  Klarna redirects to your $urls['success']
 *                                     URL contains ?sid={session.id} automatically
 *  4. getHppSession($_GET['sid'])  →  verify status === 'COMPLETED'
 *  5. createOrderFromAuthorizationToken($hpp->authorizationToken, $data)
 *  6. acknowledgeOrder($orderId)   ← REQUIRED within 14 days or Klarna auto-cancels
 *
 *  Separately:
 *  7. Klarna POST to $urls['notification']  (push webhook — re-acknowledge here too)
 *
 * ─── STATE / SESSION ──────────────────────────────────────────────────────────
 *
 *  Klarna injects {session.id} into your success URL as a query parameter, so
 *  you do NOT need to store the HPP session ID in your own state/session.
 *
 *  Your existing setState('order_id', $id) is enough — that maps your internal
 *  order to the returned Klarna order so you can complete it on the success page.
 *
 *  Minimum state to save before redirect:
 *    setState('order_id',   $internalOrderId)   ← your existing pattern
 *    setState('order_data', $cartSnapshot)       ← needed to pass to createOrder()
 *
 *  On return ($urls['success'] fires):
 *    $sid  = $_GET['sid'];                       ← Klarna passes this automatically
 *    $hpp  = $klarna->getHppSession($sid);       ← verify COMPLETED
 *    $id   = getState('order_id');               ← your existing pattern
 *    $data = getState('order_data');
 *
 * ─── ALTERNATIVE — encode order_id in the success URL ─────────────────────────
 *
 *  If you prefer zero session state, encode everything in the URL:
 *    'success' => 'https://yourshop.gr/success?sid={session.id}&oid=' . $internalOrderId
 *  Then on return: $id = $_GET['oid'];
 *
 * @see https://docs.klarna.com/hosted-payment-page/
 */
trait ManageHostedPaymentPage
{
    /**
     * Create a Hosted Payment Page session linked to a Klarna Payments session.
     *
     * Returns an HppSession. Use $hpp->redirectUrl for your header() redirect.
     *
     * @param string $paymentSessionId The sessionId returned by createSession().
     * @param array $urls {
     * @type string $success       URL Klarna redirects customer to on success.
     *              Use {session.id} placeholder — Klarna fills it in.
     *              Example: 'https://yourshop.gr/success?sid={session.id}'
     * @type string $cancel        URL on customer cancellation.
     * @type string $failure       URL on payment failure / rejection.
     * @type string $back          URL when customer clicks "go back".
     * @type string $notification  Webhook URL Klarna POSTs to as a push confirmation.
     *              Example: 'https://yourshop.gr/api/klarna/push?sid={session.id}'
     *              }
     * @param array $options Optional overrides (payment_method_category, locale, etc.).
     *
     * @see https://docs.klarna.com/payments/web-payments/integrate-with-klarna-payments/integrate-via-hpp/api-documentation/
     */
    public function createHppSession(
        string $paymentSessionId,
        array $urls,
        array $options = [],
    ): HppSession {
        $payload = array_merge([
            'payment_session_url' => $this->baseUri . 'payments/v1/sessions/' . $paymentSessionId,
            'merchant_urls' => $urls,
        ], $options);

        return new HppSession($this->post('hpp/v1/sessions', $payload), $this);
    }

    /**
     * Retrieve the current status of an HPP session.
     *
     * Call this on your success URL after Klarna redirects the customer back.
     * Always verify status === 'COMPLETED' before creating the order.
     *
     * Possible statuses:
     *   WAITING   — customer has not yet paid (do not proceed)
     *   COMPLETED — paid successfully; use $hpp->authorizationToken to create order
     *   CANCELLED — customer clicked cancel / back
     *   FAILED    — payment rejected (fraud / insufficient funds / etc.)
     *   DISABLED  — session expired (48h KP session lifetime exceeded)
     *
     * @param string $sessionId The HPP session ID — from $_GET['sid'] on your success URL.
     */
    public function getHppSession(string $sessionId): HppSession
    {
        return new HppSession($this->get('hpp/v1/sessions/' . $sessionId), $this);
    }
}
