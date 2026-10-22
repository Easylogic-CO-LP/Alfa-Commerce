<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * Order Item Edit Popup (loaded in modal iframe)
 *
 * Pattern: Same as edit_payment.php / edit_shipment.php
 *
 * Structure:
 * 1. Product search: via layout (overridable, language-string aware)
 * 2. Configuration surfaces: the storefront field layouts, seeded from the
 *    line's frozen snapshot
 * 3. Pricing: the line's current terms, the price command (layout
 *    order.item_price_command: Leave unchanged / Set / Clear), the quantity,
 *    the manual discount (the line's stored setting) with its custom-price
 *    tick, and "After save": the save's own dry run
 * 4. JS: media/com_alfa/js/admin/order-item.js; it fills slots, it never
 *    computes money
 *
 * A refused Save comes back through this layout with what was posted
 * (HtmlView::$keptPost, on the kept=1 redisplay only): the price command and
 * its amount, the quantity, the manual discount and its tick, the
 * configuration and a new line's product.
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemSurface;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\PluginLayoutHelper;
use Alfa\Component\Alfa\Administrator\Service\Order\ManualDiscountScope;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExplicitPriceInstruction;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Session\Session;

$wa = $this->document->getWebAssetManager();
$wa->useScript('keepalive')
	->useScript('form.validate')
	->useScript('com_alfa.validate.engine')
	->useStyle('com_alfa.validate')
	->useScript('com_alfa.admin')
	->useStyle('com_alfa.admin');

// The configuration surfaces are the storefront field layouts; load the
// storefront item stylesheet + the showon engine so they LOOK and BEHAVE
// exactly as the customer saw them (conditional fields toggle live).
$wa->useStyle('com_alfa.item');
$wa->useScript('com_alfa.showon.engine');

// The popup's behaviour lives in media: the product search of a new line and
// the preview, which is the save's own dry run (order.previewOrderItem).
$wa->useScript('com_alfa.admin.order-item');

$isNew   = empty($this->orderItem);
$itemID  = $isNew ? 0 : (int) $this->orderItem->id;
$orderID = (int) $this->order->id;

// What a refused Save posted, redisplayed as typed; empty on any other open.
$kept  = $this->keptPost ?? [];
$typed = static fn (string $name): ?string => is_string($kept[$name] ?? null) ? $kept[$name] : null;

// Product selection state: an existing line, or a product picked in the
// search that reloaded this layout in configure-first mode (id=0&id_item=N).
$isConfigureFirst = $isNew && !empty($this->newItemId);
$hasProduct = (!$isNew && !empty($this->orderItem->id_item) && (int) $this->orderItem->id_item > 0) || $isConfigureFirst;

// The line's item-field surfaces (configuration half of the popup), prepared
// by getOrderItemConfig, pre-seeded from the frozen snapshot. Empty on new items.
$configSurfaces = $this->configItem->surfaces[ItemSurface::BuyForm->value] ?? [];

// CSRF token for AJAX calls
$token   = Session::getFormToken();
$searchUrl = Route::_(
	"index.php?option=com_alfa&task=order.searchProducts&id_order={$orderID}&{$token}=1",
	false
);

$this->getDocument()->addScriptOptions('com_alfa.orderItem', [
	'previewUrl' => Route::_('index.php?option=com_alfa&task=order.previewOrderItem&format=json', false),
	'failed'     => Text::_('COM_ALFA_ORDER_ITEM_PREVIEW_FAILED'),
	'orderId'    => $orderID,
]);
?>

<!-- Toolbar -->
<div class="subhead noshadow mb-3">
	<?php echo $this->getDocument()->getToolbar('toolbar')->render(); ?>
</div>

<?php // The item search's styling is shared with the cart builder's line
      // popup; it lives with the shared layout, in com_alfa.admin. ?>

<div class="container-popup">
    <form
            action="<?php echo Route::_("index.php?option=com_alfa&layout=edit_order_item&tmpl=component&id={$itemID}&id_order={$orderID}"); ?>"
            method="post"
            enctype="multipart/form-data"
            name="adminForm"
            id="order-item-form"
            class="form-validate form-horizontal">

        <!-- PRODUCT SEARCH (layout, overridable) -->
        <!-- New items: search + select. Existing items: locked display. -->
        <!-- To change the product, delete this item and add a new one. -->
		<?php echo LayoutHelper::render('item.search', [
			'search_url'  => $searchUrl,
			'has_product' => $hasProduct,
			'product'     => $hasProduct
                ? ($this->orderItem ?? (object) [
                    'name' => $this->configItem->name ?? '',
                    'sku' => $this->configItem->sku ?? '',
                    'id_item' => $this->newItemId,
                ])
                : null,
			'is_new'      => $isNew,
		], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>

        <!-- CONFIGURATION (the customer's own field surfaces, seeded from -->
        <!-- the frozen snapshot: variants / options / payload widgets).   -->
        <!-- A line the catalogue can no longer draw as sold shows what    -->
        <!-- it sold, read-only, and posts no configuration.               -->
		<?php if ($this->soldReadOnly): ?>
        <div class="order-item-sold border-bottom pb-3 mb-3" data-order-item-sold-readonly>
            <h6 class="text-muted mb-2"><?php echo Text::_('COM_ALFA_ORDER_ITEM_SOLD_AS'); ?></h6>
            <p class="mb-1"><strong><?php echo $this->escape($this->orderItem->name); ?></strong></p>
			<?php foreach ((array) ($this->orderItem->fields ?? []) as $soldField): ?>
            <div class="small"><?php echo $this->escape($soldField->label); ?><?php echo $soldField->value !== '' ? ': ' . $this->escape($soldField->value) : ''; ?></div>
			<?php endforeach; ?>
            <div class="alert alert-info small mt-2 mb-0"><?php echo Text::_('COM_ALFA_ORDER_ITEM_SOLD_NOT_OFFERED'); ?></div>
        </div>
		<?php elseif ($configSurfaces): ?>
        <div class="order-item-config-surfaces border-bottom pb-3 mb-3">
			<?php foreach ($configSurfaces as $surface): ?>
				<?php if (empty($surface['layout'])) {
                    continue;
				} ?>
                <div class="order-item-config-surface mb-3"<?php echo $surface['showon'] ?? ''; ?>>
					<?php echo PluginLayoutHelper::storefrontLayout(
                        $surface['layoutPluginType'] ?: 'alfa-item-fields',
                        $surface['layoutPluginName'],
                        $surface['layout'],
					)->render(!empty($surface['layoutData']) ? $surface['layoutData'] : $this->configItem); ?>
                </div>
			<?php endforeach; ?>
        </div>
		<?php endif; ?>

        <!-- PRICING: what the line is now, how its price is to be        -->
        <!-- treated, and what Save will write. The popup computes no     -->
        <!-- money: "After save" is the server's own dry run.             -->
        <div id="form-fields-section" style="<?php echo ($isNew && !$hasProduct) ? 'display:none;' : ''; ?>">
			<?php
            // Hidden keys only: name/sku are the line's own frozen snapshot,
            // shown by the product header / modal title, never edited here.
            echo $this->form->renderField('id');
            echo $this->form->renderField('id_order');
            echo $this->form->renderField('id_item');

            // Frozen composition of the unit price (existing lines only).
            $breakdownFields = array_filter(
                (array) ($this->orderItem->fields ?? []),
                static fn ($f) => $f->impact !== '',
            );

            $hasBreakdown = !$isNew && $breakdownFields
                && is_object($this->orderItem->currency ?? null)
                && is_numeric($this->orderItem->unit_price_tax_incl_stored ?? null);

            $lineCurrency = !$isNew ? ($this->orderItem->currency ?? null) : null;
            $format = static fn ($value): string => $lineCurrency && $value !== null && $value !== ''
                ? $lineCurrency->format((float) (is_object($value) ? $value->getAmount() : $value))
                : '';
            // The custom rate itself shows its own stored decimals, never fewer
            // than the currency's: it is the agreed rate as data.
            $formatRate = static fn ($value): string => $lineCurrency && $value !== null && $value !== '' ? $lineCurrency->formatRate(rate: (float) $value) : '';
            $storedCustom = !$isNew ? ($this->orderItem->custom_unit_price_tax_incl ?? null) : null;
            // The line's units by the one unit rule, from its own quantity and
            // totals at the order's frozen scale; the agreed rate is a gross
            // rate and is passed beside the gross unit only.
            $lineQuantity = !$isNew ? ($this->orderItem->quantity ?? 0) : 0;
            $amountOf = static fn ($value) => is_object($value) ? $value->getAmount() : ($value ?? 0);
            $formatUnit = static fn ($unit, $total, $rate = null): string => $lineCurrency && $unit !== null && $unit !== ''
                ? $lineCurrency->formatUnit(unit: $unit, lineTotal: $amountOf($total), quantity: $lineQuantity, rate: $rate)
                : '';

            // The line's stored manual discount and its tick. The popup opens
            // on Leave, or on the command a refused Save posted. The tick is
            // shown as the script shows it: under Set, and under Leave while
            // the line carries a custom price.
            $storedManual = !$isNew ? number_format((float) ($this->orderItem->manual_discount_percent ?? 0), 2, '.', '') : '';
            $storedScope = !$isNew ? (string) ($this->orderItem->manual_discount_scope ?? '') : '';
            $command = $typed('custom_price_action') ?? 'leave';
            $scopeApplies = ($typed('manual_discount_scope') ?? $storedScope) === ManualDiscountScope::ApplyToCustomPrice->value;
            $scopeShown = $command === 'set' || ($command === 'leave' && $storedCustom !== null);
			?>

            <div class="order-item-pricing border rounded p-3">
                <h6 class="text-muted mb-2"><?php echo Text::_('COM_ALFA_ORDER_ITEM_PRICE_BREAKDOWN'); ?></h6>

                <div data-price-breakdown-lines>
				<?php if ($hasBreakdown):
                    // The unit as stored, less every field's stored impact, exactly:
                    // the base and the impacts add up to the stored unit, and each
                    // prints by the one unit rule's stored branch.
                    $base = \Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount::of((string) $this->orderItem->unit_price_tax_incl_stored);

                    foreach ($breakdownFields as $bf) {
                        $base = $base->minus(\Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount::of((string) $bf->impact_amount));
                    }
					?>
                <div class="d-flex justify-content-between py-1">
                    <span><?php echo Text::_('COM_ALFA_ORDER_ITEM_BASE_PRICE'); ?></span>
                    <span><?php echo $this->escape($lineCurrency->formatStored(value: $base->text())); ?></span>
                </div>

					<?php foreach ($breakdownFields as $bf): ?>
                <div class="d-flex justify-content-between py-1">
                    <span>
                        <?php echo $this->escape($bf->label); ?><?php echo $bf->value !== '' ? ': ' . $this->escape($bf->value) : ''; ?>
                    </span>
                    <span class="text-success"><?php echo $this->escape($bf->impact); ?></span>
                </div>
						<?php foreach ($bf->breakdown as $line): ?>
                <div class="d-flex justify-content-between py-1 ps-4 small text-muted">
                    <span>&middot; <?php echo $this->escape($line->label); ?></span>
                    <span><?php echo $this->escape($line->impact); ?></span>
                </div>
						<?php endforeach; ?>
					<?php endforeach; ?>

				<?php endif; ?>
                </div>

				<?php if (!$isNew): ?>
                <h6 class="text-muted mt-3 mb-2"><?php echo Text::_('COM_ALFA_ORDER_ITEM_CURRENT_TERMS'); ?></h6>
                <dl class="row small mb-0">
                    <dt class="col-6"><?php echo Text::_('COM_ALFA_ORDER_ITEM_UNIT_PRICE'); ?></dt>
                    <dd class="col-6 text-end mb-1"><?php echo $this->escape($formatUnit($this->orderItem->unit_price_tax_incl_stored, $this->orderItem->total_price_tax_incl ?? null, $storedCustom)); ?>
                        <span class="text-muted">(<?php echo $this->escape($formatUnit($this->orderItem->unit_price_tax_excl_stored, $this->orderItem->total_price_tax_excl ?? null)); ?>)</span></dd>
                    <dt class="col-6"><?php echo Text::_('COM_ALFA_ORDER_ITEM_DISCOUNT'); ?></dt>
                    <dd class="col-6 text-end mb-1"><?php echo $this->escape(AlfaHelper::percentDisplay(value: $this->orderItem->discount_percent ?? 0)); ?>
                        <span class="text-muted">(<?php echo $this->escape($format($this->orderItem->discount_amount_tax_incl ?? null)); ?>)</span></dd>
                    <dt class="col-6"><?php echo Text::_('COM_ALFA_FORM_LBL_TAX_RATE'); ?></dt>
                    <dd class="col-6 text-end mb-1"><?php echo $this->escape(AlfaHelper::percentDisplay(value: $this->orderItem->tax_rate ?? 0)); ?></dd>
                    <dt class="col-6"><?php echo Text::_('COM_ALFA_ORDER_ITEM_LINE_TOTAL'); ?></dt>
                    <dd class="col-6 text-end mb-1"><strong><?php echo $this->escape($format($this->orderItem->total_price_tax_incl ?? null)); ?></strong></dd>
                </dl>
				<?php endif; ?>

                <hr class="my-2">

				<?php echo LayoutHelper::render('order.item_price_command', [
                    'is_new'       => $isNew,
                    // The stored rate as its canonical text, never through a float: PHP prints 0.00001 as 1.0E-5,
                    // which the Set decoder refuses.
                    'stored_custom_price' => $storedCustom !== null ? ExplicitPriceInstruction::canonicalRate(value: $storedCustom) : null,
                    'custom_label' => $storedCustom !== null ? $formatRate($storedCustom) : '',
                    // The command and the amount a refused Save posted, on its redisplay.
                    'command'      => $command,
                    'amount'       => $typed('custom_unit_price_tax_incl'),
                    // A unit rate may carry Alfa's eight decimal places, whatever the currency's scale.
                    'step'         => '0.' . str_repeat('0', Currency::SCALE_MAX - 1) . '1',
                ], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>

				<?php echo $this->form->renderField('quantity'); ?>

                <!-- The manual discount is the line's SETTING:
                     it opens on the stored percentage, never on the combined
                     saving above. A new value replaces it and 0 removes it;
                     posted back as it opened, or emptied, it stays. -->
                <div class="control-group">
                    <div class="control-label">
                        <label for="order-item-manual-discount"><?php echo Text::_('COM_ALFA_ORDER_ITEM_MANUAL_DISCOUNT'); ?></label>
                    </div>
                    <div class="controls">
                        <input class="form-control" type="number" min="0" max="100" step="0.01"
                               id="order-item-manual-discount" name="manual_discount_percent" value="<?php echo $this->escape($typed('manual_discount_percent') ?? $storedManual); ?>">
						<?php if (!$isNew): ?>
                        <!-- What the popup opened on: a value posted back equal
                             to it is not stated, so a change saved elsewhere
                             after this popup opened is never written over. -->
                        <input type="hidden" name="manual_discount_percent_shown" value="<?php echo $this->escape($typed('manual_discount_percent_shown') ?? $storedManual); ?>">
                        <input type="hidden" name="manual_discount_scope_shown" value="<?php echo $this->escape($typed('manual_discount_scope_shown') ?? $storedScope); ?>">
						<?php endif; ?>
                        <div class="form-text"><?php echo Text::_('COM_ALFA_ORDER_ITEM_MANUAL_DISCOUNT_SETTING_DESC'); ?></div>
                    </div>
                </div>

                <!-- "Apply this discount to the custom price": shown
                     only while a custom price is set, off by default, stored
                     with the line. The hidden default makes an unticked box
                     post "does not apply"; while hidden both are disabled and
                     post nothing, so the stored choice stands. -->
                <div class="control-group order-item-manual-scope" data-order-item-manual-scope
                     data-has-custom-price="<?php echo $storedCustom !== null ? '1' : '0'; ?>"<?php echo $scopeShown ? '' : ' hidden'; ?>>
                    <div class="controls">
                        <input type="hidden" name="manual_discount_scope"
                               value="<?php echo $this->escape(ManualDiscountScope::ExcludeCustomPrice->value); ?>"
                               data-order-item-manual-scope-input<?php echo $scopeShown ? '' : ' disabled'; ?>>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="manual_discount_scope" id="order-item-manual-scope"
                                   value="<?php echo $this->escape(ManualDiscountScope::ApplyToCustomPrice->value); ?>"
                                   data-order-item-manual-scope-input<?php echo $scopeApplies ? ' checked' : ''; ?><?php echo $scopeShown ? '' : ' disabled'; ?>>
                            <label class="form-check-label" for="order-item-manual-scope"><?php echo Text::_('COM_ALFA_ORDER_ITEM_MANUAL_APPLIES_TO_CUSTOM_PRICE'); ?></label>
                        </div>
                        <div class="form-text"><?php echo Text::_('COM_ALFA_ORDER_ITEM_MANUAL_APPLIES_TO_CUSTOM_PRICE_DESC'); ?></div>
                    </div>
                </div>

                <hr class="my-2">

                <h6 class="text-muted mb-2"><?php echo Text::_('COM_ALFA_ORDER_ITEM_AFTER_SAVE'); ?></h6>
                <div data-order-item-preview data-state="pending" aria-live="polite">
                    <dl class="row small mb-0">
                        <dt class="col-6"><?php echo Text::_('COM_ALFA_ORDER_ITEM_UNIT_PRICE'); ?></dt>
                        <dd class="col-6 text-end mb-1"><span data-order-item-preview-field="unit_price_tax_incl"></span>
                            <span class="text-muted">(<span data-order-item-preview-field="unit_price_tax_excl"></span>)</span></dd>
                        <dt class="col-6"><?php echo Text::_('COM_ALFA_ORDER_ITEM_DISCOUNT'); ?></dt>
                        <dd class="col-6 text-end mb-1"><span data-order-item-preview-field="discount_percent"></span>
                            <span class="text-muted">(<span data-order-item-preview-field="discount_amount_tax_incl"></span>)</span></dd>
                        <dt class="col-6"><?php echo Text::_('COM_ALFA_FORM_LBL_TAX_RATE'); ?></dt>
                        <dd class="col-6 text-end mb-1"><span data-order-item-preview-field="tax_rate"></span></dd>
                        <dt class="col-6"><?php echo Text::_('COM_ALFA_ORDER_ITEM_CUSTOM_AMOUNT'); ?></dt>
                        <dd class="col-6 text-end mb-1"><span data-order-item-preview-field="custom_unit_price_tax_incl"></span></dd>
                        <dt class="col-6"><?php echo Text::_('COM_ALFA_ORDER_ITEM_MANUAL_DISCOUNT'); ?></dt>
                        <dd class="col-6 text-end mb-1"><span data-order-item-preview-field="manual_discount_percent"></span>
                            <span class="text-muted" data-order-item-preview-field="manual_discount_scope"></span></dd>
                        <dt class="col-6"><?php echo Text::_('COM_ALFA_ORDER_ITEM_LINE_TOTAL'); ?></dt>
                        <dd class="col-6 text-end mb-1"><strong data-order-item-preview-field="total_price_tax_incl"></strong>
                            <span class="text-muted">(<span data-order-item-preview-field="total_price_tax_excl"></span>)</span></dd>
                    </dl>
                    <p class="text-muted small mb-0" data-order-item-preview-unchanged hidden><?php echo Text::_('COM_ALFA_ORDER_ITEM_PREVIEW_UNCHANGED'); ?></p>
                    <div class="alert alert-danger small mt-2 mb-0" data-order-item-preview-error hidden></div>
                </div>
            </div>
        </div>

        <!-- Hidden Fields -->
		<?php // The composition each payload widget showed before the operator first touched it, as a
              // refused Save posted it: still its baseline, so a composition typed before the refusal
              // is still a change (order-item.js). ?>
		<?php foreach ((array) ($kept['configuration']['payloadBaselines'] ?? []) as $fieldId => $baseline): ?>
        <input type="hidden" name="itemfields_payload_baseline[<?php echo (int) $fieldId; ?>]" value="<?php echo $this->escape((string) $baseline); ?>" data-order-item-payload-baseline="<?php echo (int) $fieldId; ?>">
		<?php endforeach; ?>
		<?php echo HTMLHelper::_('form.token'); ?>
        <input type="hidden" name="task" value="" />
        <input type="hidden" name="id" value="<?php echo $itemID; ?>" />
        <input type="hidden" name="id_order" value="<?php echo $orderID; ?>" />
    </form>
</div>
