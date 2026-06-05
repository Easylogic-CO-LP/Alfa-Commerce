<?php
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

$animation = $params->get('animation', 'zoomIn');
$imageType = $params->get('imageType', 'default');
$imageFile = $params->get('imageFile', null);
$fixed = $params->get('fixed_btn', '0');
$fixed_pos = $params->get('fixed_pos', 'bottom-right');
$histogram_options = $params->get('histogram_options', 'tower');

$minimum_price = $getMinMax['min'];
$maximum_price = $getMinMax['max'];

$getPriceHistogram ??= [];

$loadingImg = $params->get('loadingImg', 'default');
$loadingImageFile = $params->get('loadingImageFile', null);
$headerTxt = $params->get('headerTxt', null);

$basePath = Uri::base(true);
$formAction = $basePath . Route::_('index.php?option=com_alfa&view=items');
$ajaxAction = $basePath . Route::_('index.php?option=com_alfa&tmpl=component&view=items');

$updateFiltersAction = $basePath . 'index.php?option=com_ajax&module=alfa_filters&method=getFilters&format=json';

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
                <button type="button" class="alfa-filters-close-btn" aria-label="Close">
					<?php if ($imageType === 'image'):?>
                        <img src="<?=htmlspecialchars($imageFile, ENT_QUOTES, 'UTF-8')?>" alt="Filter Menu Close Button">
					<?php elseif ($imageType === 'svg' && !empty($svg_close_button)):?>
						<?= $svg_close_button ?>
					<?php else:?>
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g stroke-width="0"></g><g stroke-linecap="round" stroke-linejoin="round"></g><g><path fill-rule="evenodd" clip-rule="evenodd" d="M19.207 6.207a1 1 0 0 0-1.414-1.414L12 10.586 6.207 4.793a1 1 0 0 0-1.414 1.414L10.586 12l-5.793 5.793a1 1 0 1 0 1.414 1.414L12 13.414l5.793 5.793a1 1 0 0 0 1.414-1.414L13.414 12l5.793-5.793z" fill="#000000"></path></g>
                        </svg>
					<?php endif; ?>
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
                <img src="<?=htmlspecialchars($loadingImageFile, ENT_QUOTES, 'UTF-8')?>" alt="Loading Icon">
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
