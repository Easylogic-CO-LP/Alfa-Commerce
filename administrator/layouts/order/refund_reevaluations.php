<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * The warning on the order screen after a refunded payment was set back to completed.
 *
 * When a refund that had made its payment refunded becomes failed or cancelled,
 * the ledger re-evaluates the payment against the refunds still standing and sets
 * it back to completed when they no longer cover it. What the refunded state set
 * off earlier (the order's status, stock, download access, emails to the customer)
 * is not reversed: this says so, per payment, from
 * PaymentLedger::refundReevaluations(), while the payment is still completed.
 * Nothing is printed when there is none.
 *
 * Layout path: administrator/components/com_alfa/layouts/order/refund_reevaluations.php
 * Override:    administrator/templates/{template}/html/layouts/com_alfa/order/refund_reevaluations.php
 *
 * @var array $displayData Contains:
 *   - 'reevaluated' => list<array{payment: int, refund: int, status: string, at: string}>
 *
 * @since  2.1.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$reevaluated = (array) ($displayData['reevaluated'] ?? []);

if ($reevaluated === []) {
    return;
}

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$lines = [];

foreach ($reevaluated as $entry) {
    $status = Text::_($entry['status'] === PaymentLedger::STATUS_CANCELLED ? 'COM_ALFA_REFUND_REEVALUATED_CANCELLED' : 'COM_ALFA_REFUND_REEVALUATED_FAILED');
    $lines[] = Text::sprintf(
        'COM_ALFA_REFUND_REEVALUATED_ITEM',
        (int) $entry['payment'],
        HTMLHelper::_('date', $entry['at'], Text::_('DATE_FORMAT_LC5')),
        (int) $entry['refund'],
        $status,
    );
}
?>
<div class="alert alert-warning" role="alert" data-alfa-refund-reevaluated>
    <h4 class="alert-heading h6 mb-2"><?php echo $escape(Text::_('COM_ALFA_REFUND_REEVALUATED_TITLE')); ?></h4>
    <ul class="mb-0">
        <?php foreach ($lines as $line) : ?>
            <li><?php echo $escape($line); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
