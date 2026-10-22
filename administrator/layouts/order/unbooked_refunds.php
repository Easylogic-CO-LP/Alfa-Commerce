<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * The refund block's notice, and the reservations, on the order screen.
 *
 * While a payment provider has reported refunds on the order that the books do
 * not hold and that hold the refund block, no new refund starts on any of its
 * payments and Refund is not offered. The first notice says so, and lists each
 * such report with its payment, the provider's refund id (or the report's
 * reference when the provider gave none), its amount and when it was reported,
 * from the same answer the block is decided on
 * (PaymentLedger::unbookedProviderRefunds()). A report the provider has not
 * settled yet whose amount cannot be reserved exactly says so. A report with no
 * exact amount in the order's currency shows the provider's figure as stated and
 * what to do. A gateway answer that cannot be read, or a malformed report, is
 * named with what is wrong. A refund reservation its provider settled while the books
 * refused the booking is listed as the core's own settled report (since 2.1.0), with
 * the same actions. When the provider sent more than one word about a refund core keeps
 * for staff (another figure, a hold after a settle or a settle after a hold, a failure
 * the provider reported), its line names every word, latest first, with its kind: the
 * report states the latest settled one, which Record this refund books (since 2.1.0).
 *
 * The second notice, apart, lists what is reserved in the order's refundable
 * pool: the refunds the provider has accepted but not completed whose exact
 * amount is known (each with its payment, reference, the amount reserved and
 * when it was reported), and the order's refunds in the books that are not
 * settled, pending or authorized, every refund reservation among them (each
 * with its payment, row, amount, status and since when; since 2.1.0). Nothing
 * is printed when nothing is reported or reserved.
 *
 * Layout path: administrator/components/com_alfa/layouts/order/unbooked_refunds.php
 * Override:    administrator/templates/{template}/html/layouts/com_alfa/order/unbooked_refunds.php
 *
 * @var array $displayData Contains:
 *   - 'unbooked' => array{refunds: array<int, list<array{reportId: string, id: ?string, amount: ?Money, providerAmount: string, providerCurrency: string, reportedOn: string, status: string, words?: list<array{kind: string, id: string, amount: string, currency: string, at: string}>}>>, reserved: array<int, list<array{reportId: string, id: ?string, amount: Money, providerAmount: string, providerCurrency: string, reportedOn: string, status: string}>>, unread: array<int, string>, rows: array<int, list<array{id: int, amount: Money, status: string, reference: string, added: string}>>}
 *   - 'currency' => Currency, the order's
 *
 * @since  2.1.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger;
use Joomla\CMS\Language\Text;

$unbooked = (array) ($displayData['unbooked'] ?? []);
$refunds = (array) ($unbooked['refunds'] ?? []);
$reserved = (array) ($unbooked['reserved'] ?? []);
$unread = (array) ($unbooked['unread'] ?? []);
$rows = (array) ($unbooked['rows'] ?? []);

if ($refunds === [] && $reserved === [] && $unread === [] && $rows === []) {
    return;
}

// Every line quotes the provider's own values: escaped once, here.
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$lines = [];
$reservations = [];

$code = isset($displayData['currency']) ? (string) $displayData['currency']->getCode() : '';

// Every word the provider sent about a refund core keeps for staff, latest first, when there is more than one.
$words = static function (array $refund): string {
    $list = (array) ($refund['words'] ?? []);

    if (\count($list) < 2) {
        return '';
    }

    return ' ' . Text::sprintf('COM_ALFA_UNBOOKED_REFUNDS_WORDS', implode('; ', array_map(
        static fn (array $word): string => Text::sprintf(
            match ((string) ($word['kind'] ?? '')) {
                PaymentLedger::REPORT_STATUS_PENDING => 'COM_ALFA_REFUND_WORD_HELD',
                PaymentLedger::STATUS_FAILED, PaymentLedger::STATUS_CANCELLED => 'COM_ALFA_REFUND_WORD_FAILED',
                default => 'COM_ALFA_REFUND_WORD_SETTLED',
            },
            (string) ($word['id'] ?? '') !== '' ? (string) $word['id'] : Text::_('COM_ALFA_REFUND_WORD_NO_ID'),
            (string) ($word['amount'] ?? ''),
            (string) ($word['currency'] ?? ''),
            (string) ($word['at'] ?? ''),
        ),
        $list,
    )));
};

