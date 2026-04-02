<?php defined('_JEXEC') or die;
use Alfa\Plugin\AlfaPayments\Klarna\Assets;
extract($displayData); if (!isset($method)) return; ?>
<div class="klarna-cart-info d-flex align-items-start gap-3 p-3 border rounded">
    <div class="flex-shrink-0"><?php echo Assets::LOGO_SVG; ?></div>
    <div>
        <strong>Pay with Klarna</strong>
        <p class="mb-0 text-muted small mt-1">
            You will be redirected to Klarna's secure hosted payment page.
            Pay now, pay later, or split your purchase.
        </p>
    </div>
</div>
