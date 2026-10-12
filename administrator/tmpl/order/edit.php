<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * Order Edit View
 *
 * Architecture: Every section loads via loadTemplate(); no inline HTML for entities.
 *
 * Template map:
 *   edit_payments.php      -> Payments table + modals
 *   edit_shipments.php     -> Shipments table + modals
 *   edit_order_items.php   -> Order items table + modals
 *   edit_history.php       -> Unified timeline (status + activity)
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Language\Text;

$wa = $this->document->getWebAssetManager();
$wa->useScript('keepalive')
	->useScript('com_alfa.actions')
	->useScript('com_alfa.core-loader')
	->useScript('form.validate')
	->useScript('com_alfa.validate.engine')
	// The checkout fields carry ShowOn rules; without the engine the backend
	// would demand a field the storefront hides (and OrderModel::validate
	// prunes); the two sides must judge visibility identically.
	->useScript('com_alfa.showon.engine')
	->useStyle('com_alfa.validate')
	->useStyle('com_alfa.admin');

Text::script('JCLOSE'); //used by action modal popups

HTMLHelper::_('bootstrap.tooltip');
?>

<form
        action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->order->id); ?>"
        method="post" enctype="multipart/form-data" name="adminForm" id="order-form"
        class="form-validate form-horizontal">

	<?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'order', 'recall' => true, 'breakpoint' => 768]); ?>

    <!-- TAB 1: Order -->
	<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'order', Text::_('COM_ALFA_TAB_ORDER', true)); ?>

    <div class="row">
        <?php
        // Customer details render only when there is content: a guest order
        // drops the empty account fields. The dynamic checkout groups carry
        // their OWN fieldset+legend, so the outer "Customer Details" wrapper is
        // added only when the loose account fields exist (registered orders),
        // never a fieldset nested directly inside another.
        $userDetailsHtml = FieldsHelper::renderFieldset($this->form, 'user_details');
        $hasAccountFields = $this->form->getField('user_email') !== false;

        // Invoice section: the backend twin of the checkout's "different
        // details". Opening an order shows the choice it was saved with; the
        // redisplay after a reload or a refused save shows what the operator
        // left, like the fields it refills (the model decides which).
        $billingHtml = FieldsHelper::renderFieldset($this->form, 'user_details_billing');
        $invoiceDiffers = $this->getModel()->invoiceDiffersOnForm();
        ?>
        <?php if (trim($userDetailsHtml) !== '' || trim($billingHtml) !== ''): ?>
        <div class="col-md-6">
            <?php if ($hasAccountFields): ?>
            <fieldset class="adminform">
                <legend><?php echo Text::_('COM_ALFA_FIELDSET_USER_DETAILS'); ?></legend>
                <?php echo $userDetailsHtml; ?>
            </fieldset>
            <?php else: ?>
                <?php echo $userDetailsHtml; ?>
            <?php endif; ?>

            <?php if (trim($billingHtml) !== ''): ?>
                <div class="order-invoice-toggle form-check mb-2" data-showon-name="invoice_differs">
                    <input type="checkbox" class="form-check-input" id="invoice_differs"
                           name="jform[invoice_differs]" value="1"<?php echo $invoiceDiffers ? ' checked' : ''; ?>>
                    <label class="form-check-label" for="invoice_differs">
                        <?php echo Text::_('COM_ALFA_CART_INVOICE_DIFFERS'); ?>
                    </label>
                </div>

                <!-- data-showon-scope: the invoice fields repeat the delivery field
                     NAMES, so they answer in their own scope; otherwise one
                     section's values would drive the other's visibility. -->
                <fieldset class="adminform" data-showon-scope<?php echo $invoiceDiffers ? '' : ' hidden'; ?>
                          data-showon-rule='{"match":"all","rules":[{"operand":"invoice_differs.value","operator":"=","value":"1"}],"action":"show"}'>
                    <legend><?php echo Text::_('COM_ALFA_FORM_BILLING_DETAILS'); ?></legend>
                    <?php echo $billingHtml; ?>
                </fieldset>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="col-md-6">
            <fieldset class="adminform">
                <legend><?php echo Text::_('COM_ALFA_FIELDSET_ORDER_DETAILS'); ?></legend>
                <?php echo $this->form->renderFieldset('order_details'); ?>
            </fieldset>

            <?php echo $this->renderSlot('order-details'); ?>
        </div>
    </div>

	<?php if (!empty($this->order->id)): ?>

		<?php echo $this->loadTemplate('payments'); ?>

		<?php echo $this->loadTemplate('shipments'); ?>

		<?php echo $this->loadTemplate('order_items'); ?>

        <!-- Sales documents: legal records + Issue modal -->
		<?php echo $this->loadTemplate('documents'); ?>

        <!-- Financial summary (products + shipping - discounts = total, paid, balance) -->
		<?php echo $this->loadTemplate('totals'); ?>

		<?php echo $this->loadTemplate('reconciliation'); ?>

		<?php echo $this->renderSlot('order-main'); ?>

	<?php else: ?>

        <!-- Manual creation: a metadata-only shell until the first save;
             products/payments/shipments live on the edit screen after it. -->
        <div class="alert alert-info">
			<?php echo Text::_('COM_ALFA_ORDER_CREATE_HINT'); ?>
        </div>

	<?php endif; ?>

	<?php echo HTMLHelper::_('uitab.endTab'); ?>

	<?php if (!empty($this->order->id)): ?>
    <!-- TAB 2: History & Activity -->
	<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'history', Text::_('COM_ALFA_ORDER_HISTORY', true)); ?>

	<?php echo $this->loadTemplate('history'); ?>

	<?php echo HTMLHelper::_('uitab.endTab'); ?>
	<?php endif; ?>

