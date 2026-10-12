<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

defined('_JEXEC') or die;

// Get data from helper
$products = $displayData['products'] ?? [];
$categories = $displayData['categories'] ?? [];
$manufacturers = $displayData['manufacturers'] ?? [];
$suggestions = $displayData['suggestions'] ?? [];
$params = $displayData['params'] ?? null;

if (!$params) {
    return;
}

// Get parameters
$showDescription = $params->get('show_description', 1);
$descriptionLimit = $params->get('description_limit', 50);
$showCategories = $params->get('show_categories', 0);
$showManufacturers = $params->get('show_manufacturers', 0);

// Query completions from the active engine (the greeklish bridge). Rendered ABOVE
// the results and BEFORE the no-results guard, so a query that finds nothing directly
// can still offer the canonical word to search for. Each chip carries its own text;
// the module's JS puts it in the box and re-searches. With JS off the chips simply do
// not appear; the search form's normal submit is the untouched fallback.
if (!empty($suggestions)):
    ?>
    <div class="search-suggestions" role="listbox" aria-label="<?php echo htmlspecialchars(Text::_('MOD_ALFA_SEARCH_SUGGESTIONS_HEADING'), ENT_QUOTES, 'UTF-8'); ?>">
        <?php foreach ($suggestions as $suggestion): ?>
            <button type="button" class="search-suggestion" role="option"
                    data-suggestion="<?php echo htmlspecialchars($suggestion, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($suggestion, ENT_QUOTES, 'UTF-8'); ?>
            </button>
        <?php endforeach; ?>
    </div>
	<?php
endif;

// Check if we have any results - layout handles this
$hasResults = !empty($products) || !empty($categories) || !empty($manufacturers);

if (!$hasResults):
    if (empty($suggestions)):
        ?>
        <div class="search-no-results">
            <div class="search-no-results-icon">🔍</div>
            <p><?php echo Text::_('MOD_ALFA_SEARCH_NO_RESULTS'); ?></p>
        </div>
		<?php
    endif;
    return;
endif;
?>

<?php if (!empty($products)): ?>
    <h3><?php echo Text::_('MOD_ALFA_SEARCH_RESULT_PRODUCTS_HEADING'); ?></h3>
	<?php foreach ($products as $product): ?>
        <a class="search-product-item"
           href="<?php echo htmlspecialchars($product->link, ENT_QUOTES, 'UTF-8'); ?>" tabindex="0">
            <div class="search-image-container">
                <img src="<?php echo Uri::root(true); ?>/media/com_alfa/images/no-image.webp"
                     alt="<?php echo htmlspecialchars($product->name, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="search-info-container">
                <div class="search-product-title">
                    <h3><?php echo htmlspecialchars($product->name, ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
				<?php
                $productDescription = isset($product->short_desc) ?
                    AlfaHelper::cleanContent(
                        html: $product->short_desc,
                        removeTags: true,
                        removeScripts: true,
                        removeIsolatedPunctuation: false,
                    ) :
                    '';
	    if ($showDescription && $productDescription !== ''): ?>
                    <div class="search-product-description">
						<?php echo mb_strimwidth($productDescription, 0, $descriptionLimit, '...'); ?>
                    </div>
				<?php endif; ?>
            </div>
        </a>
	<?php
	endforeach;
endif;
?>

<?php if ($showCategories && !empty($categories)): ?>
    <h3><?php echo Text::_('MOD_ALFA_SEARCH_RESULT_CATEGORIES_HEADING'); ?></h3>
		<?php foreach ($categories as $category): ?>
            <a class="search-category-item"
               href="<?php echo htmlspecialchars($category->link, ENT_QUOTES, 'UTF-8'); ?>"
               tabindex="0">
                <div class="search-category-title">
                    <h3><?php echo htmlspecialchars($category->name, ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>

				<?php
                $categoryDescription = isset($category->desc) ?
                    AlfaHelper::cleanContent(
                        html: $category->desc,
                        removeTags: true,
                        removeScripts: true,
                        removeIsolatedPunctuation: false,
                    ) :
                    '';
		    if ($showDescription && $categoryDescription !== ''): ?>
                    <div class="search-category-description">
						<?php echo mb_strimwidth($categoryDescription, 0, $descriptionLimit, '...'); ?>
                    </div>
				<?php endif; ?>

            </a>
		<?php
		endforeach;
endif;
?>
<?php if ($showManufacturers && !empty($manufacturers)): ?>
    <h3><?php echo Text::_('MOD_ALFA_SEARCH_RESULT_MANUFACTURERS_HEADING'); ?></h3>
		<?php foreach ($manufacturers as $manufacturer): //TODO ADD HREF?>
            <a class="search-manufacturer-item" tabindex="0">
                <div class="search-manufacturer-title">
                    <h3><?php echo htmlspecialchars($manufacturer->name, ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>

				<?php
                $manufacturerDescription = isset($manufacturer->desc) ?
                    AlfaHelper::cleanContent(
                        html: $manufacturer->desc,
                        removeTags: true,
                        removeScripts: true,
                        removeIsolatedPunctuation: false,
                    ) :
                    '';

		    if ($showDescription && $manufacturerDescription !== ''): ?>
                    <div class="search-manufacturer-description">
						<?php echo mb_strimwidth($manufacturerDescription, 0, $descriptionLimit, '...'); ?>
                    </div>
				<?php endif; ?>

            </a>
		<?php
		endforeach;
endif;
?>