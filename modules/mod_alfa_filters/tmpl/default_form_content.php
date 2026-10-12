<?php

\defined('_JEXEC') or die;

use Alfa\Module\AlfaFilters\Site\Section\FilterSections;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;

// Support both require (parent scope) and FileLayout::render ($displayData)
if (isset($displayData)) {
    extract($displayData);
}

$alfa_filters ??= null;
$alfa_active_filters ??= [];
$moduleId ??= 0;
$minimum_price ??= 0;
$maximum_price ??= 0;
$form_fields ??= [];
$dynamic_filtering ??= false;

// THE PANEL IS A SEQUENCE OF SECTIONS, AND THE SEQUENCE IS RESOLVED UPSTREAM: item-field
// ordering from the backend, with this module's optional override reconciled on top. A
// template override that renders the sections itself can ignore this; one that wants the
// merchant's arrangement honoured should walk it. The fallback is the natural order, so a
// caller that never passes it still gets a sane panel.
$sectionOrder ??= FilterSections::natural(array_keys((array) ($alfa_filters['fields'] ?? [])));

// Arrow SVG from overridable template
ob_start();
require ModuleHelper::getLayoutPath('mod_alfa_filters', 'default_arrow');
$arrowSvg = ob_get_clean();

// The catalogue sections (categories, manufacturers, one per item field) are all drawn by
// default_filters, so the resolved order is first collapsed into BLOCKS: each is either a
// consecutive run of catalogue sections, handed over in one pass, or a single built-in
// section rendered here. Collapsing up front rather than flushing a buffer mid-loop is
// what keeps the require in this template's own variable scope, where the layout data is.
$isCatalogueSection = static fn (string $key): bool => \in_array($key, ['category', 'manufacturer'], true)
    || str_starts_with($key, FilterSections::FIELD_PREFIX);

$blocks = [];

foreach ($sectionOrder as $sectionKey) {
    if (!$isCatalogueSection($sectionKey)) {
        $blocks[] = ['catalogue' => false, 'key' => $sectionKey];

        continue;
    }

    $last = $blocks ? array_key_last($blocks) : null;

    if ($last !== null && $blocks[$last]['catalogue']) {
        $blocks[$last]['keys'][] = $sectionKey;

        continue;
    }

    $blocks[] = ['catalogue' => true, 'keys' => [$sectionKey]];
}
?>

