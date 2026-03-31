<?php
/**
 * Klarna — order completion / thank-you page.
 *
 * @package  Alfa Commerce — Klarna
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

extract($displayData);

if (!isset($order)) {
    return;
}
?>
<div class="klarna-order-complete text-center py-4">
    <img src="https://x.klarnacdn.net/payment-method/assets/badges/generic/klarna.svg"
         alt="Klarna" height="40" loading="lazy" class="mb-3">
    <h3 class="text-success">
        <span class="icon-checkmark-circle" aria-hidden="true"></span>
        <?php echo Text::_('PLG_ALFA_PAYMENTS_KLARNA_ORDER_COMPLETE_TITLE'); ?>
    </h3>
    <p class="text-muted">
        <?php echo Text::sprintf('PLG_ALFA_PAYMENTS_KLARNA_ORDER_COMPLETE_MSG', $order->id ?? ''); ?>
    </p>
    <p class="small text-muted">
        <?php echo Text::_('PLG_ALFA_PAYMENTS_KLARNA_ORDER_COMPLETE_SHIP_NOTE'); ?>
    </p>
</div>
