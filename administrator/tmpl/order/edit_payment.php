<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Single payment edit form, rendered inside a modal iframe.
 * Shows the form fields + plugin action buttons (for existing payments).
 *
 * Path: administrator/components/com_alfa/tmpl/order/edit_payment.php
 *
 * @since  1.0.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ActionRegistry;
use Alfa\Component\Alfa\Administrator\Helper\PluginLayoutHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$wa = $this->document->getWebAssetManager();
$wa->useScript('keepalive')
	->useScript('form.validate')
	->useScript('com_alfa.validate.engine')
	->useStyle('com_alfa.validate')
	->useScript('com_alfa.admin')
	->useScript('com_alfa.actions')
	->useStyle('com_alfa.admin');

$isNew = empty($this->payment);

$paymentID = $isNew ? 0 : (int) $this->payment->id;
$orderID   = (int) $this->order->id;

// Get plugin actions (only for existing payments)
$actions    = [];
$pluginType = '';

if (!$isNew && $this->payment) {
	$actions    = ActionRegistry::getPaymentActions($this->payment, $this->order);
	$pluginType = ActionRegistry::methodType($this->payment, 'payment_method_type');
}

?>

    <!-- Toolbar -->
    <div class="subhead noshadow mb-3">
		<?php echo $this->getDocument()->getToolbar('toolbar')->render(); ?>
    </div>

    <div class="container-popup">

		<?php // Plugin action buttons (existing payments only)?>
		<?php if (!empty($actions)): ?>
            <div class="mb-3 p-2 bg-body-tertiary border rounded d-flex align-items-center flex-wrap gap-2">
			<span class="fw-semibold text-muted me-2">
				<span class="icon-cogs" aria-hidden="true"></span>
				<?php echo Text::_('COM_ALFA_ACTIONS'); ?>:
			</span>
				<?php foreach ($actions as $action):
					$buttonLayout = PluginLayoutHelper::pluginLayout(
						'alfa-payments',
						str_starts_with((string) $action->id, 'ledger_') ? ActionRegistry::LEDGER_LAYOUT_OWNER : $pluginType,
						$action->button_layout ?? 'action_button'
					);

					echo $buttonLayout->render([
						'action'  => $action,
						'context' => 'payment',
						'id'      => $paymentID,
					]);
				endforeach; ?>
            </div>
		<?php endif; ?>

        <form
                action="<?php echo Route::_("index.php?option=com_alfa&layout=edit_payment&tmpl=component&id={$paymentID}&id_order={$orderID}"); ?>"
                method="post"
                enctype="multipart/form-data"
                name="adminForm"
                id="payment-form"
                class="form-validate form-horizontal">

            <!-- Form Fields -->
			<?php echo $this->form->renderFieldset('payment'); ?>

            <!-- Hidden Fields -->
			<?php echo HTMLHelper::_('form.token'); ?>
            <input type="hidden" name="task" value="" />
            <input type="hidden" name="id" value="<?php echo $paymentID; ?>" />
            <input type="hidden" name="id_order" value="<?php echo $orderID; ?>" />
        </form>
    </div>