<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
// No direct access
defined('_JEXEC') or die;

use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Layout\LayoutHelper;

// Import CSS
$wa = $this->document->getWebAssetManager();
$wa->useStyle('com_alfa.list');
?>

<?php echo LayoutHelper::render('filter_form', ['view' => $this]); ?>

<section>
    <div class="manufacturer-list list-container">
		<?php foreach ($this->items as $item) : ?>
            <article>
                <div class="manufacturer-item list-item">
                    <a href="<?php echo $item->link; ?>">
                        <?php if (!empty($item->medias[0])): ?>
                            <img src="<?= $item->medias[0]->url ?>">
                        <?php endif; ?>
                    </a>

                    <div class="manufacturer-title item-title">
                        <h3><?php echo $this->escape($item->name); ?></h3>
                    </div>

                    <div class="manufacturer-description">
                        <?php echo $item->desc; // editor (HTML) field — output raw, not escaped ?>
                    </div>
                    <div class="manufacturer-products">
                        <a href="<?php echo $item->link; ?>">
                            <?php echo Text::_('COM_ALFA_MANUFACTURER_SHOW_ALL_PRODUCTS'); ?> </a>
                    </div>

                    <div class="manufacturer-url">
                        <a href="<?php echo $item->details_link; ?>">
                            <?php echo Text::_('COM_ALFA_MANUFACTURER_DETAILS'); ?> </a>
                    </div>
                </div>
            </article>
		<?php endforeach; ?>
    </div>
</section>
