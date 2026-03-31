<?php
namespace Alfa\PhpViva\SmartCheckout;

defined('_JEXEC') or die;

use Alfa\PhpViva\Account\Authorization as AccountAuthorization;

/**
 * Abstract base for all Smart Checkout API calls.
 * Handles OAuth2 token acquisition and JSON request dispatch.
 */
abstract class Request implements \JsonSerializable
{
    use \Alfa\PhpViva\Request;

    const URI    = '';
    const METHOD = 'GET';

    private ?string $accessToken = null;

    /**
     * Lazily fetch the OAuth2 access token from the Viva accounts endpoint.
     */
    public function getAccessToken(): ?string
    {
        if (empty($this->accessToken)) {
            $auth  = (new AccountAuthorization())
                ->setClientId($this->getClientId())
                ->setClientSecret($this->getClientSecret())
                ->setTestMode($this->getTestMode());

            $token = $auth->getAccessToken();

            if (!empty($auth->getError())) {
                $this->setError($auth->getError());
                return null;
            }

            $this->accessToken = $token;
        }

        return $this->accessToken;
    }

    public function setAccessToken(string $token): static
    {
        $this->accessToken = $token;
        return $this;
    }

    /**
     * Send the request to the Viva Smart Checkout API.
     *
     * @return object|null  Decoded JSON response, or null on error.
     */
    public function send(): ?object
    {
        $token = $this->getAccessToken();

        if (empty($token)) {
            return null;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ];

        $body = '';
        if (!in_array(static::METHOD, ['GET', 'DELETE'], true)) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($this, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $this->httpRequest(static::METHOD, $this->getApiUrl(), $body, $headers);
    }

    public function jsonSerialize(): array
    {
        return [];
    }

    protected function getApiUrl(): string
    {
        return Url::getUrl($this->getTestMode()) . static::URI;
    }
}
