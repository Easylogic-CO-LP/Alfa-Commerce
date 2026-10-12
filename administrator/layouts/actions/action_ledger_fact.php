<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Record a provider fact on a gateway row: the COMPONENT'S own form for
 * Record failed refund, Record won dispute and Record chargeback
 * alike. No plugin owns these actions, so no
 * plugin provides a layout for them.
 *
 * Rendered by the non-mutating 'ledger_reversal' / 'ledger_chargeback' arms.
 * The submit button carries data attributes only; ledger-fact-form.js reads
 * the fields and calls AlfaAction.run('<action>_do', ...).
 *
 * Receives: kind ('refund_reversal' | 'dispute_won' | 'chargeback'), payment,
 * order, cap (Money for a reversal: what is left to reverse; null for a
 * chargeback), currency (the order's), submit (the _do action id). The ledger
 * decides; max below is a courtesy to the typist, never the guard.
 *
 * A refused submission comes back here, not as the result notice: typed (the
 * amount, reference and reason exactly as posted) fills the fields again, and
 * refusal (plain text) reads above them. Both are escaped once, here.
 *
 * @since  1.14.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/** @var array $displayData */
extract($displayData);

if (!isset($kind, $currency, $submit)) {
    return;
}

Factory::getApplication()->getDocument()->getWebAssetManager()
    ->useScript('com_alfa.ledger-fact-form');

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$scale  = $currency->getDecimalPlaces();

// One minor unit: the smallest amount that exists in this currency.
$minorUnit = $scale > 0 ? '0.' . str_repeat('0', $scale - 1) . '1' : '1';

// The machine form of the cap: no thousands separator, the currency's decimals.
$capAttr = isset($cap) && $cap !== null ? number_format($cap->getAmount(), $scale, '.', '') : '';

$intro = match ($kind) {
    'refund_reversal' => Text::sprintf('COM_ALFA_LEDGER_REFUND_REVERSAL_INTRO', $escape($cap->format())),
    'dispute_won'     => Text::sprintf('COM_ALFA_LEDGER_DISPUTE_WON_INTRO', $escape($cap->format())),
    default           => Text::_('COM_ALFA_LEDGER_CHARGEBACK_INTRO'),
};

// What the operator typed, on a refused submission; a fresh form has none.
$typedOf = static fn (string $name): string => \is_array($typed ?? null) ? (string) ($typed[$name] ?? '') : '';
$refusal = (string) ($refusal ?? '');
?>

<div class="alfa-ledger-fact" data-ledger-fact>

    <p class="mb-3"><?php echo $intro; ?></p>

    <?php if ($refusal !== '') : ?>
        <div class="alert alert-danger" role="alert"><?php echo $escape($refusal); ?></div>
    <?php endif; ?>

    <div class="mb-3">
        <label class="form-label" for="alfa-ledger-fact-amount">
            <?php echo Text::_('COM_ALFA_LEDGER_AMOUNT'); ?>
        </label>
        <?php // NO default value: the operator states the amount the provider reported; only a refused submission gives back what was typed. ?>
        <input type="number"
               class="form-control"
               id="alfa-ledger-fact-amount"
               name="amount"
               required
               step="<?php echo $escape($minorUnit); ?>"
               min="<?php echo $escape($minorUnit); ?>"
               <?php if ($capAttr !== '') : ?>max="<?php echo $escape($capAttr); ?>"<?php endif; ?>
               placeholder="<?php echo $escape($capAttr); ?>"
               <?php if ($typedOf('amount') !== '') : ?>value="<?php echo $escape($typedOf('amount')); ?>"<?php endif; ?>
               data-ledger-fact-field>
    </div>

    <div class="mb-3">
        <label class="form-label" for="alfa-ledger-fact-reference">
            <?php echo Text::_('COM_ALFA_LEDGER_REFERENCE'); ?>
        </label>
        <?php // No maxlength: the reference is stored whole. ?>
        <input type="text"
               class="form-control"
               id="alfa-ledger-fact-reference"
               name="reference"
               required
               aria-describedby="alfa-ledger-fact-reference-desc"
               <?php if ($typedOf('reference') !== '') : ?>value="<?php echo $escape($typedOf('reference')); ?>"<?php endif; ?>
               data-ledger-fact-field>
        <small class="form-text text-muted" id="alfa-ledger-fact-reference-desc">
            <?php echo Text::_('COM_ALFA_LEDGER_REFERENCE_DESC'); ?>
        </small>
    </div>

    <div class="mb-3">
        <label class="form-label" for="alfa-ledger-fact-reason">
            <?php echo Text::_('COM_ALFA_LEDGER_REASON'); ?>
        </label>
        <input type="text"
               class="form-control"
               id="alfa-ledger-fact-reason"
               name="reason"
               maxlength="255"
               <?php if ($typedOf('reason') !== '') : ?>value="<?php echo $escape($typedOf('reason')); ?>"<?php endif; ?>
               data-ledger-fact-field>
    </div>

    <div class="d-flex justify-content-end">
        <button type="button"
                class="btn btn-danger"
                data-ledger-fact-submit
                data-ledger-fact-action="<?php echo $escape((string) $submit); ?>">
            <?php echo Text::_('COM_ALFA_LEDGER_SUBMIT'); ?>
        </button>
    </div>

</div>
