<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$animation = $params->get('animation', 'zoomIn');
$imageType = $params->get('imageType', 'default');
$imageFile = $params->get('imageFile', null);
$fixed = $params->get('fixed_btn', '0');
$fixed_pos = $params->get('fixed_pos', 'bottom-right');

$minimum_price = $getMinMax['min'];
$maximum_price = $getMinMax['max'];

$loadingImg = $params->get('loadingImg', 'default');
$loadingImageFile = $params->get('loadingImageFile', null);
$headerTxt = $params->get('headerTxt', null);

// Route::_ roots what it returns (SiteRouter::buildBase() prepends Uri::root(true) to every
// path it builds), so nothing prefixes the base here. Concatenating Uri::base(true) in front of
// it emitted the base TWICE (/shop/shop/...) on any install below the document root, and nothing
// at all on one at the root, which is why it went unseen.
$formAction = Route::_('index.php?option=com_alfa&view=items');
// The AJAX render must stay on THIS menu item. With SEF on, Route::_ resolves to
// the menu alias and the Itemid is implied; with SEF OFF it emits a bare
// index.php?option=com_alfa&view=items, Joomla falls back to whatever menu item
// it considers default for the component, and the view then renders with THAT
// item's params instead of this page's. Read it from the ACTIVE MENU, not from
// the request: a SEF request carries no Itemid at all.
$activeMenu = Factory::getApplication()->getMenu()->getActive();
$activeItemid = $activeMenu ? (int) $activeMenu->id : 0;
// The routed category rides along too. Without it the re-render is the MENU
// ITEM's listing: on /en/katastema/fashion every AJAX apply-or-clear swapped in
// the whole catalogue while the URL still said Fashion. Same read as
// $updateFiltersAction below: the router sets category_id when it parses the
// category alias, so getInt() resolves it on a SEF URL, and Route::_ folds it
// back into the alias: the action becomes this page's own URL plus tmpl.
$appInput = Factory::getApplication()->getInput();
$ajaxCatId = $appInput->getInt('category_id', 0);
$ajaxAction = Route::_('index.php?option=com_alfa&tmpl=component&view=items'
    . ($ajaxCatId ? '&category_id=' . $ajaxCatId : '')
    . ($activeItemid ? '&Itemid=' . $activeItemid : ''));

// Route::_ so the URL is language/SEF-aware and ROOTED (e.g. /en/?option=com_ajax...),
// same as the item page's reloadUrl. The second arg false returns a clean "&"
// (not "&amp;") for use in the fetch/dataset URL. A raw "index.php?..." is relative:
// the browser resolves it against the current SEF page (/en/katastema) ->
// /en/index.php?... which the router can't parse (404).
//
// The refresh re-renders fields whose shape depends on the BROWSING CONTEXT
// (the limit dropdown resolves category -> menu -> global page sizes), and a
// bare com_ajax request has neither a routed category nor the page's menu item
// - the re-rendered dropdown would silently fall back to the global tier. So
// the page's category_id and Itemid ride along.
$updateFiltersAction = Route::_(
    'index.php?option=com_ajax&module=alfa_filters&method=getFilters&format=json'
        . ($ajaxCatId ? '&category_id=' . $ajaxCatId : '')
        . (($ajaxItemId = $appInput->getInt('Itemid', 0)) ? '&Itemid=' . $ajaxItemId : ''),
    false,
);

$moduleId = $module->id;

