<?php

use Joomla\CMS\Language\Text;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Component\ComponentHelper;

// Support both require (parent scope) and FileLayout::render ($displayData)
if (isset($displayData)) extract($displayData);

$alfa_filters = $alfa_filters ?? null;
$alfa_active_filters = $alfa_active_filters ?? [];
$moduleId = $moduleId ?? 0;
$minimum_price = $minimum_price ?? 0;
$maximum_price = $maximum_price ?? 0;
$getPriceHistogram = $getPriceHistogram ?? [];
$form_fields = $form_fields ?? [];
$dynamic_filtering = $dynamic_filtering ?? false;

// Arrow SVG from overridable template
ob_start();
require ModuleHelper::getLayoutPath('mod_alfa_filters', 'default_arrow');
$arrowSvg = ob_get_clean();
?>

<div class="af-main">
    <?php require ModuleHelper::getLayoutPath('mod_alfa_filters', 'default_filters'); ?>
</div>

<div class="af-price">
    <fieldset class="af-price-fieldset">
        <legend class="visually-hidden"><?= Text::_('MOD_ALFAFILTERS_FILTER_PRICE') ?></legend>
        <div class="af-price-header">
            <span><?= Text::_('MOD_ALFAFILTERS_FILTER_PRICE') ?></span>
            <button type="button" class="af-price-reset">
                <?= Text::_('MOD_ALFAFILTERS_FILTER_RESET_BTN'); ?>
            </button>
        </div>
        <div class="af-price-histogram af-<?=$histogram_options ?>">
            <?php if ($histogram_options === 'tower' && !empty($getPriceHistogram)) :
                $maxCount = max(array_column($getPriceHistogram, 'count')) ?: 1;
                foreach ($getPriceHistogram as $bucket) :
                    $heightPercent = ($bucket['count'] / $maxCount) * 100;
                    ?>
                    <span class="af-price-bar"
                          style="height: <?= $heightPercent ?>%;"
                          title="<?= (int) $bucket['count'] ?> products"></span>
                <?php endforeach;
                endif; ?>


	        <?php if ($histogram_options === 'slope' && !empty($getPriceHistogram)) :
		        $maxCount = max(array_column($getPriceHistogram, 'count')) ?: 1;
		        $width = 100;
		        $svgHeight = 100;
		        $count = count($getPriceHistogram);

		        $points = [];
		        foreach ($getPriceHistogram as $i => $bucket) {
			        $x = $count > 1 ? ($i / ($count - 1)) * $width : 0;
			        $y = $svgHeight - ($bucket['count'] / $maxCount) * $svgHeight;
			        $points[] = ['x' => $x, 'y' => $y];
		        }

		        $path = "M 0 {$svgHeight}";
		        $path .= " L {$points[0]['x']} {$points[0]['y']}";

		        for ($i = 0; $i < count($points) - 1; $i++) {
			        $curr = $points[$i];
			        $next = $points[$i + 1];
			        $cpx = ($curr['x'] + $next['x']) / 2;

			        $path .= " C {$cpx} {$curr['y']}, {$cpx} {$next['y']}, {$next['x']} {$next['y']}";
		        }

		        $path .= " L {$width} {$svgHeight}";
		        $path .= " Z";
		        ?>
                <svg viewBox="0 0 <?= $width ?> <?= $svgHeight ?>" preserveAspectRatio="none" class="af-histogram-svg">

                    <defs>
                        <linearGradient id="histogramGradient" x1="0%" y1="0%" x2="0%" y2="100%">
                            <stop class="af-svg-stop-0" offset="0%" />
                            <stop class="af-svg-stop-100" offset="100%" />
                        </linearGradient>
                    </defs>

                    <path d="<?= $path ?>" fill="url(#histogramGradient)"/>

                </svg>
	        <?php endif; ?>
        </div>
        <div class="af-range">
            <div class="af-range-track"></div>
            <div class="af-range-highlight"></div>
            <input type="range" class="af-range-min" name="filter[price_min]"
                       min="<?= $minimum_price ?>" max="<?= $maximum_price ?>"
                   value="<?= $alfa_active_filters['price_min'] ?? $minimum_price ?>">

            <input type="range" class="af-range-max" name="filter[price_max]"
                   min="<?= $minimum_price ?>" max="<?= $maximum_price ?>"
                   value="<?= $alfa_active_filters['price_max'] ?? $maximum_price ?>">
        </div>

        <div class="af-price-values">
            <div class="af-price-search">
                <div class="af-price-box">
                    <label class="af-price-label" for="af-<?= $moduleId ?>-price-min">
                        <?= Text::_('MOD_ALFAFILTERS_FILTER_MIN_PRICE') ?>
                    </label>
                    <input type="number" class="af-price-min"
                           id="af-<?= $moduleId ?>-price-min"
                           min="<?= $minimum_price ?>"
                           max="<?= $maximum_price ?>"
                           value="<?= $alfa_active_filters['price_min'] ?? $minimum_price ?>"
                           step="0.01">
                </div>
                <div class="af-price-box">
                    <label class="af-price-label" for="af-<?= $moduleId ?>-price-max">
                        <?= Text::_('MOD_ALFAFILTERS_FILTER_MAX_PRICE') ?>
                    </label>
                    <input type="number" class="af-price-max"
                           id="af-<?= $moduleId ?>-price-max"
                           min="<?= $minimum_price ?>"
                           max="<?= $maximum_price ?>"
                           value="<?= $alfa_active_filters['price_max'] ?? $maximum_price ?>"
                           step="0.01">
                </div>

                <?php if($dynamic_filtering) : ?>
                    <button type="button" class="af-price-apply"
                           aria-label="<?= Text::_('MOD_ALFAFILTERS_FILTER_APPLY_PRICE_BTN') ?>">
                        <?= $arrowSvg ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </fieldset>
