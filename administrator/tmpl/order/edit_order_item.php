<?php
/**
 * Order Item Edit Popup (loaded in modal iframe)
 *
 * Pattern: Same as edit_payment.php / edit_shipment.php
 *
 * Structure:
 * 1. Product search — via layout (overridable, language-string aware)
 * 2. Form fields — via renderFieldset() from order_items.xml
 * 3. JS — template-based rendering (no hardcoded HTML strings)
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Session\Session;

$wa = $this->document->getWebAssetManager();
$wa->useScript('keepalive')
	->useScript('form.validate')
	->useScript('com_alfa.admin')
	->useStyle('com_alfa.admin');

$isNew   = empty($this->orderItem);
$itemID  = $isNew ? 0 : (int) $this->orderItem->id;
$orderID = (int) $this->order->id;

// Check if product is already selected (existing item)
$hasProduct = !$isNew && !empty($this->orderItem->id_item) && (int) $this->orderItem->id_item > 0;

// Tax rate from existing item (for JS calculations)
$currentTaxRate = !$isNew ? (float) ($this->orderItem->tax_rate ?? 0) : 0;

// CSRF token for AJAX calls
$token   = Session::getFormToken();
$searchUrl = Route::_(
	"index.php?option=com_alfa&task=order.searchProducts&id_order={$orderID}&{$token}=1",
	false
);
?>

<!-- Toolbar -->
<div class="subhead noshadow mb-3">
	<?php echo $this->getDocument()->getToolbar('toolbar')->render(); ?>
</div>

<style>
    .product-search-container { position: relative; }
    .product-search-results {
        position: absolute; z-index: 1050; width: 100%;
        max-height: 350px; overflow-y: auto;
        background: #fff; border: 1px solid #dee2e6;
        border-radius: 0 0 6px 6px; box-shadow: 0 4px 12px rgba(0,0,0,.15);
        display: none;
    }
    .product-search-results.show { display: block; }
    .product-result {
        padding: 10px 14px; cursor: pointer;
        border-bottom: 1px solid #f0f0f0; transition: background .15s;
    }
    .product-result:hover { background-color: #f8f9fa; }
    .product-result:last-child { border-bottom: none; }
    .product-result-name { font-weight: 600; color: #212529; }
    .product-result-meta { font-size: .85em; color: #6c757d; margin-top: 2px; }
    .product-result-price { font-weight: 600; color: #198754; }
    .product-result-stock.low { color: #dc3545; }
    .search-spinner { display: none; position: absolute; right: 12px; top: 50%; transform: translateY(-50%); }
    .search-spinner.show { display: block; }
    .no-results { padding: 20px; text-align: center; color: #6c757d; font-style: italic; }
    .selected-product-header {
        background: #f8f9fa; border: 1px solid #dee2e6;
        border-radius: 6px; padding: 12px 16px; margin-bottom: 16px;
    }
</style>

<div class="container-popup">
    <form
            action="<?php echo Route::_("index.php?option=com_alfa&layout=edit_order_item&tmpl=component&id={$itemID}&id_order={$orderID}"); ?>"
            method="post"
            enctype="multipart/form-data"
            name="adminForm"
            id="order-item-form"
            class="form-validate form-horizontal">

        <!-- ============================================================ -->
        <!-- PRODUCT SEARCH (layout — overridable) -->
        <!-- New items: search + select. Existing items: locked display. -->
        <!-- To change the product, delete this item and add a new one. -->
        <!-- ============================================================ -->
		<?php echo LayoutHelper::render('order_item_search', [
			'search_url'  => $searchUrl,
			'has_product' => $hasProduct,
			'product'     => $hasProduct ? $this->orderItem : null,
			'is_new'      => $isNew,
		], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>

        <!-- ============================================================ -->
        <!-- FORM FIELDS (from order_items.xml via renderFieldset) -->
        <!-- ============================================================ -->
        <div id="form-fields-section" style="<?php echo ($isNew && !$hasProduct) ? 'display:none;' : ''; ?>">
			<?php echo $this->form->renderFieldset('order_item'); ?>
        </div>

        <!-- Hidden Fields -->
		<?php echo HTMLHelper::_('form.token'); ?>
        <input type="hidden" name="task" value="" />
        <input type="hidden" name="id" value="<?php echo $itemID; ?>" />
        <input type="hidden" name="id_order" value="<?php echo $orderID; ?>" />
    </form>
</div>

<script>
    (function() {
        'use strict';

        // ================================================================
        // CONFIG
        // ================================================================
        var currentTaxRate = <?php echo $currentTaxRate; ?>;

        // DOM refs — Joomla form fields (always present)
        var fieldIdItem      = document.getElementById('jform_id_item');
        var fieldName        = document.getElementById('jform_name');
        var fieldReference   = document.getElementById('jform_reference');
        var fieldQuantity    = document.getElementById('jform_quantity');
        var fieldPriceIncl   = document.getElementById('jform_unit_price_tax_incl');
        var fieldPriceExcl   = document.getElementById('jform_unit_price_tax_excl');
        var fieldTotalIncl   = document.getElementById('jform_total_price_tax_incl');
        var fieldTaxRate     = document.getElementById('jform_tax_rate');
        var formSection      = document.getElementById('form-fields-section');

        // ================================================================
        // PRODUCT SEARCH (only present on new items — layout returns early on edit)
        // ================================================================
        var searchUrlEl = document.getElementById('product-search-url');

        if (searchUrlEl) {
            var searchUrl      = searchUrlEl.value;
            var minChars       = 2;
            var searchTimer    = null;
            var T              = Joomla.Text._;

            var searchSection  = document.getElementById('product-search-section');
            var headerSection  = document.getElementById('selected-product-header');
            var searchInput    = document.getElementById('product-search-input');
            var searchResults  = document.getElementById('product-search-results');
            var searchSpinner  = document.getElementById('search-spinner');
            var btnChange      = document.getElementById('btn-change-product');
            var productDisplay = document.getElementById('selected-product-display');
            var stockDisplay   = document.getElementById('selected-product-stock');
            var resultTemplate = document.getElementById('product-result-template');

            // ================================================================
            // PRODUCT SEARCH
            // ================================================================
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    var q = this.value.trim();
                    clearTimeout(searchTimer);

                    if (q.length < minChars) {
                        hideResults();
                        return;
                    }

                    searchSpinner.classList.add('show');
                    searchTimer = setTimeout(function() { fetchProducts(q); }, 300);
                });

                document.addEventListener('click', function(e) {
                    if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                        hideResults();
                    }
                });
            }

            function fetchProducts(query) {
                var url = searchUrl + '&q=' + encodeURIComponent(query);

                fetch(url)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        searchSpinner.classList.remove('show');
                        renderResults(data.results || []);
                    })
                    .catch(function(err) {
                        searchSpinner.classList.remove('show');
                        console.error('Product search error:', err);
                        searchResults.innerHTML = '<div class="no-results">' + T('COM_ALFA_SEARCH_ERROR') + '</div>';
                        searchResults.classList.add('show');
                    });
            }

            // ================================================================
            // RENDER RESULTS (template-based — no hardcoded HTML strings)
            // ================================================================
            function renderResults(results) {
                searchResults.innerHTML = '';

                if (results.length === 0) {
                    searchResults.innerHTML = '<div class="no-results">' + T('COM_ALFA_NO_PRODUCTS_FOUND') + '</div>';
                    searchResults.classList.add('show');
                    return;
                }

                results.forEach(function(product) {
                    var clone = resultTemplate.content.cloneNode(true);
                    var wrapper = clone.querySelector('.product-result');

                    // Store product data for click handler
                    wrapper.setAttribute('data-product-json', JSON.stringify(product));

                    // Populate template fields
                    setText(clone, '[data-field="name"]', product.name);
                    setText(clone, '[data-field="id"]', 'ID: ' + product.id);
                    setText(clone, '[data-field="stock"]', product.stock);

                    // SKU
                    var skuEl = clone.querySelector('[data-field="sku"]');
                    if (skuEl) {
                        skuEl.textContent = product.sku ? T('COM_ALFA_SKU') + ': ' + product.sku + ' · ' : '';
                    }

                    // Price — show what customer actually pays
                    var priceEl = clone.querySelector('[data-field="price"]');
                    if (priceEl) {
                        var displayPrice = product.customer_price || product.price_tax_incl;
                        priceEl.textContent = displayPrice > 0
                            ? formatPrice(displayPrice)
                            : T('COM_ALFA_NO_PRICE');
                    }

                    // Tax info
                    var taxEl = clone.querySelector('[data-field="tax-info"]');
                    if (taxEl && product.tax_rate > 0) {
                        taxEl.textContent = '(' + T('COM_ALFA_TAX_EXCL_SHORT') + ': ' +
                            formatPrice(product.price_tax_excl) + ')';
                    }

                    // Discount badge
                    var discountEl = clone.querySelector('[data-field="discount-badge"]');
                    if (discountEl && product.has_discount && product.discount_percent > 0) {
                        discountEl.innerHTML = ' <span class="badge bg-danger">-' +
                            product.discount_percent.toFixed(0) + '%</span>';
                    }

                    // Stock styling
                    var stockContainer = clone.querySelector('[data-field="stock-container"]');
                    if (stockContainer && product.stock <= 0) {
                        stockContainer.classList.add('low');
                    }

                    // Click handler
                    wrapper.addEventListener('click', function() {
                        selectProduct(product);
                    });

                    searchResults.appendChild(clone);
                });

                searchResults.classList.add('show');
            }

            // ================================================================
            // SELECT PRODUCT — fills Joomla form fields
            // ================================================================
            function selectProduct(product) {
                // Fill hidden field
                if (fieldIdItem) fieldIdItem.value = product.id;

                // Fill readonly snapshot fields
                if (fieldName) fieldName.value = product.name;
                if (fieldReference) fieldReference.value = product.sku || '';

                // Fill editable price (tax inclusive — admin can override)
                if (fieldPriceIncl && product.price_tax_incl > 0) {
                    fieldPriceIncl.value = product.price_tax_incl.toFixed(2);
                }

                // Set tax rate and default quantity
                currentTaxRate = product.tax_rate || 0;
                if (fieldTaxRate) fieldTaxRate.value = currentTaxRate.toFixed(3);
                if (fieldQuantity && (!fieldQuantity.value || fieldQuantity.value === '0')) {
                    fieldQuantity.value = 1;
                }

                // Update header display
                if (productDisplay) productDisplay.textContent = product.name;
                if (stockDisplay) {
                    var stockClass = product.stock <= 0 ? 'text-danger' : (product.stock <= 5 ? 'text-warning' : 'text-success');
                    stockDisplay.innerHTML = '<span class="' + stockClass + '">' +
                        T('COM_ALFA_STOCK') + ': ' + product.stock + '</span>';
                }

                // Show form fields + header, hide search
                searchSection.style.display = 'none';
                headerSection.style.display = '';
                formSection.style.display = '';

                hideResults();
                recalculateTotal();
            }

            // ================================================================
            // CHANGE PRODUCT
            // ================================================================
            if (btnChange) {
                btnChange.addEventListener('click', function() {
                    if (fieldIdItem) fieldIdItem.value = 0;
                    currentTaxRate = 0;

                    searchSection.style.display = '';
                    headerSection.style.display = 'none';

                    searchInput.value = '';
                    searchInput.focus();
                });
            }

            function hideResults() { searchResults.classList.remove('show'); }

            function setText(root, selector, value) {
                var el = root.querySelector(selector);
                if (el) el.textContent = value;
            }

            function formatPrice(amount) {
                return new Intl.NumberFormat(undefined, {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }).format(amount);
            }

        } // end if (searchUrlEl) — search-only code

        // ================================================================
        // AUTO-CALCULATE (totals + tax split — always active)
        // ================================================================
        function recalculateTotal() {
            var qty       = parseInt(fieldQuantity ? fieldQuantity.value : 0) || 0;
            var priceIncl = parseFloat(fieldPriceIncl ? fieldPriceIncl.value : 0) || 0;

            var priceExcl = currentTaxRate > 0
                ? priceIncl / (1 + currentTaxRate / 100)
                : priceIncl;

            var totalIncl = qty * priceIncl;

            if (fieldPriceExcl) fieldPriceExcl.value = priceExcl.toFixed(2);
            if (fieldTotalIncl) fieldTotalIncl.value = totalIncl.toFixed(2);
        }

        if (fieldQuantity) {
            fieldQuantity.addEventListener('input', recalculateTotal);
            fieldQuantity.addEventListener('change', recalculateTotal);
        }
        if (fieldPriceIncl) {
            fieldPriceIncl.addEventListener('input', recalculateTotal);
            fieldPriceIncl.addEventListener('change', recalculateTotal);
        }

        // Initial calculation
        recalculateTotal();

    })();
</script>