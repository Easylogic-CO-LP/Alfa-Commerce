<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The builder's Items section: the cart's lines, the coupon and the totals, in one table.
 *
 * Every line with its chosen configuration, its quantity, its unit price with and without tax, its tax, what a rule
 * took off it and what it comes to, and an Edit action opening the line in the popup, where quantity, configuration
 * and Remove live beside the thing they act on; then the goods before and after rules, each applied rule by name (a
 * code with its remove button), tax, the carrier's price with the engine's shipping verdict, every plugin
 * contribution, and the grand total with and without tax. Below the table, the coupon box.
 *
 * The logic is the storefront's (the same settled snapshot, the same fields, the same order), the layout the back
 * office's own. Nothing is added, multiplied or compared here: every figure is read off the line's own priced result
 * or off the snapshot's totals, and free shipping is the engine's classification. A mutation is a form post to a
 * controller task, and the page then re-renders: the browser never edits a line, computes a price or rebuilds a row.
 *
 * The line's configuration, discount and total are layouts (cart.line_selection, cart.line_discount,
 * cart.line_total), the last two shared with the popup's quote.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Cart\HtmlView $this
 *
 * @since  2.3.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Site\Helper\PriceUnits;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

$snapshot = $this->builderSnapshot;

if ($snapshot === null) {
    echo '<div class="alert alert-warning">' . Text::_('COM_ALFA_CART_NOT_FOUND') . '</div>';

    return;
}

$totals   = $snapshot->totals;
$shipping = $totals->shippingBenefit;
$cartId   = (int) $this->builderCartId;
// The line layouts, found by component so an administrator template's html/layouts/com_alfa/cart/ overrides them.
$layouts  = ['component' => 'com_alfa', 'client' => 1];

// The line popup and every line mutation ask carts checkout, and Carts: Edit on a cart a storefront shopper created;
// without them the Edit action is the column's only content, so the column is dropped with it.
$columns = $this->canOperate ? 8 : 7;

// Everything left of the discount column, for the total rows' label cell.
$labelSpan = 5;

// The em dash is what an unpriced line reads: a line whose item left the catalogue has no offer to price, so no money
// column applies to it.
$dash = '<span class="text-muted">&mdash;</span>';

/** One popup URL, for adding (line 0) or for re-configuring an existing line. */
$popupUrl = static fn (int $lineId): string => Route::_(sprintf(
    'index.php?option=com_alfa&view=cart&layout=builder_line&tmpl=component&cart_id=%d&line_id=%d',
    $cartId,
    $lineId,
));
?>

<?php if ($snapshot->isEmpty()) : ?>
    <div class="alert alert-info"><?php echo Text::_('COM_ALFA_CART_BUILDER_ITEMS_EMPTY'); ?></div>
