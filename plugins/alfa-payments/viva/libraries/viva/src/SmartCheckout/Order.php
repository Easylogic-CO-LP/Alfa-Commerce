<?php
namespace Alfa\PhpViva\SmartCheckout;

defined('_JEXEC') or die;

/**
 * Create a Viva Smart Checkout payment order.
 *
 * POST /checkout/v2/orders
 * Returns: { "orderCode": 6962462720138 }
 *
 * The orderCode is used to build the redirect URL:
 *   Url::checkoutUrl($orderCode, $testMode)
 * → https://www.vivapayments.com/web/checkout?ref=6962462720138
 *
 * Amount is in minor units (integer cents): €9.99 → 999
 */
class Order extends Request
{
    const URI    = '/checkout/v2/orders';
    const METHOD = 'POST';

    private int     $amount         = 0;
    private ?string $sourceCode     = null;
    private ?string $merchantTrns   = null;  // your internal reference (order ID)
    private ?string $customerTrns   = null;  // description shown to customer
    private bool    $preauth        = false;
    private int     $maxInstallments = 0;
    private bool    $allowRecurring  = false;
    private bool    $disableCash    = true;
    private bool    $disableWallet  = false;
    private int     $paymentTimeout = 3600;  // seconds

    // Customer info
    private ?string $customerEmail    = null;
    private ?string $customerFullname = null;
    private ?string $customerPhone    = null;
    private ?string $countryCode      = 'GR';
    private ?string $requestLang      = 'el-GR';

    // URLs — where Viva sends the customer after payment
    private ?string $successUrl    = null;
    private ?string $failureUrl    = null;

    public function setAmount(int $amount): static              { $this->amount = $amount; return $this; }
    public function getAmount(): int                            { return $this->amount; }
    public function setSourceCode(string $code): static         { $this->sourceCode = $code; return $this; }
    public function setMerchantTrns(string $ref): static        { $this->merchantTrns = $ref; return $this; }
    public function setCustomerTrns(string $desc): static       { $this->customerTrns = $desc; return $this; }
    public function setPreauth(bool $preauth): static           { $this->preauth = $preauth; return $this; }
    public function setMaxInstallments(int $n): static          { $this->maxInstallments = $n; return $this; }
    public function setCustomerEmail(string $email): static     { $this->customerEmail = $email; return $this; }
    public function setCustomerFullname(string $name): static   { $this->customerFullname = $name; return $this; }
    public function setCustomerPhone(string $phone): static     { $this->customerPhone = $phone; return $this; }
    public function setCountryCode(string $code): static        { $this->countryCode = $code; return $this; }
    public function setRequestLang(string $lang): static        { $this->requestLang = $lang; return $this; }
    public function setSuccessUrl(string $url): static          { $this->successUrl = $url; return $this; }
    public function setFailureUrl(string $url): static          { $this->failureUrl = $url; return $this; }
    public function setPaymentTimeout(int $seconds): static     { $this->paymentTimeout = $seconds; return $this; }
    public function setDisableCash(bool $v): static             { $this->disableCash = $v; return $this; }
    public function setDisableWallet(bool $v): static           { $this->disableWallet = $v; return $this; }

    /**
     * Create the payment order and return the raw response.
     * Access the orderCode via $result->orderCode.
     */
    public function send(): ?object
    {
        $result = parent::send();

        if (empty($result)) {
            return null;
        }

        if (empty($result->orderCode)) {
            $this->setError('orderCode absent in Viva response');
            return null;
        }

        return $result;
    }

    public function jsonSerialize(): array
    {
        $payload = [
            'amount'           => $this->amount,
            'preauth'          => $this->preauth,
            'allowRecurring'   => $this->allowRecurring,
            'maxInstallments'  => $this->maxInstallments,
            'paymentTimeout'   => $this->paymentTimeout,
            'paymentNotification' => true,
            'disableExactAmount'  => false,
            'disableCash'      => $this->disableCash,
            'disableWallet'    => $this->disableWallet,
            'tipAmount'        => 0,
            'tags'             => [],
        ];

        if (!empty($this->sourceCode))   $payload['sourceCode']   = $this->sourceCode;
        if (!empty($this->merchantTrns)) $payload['merchantTrns'] = $this->merchantTrns;
        if (!empty($this->customerTrns)) $payload['customerTrns'] = $this->customerTrns;

        // Redirect URLs — send only if provided (Viva falls back to source defaults otherwise)
        if (!empty($this->successUrl)) $payload['successUrl'] = $this->successUrl;
        if (!empty($this->failureUrl)) $payload['failureUrl'] = $this->failureUrl;

        // Customer block — include only if at least one field is set
        $customer = [];
        if (!empty($this->customerEmail))    $customer['email']       = $this->customerEmail;
        if (!empty($this->customerFullname)) $customer['fullname']    = $this->customerFullname;
        if (!empty($this->customerPhone))    $customer['phone']       = $this->customerPhone;
        if (!empty($this->countryCode))      $customer['countryCode'] = $this->countryCode;
        if (!empty($this->requestLang))      $customer['requestLang'] = $this->requestLang;
        if (!empty($customer))               $payload['customer']     = $customer;

        return $payload;
    }
}
