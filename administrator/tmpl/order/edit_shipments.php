<?php
/**
 * Order Edit — Shipments Tab (Auto-Discovery + Merge Map)
 *
 * Columns are auto-generated from order_shipments.xml subform fields.
 * Adding a field to the XML automatically adds it to this table.
 *
 * Three configuration arrays at the top control the layout:
 *
 *   $mergeMap   — Which fields share a table cell. The "primary" field
 *                 gets the column header; "children" render below it.
 *
 *   $renderMap  — Custom HTML rendering per field name. Provides badges,
 *                 monospace formatting, coloured dates, etc.
 *
 * To reorganize the table, just edit the two arrays — no need to touch
 * the rendering loop below.
 *
 * @package    Com_Alfa
 * @subpackage Administrator.View.Order
 * @version    4.2.0
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  3.0.0
 */

use Alfa\Component\Alfa\Administrator\Helper\ActionRegistry;
use Alfa\Component\Alfa\Administrator\Helper\PluginLayoutHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

// Load shipment data from the order and the form field definitions
$shipments         = $this->order->shipments;
$shipments_subform = $this->form->getField('shipments')->loadSubForm();
$shipmentFields    = $shipments_subform->getFieldset();

// =====================================================================
//  MERGE MAP — Which fields share a table cell
//
//  Format: 'primary_field' => ['child1', 'child2', ...]
//
//  - The primary field name becomes the column header
//  - Children render below the primary in the same <td>
//  - Fields NOT mentioned anywhere get their own column
//
//  To combine two columns, add the second as a child of the first.
//  To split them back, just remove the entry.
//
//  Example results:
//    'id_shipment_method' => ['status']
//    → Cell shows:  test
//                   [Shipped]   ← badge below
//
//    'added' => ['shipped', 'delivered']
//    → Cell shows:  2026-03-04 17:55
//                   Shipped: 2026-03-05 10:45   ← blue
//                   Delivered: 2026-03-05 14:30 ← green bold
// =====================================================================
$mergeMap = [
	'id_shipment_method'     => ['status'],                     // Method name + status badge
	'shipping_cost_tax_incl' => ['shipping_cost_tax_excl'],     // Cost incl + excl below
	'tracking_number'        => ['carrier_name'],               // Tracking code + carrier name
	'added'                  => ['shipped', 'delivered'],       // All dates stacked
];

// =====================================================================
//  RENDER MAP — Custom HTML rendering per field name
//
//  Format: 'field_name' => function($value, $shipment, $escape) → string
//
//  Each function receives:
//    $value    — The field's value from the shipment object
//    $shipment — The full shipment object (for cross-field access)
//    $escape   — Callable that escapes HTML
//
//  Return an HTML string. Fields NOT listed here fall through to:
//    1. Money objects → $value->format()
//    2. Everything else → $this->escape($value)
// =====================================================================

// Badge class lookups for shipment status values
$statusBadges = [
	'pending'   => 'bg-warning text-dark',
	'shipped'   => 'bg-info text-dark',
	'delivered' => 'bg-success',
	'cancelled' => 'bg-danger',
];

// Escape helper that can be passed into closures
$escape = function (string $value): string {
	return $this->escape($value);
};

