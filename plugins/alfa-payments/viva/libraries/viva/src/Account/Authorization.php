<?php
namespace Alfa\PhpViva\Account;

defined('_JEXEC') or die;

/**
 * OAuth2 client_credentials token from the Viva accounts endpoint.
 * POST /connect/token with Basic auth and form-encoded body.
 */
class Authorization
{
    use \Alfa\PhpViva\Request;

    const URI    = '/connect/token';
    const METHOD = 'POST';

    public function getAccessToken(): ?string
    {
        $headers = [
            'Authorization' => 'Basic ' . base64_encode($this->getClientId() . ':' . $this->getClientSecret()),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ];

        $body   = http_build_query(['grant_type' => 'client_credentials']);
        $result = $this->httpRequest(static::METHOD, Url::getUrl($this->getTestMode()) . static::URI, $body, $headers);

        if (!empty($this->getError())) {
            return null;
        }

        if (empty($result->access_token)) {
            $this->setError('Access token absent in response');
            return null;
        }

        return $result->access_token;
    }
}
