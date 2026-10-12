<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('com_alfa.validate.engine');
$wa->useStyle('com_alfa.validate');
$wa->useScript('com_alfa.showon.engine');
$wa->useScript('com_alfa.cart.form-state');
Text::script('COM_ALFA_VALIDATE_FIELD_INVALID');
?>
<form id="cart-form"
      class="form-validate"
      action="<?php echo Route::_('index.php?option=com_alfa&task=cart.placeOrder'); ?>"
      method="POST">

    <div class="cart-form-grid">
        <div class="cart-form-col">
            <h4><?php echo Text::_('COM_ALFA_FORM_CUSTOMER_DETAILS'); ?></h4>

            <?php
            // Which identity the order will carry. A member's email is the
            // account's (not asked below), so it is stated here; a guest is
            // offered the sign-in that carries their cart over (the opener
            // adopts it). No log-out link: leaving the account mid-checkout
            // would hand the shopper an empty cart.
            $customer = $this->snapshot->customer;
            ?>
            <?php if ((int) $customer->id > 0) : ?>
                <p class="cart-identity">
                    <?php echo Text::sprintf('COM_ALFA_CART_ORDERING_AS', $this->escape($customer->name), $this->escape($customer->email)); ?>
                </p>
            <?php else : ?>
                <p class="cart-identity">
                    <?php echo Text::_('COM_ALFA_CART_HAVE_ACCOUNT'); ?>
                    <a href="<?php echo Route::_('index.php?option=com_users&view=login&return=' . base64_encode(Uri::getInstance()->toString())); ?>"><?php echo Text::_('COM_ALFA_CART_SIGN_IN'); ?></a>
                </p>
            <?php endif; ?>

            <?php if (!empty($this->savedUserInfo)) : ?>
				<?php echo $this->loadTemplate($this->selectUserInfoTpl); ?>
            <?php endif; ?>

			<?php echo FieldsHelper::renderFieldset($this->form, 'cart'); ?>

            <div class="cart-invoice-toggle" data-showon-name="invoice_differs">
                <label>
                    <input type="checkbox" name="cartform[invoice_differs]" value="1"
                        <?php echo $this->invoiceDiffers ? 'checked' : ''; ?>>
                    <?php echo Text::_('COM_ALFA_CART_INVOICE_DIFFERS'); ?>
                </label>
            </div>

            <!-- data-showon-scope: the invoice fields repeat the delivery field NAMES,
                 so they answer in their own scope; otherwise one section's values
                 would drive the other's visibility (state-reader.js scopeOf()). -->
            <div class="cart-billing-section" data-showon-scope<?php echo $this->invoiceDiffers ? '' : ' hidden'; ?>
                data-showon-rule='{"match":"all","rules":[{"operand":"invoice_differs.value","operator":"=","value":"1"}],"action":"show"}'>
                <h4><?php echo Text::_('COM_ALFA_FORM_BILLING_DETAILS'); ?></h4>

                <?php if (!empty($this->savedUserInfo)) : ?>
                    <?php $this->pickerRole = 'billing'; ?>
                    <?php echo $this->loadTemplate($this->selectUserInfoTpl); ?>
                    <?php $this->pickerRole = null; ?>
                <?php endif; ?>

				<?php echo FieldsHelper::renderFieldset($this->form, 'cart_billing'); ?>
            </div>

			<?php echo $this->form->renderFieldset('captcha'); ?>

        </div>

        <div class="cart-form-col">
			<?php
			// Shipping before payment: the payment methods offered depend on the
			// shipping method chosen. Each section stays on the page while the
			// store offers this cart methods of that kind at all; its list may
			// be empty for the current delivery place or shipping method, and
			// AJAX method changes replace the list container in place.
			?>
			<?php if ($this->snapshot->offers->hasShipments) : ?>
                <h4><?php echo Text::_('COM_ALFA_FORM_SHIPMENT'); ?></h4>
				<?php echo $this->loadTemplate($this->selectShipmentTpl); ?>
			<?php endif; ?>

			<?php
			// Customer credit opt-in: logged-in customers with balance
			// The checkbox carries INTENT only: the POSTed flag is re-read and
			// balance-revalidated at placement (never trust render-time state
			// for money), the debit fires after the order exists, and the
			// gateway then collects only the remainder. Deliberately OUTSIDE
			// [data-cart-payments], which AJAX method changes replace.
			//
			// WALLET MONEY IS MONEY IN THE SHOP SETTLEMENT CURRENCY. The ledger
			// stores, caps and spends the balance in that currency and refuses
			// any other, so the WALLET's currency decides both whether there is
			// anything to offer and how it is written, never the cart's, and
			// never a hard-coded two decimals. The 0.005 this replaced was half
			// a cent: it hid a 0.001 KWD balance the checkout would have spent,
			// and offered nothing the shopper could act on.
			//
			// NOTHING IS RESOLVED FOR A GUEST. Currency::getDefault() throws
			// when the settlement currency row is missing or unpublished, and
			// a throw here is a fatal cart page. Today a guest never reaches
			// it, because balance() is what calls it; keep it that way.
			$creditUserId = (int) $this->snapshot->customer->id;
			$creditMoney  = $creditUserId > 0
			    ? \Alfa\Component\Alfa\Site\Service\Pricing\Money::of(
			        \Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger::balance($creditUserId),
			        \Alfa\Component\Alfa\Site\Service\Pricing\Currency::getDefault(),
			    )
			    : null;
			?>
			<?php if ($creditMoney?->isPositive()) : ?>
                <div class="cart-credit-optin">
                    <label>
                        <?php // Restored after a validation-failure redirect, like every
                              // other typed value (the flag is top-level, not in cartform[]). ?>
                        <?php $useCreditPrev = (int) \Joomla\CMS\Factory::getApplication()->getUserState('com_alfa.cart.use_credit', 0) === 1; ?>
                        <input type="checkbox" name="use_credit" value="1"<?php echo $useCreditPrev ? ' checked' : ''; ?>/>
                        <?php echo Text::sprintf('COM_ALFA_CART_USE_CREDIT', $creditMoney->format()); ?>
                    </label>
                    <p class="cart-credit-note"><?php echo Text::_('COM_ALFA_CART_USE_CREDIT_NOTE'); ?></p>
                </div>
			<?php endif; ?>

			<?php if ($this->snapshot->offers->hasPayments) : ?>
                <h4><?php echo Text::_('COM_ALFA_FORM_PAYMENT'); ?></h4>
				<?php echo $this->loadTemplate($this->selectPaymentTpl); ?>
			<?php endif; ?>
        </div>

    </div>

    <button type="submit" class="action primary block">
        <?php echo Text::_('COM_ALFA_BUTTON_PLACE_ORDER'); ?>
    </button>

	<?php echo HTMLHelper::_('form.token'); ?>
</form>
