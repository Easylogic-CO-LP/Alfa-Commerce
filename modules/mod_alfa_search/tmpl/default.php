<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

// to keep the functionallity do not change the ids search-container-input , search-container-popup , search-container-loading-img

// $formAction and $ajaxAction are provided by the module Dispatcher
// (getLayoutData) and extracted into this layout, built there once with Route::_
// so the com_ajax URL is rooted + SEF/language-aware (no duplicate build here).

// SAME FLOOR THE HELPER ENFORCES: the client gate and the server gate must agree, or
// the module fires a request its own validator is guaranteed to reject: below two
// characters the search service answers nothing, so a shop configured at 1 would trade
// an empty dropdown for an error per keystroke.
$min_characters = max(2, (int) $params->get('minCharacters', '2'));
$loadingImageType = $params->get('loadingImageType', '3');
$loadingImageFile = $params->get('loadingImageFile', '');
$loadingImageInline = $params->get('loadingImageInline', '');
?>

<div class="alfasearch-wrapper alfasearch-wrapper-<?php echo (int) $module->id; ?>">
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