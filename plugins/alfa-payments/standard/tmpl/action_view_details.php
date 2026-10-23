<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * Payment View Details - Standard Plugin
 *
 * Rendered on the action page after the plugin's onExecuteAction() called
 *   $event->setLayout('action_view_details'); $event->setLayoutData([...]);
 *
 * Path: plugins/alfa-payments/standard/tmpl/action_view_details.php
 *
 * @since  1.0.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$payment = $displayData['payment'] ?? null;
$order = $displayData['order'] ?? null;

if (!$payment) {
    return;
}

$status = $payment->transaction_status ?? $payment->status ?? 'pending';

$statusBadge = match ($status) {
    'completed', 'paid' => 'bg-success',
    'pending', '' => 'bg-warning text-dark',
    'cancelled' => 'bg-danger',
    'refunded' => 'bg-secondary',
    default => 'bg-secondary',
};

// Handle Money object or raw float
$amount = $payment->amount ?? 0;
$amountDisplay = (is_object($amount) && method_exists($amount, 'format'))
    ? $amount->format()
    : number_format((float) $amount, 2);
?>

<div class="card">
    <div class="card-body">
        <dl class="row mb-0">

			<?php if (!empty($payment->payment_method)): ?>
                <dt class="col-sm-4"><?php echo Text::_('PLG_ALFA_PAYMENTS_STANDARD_PAYMENT_METHOD'); ?></dt>
                <dd class="col-sm-8"><?php echo htmlspecialchars($payment->payment_method); ?></dd>
			<?php endif; ?>

            <dt class="col-sm-4"><?php echo Text::_('PLG_ALFA_PAYMENTS_STANDARD_AMOUNT'); ?></dt>
            <dd class="col-sm-8"><?php echo $amountDisplay; ?></dd>

			<?php if (!empty($payment->transaction_id ?? '')): ?>
                <dt class="col-sm-4"><?php echo Text::_('PLG_ALFA_PAYMENTS_STANDARD_TRANSACTION_ID'); ?></dt>
                <dd class="col-sm-8"><code><?php echo htmlspecialchars($payment->transaction_id); ?></code></dd>
			<?php endif; ?>

            <dt class="col-sm-4"><?php echo Text::_('PLG_ALFA_PAYMENTS_STANDARD_STATUS'); ?></dt>
            <dd class="col-sm-8">
                <span class="badge <?php echo $statusBadge; ?>"><?php echo htmlspecialchars(Text::_('PLG_ALFA_PAYMENTS_STANDARD_STATUS_' . strtoupper($status))); ?></span>
            </dd>

            <dt class="col-sm-4"><?php echo Text::_('PLG_ALFA_PAYMENTS_STANDARD_DATE_ADDED'); ?></dt>
            <dd class="col-sm-8"><?php echo htmlspecialchars($payment->added ?? '-'); ?></dd>

        </dl>
    </div>
</div>