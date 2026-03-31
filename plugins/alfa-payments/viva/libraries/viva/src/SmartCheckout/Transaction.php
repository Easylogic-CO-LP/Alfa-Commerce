<?php
namespace Alfa\PhpViva\SmartCheckout;

defined('_JEXEC') or die;

/**
 * Verify a completed Smart Checkout transaction by its transaction ID.
 *
 * GET /checkout/v2/transactions/{transactionId}
 *
 * Called on the return visit (visit 2) after Viva redirects back with ?s=TRANSACTION_ID.
 * The response includes statusId, amount, orderCode, and other details.
 *
 * statusId 'F' = Final (successful)
 * statusId 'A' = Active/pending
 * statusId 'C' = Cancelled
 * statusId 'E' = Error
 */
class Transaction extends Request
{
    const METHOD = 'GET';

    private string $transactionId;

    public function setTransactionId(string $transactionId): static
    {
        $this->transactionId = $transactionId;
        return $this;
    }

    public function getTransactionId(): string
    {
        return $this->transactionId ?? '';
    }

    protected function getApiUrl(): string
    {
        return Url::getUrl($this->getTestMode())
            . '/checkout/v2/transactions/'
            . urlencode($this->getTransactionId());
    }
}
