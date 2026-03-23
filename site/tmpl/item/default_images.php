<?php
    /**
     * @version    1.0.1
     * @package    Com_Alfa
     * @author     Agamemnon Fakas <info@easylogic.gr>
     * @copyright  2024 Easylogic CO LP
     * @license    GNU General Public License version 2 or later; see LICENSE.txt
     */

    // No direct access
    use Joomla\CMS\HTML\HTMLHelper;

    defined('_JEXEC') or die;

    $wa = $this->document->getWebAssetManager();
    $wa->useStyle('com_alfa.glightbox')
        ->useStyle('com_alfa.keen-slider')
        ->useScript('com_alfa.glightbox')
        ->useScript('com_alfa.keen-slider');

    $medias = $this->item->medias;
?>

<div class="main-images navigation-controls">
    <?php foreach ($medias as $index => $media):
        $isFirst = $index === 0;
        $imgAttribs = [
            'width'  => 600,
            'height' => 600,
        ];

        $imgAlt = $media->alt ?: ($this->item->name . ' ' . $index );

        if ($isFirst) {
            $imgAttribs['fetchpriority'] = 'high';
        } else {
            $imgAttribs['loading'] = 'lazy';
        }
        ?>
        <a class="item-image" href="<?= $media->path ?>">
            <?= HTMLHelper::_('image', $media->thumbnail, $imgAlt, $imgAttribs); ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="thumbnail-images keen-slider d-flex flex-row mt-3">
    <?php foreach ($medias as $index => $media):
        $isFirst = $index === 0;
        $imgAttribs = [
            'width'  => 100,
            'height' => 100,
            'class'  => 'item-image',
        ];

        $imgAlt = $media->alt ?: ($this->item->name . ' ' . $index );

        if ($isFirst) {
            $imgAttribs['fetchpriority'] = 'high';
        } else {
            $imgAttribs['loading'] = 'lazy';
        }
        ?>

        <?= HTMLHelper::_('image', $media->thumbnail, $imgAlt, $imgAttribs); ?>
    <?php endforeach; ?>
</div>