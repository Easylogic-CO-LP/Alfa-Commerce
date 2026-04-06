<?php

namespace Alfa\PhpRevolut\Requests;

defined('_JEXEC') or die;

/** Request payload for POST /api/orders/{id}/capture */
final class OrderCapture
{
    public function __construct(
        public readonly int $amount,  // minor units — capture a specific amount
    ) {
    }

    public function toArray(): array
    {
        return ['amount' => $this->amount];
    }
}
