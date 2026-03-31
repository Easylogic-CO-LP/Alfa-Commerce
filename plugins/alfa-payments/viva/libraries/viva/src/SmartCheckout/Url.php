<?php
namespace Alfa\PhpViva\SmartCheckout;

defined('_JEXEC') or die;

use Alfa\PhpViva\Url as BaseUrl;

/**
 * Viva Smart Checkout URLs.
 *
 * Two separate domains:
 *   API base       — where your PHP calls go  (api.vivapayments.com)
 *   Checkout page  — where customers are sent (www.vivapayments.com/web/checkout)
 */
class Url extends BaseUrl
{
    // API base — used for creating orders and verifying transactions
    const LIVE_URL = 'https://api.vivapayments.com';
    const TEST_URL = 'https://demo-api.vivapayments.com';

    // Customer-facing hosted checkout page — append ?ref={orderCode}
    const LIVE_CHECKOUT = 'https://www.vivapayments.com/web/checkout';
    const TEST_CHECKOUT = 'https://demo.vivapayments.com/web/checkout';

    /**
     * Build the full redirect URL for the customer.
     *
     * @param int|string $orderCode  The orderCode returned by createOrder()
     * @param bool       $testMode
     * @return string
     */
    public static function checkoutUrl(int|string $orderCode, bool $testMode = false): string
    {
        $base = $testMode ? static::TEST_CHECKOUT : static::LIVE_CHECKOUT;
        return $base . '?ref=' . $orderCode;
    }
}
