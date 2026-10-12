<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * Shipment Tracking - Standard Plugin
 *
 * Basic tracking info display. Carrier-specific plugins (one per courier)
 * would override this with real-time API tracking data in their own tmpl/.
 *
 * Rendered on the action page after the plugin's onExecuteAction() called
 *   $event->setLayout('action_tracking'); $event->setLayoutData([...]);
 *
 * Path: plugins/alfa-shipments/standard/tmpl/action_tracking.php
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

$trackingNumber = $displayData['tracking_number'] ?? ($shipment->tracking_number ?? '');
// Derived by the plugin from the method's URL template, never a stored column.
$trackingUrl = $displayData['tracking_url'] ?? '';
// The carrier actually handling the parcel, falling back to the method's
// snapshot name. Both are stored values, so neither is translated, but the
// last-resort dash must not be an English word.
$carrierName = $shipment->carrier_name ?: ($shipment->shipment_method_name ?: '-');
$status = $shipment->status ?? 'pending';
?>

<div class="card">
    <div class="card-body">
        <h5 class="card-title">
            <span class="icon-truck me-2"></span>
            <?php echo Text::_('PLG_ALFA_SHIPMENTS_STANDARD_TRACKING'); ?>
        </h5>
        <dl class="row mb-0">

            <dt class="col-sm-4"><?php echo Text::_('COM_ALFA_CARRIER'); ?></dt>
            <dd class="col-sm-8"><?php echo htmlspecialchars($carrierName); ?></dd>

            <dt class="col-sm-4"><?php echo Text::_('COM_ALFA_TRACKING_NUMBER'); ?></dt>
            <dd class="col-sm-8"><code><?php echo htmlspecialchars($trackingNumber); ?></code></dd>

            <dt class="col-sm-4"><?php echo Text::_('COM_ALFA_STATUS'); ?></dt>
            <dd class="col-sm-8">
                <span class="badge bg-info"><?php echo htmlspecialchars(Text::_('COM_ALFA_STATUS_' . strtoupper($status))); ?></span>
            </dd>

            <?php if ($trackingUrl !== '') : ?>
                <dt class="col-sm-4"><?php echo Text::_('PLG_ALFA_SHIPMENTS_STANDARD_TRACK'); ?></dt>
                <dd class="col-sm-8">
                    <a href="<?php echo htmlspecialchars($trackingUrl, ENT_QUOTES, 'UTF-8'); ?>"
                       target="_blank" rel="noopener noreferrer">
                        <?php echo htmlspecialchars($trackingUrl, ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </dd>
            <?php endif; ?>

        </dl>
    </div>
</div>