<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use \Joomla\CMS\Uri\Uri;
use \Joomla\CMS\Language\Text;

?>

<div>

    <?php echo Text::_('COM_ALFA_CART_EMPTY_MESSAGE'); ?><br>
    <a href="<?php echo Uri::root(); ?>"><?php echo Text::_('COM_ALFA_CART_EMPTY_CONTINUE'); ?></a>

</div>