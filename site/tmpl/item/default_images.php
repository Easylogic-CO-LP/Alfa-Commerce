<?php
    /**
     * @package    Alfa Commerce
     * @author     Agamemnon Fakas <info@easylogic.gr>
     * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
     * @license    GNU General Public License version 3 or later; see LICENSE
     */

    // No direct access
    use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
    use Joomla\CMS\Language\Text;
    use Joomla\CMS\Layout\LayoutHelper;

    defined('_JEXEC') or die;

    // The markup only: the item view loads the gallery's assets for every gallery the page can show (a variant
    // swaps its own gallery in without loading any), Keen Slider where one has two or more images, GLightbox where
    // one has any. One image is the main image in its lightbox link, with no thumbnail strip.
    $medias = array_values($this->item->medias);

    // The name the page shows, with the card's fallback when the current language has none.
    $itemName = trim((string) ($this->item->name ?? ''));

    if ($itemName === '') {
        $itemName = trim((string) ($this->item->sku ?? '')) ?: Text::sprintf('COM_ALFA_ITEM_UNNAMED', (int) $this->item->id);
    }

    // The merchant's own alt when set; otherwise the name and the image's ordinal from 1. The strip repeats it.
    $altOf = static fn (object $media, int $index): string => (string) ($media->alt ?: Text::sprintf('COM_ALFA_ALT_ITEM_IMAGE', $itemName, $index + 1));

    // A slide is the thumbnail, with the stored image beside it in its srcset (2.2.2), so a slide wider than the
    // thumbnail's pixels (times the screen's pixel ratio) is drawn from the stored image; the lightbox opens the stored
    // image. The sizes follow item.css on Cassiopeia: the images take the whole page (less its 20 px of padding) up to
    // 651 px and half of it (less 26 px) from 652 px, never less than the slide (never "auto", which draws an image in
    // a box of 300 x 150 until the CSS sets both its sides), each capped at the stored image's width
    // (MediaHelper::sizes()). The strip's small images stay thumbnails.
    // The addresses carry their files' versions (getMediaData()), so each image is drawn by Joomla's own image layout
    // (joomla.html.image): HTMLHelper::image() loses an address with a query and no fragment.
    $slideSizes = '(max-width: 651px) calc(100vw - 20px), calc(50vw - 26px)';
?>

<div class="main-images navigation-controls">
    <?php foreach ($medias as $index => $media):
        $isFirst = $index === 0;
        $imgAttribs = [
            'width'  => 600,
            'height' => 600,
        ];

        if ($isFirst) {
            $imgAttribs['fetchpriority'] = 'high';
        } else {
            $imgAttribs['loading'] = 'lazy';
        }

        $srcset = MediaHelper::srcset($media);

        if ($srcset !== '') {
            $imgAttribs['srcset'] = $srcset;
            $imgAttribs['sizes'] = MediaHelper::sizes($media, $slideSizes);
        }
        ?>
        <a class="item-image" href="<?= $this->escape($media->url) ?>">
            <?= LayoutHelper::render('joomla.html.image', ['src' => $media->thumbnail_url, 'alt' => $altOf($media, $index)] + $imgAttribs); ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if (\count($medias) > 1) : ?>
<div class="thumbnail-images keen-slider">
    <?php foreach ($medias as $index => $media):
        $isFirst = $index === 0;
        $imgAttribs = [
            'width'  => 100,
            'height' => 100,
            'class'  => 'item-image',
        ];

        if ($isFirst) {
            $imgAttribs['fetchpriority'] = 'high';
        } else {
            $imgAttribs['loading'] = 'lazy';
        }
        ?>

        <?= LayoutHelper::render('joomla.html.image', ['src' => $media->thumbnail_url, 'alt' => $altOf($media, $index)] + $imgAttribs); ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>