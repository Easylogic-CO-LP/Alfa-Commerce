<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * Partial Refund - Standard Plugin
 *
 * Amount form for the 'refund_partial' action. Rendered on the action page
 * after the plugin's onExecuteAction() called
 *   $event->setLayout('action_refund_partial'); $event->setLayoutData([...]);
 *
 * NO inline JS: the submit button carries data attributes only. The plugin's
 * registered asset (refund-partial.js) reads the input and navigates to the
 * mutating 'refund_partial_do' arm via AlfaAction.run().
 *
 * The handler never renders this form with an exhausted remainder: below one
 * minor unit it answers a notice instead (a max under min would fail
 * checkValidity() on every value), so max here always clears min.
 *
 * Step, min and max come from the remainder's OWN currency scale, never a
 * hard-coded two places: on a 0-decimal currency the smallest refund is 1, on
 * a 3-decimal one it is 0.001, and a 2-decimal max would round the remainder
 * up past the figure the ledger will actually accept.
 *
 * Path: plugins/alfa-payments/standard/tmpl/action_refund_partial.php
 *
 * @since  1.9.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

$payment = $displayData['payment'] ?? null;
$maxMoney = $displayData['max'] ?? null;

// refundableRemainder() returns Money. Casting an object to float yields 1,
// so `(float) $displayData['max']` capped EVERY partial refund at 1.00: the
// dialog announced "Refundable remainder 1,00 €" on a 100,00 € payment and
// the input's max attribute enforced it. Ask Money for its amount.
$max = is_object($maxMoney) && method_exists($maxMoney, 'getAmount')
    ? $maxMoney->getAmount()
    : (float) ($maxMoney ?? 0.0);

if (!$payment) {
    return;
}

// The action page is a REAL page, so plugin assets load normally. Register
// the plugin's asset registry, then the form's few lines of JS; AlfaAction
// (com_alfa.action-page) is already loaded on every action page.
$wa = Factory::getApplication()->getDocument()->getWebAssetManager();
$wa->getRegistry()->addRegistryFile('media/plg_alfa-payments_standard/joomla.asset.json');
$wa->useScript('plg_alfa-payments_standard.refund-partial');

// Prefer the remainder's OWN formatting: it already knows its currency and
// scale. The payment-amount fallback stays for a non-Money caller.
$amount = $payment->amount ?? 0;

if (is_object($maxMoney) && method_exists($maxMoney, 'format')) {
    $maxDisplay = $maxMoney->format();
} elseif (is_object($amount) && method_exists($amount, 'getCurrency')) {
    $maxDisplay = $amount::of($max, $amount->getCurrency())->format();
} else {
    $maxDisplay = number_format($max, 2);
}

// The scale money is expressed in here: the currency's, or two places for a
// caller that handed over a bare float and told us nothing.
$scale = is_object($maxMoney) && method_exists($maxMoney, 'getCurrency')
    ? $maxMoney->getCurrency()->getDecimalPlaces()
    : 2;

// Attribute values need the machine form: no thousands separator. $max is
// already quantised to $scale, so this formats it without rounding it.
$maxAttr = number_format($max, $scale, '.', '');

// One minor unit: the smallest amount that exists in this currency, and so
// both the step and the floor of what may be refunded.
$minorUnit = $scale > 0 ? '0.' . str_repeat('0', $scale - 1) . '1' : '1';
?>

<div class="card" data-refund-partial>
    <div class="card-body">
        <dl class="row">

            <dt class="col-sm-5"><?php echo Text::_('PLG_ALFA_PAYMENTS_STANDARD_REFUND_PARTIAL_REMAINDER'); ?></dt>
            <dd class="col-sm-7"><?php echo $maxDisplay; ?></dd>

        </dl>

        <label class="form-label" for="refund-partial-amount">
            <?php echo Text::_('PLG_ALFA_PAYMENTS_STANDARD_REFUND_PARTIAL_AMOUNT'); ?>
        </label>
        <div class="input-group">
            <input type="number" class="form-control" id="refund-partial-amount"
                   name="amount" required
                   step="<?php echo $minorUnit; ?>" min="<?php echo $minorUnit; ?>"
                   max="<?php echo $maxAttr; ?>"
                   <?php // NO default value. An input whose value is its max
                         // turns "Partial refund" into a full refund that
                         // anyone can perform by confirming the dialog, which
                         // is how a 100%-consumed payment came to be filed as
                         // a partial. The operator states the amount.?>
                   placeholder="<?php echo $maxAttr; ?>"
                   data-refund-partial-amount>
            <button type="button" class="btn btn-warning" data-refund-partial-submit>
                <span class="icon-undo-2 me-1" aria-hidden="true"></span>
                <?php echo Text::_('PLG_ALFA_PAYMENTS_STANDARD_REFUND_PARTIAL_SUBMIT'); ?>
            </button>
        </div>
    </div>
</div>
