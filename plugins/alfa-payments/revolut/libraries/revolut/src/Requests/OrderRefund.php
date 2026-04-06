<?php
namespace Alfa\PhpRevolut\Requests;
defined('_JEXEC') or die;

/** Request payload for POST /api/orders/{id}/refund */
final class OrderRefund
{
    public function __construct(
        public readonly int     $amount,       // minor units — required for refund
        public readonly ?string $currency,
        public readonly ?string $description = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'amount'      => $this->amount,
			'currency'    => $this->currency,
            'description' => $this->description,
        ], fn($v) => $v !== null);
    }
}
