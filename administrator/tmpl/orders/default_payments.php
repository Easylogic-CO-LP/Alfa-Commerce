<?php
/**
 * Orders List - Detail Panel: Payments
 *
 * Renders the payments section inside the detail panel.
 * Visual treatment matches edit_payments.php exactly:
 *
 *   Row 1 - #ID + method name + payment_type badge + "Refund for #N"
 *   Row 2 - Amount (red + "-" prefix for refunds) + transaction_id
 *   Row 3 - Refund type badge [Full/Partial] + refund reason (conditional)
 *   Row 4 - Added date + processed_at (when set)
 *   Row 5 - .dp-actions placeholder (filled by AJAX on panel open)
 *
 * Refund records get the theme's subtle warning row background, which flips
 * with the admin colour scheme; a fixed cream would leave light text on a
 * light row the moment an admin switches to dark.
 *
 * Badge colour maps mirror edit_payments.php:
 *   transaction_status: completed=green, pending=yellow, authorized=cyan,
 *                       failed=red, cancelled=grey, refunded=purple
 *   payment_type:       payment=blue, refund=red, authorization=cyan
 *   refund_type:        full=red, partial=yellow
 *
 * Requires these fields in $op (loaded by OrdersModel::batchLoadPayments):
 *   id, payment_method, amount, payment_type, transaction_status,
 *   transaction_id, id_refunded_payment, refund_type, refund_reason,
 *   added, processed_at
 *
 * Receives per-row data via:
 *   $this->currentItem - order object (reads _payments[])
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\OrderLabels;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$item = $this->currentItem;
?>
<div class="dp-section">

    <div class="dp-hdr dp-hdr-payments">
        <?php echo Text::_('COM_ALFA_PAYMENTS'); ?>
        <span class="badge bg-body-tertiary text-body-emphasis"><?php echo count($item->_payments); ?></span>
    </div>

    <?php foreach ($item->_payments as $pi => $op) :
        $opType        = (string) ($op->payment_type ?? 'payment');
        $isRefund      = $opType === 'refund';
        // Money out (refund, chargeback) and money back (a reversal).
        $isOut         = \in_array($opType, ['refund', 'chargeback'], true);
        $isBack        = $opType === 'reversal';
        $txnStatus     = $op->transaction_status ?? 'pending';
        $hasRefundData = !empty($op->refund_type) || !empty($op->refund_reason);

        // Badge colour maps (match edit_payments.php)
        $statusBadge = match ($txnStatus) {
            'completed'  => 'bg-success',
            'pending'    => 'bg-warning text-dark',
            'authorized' => 'bg-info text-dark',
            'failed'     => 'bg-danger',
            'cancelled'  => 'bg-secondary',
            'refunded'   => 'bg-purple',
            default      => 'bg-secondary',
        };

        $typeBadge = match ($opType) {
            'refund'        => 'bg-danger',
            'authorization' => 'bg-info text-dark',
            'reversal'      => 'bg-success',
            'chargeback'    => 'bg-dark',
            default         => 'bg-primary',
        };

        $refundTypeBadge = match ($op->refund_type ?? '') {
            'full'    => 'bg-danger',
            'partial' => 'bg-warning text-dark',
            default   => 'bg-secondary',
        };
        ?>
        <div style="padding:8px 12px;
                    <?php echo $pi > 0 ? 'border-top:1px solid var(--border-color-translucent, #f0f0f0);' : ''; ?>
                    <?php echo $isOut      ? 'background:var(--warning-bg-subtle, #fff8e1);' : ''; ?>
                    font-size:0.88em;">

            <!-- Row 1: Identity + transaction status -->
            <div class="d-flex justify-content-between align-items-start mb-1">
                <div>
                    <!-- #ID + method name -->
                    <span class="text-muted" style="font-size:0.82em">#<?php echo (int) $op->id; ?></span>
                    <strong class="ms-1"><?php echo $this->escape($op->payment_method ?? '-'); ?></strong>

                    <!-- Payment type badge: [Payment] / [Refund] / [Authorization] -->
                    <span class="badge <?php echo $typeBadge; ?> ms-1" style="font-size:0.78em">
                        <?php echo $this->escape(Text::_('COM_ALFA_PAYMENT_TYPE_' . strtoupper($opType))); ?>
                    </span>

                    <!-- "Refund for #N" / "Chargeback of #N" / "Reverses #N" -->
                    <?php if ($isOut && !empty($op->id_refunded_payment)) : ?>
                        <small class="text-muted ms-1">
                            <?php echo Text::_($isRefund ? 'COM_ALFA_REFUND_FOR' : 'COM_ALFA_CHARGEBACK_OF'); ?>
                            <strong>#<?php echo (int) $op->id_refunded_payment; ?></strong>
                        </small>
                    <?php elseif ($isBack && !empty($op->id_reversed_payment)) : ?>
                        <small class="text-muted ms-1">
                            <?php echo Text::_('COM_ALFA_REVERSAL_OF'); ?>
                            <strong>#<?php echo (int) $op->id_reversed_payment; ?></strong>
                        </small>
                    <?php endif; ?>
                </div>

                <!-- Transaction status badge (right-aligned) -->
                <span class="badge <?php echo $statusBadge; ?>">
                    <?php echo $this->escape(OrderLabels::transactionStatus((string) $txnStatus)); ?>
                </span>
            </div>

            <!-- Row 2: Amount + transaction ID -->
            <div class="d-flex gap-3 align-items-baseline" style="font-size:0.9em">

                <!-- Amount: "-" for money out, "+" for money back -->
                <span class="<?php echo $isOut ? 'text-danger-emphasis fw-bold' : ($isBack ? 'text-success fw-bold' : 'fw-bold'); ?>">
                    <?php echo $isOut ? '−' : ($isBack ? '+' : ''); ?>
                    <?php // The model formats these; a hard-coded € fallback
                          // would have quietly mislabelled every non-EUR shop. ?>
                    <?php echo $this->escape($op->amount_formatted ?? ''); ?>
                </span>

                <!-- Transaction ID: monospace, only when set -->
                <?php if (!empty($op->transaction_id)) : ?>
                    <span class="text-muted">
                        <?php echo Text::_('COM_ALFA_TXN'); ?>:
                        <code class="text-body-emphasis"><?php echo $this->escape($op->transaction_id); ?></code>
                    </span>
                <?php endif; ?>

            </div>

            <!-- Row 3: Refund details (conditional) -->
            <!-- Only rendered when this payment has refund data.  -->
            <!-- Skipped entirely for normal payment records.       -->
            <?php if ($hasRefundData) : ?>
                <div class="mt-1" style="font-size:0.85em">

                    <!-- [Full] or [Partial] badge -->
                    <?php if (!empty($op->refund_type)) : ?>
                        <span class="badge <?php echo $refundTypeBadge; ?>" style="font-size:0.8em">
                            <?php echo $this->escape(OrderLabels::refundType((string) $op->refund_type)); ?>
                        </span>
                    <?php endif; ?>

                    <!-- Refund reason text -->
                    <?php if (!empty($op->refund_reason)) : ?>
                        <small class="text-muted ms-1"><?php echo $this->escape($op->refund_reason); ?></small>
                    <?php endif; ?>

                </div>
            <?php endif; ?>

            <!-- Row 4: Dates -->
            <div class="text-muted mt-1" style="font-size:0.82em">
                <!-- Added date: always shown -->
                <?php echo HTMLHelper::_('date', $op->added, 'Y-m-d H:i'); ?>

                <!-- Processed at: only when gateway confirmed -->
                <?php if (!empty($op->processed_at)) : ?>
                    - <?php echo Text::_('COM_ALFA_PROCESSED'); ?>:
                    <?php echo HTMLHelper::_('date', $op->processed_at, 'Y-m-d H:i'); ?>
                <?php endif; ?>
            </div>

            <!-- Action buttons placeholder -->
            <!-- Filled by loadOrderActions() in orders-list.js  -->
            <!-- when the detail panel opens via Bootstrap collapse. -->
            <div class="dp-actions mt-1"
                 data-entity="payment"
                 data-entity-id="<?php echo (int) $op->id; ?>"></div>

        </div>
    <?php endforeach; ?>

</div>
