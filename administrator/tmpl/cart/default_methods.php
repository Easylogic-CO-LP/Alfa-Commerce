<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The builder's Shipment & payment tab.
 *
 * A READING, not a decision. Every method on this screen is one the ENGINE
 * offered for THIS cart ({@see \Alfa\Component\Alfa\Site\Service\Cart\CartMethods},
 * settled through CheckoutCart), and the tab renders that answer without asking
 * a question of its own. It runs no query, consults no plugin table, and repeats
 * none of the eligibility rules: published is not eligible, and a back office
 * that decided otherwise would be a second engine disagreeing with the shop.
 *
 * The three states a section can be in all come from the same projection, and
 * they mean different things:
 *
 *   NOT APPLICABLE: the dimension is not part of this checkout at all. Nothing
 *   in this cart needs carrying, or the shop takes no payment for it. Placement
 *   asks for no method, and refuses one if it is offered anyway.
 *
 *   NONE ELIGIBLE: the dimension applies but nothing serves this cart, usually
 *   its destination. That is a real problem the operator must see, because
 *   placement will refuse it; it is never papered over with the published list.
 *
 *   OFFERED: the methods, exactly as the storefront would show them, each with
 *   whatever its own plugin contributed.
 *
 * Selected state is the CART's, read back after settlement: reconciliation may
 * already have dropped a choice that stopped being eligible, or taken the only
 * one left, and the engine's own notice says so. Nothing here trusts what was
 * submitted.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Cart\HtmlView $this
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\PluginLayoutHelper;
use Alfa\Component\Alfa\Site\Service\Cart\MethodRequirement;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$snapshot = $this->builderSnapshot;
$cartId   = (int) $this->builderCartId;

if ($snapshot === null) {
    echo '<div class="alert alert-warning">' . Text::_('COM_ALFA_CART_NOT_FOUND') . '</div>';

    return;
}

$offers = $snapshot->offers;

/**
 * One method section. The two kinds differ only in their words and their task;
 * the contract they render is identical, so they are rendered by one piece of
 * markup rather than two that can drift apart.
 */
$section = function (
    string $kind,
    array $offered,
    MethodRequirement $requirement,
    int $selectedId,
    string $task,
    string $idField,
    string $heading,
    string $notApplicable,
    string $noneEligible
) use ($offers, $cartId): void {
    ?>
    <div class="card mb-3">
        <div class="card-header"><?php echo Text::_($heading); ?></div>

        <div class="card-body">
            <?php // The ENGINE's own words about a choice it had to change. ?>
            <?php foreach ($offers->notices($kind) as $notice) : ?>
                <div class="alert alert-warning" role="status"><?php echo $this->escape($notice); ?></div>
            <?php endforeach; ?>

            <?php if ($requirement->permitsNone()) : ?>
                <p class="text-muted mb-0"><?php echo Text::_($notApplicable); ?></p>
            <?php elseif ($offered === []) : ?>
                <?php // TRUTHFULLY EMPTY. The published list is not a fallback:
                      // placement refuses a cart whose candidates exist but none
                      // of which serves it, and showing methods that cannot be
                      // used would only move that refusal later.
                      //
                      // One empty payment list has a plainer cause than "none
                      // serves this cart": a payment method may be restricted to
                      // particular carriers, and until a carrier is chosen no
                      // such method can qualify. That is the engine's own
                      // narrowing (CartMethods::payments() evaluates the
                      // shipment dimension), so the reason is read off the same
                      // projection rather than guessed at. ?>
                <?php if ($kind === 'payment' && $offers->selectedShipmentId === 0 && $offers->shipments !== []) : ?>
                    <div class="alert alert-info mb-0"><?php echo Text::_('COM_ALFA_CART_BUILDER_PAYMENT_NEEDS_SHIPMENT'); ?></div>
                <?php else : ?>
                    <div class="alert alert-danger mb-0"><?php echo Text::_($noneEligible); ?></div>
                <?php endif; ?>
            <?php else : ?>
                <form action="<?php echo Route::_('index.php?option=com_alfa'); ?>" method="post">
                    <?php foreach ($offered as $method) :
                        $methodId = (int) $method->id;
                        $view     = $offers->viewFor($kind, $methodId);
                        ?>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="radio"
                                   id="alfa-<?php echo $kind; ?>-<?php echo $methodId; ?>"
                                   name="<?php echo $idField; ?>" value="<?php echo $methodId; ?>"
                                   <?php echo $selectedId === $methodId ? 'checked' : ''; ?>>

                            <label class="form-check-label" for="alfa-<?php echo $kind; ?>-<?php echo $methodId; ?>">
                                <span class="fw-semibold"><?php echo $this->escape((string) ($method->name ?? $method->type)); ?></span>
                            </label>

                            <?php if ((string) ($method->description ?? '') !== '') : ?>
                                <div class="small text-muted"><?php echo $this->escape((string) $method->description); ?></div>
                            <?php endif; ?>

                            <?php // WHATEVER THE METHOD'S OWN PLUGIN CONTRIBUTED,
                                  // found by method id and rendered through the
                                  // plugin's layout. The plugin decides how its
                                  // method looks; it never decides here whether
                                  // the method is eligible. ?>
                            <?php // RESOLVABLE OR NOTHING, the same gate the
                                  // storefront's Layout::render() applies. The
                                  // dispatcher backfills a plugin's identity only
                                  // for the SELECTED method, so an unselected one
                                  // can carry a layout name with nobody to own it;
                                  // asking for that layout resolves nothing and
                                  // says so loudly. A method whose plugin did not
                                  // name itself simply contributes no markup. ?>
                            <?php if ($view !== null
                                && (string) $view->layout !== ''
                                && (string) $view->pluginType !== ''
                                && (string) $view->pluginName !== '') : ?>
                                <div class="mt-2 ms-2 ps-2 border-start">
                                    <?php echo PluginLayoutHelper::pluginLayout(
                                        $view->pluginType,
                                        $view->pluginName,
                                        $view->layout,
                                    )->render($view->layoutData); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php // Choosing a method is a builder mutation; the radios stay so
                          // the tab still reads as a statement of what the cart holds. ?>
                    <?php if ($this->canOperate) : ?>
                        <button type="submit" class="btn btn-primary">
                            <span class="icon-checkbox" aria-hidden="true"></span>
                            <?php echo Text::_('COM_ALFA_CART_BUILDER_METHOD_APPLY'); ?>
                        </button>
                    <?php endif; ?>

                    <input type="hidden" name="task" value="<?php echo $task; ?>">
                    <input type="hidden" name="form" value="page">
                    <input type="hidden" name="cart_id" value="<?php echo $cartId; ?>">
                    <?php echo HTMLHelper::_('form.token'); ?>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
};
?>

