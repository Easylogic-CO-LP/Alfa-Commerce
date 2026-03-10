<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

defined('_JEXEC') or die;

// to keep the functionallity do not change the ids search-container-input , search-container-popup , search-container-loading-img

$basePath = Uri::base(true);

$formAction = $basePath . '/index.php?option=com_alfa&view=items';
$ajaxAction = $basePath . '/index.php?option=com_ajax&module=alfa_search&method=get&format=json';

$min_characters = (int) $params->get('minCharacters', '2');
$loadingImageType = $params->get('loadingImageType', '3');
$loadingImageFile = $params->get('loadingImageFile', '');
$loadingImageInline = $params->get('loadingImageInline', '');
?>

<div class="alfasearch-wrapper">
    <form
            method="get"
            action="<?php echo $formAction; ?>"
            data-minchars="<?php echo $min_characters ?>"
            data-action="<?php echo $ajaxAction; ?>">

        <div class="search-container">

            <input type="search"
                   name="filter[search]"
                   class="searchbar"
                   id="search-container-input"
                   autocomplete="off"
                   value="<?php echo htmlspecialchars($currentSearch, ENT_COMPAT, 'UTF-8'); ?>"
                   placeholder="<?php echo Text::_('MOD_ALFA_SEARCH_SEARCHBAR_PLACEHOLDER'); ?>">


            <div class="loading-img" id="search-container-loading-img">
                <?php if ($loadingImageType == '0' && !empty($loadingImageFile)) {
                    echo HtmlHelper::image($loadingImageFile, 'loading-img');
                } elseif ($loadingImageInline == '1' && !empty($loadingImageInline)) {
                    echo $loadingImageInline;
                } elseif ($loadingImageType == '3') { ?>
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
            <button type="submit" class="search-submit-button" aria-label="<?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?>">
	            <?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?>
            </button>
        </div>
    </form>
    <div class="searchbar-popup" id="search-container-popup" tabindex="-1">
    </div>
</div>