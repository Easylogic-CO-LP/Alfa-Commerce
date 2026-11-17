<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The card's images (the "card_images" slot of the listing's card, 2.2.1): the product's first image, as the card has
 * always drawn it. Chosen per item, per category, per menu item or in the Options (Items List Item Images); a variant
 * such as default_card_images-slider.php draws more than one.
 *
 * Overridable as html/com_alfa/items/default_card_images.php. The card is on $this->item.
 *
 * @var \Alfa\Component\Alfa\Site\View\Items\HtmlView $this
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemListSurface;
use Alfa\Component\Alfa\Site\Helper\Surface;

$item = $this->item;
?>
<?php if (!empty($item->medias[0]->thumbnail)): ?>
    <div class="item-image">
        <?php
            // The same destination as the title below it. Kept clickable for the
            // mouse, but taken out of the accessibility tree and the tab order:
            // one card should offer one link, not the same one twice. The image
            // is decorative for the same reason: the title states the name.
        ?>
        <a href="<?php echo $this->escape($item->link); ?>" tabindex="-1" aria-hidden="true">
            <img src="<?php echo $this->escape($item->medias[0]->thumbnail_url); ?>" alt="" />
        </a>
        <?php echo Surface::render($item, ItemListSurface::Image); ?>
    </div>
<?php endif; ?>
