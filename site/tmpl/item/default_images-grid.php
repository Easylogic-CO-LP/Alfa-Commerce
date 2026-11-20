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
 * Each image is the generated thumbnail, never the stored image, which only the lightbox opens when the shopper opens
 * it. The addresses carry their files' versions (getMediaData()), so each image is drawn by Joomla's own image layout.
 *
 * Overridable as html/com_alfa/item/default_images-grid.php.
 *
 * @var \Alfa\Component\Alfa\Site\View\Item\HtmlView $this
 */

defined('_JEXEC') or die;

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
        ?>
        <a class="item-image" href="<?php echo $this->escape($media->url); ?>">
            <?php echo LayoutHelper::render('joomla.html.image', ['src' => $media->thumbnail_url, 'alt' => $altOf($media, $index)] + $imgAttribs); ?>
        </a>
    <?php endforeach; ?>
</div>
