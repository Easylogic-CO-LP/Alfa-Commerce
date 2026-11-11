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
    <h2 class="item-list-title item-manufacturers-title"><?php echo Text::_('COM_ALFA_TITLE_MANUFACTURERS'); ?></h2>

    <div class="item-list-inner item-manufacturers-inner">
        <?php foreach ($manufacturers as $manufacturerId => $manufacturer) : ?>

            <?php
            $manufacturerUrl = Route::_('index.php?option=com_alfa&view=manufacturer&id=' . (int) $manufacturerId);
            $firstImage      = !empty($manufacturer['media']) ? reset($manufacturer['media']) : null;
            // The merchant's own alt when set; otherwise empty: the link already says the manufacturer's name as its text.
            $imageAlt        = !empty($firstImage->alt) ? $firstImage->alt : '';
            ?>

            <div class="item-list-entry item-manufacturer">
                <a href="<?php echo $manufacturerUrl; ?>">
                    <div class="item-list-entry-name item-manufacturer-name">
                        <?php echo $this->escape($manufacturer['name']); ?>
                    </div>
                    <?php if ($firstImage) : ?>
                        <div class="item-list-entry-image item-manufacturer-image">
                            <img src="<?php echo $this->escape($firstImage->url); ?>" alt="<?php echo $this->escape($imageAlt); ?>">
                        </div>
                    <?php endif; ?>
                </a>
            </div>

        <?php endforeach; ?>
    </div>
</div>