<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * Product Search Layout for Order Item Popup
 *
 * Renders the search input, results container, and a HTML <template>
 * for JS to clone when populating search results.
 *
 * Layout path: administrator/components/com_alfa/layouts/order_item_search.php
 * Override:    administrator/templates/{template}/html/layouts/com_alfa/order_item_search.php
 *
 * @var array $displayData Contains:
 *   - 'search_url'  => AJAX search URL (with CSRF token)
 *   - 'has_product' => bool — whether a product is already selected
 *   - 'product'     => object|null — currently selected product (for edit)
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$searchUrl  = $displayData['search_url'] ?? '';
$hasProduct = $displayData['has_product'] ?? false;
$product    = $displayData['product'] ?? null;
$isNew      = $displayData['is_new'] ?? true;

// Existing items: no search — product is locked, shown in form header
if (!$isNew) {
	return;
}
?>

<!-- ================================================================ -->
<!-- SEARCH SECTION (hidden once product is selected) -->
<!-- ================================================================ -->
<div id="product-search-section" style="<?php echo $hasProduct ? 'display:none;' : ''; ?>">
    <fieldset class="options-form mb-3">
        <legend><?php echo Text::_('COM_ALFA_SEARCH_PRODUCT'); ?></legend>
        <div class="product-search-container">
            <input type="text"
                   id="product-search-input"
                   class="form-control form-control-lg"
                   placeholder="<?php echo Text::_('COM_ALFA_SEARCH_PRODUCT_PLACEHOLDER'); ?>"
                   autocomplete="off" />
            <div class="search-spinner" id="search-spinner">
                <div class="spinner-border spinner-border-sm text-primary" role="status">
                    <span class="visually-hidden"><?php echo Text::_('COM_ALFA_LOADING'); ?></span>
                </div>
            </div>
            <div class="product-search-results" id="product-search-results"></div>
        </div>
    </fieldset>
</div>

<!-- ================================================================ -->
<!-- SELECTED PRODUCT HEADER -->
<!-- "Change" button lets admin pick a different product before saving -->
<!-- ================================================================ -->
<div id="selected-product-header" style="<?php echo $hasProduct ? '' : 'display:none;'; ?>">
    <div class="selected-product-header d-flex justify-content-between align-items-center">
        <div>
            <strong id="selected-product-display">
				<?php echo $hasProduct && $product ? $this->escape($product->name) : ''; ?>
            </strong>
            <span id="selected-product-stock" class="ms-3 text-muted"></span>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-change-product">
            <span class="icon-refresh"></span>
			<?php echo Text::_('COM_ALFA_CHANGE_PRODUCT'); ?>
        </button>
    </div>
</div>

<!-- ================================================================ -->
<!-- RESULT TEMPLATE (cloned by JS for each search result) -->
<!-- Overridable: change this template to alter search result display -->
<!-- ================================================================ -->
<template id="product-result-template">
    <div class="product-result" data-product-json="">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="product-result-name">
                    <span data-field="name"></span>
                    <span data-field="discount-badge"></span>
                </div>
                <div class="product-result-meta">
                    <span data-field="sku"></span>
                    <span data-field="id"></span>
                </div>
            </div>
            <div class="text-end">
                <div class="product-result-price">
                    <span data-field="price"></span>
                    <small class="text-muted" data-field="tax-info"></small>
                </div>
                <div class="product-result-meta product-result-stock" data-field="stock-container">
					<?php echo Text::_('COM_ALFA_STOCK'); ?>: <span data-field="stock"></span>
                </div>
            </div>
        </div>
    </div>
</template>

<!-- ================================================================ -->
<!-- LOCALISED STRINGS (Joomla standard: Text::script → Joomla.Text._) -->
<!-- ================================================================ -->
<?php
// Register strings for JS via Joomla's built-in system
// Available in JS as: Joomla.Text._('COM_ALFA_NO_PRODUCTS_FOUND')
Text::script('COM_ALFA_NO_PRODUCTS_FOUND');
Text::script('COM_ALFA_NO_PRICE');
Text::script('COM_ALFA_STOCK');
Text::script('COM_ALFA_SKU');
Text::script('COM_ALFA_TAX_EXCL_SHORT');
Text::script('COM_ALFA_DISCOUNT');
Text::script('COM_ALFA_LOADING');
Text::script('COM_ALFA_SEARCH_ERROR');
?>

<!-- Search URL (only config that isn't a language string) -->
<input type="hidden" id="product-search-url" value="<?php echo $searchUrl; ?>" />