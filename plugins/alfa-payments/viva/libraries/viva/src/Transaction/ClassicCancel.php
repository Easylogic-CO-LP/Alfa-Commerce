<?php
namespace Alfa\PhpViva\Transaction;

defined('_JEXEC') or die;

/**
 * Cancel (void) or refund a transaction via the Viva Classic API.
 *
 * Uses Basic Auth (Merchant ID + API Key) — no OAuth2 token needed.
 * Does NOT require "Allow API Cancellation" in the payment source.
 * Only requires Settings → API Access → Allow refunds.
 *
 * No amount → void (cancel pre-auth)
 * With amount → refund (partial or full)
 *
 * Live: DELETE https://www.vivapayments.com/api/transactions/{txId}?amount=X&sourceCode=Y
 * Demo: DELETE https://demo.vivapayments.com/api/transactions/{txId}?amount=X&sourceCode=Y
 *
 * Credentials: Viva portal → Settings → API Access → Merchant ID + API Key
 */
class ClassicCancel
{
    use \Alfa\PhpViva\Request;

    const LIVE_URL = 'https://www.vivapayments.com';
    const TEST_URL = 'https://demo.vivapayments.com';

    private string  $merchantId    = '';
    private string  $apiKey        = '';
    private ?string $transactionId = null;
    private ?string $sourceCode    = null;
    private ?int    $amount        = null;

    public function setMerchantId(string $id): static       { $this->merchantId    = $id;   return $this; }
    public function setApiKey(string $key): static          { $this->apiKey        = $key;  return $this; }
    public function setTransactionId(string $id): static    { $this->transactionId = $id;   return $this; }
    public function setSourceCode(string $code): static     { $this->sourceCode    = $code; return $this; }
    public function setAmount(int $amount): static          { $this->amount        = $amount; return $this; }

    public function send(): ?object
    {
        $base   = $this->testMode ? self::TEST_URL : self::LIVE_URL;
        $params = [];

        if ($this->amount !== null) {
            $params['amount'] = $this->amount;
        }
        if (!empty($this->sourceCode)) {
            $params['sourceCode'] = $this->sourceCode;
        }

        $url = $base . '/api/transactions/' . urlencode($this->transactionId ?? '');
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $headers = [
            'Authorization' => 'Basic ' . base64_encode($this->merchantId . ':' . $this->apiKey),
            'Accept'        => 'application/json',
        ];

        $result = $this->httpRequest('DELETE', $url, '', $headers);

        if (!empty($this->getError())) {
            return null;
        }

        return $result;
    }
}
