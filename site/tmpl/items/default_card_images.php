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
 * The image is the thumbnail, with the stored image beside it in its srcset (MediaHelper::srcset(), 2.2.2), so
 * a card wider than the thumbnail's pixels (times the screen's pixel ratio) is drawn from the stored image and the
 * thumbnail is never stretched past its pixels. Its sizes follow list.css's grid (cards of at least 300 px, a gap of
 * 1rem; at most one card a row up to 640 px, two up to 960 px, three up to 1280 px); "auto" first gives a browser that
 * knows it the card's own width, since the image loads lazily. An override that changes the grid gives its own sizes.
 * The addresses are the files' versioned ones (MediaHelper::displayUrl()), so a file written again is never drawn from
 * the browser's cache.
 *
 * Overridable as html/com_alfa/items/default_card_images.php. The card is on $this->item.
 *
 * @var \Alfa\Component\Alfa\Site\View\Items\HtmlView $this
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemListSurface;
use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Site\Helper\Surface;

$item = $this->item;
$srcset = empty($item->medias[0]->thumbnail) ? '' : MediaHelper::srcset($item->medias[0]);
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
            <img src="<?php echo $this->escape(MediaHelper::displayUrl($item->medias[0]->thumbnail_url)); ?>" alt=""<?php if ($srcset !== '') : ?>
                 srcset="<?php echo $this->escape($srcset); ?>"
                 sizes="auto, (max-width: 640px) 100vw, (max-width: 960px) 50vw, (max-width: 1280px) 34vw, 25vw"<?php endif; ?>
                 loading="lazy" />
        </a>
        <?php echo Surface::render($item, ItemListSurface::Image); ?>
    </div>
<?php endif; ?>
