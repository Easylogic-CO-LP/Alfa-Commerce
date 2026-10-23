<?php

/**
 * Alfa Commerce: order reconciliation panel.
 *
 * Path: administrator/components/com_alfa/tmpl/order/edit_reconciliation.php
 *
 * PRESENTATION ONLY. This panel reads ReconciliationFacts and states what it
 * finds. It computes no money, decides nothing, and offers no action: issuing
 * a correction, voiding, refunding or crediting all belong to workflows this
 * screen does not have.
 *
 * It is always here, and that is the point
 *
 * This panel is the canonical explanation of an order's document/payment state,
 * not an error box. It never hides itself, because "green" is an answer an
 * operator needs to be able to READ, and because a green verdict does not mean
 * there is nothing to see: an order may be perfectly reconciled and still have
 * unused invoicing capacity, an unsettled credit note, or no documents at all.
 *
 * So it renders in every case and states which case it is. Healthy orders come
 * COLLAPSED with their verdict in the summary; anything worth a second look
 * comes OPEN with the reason already on screen. The collapse is a native
 * <details>, so it costs no JavaScript and no state of its own.
 *
 * FIVE STATES AN OPERATOR MUST BE ABLE TO TELL APART, and the headline says
 * which one it is:
 *
 *   no issued document history   nothing has been documented yet
 *   reconciled                   documented and sound
 *   partially documented         sound, with capacity left to invoice
 *   needs reconciliation         a DEFECT
 *   financially outstanding      overpaid, or a credit note not yet settled
 *
 * Why it is not one red dot
 *
 * Three unrelated things can be true of an order at once, and an operator needs
 * to tell them apart before deciding anything:
 *
 *   a DEFECT       a document claimed more than existed, or froze a fact the
 *                  order has since contradicted. Something is wrong.
 *   WORK           a credit note is issued and the customer has not had the
 *                  money yet. Nothing is wrong; someone owes an action.
 *   WORK           the shop holds more than the order now asks for. Same.
 *
 * So "sales = FULL, credit = NONE, needs_reconciliation = false" renders as a
 * healthy order with nothing outstanding, and an unsettled credit note renders
 * as an amber task rather than a red fault.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Service\Order\ReconciliationFacts;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

$order = $this->item ?? null;
$rf    = $order->reconciliation_facts ?? null;

if ($rf === null) {
    return;
}

// which of the five states is this, and is it worth opening?
$hasMismatch   = $rf->mismatches !== [];
$unsettled     = \in_array($rf->credit_settlement->state, [
    ReconciliationFacts::STATE_NONE,
    ReconciliationFacts::STATE_PARTIAL,
], true);
$undocumented  = (int) $rf->issued_documents === 0;

// OPEN when there is something to act on. A healthy order stays shut, but it
// still says so; collapsed is not the same as absent.
$attention = $rf->needs_reconciliation || $rf->overpaid || $unsettled;

// Is there anything an INCREMENTAL correction could address? PARTIAL means the
// order now holds more than its documents cover; OVER means it holds less.
// Both are drift; neither is read from money here; the state is a fact the
// projection already decided, and this panel only reports.
// A member that over-claims is drift too, even when its pool balances: the
// projection lists it, and this panel only reads that list.
$memberAnomalies = [];

foreach (ReconciliationFacts::POOLS as $poolName) {
    foreach ($rf->coverage->sales->pools->{$poolName}->anomalies ?? [] as $member) {
        $memberAnomalies[] = $member;
    }
}

$correctable = \in_array($rf->coverage->sales->state, [
    ReconciliationFacts::STATE_PARTIAL,
    ReconciliationFacts::STATE_OVER,
], true) || $memberAnomalies !== [];

// Asking a permission is presentation, not arithmetic: it decides whether to
// draw a control, nothing else. It is the SAME pair ordinary manual issuance
// answers to, because a correction is an ordinary issuance of an ordinary
// document, and because that pair is what the Documents panel guards its
// postMessage listener on, the listener is present exactly when this button
// is. The modal enforces the permission again, at its own layout and service.
// And only while Alfa issues the documents: with documents_issuer external the
// Documents panel, and with it that listener, is not drawn, so a correction
// modal opened then could never close (F-34).
$canCorrect = DocumentHelper::isIssuerEnabled()
    && AlfaHelper::can(section: 'orders', action: 'edit', user: $this->getCurrentUser())
    && AlfaHelper::can(section: 'documents', action: 'create', user: $this->getCurrentUser());

$headline = match (true) {
    $rf->needs_reconciliation => ['COM_ALFA_RECON_NEEDED', 'bg-danger', 'needs_reconciliation'],
    $rf->overpaid, $unsettled => ['COM_ALFA_RECON_OUTSTANDING', 'bg-warning text-dark', 'financially_outstanding'],
    $undocumented             => ['COM_ALFA_RECON_UNDOCUMENTED', 'bg-secondary', 'no_document_history'],
    $rf->coverage->sales->state === ReconciliationFacts::STATE_PARTIAL
                              => ['COM_ALFA_RECON_PARTIALLY_DOCUMENTED', 'bg-success', 'partially_documented'],
    default                   => ['COM_ALFA_RECON_OK', 'bg-success', 'reconciled'],
};

/** A coverage state as its badge class; NONE and PARTIAL are NOT warnings. */
$stateClass = static function (string $state): string {
    return match ($state) {
        ReconciliationFacts::STATE_OVER => 'bg-danger',
        ReconciliationFacts::STATE_FULL => 'bg-success',
        ReconciliationFacts::STATE_PARTIAL => 'bg-info',
        ReconciliationFacts::STATE_NONE => 'bg-secondary',
        default => 'bg-light text-muted',
    };
};

