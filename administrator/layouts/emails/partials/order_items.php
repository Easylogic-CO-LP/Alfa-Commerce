<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * ─────────────────────────────────────────────────────────────────
 *  Order-email items table — overridable partial
 * ─────────────────────────────────────────────────────────────────
 *
 *  Rendered for the {order_items} token. Override at:
 *    templates/<template>/html/layouts/com_alfa/emails/partials/order_items.php
 *
 *  $displayData (set by OrderEmailHelper::renderItemsLayout):
 *    object[]  $items  OrderHelper::getOrderItems() output (Money objects on price fields).
 *
 *  Inline-styled for email-client compatibility — every visible
 *  element carries its own `style=""` so it survives the worst
 *  email-client CSS strip cycle.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

// FileLayout passes the whole displayData array — it does NOT extract
// keys into individual variables (unlike Joomla's BaseLayout). Match
// the com_alfa convention by extracting here so `$items` is in scope.
extract($displayData);

/** @var object[] $items */

$items = isset($items) && is_array($items) ? $items : [];

$thStyle  = 'padding:8px 10px;text-align:left;font-size:11px;letter-spacing:0.12em;text-transform:uppercase;color:#7a6e5d;border-bottom:1px solid #1c1814;';
$tdStyle  = 'padding:10px;text-align:left;border-bottom:1px solid #e8dfd2;color:#1c1814;font-size:14px;';
$numStyle = $tdStyle . 'text-align:right;';
$muted    = 'padding:14px 10px;text-align:center;color:#7a6e5d;font-style:italic;font-size:14px;';

?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
       style="width:100%;border-collapse:collapse;margin:1em 0;">
    <thead>
        <tr>
            <th style="<?php echo $thStyle; ?>">
                <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_COL_ITEM'), ENT_COMPAT, 'UTF-8'); ?>
            </th>
            <th style="<?php echo $thStyle; ?>text-align:right;">
                <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_COL_QTY'), ENT_COMPAT, 'UTF-8'); ?>
            </th>
            <th style="<?php echo $thStyle; ?>text-align:right;">
                <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_COL_UNIT_PRICE'), ENT_COMPAT, 'UTF-8'); ?>
            </th>
            <th style="<?php echo $thStyle; ?>text-align:right;">
                <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_COL_LINE_TOTAL'), ENT_COMPAT, 'UTF-8'); ?>
            </th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($items)) : ?>
            <tr>
                <td colspan="4" style="<?php echo $muted; ?>">
                    <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_ITEMS_EMPTY'), ENT_COMPAT, 'UTF-8'); ?>
                </td>
            </tr>
        <?php else : ?>
            <?php foreach ($items as $item) :
                $name      = (string) ($item->name ?? '');
                $quantity  = (int)    ($item->quantity ?? 0);
                $unit      = isset($item->price) && is_object($item->price)
                    ? (string) $item->price->format()
                    : '';
                $lineTotal = isset($item->total_price_tax_incl) && is_object($item->total_price_tax_incl)
                    ? (string) $item->total_price_tax_incl->format()
                    : '';
                ?>
                <tr>
                    <td style="<?php echo $tdStyle; ?>"><?php echo htmlspecialchars($name, ENT_COMPAT, 'UTF-8'); ?></td>
                    <td style="<?php echo $numStyle; ?>"><?php echo $quantity; ?></td>
                    <td style="<?php echo $numStyle; ?>"><?php echo htmlspecialchars($unit, ENT_COMPAT, 'UTF-8'); ?></td>
                    <td style="<?php echo $numStyle; ?>"><?php echo htmlspecialchars($lineTotal, ENT_COMPAT, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
