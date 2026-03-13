<?php
    /**
     * @package    Alfa Commerce
     * @author     Agamemnon Fakas <info@easylogic.gr>
     * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
     * @license    GNU General Public License version 3 or later; see LICENSE
     */

    defined('_JEXEC') or die;

    use Joomla\CMS\Router\Route;
    use Joomla\CMS\Language\Text;

    $categories = $this->item->categories;

    if (empty($categories)) {
        return;
    }
?>

<div class="item-list item-categories">
    <h2 class="item-list-title item-categories-title"><?php echo Text::_('Categories'); ?></h2>

    <div class="item-list-inner item-categories-inner">
        <?php foreach ($categories as $categoryId => $category) : ?>

            <?php
            $categoryUrl = Route::_('index.php?option=com_alfa&view=items&category_id=' . (int) $categoryId);
            $firstImage  = !empty($category['media']) ? reset($category['media']) : null;
            $imageAlt    = !empty($firstImage->alt) ? $firstImage->alt : $category['name'] . ' logo';
            ?>

            <div class="item-list-entry item-category">
                <a href="<?php echo $categoryUrl; ?>">
                    <div class="item-list-entry-name item-category-name">
                        <?php echo $category['name']; ?>
                    </div>
                    <?php if ($firstImage) : ?>
                        <div class="item-list-entry-image item-category-image">
                            <img src="<?php echo $firstImage->path; ?>" alt="<?php echo $imageAlt; ?>">
                        </div>
                    <?php endif; ?>
                </a>
            </div>

        <?php endforeach; ?>
    </div>
</div>