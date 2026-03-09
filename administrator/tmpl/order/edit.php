<?php
/**
 * Order Edit View
 *
 * Architecture: Every section loads via loadTemplate() — no inline HTML for entities.
 *
 * Template map:
 *   edit_payments.php      → Payments table + modals
 *   edit_shipments.php     → Shipments table + modals
 *   edit_order_items.php   → Order items table + modals
 *   edit_history.php       → Unified timeline (status + activity)
 *
 * @package    Com_Alfa
 * @subpackage Administrator
 * @version    3.4.0
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2026 Easylogic CO LP
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Language\Text;

$wa = $this->document->getWebAssetManager();
$wa->useScript('keepalive')
	->useScript('com_alfa.order-actions')
	->useScript('webcomponent.core-loader')
	->useScript('form.validate')
	->useStyle('com_alfa.admin');

Text::script('JCLOSE'); //used by action modal popups

HTMLHelper::_('bootstrap.tooltip');
?>

<form
        action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->order->id); ?>"
        method="post" enctype="multipart/form-data" name="adminForm" id="order-form"
        class="form-validate form-horizontal">

	<?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'order', 'recall' => true, 'breakpoint' => 768]); ?>

    <!-- ============================================================ -->
    <!-- TAB 1: Order -->
    <!-- ============================================================ -->
	<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'order', Text::_('COM_ALFA_TAB_ORDER', true)); ?>

    <div class="row">
        <div class="col-md-6">
            <fieldset class="adminform">
                <h5 class="mb-0"><?php echo Text::_('COM_ALFA_FIELDSET_USER_DETAILS'); ?></h5>
				<?php echo $this->form->renderFieldset('fields-0'); ?>
            </fieldset>
        </div>
        <div class="col-md-6">
            <fieldset class="adminform">
                <h5 class="mb-0"><?php echo Text::_('COM_ALFA_FIELDSET_ORDER_DETAILS'); ?></h5>
				<?php echo $this->form->renderFieldset('order_details'); ?>
            </fieldset>
        </div>
    </div>

	<?php echo $this->loadTemplate('payments'); ?>

	<?php echo $this->loadTemplate('shipments'); ?>

	<?php echo $this->loadTemplate('order_items'); ?>

    <!-- Financial summary (products + shipping - discounts = total, paid, balance) -->
	<?php echo $this->loadTemplate('totals'); ?>

	<?php echo HTMLHelper::_('uitab.endTab'); ?>

    <!-- ============================================================ -->
    <!-- TAB 2: History & Activity -->
    <!-- ============================================================ -->
	<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'history', Text::_('History', true)); ?>

	<?php echo $this->loadTemplate('history'); ?>

	<?php echo HTMLHelper::_('uitab.endTab'); ?>

	<?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <input type="hidden" name="task" value="" />
	<?php echo HTMLHelper::_('form.token'); ?>

</form>