<div class="af-main">
<?php foreach ($blocks as $block): ?>
    <?php if ($block['catalogue']) {
        $afSectionKeys = $block['keys'];

        require ModuleHelper::getLayoutPath('mod_alfa_filters', 'default_filters');

        continue;
    } ?>

    <?php switch ($block['key']):
        case 'price': ?>
        <?php // Hide the price filter entirely when there is no meaningful range to filter
              // on (e.g. all items in the current set are unpriced / 0-0). Server-side so
              // there is no flicker; the JS slider init simply finds nothing to bind.?>
        <?php if ((float) $maximum_price > 0): ?>
        <div class="af-price">
            <fieldset class="af-price-fieldset">
                <legend class="visually-hidden"><?= Text::_('MOD_ALFAFILTERS_FILTER_PRICE') ?></legend>
                <div class="af-price-header">
                    <span><?= Text::_('MOD_ALFAFILTERS_FILTER_PRICE') ?></span>
                    <button type="button" class="af-price-reset">
                        <?= Text::_('MOD_ALFAFILTERS_FILTER_RESET_BTN'); ?>
                    </button>
                </div>
                <div class="af-range">
                    <div class="af-range-track"></div>
                    <div class="af-range-highlight"></div>
                    <input type="range" class="af-range-min" name="filter[price_min]"
                           aria-label="<?= htmlspecialchars(Text::_('MOD_ALFAFILTERS_FILTER_MIN_PRICE'), ENT_QUOTES, 'UTF-8') ?>"
                               min="<?= $minimum_price ?>" max="<?= $maximum_price ?>"
                           value="<?= $alfa_active_filters['price_min'] ?? $minimum_price ?>">

                    <input type="range" class="af-range-max" name="filter[price_max]"
                           aria-label="<?= htmlspecialchars(Text::_('MOD_ALFAFILTERS_FILTER_MAX_PRICE'), ENT_QUOTES, 'UTF-8') ?>"
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

                        <?php if ($dynamic_filtering) : ?>
                            <button type="button" class="af-price-apply"
                                   aria-label="<?= Text::_('MOD_ALFAFILTERS_FILTER_APPLY_PRICE_BTN') ?>">
                                <?= $arrowSvg ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </fieldset>
        </div>
        <?php endif; // price filter?>
        <?php break; ?>

    <?php case 'ordering': ?>
        <div class="af-ordering">
            <label for="list_alfa_filters_ordering_<?= $moduleId ?>">
                <?= Text::_('MOD_ALFAFILTERS_FILTER_ORDERING_LABEL') ?>
            </label>
            <?= $form_fields['ordering']; ?>
        </div>
        <?php break; ?>

    <?php case 'limit': ?>
        <div class="af-limit">
            <label for="list_alfa_filters_limit_<?= $moduleId ?>">
                <?= Text::_('MOD_ALFAFILTERS_FILTER_LIST_LIMIT_LABEL') ?>
            </label>
            <?= $form_fields['limit']; ?>
        </div>
        <?php break; ?>

    <?php case 'stock': ?>
        <?php
        // Availability: two checkboxes, the facet's usual OR; both ticked or neither is
        // "everything". Counts say what each pick would leave, like a category's do.
        $stockCounts = (array) ($alfa_filters['stock'] ?? []);
        $stockActive = array_map('strval', array_filter((array) ($alfa_active_filters['stock'] ?? []), 'is_scalar'));
        ?>
        <fieldset class="af-fieldset af-fieldset-stock">
            <div class="af-fieldset-header">
                <legend><?= Text::_('MOD_ALFAFILTERS_FILTER_STOCK_LABEL') ?></legend>
            </div>
            <ul class="af-list">
                <?php $sIndex = 0;
        foreach (['in' => 'MOD_ALFAFILTERS_FILTER_STOCK_IN', 'out' => 'MOD_ALFAFILTERS_FILTER_STOCK_OUT'] as $stockValue => $stockLabel): ?>
                    <?php $sIndex++;
            $inputId = 'af-' . $moduleId . '-stock-' . $stockValue; ?>
                    <li class="af-item af-item-<?= $sIndex ?> depth-0" data-id="<?= $stockValue ?>">
                        <div class="af-option">
                            <input
                                type="checkbox"
                                id="<?= $inputId ?>"
                                name="filter[stock][]"
                                value="<?= $stockValue ?>"
                                <?= \in_array($stockValue, $stockActive, true) ? 'checked' : '' ?>
                            >
                            <label class="af-label" for="<?= $inputId ?>"><?= Text::_($stockLabel) ?></label>
                            <?php if (isset($stockCounts[$stockValue])): ?>
                                <span class="af-count">(<?= (int) $stockCounts[$stockValue] ?>)</span>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </fieldset>
        <?php break; ?>
    <?php case 'on_sale': ?>
        <div class="af-onsale">
            <span><?= Text::_('MOD_ALFAFILTERS_FILTER_ON_SALE_LABEL') ?></span>
            <?= $form_fields['on_sale']; ?>
        </div>
        <?php break; ?>

    <?php case 'discount_amount': ?>
        <div class="af-discount-amount">
            <label for="filter_alfa_filters_discount_amount_min_<?= $moduleId ?>">
                <?= Text::_('MOD_ALFAFILTERS_FILTER_DISCOUNT_AMOUNT_MIN_LABEL') ?>
            </label>
            <div class="af-discount-amount-inner">
                <?= $form_fields['discount_amount_min']; ?>
                <?php if ($dynamic_filtering) : ?>
                    <button type="button" class="af-discount-amount-btn"
                            aria-label="<?= htmlspecialchars(Text::_('MOD_ALFAFILTERS_FILTER_APPLY_DISCOUNT_AMOUNT_BTN'), ENT_QUOTES, 'UTF-8') ?>">
                        <?=$arrowSvg?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php break; ?>

    <?php case 'discount_percent': ?>
        <div class="af-discount-percent">
            <label for="filter_alfa_filters_discount_percent_min_<?= $moduleId ?>">
                <?= Text::_('MOD_ALFAFILTERS_FILTER_DISCOUNT_PERCENT_MIN_LABEL') ?>
            </label>
            <div class="af-discount-percent-inner">
                <?= $form_fields['discount_percent_min']; ?>
                <?php if ($dynamic_filtering) : ?>
                    <button type="button" class="af-discount-percent-btn"
                            aria-label="<?= htmlspecialchars(Text::_('MOD_ALFAFILTERS_FILTER_APPLY_DISCOUNT_PERCENT_BTN'), ENT_QUOTES, 'UTF-8') ?>">
                        <?=$arrowSvg?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php break; ?>
    <?php endswitch; ?>
<?php endforeach; ?>
</div>

<?php // The submit button ends the form whatever order the sections are in; it is the
      // form's control, not one of the sections a merchant arranges.?>
<?php if (!$dynamic_filtering): ?>
    <button type="submit"><?= Text::_('MOD_ALFAFILTERS_FILTER_SUBMIT_BTN') ?></button>
<?php endif; ?>
