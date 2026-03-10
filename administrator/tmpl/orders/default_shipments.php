<?php
/**
 * Orders List — Detail Panel: Shipments
 *
 * Renders the shipments section inside the detail panel.
 * Each shipment shows: #ID, method, carrier, status badge,
 * cost, tracking number, dates (added / shipped / delivered),
 * and a .dp-actions placeholder that AJAX fills with plugin
 * action buttons (Mark Shipped, Mark Delivered, etc.) when
 * the panel opens.
 *
 * Receives per-row data via:
 *   $this->currentItem — order object (reads _shipments[])
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$item = $this->currentItem;
?>
<div class="dp-section">

    <div class="dp-hdr dp-hdr-shipments">
        <?php echo Text::_('COM_ALFA_SHIPMENTS'); ?>
        <span class="badge bg-light text-dark"><?php echo count($item->_shipments); ?></span>
    </div>

    <?php foreach ($item->_shipments as $si => $os) : ?>
        <div style="padding:8px 12px;
                    <?php echo $si > 0 ? 'border-top:1px solid #f0f0f0;' : ''; ?>
                    font-size:0.88em">

            <!-- ── Row 1: Method + carrier + status ──────────── -->
            <div class="d-flex justify-content-between align-items-start mb-1">
                <div>
                    <span class="text-muted" style="font-size:0.82em">#<?php echo (int) $os->id; ?></span>
                    <strong class="ms-1"><?php echo $this->escape($os->shipment_method_name ?? '—'); ?></strong>
                    <?php if (!empty($os->carrier_name)) : ?>
                        <small class="text-muted ms-1">(<?php echo $this->escape($os->carrier_name); ?>)</small>
                    <?php endif; ?>
                </div>
                <span class="sts sts-<?php echo $os->status ?? 'pending'; ?>">
                    <?php echo ucfirst($os->status ?? 'pending'); ?>
                </span>
            </div>

            <!-- ── Row 2: Cost + tracking ────────────────────── -->
            <div class="d-flex gap-3 text-muted" style="font-size:0.9em">
                <span>
                    <?php echo Text::_('COM_ALFA_COST'); ?>:
                    <strong class="text-dark">
                        <?php echo $os->shipping_cost_formatted ?? number_format((float) ($os->shipping_cost_tax_incl ?? 0), 2); ?>
                    </strong>
                </span>
                <?php if (!empty($os->tracking_number)) : ?>
                    <span>
                        <?php echo Text::_('COM_ALFA_TRACKING'); ?>:
                        <span class="text-mono text-dark"><?php echo $this->escape($os->tracking_number); ?></span>
                    </span>
                <?php endif; ?>
            </div>

            <!-- ── Row 3: Dates ──────────────────────────────── -->
            <div class="text-muted" style="font-size:0.82em;margin-top:2px">
                <?php echo HTMLHelper::_('date', $os->added, 'Y-m-d H:i'); ?>

                <?php if (!empty($os->shipped)) : ?>
                    · <?php echo Text::_('COM_ALFA_SHIPPED'); ?>:
                    <?php echo HTMLHelper::_('date', $os->shipped, 'Y-m-d'); ?>
                <?php endif; ?>

                <?php if (!empty($os->delivered)) : ?>
                    · <?php echo Text::_('COM_ALFA_DELIVERED'); ?>:
                    <?php echo HTMLHelper::_('date', $os->delivered, 'Y-m-d'); ?>
                <?php endif; ?>
            </div>

            <!-- ── Action buttons placeholder ───────────────── -->
            <!-- Filled by loadOrderActions() in orders-list.js  -->
            <!-- when the detail panel opens via Bootstrap collapse. -->
            <div class="dp-actions"
                 data-entity="shipment"
                 data-entity-id="<?php echo (int) $os->id; ?>"></div>

        </div>
    <?php endforeach; ?>

</div>
