<?php
    /**
     * @package    Alfa Commerce
     * @author     Agamemnon Fakas <info@easylogic.gr>
     * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
     * @license    GNU General Public License version 3 or later; see LICENSE
     */

    // No direct access
    defined('_JEXEC') or die;

    use \Joomla\CMS\Factory;
    use \Joomla\CMS\Language\Text;

    $user   = Factory::getApplication()->getIdentity();
    $userId = $user->get('id');

    // Import CSS
    $wa = $this->document->getWebAssetManager();
    $wa->useStyle('com_alfa.manufacturer');

    $manufacturerMedia = !empty($this->item->medias[0]) ? $this->item->medias[0] : null;
?>

<section>
    <div class="manufacturer-container">
        <article>
            <?php if (!empty($manufacturerMedia)): ?>
                <div class="manufacturer-image-wrapper">
                    <img src="<?php echo $this->escape($manufacturerMedia->url); ?>">
                </div>
            <?php endif; ?>
            <div class="manufacturer-info">
                <div class="manufacturer-name">
                    <h3><?php echo $this->escape($this->item->name); ?></h3>
                </div>

                <div class="manufacturer-description">
                    <?php echo $this->item->desc; ?>
                </div>

                <div class="manufacturer-products">
                    <a href="<?php echo $this->item->link; ?>">
                        <?php echo Text::_('COM_ALFA_MANUFACTURER_SHOW_ALL_PRODUCTS'); ?> </a>
                </div>
            </div>
        </article>
    </div>
</section>