foreach ($refunds as $paymentId => $list) {
    foreach ($list as $refund) {
        $reference = $refund['id'] ?? Text::sprintf('COM_ALFA_REPORT_NO_ID', $refund['reportId']);

        if (($refund['status'] ?? '') === PaymentLedger::REPORT_STATUS_PENDING) {
            $lines[] = Text::sprintf('COM_ALFA_UNBOOKED_REFUNDS_ITEM_PENDING', (int) $paymentId, $reference, $refund['providerAmount'], $refund['providerCurrency'], $refund['reportedOn'], $code) . $words($refund);

            continue;
        }

        $lines[] = ($refund['amount'] === null
            ? Text::sprintf('COM_ALFA_UNBOOKED_REFUNDS_ITEM_UNKNOWN', (int) $paymentId, $reference, $refund['providerAmount'], $refund['providerCurrency'], $refund['reportedOn'], $code)
            : Text::sprintf('COM_ALFA_UNBOOKED_REFUNDS_ITEM', (int) $paymentId, $reference, $refund['amount']->format(), $refund['reportedOn'])) . $words($refund);
    }
}

foreach ($unread as $paymentId => $why) {
    $lines[] = Text::sprintf('COM_ALFA_UNBOOKED_REFUNDS_UNREAD', (int) $paymentId, (string) $why);
}

foreach ($reserved as $paymentId => $list) {
    foreach ($list as $refund) {
        $reference = $refund['id'] ?? Text::sprintf('COM_ALFA_REPORT_NO_ID', $refund['reportId']);
        $reservations[] = Text::sprintf('COM_ALFA_REFUND_RESERVATIONS_ITEM', (int) $paymentId, $reference, $refund['amount']->format(), $refund['reportedOn']);
    }
}

// The refunds in the books that are not settled reserve their amount too.
foreach ($rows as $paymentId => $list) {
    foreach ($list as $row) {
        $status = Text::_('COM_ALFA_STATUS_' . strtoupper((string) $row['status']));
        $reservations[] = (int) $paymentId > 0
            ? Text::sprintf('COM_ALFA_REFUND_RESERVATION_ROW_ITEM', (int) $paymentId, (int) $row['id'], $row['amount']->format(), $status, (string) $row['added'])
            : Text::sprintf('COM_ALFA_REFUND_RESERVATION_ROW_ITEM_ORDER', (int) $row['id'], $row['amount']->format(), $status, (string) $row['added']);
    }
}
?>
<?php if ($lines !== []) : ?>
<div class="alert alert-warning" role="alert" data-alfa-unbooked-refunds>
    <h4 class="alert-heading h6 mb-2"><?php echo $escape(Text::_('COM_ALFA_UNBOOKED_REFUNDS_TITLE')); ?></h4>
    <p class="mb-2"><?php echo $escape(Text::_('COM_ALFA_UNBOOKED_REFUNDS_INTRO')); ?></p>
    <ul class="mb-0">
        <?php foreach ($lines as $line) : ?>
            <li><?php echo $escape($line); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<?php if ($reservations !== []) : ?>
<div class="alert alert-info" role="status" data-alfa-refund-reservations>
    <h4 class="alert-heading h6 mb-2"><?php echo $escape(Text::_('COM_ALFA_REFUND_RESERVATIONS_TITLE')); ?></h4>
    <p class="mb-2"><?php echo $escape(Text::_('COM_ALFA_REFUND_RESERVATIONS_INTRO')); ?></p>
    <ul class="mb-0">
        <?php foreach ($reservations as $line) : ?>
            <li><?php echo $escape($line); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
