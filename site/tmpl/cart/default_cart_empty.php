<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

?>

<?php // Alfa's root: the page renders this layout alone, and the notice's rule (cart.css) is scoped under it. ?>
<div class="com-alfa">

    <?php echo Text::_('COM_ALFA_CART_EMPTY_MESSAGE'); ?><br>
    <a href="<?php echo Uri::root(); ?>"><?php echo Text::_('COM_ALFA_CART_EMPTY_CONTINUE'); ?></a>

    <?php // THE CART FILLED ELSEWHERE. An empty page has no sections and no checkout
          // form to redraw, so when another surface changes the cart (an add, the cart
          // module) cart.js only reveals this notice, and the shopper reloads when ready.
          // Markup only: this file is also the JSON partial of an emptied cart, where no
          // asset may be declared. Without the data attribute nothing is shown. ?>
    <div class="cart-changed-notice" data-cart-changed-notice role="status" hidden>
        <?php echo Text::_('COM_ALFA_CART_CHANGED_ELSEWHERE'); ?>
        <a href="<?php echo Route::_('index.php?option=com_alfa&view=cart'); ?>"><?php echo Text::_('COM_ALFA_CART_CHANGED_RELOAD'); ?></a>
    </div>

</div>
