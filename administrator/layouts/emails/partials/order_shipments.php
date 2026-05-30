<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * ─────────────────────────────────────────────────────────────────
 *  Order-email shipments table — overridable partial (customer-minimal)
 * ─────────────────────────────────────────────────────────────────
 *
 *  Rendered by the layout via:
 *    $render('emails.partials.order_shipments', ['shipments' => $shipments])
 *  Override at:
 *    templates/<template>/html/layouts/com_alfa/emails/partials/order_shipments.php
 *
 *  $displayData:
 *    object[]  $shipments  Each: {method, tracking, status} — already
 *                          customer-facing (no cost/carrier/weight/ids).
 *
 *  The tracking column shows a dash when empty. Renders nothing when
 *  there are no shipments (the layout also guards with !empty).
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

extract($displayData);

/** @var object[] $shipments */
$shipments = isset($shipments) && is_array($shipments) ? $shipments : [];

if (empty($shipments)) {
    return;
}

$thStyle = 'padding:8px 10px;text-align:left;font-size:11px;letter-spacing:0.12em;text-transform:uppercase;color:#6b7280;border-bottom:1px solid #111827;';
$tdStyle = 'padding:9px 10px;text-align:left;border-bottom:1px solid #e5e7eb;color:#111827;font-size:14px;';

?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
       style="width:100%;border-collapse:collapse;margin:1em 0;">
    <thead>
        <tr>
            <th style="<?php echo $thStyle; ?>">
                <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_COL_SHIPMENT_METHOD'), ENT_COMPAT, 'UTF-8'); ?>
            </th>
            <th style="<?php echo $thStyle; ?>">
                <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_COL_STATUS'), ENT_COMPAT, 'UTF-8'); ?>
            </th>
            <th style="<?php echo $thStyle; ?>">
                <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_COL_TRACKING'), ENT_COMPAT, 'UTF-8'); ?>
            </th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($shipments as $shipment) :
            $tracking = (string) ($shipment->tracking ?? '');
            ?>
            <tr>
                <td style="<?php echo $tdStyle; ?>"><?php echo htmlspecialchars((string) ($shipment->method ?? ''), ENT_COMPAT, 'UTF-8'); ?></td>
                <td style="<?php echo $tdStyle; ?>"><?php echo htmlspecialchars((string) ($shipment->status ?? ''), ENT_COMPAT, 'UTF-8'); ?></td>
                <td style="<?php echo $tdStyle; ?>"><?php echo $tracking !== '' ? htmlspecialchars($tracking, ENT_COMPAT, 'UTF-8') : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
