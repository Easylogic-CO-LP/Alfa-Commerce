<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Catalog card: ONE <article class="list-item">. Rendered by the listing loop
 * AND by the single-card reload (view=items&layout=item), so a card behaves
 * exactly like the inner product: a variant item is wrapped in a JS-driven form
 * (data-item-form) carrying the same combination-aware selectors the
 * product page uses ($field->surfaces); changing a variant re-fetches the
 * whole [data-item-id], the price block keeps its own [data-item-prices] for
 * option-only updates. Informational specs render read-only inside the same
 * form, after the selectors, as on the product page.
 *
 * Overridable as html/com_alfa/items/default_item.php. The current card is
 * passed on $this->item (the listing loop sets it per row).
 *
 * @var \Alfa\Component\Alfa\Site\View\Items\HtmlView $this
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemListSurface;
use Alfa\Component\Alfa\Site\Helper\Layout;
use Alfa\Component\Alfa\Site\Helper\Surface;
use Joomla\CMS\Language\Text;

$item          = $this->item;
$priceSettings = $this->priceSettings;

// A card's link must always say what it leads to. A name can be missing (an
// item never translated into the active language has no row to read it from),
// and an empty <a></a> is a link nobody using a screen reader can identify.
// The SKU is the next best identifier; failing that, the id at least names it.
$itemName = trim((string) ($item->name ?? ''));

if ($itemName === '') {
    $itemName = trim((string) ($item->sku ?? '')) ?: Text::sprintf('COM_ALFA_ITEM_UNNAMED', (int) $item->id);
}
?>
<article class="list-item" data-item-id="<?php echo (int) $item->id; ?>"<?php echo isset($this->itemPosition) ? ' data-position="' . (int) $this->itemPosition . '"' : ''; ?>>

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

    <div class="item-title">
        <a href="<?php echo $this->escape($item->link); ?>"><?php echo $this->escape($itemName); ?></a>
    </div>
    <?php echo Surface::render($item, ItemListSurface::Title); ?>

    <?php
        // Always a buy form (same as the product page). reloadUrl is empty when the
        // item has no interactive fields: variant.js just skips the reload; the
        // add-to-cart still works (JS reads item_id + quantity). One path, no fork.
    ?>
    <form class="list-item-buy-form" data-item-form
          data-action="<?php echo $this->escape($item->reloadUrl); ?>"
          action="<?php echo $this->escape($item->addToCartUrl); ?>" method="post">
        <input type="hidden" name="item_id" value="<?php echo (int) $item->id; ?>">
        <?php echo HTMLHelper::_('form.token'); ?>

        <div class="item-fields-surfaces">
            <?php echo Surface::render($item, ItemListSurface::BuyForm); ?>
        </div>

        <?php // Read-only, no inputs. Inside the form so ShowOn re-evaluates it with the selectors. ?>
        <?php if (Surface::has($item, ItemListSurface::Specifications)): ?>
            <div class="item-specifications">
                <?php echo Surface::render($item, ItemListSurface::Specifications); ?>
            </div>
        <?php endif; ?>

        <?php echo Layout::render('item.price', ['item' => $item, 'settings' => $priceSettings]); ?>
        <?php echo Layout::render('item.stock_info', ['item' => $item]); ?>
        <?php echo Layout::render('cart.wrapper', $item); ?>
    </form>

</article>
