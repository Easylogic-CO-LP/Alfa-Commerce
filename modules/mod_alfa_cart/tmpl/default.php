<?php

use Joomla\CMS\HTML\HTMLHelper;
use \Joomla\CMS\Helper\ModuleHelper;

defined('_JEXEC') or die;

$imageType = $params->get('imageType', '3');
$imageFile = $params->get('imageFile', '');
$imageInline = $params->get('imageInline', '');
$animation = $params->get('animation', 'fromTop');

?>

<div class="mod-alfa-cart <?php echo $animation?>" data-mod-cart-outer>
    <div class="cart-toggler" data-counter="<?php echo $togglerCounter; ?>">
        <?php if ($imageType == '0' && !empty($imageFile)) {
            echo HtmlHelper::image($imageFile, 'loading-img');
        } else if ($imageType == '1' && !empty($imageInline)) {
            echo $imageInline;
        } else if ($imageType == '3'){?>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="50" height="50">
            <path d="M95.34,94,31.42,89.36,29,75.25H10.66v4h15L35.5,137a9.82,9.82,0,1,0,11.35,3.36h29.4a9.8,9.8,0,1,0,7.89-4H39.44l-1.5-8.73H86.11ZM44.77,146.16a5.81,5.81,0,1,1-5.8-5.82h0A5.82,5.82,0,0,1,44.77,146.16Zm45.17,0a5.81,5.81,0,1,1-5.81-5.81h0A5.82,5.82,0,0,1,89.94,146.16ZM37.26,123.61,32.12,93.43l58.07,4.18-7.13,26Z" transform="translate(-10.66 -75.25)"/>
        </svg>
    <?php }?>
    </div>

    <div class="mod-alfa-cart-data">
        <?php require ModuleHelper::getLayoutPath('mod_alfa_cart','default_items'); ?>
    </div>
</div>