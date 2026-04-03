<?php

/**
 * @package     Alfa\PhpKlarna
 * @copyright   Copyright (C) Alfa. All rights reserved.
 * @license     MIT
 */

namespace Alfa\PhpKlarna;

defined('_JEXEC') or die;

use Alfa\PhpKlarna\Exceptions\FailedActionException;
use Alfa\PhpKlarna\Exceptions\NotFoundException;
use Alfa\PhpKlarna\Exceptions\ValidationException;
use Exception;

/**
 * MakesHttpRequests
 *
 * All HTTP traffic goes through Joomla\CMS\Http\Http (ships with Joomla — no Guzzle).
 *
 * Response compatibility:
 *   Joomla 5: public $response->code (int), $response->body (string)
 *   Joomla 6: PSR-7 Laminas — statusCode is private, use getStatusCode()/getBody()
 *   We use responseCode() / responseBody() helpers for compatibility.
 */
trait MakesHttpRequests
{
    protected function get(string $uri): mixed
    {
        return $this->request('GET', $uri);
    }

    protected function post(string $uri, array $payload = []): mixed
    {
        return $this->request('POST', $uri, $payload);
    }

    protected function put(string $uri, array $payload = []): mixed
    {
        return $this->request('PUT', $uri, $payload);
    }

    protected function patch(string $uri, array $payload = []): mixed
    {
        return $this->request('PATCH', $uri, $payload);
    }

    protected function delete(string $uri, array $payload = []): mixed
    {
        return $this->request('DELETE', $uri, $payload);
    }

    /**
     * Dispatch a request through the Joomla Http transport.
     *
     * @return array|string Decoded JSON array, or raw body string if not JSON.
     *
     * @throws ValidationException HTTP 422
     * @throws NotFoundException HTTP 404
     * @throws FailedActionException HTTP 400 / 401 / 403
     * @throws Exception Any other non-2xx
     */
    protected function request(string $verb, string $uri, array $payload = []): mixed
    {
        $url = $this->baseUri . ltrim($uri, '/');
        $body = empty($payload)
            ? null
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $response = match (strtoupper($verb)) {
            'GET' => $this->client->get($url, $this->defaultHeaders),
            'POST' => $this->client->post($url, $body, $this->defaultHeaders),
            'PUT' => $this->client->put($url, $body, $this->defaultHeaders),
            'PATCH' => $this->client->patch($url, $body, $this->defaultHeaders),
            'DELETE' => $this->client->delete($url, $this->defaultHeaders),
            default => $this->client->post($url, $body, $this->defaultHeaders),
        };

        if (!$this->isSuccessful($response)) {
            $this->handleRequestError($response);
        }

        $raw = $this->responseBody($response);
        return json_decode($raw, true) ?: $raw;
    }

    /** True when the response carries a 2xx status code. */
    public function isSuccessful(mixed $response): bool
    {
        return (int) substr((string) $this->responseCode($response), 0, 1) === 2;
    }

    /** Map a non-2xx response to a typed exception. Always throws. */
    protected function handleRequestError(mixed $response): void
    {
        $code = $this->responseCode($response);
        $body = $this->responseBody($response);

        match ($code) {
            422 => throw new ValidationException(json_decode($body, true) ?? ['error' => $body]),
            404 => throw new NotFoundException(),
            400 => throw new FailedActionException($body),
            401 => throw new FailedActionException('Unauthorized – check your Klarna API credentials.'),
            403 => throw new FailedActionException('Forbidden – your account may not have access to this resource.'),
            default => throw new Exception('Klarna API error (HTTP ' . $code . '): ' . $body),
        };
    }

    /**
     * Get HTTP status code from response.
     * Joomla 5: $response->code  |  Joomla 6 PSR-7: $response->getStatusCode()
     */
    private function responseCode(mixed $response): int
    {
        if (method_exists($response, 'getStatusCode')) {
            return (int) $response->getStatusCode();
        }
        return (int) ($response->code ?? 0);
    }

    /**
     * Get response body as string.
     * Joomla 5: $response->body  |  Joomla 6 PSR-7: (string) $response->getBody()
     */
    private function responseBody(mixed $response): string
    {
        if (method_exists($response, 'getBody')) {
            return (string) $response->getBody();
        }
        return (string) ($response->body ?? '');
    }
}
