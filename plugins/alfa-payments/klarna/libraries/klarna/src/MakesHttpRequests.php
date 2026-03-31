<?php
/**
 * @package     Alfa\PhpKlarna
 * @copyright   Copyright (C) Alfa. All rights reserved.
 * @license     MIT
 */

namespace Alfa\PhpKlarna;

defined('_JEXEC') or die;

use Exception;
use Joomla\Http\Response;
use Alfa\PhpKlarna\Exceptions\FailedActionException;
use Alfa\PhpKlarna\Exceptions\NotFoundException;
use Alfa\PhpKlarna\Exceptions\ValidationException;

/**
 * MakesHttpRequests
 *
 * All HTTP traffic goes through Joomla\CMS\Http\Http (ships with Joomla — no Guzzle).
 *
 * Joomla Response differences vs PSR-7 (Guzzle):
 *   $response->code   (int)    ← was $response->getStatusCode()
 *   $response->body   (string) ← was (string) $response->getBody()
 *
 * Because Joomla Http has no built-in base URI concept, $this->baseUri is
 * prepended to every relative path here in request().
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
	 * @return array|string  Decoded JSON array, or raw body string if not JSON.
	 *
	 * @throws ValidationException    HTTP 422
	 * @throws NotFoundException      HTTP 404
	 * @throws FailedActionException  HTTP 400 / 401 / 403
	 * @throws Exception              Any other non-2xx
	 */
	protected function request(string $verb, string $uri, array $payload = []): mixed
	{
		$url  = $this->baseUri . ltrim($uri, '/');
		$body = empty($payload)
			? null
			: json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		$httpMethod = strtolower($verb);

		if (in_array($httpMethod, ['get', 'delete'])) {
			$response = $this->client->$httpMethod($url, $this->defaultHeaders);
		} else {
			$response = $this->client->$httpMethod($url, $body, $this->defaultHeaders);
		}

		if (!$this->isSuccessful($response)) {
			$this->handleRequestError($response);
		}

		return json_decode($response->body, true) ?: $response->body;
	}

    /** True when the response carries a 2xx status code. */
    public function isSuccessful(Response $response): bool
    {
        return (int) substr((string) $response->code, 0, 1) === 2;
    }

    /** Map a non-2xx response to a typed exception. Always throws. */
    protected function handleRequestError(Response $response): void
    {
        match ($response->code) {
            422     => throw new ValidationException(json_decode($response->body, true) ?? ['error' => $response->body]),
            404     => throw new NotFoundException(),
            400     => throw new FailedActionException($response->body),
            401     => throw new FailedActionException('Unauthorized – check your Klarna API credentials.'),
            403     => throw new FailedActionException('Forbidden – your account may not have access to this resource.'),
            default => throw new Exception('Klarna API error (HTTP ' . $response->code . '): ' . $response->body),
        };
    }
}
