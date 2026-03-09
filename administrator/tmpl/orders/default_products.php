<?php
/**
 * Orders List — Detail Panel: Products Grid
 *
 * Renders the products section of the detail panel.
 * Shows a grid of all order items with: name, discount badge,
 * refund/return badges, SKU, qty, unit price (incl/excl),
 * line total (incl/excl), and fulfillment status.
 *
 * Receives per-row data via:
 *   $this->currentItem — order object (reads _items[])
 *
 * @package    Com_Alfa
 * @subpackage Administrator.View.Orders
 * @version    8.0.0
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2026 Easylogic CO LP
 * @license    GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$item = $this->currentItem;
?>
<div class="dp-section">

    <div class="dp-hdr dp-hdr-products">
        <?php echo Text::_('COM_ALFA_PRODUCTS'); ?>
        <span class="badge bg-light text-dark"><?php echo count($item->_items ?? []); ?></span>
    </div>

    <?php if (!empty($item->_items)) : ?>
        <div class="dp-grid dp-items">

            <!-- Grid header -->
            <div class="dp-grid-head">
                <div><?php echo Text::_('COM_ALFA_PRODUCT'); ?></div>
                <div class="text-center"><?php echo Text::_('COM_ALFA_SKU'); ?></div>
                <div class="text-center"><?php echo Text::_('COM_ALFA_QTY'); ?></div>
                <div class="text-end"><?php echo Text::_('COM_ALFA_UNIT_PRICE'); ?></div>
                <div class="text-end"><?php echo Text::_('COM_ALFA_TOTAL'); ?></div>
                <div class="text-center"><?php echo Text::_('COM_ALFA_STATUS'); ?></div>
            </div>

            <!-- Grid rows -->
            <?php foreach ($item->_items as $oi) :
                $oiSts       = $oi->shipment_status ?? '';
                $oiStsClass  = in_array($oiSts, ['shipped','delivered','pending','cancelled'], true)
                    ? $oiSts : 'unassigned';
                $oiStsLabel  = $oiSts ?: Text::_('COM_ALFA_UNASSIGNED');
                $hasDiscount = ((float) ($oi->reduction_percent ?? 0)) > 0
                    || ((float) ($oi->reduction_amount_tax_incl ?? 0)) > 0;
                $hasRefund   = ((int) ($oi->quantity_refunded ?? 0)) > 0;
                $hasReturn   = ((int) ($oi->quantity_return   ?? 0)) > 0;
                ?>
                <div class="dp-grid-row">

                    <!-- Product name + discount / refund / return badges -->
                    <div>
                        <strong><?php echo $this->escape($oi->name); ?></strong>

                        <?php if ($hasDiscount) : ?>
                            <small class="text-muted ms-1">
                                (−<?php echo ((float) ($oi->reduction_percent ?? 0)) > 0
                                    ? number_format((float) $oi->reduction_percent, 0) . '%'
                                    : ($oi->reduction_formatted ?? number_format((float) $oi->reduction_amount_tax_incl, 2)); ?>)
                            </small>
                        <?php endif; ?>

                        <?php if ($hasRefund) : ?>
                            <span class="badge bg-warning text-dark ms-1" style="font-size:0.7em">
                                <?php echo Text::_('COM_ALFA_REFUNDED'); ?> ×<?php echo (int) $oi->quantity_refunded; ?>
                            </span>
                        <?php endif; ?>

                        <?php if ($hasReturn) : ?>
                            <span class="badge bg-info text-dark ms-1" style="font-size:0.7em">
                                <?php echo Text::_('COM_ALFA_RETURNED'); ?> ×<?php echo (int) $oi->quantity_return; ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- SKU / Reference -->
                    <div class="text-center text-mono">
                        <?php echo $this->escape($oi->reference ?? '—'); ?>
                    </div>

                    <!-- Quantity -->
                    <div class="text-center"><?php echo (int) $oi->quantity; ?></div>

                    <!-- Unit price: tax incl above, excl below -->
                    <div class="text-end">
                        <?php echo $oi->unit_price_tax_incl_formatted ?? number_format((float) $oi->unit_price_tax_incl, 2); ?>
                        <small class="d-block text-muted">
                            <?php echo $oi->unit_price_tax_excl_formatted ?? number_format((float) $oi->unit_price_tax_excl, 2); ?>
                        </small>
                    </div>

                    <!-- Line total: tax incl above, excl below -->
                    <div class="text-end">
                        <strong>
                            <?php echo $oi->total_price_tax_incl_formatted ?? number_format((float) $oi->total_price_tax_incl, 2); ?>
                        </strong>
                        <small class="d-block text-muted">
                            <?php echo $oi->total_price_tax_excl_formatted ?? number_format((float) $oi->total_price_tax_excl, 2); ?>
                        </small>
                    </div>

                    <!-- Fulfillment status + tracking -->
                    <div class="text-center">
                        <span class="sts sts-<?php echo $oiStsClass; ?>"><?php echo ucfirst($oiStsLabel); ?></span>
                        <?php if (!empty($oi->shipment_tracking)) : ?>
                            <small class="d-block text-mono text-muted">
                                <?php echo $this->escape($oi->shipment_tracking); ?>
                            </small>
                        <?php endif; ?>
                    </div>

                </div>
            <?php endforeach; ?>

        </div>
    <?php else : ?>
        <div style="padding:10px 12px;color:#6c757d;font-size:0.88em">
            <?php echo Text::_('COM_ALFA_NO_ITEMS'); ?>
        </div>
    <?php endif; ?>

</div>
