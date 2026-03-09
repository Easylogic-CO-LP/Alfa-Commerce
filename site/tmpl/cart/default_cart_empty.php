<?php

use \Joomla\CMS\Uri\Uri;
use \Joomla\CMS\Language\Text;

?>

<div>

    <?php echo Text::_('COM_ALFA_CART_EMPTY_MESSAGE'); ?><br>
    <a href="<?php echo Uri::root(); ?>"><?php echo Text::_('COM_ALFA_CART_EMPTY_CONTINUE'); ?></a>

</div>