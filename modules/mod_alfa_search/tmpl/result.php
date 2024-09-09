<?php

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

// $displayData array is passed through the helper file on the render function
$products = $displayData['products'] ?? null;  //products data
$categories = $displayData['categories'] ?? null;  //categories data
$manufacturers = $displayData['manufacturers'] ?? null;  //manufacturers data

$params = $displayData['params'] ?? null;  //module params

if (!$params) return;

$showDescription = $params->get('show_description', 1);
$descriptionLimit = $params->get('description_limit', 50);
$showCategories = $params->get('show_categories', 0);
$showManufacturers = $params->get('show_manufacturers', 0);

?>

    <h3><?php echo Text::_('MOD_ALFA_SEARCH_RESULT_PRODUCTS_HEADING'); ?></h3>
<?php if (empty($products)): ?>
    <div><?php echo Text::_('MOD_ALFA_SEARCH_RESULT_EMPTY'); ?></div>
<?php else: ?>
    <?php foreach ($products as $product): ?>
        <a class="search-product-item" href="<?php echo Route::_('index.php?option=com_alfa&view=item&id=' . (int)$product->id); ?>" tabindex="0">
            <div class="search-image-container">
                <img src="https://americanathleticshoe.com/cdn/shop/t/23/assets/placeholder_600x.png?v=113555733946226816651665571258" alt="<?php echo $product->name; ?>">
            </div>
            <div class="search-info-container">
                <div class="search-product-title">
                    <h3><?php echo $product->name; ?></h3>
                </div>
                <?php if ($showDescription): ?>
                    <div class="search-product-description">
                        <?php echo mb_strimwidth($product->short_desc, 0, $descriptionLimit, '...'); ?>
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
            <a class="search-category-item" href="<?php echo Route::_('index.php?option=com_alfa&view=items&filter[category_id]=' . (int)$category->id); ?>" tabindex="0">
                <div class="search-category-title">
                    <!-- TODO: prosthiki filtrou sto forms/filter_items.xml kai epeita sto model / project task sto github  -->
                    <h3><?php echo $category->name; ?></h3>
                </div>

                <?php if ($showDescription): ?>
                    <div class="search-category-description">
                        <?php echo htmlspecialchars(mb_strimwidth($category->short_desc, 0, $descriptionLimit, '...')); ?>
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
        <?php foreach ($manufacturers as $manufacturer): ?>
            <a class="search-manufacturer-item" tabindex="0">
                <div class="search-manufacturer-title">
                    <!-- TODO: prosthiki filtrou sto forms/filter_items.xml kai epeita sto model / project task sto github  -->
                    <h3><?php echo $manufacturer->name; ?></h3>
                </div>

                <?php if ($showDescription): ?>
                    <div class="search-manufacturer-description">
                        <?php echo htmlspecialchars(mb_strimwidth($manufacturer->short_desc, 0, $descriptionLimit, '...')); ?>
                    </div>
                <?php endif; ?>

            </a>
        <?php
        endforeach;
    endif;
endif;
?>