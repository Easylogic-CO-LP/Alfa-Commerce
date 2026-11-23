<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * A priced cart line's discount, as the order builder's items table and its line popup print it.
 *
 * Three readings, never computed here: what a rule took off the line (the saving, read off the priced result); a
 * negotiated price, which is its own surface and not a discount (the saving against the ordinary price, read off the
 * result); or a dash when the line is priced with no discount. A line with no price at all is the caller's to mark: no
 * money column applies to it.
 *
 * Override html/layouts/com_alfa/cart/line_discount.php in the administrator template to change it in both places.
 *
 * Display data:
 *   price  \Alfa\Component\Alfa\Site\Service\Pricing\PriceResult  the line's priced result
 *
 * @since  2.3.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \Alfa\Component\Alfa\Site\Service\Pricing\PriceResult $price */
$price = $displayData['price'];
?>
<?php if ($price->hasDiscount()) : ?>
    <span class="text-success">&minus;<?php echo $this->escape($price->getSavingsTotalWithTax()->format()); ?></span>
<?php elseif ($price->hasExplicitPrice()) : ?>
    <span class="badge bg-info-subtle text-info-emphasis">
        <?php echo $this->escape(Text::_('COM_ALFA_PRICE_CUSTOM_LINE_SAVING')); ?>
        &minus;<?php echo $this->escape($price->getLineSavingWithTax()->format()); ?>
    </span>
<?php else : ?>
    -
<?php endif; ?>
