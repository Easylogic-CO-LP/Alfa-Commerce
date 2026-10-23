<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The setup checklist card: the WHOLE component as one node, server-rendered here and
 * only here: the Dashboard prints it on page load and the refresh endpoint returns this
 * same layout, so the JS never builds markup; it swaps the node (the notifications
 * badge convention). Every selector the JS touches is a `data-region` / `data-action`
 * contract; presentation state (`data-state`) is declarative so a refresh preserves it.
 *
 * Row actions are TOUR-FIRST: while the row's guided tour exists and this user has not
 * completed/skipped it, the primary action starts the tour ("Show me how", the core
 * `button-start-guidedtour` API) and the deep link demotes to "just take me there";
 * afterwards they flip. No tour in the DB (they ship in a later slice) -> plain CTA.
 * Styling: setup-dashboard.css.
 *
 * @var array $displayData  ['status'       => array (SetupHelper::evaluate() shape),
 *                           'tours'        => array<uid, {id:int, seen:bool}>,
 *                           'state'        => 'open'|'collapsed',
 *                           'canProvision' => bool: may this viewer run a row's one-click fix]
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Setup\SetupCheck;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$status = $displayData['status'] ?? [];
$tours  = $displayData['tours'] ?? [];
$state  = ($displayData['state'] ?? 'open') === 'collapsed' ? 'collapsed' : 'open';

// The one-click fix is offered only to who may run it (setup.provision refuses everyone else);
// the row still offers Open and its tour.
$canProvision = !empty($displayData['canProvision']);

$counts  = $status['counts'] ?? [];
$total   = (int) ($counts['total'] ?? 0);
$done    = (int) ($counts['done'] ?? 0);
$missing = (int) ($counts['required_missing'] ?? 0);
$percent = $total > 0 ? (int) round($done / $total * 100) : 0;
$ready   = $total > 0 && $done === $total;

// A finished setup must not keep announcing itself: once every
// step passes the strip leaves the page altogether and the dashboard title carries
// a small tick instead. The tick reopens the checklist for a review; the next load
// quietens it again. The node itself stays in the DOM so the refresh endpoint has
// something to swap when a check starts failing again.
if ($ready) {
    $state = 'done';
}

$tiers = [
    SetupCheck::TIER_REQUIRED    => ['label' => Text::_('COM_ALFA_SETUP_TIER_REQUIRED'),    'rows' => []],
    SetupCheck::TIER_RECOMMENDED => ['label' => Text::_('COM_ALFA_SETUP_TIER_RECOMMENDED'), 'rows' => []],
    SetupCheck::TIER_OPTIONAL    => ['label' => Text::_('COM_ALFA_SETUP_TIER_OPTIONAL'),    'rows' => []],
];

// Real inline SVGs, OUR icons, the same stroke grammar as the admin menu set
// (24 viewBox, currentColor, 1.6 round strokes), so the card owns its glyphs and
// renders identically under any admin template, icon font or none.
$svg = static function (string $name): string {
    $paths = [
        'check'        => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'circle'       => '<circle cx="12" cy="12" r="8"/>',
        'warn'         => '<path d="M12 4.5 21 20H3Z"/><path d="M12 10.5v4"/><path d="M12 17.2v.3"/>',
        'refresh'      => '<path d="M20 12a8 8 0 1 1-2.35-5.65"/><path d="M20 4v4h-4"/>',
        'chevron-up'   => '<path d="m6 14 6-6 6 6"/>',
        'chevron-down' => '<path d="m6 10 6 6 6-6"/>',
        'briefcase'    => '<rect x="4" y="8" width="16" height="11" rx="2"/><path d="M9 8V6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/><path d="M4 13h16"/>',
    ];

    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '') . '</svg>';
};