$money = static fn ($value): string => number_format((float) $value, (int) $rf->currency_scale, ',', '.');

$semantics = [
    'sales'  => Text::_('COM_ALFA_RECON_POOL_SALES'),
    'credit' => Text::_('COM_ALFA_RECON_POOL_CREDIT'),
];

// The operator reads words, never the facts' codes: each state, mismatch kind and
// reason is said by its label, and the code stays in a data attribute beside it.
$stateLabel = static fn (string $state): string => match ($state) {
    ReconciliationFacts::STATE_NONE => Text::_('COM_ALFA_RECON_STATE_NONE'),
    ReconciliationFacts::STATE_PARTIAL => Text::_('COM_ALFA_RECON_STATE_PARTIAL'),
    ReconciliationFacts::STATE_FULL => Text::_('COM_ALFA_RECON_STATE_FULL'),
    ReconciliationFacts::STATE_OVER => Text::_('COM_ALFA_RECON_STATE_OVER'),
    ReconciliationFacts::STATE_NOT_APPLICABLE => Text::_('COM_ALFA_RECON_STATE_NOT_APPLICABLE'),
    default => $state,
};

$kindLabel = static fn (string $kind): string => match ($kind) {
    ReconciliationFacts::MISMATCH_TAX_RATE => Text::_('COM_ALFA_RECON_MISMATCH_TAX_RATE'),
    ReconciliationFacts::MISMATCH_ITEM_IDENTITY => Text::_('COM_ALFA_RECON_MISMATCH_ITEM_IDENTITY'),
    ReconciliationFacts::MISMATCH_COMBINATION_IDENTITY => Text::_('COM_ALFA_RECON_MISMATCH_COMBINATION_IDENTITY'),
    default => $kind,
};

/** A reason is "<code>:<coverage>.<pool>", or "<code>:<mismatch kind>". */
$reasonLabel = static function (string $reason) use ($semantics, $kindLabel): string {
    [$code, $subject] = array_pad(explode(':', $reason, 2), 2, '');
    [$semantic, $pool] = array_pad(explode('.', $subject, 2), 2, '');
    $where = static fn (string $key): string => Text::sprintf(
        $key,
        $semantics[$semantic] ?? $semantic,
        Text::_('COM_ALFA_RECON_POOL_' . strtoupper($pool)),
    );

    return match ($code) {
        ReconciliationFacts::REASON_OVER_CLAIMED => $where('COM_ALFA_RECON_REASON_POOL_OVER_CLAIMED'),
        ReconciliationFacts::REASON_NEGATIVE_REMAINING => $where('COM_ALFA_RECON_REASON_NEGATIVE_REMAINING'),
        ReconciliationFacts::REASON_MEMBER_OVER_CLAIMED => $where('COM_ALFA_RECON_REASON_MEMBER_OVER_CLAIMED'),
        ReconciliationFacts::REASON_SEMANTIC_MISMATCH => Text::sprintf('COM_ALFA_RECON_REASON_SEMANTIC_MISMATCH', $kindLabel($subject)),
        default => $reason,
    };
};
?>

