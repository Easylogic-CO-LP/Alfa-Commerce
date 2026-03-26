<?php
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Helper\ModuleHelper;

$app = Factory::getApplication();
$appSettings = ComponentHelper::getParams('com_alfa');

// Option in Alfa Configuration. Category show also subcategories items Category show also subcategories items 
$includeSubcategories = $appSettings->get('include_subcategories', 1);

$lang = $app->getLanguage();
$lang->load('mod_alfa_filters', JPATH_SITE);

if (isset($displayData)) extract($displayData);

$alfa_filters = $alfa_filters ?? null;
$alfa_active_filters = $alfa_active_filters ?? [];
$moduleId = $moduleId ?? 0;

if (!$alfa_filters) return;

// Arrow SVG from overridable template
ob_start();
require ModuleHelper::getLayoutPath('mod_alfa_filters', 'default_arrow');
$arrowSvg = ob_get_clean();

// Recursive category rendering with <ul>/<li> and depth classes
$renderCategories = function(array $categories, array $activeCategories, int $depth = 0) use (&$renderCategories,
    $includeSubcategories, $arrowSvg, $moduleId) {

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
		if ($hasChildren) $liClasses .= ' parent';

		$html .= '<li class="' . $liClasses . '" data-id="' . $catId . '">';
		$html .= '<div class="af-option">';

		$html .= '<input type="checkbox" name="filter[category][]" value="' . $catId . '" '
			. $checked . ' id="' . $inputId . '">';

		$html .= '<label class="af-label" for="' . $inputId . '">';
		$html .= htmlspecialchars($category->name);
		$html .= '</label>';

		if(!empty($product_count))
		{
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

<?php foreach ($alfa_filters as $name => $options): ?>
    <?php if (empty($options)) continue; ?>
    <fieldset class="af-fieldset af-fieldset-<?= $name ?>">
        <div class="af-fieldset-header">
            <legend><?= Text::_('MOD_ALFAFILTERS_FILTER_' . strtoupper($name)) ?></legend>
            <button type="button" class="af-reset af-reset-<?= $name ?>">
                <?= Text::_('MOD_ALFAFILTERS_FILTER_RESET_BTN') ?>
            </button>
        </div>

        <?php if ($name === 'category'): ?>
            <?= $renderCategories($options, $alfa_active_filters['category'] ?? [], 0) ?>

        <?php elseif ($name === 'manufacturer'): ?>
            <ul class="af-list">
            <?php $mIndex = 0; foreach ($options as $option): ?>
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
                            name="filter[<?= $name ?>][]"
                            value="<?= (int)$manufacturerId ?>"
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
