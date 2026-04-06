<?php
namespace Alfa\PhpRevolut\Resources;
defined('_JEXEC') or die;

use Alfa\PhpRevolut\Client;
use Alfa\PhpRevolut\Exceptions\MerchantException;
use Alfa\PhpRevolut\Requests\OrderCapture;
use Alfa\PhpRevolut\Requests\OrderCreate;
use Alfa\PhpRevolut\Requests\OrderRefund;
use Alfa\PhpRevolut\Responses\Order;

class OrderResource
{
    const ENDPOINT = '/orders';

    public function __construct(private Client $client) {}

    /**
     * Create a new payment order.
     * Returns the Order with checkout_url to redirect the customer.
     *
     * @throws MerchantException
     */
    public function create(OrderCreate $request): Order
    {
        $response = $this->client->post(self::ENDPOINT, $request->toArray());
        return Order::fromArray($response);
    }

    /**
     * Retrieve a payment order by its Revolut ID.
     *
     * @throws MerchantException
     */
    public function get(string $id): Order
    {
        $response = $this->client->get(self::ENDPOINT . '/' . urlencode($id));
        return Order::fromArray($response);
    }

    /**
     * Capture an authorised payment (capture_mode = MANUAL).
     * Call this when goods are ready to ship.
     *
     * @throws MerchantException
     */
    public function capture(string $id, OrderCapture $request): Order
    {
        $response = $this->client->post(self::ENDPOINT . '/' . urlencode($id) . '/capture', $request->toArray());
        return Order::fromArray($response);
    }

    /**
     * Cancel an order that has not been captured yet.
     *
     * @throws MerchantException
     */
    public function cancel(string $id): Order
    {
        $response = $this->client->post(self::ENDPOINT . '/' . urlencode($id) . '/cancel');
        return Order::fromArray($response);
    }

    /**
     * Create a full or partial refund for a completed order.
     *
     * @throws MerchantException
     */
    public function refund(string $id, OrderRefund $request): Order
    {
        $response = $this->client->post(self::ENDPOINT . '/' . urlencode($id) . '/refund', $request->toArray());
        return Order::fromArray($response);
    }
}
