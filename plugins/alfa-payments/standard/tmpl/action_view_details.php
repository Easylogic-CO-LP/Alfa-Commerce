<?php
/**
 * Payment View Details — Standard Plugin
 *
 * Rendered by the controller when handler returns:
 *   ActionResult::withLayout('action_view_details', [...], 'Title')
 *
 * Path: plugins/alfa-payments/standard/tmpl/action_view_details.php
 *
 * @since  3.0.0
 */

defined('_JEXEC') or die;

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
                <dt class="col-sm-4">Payment Method</dt>
                <dd class="col-sm-8"><?php echo htmlspecialchars($payment->payment_method); ?></dd>
			<?php endif; ?>

            <dt class="col-sm-4">Amount</dt>
            <dd class="col-sm-8"><?php echo $amountDisplay; ?></dd>

			<?php if (!empty($payment->transaction_id ?? '')): ?>
                <dt class="col-sm-4">Transaction ID</dt>
                <dd class="col-sm-8"><code><?php echo htmlspecialchars($payment->transaction_id); ?></code></dd>
			<?php endif; ?>

            <dt class="col-sm-4">Status</dt>
            <dd class="col-sm-8">
                <span class="badge <?php echo $statusBadge; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
            </dd>

            <dt class="col-sm-4">Date</dt>
            <dd class="col-sm-8"><?php echo htmlspecialchars($payment->added ?? '—'); ?></dd>

        </dl>
    </div>
</div>