<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Cart region: quantity controls (- [input name="quantity"] +). An auxiliary
 * surface rendered inside the MAIN (cart.add). Always emits the data-action /
 * data-step / min / max / name="quantity" the add-to-cart + quantity JS read.
 *
 * The item-quantity-* classes carry the BEHAVIOUR; alfa-stepper-* carry the look,
 * and are shared with the cart line's own spinner so the two cannot drift.
 *
 * The figures are the cart's own, in whole units, under the displayed unit's
 * quantity rules (the active combination's where it sets them): the smallest
 * sale, its increment and the largest sale within the maximum, so every figure
 * the stepper reaches is a quantity the cart keeps unchanged. Stock is not
 * consulted: the line the cart keeps is still no more than the shelf supplies.
 * The raw rule values are never rendered.
 *
 * @var object $displayData The item, as the model resolved it (its quantity
 *                          rules, combinations and displayed variant).
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Cart\CartQuantityPolicy;
use Joomla\CMS\Language\Text;

$item = $displayData;

$activeId = $item->variant->activeCombination->id ?? null;
$bounds   = CartQuantityPolicy::bounds(
    item: $item,
    combinationId: $activeId !== null ? (int) $activeId : null,
);

$minimum = $bounds['minimum'] ?? 1;
$maximum = $bounds['maximum'] ?? '';
?>

<div class="alfa-stepper">

    <button
            class="alfa-stepper-btn decrement"
            data-action="cart-decrement"
            aria-label="<?php echo Text::_('COM_ALFA_QUANTITY_DECREMENT'); ?>"
    >
        -
    </button>

    <input
            class="alfa-stepper-input"
            type="number"
            name="quantity"
            aria-label="<?php echo Text::_('COM_ALFA_QUANTITY_LABEL'); ?>"
            value="<?php echo $minimum; ?>"
            min="<?php echo $minimum; ?>"
            max="<?php echo $maximum; ?>"
            data-step="<?php echo $bounds['increment']; ?>"
    />

    <button
            class="alfa-stepper-btn increment"
            data-action="cart-increment"
            aria-label="<?php echo Text::_('COM_ALFA_QUANTITY_INCREMENT'); ?>"
    >
        +
    </button>

</div>