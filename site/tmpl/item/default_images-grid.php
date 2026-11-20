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
 * Each image is the thumbnail, with the stored image beside it in its srcset, so a cell wider than the
 * thumbnail's pixels (times the screen's pixel ratio) is drawn from the stored image. The sizes follow item.css on
 * Cassiopeia: the images take the whole page (less 20 px) up to 651 px and half of it (less 26 px) from 652 px, in the
 * grid's columns with 8 px between them (two columns up to 600 px), each a cell's width or a little more, never less,
 * and capped at the stored image's width (MediaHelper::sizes()).
 * The addresses carry their files' versions (getMediaData()), so each image is drawn by Joomla's own image layout.
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

// A cell's width: the images' room (the share of the screen less the px given) over the grid's columns, less the 8 px
// gaps between them; the share rounded up and the px down, so the width is never less than the cell.
$columns = max(2, (int) $this->imagesGridColumns);
$cell = static function (int $share, int $less, int $across): string {
    return 'calc(' . (ceil(100 * $share / $across) / 100) . 'vw - ' . intdiv($less + 8 * ($across - 1), $across) . 'px)';
};
$cellSizes = '(max-width: 600px) ' . $cell(100, 20, 2) . ', (max-width: 651px) ' . $cell(100, 20, $columns) . ', ' . $cell(50, 26, $columns);
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
            $imgAttribs['sizes'] = MediaHelper::sizes($media, $cellSizes);
        }
        ?>
        <a class="item-image" href="<?php echo $this->escape($media->url); ?>">
            <?php echo LayoutHelper::render('joomla.html.image', ['src' => $media->thumbnail_url, 'alt' => $altOf($media, $index)] + $imgAttribs); ?>
        </a>
    <?php endforeach; ?>
</div>
