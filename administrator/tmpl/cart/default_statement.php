<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The cart, stated in full: one analytical table, line by line.
 *
 * The back office's OWN layout for the money the shopper's cart states: every
 * line with its chosen configuration, its quantity, its unit price with and
 * without tax, its tax, what a rule took off it and what it comes to; then the
 * goods before and after rules, each applied rule by name, tax, the carrier's
 * price with the engine's shipping verdict, every plugin contribution, and the
 * grand total with and without tax.
 *
 * The LOGIC is the storefront's (the same settled snapshot, the same fields,
 * the same order), but this is the back office's file and the storefront's own
 * templates are neither used nor touched. Nothing is added, multiplied or
 * compared here: every figure is read off the line's own priced result or off
 * the snapshot's totals, and free shipping is the engine's classification, not
 * a comparison this template makes.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Cart\HtmlView $this
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\PriceUnits;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$snapshot = $this->builderSnapshot;

if ($snapshot === null) {
    echo '<div class="alert alert-warning m-3">' . Text::_('COM_ALFA_CART_NOT_FOUND') . '</div>';

    return;
}

$totals   = $snapshot->totals;
$shipping = $totals->shippingBenefit;

// The em dash is what an UNPRICED line reads: a line whose item left the
// catalogue has no offer to price, so no money column applies to it.
$dash = '<span class="text-muted">&mdash;</span>';

// Everything left of the money columns, for the total rows' label cell.
$labelSpan = 5;

