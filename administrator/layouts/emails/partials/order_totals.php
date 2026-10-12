<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 *  Order-email totals breakdown: overridable partial
 *
 *  Rendered for the {order_totals} token. Override at:
 *    templates/<template>/html/layouts/com_alfa/emails/partials/order_totals.php
 *
 *  $displayData (set by EmailService::renderTotalsLayout):
 *    object[]    $items     OrderHelper::getOrderItems() output.
 *    Money|null  $subtotal  items_tax_excl (line totals before tax).
 *    Money|null  $shipping  NET shipping after shipping-class rules (tax-incl).
 *    Money|null  $shipping_gross  Carrier charge BEFORE the benefit; set only
 *                when a shipping rule reduced it, rendered struck-through.
 *    bool        $shipping_free   True when a rule made shipping fully free.
 *    object[]    $cart_rules  One row per non-shipping rule: ->label (coupon
 *                code, or the rule's customer-facing name for automatic
 *                rules) and ->amount (Money, tax-incl).
 *    Money|null  $discount  Aggregate of those rows: the fallback row for
 *                template overrides that predate $cart_rules.
 *    Money|null  $tax       grand_total_tax_incl - grand_total_tax_excl,
 *                rendered as an informational "Includes VAT" note, never
 *                as an addend row.
 *    Money|null  $total     grand_total_tax_incl.
 *
 *  ONE TAX BASIS: every money row is tax-INCLUSIVE, so the visible rows
 *  sum to Total exactly (subtotal + shipping + fees - rules = total).
 *
 *  Numbers come from OrderTotalHelper::getBreakdown, same formula
 *  the admin order view + invoices use. Money objects arrive raw so
 *  the layout (or any override) calls $money->format() directly.
 *
 *  Each row renders only when its Money value is non-null AND non-zero,
 *  so an order with no shipping / no discount doesn't show empty rows.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

// FileLayout passes $displayData as a single array; pull the named
// keys into local scope so the rest reads like a normal template.
extract($displayData);

/** @var object[] $items */
/** @var \Alfa\Component\Alfa\Site\Service\Pricing\Money|null $subtotal */
/** @var \Alfa\Component\Alfa\Site\Service\Pricing\Money|null $shipping */
/** @var \Alfa\Component\Alfa\Site\Service\Pricing\Money|null $discount */
/** @var \Alfa\Component\Alfa\Site\Service\Pricing\Money|null $tax */
/** @var \Alfa\Component\Alfa\Site\Service\Pricing\Money|null $total */

$items      = isset($items) && is_array($items) ? $items : [];
$subtotal   = $subtotal ?? null;
$shipping   = $shipping ?? null;
$discount   = $discount ?? null;
$tax        = $tax ?? null;
$total      = $total ?? null;
$cartRules  = isset($cart_rules) && is_array($cart_rules) ? $cart_rules : [];
$shipGross  = $shipping_gross ?? null;
$shipFree   = !empty($shipping_free);

$labelStyle = 'padding:6px 10px;text-align:left;font-size:13px;color:#6b7280;';
$valueStyle = 'padding:6px 10px;text-align:right;font-size:14px;color:#111827;';
$totalLbl   = 'padding:10px;text-align:left;font-size:14px;font-weight:500;color:#111827;border-top:1px solid #111827;';
$totalVal   = 'padding:10px;text-align:right;font-size:16px;font-weight:600;color:#111827;border-top:1px solid #111827;';
$muted      = 'padding:14px 10px;text-align:center;color:#6b7280;font-style:italic;font-size:14px;';
$wasStyle   = 'text-decoration:line-through;color:#9ca3af;margin-right:6px;';

// A Money row renders only when the value exists and is non-zero. The
// grand-total row is the exception: it always renders if non-null,
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

            <?php if ($showMoney($shipping) || $shipGross !== null) : ?>
                <tr>
                    <td style="<?php echo $labelStyle; ?>">
                        <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TOTALS_SHIPPING'), ENT_COMPAT, 'UTF-8'); ?>
                    </td>
                    <td style="<?php echo $valueStyle; ?>">
                        <?php // A shipping-class rule is a benefit ON this row,
                              // never a negative line beside a full charge;
                              // same verdict rendering as the cart. ?>
                        <?php if ($shipGross !== null) : ?>
                            <span style="<?php echo $wasStyle; ?>"><?php echo $shipGross->format(); ?></span>
                            <?php if ($shipFree) : ?>
                                <strong><?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TOTALS_SHIPPING_FREE'), ENT_COMPAT, 'UTF-8'); ?></strong>
                            <?php else : ?>
                                <?php echo $shipping !== null ? $shipping->format() : ''; ?>
                            <?php endif; ?>
                        <?php else : ?>
                            <?php echo $shipping->format(); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php if ($showMoney($contributions ?? null)) : ?>
                <tr>
                    <td style="<?php echo $labelStyle; ?>">
                        <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TOTALS_CONTRIBUTIONS'), ENT_COMPAT, 'UTF-8'); ?>
                    </td>
                    <td style="<?php echo $valueStyle; ?>"><?php echo $contributions->format(); ?></td>
                </tr>
            <?php endif; ?>

            <?php if ($cartRules !== []) : ?>
                <?php // One row per rule, labelled with the coupon code the
                      // customer typed (automatic rules: their public name). ?>
                <?php foreach ($cartRules as $rule) : ?>
                    <tr>
                        <td style="<?php echo $labelStyle; ?>">
                            <?php echo htmlspecialchars((string) $rule->label, ENT_COMPAT, 'UTF-8'); ?>
                        </td>
                        <td style="<?php echo $valueStyle; ?>">−<?php echo $rule->amount->format(); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php elseif ($showMoney($discount)) : ?>
                <tr>
                    <td style="<?php echo $labelStyle; ?>">
                        <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TOTALS_DISCOUNT'), ENT_COMPAT, 'UTF-8'); ?>
                    </td>
                    <td style="<?php echo $valueStyle; ?>">−<?php echo $discount->format(); ?></td>
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

            <?php // Informational, never an addend; the rows above are all
                  // tax-inclusive and already sum to the Total. ?>
            <?php if ($showMoney($tax)) : ?>
                <tr>
                    <td colspan="2" style="padding:2px 10px;text-align:right;font-size:12px;color:#6b7280;">
                        <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TOTALS_INCLUDES_TAX'), ENT_COMPAT, 'UTF-8'); ?>:
                        <?php echo $tax->format(); ?>
                    </td>
                </tr>
            <?php endif; ?>
        <?php endif; ?>
    </tbody>
</table>
