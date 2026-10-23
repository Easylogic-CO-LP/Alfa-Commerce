<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\LocationPolicy;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

// Stock column: every figure comes from the stock records, read once for the
// whole grid. A combination that tracks stock gets an input only when a single
// figure can express its stock (one enabled location, stock held nowhere else);
// the input posts back with the figure it was rendered with, so an untouched
// figure moves nothing on save. Otherwise the cell is a read-only summary that
// opens the Studio, where stock is edited per location.
$stockDb = Factory::getContainer()->get('DatabaseDriver');
$stockByCombination = ItemFieldsAdminHelper::combinationStockByLocation(itemId: (int) $this->item->id);
$enabledLocationIds = LocationPolicy::enabledIds(db: $stockDb);
$stockLocationTitles = $stockDb->setQuery(
    $stockDb->getQuery(true)
        ->select($stockDb->quoteName(['id', 'title']))
        ->from($stockDb->quoteName('#__alfa_inventory_locations')),
)->loadAssocList('id', 'title') ?: [];
$itemStockTracking = $this->item->manage_stock ?? null;

/**
 * The item's field layer: three sections, each a list with its own action in
 * its header. A field's role comes from where it is used, not from a flag.
 *
 *   VARIATIONS    Generate (popup: pick pool values, cross-combine, additive
 *                 and idempotent) plus the grid with inline quick-edits,
 *                 per-value filters and bulk actions. Per-variant edits open
 *                 the field action popup.
 *   OPTIONS       Cart-line modifiers: offered values with an optional price
 *                 delta, saved via AJAX. Dimension fields are not listed here.
 *   INFORMATIONAL The item's descriptive values (specs / catalog filters),
 *                 saved via AJAX.
 *
 * Category scope decides eligibility; the definition order applies throughout.
 */

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.combinations')
    ->useScript('com_alfa.combinations')
    // The per-row name override reuses MultilingualTextField's flag-pin chrome
    // (.ml-field-stack / .ml-field-item / .ml-field-flag).
    ->useStyle('com_alfa.multilingual-fields')
    // The grid filters reuse core's fancy multiselect (choices.js with
    // search), the same component joomla.form.field.list-fancy-select renders.
    ->useScript('webcomponent.field-fancy-select')
    // Field-plugin action buttons open the action page (Alfa.openAction).
    ->useScript('com_alfa.actions')
    // Per-field visibility (ShowOn) builder, rendered in a lazy popup.
    ->useStyle('com_alfa.showon-builder')
    ->useScript('com_alfa.showon-builder')
    // Drag-to-reorder handles for the option + variation lists (vendored).
    ->useScript('com_alfa.sortable');

Text::script('COM_ALFA_COMBINATION_DIRTY_GUARD');
Text::script('COM_ALFA_COMBINATION_NAV_DIRTY');
Text::script('COM_ALFA_COMBI_GENERATE_NONE');
Text::script('COM_ALFA_COMBI_BULK_STOCK_PROMPT');
Text::script('COM_ALFA_COMBI_BULK_DELETE_CONFIRM');
Text::script('COM_ALFA_SHOWON_BADGE_CUSTOM');
Text::script('COM_ALFA_SHOWON_BADGE_GLOBAL');
Text::script('COM_ALFA_SHOWON_BADGE_INVALID');
Text::script('COM_ALFA_SHOWON_ALWAYS');
Text::script('COM_ALFA_SHOWON_SET');
Text::script('COM_ALFA_SHOWON_EDIT');

$combinations = is_array($this->itemCombinations ?? null) ? $this->itemCombinations : [];
$descriptors = is_array($this->combinationOverrideFields ?? null) ? $this->combinationOverrideFields : [];
$allFields = is_array($this->itemFieldsData ?? null) ? $this->itemFieldsData : [];

$scalarNames = array_keys(array_filter($descriptors, static fn ($d) => !$d->multilingual));

// Every field is uniform: variations come from choice (non-informational)
// fields, informational fields carry specs. A field's plugin adds Configure
// actions on the rows it cares about (variation/option), no separate section.
$choiceFields = array_values(array_filter(
    $allFields,
    static fn ($f) => (int) $f->informational !== 1 && !empty($f->values),
));
$infoFields = array_values(array_filter(
    $allFields,
    static fn ($f) => (int) $f->informational === 1 && !empty($f->values),
));

// The dimension set of the EXISTING combinations (empty = free choice).
$comboFieldIds = \Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper::getCombinationFieldIds(
    itemId: (int) $this->item->id,
);

// Filters only make sense over the dimensions actually in use; options only
// over choice fields NOT serving as dimensions.
$filterFields = array_values(array_filter(
    $choiceFields,
    static fn ($f) => in_array((int) $f->id, $comboFieldIds, true),
));
$optionFields = array_values(array_filter(
    $choiceFields,
    static fn ($f) => !in_array((int) $f->id, $comboFieldIds, true),
));

// Fields that may still seed new rows. A retired one (unpublished, or scoped
// away from this item's categories) is kept above so its saved rows keep
// rendering; it just never feeds the generator again.
$liveChoiceFields = array_values(array_filter($choiceFields, static fn ($f) => empty($f->retired)));

// An unpublished value stays in the pool only while a saved row still points at
// it, so labels resolve without the picker offering it to anybody else.
$poolValues = static fn ($field): array => array_values(array_filter(
    (array) $field->values,
    static fn ($value) => empty($value->retired) || !empty($value->in_use),
));

// The three sections below are fed entirely by item fields. When a section has
// none, it explains itself and offers the way out (see layouts/combinations/empty).
// The tour is only offered when it actually exists in this install.
$fieldsTour = (bool) (\Alfa\Component\Alfa\Administrator\Helper\SetupHelper::tourAvailability(
    ['com_alfa.item-fields'],
)['com_alfa.item-fields'] ?? null);

$emptyPanel = static function (string $message, bool $link = true) use ($fieldsTour): void {
    echo \Joomla\CMS\Layout\LayoutHelper::render(
        'combinations.empty',
        ['message' => $message, 'tour' => $fieldsTour, 'link' => $link],
        JPATH_ADMINISTRATOR . '/components/com_alfa/layouts',
    );
};

// Fields actually PRESENT on this item: those that contribute values here as a
// variant dimension, an option row or an informational pick. Drives the ShowOn
// dangling check (a rule gating on a field NOT present reads as "Always shown"),
// from data already loaded: no extra query.
$presentFieldNames = [];

foreach ($allFields as $eligibleField) {
    if (
        !empty($eligibleField->option_rows)
        || !empty($eligibleField->selected_value_ids)
        || in_array((int) $eligibleField->id, $comboFieldIds, true)
    ) {
        $presentFieldNames[] = (string) ($eligibleField->field_name ?? '');
    }
}

