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
 * Three figures, as the items table prints them: the unit with tax (by the one
 * unit rule), the discount and the line total (the layouts cart.line_discount
 * and cart.line_total, which the table prints too), read off the priced line.
 * With no price applying, the sentence that the line waits for its custom
 * price. Nothing here computes money. The popup renders it at open and the
 * cart.priceLineConfig endpoint renders it again on every change.
 *
 * @since  2.0.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\PriceUnits;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \Alfa\Component\Alfa\Site\Service\Pricing\PriceResult|null $price Null when no price applies to the line. */
$price = $displayData['price'] ?? null;

// The discount and the total are the items table's own layouts, so the popup and the table read alike; found by
// component, so an administrator template's override of them applies to both.
$layouts = ['component' => 'com_alfa', 'client' => 1];

// No price applies to the product at this quantity for this customer: the line waits for its custom price.
if ($price === null) : ?>
<p class="text-warning-emphasis mb-0"><?php echo $this->escape(Text::_('COM_ALFA_CART_LINE_ENTER_PRICE')); ?></p>
<?php return;
endif;
?>
<div class="row g-3">
    <div class="col-auto">
        <div class="form-label"><?php echo Text::_('COM_ALFA_CART_BUILDER_COL_UNIT_INCL'); ?></div>
        <div class="fs-5 fw-semibold"><?php echo $this->escape(PriceUnits::line(price: $price, withTax: true)); ?></div>
    </div>
    <div class="col-auto">
        <div class="form-label"><?php echo Text::_('COM_ALFA_CART_TABLE_DISCOUNT'); ?></div>
        <div class="fs-5 fw-semibold">
            <?php echo LayoutHelper::render('cart.line_discount', ['price' => $price], null, $layouts); ?>
        </div>
    </div>
    <div class="col-auto">
        <div class="form-label"><?php echo Text::_('COM_ALFA_CART_TABLE_TOTAL'); ?></div>
        <div class="fs-5 fw-semibold">
            <?php echo LayoutHelper::render('cart.line_total', ['price' => $price], null, $layouts); ?>
        </div>
    </div>
</div>
