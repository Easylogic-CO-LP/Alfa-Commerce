<?php

/**
 * @package     Alfa\PhpKlarna
 * @copyright   Copyright (C) Alfa. All rights reserved.
 * @license     MIT
 */

namespace Alfa\PhpKlarna\Resources;

defined('_JEXEC') or die;

/**
 * HppSession — Klarna Hosted Payment Page session resource.
 *
 * After createHppSession():
 *   header('Location: ' . $hpp->redirectUrl);  ← redirect customer here
 *
 * After customer pays and Klarna redirects back to your success URL:
 *   $hpp = getHppSession($_GET['sid']);
 *   if ($hpp->status === 'COMPLETED') {
 *       $order = createOrderFromAuthorizationToken($hpp->authorizationToken, $data);
 *   }
 *
 * Status values:
 *   WAITING   — not yet paid
 *   COMPLETED — paid; $authorizationToken is set
 *   CANCELLED — customer cancelled
 *   FAILED    — payment rejected
 *   DISABLED  — session expired (48h limit)
 */
class HppSession extends ApiResource
{
    /** Redirect the customer to this URL. Klarna's payment page. */
    public string $redirectUrl = '';

    /** HPP session ID — returned by Klarna as ?sid={session.id} in your success URL. */
    public string $sessionId = '';

    /** WAITING | COMPLETED | CANCELLED | FAILED | DISABLED */
    public string $status = '';

    /** The KP session ID this HPP session is linked to. */
    public string $paymentSessionId = '';

    /** ISO-8601 expiry timestamp. */
    public string $expiresAt = '';

    /**
     * Authorization token — only set when status === 'COMPLETED'.
     * Pass this to createOrderFromAuthorizationToken().
     */
    public string $authorizationToken = '';

    public function __construct(array $attributes, $klarna = null)
    {
        parent::__construct($attributes, $klarna);
    }
}
