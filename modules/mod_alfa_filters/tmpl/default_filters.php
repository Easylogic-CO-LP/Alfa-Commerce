<?php

\defined('_JEXEC') or die;

use Alfa\Module\AlfaFilters\Site\Helper\AlfaFiltersHelper;
use Alfa\Module\AlfaFilters\Site\Section\FilterSections;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\Registry\Registry;

$app = Factory::getApplication();
$appSettings = ComponentHelper::getParams('com_alfa');

// Option in Alfa Configuration. Category show also subcategories items Category show also subcategories items
$includeSubcategories = $appSettings->get('include_subcategories', 1);

$lang = $app->getLanguage();
$lang->load('mod_alfa_filters', JPATH_SITE);

if (isset($displayData)) {
    extract($displayData);
}

$alfa_filters ??= null;
$alfa_active_filters ??= [];
$moduleId ??= 0;
// How the option groups behave, read once from the module's settings by the caller; a caller
// that passes none gets the settings' own defaults.
$groupSettings ??= AlfaFiltersHelper::getGroupSettings(new Registry());

if (!$alfa_filters) {
    return;
}

// WHICH SECTIONS, IN WHICH ORDER: decided by the composer that required this template,
// which walks the resolved panel order and hands over one consecutive run of catalogue
// sections at a time. Rendering is driven by that list and never by the shape of
// $alfa_filters, so a merchant's arrangement survives here rather than being re-sorted.
// Absent (a template override calling this directly) -> everything, in natural order.
$afSectionKeys ??= array_merge(
    ['category', 'manufacturer'],
    array_map(
        static fn ($id): string => FilterSections::fieldKey((int) $id),
        array_keys((array) ($alfa_filters['fields'] ?? [])),
    ),
);

// Arrow SVG from overridable template
ob_start();
require ModuleHelper::getLayoutPath('mod_alfa_filters', 'default_arrow');
$arrowSvg = ob_get_clean();

// Recursive category rendering with <ul>/<li> and depth classes
$renderCategories = function (array $categories, array $activeCategories, int $depth = 0) use (
    &$renderCategories,
    $includeSubcategories,
    $arrowSvg,
    $moduleId
) {
    $html = '<ul class="af-list">';
    $index = 0;

    foreach ($categories as $category) {
        $index++;
        $catId = (int) $category->id;
        $inputId = 'af-' . $moduleId . '-cat-' . $catId;
        $checked = in_array($catId, $activeCategories) ? 'checked' : '';
        $hasChildren = !empty($category->children);
        $product_count = $includeSubcategories ? $category->product_count : $category->direct_product_count;

        $liClasses = 'af-item af-item-' . $index . ' depth-' . $depth;
        if ($hasChildren) {
            $liClasses .= ' parent';
        }

        $html .= '<li class="' . $liClasses . '" data-id="' . $catId . '">';
        $html .= '<div class="af-option">';

        $html .= '<input type="checkbox" name="filter[category][]" value="' . $catId . '" '
            . $checked . ' id="' . $inputId . '">';

        $html .= '<label class="af-label" for="' . $inputId . '">';
        $html .= htmlspecialchars($category->name);
        $html .= '</label>';

        if (!empty($product_count)) {
            $html .= '<span class="af-count">(' . $product_count . ')</span>';
        }

        if ($hasChildren) {
            $html .= '<button type="button" class="af-toggle"'
                . ' aria-expanded="false" aria-label="' . Text::_('MOD_ALFAFILTERS_TOGGLE_SUBCATEGORIES') . '">';
            $html .= $arrowSvg;
            $html .= '</button>';
        }

        $html .= '</div>';

        if ($hasChildren) {
            $html .= '<div class="af-children">';
            $html .= $renderCategories($category->children, $activeCategories, $depth + 1);
            $html .= '</div>';
        }

        $html .= '</li>';
    }

    $html .= '</ul>';
    return $html;
};

