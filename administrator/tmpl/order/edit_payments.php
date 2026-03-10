<?php
/**
 * Order Edit — Payments Tab (Auto-Discovery + Merge Map)
 *
 * Columns are auto-generated from order_payments.xml subform fields.
 * Adding a field to the XML automatically adds it to this table.
 *
 * Three configuration arrays at the top control the layout:
 *
 *   $mergeMap    — Which fields share a table cell. The "primary" field
 *                  gets the column header; "children" render below it.
 *                  Fields not listed anywhere get their own column.
 *
 *   $renderMap   — Custom HTML rendering per field name. Provides badges,
 *                  monospace formatting, date formatting, etc. Fields not
 *                  listed fall through to the default renderer (Money
 *                  objects call ->format(), everything else gets escaped).
 *
 *   $conditionalColumns — Hides an entire merged group when ALL its values
 *                         are empty across every row. For example, the
 *                         refund column disappears when no payments have
 *                         refund data.
 *
 * To reorganize the table, just edit the three arrays above — no need
 * to touch the rendering loop below.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  3.0.0
 */

use Alfa\Component\Alfa\Administrator\Helper\ActionRegistry;
use Alfa\Component\Alfa\Administrator\Helper\PluginLayoutHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

// Load payment data from the order and the form field definitions
$payments         = $this->order->payments;
$payments_subform = $this->form->getField('payments')->loadSubForm();
$paymentFields    = $payments_subform->getFieldset();

// =====================================================================
//  MERGE MAP — Which fields share a table cell
//
//  Format: 'primary_field' => ['child1', 'child2', ...]
//
//  - The primary field name becomes the column header
//  - Children render below the primary in the same <td>
//  - Fields NOT mentioned in any group get their own column
//
//  To combine two separate columns, just add the second field
//  as a child of the first. To split them back, remove the entry.
//
//  Example: the identity column combines method + type + refund link:
//    'id_payment_method' => ['payment_type', 'id_refunded_payment']
//    Result: #5 Bank Transfer
//            [Refund]                  ← type badge below
//            ↩ Refund for #3           ← refund link (only if set)
// =====================================================================
$mergeMap = [
	'id_payment_method' => ['payment_type', 'id_refunded_payment'], // Identity: #5 method + [Refund] + "for #3"
	'transaction_id'    => ['gateway_response'],                     // Txn ID + truncated JSON
	'refund_type'       => ['refund_reason'],                        // Refund info: [Full] + reason text
	'added'             => ['id_employee'],                          // Date + employee ref
];

// =====================================================================
//  CONDITIONAL COLUMNS — Hide entire merged group when all values empty
//
//  Format: 'primary_field' => function($payments) → bool
//
//  When the callback returns false, the entire column (primary + all
//  its children) is hidden from both <th> and <td>.
//
//  This keeps the table clean: orders without refunds don't show
//  an empty "Refund Type" column.
// =====================================================================
$conditionalColumns = [
	// Hide the "Refund Type" column (+ its child "refund_reason") when
	// no payment in this order has any refund-related data.
	'refund_type' => function (array $payments): bool {
		foreach ($payments as $p) {
			if (!empty($p->refund_type) || !empty($p->refund_reason)) {
				return true;
			}
		}
		return false;
	},
];

// =====================================================================
//  RENDER MAP — Custom HTML rendering per field name
//
//  Format: 'field_name' => function($value, $payment, $escape) → string
//
//  Each function receives:
//    $value   — The field's value from the payment object
//    $payment — The full payment object (for cross-field logic)
//    $escape  — Callable that escapes HTML: $escape($string)
//
//  Return an HTML string. Fields NOT listed here fall through to:
//    1. Money objects → $value->format()
//    2. Everything else → $this->escape($value)
//
//  To add styling for a new field, just add a new entry here.
// =====================================================================

// Badge class lookups for known status/type values
$statusBadges = [
	'completed'  => 'bg-success',
	'pending'    => 'bg-warning text-dark',
	'authorized' => 'bg-info text-dark',
	'failed'     => 'bg-danger',
	'cancelled'  => 'bg-secondary',
	'refunded'   => 'bg-purple',
];

