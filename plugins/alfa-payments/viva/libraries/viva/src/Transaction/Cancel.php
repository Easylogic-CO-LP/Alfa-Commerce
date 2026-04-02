<?php
namespace Alfa\PhpViva\Transaction;

defined('_JEXEC') or die;

/**
 * Cancel (void) or refund a transaction.
 *
 * PRIMARY:  Native Checkout v2 — OAuth2 Bearer token
 *   DELETE /nativecheckout/v2/transactions/{txId}?amount=X&sourceCode=Y
 *   Requires "Allow API Cancellation" enabled in Viva portal payment source.
 *
 * FALLBACK: Classic API — Basic Auth (merchantId:apiKey)
 *   DELETE https://www.vivapayments.com/api/transactions/{txId}?amount=X&sourceCode=Y
 *   Only requires "Allow refunds" in Viva portal → Settings → API Access.
 *   Triggered automatically when the primary call returns HTTP 403.
 *
 * Usage — fallback is transparent, just set Classic credentials:
 *   (new Cancel())
 *     ->setClientId($clientId)->setClientSecret($clientSecret)   // OAuth2
 *     ->setMerchantId($merchantId)->setApiKey($apiKey)           // Classic fallback
 *     ->setTestMode($testMode)->setSourceCode($sourceCode)
 *     ->setTransactionId($txId)->setAmount($amountCents)
 *     ->send();
 *
 * If merchantId/apiKey are not set, only the primary OAuth2 call is attempted.
 *
 * No amount → void (cancel pre-auth)
 * With amount → refund (partial or full)
 */
class Cancel extends Request
{
    const METHOD = 'DELETE';

    private ?string $transactionId = null;

    public function setTransactionId(string $id): static { $this->transactionId = $id; return $this; }
    public function getTransactionId(): ?string           { return $this->transactionId; }

    protected function getApiUrl(): string
    {
        $url    = parent::getApiUrl() . '/' . $this->getTransactionId();
        $params = [];

        if (!empty($this->getAmount()))     $params[] = 'amount='     . $this->getAmount();
        if (!empty($this->getSourceCode())) $params[] = 'sourceCode=' . $this->getSourceCode();

        return empty($params) ? $url : $url . '?' . implode('&', $params);
    }

    /**
     * Send with automatic Classic API fallback on HTTP 403.
     */
    public function send(): ?object
    {
        // Try Native Checkout v2 (OAuth2) first
        $result = parent::send();

        if ($result !== null) {
            return $result;
        }

        // Fallback: if 403 and Classic credentials are configured, try Classic API
        if ($this->getLastHttpCode() === 403
            && !empty($this->getMerchantId())
            && !empty($this->getApiKey()))
        {
            return $this->sendClassic();
        }

        return null;
    }

    /**
     * Classic API DELETE — Basic Auth, no portal permission needed.
     * Live: https://www.vivapayments.com/api/transactions/{txId}?amount=X&sourceCode=Y
     * Demo: https://demo.vivapayments.com/api/transactions/{txId}?amount=X&sourceCode=Y
     */
    private function sendClassic(): ?object
    {
        $host   = $this->getTestMode() ? 'demo.vivapayments.com' : 'www.vivapayments.com';
        $params = [];

        if (!empty($this->getAmount()))     $params['amount']     = $this->getAmount();
        if (!empty($this->getSourceCode())) $params['sourceCode'] = $this->getSourceCode();

        $url = 'https://' . $host . '/api/transactions/' . urlencode((string) $this->getTransactionId());
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $credentials = base64_encode($this->getMerchantId() . ':' . $this->getApiKey());
        $headers     = [
            'Authorization' => 'Basic ' . $credentials,
            'Accept'        => 'application/json',
        ];

        $result = $this->httpRequest('DELETE', $url, '', $headers);

        if ($result === null) {
            return null; // error already set by httpRequest
        }

        // Classic API returns capital-case fields — normalize to match v2 format
        $normalized = new \stdClass();
        $normalized->transactionId = $result->TransactionId ?? $result->transactionId ?? null;
        $normalized->statusId      = $result->StatusId      ?? $result->statusId      ?? null;
        $normalized->amount        = $result->Amount        ?? $result->amount        ?? null;
        $normalized->errorCode     = $result->ErrorCode     ?? $result->errorCode     ?? 0;
        $normalized->errorText     = $result->ErrorText     ?? $result->errorText     ?? null;

        if (!empty($normalized->errorCode)) {
            $this->setError($normalized->errorText ?? ('Classic API error code: ' . $normalized->errorCode));
            return null;
        }

        $this->setError(null);
        return $normalized;
    }
}
