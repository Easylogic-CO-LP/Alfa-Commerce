<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Cart region: the NOTIFY slot. The "notify me when available" control of a product that cannot be bought now and
 * whose stock rule says to offer it (the model's $item->cartState->showNotify, set in VariantHelper::resolve). Drawn
 * by the wrapper beside the MAIN slot, not inside it: a plugin's replacement of the add to cart, and the
 * catalogue-only mode, which draws no add to cart, keep it. The notify flow attaches through data-action="cart-notify";
 * no inline handler ships to the storefront.
 *
 * @var object $displayData The item (carries ->cartState).
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$item = $displayData;

if (empty($item->cartState->showNotify)) {
    return;
}
?>
<div class="item-cart-notify">
    <button type="button" data-action="cart-notify"><?php echo Text::_('COM_ALFA_NOTIFY_ME_BUTTON_TEXT'); ?></button>
</div>
