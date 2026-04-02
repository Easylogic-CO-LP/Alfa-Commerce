<?php defined('_JEXEC') or die;
use Alfa\Plugin\AlfaPayments\PayPal\Assets;
extract($displayData); if (!isset($method)) return; ?>
<div class="paypal-cart-info d-flex align-items-start gap-3 p-3 border rounded">
    <div class="flex-shrink-0"><?php echo Assets::LOGO_SVG; ?></div>
    <div>
        <strong>Pay with PayPal</strong>
        <p class="mb-0 text-muted small mt-1">
            You will be redirected to PayPal's secure payment page.
            Pay with your PayPal account, credit card, or debit card.
        </p>
    </div>
</div>
