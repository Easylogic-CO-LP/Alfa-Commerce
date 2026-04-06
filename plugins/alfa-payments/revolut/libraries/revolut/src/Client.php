<?php

/**
 * Alfa\PhpRevolut — Revolut Merchant API client.
 *
 * HTTP transport: Joomla\Http\HttpFactory (PSR-7 response).
 * Response is PSR-7: getStatusCode() and getBody(), NOT ->code / ->body.
 *
 * Targets:  merchant.revolut.com  (Merchant API — for e-commerce checkout)
 * NOT:      b2b.revolut.com       (Business API — peer-to-peer transfers)
 */

namespace Alfa\PhpRevolut;

defined('_JEXEC') or die;

use Alfa\PhpRevolut\Exceptions\MerchantException;
use Alfa\PhpRevolut\Resources\OrderResource;
use Joomla\Http\HttpFactory;

class Client
{
    public const SANDBOX_URL = 'https://sandbox-merchant.revolut.com';
    public const PRODUCTION_URL = 'https://merchant.revolut.com';
    public const API_ENDPOINT = '/api';
    public const API_VERSION = '2024-09-01'; // Sent as Revolut-Api-Version header

    private string $apiKey;
    private bool $sandbox;

    public OrderResource $order;

    public function __construct(string $apiKey, bool $sandbox = false)
    {
        $this->apiKey = $apiKey;
        $this->sandbox = $sandbox;
        $this->order = new OrderResource($this);
    }

    public function baseUri(): string
    {
        return ($this->sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL) . self::API_ENDPOINT;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  HTTP METHODS — Joomla HttpFactory
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @throws MerchantException
     */
    public function post(string $endpoint, array $payload = []): array
    {
        return $this->request('POST', $endpoint, $payload);
    }

    /**
     * @throws MerchantException
     */
    public function get(string $endpoint, array $query = []): array
    {
        $url = $this->baseUri() . $endpoint;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        return $this->request('GET', $url, []);
    }

    /**
     * @throws MerchantException
     */
    public function patch(string $endpoint, array $payload = []): array
    {
        return $this->request('PATCH', $endpoint, $payload);
    }

    /**
     * @throws MerchantException
     */
    public function delete(string $endpoint): array
    {
        return $this->request('DELETE', $endpoint, []);
    }

    /**
     * Dispatch an HTTP request via Joomla HttpFactory.
     *
     * PSR-7 response — use getStatusCode() and getBody(), NOT ->code / ->body.
     * The joomla/http package was updated to PSR-7; the old public properties
     * no longer exist and silently return null, causing ->code to cast to 0.
     *
     * @throws MerchantException on HTTP error or invalid JSON
     */
    private function request(string $method, string $endpoint, array $payload): array
    {
        $url = str_starts_with($endpoint, 'http') ? $endpoint : $this->baseUri() . $endpoint;

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'Revolut-Api-Version' => self::API_VERSION,
        ];

        $body = null;
        if (!empty($payload) && !in_array($method, ['GET', 'DELETE'], true)) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $http = (new HttpFactory())->getHttp();

        // Joomla HTTP has no generic request() — use the specific method.
        // Timeout (15s) passed as third argument per Joomla HTTP API.
        $response = match (strtoupper($method)) {
            'GET' => $http->get($url, $headers, 15),
            'POST' => $http->post($url, $body, $headers, 15),
            'PUT' => $http->put($url, $body, $headers, 15),
            'PATCH' => $http->patch($url, $body, $headers, 15),
            'DELETE' => $http->delete($url, $headers, 15),
            default => throw new MerchantException('Unsupported HTTP method: ' . $method),
        };

        // PSR-7: getStatusCode() and (string) getBody()
        $code = (int) $response->getStatusCode();
        $raw = (string) $response->getBody();
        $result = @json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $message = $result['message'] ?? $result['error'] ?? $raw ?? 'HTTP error ' . $code;
            throw new MerchantException($message, $code);
        }

        return $result ?? [];
    }
}
