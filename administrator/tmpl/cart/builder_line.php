<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The builder's cart-line popup: the Order screen's item popup, for a live cart.
 *
 * Same shape, same order, same mechanics as tmpl/order/edit_order_item.php: a
 * toolbar of real buttons, one plain form, and a controller task behind each
 * button. With nothing chosen it renders the SHARED item-search layout; picking
 * an item reloads this popup on it (configure-first); an existing line opens
 * pre-selected on what the shopper actually chose. The field surfaces are the
 * customer's own (variants, options, item fields, ShowOn), rendered by each
 * field plugin through PluginLayoutHelper::storefrontLayout() (the default site
 * template's overrides, then the plugin's tmpl) and priced for the CART's customer.
 *
 * Nothing here computes money, resolves a combination or decides what may be
 * added. Save posts the configured form to cart.addLine, which builds the SAME
 * AddToCartCommand the storefront's own request reader builds; the controller
 * answers with the return layout, which closes the popup and re-renders the
 * page. Editing posts edit_line, so CartLineWriter replaces the line in place
 * rather than adding a second one.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Cart\HtmlView $this
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemSurface;
use Alfa\Component\Alfa\Administrator\Helper\PluginLayoutHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$wa = $this->document->getWebAssetManager();
$wa->useScript('keepalive')
    ->useScript('form.validate')
    ->useScript('com_alfa.validate.engine')
    ->useStyle('com_alfa.validate')
    ->useStyle('com_alfa.admin')
    // The configuration surfaces ARE the storefront's field layouts; load the
    // storefront item stylesheet and the shared ShowOn engine so they look and
    // behave exactly as the customer saw them.
    ->useStyle('com_alfa.item')
    ->useScript('com_alfa.showon.engine')
    ->useScript('com_alfa.admin.cart-builder-line');

$cartId  = (int) $this->builderCartId;
$line    = $this->configLine;
$item    = $this->configItem;
$lineId  = $line !== null ? (int) $line->id : 0;
$isNew   = $lineId === 0;
$itemId  = $item !== null ? (int) $item->id : 0;

// An item is on screen either because a line is being re-configured, or because
// one was picked in the search and this popup reloaded on it (configure-first).
$hasItem   = $item !== null;
$quantity  = $line !== null ? max(1, (int) $line->quantity) : 1;
$surfaces  = $hasItem ? ($item->surfaces[ItemSurface::BuyForm->value] ?? []) : [];
$token     = Session::getFormToken();

$searchUrl = Route::_(
    "index.php?option=com_alfa&task=cart.searchProducts&cart_id={$cartId}&{$token}=1",
    false,
);

// The quote follows the form: cart-builder-line.js asks the server to render it
// again for the posted configuration and quantity.
if ($hasItem) {
    $this->getDocument()->addScriptOptions('com_alfa.cartBuilderLine', [
        'quoteUrl' => Route::_("index.php?option=com_alfa&task=cart.priceLineConfig&cart_id={$cartId}&item_id={$itemId}&line_id={$lineId}&{$token}=1", false),
        'failed'   => Text::_('COM_ALFA_ORDER_ITEM_PREVIEW_FAILED'),
    ]);
}
?>

<div class="subhead noshadow mb-3">
    <?php echo $this->getDocument()->getToolbar('toolbar')->render(); ?>
</div>

