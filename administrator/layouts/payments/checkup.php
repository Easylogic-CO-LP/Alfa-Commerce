<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The checkup card of a payment method: its setup steps, each with where it
 * stands, and the "Check connection" button.
 *
 * One renderer for both the settings form and the button's answer, so the
 * card the button swaps in is exactly the card the page drew.
 *
 * The steps and the button sit in a native <details>, as the order's
 * reconciliation panel does: collapsed to the title and the progress badge,
 * open when a step failed or when the card answers a "Check connection". No
 * JavaScript and no stored UI state either way. The step help
 * comes from the gateway's own language file and may carry markup; the
 * {webhook} and {return} tokens become this site's addresses, escaped here.
 *
 * The progress counts only the steps the site can judge: done, to do and
 * failed. A step the operator must confirm, or one this method cannot do, is
 * shown with its icon and not counted, so a card can turn green without
 * claiming something the site did not check.
 *
 * $displayData:
 *   id       string  DOM id of the card.
 *   title    string  Translated card title.
 *   steps    array   [title, status, help, detail] from PaymentCheckupEvent.
 *   webhook  string  Webhook address.
 *   return   string  Buyer-return address.
 *   notice   string  Plain text shown instead of the steps (e.g. save first).
 *   check    array|null  [url, token, method, variant, checked] when a button is offered.
 *   open     bool    Draw the card open (it answers a pressed "Check connection").
 *   active   bool|null  True when this card's credential set is the one new payments
 *                    use (the "In use" badge), false for the other set, null for a
 *                    card without a set (no badge).
 *
 * @since  1.10.0
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Payments\PaymentCheckupEvent;
use Joomla\CMS\Language\Text;

$esc = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

$steps   = (array) ($displayData['steps'] ?? []);
$counted = array_filter($steps, static fn (array $step): bool => in_array($step['status'], [PaymentCheckupEvent::DONE, PaymentCheckupEvent::TODO, PaymentCheckupEvent::FAILED], true));
$done    = count(array_filter($counted, static fn (array $step): bool => $step['status'] === PaymentCheckupEvent::DONE));
$failed  = count(array_filter($counted, static fn (array $step): bool => $step['status'] === PaymentCheckupEvent::FAILED));
$check   = $displayData['check'] ?? null;
$active  = $displayData['active'] ?? null;

$tokens = [
    '{webhook}' => '<code class="user-select-all">' . $esc((string) ($displayData['webhook'] ?? '')) . '</code>',
    '{return}'  => '<code class="user-select-all">' . $esc((string) ($displayData['return'] ?? '')) . '</code>',
];

// Icon, colour and the word a screen reader hears, per status.
$look = [
    PaymentCheckupEvent::DONE   => ['icon-check', 'text-success', 'COM_ALFA_CHECKUP_STATUS_DONE'],
    PaymentCheckupEvent::TODO   => ['icon-circle', 'text-body-secondary', 'COM_ALFA_CHECKUP_STATUS_TODO'],
    PaymentCheckupEvent::FAILED => ['icon-times', 'text-danger', 'COM_ALFA_CHECKUP_STATUS_FAILED'],
    PaymentCheckupEvent::MANUAL => ['icon-info-circle', 'text-warning', 'COM_ALFA_CHECKUP_STATUS_MANUAL'],
    PaymentCheckupEvent::UNAVAILABLE => ['icon-minus-circle', 'text-body-secondary', 'COM_ALFA_CHECKUP_STATUS_UNAVAILABLE'],
];

$badge = $failed > 0 ? 'bg-danger' : ($counted !== [] && $done === count($counted) ? 'bg-success' : 'bg-secondary');
$open  = !empty($displayData['open']) || $failed > 0;

// "In use" on the card of the set new payments use, "Not in use" on the other.
$inUse = match ($active) {
    true    => '<span class="badge bg-primary ms-2">' . Text::_('COM_ALFA_CHECKUP_IN_USE') . '</span>',
    false   => '<span class="badge border border-secondary text-body-secondary ms-2">' . Text::_('COM_ALFA_CHECKUP_NOT_IN_USE') . '</span>',
    default => '',
};
?>
<div class="card w-100 alfa-checkup" id="<?php echo $esc((string) ($displayData['id'] ?? '')); ?>" data-alfa-checkup>
    <?php if (!empty($displayData['notice'])) : ?>
        <div class="card-header">
            <strong><?php echo $esc((string) ($displayData['title'] ?? '')); ?></strong><?php echo $inUse; ?>
        </div>
        <div class="card-body small text-body-secondary"><?php echo $esc((string) $displayData['notice']); ?></div>
    <?php else : ?>
        <details<?php echo $open ? ' open' : ''; ?>>
            <summary class="card-header d-flex justify-content-between align-items-center gap-2">
                <span>
                    <span class="icon-chevron-down alfa-checkup-caret me-1" aria-hidden="true"></span>
                    <strong><?php echo $esc((string) ($displayData['title'] ?? '')); ?></strong><?php echo $inUse; ?>
                </span>
                <?php if ($counted !== []) : ?>
                    <span class="badge <?php echo $badge; ?>">
                        <?php echo Text::sprintf('COM_ALFA_CHECKUP_PROGRESS', $done, count($counted)); ?>
                    </span>
                <?php endif; ?>
            </summary>

            <ol class="list-group list-group-flush list-group-numbered">
                <?php foreach ($steps as $step) : ?>
                    <?php [$icon, $colour, $word] = $look[$step['status']]; ?>
                    <li class="list-group-item d-flex align-items-start gap-2">
                        <span class="<?php echo $icon . ' ' . $colour; ?> mt-1" aria-hidden="true"></span>
                        <div class="flex-grow-1">
                            <div class="fw-semibold">
                                <?php echo $esc($step['title']); ?>
                                <span class="visually-hidden">(<?php echo Text::_($word); ?>)</span>
                            </div>
                            <?php if ($step['help'] !== '') : ?>
                                <div class="small text-body-secondary"><?php echo strtr($step['help'], $tokens); ?></div>
                            <?php endif; ?>
                            <?php if ($step['detail'] !== '') : ?>
                                <div class="small <?php echo $step['status'] === PaymentCheckupEvent::FAILED ? 'text-danger' : ''; ?>"><?php echo $esc($step['detail']); ?></div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>

            <?php if (is_array($check)) : ?>
                <div class="card-footer d-flex flex-wrap align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-primary"
                            data-alfa-checkup-run
                            data-url="<?php echo $esc($check['url']); ?>"
                            data-token="<?php echo $esc($check['token']); ?>"
                            data-method="<?php echo (int) $check['method']; ?>"
                            data-variant="<?php echo $esc($check['variant']); ?>"
                            data-error="<?php echo $esc(Text::_('COM_ALFA_CHECKUP_ERROR')); ?>">
                        <span class="icon-refresh me-1" aria-hidden="true"></span><?php echo Text::_('COM_ALFA_CHECKUP_RUN'); ?>
                    </button>
                    <span class="small text-body-secondary" data-alfa-checkup-state aria-live="polite">
                        <?php echo $esc((string) ($check['checked'] ?? '')); ?>
                    </span>
                </div>
            <?php endif; ?>
        </details>
    <?php endif; ?>
</div>
