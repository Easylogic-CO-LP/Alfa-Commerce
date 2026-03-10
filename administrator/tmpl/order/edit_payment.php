<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Single payment edit form — rendered inside a modal iframe.
 * Shows the form fields + plugin action buttons (for existing payments).
 *
 * Path: administrator/components/com_alfa/tmpl/order/edit_payment.php
 *
 * @since  3.0.0
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
	->useScript('com_alfa.admin')
	->useScript('com_alfa.order-actions')
	->useStyle('com_alfa.admin');

$isNew = empty($this->payment);

$paymentID = $isNew ? 0 : (int) $this->payment->id;
$orderID   = (int) $this->order->id;

// ── Get plugin actions (only for existing payments) ──────────
$actions    = [];
$pluginType = '';

if (!$isNew && $this->payment) {
	$actions    = ActionRegistry::getPaymentActions($this->payment, $this->order);
	$pluginType = $this->payment->params->type ?? 'standard';
}

?>

    <!-- Toolbar -->
    <div class="subhead noshadow mb-3">
		<?php echo $this->getDocument()->getToolbar('toolbar')->render(); ?>
    </div>

    <div class="container-popup">

		<?php // ── Plugin action buttons (existing payments only) ──── ?>
		<?php if (!empty($actions)): ?>
            <div class="mb-3 p-2 bg-light border rounded d-flex align-items-center flex-wrap gap-2">
			<span class="fw-semibold text-muted me-2">
				<span class="icon-cogs" aria-hidden="true"></span>
				<?php echo Text::_('Actions'); ?>:
			</span>
				<?php foreach ($actions as $action):
					$buttonLayout = PluginLayoutHelper::pluginLayout(
						'alfa-payments',
						$pluginType,
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

<?php if (!$isNew): ?>
    <script>
        /**
         * Iframe context flag for order-actions.js.
         *
         * When set, executeAction() will notify the parent window
         * instead of reloading the iframe on refresh/redirect actions.
         */
        window.AlfaActionContext = {
            iframe: true,
            messageType: 'alfa:payment-action',
            entityId: <?php echo $paymentID; ?>
        };
    </script>
<?php endif; ?>