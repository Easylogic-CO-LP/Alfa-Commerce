<?php defined('_JEXEC') or die;
use Alfa\Plugin\AlfaPayments\PayPal\Assets;

extract($displayData);
if (!isset($method)) {
    return;
} ?>
<div class="paypal-item-badge d-flex align-items-center gap-2 mt-2">
    <?php echo Assets::LOGO_SVG; ?>
    <small class="text-muted">Pay securely with your PayPal account or card.</small>
</div>
