<?php

namespace Alfa\PhpViva;

defined('_JEXEC') or die;

use Joomla\CMS\Http\HttpFactory;

/**
 * HTTP transport trait shared by all SDK classes.
 * Uses Joomla\CMS\Http\HttpFactory — zero external dependencies.
 *
 * Tracks the last HTTP status code so callers can branch on e.g. 403
 * without parsing the error string.
 */
trait Request
{
    private string $clientId;
    private string $clientSecret;
    private bool $testMode = false;
    private ?string $error = null;
    private int $lastHttpCode = 0; // last raw HTTP status code from httpRequest()

    public function setClientId(string $clientId): static
    {
        $this->clientId = $clientId;
        return $this;
    }
    public function getClientId(): string
    {
        return $this->clientId ?? '';
    }
    public function setClientSecret(string $s): static
    {
        $this->clientSecret = $s;
        return $this;
    }
    public function getClientSecret(): string
    {
        return $this->clientSecret ?? '';
    }
    public function setTestMode(bool $testMode): static
    {
        $this->testMode = $testMode;
        return $this;
    }
    public function getTestMode(): bool
    {
        return $this->testMode;
    }
    public function getError(): ?string
    {
        return $this->error;
    }
    public function getLastHttpCode(): int
    {
        return $this->lastHttpCode;
    }

    protected function setError(?string $error): static
    {
        $this->error = $error;
        return $this;
    }

    /**
     * Dispatch an HTTP request via Joomla HttpFactory.
     * Sets $this->lastHttpCode so callers can detect specific status codes (e.g. 403).
     */
    protected function httpRequest(string $method, string $url, string $body, array $headers): ?object
    {
        $http = (new HttpFactory())->getHttp();
        $response = match (strtoupper($method)) {
            'GET' => $http->get($url, $headers),
            'POST' => $http->post($url, $body ?: null, $headers),
            'DELETE' => $http->delete($url, $headers),
            default => $http->post($url, $body ?: null, $headers),
        };

        // Joomla 5: $response->code / $response->body
        // Joomla 6: PSR-7 — use getStatusCode() / getBody()
        $code = method_exists($response, 'getStatusCode')
            ? (int) $response->getStatusCode()
            : (int) ($response->code ?? 0);
        $raw = method_exists($response, 'getBody')
            ? (string) $response->getBody()
            : (string) ($response->body ?? '');

        $this->lastHttpCode = $code;
        $result = @json_decode($raw);

        if ($code < 200 || $code >= 300) {
            $this->setError($raw);
            if (!empty($result->error)) {
                $this->setError($result->error);
            } elseif (!empty($result->message)) {
                $this->setError($result->message);
            } elseif (!empty($result->ErrorText)) {
                $this->setError($result->ErrorText);
            }
            if (empty($this->getError())) {
                $this->setError('HTTP error ' . $code);
            }
            return null;
        }

        $this->setError(null);
        return $result;
    }
}
