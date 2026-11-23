<?php

/**
 * Order Edit: Coupons card
 *
 * The codes on the order, each with what it takes off and a Remove button, and a code field that adds a code or puts
 * it in place of one the order holds. Drawn beside the totals, which follow at once. The buttons post the sibling form
 * alfa-order-coupon-form (edit.php): the order form cannot hold a nested form, and its empty task would beat the
 * buttons' own.
 *
 * An order holding a discount frozen before shares were kept shows its codes and says why they stay as they are.
 *
 * Path: administrator/components/com_alfa/tmpl/order/edit_coupons.php
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  2.3.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$order = $this->order;

// The order was read without its money (no currency): there is nothing to show.
if (!isset($order->coupons)) {
    return;
}

$held      = $order->coupons;
$canChange = $held->changeable && AlfaHelper::can(section: 'orders', action: 'edit', user: $this->getCurrentUser());
// Each button names its task in its formaction.
$action    = static fn (string $task): string => Route::_('index.php?option=com_alfa', false) . '&id_order=' . (int) $order->id . '&task=' . $task;
?>

<div class="col-lg-6 col-md-12 mb-3 mb-lg-0" id="alfa-order-coupons">
    <div class="card shadow-sm">
        <div class="card-header bg-body-tertiary py-2">
            <h5 class="card-title mb-0">
                <span class="icon-tag me-2" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_ORDER_COUPONS'); ?>
            </h5>
        </div>

        <div class="card-body">
            <?php if ($held->coupons === []) : ?>
                <p class="text-muted mb-3"><?php echo Text::_('COM_ALFA_ORDER_COUPONS_NONE'); ?></p>
            <?php else : ?>
                <table class="table table-sm mb-3">
                    <tbody>
                    <?php foreach ($held->coupons as $coupon) : ?>
                        <tr>
                            <td class="ps-0">
                                <code><?php echo $this->escape($coupon->code); ?></code>
                                <div class="small text-muted"><?php echo $this->escape($coupon->name); ?></div>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php if ((int) $coupon->free_shipping === 1) : ?>
                                    <?php echo Text::_('COM_ALFA_FREE_SHIPPING'); ?>
                                <?php else : ?>
                                    −<?php echo Money::of((float) $coupon->value_tax_incl, $order->currency)->format(); ?>
                                <?php endif; ?>
                            </td>
                            <?php if ($canChange) : ?>
                                <td class="text-end pe-0">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" form="alfa-order-coupon-form" formnovalidate
                                            formaction="<?php echo $this->escape($action('order.removeCoupon') . '&id_order_cart_rule=' . (int) $coupon->id_order_cart_rule); ?>">
                                        <?php echo Text::_('COM_ALFA_ORDER_COUPON_REMOVE'); ?>
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ($canChange) : ?>
                <div class="row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label" for="alfa-order-coupon-code"><?php echo Text::_('COM_ALFA_CART_COUPON_LABEL'); ?></label>
                        <input type="text" class="form-control form-control-sm" id="alfa-order-coupon-code" name="code"
                               form="alfa-order-coupon-form" autocomplete="off"
                               placeholder="<?php echo Text::_('COM_ALFA_CART_COUPON_PLACEHOLDER'); ?>">
                    </div>
                    <?php if ($held->coupons !== []) : ?>
                        <div class="col-auto">
                            <label class="form-label" for="alfa-order-coupon-replaces"><?php echo Text::_('COM_ALFA_ORDER_COUPON_REPLACES'); ?></label>
                            <select class="form-select form-select-sm" id="alfa-order-coupon-replaces" name="replaces" form="alfa-order-coupon-form">
                                <option value="0"><?php echo Text::_('COM_ALFA_ORDER_COUPON_REPLACES_NONE'); ?></option>
                                <?php foreach ($held->coupons as $coupon) : ?>
                                    <option value="<?php echo (int) $coupon->id_order_cart_rule; ?>"><?php echo $this->escape($coupon->code); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-secondary" form="alfa-order-coupon-form" formaction="<?php echo $this->escape($action('order.addCoupon')); ?>">
                            <?php echo Text::_('COM_ALFA_CART_COUPON_APPLY'); ?>
                        </button>
                    </div>
                </div>
                <p class="form-text mb-0 mt-2"><?php echo Text::_('COM_ALFA_ORDER_COUPONS_DOCUMENTS_NOTE'); ?></p>
            <?php elseif (!$held->changeable) : ?>
                <p class="form-text mb-0"><?php echo Text::_('COM_ALFA_ORDER_COUPONS_SHARES_NOT_KEPT'); ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>
