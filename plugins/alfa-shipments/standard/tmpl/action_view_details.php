<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * Shipment View Details — Standard Plugin
 *
 * Rendered by the controller when handler returns:
 *   ActionResult::withLayout('action_view_details', [...], 'Title')
 *
 * Path: plugins/alfa-shipments/standard/tmpl/action_view_details.php
 *
 * @since  3.0.0
 */

defined('_JEXEC') or die;

$shipment = $displayData['shipment'] ?? null;
$order = $displayData['order'] ?? null;

if (!$shipment) {
    return;
}

$status = $shipment->status ?? 'pending';

$statusBadge = match ($status) {
    'delivered' => 'bg-success',
    'shipped' => 'bg-primary',
    'cancelled' => 'bg-danger',
    'pending', '' => 'bg-secondary',
    default => 'bg-secondary',
};

// Handle Money object or raw float
$costIncl = $shipment->shipping_cost_tax_incl ?? 0;
$costDisplay = (is_object($costIncl) && method_exists($costIncl, 'format'))
    ? $costIncl->format()
    : number_format((float) $costIncl, 2);

$weight = $shipment->weight ?? 0;
$weightVal = is_object($weight) ? (float) ($weight->value ?? 0) : (float) $weight;
?>

<div class="card">
    <div class="card-body">
        <dl class="row mb-0">

            <dt class="col-sm-4">Shipping Method</dt>
            <dd class="col-sm-8"><?php echo htmlspecialchars($shipment->shipment_method_name ?? $shipment->carrier_name ?? '—'); ?></dd>

            <dt class="col-sm-4">Tracking Number</dt>
            <dd class="col-sm-8"><?php echo htmlspecialchars($shipment->tracking_number ?? '—'); ?></dd>

            <dt class="col-sm-4">Shipping Cost</dt>
            <dd class="col-sm-8"><?php echo $costDisplay; ?></dd>

			<?php if ($weightVal > 0): ?>
                <dt class="col-sm-4">Weight</dt>
                <dd class="col-sm-8"><?php echo number_format($weightVal, 2); ?> kg</dd>
			<?php endif; ?>

			<?php if (!empty($shipment->items)): ?>
                <dt class="col-sm-4">Items</dt>
                <dd class="col-sm-8"><?php echo htmlspecialchars($shipment->items); ?></dd>
			<?php endif; ?>

            <dt class="col-sm-4">Status</dt>
            <dd class="col-sm-8">
                <span class="badge <?php echo $statusBadge; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
            </dd>

            <dt class="col-sm-4">Date</dt>
            <dd class="col-sm-8"><?php echo htmlspecialchars($shipment->added ?? '—'); ?></dd>

        </dl>
    </div>
</div>