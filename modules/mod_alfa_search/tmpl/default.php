<?php

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

// to keep the functionallity do not change the ids search-container-input , search-container-popup , search-container-loading-img

$min_characters = (int)$params->get('minCharacters', '2');
$loadingImageType = $params->get('loadingImageType', '3');
$loadingImageFile = $params->get('loadingImageFile', '');
$loadingImageInline = $params->get('loadingImageInline', '');
?>

<div class="alfasearch-wrapper">
    <form action="/index.php?option=com_alfa&view=items" data-minchars="<?php echo $min_characters ?>"
          data-action="/index.php?option=com_ajax&module=alfa_search&method=get&format=json" method="get">
        <div class="search-container">
            <input type="search" name="filter[search]" class="searchbar" id="search-container-input" autocomplete="off"
                   placeholder="<?php echo Text::_('MOD_ALFA_SEARCH_SEARCHBAR_PLACEHOLDER'); ?>">
            <div class="loading-img" id="search-container-loading-img">
                <?php if ($loadingImageType == '0' && !empty($loadingImageFile)) {
                    echo HtmlHelper::image($loadingImageFile, 'loading-img');
                } else if ($loadingImageInline == '1' && !empty($loadingImageInline)) {
                    echo $loadingImageInline;
                } else if ($loadingImageType == '3') { ?>
                    <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
                         viewBox="0 0 100 100"
                         preserveAspectRatio="xMidYMid">
                        <circle cx="50" cy="50" fill="none" stroke="currentColor" stroke-width="6" r="35"
                                stroke-dasharray="164.93361431346415 56.97787143782138">
                            <animateTransform attributeName="transform" type="rotate" repeatCount="indefinite" dur="1s"
                                              values="0 50 50;360 50 50" keyTimes="0;1"></animateTransform>
                        </circle>
                    </svg>
                <?php } else {
                    echo '';
                } ?>
            </div>
        </div>
        <input type="hidden" name="option" value="com_alfa">
        <input type="hidden" name="view" value="items">
    </form>
    <div class="searchbar-popup" id="search-container-popup" tabindex="-1">
    </div>
</div>