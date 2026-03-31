<?php
defined('_JEXEC') or die;
$payment = $displayData['payment'] ?? null;
if (!$payment) return;
$status = $payment->transaction_status ?? 'pending';
$badge  = match ($status) {
    'completed','authorized' => 'bg-success', 'pending' => 'bg-warning text-dark',
    'cancelled' => 'bg-danger', 'refunded' => 'bg-secondary', default => 'bg-secondary',
};
$amount = $payment->amount ?? 0;
$amountDisplay = (is_object($amount) && method_exists($amount, 'format'))
    ? $amount->format() : number_format((float) $amount, 2);
?>
<div class="card"><div class="card-body">
    <dl class="row mb-0">
        <dt class="col-sm-4">Status</dt>
        <dd class="col-sm-8"><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></dd>
        <dt class="col-sm-4">Amount</dt>
        <dd class="col-sm-8"><?php echo $amountDisplay; ?></dd>
        <?php if (!empty($payment->transaction_id)): ?>
        <dt class="col-sm-4">Viva Transaction ID</dt>
        <dd class="col-sm-8"><code><?php echo htmlspecialchars($payment->transaction_id); ?></code></dd>
        <?php endif; ?>
        <dt class="col-sm-4">Date</dt>
        <dd class="col-sm-8"><?php echo htmlspecialchars($payment->added ?? '—'); ?></dd>
    </dl>
</div></div>
<?php if ($status === 'authorized'): ?>
<div class="alert alert-info mt-3 mb-0">
    Payment is <strong>authorized</strong>. Use <strong>Capture</strong> when goods are shipped.
</div>
<?php endif; ?>