<?php
// CONTRIBUTED TABS. A plugin that loads a named <fieldset> into this form through
// onAlfaAdminForm gets it rendered here as its own tab. Two families are skipped because
// this template already renders them itself: the form's OWN fieldsets, and the Alfa
// checkout-field groups (FieldsHelper injects them with a `target` attribute and the
// user_details block above emits them inline; see FieldsHelper::renderFieldset).
$ownFieldsets = ['user_details', 'order_details'];
foreach ($this->form->getFieldsets() as $fieldsetName => $fieldset) {
	if (in_array($fieldsetName, $ownFieldsets, true) || !empty($fieldset->target)) {
		continue;
	}
	$fieldsetLabel = !empty($fieldset->label)
		? Text::_($fieldset->label)
		: ucwords(str_replace('_', ' ', $fieldsetName));

	echo HTMLHelper::_('uitab.addTab', 'myTab', 'plg-' . $fieldsetName, $fieldsetLabel);
	echo '<div class="row"><div class="col-12"><fieldset class="options-form"><legend>'
		. $fieldsetLabel . '</legend><div>'
		. $this->form->renderFieldset($fieldsetName)
		. '</div></fieldset></div></div>';
	echo HTMLHelper::_('uitab.endTab');
}
?>	<?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <input type="hidden" name="task" value="" />
	<?php if (!empty($this->order->id)): ?>
	<?php // The STORED status this page was built with, whatever the select
	      // shows: a modal's return posts it back, so the redisplay can tell a
	      // status the operator changed from one the modal moved. ?>
    <input type="hidden" name="jform[<?php echo OrderModel::LOADED_STATUS_KEY; ?>]"
           value="<?php echo (int) $this->order->id_order_status; ?>" />
	<?php endif; ?>
	<?php echo HTMLHelper::_('form.token'); ?>

</form>

<?php // Sibling form for the order's customer-credit actions, Pay from credit
      // (Payments panel) and Pay in the customer credit (Documents panel),
      // same reasoning as the user Credit tab: no nested forms, and no hidden
      // `task` (a POSTed empty task beats the query string and lands on the
      // default view). Each button names its own task in its formaction. ?>
<form id="alfa-order-credit-form" action="<?php echo Route::_('index.php?option=com_alfa'); ?>" method="post">
    <input type="hidden" name="id_order" value="<?php echo (int) $this->order->id; ?>"/>
	<?php echo HTMLHelper::_('form.token'); ?>
</form>