<div class="container-popup">
    <form action="<?php echo Route::_("index.php?option=com_alfa&view=cart&layout=builder_line&tmpl=component&cart_id={$cartId}&line_id={$lineId}"); ?>"
          method="post"
          name="adminForm"
          id="alfa-builder-line-form"
          class="form-validate"
          data-cart-id="<?php echo $cartId; ?>">

        <?php // The SHARED item-search layout: the same one the Order screen's
              // item popup renders, so one search box and one result row are
              // maintained (and overridden) in one place. An existing line shows
              // its item locked: to change the item, remove the line and add. ?>
        <?php echo LayoutHelper::render(
            'item.search',
            [
                'search_url'  => $searchUrl,
                'has_product' => $hasItem,
                'product'     => $hasItem
                    ? (object) [
                        'name'    => $item->name ?? '',
                        'sku'     => $item->sku ?? '',
                        'id_item' => $itemId,
                    ]
                    : null,
                'is_new'      => $isNew,
            ],
            JPATH_ADMINISTRATOR . '/components/com_alfa/layouts',
        ); ?>

        <?php if ($hasItem) : ?>
            <?php if ($surfaces) : ?>
                <div class="alfa-builder-line-surfaces card mb-3">
                    <div class="card-body">
                        <?php foreach ($surfaces as $surface) : ?>
                            <?php if (empty($surface['layout'])) {
                                continue;
                            } ?>
                            <div class="mb-3"<?php echo $surface['showon'] ?? ''; ?>>
                                <?php echo PluginLayoutHelper::storefrontLayout(
                                    $surface['layoutPluginType'] ?: 'alfa-item-fields',
                                    $surface['layoutPluginName'],
                                    $surface['layout'],
                                )->render(!empty($surface['layoutData']) ? $surface['layoutData'] : $item); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body row g-3 align-items-end">
                    <div class="col-auto">
                        <label class="form-label" for="alfa-builder-quantity">
                            <?php echo Text::_('COM_ALFA_CART_TABLE_QUANTITY'); ?>
                        </label>
                        <input type="number" class="form-control" id="alfa-builder-quantity"
                               name="quantity" min="1" step="1" value="<?php echo $quantity; ?>"
                               style="width:8rem;" required>
                    </div>

                    <?php // The quote: the line as the cart will price it, the Items
                          // table's own cells, re-rendered by the server on change. ?>
                    <?php if (!empty($item->price)) : ?>
                        <div class="col-auto" data-builder-quote aria-live="polite">
                            <div data-builder-quote-figures>
                                <?php echo LayoutHelper::render('cart.builder_quote', ['price' => $item->price], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>
                            </div>
                            <div class="alert alert-danger small mt-2 mb-0" data-builder-quote-error hidden></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php echo HTMLHelper::_('form.token'); ?>
        <input type="hidden" name="task" value="">
        <input type="hidden" name="form" value="popup">
        <input type="hidden" name="cart_id" value="<?php echo $cartId; ?>">
        <input type="hidden" name="item_id" value="<?php echo $itemId; ?>">
        <input type="hidden" name="line_id" value="<?php echo $lineId; ?>">
        <?php // Re-configuring: CartLineWriter REPLACES this line rather than
              // adding a second one carrying the new selection. ?>
        <?php if (!$isNew) : ?>
            <input type="hidden" name="edit_line" value="<?php echo $lineId; ?>">
        <?php endif; ?>
    </form>
</div>

<script>
    /**
     * The popup's item search: the same behaviour as the Order screen's item
     * popup, driving the same SHARED layout contract: paint the results the
     * layout's <template> defines, and on a pick reload this popup ON that item
     * (configure-first). Nothing is added until Save, and nothing here prices,
     * resolves or validates anything.
     */
    (function () {
        'use strict';

        var cartId = <?php echo $cartId; ?>;
        var searchInput = document.getElementById('product-search-input');
        var searchResults = document.getElementById('product-search-results');
        var resultTemplate = document.getElementById('product-result-template');
        var searchUrl = document.getElementById('product-search-url');
        var spinner = document.getElementById('search-spinner');
        var searchTimeout = null;

        if (!searchInput || !searchResults || !resultTemplate || !searchUrl) {
            return;
        }

        function showSpinner(show) {
            if (spinner) {
                spinner.classList.toggle('show', show);
            }
        }

        function hideResults() {
            searchResults.classList.remove('show');
        }

        function setText(root, field, value) {
            var el = root.querySelector('[data-field="' + field + '"]');

            if (el) {
                el.textContent = value === null || value === undefined ? '' : String(value);
            }
        }

        function selectProduct(product) {
            // Configure-first: reload this popup as the full line editor for the
            // picked item (configuration + pricing), one save total.
            window.location.href = 'index.php?option=com_alfa&view=cart&layout=builder_line&tmpl=component'
                + '&cart_id=' + cartId + '&line_id=0&id_item=' + product.id;
        }

        function renderResults(results) {
            searchResults.replaceChildren();

            if (!results.length) {
                var empty = document.createElement('div');
                empty.className = 'no-results';
                empty.textContent = Joomla.Text._('COM_ALFA_NO_PRODUCTS_FOUND');
                searchResults.appendChild(empty);
                searchResults.classList.add('show');

                return;
            }

            results.forEach(function (product) {
                var node = resultTemplate.content.cloneNode(true);
                var card = node.querySelector('.product-result');

                setText(node, 'name', product.name);
                setText(node, 'sku', product.sku ? Joomla.Text._('COM_ALFA_SKU') + ': ' + product.sku : '');
                setText(node, 'id', '#' + product.id);
                setText(node, 'price', product.customer_price);
                setText(node, 'stock', product.stock);

                if (card) {
                    card.addEventListener('click', function () { selectProduct(product); });
                }

                searchResults.appendChild(node);
            });

            searchResults.classList.add('show');
        }

        function fetchProducts(query) {
            showSpinner(true);

            fetch(searchUrl.value + '&q=' + encodeURIComponent(query), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                showSpinner(false);
                renderResults((payload && payload.data && payload.data.results) || []);
            })
            .catch(function () {
                showSpinner(false);
                hideResults();
                Joomla.renderMessages({ error: [Joomla.Text._('COM_ALFA_SEARCH_ERROR')] });
            });
        }

        searchInput.addEventListener('input', function () {
            var query = searchInput.value.trim();
            window.clearTimeout(searchTimeout);

            if (query.length < 2) {
                hideResults();

                return;
            }

            searchTimeout = window.setTimeout(function () { fetchProducts(query); }, 250);
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('.product-search-container')) {
                hideResults();
            }
        });

        // "Change item": the shared layout's own control. The configuration on
        // screen belongs to the item that was picked and is rendered by the
        // server, so changing the item means going back to the search, not
        // swapping a hidden field.
        var btnChange = document.getElementById('btn-change-product');

        if (btnChange) {
            btnChange.addEventListener('click', function () {
                window.location.href = 'index.php?option=com_alfa&view=cart&layout=builder_line&tmpl=component'
                    + '&cart_id=' + cartId + '&line_id=0';
            });
        }
    }());
</script>