// Per-field visibility (ShowOn): state + human summary for options + informational
// fields (the non-variant ShowOn targets), rendered inline at the group-header edge.

// Field role + render-order rank, so the badge can flag a rule that gates on a
// LATER or informational field (forward-only). Built from data already loaded:
// no extra query.
$fieldRoleRank = \Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper::fieldRoleRank(
    fields: $allFields,
    comboFieldIdSet: array_fill_keys($comboFieldIds, true),
);

$showonDisplay = \Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper::getShowonDisplay(
    itemId: (int) $this->item->id,
    fields: array_merge($optionFields, $infoFields),
    allFields: $allFields,
    presentFieldNames: $presentFieldNames,
    roleRank: $fieldRoleRank,
);

$requiredOverrides = \Alfa\Component\Alfa\Administrator\Helper\FieldParams::getItemAll((int) $this->item->id);

// Per-item REQUIRED override: '' inherits the field's global flag; the
// buyer cannot add to cart until every effective-required field is answered.
$requiredControl = static function ($field) use ($requiredOverrides): string {
    $fieldId = (int) $field->id;
    $esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $override = (string) (($requiredOverrides[$fieldId] ?? null)?->get('required', '') ?? '');
    $globalTxt = Text::_(!empty($field->required) ? 'JYES' : 'JNO');

    return '<span class="required-control me-2" title="' . $esc(Text::_('COM_ALFA_FIELD_REQUIRED_TIP')) . '">'
        . '<select class="form-select form-select-sm w-auto d-inline-block" name="jform[field_required][' . $fieldId . ']">'
        . '<option value=""' . ($override === '' ? ' selected' : '') . '>' . $esc(Text::sprintf('COM_ALFA_FIELD_REQUIRED_INHERIT', $globalTxt)) . '</option>'
        . '<option value="1"' . ($override === '1' ? ' selected' : '') . '>' . $esc(Text::_('COM_ALFA_FIELD_REQUIRED_YES')) . '</option>'
        . '<option value="0"' . ($override === '0' ? ' selected' : '') . '>' . $esc(Text::_('COM_ALFA_FIELD_REQUIRED_NO')) . '</option>'
        . '</select></span>';
};

$showonControl = static function ($field) use ($showonDisplay): string {
    $fieldId = (int) $field->id;
    $info    = $showonDisplay[$fieldId] ?? ['state' => 'none', 'summary' => ''];
    $state   = $info['state'];
    $summary = (string) $info['summary'];
    $esc     = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    // The badge text comes from the state; its colour + the badge/summary
    // visibility are driven by [data-showon-state] in CSS, so a JS save just sets
    // the state + text (no class juggling).
    $badgeText = $state === 'set'
        ? Text::_('COM_ALFA_SHOWON_BADGE_CUSTOM')
        : ($state === 'inherited'
            ? Text::_('COM_ALFA_SHOWON_BADGE_GLOBAL')
            : ($state === 'invalid' ? Text::_('COM_ALFA_SHOWON_BADGE_INVALID') : ''));

    return '<span class="showon-control" data-showon-control data-showon-state="' . $state . '">'
        . '<span class="showon-badge" data-showon-badge>' . $esc($badgeText) . '</span>'
        . '<span class="showon-summary" data-showon-summary>' . $esc($summary !== '' ? $summary : Text::_('COM_ALFA_SHOWON_ALWAYS')) . '</span>'
        . '<button type="button" class="btn btn-sm btn-link p-0 showon-edit" data-showon-open data-field-id="' . $fieldId . '"'
        . ' title="' . $esc(Text::_('COM_ALFA_SHOWON_BTN_TITLE')) . '">'
        . '<span class="icon-pencil-2" aria-hidden="true"></span> '
        . '<span data-showon-edit-label>' . $esc(Text::_($state === 'none' ? 'COM_ALFA_SHOWON_SET' : 'COM_ALFA_SHOWON_EDIT')) . '</span>'
        . '</button>'
        . '</span>';
};
$isOptionField = static fn ($field): bool => !empty($field->option_rows);

// Content languages + flags for the per-row name override, resolved ONCE for
// the whole grid (same source as MultilingualTextField, so the inputs match the
// form above them).
$contentLanguages = [];

foreach (\Joomla\CMS\Language\LanguageHelper::getLanguages('lang_code') as $languageCode => $languageInfo) {
    $contentLanguages[] = [
        'suffix' => strtolower(str_replace('-', '_', (string) $languageCode)),
        'img' => !empty($languageInfo->image)
            ? \Joomla\CMS\Uri\Uri::root() . 'media/mod_languages/images/' . $languageInfo->image . '.gif'
            : null,
        'sef' => (string) ($languageInfo->sef ?? ''),
        'title' => (string) ($languageInfo->title_native ?? $languageCode),
    ];
}

$singleLanguage = \count($contentLanguages) <= 1;

// Per-language name override: quick-editable here so a variant can be retitled
// without opening the Studio. NOT the derived label beside it: a typed name
// REPLACES the whole product title for this variant on the storefront, the cart
// and frozen order lines. Blank inherits the item's own name.
//
// Its OWN column, always visible: the same standing every other editable cell
// in this grid has. Costs no query: the translations are already loaded for the
// override chips.
//
// COLLAPSED to the default content language (the first, as MultilingualTextField
// resolves it) so a row stays one line high; the other languages are rendered
// alongside and revealed by CSS :focus-within the moment the cell is edited. A
// row that already holds a non-default override renders expanded: an override
// nobody can see is worse than a tall row.
$nameControl = static function ($combination) use ($contentLanguages, $singleLanguage): string {
    $esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $base = 'jform[combinations][' . (int) $combination->id . ']';
    $tip = $esc(Text::_('COM_ALFA_COMBINATION_NAME_TIP'));
    $inherit = $esc(Text::_('COM_ALFA_COMBINATION_OVR_INHERIT'));
    $valueFor = static fn (array $lang): string => (string) ($combination->translations[$lang['suffix']]['name'] ?? '');
    $items = '';

    // Any secondary-language override pins the cell open.
    $expanded = false;

    foreach (\array_slice($contentLanguages, 1) as $secondary) {
        $expanded = $expanded || $valueFor($secondary) !== '';
    }

    foreach ($contentLanguages as $index => $language) {
        $items .= '<div class="ml-field-item' . ($index > 0 ? ' is-secondary' : '') . '">'
            . '<input type="text" class="form-control form-control-sm" title="' . $tip . '"'
            . ' name="' . $base . '[name_' . $language['suffix'] . ']"'
            . ' value="' . $esc($valueFor($language)) . '"'
            . ' placeholder="' . $inherit . '">';

        if (!$singleLanguage) {
            $items .= $language['img'] !== null
                ? '<img class="ml-field-flag" src="' . $esc($language['img']) . '"'
                    . ' alt="' . $esc($language['sef']) . '" title="' . $esc($language['title']) . '">'
                : '<span class="ml-field-flag ml-field-flag--text">' . $esc($language['sef']) . '</span>';
        }

        $items .= '</div>';
    }

    return '<div class="ml-field-stack variant-name" data-variant-name'
        . ($expanded ? ' data-expanded' : '') . '>' . $items . '</div>';
};

