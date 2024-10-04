<?php

use \Joomla\CMS\Language\Text;
// use Joomla\CMS\Factory;
// use Joomla\CMS\HTML\HTMLHelper;

$price = $displayData;

?>

<p><strong><?php echo Text::_('COM_ALFA_BASE_PRICE'); ?></strong> <?php echo($price['base_price']); ?></p>
<p><strong><?php echo Text::_('COM_ALFA_PRICE'); ?></strong> <?php echo($price['price']); ?></p>
<p><strong><?php echo Text::_('COM_ALFA_PRICE_WITH_TAX'); ?></strong> <?php echo($price['price_with_tax']); ?></p>