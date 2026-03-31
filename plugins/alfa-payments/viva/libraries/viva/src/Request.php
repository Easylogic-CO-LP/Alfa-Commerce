<?php
namespace Alfa\PhpViva;

defined('_JEXEC') or die;

use Joomla\Http\HttpFactory;
use Joomla\Uri\Uri;

/**
 * HTTP transport trait shared by all SDK classes.
 * Uses Joomla\CMS\Http\HttpFactory — zero external dependencies.
 * Response: $response->code (int), $response->body (string).
 */
trait Request
{
    private string  $clientId;
    private string  $clientSecret;
    private bool    $testMode = false;
    private ?string $error    = null;

    public function setClientId(string $clientId): static
    {
        $this->clientId = $clientId;
        return $this;
    }

    public function getClientId(): string { return $this->clientId ?? ''; }

    public function setClientSecret(string $clientSecret): static
    {
        $this->clientSecret = $clientSecret;
        return $this;
    }

    public function getClientSecret(): string { return $this->clientSecret ?? ''; }

    public function setTestMode(bool $testMode): static
    {
        $this->testMode = $testMode;
        return $this;
    }

    public function getTestMode(): bool { return $this->testMode; }

    public function getError(): ?string { return $this->error; }

    private function setError(?string $error): static
    {
        $this->error = $error;
        return $this;
    }

    /**
     * Dispatch an HTTP request via Joomla HttpFactory.
     *
     * @param string $method   GET | POST | DELETE
     * @param string $url      Full URL
     * @param string $body     Raw body (JSON, form-encoded, or empty string)
     * @param array  $headers  HTTP headers
     *
     * @return object|null  Decoded JSON body, or null on error.
     */
    protected function httpRequest(string $method, string $url, string $body, array $headers): ?object
    {
	    $http = (new HttpFactory)->getAvailableDriver();
	    $uri = new Uri($url);
	    $response = $http->request(strtoupper($method), $uri, $body ?: null, $headers);

	    $httpCode = $response->getStatusCode();
	    $httpBody = $response->getBody();
        $result = @json_decode($httpBody);

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->setError($httpBody);

            if (!empty($result->error))   $this->setError($result->error);
            elseif (!empty($result->message)) $this->setError($result->message);

            if (empty($this->getError())) {
                $this->setError('HTTP error ' . $httpCode);
            }

            return null;
        }

        $this->setError(null);
        return $result;
    }
}