$typeBadges = [
	'payment'       => 'bg-primary',
	'refund'        => 'bg-danger',
	'authorization' => 'bg-info text-dark',
];

$refundTypeBadges = [
	'full'    => 'bg-danger',
	'partial' => 'bg-warning text-dark',
];

// Escape helper that can be passed into closures
$escape = function (string $value): string {
	return $this->escape($value);
};

$renderMap = [

	// ── Amount: bold, red prefix for refunds ────────────────
	'amount' => function ($value, $payment) {
		$isRefund = ($payment->payment_type ?? '') === 'refund';
		$class    = $isRefund ? 'text-danger fw-bold' : 'fw-bold';
		$prefix   = $isRefund ? '−' : '';

		if (is_object($value) && method_exists($value, 'format')) {
			return '<span class="' . $class . '">' . $prefix . $value->format() . '</span>';
		}

		return '<span class="' . $class . '">'
			. $prefix . '€' . number_format(abs((float) $value), 2, ',', '.')
			. '</span>';
	},

	// ── Payment type: coloured badge ────────────────────────
	'payment_type' => function ($value) use ($typeBadges) {
		$badgeClass = $typeBadges[(string) $value] ?? 'bg-secondary';
		return '<span class="badge ' . $badgeClass . '" style="font-size:0.85em">'
			. ucfirst((string) $value) . '</span>';
	},

	// ── Transaction status: coloured badge ──────────────────
	'transaction_status' => function ($value) use ($statusBadges) {
		$badgeClass = $statusBadges[(string) $value] ?? 'bg-secondary';
		return '<span class="badge ' . $badgeClass . '">'
			. ucfirst((string) $value) . '</span>';
	},

	// ── Transaction ID: monospace code styling ──────────────
	'transaction_id' => function ($value, $payment, $escape) {
		if (!empty($value)) {
			return '<code>' . $escape($value) . '</code>';
		}
		return '<span class="text-muted">—</span>';
	},

	// ── Gateway response: truncated JSON preview with hover ─
	'gateway_response' => function ($value, $payment, $escape) {
		if (empty($value)) {
			return '';
		}
		$preview = mb_strimwidth((string) $value, 0, 30, '…');
		return '<code title="' . $escape($value) . '" style="cursor:help">'
			. $escape($preview) . '</code>';
	},

	// ── Processed at: formatted datetime ────────────────────
	'processed_at' => function ($value) {
		if (!empty($value)) {
			return HTMLHelper::_('date', $value, 'Y-m-d H:i');
		}
		return '<span class="text-muted">—</span>';
	},

	// ── Refund type: coloured badge ─────────────────────────
	'refund_type' => function ($value) use ($refundTypeBadges) {
		if (empty($value)) {
			return '<span class="text-muted">—</span>';
		}
		$badgeClass = $refundTypeBadges[(string) $value] ?? 'bg-secondary';
		return '<span class="badge ' . $badgeClass . '" style="font-size:0.85em">'
			. ucfirst((string) $value) . '</span>';
	},

	// ── Refund reason: small text (empty string if no reason) ─
	'refund_reason' => function ($value, $payment, $escape) {
		if (!empty($value)) {
			return '<small>' . $escape($value) . '</small>';
		}
		return '';
	},

	// ── Refunded payment: "↩ Refund for #3" reference ──────
	// Only renders if this is a refund record that links to an original.
	// Shown inside the identity column below the payment type badge.
	'id_refunded_payment' => function ($value) {
		if (!empty($value) && (int) $value > 0) {
			return '<small>' . Text::_('COM_ALFA_REFUND_FOR') . ' <strong>#' . (int) $value . '</strong></small>';
		}
		return '';
	},

	// ── Payment method: #ID + snapshot name ─────────────────
	// Shows the payment record ID prominently so the admin can
	// identify which payment is being referenced (e.g. in refunds).
	// The method name comes from the snapshot (survives method deletion).
	'id_payment_method' => function ($value, $payment, $escape) {
		$id   = (int) ($payment->id ?? 0);
		$name = $escape($payment->payment_method ?? $value);
		return '<strong>#' . $id . '</strong> ' . $name;
	},

	// ── Employee: short reference (full name in edit popup) ──
	'id_employee' => function ($value) {
		if (!empty($value)) {
			return '<small class="text-muted">by #' . (int) $value . '</small>';
		}
		return '';
	},

	// ── Date added: formatted datetime ──────────────────────
	'added' => function ($value) {
		if (!empty($value)) {
			return HTMLHelper::_('date', $value, 'Y-m-d H:i');
		}
		return '';
	},
];

