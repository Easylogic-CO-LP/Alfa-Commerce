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

use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Layout\LayoutHelper;
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
        // The checkout's address groups, in its order and under its names, through the layout the order builder's
        // placement form prints too. Opening an order ticks the invoice choice it was saved with; the redisplay after
        // a reload or a refused save ticks what the operator left, like the fields it refills (the model decides).
        $addressesHtml = LayoutHelper::render(
            'order.checkout_details',
            [
                'form'           => $this->form,
                'invoiceDiffers' => $this->getModel()->invoiceDiffersOnForm(),
                'toggleName'     => 'jform[invoice_differs]',
                'toggleId'       => 'invoice_differs',
            ],
            null,
            ['component' => 'com_alfa', 'client' => 1],
        );
        ?>
        <?php if (trim($addressesHtml) !== ''): ?>
        <div class="col-md-6">
            <?php echo $addressesHtml; ?>
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

		<?php // The checkout's order below the addresses: the lines and their totals, the shipment, then the
		      // payment (the payment methods offered depend on the shipping method), then the documents. ?>
		<?php echo $this->loadTemplate('order_items'); ?>

        <!-- Financial summary (products + shipping - discounts = total, paid, balance) -->
		<?php echo $this->loadTemplate('totals'); ?>

		<?php echo $this->loadTemplate('shipments'); ?>

		<?php echo $this->loadTemplate('payments'); ?>

        <!-- Sales documents: legal records + Issue modal -->
		<?php echo $this->loadTemplate('documents'); ?>

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
// address layout above emits them inline; see FieldsHelper::renderFieldset).
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

<?php // Sibling form for the order's actions that post the order id alone: Pay from
      // credit and Send payment link (Payments panel) and Pay in the customer
      // credit (Documents panel), same reasoning as the user Credit tab: no
      // nested forms, and no hidden `task` (a POSTed empty task beats the query
      // string and lands on the default view). Each button names its own task
      // in its formaction. ?>
<form id="alfa-order-credit-form" action="<?php echo Route::_('index.php?option=com_alfa'); ?>" method="post">
    <input type="hidden" name="id_order" value="<?php echo (int) $this->order->id; ?>"/>
	<?php echo HTMLHelper::_('form.token'); ?>
</form>

<?php // Sibling form for the Coupons card (edit_coupons.php), for the same reasons. ?>
<form id="alfa-order-coupon-form" action="<?php echo Route::_('index.php?option=com_alfa'); ?>" method="post">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>