</div>

<div class="af-ordering">
    <label for="list_alfa_filters_ordering_<?= $moduleId ?>">
        <?= Text::_('MOD_ALFAFILTERS_FILTER_ORDERING_LABEL') ?>
    </label>
    <?= $form_fields['ordering']; ?>
</div>

<div class="af-limit">
    <label for="list_alfa_filters_limit_<?= $moduleId ?>">
        <?= Text::_('MOD_ALFAFILTERS_FILTER_LIST_LIMIT_LABEL') ?>
    </label>
    <?= $form_fields['limit']; ?>
</div>

<div class="af-onsale">
    <span><?= Text::_('MOD_ALFAFILTERS_FILTER_ON_SALE_LABEL') ?></span>
    <?= $form_fields['on_sale']; ?>
</div>

<div class="af-discount-amount">
    <label for="filter_alfa_filters_discount_amount_min_<?= $moduleId ?>">
        <?= Text::_('MOD_ALFAFILTERS_FILTER_DISCOUNT_AMOUNT_MIN_LABEL') ?>
    </label>
    <div class="af-discount-amount-inner">
        <?= $form_fields['discount_amount_min']; ?>
        <?php if($dynamic_filtering) : ?>
            <button type="button" class="af-discount-amount-btn">
                <?=$arrowSvg?>
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="af-discount-percent">
    <label for="filter_alfa_filters_discount_percent_min_<?= $moduleId ?>">
        <?= Text::_('MOD_ALFAFILTERS_FILTER_DISCOUNT_PERCENT_MIN_LABEL') ?>
    </label>
    <div class="af-discount-percent-inner">
        <?= $form_fields['discount_percent_min']; ?>
        <?php if($dynamic_filtering) : ?>
            <button type="button" class="af-discount-percent-btn">
                <?=$arrowSvg?>
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if (!$dynamic_filtering): ?>
    <button type="submit"><?= Text::_('MOD_ALFAFILTERS_FILTER_SUBMIT_BTN') ?></button>
<?php endif; ?>