// =====================================================================
//  BUILD LOOKUP TABLES
//
//  These are computed once from the merge map and used during the
//  rendering loop to decide which fields to skip.
// =====================================================================

// Build a reverse lookup: child field name → its parent field name.
// During the loop, if a field is a child, we skip it (it's rendered
// inside its parent's <td> via the mergeMap).
$childFields = [];
foreach ($mergeMap as $primary => $children) {
	foreach ($children as $child) {
		$childFields[$child] = $primary;
	}
}

// Evaluate conditional columns: run each callback to decide visibility.
// If a primary is hidden, all its children are hidden too.
$hiddenGroups = [];
foreach ($conditionalColumns as $primary => $checkCallback) {
	if (!$checkCallback($payments)) {
		$hiddenGroups[$primary] = true;
		foreach ($mergeMap[$primary] ?? [] as $child) {
			$hiddenGroups[$child] = true;
		}
	}
}
?>

<style>
    /* Custom badge colour for "refunded" status */
    .bg-purple { background-color: #6f42c1 !important; color: #fff; }

    /* Merged child fields appear smaller below the primary */
    .merge-child { font-size: 0.82em; color: #6c757d; margin-top: 2px; }
</style>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!--  SECTION HEADER: Title + Add Payment button                    -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3>
                <span class="icon-credit me-2"></span>
				<?php echo Text::_('COM_ALFA_PAYMENTS'); ?>
				<?php if (!empty($payments)) : ?>
                    <span class="badge bg-secondary"><?php echo count($payments); ?></span>
				<?php endif; ?>
            </h3>
            <a href="#addPaymentModal" data-bs-toggle="modal" class="btn btn-success">
                <span class="icon-plus"></span> <?php echo Text::_('COM_ALFA_ADD_PAYMENT'); ?>
            </a>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!--  PAYMENTS TABLE                                                -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<?php if (!empty($payments)): ?>
    <table class="table table-striped table-bordered">

        <!-- ── TABLE HEADER ────────────────────────────────────── -->
        <!-- Auto-generated from XML fields.                        -->
        <!-- Skips: hidden fields, child fields, hidden groups.     -->
        <thead>
        <tr>
			<?php foreach ($paymentFields as $field): ?>
				<?php
				$name = $field->getAttribute('name') ?? $field->fieldname ?? '';
				$type = $field->getAttribute('type');

				// Hidden fields don't get columns
				if ($type === 'hidden') continue;

				// Child fields are merged into their parent's column
				if (isset($childFields[$name])) continue;

				// Conditional columns hidden when all values empty
				if (isset($hiddenGroups[$name])) continue;
				?>
                <th><?php echo Text::_($field->getAttribute('label')); ?></th>
			<?php endforeach; ?>

            <!-- Actions column (Edit button + plugin action buttons) -->
            <th><?php echo Text::_('COM_ALFA_ACTIONS'); ?></th>
        </tr>
        </thead>

        <!-- ── TABLE BODY ─────────────────────────────────────── -->
        <tbody id="payment-rows">
		<?php foreach ($payments as $payment): ?>
			<?php
			// Modal and URL for the Edit popup
			$modalId    = 'paymentModal-' . $payment->id;
			$editUrl    = Route::_(sprintf(
				'index.php?option=com_alfa&view=order&layout=edit_payment&tmpl=component&id=%d&id_order=%d',
				$payment->id, $this->order->id
			));

			// Plugin action buttons (Mark Paid, Refund, etc.)
			$actions    = ActionRegistry::getPaymentActions($payment, $this->order);
			$pluginType = $payment->params->type ?? 'standard';

			// Refund rows get a yellow background highlight
			$isRefund = ($payment->payment_type ?? '') === 'refund';
			?>
            <tr class="<?php echo $isRefund ? 'table-warning' : ''; ?>">

				<?php foreach ($payments_subform->getGroup('') as $field): ?>
					<?php
					$fieldName = $field->fieldname;
					$fieldType = $field->getAttribute('type');

					// Same skip logic as the header
					if ($fieldType === 'hidden') continue;
					if (isset($childFields[$fieldName])) continue;
					if (isset($hiddenGroups[$fieldName])) continue;

					// Get the field value from the payment object
					$value = $payment->{$fieldName} ?? '';
					?>
                    <td>
						<?php
						// ── STEP 1: Render the primary field ────────
						// Check renderMap for custom rendering, otherwise
						// fall through to Money::format() or escape()
						if (isset($renderMap[$fieldName])) {
							echo $renderMap[$fieldName]($value, $payment, $escape);
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
								$childValue = $payment->{$childName} ?? '';

								// Apply custom renderer if available
								if (isset($renderMap[$childName])) {
									$childHtml = $renderMap[$childName]($childValue, $payment, $escape);
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

                    <!-- Plugin action buttons (Mark Paid, Refund, etc.) -->
					<?php if (!empty($actions)): ?>
                        <div class="btn-group" role="group">
							<?php foreach ($actions as $action):
								$buttonLayout = PluginLayoutHelper::pluginLayout(
									'alfa-payments', $pluginType,
									$action->button_layout ?? 'action_button'
								);
								echo $buttonLayout->render([
									'action'  => $action,
									'context' => 'payment',
									'id'      => $payment->id,
								]);
							endforeach; ?>
                        </div>
					<?php endif; ?>

                    <!-- Bootstrap modal for the Edit popup (iframe-based) -->
					<?php
					echo HTMLHelper::_(
						'bootstrap.renderModal', $modalId,
						[
							'title'      => Text::_('COM_ALFA_EDIT_PAYMENT'),
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
		<?php echo Text::_('COM_ALFA_NO_PAYMENTS_YET'); ?>
    </div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!--  ADD PAYMENT MODAL                                             -->
<!--  Opens the edit_payment.php form with id=0 for a new record.   -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<?php
$addPaymentUrl = Route::_(sprintf(
	'index.php?option=com_alfa&view=order&layout=edit_payment&tmpl=component&id=0&id_order=%d',
	$this->order->id
));

echo HTMLHelper::_(
	'bootstrap.renderModal', 'addPaymentModal',
	[
		'title'      => Text::_('COM_ALFA_ADD_PAYMENT'),
		'url'        => $addPaymentUrl,
		'width'      => '80%',
		'bodyHeight' => '70vh',
	]
);
?>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!--  MODAL COMMUNICATION — postMessage between iframe and parent   -->
<!--                                                                 -->
<!--  When a payment is saved or an action is executed inside the    -->
<!--  modal iframe, the iframe sends a postMessage to the parent:    -->
<!--    { messageType: 'alfa:payment-action',                        -->
<!--      shouldClose: true, shouldReload: true, paymentId: 5 }     -->
<!--                                                                 -->
<!--  This script listens for that message and:                      -->
<!--    1. Closes the modal (if shouldClose)                         -->
<!--    2. Reloads the order page (if shouldReload)                  -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<script>
    (function() {
        'use strict';

        /**
         * Listen for messages from payment modal iframes.
         *
         * The iframe (edit_payment.php) sends a postMessage when:
         *   - A payment is saved (shouldReload = true)
         *   - A plugin action completes (shouldReload = true)
         *   - The modal should close (shouldClose = true)
         */
        window.addEventListener('message', function(event) {
            // Only handle messages from our payment modals
            if (event.data.messageType !== 'alfa:payment-action') {
                return;
            }

            // Close the modal if requested
            if (event.data.shouldClose) {
                closeModal(event.data.paymentId);
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
                console.error('[Payments] Order form not found, cannot reload');
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
         * Close a Bootstrap modal by its payment ID.
         *
         * Maps paymentId → modal element ID:
         *   paymentId = 0  → 'addPaymentModal' (the "Add Payment" modal)
         *   paymentId = 5  → 'paymentModal-5' (edit modal for payment #5)
         *
         * @param {number} paymentId  The payment row PK (0 for new)
         */
        function closeModal(paymentId) {
            var modalId = (paymentId === 0)
                ? 'addPaymentModal'
                : 'paymentModal-' + paymentId;

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