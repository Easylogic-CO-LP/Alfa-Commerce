<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The add-to-cart WRAPPER: one renderer, two contexts (product page + listing
 * card). A composition shell ONLY: a DUMB presenter that renders already-resolved
 * state. It is NEVER a decision layer: no conditionals, no intent resolution, no
 * plugin merging, no state logic. The moment it starts deciding, the architecture
 * collapses into layout-driven business logic. All "list vs product" defaults and
 * plugin overrides are resolved upstream (HtmlView / ItemsModel) onto $item->cart;
 * this only lays out the two slots:
 *
 *   MAIN   - the core add-to-cart (on a card, possibly the core cart.options
 *            link), or a plugin's replacement ($item->cart).
 *   NOTIFY - the "notify me when available" control (cart.notify), drawn when
 *            the model's cart state offers it; outside MAIN, so a replaced or
 *            absent add to cart (catalogue-only mode) keeps it.
 *   CHILD  - append-only siblings (wishlist, buy-now); the component-owned region
 *            wrapper keeps this slot alive even when MAIN is replaced.
 *
 * @var object $displayData The item (carries ->cart and optional ->cartChildren).
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\Layout;

$item = $displayData;
$cart = $item->cart ?? ['layout' => 'cart.add', 'layoutData' => [], 'layoutPluginType' => '', 'layoutPluginName' => ''];
?>
<div class="item-cart-wrapper">

    <?php // MAIN slot: default add-to-cart or a plugin's replacement. ?>
    <?php echo Layout::render(
        $cart['layout'],
        !empty($cart['layoutData']) ? $cart['layoutData'] : $item,
        $cart['layoutPluginType'] ?? '',
        $cart['layoutPluginName'] ?? '',
    ); ?>

    <?php // NOTIFY slot: the layout draws the control only when the cart state offers it. ?>
    <?php echo Layout::render('cart.notify', $item); ?>

    <?php // CHILD slot: append-only siblings. Empty until a plugin contributes. ?>
    <?php foreach ($item->cartChildren ?? [] as $child): ?>
        <?php echo Layout::render(
            $child['layout'],
            !empty($child['layoutData']) ? $child['layoutData'] : $item,
            $child['layoutPluginType'] ?? '',
            $child['layoutPluginName'] ?? '',
        ); ?>
    <?php endforeach; ?>

</div>
