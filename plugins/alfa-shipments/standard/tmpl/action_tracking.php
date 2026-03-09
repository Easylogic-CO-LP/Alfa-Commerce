<?php
/**
 * Shipment Tracking — Standard Plugin
 *
 * Basic tracking info display. Carrier-specific plugins (ACS, DHL)
 * would override this with real-time API tracking data in their own tmpl/.
 *
 * Rendered by the controller when handler returns:
 *   ActionResult::withLayout('action_tracking', [...], 'Title')
 *
 * Path: plugins/alfa-shipments/standard/tmpl/action_tracking.php
 *
 * @since  3.0.0
 */

defined('_JEXEC') or die;

$shipment = $displayData['shipment'] ?? null;
$order    = $displayData['order'] ?? null;

if (!$shipment) {
	return;
}

$trackingNumber = $shipment->tracking_number ?? '';
$carrierName    = $shipment->shipment_method_name ?? $shipment->carrier_name ?? 'Standard';
$status         = $shipment->status ?? 'pending';
?>

<div class="card">
    <div class="card-body">
        <h5 class="card-title">
            <span class="icon-truck me-2"></span>
            Tracking Information
        </h5>
        <dl class="row mb-0">

            <dt class="col-sm-4">Carrier</dt>
            <dd class="col-sm-8"><?php echo htmlspecialchars($carrierName); ?></dd>

            <dt class="col-sm-4">Tracking Number</dt>
            <dd class="col-sm-8"><code><?php echo htmlspecialchars($trackingNumber); ?></code></dd>

            <dt class="col-sm-4">Status</dt>
            <dd class="col-sm-8">
                <span class="badge bg-info"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
            </dd>

        </dl>
    </div>
</div>