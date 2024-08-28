<?php

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

$min_characters = (int)$params->get('minCharacters', '2');
$loadingImageType = $params->get('loadingImageType', '0');
$loadingImageFile = $params->get('loadingImageFile', '');
$loadingImageInline = $params->get('loadingImageInline', '');
?>

<div class="alfasearch-wrapper">
    <form action="/index.php?option=com_alfa&view=items" data-minchars="<?php echo $min_characters ?>"
          data-action="/index.php?option=com_ajax&module=alfa_search&method=get&format=json" method="get">
        <div class="search-container">
            <input type="search" name="filter[search]" class="searchbar" autocomplete="off"
                   placeholder="<?php echo Text::_('MOD_ALFA_SEARCH_SEARCHBAR_PLACEHOLDER'); ?>">
            <div class="loading-img">
                <?php if ($loadingImageType == '0') {
                    echo HtmlHelper::image($loadingImageFile, 'loading-img');
                } else {
                    echo $loadingImageInline;
                } ?>
            </div>
        </div>
        <input type="hidden" name="option" value="com_alfa">
        <input type="hidden" name="view" value="items">
    </form>
    <div class="searchbar-popup">
    </div>
</div>