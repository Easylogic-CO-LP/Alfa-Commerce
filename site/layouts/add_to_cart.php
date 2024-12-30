<?php

// use Joomla\CMS\Factory;
// use Joomla\CMS\HTML\HTMLHelper;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Layout\LayoutHelper;

$item = $displayData;

$showNotify = ($item->stock <= 0 && $item->stock_action == 1);
?>

<div class="add-to-cart-wrapper">

    <?php if($showNotify):    //Checking for unavailable stock ?>
        
        <button onclick="alert('functionality to be added')" data-action="notify">
            <?php echo Text::_("COM_ALFA_NOTIFY_ME_BUTTON_TEXT");?>
        </button>

    <?php else: ?>

        <?php echo LayoutHelper::render('quantity_controls',$item); //passed data as $displayData in layout ?>

        <button class="add-to-cart-btn" data-action="add-to-cart">
            <?php echo Text::_('COM_ALFA_CART_ADD_TO_CART')?>
        </button>

    <?php endif; ?>

</div>