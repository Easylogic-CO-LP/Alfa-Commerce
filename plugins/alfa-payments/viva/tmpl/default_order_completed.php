<?php
defined('_JEXEC') or die;
use Alfa\Plugin\AlfaPayments\Viva\Assets;
use Joomla\CMS\Language\Text;

extract($displayData);
if (!isset($order)) {
    return;
}
?>
<div class="viva-order-complete text-center py-4">
    <div class="mb-3"><?php echo Assets::LOGO_SVG; ?></div>
    <h3 class="text-success">
        <span class="icon-checkmark-circle" aria-hidden="true"></span>
        <?php echo Text::_('PLG_ALFA_PAYMENTS_VIVA_ORDER_COMPLETE_TITLE'); ?>
    </h3>
    <p class="text-muted">
        <?php echo Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_ORDER_COMPLETE_MSG', $order->id ?? ''); ?>
    </p>
</div>
