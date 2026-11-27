<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * One card dispute on the order screen: its state and round, the deadline in the shop's time zone, the payment and the
 * disputed amount, the reason, evidence sent, the fee, the provider's reference, who made the record, what the books
 * hold for it, the question staff must answer when the record waits, the note, and the actions offered now.
 *
 * @var array $displayData
 *   dispute   object        the record, as DisputeLedger::forOrder() reads it (with booked, rows, candidates)
 *   payment   object|null   its payment row as the order screen holds it
 *   currency  \Alfa\Component\Alfa\Site\Service\Pricing\Currency  the order's currency at its frozen scale
 *   actions   \Alfa\Component\Alfa\Administrator\Plugin\PluginAction[]  the card's actions for this user
 *
 * @since  2.3.0
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\DisputeLedger;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\User\UserFactoryInterface;

$dispute = $displayData['dispute'];
$payment = $displayData['payment'] ?? null;
$currency = $displayData['currency'];
$actions = (array) ($displayData['actions'] ?? []);
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$date = static fn (string $utc): string => HTMLHelper::_('date', $utc, Text::_('DATE_FORMAT_LC3'), null);
$money = static fn (?string $amount): string => $amount === null ? '-' : Money::of($amount, $currency)->format();
$state = (string) $dispute->state;
$badge = match ($state) {
    DisputeLedger::STATE_INQUIRY => 'bg-warning text-dark',
    DisputeLedger::STATE_OPEN, DisputeLedger::STATE_LOST => 'bg-danger',
    DisputeLedger::STATE_UNDER_REVIEW => 'text-bg-info',
    DisputeLedger::STATE_WON => 'bg-success',
    default => 'bg-secondary',
};
$amount = $dispute->amount !== null
    ? $money((string) $dispute->amount)
    : Text::sprintf('COM_ALFA_DISPUTE_CARD_STATED_ONLY', PublishedDecimal::decimal((string) $dispute->stated_amount), $escape((string) $dispute->stated_currency));
$source = match (true) {
    (string) $dispute->source === DisputeLedger::SOURCE_MIGRATED => Text::_('COM_ALFA_DISPUTE_CARD_SOURCE_MIGRATED'),
    (string) $dispute->source === DisputeLedger::SOURCE_PROVIDER => Text::sprintf('COM_ALFA_DISPUTE_CARD_SOURCE_PROVIDER', $escape((string) ($payment->payment_method ?? $dispute->payment_method_type))),
    default => Text::sprintf('COM_ALFA_DISPUTE_CARD_SOURCE_STAFF', $escape((string) Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $dispute->created_by)->name)),
};
$deadline = '';