<div class="card shadow-sm mt-4" id="alfa-reconciliation-panel">
	<!-- Native <details>: collapsed when healthy, open when there is something
	     to act on, and no JavaScript or stored UI state either way. -->
	<details<?php echo $attention ? ' open' : ''; ?>
	         data-alfa-recon-open="<?php echo $attention ? 'true' : 'false'; ?>">
		<summary class="card-header bg-body-tertiary py-2 d-flex justify-content-between align-items-center"
		         style="cursor:pointer; list-style:revert">
			<h5 class="card-title mb-0 d-inline">
				<span class="icon-checkbox-partial me-2" aria-hidden="true"></span>
				<?php echo Text::_('COM_ALFA_RECON_HEADING'); ?>
			</h5>

			<span data-alfa-recon-verdict="<?php echo $rf->needs_reconciliation ? 'true' : 'false'; ?>">
				<!-- The verdict, always stated, so green is readable and not
				     merely the absence of red. -->
				<span class="badge <?php echo $headline[1]; ?>"
				      data-alfa-recon-headline="<?php echo $headline[2]; ?>">
					<?php echo Text::_($headline[0]); ?>
				</span>
				<?php // The verdict badge only where the headline is a different
				      // statement ("Financially outstanding - Reconciled"): where
				      // the headline IS the verdict it printed the same words twice. ?>
				<?php if (!\in_array($headline[2], ['needs_reconciliation', 'reconciled'], true)) : ?>
					<span class="badge <?php echo $rf->needs_reconciliation ? 'bg-danger' : 'bg-success'; ?> ms-1">
						<?php echo $rf->needs_reconciliation
							? Text::_('COM_ALFA_RECON_NEEDED')
							: Text::_('COM_ALFA_RECON_OK'); ?>
					</span>
				<?php endif; ?>
			</span>
		</summary>

		<?php if ($correctable && $canCorrect) : ?>
			<?php // section 4A's entrance. A LINK, deliberately: this panel is
			      // presentation only, so it opens the correction surface and
			      // decides nothing. The modal re-derives the proposal, shows
			      // the exact figures, and refuses with 403 for an operator who
			      // may not issue; permission is enforced there, at the
			      // service and the layout, never by hiding a button. ?>
			<div class="card-body border-bottom py-2">
				<a class="btn btn-sm btn-primary"
				   data-alfa-recon-correct="<?php echo (int) $order->id; ?>"
				   href="#correctionModal" data-bs-toggle="modal">
					<span class="icon-wrench" aria-hidden="true"></span>
					<?php echo Text::_('COM_ALFA_CORRECTION_ACTION_REVIEW'); ?>
				</a>
				<span class="small text-muted ms-2">
					<?php echo Text::_('COM_ALFA_CORRECTION_PANEL_HINT'); ?>
				</span>
			</div>
			<?php
			echo HTMLHelper::_(
				'bootstrap.renderModal', 'correctionModal',
				[
					'title'      => Text::_('COM_ALFA_CORRECTION_MODAL_TITLE'),
					'url'        => Route::_(sprintf(
						'index.php?option=com_alfa&view=order&layout=edit_correction&tmpl=component&id_order=%d&id_source=%d',
						(int) $order->id,
						// NO source is pre-named. Which document a credit
						// corrects is a fiscal attribution the operator makes
						// - the same ruling the Issue modal's picker follows,
						// where nothing is pre-selected. From here a POSITIVE
						// correction needs no source; a negative one is opened
						// from the document it credits, on the row below.
						0,
					)),
					'width'      => '80%',
					'bodyHeight' => '70vh',
				]
			);
			?>
		<?php endif; ?>

	<div class="card-body">

		<!-- NO HISTORY IS A STATE, NOT AN EMPTY PANEL. Every pool reads NONE
		     both when nothing has been invoiced and when nothing exists to
		     invoice, so the difference is said in words. -->
		<?php if ($undocumented) : ?>
			<p class="text-muted" data-alfa-recon-undocumented>
				<span class="icon-info-circle me-1" aria-hidden="true"></span>
				<?php echo Text::_('COM_ALFA_RECON_UNDOCUMENTED_HINT'); ?>
			</p>
		<?php endif; ?>

		<!-- Why, when there is a why. Never a bare verdict. -->
		<?php if ($rf->reconciliation_reasons !== []) : ?>
			<ul class="list-unstyled mb-3" data-alfa-recon-reasons>
				<?php foreach ($rf->reconciliation_reasons as $reason) : ?>
					<li class="text-danger" data-alfa-recon-reason="<?php echo htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'); ?>">
						<span class="icon-warning me-1" aria-hidden="true"></span>
						<?php echo htmlspecialchars($reasonLabel($reason), ENT_QUOTES, 'UTF-8'); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<!-- Coverage, one named semantic per block -->
		<?php foreach ($semantics as $key => $label) : ?>
			<?php $semantic = $rf->coverage->{$key}; ?>
			<h6 class="mt-2 mb-1">
				<?php echo $label; ?>
				<span class="badge <?php echo $stateClass($semantic->state); ?> ms-1"
				      data-alfa-recon-semantic="<?php echo $key; ?>"
				      data-alfa-recon-state="<?php echo htmlspecialchars($semantic->state, ENT_QUOTES, 'UTF-8'); ?>">
					<?php echo htmlspecialchars($stateLabel($semantic->state), ENT_QUOTES, 'UTF-8'); ?>
				</span>
			</h6>
			<table class="table table-sm mb-3">
				<thead>
					<tr>
						<th><?php echo Text::_('COM_ALFA_RECON_POOL'); ?></th>
						<th class="text-end"><?php echo Text::_('COM_ALFA_RECON_CAPACITY'); ?></th>
						<th class="text-end"><?php echo Text::_('COM_ALFA_RECON_CONSUMED'); ?></th>
						<th class="text-end"><?php echo Text::_('COM_ALFA_RECON_REMAINING'); ?></th>
						<th><?php echo Text::_('COM_ALFA_RECON_STATE'); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach (ReconciliationFacts::POOLS as $poolName) : ?>
					<?php $pool = $semantic->pools->{$poolName}; ?>
					<tr data-alfa-recon-pool="<?php echo $key . '.' . $poolName; ?>">
						<td><?php echo Text::_('COM_ALFA_RECON_POOL_' . strtoupper($poolName)); ?></td>
						<td class="text-end"><?php echo $money($pool->capacity); ?></td>
						<td class="text-end"><?php echo $money($pool->consumed); ?></td>
						<!-- SIGNED, never floored: an over-claim must stay visible
						     as the negative it is. -->
						<td class="text-end <?php echo (float) $pool->remaining < 0 ? 'text-danger fw-bold' : ''; ?>"
						    data-alfa-recon-remaining="<?php echo $key . '.' . $poolName; ?>">
							<?php echo $money($pool->remaining); ?>
						</td>
						<td>
							<span class="badge <?php echo $stateClass($pool->state); ?>"
							      data-alfa-recon-state="<?php echo htmlspecialchars($pool->state, ENT_QUOTES, 'UTF-8'); ?>">
								<?php echo htmlspecialchars($stateLabel($pool->state), ENT_QUOTES, 'UTF-8'); ?>
							</span>
						</td>
					</tr>
					<!-- The members that over-claim, each on its own row: the
					     pool total above can balance while one of them does not. -->
					<?php foreach ($pool->anomalies ?? [] as $member) : ?>
						<tr class="small" data-alfa-recon-member="<?php echo $key . '.' . $poolName . ':' . (int) $member->source; ?>">
							<td class="ps-4">
								&#8627; <?php echo htmlspecialchars($member->label !== ''
									? $member->label
									: Text::_('COM_ALFA_RECON_MEMBER_' . strtoupper($poolName)), ENT_QUOTES, 'UTF-8'); ?>
							</td>
							<td class="text-end"><?php echo $money($member->capacity); ?></td>
							<td class="text-end"><?php echo $money($member->consumed); ?></td>
							<td class="text-end text-danger fw-bold"><?php echo $money($member->remaining); ?></td>
							<td>
								<span class="badge <?php echo $stateClass($member->state); ?>"
								      data-alfa-recon-state="<?php echo htmlspecialchars($member->state, ENT_QUOTES, 'UTF-8'); ?>">
									<?php echo htmlspecialchars($stateLabel($member->state), ENT_QUOTES, 'UTF-8'); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>

		<?php if ($memberAnomalies !== [] && $rf->coverage->sales->state !== ReconciliationFacts::STATE_OVER) : ?>
			<p class="text-danger small mb-3" data-alfa-recon-member-hint>
				<span class="icon-warning me-1" aria-hidden="true"></span>
				<?php echo Text::_('COM_ALFA_RECON_MEMBER_HINT'); ?>
			</p>
		<?php endif; ?>

		<!-- Independent aliases, each its own pool -->
		<?php if ($rf->coverage->independent->applicable) : ?>
			<h6 class="mt-2 mb-1"><?php echo Text::_('COM_ALFA_RECON_POOL_INDEPENDENT'); ?></h6>
			<!-- THE TYPE'S OWN NAME, never its alias. "core.delivery_note" is a
			     registry key, not something to put in front of an operator,
			     and the state beside it means nothing without saying what the
			     axis IS, so the hint says it in one line. -->
			<p class="text-muted small mb-1"><?php echo Text::_('COM_ALFA_RECON_POOL_INDEPENDENT_HINT'); ?></p>
			<p class="mb-3">
				<?php foreach ($rf->coverage->independent->aliases as $alias => $entry) : ?>
					<span class="badge <?php echo $stateClass($entry->state); ?> me-1"
					      data-alfa-recon-independent="<?php echo htmlspecialchars($alias, ENT_QUOTES, 'UTF-8'); ?>">
						<?php echo htmlspecialchars(DocumentHelper::typeLabel($alias), ENT_QUOTES, 'UTF-8'); ?>:
						<?php echo $entry->state === ReconciliationFacts::STATE_NONE
							? Text::_('COM_ALFA_RECON_NOTHING_ISSUED')
							: htmlspecialchars($stateLabel($entry->state), ENT_QUOTES, 'UTF-8'); ?>
					</span>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>

		<!-- Uncounted: named, so its absence is not read as a gap -->
		<?php if ($rf->coverage->uncounted->aliases !== []) : ?>
			<p class="text-muted small mb-3" data-alfa-recon-uncounted>
				<?php echo Text::_('COM_ALFA_RECON_UNCOUNTED'); ?>
				<?php echo htmlspecialchars(implode(', ', array_map(
					static fn (string $alias): string => DocumentHelper::typeLabel($alias),
					$rf->coverage->uncounted->aliases,
				)), ENT_QUOTES, 'UTF-8'); ?>
			</p>
		<?php endif; ?>

		<!-- Semantic mismatches: not money, so not in a money table -->
		<?php if ($rf->mismatches !== []) : ?>
			<?php // A tax rate is a percentage and is printed as one; the other kinds are ids.
			$states = static fn (object $mismatch, $value): string => $mismatch->kind === ReconciliationFacts::MISMATCH_TAX_RATE
				? AlfaHelper::percentDisplay(value: $value)
				: (string) $value; ?>
			<h6 class="mt-2 mb-1 text-danger"><?php echo Text::_('COM_ALFA_RECON_MISMATCHES'); ?></h6>
			<ul class="list-unstyled mb-3" data-alfa-recon-mismatches>
				<?php foreach ($rf->mismatches as $mismatch) : ?>
					<?php // A charge names no order line: it is named by its pool
					      // and the label its document printed. ?>
					<?php $charge = $mismatch->line_type === DocumentHelper::LINE_SHIPPING
						|| $mismatch->line_type === DocumentHelper::LINE_FEE; ?>
					<li<?php if ($charge) : ?> data-alfa-recon-mismatch-charge="<?php echo htmlspecialchars($mismatch->line_type . ':' . (int) $mismatch->id_source, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
						<span data-alfa-recon-mismatch-kind="<?php echo htmlspecialchars($mismatch->kind, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($kindLabel($mismatch->kind), ENT_QUOTES, 'UTF-8'); ?></span>
						<?php if ($charge) : ?>
							- <?php echo Text::_($mismatch->line_type === DocumentHelper::LINE_FEE
								? 'COM_ALFA_RECON_POOL_FEES'
								: 'COM_ALFA_RECON_POOL_SHIPPING'); ?>
							<strong><?php echo htmlspecialchars((string) $mismatch->label, ENT_QUOTES, 'UTF-8'); ?></strong>
						<?php endif; ?>
						- <?php echo Text::_('COM_ALFA_RECON_ORDER_STATES'); ?>
						<strong><?php echo htmlspecialchars($states($mismatch, $mismatch->order_states), ENT_QUOTES, 'UTF-8'); ?></strong>,
						<?php echo Text::_('COM_ALFA_RECON_DOCUMENT_STATES'); ?>
						<strong><?php echo htmlspecialchars($states($mismatch, $mismatch->document_states), ENT_QUOTES, 'UTF-8'); ?></strong>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<!-- The two OTHER axes. Work, not faults. -->
		<div class="row">
			<div class="col-md-6">
				<h6 class="mb-1"><?php echo Text::_('COM_ALFA_RECON_OVERPAYMENT'); ?></h6>
				<?php if ($rf->overpaid) : ?>
					<p class="mb-0" data-alfa-recon-overpaid="true">
						<span class="badge bg-warning text-dark"><?php echo Text::_('COM_ALFA_OVERPAID'); ?></span>
						<strong class="ms-1"><?php echo $money($rf->excess); ?></strong>
					</p>
				<?php elseif ((float) $rf->balance > 0.0) : ?>
					<!-- NOT OVERPAID IS NOT THE SAME AS SETTLED. An order nobody
					     has paid is also "not overpaid", and saying the shop
					     holds exactly what it asks for would be a plain untruth
					     on the commonest order there is. -->
					<p class="text-muted mb-0" data-alfa-recon-overpaid="false" data-alfa-recon-due>
						<?php echo Text::_('COM_ALFA_RECON_STILL_DUE'); ?>
						<strong><?php echo $money($rf->balance); ?></strong>
					</p>
				<?php else : ?>
					<p class="text-muted mb-0" data-alfa-recon-overpaid="false" data-alfa-recon-settled>
						<?php echo Text::_('COM_ALFA_RECON_NO_OVERPAYMENT'); ?>
					</p>
				<?php endif; ?>
			</div>

			<div class="col-md-6">
				<h6 class="mb-1"><?php echo Text::_('COM_ALFA_RECON_SETTLEMENT'); ?></h6>
				<?php if ($rf->credit_settlement->state === ReconciliationFacts::STATE_NOT_APPLICABLE) : ?>
					<p class="text-muted mb-0" data-alfa-recon-settlement="NOT_APPLICABLE">
						<?php echo Text::_('COM_ALFA_RECON_NO_CREDIT_NOTES'); ?>
					</p>
				<?php else : ?>
					<p class="mb-1" data-alfa-recon-settlement="<?php echo $rf->credit_settlement->state; ?>">
						<span class="badge <?php echo $rf->credit_settlement->state === ReconciliationFacts::STATE_FULL
							? 'bg-success' : 'bg-warning text-dark'; ?>">
							<?php echo htmlspecialchars($stateLabel($rf->credit_settlement->state), ENT_QUOTES, 'UTF-8'); ?>
						</span>
						<span class="ms-1">
							<?php echo Text::_('COM_ALFA_RECON_UNSETTLED'); ?>
							<strong><?php echo $money($rf->credit_settlement->remaining_unsettled); ?></strong>
						</span>
					</p>
					<ul class="list-unstyled small mb-0">
					<?php foreach ($rf->credit_settlement->documents as $documentId => $note) : ?>
						<li data-alfa-recon-note="<?php echo (int) $documentId; ?>">
							#<?php echo (int) $documentId; ?>:
							<?php echo $money($note->credit_amount); ?>
							(<?php echo Text::_('COM_ALFA_RECON_REFUNDED'); ?> <?php echo $money($note->refunded); ?>,
							<?php echo Text::_('COM_ALFA_RECON_WALLET'); ?> <?php echo $money($note->wallet); ?>)
							<span class="badge bg-light text-dark"
							      data-alfa-recon-state="<?php echo htmlspecialchars($note->state, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($stateLabel($note->state), ENT_QUOTES, 'UTF-8'); ?></span>
						</li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
	</div>
	</details>
</div>