foreach ($status['checks'] ?? [] as $row) {
    $tiers[$row['tier'] ?? SetupCheck::TIER_REQUIRED]['rows'][] = $row;
}
?>
<section class="setup-card" data-region="component" data-state="<?php echo $state; ?>" data-ready="<?php echo $ready ? '1' : '0'; ?>">

    <header class="setup-head">
        <div class="setup-headline">
            <h2 class="setup-title"><?php echo Text::_('COM_ALFA_SETUP_CARD_TITLE'); ?></h2>
            <span class="setup-progress-text">
                <?php echo $ready
                    ? Text::_('COM_ALFA_SETUP_CARD_READY_SHORT')
                    : Text::sprintf('COM_ALFA_SETUP_CARD_PROGRESS', $done, $total); ?>
            </span>
        </div>
        <div class="setup-progress" role="progressbar" aria-valuenow="<?php echo $percent; ?>" aria-valuemin="0" aria-valuemax="100">
            <span class="setup-progress-fill" style="width: <?php echo $percent; ?>%"></span>
        </div>
        <div class="setup-head-actions">
            <button type="button" class="btn btn-sm setup-act" data-action="refresh"
                    title="<?php echo Text::_('COM_ALFA_SETUP_CARD_REFRESH'); ?>">
                <?php echo $svg('refresh'); ?>
                <span class="setup-act-label"><?php echo Text::_('COM_ALFA_SETUP_CARD_REFRESH'); ?></span>
            </button>
            <button type="button" class="btn btn-sm setup-act" data-action="collapse"
                    title="<?php echo Text::_('COM_ALFA_SETUP_CARD_COLLAPSE'); ?>">
                <?php echo $svg('chevron-up'); ?>
                <span class="setup-act-label"><?php echo Text::_('COM_ALFA_SETUP_CARD_COLLAPSE'); ?></span>
            </button>
            <button type="button" class="btn btn-sm setup-act" data-action="expand"
                    title="<?php echo Text::_('COM_ALFA_SETUP_CARD_EXPAND'); ?>">
                <?php echo $svg('chevron-down'); ?>
                <span class="setup-act-label"><?php echo Text::_('COM_ALFA_SETUP_CARD_EXPAND'); ?></span>
            </button>
        </div>
    </header>

    <div class="setup-body" data-region="body">
        <?php foreach ($tiers as $tierKey => $tier) : ?>
            <?php if ($tier['rows'] === []) : ?>
                <?php continue; ?>
            <?php endif; ?>
            <h3 class="setup-tier"><?php echo $tier['label']; ?></h3>
            <ul class="setup-list">
                <?php foreach ($tier['rows'] as $row) : ?>
                    <?php
                    $rowState = (string) ($row['state'] ?? SetupCheck::STATE_FAIL);
                    $isPass   = $rowState === SetupCheck::STATE_PASS;
                    $tourUid  = (string) ($row['tour'] ?? '');
                    $tour     = $tourUid !== '' ? ($tours[$tourUid] ?? null) : null;
                    $cta      = (string) ($row['cta'] ?? '');
                    $ctaHref  = $cta !== '' ? Route::_($cta) : '';
                    $tourFirst = !$isPass && $tour !== null && empty($tour['seen']);
                    ?>
                    <li class="setup-item is-<?php echo $rowState; ?> tier-<?php echo $tierKey; ?>" data-check="<?php echo htmlspecialchars((string) $row['id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="setup-state" aria-hidden="true">
                            <?php echo $svg($isPass ? 'check' : ($rowState === SetupCheck::STATE_WARN ? 'warn' : 'circle')); ?>
                        </span>
                        <div class="setup-item-body">
                            <span class="setup-item-label"><?php echo Text::_((string) $row['label']); ?></span>
                            <?php if (!$isPass && (string) ($row['reason'] ?? '') !== '') : ?>
                                <span class="setup-item-reason"><?php echo Text::_((string) $row['reason']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($ctaHref !== '' || $tour !== null || (!$isPass && $canProvision && !empty($row['provisionable']))) : ?>
                            <?php
                            // NOTHING disappears: Open and the tour stay
                            // available even on a completed row; the checklist doubles
                            // as a launcher. Emphasis alone changes: quiet links when
                            // done, a primary action while work remains.
                            $openLink = static function (string $classes) use ($ctaHref, $row): void {
                                echo '<a class="' . $classes . ' setup-cta" href="' . $ctaHref . '"'
                                    . ' data-frame-title="' . htmlspecialchars(Text::_((string) $row['label']), ENT_QUOTES, 'UTF-8') . '">'
                                    . Text::_('COM_ALFA_SETUP_ROW_OPEN') . '</a>';
                            };
                            $tourButton = static function (string $classes) use ($tourUid): void {
                                echo '<button type="button" class="' . $classes . ' button-start-guidedtour"'
                                    . ' data-gt-uid="' . htmlspecialchars($tourUid, ENT_QUOTES, 'UTF-8') . '">'
                                    . Text::_('COM_ALFA_SETUP_ROW_SHOW_ME') . '</button>';
                            };
                            ?>
                            <div class="setup-item-actions">
                                <?php if (!$isPass && $canProvision && !empty($row['provisionable'])) : ?>
                                    <?php foreach ((array) ($row['choices'] ?? []) as $choice) : ?>
                                        <?php if (($choice['options'] ?? []) === []) { continue; } ?>
                                        <label class="setup-item-choice">
                                            <span class="visually-hidden"><?php echo Text::_((string) $choice['label']); ?></span>
                                            <select class="form-select form-select-sm"
                                                    id="setup-<?php echo htmlspecialchars((string) $row['id'] . '-' . (string) $choice['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-provision-answer="<?php echo htmlspecialchars((string) $choice['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    title="<?php echo htmlspecialchars(Text::_((string) $choice['label']), ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php foreach ($choice['options'] as $value => $text) : ?>
                                                    <option value="<?php echo htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                    <?php endforeach; ?>
                                    <button type="button" class="btn btn-sm btn-success" data-action="provision"
                                            data-check="<?php echo htmlspecialchars((string) $row['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo Text::_('COM_ALFA_SETUP_ROW_PROVISION'); ?>
                                    </button>
                                    <?php if ($ctaHref !== '') { $openLink('setup-item-alt'); } ?>
                                    <?php if ($tour !== null) { $tourButton('setup-item-alt'); } ?>
                                <?php elseif (!$isPass && $tourFirst) : ?>
                                    <?php $tourButton('btn btn-sm btn-primary'); ?>
                                    <?php if ($ctaHref !== '') { $openLink('setup-item-alt'); } ?>
                                <?php elseif (!$isPass) : ?>
                                    <?php if ($ctaHref !== '') { $openLink('btn btn-sm btn-primary'); } ?>
                                    <?php if ($tour !== null) { $tourButton('setup-item-alt'); } ?>
                                <?php else : ?>
                                    <?php if ($ctaHref !== '') { $openLink('setup-item-alt'); } ?>
                                    <?php if ($tour !== null) { $tourButton('setup-item-alt'); } ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </div>

    <?php if (!$ready && $missing > 0) : ?>
        <footer class="setup-foot">
            <?php echo Text::sprintf('COM_ALFA_SETUP_CARD_FOOT_REQUIRED', $missing); ?>
        </footer>
    <?php endif; ?>

</section>
