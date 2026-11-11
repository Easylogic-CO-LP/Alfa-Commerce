<?php
    /**
     * @package    Alfa Commerce
     * @author     Agamemnon Fakas <info@easylogic.gr>
     * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
     * @license    GNU General Public License version 3 or later; see LICENSE
     */

    // No direct access
    use Joomla\CMS\HTML\HTMLHelper;
    use Joomla\CMS\Language\Text;

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
        ?>
        <a class="item-image" href="<?= $media->url ?>">
            <?= HTMLHelper::_('image', $media->thumbnail_url, $altOf($media, $index), $imgAttribs); ?>
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

        <?= HTMLHelper::_('image', $media->thumbnail_url, $altOf($media, $index), $imgAttribs); ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>