// Value ids actually USED by the existing combinations; the filters offer
// only those (a value with no combination cannot match anything).
$usedValueIds = [];

foreach ($combinations as $usedCombination) {
    foreach ((array) ($usedCombination->value_ids ?? []) as $usedValueId) {
        $usedValueIds[(int) $usedValueId] = true;
    }
}

// Operation cycler: ONE button in front of the amount showing the CURRENT
// operation; clicking cycles + -> - -> +% -> -% (stored in the hidden input).
$opCycle = static function (string $name, ?string $current): string {
    $current = in_array($current, ['+', '-', '%', '-%'], true) ? $current : '+';
    $labels = ['+' => '+', '-' => '−', '%' => '+%', '-%' => '−%'];

    return '<span class="op-cycle">'
        . '<button type="button" data-op-cycle title="' . $labels[$current] . '">' . $labels[$current] . '</button>'
        . '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($current, ENT_QUOTES, 'UTF-8') . '">'
        . '</span>';
};

// Generic value cycler (same chrome as op-cycle): a prefix button that walks a
// fixed value->label map. Used for the quick mode (price/adjust) and the adjust
// target (net/gross). $extra adds attributes on the wrapper (e.g. data-quick-mode).
$cycle = static function (string $name, ?string $current, array $options, string $extra = ''): string {
    $values = array_keys($options);
    $current = in_array($current, $values, true) ? $current : ($values[0] ?? '');
    $esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    return '<span class="op-cycle" data-cycle data-cycle-values="' . $esc(implode(',', $values)) . '"'
        . ' data-cycle-labels="' . $esc(json_encode($options)) . '"' . ($extra !== '' ? ' ' . $extra : '') . '>'
        . '<button type="button" title="' . $esc($options[$current] ?? $current) . '">' . $esc($options[$current] ?? $current) . '</button>'
        . '<input type="hidden" name="' . $esc($name) . '" value="' . $esc($current) . '">'
        . '</span>';
};

// Default start/final target for a NEW quick adjust (global config).
$adjustTargetDefault = \Joomla\CMS\Component\ComponentHelper::getParams('com_alfa')->get('combination_adjust_target', 'final');
$adjustTargetDefault = in_array($adjustTargetDefault, ['start', 'final'], true) ? $adjustTargetDefault : 'final';

// Live final-price preview for the quick edit: the item's any-context pricing
// coefficients (final = a*net + b) + its base net price, computed ONCE here. The
// grid derives each row's final CLIENT-SIDE (no per-row server cost).
$priceCoef = \Alfa\Component\Alfa\Administrator\Field\AlfapricesField::rowPreview((int) $this->item->id, []);
$priceCoef['baseNet'] = \Alfa\Component\Alfa\Administrator\Field\AlfapricesField::baseNet((int) $this->item->id);
\Joomla\CMS\Factory::getApplication()->getDocument()->addScriptOptions('com_alfa.combinations.priceCoef', $priceCoef);

// Whether any discount runs in the any-context preview: gates the read-only
// "after discounts" figure beside each row's final (same idea as the Prices panel).
$hasDiscounts = !empty($priceCoef['discounts']);

// Shared renderer for field-plugin action buttons (option/variation rows).
$actionButtonLayout = new \Joomla\CMS\Layout\FileLayout('actions.button', JPATH_ADMINISTRATOR . '/components/com_alfa/layouts');
?>