// Every option of the category tree, at every depth: what the search box looks through.
$countCategories = static function (array $categories) use (&$countCategories): int {
    $count = 0;

    foreach ($categories as $category) {
        $count += 1 + (empty($category->children) ? 0 : $countCategories($category->children));
    }

    return $count;
};

$esc = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

?>

<?php foreach ($afSectionKeys as $afSectionKey): ?>
    <?php
    // ONE GROUP: the category tree, the manufacturers, or one item field's facet. Each kind
    // builds its options here; the group around them (heading, search box, options, the
    // "nothing found" line) is drawn once, below, for all three.
    //
    // An item field's key carries the field id, so the section renders THAT field wherever
    // the merchant put it; the facets are never walked in array order.
    $fieldId = str_starts_with($afSectionKey, FilterSections::FIELD_PREFIX)
        ? (int) substr($afSectionKey, \strlen(FilterSections::FIELD_PREFIX))
        : 0;

    $groupAttr = '';
    $optionsAttr = '';
    $resetButton = '';
    $searchable = true;

    if ($fieldId > 0) {
        $fieldData = (array) ($alfa_filters['fields'][$fieldId] ?? []);
        $fieldValues = $fieldData['values'] ?? [];

        if (empty($fieldValues)) {
            continue;
        }

        $activeValues = array_map('intval', (array) ($alfa_active_filters['fields'][$fieldId] ?? []));
        $renderAs = $fieldData['render_as'] ?? 'checkboxes';
        $groupName = 'field-' . $fieldId;
        $groupLabel = (string) ($fieldData['label'] ?? '');
        $groupClass = 'af-fieldset af-fieldset-field af-render-' . $esc($renderAs);
        $groupAttr = ' data-field-id="' . $fieldId . '"';
        $optionCount = \count($fieldValues);
        // A dropdown carries its own search.
        $searchable = $renderAs !== 'select';

        // ShowOn: the SERVER has already decided visibility and pruned the state; a facet
        // that reaches this loop is one the rule allows. These attributes only let the
        // browser engine re-evaluate live as the shopper ticks, using the very same rule.
        // The rule sits on the group, which it hides; the name sits on the options alone,
        // because the engine reads every input inside it as `<name>.value`, and the
        // group's search box is not one of the field's values.
        $optionsAttr = ' data-showon-name="' . $esc($fieldData['name'] ?? '') . '"'
            . ' data-showon-type="' . $esc($fieldData['type'] ?? '') . '"';

        if (!empty($fieldData['showon'])) {
            $groupAttr .= " data-showon-rule='" . $esc(json_encode($fieldData['showon'])) . "'";

            if (($fieldData['showon_effect'] ?? 'hide') === 'disable') {
                $groupAttr .= ' data-showon-effect="disable"';
            }
        }

        // The control itself lives in its own layout so a template override
        // can restyle one mode without touching the rest of the panel.
        $optionsHtml = LayoutHelper::render(
            'default_field',
            [
                'fieldValues' => $fieldValues,
                'activeValues' => $activeValues,
                'fieldId' => (int) $fieldId,
                'fieldLabel' => $groupLabel,
                'renderAs' => $renderAs,
                'moduleId' => $moduleId,
                'groups' => $fieldData['groups'] ?? [],
                'activeGroups' => (array) ($alfa_active_filters['groups'][$fieldId] ?? []),
                'fieldType' => (string) ($fieldData['type'] ?? ''),
                'groupValues' => (bool) ($fieldData['group_default'] ?? false),
            ],
            JPATH_SITE . '/modules/mod_alfa_filters/tmpl',
        );
    } else {
        $options = $alfa_filters[$afSectionKey] ?? [];

        if (empty($options)) {
            continue;
        }

        $groupName = $afSectionKey;
        $groupLabel = Text::_('MOD_ALFAFILTERS_FILTER_' . strtoupper($afSectionKey));
        $groupClass = 'af-fieldset af-fieldset-' . $afSectionKey;
        $resetButton = '<button type="button" class="af-reset af-reset-' . $afSectionKey . '">'
            . Text::_('MOD_ALFAFILTERS_FILTER_RESET_BTN') . '</button>';

        if ($afSectionKey === 'category') {
            $optionCount = $countCategories($options);
            $optionsHtml = $renderCategories($options, $alfa_active_filters['category'] ?? [], 0);
        } else {
            $optionCount = \count($options);
            ob_start(); ?>
            <ul class="af-list">
            <?php $mIndex = 0;
            foreach ($options as $option): ?>
                <?php
                    $mIndex++;
                $manufacturerId = is_array($option) ? $option['id'] : $option->id;
                $manufacturerName = is_array($option) ? $option['name'] : $option->name;
                $isChecked = in_array($manufacturerId, $alfa_active_filters['manufacturer'] ?? []);
                $inputId = 'af-' . $moduleId . '-mfr-' . (int) $manufacturerId;
                ?>
                <li class="af-item af-item-<?= $mIndex ?> depth-0" data-id="<?= (int) $manufacturerId ?>">
                    <div class="af-option">
                        <input
                            type="checkbox"
                            id="<?= $inputId ?>"
                            name="filter[<?= $afSectionKey ?>][]"
                            value="<?= (int) $manufacturerId ?>"
                            <?= $isChecked ? 'checked' : '' ?>
                        >
                        <label class="af-label" for="<?= $inputId ?>"><?= htmlspecialchars($manufacturerName) ?></label>
                    </div>
                </li>
            <?php endforeach; ?>
            </ul>
            <?php $optionsHtml = ob_get_clean();
        }
    }

    $groupId = 'af-' . $moduleId . '-' . $groupName;
    ?>
    <fieldset class="<?= $groupClass ?>" data-af-group="<?= $esc($groupName) ?>"<?= $groupAttr ?>>
        <?php // The heading is the group's opener: a button inside the legend, so the group
              // keeps its name and the button says whether it is open. filters.js turns it
              // and marks the group shut (data-af-closed); the stylesheet hides the body. ?>
        <div class="af-fieldset-header">
            <legend>
                <button type="button" class="af-group-toggle" aria-expanded="true" aria-controls="<?= $esc($groupId . '-body') ?>">
                    <span class="af-group-name"><?= $esc($groupLabel) ?></span>
                    <?php require ModuleHelper::getLayoutPath('mod_alfa_filters', 'default_group_icon'); ?>
                </button>
            </legend>
            <?= $resetButton ?>
        </div>

        <div class="af-group-body" id="<?= $esc($groupId . '-body') ?>">
            <?php // The search box narrows this group's options as the shopper types (filters.js
                  // marks what matches, the stylesheet hides the rest); it is no filter itself:
                  // it has no name, so it never travels with the form. ?>
            <?php if ($searchable && $groupSettings['search'] > 0 && $optionCount >= $groupSettings['search']): ?>
                <div class="af-group-search">
                    <input type="search" class="af-group-search-input" id="<?= $esc($groupId . '-search') ?>"
                           placeholder="<?= $esc(Text::_('MOD_ALFAFILTERS_GROUP_SEARCH_PLACEHOLDER')) ?>"
                           aria-label="<?= $esc(Text::sprintf('MOD_ALFAFILTERS_GROUP_SEARCH_LABEL', $groupLabel)) ?>"
                           autocomplete="off" spellcheck="false" data-af-search>
                </div>
            <?php endif; ?>

            <div class="af-group-options"<?= $optionsAttr ?>>
                <?= $optionsHtml ?>
            </div>

            <p class="af-group-empty" data-af-search-empty hidden><?= $esc(Text::_('MOD_ALFAFILTERS_GROUP_NOTHING_FOUND')) ?></p>
        </div>
    </fieldset>
<?php endforeach; ?>