$renderMap = [

	// ── Shipment method: #ID + snapshot name ────────────────
	// Shows the shipment record ID prominently so the admin can
	// identify which shipment is referenced in activity logs.
	// The method name comes from the snapshot (survives method deletion).
	'id_shipment_method' => function ($value, $shipment, $escape) {
		$id   = (int) ($shipment->id ?? 0);
		$name = $escape($shipment->shipment_method_name ?? $value);
		return '<strong>#' . $id . '</strong> ' . $name;
	},

	// ── Status: coloured badge ──────────────────────────────
	'status' => function ($value) use ($statusBadges) {
		$badgeClass = $statusBadges[(string) $value] ?? 'bg-secondary';
		return '<span class="badge ' . $badgeClass . '" style="font-size:0.9em">'
			. ucfirst((string) $value) . '</span>';
	},

	// ── Cost tax incl: bold ─────────────────────────────────
	'shipping_cost_tax_incl' => function ($value) {
		if (is_object($value) && method_exists($value, 'format')) {
			return '<strong>' . $value->format() . '</strong>';
		}
		return '<strong>€' . number_format((float) $value, 2, ',', '.') . '</strong>';
	},

	// ── Cost tax excl: smaller muted text ───────────────────
	'shipping_cost_tax_excl' => function ($value) {
		if (is_object($value) && method_exists($value, 'format')) {
			return '<small>' . $value->format() . '</small>';
		}
		return '<small>€' . number_format((float) $value, 2, ',', '.') . '</small>';
	},

	// ── Tracking number: monospace code styling ─────────────
	'tracking_number' => function ($value, $shipment, $escape) {
		if (!empty($value)) {
			return '<code>' . $escape($value) . '</code>';
		}
		return '<span class="text-muted">—</span>';
	},

	// ── Carrier name: plain text (empty returns nothing) ────
	'carrier_name' => function ($value, $shipment, $escape) {
		return !empty($value) ? $escape($value) : '';
	},

	// ── Items: each product on its own line ─────────────────
	// The value is a comma-separated string from getShipmentItemNames().
	// Split by comma and render each product as a separate line
	// for readability instead of one long wrapping string.
	'items' => function ($value, $shipment, $escape) {
		if (empty($value)) {
			return '<span class="text-muted">—</span>';
		}

		// Split the comma-separated string into individual products
		$products = array_map('trim', explode(',', $value));
		$lines = [];

		foreach ($products as $product) {
			if (!empty($product)) {
				$lines[] = $escape($product);
			}
		}

		return '<small>' . implode('<br>', $lines) . '</small>';
	},

	// ── Weight: formatted with kg unit ──────────────────────
	'weight' => function ($value) {
		// Handle Money objects (shouldn't be, but defensive)
		if (is_object($value) && method_exists($value, 'getAmount')) {
			$value = $value->getAmount();
		}
		return ((float) $value > 0)
			? number_format((float) $value, 2) . ' kg'
			: '<span class="text-muted">—</span>';
	},

	// ── Date added: standard formatted date ─────────────────
	'added' => function ($value) {
		return !empty($value) ? HTMLHelper::_('date', $value, 'Y-m-d H:i') : '';
	},

	// ── Shipped date: blue tinted with label ────────────────
	'shipped' => function ($value) {
		if (empty($value)) {
			return '';
		}
		return '<span class="text-info">'
			. Text::_('COM_ALFA_STATUS_SHIPPED') . ': '
			. HTMLHelper::_('date', $value, 'Y-m-d H:i')
			. '</span>';
	},

	// ── Delivered date: green bold with label ───────────────
	'delivered' => function ($value) {
		if (empty($value)) {
			return '';
		}
		return '<span class="text-success fw-bold">'
			. Text::_('COM_ALFA_STATUS_DELIVERED') . ': '
			. HTMLHelper::_('date', $value, 'Y-m-d H:i')
			. '</span>';
	},
];

// =====================================================================
//  BUILD LOOKUP TABLES
//
//  Computed once from the merge map. Used during the rendering loop
//  to skip child fields (they're rendered inside their parent's cell).
// =====================================================================
$childFields = [];
foreach ($mergeMap as $primary => $children) {
	foreach ($children as $child) {
		$childFields[$child] = $primary;
	}
}
?>

