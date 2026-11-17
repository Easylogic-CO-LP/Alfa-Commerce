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
 * passed on $this->item (the listing loop sets it per row). Its images are a
 * part of their own, the "card_images" slot (default_card_images.php and its
 * variants), drawn through $this->cardImagesTplFor($item).
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
<?php // The card's global position through the view's call (2.2.0): only on a result set with a handle, or on a
      // reload that carried it; an override keeps the call. ?>
<article class="list-item" data-item-id="<?php echo (int) $item->id; ?>"<?php echo $this->resultAttributes(position: isset($this->itemPosition) ? (int) $this->itemPosition : null); ?>>

    <?php // The card's images, a part of its own (2.2.1): the first image (default_card_images.php) or a variant of it,
          // the slider among them, chosen per item, category, menu item or in the Options. An override of this card
          // that keeps its own image markup keeps showing its first image. ?>
    <?php echo $this->loadTemplate($this->cardImagesTplFor($item)); ?>

    <div class="item-title">
        <a href="<?php echo $this->escape($item->link); ?>"><?php echo $this->escape($itemName); ?></a>
    </div>
    <?php echo Surface::render($item, ItemListSurface::Title); ?>

    <?php
        // A buy form (same as the product page) when the shop sells online. reloadUrl
        // is empty when the item has no interactive fields: variant.js just skips the
        // reload; the add-to-cart still works (JS reads item_id + quantity). In
        // catalogue-only mode (2.2.2, $item->sellsOnline false) a plain block, no
        // form: the fields arrive as read-only lists and there is no add to cart.
        $sellsOnline = (bool) ($item->sellsOnline ?? true);
    ?>
    <?php if ($sellsOnline): ?>
    <form class="list-item-buy-form" data-item-form
          data-action="<?php echo $this->escape($item->reloadUrl); ?>"
          action="<?php echo $this->escape($item->addToCartUrl); ?>" method="post">
        <input type="hidden" name="item_id" value="<?php echo (int) $item->id; ?>">
        <?php echo HTMLHelper::_('form.token'); ?>
    <?php else: ?>
    <div class="list-item-catalogue">
    <?php endif; ?>

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
    <?php echo $sellsOnline ? '</form>' : '</div>'; ?>

</article>
