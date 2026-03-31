<?php defined('_JEXEC') or die;
use Alfa\Plugin\AlfaPayments\Viva\Assets;
extract($displayData); if (!isset($method)) return; ?>
<div class="viva-item-badge d-flex align-items-center gap-2 mt-2">
    <?php echo Assets::LOGO_SVG; ?>
    <small class="text-muted">Pay securely with card via Viva Wallet.</small>
</div>