<?php else : ?>
<div class="card mb-3">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-striped mb-0 align-middle alfa-builder-statement">
            <thead>
                <tr>
                    <th><?php echo Text::_('COM_ALFA_CART_TABLE_NAME'); ?></th>
                    <th class="text-center"><?php echo Text::_('COM_ALFA_CART_TABLE_QUANTITY'); ?></th>
                    <th class="text-end"><?php echo Text::_('COM_ALFA_CART_BUILDER_COL_UNIT_EXCL'); ?></th>
                    <th class="text-end"><?php echo Text::_('COM_ALFA_CART_BUILDER_COL_UNIT_INCL'); ?></th>
                    <th class="text-end"><?php echo Text::_('COM_ALFA_CART_TABLE_TAX'); ?></th>
                    <th class="text-end"><?php echo Text::_('COM_ALFA_CART_TABLE_DISCOUNT'); ?></th>
                    <th class="text-end"><?php echo Text::_('COM_ALFA_CART_TABLE_TOTAL'); ?></th>
                    <?php if ($this->canOperate) : ?>
                        <th><span class="visually-hidden"><?php echo Text::_('COM_ALFA_ACTIONS'); ?></span></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($snapshot->lines as $line) :
                $lineId      = (int) $line->id;
                $modalId     = 'cartLineModal-' . $lineId;
                $unavailable = !empty($line->unavailable);

                // The line's own priced result. A stranded line has none, which is exactly why it contributes nothing
                // to any total.
                $price = $line->data->price ?? null;
                $price = \is_object($price) ? $price : null;

                // An item with no name in this language is still a line an operator must be able to see and act on.
                $lineName = (string) ($line->data->name ?? '') !== ''
                    ? (string) $line->data->name
                    : Text::sprintf('COM_ALFA_CART_DETAIL_ITEM_ID', (int) $line->id_item);
                ?>
                <tr class="<?php echo $unavailable ? 'table-warning' : ''; ?>" data-line-id="<?php echo $lineId; ?>">
                    <td>
                        <div class="fw-semibold"><?php echo $this->escape($lineName); ?></div>

                        <?php echo LayoutHelper::render(
                            'cart.line_selection',
                            ['selections' => $this->builderSelections[$lineId] ?? []],
                            null,
                            $layouts,
                        ); ?>

                        <?php if ($unavailable) : ?>
                            <div class="small mt-1">
                                <span class="badge bg-danger"><?php echo Text::_('COM_ALFA_CART_DETAIL_ITEM_UNAVAILABLE'); ?></span>
                                <span class="text-danger"><?php echo $this->escape($line->unavailable_reason ?? ''); ?></span>
                            </div>
                        <?php endif; ?>
                    </td>

                    <td class="text-center"><?php echo (int) $line->quantity; ?></td>

                    <?php // Each unit by the one unit rule, from the line's own whole amount and quantity; on a
                          // negotiated line the gross unit is the agreed rate. ?>
                    <td class="text-end"><?php echo $price !== null ? $this->escape(PriceUnits::line(price: $price, withTax: false)) : $dash; ?></td>
                    <td class="text-end"><?php echo $price !== null ? $this->escape(PriceUnits::line(price: $price, withTax: true)) : $dash; ?></td>
                    <td class="text-end"><?php echo $price !== null ? $this->escape($price->getTaxTotal()->format()) : $dash; ?></td>

                    <td class="text-end">
                        <?php echo $price !== null
                            ? LayoutHelper::render('cart.line_discount', ['price' => $price], null, $layouts)
                            : $dash; ?>
                    </td>

                    <td class="text-end fw-semibold">
                        <?php echo $price !== null
                            ? LayoutHelper::render('cart.line_total', ['price' => $price], null, $layouts)
                            : $dash; ?>
                    </td>

                    <?php // The builder_line popup (403 otherwise) and the line mutations ask carts checkout, and
                          // Carts: Edit on a shopper's cart. ?>
                    <?php if ($this->canOperate) : ?>
                        <td class="text-end">
                            <a href="#<?php echo $modalId; ?>" data-bs-toggle="modal" role="button"
                               class="btn btn-sm btn-primary">
                                <span class="icon-edit" aria-hidden="true"></span>
                                <?php echo Text::_('JACTION_EDIT'); ?>
                            </a>

                            <?php echo HTMLHelper::_(
                                'bootstrap.renderModal',
                                $modalId,
                                [
                                    'title'      => Text::_('COM_ALFA_CART_BUILDER_LINE_EDIT')
                                        . ' - ' . $this->escape($lineName),
                                    'url'        => $popupUrl($lineId),
                                    'width'      => '80%',
                                    'bodyHeight' => '70vh',
                                ],
                            ); ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>

            <tfoot>
                <tr class="border-top">
                    <th colspan="<?php echo $labelSpan; ?>" class="text-end fw-normal">
                        <?php echo Text::_('COM_ALFA_CART_BUILDER_GOODS_SUBTOTAL'); ?>
                    </th>
                    <th class="text-end fw-normal">
                        <?php echo $totals->discount->isZero()
                            ? '-'
                            : '<span class="text-success">&minus;' . $this->escape($totals->discount->format()) . '</span>'; ?>
                    </th>
                    <th class="text-end">
                        <?php if (!$totals->discount->isZero()) : ?>
                            <div class="text-muted text-decoration-line-through small">
                                <?php echo $this->escape($totals->beforeDiscount->format()); ?>
                            </div>
                        <?php endif; ?>
                        <?php echo $this->escape($totals->goods->format()); ?>
                    </th>
                    <?php if ($this->canOperate) : ?><td></td><?php endif; ?>
                </tr>

                <?php // Each rule that actually applied, by name; the shipping rule speaks on the shipping row
                      // instead. ?>
                <?php foreach ($snapshot->rules->applied as $rule) : ?>
                    <?php if ($rule->discountClass === 'shipping') {
                        continue;
                    } ?>
                    <tr class="small">
                        <td colspan="<?php echo $labelSpan + 1; ?>" class="text-end ps-4">
                            <?php echo $this->escape($rule->name); ?>

                            <?php // A rule with no code applied by itself: the cart qualified for it. There is nothing
                                  // to take off, so no control is offered: removing it means changing the cart until it
                                  // no longer qualifies. ?>
                            <?php if ($rule->code === null) : ?>
                                <span class="badge bg-light text-dark border"><?php echo Text::_('COM_ALFA_CART_BUILDER_RULE_AUTOMATIC'); ?></span>
                            <?php else : ?>
                                <span class="badge text-bg-info"><?php echo $this->escape($rule->code); ?></span>

                                <?php if ($this->canOperate) : ?>
                                <form action="<?php echo Route::_('index.php?option=com_alfa'); ?>" method="post" class="d-inline">
                                    <input type="hidden" name="task" value="cart.removeCoupon">
                                    <input type="hidden" name="form" value="page">
                                    <input type="hidden" name="section" value="lines">
                                    <input type="hidden" name="cart_id" value="<?php echo $cartId; ?>">
                                    <input type="hidden" name="code" value="<?php echo $this->escape($rule->code); ?>">
                                    <?php echo HTMLHelper::_('form.token'); ?>
                                    <button type="submit" class="btn btn-sm btn-link text-danger p-0 align-baseline"
                                            title="<?php echo $this->escape(Text::sprintf('COM_ALFA_CART_COUPON_REMOVE', $rule->code)); ?>">&times;</button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-success">
                            &minus;<?php echo $this->escape($rule->discountTaxInclMoney($snapshot->currency)->format()); ?>
                        </td>
                        <?php if ($this->canOperate) : ?><td></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>

                <tr>
                    <td colspan="<?php echo $labelSpan + 1; ?>" class="text-end"><?php echo Text::_('COM_ALFA_CART_TABLE_TAX'); ?></td>
                    <td class="text-end"><?php echo $this->escape($totals->tax->format()); ?></td>
                    <?php if ($this->canOperate) : ?><td></td><?php endif; ?>
                </tr>

                <tr>
                    <td colspan="<?php echo $labelSpan + 1; ?>" class="text-end"><?php echo Text::_('COM_ALFA_CART_SHIPPING'); ?></td>
                    <td class="text-end">
                        <?php // Free shipping is a shipping benefit the engine classified, never a negative line
                              // beside a full charge. This row renders that verdict; it compares nothing. ?>
                        <?php if ($shipping->benefit === 'free_shipping') : ?>
                            <div class="text-muted text-decoration-line-through small">
                                <?php echo $this->escape(Money::of($shipping->cost, $snapshot->currency)->format()); ?>
                            </div>
                            <strong class="text-success"><?php echo Text::_('COM_ALFA_FREE_SHIPPING'); ?></strong>
                        <?php elseif ($shipping->benefit === 'partial') : ?>
                            <div class="text-muted text-decoration-line-through small">
                                <?php echo $this->escape(Money::of($shipping->cost, $snapshot->currency)->format()); ?>
                            </div>
                            <?php echo $this->escape($totals->shipment->format()); ?>
                        <?php else : ?>
                            <?php echo $this->escape($totals->shipment->format()); ?>
                        <?php endif; ?>
                    </td>
                    <?php if ($this->canOperate) : ?><td></td><?php endif; ?>
                </tr>

                <?php foreach ($totals->contributions as $contribution) : ?>
                    <tr>
                        <td colspan="<?php echo $labelSpan + 1; ?>" class="text-end"><?php echo $this->escape($contribution->label); ?></td>
                        <td class="text-end"><?php echo $this->escape($contribution->amount_tax_incl_money->format()); ?></td>
                        <?php if ($this->canOperate) : ?><td></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>

                <?php // A plugin of the customer's that cannot run for this operator: its charges are missing from
                      // these totals and the placement refuses, so the operator reads why here, with the totals. ?>
                <?php foreach ($totals->contributionsNotLoaded as $notLoaded) : ?>
                    <tr class="table-warning">
                        <td colspan="<?php echo $columns; ?>"><?php echo Text::sprintf('COM_ALFA_PLUGIN_NOT_LOADED_FOR_OPERATOR', $this->escape(AlfaPluginHelper::get()->name(PluginId::of((string) $notLoaded)))); ?></td>
                    </tr>
                <?php endforeach; ?>

                <tr class="border-top">
                    <th colspan="<?php echo $labelSpan + 1; ?>" class="text-end"><?php echo Text::_('COM_ALFA_CART_TOTAL'); ?></th>
                    <th class="text-end fs-5"><?php echo $this->escape($totals->grand->format()); ?></th>
                    <?php if ($this->canOperate) : ?><td></td><?php endif; ?>
                </tr>

                <tr class="small text-muted">
                    <td colspan="<?php echo $labelSpan + 1; ?>" class="text-end"><?php echo Text::_('COM_ALFA_CART_BUILDER_GRAND_EXCL'); ?></td>
                    <td class="text-end"><?php echo $this->escape($totals->grandExcl->format()); ?></td>
                    <?php if ($this->canOperate) : ?><td></td><?php endif; ?>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php // A code the operator enters on the customer's behalf. It goes through the same editor the storefront's own
      // coupon box goes through, so a code that this customer may not use is refused here for the same reason. ?>
