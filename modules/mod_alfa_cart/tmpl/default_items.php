<?php

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;
use \Alfa\Component\Alfa\Site\Helper\CartHelper;

defined('_JEXEC') or die;

$cart = !empty($displayData) ? $displayData : ($cart ?? null);
if (!isset($cart)) return;

$data = $cart->getData();
$items = $data->items ?? [];
?>

<div class="mod-alfa-cart-data-inner" data-cart-items="<?php echo count($items); ?>">
    <?php if (!empty($items)): ?>
        <div class="cart-header">
            <span class="cart-quantity"><?php echo count($items); ?> <?php echo Text::_('MOD_ALFA_CART_NUMBER_OF_ITEMS_TEXT');?></span>
            <button class="close-btn" aria-label="Close">X</button>
        </div>
        <div class="cart-body">
            <ul class="cart-list">
                <?php foreach ($items as $item): ?>
                    <li class="cart-item-outer" data-item-id="<?php echo htmlspecialchars($item->id_item); ?>">
                        <span class="cart-item-name">
                            <?php echo htmlspecialchars($item->name); ?> -
                            <span class="cart-item-price">
                                <?php echo number_format($item->price['price_with_tax'] , 2); ?>
                            </span> x
                            <span class="cart-item-quantity"><?php echo (int)$item->quantity; ?></span>
                        </span>
                        <div>
                            <div class="item-quantity-controls-wrapper">
                                <button class="item-quantity-controls decrement" data-action="cart-item-decrement">-</button>
                                <input class="item-quantity" type="number" name="quantity" data-action="cart-item-quantity" min="1" value="<?php echo $item->quantity; ?>">
                                <button class="item-quantity-controls increment" data-action="cart-item-increment">+</button>
                            </div>
                        </div>
                        <button class="cart-item-remove" data-action="cart-item-remove">
                            <svg height="32" viewBox="0 0 32 32" width="32" xmlns="http://www.w3.org/2000/svg"><path d="m3 7h2v20.48a3.53 3.53 0 0 0 3.52 3.52h15a3.53 3.53 0 0 0 3.48-3.52v-20.48h2a1 1 0 0 0 0-2h-26a1 1 0 0 0 0 2zm22 0v20.48a1.52 1.52 0 0 1 -1.52 1.52h-15a1.52 1.52 0 0 1 -1.48-1.52v-20.48z"/><path d="m12 3h8a1 1 0 0 0 0-2h-8a1 1 0 0 0 0 2z"/><path d="m12.68 25a1 1 0 0 0 1-1v-12a1 1 0 0 0 -2 0v12a1 1 0 0 0 1 1z"/><path d="m19.32 25a1 1 0 0 0 1-1v-12a1 1 0 0 0 -2 0v12a1 1 0 0 0 1 1z"/></svg>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="cart-footer">
            <div>
                <span class="cart-total-price-label"><?php echo Text::_('MOD_ALFA_CART_TOTAL_PRICE_TEXT');?></span>
                <span class="cart-total-price"><?php echo number_format($cart->getTotal(), 2); ?></span>
            </div>

            <a href="<?php echo Route::_('index.php?option=com_alfa&view=cart'); ?>" class="btn btn-primary"><?php echo Text::_('MOD_ALFA_CART_COMPLETE_ORDER_BUTTON');?></a>

        </div>
    <?php else: ?>
        <span class="alfa-cart-empty"><?php echo Text::_('MOD_ALFA_CART_EMPTY_MSG'); ?></span>
    <?php endif; ?>
</div>