<div class="row">
    <div class="col-lg-12">
        <fieldset id="fieldset-combinations" class="options-form">
            <legend><?php echo Text::_('COM_ALFA_TAB_ITEM_COMBINATIONS_GRID'); ?></legend>
            <p class="text-muted small"><?php echo Text::_('COM_ALFA_ITEM_COMBINATIONS_HELP'); ?></p>

            <input type="hidden" name="jform[deleted_combination_ids]" id="deleted-combination-ids" value="">

            <div class="alfa-combi-tools" data-field-settings data-item-id="<?php echo (int) $this->item->id; ?>">

                <!-- Variations -->
                <?php // data-variations-panel: the stable anchor the product tour highlights,
                      // matching data-options-panel / data-info-panel on its siblings. ?>
                <div class="tools-panel with-head" data-variations-panel>
                    <div class="tools-head">
                        <span class="tools-caption"><?php echo Text::_('COM_ALFA_COMBI_SECTION_VARIATIONS'); ?></span>
                        <div class="tools-actions">
                            <?php if ($combinations && $filterFields) : ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-combi-filter-toggle aria-expanded="false">
                                    <span class="icon-search" aria-hidden="true"></span> <?php echo Text::_('COM_ALFA_COMBI_FILTER_TOGGLE'); ?>
                                </button>
                            <?php endif; ?>
                            <?php if ($combinations) : ?>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-secondary dropdown-toggle"
                                            data-bs-toggle="dropdown" aria-expanded="false" data-bulk-trigger disabled>
                                        <?php echo Text::_('COM_ALFA_COMBI_BULK'); ?> <span class="badge bg-primary" data-bulk-count hidden>0</span>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><button type="button" class="dropdown-item" data-bulk-state="1"><?php echo Text::_('COM_ALFA_COMBINATIONS_BULK_ENABLED'); ?></button></li>
                                        <li><button type="button" class="dropdown-item" data-bulk-state="0"><?php echo Text::_('COM_ALFA_COMBINATIONS_BULK_DISABLED'); ?></button></li>
                                        <li><button type="button" class="dropdown-item" data-bulk-stock><?php echo Text::_('COM_ALFA_COMBI_BULK_STOCK'); ?></button></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><button type="button" class="dropdown-item text-danger" data-bulk-delete><?php echo Text::_('JACTION_DELETE'); ?></button></li>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            <?php if ($liveChoiceFields) : ?>
                                <button type="button" class="btn btn-sm btn-primary"
                                        data-bs-toggle="modal" data-bs-target="#combinationGeneratorModal">
                                    <span class="icon-cog" aria-hidden="true"></span> <?php echo Text::_('COM_ALFA_COMBI_GENERATE'); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!$choiceFields && !$combinations) : ?>
                        <?php $emptyPanel(Text::_('COM_ALFA_COMBI_VARIATIONS_EMPTY')); ?>
                    <?php elseif (!$liveChoiceFields && $combinations) : ?>
                        <?php // Rows survive their field: say why Generate is gone. ?>
                        <p class="tools-hint mb-3"><?php echo Text::_('COM_ALFA_COMBI_VARIATIONS_RETIRED'); ?></p>
                    <?php endif; ?>

                    <?php if ($combinations && $filterFields) : ?>
                        <!-- "Show only" filter: collapsed until toggled from the header. -->
                        <div class="tools-subpanel" data-combi-filters hidden>
                            <div class="filter-selects">
                                <?php foreach ($filterFields as $filterField) : ?>
                                    <?php
                                    // Offer ONLY values that exist in the grid.
                                    $usedValues = array_values(array_filter(
                                        $poolValues($filterField),
                                        static fn ($v) => isset($usedValueIds[(int) $v->id]),
                                    ));

                                    if (!$usedValues) {
                                        continue;
                                    }
                                    ?>
                                    <div class="filter-select" data-filter-field>
                                        <label class="tools-label" for="combi-filter-<?php echo (int) $filterField->id; ?>"><?php echo $this->escape($filterField->display_name); ?></label>
                                        <joomla-field-fancy-select>
                                            <select multiple data-filter-select id="combi-filter-<?php echo (int) $filterField->id; ?>">
                                                <?php foreach ($usedValues as $poolValue) : ?>
                                                    <option value="<?php echo (int) $poolValue->id; ?>"><?php echo $this->escape($poolValue->display_name); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </joomla-field-fancy-select>
                                    </div>
                                <?php endforeach; ?>
                                <button type="button" class="btn btn-sm btn-secondary" data-filter-clear hidden>
                                    <?php echo Text::_('COM_ALFA_COMBI_FILTER_CLEAR'); ?>
                                </button>
                            </div>
                            <p class="tools-hint mb-0" data-filter-empty hidden><?php echo Text::_('COM_ALFA_COMBI_NO_MATCH'); ?></p>
                        </div>

                    <?php endif; ?>

                    <?php if (!$combinations) : ?>
                        <?php // Same quiet empty state as Options and Info - no alert box: the
                              // action that fixes it (Generate) is already in this panel's head. ?>
                        <div class="tools-empty">
                            <p class="tools-hint mb-0"><?php echo Text::_('COM_ALFA_ITEM_COMBINATIONS_EMPTY'); ?></p>
                        </div>
                    <?php else : ?>
                        <table class="table table-sm align-middle mb-0 alfa-combinations">
                            <thead>
                            <tr>
                                <th class="w-1"></th>
                                <th class="w-1"><input type="checkbox" class="form-check-input" id="combi-select-all" aria-label="<?php echo $this->escape(Text::_('JGLOBAL_CHECK_ALL')); ?>" data-select-all></th>
                                <th><?php echo Text::_('COM_ALFA_ITEM_COMBINATIONS_VARIANT'); ?></th>
                                <th class="w-15">
                                    <?php echo Text::_('COM_ALFA_ITEM_COMBINATIONS_NAME'); ?>
                                    <span class="icon-info-circle alfa-quick-info" aria-hidden="true"
                                          title="<?php echo $this->escape(Text::_('COM_ALFA_COMBINATION_NAME_TIP')); ?>"></span>
                                </th>
                                <th class="w-15"><?php echo Text::_('COM_ALFA_ITEM_COMBINATIONS_SKU'); ?></th>
                                <th class="w-10"><?php echo Text::_('COM_ALFA_ITEM_COMBINATIONS_STOCK'); ?></th>
                                <th class="w-10">
                                    <?php echo Text::_('COM_ALFA_ITEM_COMBINATIONS_ADJUST'); ?>
                                    <span class="icon-info-circle alfa-quick-info" aria-hidden="true"
                                          title="<?php echo $this->escape(Text::_('COM_ALFA_ITEM_COMBINATIONS_QUICK_ANYCONTEXT_DESC')); ?>"></span>
                                </th>
                                <th><?php echo Text::_('COM_ALFA_ITEM_COMBINATIONS_CUSTOMIZED'); ?></th>
                                <th class="w-5 text-center"><?php echo Text::_('COM_ALFA_ITEM_COMBINATIONS_DEFAULT'); ?></th>
                                <th class="w-5 text-center"><?php echo Text::_('JSTATUS'); ?></th>
                                <th class="w-10 text-end"></th>
                            </tr>
                            </thead>
                            <tbody data-sortable-combinations>
                            <?php foreach ($combinations as $combination) :
                                $combinationId = (int) $combination->id;
                                $base = 'jform[combinations][' . $combinationId . ']';

                                // Summary chips: what does this variant customize?
                                $priceRows = (array) ($combination->price_rows ?? []);
                                $priceCount = count(array_filter($priceRows, static fn ($r) => ((array) $r)['_mode'] !== 'adjust'));
                                $adjustCount = count($priceRows) - $priceCount;
                                $mediaCount = (int) ($combination->media_count ?? 0);
                                $overrideCount = count(array_filter($scalarNames, static fn ($n) => $combination->{$n} !== null));
                                $textCount = count((array) ($combination->translations ?? []));

                                // Quick edit owns at most ONE simple (any-context) row, of
                                // either mode (price or adjust). Two or more rows (or a
                                // single CONDITIONED row) are the Studio's business and
                                // lock the grid edit (it must never clobber/confuse them).
                                $isAnyContext = static fn (array $r): bool => !(int) ($r['currency_id'] ?? 0)
                                    && !(int) ($r['country_id'] ?? 0)
                                    && !(int) ($r['usergroup_id'] ?? 0)
                                    && !(int) ($r['user_id'] ?? 0)
                                    && trim((string) ($r['quantity_start'] ?? '')) === ''
                                    && trim((string) ($r['quantity_end'] ?? '')) === ''
                                    && empty($r['publish_up'])
                                    && empty($r['publish_down']);

                                $quickRow = null;
                                $quickLocked = false;

                                if (count($priceRows) >= 2) {
                                    $quickLocked = true;
                                } elseif (count($priceRows) === 1) {
                                    $single = (array) $priceRows[array_key_first($priceRows)];

                                    if ($isAnyContext($single)) {
                                        $quickRow = $single;
                                    } else {
                                        $quickLocked = true;
                                    }
                                }

                                $quickMode = (($quickRow['_mode'] ?? 'adjust') === 'price') ? 'price' : 'adjust';
                                $quickOperation = in_array($quickRow['operation'] ?? null, ['+', '-', '%', '-%'], true) ? $quickRow['operation'] : '+';
                                $quickTarget = in_array($quickRow['override_type'] ?? '', ['start', 'final'], true)
                                    ? $quickRow['override_type'] : $adjustTargetDefault;
                                // A 'price' row carrying its own sale is charged the sale (the
                                // engine's sale rule); the "after discounts" figure follows it.
                                $quickSaleValue = ($quickMode === 'price' && $quickRow !== null) ? trim((string) ($quickRow['override_value'] ?? '')) : '';
                                $quickSaleType = $quickSaleValue === '' ? '' : ((($quickRow['override_type'] ?? '') === 'final') ? 'final' : 'start');
                                ?>
                                <tr data-combination-id="<?php echo $combinationId; ?>" data-values="<?php echo $this->escape($combination->signature); ?>" data-tuple="<?php echo implode('-', array_map('intval', (array) ($combination->value_ids ?? []))); ?>">
                                    <td class="text-center"><span class="sortable-handler" aria-hidden="true" title="<?php echo $this->escape(Text::_('COM_ALFA_DRAG_TO_REORDER')); ?>"><span class="icon-ellipsis-v"></span></span></td>
                                    <td><input type="checkbox" class="form-check-input" id="combi-select-<?php echo $combinationId; ?>" aria-label="<?php echo $this->escape($combination->label); ?>" data-select-row></td>
                                    <td>
                                        <span class="variant-label"><?php echo $this->escape($combination->label); ?></span>
                                        <div class="signature"><?php echo $this->escape($combination->signature); ?></div>
                                    </td>
                                    <td><?php echo $nameControl($combination); ?></td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm" name="<?php echo $base; ?>[sku]"
                                               value="<?php echo $this->escape($combination->sku); ?>"
                                               placeholder="<?php echo $this->escape(($this->item->sku ?? '') !== '' ? $this->item->sku : '-'); ?>">
                                    </td>
                                    <td>
                                        <?php
                                        // The combination's own tracking setting when it has one,
                                        // else the product's; no setting at all means not tracked.
                                        $unitStock = $stockByCombination[$combinationId] ?? [];
                                        $tracksStock = (int) ($combination->manage_stock ?? $itemStockTracking ?? 0) === 1;
                                        $gridLocationId = $tracksStock
                                            ? ItemFieldsAdminHelper::gridStockLocation(unitRows: $unitStock, enabledLocationIds: $enabledLocationIds)
                                            : null;
                                        ?>
                                        <?php if (!$tracksStock) : ?>
                                            <!-- No stock is counted for this variant; tracking is switched
                                                 on in its Edit, together with the quantity on hand. -->
                                            <span class="chip" data-open-edit role="button" tabindex="0"><?php echo Text::_('COM_ALFA_COMBI_STOCK_NOT_TRACKED'); ?></span>
                                        <?php elseif ($gridLocationId !== null) : ?>
                                            <?php $gridFigure = InventoryAvailabilityService::formatQuantity(value: (float) ($unitStock[$gridLocationId]['physical'] ?? 0.0)); ?>
                                            <input type="number" step="any" class="form-control form-control-sm" name="<?php echo $base; ?>[stock]"
                                                   value="<?php echo $this->escape($gridFigure); ?>">
                                            <input type="hidden" name="<?php echo $base; ?>[stock_baseline]"
                                                   value="<?php echo $this->escape($gridFigure); ?>">
                                        <?php else : ?>
                                            <?php
                                            // Stock at several locations: the quantities live in the
                                            // Studio; the cell shows the totals (physical / available /
                                            // reserved) and the per-location split rides the tooltip.
                                            $chipPhysical = 0.0;
                                            $chipAvailable = 0.0;
                                            $chipReserved = 0.0;
                                            $parts = [];

                                            foreach ($unitStock as $locId => $figures) {
                                                $chipPhysical += $figures['physical'];
                                                $chipReserved += $figures['reserved'];

                                                if (\in_array((int) $locId, $enabledLocationIds, true)) {
                                                    $chipAvailable += $figures['physical'] - $figures['reserved'];
                                                }
                                            }

                                            foreach (array_unique(array_merge($enabledLocationIds, array_keys($unitStock))) as $locId) {
                                                $parts[] = ($stockLocationTitles[$locId] ?? ('#' . $locId)) . ': '
                                                    . InventoryAvailabilityService::formatQuantity(value: (float) ($unitStock[$locId]['physical'] ?? 0.0));
                                            }
                                            ?>
                                            <!-- Per-location quantities: the grid must not clobber them;
                                                 the chip opens this row's Edit (same doctrine as price). -->
                                            <span class="quick-adjust is-locked" data-open-edit role="button" tabindex="0"
                                                  title="<?php echo $this->escape(implode(' - ', $parts) . ' - ' . Text::_('COM_ALFA_ITEM_COMBINATIONS_STOCK_LOCKED_DESC')); ?>">
                                                <span class="icon-lock" aria-hidden="true"></span>
                                                <strong><?php echo $this->escape(InventoryAvailabilityService::formatQuantity(value: $chipPhysical)); ?></strong>
                                                <small class="text-muted">
                                                    <?php echo Text::_('COM_ALFA_INVENTORY_ABBR_AVAILABLE'); ?> <?php echo $this->escape(InventoryAvailabilityService::formatQuantity(value: $chipAvailable)); ?>
                                                    - <?php echo Text::_('COM_ALFA_INVENTORY_ABBR_RESERVED'); ?> <?php echo $this->escape(InventoryAvailabilityService::formatQuantity(value: $chipReserved)); ?>
                                                </small>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($quickLocked) : ?>
                                            <!-- 2+ price rows: the grid must not clobber them; the chip opens
                                                 this row's Edit, where the full price matrix is managed. -->
                                            <span class="quick-adjust is-locked" data-open-edit role="button" tabindex="0"
                                                  title="<?php echo $this->escape(Text::_('COM_ALFA_ITEM_COMBINATIONS_QUICK_LOCKED_DESC')); ?>">
                                                <span class="icon-lock" aria-hidden="true"></span>
                                                <?php echo Text::_('COM_ALFA_ITEM_COMBINATIONS_QUICK_LOCKED'); ?>
                                            </span>
                                        <?php else : ?>
                                            <!-- Quick edit of the single any-context row: a full price
                                                 OR a +/-/% adjust on the item price. The op-cycle and
                                                 the net/final target show only in adjust mode. All three
                                                 are the same cycle-button chrome. -->
                                            <span class="quick-adjust" data-quick-sale-type="<?php echo $this->escape($quickSaleType); ?>" data-quick-sale-value="<?php echo $this->escape($quickSaleValue); ?>">
                                                <?php echo $cycle($base . '[quick][mode]', $quickMode, [
                                                    'adjust' => Text::_('COM_ALFA_PRICES_MODE_ADJUST'),
                                                    'price' => Text::_('COM_ALFA_PRICES_MODE_PRICE'),
                                                ], 'data-quick-mode'); ?>
                                                <span class="adjust-only"<?php echo $quickMode === 'price' ? ' hidden' : ''; ?>>
                                                    <?php echo $opCycle($base . '[quick][operation]', $quickOperation); ?>
                                                </span>
                                                <input type="number" step="0.00000001" class="form-control form-control-sm" data-quick-value
                                                       name="<?php echo $base; ?>[quick][value]"
                                                       value="<?php echo $quickRow !== null ? $this->escape($quickRow['value']) : ''; ?>"
                                                       placeholder="&mdash;">
                                                <span class="adjust-only"<?php echo $quickMode === 'price' ? ' hidden' : ''; ?>>
                                                    <?php echo $cycle($base . '[quick][target]', $quickTarget, [
                                                        'final' => Text::_('COM_ALFA_PRICES_TARGET_GROSS'),
                                                        'start' => Text::_('COM_ALFA_PRICES_TARGET_NET'),
                                                    ]); ?>
                                                </span>
                                                <!-- Final (gross, BEFORE discounts), editable: typing here
                                                     back-computes the net value/adjust. Not posted (the net
                                                     value is the source). Same meaning as the Prices panel. -->
                                                <input type="number" step="any" class="form-control form-control-sm quick-final"
                                                       id="combi-final-<?php echo $combinationId; ?>" data-quick-final
                                                       title="<?php echo $this->escape(Text::_('COM_ALFA_PRICES_FINAL')); ?>"
                                                       placeholder="<?php echo $this->escape(Text::_('COM_ALFA_PRICES_FINAL')); ?>">
                                                <?php if ($hasDiscounts) : ?>
                                                    <!-- Read-only "after discounts" figure (JS-filled value),
                                                         label over price like the Prices panel. Shown only while
                                                         discounts run. Informational, never posted. -->
                                                    <span class="quick-after" data-quick-after hidden>
                                                        <span class="quick-after-label"><?php echo $this->escape(Text::_('COM_ALFA_PRICES_DISCOUNTED')); ?></span>
                                                        <strong class="quick-after-value" data-quick-after-value></strong>
                                                    </span>
                                                <?php endif; ?>
                                            </span>
                                            <!-- The cell as rendered: the save writes only the fields
                                                 changed on this page. -->
                                            <input type="hidden" name="<?php echo $base; ?>[quick_baseline][mode]" value="<?php echo $this->escape($quickMode); ?>">
                                            <input type="hidden" name="<?php echo $base; ?>[quick_baseline][operation]" value="<?php echo $this->escape($quickOperation); ?>">
                                            <input type="hidden" name="<?php echo $base; ?>[quick_baseline][value]" value="<?php echo $quickRow !== null ? $this->escape($quickRow['value']) : ''; ?>">
                                            <input type="hidden" name="<?php echo $base; ?>[quick_baseline][target]" value="<?php echo $this->escape($quickTarget); ?>">
                                            <?php if ($quickSaleValue !== '') : ?>
                                                <!-- The row's custom discount, kept by every grid save: what the
                                                     shop charges for the variant, from the engine's own price. -->
                                                <small class="d-block text-muted" data-quick-sale-charged>
                                                    <?php echo Text::sprintf('COM_ALFA_COMBI_QUICK_SALE_CHARGED', $this->escape(\Alfa\Component\Alfa\Administrator\Field\AlfapricesField::chargedPrice(itemId: (int) $this->item->id, combinationId: $combinationId)->format())); ?>
                                                </small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($priceCount) : ?>
                                            <span class="chip is-set"><?php echo Text::plural('COM_ALFA_COMBINATION_CHIP_PRICES', $priceCount); ?></span>
                                        <?php endif; ?>
                                        <?php if ($adjustCount) : ?>
                                            <span class="chip is-set"><?php echo Text::plural('COM_ALFA_COMBINATION_CHIP_ADJUSTS', $adjustCount); ?></span>
                                        <?php endif; ?>
                                        <?php if ($mediaCount) : ?>
                                            <span class="chip is-set"><?php echo Text::plural('COM_ALFA_COMBINATION_CHIP_IMAGES', $mediaCount); ?></span>
                                        <?php endif; ?>
                                        <?php if ($overrideCount + $textCount) : ?>
                                            <span class="chip is-set"><?php echo Text::plural('COM_ALFA_COMBINATION_CHIP_OVERRIDES', $overrideCount + $textCount); ?></span>
                                        <?php endif; ?>
                                        <?php if (!$priceCount && !$adjustCount && !$mediaCount && !($overrideCount + $textCount)) : ?>
                                            <span class="chip"><?php echo Text::_('COM_ALFA_COMBINATION_CHIP_INHERITS'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <input type="radio" class="form-check-input" name="jform[combinations_default]"
                                               value="<?php echo $combinationId; ?>" <?php echo (int) $combination->default_on === 1 ? 'checked' : ''; ?>>
                                    </td>
                                    <td class="text-center">
                                        <input type="checkbox" class="form-check-input" name="<?php echo $base; ?>[state]" value="1"
                                               <?php echo (int) $combination->state === 1 ? 'checked' : ''; ?>>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-primary" data-studio-open
                                                data-src="<?php echo Route::_('index.php?option=com_alfa&view=item&layout=edit_combination&tmpl=component&id=' . (int) $this->item->id . '&combination_id=' . $combinationId, false); ?>"
                                                data-label="<?php echo $this->escape($combination->label); ?>">
                                            <span class="icon-edit" aria-hidden="true"></span> <?php echo Text::_('JACTION_EDIT'); ?>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" data-combination-remove aria-label="<?php echo Text::_('JACTION_DELETE'); ?>">
                                            <span class="icon-trash" aria-hidden="true"></span>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Options -->
                <?php if (!$optionFields) : ?>
                    <div class="tools-panel with-head" data-options-panel>
                        <div class="tools-head">
                            <span class="tools-caption"><?php echo Text::_('COM_ALFA_COMBI_SECTION_OPTIONS'); ?></span>
                        </div>
                        <?php $emptyPanel(
                            $choiceFields
                                ? Text::_('COM_ALFA_COMBI_OPTIONS_EMPTY_ALL_DIMENSIONS')
                                : Text::_('COM_ALFA_COMBI_OPTIONS_EMPTY'),
                            !$choiceFields,
                        ); ?>
                    </div>
                <?php endif; ?>

                <?php if ($optionFields) : ?>
                    <div class="tools-panel with-head" data-options-panel>
                        <div class="tools-head">
                            <span class="tools-caption"><?php echo Text::_('COM_ALFA_COMBI_SECTION_OPTIONS'); ?></span>
                            <button type="button" class="btn btn-sm btn-primary tools-action"
                                    data-bs-toggle="modal" data-bs-target="#itemOptionsModal">
                                <span class="icon-options" aria-hidden="true"></span> <?php echo Text::_('COM_ALFA_COMBI_SET_OPTIONS'); ?>
                            </button>
                        </div>
                        <p class="tools-hint"><?php echo Text::_('COM_ALFA_COMBI_OPTIONS_HINT'); ?></p>

                        <input type="hidden" name="jform[deleted_option_value_ids]" id="deleted-option-value-ids" value="">

                        <?php $hasOptions = (bool) array_filter($optionFields, $isOptionField); ?>
                        <div class="settings-rows" data-options-list>
                            <?php foreach ($optionFields as $optionField) : ?>
                                <?php if (!$isOptionField($optionField)) {
                                    continue;
                                } ?>
                                <?php
                                // Value-pool lookup for labels; the rows themselves are iterated
                                // in their own per-item ordering (option_rows is loaded ORDER BY
                                // ordering), so a drag reorder is what drives the displayed order.
                                $optionValueById = [];

                                foreach ($poolValues($optionField) as $poolValue) {
                                    $optionValueById[(int) $poolValue->id] = $poolValue;
                                }
                                ?>
                                <div class="settings-group" data-field-group="<?php echo (int) $optionField->id; ?>">
                                    <div class="group-header d-flex justify-content-between align-items-center">
                                        <span class="group-head-label">
                                            <span class="sortable-handler group-drag" aria-hidden="true" title="<?php echo $this->escape(Text::_('COM_ALFA_DRAG_TO_REORDER')); ?>"><span class="icon-ellipsis-v"></span></span>
                                            <?php echo $this->escape($optionField->display_name); ?>
                                            <?php if (!empty($optionField->retired)) : ?>
                                                <span class="badge bg-secondary fw-normal ms-1"
                                                      title="<?php echo $this->escape(Text::_('COM_ALFA_COMBI_FIELD_RETIRED_DESC')); ?>"><?php echo Text::_('COM_ALFA_COMBI_FIELD_RETIRED'); ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="d-inline-flex align-items-center gap-2">
                                            <?php echo $requiredControl($optionField); ?>
                                            <?php echo $showonControl($optionField); ?>
                                        </span>
                                    </div>
                                    <input type="hidden" name="jform[field_order][]" value="<?php echo (int) $optionField->id; ?>">
                                    <div class="settings-group-rows" data-sortable-options>
                                    <?php foreach ($optionField->option_rows as $valueId => $optionRow) :
                                        $valueId = (int) $valueId;
                                        $poolValue = $optionValueById[$valueId] ?? null;

                                        if ($poolValue === null) {
                                            continue;
                                        }
                                        ?>
                                        <div class="settings-row" data-option-item="<?php echo $valueId; ?>">
                                            <span class="sortable-handler" aria-hidden="true" title="<?php echo $this->escape(Text::_('COM_ALFA_DRAG_TO_REORDER')); ?>"><span class="icon-ellipsis-v"></span></span>
                                            <span class="chip is-set"><?php echo $this->escape($poolValue->display_name); ?></span>
                                            <span class="quick-adjust">
                                                <?php echo $opCycle('jform[options][' . $valueId . '][operation]', $optionRow->operation); ?>
                                                <input type="number" step="0.00000001" class="form-control form-control-sm row-amount"
                                                       name="jform[options][<?php echo $valueId; ?>][amount]"
                                                       value="<?php echo $optionRow->amount !== null ? $this->escape($optionRow->amount) : ''; ?>"
                                                       placeholder="&mdash;">
                                                <?php
                                                // Net/gross base for a fixed-amount delta. Defaults from the
                                                // global config; a percentage ignores it.
                                                echo $cycle(
                                                    'jform[options][' . $valueId . '][apply_on]',
                                                    in_array($optionRow->apply_on ?? '', ['start', 'final'], true) ? $optionRow->apply_on : $adjustTargetDefault,
                                                    ['final' => Text::_('COM_ALFA_PRICES_TARGET_GROSS'), 'start' => Text::_('COM_ALFA_PRICES_TARGET_NET')],
                                                );
                                                ?>
                                            </span>
                                            <?php
                                            // Field-plugin actions on THIS option row (scope 'option',
                                            // scope id = the option-values row id).
                                            foreach (\Alfa\Component\Alfa\Administrator\Helper\ActionRegistry::getItemFieldActions(
                                                field: $optionField,
                                                scope: 'option',
                                                scopeId: (int) $optionRow->id,
                                                itemId: (int) $this->item->id,
                                            ) as $optionAction) {
                                                echo $actionButtonLayout->render([
                                                    'action' => $optionAction,
                                                    'context' => 'itemfield',
                                                    'id' => (int) $optionField->id,
                                                    'data' => [
                                                        'scope' => 'option',
                                                        'scope_id' => (int) $optionRow->id,
                                                        'item_id' => (int) $this->item->id,
                                                    ],
                                                ]);
                                            }
                                            ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" data-option-remove
                                                    aria-label="<?php echo Text::_('JACTION_DELETE'); ?>">
                                                <span class="icon-trash" aria-hidden="true"></span>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="tools-hint mb-0" data-options-empty <?php echo $hasOptions ? 'hidden' : ''; ?>><?php echo Text::_('COM_ALFA_COMBI_OPTIONS_NONE'); ?></p>
                    </div>
                <?php endif; ?>

                <!-- Informational -->
                <?php if (!$infoFields) : ?>
                    <div class="tools-panel with-head" data-info-panel>
                        <div class="tools-head">
                            <span class="tools-caption"><?php echo Text::_('COM_ALFA_COMBI_SECTION_INFO'); ?></span>
                        </div>
                        <?php $emptyPanel(Text::_('COM_ALFA_COMBI_INFO_EMPTY')); ?>
                    </div>
                <?php endif; ?>

                <?php if ($infoFields) : ?>
                    <div class="tools-panel with-head" data-info-panel>
                        <div class="tools-head">
                            <span class="tools-caption"><?php echo Text::_('COM_ALFA_COMBI_SECTION_INFO'); ?></span>
                            <button type="button" class="btn btn-sm btn-primary tools-action"
                                    data-bs-toggle="modal" data-bs-target="#itemInfoModal">
                                <span class="icon-info-circle" aria-hidden="true"></span> <?php echo Text::_('COM_ALFA_COMBI_SET_INFO'); ?>
                            </button>
                        </div>
                        <p class="tools-hint"><?php echo Text::_('COM_ALFA_COMBI_INFO_HINT'); ?></p>

                        <input type="hidden" name="jform[deleted_info_value_ids]" id="deleted-info-value-ids" value="">

                        <?php $hasInfo = (bool) array_filter($infoFields, static fn ($f) => !empty($f->selected_value_ids)); ?>
                        <div class="settings-rows" data-info-list>
                            <?php foreach ($infoFields as $infoField) : ?>
                                <?php if (empty($infoField->selected_value_ids)) {
                                    continue;
                                } ?>
                                <?php
                                // Value-pool lookup for labels; rows iterate in the item's
                                // own ordering (selected_value_ids is loaded ORDER BY ordering),
                                // so a drag reorder drives the displayed order. The hidden
                                // value-id per row carries that order to the item save.
                                $infoValueById = [];

                                foreach ($poolValues($infoField) as $poolValue) {
                                    $infoValueById[(int) $poolValue->id] = $poolValue;
                                }
                                ?>
                                <div class="settings-group" data-field-group="<?php echo (int) $infoField->id; ?>">
                                    <div class="group-header d-flex justify-content-between align-items-center">
                                        <span class="group-head-label">
                                            <span class="sortable-handler group-drag" aria-hidden="true" title="<?php echo $this->escape(Text::_('COM_ALFA_DRAG_TO_REORDER')); ?>"><span class="icon-ellipsis-v"></span></span>
                                            <?php echo $this->escape($infoField->display_name); ?>
                                            <?php if (!empty($infoField->retired)) : ?>
                                                <span class="badge bg-secondary fw-normal ms-1"
                                                      title="<?php echo $this->escape(Text::_('COM_ALFA_COMBI_FIELD_RETIRED_DESC')); ?>"><?php echo Text::_('COM_ALFA_COMBI_FIELD_RETIRED'); ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <?php echo $showonControl($infoField); ?>
                                    </div>
                                    <input type="hidden" name="jform[field_order][]" value="<?php echo (int) $infoField->id; ?>">
                                    <div class="settings-group-rows" data-sortable-info>
                                    <?php foreach ($infoField->selected_value_ids as $valueId) :
                                        $valueId = (int) $valueId;
                                        $poolValue = $infoValueById[$valueId] ?? null;

                                        if ($poolValue === null) {
                                            continue;
                                        }
                                        ?>
                                        <div class="settings-row" data-info-item="<?php echo $valueId; ?>">
                                            <span class="sortable-handler" aria-hidden="true" title="<?php echo $this->escape(Text::_('COM_ALFA_DRAG_TO_REORDER')); ?>"><span class="icon-ellipsis-v"></span></span>
                                            <span class="chip is-set"><?php echo $this->escape($poolValue->display_name); ?></span>
                                            <input type="hidden" name="jform[info_values][]" value="<?php echo $valueId; ?>">
                                            <button type="button" class="btn btn-sm btn-outline-danger" data-info-remove
                                                    aria-label="<?php echo Text::_('JACTION_DELETE'); ?>">
                                                <span class="icon-trash" aria-hidden="true"></span>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="tools-hint mb-0" data-info-empty <?php echo $hasInfo ? 'hidden' : ''; ?>><?php echo Text::_('COM_ALFA_COMBI_INFO_NONE'); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($liveChoiceFields) : ?>
                <?php
                // LAZY popup: the body is fetched on first open via
                // item.fieldsPopup (zero page weight until needed), same
                // idea as the Studio iframe. The footer exists up front;
                // Generate stays disabled until content + valid selection.
                echo HTMLHelper::_(
                    'bootstrap.renderModal',
                    'combinationGeneratorModal',
                    [
                        'title' => Text::_('COM_ALFA_COMBI_GENERATE'),
                        'footer' => '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">'
                            . Text::_('JCANCEL') . '</button>'
                            . '<button type="button" class="btn btn-primary" data-generate disabled>'
                            . '<span class="icon-cog" aria-hidden="true"></span> ' . Text::_('COM_ALFA_COMBI_GENERATE_BTN') . '</button>',
                    ],
                    '<div class="text-center p-4" data-popup-lazy><span class="spinner-border" role="status" aria-hidden="true"></span></div>',
                );
                ?>
            <?php endif; ?>

            <?php if ($optionFields) : ?>
                <?php
                echo HTMLHelper::_(
                    'bootstrap.renderModal',
                    'itemOptionsModal',
                    [
                        'title' => Text::_('COM_ALFA_COMBI_SET_OPTIONS'),
                        'footer' => '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">'
                            . Text::_('JCANCEL') . '</button>'
                            . '<button type="button" class="btn btn-primary" data-add-options disabled>'
                            . Text::_('COM_ALFA_COMBI_ADD') . '</button>',
                    ],
                    '<div class="text-center p-4" data-popup-lazy><span class="spinner-border" role="status" aria-hidden="true"></span></div>',
                );
                ?>
            <?php endif; ?>

            <?php if ($infoFields) : ?>
                <?php
                echo HTMLHelper::_(
                    'bootstrap.renderModal',
                    'itemInfoModal',
                    [
                        'title' => Text::_('COM_ALFA_COMBI_SET_INFO'),
                        'footer' => '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">'
                            . Text::_('JCANCEL') . '</button>'
                            . '<button type="button" class="btn btn-primary" data-add-info disabled>'
                            . Text::_('COM_ALFA_COMBI_ADD') . '</button>',
                    ],
                    '<div class="text-center p-4" data-popup-lazy><span class="spinner-border" role="status" aria-hidden="true"></span></div>',
                );
                ?>
            <?php endif; ?>

            <?php if ($combinations) : ?>
                <?php
                // ONE shared Studio dialog for every variant: core bootstrap
                // modal, exactly like the order-edit popups (native
                // modal-header carries the variant title, set on open). A
                // single lazy iframe means zero per-variant page weight.
                echo HTMLHelper::_(
                    'bootstrap.renderModal',
                    'combinationStudioModal',
                    [
                        'title' => Text::_('COM_ALFA_TITLE_COMBINATION_EDIT'),
                        'width' => '80%',
                        'bodyHeight' => '70vh',
                    ],
                    '<iframe id="combination-frame" src="about:blank" title="" loading="lazy"'
                    . ' style="width:100%;height:70vh;border:0;display:block"></iframe>',
                );
                ?>
            <?php endif; ?>

            <?php if ($optionFields || $infoFields) : ?>
                <?php
                // ONE shared visibility (ShowOn) dialog for every non-variant field:
                // the body is fetched per field on open (item.fieldsPopup
                // type=visibility), the rule saved on the footer button
                // (item.saveVisibility). Field-agnostic, so one modal serves all.
                echo HTMLHelper::_(
                    'bootstrap.renderModal',
                    'itemVisibilityModal',
                    [
                        'title' => Text::_('COM_ALFA_SHOWON_MODAL_TITLE'),
                        'footer' => '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">'
                            . Text::_('JCANCEL') . '</button>'
                            . '<button type="button" class="btn btn-primary" data-showon-save>'
                            . '<span class="icon-save" aria-hidden="true"></span> ' . Text::_('JSAVE') . '</button>',
                    ],
                    '<div class="text-center p-4" data-showon-body><span class="spinner-border" role="status" aria-hidden="true"></span></div>',
                );
                ?>
            <?php endif; ?>
        </fieldset>
    </div>
</div>
