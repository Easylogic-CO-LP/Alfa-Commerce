<?php defined('_JEXEC') or die;
use Alfa\Plugin\AlfaPayments\Viva\Assets;
extract($displayData); if (!isset($method)) return; ?>
<div class="viva-cart-info d-flex align-items-start gap-3 p-3 border rounded">
    <div class="flex-shrink-0"><?php echo Assets::LOGO_SVG; ?></div>
    <div>
        <strong>Pay by card via Viva</strong>
        <p class="mb-0 text-muted small mt-1">
            You will be redirected to Viva's secure hosted checkout page.
            Visa, Mastercard and American Express accepted.
        </p>
    </div>
</div>
