<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * User edit: Customer Credit Account tab.
 *
 * The customer LIABILITY ledger, not a voucher system: every row answers
 * "why does this customer have this balance" (source, reason, actor, time).
 * Rows are never edited or deleted; corrections are counter-entries, so
 * this panel is read-only apart from the two guarded actions.
 *
 * The fields and buttons belong to a SIBLING form (#alfa-credit-form,
 * rendered after adminForm in edit.php) via the HTML5 `form` attribute:
 * nested <form>s are invalid, and repointing adminForm itself does NOT work:
 * its hidden `task` field posts empty and beats the formaction query string,
 * so the request lands on the component's default view instead of the task.
 * The sibling form therefore carries NO `task` field; each button supplies
 * one through formaction. jform never rides along, so profile edits are
 * untouched (and unsaved ones are still lost; the hint says so).
 *
 * @since  2.0.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$creditUserId = (int) ($this->item->id_user ?? 0);

if ($creditUserId <= 0) {
    return;
}

$balance = CustomerCreditLedger::balance($creditUserId);
$entries = CustomerCreditLedger::transactions($creditUserId);
$taskUrl = Route::_('index.php?option=com_alfa', false);

// Wallet money is money in the shop default currency, shown and entered at that
// currency's own scale, the scale the ledger stores it at.
$wallet     = \Alfa\Component\Alfa\Site\Service\Pricing\Currency::getDefault();
$walletStep = number_format(10 ** -$wallet->getDecimalPlaces(), $wallet->getDecimalPlaces(), '.', '');
?>

<div class="row">
    <div class="col-lg-8">
        <table class="table table-sm align-middle">
            <caption class="visually-hidden"><?php echo Text::_('COM_ALFA_USER_CREDIT_HISTORY'); ?></caption>
            <thead>
            <tr>
                <th style="width: 12rem;"><?php echo Text::_('JDATE'); ?></th>
                <th><?php echo Text::_('COM_ALFA_USER_CREDIT_SOURCE'); ?></th>
                <th><?php echo Text::_('COM_ALFA_USER_CREDIT_REASON'); ?></th>
                <th class="text-end" style="width: 8rem;"><?php echo Text::_('COM_ALFA_USER_CREDIT_AMOUNT'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($entries === []) : ?>
                <tr><td colspan="4" class="text-muted fst-italic"><?php echo Text::_('COM_ALFA_USER_CREDIT_EMPTY'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($entries as $entry) : ?>
                <tr>
                    <?php // Stored in UTC; shown in the operator's timezone. ?>
                    <td><?php echo HTMLHelper::_('date', (string) $entry->created, 'Y-m-d H:i'); ?></td>
                    <td>
                        <span class="badge bg-body-tertiary text-body-emphasis"><?php echo $this->escape((string) $entry->source_type); ?></span>
                        <?php if (!empty($entry->source_id)) : ?>
                            <span class="small text-muted">#<?php echo (int) $entry->source_id; ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $this->escape((string) $entry->note); ?></td>
                    <td class="text-end <?php echo (float) $entry->amount < 0 ? 'text-danger' : 'text-success'; ?>">
                        <?php echo ((float) $entry->amount > 0 ? '+' : '') . $wallet->format((float) $entry->amount, false); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-body text-center">
                <div class="text-muted small"><?php echo Text::_('COM_ALFA_USER_CREDIT_BALANCE'); ?></div>
                <div class="fs-3 fw-semibold"><?php echo $wallet->format($balance, false); ?></div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="mb-2">
                    <label class="form-label" for="credit_amount"><?php echo Text::_('COM_ALFA_USER_CREDIT_AMOUNT'); ?></label>
                    <input type="number" step="<?php echo $walletStep; ?>" min="<?php echo $walletStep; ?>" class="form-control" id="credit_amount" name="credit_amount" value="" form="alfa-credit-form"/>
                </div>
                <div class="mb-2">
                    <label class="form-label" for="credit_reason"><?php echo Text::_('COM_ALFA_USER_CREDIT_REASON'); ?> *</label>
                    <input type="text" class="form-control" id="credit_reason" name="credit_reason" value=""
                           list="credit_reason_presets" maxlength="500" form="alfa-credit-form"/>
                    <datalist id="credit_reason_presets">
                        <option value="<?php echo Text::_('COM_ALFA_USER_CREDIT_PRESET_GOODWILL'); ?>"></option>
                        <option value="<?php echo Text::_('COM_ALFA_USER_CREDIT_PRESET_COMPLAINT'); ?>"></option>
                        <option value="<?php echo Text::_('COM_ALFA_USER_CREDIT_PRESET_COMPENSATION'); ?>"></option>
                        <option value="<?php echo Text::_('COM_ALFA_USER_CREDIT_PRESET_CORRECTION'); ?>"></option>
                    </datalist>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success btn-sm" form="alfa-credit-form"
                            formaction="<?php echo $taskUrl; ?>&task=user.addCredit&id=<?php echo (int) $this->item->id; ?>">
                        <?php echo Text::_('COM_ALFA_USER_CREDIT_ADD'); ?>
                    </button>
                    <button type="submit" class="btn btn-outline-danger btn-sm" form="alfa-credit-form"
                            formaction="<?php echo $taskUrl; ?>&task=user.deductCredit&id=<?php echo (int) $this->item->id; ?>">
                        <?php echo Text::_('COM_ALFA_USER_CREDIT_DEDUCT'); ?>
                    </button>
                </div>
                <p class="small text-muted mt-2 mb-0"><?php echo Text::_('COM_ALFA_USER_CREDIT_ACTIONS_HINT'); ?></p>
            </div>
        </div>
    </div>
</div>
