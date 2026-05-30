<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * ─────────────────────────────────────────────────────────────────
 *  Order-email payments table — overridable partial (customer-minimal)
 * ─────────────────────────────────────────────────────────────────
 *
 *  Rendered by the layout via:
 *    $render('emails.partials.order_payments', ['payments' => $payments])
 *  Override at:
 *    templates/<template>/html/layouts/com_alfa/emails/partials/order_payments.php
 *
 *  $displayData:
 *    object[]  $payments  Each: {method, amount, status} — already
 *                         formatted/customer-facing by OrderEmailHelper
 *                         (no Money objects, no internal ids/txn data).
 *
 *  Inline-styled for email-client compatibility. Renders nothing when
 *  there are no payments (the layout also guards with !empty).
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

extract($displayData);

/** @var object[] $payments */
$payments = isset($payments) && is_array($payments) ? $payments : [];

if (empty($payments)) {
    return;
}

$thStyle  = 'padding:8px 10px;text-align:left;font-size:11px;letter-spacing:0.12em;text-transform:uppercase;color:#6b7280;border-bottom:1px solid #111827;';
$tdStyle  = 'padding:9px 10px;text-align:left;border-bottom:1px solid #e5e7eb;color:#111827;font-size:14px;';
$numStyle = $tdStyle . 'text-align:right;';

?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
       style="width:100%;border-collapse:collapse;margin:1em 0;">
    <thead>
        <tr>
            <th style="<?php echo $thStyle; ?>">
                <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_COL_PAYMENT_METHOD'), ENT_COMPAT, 'UTF-8'); ?>
            </th>
            <th style="<?php echo $thStyle; ?>">
                <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_COL_STATUS'), ENT_COMPAT, 'UTF-8'); ?>
            </th>
            <th style="<?php echo $thStyle; ?>text-align:right;">
                <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_COL_AMOUNT'), ENT_COMPAT, 'UTF-8'); ?>
            </th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($payments as $payment) : ?>
            <tr>
                <td style="<?php echo $tdStyle; ?>"><?php echo htmlspecialchars((string) ($payment->method ?? ''), ENT_COMPAT, 'UTF-8'); ?></td>
                <td style="<?php echo $tdStyle; ?>"><?php echo htmlspecialchars((string) ($payment->status ?? ''), ENT_COMPAT, 'UTF-8'); ?></td>
                <td style="<?php echo $numStyle; ?>"><?php echo htmlspecialchars((string) ($payment->amount ?? ''), ENT_COMPAT, 'UTF-8'); ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
