<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
// No direct access
defined('_JEXEC') or die;

use \Joomla\CMS\Uri\Uri;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Layout\LayoutHelper;

// Import CSS
$wa = $this->document->getWebAssetManager();
$wa->useStyle('com_alfa.list');
?>

<form action="<?php echo htmlspecialchars(Uri::getInstance()->toString()); ?>" method="post"
      name="adminForm" id="adminForm">
    <?php echo LayoutHelper::render('joomla.searchtools.default', array('view' => $this)); ?>
</form>

<section>
    <div class="manufacturer-list list-container">
		<?php foreach ($this->items as $item) : ?>
            <article>
                <div class="manufacturer-item list-item">
                    <a href="<?php echo $item->link; ?>">
                        <?php if (!empty($item->medias[0])): ?>
                            <img src=<?= $item->medias[0]->path ?>>
                        <?php endif; ?>
                    </a>

                    <div class="manufacturer-title item-title">
                        <h3><?php echo $this->escape($item->name); ?></h3>
                    </div>

                    <div class="manufacturer-description">
                        <?php echo $this->escape($item->desc); ?>
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
