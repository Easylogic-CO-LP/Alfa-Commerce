<?php
namespace Alfa\PhpRevolut\Responses;
defined('_JEXEC') or die;

/**
 * Represents a Revolut Merchant order response.
 * Plain class hydrated from API response array.
 *
 * Key fields for checkout flow:
 *   $id           — store as transaction_id in payment record
 *   $checkout_url — redirect customer here  (e.g. https://merchant.revolut.com/order/checkout/{public_id})
 *   $state        — PENDING | PROCESSING | AUTHORISED | COMPLETED | FAILED | CANCELLED
 *
 * States:
 *   PENDING    → order created, no payment started
 *   PROCESSING → payment in progress
 *   AUTHORISED → pre-auth captured, awaiting capture
 *   COMPLETED  → payment fully captured
 *   FAILED     → payment failed (customer stays on Revolut's retry page)
 *   CANCELLED  → cancelled
 */
final class Order
{
    public string  $id;
    public string  $state;
    public string  $type;
    public string  $created_at;
    public string  $updated_at;
    public ?string $checkout_url;   // redirect the customer here
    public ?string $public_id;      // used to build checkout_url if not directly returned
    public ?string $description;
    public ?string $capture_mode;
    public ?string $merchant_order_ext_ref;
    public ?string $completed_at;
    public ?string $customer_id;
    public ?string $email;
    public array   $payments = [];

    public static function fromArray(array $data): self
    {
        $order = new self();
        $order->id                     = $data['id'] ?? '';
        $order->state                  = $data['state'] ?? '';
        $order->type                   = $data['type'] ?? '';
        $order->created_at             = $data['created_at'] ?? '';
        $order->updated_at             = $data['updated_at'] ?? '';
        $order->description            = $data['description'] ?? null;
        $order->capture_mode           = $data['capture_mode'] ?? null;
        $order->merchant_order_ext_ref = $data['merchant_order_ext_ref'] ?? null;
        $order->completed_at           = $data['completed_at'] ?? null;
        $order->customer_id            = $data['customer_id'] ?? null;
        $order->email                  = $data['email'] ?? null;
        $order->payments               = $data['payments'] ?? [];
        $order->public_id              = $data['public_id'] ?? null;

        // checkout_url may be returned directly by the API.
        // Fall back to constructing it from public_id if absent.
        $order->checkout_url = $data['checkout_url']
            ?? (!empty($data['public_id'])
                ? 'https://merchant.revolut.com/order/checkout/' . $data['public_id']
                : null);

        return $order;
    }
}
