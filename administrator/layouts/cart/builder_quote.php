<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The builder popup's quote: the line as the cart will price it.
 *
 * Three cells, each the Items table's own (tmpl/cart/default_items.php): the
 * unit with tax (by the one unit rule), the discount and the line total, read
 * off the priced line.
 * Nothing here computes money. The popup renders it at open and the
 * cart.priceLineConfig endpoint renders it again on every change.
 *
 * @since  1.14.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\PriceUnits;
use Joomla\CMS\Language\Text;

/** @var \Alfa\Component\Alfa\Site\Service\Pricing\PriceResult $price */
$price = $displayData['price'];
?>
<div class="row g-3">
    <div class="col-auto">
        <div class="form-label"><?php echo Text::_('COM_ALFA_CART_BUILDER_COL_UNIT_INCL'); ?></div>
        <div class="fs-5 fw-semibold"><?php echo $this->escape(PriceUnits::line(price: $price, withTax: true)); ?></div>
    </div>
    <div class="col-auto">
        <div class="form-label"><?php echo Text::_('COM_ALFA_CART_TABLE_DISCOUNT'); ?></div>
        <div class="fs-5 fw-semibold">
            <?php if ($price->hasDiscount()) : ?>
                <span class="text-success">&minus;<?php echo $this->escape($price->getSavingsTotal()->format()); ?></span>
            <?php elseif ($price->hasExplicitPrice()) : ?>
                <?php // A negotiated price is its own surface, not a discount. ?>
                <span class="badge bg-info-subtle text-info-emphasis">
                    <?php echo $this->escape(Text::_('COM_ALFA_PRICE_CUSTOM_LINE_SAVING')); ?>
                    &minus;<?php echo $this->escape($price->getLineSavingWithTax()->format()); ?>
                </span>
            <?php else : ?>
                -
            <?php endif; ?>
        </div>
    </div>
    <div class="col-auto">
        <div class="form-label"><?php echo Text::_('COM_ALFA_CART_TABLE_TOTAL'); ?></div>
        <div class="fs-5 fw-semibold">
            <?php if ($price->hasDiscount()) : ?>
                <div class="text-muted text-decoration-line-through small">
                    <?php echo $this->escape($price->getBaseTotalWithTax()->format()); ?>
                </div>
            <?php elseif ($price->hasExplicitPrice()) : ?>
                <?php // The canonical amount struck through, the operator's figure below it. ?>
                <div class="text-muted text-decoration-line-through small">
                    <?php echo $this->escape($price->getReference()->getTotal()->format()); ?>
                </div>
            <?php endif; ?>
            <?php echo $this->escape($price->getTotal()->format()); ?>
        </div>
    </div>
</div>
