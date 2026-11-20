<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The card's images as a slider (a variant of the "card_images" slot, 2.2.1): the product's first images, as many as
 * "Images in the Card Slider" says (the item's, else its category's, else the menu item's, else the Options'; All for
 * every image), side by side in a track that scrolls sideways and stops on each image.
 *
 * The sliding is the browser's own (list.css): a swipe or a sideways scroll moves the track, and a swipe never opens
 * the product, while a click on an image does, as on the default card. The arrows and the dots are drawn here, as
 * buttons, and card-slider.js only makes them move the track and follow it; nothing is drawn by the script. The arrows
 * are in the tab order, the dots are not (the arrows reach every image). A product with one image is drawn as the
 * default card draws it, with no controls.
 *
 * Each image is the generated thumbnail, never the stored image, at its file's versioned address. The first image
 * loads as the default card's image does at the card's place on the page ($this->cardImageLoading(): the first four
 * cards at once, the others lazily), and the slider's other images lazily.
 *
 * Overridable as html/com_alfa/items/default_card_images-slider.php. The card is on $this->item; the images to show
 * come from $this->cardSlides($this->item).
 *
 * @var \Alfa\Component\Alfa\Site\View\Items\HtmlView $this
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemListSurface;
use Alfa\Component\Alfa\Site\Helper\Surface;
use Joomla\CMS\Language\Text;

$item = $this->item;
$slides = $this->cardSlides($item);
$count = \count($slides);
?>
<?php if ($count === 1): ?>
    <div class="item-image">
        <a href="<?php echo $this->escape($item->link); ?>" tabindex="-1" aria-hidden="true">
            <img src="<?php echo $this->escape($slides[0]->thumbnail_url); ?>" alt=""<?php echo $this->cardImageLoading(); ?> />
        </a>
        <?php echo Surface::render($item, ItemListSurface::Image); ?>
    </div>
<?php elseif ($count > 1): ?>
    <div class="item-image card-slider" data-card-slider>
        <?php
            // Every image leads to the product, as the title does, and stays out of the accessibility tree and the
            // tab order for the same reason as the default card's image. The track takes no focus of its own: the
            // arrows move it.
        ?>
        <div class="card-slider-track" data-card-slider-track tabindex="-1">
            <?php foreach ($slides as $index => $slide): ?>
                <a class="card-slider-slide" href="<?php echo $this->escape($item->link); ?>" tabindex="-1" aria-hidden="true" data-card-slider-slide>
                    <img src="<?php echo $this->escape($slide->thumbnail_url); ?>" alt=""<?php echo $index > 0 ? ' loading="lazy"' : $this->cardImageLoading(); ?> />
                </a>
            <?php endforeach; ?>
        </div>

        <button type="button" class="card-slider-arrow card-slider-prev" data-action="card-slider-prev"
                aria-label="<?php echo $this->escape(Text::_('COM_ALFA_CARD_IMAGES_PREVIOUS')); ?>" aria-disabled="true">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 18l-6-6 6-6"/></svg>
        </button>
        <button type="button" class="card-slider-arrow card-slider-next" data-action="card-slider-next"
                aria-label="<?php echo $this->escape(Text::_('COM_ALFA_CARD_IMAGES_NEXT')); ?>" aria-disabled="false">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 6l6 6-6 6"/></svg>
        </button>

        <div class="card-slider-dots">
            <?php for ($index = 0; $index < $count; $index++): ?>
                <button type="button" class="card-slider-dot" data-action="card-slider-dot" data-index="<?php echo $index; ?>" tabindex="-1"
                        aria-label="<?php echo $this->escape(Text::sprintf('COM_ALFA_CARD_IMAGES_SHOW', $index + 1, $count)); ?>"<?php echo $index === 0 ? ' aria-current="true"' : ''; ?>></button>
            <?php endfor; ?>
        </div>

        <?php echo Surface::render($item, ItemListSurface::Image); ?>
    </div>
<?php endif; ?>
