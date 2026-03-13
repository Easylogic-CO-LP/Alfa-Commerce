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

    $this->document->getWebAssetManager()->useStyle('com_alfa.item');

    $manufacturers = $this->item->manufacturers;

    if (empty($manufacturers)) {
        return;
    }
?>

<div class="item-list item-manufacturers">
    <h2 class="item-list-title item-manufacturers-title"><?php echo Text::_('Manufacturers'); ?></h2>

    <div class="item-list-inner item-manufacturers-inner">
        <?php foreach ($manufacturers as $manufacturerId => $manufacturer) : ?>

            <?php
            $manufacturerUrl = Route::_('index.php?option=com_alfa&view=manufacturer&id=' . (int) $manufacturerId);
            $firstImage      = !empty($manufacturer['media']) ? reset($manufacturer['media']) : null;
            $imageAlt        = !empty($firstImage->alt) ? $firstImage->alt : $manufacturer['name'] . ' logo';
            ?>

            <div class="item-list-entry item-manufacturer">
                <a href="<?php echo $manufacturerUrl; ?>">
                    <div class="item-list-entry-name item-manufacturer-name">
                        <?php echo $manufacturer['name']; ?>
                    </div>
                    <?php if ($firstImage) : ?>
                        <div class="item-list-entry-image item-manufacturer-image">
                            <img src="<?php echo $firstImage->path; ?>" alt="<?php echo $imageAlt; ?>">
                        </div>
                    <?php endif; ?>
                </a>
            </div>

        <?php endforeach; ?>
    </div>
</div>