?>
<div class="mod-alfa-filters-wrapper mod-alfa-filters-<?= $moduleId ?> <?= $fixed ? 'alfa-filters-fixed ' . $fixed_pos :
 '' ?>" data-module-id="<?= $moduleId ?>">
    <button class="alfa-filters-offcanvas-toggler"
            aria-controls="alfa-filters-offcanvas-<?=$moduleId?>"
            aria-expanded="false">
		<?= Text::_('MOD_ALFAFILTERS_TOGGLE_BUTTON_TXT') ?>
    </button>

    <div class="alfa-filters-offcanvas-wrapper alfa-filters-offcanvas <?= $animation ?>"
         id="alfa-filters-offcanvas-<?=$moduleId?>">
        <div class="alfa-filters-wrapper-inner">
            <div class="alfa-filters-header">
                <?php if ($headerTxt !== null) : ?>
                    <h2 class="alfa-filters-header-title"><?= $headerTxt ?></h2>
                <?php endif; ?>
                <button type="button" class="af-reset-all">
                    <?= Text::_('MOD_ALFAFILTERS_FILTER_RESET_ALL_BTN') ?>
                </button>
                <button type="button" class="alfa-filters-close-btn" aria-label="<?= Text::_('MOD_ALFAFILTERS_CLOSE_BTN_LABEL') ?>">
					<?php if ($imageType === 'image'):?>
                        <img src="<?=htmlspecialchars($imageFile, ENT_QUOTES, 'UTF-8')?>" alt="<?= Text::_('MOD_ALFAFILTERS_CLOSE_BTN_ALT') ?>">
					<?php elseif ($imageType === 'svg' && !empty($svg_close_button)):?>
						<?= $svg_close_button ?>
					<?php else:?>
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g stroke-width="0"></g><g stroke-linecap="round" stroke-linejoin="round"></g><g><path fill-rule="evenodd" clip-rule="evenodd" d="M19.207 6.207a1 1 0 0 0-1.414-1.414L12 10.586 6.207 4.793a1 1 0 0 0-1.414 1.414L10.586 12l-5.793 5.793a1 1 0 1 0 1.414 1.414L12 13.414l5.793 5.793a1 1 0 0 0 1.414-1.414L13.414 12l5.793-5.793z" fill="#000000"></path></g>
                        </svg>
					<?php endif; ?>
                </button>
            </div>
            <!-- Desktop reset. The header above is the off-canvas chrome and the
                 breakpoint CSS hides it, so desktop needs its own control. -->
            <div class="alfa-filters-actions">
                <button type="button" class="af-reset-all">
                    <?= Text::_('MOD_ALFAFILTERS_FILTER_RESET_ALL_BTN') ?>
                </button>
            </div>
            <form
                    method="get"
                    action="<?= $formAction; ?>"
                    data-action="<?= $ajaxAction; ?>"
                    data-filters-action="<?= $updateFiltersAction; ?>"
                    id="alfa-filters-form-<?= $moduleId ?>"
                    name="alfaFilterForm"
                    class="mod-alfa-filters"
                    data-showon-scope
            >

                <div class="af-form-content">
                    <?php require ModuleHelper::getLayoutPath('mod_alfa_filters', 'default_form_content'); ?>
                </div>
            </form>
        </div>
    </div>
    <div class="alfa-filters-loading-overlay" aria-hidden="true">
        <?php if ($loadingImg === 'image'): ?>
            <div class="af-loading-anim">
                <img src="<?=htmlspecialchars($loadingImageFile, ENT_QUOTES, 'UTF-8')?>" alt="<?= Text::_('MOD_ALFAFILTERS_LOADING_ICON_ALT') ?>">
            </div>
        <?php elseif ($loadingImg === 'svg' && !empty($svg_loading_icon)) : ?>
            <div class="af-loading-anim">
	            <?= $svg_loading_icon ?>
            </div>
        <?php else :?>
            <svg width="100" height="100" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                <circle cx="50" cy="50" r="47" fill="none" stroke="#e0e0e0" stroke-width="3"/>
                <circle cx="50" cy="50" r="47" fill="none" stroke="#3b82f6" stroke-width="3" stroke-dasharray="74 222" stroke-linecap="round">
                    <animateTransform attributeName="transform" type="rotate" from="0 50 50" to="360 50 50" dur="0.6s" repeatCount="indefinite"/>
                </circle>
            </svg>
        <?php endif; ?>
    </div>
</div>
