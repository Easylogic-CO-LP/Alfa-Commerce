<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The proposed incremental correction, previewed: rendered inside a modal
 * iframe, the same shell every other order popup uses.
 *
 * The document reconciliation contract, section 6: "Before
 * committing any document reconciliation, show the exact proposed result...
 * No client-side financial approximation." Every figure on this page came from
 * DocumentIssuer::preview(), which is the issuer's own calculator; this
 * template performs no arithmetic of any kind.
 *
 * Path: administrator/components/com_alfa/tmpl/order/edit_correction.php
 *
 * @since  2.0.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Service\Document\CorrectionStrategies;
use Alfa\Component\Alfa\Administrator\Service\Document\IncrementalCorrection;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$wa = $this->document->getWebAssetManager();
// form.validate: the Issue button is a save, and a save asks Joomla's form
// validator before it submits; without it the click threw in core.js and
// nothing was ever issued from this modal.
$wa->useScript('keepalive')
	->useScript('form.validate')
	->useScript('com_alfa.admin')
	->useStyle('com_alfa.admin');

$orderID   = (int) $this->order->id;
$sourceID  = (int) $this->sourceDocumentId;
$proposal  = $this->proposal;
$preview   = $proposal->preview;

// At the preview's own frozen scale: the figures the operator confirms are the
// ones that will be frozen, to the last minor unit. Called only on an
// available proposal, which always carries its preview.
$money = static fn ($value): string => number_format((float) $value, (int) $preview->currency_scale, ',', '.');

