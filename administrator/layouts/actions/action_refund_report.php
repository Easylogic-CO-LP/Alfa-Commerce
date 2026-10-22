<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The form of a provider refund report action: the COMPONENT'S own, for every
 * gateway. Record this refund with an amount to enter (a report with no exact
 * amount in the order's currency), Reconcile to an existing refund, Duplicate
 * or invalid report. It shows the report's facts as the gateway lists them,
 * unchanged, and asks only what the action needs, with a reason. It posts to
 * the action page's _do arm as a plain form: a POST with the CSRF token and a
 * one-time key, no script.
 *
 * Receives: kind ('typed' | 'reconcile' | 'dismiss'), payment, order, report
 * (reportId, id, amount, providerAmount, providerCurrency, reportedOn),
 * reference (how the report is named), currency (the order's), candidates (the
 * refunds a report may be reconciled to), submit (the _do action id), typed
 * (amount, refund, reason as posted on a refused submission) and refusal (plain
 * text). Every value is escaped once, here.
 *
 * @since  2.1.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var array $displayData */
extract($displayData);

if (!isset($kind, $payment, $report, $currency, $submit)) {
    return;
}

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$typedOf = static fn (string $name): string => \is_array($typed ?? null) ? (string) ($typed[$name] ?? '') : '';
$refusal = (string) ($refusal ?? '');
$scale = $currency->getDecimalPlaces();
$minorUnit = $scale > 0 ? '0.' . str_repeat('0', $scale - 1) . '1' : '1';
$candidates = \is_array($candidates ?? null) ? $candidates : [];

$intro = match ($kind) {
    'typed' => Text::sprintf('COM_ALFA_REPORT_TYPED_INTRO', $currency->getCode()),
    'reconcile' => Text::_('COM_ALFA_REPORT_RECONCILE_INTRO'),
    default => Text::_('COM_ALFA_REPORT_DISMISS_INTRO'),
};
$button = match ($kind) {
    'typed' => Text::_('COM_ALFA_RECORD_REFUND_TYPED'),
    'reconcile' => Text::_('COM_ALFA_RECONCILE_REPORT'),
    default => Text::_('COM_ALFA_DISMISS_REPORT'),
};
?>

<form class="alfa-refund-report" method="post" action="index.php">

    <p class="mb-3"><?php echo $escape($intro); ?></p>

    <?php if ($refusal !== '') : ?>
        <div class="alert alert-danger" role="alert"><?php echo $escape($refusal); ?></div>
    <?php endif; ?>

    <dl class="row mb-3">
        <dt class="col-sm-5"><?php echo $escape(Text::_('COM_ALFA_REPORT_FORM_PROVIDER')); ?></dt>
        <dd class="col-sm-7"><?php echo $escape((string) ($reference ?? $report['reportId'])); ?></dd>
        <dt class="col-sm-5"><?php echo $escape(Text::_('COM_ALFA_REPORT_FORM_REPORTED')); ?></dt>
        <dd class="col-sm-7"><?php echo $escape($report['providerAmount'] . ' ' . $report['providerCurrency']); ?>, <?php echo $escape($report['reportedOn']); ?></dd>
        <dt class="col-sm-5"><?php echo $escape(Text::_('COM_ALFA_REPORT_FORM_REFERENCE')); ?></dt>
        <dd class="col-sm-7"><code><?php echo $escape($report['reportId']); ?></code></dd>
    </dl>

    <?php if ($kind === 'typed') : ?>
        <div class="mb-3">
            <label class="form-label" for="alfa-refund-report-amount"><?php echo $escape(Text::sprintf('COM_ALFA_REPORT_FORM_AMOUNT', $currency->getCode())); ?></label>
            <?php // No default value: the operator states the amount to book; only a refused submission gives back what was typed. ?>
            <input type="number" class="form-control" id="alfa-refund-report-amount" name="amount" required
                   step="<?php echo $escape($minorUnit); ?>" min="<?php echo $escape($minorUnit); ?>"
                   <?php if ($typedOf('amount') !== '') : ?>value="<?php echo $escape($typedOf('amount')); ?>"<?php endif; ?>>
        </div>
    <?php endif; ?>

    <?php if ($kind === 'reconcile') : ?>
        <?php if ($candidates === []) : ?>
            <p class="text-muted"><?php echo $escape(Text::_('COM_ALFA_REPORT_RECONCILE_NONE')); ?></p>
        <?php else : ?>
            <fieldset class="mb-3">
                <?php foreach ($candidates as $candidate) : ?>
                    <?php $rowId = (int) $candidate->id; ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="refund" id="alfa-refund-report-row-<?php echo $rowId; ?>"
                               value="<?php echo $rowId; ?>" required<?php echo $typedOf('refund') === (string) $rowId ? ' checked' : ''; ?>>
                        <label class="form-check-label" for="alfa-refund-report-row-<?php echo $rowId; ?>">
                            <?php echo $escape(Text::sprintf(
                                'COM_ALFA_REPORT_RECONCILE_ROW',
                                $rowId,
                                $currency->format((float) $candidate->amount),
                                $candidate->added ? HTMLHelper::_('date', (string) $candidate->added, 'Y-m-d H:i') : '-',
                                (string) ($candidate->transaction_id ?? '') !== '' ? (string) $candidate->transaction_id : '-',
                            )); ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($kind !== 'reconcile' || $candidates !== []) : ?>
        <div class="mb-3">
            <label class="form-label" for="alfa-refund-report-reason"><?php echo $escape(Text::_('COM_ALFA_REPORT_FORM_REASON')); ?></label>
            <textarea class="form-control" id="alfa-refund-report-reason" name="reason" required maxlength="1000" rows="3"><?php echo $escape($typedOf('reason')); ?></textarea>
        </div>

        <input type="hidden" name="option" value="com_alfa">
        <input type="hidden" name="view" value="action">
        <input type="hidden" name="tmpl" value="component">
        <input type="hidden" name="group" value="alfa-payments">
        <input type="hidden" name="action" value="<?php echo $escape((string) $submit); ?>">
        <input type="hidden" name="payment_id" value="<?php echo (int) ($payment->id ?? 0); ?>">
        <input type="hidden" name="report" value="<?php echo $escape($report['reportId']); ?>">
        <?php // A one-time key per form: a second submission of this form is refused, never run again. ?>
        <input type="hidden" name="once" value="<?php echo $escape(bin2hex(random_bytes(16))); ?>">
        <?php echo HTMLHelper::_('form.token'); ?>

        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-warning"><?php echo $escape($button); ?></button>
        </div>
    <?php endif; ?>

</form>
