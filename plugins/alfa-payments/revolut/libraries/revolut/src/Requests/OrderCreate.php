<?php

namespace Alfa\PhpRevolut\Requests;

defined('_JEXEC') or die;

/**
 * Request payload for POST /api/orders.
 * Plain readonly class — no external DTO dependencies.
 */
final class OrderCreate
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
        public readonly ?string $redirect_url = null,
        public readonly ?string $capture_mode = null,  // 'AUTOMATIC' | 'MANUAL'
        public readonly ?string $merchant_order_ext_ref = null,  // your internal order ID
        public readonly ?string $email = null,
        public readonly ?string $description = null,
        public readonly ?string $customer_id = null,
    ) {
    }

    public function toArray(): array
    {
        return array_filter([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'redirect_url' => $this->redirect_url,
            'capture_mode' => $this->capture_mode,
            'merchant_order_ext_ref' => $this->merchant_order_ext_ref,
            'email' => $this->email,
            'description' => $this->description,
            'customer_id' => $this->customer_id,
        ], fn ($v) => $v !== null);
    }
}
