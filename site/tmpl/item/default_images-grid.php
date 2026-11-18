<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The product's images as a grid (a variant of the "images" slot, 2.2.1): every image at once, in as many columns as
 * "Grid Columns" says (the item's, else its default category's, else the menu item's or the Options'), each opening the
 * gallery's lightbox; on a phone at most two columns. A variant with a gallery of its own swaps its grid in, as it
 * swaps the slider (item.js binds the lightbox again).
 *
 * The grid is CSS alone (item.css, the modifier class of its columns); it is never the slider's .main-images.
 *
 * Each image is the thumbnail, with the stored image beside it in its srcset (2.2.2), so a cell wider than the
 * thumbnail's pixels (times the screen's pixel ratio) is drawn from the stored image. The sizes follow item.css: the
 * images take the whole page below 640 px and half of it above, in the grid's columns (at most two on a phone). The
 * addresses carry their files' versions (getMediaData()), so each image is drawn by Joomla's own image layout.
 *
 * Overridable as html/com_alfa/item/default_images-grid.php.
 *
 * @var \Alfa\Component\Alfa\Site\View\Item\HtmlView $this
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

// The markup only: the item view loads the lightbox for every gallery the page can show.
$medias = array_values($this->item->medias);

// The name the page shows, with the card's fallback when the current language has none.
$itemName = trim((string) ($this->item->name ?? ''));

if ($itemName === '') {
    $itemName = trim((string) ($this->item->sku ?? '')) ?: Text::sprintf('COM_ALFA_ITEM_UNNAMED', (int) $this->item->id);
}

// The merchant's own alt when set; otherwise the name and the image's ordinal from 1.
$altOf = static fn (object $media, int $index): string => (string) ($media->alt ?: Text::sprintf('COM_ALFA_ALT_ITEM_IMAGE', $itemName, $index + 1));

// A cell's width: the images' share of the page over the grid's columns (two on a phone, item.css).
$columns = max(2, (int) $this->imagesGridColumns);
$cellSizes = 'auto, (max-width: 600px) 50vw, (max-width: 640px) ' . round(100 / $columns, 2) . 'vw, ' . round(50 / $columns, 2) . 'vw';
?>

<div class="item-images-grid item-images-grid--cols-<?php echo (int) $this->imagesGridColumns; ?>" data-item-gallery>
    <?php foreach ($medias as $index => $media):
        $imgAttribs = [
            'width'  => 600,
            'height' => 600,
        ];

        if ($index === 0) {
            $imgAttribs['fetchpriority'] = 'high';
        } else {
            $imgAttribs['loading'] = 'lazy';
        }

        $srcset = MediaHelper::srcset($media);

        if ($srcset !== '') {
            $imgAttribs['srcset'] = $srcset;
            $imgAttribs['sizes'] = $cellSizes;
        }
        ?>
        <a class="item-image" href="<?php echo $this->escape($media->url); ?>">
            <?php echo LayoutHelper::render('joomla.html.image', ['src' => $media->thumbnail_url, 'alt' => $altOf($media, $index)] + $imgAttribs); ?>
        </a>
    <?php endforeach; ?>
</div>
