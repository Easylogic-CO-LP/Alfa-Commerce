<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Cart region: the core MAIN a listing card gets instead of cart.add when it
 * hides a variant dimension or a required field the buyer must still answer
 * (decided in ItemsModel). A plain link to the product page, carrying the variant
 * picks the card shows ($item->optionsLink), so it works without JavaScript. No
 * quantity, no data-action, nothing computed here. Styled with the add-to-cart
 * classes (.item-cart-add / .item-cart-btn) so a theme draws both alike.
 *
 * @var object $displayData The item (carries ->optionsLink).
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$item = $displayData;
?>
<div class="item-cart-add">
    <a class="item-cart-btn" href="<?php echo htmlspecialchars((string) $item->optionsLink, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo Text::_('COM_ALFA_CART_CHOOSE_OPTIONS_BUTTON'); ?>
    </a>
</div>
