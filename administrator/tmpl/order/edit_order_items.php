<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * Order Items List (embedded in order edit page)
 * Follows the edit_payments.php pattern
 *
 * Field definitions come from order_items.xml (via subform in order.xml)
 * Table headers: from form field labels
 * Table data: from $item->{$fieldName}
 * Editing: via modal popup (edit_order_item.php)
 *
 * @see edit_payments.php (the same pattern)
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Router\Route;

// Adding, editing and running plugin actions on order lines are order edits.
$canEditOrder = AlfaHelper::can(section: 'orders', action: 'edit', user: $this->getCurrentUser());

// A line's product name links to the catalog item only for a user who may edit items.
$canEditItems = AlfaHelper::can(section: 'items', action: 'edit', user: $this->getCurrentUser());

$items         = $this->order->items ?? [];
$items_subform = $this->form->getField('items')->loadSubForm();
$itemFields    = $items_subform->getFieldset();

// Column span for the per-line configuration row (visible columns + Actions).
$visibleColumns = 0;

foreach ($itemFields as $columnField) {
    if ($columnField->getAttribute('type') !== 'hidden') {
        $visibleColumns++;
    }
}

$configColspan = $visibleColumns + 1;

?>

<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3>
                <span class="icon-cube me-2"></span>
				<?php echo Text::_('COM_ALFA_ITEMS'); ?>
            </h3>

            <!-- Add Item Button -->
            <?php if ($canEditOrder) : ?>
            <a href="#addOrderItemModal"
               data-bs-toggle="modal"
               class="btn btn-success">
                <span class="icon-plus"></span>
				<?php echo Text::_('COM_ALFA_ADD_ORDER_ITEM'); ?>
            </a>
            <?php endif; ?>
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
            <th><?php echo Text::_('COM_ALFA_ACTIONS'); ?></th>
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

						// Frozen names are {lang:text} snapshots; resolve to
						// the current language for display.
						if ($fieldName === 'name') {
							$value = MultilingualHelper::resolveLangMap($value);
						}

						if ($fieldName === 'name' && !empty($item->id_item) && $canEditItems) {
							// Product name links to the catalog item (new tab), through the
							// controller task so the item is checked out like any other edit.
							echo '<a href="'
								. Route::_('index.php?option=com_alfa&task=item.edit&id=' . (int) $item->id_item)
								. '" target="_blank" rel="noopener">'
								. $this->escape($value)
								. '</a>';
						} elseif (isset($item->{$fieldName . '_formatted'})) {
							// A unit as the one unit rule prints it, at the order's frozen scale;
							// a percentage (discount, tax rate) as AlfaHelper::percentDisplay() prints it.
							echo $this->escape($item->{$fieldName . '_formatted'});
						} elseif (is_object($value) && method_exists($value, 'format')) {
							// Format Money objects
							echo $value->format();
						} else {
							echo $this->escape($value);
						}
						?>
                    </td>
				<?php endforeach; ?>

                <td>
                    <?php if ($canEditOrder) : ?>
                    <div class="btn-group me-2" role="group">
                        <a href="#<?php echo $modalId; ?>"
                           data-bs-toggle="modal"
                           role="button"
                           class="btn btn-sm btn-primary">
                            <span class="icon-edit"></span>
							<?php echo Text::_('JACTION_EDIT'); ?>
                        </a>
                    </div>
                    <?php endif; ?>

					<?php
					// Render edit modal
					echo HTMLHelper::_(
						'bootstrap.renderModal',
						$modalId,
						[
							'title'      => Text::_('COM_ALFA_EDIT_ORDER_ITEM')
								. (!empty($item->name) ? ' - ' . $this->escape(MultilingualHelper::resolveLangMap($item->name)) : '')
								. (!empty($item->sku) ? ' (' . $this->escape($item->sku) . ')' : ''),
							'url'        => $editUrl,
							'width'      => '80%',
							'bodyHeight' => '70vh',
						]
					);
					?>
                </td>
            </tr>

			<?php if (!empty($item->fields)): ?>
                <tr class="alfa-order-item-config">
                    <td colspan="<?php echo (int) $configColspan; ?>" class="small text-muted ps-4 py-1">
						<?php foreach ($item->fields as $configField): ?>
                            <div class="d-flex justify-content-between align-items-baseline py-1 border-bottom border-light">
                                <span>
                                    <strong><?php echo $this->escape($configField->label); ?>:</strong>
                                    <?php
                                    if ($configField->value !== "") {
                                        echo " " . $this->escape($configField->value);
                                    } elseif ($configField->has_payload) {
                                        echo " <span class=\"badge bg-body-tertiary text-body-emphasis\">" . Text::_("COM_ALFA_ORDER_ITEM_FIELD_CONFIGURED") . "</span>";
                                    }
                                    ?>
                                </span>
                                <?php if ($configField->impact !== ""): ?>
                                    <span class="text-success fw-bold ms-3 flex-shrink-0"><?php echo $this->escape($configField->impact); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($configField->breakdown)): ?>
                                <?php foreach ($configField->breakdown as $line): ?>
                                    <div class="d-flex justify-content-between align-items-baseline ps-4 py-1">
                                        <span>&middot; <?php echo $this->escape($line->label); ?></span>
                                        <?php if ($line->impact !== ""): ?>
                                            <span class="text-success ms-3 flex-shrink-0"><?php echo $this->escape($line->impact); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
						<?php endforeach; ?>
                    </td>
                </tr>
			<?php endif; ?>
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
            if (event.origin !== window.location.origin) {
                return;
            }

            if (event.data.messageType !== 'alfa:order-item-action') {
                return;
            }

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

            const taskInput = orderForm.querySelector('input[name=task]');
            if (taskInput) {
                taskInput.value = `${view}.reload`;
            }

            // Painted first, then submitted; see Alfa.loader.showThen().
            Alfa.loader.showThen(() => orderForm.submit());
        }

        function closeModal(itemId) {
            const modalId = itemId === 0 ? 'addOrderItemModal' : 'orderItemModal-' + itemId;
            const modalEl = document.getElementById(modalId);

            if (modalEl) {
                const bsModal = bootstrap.Modal.getInstance(modalEl);
                if (bsModal) {
                    bsModal.hide();
                }
            }
        }

    })();
</script>