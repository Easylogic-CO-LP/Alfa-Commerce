<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The cart and the checkout in catalogue-only mode: one sentence instead of the cart, no form. Overridable as
 * html/com_alfa/cart/default_catalogue_only.php.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

?>
    <p class="cart-catalogue-only"><?php echo Text::_('COM_ALFA_CATALOGUE_ONLY_NOTICE'); ?></p>
    <a href="<?php echo Uri::root(); ?>"><?php echo Text::_('COM_ALFA_CART_EMPTY_CONTINUE'); ?></a>
