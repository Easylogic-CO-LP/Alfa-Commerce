<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Partial refund of a STORE-CREDIT payment: the COMPONENT'S own form, because
 * no gateway plugin owns this row and none can provide a layout for it.
 *
 * Rendered by the non-mutating 'credit_refund_partial' arm. The submit button
 * carries data attributes only; credit-refund-partial.js reads the amount and
 * calls AlfaAction.run('credit_refund_partial_do', ...). The split matters: a
 * GET that refunds money is a GET a browser prefetch can fire.
 *
 * Receives: payment (row), order, remainder (Money, the cap).
 * The cap is enforced by PaymentLedger::refundPayment(); max below is a
 * courtesy to the typist, never the guard.
 *
 * @since  1.10.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/** @var array $displayData */
extract($displayData);

if (!isset($payment, $remainder)) {
    return;
}

// The action page is a real page, so the component's assets load normally.
// AlfaAction (com_alfa.action-page) is already on every action page.
Factory::getApplication()->getDocument()->getWebAssetManager()
    ->useScript('com_alfa.credit-refund-partial');

$scale = $remainder->getCurrency()->getDecimalPlaces();

// Attribute values need the machine form: no thousands separator, and the
// currency's own number of decimals.
$maxAttr = number_format($remainder->getAmount(), $scale, '.', '');

// One minor unit: the smallest amount that exists in this currency, and so
// both the step and the floor of what may be refunded.
$minorUnit = $scale > 0 ? '0.' . str_repeat('0', $scale - 1) . '1' : '1';
?>

<div class="alfa-credit-refund-partial" data-credit-refund>

    <p class="mb-3">
        <?php echo Text::sprintf('COM_ALFA_CREDIT_REFUND_PARTIAL_INTRO', $remainder->format()); ?>
    </p>

    <div class="mb-3">
        <label class="form-label" for="alfa-credit-refund-amount">
            <?php echo Text::_('COM_ALFA_CREDIT_REFUND_AMOUNT'); ?>
        </label>

        <input type="number"
               class="form-control"
               id="alfa-credit-refund-amount"
               name="amount"
               required
               step="<?php echo htmlspecialchars($minorUnit, ENT_QUOTES, 'UTF-8'); ?>"
               min="<?php echo htmlspecialchars($minorUnit, ENT_QUOTES, 'UTF-8'); ?>"
               max="<?php echo htmlspecialchars($maxAttr, ENT_QUOTES, 'UTF-8'); ?>"
               <?php // NO default value. Prefilled with the whole remainder,
                     // submitting the partial form untouched returned the full
                     // remainder to the wallet. The operator states the amount. ?>
               placeholder="<?php echo htmlspecialchars($maxAttr, ENT_QUOTES, 'UTF-8'); ?>"
               data-credit-refund-amount>

        <small class="form-text text-muted">
            <?php echo Text::_('COM_ALFA_CREDIT_REFUND_PARTIAL_NOTE'); ?>
        </small>
    </div>

    <div class="d-flex justify-content-end">
        <button type="button" class="btn btn-warning" data-credit-refund-submit>
            <span class="icon-undo-2 me-1" aria-hidden="true"></span>
            <?php echo Text::_('COM_ALFA_CREDIT_REFUND_PARTIAL_SUBMIT'); ?>
        </button>
    </div>

</div>
