<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The checkout's address groups, in the checkout's order and under its names: the customer details, the choice of
 * different invoice details, then the invoice details. The order screen and the order builder's placement form both
 * print them through this layout.
 *
 * The fields come through FieldsHelper::renderFieldset(): the target's own loose fields first (an account's email and
 * name on the order screen), then each checkout field group as a fieldset with its own legend inside the section's
 * fieldset, so a merchant's own field appears without this layout changing. An unticked toggle is a meaningful value: the invoice goes to the delivery
 * details, and the hidden section's inputs are parked by the ShowOn engine. The invoice fields repeat the delivery
 * field names, so they answer ShowOn in their own scope (data-showon-scope); otherwise one section's values would drive
 * the other's visibility. Prints nothing when the form carries neither group.
 *
 * Override html/layouts/com_alfa/order/checkout_details.php in the administrator template to change both screens.
 *
 * Display data:
 *   form            \Joomla\CMS\Form\Form  the form carrying the checkout groups (targets user_details and
 *                                          user_details_billing)
 *   invoiceDiffers  bool                   whether the toggle is ticked when the page opens
 *   toggleName      string                 the toggle's input name in its form
 *   toggleId        string                 the toggle's element id
 *
 * @since  2.3.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Joomla\CMS\Language\Text;

/** @var \Joomla\CMS\Form\Form $form */
$form           = $displayData['form'];
$invoiceDiffers = !empty($displayData['invoiceDiffers']);
$toggleName     = (string) $displayData['toggleName'];
$toggleId       = (string) $displayData['toggleId'];

$customerHtml = FieldsHelper::renderFieldset($form, 'user_details');
$billingHtml  = FieldsHelper::renderFieldset($form, 'user_details_billing');

if (trim($customerHtml) === '' && trim($billingHtml) === '') {
    return;
}
?>
<div class="alfa-checkout-details">
    <?php if (trim($customerHtml) !== '') : ?>
        <fieldset class="adminform">
            <legend><?php echo Text::_('COM_ALFA_FIELDSET_USER_DETAILS'); ?></legend>
            <?php echo $customerHtml; ?>
        </fieldset>
    <?php endif; ?>

    <?php if (trim($billingHtml) !== '') : ?>
        <div class="form-check mb-2" data-showon-name="invoice_differs">
            <input type="checkbox" class="form-check-input" id="<?php echo $this->escape($toggleId); ?>"
                   name="<?php echo $this->escape($toggleName); ?>" value="1"<?php echo $invoiceDiffers ? ' checked' : ''; ?>>
            <label class="form-check-label" for="<?php echo $this->escape($toggleId); ?>">
                <?php echo Text::_('COM_ALFA_CART_INVOICE_DIFFERS'); ?>
            </label>
        </div>

        <fieldset class="adminform" data-showon-scope<?php echo $invoiceDiffers ? '' : ' hidden'; ?>
                  data-showon-rule='{"match":"all","rules":[{"operand":"invoice_differs.value","operator":"=","value":"1"}],"action":"show"}'>
            <legend><?php echo Text::_('COM_ALFA_FORM_BILLING_DETAILS'); ?></legend>
            <?php echo $billingHtml; ?>
        </fieldset>
    <?php endif; ?>
</div>
