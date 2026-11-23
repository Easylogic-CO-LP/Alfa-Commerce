<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * A priced cart line's total, as the order builder's items table and its line popup print it.
 *
 * The line's total, with the amount it would have been struck through above it: the undiscounted total when a rule
 * took something off, or the ordinary price's total when the price was negotiated (on a line that carried a catalogue
 * sale before it was negotiated those are different numbers, so the negotiated case reads the reference price). Every
 * figure is read off the priced result. A line with no price at all is the caller's to mark.
 *
 * Override html/layouts/com_alfa/cart/line_total.php in the administrator template to change it in both places.
 *
 * Display data:
 *   price  \Alfa\Component\Alfa\Site\Service\Pricing\PriceResult  the line's priced result
 *
 * @since  2.3.0
 */

defined('_JEXEC') or die;

/** @var \Alfa\Component\Alfa\Site\Service\Pricing\PriceResult $price */
$price = $displayData['price'];
?>
<?php if ($price->hasDiscount()) : ?>
    <div class="text-muted text-decoration-line-through small">
        <?php echo $this->escape($price->getBaseTotalWithTax()->format()); ?>
    </div>
<?php elseif ($price->hasExplicitPrice()) : ?>
    <div class="text-muted text-decoration-line-through small">
        <?php echo $this->escape($price->getReference()->getTotal()->format()); ?>
    </div>
<?php endif; ?>
<?php echo $this->escape($price->getTotal()->format()); ?>
