<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Void & Replace, previewed: rendered inside a modal iframe, the same shell
 * every other order popup uses.
 *
 * The document reconciliation contract, section 6: before
 * committing, show the document that will be voided, the replacement that will
 * be issued, and what survives. Every figure here came from the issuer's own
 * preview, computed with the source's claims released and then rolled back;
 * this template performs no arithmetic, and nothing was written to show it.
 *
 * The fingerprint of this exact preview travels with the form. The commit
 * rebuilds the replacement the one way it is ever built and refuses unless it
 * still matches, so what is issued is what was shown here.
 *
 * THE BUYER: the source's frozen buyer is shown
 * read-only (all of it, the country and address it carries unchanged
 * included) and the replacement's is editable, bound to the proposal's own
 * buyer block (the source's by default, else the operator's last edit, on the
 * controller's own redisplay only), rendered from the issue form's canonical
 * field definitions. Preview re-proposes with the edits; the fingerprint
 * covers the buyer, so an edit confirmed without previewing it is refused and
 * comes back previewed. A field posted back as it is displayed here is no
 * edit. The block stays on screen when the issuer refuses, so a refused buyer
 * can be corrected.
 *
 * A CREDIT NOTE'S REPLACEMENT is the exception: it is
 * made out to the buyer of the document the note corrects, as that document
 * froze it, so that buyer is shown read-only, there is nothing to preview, and
 * only the reason and the confirm remain.
 *
 * ONE VALIDATOR, THE SERVER'S. The form carries no client validator and is
 * novalidate: a browser check on the required reason blocked Cancel and
 * Preview on every fresh open, and one on the email would block a frozen email
 * nobody touched. The server refuses an empty reason and an edited email that
 * is not an address, and keeps what was typed.
 *
 * Money at the frozen scales: the preview's figures at the preview's own
 * currency_scale, the document being voided at its own.
 *
 * Path: administrator/components/com_alfa/tmpl/order/edit_replacement.php
 *
 * @since  2.0.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger;
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentMoney;
use Alfa\Component\Alfa\Administrator\Service\Document\VoidAndReplace;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$wa = $this->document->getWebAssetManager();
$wa->useScript('keepalive')
	->useScript('com_alfa.admin')
	->useStyle('com_alfa.admin');

$orderID     = (int) $this->order->id;
$sourceID    = (int) $this->sourceDocumentId;
$replacement = $this->replacement;
$source      = $replacement->source;
$preview     = $replacement->preview;

// The preview's figures at its own frozen scale, the ones that will be frozen;
// read only on an available replacement, which always carries its preview.
$money = static fn ($value): string => number_format((float) $value, (int) $preview->currency_scale, ',', '.');
// The document being voided prints at the scale it was issued at.
$sourceMoney = $source ? DocumentMoney::formatter($source) : null;
// A credit note sent to the customer's credit account: its void takes back
// what that account holds for it, and its replacement is sent there too and
// paid in once issued. The closing note says so for such a source.
$toCreditAccount = $source && ($source->credit_destination ?? null) === CustomerCreditLedger::CREDIT_NOTE_DESTINATION;

// The source's buyer as it was frozen: read-only, beside the editable one.
// All seven columns: the country and the address are not editable and are
// carried to the replacement unchanged, so they are shown here to be seen.
$sourceAddress = $source ? json_decode((string) ($source->buyer_address ?? ''), true) : null;
$sourceBuyer = $source ? array_filter([
    'COM_ALFA_DOCUMENTS_FIELD_BUYER_NAME'       => (string) $source->buyer_name,
    'COM_ALFA_DOCUMENTS_FIELD_BUYER_VAT'        => (string) $source->buyer_vat_number,
    'COM_ALFA_DOCUMENTS_FIELD_BUYER_TAX_OFFICE' => (string) $source->buyer_tax_office,
    'COM_ALFA_DOCUMENTS_FIELD_BUYER_ACTIVITY'   => (string) $source->buyer_activity,
    'JGLOBAL_EMAIL'                             => (string) $source->buyer_email,
    'COM_ALFA_REPLACEMENT_BUYER_COUNTRY'        => (string) $source->buyer_country,
    // Its parts in stored order ({street, number, city, zip, region,
    // country}, or the free `text` the printed document shows).
    'COM_ALFA_DOC_PRINT_ADDRESS'                => \is_array($sourceAddress) ? implode(', ', array_filter(
        array_map(static fn ($part): string => \is_scalar($part) ? trim((string) $part) : '', $sourceAddress),
        static fn (string $part): bool => $part !== '',
    )) : '',
], static fn (string $value): bool => $value !== '') : [];
$carried = isset($sourceBuyer['COM_ALFA_REPLACEMENT_BUYER_COUNTRY']) || isset($sourceBuyer['COM_ALFA_DOC_PRINT_ADDRESS']);

// A credit note's replacement is made out to the buyer of the document it
// corrects: the proposal's block, as the header will store it, read-only.
$newBuyer = $replacement->buyer;
$newAddress = json_decode((string) ($newBuyer['buyer_address'] ?? ''), true);
$replacementBuyer = $this->replacementCredit ? array_filter([
	'COM_ALFA_DOCUMENTS_FIELD_BUYER_NAME'       => (string) ($newBuyer['buyer_name'] ?? ''),
	'COM_ALFA_DOCUMENTS_FIELD_BUYER_VAT'        => (string) ($newBuyer['buyer_vat_number'] ?? ''),
	'COM_ALFA_DOCUMENTS_FIELD_BUYER_TAX_OFFICE' => (string) ($newBuyer['buyer_tax_office'] ?? ''),
	'COM_ALFA_DOCUMENTS_FIELD_BUYER_ACTIVITY'   => (string) ($newBuyer['buyer_activity'] ?? ''),
	'JGLOBAL_EMAIL'                             => (string) ($newBuyer['buyer_email'] ?? ''),
	'COM_ALFA_REPLACEMENT_BUYER_COUNTRY'        => (string) ($newBuyer['buyer_country'] ?? ''),
	'COM_ALFA_DOC_PRINT_ADDRESS'                => \is_array($newAddress) ? implode(', ', array_filter(
		array_map(static fn ($part): string => \is_scalar($part) ? trim((string) $part) : '', $newAddress),
		static fn (string $part): bool => $part !== '',
	)) : '',
], static fn (string $value): bool => $value !== '') : [];

?>

    <!-- Toolbar -->
    <div class="subhead noshadow mb-3">
		<?php echo $this->getDocument()->getToolbar('toolbar')->render(); ?>
    </div>

    <div class="container-popup">

        <form
                action="<?php echo Route::_("index.php?option=com_alfa&view=order&layout=edit_replacement&tmpl=component&id_order={$orderID}&id_source={$sourceID}"); ?>"
                method="post"
                name="adminForm"
                id="replacement-form"
                novalidate>

			<?php if ($source) : ?>
                <h4 class="h6"><?php echo Text::_('COM_ALFA_REPLACEMENT_SOURCE'); ?></h4>
                <p>
                    <strong><?php echo $this->escape((string) $source->number_formatted); ?></strong>
                    - <?php echo DocumentHelper::typeLabel((string) $source->doc_type); ?>
                    - <?php echo $sourceMoney((float) $source->total_tax_incl); ?> <?php echo $this->escape((string) $source->currency_code); ?>
                    - <?php echo HTMLHelper::_('date', $source->issue_date_local, Text::_('DATE_FORMAT_LC4')); ?>
                </p>
				<?php if ($sourceBuyer !== []) : ?>
                    <dl class="row small mb-2" id="replacement-source-buyer">
						<?php foreach ($sourceBuyer as $label => $value) : ?>
                            <dt class="col-sm-3"><?php echo Text::_($label); ?></dt>
                            <dd class="col-sm-9"><?php echo nl2br($this->escape($value)); ?></dd>
						<?php endforeach; ?>
                    </dl>
					<?php if ($carried) : ?>
                        <p class="small text-muted"><?php echo Text::_('COM_ALFA_REPLACEMENT_BUYER_CARRIED'); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>

			<?php if (!$replacement->available) : ?>
                <div class="alert alert-info">
                    <span class="icon-info-circle" aria-hidden="true"></span>
					<?php echo Text::_('COM_ALFA_CORRECTION_REASON_' . strtoupper($replacement->reason)); ?>
					<?php if ($replacement->detail !== '') : ?>
                        <div class="small text-muted mt-1"><?php echo $this->escape($replacement->detail); ?></div>
					<?php endif; ?>
                </div>
			<?php else : ?>

                <h4 class="h6 mt-3"><?php echo Text::_('COM_ALFA_REPLACEMENT_SURVIVING'); ?></h4>
				<?php if ($replacement->surviving === []) : ?>
                    <p class="text-muted small"><?php echo Text::_('COM_ALFA_REPLACEMENT_SURVIVING_NONE'); ?></p>
				<?php else : ?>
                    <ul class="small">
						<?php foreach ($replacement->surviving as $claim) : ?>
                            <li>
								<?php echo $this->escape($claim->number_formatted); ?>
                                - <?php echo DocumentHelper::typeLabel($claim->doc_type); ?>
                                - <?php echo $money($claim->total_tax_incl); ?>
                            </li>
						<?php endforeach; ?>
                    </ul>
                    <p class="small text-muted"><?php echo Text::_('COM_ALFA_REPLACEMENT_SURVIVING_HINT'); ?></p>
				<?php endif; ?>

                <h4 class="h6 mt-3"><?php echo Text::_('COM_ALFA_REPLACEMENT_NEW'); ?></h4>
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

                <p class="text-muted small"><?php echo Text::_($toCreditAccount ? 'COM_ALFA_REPLACEMENT_NOTE_CUSTOMER_CREDIT' : 'COM_ALFA_REPLACEMENT_NOTE'); ?></p>
			<?php endif; ?>

			<?php if ($this->replacementCredit && $replacement->available) : ?>
				<?php // A credit note's replacement is made out to the buyer of
				      // the document it corrects, as that document froze it:
				      // shown, never edited. ?>
                <h4 class="h6 mt-3"><?php echo Text::_('COM_ALFA_REPLACEMENT_BUYER'); ?></h4>
				<?php if ($replacementBuyer !== []) : ?>
                    <dl class="row small mb-2" id="replacement-new-buyer">
						<?php foreach ($replacementBuyer as $label => $value) : ?>
                            <dt class="col-sm-3"><?php echo Text::_($label); ?></dt>
                            <dd class="col-sm-9"><?php echo nl2br($this->escape($value)); ?></dd>
						<?php endforeach; ?>
                    </dl>
				<?php endif; ?>
                <p class="small text-muted"><?php echo Text::_('COM_ALFA_REPLACEMENT_BUYER_CREDIT'); ?></p>
			<?php endif; ?>

			<?php if ($this->buyerEditable) : ?>
                <h4 class="h6 mt-3"><?php echo Text::_('COM_ALFA_REPLACEMENT_BUYER'); ?></h4>
                <p class="small text-muted"><?php echo Text::_('COM_ALFA_REPLACEMENT_BUYER_HINT'); ?></p>
				<?php foreach (VoidAndReplace::EDITABLE_BUYER as $buyerField) : ?>
					<?php echo $this->buyerForm->renderField($buyerField); ?>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php if ($this->buyerEditable || ($this->replacementCredit && $replacement->available)) : ?>
                <div class="control-group">
                    <div class="control-label">
                        <label for="replacement_reason" class="required">
							<?php echo Text::_('COM_ALFA_DOCUMENTS_VOID_REASON_LABEL'); ?>
                            <span class="star" aria-hidden="true">&#160;*</span>
                        </label>
                    </div>
                    <div class="controls">
                        <textarea name="reason" id="replacement_reason" rows="3"
                                  class="form-control required" required><?php echo $this->escape($this->replacementReason); ?></textarea>
                    </div>
                </div>
			<?php endif; ?>

			<?php if ($replacement->available) : ?>
                <input type="hidden" name="fingerprint" value="<?php echo $this->escape($replacement->fingerprint); ?>" />
			<?php endif; ?>

            <!-- Hidden Fields -->
			<?php echo HTMLHelper::_('form.token'); ?>
            <input type="hidden" name="task" value="" />
            <input type="hidden" name="id_order" value="<?php echo $orderID; ?>" />
            <input type="hidden" name="id_source" value="<?php echo $sourceID; ?>" />
        </form>
    </div>
