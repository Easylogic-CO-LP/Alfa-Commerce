<?php

namespace Alfa\PhpKlarna\Resources;

defined('_JEXEC') or die;

/** Returned when a reusable token is created from a one-time authorization token. */
class CustomerTokenFromAuthorization extends ApiResource
{
    public string $tokenId = '';
    public array $billingAddress = [];

    public function __construct(array $attributes, $klarna = null)
    {
        parent::__construct($attributes, $klarna);
    }
}