<?php if ($this->canOperate) : ?>
<form action="<?php echo Route::_('index.php?option=com_alfa'); ?>" method="post" class="row g-2 align-items-end mb-3">
    <div class="col-auto">
        <label class="form-label" for="alfa-builder-coupon"><?php echo Text::_('COM_ALFA_CART_COUPON_LABEL'); ?></label>
        <input type="text" class="form-control" id="alfa-builder-coupon" name="code"
               placeholder="<?php echo Text::_('COM_ALFA_CART_COUPON_PLACEHOLDER'); ?>" required>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-secondary"><?php echo Text::_('COM_ALFA_CART_COUPON_APPLY'); ?></button>
    </div>

    <input type="hidden" name="task" value="cart.applyCoupon">
    <input type="hidden" name="form" value="page">
    <input type="hidden" name="section" value="lines">
    <input type="hidden" name="cart_id" value="<?php echo $cartId; ?>">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
<?php endif; ?>

<?php // The Add popup: the same layout, opened on no line (line 0). The section heading carries its button. ?>
<?php if ($this->canOperate) : ?>
    <?php echo HTMLHelper::_(
        'bootstrap.renderModal',
        'addCartLineModal',
        [
            'title'      => Text::_('COM_ALFA_CART_BUILDER_LINE_NEW'),
            'url'        => $popupUrl(0),
            'width'      => '80%',
            'bodyHeight' => '70vh',
        ],
    ); ?>
