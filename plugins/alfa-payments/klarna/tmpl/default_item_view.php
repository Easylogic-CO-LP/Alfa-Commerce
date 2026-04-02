<?php defined('_JEXEC') or die;
use Alfa\Plugin\AlfaPayments\Klarna\Assets;
extract($displayData); if (!isset($method)) return; ?>
<div class="klarna-item-badge d-flex align-items-center gap-2 mt-2">
    <?php echo Assets::LOGO_SVG; ?>
    <small class="text-muted">Buy now, pay later with Klarna.</small>
</div>
