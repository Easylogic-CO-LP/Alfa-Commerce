<?php
namespace Alfa\PhpKlarna\Resources;
defined('_JEXEC') or die;

/** A stored Klarna customer token for recurring payments. */
class CustomerToken extends ApiResource
{
    public string $status            = ''; // ACTIVE | SUSPENDED | CANCELLED
    public string $paymentMethodType = ''; // INVOICE | DIRECT_DEBIT | etc.

    public function __construct(array $attributes, $klarna = null)
    {
        parent::__construct($attributes, $klarna);
    }
}