<?php endif; ?>

<?php // Every modal this handler drives is drawn above only for an operator who
      // may work the cart, so the handler itself belongs to that operator too. ?>
<?php if ($this->canOperate) : ?>
<script>
    /**
     * Cart Line Modal Communication Handler
     *
     * Listens for the popup's return page, exactly as the Order screen listens
     * for its own item popup: close the modal it came from, then re-render the
     * page so what is on screen is server-rendered state.
     */
    (function () {
        'use strict';

        window.addEventListener('message', function (event) {
            if (event.origin !== window.location.origin) {
                return;
            }

            if (!event.data || event.data.messageType !== 'alfa:cart-line-action') {
                return;
            }

            if (event.data.shouldClose) {
                closeModal(parseInt(event.data.lineId, 10) || 0);
            }

            if (event.data.shouldReload) {
                reloadBuilder();
            }
        });

        function closeModal(lineId) {
            // The popup reports the line it was OPENED on, so 0 is the Add popup.
            var modalEl = document.getElementById(lineId === 0 ? 'addCartLineModal' : 'cartLineModal-' + lineId);
            var bsModal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;

            if (bsModal) {
                bsModal.hide();
            }
        }

        function reloadBuilder() {
            // The builder reads its cart from the server on every render, so a
            // re-render IS the refresh: there is no unsaved form state to post
            // back the way the order edit screen has.
            if (window.Alfa && Alfa.loader && typeof Alfa.loader.showThen === 'function') {
                // Painted first, then navigated; see Alfa.loader.showThen().
                Alfa.loader.showThen(function () { window.location.reload(); });

                return;
            }

            window.location.reload();
        }
    }());
</script>
<?php endif; ?>
