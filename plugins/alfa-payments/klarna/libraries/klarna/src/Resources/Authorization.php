<?php

namespace Alfa\PhpKlarna\Resources;

defined('_JEXEC') or die;

/** Klarna Payments authorization session (server-side mirror of a browser authorization). */
class Authorization extends ApiResource
{
    public string $sessionId = '';
    public string $clientToken = '';
    public array $paymentMethodCategories = [];

    public function __construct(array $attributes, $klarna = null)
    {
        parent::__construct($attributes, $klarna);
    }
}