if ($dispute->evidence_due_by !== null && \in_array($state, [DisputeLedger::STATE_INQUIRY, DisputeLedger::STATE_OPEN], true) && $dispute->evidence_sent_at === null) {
    $due = Factory::getDate((string) $dispute->evidence_due_by, 'UTC');
    // Whole days left: less than a day is today, and a deadline that has passed is overdue at once.
    $days = (int) floor(($due->toUnix() - time()) / 86400);
    $deadline = $days >= 0
        ? Text::plural('COM_ALFA_DISPUTE_CARD_ANSWER_BY', $days, $date((string) $dispute->evidence_due_by))
        : Text::sprintf('COM_ALFA_DISPUTE_CARD_OVERDUE', $date((string) $dispute->evidence_due_by));
}
?>
<div class="card mb-3 dispute" id="dispute-<?php echo (int) $dispute->id; ?>">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <strong><?php echo Text::sprintf('COM_ALFA_DISPUTE_CARD_TITLE', (int) $dispute->id); ?></strong>
        <span class="badge <?php echo $badge; ?>"><?php echo Text::_('COM_ALFA_DISPUTE_STATE_' . strtoupper($state)); ?></span>
        <?php if ((int) $dispute->stage > 1) : ?>
            <span class="badge bg-light text-dark border"><?php echo Text::sprintf('COM_ALFA_DISPUTE_CARD_ROUND', (int) $dispute->stage); ?></span>
        <?php endif; ?>
        <?php if ($deadline !== '') : ?>
            <span class="ms-auto"><?php echo $escape($deadline); ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ((string) ($dispute->waits_for ?? '') !== '') : ?>
            <div class="alert alert-warning" role="alert">
                <?php echo (string) $dispute->waits_for === DisputeLedger::WAITS_SAME_PAYMENT
                    ? Text::sprintf('COM_ALFA_DISPUTE_CARD_ASK_SAME', $escape((string) $dispute->reference), '#' . implode(', #', array_map('intval', (array) $dispute->candidates)))
                    : Text::_('COM_ALFA_DISPUTE_CARD_ASK_AMOUNT'); ?>
            </div>
        <?php endif; ?>
        <dl class="row mb-0">
            <dt class="col-sm-4"><?php echo Text::_('COM_ALFA_DISPUTE_CARD_PAYMENT'); ?></dt>
            <dd class="col-sm-8"><?php echo Text::sprintf('COM_ALFA_DISPUTE_CARD_PAYMENT_LINE', (int) $dispute->id_payment, $escape((string) ($payment->payment_method ?? ''))); ?></dd>
            <dt class="col-sm-4"><?php echo Text::_('COM_ALFA_DISPUTE_CARD_AMOUNT'); ?></dt>
            <dd class="col-sm-8">
                <?php echo $escape($amount); ?>
                <?php if ($dispute->amount !== null && (string) $dispute->stated_currency !== $currency->getCode()) : ?>
                    <small class="text-muted"><?php echo Text::sprintf('COM_ALFA_DISPUTE_CARD_STATED', PublishedDecimal::decimal((string) $dispute->stated_amount), $escape((string) $dispute->stated_currency)); ?></small>
                <?php endif; ?>
                <?php if (\in_array($state, DisputeLedger::UNDECIDED_STATES, true) && $dispute->outcome === null) : ?>
                    <small class="d-block text-muted"><?php echo Text::_('COM_ALFA_DISPUTE_CARD_UNDECIDED'); ?></small>
                <?php endif; ?>
            </dd>
            <dt class="col-sm-4"><?php echo Text::_('COM_ALFA_DISPUTE_CARD_REASON'); ?></dt>
            <dd class="col-sm-8">
                <?php echo Text::_('COM_ALFA_DISPUTE_REASON_' . strtoupper((string) $dispute->reason_code)); ?>
                <?php if ((string) $dispute->reason_text !== '') : ?>
                    <small class="text-muted">(<?php echo $escape((string) $dispute->reason_text); ?>)</small>
                <?php endif; ?>
            </dd>
            <?php if ($dispute->evidence_sent_at !== null) : ?>
                <dt class="col-sm-4"><?php echo Text::_('COM_ALFA_DISPUTE_CARD_EVIDENCE_SENT'); ?></dt>
                <dd class="col-sm-8"><?php echo $date((string) $dispute->evidence_sent_at); ?></dd>
            <?php endif; ?>
            <?php if ($dispute->fee_amount !== null) : ?>
                <dt class="col-sm-4"><?php echo Text::_('COM_ALFA_DISPUTE_CARD_FEE'); ?></dt>
                <dd class="col-sm-8">
                    <?php echo $escape(PublishedDecimal::decimal((string) $dispute->fee_amount) . ' ' . (string) $dispute->fee_currency); ?>
                    <?php if ($dispute->fee_returned !== null) : ?>
                        <small class="text-muted"><?php echo Text::sprintf('COM_ALFA_DISPUTE_CARD_FEE_RETURNED', $escape(PublishedDecimal::decimal((string) $dispute->fee_returned) . ' ' . (string) $dispute->fee_currency)); ?></small>
                    <?php endif; ?>
                </dd>
            <?php endif; ?>
            <dt class="col-sm-4"><?php echo Text::_('COM_ALFA_DISPUTE_CARD_REFERENCE'); ?></dt>
            <dd class="col-sm-8">
                <?php if ((string) ($dispute->reference ?? '') === '') : ?>
                    -
                <?php elseif ((string) $dispute->provider_link !== '') : ?>
                    <a href="<?php echo $escape((string) $dispute->provider_link); ?>" target="_blank" rel="noopener noreferrer"><?php echo $escape((string) $dispute->reference); ?></a>
                <?php else : ?>
                    <?php echo $escape((string) $dispute->reference); ?>
                <?php endif; ?>
                <small class="d-block text-muted"><?php echo $source; ?></small>
            </dd>
            <dt class="col-sm-4"><?php echo Text::_('COM_ALFA_DISPUTE_CARD_BOOKS'); ?></dt>
            <dd class="col-sm-8">
                <?php if ((array) $dispute->rows === []) : ?>
                    <?php echo Text::_('COM_ALFA_DISPUTE_CARD_BOOKS_NONE'); ?>
                <?php else : ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ((array) $dispute->rows as $row) : ?>
                            <li><?php echo Text::sprintf(
                                (string) $row->payment_type === 'chargeback' ? 'COM_ALFA_DISPUTE_CARD_BOOKED_CHARGEBACK' : 'COM_ALFA_DISPUTE_CARD_BOOKED_REVERSAL',
                                $money(ltrim((string) $row->amount, '-')),
                                $date((string) ($row->processed_at ?? $row->added)),
                            ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </dd>
            <?php if ((string) $dispute->note !== '') : ?>
                <dt class="col-sm-4"><?php echo Text::_('COM_ALFA_DISPUTE_CARD_NOTE'); ?></dt>
                <dd class="col-sm-8"><?php echo $escape((string) $dispute->note); ?></dd>
            <?php endif; ?>
        </dl>
        <?php if ($actions !== [] && $payment !== null) : ?>
            <div class="mt-3">
                <?php foreach ($actions as $action) : ?>
                    <?php echo LayoutHelper::render('actions.button', ['action' => $action, 'context' => 'payment', 'id' => (int) $dispute->id_payment], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