<style>
    /* Merged child fields appear smaller below the primary */
    .merge-child { font-size: 0.82em; color: #6c757d; margin-top: 2px; }
</style>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!--  SECTION HEADER: Title + Add Shipment button                   -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3>
                <span class="icon-cubes me-2"></span>
				<?php echo Text::_('COM_ALFA_SHIPMENTS'); ?>
				<?php if (!empty($shipments)) : ?>
                    <span class="badge bg-secondary"><?php echo count($shipments); ?></span>
				<?php endif; ?>
            </h3>
            <a href="#addShipmentModal" data-bs-toggle="modal" class="btn btn-success">
                <span class="icon-plus"></span> <?php echo Text::_('COM_ALFA_ADD_SHIPMENT'); ?>
            </a>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!--  SHIPMENTS TABLE                                               -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<?php if (!empty($shipments)): ?>
    <table class="table table-striped table-bordered">

        <!-- ── TABLE HEADER ────────────────────────────────────── -->
        <!-- Auto-generated from XML fields.                        -->
        <!-- Skips: hidden fields and child fields.                  -->
        <thead>
        <tr>
			<?php foreach ($shipmentFields as $field): ?>
				<?php
				$name = $field->getAttribute('name') ?? $field->fieldname ?? '';
				$type = $field->getAttribute('type');

				// Hidden fields don't get columns
				if ($type === 'hidden') continue;

				// Child fields are merged into their parent's column
				if (isset($childFields[$name])) continue;
				?>
                <th><?php echo Text::_($field->getAttribute('label')); ?></th>
			<?php endforeach; ?>

            <!-- Actions column (Edit button + plugin action buttons) -->
            <th><?php echo Text::_('COM_ALFA_ACTIONS'); ?></th>
        </tr>
        </thead>

        <!-- ── TABLE BODY ─────────────────────────────────────── -->
        <tbody id="shipment-rows">
		<?php foreach ($shipments as $shipment): ?>
			<?php
			// Modal and URL for the Edit popup
			$modalId    = 'shipmentModal-' . $shipment->id;
			$editUrl    = Route::_(sprintf(
				'index.php?option=com_alfa&view=order&layout=edit_shipment&tmpl=component&id=%d&id_order=%d',
				$shipment->id, $this->order->id
			));

			// Plugin action buttons (Mark Shipped, Delivered, etc.)
			$actions    = ActionRegistry::getShipmentActions($shipment, $this->order);
			$pluginType = $shipment->params->type ?? 'standard';
			?>
            <tr>

				<?php foreach ($shipments_subform->getGroup('') as $field): ?>
					<?php
					$fieldName = $field->fieldname;
					$fieldType = $field->getAttribute('type');

					// Same skip logic as the header
					if ($fieldType === 'hidden') continue;
					if (isset($childFields[$fieldName])) continue;

					// Get the field value from the shipment object
					$value = $shipment->{$fieldName} ?? '';
					?>
                    <td>
						<?php
						// ── STEP 1: Render the primary field ────────
						// Check renderMap for custom rendering, otherwise
						// fall through to Money::format() or escape()
						if (isset($renderMap[$fieldName])) {
							echo $renderMap[$fieldName]($value, $shipment, $escape);
						} elseif (is_object($value) && method_exists($value, 'format')) {
							echo $value->format();
						} else {
							echo $this->escape($value);
						}

						// ── STEP 2: Render merged children below ────
						// If this field has children in the mergeMap,
						// render each one inside a <div class="merge-child">
						if (isset($mergeMap[$fieldName])) {
							foreach ($mergeMap[$fieldName] as $childName) {
								$childValue = $shipment->{$childName} ?? '';

								// Apply custom renderer if available
								if (isset($renderMap[$childName])) {
									$childHtml = $renderMap[$childName]($childValue, $shipment, $escape);
								} elseif (is_object($childValue) && method_exists($childValue, 'format')) {
									$childHtml = $childValue->format();
								} else {
									$childHtml = $this->escape($childValue);
								}

								// Only output the wrapper div if there's content
								if (!empty($childHtml)) {
									echo '<div class="merge-child">' . $childHtml . '</div>';
								}
							}
						}
						?>
                    </td>
				<?php endforeach; ?>

                <!-- ── Actions cell ───────────────────────────── -->
                <td>
                    <!-- Edit button (icon-only to save space) -->
                    <div class="btn-group me-1" role="group">
                        <a href="#<?php echo $modalId; ?>"
                           data-bs-toggle="modal"
                           class="btn btn-sm btn-primary"
                           title="<?php echo Text::_('JACTION_EDIT'); ?>">
                            <span class="icon-edit"></span>
                        </a>
                    </div>

                    <!-- Plugin action buttons (Mark Shipped, Delivered, etc.) -->
					<?php if (!empty($actions)): ?>
                        <div class="btn-group" role="group">
							<?php foreach ($actions as $action):
								$buttonLayout = PluginLayoutHelper::pluginLayout(
									'alfa-shipments', $pluginType,
									$action->button_layout ?? 'action_button'
								);
								echo $buttonLayout->render([
									'action'  => $action,
									'context' => 'shipment',
									'id'      => $shipment->id,
								]);
							endforeach; ?>
                        </div>
					<?php endif; ?>

                    <!-- Bootstrap modal for the Edit popup (iframe-based) -->
					<?php
					echo HTMLHelper::_(
						'bootstrap.renderModal', $modalId,
						[
							'title'      => Text::_('COM_ALFA_EDIT_SHIPMENT'),
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
		<?php echo Text::_('COM_ALFA_NO_SHIPMENTS_YET'); ?>
    </div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!--  ADD SHIPMENT MODAL                                            -->
<!--  Opens the edit_shipment.php form with id=0 for a new record.  -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<?php
$addShipmentUrl = Route::_(sprintf(
	'index.php?option=com_alfa&view=order&layout=edit_shipment&tmpl=component&id=0&id_order=%d',
	$this->order->id
));

echo HTMLHelper::_(
	'bootstrap.renderModal', 'addShipmentModal',
	[
		'title'      => Text::_('COM_ALFA_ADD_SHIPMENT'),
		'url'        => $addShipmentUrl,
		'width'      => '80%',
		'bodyHeight' => '70vh',
	]
);
?>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!--  MODAL COMMUNICATION — postMessage between iframe and parent   -->
<!--                                                                 -->
<!--  When a shipment is saved or an action is executed inside the   -->
<!--  modal iframe, the iframe sends a postMessage to the parent:    -->
<!--    { messageType: 'alfa:shipment-action',                       -->
<!--      shouldClose: true, shouldReload: true, shipmentId: 5 }    -->
<!--                                                                 -->
<!--  This script listens for that message and:                      -->
<!--    1. Closes the modal (if shouldClose)                         -->
<!--    2. Reloads the order page (if shouldReload)                  -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<script>
    (function() {
        'use strict';

        /**
         * Listen for messages from shipment modal iframes.
         *
         * The iframe (edit_shipment.php) sends a postMessage when:
         *   - A shipment is saved (shouldReload = true)
         *   - A plugin action completes (shouldReload = true)
         *   - The modal should close (shouldClose = true)
         */
        window.addEventListener('message', function(event) {
            // Only handle messages from our shipment modals
            if (event.data.messageType !== 'alfa:shipment-action') {
                return;
            }

            // Close the modal if requested
            if (event.data.shouldClose) {
                closeModal(event.data.shipmentId);
            }

            // Reload the order page to reflect changes
            if (event.data.shouldReload) {
                reloadOrderPage();
            }
        });

        /**
         * Reload the order edit page by submitting the form with a
         * special "reload" task. This preserves the current tab state
         * and shows a Joomla loading spinner during the reload.
         */
        function reloadOrderPage() {
            var orderForm = document.querySelector('#order-form');

            if (!orderForm) {
                console.error('[Shipments] Order form not found, cannot reload');
                return;
            }

            // Show Joomla's built-in loading spinner
            document.body.appendChild(document.createElement('joomla-core-loader'));

            // Set the task to "reload" — the controller handles this by
            // re-rendering the edit page without saving
            var taskInput = orderForm.querySelector('input[name=task]');
            var url = new URL(window.location.href);
            var view = url.searchParams.get('view') || '';

            if (taskInput && view) {
                taskInput.value = view + '.reload';
            }

            orderForm.submit();
        }

        /**
         * Close a Bootstrap modal by its shipment ID.
         *
         * Maps shipmentId → modal element ID:
         *   shipmentId = 0  → 'addShipmentModal' (the "Add Shipment" modal)
         *   shipmentId = 5  → 'shipmentModal-5' (edit modal for shipment #5)
         *
         * @param {number} shipmentId  The shipment row PK (0 for new)
         */
        function closeModal(shipmentId) {
            var modalId = (shipmentId === 0)
                ? 'addShipmentModal'
                : 'shipmentModal-' + shipmentId;

            var modalElement = document.getElementById(modalId);

            if (modalElement) {
                var bsModal = bootstrap.Modal.getInstance(modalElement);
                if (bsModal) {
                    bsModal.hide();
                }
            }
        }
    })();
</script>