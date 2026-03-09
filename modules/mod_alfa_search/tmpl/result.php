<?php

use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

// Get data from helper
$products      = $displayData['products'] ?? [];
$categories    = $displayData['categories'] ?? [];
$manufacturers = $displayData['manufacturers'] ?? [];
$params        = $displayData['params'] ?? null;

if (!$params)
{
	return;
}

// Get parameters
$showDescription   = $params->get('show_description', 1);
$descriptionLimit  = $params->get('description_limit', 50);
$showCategories    = $params->get('show_categories', 0);
$showManufacturers = $params->get('show_manufacturers', 0);

// Check if we have any results - layout handles this
$hasResults = !empty($products) || !empty($categories) || !empty($manufacturers);

if (!$hasResults):
	?>
    <div class="search-no-results">
        <div class="search-no-results-icon">🔍</div>
        <p><?php echo Text::_('MOD_ALFA_SEARCH_NO_RESULTS'); ?></p>
    </div>
	<?php
	return;
endif;
?>

    <h3><?php echo Text::_('MOD_ALFA_SEARCH_RESULT_PRODUCTS_HEADING'); ?></h3>
<?php if (empty($products)): ?>
    <div><?php echo Text::_('MOD_ALFA_SEARCH_RESULT_EMPTY'); ?></div>
<?php else: ?>
	<?php foreach ($products as $product): ?>
        <a class="search-product-item"
           href="<?= $product->link; ?>" tabindex="0">
            <div class="search-image-container">
                <img src="https://americanathleticshoe.com/cdn/shop/t/23/assets/placeholder_600x.png?v=113555733946226816651665571258"
                     alt="<?php echo $product->name; ?>">
            </div>
            <div class="search-info-container">
                <div class="search-product-title">
                    <h3><?php echo $product->name; ?></h3>
                </div>
				<?php
				$productDescription = isset($product->short_desc) ?
					AlfaHelper::cleanContent(
						html: $product->short_desc,
						removeTags: true,
						removeScripts: true,
						removeIsolatedPunctuation: false) :
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

<?php if ($showCategories): ?>
    <h3><?php echo Text::_('MOD_ALFA_SEARCH_RESULT_CATEGORIES_HEADING'); ?></h3>
	<?php if (empty($categories)): ?>
        <div><?php echo Text::_('MOD_ALFA_SEARCH_RESULT_EMPTY'); ?></div>
	<?php else: ?>
		<?php foreach ($categories as $category): ?>
            <a class="search-category-item"
               href="<?= $category->link; ?>"
               tabindex="0">
                <div class="search-category-title">
                    <h3><?php echo $category->name; ?></h3>
                </div>

				<?php
				$categoryDescription = isset($category->desc) ?
					AlfaHelper::cleanContent(
						html: $category->desc,
						removeTags: true,
						removeScripts: true,
						removeIsolatedPunctuation: false) :
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
endif;
?>
<?php if ($showManufacturers): ?>
    <h3><?php echo Text::_('MOD_ALFA_SEARCH_RESULT_MANUFACTURERS_HEADING'); ?></h3>
	<?php if (empty($manufacturers)): ?>
        <div><?php echo Text::_('MOD_ALFA_SEARCH_RESULT_EMPTY'); ?></div>
	<?php else: ?>
		<?php foreach ($manufacturers as $manufacturer): //TODO ADD HREF ?>
            <a class="search-manufacturer-item" tabindex="0">
                <div class="search-manufacturer-title">
                    <h3><?php echo $manufacturer->name; ?></h3>
                </div>

				<?php
				$manufacturerDescription = isset($manufacturer->desc) ?
					AlfaHelper::cleanContent(
						html: $manufacturer->desc,
						removeTags: true,
						removeScripts: true,
						removeIsolatedPunctuation: false) :
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
endif;
?>