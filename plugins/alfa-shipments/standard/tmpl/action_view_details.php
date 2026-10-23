<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * Shipment View Details - Standard Plugin
 *
 * Rendered on the action page after the plugin's onExecuteAction() called
 *   $event->setLayout('action_view_details'); $event->setLayoutData([...]);
 *
 * Path: plugins/alfa-shipments/standard/tmpl/action_view_details.php
 *
 * @since  1.0.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

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

            <dt class="col-sm-4"><?php echo Text::_('PLG_ALFA_SHIPMENTS_STANDARD_SHIPMENT_METHOD'); ?></dt>
            <dd class="col-sm-8"><?php echo htmlspecialchars($shipment->shipment_method_name ?? $shipment->carrier_name ?? '-'); ?></dd>

            <dt class="col-sm-4"><?php echo Text::_('PLG_ALFA_SHIPMENTS_STANDARD_TRACKING_NUMBER'); ?></dt>
            <dd class="col-sm-8"><?php echo htmlspecialchars($shipment->tracking_number ?? '-'); ?></dd>

            <dt class="col-sm-4"><?php echo Text::_('PLG_ALFA_SHIPMENTS_STANDARD_SHIPPING_COST'); ?></dt>
            <dd class="col-sm-8"><?php echo $costDisplay; ?></dd>

			<?php if ($weightVal > 0): ?>
                <dt class="col-sm-4"><?php echo Text::_('PLG_ALFA_SHIPMENTS_STANDARD_WEIGHT'); ?></dt>
                <dd class="col-sm-8"><?php echo number_format($weightVal, 2); ?> kg</dd>
			<?php endif; ?>

			<?php if (!empty($shipment->items)): ?>
                <dt class="col-sm-4"><?php echo Text::_('PLG_ALFA_SHIPMENTS_STANDARD_ITEMS'); ?></dt>
                <dd class="col-sm-8"><?php echo htmlspecialchars($shipment->items); ?></dd>
			<?php endif; ?>

            <dt class="col-sm-4"><?php echo Text::_('PLG_ALFA_SHIPMENTS_STANDARD_STATUS'); ?></dt>
            <dd class="col-sm-8">
                <span class="badge <?php echo $statusBadge; ?>"><?php echo htmlspecialchars(Text::_('PLG_ALFA_SHIPMENTS_STANDARD_STATUS_' . strtoupper($status))); ?></span>
            </dd>

            <dt class="col-sm-4"><?php echo Text::_('PLG_ALFA_SHIPMENTS_STANDARD_DATE_ADDED'); ?></dt>
            <dd class="col-sm-8"><?php echo htmlspecialchars($shipment->added ?? '-'); ?></dd>

        </dl>
    </div>
</div>