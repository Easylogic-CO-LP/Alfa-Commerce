<?php

namespace Alfa\PhpKlarna\Resources;

defined('_JEXEC') or die;

/**
 * Session — Klarna Payments server-side session.
 *
 * Flow 1 (JS widget): pass $session->clientToken to Klarna.js.
 * Flow 2 (HPP):       pass $session->sessionId to createHppSession().
 */
class Session extends ApiResource
{
    public string $sessionId = '';
    public string $clientToken = '';
    public array $paymentMethodCategories = [];

    public function __construct(array $attributes, $klarna = null)
    {
        parent::__construct($attributes, $klarna);
    }
}
