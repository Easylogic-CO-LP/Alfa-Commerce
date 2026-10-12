<?php

\defined('_JEXEC') or die;

use Alfa\Module\AlfaFilters\Site\Section\FilterSections;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

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

?>

<?php foreach ($afSectionKeys as $afSectionKey): ?>
    <?php
    // One item field's facet. The key carries the field id, so the section renders THAT
    // field wherever the merchant put it; the facets are never walked in array order.
    $fieldId = str_starts_with($afSectionKey, FilterSections::FIELD_PREFIX)
        ? (int) substr($afSectionKey, \strlen(FilterSections::FIELD_PREFIX))
        : 0;
    ?>

    <?php if ($fieldId > 0): ?>
        <?php
        $fieldData = (array) ($alfa_filters['fields'][$fieldId] ?? []);
        $fieldValues = $fieldData['values'] ?? [];

        if (empty($fieldValues)) {
            continue;
        }

        $activeValues = array_map('intval', (array) ($alfa_active_filters['fields'][$fieldId] ?? []));
        $renderAs = $fieldData['render_as'] ?? 'checkboxes';

        // ShowOn: the SERVER has already decided visibility and pruned the state;
        // a facet that reaches this loop is one the rule allows. These attributes
        // only let the browser engine re-evaluate live as the shopper ticks, using
        // the very same rule. data-showon-name doubles as the state source: the
        // reader collects this fieldset's inputs as `<name>.value`.
        $showonAttr = ' data-showon-name="' . htmlspecialchars((string) ($fieldData['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '"'
            . ' data-showon-type="' . htmlspecialchars((string) ($fieldData['type'] ?? ''), ENT_QUOTES, 'UTF-8') . '"';

        if (!empty($fieldData['showon'])) {
            $showonAttr .= " data-showon-rule='"
                . htmlspecialchars((string) json_encode($fieldData['showon']), ENT_QUOTES, 'UTF-8') . "'";

            if (($fieldData['showon_effect'] ?? 'hide') === 'disable') {
                $showonAttr .= ' data-showon-effect="disable"';
            }
        }
        ?>
        <fieldset class="af-fieldset af-fieldset-field af-render-<?= htmlspecialchars($renderAs) ?>" data-field-id="<?= (int) $fieldId ?>"<?= $showonAttr ?>>
            <div class="af-fieldset-header">
                <legend><?= htmlspecialchars($fieldData['label'] ?? '') ?></legend>
            </div>
            <?php
            // The control itself lives in its own layout so a template override
            // can restyle one mode without touching the rest of the panel.
            echo LayoutHelper::render(
                'default_field',
                [
                    'fieldValues' => $fieldValues,
                    'activeValues' => $activeValues,
                    'fieldId' => (int) $fieldId,
                    'fieldLabel' => (string) ($fieldData['label'] ?? ''),
                    'renderAs' => $renderAs,
                    'moduleId' => $moduleId,
                    'groups' => $fieldData['groups'] ?? [],
                    'activeGroups' => (array) ($alfa_active_filters['groups'][$fieldId] ?? []),
                    'fieldType' => (string) ($fieldData['type'] ?? ''),
                    'groupValues' => (bool) ($fieldData['group_default'] ?? false),
                ],
                JPATH_SITE . '/modules/mod_alfa_filters/tmpl',
            );
        ?>
        </fieldset>
        <?php continue; ?>
    <?php endif; ?>

    <?php $options = $alfa_filters[$afSectionKey] ?? []; ?>

    <?php if (empty($options)) {
        continue;
    } ?>

    <fieldset class="af-fieldset af-fieldset-<?= $afSectionKey ?>">
        <div class="af-fieldset-header">
            <legend><?= Text::_('MOD_ALFAFILTERS_FILTER_' . strtoupper($afSectionKey)) ?></legend>
            <button type="button" class="af-reset af-reset-<?= $afSectionKey ?>">
                <?= Text::_('MOD_ALFAFILTERS_FILTER_RESET_BTN') ?>
            </button>
        </div>

        <?php if ($afSectionKey === 'category'): ?>
            <?= $renderCategories($options, $alfa_active_filters['category'] ?? [], 0) ?>

        <?php elseif ($afSectionKey === 'manufacturer'): ?>
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

        <?php endif; ?>
    </fieldset>
<?php endforeach; ?>
