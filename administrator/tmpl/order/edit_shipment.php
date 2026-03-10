<?php
/**
 * @package     Alfa.Component
 * @subpackage  Administrator.View.Order
 * @version     3.0.0
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2025-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * Single shipment edit form — rendered inside a modal iframe.
 * Shows the form fields + plugin action buttons (for existing shipments).
 *
 * Path: administrator/components/com_alfa/tmpl/order/edit_shipment.php
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

$isNew = empty($this->shipment);

$shipmentID = $isNew ? 0 : (int) $this->shipment->id;
$orderID    = (int) $this->order->id;

// ── Get plugin actions (only for existing shipments) ─────────
$actions    = [];
$pluginType = '';

if (!$isNew && $this->shipment) {
	$actions    = ActionRegistry::getShipmentActions($this->shipment, $this->order);
	$pluginType = $this->shipment->params->type ?? 'standard';
}

?>

    <!-- Toolbar -->
    <div class="subhead noshadow mb-3">
		<?php echo $this->getDocument()->getToolbar('toolbar')->render(); ?>
    </div>

    <div class="container-popup">

		<?php // ── Plugin action buttons (existing shipments only) ─── ?>
		<?php if (!empty($actions)): ?>
            <div class="mb-3 p-2 bg-light border rounded d-flex align-items-center flex-wrap gap-2">
			<span class="fw-semibold text-muted me-2">
				<span class="icon-cogs" aria-hidden="true"></span>
				<?php echo Text::_('Actions'); ?>:
			</span>
				<?php foreach ($actions as $action):
					$buttonLayout = PluginLayoutHelper::pluginLayout(
						'alfa-shipments',
						$pluginType,
						$action->button_layout ?? 'action_button'
					);

					echo $buttonLayout->render([
						'action'  => $action,
						'context' => 'shipment',
						'id'      => $shipmentID,
					]);
				endforeach; ?>
            </div>
		<?php endif; ?>

        <form
                action="<?php echo Route::_("index.php?option=com_alfa&layout=edit_shipment&tmpl=component&id={$shipmentID}&id_order={$orderID}"); ?>"
                method="post"
                enctype="multipart/form-data"
                name="adminForm"
                id="shipment-form"
                class="form-validate form-horizontal">

            <!-- Form Fields -->
			<?php echo $this->form->renderFieldset('shipment'); ?>

            <!-- Hidden Fields -->
			<?php echo HTMLHelper::_('form.token'); ?>
            <input type="hidden" name="task" value="" />
            <input type="hidden" name="id" value="<?php echo $shipmentID; ?>" />
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
            messageType: 'alfa:shipment-action',
            entityId: <?php echo $shipmentID; ?>
        };
    </script>
<?php endif; ?>