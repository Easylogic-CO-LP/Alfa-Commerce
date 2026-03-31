<?php
namespace Alfa\PhpViva\Transaction;
defined('_JEXEC') or die;

/**
 * Capture a pre-authorized transaction.
 * POST /nativecheckout/v2/transactions/{transactionId}
 */
class Capture extends Request
{
    const METHOD = 'POST';

    private ?string $transactionId = null;

    public function setTransactionId(string $id): static { $this->transactionId = $id; return $this; }
    public function getTransactionId(): ?string           { return $this->transactionId; }

    protected function getApiUrl(): string
    {
        return parent::getApiUrl() . '/' . $this->getTransactionId();
    }

    public function jsonSerialize(): array
    {
        return ['amount' => $this->getAmount()];
    }
}
