<?php

namespace Alfa\PhpKlarna\Resources;

defined('_JEXEC') or die;

/**
 * Order — Klarna Order Management order.
 * All monetary amounts are in minor currency units (cents / pence / öre).
 */
class Order extends ApiResource
{
    public string $orderId = '';
    public string $status = ''; // AUTHORIZED | PART_CAPTURED | CAPTURED | CANCELLED
    public string $fraudStatus = ''; // ACCEPTED | PENDING | REJECTED
    public array $orderLines = [];
    public int $orderAmount = 0;
    public int $capturedAmount = 0;
    public int $refundedAmount = 0;
    public string $purchaseCurrency = '';
    public int $remainingAuthorizedAmount = 0;
    public int $originalOrderAmount = 0;
    public string $merchantReference1 = '';
    public string $merchantReference2 = '';
    public string $createdAt = '';
    public string $expiresAt = '';
    public array $captures = [];
    public array $refunds = [];

    /** @var mixed */
    public $customer;
    /** @var mixed */
    public $billingAddress;
    /** @var mixed */
    public $shippingAddress;
    /** @var mixed */
    public $merchantData;
    /** @var mixed */
    public $initialPaymentMethod;

    public function __construct(array $attributes, $klarna = null)
    {
        parent::__construct($attributes, $klarna);
    }
}
