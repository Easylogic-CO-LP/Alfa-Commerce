<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The builder's placement card: what will be placed, and the intent to place it.
 *
 * EVERYTHING COMMERCIAL WAS ALREADY DECIDED. The items, the customer, the two
 * addresses and the two methods belong to the live cart, chosen on the tabs
 * beside this one and settled by the engine. This card STATES them so an
 * operator can check what they are about to commit, and it offers as INPUT only
 * the two things that are genuinely placement intent: the status the order
 * should land in, and whether to spend the customer's credit.
 *
 * That distinction is the whole point. An earlier version of this form carried
 * the customer, the addresses and the two method ids as fields, which made the
 * last-rendered page an authority on state the cart already owned, and made a
 * crafted POST indistinguishable from a stale one. The controller now reads the
 * methods off the cart at the moment of placing and never looks at the request
 * for them, so there is nothing here to tamper with.
 *
 * The merchant's checkout FIELDS are still posted, because placement freezes
 * the address from the typed buckets rather than from the cart's pointers:
 * they arrive seeded from the addresses the cart points at, so what is frozen
 * is what the operator chose.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Cart\HtmlView $this
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Alfa\Component\Alfa\Site\Service\Cart\MethodRequirement;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$snapshot = $this->builderSnapshot;
$customer = $this->builderCustomer;
$cartId   = (int) $this->builderCartId;

if ($snapshot === null || $customer === null || !$this->canOperate) {
    return;
}

$offers = $snapshot->offers;

/** A method's own name, from the offers that named it. */
$methodName = static function (array $offered, int $id): string {
    foreach ($offered as $method) {
        if ((int) $method->id === $id) {
            return (string) ($method->name ?? $method->type);
        }
    }

    return '';
};

$shipmentName = $methodName($offers->shipments, $offers->selectedShipmentId);
$paymentName  = $methodName($offers->payments, $offers->selectedPaymentId);

// WHAT WOULD STOP THIS BEING PLACED, asked of the same settled reading the
// engine will judge it by. These are shown so the operator can fix them; they
// are not the check; placement makes its own, inside the transaction.
$blockers = [];

if ($snapshot->isEmpty()) {
    $blockers[] = Text::_('COM_ALFA_CART_BUILDER_PLACE_NO_LINES');
}

// THE SAME VERDICT PLACEMENT JUDGES BY, so what is warned about here is what
// would actually be refused. A dimension that does not apply blocks nothing; one
// that applies with nothing eligible blocks whatever the operator does next,
// because no choice exists to make.
if ($offers->shipmentRequirement === MethodRequirement::Unavailable) {
    $blockers[] = Text::_('COM_ALFA_CART_BUILDER_SHIPMENT_NONE_ELIGIBLE');
} elseif ($offers->shipmentRequirement->mustBeNamed() && $offers->selectedShipmentId <= 0) {
    $blockers[] = Text::_('COM_ALFA_CART_BUILDER_PLACE_NO_SHIPMENT');
}

if ($offers->paymentRequirement === MethodRequirement::Unavailable) {
    $blockers[] = Text::_('COM_ALFA_CART_BUILDER_PAYMENT_NONE_ELIGIBLE');
} elseif ($offers->paymentRequirement->mustBeNamed() && $offers->selectedPaymentId <= 0) {
    $blockers[] = Text::_('COM_ALFA_CART_BUILDER_PLACE_NO_PAYMENT');
}
?>

