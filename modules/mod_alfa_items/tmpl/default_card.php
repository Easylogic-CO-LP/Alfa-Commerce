<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * One product card: its image, its name, its specifications and its buy form. The card is the module's (
 * its markup here, its styles in items.css, its look from the module's Style tab and the tab),
 * so an override of this file changes the module's cards and nothing in the shop.
 * What it shows is Alfa's: an item Alfa's listing model decorated, and the parts Alfa
 * draws itself - the field plugins' surfaces (variant and option choices, specifications, badges), the
 * price, the stock line and add to cart - so the card buys exactly like the shop. A
 * variant or option change re-draws it from $reloadUrl, this module's endpoint. The
 * card names its place (`data-look`); how it looks there, on every screen and per
 * breakpoint, is in the rules the module writes for that place (CardLook::css).
 *
 * @var object $item          A decorated item (Alfa's ItemsModel)
 * @var string $place         The card's place, which its rules are written for
 * @var array $priceSettings Price visibility settings
 * @var string $reloadUrl     Where a variant or option change re-draws the card; '' when there is nothing to choose
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemListSurface;
use Alfa\Component\Alfa\Site\Helper\Layout;
use Alfa\Component\Alfa\Site\Helper\Surface;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$link = (string) ($item->link ?? '');

// The link must say what it leads to: an item never translated into the active
// language has no name, so its SKU names it, and failing that its id.
$name = trim((string) ($item->name ?? ''));

if ($name === '') {
    $name = trim((string) ($item->sku ?? '')) ?: Text::sprintf('COM_ALFA_ITEM_UNNAMED', (int) $item->id);
}
?>
<article class="item-card" data-look="<?php echo $esc($place); ?>" data-item-id="<?php echo (int) $item->id; ?>">
    <?php if (!empty($item->medias[0]->thumbnail)) : ?>
        <div class="item-card-image">
            <?php // The name's destination again, for the mouse only: one link per card in the tab order and for a screen reader.?>
            <a href="<?php echo $esc($link); ?>" tabindex="-1" aria-hidden="true">
                <img src="<?php echo $esc((string) $item->medias[0]->thumbnail_url); ?>" alt="">
            </a>
            <?php echo Surface::render(owner: $item, slot: ItemListSurface::Image); ?>
        </div>
    <?php endif; ?>
    <div class="item-card-body">
        <div class="item-card-title">
            <a href="<?php echo $esc($link); ?>"><?php echo $esc($name); ?></a>
        </div>
        <?php echo Surface::render(owner: $item, slot: ItemListSurface::Title); ?>
        <form class="item-card-buy" data-item-form data-action="<?php echo $esc($reloadUrl); ?>"
              action="<?php echo $esc((string) ($item->addToCartUrl ?? '')); ?>" method="post">
            <input type="hidden" name="item_id" value="<?php echo (int) $item->id; ?>">
            <?php echo HTMLHelper::_('form.token'); ?>
            <div class="item-fields-surfaces">
                <?php echo Surface::render(owner: $item, slot: ItemListSurface::BuyForm); ?>
            </div>
            <?php // Read-only, no inputs. Inside the form so ShowOn re-evaluates it with the selectors.?>
            <?php if (Surface::has(owner: $item, slot: ItemListSurface::Specifications)) : ?>
                <div class="item-card-specs">
                    <?php echo Surface::render(owner: $item, slot: ItemListSurface::Specifications); ?>
                </div>
            <?php endif; ?>
            <?php echo Layout::render(layout: 'item.price', data: ['item' => $item, 'settings' => $priceSettings]); ?>
            <?php echo Layout::render(layout: 'item.stock_info', data: ['item' => $item]); ?>
            <?php echo Layout::render(layout: 'cart.wrapper', data: $item); ?>
        </form>
    </div>
</article>
