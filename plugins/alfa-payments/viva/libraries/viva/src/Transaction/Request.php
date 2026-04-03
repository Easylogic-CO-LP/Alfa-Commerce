<?php

namespace Alfa\PhpViva\Transaction;

defined('_JEXEC') or die;

use Alfa\PhpViva\Account\Authorization as AccountAuthorization;
use JsonSerializable;

abstract class Request implements JsonSerializable
{
    use \Alfa\PhpViva\Request;

    public const URI = '/nativecheckout/v2/transactions';
    public const METHOD = '';

    private ?string $sourceCode = null;
    private ?int $amount = null;
    private ?string $accessToken = null;
    private array $extraHeaders = [];
    private array $expectedResult = ['transactionId' => 'Transaction id'];

    // Classic API credentials — used as fallback when OAuth2 call returns 403
    private ?string $merchantId = null;
    private ?string $apiKey = null;

    public function setSourceCode(string|int $code): static
    {
        $this->sourceCode = (string) $code;
        return $this;
    }
    public function getSourceCode(): ?string
    {
        return $this->sourceCode;
    }
    public function setAmount(int $amount): static
    {
        $this->amount = $amount;
        return $this;
    }
    public function getAmount(): ?int
    {
        return $this->amount;
    }
    public function setHeaders(array $h): static
    {
        $this->extraHeaders = $h;
        return $this;
    }
    public function getHeaders(): array
    {
        return $this->extraHeaders;
    }
    public function setExpectedResult(array $r): static
    {
        $this->expectedResult = $r;
        return $this;
    }
    public function getExpectedResult(): array
    {
        return $this->expectedResult;
    }

    // Classic API credentials (optional — enables fallback on 403)
    public function setMerchantId(string $id): static
    {
        $this->merchantId = $id;
        return $this;
    }
    public function getMerchantId(): ?string
    {
        return $this->merchantId;
    }
    public function setApiKey(string $key): static
    {
        $this->apiKey = $key;
        return $this;
    }
    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function getAccessToken(): ?string
    {
        if (empty($this->accessToken)) {
            $auth = (new AccountAuthorization())
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

    public function send(): ?object
    {
        $token = $this->getAccessToken();
        if (empty($token)) {
            return null;
        }

        $headers = array_merge(
            ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
            $this->getHeaders(),
        );

        $body = '';
        if (!in_array(static::METHOD, ['DELETE', 'GET'], true)) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($this, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $result = $this->httpRequest(static::METHOD, $this->getApiUrl(), $body, $headers);

        if (!empty($this->getError())) {
            return null;
        }

        foreach ($this->getExpectedResult() as $key => $label) {
            if (!is_object($result) || !property_exists($result, $key)) {
                $this->setError($label . ' is absent in response');
                return null;
            }
        }

        return $result;
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
