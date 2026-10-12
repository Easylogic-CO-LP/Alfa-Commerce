<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Card\DashboardSurface;
use Alfa\Component\Alfa\Administrator\Service\Stats\DashboardPeriod;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/** @var \Alfa\Component\Alfa\Administrator\View\Dashboard\HtmlView $this */

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
    ->useStyle('com_alfa.setup-dashboard')
    ->useScript('com_alfa.setup-dashboard')
    // Row deep links open in the shared iframe modal (Alfa.openActionFrame).
    ->useScript('bootstrap.modal')
    ->useScript('com_alfa.actions')
    // The setup card's failure notice (Alfa.notify).
    ->usePreset('com_alfa.toast');

// The endpoints the card JS calls; the card markup itself is the layout's contract.
$this->getDocument()->addScriptOptions('com_alfa.setup', [
    'cardUrl'      => Route::_('index.php?option=com_alfa&task=setup.card', false),
    'stateUrl'     => Route::_('index.php?option=com_alfa&task=setup.state', false),
    'provisionUrl' => Route::_('index.php?option=com_alfa&task=setup.provision', false),
    'token'        => Session::getFormToken(),
]);

// The card's failure notice: a refused request's body is never shown to the operator.
Text::script('COM_ALFA_SETUP_REQUEST_FAILED');
?>
<div class="alfa-dashboard">
    <?php // The download store's direct-download protection: only while it is not verified. ?>
    <?php echo LayoutHelper::render('downloads.protection', ['always' => false], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>
    <header class="dash-head">
        <h1 class="dash-title">
            <?php echo $this->storeName !== ''
                ? htmlspecialchars($this->storeName, ENT_QUOTES, 'UTF-8')
                : Text::_('COM_ALFA_DASHBOARD_UNNAMED'); ?>
            <?php // Rendered always, revealed by CSS only while the setup card sits in
                  // its "done" state, so a refresh that swaps the card can never leave
                  // this tick disagreeing with it, and no script has to build markup. ?>
            <button type="button" class="dash-ready" data-action="setup-review"
                    title="<?php echo Text::_('COM_ALFA_DASHBOARD_SETUP_DONE'); ?>"
                    aria-label="<?php echo Text::_('COM_ALFA_DASHBOARD_SETUP_DONE'); ?>">
                <?php // A quiet receipt in the title: a ring and a check, grey and
                      // still. No motion: it reports a state, it does not celebrate. ?>
                <svg class="dash-ready-mark" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.1" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <circle class="dash-ready-ring" cx="12" cy="12" r="9.25"/>
                    <path class="dash-ready-check" d="M7.4 12.4l3.1 3.1 6.1-6.6"/>
                </svg>
            </button>
        </h1>
        <p class="dash-sub"><?php echo Text::_('COM_ALFA_DASHBOARD_SUB'); ?></p>
    </header>

    <?php echo $this->cardHtml; ?>

    <?php // The alert strip and the tiles are BOTH "right now": orders since midnight,
          // the queue waiting for a human, today's takings, what is running out. The
          // period control governs none of them, so it is not printed above them.
          // Sitting there it read as broken: a merchant clicked it, the four numbers
          // under it did not move, and the conclusion was that the filter did nothing. ?>
    <?php echo $this->surfaces[DashboardSurface::Top->value] ?? ''; ?>
    <?php echo $this->surfaces[DashboardSurface::Metrics->value] ?? ''; ?>

    <?php // Here, immediately above the first card it actually changes. PLAIN LINKS,
          // not a script: the choice is remembered in user state, so a link both answers
          // this load and sets the next one, and the whole control keeps working with
          // JavaScript off. `aria-current` carries the selection to a screen reader; the
          // class only paints it. ?>
    <nav class="dash-period" aria-label="<?php echo $this->escape(Text::_('COM_ALFA_DASHBOARD_PERIOD')); ?>">
        <span class="dash-period-label"><?php echo Text::_('COM_ALFA_DASHBOARD_PERIOD_LEAD'); ?></span>

        <?php foreach (DashboardPeriod::OPTIONS as $option => $label) : ?>
            <?php $current = $option === $this->days; ?>
            <a class="dash-period-option<?php echo $current ? ' is-current' : ''; ?>"
               href="<?php echo Route::_('index.php?option=com_alfa&view=dashboard&'
                   . DashboardPeriod::REQUEST_KEY . '=' . $option, false); ?>"
               <?php echo $current ? 'aria-current="page"' : ''; ?>>
                <?php echo Text::_($label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php foreach ([DashboardSurface::Main, DashboardSurface::Side, DashboardSurface::Bottom] as $surface) : ?>
        <?php echo $this->surfaces[$surface->value] ?? ''; ?>
    <?php endforeach; ?>
</div>
