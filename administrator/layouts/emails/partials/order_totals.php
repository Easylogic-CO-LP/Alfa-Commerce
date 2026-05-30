<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * ─────────────────────────────────────────────────────────────────
 *  Order-email totals breakdown — overridable partial
 * ─────────────────────────────────────────────────────────────────
 *
 *  Rendered for the {order_totals} token. Override at:
 *    templates/<template>/html/layouts/com_alfa/emails/partials/order_totals.php
 *
 *  $displayData (set by OrderEmailHelper::renderTotalsLayout):
 *    object[]    $items     OrderHelper::getOrderItems() output.
 *    Money|null  $subtotal  items_tax_excl (line totals before tax).
 *    Money|null  $shipping  Sum of shipment rows (tax-incl).
 *    Money|null  $discount  Sum of discount/coupon rows (tax-incl).
 *    Money|null  $tax       grand_total_tax_incl − grand_total_tax_excl.
 *    Money|null  $total     grand_total_tax_incl (subtotal + shipping − discount + tax).
 *
 *  Numbers come from OrderTotalHelper::getBreakdown — same formula
 *  the admin order view + invoices use. Money objects arrive raw so
 *  the layout (or any override) calls $money->format() directly.
 *
 *  Each row renders only when its Money value is non-null AND non-zero,
 *  so an order with no shipping / no discount doesn't show empty rows.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

// FileLayout passes $displayData as a single array — pull the named
// keys into local scope so the rest reads like a normal template.
extract($displayData);

/** @var object[] $items */
/** @var \Alfa\Component\Alfa\Site\Service\Pricing\Money|null $subtotal */
/** @var \Alfa\Component\Alfa\Site\Service\Pricing\Money|null $shipping */
/** @var \Alfa\Component\Alfa\Site\Service\Pricing\Money|null $discount */
/** @var \Alfa\Component\Alfa\Site\Service\Pricing\Money|null $tax */
/** @var \Alfa\Component\Alfa\Site\Service\Pricing\Money|null $total */

$items    = isset($items) && is_array($items) ? $items : [];
$subtotal = $subtotal ?? null;
$shipping = $shipping ?? null;
$discount = $discount ?? null;
$tax      = $tax ?? null;
$total    = $total ?? null;

$labelStyle = 'padding:6px 10px;text-align:left;font-size:13px;color:#6b7280;';
$valueStyle = 'padding:6px 10px;text-align:right;font-size:14px;color:#111827;';
$totalLbl   = 'padding:10px;text-align:left;font-size:14px;font-weight:500;color:#111827;border-top:1px solid #111827;';
$totalVal   = 'padding:10px;text-align:right;font-size:16px;font-weight:600;color:#111827;border-top:1px solid #111827;';
$muted      = 'padding:14px 10px;text-align:center;color:#6b7280;font-style:italic;font-size:14px;';

// A Money row renders only when the value exists and is non-zero. The
// grand-total row is the exception — it always renders if non-null,
// even at 0, so admins see "Total: €0.00" rather than nothing.
$showMoney = static fn($money) => $money !== null && !$money->isZero();

?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
       style="width:100%;border-collapse:collapse;margin:1em 0;">
    <tbody>
        <?php if (empty($items) || ($subtotal === null && $total === null)) : ?>
            <tr>
                <td colspan="2" style="<?php echo $muted; ?>">
                    <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TOTALS_EMPTY'), ENT_COMPAT, 'UTF-8'); ?>
                </td>
            </tr>
        <?php else : ?>
            <?php if ($showMoney($subtotal)) : ?>
                <tr>
                    <td style="<?php echo $labelStyle; ?>">
                        <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TOTALS_SUBTOTAL'), ENT_COMPAT, 'UTF-8'); ?>
                    </td>
                    <td style="<?php echo $valueStyle; ?>"><?php echo $subtotal->format(); ?></td>
                </tr>
            <?php endif; ?>

            <?php if ($showMoney($shipping)) : ?>
                <tr>
                    <td style="<?php echo $labelStyle; ?>">
                        <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TOTALS_SHIPPING'), ENT_COMPAT, 'UTF-8'); ?>
                    </td>
                    <td style="<?php echo $valueStyle; ?>"><?php echo $shipping->format(); ?></td>
                </tr>
            <?php endif; ?>

            <?php if ($showMoney($discount)) : ?>
                <tr>
                    <td style="<?php echo $labelStyle; ?>">
                        <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TOTALS_DISCOUNT'), ENT_COMPAT, 'UTF-8'); ?>
                    </td>
                    <td style="<?php echo $valueStyle; ?>">−<?php echo $discount->format(); ?></td>
                </tr>
            <?php endif; ?>

            <?php if ($showMoney($tax)) : ?>
                <tr>
                    <td style="<?php echo $labelStyle; ?>">
                        <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TOTALS_TAX'), ENT_COMPAT, 'UTF-8'); ?>
                    </td>
                    <td style="<?php echo $valueStyle; ?>"><?php echo $tax->format(); ?></td>
                </tr>
            <?php endif; ?>

            <?php if ($total !== null) : ?>
                <tr>
                    <td style="<?php echo $totalLbl; ?>">
                        <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TOTALS_GRAND_TOTAL'), ENT_COMPAT, 'UTF-8'); ?>
                    </td>
                    <td style="<?php echo $totalVal; ?>"><?php echo $total->format(); ?></td>
                </tr>
            <?php endif; ?>
        <?php endif; ?>
    </tbody>
</table>