$cartId = (int) $this->builderCartId;
?>

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
        </tr>
    </thead>

    <tbody>
    <?php if ($snapshot->isEmpty()) : ?>
        <tr>
            <td colspan="7" class="text-muted"><?php echo Text::_('COM_ALFA_CART_DETAIL_NO_LINES'); ?></td>
        </tr>
    <?php endif; ?>

    <?php foreach ($snapshot->lines as $line) :
        $lineId      = (int) $line->id;
        $unavailable = !empty($line->unavailable);
        $price       = $line->data->price ?? null;
        $price       = \is_object($price) ? $price : null;

        $lineName = (string) ($line->data->name ?? '') !== ''
            ? (string) $line->data->name
            : Text::sprintf('COM_ALFA_CART_DETAIL_ITEM_ID', (int) $line->id_item);

        // The one semantic configuration renderer, from the same read model the
        // Items tab prints.
        $this->lineSelections = $this->builderSelections[$lineId] ?? [];
        ?>
        <tr class="<?php echo $unavailable ? 'table-warning' : ''; ?>">
            <td>
                <div class="fw-semibold"><?php echo $this->escape($lineName); ?></div>

                <?php echo $this->loadTemplate('selection'); ?>

                <?php if ($unavailable) : ?>
                    <div class="small mt-1">
                        <span class="badge bg-danger"><?php echo Text::_('COM_ALFA_CART_DETAIL_ITEM_UNAVAILABLE'); ?></span>
                        <span class="text-danger"><?php echo $this->escape($line->unavailable_reason ?? ''); ?></span>
                    </div>
                <?php endif; ?>
            </td>

            <td class="text-center"><?php echo (int) $line->quantity; ?></td>

            <?php // Each unit by the one unit rule, from the line's own whole amount and
                  // quantity; on a negotiated line the gross unit is the agreed rate. ?>
            <td class="text-end"><?php echo $price !== null ? $this->escape(PriceUnits::line(price: $price, withTax: false)) : $dash; ?></td>
            <td class="text-end"><?php echo $price !== null ? $this->escape(PriceUnits::line(price: $price, withTax: true)) : $dash; ?></td>
            <td class="text-end"><?php echo $price !== null ? $this->escape($price->getTaxTotal()->format()) : $dash; ?></td>

            <td class="text-end">
                <?php // '-' means priced with no discount; the em dash means
                      // unpriced, where the column does not apply at all. ?>
                <?php if ($price === null) : ?>
                    <?php echo $dash; ?>
                <?php elseif ($price->hasDiscount()) : ?>
                    <span class="text-success">&minus;<?php echo $this->escape($price->getSavingsTotal()->format()); ?></span>
                <?php elseif ($price->hasExplicitPrice()) : ?>
                    <?php // ITS OWN SURFACE, NOT THE DISCOUNT ONE. A negotiated
                          // price is not a promotional adjustment, and the ruling
                          // requires all three concepts to stay distinguishable:
                          // individual promotional adjustments, the price
                          // override, and the aggregate whole-line saving.
                          //
                          // The saving is READ OFF THE RESULT, never computed
                          // here: subtracting two Money objects in a view is
                          // what two standing negative arms forbid, and the
                          // result already carries the floored figure. ?>
                    <span class="badge bg-info-subtle text-info-emphasis">
                        <?php echo $this->escape(Text::_('COM_ALFA_PRICE_CUSTOM_LINE_SAVING')); ?>
                        &minus;<?php echo $this->escape($price->getLineSavingWithTax()->format()); ?>
                    </span>
                <?php else : ?>
                    -
                <?php endif; ?>
            </td>

            <td class="text-end fw-semibold">
                <?php if ($price === null) : ?>
                    <?php echo $dash; ?>
                <?php else : ?>
                    <?php if ($price->hasDiscount()) : ?>
                        <div class="text-muted text-decoration-line-through small">
                            <?php echo $this->escape($price->getBaseTotalWithTax()->format()); ?>
                        </div>
                    <?php elseif ($price->hasExplicitPrice()) : ?>
                        <?php // The CANONICAL amount struck through (what the
                              // ordinary pricing would have charged), with the
                              // operator's figure below it. getReference() is
                              // that amount; getBaseTotalWithTax() is the
                              // UNDISCOUNTED one, and on a line that carried a
                              // catalogue sale before it was negotiated those
                              // are different numbers. ?>
                        <div class="text-muted text-decoration-line-through small">
                            <?php echo $this->escape($price->getReference()->getTotal()->format()); ?>
                        </div>
                    <?php endif; ?>
                    <?php echo $this->escape($price->getTotal()->format()); ?>
                <?php endif; ?>
            </td>
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
        </tr>

        <?php // Each rule that actually applied, by name; the shipping rule
              // speaks on the shipping row instead. ?>
        <?php foreach ($snapshot->rules->applied as $rule) : ?>
            <?php if ($rule->discountClass === 'shipping') {
                continue;
            } ?>
            <tr class="small">
                <td colspan="<?php echo $labelSpan + 1; ?>" class="text-end ps-4">
                    <?php echo $this->escape($rule->name); ?>

                    <?php // A rule with no code applied by itself: the cart
                          // qualified for it. There is nothing to take off, so
                          // no control is offered: removing it means changing
                          // the cart until it no longer qualifies. ?>
                    <?php if ($rule->code === null) : ?>
                        <span class="badge bg-light text-dark border"><?php echo Text::_('COM_ALFA_CART_BUILDER_RULE_AUTOMATIC'); ?></span>
                    <?php else : ?>
                        <span class="badge bg-info text-dark"><?php echo $this->escape($rule->code); ?></span>

                        <?php // Taking a code off is a builder mutation; the badge stays. ?>
                        <?php if ($this->canOperate) : ?>
                        <form action="<?php echo Route::_('index.php?option=com_alfa'); ?>" method="post" class="d-inline">
                            <input type="hidden" name="task" value="cart.removeCoupon">
                            <input type="hidden" name="form" value="page">
                            <input type="hidden" name="cart_id" value="<?php echo $cartId; ?>">
                            <input type="hidden" name="code" value="<?php echo $this->escape($rule->code); ?>">
                            <?php echo HTMLHelper::_('form.token'); ?>
                            <button type="submit" class="btn btn-sm btn-link text-danger p-0 align-baseline"
                                    title="<?php echo Text::_('COM_ALFA_CART_COUPON_REMOVE'); ?>">&times;</button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="text-end text-success">
                    &minus;<?php echo $this->escape($rule->discountTaxInclMoney($snapshot->currency)->format()); ?>
                </td>
            </tr>
        <?php endforeach; ?>

        <tr>
            <td colspan="<?php echo $labelSpan + 1; ?>" class="text-end"><?php echo Text::_('COM_ALFA_CART_TABLE_TAX'); ?></td>
            <td class="text-end"><?php echo $this->escape($totals->tax->format()); ?></td>
        </tr>

        <tr>
            <td colspan="<?php echo $labelSpan + 1; ?>" class="text-end"><?php echo Text::_('COM_ALFA_CART_SHIPPING'); ?></td>
            <td class="text-end">
                <?php // Free shipping is a shipping BENEFIT the engine
                      // classified, never a negative line beside a full charge.
                      // This row renders that verdict; it compares nothing. ?>
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
        </tr>

        <?php foreach ($totals->contributions as $contribution) : ?>
            <tr>
                <td colspan="<?php echo $labelSpan + 1; ?>" class="text-end"><?php echo $this->escape($contribution->label); ?></td>
                <td class="text-end"><?php echo $this->escape($contribution->amount_tax_incl_money->format()); ?></td>
            </tr>
        <?php endforeach; ?>

        <tr class="border-top">
            <th colspan="<?php echo $labelSpan + 1; ?>" class="text-end"><?php echo Text::_('COM_ALFA_CART_TOTAL'); ?></th>
            <th class="text-end fs-5"><?php echo $this->escape($totals->grand->format()); ?></th>
        </tr>

        <tr class="small text-muted">
            <td colspan="<?php echo $labelSpan + 1; ?>" class="text-end"><?php echo Text::_('COM_ALFA_CART_BUILDER_GRAND_EXCL'); ?></td>
            <td class="text-end"><?php echo $this->escape($totals->grandExcl->format()); ?></td>
        </tr>
    </tfoot>
</table>

<?php // A code the operator enters on the customer's behalf. It goes through the
      // SAME editor the storefront's own coupon box goes through, so a code that
      // this customer may not use is refused here for the same reason. ?>
<?php if ($this->canOperate) : ?>
<div class="p-3 border-top">
    <form action="<?php echo Route::_('index.php?option=com_alfa'); ?>" method="post" class="row g-2 align-items-end">
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
        <input type="hidden" name="cart_id" value="<?php echo $cartId; ?>">
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>
</div>
<?php endif; ?>