<div class="row">
    <div class="col-lg-6">
        <?php $section(
            'shipment',
            $offers->shipments,
            $offers->shipmentRequirement,
            $offers->selectedShipmentId,
            'cart.chooseShipment',
            'shipment_id',
            'COM_ALFA_CART_BUILDER_TAB_SHIPMENT',
            'COM_ALFA_CART_BUILDER_SHIPMENT_NOT_APPLICABLE',
            'COM_ALFA_CART_BUILDER_SHIPMENT_NONE_ELIGIBLE',
        ); ?>
    </div>

    <div class="col-lg-6">
        <?php // PAYMENT IS NARROWED BY THE CHOSEN CARRIER. CartMethods::payments()
              // evaluates the shipment dimension, so this list is already the
              // pair the engine allows: choosing a carrier re-settles the cart
              // and this side changes with it. ?>
        <?php $section(
            'payment',
            $offers->payments,
            $offers->paymentRequirement,
            $offers->selectedPaymentId,
            'cart.choosePayment',
            'payment_id',
            'COM_ALFA_CART_BUILDER_TAB_PAYMENT',
            'COM_ALFA_CART_BUILDER_PAYMENT_NOT_APPLICABLE',
            'COM_ALFA_CART_BUILDER_PAYMENT_NONE_ELIGIBLE',
        ); ?>
    </div>
</div>

<?php // WHAT THE CARRIER COSTS is not a property of a method: only the ONE
      // effective carrier is priced, by its own plugin, and the answer lands on
      // the settled totals. So it is stated once, here, rather than guessed per
      // row: a per-method price would have to be invented, and inventing it is
      // exactly the second engine this tab exists to avoid. ?>
<?php if ($offers->selectedShipmentId > 0) : ?>
    <div class="card">
        <div class="card-body d-flex justify-content-between align-items-center">
            <span><?php echo Text::_('COM_ALFA_CART_SHIPPING'); ?></span>
            <span class="fs-5 fw-semibold">
                <?php $benefit = $snapshot->totals->shippingBenefit; ?>
                <?php if ($benefit->benefit === 'free_shipping') : ?>
                    <span class="text-success"><?php echo Text::_('COM_ALFA_FREE_SHIPPING'); ?></span>
                <?php else : ?>
                    <?php echo $this->escape($snapshot->totals->shipment->format()); ?>
                <?php endif; ?>
            </span>
        </div>
    </div>
<?php endif; ?>
