<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * Order Items List (embedded in order edit page)
 * Mirrors edit_payments.php pattern exactly
 *
 * Field definitions come from order_items.xml (via subform in order.xml)
 * Table headers: from form field labels
 * Table data: from $item->{$fieldName}
 * Editing: via modal popup (edit_order_item.php)
 *
 * @see edit_payments.php — identical pattern
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

$items         = $this->order->items ?? [];
$items_subform = $this->form->getField('items')->loadSubForm();
$itemFields    = $items_subform->getFieldset();

?>

<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3>
                <span class="icon-cube me-2"></span>
				<?php echo Text::_('Items'); ?>
            </h3>

            <!-- Add Item Button -->
            <a href="#addOrderItemModal"
               data-bs-toggle="modal"
               class="btn btn-success">
                <span class="icon-plus"></span>
				<?php echo Text::_('Add item'); ?>
            </a>
        </div>
    </div>
</div>

<?php if (!empty($items)): ?>
    <table class="table table-striped table-bordered">
        <thead>
        <tr>
			<?php foreach ($itemFields as $field): ?>
				<?php if ($field->getAttribute('type') !== 'hidden'): ?>
                    <th><?php echo Text::_($field->getAttribute('label')); ?></th>
				<?php endif; ?>
			<?php endforeach; ?>
            <th><?php echo Text::_('Actions'); ?></th>
        </tr>
        </thead>
        <tbody id="order-item-rows">
		<?php foreach ($items as $item): ?>
			<?php
			$modalId = 'orderItemModal-' . $item->id;

			$editUrl = Route::_(sprintf(
				'index.php?option=com_alfa&view=order&layout=edit_order_item&tmpl=component&id=%d&id_order=%d',
				$item->id,
				$this->order->id
			));
			?>
            <tr>
				<?php foreach ($items_subform->getGroup('') as $field): ?>
					<?php $fieldName = $field->fieldname; ?>
                    <td style="<?php echo $field->getAttribute('type') === 'hidden' ? 'display: none;' : ''; ?>">
						<?php
						$value = $item->{$fieldName} ?? '';

						// Format Money objects
						if (is_object($value) && method_exists($value, 'format')) {
							echo $value->format();
						} else {
							echo $this->escape($value);
						}
						?>
                    </td>
				<?php endforeach; ?>

                <td>
                    <div class="btn-group me-2" role="group">
                        <a href="#<?php echo $modalId; ?>"
                           data-bs-toggle="modal"
                           role="button"
                           class="btn btn-sm btn-primary">
                            <span class="icon-edit"></span>
							<?php echo Text::_('JACTION_EDIT'); ?>
                        </a>
                    </div>

                    <!-- Plugin Actions -->
					<?php if (!empty($actions)): ?>
                        <div class="btn-group" role="group">
							<?php foreach ($actions as $action): ?>
								<?php echo LayoutHelper::render('actions.button', [
									'action'  => $action,
									'context' => 'order_item',
									'id'      => $item->id
								], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>
							<?php endforeach; ?>
                        </div>
					<?php endif; ?>

					<?php
					// Render edit modal
					echo HTMLHelper::_(
						'bootstrap.renderModal',
						$modalId,
						[
							'title'      => Text::_('COM_ALFA_EDIT_ORDER_ITEM'),
							'url'        => $editUrl,
							'width'      => '80%',
							'bodyHeight' => '70vh',
						]
					);
					?>
                </td>
            </tr>
		<?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="alert alert-info">
		<?php echo Text::_('COM_ALFA_NO_ORDER_ITEMS_YET'); ?>
    </div>
<?php endif; ?>

<?php
// Add Item Modal (id=0 for new item)
$addItemUrl = Route::_(sprintf(
	'index.php?option=com_alfa&view=order&layout=edit_order_item&tmpl=component&id=0&id_order=%d',
	$this->order->id
));

echo HTMLHelper::_(
	'bootstrap.renderModal',
	'addOrderItemModal',
	[
		'title'      => Text::_('COM_ALFA_ADD_ORDER_ITEM'),
		'url'        => $addItemUrl,
		'width'      => '80%',
		'bodyHeight' => '70vh',
	]
);
?>

<script>
    /**
     * Order Item Modal Communication Handler
     * Listens for postMessage from order item modals
     */
    (function() {
        'use strict';

        window.addEventListener('message', function(event) {
            if (event.data.messageType !== 'alfa:order-item-action') {
                return;
            }

            console.log('[Parent] Received order item message:', event.data);

            const shouldClose = event.data.shouldClose;
            const shouldReload = event.data.shouldReload;
            const itemId = event.data.itemId;

            if (shouldClose) {
                closeModal(itemId);
            }

            if (shouldReload) {
                reloadOrderPage();
            }
        });

        function reloadOrderPage() {
            const url = new URL(window.location.href);
            const view = url.searchParams.get('view') || '';

            if (view === '') {
                console.error('View not found to call the controller reload');
                return;
            }

            const orderForm = document.querySelector("#order-form");

            if (!orderForm) {
                console.error('Order form not found');
                return;
            }

            document.body.appendChild(document.createElement('joomla-core-loader'));

            const taskInput = orderForm.querySelector('input[name=task]');
            if (taskInput) {
                taskInput.value = `${view}.reload`;
            }

            orderForm.submit();
        }

        function closeModal(itemId) {
            const modalId = itemId === 0 ? 'addOrderItemModal' : 'orderItemModal-' + itemId;
            const modalEl = document.getElementById(modalId);

            if (modalEl) {
                const bsModal = bootstrap.Modal.getInstance(modalEl);
                if (bsModal) {
                    console.log('[Parent] Closing modal:', modalId);
                    bsModal.hide();
                }
            }
        }

    })();
</script>