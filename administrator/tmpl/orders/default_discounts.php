<?php
/**
 * Orders List — Detail Panel: Discounts
 *
 * Renders the discounts / cart rules section inside the detail panel.
 * Shows each applied discount with its name, tax-excl value,
 * and a "Free Shipping" badge when applicable.
 *
 * Receives per-row data via:
 *   $this->currentItem — order object (reads _discounts[])
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

    <div class="dp-hdr dp-hdr-discounts">
        <?php echo Text::_('COM_ALFA_DISCOUNTS'); ?>
        <span class="badge bg-light text-dark"><?php echo count($item->_discounts); ?></span>
    </div>

    <?php foreach ($item->_discounts as $di => $cr) : ?>
        <div style="padding:6px 12px;
                    <?php echo $di > 0 ? 'border-top:1px solid #f0f0f0;' : ''; ?>
                    font-size:0.88em;
                    display:flex;
                    justify-content:space-between;
                    align-items:center">

            <!-- Discount / coupon name -->
            <span class="discount-tag"><?php echo $this->escape($cr->name); ?></span>

            <span>
                <!-- Discount value (tax excl) -->
                <strong class="text-danger">
                    −<?php echo $cr->value_formatted ?? number_format((float) $cr->value_tax_excl, 2); ?>
                </strong>

                <!-- Free shipping indicator -->
                <?php if ((int) ($cr->free_shipping ?? 0)) : ?>
                    <span class="badge bg-success ms-1" style="font-size:0.8em">
                        <?php echo Text::_('COM_ALFA_FREE_SHIPPING'); ?>
                    </span>
                <?php endif; ?>
            </span>

        </div>
    <?php endforeach; ?>

</div>
