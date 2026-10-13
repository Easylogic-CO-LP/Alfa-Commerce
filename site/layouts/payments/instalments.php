<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The instalment choice in the cart, for a payment method where the shop's
 * tiers let the customer pick the number of instalments.
 *
 * Rendered only when more than one instalment is on offer. The customer's
 * choice posts as cartform[instalments] (InstalmentTiers::CART_INPUT). The
 * amounts are a disclosure: the bank splits the payment and the order total
 * does not change.
 *
 * A gateway renders it through the component, so a template override applies:
 *
 *   LayoutHelper::render('payments.instalments', $data, '', ['component' => 'com_alfa', 'client' => 'site']);
 *
 * $displayData:
 *   id        string              DOM id of the select.
 *   max       int                 The highest count on offer (MethodProfile::maxInstalments()).
 *   amounts   array<int, string>  count => one instalment, formatted (InstalmentTiers::amounts()).
 *   selected  int                 The count shown as chosen (InstalmentTiers::selected()).
 *
 * @since  2.0.0
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\InstalmentTiers;
use Joomla\CMS\Language\Text;

$max = (int) ($displayData['max'] ?? 1);

if ($max < 2) {
    return;
}

$id       = htmlspecialchars((string) ($displayData['id'] ?? ''), ENT_QUOTES, 'UTF-8');
$amounts  = (array) ($displayData['amounts'] ?? []);
$selected = (int) ($displayData['selected'] ?? 1);
?>
<div class="mt-2 ms-4">
    <label for="<?php echo $id; ?>" class="form-label fw-bold small">
        <?php echo Text::_('COM_ALFA_PAYMENT_INSTALMENTS_SELECT'); ?>
    </label>

    <select name="cartform[<?php echo InstalmentTiers::CART_INPUT; ?>]" id="<?php echo $id; ?>" class="form-select form-select-sm" aria-describedby="<?php echo $id; ?>-hint">
        <option value="1"<?php echo $selected === 1 ? ' selected' : ''; ?>><?php echo Text::_('COM_ALFA_PAYMENT_INSTALMENTS_SINGLE'); ?></option>
        <?php foreach ($amounts as $count => $amount) : ?>
            <?php if ((int) $count >= 2 && (int) $count <= $max) : ?>
                <option value="<?php echo (int) $count; ?>"<?php echo $selected === (int) $count ? ' selected' : ''; ?>>
                    <?php echo htmlspecialchars(Text::sprintf('COM_ALFA_PAYMENT_INSTALMENTS_OPTION', (int) $count, (string) $amount), ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endif; ?>
        <?php endforeach; ?>
    </select>

    <div class="form-text small" id="<?php echo $id; ?>-hint">
        <?php echo Text::_('COM_ALFA_PAYMENT_INSTALMENTS_HINT'); ?>
    </div>
</div>