?>

    <!-- Toolbar -->
    <div class="subhead noshadow mb-3">
		<?php echo $this->getDocument()->getToolbar('toolbar')->render(); ?>
    </div>

    <div class="container-popup">

        <form
                action="<?php echo Route::_("index.php?option=com_alfa&view=order&layout=edit_correction&tmpl=component&id_order={$orderID}&id_source={$sourceID}"); ?>"
                method="post"
                name="adminForm"
                id="correction-form"
                class="form-validate">

			<?php if (!$proposal->available) : ?>
				<?php // section 5: the operator is told WHY, in the words of whatever
				      // refused it: the strategy engine, the issuer, or the
				      // fact that there is simply nothing to correct. ?>
                <div class="alert alert-info">
                    <span class="icon-info-circle" aria-hidden="true"></span>
					<?php echo Text::_('COM_ALFA_CORRECTION_REASON_' . strtoupper($proposal->reason)); ?>
					<?php if ($proposal->detail !== '') : ?>
                        <div class="small text-muted mt-1"><?php echo $this->escape($proposal->detail); ?></div>
					<?php endif; ?>
                </div>
			<?php else : ?>

                <div class="alert alert-<?php echo $proposal->direction === IncrementalCorrection::DIRECTION_NEGATIVE ? 'warning' : 'success'; ?>">
					<?php echo Text::_(
						$proposal->direction === IncrementalCorrection::DIRECTION_NEGATIVE
							? 'COM_ALFA_CORRECTION_DIRECTION_NEGATIVE'
							: 'COM_ALFA_CORRECTION_DIRECTION_POSITIVE'
					); ?>
                </div>

				<?php // The type and the series it is issued on, named before the
				      // operator confirms: a credit takes the credit type paired
				      // with the corrected document's type, an additional sales
				      // document the order's own sales type. ?>
				<?php
				$issuedAs = (string) $proposal->draft['doc_type'];
				$series = array_values(array_filter(
					DocumentHelper::seriesForType($issuedAs),
					static fn (object $row): bool => (int) $row->id === (int) $proposal->draft['id_series'],
				))[0] ?? null;
				$seriesLabel = $series === null ? '' : (string) $series->code
					. ((string) $series->title !== '' ? ' (' . $series->title . ')' : '');
				?>
                <p data-alfa-correction-type="<?php echo $this->escape($issuedAs); ?>">
					<?php echo Text::sprintf(
						'COM_ALFA_CORRECTION_ISSUED_AS',
						'<strong>' . $this->escape(DocumentHelper::typeLabel($issuedAs)) . '</strong>',
						$this->escape($seriesLabel),
					); ?>
                </p>

                <table class="table table-striped table-bordered">
                    <thead>
                    <tr>
                        <th><?php echo Text::_('COM_ALFA_DOCUMENTS_MODAL_PRODUCT'); ?></th>
                        <th class="text-center"><?php echo Text::_('COM_ALFA_DOCUMENTS_MODAL_QTY'); ?></th>
                        <th class="text-end"><?php echo Text::_('COM_ALFA_COST_TAX_EXCL'); ?></th>
                        <th class="text-end"><?php echo Text::_('COM_ALFA_TAX_AMOUNT'); ?></th>
                        <th class="text-end"><?php echo Text::_('COM_ALFA_COST_TAX_INCL'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
					<?php foreach ($preview->lines as $line) : ?>
                        <tr>
                            <td><?php echo $this->escape((string) $line->name); ?></td>
                            <td class="text-center"><?php echo $this->escape((string) $line->quantity); ?></td>
                            <td class="text-end"><?php echo $money($line->total_tax_excl); ?></td>
                            <td class="text-end"><?php echo $money($line->tax_amount); ?></td>
                            <td class="text-end"><?php echo $money($line->total_tax_incl); ?></td>
                        </tr>
					<?php endforeach; ?>
                    </tbody>
                    <tfoot>
                    <tr>
                        <th colspan="2" class="text-end"><?php echo Text::_('COM_ALFA_DOCUMENTS_HEADING_TOTAL'); ?></th>
                        <th class="text-end"><?php echo $money($preview->totals->excl); ?></th>
                        <th class="text-end"><?php echo $money($preview->totals->tax); ?></th>
                        <th class="text-end">
							<?php echo $money($preview->totals->incl); ?>
							<?php echo $this->escape((string) $preview->currency_code); ?>
                        </th>
                    </tr>
                    </tfoot>
                </table>

				<?php // The per-rate VAT summary, because a correction that
				      // spans two rates is not one number. ?>
				<?php if (!empty($preview->tax_summary)) : ?>
                    <table class="table table-sm w-auto">
                        <tbody>
						<?php foreach ($preview->tax_summary as $taxRow) : ?>
                            <tr>
                                <td><?php echo $this->escape(AlfaHelper::percentDisplay(value: $taxRow->tax_rate)); ?></td>
                                <td class="text-end"><?php echo $money($taxRow->base_tax_excl); ?></td>
                                <td class="text-end"><?php echo $money($taxRow->tax_amount); ?></td>
                            </tr>
						<?php endforeach; ?>
                        </tbody>
                    </table>
				<?php endif; ?>

				<?php // Who it is made out to: read-only on this path, and the
				      // buyer the fingerprint binds, so the one shown is the one
				      // frozen. Every column the header stores, as the preview
				      // normalised it. ?>
				<?php
				$buyer = $preview->buyer;
				$buyerAddress = json_decode((string) ($buyer['buyer_address'] ?? ''), true);
				$buyerRows = array_filter([
					'COM_ALFA_DOCUMENTS_FIELD_BUYER_NAME'       => $buyer['buyer_name'],
					'COM_ALFA_DOCUMENTS_FIELD_BUYER_VAT'        => $buyer['buyer_vat_number'],
					'COM_ALFA_DOCUMENTS_FIELD_BUYER_TAX_OFFICE' => $buyer['buyer_tax_office'],
					'COM_ALFA_DOCUMENTS_FIELD_BUYER_ACTIVITY'   => $buyer['buyer_activity'],
					'JGLOBAL_EMAIL'                             => $buyer['buyer_email'],
					'COM_ALFA_REPLACEMENT_BUYER_COUNTRY'        => $buyer['buyer_country'],
					'COM_ALFA_DOC_PRINT_ADDRESS'                => \is_array($buyerAddress) ? implode(', ', array_filter(
						array_map(static fn ($part): string => \is_scalar($part) ? trim((string) $part) : '', $buyerAddress),
						static fn (string $part): bool => $part !== '',
					)) : '',
				], static fn (string $value): bool => $value !== '');
				?>
                <h4 class="h6 mt-3"><?php echo Text::_('COM_ALFA_CORRECTION_BUYER_HEADING'); ?></h4>
                <dl class="row small mb-1" data-alfa-correction-buyer>
					<?php foreach ($buyerRows as $label => $value) : ?>
                        <dt class="col-sm-3"><?php echo Text::_($label); ?></dt>
                        <dd class="col-sm-9"><?php echo $this->escape($value); ?></dd>
					<?php endforeach; ?>
                </dl>
                <p class="small text-muted">
					<?php echo Text::_(
						$proposal->direction === IncrementalCorrection::DIRECTION_NEGATIVE
							? 'COM_ALFA_CORRECTION_BUYER_FROZEN'
							: 'COM_ALFA_CORRECTION_BUYER_ORDER'
					); ?>
                </p>

                <p class="text-muted small">
					<?php echo Text::_('COM_ALFA_CORRECTION_PREVIEW_NOTE'); ?>
                </p>
			<?php endif; ?>

			<?php if (!empty($this->strategies)) : ?>
				<?php // section 5: every strategy for this document, available or not, and
				      // why; the operator is never left to wonder where one went. ?>
                <h4 class="h6 mt-4"><?php echo Text::_('COM_ALFA_CORRECTION_STRATEGIES_HEADING'); ?></h4>
                <table class="table table-sm w-auto" data-alfa-strategies>
                    <tbody>
					<?php foreach ([
						CorrectionStrategies::INCREMENTAL      => 'COM_ALFA_STRATEGY_INCREMENTAL',
						CorrectionStrategies::VOID_AND_REPLACE => 'COM_ALFA_STRATEGY_VOID_AND_REPLACE',
						CorrectionStrategies::VOID             => 'COM_ALFA_STRATEGY_VOID',
					] as $strategyKey => $strategyLabel) : ?>
						<?php $verdict = $this->strategies[$strategyKey]; ?>
                        <tr data-alfa-strategy="<?php echo $strategyKey; ?>">
                            <td><?php echo Text::_($strategyLabel); ?></td>
                            <td>
								<?php if ($verdict->available) : ?>
                                    <span class="badge bg-success"><?php echo Text::_('COM_ALFA_STRATEGY_AVAILABLE'); ?></span>
								<?php else : ?>
                                    <span class="text-muted small"><?php echo Text::_('COM_ALFA_CORRECTION_REASON_' . strtoupper($verdict->reason)); ?></span>
									<?php // Its sentence speaks of the credit notes named below it,
									      // so they are named: voiding them is the way out. ?>
									<?php if (\in_array($verdict->reason, [CorrectionStrategies::REASON_CREDIT_UNAVAILABLE, CorrectionStrategies::REASON_CREDIT_STILL_ACCOUNTS, CorrectionStrategies::REASON_REPLACEMENT_UNAVAILABLE], true)) : ?>
                                        <div class="small text-muted mt-1" data-alfa-strategy-notes><?php echo $this->escape($verdict->detail); ?></div>
									<?php endif; ?>
								<?php endif; ?>
                            </td>
                        </tr>
					<?php endforeach; ?>
                    </tbody>
                </table>
			<?php endif; ?>

			<?php // Charge drift this document cannot give back, named with its
			      // amount: charged on another document, or beyond what this
			      // one has left. A refused proposal names it too. At the
			      // preview's frozen scale, else at the order's, which the
			      // figure carries. ?>
			<?php if (!empty($proposal->unabsorbed_charges)) : ?>
                <p class="small text-warning">
					<?php echo Text::sprintf('COM_ALFA_CORRECTION_UNABSORBED_CHARGES', implode(', ', array_map(
						fn (object $charge): string => $this->escape($charge->label) . ' ' . number_format(
							(float) $charge->incl,
							(int) ($preview->currency_scale ?? $charge->scale),
							',',
							'.',
						),
						$proposal->unabsorbed_charges,
					))); ?>
                </p>
			<?php endif; ?>

			<?php // A discount that drifted in a way no amount corrects on its
			      // own is named with its cause, never corrected with money. ?>
			<?php if (!empty($proposal->blocked_discounts)) : ?>
                <p class="small text-warning" data-alfa-correction-blocked-discounts>
					<?php echo Text::sprintf('COM_ALFA_CORRECTION_BLOCKED_DISCOUNTS', implode(', ', array_map(
						fn (object $discount): string => $this->escape($discount->label) . ' - '
							. Text::_('COM_ALFA_CORRECTION_DISCOUNT_' . strtoupper($discount->reason)),
						$proposal->blocked_discounts,
					))); ?>
                </p>
			<?php endif; ?>

            <!-- Hidden Fields -->
			<?php echo HTMLHelper::_('form.token'); ?>
            <input type="hidden" name="task" value="" />
            <input type="hidden" name="id_order" value="<?php echo $orderID; ?>" />
            <input type="hidden" name="id_source" value="<?php echo $sourceID; ?>" />
			<?php // The preview this confirms: issued only while the correction
			      // derived at confirmation still has this fingerprint. ?>
            <input type="hidden" name="fingerprint" value="<?php echo $this->escape($proposal->fingerprint); ?>" />
        </form>
    </div>
