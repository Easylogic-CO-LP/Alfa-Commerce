<?php
namespace Alfa\PhpViva\Transaction;
defined('_JEXEC') or die;

/**
 * Cancel (void) or refund a transaction.
 * DELETE /nativecheckout/v2/transactions/{transactionId}?amount=X&sourceCode=Y
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
}