<div class="card mb-3 border-primary">
    <div class="card-header bg-primary text-white">
        <?php echo Text::_('COM_ALFA_CART_CHECKOUT_HEADING'); ?>
    </div>

    <div class="card-body">
        <?php // WHAT WILL BE PLACED: stated, never re-offered. ?>
        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="form-label"><?php echo Text::_('COM_ALFA_CART_DETAIL_OWNER'); ?></div>
                <?php if ($customer->isGuest) : ?>
                    <div class="text-muted"><?php echo Text::_('COM_ALFA_CART_CHECKOUT_GUEST'); ?></div>
                <?php else : ?>
                    <div class="fw-semibold"><?php echo $this->escape($customer->name); ?></div>
                    <div class="small text-muted"><?php echo $this->escape($customer->email); ?></div>
                <?php endif; ?>
            </div>

            <div class="col-md-3">
                <div class="form-label"><?php echo Text::_('COM_ALFA_CART_BUILDER_DELIVERY'); ?></div>
                <?php echo $customer->delivery !== null
                    ? $this->escape(UserInfoHelper::label($customer->delivery))
                    : '<span class="text-muted">' . Text::_('COM_ALFA_CART_BUILDER_ADDRESS_NONE') . '</span>'; ?>
            </div>

            <div class="col-md-3">
                <div class="form-label"><?php echo Text::_('COM_ALFA_CART_BUILDER_INVOICE'); ?></div>
                <?php if ($customer->invoice !== null && !$customer->sameAddress) : ?>
                    <?php echo $this->escape(UserInfoHelper::label($customer->invoice)); ?>
                <?php elseif ($customer->deliveryId > 0) : ?>
                    <span class="text-muted"><?php echo Text::_('COM_ALFA_CART_BUILDER_INVOICE_SAME'); ?></span>
                <?php else : ?>
                    <span class="text-muted"><?php echo Text::_('COM_ALFA_CART_BUILDER_ADDRESS_NONE'); ?></span>
                <?php endif; ?>
            </div>

            <div class="col-md-3">
                <div class="form-label"><?php echo Text::_('COM_ALFA_CART_TOTAL'); ?></div>
                <div class="fs-5 fw-bold"><?php echo $this->escape($snapshot->totals->grand->format()); ?></div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="form-label"><?php echo Text::_('COM_ALFA_CART_CHECKOUT_SHIPMENT'); ?></div>
                <?php if ($shipmentName !== '') : ?>
                    <div class="fw-semibold"><?php echo $this->escape($shipmentName); ?></div>
                <?php elseif ($offers->shipmentRequirement->permitsNone()) : ?>
                    <span class="text-muted"><?php echo Text::_('COM_ALFA_CART_BUILDER_SHIPMENT_NOT_APPLICABLE'); ?></span>
                <?php else : ?>
                    <span class="text-danger"><?php echo Text::_('COM_ALFA_CART_BUILDER_METHOD_NOT_CHOSEN'); ?></span>
                <?php endif; ?>
            </div>

            <div class="col-md-6">
                <div class="form-label"><?php echo Text::_('COM_ALFA_CART_CHECKOUT_PAYMENT'); ?></div>
                <?php if ($paymentName !== '') : ?>
                    <div class="fw-semibold"><?php echo $this->escape($paymentName); ?></div>
                <?php elseif ($offers->paymentRequirement->permitsNone()) : ?>
                    <span class="text-muted"><?php echo Text::_('COM_ALFA_CART_BUILDER_PAYMENT_NOT_APPLICABLE'); ?></span>
                <?php else : ?>
                    <span class="text-danger"><?php echo Text::_('COM_ALFA_CART_BUILDER_METHOD_NOT_CHOSEN'); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php foreach ($blockers as $blocker) : ?>
            <div class="alert alert-warning"><?php echo $this->escape($blocker); ?></div>
        <?php endforeach; ?>

        <form action="<?php echo Route::_('index.php?option=com_alfa'); ?>" method="post"
              name="alfaCartCheckout" id="alfaCartCheckout">

            <?php // THE MERCHANT'S CHECKOUT FIELDS. Placement freezes the address
                  // from these buckets, and they arrive seeded from the rows the
                  // cart points at, which is what carries the operator's choice
                  // into the order. Rendered through the one renderer, so a
                  // merchant's own field appears without this template changing. ?>
            <?php
            $deliveryHtml = $this->checkoutForm !== null
                ? FieldsHelper::renderFieldset($this->checkoutForm, 'user_details')
                : '';
            $billingHtml = $this->checkoutForm !== null
                ? FieldsHelper::renderFieldset($this->checkoutForm, 'user_details_billing')
                : '';
            $invoiceDiffers = !$customer->sameAddress;
            ?>

            <?php if (trim($deliveryHtml) !== '') : ?>
                <fieldset class="adminform">
                    <legend><?php echo Text::_('COM_ALFA_FIELDSET_USER_DETAILS'); ?></legend>
                    <?php echo $deliveryHtml; ?>
                </fieldset>
            <?php endif; ?>

            <?php if (trim($billingHtml) !== '') : ?>
                <?php // An UNTICKED toggle is a meaningful value: invoice to the
                      // delivery address. The engine parks the hidden section's
                      // inputs, so the billing bucket then arrives empty and
                      // placement resolves the invoice to the delivery record. ?>
                <div class="form-check mb-2" data-showon-name="invoice_differs">
                    <input type="checkbox" class="form-check-input" id="alfa-checkout-invoice-differs"
                           name="invoice_differs" value="1"<?php echo $invoiceDiffers ? ' checked' : ''; ?>>
                    <label class="form-check-label" for="alfa-checkout-invoice-differs">
                        <?php echo Text::_('COM_ALFA_CART_INVOICE_DIFFERS'); ?>
                    </label>
                </div>

                <fieldset class="adminform" data-showon-scope<?php echo $invoiceDiffers ? '' : ' hidden'; ?>
                          data-showon-rule='{"match":"all","rules":[{"operand":"invoice_differs.value","operator":"=","value":"1"}],"action":"show"}'>
                    <legend><?php echo Text::_('COM_ALFA_FORM_BILLING_DETAILS'); ?></legend>
                    <?php echo $billingHtml; ?>
                </fieldset>
            <?php endif; ?>

            <hr>

            <div class="row g-3 align-items-end">
                <?php // PLACEMENT INTENT #1: where the order lands. The list is
                      // the merchant's own, narrowed by the one question
                      // OrderCreator::mayCreateInto() answers: this template
                      // states no status rule of its own, and an id that stops
                      // being acceptable between here and the commit is still
                      // policed there. ?>
                <div class="col-md-5">
                    <label class="form-label" for="alfa-checkout-status">
                        <?php echo Text::_('COM_ALFA_CART_BUILDER_PLACE_STATUS'); ?>
                    </label>
                    <select class="form-select" name="order_status" id="alfa-checkout-status">
                        <?php foreach ($this->placementStatuses as $status) : ?>
                            <option value="<?php echo (int) $status->id; ?>"
                                    <?php echo (int) $status->is_initial === 1 ? 'selected' : ''; ?>>
                                <?php echo $this->escape((string) $status->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php // PLACEMENT INTENT #2: whether to spend the balance. ?>
                <?php if ($customer->credit !== null) : ?>
                    <div class="col-md-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1"
                                   name="use_credit" id="alfa-checkout-credit">
                            <label class="form-check-label" for="alfa-checkout-credit">
                                <?php echo Text::sprintf(
                                    'COM_ALFA_CART_CHECKOUT_USE_CREDIT',
                                    $this->escape((string) $customer->credit),
                                ); ?>
                            </label>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($this->isShopperCart) : ?>
                    <?php // A SHOPPER'S CART IS CONSUMED BY THIS. The consequence is
                          // stated where the operator is standing, and the tick is the
                          // deliberate act. It is a browser-side safeguard only: the
                          // server asks Carts: Edit either way and never reads this
                          // input. It sits INSIDE the form, because a modal <dialog>
                          // makes the form behind it inert and a required checkout
                          // field would then fail validation against a control the
                          // browser cannot focus. ?>
                    <div class="col-12">
                        <div class="alert alert-warning mb-0">
                            <p class="mb-2"><?php echo Text::_('COM_ALFA_CART_SHOPPER_CART_NOTICE'); ?></p>
                            <p class="fw-semibold"><?php echo Text::_('COM_ALFA_CART_PLACE_SHOPPER_CONFIRM'); ?></p>
                            <div class="form-check mb-0">
                                <input type="checkbox" class="form-check-input" id="alfa-place-shopper-ack"
                                       name="place_shopper_ack" value="1" required>
                                <label class="form-check-label" for="alfa-place-shopper-ack">
                                    <?php echo Text::_('COM_ALFA_CART_PLACE_SHOPPER_ACK'); ?>
                                </label>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="col-md-3 text-end">
                    <button type="submit" class="btn btn-lg <?php echo $this->isShopperCart ? 'btn-danger' : 'btn-primary'; ?>" data-alfa-place-once>
                        <span class="icon-checkbox" aria-hidden="true"></span>
                        <?php echo Text::_($this->isShopperCart ? 'COM_ALFA_CART_PLACE_SHOPPER_SUBMIT' : 'COM_ALFA_CART_CHECKOUT_PLACE'); ?>
                    </button>
                </div>
            </div>

            <input type="hidden" name="task" value="cart.checkout">
            <input type="hidden" name="cart_id" value="<?php echo $cartId; ?>">
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>
    </div>
</div>

<script>
    // ERGONOMICS ONLY. The server is what stops a second order: placement takes
    // a row lock on the cart and consumes it inside the same transaction, so a
    // replay finds nothing to place. This just spares the operator a confusing
    // second submit while the first is in flight, and is not relied upon.
    (function () {
        'use strict';

        var form = document.getElementById('alfaCartCheckout');

        if (!form) {
            return;
        }

        form.addEventListener('submit', function () {
            var button = form.querySelector('[data-alfa-place-once]');

            if (button) {
                window.setTimeout(function () { button.disabled = true; }, 0);
            }
        });
    }());
</script>
