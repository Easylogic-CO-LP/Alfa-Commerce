<?php

namespace Alfa\PhpKlarna\Resources;

defined('_JEXEC') or die;

/** Returned when an order is created via the Payments API authorization token. */
class OrderCreated extends ApiResource
{
    public string $orderId = '';
    public string $redirectUrl = '';
    public string $fraudStatus = '';  // ACCEPTED | PENDING | REJECTED
    public array $authorizedPaymentMethod = [];

    public function __construct(array $attributes, $klarna = null)
    {
        parent::__construct($attributes, $klarna);
    }
}
